<?php
require_once 'config.php';
require_once __DIR__ . '/sesion-admin.php';

requerirSesionAdmin();

header('Content-Type: application/json');

try {
    $conn = getConnection();
    if (!$conn) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    $sqlTotal = "SELECT COUNT(*) as total FROM registros";
    $stmtTotal = $conn->query($sqlTotal);
    $total = (int) $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sqlConfirmados = "SELECT COUNT(*) as confirmados FROM registros WHERE fecha_entrada IS NOT NULL";
    $stmtConfirmados = $conn->query($sqlConfirmados);
    $confirmados = (int) $stmtConfirmados->fetch(PDO::FETCH_ASSOC)['confirmados'];
    
    $pendientes = $total - $confirmados;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total' => $total,
            'confirmados' => $confirmados,
            'pendientes' => $pendientes
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>