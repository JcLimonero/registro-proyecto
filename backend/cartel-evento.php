<?php
/**
 * Carteles por evento: rutas públicas, subida y borrado de archivos en assets/carteles/.
 */

const CARTEL_EVENTO_REL = 'assets/carteles';
const CARTEL_EVENTO_MAX_BYTES = 3145728; // 3 MiB
const CARTEL_EVENTO_DEFECTO = 'assets/posada-vanguardia.jpg';

function directorioCartelesEvento() {
    return dirname(__DIR__) . '/' . CARTEL_EVENTO_REL;
}

function asegurarDirectorioCarteles() {
    $dir = directorioCartelesEvento();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return is_dir($dir) && is_writable($dir);
}

/** Ruta relativa segura si el archivo existe; si no, cadena vacía. */
function cartelEventoPublico($ruta) {
    $ruta = trim((string) $ruta);
    if ($ruta === '' || !preg_match('#^assets/carteles/[a-zA-Z0-9._-]+$#', $ruta)) {
        return '';
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    return is_readable($abs) ? $ruta : '';
}

function eliminarCartelEventoArchivo($ruta) {
    $ruta = cartelEventoPublico($ruta);
    if ($ruta === '') {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Guarda la subida del admin. Devuelve [ruta relativa|null, mensaje error|null].
 */
function guardarCartelEventoArchivo(array $archivo, $eventoId) {
    $eventoId = (int) $eventoId;
    if ($eventoId < 1) {
        return [null, 'Evento no válido.'];
    }
    if (!isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return [null, 'No se pudo subir la imagen del cartel.'];
    }
    if (($archivo['size'] ?? 0) > CARTEL_EVENTO_MAX_BYTES) {
        return [null, 'El cartel no debe pasar de 3 MB.'];
    }
    if (!asegurarDirectorioCarteles()) {
        return [null, 'No se puede escribir en la carpeta de carteles del servidor.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($tipos[$mime])) {
        return [null, 'Usa JPG, PNG o WebP para el cartel.'];
    }

    $nombre = 'evento-' . $eventoId . '.' . $tipos[$mime];
    $rel = CARTEL_EVENTO_REL . '/' . $nombre;
    $dest = dirname(__DIR__) . '/' . $rel;

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $viejo = dirname(__DIR__) . '/' . CARTEL_EVENTO_REL . '/evento-' . $eventoId . '.' . $ext;
        if (is_file($viejo) && realpath($viejo) !== realpath($dest)) {
            @unlink($viejo);
        }
    }

    if (!move_uploaded_file($archivo['tmp_name'], $dest)) {
        return [null, 'No se pudo guardar el cartel en el servidor.'];
    }
    @chmod($dest, 0644);

    return [$rel, null];
}
