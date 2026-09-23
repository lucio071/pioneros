# CHANGELOG — Sistema de Cronometraje Pioneros 4x4

## 2026-09-23

### 1. Precision timestamps (e0b53dd, dc9e84c)
- Migracion: ALTER COLUMN TYPE timestamp(3) en comandos, eventos, estado
- Modelos: $dateFormat = 'Y-m-d H:i:s.v', casts con .v
- Fix: timestamp_servidor formateado con ->format() (fillable bypass $dateFormat)
- Verificado: created_at muestra ms (09:38:00.812), diff medible

### 2. Vuelta incorrecta (6f5b00d)
- Causa: selectedVuelta persistia en localStorage de prueba anterior
- PWA: calcularVueltaPendiente() busca primera vuelta sin tramos completos en ambas trips
- Backend: armarLargadaDoble rechaza vuelta > 1 si anterior no completa/nula (422)
- enviarTripulacionACrono ahora pasa vuelta_numero en payload

### 3. Latencia sensor->crono (medicion)
- Cruce real -> evento created_at: 187ms (curl localhost)
- Evento -> stop created: 16ms (backend)
- Total cruce -> stop: 203ms (sin WiFi real)
- Pendiente: medir entregado_at con long polling real

### 4. Limpieza
- Borradas 3 fechas de prueba, 4 reales preservadas
- Estados cronometraje en idle, comandos limpiados

### 5. Prueba general
- Fecha "Prueba general": doble, 3 vueltas, T3, 4 trips (#301-304), penal 5/10
- Guion: V1 2 pares, ranking, V2 con abandono, DNF+reincorporar, finalizar

### Manual: restaurado + tecnico separado (2bfac06)
- MANUAL_USUARIO.md restaurado version operador (671 lineas)
- MANUAL_TECNICO.md separado como doc tecnico
- Ediciones puntuales: notebook N14-C2564, M18 laser, verde 5s, 35s ventana, abandono/DNF
- CLAUDE.md: regla "nunca reescribir manual usuario"

### A. Ventana sensor 35s (da4070a)
- Backend habilitarSensor: duracion_seg 20 -> 35
- Gateway lee duracion_seg del payload (no hardcoded 20)
- Sensor HABILITADO_DURACION_MS 35000

### B. Gateway: confirmar solo si se encolo (da4070a)
- Si tx_q llena, no confirma al servidor (antes confirmaba y perdia el comando)

### C. Semaforo: millis() sin delay (da4070a)
- Maquina de estados (SEQ_R1, SEQ_R1R2, SEQ_R1R2R3, SEQ_VERDE)
- Responde a RESET y heartbeat durante la secuencia
- Tiempos configurables con #define

### D. Indicador sensor armado (da4070a)
- Backend crea comando sensor_armado para crono al habilitar sensor
- Crono: punto amarillo 2x2 parpadeante en esquina inferior derecha
- PWA: contador "Sensor armado: XXs" parpadeante amarillo->rojo

### Gateway dual-core + ACK + deduplicacion (da4070a)
- LoRa RX por interrupcion (onReceive) — nunca pierde paquetes durante HTTP
- Ring buffer de 8 paquetes, procesados en Core 1
- HTTP en Core 0 (polls, eventos, heartbeat)
- ACK al sensor en cada cruce, deduplicacion por (src_id, seq)
- CMD_HABILITAR_SENSOR enviado 3x como semaforo

### Sensor cruce con reintento + delta_ms (da4070a)
- Reenvio cada 300ms hasta ACK (max 10 intentos), seq fijo
- delta_ms en paquete (ms desde cruce real) para precision
- Backend resta delta_ms de now() para timestamp exacto

### E. Manual actualizado (1ee401f)
- Ventana 35s, semaforo millis, gateway dual-core, indicador sensor armado

## 2026-09-22

### 8. Eliminar fecha — solo admin (3768742)
- Boton "Eliminar fecha" visible en cualquier estado, solo para admin
- Fechas activas/finalizadas: pide escribir el nombre para confirmar
- Backend: 403 si no es admin; resetea EstadoCronometraje si fecha activa
- Cascada via FK borra categorias, tripulaciones, vueltas, tramos, TM

### 7. DNF / reincorporar (05aa286)
- POST /tripulaciones/{id}/reincorporar: estado <- en_pista + actualizarEstado
- Auto-reincorporar: al armar largada, si trip esta en DNF pregunta si reincorporar
- Al finalizar fecha: muestra lista de DNF para confirmar antes de ejecutar
- Botones DNF y Reinc. en lista de tripulaciones del cronometraje
- "Abandona esta vuelta" (solo anula la vuelta, trip sigue activa para las siguientes)
- "DNF" = fuera de la fecha (1 punto)

### Semaforo GPIOs (541d64b, 2e26ed5)
- R3: GPIO 32 -> 4 (no flota LOW al boot)
- Verde: GPIO 33 -> 25 (usa pin LED, no flota al boot)

## 2026-09-21

### 6. MANUAL_USUARIO.md (fa2ee8e)
- Manual completo: hardware, cableado, troubleshooting, flujo de carrera
- Notebook modelo corregido: N14-C2564 (no BMAX B1)
- Sensor M18 laser de barrera (no ABT-30 infrarrojo)
- Voltajes por dispositivo: cronos/gateway 5V, sensores 3.3V, semaforo 12V
- Flasheo: ESP32S3 Dev Module para cronos, TTGO LoRa32-OLED para sensores/gateway/semaforo

### 5. Long polling cronos (6f6e923)
- GET comandos-pendientes?wait=10: servidor retiene hasta 10s, responde instantaneo
- Heartbeat dentro del mismo GET via query params (rssi, voltaje, uptime)
- Eliminado POST /heartbeat separado en cronos
- Backend: usleep 25ms loop, set_time_limit(15)
- PHP-FPM: pm.max_children 5 -> 15

### 4. Watchdog de red en cronos (1551a4c, f605ba0)
- 30s sin HTTP 200 -> reconecta WiFi
- 3 min sin respuesta -> ESP.restart()
- Status line muestra segundos desde ultima respuesta exitosa

### 3. Gateway HTTP timeout (c840daf)
- HTTP_TIMEOUT_MS 2s -> 5s
- Causa raiz de fallo en pista: gateway cerraba conexion antes de que PHP-FPM respondiera (499)
- Gateway envia semaforo_largada 3x con 200ms (reintento LoRa)

### 2. Pista doble — corrida 1/2 (9e00c52)
- Header muestra quien corre en cada pista por corrida
- Corrida 1 se bloquea despues de guardar (checkmark)
- Corrida 2 bloqueada hasta guardar corrida 1
- Se cambian automaticamente de pista en corrida 2
- "Salieron a pista" marca ambas tripulaciones
- Armar Largada llama armar-largada-doble (sensores + semaforo + cronos)
- Armar Llegada llama armar-llegada-doble (sensores)
- Armar Largada se deshabilita mientras cronos corren
- Backend: registrarEvento acepta tipo "cruce" (gateway) ademas de "sensor_disparado"
- Backend: enviarTripulacionACrono castea numero a (int)
- Backend: reintento automatico de comandos entregados > 1s

### 1. Formularios tramo A+B (76bfcc0)
- Tramos A y B lado a lado en grid 2 columnas
- Auto-fill formularios con tiempo del sensor (cada uno independiente)
- No sobreescribe tiempo del sensor si formulario esta en 00:00:00
- Tiempos muertos integrados en cada formulario
- Precarga datos existentes al cambiar de vuelta
- Bloqueo secuencial de vueltas (V2 hasta V1 completa)
- Par persistido en localStorage
- Polling de dispositivos al entrar en tab crono
- Umbral online de 30s a 60s
- Print por trip (texto plano para POS)
- Guardar Corrida se deshabilita cuando vuelta completa

### Sensores M18 (76cdbd4, fa2ee8e)
- Sensor M18 laser PNP dark-on via opto HY-M154 en GPIO34
- SENSOR_ACTIVO configurable (HIGH/LOW)
- Interrupcion RISING/FALLING segun cableado
- Confirmacion 20ms (filtro ruido LoRa TX)
- Debounce 3000ms
- Status muestra ruido_count y estado del haz
