# MANUAL DE USUARIO
# Sistema de Cronometraje Pioneros 4x4

**Version:** 2026 · Para uso del organizador de carrera

---

## TABLA DE CONTENIDOS

1. Que es el sistema
2. Componentes del sistema
3. Antes de la carrera (en la oficina)
4. Montaje en pista
5. Durante la carrera — Pista doble
6. Durante la carrera — Pista simple
7. Despues de la carrera
8. Panel de dispositivos
9. Problemas comunes y soluciones
10. Datos tecnicos (para el tecnico)

---

## 1. QUE ES EL SISTEMA

El sistema Pioneros 4x4 es un sistema de cronometraje electronico para competencias 4x4 off-road. Mide los tiempos de cada tripulacion con sensores infrarrojos colocados en la pista, los muestra en tiempo real en cronometros LED fisicos, y calcula el ranking automaticamente.

Todo se administra desde la aplicacion web (la "app"), que funciona tanto en la notebook de pista como en celulares conectados a la red WiFi local.

**Lo que el sistema hace automaticamente:**
- Detecta cuando un auto cruza el sensor de largada y arranca el cronometro
- Detecta cuando el auto cruza el sensor de llegada, para el cronometro y calcula el tiempo
- Ordena el ranking por categoria segun el mejor tiempo de cada tripulacion
- Suma penalizaciones por estacas y cintas derribadas
- Acumula puntos de campeonato

**Si falla algun sensor:** el cronometrista ingresa el tiempo a mano. El sistema siempre tiene respaldo manual.

---

## 2. COMPONENTES DEL SISTEMA

### 2.1 Notebook (el "cerebro")

- Computadora portatil **N14-C2564** con Ubuntu Linux
- Funciona como servidor local en pista
- Tiene la aplicacion web corriendo en `http://192.168.100.5`
- La pantalla arranca sola con la app en pantalla completa
- **No apagar la notebook durante la carrera**

### 2.2 Router WiFi AC1200

- Router de color negro/gris
- Crea la red WiFi de pista: **PIONEROS4x4**
- Se conecta a la notebook por cable de red (ethernet)
- Todos los dispositivos electronicos se conectan a esta red
- **No tocar la configuracion del router**

### 2.3 Cronometros LED (crono-a y crono-b)

- Paneles LED grandes que muestran el tiempo en pista
- **crono-a** va en la pista A (generalmente la primera pista)
- **crono-b** va en la pista B (en carreras de pista doble)
- Muestran: numero de la tripulacion, numero de vuelta, y el tiempo corriendo en verde / tiempo final en rojo
- Se conectan por WiFi a la red PIONEROS4x4 automaticamente al encender
- Reciben comandos de la app (arrancar, parar, mostrar tiempo)

### 2.4 Sensores laser (sensor-a y sensor-b)

- Dispositivos ESP32 con sensor laser **M18 de barrera** (emisor + receptor), alcance ~20 metros
- El receptor tiene un **LED indicador de haz**: encendido = haz alineado, apagado = haz cortado
- **sensor-a** mide en la pista A
- **sensor-b** mide en la pista B
- Se comunican por **radio LoRa** (433 MHz) con el gateway, no por WiFi
- Detectan el cruce del auto cuando el haz se corta, y mandan el evento al sistema
- Tras un cruce, ignoran 3 segundos (evita doble deteccion del mismo vehiculo)
- Funcionan con alimentacion 12V (sensor) + 3.3V (placa LoRa)

### 2.5 Semaforo

- Luces de semaforo (rojo / verde) para la largada
- Se controla por radio LoRa desde el gateway
- Cuando el operador pulsa "Armar largada" en la app: secuencia de 3 rojos progresivos (R1 3s, R1+R2 3s, R1+R2+R3 3s) + verde 5s (14 segundos en total)
- Funciona con alimentacion propia

### 2.6 Gateway LoRa

- Dispositivo **LILYGO T3** con antena LoRa
- Hace de puente entre los sensores/semaforo (radio) y la notebook (WiFi)
- Tiene una pantallita OLED que muestra estado de conexion, paquetes recibidos y errores
- Se conecta por WiFi a la red PIONEROS4x4
- Debe estar ubicado en un punto intermedio con buena linea de vista hacia los sensores

### 2.7 Celulares de los operadores

- Cualquier celular conectado a la red **PIONEROS4x4**
- Abrir el navegador y entrar a: `http://192.168.100.5`
- Desde ahi se puede ver el ranking en tiempo real, los tiempos, y todo el estado de la carrera

---

## 3. ANTES DE LA CARRERA (EN LA OFICINA)

Esta parte se hace en la oficina, antes del dia de la carrera, con la notebook conectada a internet (red WiFi de oficina: G5_CLARO_2.4). O bien, puede hacerse desde la nube en `https://pioneros.upper.com.py`.

### 3.1 Ingresar al sistema

1. Abrir el navegador y entrar a la direccion de la app
2. Ir a `/login` e ingresar con usuario y clave del administrador
3. Se abre el **Panel del cronometrista**

### 3.2 Crear las categorias del sistema (una sola vez)

Las categorias son los grupos de competencia (por ejemplo: Standard, Modificado, Extreme). Solo se crean una vez y quedan disponibles para todas las fechas.

1. En el Panel del cronometrista, buscar la seccion **"Categorias"**
2. Tocar **"+ Nueva categoria"**
3. Escribir el nombre (ejemplo: `Standard`)
4. Tocar **"Crear"**
5. Repetir para cada categoria

> Si ya existen las categorias de temporadas anteriores, no hace falta crearlas de nuevo.

### 3.3 Crear una nueva fecha

1. En el Panel del cronometrista, tocar **"+ Nueva fecha"**
2. Se abre el asistente (wizard) en **Paso 1**:
   - **Nombre:** el nombre de la competencia (ejemplo: `Desafio Hernandarias`)
   - **Fecha:** el dia del evento
   - **Pista:** elegir `Doble (A+B)` si hay dos pistas paralelas, o `Simple` si hay una sola pista
   - **Vueltas clasificacion:** cuantas vueltas hace cada tripulacion para clasificar (normalmente 2 o 3)
   - **Final:** activar si habra una vuelta final entre los mejores. Si se activa, definir cuantos finalistas (ejemplo: Top 3)
   - **Campeonato:** si esta fecha suma puntos al campeonato anual, elegir el campeonato aqui
3. Tocar **"Siguiente: Categorias"**
4. En el **Paso 2**, marcar las categorias que participan en esta fecha
   - Para cada categoria marcada, definir las penalizaciones:
     - **Estaca (segundos):** segundos que se suman al tiempo por cada estaca derribada (normalmente 5)
     - **Cinta (segundos):** segundos que se suman al tiempo por cada cinta cortada (normalmente 10)
5. Tocar **"Crear fecha"**

El sistema crea la fecha y abre automaticamente la pagina de detalle de esa fecha.

### 3.4 Inscribir tripulaciones

1. Dentro de la fecha, tocar la pestana **"Inscripcion"**
2. Seleccionar la categoria de la tripulacion (pestanas de colores arriba)
3. Completar el formulario:
   - **N°:** numero de la tripulacion (ejemplo: `401`)
   - **Nombre tripulacion:** nombre del equipo o vehiculo
   - **Piloto:** nombre del piloto
   - **Copiloto:** nombre del copiloto (opcional)
4. Tocar **"+ Inscribir"**
5. Repetir para cada tripulacion

Para editar una tripulacion ya inscripta: tocar **"Editar"** en la lista.
Para eliminar: tocar **"Eliminar"**.

> El numero de tripulacion debe ser unico dentro de la categoria.

### 3.5 Activar la fecha

Una vez que todo esta listo (categorias y tripulaciones cargadas), hay que activar la fecha para poder cronometrar.

1. Ir a la pestana **"Config"** de la fecha
2. Verificar que los datos sean correctos (pista, vueltas, categorias, penalizaciones)
3. Tocar **"Activar fecha"**
4. Leer el mensaje de confirmacion con atencion:
   - Una vez activada, **no se pueden modificar** datos de la fecha, categorias ni penalizaciones
   - Verificar que el numero de categorias y tripulaciones sea el correcto
5. Confirmar con **"Continuar"**

> **Atencion:** si hay algo mal, corregirlo ANTES de activar. Despues de activar solo se pueden agregar tiempos y anular vueltas.

### 3.6 Definir el orden de largada (sorteo)

El orden en que los autos salen a pista se define en la pestana de cronometraje. En pista doble, el sistema agrupa automaticamente a los autos de a pares: el primero contra el segundo, el tercero contra el cuarto, y asi sucesivamente.

**Para reordenar manualmente:**

1. Ir a la pestana **"Crono"** de la fecha
2. Seleccionar la categoria
3. Si es pista doble, activar el boton **"Pares"**
4. Usar las flechas (arriba/abajo) para mover a cada tripulacion
5. La columna **A** o **B** indica si esa tripulacion sale primero por pista A o pista B
6. Una vez definido el orden, tocar **"Guardar orden"**

> El sorteo se puede hacer tambien tirando a suerte los numeros y luego asignandolos en el sistema.

### 3.7 Bajar datos a la notebook (si se cargo todo en la nube)

Si la inscripcion y configuracion se hizo en la nube (`pioneros.upper.com.py`) y no directamente en la notebook:

1. Conectar la notebook a internet (WiFi de oficina: G5_CLARO_2.4)
2. Abrir la app en la notebook
3. En el Panel, buscar el boton **"Bajar desde nube"** en la fecha correspondiente
4. Confirmar la descarga
5. Los datos de la fecha, categorias y tripulaciones se copian a la notebook
6. Verificar que todo este correcto antes de llevar la notebook a la pista

---

## 4. MONTAJE EN PISTA

### 4.1 Diagrama de red en pista

```
[Internet celular/Starlink]
          |
          | WiFi (red del celular o starlink)
          |
      NOTEBOOK ----[cable ethernet]---- ROUTER AC1200
   192.168.100.5                       192.168.100.6
                                             |
                                        WiFi: PIONEROS4x4
                                        Clave: Pioneros2024!
                                             |
                    +------------------------+------------------------+
                    |                        |                        |
              crono-a (LED)           crono-b (LED)           Gateway LoRa
           (pista A, WiFi)          (pista B, WiFi)            (WiFi)
                                                                     |
                                                              Radio 433 MHz (LoRa)
                                                            +--------+--------+
                                                        sensor-a          sensor-b
                                                       (pista A)         (pista B)
                                                                        + semaforo
```

### 4.2 Donde colocar cada dispositivo

| Dispositivo | Ubicacion | Detalle |
|---|---|---|
| **Notebook** | Mesa de cronometraje | Bajo techo si es posible. Con cable ethernet al router |
| **Router AC1200** | Mesa de cronometraje | Cerca de la notebook. Con buena elevacion para cubrir toda la pista |
| **crono-a** | Linea de llegada pista A | Visible para el publico y para el cronometrista |
| **crono-b** | Linea de llegada pista B | Visible para el publico |
| **sensor-a** | Linea de llegada pista A | A la altura del chasis del auto (aprox 30 cm del suelo). Bien fijo |
| **sensor-b** | Linea de llegada pista B | Idem |
| **Semaforo** | Zona de largada | Visible para el piloto antes de salir |
| **Gateway LoRa** | Punto central de la pista | Con buena linea de vista hacia los sensores y el semaforo. Cerca del WiFi |

> Los sensores miden por rayo laser M18. Deben estar alineados (emisor y receptor uno frente al otro), sin obstaculos entre ellos. Verificar que el **LED del receptor** este encendido (indica que el haz llega correctamente).

### 4.3 Conectar la notebook al router AC1200

1. Enchufar el cable de red (ethernet) entre la notebook y cualquier puerto LAN del router AC1200
2. La notebook detecta el cable automaticamente y cambia a **modo pista**:
   - La notebook toma la IP `192.168.100.5`
   - El router sirve la red WiFi PIONEROS4x4
   - La notebook da IPs a los dispositivos que se conecten
3. Verificar que la app este accesible en el navegador: `http://192.168.100.5`

> Si la notebook estaba en la oficina (sin cable), al conectar el cable tarda unos 10-15 segundos en cambiar de modo. No hacer nada, esperar.

### 4.4 Encender los dispositivos

Orden recomendado:

1. Encender el router AC1200
2. Encender la notebook
3. Esperar 1-2 minutos hasta que la app este disponible en `http://192.168.100.5`
4. Encender los cronometros LED (crono-a y crono-b)
5. Encender el gateway LoRa
6. Encender los sensores (sensor-a y sensor-b)
7. Encender el semaforo

### 4.5 Verificar que todos los dispositivos estan online

1. En la app, ir al **Panel del cronometrista**
2. Tocar **"Dispositivos"**
3. La pantalla muestra la lista de todos los dispositivos con su estado:
   - **Punto verde pulsante = Online** (dispositivo conectado y funcionando)
   - **Punto gris = Offline** (no se ha comunicado en los ultimos 60 segundos)
   - **Punto amarillo = Nunca conectado** (el dispositivo nunca se conecto)
4. Verificar que todos esten en verde antes de empezar la carrera

> Los cronometros LED y el gateway envian su estado cada 5 segundos. Los sensores lo hacen a traves del gateway. Si un dispositivo aparece offline, ver la seccion de "Problemas comunes".

---

## 5. DURANTE LA CARRERA — PISTA DOBLE

La **pista doble** significa que hay dos pistas paralelas (A y B). En cada heat (tanda) salen dos autos al mismo tiempo: uno por pista A y otro por pista B. Luego se intercambian para hacer la segunda corrida.

### 5.1 Flujo completo paso a paso

#### Preparacion de la tanda (heat)

1. Ir a la fecha -> pestana **"Crono"**
2. Seleccionar la categoria que va a correr (pestanas de colores arriba)
3. Activar la vista **"Pares"** para ver los emparejamientos
4. Identificar el siguiente heat (par de autos) que le toca salir

#### Seleccionar el heat

5. Tocar sobre el par que va a salir (el boton con los numeros de los dos autos)
6. El sistema carga automaticamente los numeros en los cronometros LED
7. El crono-a muestra el numero del auto que va por pista A
8. El crono-b muestra el numero del auto que va por pista B

#### Corrida 1: el primer par de autos

9. Cuando los autos esten en la linea de largada y listos, tocar **"ARMAR LARGADA"** (boton verde)
   - El semaforo hace la secuencia: 3 rojos progresivos (3+3+3 s) + **verde 5 segundos** (los autos deben arrancar al ver el verde)
   - Los sensores quedan activos por **35 segundos** para detectar el cruce de largada
   - Un **punto amarillo parpadeante** aparece en el crono y un **contador "Sensor armado: XXs"** en la pantalla del celular mientras el sensor esta activo
   - Los cronometros arrancan automaticamente cuando cada auto cruza su sensor
10. Si los sensores detectan la largada: los cronos arrancan solos
    - Si no detectan (ver seccion de fallas mas abajo)
11. Cuando los autos van llegando, tocar **"ARMAR LLEGADA"** (boton amarillo)
    - Los sensores quedan activos por **35 segundos** para detectar la llegada
    - El contador "Sensor armado" vuelve a aparecer
12. Cuando cada auto cruza la llegada: el crono para automaticamente y muestra el tiempo final en rojo
13. Verificar los tiempos en pantalla
14. En el formulario de la app, cargar las penalizaciones si corresponde:
    - **Estacas:** cuantas estacas derribo el auto (boton + y -)
    - **Cintas:** cuantas cintas corto el auto (boton + y -)
    - El tiempo mostrado es el tiempo de ruta. Las penalizaciones se suman automaticamente al calcular el ranking
15. Tocar **"Guardar Tramo A"** para guardar el tiempo del auto de pista A
16. El formulario del Tramo B guarda el tiempo del auto de pista B. Tocar **"Guardar Tramo B"**
17. Aparece el mensaje "Corrida 1 guardada"

#### Corrida 2: los mismos autos se intercambian de pista

18. Tocar **"Pasar a Corrida 2"** (si existe este boton) o bien el sistema lo pide automaticamente
    - El sistema envia reset a los cronometros
    - **Los autos se intercambian:** el que fue por A ahora va por B, y viceversa
    - Los cronos muestran los numeros ya invertidos
19. Repetir los pasos 9 al 16 para la corrida 2
20. Al guardar la corrida 2, la vuelta queda completa para ese par

#### Pasar al siguiente heat

21. Tocar **"Volver"** para regresar a la lista de pares
22. Seleccionar el siguiente par
23. Repetir el proceso

### 5.2 Que hacer si un sensor no detecta el cruce

Si el sensor no detecta el cruce de un auto (largada o llegada), hay dos opciones:

**Opcion 1: Re-armar solo ese sensor (sin repetir semaforo)**
En la fila de botones debajo de "Armar Largada", tocar **"Largada A"** o **"Largada B"** para re-habilitar solo el sensor de esa pista (35 segundos). No activa el semaforo. Util si un sensor no detecto pero el otro si.

Tambien hay botones **"Reset A"** y **"Reset B"** para resetear un crono individual.

**Opcion 2: Ingresar tiempo manual**

Si el sensor no detecta el cruce de un auto (largada o llegada):

**Si el sensor no detecta en LARGADA:**
1. Usar un cronometro manual (celular) y tomar el tiempo desde que el auto cruzo la linea
2. Tocar **"RESET"** en la app (para limpiar los cronos)
3. En el formulario de tramo, ingresar el tiempo a mano:
   - Campo **minutos** : **segundos** : **centesimas**
   - Ejemplo: si el auto hizo 1 minuto 23 segundos y 45 centesimas, ingresar `01 : 23 : 45`
4. Agregar estacas y cintas si corresponde
5. Tocar "Guardar"

> El campo de centesimas acepta 2 digitos (00 a 99). No se ingresan milisegundos, sino centesimas.

**Si el sensor no detecta en LLEGADA:**
1. El cronometro sigue corriendo en el LED
2. Notar el tiempo del LED en el momento en que el auto cruzo la linea
3. Tocar **"RESET"** y luego ingresar el tiempo a mano como se describio arriba

### 5.3 Que hacer si un auto abandona una vuelta

Si un auto no puede terminar una corrida por problemas mecanicos u otro motivo:

1. En la pantalla de cronometraje, tocar **"#N abandona vuelta"** (boton rojo con el numero del auto)
2. Confirmar — la vuelta queda marcada como **NULA**
3. El crono de esa pista se resetea
4. **El otro auto del par sigue corriendo solo**: se habilita corrida 2 automaticamente, el compañero cambia de pista y corre normalmente. Su vuelta queda completa (tramo A + tramo B).
5. La tripulacion que abandono **puede volver a correr en la vuelta siguiente**

> Una vuelta nula no cuenta para el ranking. Si la tripulacion tiene otras vueltas validas, se usa la mejor.
> Para clasificar, basta con tener **al menos 1 vuelta valida completa**. No importa si no corrio todas las vueltas.

### 5.4 DNF — cuando un auto no corre mas en la fecha

Si un auto no puede correr mas en toda la fecha:

1. En la lista de tripulaciones del cronometraje, tocar **"DNF"** (boton rojo chico)
2. Confirmar "No corre mas en esta fecha"
3. La tripulacion queda como **DNF** y recibe 1 punto en el campeonato
4. Si por error se marca DNF y el auto quiere volver a correr: al tocar "Armar largada" la app pregunta si reincorporar

> Al finalizar la fecha, se muestra la lista de DNF para confirmar antes de cerrar.

### 5.5 Como anular una vuelta ya guardada

Si se guardo un tiempo incorrecto o hubo un error:

1. Seleccionar la tripulacion
2. Seleccionar la vuelta a anular (boton V1, V2, etc.)
3. Tocar **"Anular vuelta"**
4. Confirmar en el cuadro que aparece
5. La vuelta queda marcada como NULA y no cuenta en el ranking

> **Atencion:** una vuelta confirmada (marcada como "CONFIRMADA") NO se puede anular. Solo anular vueltas antes de confirmarlas.

### 5.5 Confirmar vueltas

Una vez que el tiempo de una vuelta es correcto, se puede confirmar para protegerla:

1. En los tiempos cargados de la tripulacion, tocar **"Confirmar"** al lado de la vuelta
2. Confirmar en el mensaje
3. La vuelta queda protegida (fondo azul, etiqueta "CONFIRMADA")

> Las vueltas confirmadas ya no se pueden modificar. Hacerlo solo cuando el tiempo sea definitivo.

---

## 6. DURANTE LA CARRERA — PISTA SIMPLE

La **pista simple** tiene una sola pista. Cada auto corre solo, un tiempo por vuelta.

### 6.1 Flujo paso a paso

1. Ir a la fecha -> pestana **"Crono"**
2. Seleccionar la categoria
3. En la lista de tripulaciones, tocar el auto que le toca salir
4. El sistema manda el numero de la tripulacion al crono-a
5. Elegir la vuelta que se va a cronometrar (boton V1, V2, etc.)
6. Cuando el auto este listo en la largada, tocar **"ARMAR LARGADA"** (boton verde)
   - El semaforo enciende rojo -> verde
   - El sensor queda activo por 20 segundos
7. El auto cruza el sensor de largada -> el crono arranca solo
8. Cuando el auto va llegando, tocar **"ARMAR LLEGADA"** (boton amarillo)
9. El auto cruza el sensor de llegada -> el crono para y muestra el tiempo en rojo
10. Cargar estacas y cintas si corresponde
11. Tocar **"Guardar Tramo"** (en pista simple hay un solo campo de tiempo)
12. Aparece "Tramo A guardado"
13. Tocar **"Volver"** y seleccionar el siguiente auto

> En pista simple, el auto puede tener 2 o 3 vueltas (segun como este configurada la fecha). El ranking usa la mejor vuelta de cada tripulacion.

### 6.2 Marcar que un auto salio a pista

Si el auto ya salio pero todavia no cruzo el sensor de largada (el sensor no detecto):

1. Seleccionar la tripulacion
2. Tocar **"Salio a pista"** (boton amarillo)
3. Esto marca al auto como "en pista" en el ranking en vivo
4. Luego ingresar el tiempo a mano cuando llegue

---

## 7. DESPUES DE LA CARRERA

### 7.1 Verificar el ranking final

1. En la fecha, ir a la pestana **"Ranking"**
2. Seleccionar cada categoria
3. Verificar que los tiempos y posiciones sean correctos
4. La seccion **"Clasificacion"** muestra el ranking por mejor vuelta
5. Si hay final, la seccion **"Ranking Final"** muestra los resultados de la vuelta final

### 7.2 Asignar puntos de campeonato

Si la fecha pertenece a un campeonato, hay que asignar los puntos a cada tripulacion:

1. En la pestana **"Ranking"**, cada tripulacion tiene un campo de puntos al final
2. Ingresar la cantidad de puntos segun la posicion:
   - 1er puesto: 15 puntos
   - 2do puesto: 12 puntos
   - 3er puesto: 10 puntos
   - 4to: 8 puntos
   - 5to: 6 puntos
   - 6to: 4 puntos
   - Resto: 1 punto
   - DNF: 1 punto
3. El campo se guarda automaticamente al cambiar el valor (no hace falta tocar un boton de guardar)

> Si el sistema esta configurado con campeonato, los puntos se acumulan automaticamente.

### 7.3 Finalizar la fecha

Una vez verificados todos los tiempos y puntos:

1. Ir a la pestana **"Config"** de la fecha
2. Tocar **"Finalizar fecha"** (boton rojo)
3. Leer el mensaje de confirmacion — muestra:
   - Cuantos clasificados hay (con al menos 1 vuelta valida)
   - Lista de quienes quedan como **DNF** (abandonados o sin vueltas validas)
   - Si alguno no deberia ser DNF, cancelar y reincorporarlo antes
4. Confirmar

Una vez finalizada, la fecha queda bloqueada. Solo el administrador puede modificar puntos.

### 7.4 Subir datos a la nube

Los datos de la carrera se sincronizan automaticamente con la nube mientras haya internet. Si la notebook tuvo internet durante la carrera (via celular o Starlink), los datos ya estan en `pioneros.upper.com.py`.

Si no hubo internet durante la carrera:
1. Conectar la notebook a internet (WiFi de oficina o celular por hotspot)
2. Los datos pendientes se sincronizan automaticamente en cuanto hay conexion
3. Verificar entrando a `https://pioneros.upper.com.py` que la fecha y tiempos esten visibles

---

## 8. PANEL DE DISPOSITIVOS

Para acceder: Panel del cronometrista -> **"Dispositivos"**

La pantalla se actualiza automaticamente cada 5 segundos.

### 8.1 Que significa cada indicador

| Indicador | Significado |
|---|---|
| Punto verde pulsante + texto "Online" | Dispositivo conectado y comunicandose normalmente |
| Punto gris + texto "Offline" | Dispositivo no se comunica hace mas de 60 segundos |
| Punto amarillo + texto "Nunca conectado" | Nunca se conecto al sistema (posible problema de firmware o token) |

**Informacion adicional que se muestra para cada dispositivo:**
- **RSSI:** potencia de la senal WiFi o LoRa. Valores entre -30 y -70 dBm son buenos. Por debajo de -85 dBm la conexion puede tener problemas
- **Voltaje:** tension de alimentacion. Cronos y gateway: 5V. Sensores: 3.3V (placa LoRa) + 12V (sensor M18). Semaforo: 12V
- **Uptime:** cuanto tiempo lleva encendido el dispositivo sin reiniciarse

### 8.2 Que hacer si un dispositivo esta offline

1. Verificar que el dispositivo este encendido y que la luz de energia este prendida
2. Verificar que la red WiFi PIONEROS4x4 este activa (router encendido)
3. Esperar 30 segundos: el dispositivo intenta reconectarse cada 5 segundos
4. Si sigue offline, apagar y encender el dispositivo
5. Si al encenderlo aparece la pantallita (gateway) o el LED (cronometro), el dispositivo arranco bien y deberia conectarse en unos segundos
6. Si sigue sin aparecer, ver la seccion de "Problemas comunes"

---

## 9. PROBLEMAS COMUNES Y SOLUCIONES

### La notebook no conecta al WiFi de pista

**Sintoma:** los celulares se conectan a PIONEROS4x4 pero no pueden entrar a `http://192.168.100.5`

**Causa posible:** la notebook no detecto el cable ethernet o no cambio a modo pista.

**Solucion:**
1. Verificar que el cable ethernet este bien enchufado en la notebook y en el router
2. Abrir una terminal en la notebook (Ctrl+Alt+T o click derecho en el escritorio)
3. Escribir `sudo modo-pista` y presionar Enter
4. Ingresar la clave: `pioneros`
5. Esperar 15 segundos y volver a intentar desde el celular

### Un cronometro LED no responde (offline)

**Sintoma:** crono-a o crono-b aparece offline en el panel de dispositivos. El panel LED sigue prendido pero no cambia.

**Solucion:**
1. Verificar que el cronometro este encendido y que el panel LED tenga algo en pantalla
2. Verificar que el router AC1200 este funcionando (red PIONEROS4x4 visible desde el celular)
3. Apagar y encender el cronometro. Esperar 30 segundos
4. Si sigue offline, el ESP32 puede estar intentando conectarse. Esperar hasta 1 minuto
5. Si en la pantallita del cronometro (o en el LED mismo) aparece algo raro, reiniciarlo de nuevo
6. Como respaldo: ingresar el tiempo a mano. El cronometro LED es decorativo; el tiempo se puede registrar igual

### Un sensor no detecta el cruce del auto

**Sintoma:** el auto cruza pero el crono no arranca (o no para).

**Posibles causas y soluciones:**

1. **El sensor estaba deshabilitado:** los sensores solo estan activos por **35 segundos** despues de tocar "Armar largada" o "Armar llegada". Si el auto tardo mas, el sensor ya se desactivo. Verificar el contador "Sensor armado: XXs" en la pantalla.
   - Solucion: volver a tocar "Armar llegada" y esperar que el auto llegue

2. **Debounce (3 segundos):** tras detectar un cruce, el sensor ignora los siguientes 3 segundos. Si dos autos pasan muy seguidos, el segundo puede no detectarse.
   - Solucion: esperar 3 segundos entre cruces o ingresar tiempo manual

3. **El sensor o gateway esta offline:** verificar en el panel de dispositivos
   - Si el gateway esta offline: el sensor tampoco funciona. Reiniciar el gateway
   - Si el sensor esta offline: reiniciar el sensor

4. **Desalineacion del sensor laser M18:** verificar el **LED del receptor M18** — debe estar encendido cuando el haz llega correctamente
   - Verificar que el emisor y el receptor esten bien alineados
   - Los autos pasan por enmedio del rayo

5. **Respaldo siempre disponible:** ingresar el tiempo manualmente. Anotar el tiempo en un papel en el momento del cruce y cargarlo despues.

### El semaforo no enciende

**Sintoma:** se toca "Armar largada" en la app pero el semaforo no prende.

**Solucion:**
1. Verificar que el semaforo este encendido
2. Verificar que el gateway LoRa este online (aparece en el panel de dispositivos)
3. El semaforo se comunica por radio con el gateway. Verificar que haya linea de vista entre ellos
4. Reiniciar el gateway (apagar y encender)
5. Como respaldo: indicar visualmente la largada con una bandera o un gesto al piloto. La carrera puede continuar sin semaforo.

### La app no carga en el celular

**Sintoma:** el celular esta conectado a PIONEROS4x4 pero al entrar a `http://192.168.100.5` la pagina no carga.

**Solucion:**
1. Verificar que el celular este conectado a **PIONEROS4x4** y no a otra red
2. La direccion es `http://` (no https). Algunas veces el celular agrega "https" automaticamente. Escribir a mano: `http://192.168.100.5`
3. Verificar que la notebook este encendida y con el cable ethernet conectado
4. Esperar 30 segundos y recargar la pagina
5. Si sigue sin cargar, en la notebook abrir el navegador Chromium y verificar que `http://localhost` funcione. Si no funciona, reiniciar la notebook.

### La pantalla de la notebook se apago

La notebook esta configurada para que la pantalla **nunca se apague sola**. Si se apago, posibles causas:

1. **Se activo el protector de pantalla por movimiento accidental en la configuracion:** tocar cualquier tecla o mover el mouse para que vuelva
2. **La notebook entro en suspension:** presionar el boton de encendido una vez brevemente
3. **Corte de energia:** verificar que el cable de carga este enchufado
4. Si la pantalla no vuelve: reiniciar la notebook (mantener el boton de encendido 5 segundos hasta que se apague, luego encender de nuevo)

> Despues de reiniciar la notebook, esperar 2-3 minutos hasta que todos los servicios arranquen y la app este disponible de nuevo.

### Aparece el mensaje "Sin conexion - guardado en cola"

**Que significa:** el celular perdio momentaneamente la conexion WiFi mientras guardaba un tiempo. El dato quedo guardado en el celular y se enviara automaticamente en cuanto vuelva la conexion.

**Que hacer:** no hacer nada. El numero de cambios pendientes se muestra en un banner amarillo arriba de la pantalla (ejemplo: "Sin conexion - 2 cambios pendientes"). Cuando vuelve la conexion, se envian solos.

---

## 10. DATOS TECNICOS (PARA EL TECNICO)

Esta seccion es para el tecnico del sistema, no para el cronometrista.

### 10.1 IPs y accesos

| Recurso | Valor |
|---|---|
| App en pista | `http://192.168.100.5` |
| App en nube | `https://pioneros.upper.com.py` |
| IP notebook (modo pista) | `192.168.100.5` |
| IP notebook (modo oficina) | `192.168.10.5` |
| IP router AC1200 | `192.168.100.6` |
| WiFi pista | PIONEROS4x4 / `Pioneros2024!` |
| WiFi oficina | G5_CLARO_2.4 / `Lumagabi5138` |
| SSH notebook (pista) | `ssh -i ~/.ssh/id_pioneros user@192.168.100.5` |
| SSH notebook (oficina) | `ssh -i ~/.ssh/id_pioneros user@192.168.10.5` |
| SSH nube | `ssh -i ~/.ssh/id_pioneros deploy@192.168.10.4` |
| Sudo notebook | `pioneros` |

### 10.2 Comandos utiles en la notebook

```bash
# Cambiar a modo pista manualmente
sudo modo-pista

# Cambiar a modo oficina
sudo modo-oficina

# Ver estado de los servicios
sudo systemctl status pioneros-queue.service
sudo systemctl status nginx
sudo systemctl status php8.5-fpm
sudo systemctl status postgresql

# Reiniciar la cola de trabajos
sudo systemctl restart pioneros-queue.service

# Ver logs de la app
sudo tail -f /var/www/pioneros/api/storage/logs/laravel.log
```

### 10.3 Como flashear firmware en los ESP32

**Por cable USB (Arduino IDE):**
1. Abrir **Arduino IDE** en la PC
2. Abrir el archivo `.ino` correspondiente (ejemplo: `firmware/crono_a/crono_a.ino`)
3. Verificar que exista el archivo `config.h` en la misma carpeta (con los tokens y passwords)
4. Placa segun dispositivo:
   - **Cronos**: `ESP32S3 Dev Module`, USB CDC On Boot: `Enabled`
   - **Sensores, gateway, semaforo**: `TTGO LoRa32-OLED V2.1 (1.6.1)`
5. Seleccionar el puerto USB y subir

**Por WiFi (OTA) — solo cronometros:**
1. En Arduino IDE, menu Herramientas -> Puerto -> Network ports
2. Seleccionar el hostname (crono-a o crono-b)
3. Subir. Tarda ~30 segundos

### 10.4 Backup de base de datos

El sistema hace backup automatico a las 3am todos los dias.

**Backup manual:**
```bash
# En la notebook
PGPASSWORD=pioneros pg_dump -Fc -h 127.0.0.1 -U pioneros -d pioneros_production -f /var/backups/pioneros/manual_backup.dump
```

### 10.5 Estructura del sistema

- **Backend:** Laravel + PHP + PostgreSQL en `/var/www/pioneros/api/`
- **Frontend (PWA):** Vue 3 + Pinia + Tailwind en `/var/www/pioneros/pwa/dist/`
- **Web server:** Nginx en puerto 80
- **Cache:** Redis
- **Cola:** systemd `pioneros-queue.service`
- **Protocolo LoRa:** 433 MHz, SF9, BW 125 kHz, SyncWord 0x12
- **Cronos:** long polling (respuesta instantanea al comando, hasta 10s de espera). Heartbeat dentro del mismo GET
- **Gateway:** polling cada 2 segundos. Heartbeat cada 5 segundos
- **Sensores/semaforo:** heartbeat LoRa cada 5 segundos

---

*Manual generado para el sistema Pioneros 4x4 — Version 2026*
