# MANUAL DE USUARIO — Sistema de Cronometraje Pioneros 4x4

## 1. Resumen del sistema

El sistema cronometra carreras 4x4 en pista doble (A+B). Cada vuelta tiene 2 corridas: en la primera el auto corre en una pista, en la segunda se cambia a la otra. El tiempo de la vuelta es la suma de ambas corridas mas penalizaciones.

Los sensores miden, los cronometros muestran, la app calcula.

## 2. Hardware

### 2.1 Servidor (Notebook)
- **Modelo**: N14-C2564
- **OS**: Ubuntu 26.04
- **IP pista (ethernet)**: 192.168.100.5
- **IP oficina (WiFi)**: 192.168.10.172 (DHCP)
- **Tailscale**: 100.66.167.123
- Corre Laravel + PostgreSQL + Nginx + PHP-FPM

### 2.2 Router pista
- SSID: PIONEROS4x4
- Password: Pioneros2024!
- Red: 192.168.100.x
- El notebook se conecta por **cable ethernet** al router

### 2.3 Cronometros LED (crono-a, crono-b)
- **Placa**: SparkleIoT ESP32-S3 XH-S3E
- **Panel**: HUB75 80x20 LED
- **Conexion**: WiFi directo al router
- **Voltaje**: 5V
- **Muestra**: P:A #numero V:vuelta + tiempo (verde corriendo, rojo parado)
- **Comandos**: por long polling — el servidor responde al instante cuando hay comando (~25ms latencia)
- **Heartbeat**: dentro del mismo GET, cada <=10s
- **Indicador sensor armado**: punto amarillo 2x2 parpadeante en esquina inferior derecha cuando el sensor esta habilitado; se limpia con start/stop/reset o al vencer
- **Watchdog**: sin respuesta 30s -> reconecta WiFi; 3 min -> reinicia solo
- **OTA**: actualizable por WiFi (hostname crono-a / crono-b)
- **Arduino IDE**: Placa ESP32S3 Dev Module, USB CDC On Boot: Enabled

### 2.4 Sensores (sensor-a, sensor-b)
- **Placa**: LILYGO T3 V1.6.1 (TTGO LoRa32-OLED rev V2.1)
- **Sensor**: M18 laser de barrera PNP dark-on, alcance ~20m, 12V
- **Receptor M18**: tiene LED indicador de haz (se apaga cuando el haz esta cortado)
- **Conexion a placa**: via modulo opto HY-M154 -> GPIO34
- **Voltaje placa**: 3.3V (alimentar por 3V3, NO por 5V)
- **Conexion LoRa**: 433MHz -> gateway -> servidor
- **Habilitacion**: por comando del servidor, ventana de 35 segundos
- **Reintento**: el sensor reenvía el cruce cada 300ms hasta recibir ACK del gateway (max 10 intentos)
- **delta_ms**: cada paquete incluye los ms desde el cruce real, para que el backend corrija el tiempo
- **Debounce**: tras un cruce ignora 3 segundos (evita doble deteccion del mismo vehiculo)
- **Filtro de ruido**: el pin debe estar activo 20ms continuo, si no se descarta como ruido
- **OLED muestra**: estado (ON/ESPERA), cruces, ACK, RSSI, GW (OK si recibio comando en <30s)

### 2.5 Semaforo
- **Placa**: LILYGO T3 V1.6.1 (TTGO LoRa32-OLED rev V2.1)
- **Reles**: 4 canales (ACTIVE LOW)
  - R1: GPIO 13
  - R2: GPIO 14
  - R3: GPIO 4
  - Verde: GPIO 25
- **Voltaje**: 12V (para los reles y luces)
- **Secuencia largada**: R1 3s -> R1+R2 3s -> R1+R2+R3 3s -> Verde 3s -> OFF (12s total)
- **Maquina de estados**: sin delay(), responde a RESET y heartbeat durante la secuencia
- **Tiempos configurables**: #define SEQ_R1_MS, SEQ_R2_MS, SEQ_R3_MS, SEQ_VERDE_MS
- **Conexion**: LoRa -> gateway
- **Reintento**: el gateway envia el comando 3 veces con 200ms entre cada uno

### 2.6 Gateway LoRa
- **Placa**: LILYGO T3 V1.6.1 (TTGO LoRa32-OLED rev V2.1)
- **Voltaje**: 5V
- **Conexion**: WiFi directo al router + LoRa 433MHz
- **Funcion**: traduce LoRa <-> HTTP
- **Dual-core**: Core 1 = LoRa RX (interrupcion) + OLED, Core 0 = HTTP
- **LoRa RX**: por interrupcion (onReceive), ring buffer de 8 paquetes — nunca pierde paquetes durante HTTP
- **ACK**: envia ACK al sensor en cada cruce, deduplicacion por (src_id, seq)
- **Polling**: cada 2s consulta comandos pendientes para sensor-a, sensor-b, semaforo
- **HTTP timeout**: 5 segundos
- **OLED muestra**: IP, RX/TX/ACK counts, HTTP OK/ER

### 2.7 POS Vizzion Q2i
- Android con impresora termica 58mm integrada
- Se conecta al WiFi PIONEROS4x4
- Accede a la app por Chrome
- Imprime tiempos con boton "Print"

## 3. App — Flujo de carrera

### 3.1 Preparacion
1. Crear fecha con categorias y penalizaciones (Config)
2. Inscribir tripulaciones (Inscripcion)
3. Activar fecha
4. Sortear orden de largada (opcional)

### 3.2 Cronometraje — Pista doble
1. **Armar par**: tocar 2 tripulaciones -> Pista A (azul) + Pista B (naranja)
2. **"Salieron a pista"**: marca ambas como en_pista
3. **Corrida 1**:
   - Tocar "Armar Largada" -> habilita sensores (35s) + semaforo
   - Contador "Sensor armado: XXs" aparece en pantalla
   - Semaforo hace secuencia 12s -> verde -> auto cruza sensor -> crono arranca
   - Tocar "Armar Llegada" -> habilita sensores
   - Auto cruza sensor -> crono para -> tiempo aparece en formulario
   - Cargar estacas, cintas, tiempos muertos -> "Guardar Corrida 1"
4. **Corrida 2**: automatico — las tripulaciones se cambian de pista
   - Repetir largada/llegada
   - "Guardar Corrida 2" -> V1 completa
5. **V2, V3**: seleccionar vuelta y repetir

### 3.3 Tiempo manual (respaldo)
Si el sensor falla, el cronometrista puede editar el tiempo en los campos min:seg:cc antes de guardar. Si el campo esta en 00:00:00 al guardar, no sobreescribe el tiempo del sensor.

### 3.4 Tiempos muertos
Se agregan en cada pista con el boton "+ TM" (minutos:segundos). Se restan del tiempo total. Se pueden quitar con la X.

## 4. Abandono y DNF

### 4.1 Abandona esta vuelta
- Boton en pantalla de cronometraje: "#3001 abandona vuelta"
- Marca la vuelta en curso como NULA (corrida 1 + 2)
- Reset del crono de esa pista
- La otra tripulacion sigue corriendo normal
- La tripulacion puede volver a largar en la vuelta siguiente

### 4.2 DNF (No corre mas)
- Boton "DNF" en la lista de tripulaciones del cronometraje
- Confirmacion: "No corre mas en esta fecha"
- La tripulacion queda fuera de la fecha con 1 punto
- Si por error se marca DNF y despues quiere correr: al armar largada la app pregunta "Esta en DNF, reincorporar?" y lo reactiva

### 4.3 Al finalizar fecha
- Se muestra la lista de DNF para confirmar antes de finalizar
- Si quedo alguno por error, se puede reincorporar antes

## 5. Ranking

- **En ranking**: tiene al menos 1 vuelta completa (tramo A + tramo B) y no es DNF
- **DNF**: estado abandonado, todas vueltas nulas, o no largo todas las vueltas (solo al finalizar)
- Se ordena por mejor vuelta (menor tiempo con penalizaciones)

## 6. Impresion

- Boton "Print" aparece cuando una vuelta tiene ambos tramos (A + B)
- Imprime en la POS: numero, piloto, vuelta, pista A + B con tiempos y penalizaciones, total
- Formato texto plano para que sea rapido en el POS

## 7. Panel de dispositivos

- Aparece en la pantalla de cronometraje
- Muestra 6 dispositivos con punto verde (online) o rojo (offline)
- Online = visto en los ultimos 60 segundos
- RSSI: intensidad de senal (menor es peor)

## 8. Voltajes por dispositivo

| Dispositivo | Voltaje |
|---|---|
| crono-a, crono-b | 5V |
| gateway | 5V |
| sensor-a, sensor-b | 3.3V |
| semaforo | 12V |

## 9. Troubleshooting

### Un crono no arranca
- Verificar que esta online (punto verde en panel)
- Si esta offline: watchdog lo reconecta en 30s, reinicia en 3 min
- El tiempo se calcula en el servidor, no en el crono. Si el crono muestra tarde, el tiempo guardado es correcto

### Un sensor no detecta
- Verificar LED del receptor M18: si esta encendido, el haz esta alineado
- Verificar en el OLED del sensor: "Cruces" debe subir al cortar el haz
- Verificar "GW: OK" — si dice "--", el gateway no le responde
- Tras un cruce, el sensor ignora 3 segundos (debounce). Esperar antes de probar de nuevo
- El sensor debe estar HABILITADO (estado ON en OLED) para enviar cruces

### El semaforo no prende
- Verificar que el gateway este online (OLED: HTTP OK subiendo)
- El comando se envia 3 veces por LoRa. Si no prende, puede ser problema de reles o alimentacion 12V
- Verificar OLED del semaforo: debe decir "R1" al iniciar la secuencia

### El gateway no conecta
- Verificar WiFi: OLED debe mostrar IP
- Verificar HTTP OK/ER: si ER sube y OK no, el servidor no responde
- Verificar que el notebook tenga IP 192.168.100.5 en ethernet
- HTTP timeout es 5s; si PHP-FPM esta saturado, puede dar ER

### Tiempos no aparecen en la app
- La app recarga cada 3 segundos automaticamente
- Si no aparecen, verificar que el sensor este online y el cruce fue detectado

## 10. Cableado

### 10.1 Sensor M18 -> placa LoRa (via modulo HY-M154)
```
M18 receptor:
  Negro (signal) -> IN del HY-M154 (G a GND 12V comun)

Modulo HY-M154:
  V -> 3V3 de la placa LoRa
  G -> GPIO34 de la placa LoRa
  20K resistencia de GPIO34 a GND
  100nF capacitor entre GPIO34 y GND (filtro ruido LoRa TX)
  JUMPERS DEL MODULO: SACADOS (con jumper entran 12V a la placa)
```

### 10.2 Semaforo -> placa LoRa
```
Rele 1 (R1) <- GPIO 13
Rele 2 (R2) <- GPIO 14
Rele 3 (R3) <- GPIO 4
Rele Verde  <- GPIO 25
VCC modulo reles <- 3.3V placa
GND modulo reles <- GND placa
JD-VCC <- 5V/12V externo (alimentacion bobinas)
```

### 10.3 Flasheo
- **Cronos (ESP32-S3)**: Arduino IDE, placa "ESP32S3 Dev Module", USB CDC On Boot: Enabled. Soportan OTA por WiFi.
- **Sensores, gateway, semaforo (LILYGO T3)**: Arduino IDE, placa "TTGO LoRa32-OLED rev V2.1 (1.6.1)". Flasheo por USB solamente.

### 10.4 Archivos firmware
```
firmware/crono_a/crono_a.ino    + config.h
firmware/crono_b/crono_b.ino    + config.h
firmware/sensor_a/sensor_a.ino
firmware/sensor_b/sensor_b.ino
firmware/gateway_lora/gateway_lora.ino + config.h
firmware/semaforo/semaforo.ino
```

### 10.5 Comunicacion
- **Cronos**: WiFi -> long polling GET cada <=10s (respuesta inmediata cuando hay comando). Heartbeat dentro del mismo GET.
- **Gateway**: dual-core, WiFi -> polling GET cada 2s (Core 0), LoRa RX por interrupcion (Core 1). Heartbeat POST cada 5s. ACK a sensores en cruces.
- **Sensores**: LoRa 433MHz -> gateway. Cruce con reintento (300ms x 10) hasta ACK. delta_ms en paquete. Heartbeat cada 5s.
- **Semaforo**: LoRa 433MHz -> gateway. Heartbeat cada 5s. Secuencia por maquina de estados (sin delay).
