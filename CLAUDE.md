# Reglas del proyecto Pioneros 4x4

## Manual de usuario
- `MANUAL_USUARIO.md` es para el operador/cronometrista. Editar secciones puntuales, **nunca reescribir**.
- `MANUAL_TECNICO.md` es el documento tecnico (pinouts, protocolos, dual-core, etc.).
- Ante cambios de firmware o funcionalidad, actualizar las secciones afectadas del manual de usuario sin alterar el resto.

## Deploy
- Siempre editar en el repo local y desplegar desde ahi. Nunca editar directo en el server.
- Backend: `scp` los archivos PHP al server. No hay git en los servers.
- PWA: `npm run build` local + `scp dist/` al server.

## Firmware
- Compilar cada sketch en Arduino IDE y verificar "Sketch usa X bytes" antes de commitear.
- No commitear firmware sin compilar.

## Commits
- Un commit por tarea, no mezclar.
- Commit de estado limpio antes de empezar cambios grandes.
