/*
 * PIONEROS 4x4 - SENSOR-B LoRa
 * Placa: LILYGO T3 V1.6.1
 * Sensor: M18 laser PNP dark-on (12V) via opto PC817 HY-M154 -> GPIO34, interrupcion RISING
 *
 * Comandos serie (115200):
 *   c = simular cruce
 *   h = habilitar sensor 20s
 *   d = deshabilitar
 *   s = mostrar estado
 */

#include <SPI.h>
#include <LoRa.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

#define CODIGO         "sensor-b"
#define TRAMO          'B'
#define SRC_ID         2

#define LORA_SCK   5
#define LORA_MISO  19
#define LORA_MOSI  27
#define LORA_CS    18
#define LORA_RST   23
#define LORA_DIO0  26
#define LED_PIN    25

#define SENSOR_PIN     34      // salida opto (emisor) + 10k a GND, solo entrada
#define DEBOUNCE_MS    2000   // ignora cortes repetidos del mismo vehiculo

#define MAGIC         0xA5
#define VERSION       0x01

#define ID_GATEWAY    0
#define ID_SENSOR_A   1
#define ID_SENSOR_B   2
#define ID_SEMAFORO   3

#define EV_CRUCE               0x01
#define EV_HEARTBEAT           0x02
#define CMD_HABILITAR_SENSOR   0x10
#define CMD_SEMAFORO_LARGADA   0x11
#define CMD_RESET              0x12
#define EV_ACK                 0xF0

#define HEARTBEAT_INTERVAL_MS  5000
#define HABILITADO_DURACION_MS 20000

Adafruit_SSD1306 display(128, 64, &Wire, -1);
uint16_t seq_tx = 0;
uint32_t last_hb = 0;
uint32_t cruces_count = 0;
uint32_t ack_count = 0;
uint32_t last_ack_ms = 0;
int last_rssi = 0;

bool habilitado = false;
uint32_t habilitado_hasta = 0;

volatile bool cruce_pendiente = false;
volatile uint32_t ultimo_cruce_isr_ms = 0;
uint32_t ignorados_count = 0;

void IRAM_ATTR sensorISR() {
  uint32_t ahora = millis();
  if (ahora - ultimo_cruce_isr_ms < DEBOUNCE_MS) return;
  ultimo_cruce_isr_ms = ahora;
  cruce_pendiente = true;
}

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

void updateDisplay() {
  display.clearDisplay();
  display.setCursor(0, 0);
  display.setTextSize(1);
  display.println(CODIGO);
  display.print("Estado: ");
  if (habilitado) {
    display.print("ON ");
    uint32_t restante = habilitado_hasta > millis() ? (habilitado_hasta - millis()) / 1000 : 0;
    display.print(restante);
    display.println("s");
  } else {
    display.println("ESPERA");
  }
  display.print("Cruces: ");
  display.println(cruces_count);
  display.print("ACK: ");
  display.print(ack_count);
  display.print(" RSSI:");
  display.println(last_rssi);
  uint32_t desde_ack = last_ack_ms == 0 ? 999 : (millis() - last_ack_ms) / 1000;
  display.print("GW: ");
  if (desde_ack < 30) display.print("OK");
  else display.print("--");
  display.print(" Up:");
  display.print(millis() / 1000);
  display.println("s");
  display.display();
}

void enviarLoRa(uint8_t dst_id, uint8_t tipo, uint8_t* extra, size_t extra_len) {
  uint8_t packet[20];
  packet[0] = MAGIC;
  packet[1] = VERSION;
  packet[2] = SRC_ID;
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
  Serial.printf("TX tipo=0x%02X seq=%d\n", tipo, seq_tx - 1);
}

void enviarCruce() {
  uint8_t tramo_byte = (uint8_t)TRAMO;
  enviarLoRa(ID_GATEWAY, EV_CRUCE, &tramo_byte, 1);
  cruces_count++;
}

void enviarHeartbeat() {
  uint8_t extra[8];
  int16_t rssi_val = (int16_t)last_rssi;
  uint16_t voltaje = 3300;
  uint32_t uptime = millis() / 1000;
  extra[0] = rssi_val & 0xFF;
  extra[1] = (rssi_val >> 8) & 0xFF;
  extra[2] = voltaje & 0xFF;
  extra[3] = (voltaje >> 8) & 0xFF;
  extra[4] = uptime & 0xFF;
  extra[5] = (uptime >> 8) & 0xFF;
  extra[6] = (uptime >> 16) & 0xFF;
  extra[7] = (uptime >> 24) & 0xFF;
  enviarLoRa(ID_GATEWAY, EV_HEARTBEAT, extra, 8);
}

void procesarPaqueteLoRa(uint8_t* pkt, size_t len) {
  if (len < 9) return;
  if (pkt[0] != MAGIC || pkt[1] != VERSION) return;
  uint16_t crc_rx = pkt[len - 2] | (pkt[len - 1] << 8);
  uint16_t crc_calc = crc16(pkt, len - 2);
  if (crc_rx != crc_calc) return;
  uint8_t dst_id = pkt[3];
  uint8_t tipo = pkt[4];
  if (dst_id != SRC_ID && dst_id != 0xFF) return;
  last_ack_ms = millis();
  ack_count++;
  if (tipo == CMD_HABILITAR_SENSOR) {
    uint16_t dur_seg = pkt[7] | (pkt[8] << 8);
    if (dur_seg == 0) dur_seg = 20;
    habilitado = true;
    habilitado_hasta = millis() + (dur_seg * 1000UL);
    Serial.printf("HABILITADO por %d segundos\n", dur_seg);
  } else if (tipo == CMD_RESET) {
    habilitado = false;
    habilitado_hasta = 0;
    Serial.println("RESET");
  }
}

void procesarComandoSerial() {
  if (Serial.available() > 0) {
    char c = Serial.read();
    while (Serial.available()) Serial.read();
    switch (c) {
      case 'c': case 'C':
        Serial.println(">>> SIMULANDO CRUCE");
        if (habilitado) {
          digitalWrite(LED_PIN, HIGH);
          enviarCruce();
          updateDisplay();
          delay(100);
          digitalWrite(LED_PIN, LOW);
        } else {
          Serial.println("    (ignorado, sensor NO habilitado)");
        }
        break;
      case 'h': case 'H':
        habilitado = true;
        habilitado_hasta = millis() + HABILITADO_DURACION_MS;
        Serial.println(">>> HABILITADO MANUAL 20s");
        updateDisplay();
        break;
      case 'd': case 'D':
        habilitado = false;
        habilitado_hasta = 0;
        Serial.println(">>> DESHABILITADO");
        updateDisplay();
        break;
      case 's': case 'S':
        Serial.printf(">>> Estado: %s | Cruces: %d | Ignorados: %d | ACK: %d | RSSI: %d | Sensor: %d | Up: %ds\n",
                      habilitado ? "ON" : "OFF", cruces_count, ignorados_count, ack_count, last_rssi,
                      digitalRead(SENSOR_PIN), millis()/1000);
        break;
      case '\n': case '\r': break;
      default:
        Serial.println("c=cruce h=habilitar d=deshabilitar s=estado");
    }
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(LED_PIN, OUTPUT);
  pinMode(SENSOR_PIN, INPUT);   // GPIO34 no tiene pull-down interno; el 10k externo a GND lo fija en LOW
  delay(1000);
  Wire.begin(21, 22);
  display.begin(SSD1306_SWITCHCAPVCC, 0x3C);
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.println(CODIGO);
  display.println("Iniciando...");
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
  Serial.println("=====================================");
  Serial.printf("%s listo (SRC_ID=%d TRAMO=%c)\n", CODIGO, SRC_ID, TRAMO);
  Serial.println("c=cruce h=habilitar d=deshabilitar s=estado");
  Serial.println("=====================================");
  delay(2000);
  updateDisplay();
  attachInterrupt(digitalPinToInterrupt(SENSOR_PIN), sensorISR, RISING);
  Serial.printf("Sensor M18 en GPIO%d (RISING, debounce %dms)\n", SENSOR_PIN, DEBOUNCE_MS);
}

void procesarCruceSensor() {
  if (!cruce_pendiente) return;
  noInterrupts();
  cruce_pendiente = false;
  interrupts();
  if (habilitado) {
    digitalWrite(LED_PIN, HIGH);
    enviarCruce();
    Serial.println(">>> CRUCE SENSOR");
    updateDisplay();
    digitalWrite(LED_PIN, LOW);
  } else {
    ignorados_count++;
    Serial.println("Cruce sensor ignorado (NO habilitado)");
  }
}

void loop() {
  int packetSize = LoRa.parsePacket();
  if (packetSize > 0 && packetSize <= 20) {
    uint8_t pkt[20];
    int i = 0;
    while (LoRa.available() && i < 20) pkt[i++] = LoRa.read();
    last_rssi = LoRa.packetRssi();
    procesarPaqueteLoRa(pkt, i);
    LoRa.receive();
    updateDisplay();
  }
  procesarCruceSensor();
  if (habilitado && millis() > habilitado_hasta) {
    habilitado = false;
    Serial.println("Habilitacion expirada");
    updateDisplay();
  }
  procesarComandoSerial();
  if (millis() - last_hb > HEARTBEAT_INTERVAL_MS) {
    enviarHeartbeat();
    last_hb = millis();
    updateDisplay();
  }
}
