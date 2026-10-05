<?php
require_once 'config.php';

header('Content-Type: application/json');

createTable();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required_fields = ['nombre', 'numEmpleado', 'agencia', 'puesto', 'area', 'correo', 'idTicket', 'fechaRegistro'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("El campo $field es requerido");
        }
    }
    
    $conn = getConnection();
    if (!$conn) {
        throw new Exception("Error de conexión a la base de datos");
    }
    
    $sql = "INSERT INTO registros (nombre, num_empleado, agencia, puesto, area, correo, id_ticket, fecha_registro) 
            VALUES (:nombre, :num_empleado, :agencia, :puesto, :area, :correo, :id_ticket, :fecha_registro)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':nombre' => $data['nombre'],
        ':num_empleado' => $data['numEmpleado'],
        ':agencia' => $data['agencia'],
        ':puesto' => $data['puesto'],
        ':area' => $data['area'],
        ':correo' => $data['correo'],
        ':id_ticket' => $data['idTicket'],
        ':fecha_registro' => date('Y-m-d H:i:s', strtotime($data['fechaRegistro']))
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Registro exitoso',
        'id' => $conn->lastInsertId()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>