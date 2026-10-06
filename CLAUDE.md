# Sistema de registro con escáner QR

App PHP + HTML/JS para registrar asistentes a un evento, enviarles por correo un ticket con QR y validar su entrada con un escáner (lector que teclea el ID del ticket).

## Cómo correrlo
1. `composer install` (PHPMailer, endroid/qr-code, TCPDF).
2. Crear la base MySQL `registro_proyecto` (la tabla `registros` y sus columnas, incl. `fecha_entrada` y `confirmado`, se crean/actualizan solas vía `createTable()` en `backend/config.php`).
3. `cp .env.example .env` y completar valores (DB y SMTP).
4. Servir desde la raíz SIEMPRE con el router: `php -S localhost:8000 router.php` y abrir `/index.html` (registro) o `/escaner.html` (escáner).

> No servir la raíz sin `router.php` (servidor embebido) ni sin el `.htaccess` (Apache): sin ellos `/.env` se entrega en claro. El router y el `.htaccess` devuelven 404/403 a cualquier archivo oculto.

## Endpoints (`backend/`)
- `registrar.php` (POST JSON): guarda el registro.
- `enviar-correo.php` (POST JSON): envía el correo con el QR generado en local; responde `success:false` si falta config SMTP.
- `generar-qr.php?text=...`: PNG del QR (texto máx. 200 caracteres).
- `validar-asistencia.php` (POST JSON `{idTicket}`): marca la entrada.
- `estadisticas.php` (GET): totales para el escáner.

## Secretos
Credenciales (DB, SMTP) solo en variables de entorno o `.env` (ignorado por git). Nunca en el código ni en el repo. El escáner no tiene autenticación (decisión actual).
