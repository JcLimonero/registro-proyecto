<?php
// Router para el servidor embebido de PHP: php -S localhost:8000 router.php
// Responde 404 a cualquier ruta con un segmento oculto (empieza por punto),
// p. ej. /.env, /.env.example, /.git/config. El resto se sirve igual que antes.
$ruta = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
foreach (explode('/', str_replace('\\', '/', $ruta)) as $segmento) {
    if ($segmento !== '' && $segmento[0] === '.') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No encontrado';
        return true;
    }
}
return false;
