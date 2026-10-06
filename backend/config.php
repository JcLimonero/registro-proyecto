<?php
/**
 * Configuración por entorno.
 * Los valores se leen de variables de entorno o del archivo .env en la raíz
 * del proyecto (no versionado). Si no hay entorno, se usan los valores de
 * la cuenta de eventos que ya estaban en el proyecto.
 */

// Hora del evento: fecha_registro llega en hora de México desde el navegador y
// fecha_entrada se escribe con date(); ambas deben usar el mismo reloj.
date_default_timezone_set('America/Mexico_City');

function cargarEnv($ruta) {
    if (!is_readable($ruta)) {
        return;
    }
    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
            continue;
        }
        list($clave, $valor) = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);
        if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        // Una variable ya definida en el entorno tiene prioridad sobre .env
        if ($clave !== '' && getenv($clave) === false) {
            putenv("$clave=$valor");
        }
    }
}

cargarEnv(__DIR__ . '/../.env');

function envOr($clave, $defecto) {
    $valor = getenv($clave);
    return ($valor === false || $valor === '') ? $defecto : $valor;
}

define('DB_HOST', envOr('DB_HOST', 'localhost'));
define('DB_USER', envOr('DB_USER', 'root'));
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', envOr('DB_NAME', 'registro_proyecto'));

// Contraseña de la pantalla de registros (/registros.php). Solo del entorno/.env:
// sin valor, el acceso queda deshabilitado (no hay default en el repo).
define('ADMIN_PASSWORD', envOr('ADMIN_PASSWORD', ''));

define('SMTP_HOST', envOr('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int) envOr('SMTP_PORT', 587));
define('SMTP_USER', envOr('SMTP_USER', 'eventosvgd@gmail.com'));
define('SMTP_PASS', envOr('SMTP_PASS', 'stuh dewv dtue iohz'));
define('SMTP_FROM', envOr('SMTP_FROM', 'eventosvgd@gmail.com'));

function getConnection() {
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch(PDOException $e) {
        return null;
    }
}

/**
 * Añade una columna a `registros` solo si no existe (compatible con MySQL 5.7+ y MariaDB).
 */
function asegurarColumna(PDO $conn, $columna, $definicion) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros' AND COLUMN_NAME = :columna"
    );
    $stmt->execute([':columna' => $columna]);
    if ((int) $stmt->fetchColumn() === 0) {
        $conn->exec("ALTER TABLE registros ADD COLUMN $columna $definicion");
    }
}

function createTable() {
    $conn = getConnection();
    if ($conn) {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS registros (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                num_empleado VARCHAR(50) NOT NULL,
                agencia VARCHAR(100) NOT NULL,
                puesto VARCHAR(100) NOT NULL,
                area VARCHAR(100) NOT NULL,
                correo VARCHAR(100) NOT NULL,
                id_ticket VARCHAR(50) NOT NULL UNIQUE,
                fecha_registro DATETIME NOT NULL,
                fecha_entrada DATETIME NULL,
                confirmado TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4";
            $conn->exec($sql);

            // Tablas creadas antes de que existieran estas columnas
            asegurarColumna($conn, 'fecha_entrada', 'DATETIME NULL');
            asegurarColumna($conn, 'confirmado', 'TINYINT(1) NOT NULL DEFAULT 0');
        } catch(PDOException $e) {
            error_log('createTable: ' . $e->getMessage());
        }
    }
}
?>
