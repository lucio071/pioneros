/*
 * PIONEROS 4x4 - CRONOMETRO CRONO-A (WiFi + HUB75 + OTA)
 * Placa: SparkleIoT ESP32-S3 XH-S3E
 * Panel: HUB75 80x20 (DMA 160x10)
 * DUAL-CORE: Core 1=display 20fps, Core 0=HTTP
 * Comandos por long polling: GET comandos-pendientes?wait=10 (el servidor
 * responde al instante cuando hay comando). El heartbeat va en el mismo GET.
 *
 * ARDUINO IDE:
 *   Placa: ESP32S3 Dev Module
 *   USB CDC On Boot: Enabled
 */

// ================== CONFIGURACION ==================
#define CODIGO     "crono-b"
#define TRAMO      "B"
#define OTA_HOST   "crono-b"

#include "config.h"   // API_TOKEN, WIFI_SSID, WIFI_PASS, OTA_PASS (no va al repo)

#include <ESP32-HUB75-MatrixPanel-I2S-DMA.h>
#include <Adafruit_GFX.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <ArduinoOTA.h>
#include <esp_task_wdt.h>

// ================== CONSTANTES ==================
#define API_URL    "http://192.168.100.5"
#define LONG_POLL_WAIT_S       3     // el servidor retiene el GET hasta 3 s
#define HTTP_TIMEOUT_MS        (LONG_POLL_WAIT_S * 1000 + 3000)
#define POLL_RETRY_MS          50     // reintento rapido tras error
#define STATUS_INTERVAL_MS     5000

// ================== DISPLAY ==================
#define LOGIC_W  80
#define LOGIC_H  20
#define DMA_W    160
#define DMA_H    10

#define R1_PIN  1
#define G1_PIN  2
#define B1_PIN  42
#define R2_PIN  5
#define G2_PIN  6
#define B2_PIN  7
#define A_PIN   9
#define B_PIN   10
#define C_PIN   11
#define D_PIN   14
#define E_PIN   -1
#define LAT_PIN 8
#define OE_PIN  12
#define CLK_PIN 13

MatrixPanel_I2S_DMA *display = nullptr;
GFXcanvas16 *canvas = nullptr;

// ================== ESTADO CRONOMETRO ==================
// Variables compartidas entre cores — volatile para visibilidad
enum Estado { IDLE, CORRIENDO, PARADO, MOSTRANDO_TIEMPO, MOSTRANDO_TRIPULACION };
volatile Estado estado = IDLE;

volatile uint32_t tiempo_inicio_ms = 0;
volatile uint32_t tiempo_final_ms = 0;
volatile uint32_t tiempo_mostrado_ms = 0;
String tripulacion_actual = "---";
int vuelta_actual = 1;

uint32_t http_ok = 0;
uint32_t http_err = 0;
bool wifi_connected = false;

// Mutex para proteger strings compartidos
SemaphoreHandle_t mutex_estado = NULL;

// OTA
TaskHandle_t http_task = NULL;
volatile bool ota_en_curso = false;
volatile uint32_t sensor_armado_hasta = 0;

// ================== MAPPING PANEL ==================
void drawLogicPixel(int x, int y, uint16_t color) {
  if (x < 0 || x >= LOGIC_W || y < 0 || y >= LOGIC_H) return;
  int panel_idx  = x / 40;
  int local_x    = x % 40;
  int sub_panel  = y / 10;
  int local_y    = y % 10;
  int scan_line  = local_y % 5;
  int row_pair   = local_y / 5;
  int block = local_x / 4;
  int sub   = local_x % 4;
  int effective_pair = (block % 2 == 1) ? (1 - row_pair) : row_pair;
  int dma_x = (panel_idx * 80) + (block * 8) + sub + (effective_pair * 4);
  int dma_y = scan_line + (sub_panel * 5);
  if (dma_x < DMA_W && dma_y < DMA_H) display->drawPixel(dma_x, dma_y, color);
}

void flushCanvas() {
  for (int y = 0; y < LOGIC_H; y++)
    for (int x = 0; x < LOGIC_W; x++)
      drawLogicPixel(x, y, canvas->getPixel(x, y));
}

// ================== RENDER (Core 1 — loop) ==================
uint32_t tiempoActualMs() {
  if (estado == CORRIENDO) return millis() - tiempo_inicio_ms;
  if (estado == PARADO) return tiempo_final_ms;
  if (estado == MOSTRANDO_TIEMPO) return tiempo_mostrado_ms;
  return 0;
}

void renderDisplay() {
  uint32_t ms = tiempoActualMs();
  uint32_t secs = ms / 1000;
  uint32_t cents = (ms % 1000) / 10;
  int mm = secs / 60;
  int ss = secs % 60;

  char buf[16];
  snprintf(buf, sizeof(buf), "%02d:%02d.%02lu", mm, ss, cents);

  // Copiar strings protegidos por mutex
  String trip_copy;
  int vuelta_copy;
  if (xSemaphoreTake(mutex_estado, pdMS_TO_TICKS(5))) {
    trip_copy = tripulacion_actual;
    vuelta_copy = vuelta_actual;
    xSemaphoreGive(mutex_estado);
  } else {
    trip_copy = "---";
    vuelta_copy = 1;
  }

  canvas->fillScreen(0);
  canvas->setFont();
  canvas->setTextSize(1);

  canvas->setTextColor(display->color565(0, 100, 255));
  canvas->setCursor(4, 1);
  canvas->printf("P:%s", TRAMO);

  canvas->setTextColor(display->color565(255, 200, 0));
  canvas->setCursor(28, 1);
  canvas->printf("#%s", trip_copy.c_str());

  canvas->setTextColor(display->color565(0, 100, 255));
  canvas->setCursor(58, 1);
  canvas->printf("V:%d", vuelta_copy);

  uint16_t color_tiempo = (estado == PARADO || estado == MOSTRANDO_TIEMPO) ? display->color565(255, 0, 0) : display->color565(0, 255, 0);
  canvas->setTextColor(color_tiempo);
  canvas->setCursor(16, 11);
  canvas->print(buf);

  // Punto amarillo parpadeante si sensor armado
  if (sensor_armado_hasta > millis() && (millis() / 300) % 2 == 0) {
    uint16_t amarillo = display->color565(255, 200, 0);
    canvas->fillRect(76, 18, 2, 2, amarillo);
  }

  flushCanvas();
}

// ================== ACCIONES (llamadas desde Core 0) ==================
void cmdStart() {
  Serial.println(">>> START");
  tiempo_inicio_ms = millis();
  tiempo_final_ms = 0;
  sensor_armado_hasta = 0;
  estado = CORRIENDO;
}

void cmdStop() {
  Serial.println(">>> STOP");
  if (estado == CORRIENDO) tiempo_final_ms = millis() - tiempo_inicio_ms;
  sensor_armado_hasta = 0;
  estado = PARADO;
}

void cmdReset() {
  Serial.println(">>> RESET");
  tiempo_inicio_ms = 0;
  tiempo_final_ms = 0;
  tiempo_mostrado_ms = 0;
  sensor_armado_hasta = 0;
  if (xSemaphoreTake(mutex_estado, pdMS_TO_TICKS(50))) {
    tripulacion_actual = "---";
    vuelta_actual = 1;
    xSemaphoreGive(mutex_estado);
  }
  estado = IDLE;
}

void cmdSetTiempo(uint32_t ms) {
  Serial.printf(">>> SET_TIEMPO %lu ms\n", ms);
  tiempo_mostrado_ms = ms;
  estado = MOSTRANDO_TIEMPO;
}

void cmdSetTripulacion(String numero, int v) {
  Serial.printf(">>> SET_TRIPULACION %s vuelta=%d\n", numero.c_str(), v);
  if (xSemaphoreTake(mutex_estado, pdMS_TO_TICKS(50))) {
    tripulacion_actual = numero;
    vuelta_actual = v;
    xSemaphoreGive(mutex_estado);
  }
}

void cmdSensorArmado(uint32_t segundos) {
  Serial.printf(">>> SENSOR_ARMADO %lu s\n", segundos);
  sensor_armado_hasta = millis() + segundos * 1000;
}

// ================== HTTP (Core 0 — tarea separada) ==================
void confirmarComando(const char* cmd_id) {
  if (!wifi_connected) return;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/comandos/" + cmd_id + "/confirmar");
  http.addHeader("Authorization", String("Bearer ") + API_TOKEN);
  http.POST("");
  http.end();
}

// Long poll: bloquea hasta LONG_POLL_WAIT_S o hasta que haya comando.
// Devuelve true si el servidor respondio bien.
bool consultarComandos() {
  if (!wifi_connected) return false;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  String url = String(API_URL) + "/api/v1/cronometro/comandos-pendientes"
             + "?wait=" + LONG_POLL_WAIT_S
             + "&rssi=" + (int) WiFi.RSSI()
             + "&voltaje_mv=5000"
             + "&uptime_sec=" + (millis() / 1000);
  http.begin(url);
  http.addHeader("Authorization", String("Bearer ") + API_TOKEN);
  int code = http.GET();

  if (code == 200) {
    http_ok++;
    String body = http.getString();
    StaticJsonDocument<2048> doc;
    if (deserializeJson(doc, body) == DeserializationError::Ok) {
      JsonArray comandos = doc["comandos"].as<JsonArray>();
      for (JsonObject cmd : comandos) {
        const char* tipo = cmd["tipo"];
        const char* cmd_id = cmd["id"];
        if (!tipo || !cmd_id) continue;

        Serial.printf("[CMD] tipo=%s id=%s\n", tipo, cmd_id);

        if (strcmp(tipo, "start") == 0) cmdStart();
        else if (strcmp(tipo, "stop") == 0) cmdStop();
        else if (strcmp(tipo, "reset") == 0) cmdReset();
        else if (strcmp(tipo, "set_tiempo") == 0) {
          uint32_t ms = cmd["payload"]["ms"] | 0;
          cmdSetTiempo(ms);
        }
        else if (strcmp(tipo, "set_tripulacion") == 0) {
          int numero = cmd["payload"]["numero"] | 0;
          String num = String(numero);
          int v = cmd["payload"]["vuelta"] | 1;
          cmdSetTripulacion(num, v);
        }
        else if (strcmp(tipo, "sensor_armado") == 0) {
          uint32_t seg = cmd["payload"]["segundos"] | 35;
          cmdSensorArmado(seg);
        }

        confirmarComando(cmd_id);
      }
    }
  } else {
    http_err++;
  }
  http.end();
  return code == 200;
}

// ================== TAREA HTTP (Core 0) ==================
void tareaHTTP(void* param) {
  uint32_t last_status = 0;
  uint32_t last_ok_ms = millis();  // ultimo HTTP 200 exitoso
  uint32_t last_ratio_check = millis();
  uint32_t ratio_ok_start = 0;
  uint32_t ratio_err_start = 0;

  for (;;) {
    wifi_connected = (WiFi.status() == WL_CONNECTED);

    if (wifi_connected) {
      bool ok = consultarComandos();
      if (ok) {
        last_ok_ms = millis();
      } else {
        vTaskDelay(pdMS_TO_TICKS(POLL_RETRY_MS));
      }

      // Watchdog de red: sin 200 en 60s → reconectar WiFi
      uint32_t sin_respuesta = millis() - last_ok_ms;
      if (sin_respuesta > 30000) {
        Serial.println("[WATCHDOG] 30s sin respuesta, reconectando WiFi...");
        WiFi.disconnect();
        vTaskDelay(pdMS_TO_TICKS(1000));
        WiFi.reconnect();
        last_ok_ms = millis(); // reset para dar tiempo
      }
      // Sin 200 en 3 min → reiniciar ESP
      if (sin_respuesta > 180000) {
        Serial.println("[WATCHDOG] 3 min sin respuesta, reiniciando...");
        ESP.restart();
      }

      // Watchdog por ratio: en 5 min si ER > OK → reiniciar
      if (millis() - last_ratio_check > 300000) {
        uint32_t period_ok = http_ok - ratio_ok_start;
        uint32_t period_err = http_err - ratio_err_start;
        if (period_err > period_ok && period_err > 5) {
          Serial.printf("[WATCHDOG] ratio ER(%lu) > OK(%lu) en 5min, reiniciando...\n", period_err, period_ok);
          ESP.restart();
        }
        ratio_ok_start = http_ok;
        ratio_err_start = http_err;
        last_ratio_check = millis();
      }

      if (millis() - last_status > STATUS_INTERVAL_MS) {
        last_status = millis();
        Serial.printf("[STATUS] WiFi=OK HTTP OK=%u ER=%u | Estado=%d | sinResp=%lus\n", http_ok, http_err, estado, sin_respuesta/1000);
      }
    } else {
      static uint32_t last_reconnect = 0;
      if (millis() - last_reconnect > 5000) {
        Serial.println("[WiFi] reconectando...");
        WiFi.reconnect();
        last_reconnect = millis();
      }
      // Si lleva mucho sin WiFi, reiniciar
      if (millis() - last_ok_ms > 180000) {
        Serial.println("[WATCHDOG] 3 min sin WiFi, reiniciando...");
        ESP.restart();
      }
    }

    vTaskDelay(pdMS_TO_TICKS(20));
  }
}

// ================== SETUP ==================
void setup() {
  Serial.begin(115200);
  delay(1000);

  Serial.println("=====================================");
  Serial.printf("%s iniciando (dual-core)\n", CODIGO);
  Serial.println("=====================================");

  mutex_estado = xSemaphoreCreateMutex();

  // Display primero — se ve algo mientras WiFi conecta
  HUB75_I2S_CFG::i2s_pins pins = {
    R1_PIN, G1_PIN, B1_PIN, R2_PIN, G2_PIN, B2_PIN,
    A_PIN, B_PIN, C_PIN, D_PIN, E_PIN,
    LAT_PIN, OE_PIN, CLK_PIN
  };
  HUB75_I2S_CFG mxconfig(DMA_W, DMA_H, 1, pins);
  mxconfig.gpio.e = -1;
  mxconfig.clkphase = false;
  mxconfig.driver = HUB75_I2S_CFG::SHIFTREG;
  mxconfig.i2sspeed = HUB75_I2S_CFG::HZ_8M;
  mxconfig.latch_blanking = 4;
  mxconfig.min_refresh_rate = 60;

  display = new MatrixPanel_I2S_DMA(mxconfig);
  display->begin();
  display->setBrightness8(80);
  display->clearScreen();

  canvas = new GFXcanvas16(LOGIC_W, LOGIC_H);

  // WiFi — no bloqueante
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  WiFi.setTxPower(WIFI_POWER_19_5dBm);
  Serial.printf("WiFi conectando a %s (TX 19.5dBm)...\n", WIFI_SSID);

  // OTA
  ArduinoOTA.setHostname(OTA_HOST);
  ArduinoOTA.setPassword(OTA_PASS);
  ArduinoOTA.onStart([]() {
    Serial.println("OTA iniciando...");
    ota_en_curso = true;
    esp_task_wdt_delete(NULL);                 // handle() bloquea el loop: sacarlo del WDT
    if (http_task) vTaskSuspend(http_task);    // sin polls HTTP compitiendo por WiFi
    display->clearScreen();
    display->stopDMAoutput();                  // panel apagado: menos consumo y sin DMA
  });
  ArduinoOTA.onProgress([](unsigned int prog, unsigned int total) {
    static uint8_t last_pct = 255;
    uint8_t pct = (prog * 100) / total;
    if (pct != last_pct && pct % 10 == 0) { Serial.printf("OTA %u%%\n", pct); last_pct = pct; }
  });
  ArduinoOTA.onEnd([]() { Serial.println("OTA completo, reiniciando"); });
  ArduinoOTA.onError([](ota_error_t error) {
    Serial.printf("OTA Error %u, reiniciando\n", error);
    delay(500);
    ESP.restart();                             // vuelve al firmware actual limpio
  });
  ArduinoOTA.begin();
  Serial.printf("OTA listo (hostname: %s)\n", OTA_HOST);

  // Watchdog 30s — reconfigura el WDT del framework
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = 30000,
    .idle_core_mask = 0,
    .trigger_panic = true
  };
  esp_task_wdt_reconfigure(&wdt_config);
  esp_task_wdt_add(NULL);

  // Lanzar tarea HTTP en Core 0 (loop corre en Core 1)
  xTaskCreatePinnedToCore(
    tareaHTTP,    // funcion
    "http",       // nombre
    8192,         // stack bytes
    NULL,         // parametro
    1,            // prioridad
    &http_task,   // handle (para suspender durante OTA)
    0             // Core 0
  );

  Serial.println("Setup completo: Core 1=display, Core 0=HTTP");
}

// ================== LOOP (Core 1 — solo display) ==================
void loop() {
  ArduinoOTA.handle();
  if (ota_en_curso) { delay(10); return; }   // no dibujar mientras sube
  renderDisplay();
  esp_task_wdt_reset();
  delay(50);  // ~20 fps
}
