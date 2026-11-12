<?php
/**
 * Imagen inferior del pase descargable: archivo en assets/pase-pie/ y copia en MySQL.
 */

const PASE_PIE_EVENTO_REL = 'assets/pase-pie';
const PASE_PIE_EVENTO_MAX_BYTES = 3145728;

function directorioPasePieEvento() {
    return dirname(__DIR__) . '/' . PASE_PIE_EVENTO_REL;
}

function asegurarDirectorioPasePie() {
    $dir = directorioPasePieEvento();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return is_dir($dir) && is_writable($dir);
}

function pasePieEventoPublico($ruta) {
    $ruta = trim((string) $ruta);
    if ($ruta === '' || !preg_match('#^assets/pase-pie/[a-zA-Z0-9._-]+$#', $ruta)) {
        return '';
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    return is_readable($abs) ? $ruta : '';
}

function pasePieUrlEvento(array $e) {
    $id = (int) ($e['id'] ?? 0);
    $ruta = trim((string) ($e['pase_pie'] ?? ''));
    if ($ruta !== '' && pasePieEventoPublico($ruta) !== '') {
        return $ruta;
    }
    $tieneBlob = !empty($e['tiene_pase_pie_blob'])
        || (isset($e['pase_pie_datos']) && $e['pase_pie_datos'] !== '' && $e['pase_pie_datos'] !== null);
    if ($id > 0 && $ruta !== '' && $tieneBlob) {
        return 'backend/pase-pie-evento-imagen.php?id=' . $id;
    }
    return '';
}

function eliminarPasePieEventoArchivo($ruta) {
    $ruta = pasePieEventoPublico($ruta);
    if ($ruta === '') {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

function restaurarPasePieEventoEnDisco($eventoId, $ruta, $datos, $mime) {
    $ruta = trim((string) $ruta);
    if ($ruta === '' || !preg_match('#^assets/pase-pie/[a-zA-Z0-9._-]+$#', $ruta)) {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $ruta;
    if (is_readable($abs)) {
        return;
    }
    if (!asegurarDirectorioPasePie()) {
        return;
    }
    @file_put_contents($abs, $datos);
    @chmod($abs, 0644);
}

/** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} */
function guardarPasePieEventoArchivo(array $archivo, $eventoId) {
    $eventoId = (int) $eventoId;
    if ($eventoId < 1) {
        return [null, 'Evento no válido.', null, null];
    }
    if (!isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null, null, null];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return [null, 'No se pudo subir la imagen del pie del pase.', null, null];
    }
    if (($archivo['size'] ?? 0) > PASE_PIE_EVENTO_MAX_BYTES) {
        return [null, 'La imagen del pie no debe pasar de 3 MB.', null, null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($tipos[$mime])) {
        return [null, 'Usa JPG, PNG o WebP para el pie del pase.', null, null];
    }

    $datos = file_get_contents($archivo['tmp_name']);
    if ($datos === false || $datos === '') {
        return [null, 'No se pudo leer la imagen del pie del pase.', null, null];
    }

    $nombre = 'evento-' . $eventoId . '-pie.' . $tipos[$mime];
    $rel = PASE_PIE_EVENTO_REL . '/' . $nombre;

    if (asegurarDirectorioPasePie()) {
        $dest = dirname(__DIR__) . '/' . $rel;
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $viejo = dirname(__DIR__) . '/' . PASE_PIE_EVENTO_REL . '/evento-' . $eventoId . '-pie.' . $ext;
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
