<?php
// Router para el servidor embebido de PHP: php -S localhost:8000 router.php
// Responde 404 a cualquier ruta con un segmento oculto (empieza por punto),
// p. ej. /.env, /.env.example, /.git/config, y a rutas que empiecen por "//"
// (parse_url las interpreta como autoridad y devolvería null).
// El resto se sirve igual que antes.
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

// No se usa parse_url: se recorta la query y el fragmento a mano.
$ruta = $uri;
foreach (['?', '#'] as $corte) {
    $pos = strpos($ruta, $corte);
    if ($pos !== false) {
        $ruta = substr($ruta, 0, $pos);
    }
}

$bloquear = ($ruta === '' || $ruta[0] !== '/' || strpos($ruta, '//') === 0);

if (!$bloquear) {
    // Se decodifica hasta estabilizar para cubrir doble codificación (%252e).
    $decodificada = $ruta;
    for ($i = 0; $i < 3; $i++) {
        $siguiente = rawurldecode($decodificada);
        if ($siguiente === $decodificada) {
            break;
        }
        $decodificada = $siguiente;
    }
    $decodificada = str_replace('\\', '/', $decodificada);
    if (strpos($decodificada, "\0") !== false) {
        $bloquear = true;
    } else {
        foreach (explode('/', $decodificada) as $segmento) {
            if ($segmento !== '' && $segmento[0] === '.') {
                $bloquear = true;
                break;
            }
        }
    }
}

if ($bloquear) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No encontrado';
    return true;
}
return false;
