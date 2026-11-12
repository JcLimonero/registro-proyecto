<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pase-pie-evento.php';

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

$stmt = $conn->prepare('SELECT pase_pie, pase_pie_mime, pase_pie_datos FROM eventos WHERE id = :id');
$stmt->execute([':id' => $id]);
$fila = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fila || empty($fila['pase_pie_datos'])) {
    http_response_code(404);
    exit;
}

$mime = trim((string) ($fila['pase_pie_mime'] ?? ''));
if ($mime === '' || strpos($mime, 'image/') !== 0) {
    $mime = 'image/jpeg';
}

restaurarPasePieEventoEnDisco($id, $fila['pase_pie'] ?? '', $fila['pase_pie_datos'], $mime);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
echo $fila['pase_pie_datos'];
