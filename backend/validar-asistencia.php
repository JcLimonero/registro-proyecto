<?php
require_once 'config.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['idTicket']) || empty($data['idTicket'])) {
        throw new Exception('ID Ticket no proporcionado');
    }
    
    $idTicket = trim($data['idTicket']);
    
    $conn = getConnection();
    if (!$conn) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    $sql = "SELECT * FROM registros WHERE id_ticket = :id_ticket";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id_ticket' => $idTicket]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registro) {
        echo json_encode([
            'success' => false,
            'tipo' => 'no_encontrado',
            'message' => 'El ticket no existe en el sistema'
        ]);
        exit;
    }
    
    $yaConfirmadoHoy = false;
    if (!empty($registro['fecha_entrada'])) {
        $fechaEntrada = new DateTime($registro['fecha_entrada']);
        $hoy = new DateTime();
        if ($fechaEntrada->format('Y-m-d') === $hoy->format('Y-m-d')) {
            $yaConfirmadoHoy = true;
        }
    }
    
    if ($yaConfirmadoHoy) {
        echo json_encode([
            'success' => true,
            'tipo' => 'ya_registrado',
            'message' => 'Esta entrada ya fue registrada anteriormente hoy',
            'data' => [
                'nombre' => $registro['nombre'],
                'num_empleado' => $registro['num_empleado'],
                'agencia' => $registro['agencia'],
                'puesto' => $registro['puesto'],
                'area' => $registro['area'],
                'id_ticket' => $registro['id_ticket'],
                'fecha_entrada' => date('d/m/Y H:i:s', strtotime($registro['fecha_entrada']))
            ]
        ]);
        exit;
    }
    
    $fechaEntrada = date('Y-m-d H:i:s');
    
    $sqlUpdate = "UPDATE registros 
                  SET fecha_entrada = :fecha_entrada, 
                      confirmado = 1 
                  WHERE id_ticket = :id_ticket";
    
    $stmtUpdate = $conn->prepare($sqlUpdate);
    $stmtUpdate->execute([
        ':fecha_entrada' => $fechaEntrada,
        ':id_ticket' => $registro['id_ticket']
    ]);
    
    echo json_encode([
        'success' => true,
        'tipo' => 'primera_entrada',
        'message' => 'Entrada registrada exitosamente',
        'data' => [
            'nombre' => $registro['nombre'],
            'num_empleado' => $registro['num_empleado'],
            'agencia' => $registro['agencia'],
            'puesto' => $registro['puesto'],
            'area' => $registro['area'],
            'id_ticket' => $registro['id_ticket'],
            'fecha_entrada' => date('d/m/Y H:i:s', strtotime($fechaEntrada))
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'tipo' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>