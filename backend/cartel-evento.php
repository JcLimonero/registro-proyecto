<?php
/**
 * Carteles por evento: archivo en assets/carteles/ y copia en MySQL para persistencia.
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

/** Ruta relativa segura si el archivo existe en disco; si no, cadena vacía. */
function cartelEventoPublico($ruta) {
    $ruta = trim((string) $ruta);
    if ($ruta === '' || !preg_match('#^assets/carteles/[a-zA-Z0-9._-]+$#', $ruta)) {
        return '';
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    return is_readable($abs) ? $ruta : '';
}

/** URL pública del cartel: archivo local o endpoint con copia en base. */
function cartelUrlEvento(array $e) {
    $id = (int) ($e['id'] ?? 0);
    $ruta = trim((string) ($e['cartel'] ?? ''));
    if ($ruta !== '' && cartelEventoPublico($ruta) !== '') {
        return $ruta;
    }
    $tieneBlob = !empty($e['tiene_cartel_blob'])
        || (isset($e['cartel_datos']) && $e['cartel_datos'] !== '' && $e['cartel_datos'] !== null);
    if ($id > 0 && $ruta !== '' && $tieneBlob) {
        return 'backend/cartel-evento-imagen.php?id=' . $id;
    }
    return '';
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

function restaurarCartelEventoEnDisco($eventoId, $ruta, $datos, $mime) {
    $ruta = trim((string) $ruta);
    if ($ruta === '' || !preg_match('#^assets/carteles/[a-zA-Z0-9._-]+$#', $ruta)) {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    if (is_readable($abs)) {
        return;
    }
    if (!asegurarDirectorioCarteles()) {
        return;
    }
    @file_put_contents($abs, $datos);
    @chmod($abs, 0644);
}

/**
 * Guarda la subida del admin. Devuelve [ruta|null, error|null, mime|null, binario|null].
 */
function guardarCartelEventoArchivo(array $archivo, $eventoId) {
    $eventoId = (int) $eventoId;
    if ($eventoId < 1) {
        return [null, 'Evento no válido.', null, null];
    }
    if (!isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null, null, null];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return [null, 'No se pudo subir la imagen del cartel.', null, null];
    }
    if (($archivo['size'] ?? 0) > CARTEL_EVENTO_MAX_BYTES) {
        return [null, 'El cartel no debe pasar de 3 MB.', null, null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($tipos[$mime])) {
        return [null, 'Usa JPG, PNG o WebP para el cartel.', null, null];
    }

    $datos = file_get_contents($archivo['tmp_name']);
    if ($datos === false || $datos === '') {
        return [null, 'No se pudo leer la imagen del cartel.', null, null];
    }

    $nombre = 'evento-' . $eventoId . '.' . $tipos[$mime];
    $rel = CARTEL_EVENTO_REL . '/' . $nombre;

    if (asegurarDirectorioCarteles()) {
        $dest = dirname(__DIR__) . '/' . $rel;
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $viejo = dirname(__DIR__) . '/' . CARTEL_EVENTO_REL . '/evento-' . $eventoId . '.' . $ext;
            if (is_file($viejo) && realpath($viejo) !== realpath($dest)) {
                @unlink($viejo);
            }
        }
        if (is_uploaded_file($archivo['tmp_name'])) {
            @move_uploaded_file($archivo['tmp_name'], $dest);
        } else {
            @file_put_contents($dest, $datos);
        }
        @chmod($dest, 0644);
    }

    return [$rel, null, $mime, $datos];
}
