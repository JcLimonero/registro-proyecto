<?php
/**
 * Pantalla de registros: login con contraseña, tabla de la base MySQL y
 * descarga en formato SpreadsheetML (XML de Excel 2003, .xls).
 * No usa librerías externas (la imagen de producción no tiene ext-zip).
 */

require_once __DIR__ . '/backend/config.php';

const COLUMNAS_REGISTROS = [
    'nombre'         => 'Nombre',
    'num_empleado'   => 'Número de empleado',
    'agencia'        => 'Agencia',
    'puesto'         => 'Puesto',
    'area'           => 'Área',
    'correo'         => 'Correo',
    'id_ticket'      => 'ID Ticket',
    'fecha_registro' => 'Fecha de registro',
    'fecha_entrada'  => 'Fecha de entrada',
    'confirmado'     => 'Confirmado',
];

/** Valor de una columna listo para mostrar (texto plano, sin escapar). */
function valorCelda(array $fila, $columna) {
    $valor = $fila[$columna] ?? null;
    if ($columna === 'confirmado') {
        return ((int) $valor === 1) ? 'Sí' : 'No';
    }
    return $valor === null ? '' : (string) $valor;
}

/** Escapa para XML 1.0: quita caracteres de control no válidos y escapa &, <, >, comillas. */
function escaparXml($texto) {
    $texto = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $texto);
    return htmlspecialchars((string) $texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Documento SpreadsheetML con el encabezado y las filas dadas. */
function generarSpreadsheetML(array $filas) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<?mso-application progid="Excel.Sheet"?>' . "\n"
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n"
        . '<Styles><Style ss:ID="enc"><Font ss:Bold="1"/></Style></Styles>' . "\n"
        . '<Worksheet ss:Name="Registros"><Table>' . "\n";

    $xml .= '<Row>';
    foreach (COLUMNAS_REGISTROS as $titulo) {
        $xml .= '<Cell ss:StyleID="enc"><Data ss:Type="String">' . escaparXml($titulo) . '</Data></Cell>';
    }
    $xml .= "</Row>\n";

    foreach ($filas as $fila) {
        $xml .= '<Row>';
        foreach (array_keys(COLUMNAS_REGISTROS) as $columna) {
            $xml .= '<Cell><Data ss:Type="String">' . escaparXml(valorCelda($fila, $columna)) . '</Data></Cell>';
        }
        $xml .= "</Row>\n";
    }

    return $xml . '</Table></Worksheet></Workbook>';
}

function esHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Devuelve [filas, error]. */
function obtenerRegistros() {
    $conn = getConnection();
    if (!$conn) {
        return [[], 'No se pudo conectar a la base de datos.'];
    }
    try {
        createTable();
        $columnas = implode(', ', array_keys(COLUMNAS_REGISTROS));
        $filas = $conn->query("SELECT $columnas FROM registros ORDER BY fecha_registro DESC")->fetchAll(PDO::FETCH_ASSOC);
        return [$filas, null];
    } catch (PDOException $e) {
        error_log('registros.php: ' . $e->getMessage());
        return [[], 'No se pudieron leer los registros.'];
    }
}

function h($texto) {
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

if (defined('REGISTROS_SOLO_FUNCIONES')) {
    return;
}

session_name('registros_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => esHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$autenticado = !empty($_SESSION['registros_ok']);
$errorLogin = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'salir') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        header('Location: registros.php');
        exit;
    }
    if ($accion === 'entrar') {
        $clave = (string) ($_POST['password'] ?? '');
        if (hash_equals((string) ADMIN_PASSWORD, $clave)) {
            session_regenerate_id(true);
            $_SESSION['registros_ok'] = true;
            header('Location: registros.php');
            exit;
        }
        usleep(500000);
        http_response_code(401);
        $errorLogin = 'Contraseña incorrecta';
    }
}

// Descarga: solo con sesión, sin filas ni login para quien no la tenga.
if (isset($_GET['descargar'])) {
    if (!$autenticado) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No autorizado';
        exit;
    }
    list($filas, $error) = obtenerRegistros();
    if ($error !== null) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo $error;
        exit;
    }
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="registros-' . date('Ymd') . '.xls"');
    echo generarSpreadsheetML($filas);
    exit;
}

$filas = [];
$errorDatos = null;
if ($autenticado) {
    list($filas, $errorDatos) = obtenerRegistros();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Registros</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f4f5f7;
            color: #212529;
            font-family: 'Montserrat', sans-serif;
            font-size: 14px;
        }
        .sec { color: #6c757d; }
        .etiqueta, button, .btn {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .tarjeta {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
        }
        .login { width: 100%; max-width: 380px; padding: 32px 40px; }
        .login h1 { margin: 0 0 4px; font-size: 20px; font-weight: 700; }
        .login p { margin: 0 0 24px; }
        .login label { display: block; margin-bottom: 8px; }
        .login input[type=password] {
            width: 100%;
            height: 40px;
            border: 0;
            background: #eef0f3;
            padding: 0 12px;
            font: inherit;
            color: #212529;
            border-radius: 0;
            margin-bottom: 16px;
        }
        .login input:focus { outline: 2px solid #adb5bd; }
        .error { color: #b02a37; margin: 0 0 16px; font-size: 13px; }
        button, .btn { font-family: inherit; cursor: pointer; border-radius: 0; height: 40px; padding: 0 20px; }
        .btn-primario { width: 100%; background: #1a1a1a; color: #fff; border: 0; }
        .btn-primario:hover { background: #2a2a2a; }
        .btn-sec {
            display: inline-flex;
            align-items: center;
            background: #fff;
            color: #212529;
            border: 1px solid #adb5bd;
            text-decoration: none;
        }
        .btn-sec:hover { background: #f4f5f7; }
        .panel { max-width: 1280px; margin: 0 auto; padding: 32px 16px; }
        .barra { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .barra h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .acciones { display: flex; gap: 8px; }
        .acciones form { margin: 0; }
        .tabla-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
            padding: 14px 16px;
            border-bottom: 1px solid #adb5bd;
            white-space: nowrap;
        }
        td { padding: 12px 16px; border-bottom: 1px solid #eef0f3; white-space: nowrap; }
        tr:last-child td { border-bottom: 0; }
        .vacio, .aviso { padding: 48px 16px; text-align: center; color: #6c757d; }
    </style>
</head>
<body>
<?php if (!$autenticado): ?>
    <div class="login-wrap">
        <form class="tarjeta login" method="post" action="registros.php" autocomplete="off">
            <h1>Registros</h1>
            <p class="sec">Ingresa la contraseña para continuar.</p>
            <input type="hidden" name="accion" value="entrar">
            <label class="etiqueta sec" for="password">Contraseña</label>
            <input type="password" id="password" name="password" required autofocus>
            <?php if ($errorLogin): ?>
                <p class="error" role="alert"><?= h($errorLogin) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn-primario">Entrar</button>
        </form>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="barra">
            <h1>Registros <span class="sec" style="font-weight:400;font-size:14px;">(<?= count($filas) ?>)</span></h1>
            <div class="acciones">
                <a class="btn btn-sec" href="registros.php?descargar=1">Descargar Excel</a>
                <form method="post" action="registros.php">
                    <input type="hidden" name="accion" value="salir">
                    <button type="submit" class="btn-sec">Salir</button>
                </form>
            </div>
        </div>
        <div class="tarjeta tabla-wrap">
        <?php if ($errorDatos): ?>
            <div class="aviso" role="alert"><?= h($errorDatos) ?></div>
        <?php elseif (!$filas): ?>
            <div class="vacio">Aún no hay registros.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                    <?php foreach (COLUMNAS_REGISTROS as $titulo): ?>
                        <th><?= h($titulo) ?></th>
                    <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $fila): ?>
                    <tr>
                    <?php foreach (array_keys(COLUMNAS_REGISTROS) as $columna): ?>
                        <td><?= h(valorCelda($fila, $columna)) ?></td>
                    <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
