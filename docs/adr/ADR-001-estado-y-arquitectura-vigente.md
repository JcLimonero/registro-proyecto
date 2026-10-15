# ADR-001 — Estado y arquitectura vigente del registro con escáner QR

- **Fecha:** 2026-10-14
- **Estado:** Aceptado como foto del estado. Se revisa cuando cambie la arquitectura.
- **Repo:** `Bucewayne/registro-proyecto` (carpeta local `Pablo`) · **rama `JCLN_Improvements`**, no `main`
- **Nota de propiedad:** el repositorio **no es del dueño de esta máquina**; aquí se trabaja como
  contribución, en una rama propia y con PR hacia el upstream. Este ADR documenta el estado de esa
  contribución.
- **Fuentes:** `CLAUDE.md` e historial de git.

## Contexto

App PHP + HTML/JS para registrar asistentes a un evento, enviarles por correo un ticket con QR y
validar su entrada con un escáner (un lector que teclea el ID del ticket). La descarga del QR
guarda el pase completo: evento, fechas, ubicación (liga de Maps del evento) y datos del registro.

## Decisiones vigentes

- **Stack sin framework:** PHP con Composer (PHPMailer, `endroid/qr-code`, TCPDF), MySQL
  (`registro_proyecto`), y HTML/JS plano.
- **El esquema se crea solo:** la tabla `registros` y sus columnas (incluidas `fecha_entrada` y
  `confirmado`) se crean y actualizan vía `createTable()` en `backend/config.php`. No hay
  migraciones.
- **La raíz SIEMPRE se sirve con el router:** `php -S localhost:8000 router.php`, o con el
  `.htaccess` en Apache. **Sin ellos `/.env` se entrega en claro**; el router y el `.htaccess`
  devuelven 404/403 a cualquier archivo oculto. Es la decisión de seguridad central del proyecto.
- **El acceso al admin está escondido** en el «© 2026» del pie; ya con contraseña,
  `/registros.php` tiene las pestañas «Tabla» y «Escanear» (`?vista=escanear`).
  `/escaner.html` solo redirige a `registros.php`.
- **La ventana de registro la decide el servidor:** `registrar.php` solo acepta si **ahora**
  (`America/Mexico_City`, reloj del servidor) está entre `registro_inicio` y `registro_fin` de un
  evento, ambos inclusive. **`fecha_inicio` y `fecha_fin` son las fechas del evento y no abren el
  registro** — son dos cosas distintas a propósito (separado el 2026-10-06). Con varios eventos
  abiertos exige `eventoId`.
- **`catalogos.php` es público y de solo lectura:** agencias, áreas y eventos vigentes; si no hay
  evento vigente devuelve `abierto:false` y el próximo/último evento como `referencia`.
- **El QR se genera en local** y `enviar-correo.php` responde `success:false` si falta configuración
  SMTP, en vez de fallar en silencio.

## Estado al 14 de octubre de 2026

La rama `JCLN_Improvements` lleva 25 commits sobre el upstream y **tiene el PR #1 abierto**:
«Corrige el registro, el QR y el escáner de asistencia».

Trabajo de esa rama, de más nuevo a más viejo:

- **Separar la fecha del evento del periodo de registro** (la corrección conceptual más importante).
- Logo de Grupo Vanguardia en el correo.
- Liga de Maps guardada en la configuración del evento, y el QR descargado guarda los datos del
  evento.
- Administración de agencias, áreas y eventos; registro público solo en periodo, con diseño
  Vanguardia.
- Catálogos en tablas con paginación, acciones dentro de la tabla, formulario de alta alineado,
  iconos en vez de texto para agregar/guardar/eliminar, filtro de la tabla de registros por evento.
- **Un solo acceso al admin**, y el escáner movido dentro de `registros.php`.

### Pruebas y CI

**No hay pruebas ni CI.** La validación es manual.

## Pendientes y huecos conocidos

1. **El PR #1 está abierto contra el upstream**: hasta que se mezcle, todo este trabajo vive solo en
   la rama.
2. No hay migraciones: el esquema evoluciona por `createTable()`, lo que hace difícil saber en qué
   versión está una base ya desplegada.
3. El acceso al admin escondido en el pie es seguridad por oscuridad **además** de la contraseña;
   conviene que nadie lo confunda con un control de acceso.

## Riesgos y deuda

- **Servir la raíz sin `router.php` ni `.htaccess` expone `/.env` en claro.** Es un pie de bala
  documentado: cualquiera que levante el servidor embebido «rápido» filtra las credenciales SMTP y
  de BD.
- El repositorio es de un tercero: la contribución depende de que el upstream mezcle.
- Depende del reloj del servidor para decidir si el registro está abierto.

## Lo que sigue

1. Dar seguimiento al PR #1 en el upstream.
2. Confirmar con el dueño del repo si las mejoras de catálogos y eventos se quedan.
