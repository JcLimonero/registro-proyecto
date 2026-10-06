<?php
/**
 * Pantalla de registros: login con contraseña, tabla de la base MySQL,
 * edición y eliminación de registros (solo con sesión) y descarga en formato
 * SpreadsheetML (XML de Excel 2003, .xls).
 * No usa librerías externas (la imagen de producción no tiene ext-zip).
 */

require_once __DIR__ . '/backend/config.php';

/** Columnas del Excel (todas). */
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

/**
 * Columnas de la tabla en pantalla (después del estado). La fecha de entrada
 * se ve junto a la pastilla de estado y «Confirmado» solo va en el Excel.
 */
const COLUMNAS_VISTA = [
    'nombre'         => 'Nombre',
    'num_empleado'   => 'Núm. empleado',
    'agencia'        => 'Agencia',
    'puesto'         => 'Puesto',
    'area'           => 'Área',
    'correo'         => 'Correo',
    'id_ticket'      => 'ID Ticket',
    'fecha_registro' => 'Fecha de registro',
];

/** Campos editables y su longitud máxima (num_empleado es VARCHAR(50) en la base). */
const CAMPOS_EDITABLES = [
    'nombre'       => ['Nombre', 100],
    'num_empleado' => ['Número de empleado', 50],
    'agencia'      => ['Agencia', 100],
    'puesto'       => ['Puesto', 100],
    'area'         => ['Área', 100],
    'correo'       => ['Correo', 100],
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

function h($texto) {
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Fecha de entrada como «d/m/Y H:i», o null si la persona aún no ha entrado. */
function fechaEntradaTexto(array $fila) {
    $valor = $fila['fecha_entrada'] ?? null;
    if ($valor === null || trim((string) $valor) === '') {
        return null;
    }
    $ts = strtotime((string) $valor);
    return $ts === false ? (string) $valor : date('d/m/Y H:i', $ts);
}

/** HTML de la pastilla de estado: «Entró» (con fecha) o «Pendiente». */
function htmlEstado(array $fila) {
    $fecha = fechaEntradaTexto($fila);
    if ($fecha === null) {
        return '<span class="pastilla pendiente">Pendiente</span>';
    }
    return '<span class="pastilla entro">Entró</span><span class="fecha-entrada sec">' . h($fecha) . '</span>';
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
        $filas = $conn->query("SELECT id, $columnas FROM registros ORDER BY fecha_registro DESC")->fetchAll(PDO::FETCH_ASSOC);
        return [$filas, null];
    } catch (PDOException $e) {
        error_log('registros.php: ' . $e->getMessage());
        return [[], 'No se pudieron leer los registros.'];
    }
}

/** Un registro por id numérico (o null si no existe). Lanza PDOException si falla la base. */
function obtenerRegistroPorId(PDO $conn, $id) {
    $stmt = $conn->prepare('SELECT id, ' . implode(', ', array_keys(COLUMNAS_REGISTROS)) . ' FROM registros WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila ?: null;
}

/** Id numérico positivo a partir de entrada del cliente, o null. */
function idValido($valor) {
    if (!is_string($valor) && !is_int($valor)) {
        return null;
    }
    $valor = (string) $valor;
    return (ctype_digit($valor) && (int) $valor > 0 && strlen($valor) <= 10) ? (int) $valor : null;
}

/** Valida y limpia los campos editables. Devuelve [valores, errores]. */
function validarCamposEdicion(array $entrada) {
    $valores = [];
    $errores = [];
    foreach (CAMPOS_EDITABLES as $campo => $def) {
        list($etiqueta, $max) = $def;
        $v = $entrada[$campo] ?? '';
        $v = is_string($v) ? trim($v) : '';
        $valores[$campo] = $v;
        if ($v === '') {
            $errores[] = "$etiqueta es obligatorio.";
        } elseif (mb_strlen($v, 'UTF-8') > $max) {
            $errores[] = "$etiqueta no puede pasar de $max caracteres.";
        } elseif ($campo === 'correo' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo no es válido.';
        }
    }
    return [$valores, $errores];
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
if ($autenticado && empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $autenticado ? (string) $_SESSION['csrf'] : '';
$errorLogin = null;

/** Respuesta de texto plano y fin. */
function responderTexto($codigo, $texto) {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $texto;
    exit;
}

/** Guarda un mensaje para mostrarlo tras la redirección y vuelve a la tabla. */
function volverConMensaje($tipo, $texto) {
    $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: registros.php');
    exit;
}

// Edición en curso (tarjeta sobre la tabla) y mensajes.
$edicion = null;       // ['id' => int, 'id_ticket' => string, 'valores' => array]
$erroresEdicion = [];
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'entrar') {
        $clave = (string) ($_POST['password'] ?? '');
        if (ADMIN_PASSWORD === '') {
            http_response_code(503);
            $errorLogin = 'El acceso no está configurado';
        } elseif (hash_equals((string) ADMIN_PASSWORD, $clave)) {
            session_regenerate_id(true);
            $_SESSION['registros_ok'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: registros.php');
            exit;
        } else {
            usleep(500000);
            http_response_code(401);
            $errorLogin = 'Contraseña incorrecta';
        }
    } elseif ($accion === 'salir' || $accion === 'guardar' || $accion === 'eliminar') {
        if (!$autenticado) {
            if ($accion === 'salir') {
                header('Location: registros.php');
                exit;
            }
            responderTexto(401, 'No autorizado');
        }
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($csrf, $token)) {
            responderTexto(403, 'Token inválido');
        }

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

        $id = idValido($_POST['id'] ?? null);
        $conn = getConnection();
        if (!$conn) {
            volverConMensaje('error', 'No se pudo conectar a la base de datos.');
        }

        try {
            if ($accion === 'eliminar') {
                if ($id === null) {
                    volverConMensaje('error', 'Registro no válido.');
                }
                $stmt = $conn->prepare('DELETE FROM registros WHERE id = :id');
                $stmt->execute([':id' => $id]);
                if ($stmt->rowCount() === 0) {
                    volverConMensaje('error', 'El registro ya no existe.');
                }
                volverConMensaje('ok', 'Registro eliminado.');
            }

            // guardar
            $actual = $id === null ? null : obtenerRegistroPorId($conn, $id);
            if ($actual === null) {
                volverConMensaje('error', 'El registro no existe; no se guardó nada.');
            }
            list($valores, $errores) = validarCamposEdicion($_POST);
            if ($errores) {
                http_response_code(422);
                $edicion = ['id' => $id, 'id_ticket' => (string) $actual['id_ticket'], 'valores' => $valores];
                $erroresEdicion = $errores;
            } else {
                $sets = [];
                $params = [':id' => $id];
                foreach ($valores as $campo => $v) {
                    $sets[] = "$campo = :$campo";
                    $params[":$campo"] = $v;
                }
                $stmt = $conn->prepare('UPDATE registros SET ' . implode(', ', $sets) . ' WHERE id = :id');
                $stmt->execute($params);
                volverConMensaje('ok', 'Cambios guardados.');
            }
        } catch (PDOException $e) {
            error_log('registros.php: ' . $e->getMessage());
            volverConMensaje('error', 'No se pudo completar la operación.');
        }
    }
}

// Descarga: solo con sesión, sin filas ni login para quien no la tenga.
if (isset($_GET['descargar'])) {
    if (!$autenticado) {
        responderTexto(401, 'No autorizado');
    }
    list($filas, $error) = obtenerRegistros();
    if ($error !== null) {
        responderTexto(503, $error);
    }
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="registros-' . date('Ymd') . '.xls"');
    echo generarSpreadsheetML($filas);
    exit;
}

// Sin sesión no se edita: 401 (se muestra el login).
if (isset($_GET['editar']) && !$autenticado) {
    http_response_code(401);
}

$filas = [];
$errorDatos = null;
if ($autenticado) {
    list($filas, $errorDatos) = obtenerRegistros();

    if ($edicion === null && isset($_GET['editar'])) {
        $idEditar = idValido($_GET['editar']);
        $encontrada = null;
        if ($idEditar !== null) {
            foreach ($filas as $f) {
                if ((int) $f['id'] === $idEditar) {
                    $encontrada = $f;
                    break;
                }
            }
        }
        if ($encontrada) {
            $valores = [];
            foreach (array_keys(CAMPOS_EDITABLES) as $campo) {
                $valores[$campo] = (string) $encontrada[$campo];
            }
            $edicion = ['id' => $idEditar, 'id_ticket' => (string) $encontrada['id_ticket'], 'valores' => $valores];
        } elseif (!$errorDatos) {
            http_response_code(404);
            $mensaje = ['tipo' => 'error', 'texto' => 'El registro que quieres editar no existe.'];
        }
    }

    if (!empty($_SESSION['flash'])) {
        $mensaje = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
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
        table { width: 100%; min-width: 960px; border-collapse: collapse; }
        thead { background: #f8fafc; }
        th {
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
            padding: 14px 16px;
            border-bottom: 1px solid #adb5bd;
            vertical-align: bottom;
        }
        td { padding: 12px 16px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
        tr:last-child td { border-bottom: 0; }
        td.texto { overflow-wrap: anywhere; word-break: break-word; }
        td.fecha { white-space: nowrap; }
        .vacio, .aviso { padding: 48px 16px; text-align: center; color: #6c757d; }

        .pastilla {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
        }
        .pastilla.entro { background: #1a1a1a; color: #fff; }
        .pastilla.pendiente { background: #eef0f3; color: #212529; }
        .fecha-entrada { display: block; margin-top: 4px; font-size: 12px; white-space: nowrap; }

        .fila-acciones { display: flex; gap: 6px; flex-wrap: wrap; }
        .fila-acciones form { margin: 0; }
        .btn-fila {
            height: 30px;
            padding: 0 12px;
            font-size: 12px;
            letter-spacing: 0.04em;
            text-transform: none;
            font-weight: 500;
            background: #fff;
            color: #212529;
            border: 1px solid #adb5bd;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        .btn-fila:hover { background: #f4f5f7; }
        .btn-fila.eliminar { color: #b02a37; }

        .mensaje { padding: 12px 16px; margin-bottom: 20px; border-radius: 8px; font-size: 14px; background: #fff; border: 1px solid #adb5bd; }
        .mensaje.ok { border-left: 4px solid #1a1a1a; }
        .mensaje.error { border-left: 4px solid #b02a37; color: #b02a37; }

        .edicion { padding: 24px; margin-bottom: 20px; }
        .edicion h2 { margin: 0 0 4px; font-size: 16px; font-weight: 700; }
        .edicion .ticket { margin: 0 0 20px; overflow-wrap: anywhere; }
        .campos { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .campos label { display: block; margin-bottom: 6px; }
        .campos input {
            width: 100%;
            height: 40px;
            border: 0;
            background: #eef0f3;
            padding: 0 12px;
            font: inherit;
            color: #212529;
            border-radius: 0;
        }
        .campos input:focus { outline: 2px solid #adb5bd; }
        .edicion ul { margin: 0 0 16px; padding-left: 20px; color: #b02a37; }
        .edicion .botones { display: flex; gap: 8px; flex-wrap: wrap; }
        .edicion .btn-primario { width: auto; }
        .edicion form { margin: 0; }
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
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <button type="submit" class="btn-sec">Salir</button>
                </form>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="mensaje <?= $mensaje['tipo'] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($mensaje['texto']) ?></div>
        <?php endif; ?>

        <?php if ($edicion): ?>
        <div class="tarjeta edicion">
            <h2>Editar registro</h2>
            <p class="ticket sec">ID Ticket: <strong><?= h($edicion['id_ticket']) ?></strong></p>
            <?php if ($erroresEdicion): ?>
                <ul role="alert">
                <?php foreach ($erroresEdicion as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="registros.php">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $edicion['id'] ?>">
                <div class="campos">
                <?php foreach (CAMPOS_EDITABLES as $campo => $def): ?>
                    <div>
                        <label class="etiqueta sec" for="f-<?= h($campo) ?>"><?= h($def[0]) ?></label>
                        <input type="<?= $campo === 'correo' ? 'email' : 'text' ?>" id="f-<?= h($campo) ?>" name="<?= h($campo) ?>"
                               value="<?= h($edicion['valores'][$campo] ?? '') ?>" maxlength="<?= (int) $def[1] ?>" required>
                    </div>
                <?php endforeach; ?>
                </div>
                <div class="botones">
                    <button type="submit" class="btn-primario">Guardar</button>
                    <a class="btn btn-sec" href="registros.php">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="tarjeta tabla-wrap">
        <?php if ($errorDatos): ?>
            <div class="aviso" role="alert"><?= h($errorDatos) ?></div>
        <?php elseif (!$filas): ?>
            <div class="vacio">Aún no hay registros.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Estado</th>
                    <?php foreach (COLUMNAS_VISTA as $titulo): ?>
                        <th><?= h($titulo) ?></th>
                    <?php endforeach; ?>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $fila): ?>
                    <tr>
                        <td class="estado"><?= htmlEstado($fila) ?></td>
                    <?php foreach (array_keys(COLUMNAS_VISTA) as $columna): ?>
                        <td class="<?= $columna === 'fecha_registro' ? 'fecha' : 'texto' ?>"><?= h(valorCelda($fila, $columna)) ?></td>
                    <?php endforeach; ?>
                        <td>
                            <div class="fila-acciones">
                                <a class="btn-fila" href="registros.php?editar=<?= (int) $fila['id'] ?>">Editar</a>
                                <form method="post" action="registros.php"
                                      onsubmit="return confirm(<?= h(json_encode('¿Eliminar el registro de ' . valorCelda($fila, 'nombre') . '? Esta acción no se puede deshacer.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>);">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $fila['id'] ?>">
                                    <button type="submit" class="btn-fila eliminar">Eliminar</button>
                                </form>
                            </div>
                        </td>
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
