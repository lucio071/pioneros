/*
 * PIONEROS 4x4 - GATEWAY LoRa <-> WiFi
 * Placa: LILYGO T3 V1.6.1
 * DUAL-CORE: Core 1 = LoRa RX (interrupcion) + OLED, Core 0 = HTTP
 *
 * LoRa RX por polling en loop (HTTP en Core 0, loop libre para LoRa)
 * durante HTTP. Ring buffer de 8 paquetes. ACK para cruces.
 * HTTP en Core 0: polls, eventos, heartbeat.
 */

#include <SPI.h>
#include <LoRa.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <esp_task_wdt.h>
#include <esp_system.h>

// ================== CONFIGURACION ==================
#include "config.h"   // WIFI_SSID, WIFI_PASS, GW_TOKEN, TOKEN_SENSOR_A/B, TOKEN_SEMAFORO
#define API_URL    "http://192.168.100.5"

#define HTTP_TIMEOUT_MS    5000
#define POLL_INTERVAL_MS   2000

// ================== PINOUT T3 V1.6.1 ==================
#define LORA_SCK   5
#define LORA_MISO  19
#define LORA_MOSI  27
#define LORA_CS    18
#define LORA_RST   23
#define LORA_DIO0  26
#define LED_PIN    25

// ================== PROTOCOLO LoRa ==================
#define MAGIC              0xA5
#define VERSION            0x01

#define ID_GATEWAY   0
#define ID_SENSOR_A  1
#define ID_SENSOR_B  2
#define ID_SEMAFORO  3

#define EV_CRUCE               0x01
#define EV_HEARTBEAT           0x02
#define CMD_HABILITAR_SENSOR   0x10
#define CMD_SEMAFORO_LARGADA   0x11
#define CMD_RESET              0x12
#define EV_ACK                 0xF0

// Ring buffer eliminado: RX por polling, no por ISR

// ================== COLA HTTP (LoRa -> HTTP) ==================
#define HTTP_Q_SIZE  8
struct HttpEvent {
  uint8_t tipo;       // EV_CRUCE o EV_HEARTBEAT
  uint8_t src_id;
  uint8_t tramo;      // 'A' o 'B'
  int16_t rssi;
  uint16_t voltaje;
  uint32_t uptime;
  uint32_t delta_ms;  // ms desde el cruce real (para corregir tiempo)
  uint32_t rx_at;     // millis() cuando se recibio
};
HttpEvent http_q[HTTP_Q_SIZE];
volatile uint8_t hq_head = 0;
volatile uint8_t hq_tail = 0;

// ================== COLA TX LoRa (HTTP -> LoRa) ==================
#define TX_Q_SIZE  16
struct TxCmd {
  uint8_t dst_id;
  uint8_t tipo;
  uint8_t extra[4];
  uint8_t extra_len;
  uint8_t retries;  // cuantas veces enviar
};
TxCmd tx_q[TX_Q_SIZE];
volatile uint8_t tq_head = 0;

// TX no bloqueante: una transmision por pasada del loop
bool tx_en_curso = false;
TxCmd tx_actual;
uint8_t tx_intento = 0;
uint32_t tx_proximo_ms = 0;
volatile uint8_t tq_tail = 0;

// ================== ESTADO ==================
Adafruit_SSD1306 display(128, 64, &Wire, -1);
uint16_t seq_tx = 0;
uint32_t rx_count = 0;
uint32_t tx_count = 0;
uint32_t http_ok = 0;
uint32_t http_err = 0;
uint32_t ack_sent = 0;
int last_rssi = 0;
int reset_reason = 0;
bool wifi_connected = false;

// Deduplicacion: ultimo seq por src_id
uint16_t last_seq[4] = {0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF};

// ================== CRC16 CCITT ==================
uint16_t crc16(uint8_t* data, size_t len) {
  uint16_t crc = 0xFFFF;
  for (size_t i = 0; i < len; i++) {
    crc ^= (uint16_t)data[i] << 8;
    for (int j = 0; j < 8; j++) {
      if (crc & 0x8000) crc = (crc << 1) ^ 0x1021;
      else crc <<= 1;
    }
  }
  return crc;
}

// ================== DISPLAY ==================
void updateDisplay() {
  display.clearDisplay();
  display.setCursor(0, 0);
  display.setTextSize(1);
  display.print("GW ");
  display.println(wifi_connected ? "WiFi OK" : "SIN WIFI");
  display.println(WiFi.localIP());
  display.print("RX:");
  display.print(rx_count);
  display.print(" TX:");
  display.print(tx_count);
  display.print(" ACK:");
  display.println(ack_sent);
  display.print("HTTP OK:");
  display.print(http_ok);
  display.print(" ER:");
  display.println(http_err);
  display.print("RSSI:");
  display.print(last_rssi);
  display.print(" Up:");
  display.print(millis() / 1000);
  display.println("s");
  display.print("RST:");
  display.println(reset_reason);
  display.display();
}

// ================== LoRa TX (desde Core 1) ==================
void enviarComandoLoRa(uint8_t dst_id, uint8_t tipo, uint8_t* extra, size_t extra_len) {
  uint8_t packet[20];
  packet[0] = MAGIC;
  packet[1] = VERSION;
  packet[2] = ID_GATEWAY;
  packet[3] = dst_id;
  packet[4] = tipo;
  packet[5] = seq_tx & 0xFF;
  packet[6] = (seq_tx >> 8) & 0xFF;
  seq_tx++;

  size_t payload_size = 7;
  if (extra && extra_len > 0 && extra_len <= 10) {
    memcpy(&packet[7], extra, extra_len);
    payload_size += extra_len;
  }

  uint16_t crc = crc16(packet, payload_size);
  packet[payload_size] = crc & 0xFF;
  packet[payload_size + 1] = (crc >> 8) & 0xFF;
  payload_size += 2;

  // CSMA: esperar si el canal esta ocupado (2 intentos, SF7 paquete ~10ms)
  for (int csma = 0; csma < 2; csma++) {
    if (LoRa.rssi() < -90) break;
    delay(random(5, 15));
  }
  LoRa.beginPacket();
  LoRa.write(packet, payload_size);
  LoRa.endPacket();
  LoRa.receive();  // volver a RX continuo
  tx_count++;
}

void enviarACK(uint8_t dst_id, uint16_t seq_original) {
  uint8_t extra[2];
  extra[0] = seq_original & 0xFF;
  extra[1] = (seq_original >> 8) & 0xFF;
  enviarComandoLoRa(dst_id, EV_ACK, extra, 2);
  ack_sent++;
}

// onLoRaReceive eliminado: polling en loop, sin race con TX

// ================== PROCESAR PAQUETE (Core 1) ==================
void procesarPaquete(uint8_t* pkt, size_t len, int rssi) {
  if (len < 9) return;
  if (pkt[0] != MAGIC || pkt[1] != VERSION) return;

  uint16_t crc_rx = pkt[len - 2] | (pkt[len - 1] << 8);
  uint16_t crc_calc = crc16(pkt, len - 2);
  if (crc_rx != crc_calc) return;

  uint8_t src_id = pkt[2];
  uint8_t tipo = pkt[4];
  uint16_t seq = pkt[5] | (pkt[6] << 8);

  rx_count++;
  last_rssi = rssi;

  if (tipo == EV_CRUCE) {
    // Deduplicar por (src_id, seq)
    if (src_id < 4 && seq == last_seq[src_id]) {
      // Duplicado: reenviar ACK pero no encolar HTTP
      enviarACK(src_id, seq);
      Serial.printf("RX CRUCE DUP src=%d seq=%d\n", src_id, seq);
      return;
    }
    if (src_id < 4) last_seq[src_id] = seq;

    // Enviar ACK inmediato
    enviarACK(src_id, seq);

    // Extraer delta_ms del paquete: header(7) + tramo(1) + delta(4) + crc(2) = 14
    uint32_t delta_ms = 0;
    if (len >= 14) {
      delta_ms = pkt[8] | (pkt[9] << 8) | (pkt[10] << 16) | (pkt[11] << 24);
    }

    // Encolar para HTTP
    uint8_t next = (hq_head + 1) % HTTP_Q_SIZE;
    if (next != hq_tail) {
      HttpEvent* ev = &http_q[hq_head];
      ev->tipo = EV_CRUCE;
      ev->src_id = src_id;
      ev->tramo = (len >= 9 && pkt[7] != 0) ? pkt[7] : '?';
      ev->delta_ms = delta_ms;
      ev->rx_at = millis();
      hq_head = next;
    }

    const char* tramo_str = src_id == ID_SENSOR_A ? "A" : src_id == ID_SENSOR_B ? "B" : "?";
    Serial.printf("RX CRUCE tramo=%s seq=%d delta=%lums\n", tramo_str, seq, delta_ms);

  } else if (tipo == EV_HEARTBEAT && len >= 17) {
    int16_t hb_rssi = (int16_t)(pkt[7] | (pkt[8] << 8));
    uint16_t voltaje = pkt[9] | (pkt[10] << 8);
    uint32_t uptime = pkt[11] | (pkt[12] << 8) | (pkt[13] << 16) | (pkt[14] << 24);

    // Encolar para HTTP
    uint8_t next = (hq_head + 1) % HTTP_Q_SIZE;
    if (next != hq_tail) {
      HttpEvent* ev = &http_q[hq_head];
      ev->tipo = EV_HEARTBEAT;
      ev->src_id = src_id;
      ev->rssi = hb_rssi;
      ev->voltaje = voltaje;
      ev->uptime = uptime;
      ev->rx_at = millis();
      hq_head = next;
    }
  }
}

// ================== HTTP FUNCTIONS (Core 0) ==================
bool enviarEventoApp(const char* token, const char* tramo, uint32_t delta_ms) {
  if (!wifi_connected) return false;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/eventos");
  http.addHeader("Authorization", String("Bearer ") + token);
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["tipo"] = "cruce";
  doc["tramo"] = tramo;
  if (delta_ms > 0) doc["delta_ms"] = delta_ms;
  String body;
  serializeJson(doc, body);

  int code = http.POST(body);
  http.end();
  Serial.printf("POST /eventos tramo=%s delta=%lums => %d\n", tramo, delta_ms, code);
  if (code >= 200 && code < 300) { http_ok++; return true; }
  http_err++;
  return false;
}

bool enviarHeartbeatApp(const char* token, int rssi, int voltaje_mv, uint32_t uptime) {
  if (!wifi_connected) return false;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/heartbeat");
  http.addHeader("Authorization", String("Bearer ") + token);
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["rssi"] = rssi;
  doc["voltaje_mv"] = voltaje_mv;
  doc["uptime_sec"] = uptime;
  String body;
  serializeJson(doc, body);

  int code = http.POST(body);
  http.end();
  if (code >= 200 && code < 300) { http_ok++; return true; }
  http_err++;
  return false;
}

void consultarComandos(const char* token, uint8_t dst_id) {
  if (!wifi_connected) return;

  // Fase 1: GET comandos y recolectar IDs a confirmar
  char ids_confirmar[4][40];
  uint8_t n_confirmar = 0;

  {
    HTTPClient http;
    http.setTimeout(HTTP_TIMEOUT_MS);
    http.begin(String(API_URL) + "/api/v1/cronometro/comandos-pendientes");
    http.addHeader("Authorization", String("Bearer ") + token);
    int code = http.GET();

    if (code == 200) {
      String body = http.getString();
      http.end();  // cerrar ANTES de parsear y confirmar

      static StaticJsonDocument<2048> doc;  // static: vive en .bss, no en stack
      if (deserializeJson(doc, body) == DeserializationError::Ok) {
        JsonArray comandos = doc["comandos"].as<JsonArray>();
        for (JsonObject cmd : comandos) {
          const char* tipo = cmd["tipo"];
          const char* cmd_id = cmd["id"];
          if (!tipo || !cmd_id) continue;

          // Encolar TX LoRa
          uint8_t next = (tq_head + 1) % TX_Q_SIZE;
          if (next != tq_tail) {
            TxCmd* tc = &tx_q[tq_head];
            tc->dst_id = dst_id;
            tc->extra_len = 0;
            tc->retries = 1;

            if (strcmp(tipo, "habilitar_sensor") == 0) {
              tc->tipo = CMD_HABILITAR_SENSOR;
              uint16_t dur = cmd["payload"]["duracion_seg"] | 35;
              memcpy(tc->extra, &dur, 2);
              tc->extra_len = 2;
              tc->retries = 3;
            } else if (strcmp(tipo, "semaforo_largada") == 0) {
              tc->tipo = CMD_SEMAFORO_LARGADA;
              tc->retries = 3;
            } else if (strcmp(tipo, "reset") == 0) {
              tc->tipo = CMD_RESET;
            } else {
              continue;
            }
            tq_head = next;

            // Guardar ID para confirmar despues
            if (n_confirmar < 4) {
              strncpy(ids_confirmar[n_confirmar], cmd_id, 39);
              ids_confirmar[n_confirmar][39] = '\0';
              n_confirmar++;
            }
          }
        }
      }
    } else {
      http.end();
      if (code > 0) http_err++;
    }
  } // HTTPClient http se destruye aqui

  // Fase 2: confirmar comandos encolados (sin HTTPClient anidado)
  for (uint8_t i = 0; i < n_confirmar; i++) {
    HTTPClient http;
    http.setTimeout(HTTP_TIMEOUT_MS);
    http.begin(String(API_URL) + "/api/v1/cronometro/comandos/" + ids_confirmar[i] + "/confirmar");
    http.addHeader("Authorization", String("Bearer ") + token);
    int rc = http.POST("");
    http.end();
    if (rc >= 200 && rc < 300) http_ok++;
    else http_err++;
  }
}

// ================== TAREA HTTP (Core 0) ==================
void tareaHTTP(void* param) {
  uint32_t last_poll = 0;
  uint32_t last_gw_hb = 0;

  for (;;) {
    wifi_connected = (WiFi.status() == WL_CONNECTED);

    if (wifi_connected) {
      // 1. Procesar cola HTTP (eventos LoRa -> servidor)
      while (hq_tail != hq_head) {
        HttpEvent ev = http_q[hq_tail];
        hq_tail = (hq_tail + 1) % HTTP_Q_SIZE;

        const char* token = NULL;
        const char* tramo = NULL;
        switch (ev.src_id) {
          case ID_SENSOR_A: token = TOKEN_SENSOR_A; tramo = "A"; break;
          case ID_SENSOR_B: token = TOKEN_SENSOR_B; tramo = "B"; break;
          case ID_SEMAFORO: token = TOKEN_SEMAFORO; break;
        }
        if (!token) continue;

        if (ev.tipo == EV_CRUCE && tramo) {
          enviarEventoApp(token, tramo, ev.delta_ms);
        } else if (ev.tipo == EV_HEARTBEAT) {
          enviarHeartbeatApp(token, ev.rssi, ev.voltaje, ev.uptime);
        }
      }

      // 2. Poll comandos cada POLL_INTERVAL_MS
      if (millis() - last_poll > POLL_INTERVAL_MS) {
        consultarComandos(TOKEN_SENSOR_A, ID_SENSOR_A);
        consultarComandos(TOKEN_SENSOR_B, ID_SENSOR_B);
        consultarComandos(TOKEN_SEMAFORO, ID_SEMAFORO);
        last_poll = millis();
      }

      // 3. Heartbeat propio del gateway cada 5s
      if (millis() - last_gw_hb > 5000) {
        HTTPClient http;
        http.setTimeout(HTTP_TIMEOUT_MS);
        http.begin(String(API_URL) + "/api/v1/cronometro/heartbeat");
        http.addHeader("Authorization", String("Bearer ") + GW_TOKEN);
        http.addHeader("Content-Type", "application/json");
        StaticJsonDocument<128> doc;
        doc["rssi"] = WiFi.RSSI();
        doc["voltaje_mv"] = 5000;
        doc["uptime_sec"] = millis() / 1000;
        doc["reset_reason"] = reset_reason;
        String body;
        serializeJson(doc, body);
        http.POST(body);
        http.end();
        last_gw_hb = millis();
      }
    } else {
      static uint32_t last_reconnect = 0;
      wl_status_t st = WiFi.status();
      // WL_IDLE_STATUS = intento en curso: NO interrumpirlo
      if (st != WL_IDLE_STATUS && millis() - last_reconnect > 15000) {
        Serial.printf("[WiFi] estado=%d, reintentando\n", (int)st);
        WiFi.disconnect(false, false);
        WiFi.begin(WIFI_SSID, WIFI_PASS);
        last_reconnect = millis();
      }
    }

    vTaskDelay(pdMS_TO_TICKS(20));
  }
}

// ================== SETUP ==================
void setup() {
  Serial.begin(115200);
  randomSeed(esp_random());
  pinMode(LED_PIN, OUTPUT);
  delay(1000);

  reset_reason = (int)esp_reset_reason();
  Serial.println("=====================================");
  Serial.printf("GATEWAY dual-core init (RST:%d)\n", reset_reason);
  // 1=POWERON 3=SW 4=INT_WDT 5=TASK_WDT 6=WDT 8=BROWNOUT
  Serial.println("=====================================");

  Wire.begin(21, 22);
  display.begin(SSD1306_SWITCHCAPVCC, 0x3C);
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.println("GATEWAY init");
  display.printf("RST: %d", reset_reason);
  if (reset_reason == 8) display.print(" BROWNOUT!");
  else if (reset_reason == 5) display.print(" TASK_WDT!");
  else if (reset_reason == 1) display.print(" POWERON");
  else if (reset_reason == 3) display.print(" SW");
  display.println();
  display.display();
  delay(3000);  // mostrar reset reason 3 segundos

  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_CS);
  LoRa.setPins(LORA_CS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(433E6)) {
    display.println("LoRa FAIL");
    display.display();
    ESP.restart();
  }
  LoRa.setTxPower(14);
  LoRa.setSpreadingFactor(7);
  LoRa.setSignalBandwidth(250E3);
  LoRa.setSyncWord(0x12);

  // RX por polling en loop (HTTP en Core 0, loop libre para LoRa)
  LoRa.receive();

  display.println("LoRa OK");
  display.display();

  // WiFi — no bloqueante
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.printf("WiFi conectando a %s...\n", WIFI_SSID);

  // Watchdog 30s
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = 30000,
    .idle_core_mask = 0,
    .trigger_panic = true
  };
  esp_task_wdt_reconfigure(&wdt_config);
  esp_task_wdt_add(NULL);

  // Lanzar tarea HTTP en Core 0
  xTaskCreatePinnedToCore(
    tareaHTTP,
    "http",
    16384,
    NULL,
    1,
    NULL,
    0   // Core 0
  );

  Serial.println("Setup completo: Core 1=LoRa+OLED, Core 0=HTTP");
  updateDisplay();
}

// ================== LOOP (Core 1 — LoRa + OLED) ==================
void loop() {
  // 1. LoRa RX por polling (sin ISR, sin race con TX)
  int packetSize = LoRa.parsePacket();
  if (packetSize > 0 && packetSize <= 20) {
    uint8_t pkt[20];
    int i = 0;
    while (LoRa.available() && i < 20) pkt[i++] = LoRa.read();
    int rssi = LoRa.packetRssi();
    procesarPaquete(pkt, i, rssi);
    LoRa.receive();
  }

  // 2. TX LoRa no bloqueante: una transmision por pasada
  if (!tx_en_curso && tq_tail != tq_head) {
    tx_actual = tx_q[tq_tail];
    tq_tail = (tq_tail + 1) % TX_Q_SIZE;
    tx_en_curso = true;
    tx_intento = 0;
    tx_proximo_ms = millis();
  }
  if (tx_en_curso && millis() >= tx_proximo_ms) {
    enviarComandoLoRa(tx_actual.dst_id, tx_actual.tipo, tx_actual.extra, tx_actual.extra_len);
    tx_intento++;
    if (tx_intento >= tx_actual.retries) tx_en_curso = false;
    else tx_proximo_ms = millis() + 200;
  }

  // 3. Actualizar OLED cada 1s
  static uint32_t last_display = 0;
  if (millis() - last_display > 1000) {
    updateDisplay();
    last_display = millis();
  }

  esp_task_wdt_reset();
  delay(5);  // yield
}
