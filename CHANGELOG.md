# CHANGELOG — Sistema de Cronometraje Pioneros 4x4

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
