/*
 * PIONEROS 4x4 - GATEWAY LoRa <-> WiFi
 * Placa: LILYGO T3 V1.6.1
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

// ================== CONFIGURACION ==================
#include "config.h"   // WIFI_SSID, WIFI_PASS, TOKEN_SENSOR_A/B, TOKEN_SEMAFORO (no va al repo)
#define API_URL    "http://192.168.100.5"

#define HTTP_TIMEOUT_MS  2000

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
#define POLL_INTERVAL_MS   2000

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

// ================== ESTADO ==================
Adafruit_SSD1306 display(128, 64, &Wire, -1);
uint16_t seq_tx = 0;
uint32_t last_poll = 0;
uint32_t rx_count = 0;
uint32_t tx_count = 0;
uint32_t http_ok = 0;
uint32_t http_err = 0;
int last_rssi = 0;
bool wifi_connected = false;

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
  display.print("LoRa RX:");
  display.print(rx_count);
  display.print(" TX:");
  display.println(tx_count);
  display.print("HTTP OK:");
  display.print(http_ok);
  display.print(" ER:");
  display.println(http_err);
  display.print("RSSI:");
  display.print(last_rssi);
  display.print(" Up:");
  display.print(millis() / 1000);
  display.println("s");
  display.display();
}

// ================== LORA TX ==================
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

  LoRa.beginPacket();
  LoRa.write(packet, payload_size);
  LoRa.endPacket();
  LoRa.receive();
  tx_count++;
  Serial.printf("TX cmd 0x%02X -> id %d\n", tipo, dst_id);
}

// ================== HTTP CLIENT ==================
bool enviarEventoApp(const char* token, const char* tramo) {
  if (!wifi_connected) return false;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/eventos");
  http.addHeader("Authorization", String("Bearer ") + token);
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["tipo"] = "cruce";
  doc["tramo"] = tramo;
  String body;
  serializeJson(doc, body);

  int code = http.POST(body);
  http.end();
  Serial.printf("POST /eventos tramo=%s => %d\n", tramo, code);
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
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/comandos-pendientes");
  http.addHeader("Authorization", String("Bearer ") + token);
  int code = http.GET();

  if (code == 200) {
    String body = http.getString();
    StaticJsonDocument<2048> doc;
    if (deserializeJson(doc, body) == DeserializationError::Ok) {
      JsonArray comandos = doc["comandos"].as<JsonArray>();
      for (JsonObject cmd : comandos) {
        const char* tipo = cmd["tipo"];
        const char* cmd_id = cmd["id"];
        if (!tipo || !cmd_id) continue;

        if (strcmp(tipo, "habilitar_sensor") == 0) {
          uint16_t dur = 20;
          enviarComandoLoRa(dst_id, CMD_HABILITAR_SENSOR, (uint8_t*)&dur, 2);
        } else if (strcmp(tipo, "semaforo_largada") == 0) {
          enviarComandoLoRa(dst_id, CMD_SEMAFORO_LARGADA, NULL, 0);
        } else if (strcmp(tipo, "reset") == 0) {
          enviarComandoLoRa(dst_id, CMD_RESET, NULL, 0);
        }

        HTTPClient http2;
        http2.setTimeout(HTTP_TIMEOUT_MS);
        http2.begin(String(API_URL) + "/api/v1/cronometro/comandos/" + cmd_id + "/confirmar");
        http2.addHeader("Authorization", String("Bearer ") + token);
        int rc = http2.POST("");
        http2.end();
        if (rc >= 200 && rc < 300) http_ok++;
        else http_err++;
      }
    }
  } else if (code > 0) {
    http_err++;
  }
  http.end();
}

// ================== LORA RX ==================
void procesarPaqueteLoRa(uint8_t* pkt, size_t len) {
  if (len < 9) return;
  if (pkt[0] != MAGIC || pkt[1] != VERSION) return;

  uint16_t crc_rx = pkt[len - 2] | (pkt[len - 1] << 8);
  uint16_t crc_calc = crc16(pkt, len - 2);
  if (crc_rx != crc_calc) return;

  uint8_t src_id = pkt[2];
  uint8_t tipo = pkt[4];

  const char* token = NULL;
  const char* tramo = NULL;
  switch (src_id) {
    case ID_SENSOR_A: token = TOKEN_SENSOR_A; tramo = "A"; break;
    case ID_SENSOR_B: token = TOKEN_SENSOR_B; tramo = "B"; break;
    case ID_SEMAFORO: token = TOKEN_SEMAFORO; break;
  }
  if (!token) return;

  if (tipo == EV_CRUCE && tramo) {
    Serial.printf("RX CRUCE tramo=%s\n", tramo);
    enviarEventoApp(token, tramo);
  } else if (tipo == EV_HEARTBEAT && len >= 17) {
    int rssi = (int16_t)(pkt[7] | (pkt[8] << 8));
    int voltaje = pkt[9] | (pkt[10] << 8);
    uint32_t uptime = pkt[11] | (pkt[12] << 8) | (pkt[13] << 16) | (pkt[14] << 24);
    Serial.printf("RX HB src=%d rssi=%d\n", src_id, rssi);
    enviarHeartbeatApp(token, rssi, voltaje, uptime);
  }
}

// ================== SETUP ==================
void setup() {
  Serial.begin(115200);
  pinMode(LED_PIN, OUTPUT);
  delay(1000);

  Wire.begin(21, 22);
  display.begin(SSD1306_SWITCHCAPVCC, 0x3C);
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.println("GATEWAY init");
  display.display();

  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_CS);
  LoRa.setPins(LORA_CS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(433E6)) {
    display.println("LoRa FAIL");
    display.display();
    ESP.restart();
  }
  LoRa.setTxPower(14);
  LoRa.setSpreadingFactor(9);
  LoRa.setSignalBandwidth(125E3);
  LoRa.setSyncWord(0x12);
  LoRa.receive();
  display.println("LoRa OK");
  display.display();

  // WiFi — no bloqueante
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  display.println("WiFi conectando...");
  display.display();
  Serial.printf("WiFi iniciando conexion a %s (no-bloqueante)...\n", WIFI_SSID);

  // Watchdog 30s — reconfigura el WDT del framework
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = 30000,
    .idle_core_mask = 0,
    .trigger_panic = true
  };
  esp_task_wdt_reconfigure(&wdt_config);
  esp_task_wdt_add(NULL);

  updateDisplay();
}

// ================== LOOP ==================
void loop() {
  wifi_connected = (WiFi.status() == WL_CONNECTED);

  int packetSize = LoRa.parsePacket();
  if (packetSize > 0 && packetSize <= 20) {
    uint8_t pkt[20];
    int i = 0;
    while (LoRa.available() && i < 20) pkt[i++] = LoRa.read();
    last_rssi = LoRa.packetRssi();
    rx_count++;
    procesarPaqueteLoRa(pkt, i);
    LoRa.receive();
  }

  if (millis() - last_poll > POLL_INTERVAL_MS) {
    consultarComandos(TOKEN_SENSOR_A, ID_SENSOR_A);
    consultarComandos(TOKEN_SENSOR_B, ID_SENSOR_B);
    consultarComandos(TOKEN_SEMAFORO, ID_SEMAFORO);
    last_poll = millis();
    updateDisplay();
  }

  // Heartbeat propio del gateway cada 5s
  static uint32_t last_gw_hb = 0;
  if (wifi_connected && millis() - last_gw_hb > 5000) {
    HTTPClient http;
    http.setTimeout(HTTP_TIMEOUT_MS);
    http.begin(String(API_URL) + "/api/v1/cronometro/heartbeat");
    http.addHeader("Authorization", String("Bearer ") + GW_TOKEN);
    http.addHeader("Content-Type", "application/json");
    StaticJsonDocument<128> doc;
    doc["rssi"] = WiFi.RSSI();
    doc["voltaje_mv"] = 5000;
    doc["uptime_sec"] = millis() / 1000;
    String body;
    serializeJson(doc, body);
    http.POST(body);
    http.end();
    last_gw_hb = millis();
  }

  static uint32_t last_reconnect = 0;
  if (!wifi_connected && millis() - last_reconnect > 5000) {
    Serial.println("[WiFi] reconectando...");
    WiFi.reconnect();
    last_reconnect = millis();
  }

  esp_task_wdt_reset();
}
