<?php
require_once 'config.php';

header('Content-Type: application/json');

createTable();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Solicitud no válida');
    }

    $required_fields = ['nombre', 'numEmpleado', 'agencia', 'puesto', 'area', 'correo', 'idTicket'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("El campo $field es requerido");
        }
    }

    $conn = getConnection();
    if (!$conn) {
        throw new Exception("Error de conexión a la base de datos");
    }

    // Periodo de registro: solo con un evento vigente (hora de México, extremos incluidos).
    // Se usa el reloj del servidor, no la fecha que manda el navegador.
    $ahora = ahoraMexico();
    $vigentes = eventosVigentes($conn, $ahora);
    if (!$vigentes) {
        throw new Exception('El registro está cerrado: no hay un periodo de registro abierto en este momento.');
    }

    $evento = null;
    $eventoId = $data['eventoId'] ?? null;
    if ($eventoId === null || $eventoId === '') {
        if (count($vigentes) > 1) {
            throw new Exception('Elige el evento al que te registras.');
        }
        $evento = $vigentes[0];
    } else {
        foreach ($vigentes as $v) {
            if ((string) $v['id'] === (string) $eventoId) {
                $evento = $v;
                break;
            }
        }
        if ($evento === null) {
            throw new Exception('El registro para el evento elegido está cerrado.');
        }
    }

    // Agencia y área deben existir en el catálogo; se guarda el nombre tal como está dado de alta.
    $stmt = $conn->prepare('SELECT nombre FROM agencias WHERE nombre = :nombre');
    $stmt->execute([':nombre' => trim($data['agencia'])]);
    $agencia = $stmt->fetchColumn();
    if ($agencia === false) {
        throw new Exception('La agencia elegida no existe.');
    }

    $stmt = $conn->prepare('SELECT nombre FROM areas WHERE nombre = :nombre');
    $stmt->execute([':nombre' => trim($data['area'])]);
    $area = $stmt->fetchColumn();
    if ($area === false) {
        throw new Exception('El área elegida no existe.');
    }

    $numEmpleado = trim($data['numEmpleado']);
    $ya = $conn->prepare('SELECT 1 FROM registros WHERE num_empleado = :n AND evento_id = :e LIMIT 1');
    $ya->execute([':n' => $numEmpleado, ':e' => (int) $evento['id']]);
    if ($ya->fetchColumn()) {
        throw new Exception('Este número de empleado ya está registrado en este evento.');
    }

    $sql = "INSERT INTO registros (nombre, num_empleado, agencia, puesto, area, correo, id_ticket, fecha_registro, evento_id)
            VALUES (:nombre, :num_empleado, :agencia, :puesto, :area, :correo, :id_ticket, :fecha_registro, :evento_id)";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':nombre' => $data['nombre'],
        ':num_empleado' => $numEmpleado,
        ':agencia' => $agencia,
        ':puesto' => $data['puesto'],
        ':area' => $area,
        ':correo' => $data['correo'],
        ':id_ticket' => $data['idTicket'],
        ':fecha_registro' => $ahora,
        ':evento_id' => (int) $evento['id'],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Registro exitoso',
        'id' => $conn->lastInsertId(),
        'evento' => $evento['nombre'],
        'fecha_inicio' => $evento['fecha_inicio'],
        'fecha_fin' => $evento['fecha_fin'],
        'ubicacion' => ligaUbicacion($evento['ubicacion'] ?? ''),
    ]);

} catch (PDOException $e) {
    error_log('registrar.php: ' . $e->getMessage());
    $duplicado = (int) ($e->errorInfo[1] ?? 0) === 1062
        && str_contains($e->getMessage(), 'uniq_empleado_evento');
    echo json_encode([
        'success' => false,
        'message' => $duplicado
            ? 'Este número de empleado ya está registrado en este evento.'
            : 'No se pudo guardar el registro. Intenta de nuevo.'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
