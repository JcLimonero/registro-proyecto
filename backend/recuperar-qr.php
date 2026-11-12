<?php
/**
 * Recupera un registro existente por correo (y evento si hay varios) para volver a ver/descargar el QR.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/enviar-correo.php';
require_once __DIR__ . '/pase-pie-evento.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

function fechaLegibleRegistro($valor) {
    $valor = (string) $valor;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $valor, $m)) {
        return "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}:{$m[5]}";
    }
    return $valor;
}

function normalizarCorreo($correo) {
    return mb_strtolower(trim((string) $correo), 'UTF-8');
}

function filaARegistroRespuesta(array $fila) {
    return [
        'idTicket'       => $fila['id_ticket'],
        'nombre'         => $fila['nombre'],
        'numEmpleado'    => $fila['num_empleado'],
        'agencia'        => $fila['agencia'],
        'puesto'         => $fila['puesto'],
        'area'           => $fila['area'],
        'correo'         => $fila['correo'],
        'fechaRegistro'  => fechaLegibleRegistro($fila['fecha_registro']),
        'eventoNombre'   => $fila['evento_nombre'],
        'eventoInicio'   => fechaLegibleRegistro($fila['fecha_inicio']),
        'eventoFin'      => fechaLegibleRegistro($fila['fecha_fin']),
        'eventoUbicacion'=> ligaUbicacion($fila['ubicacion'] ?? ''),
        'eventoId'       => (int) $fila['evento_id'],
        'pasePie'        => pasePieUrlEvento(array_merge($fila, ['id' => (int) $fila['evento_id']])),
    ];
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Solicitud no válida');
    }

    $correo = normalizarCorreo($data['correo'] ?? '');
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Indica un correo electrónico válido.');
    }

    $eventoId = isset($data['eventoId']) && $data['eventoId'] !== '' ? (int) $data['eventoId'] : 0;
    $reenviarCorreo = !empty($data['reenviarCorreo']);

    createTable();
    $conn = getConnection();
    if (!$conn) {
        throw new RuntimeException('No hay conexión con la base de datos.');
    }

    $sql = 'SELECT r.nombre, r.num_empleado, r.agencia, r.puesto, r.area, r.correo,
                   r.id_ticket, r.fecha_registro, r.evento_id,
                   e.nombre AS evento_nombre, e.fecha_inicio, e.fecha_fin, e.ubicacion,
                   e.pase_pie, (LENGTH(e.pase_pie_datos) > 0) AS tiene_pase_pie_blob
            FROM registros r
            INNER JOIN eventos e ON e.id = r.evento_id
            WHERE LOWER(TRIM(r.correo)) = :correo
            ORDER BY r.fecha_registro DESC';
    $stmt = $conn->prepare($sql);
    $stmt->execute([':correo' => $correo]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$filas) {
        throw new RuntimeException('No encontramos un registro con ese correo.');
    }

    if ($eventoId > 0) {
        $filas = array_values(array_filter($filas, fn($f) => (int) $f['evento_id'] === $eventoId));
        if (!$filas) {
            throw new RuntimeException('No hay registro con ese correo para el evento elegido.');
        }
    }

    // Varios registros con el mismo correo en un solo evento → el más reciente (fecha_registro DESC).
    $eventosDistintos = [];
    foreach ($filas as $f) {
        $id = (int) $f['evento_id'];
        if (!isset($eventosDistintos[$id])) {
            $eventosDistintos[$id] = $f['evento_nombre'];
        }
    }

    if (count($eventosDistintos) > 1 && $eventoId === 0) {
        $eventos = [];
        foreach ($eventosDistintos as $id => $nombre) {
            $eventos[] = ['id' => $id, 'nombre' => $nombre];
        }
        echo json_encode([
            'success'         => false,
            'necesita_evento' => true,
            'message'         => 'Tienes registros en varios eventos; elige cuál necesitas.',
            'eventos'         => $eventos,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $registro = filaARegistroRespuesta($filas[0]);
    $correoEnviado = null;
    $mensajeCorreo = '';

    if ($reenviarCorreo) {
        $resultado = enviarCorreo($registro);
        $correoEnviado = (bool) ($resultado['success'] ?? false);
        $mensajeCorreo = (string) ($resultado['message'] ?? '');
    }

    echo json_encode([
        'success'       => true,
        'message'       => 'Registro encontrado.',
        'registro'      => $registro,
        'correoEnviado' => $correoEnviado,
        'mensajeCorreo' => $correoEnviado ? '' : $mensajeCorreo,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('recuperar-qr.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo recuperar el registro. Intenta de nuevo.'], JSON_UNESCAPED_UNICODE);
}
