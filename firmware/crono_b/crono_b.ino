/*
 * PIONEROS 4x4 - CRONOMETRO CRONO-B (WiFi + HUB75 + OTA)
 * Placa: SparkleIoT ESP32-S3 XH-S3E
 * Panel: HUB75 80x20 (DMA 160x10)
 * DUAL-CORE: Core 1=display 20fps, Core 0=HTTP
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
#define HTTP_TIMEOUT_MS        2000
#define POLL_INTERVAL_MS       2000
#define HEARTBEAT_INTERVAL_MS  5000

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

  flushCanvas();
}

// ================== ACCIONES (llamadas desde Core 0) ==================
void cmdStart() {
  Serial.println(">>> START");
  tiempo_inicio_ms = millis();
  tiempo_final_ms = 0;
  estado = CORRIENDO;
}

void cmdStop() {
  Serial.println(">>> STOP");
  if (estado == CORRIENDO) tiempo_final_ms = millis() - tiempo_inicio_ms;
  estado = PARADO;
}

void cmdReset() {
  Serial.println(">>> RESET");
  tiempo_inicio_ms = 0;
  tiempo_final_ms = 0;
  tiempo_mostrado_ms = 0;
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

// ================== HTTP (Core 0 — tarea separada) ==================
void enviarHeartbeat() {
  if (!wifi_connected) return;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/heartbeat");
  http.addHeader("Authorization", String("Bearer ") + API_TOKEN);
  http.addHeader("Content-Type", "application/json");
  StaticJsonDocument<128> doc;
  doc["rssi"] = WiFi.RSSI();
  doc["voltaje_mv"] = 5000;
  doc["uptime_sec"] = millis() / 1000;
  String body;
  serializeJson(doc, body);
  int code = http.POST(body);
  http.end();
  if (code >= 200 && code < 300) http_ok++;
  else http_err++;
}

void confirmarComando(const char* cmd_id) {
  if (!wifi_connected) return;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/comandos/" + cmd_id + "/confirmar");
  http.addHeader("Authorization", String("Bearer ") + API_TOKEN);
  http.POST("");
  http.end();
}

void consultarComandos() {
  if (!wifi_connected) return;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(String(API_URL) + "/api/v1/cronometro/comandos-pendientes");
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

        confirmarComando(cmd_id);
      }
    }
  } else {
    http_err++;
  }
  http.end();
}

// ================== TAREA HTTP (Core 0) ==================
void tareaHTTP(void* param) {
  uint32_t last_poll = 0;
  uint32_t last_hb = 0;

  for (;;) {
    wifi_connected = (WiFi.status() == WL_CONNECTED);

    if (wifi_connected) {
      if (millis() - last_poll > POLL_INTERVAL_MS) {
        consultarComandos();
        last_poll = millis();
      }

      if (millis() - last_hb > HEARTBEAT_INTERVAL_MS) {
        enviarHeartbeat();
        last_hb = millis();
        Serial.printf("[STATUS] WiFi=OK HTTP OK=%u ER=%u | Estado=%d\n", http_ok, http_err, estado);
      }
    } else {
      static uint32_t last_reconnect = 0;
      if (millis() - last_reconnect > 5000) {
        Serial.println("[WiFi] reconectando...");
        WiFi.reconnect();
        last_reconnect = millis();
      }
    }

    vTaskDelay(pdMS_TO_TICKS(100));
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
  display->setBrightness8(100);
  display->clearScreen();

  canvas = new GFXcanvas16(LOGIC_W, LOGIC_H);

  // WiFi — no bloqueante
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.printf("WiFi conectando a %s...\n", WIFI_SSID);

  // OTA
  ArduinoOTA.setHostname(OTA_HOST);
  ArduinoOTA.setPassword(OTA_PASS);
  ArduinoOTA.onStart([]() {
    Serial.println("OTA iniciando...");
    display->clearScreen();
  });
  ArduinoOTA.onEnd([]() { Serial.println("OTA completo"); });
  ArduinoOTA.onError([](ota_error_t error) { Serial.printf("OTA Error %u\n", error); });
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
    NULL,         // handle
    0             // Core 0
  );

  Serial.println("Setup completo: Core 1=display, Core 0=HTTP");
}

// ================== LOOP (Core 1 — solo display) ==================
void loop() {
  ArduinoOTA.handle();
  renderDisplay();
  esp_task_wdt_reset();
  delay(50);  // ~20 fps
}
