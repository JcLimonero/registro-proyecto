<?php
/**
 * Exige la sesión de administrador de registros.php (misma cookie «registros_sid»).
 * Sin sesión responde 401 en JSON y termina. La sesión se abre solo para leer
 * y se cierra enseguida, para no bloquear otras peticiones.
 */
function requerirSesionAdmin() {
    session_name('registros_sid');
    session_start(['read_and_close' => true]);

    if (empty($_SESSION['registros_ok'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'tipo' => 'sin_sesion',
            'message' => 'Sesión no válida. Entra de nuevo en registros.'
        ]);
        exit;
    }
}
