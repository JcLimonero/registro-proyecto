<?php
/**
 * Sirve el cartel del evento desde la base (sobrevive redeploys sin volumen persistente).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cartel-evento.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    http_response_code(404);
    exit;
}

createTable();
$conn = getConnection();
if (!$conn) {
    http_response_code(503);
    exit;
}

$stmt = $conn->prepare('SELECT cartel, cartel_mime, cartel_datos FROM eventos WHERE id = :id');
$stmt->execute([':id' => $id]);
$fila = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fila || empty($fila['cartel_datos'])) {
    http_response_code(404);
    exit;
}

$mime = trim((string) ($fila['cartel_mime'] ?? ''));
if ($mime === '' || strpos($mime, 'image/') !== 0) {
    $mime = 'image/jpeg';
}

restaurarCartelEventoEnDisco($id, $fila['cartel'] ?? '', $fila['cartel_datos'], $mime);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $fila['cartel_datos'];
