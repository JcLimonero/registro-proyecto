<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'registro_proyecto');

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'tu-correo@gmail.com');
define('SMTP_PASS', 'tu-contraseña');
define('SMTP_FROM', 'tu-correo@gmail.com');

function getConnection() {
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch(PDOException $e) {
        return null;
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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $conn->exec($sql);
        } catch(PDOException $e) {
        }
    }
}
?>