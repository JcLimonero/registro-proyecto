# Sistema de registro con escáner QR

App PHP + HTML/JS para registrar asistentes a un evento, enviarles por correo un ticket con QR y validar su entrada con un escáner (lector que teclea el ID del ticket). La descarga del QR guarda el pase completo: evento, fechas, ubicación (liga de Maps del evento) y datos del registro.

## Cómo correrlo
1. `composer install` (PHPMailer, endroid/qr-code, TCPDF).
2. Crear la base MySQL `registro_proyecto` (la tabla `registros` y sus columnas, incl. `fecha_entrada` y `confirmado`, se crean/actualizan solas vía `createTable()` en `backend/config.php`).
3. `cp .env.example .env` y completar valores (DB y SMTP).
4. Servir desde la raíz SIEMPRE con el router: `php -S localhost:8000 router.php` y abrir `/index.html` (registro). El acceso al admin es el «© 2026» al pie; ya con contraseña, `/registros.php` tiene las pestañas «Tabla» y «Escanear» (`?vista=escanear`). `/escaner.html` solo redirige a `registros.php`.

> No servir la raíz sin `router.php` (servidor embebido) ni sin el `.htaccess` (Apache): sin ellos `/.env` se entrega en claro. El router y el `.htaccess` devuelven 404/403 a cualquier archivo oculto.

## Endpoints (`backend/`)
- `registrar.php` (POST JSON): guarda el registro. Solo acepta si AHORA (America/Mexico_City, reloj del servidor) está entre `fecha_inicio` y `fecha_fin` de un evento, ambos inclusive; con varios eventos vigentes exige `eventoId`. La agencia y el área deben existir en sus catálogos (se guarda el nombre como texto) y se guarda `evento_id`.
- `enviar-correo.php` (POST JSON): envía el correo con el QR generado en local; responde `success:false` si falta config SMTP.
- `catalogos.php` (GET, público, solo lectura): agencias, áreas y eventos vigentes; si no hay evento vigente devuelve `abierto:false` y el próximo/último evento como `referencia`.
- `generar-qr.php?text=...`: PNG del QR (texto máx. 200 caracteres).
- `validar-asistencia.php` (POST JSON `{idTicket}`): marca la entrada. Requiere la sesión del admin (401 sin ella).
- `estadisticas.php` (GET): totales para el escáner. Requiere la sesión del admin (401 sin ella).

- `/registros.php`: pestañas «Tabla», «Escanear», «Agencias», «Áreas» y «Eventos» (`?vista=agencias|areas|eventos`); alta/edición/baja con la misma sesión y el mismo token CSRF (sin sesión: 401). La tabla de registros filtra por evento, estado, agencia y texto (GET `evento`, `estado`, `agencia`, `q`) y el Excel respeta ese filtro. Tabla de registros y descarga Excel (`?descargar=1`, SpreadsheetML `.xls`), protegida por sesión; la contraseña va solo en el entorno/`.env` (`ADMIN_PASSWORD`), no en el repo; si está vacía, el acceso queda deshabilitado.

## Secretos
La cuenta SMTP de eventos está como valor por defecto en `backend/config.php` en esta rama, hasta que se rote. Un `.env` (ignorado por git) o las variables de entorno la sustituyen si están definidas. El escáner vive dentro de `registros.php` y solo funciona con sesión iniciada (los endpoints `validar-asistencia.php` y `estadisticas.php` validan la sesión vía `backend/sesion-admin.php`).
