/*
 * PIONEROS 4x4 - SEMAFORO LoRa
 * Placa: LILYGO T3 V1.6.1
 * Secuencia largada: R1 3s → R1+R2 3s → R1+R2+R3 3s → VERDE 3s → OFF
 *
 * Reles ACTIVE LOW (GPIO LOW = rele activo = LED encendido)
 * Comandos serie: l=largada, r=rojo, v=verde, o=off, s=estado
 */

#include <SPI.h>
#include <LoRa.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

#define CODIGO   "semaforo"
#define SRC_ID   3

// Pinout T3 V1.6.1
#define LORA_SCK   5
#define LORA_MISO  19
#define LORA_MOSI  27
#define LORA_CS    18
#define LORA_RST   23
#define LORA_DIO0  26
#define LED_PIN    25

// Reles (ACTIVE LOW)
#define RELE_ROJO1  13
#define RELE_ROJO2  14
#define RELE_ROJO3  4
#define RELE_VERDE  25

// Protocolo LoRa
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

Adafruit_SSD1306 display(128, 64, &Wire, -1);
uint16_t seq_tx = 0;
uint32_t last_hb = 0;
uint32_t largadas_count = 0;
uint32_t ack_count = 0;
int last_rssi = 0;

// Secuencia con maquina de estados (sin delay)
// Tiempos configurables
#define SEQ_R1_MS     3000
#define SEQ_R2_MS     3000
#define SEQ_R3_MS     3000
#define SEQ_VERDE_MS  3000

enum SeqFase { SEQ_IDLE, SEQ_R1, SEQ_R1R2, SEQ_R1R2R3, SEQ_VERDE };
SeqFase seq_fase = SEQ_IDLE;
uint32_t seq_inicio_fase = 0;
bool secuencia_en_curso = false;

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

void apagarTodo() {
  digitalWrite(RELE_ROJO1, HIGH);
  digitalWrite(RELE_ROJO2, HIGH);
  digitalWrite(RELE_ROJO3, HIGH);
  digitalWrite(RELE_VERDE, HIGH);
}

void updateDisplay(const char* estado) {
  display.clearDisplay();
  display.setCursor(0, 0);
  display.setTextSize(1);
  display.println(CODIGO);
  display.print("Estado: ");
  display.println(estado);
  display.print("Largadas: ");
  display.println(largadas_count);
  display.print("ACK: ");
  display.print(ack_count);
  display.print(" RSSI:");
  display.println(last_rssi);
  display.print("Up:");
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
}

void enviarHeartbeat() {
  uint8_t extra[8];
  int16_t rssi_val = (int16_t)last_rssi;
  uint16_t voltaje = 12000;
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

void iniciarSecuenciaLargada() {
  if (secuencia_en_curso) {
    Serial.println("Ya en curso, ignorado");
    return;
  }
  largadas_count++;
  secuencia_en_curso = true;
  seq_fase = SEQ_R1;
  seq_inicio_fase = millis();

  apagarTodo();
  digitalWrite(RELE_ROJO1, LOW);
  Serial.println(">>> LARGADA: R1");
  updateDisplay("R1");
}

void cancelarSecuencia() {
  secuencia_en_curso = false;
  seq_fase = SEQ_IDLE;
  apagarTodo();
  Serial.println("Secuencia cancelada");
  updateDisplay("ESPERA");
}

void actualizarSecuencia() {
  if (!secuencia_en_curso) return;
  uint32_t elapsed = millis() - seq_inicio_fase;

  switch (seq_fase) {
    case SEQ_R1:
      if (elapsed >= SEQ_R1_MS) {
        seq_fase = SEQ_R1R2;
        seq_inicio_fase = millis();
        digitalWrite(RELE_ROJO2, LOW);
        Serial.println("R1+R2");
        updateDisplay("R1+R2");
      }
      break;
    case SEQ_R1R2:
      if (elapsed >= SEQ_R2_MS) {
        seq_fase = SEQ_R1R2R3;
        seq_inicio_fase = millis();
        digitalWrite(RELE_ROJO3, LOW);
        Serial.println("R1+R2+R3");
        updateDisplay("R1+R2+R3");
      }
      break;
    case SEQ_R1R2R3:
      if (elapsed >= SEQ_R3_MS) {
        seq_fase = SEQ_VERDE;
        seq_inicio_fase = millis();
        digitalWrite(RELE_ROJO1, HIGH);
        digitalWrite(RELE_ROJO2, HIGH);
        digitalWrite(RELE_ROJO3, HIGH);
        digitalWrite(RELE_VERDE, LOW);
        Serial.println("VERDE");
        updateDisplay("VERDE");
      }
      break;
    case SEQ_VERDE:
      if (elapsed >= SEQ_VERDE_MS) {
        apagarTodo();
        secuencia_en_curso = false;
        seq_fase = SEQ_IDLE;
        Serial.println("OFF");
        updateDisplay("ESPERA");
      }
      break;
    default:
      break;
  }
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

  ack_count++;

  if (tipo == CMD_SEMAFORO_LARGADA) {
    iniciarSecuenciaLargada();
  } else if (tipo == CMD_RESET) {
    cancelarSecuencia();
  }
}

void procesarComandoSerial() {
  if (Serial.available() > 0) {
    char c = Serial.read();
    while (Serial.available()) Serial.read();

    switch (c) {
      case 'l': case 'L':
        iniciarSecuenciaLargada();
        break;
      case 'r': case 'R':
        Serial.println(">>> ROJOS ON");
        apagarTodo();
        digitalWrite(RELE_ROJO1, LOW);
        digitalWrite(RELE_ROJO2, LOW);
        digitalWrite(RELE_ROJO3, LOW);
        updateDisplay("ROJOS");
        break;
      case 'v': case 'V':
        Serial.println(">>> VERDE ON");
        apagarTodo();
        digitalWrite(RELE_VERDE, LOW);
        updateDisplay("VERDE");
        break;
      case 'o': case 'O':
        Serial.println(">>> OFF");
        apagarTodo();
        updateDisplay("ESPERA");
        break;
      case 's': case 'S':
        Serial.printf("Largadas:%d ACK:%d RSSI:%d Up:%ds\n",
          largadas_count, ack_count, last_rssi, millis()/1000);
        break;
      case '\n': case '\r': break;
      default:
        Serial.println("l=largada r=rojo v=verde o=off s=estado");
    }
  }
}

void setup() {
  Serial.begin(115200);

  // Reles OFF antes de todo
  pinMode(RELE_ROJO1, OUTPUT); digitalWrite(RELE_ROJO1, HIGH);
  pinMode(RELE_ROJO2, OUTPUT); digitalWrite(RELE_ROJO2, HIGH);
  pinMode(RELE_ROJO3, OUTPUT); digitalWrite(RELE_ROJO3, HIGH);
  pinMode(RELE_VERDE, OUTPUT); digitalWrite(RELE_VERDE, HIGH);

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
  Serial.printf("%s listo\n", CODIGO);
  Serial.println("l=largada r=rojo v=verde o=off s=estado");
  Serial.println("=====================================");

  delay(2000);
  updateDisplay("ESPERA");
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
  }

  procesarComandoSerial();
  actualizarSecuencia();

  if (millis() - last_hb > HEARTBEAT_INTERVAL_MS) {
    enviarHeartbeat();
    last_hb = millis();
    updateDisplay(secuencia_en_curso ? "SECUENCIA" : "ESPERA");
  }
}
