<?php
/**
 * Catálogos públicos de solo lectura para el formulario de registro:
 * agencias, áreas y eventos vigentes (hora de México). Si no hay evento
 * vigente, el registro está cerrado y se devuelve el próximo o el último.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

function eventoPublico(array $e) {
    return [
        'id'           => (int) $e['id'],
        'nombre'       => $e['nombre'],
        'fecha_inicio'     => $e['fecha_inicio'],
        'fecha_fin'        => $e['fecha_fin'],
        'registro_inicio'  => $e['registro_inicio'],
        'registro_fin'     => $e['registro_fin'],
        'ubicacion'        => ligaUbicacion($e['ubicacion'] ?? ''),
    ];
}

try {
    createTable();
    $conn = getConnection();
    if (!$conn) {
        throw new RuntimeException('sin conexión');
    }

    $ahora = ahoraMexico();
    $agencias = $conn->query('SELECT id, nombre FROM agencias ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    $areas = $conn->query('SELECT id, nombre FROM areas ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    $vigentes = eventosVigentes($conn, $ahora);

    $respuesta = [
        'success'  => true,
        'ahora'    => $ahora,
        'abierto'  => count($vigentes) > 0,
        'agencias' => array_map(fn($f) => ['id' => (int) $f['id'], 'nombre' => $f['nombre']], $agencias),
        'areas'    => array_map(fn($f) => ['id' => (int) $f['id'], 'nombre' => $f['nombre']], $areas),
        'eventos'  => array_map('eventoPublico', $vigentes),
        'referencia' => null,
    ];

    if (!$vigentes) {
        list($ref, $tipo) = eventoReferencia($conn, $ahora);
        if ($ref) {
            $respuesta['referencia'] = ['tipo' => $tipo] + eventoPublico($ref);
        }
    }

    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('catalogos.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudieron cargar los datos del registro.']);
}
