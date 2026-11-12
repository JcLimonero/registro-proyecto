<?php
/**
 * Pantalla de registros: login con contraseña, tabla de la base MySQL,
 * edición y eliminación de registros (solo con sesión) y descarga en formato
 * SpreadsheetML (XML de Excel 2003, .xls).
 * No usa librerías externas (la imagen de producción no tiene ext-zip).
 */

require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/cartel-evento.php';
require_once __DIR__ . '/backend/pase-pie-evento.php';

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
    'evento'         => 'Evento',
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
    'evento'         => 'Evento',
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

/** Icono de trazo (agregar, guardar o eliminar) para los botones de acción. */
function icono($nombre) {
    $trazos = [
        'agregar'  => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'guardar'  => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
        'eliminar' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'editar'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'cerrar'   => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    ];
    return '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($trazos[$nombre] ?? '') . '</svg>';
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

/** SELECT de registros con el nombre del evento (vacío en los registros sin evento). */
function sqlSelectRegistros() {
    $cols = [];
    foreach (array_keys(COLUMNAS_REGISTROS) as $c) {
        $cols[] = $c === 'evento' ? "COALESCE(e.nombre, '') AS evento" : "r.$c";
    }
    return 'SELECT r.id, ' . implode(', ', $cols) . ' FROM registros r LEFT JOIN eventos e ON e.id = r.evento_id';
}

/** Texto de entrada del cliente (solo cadenas), sin espacios en los bordes y con tope de caracteres. */
function textoFiltro($valor, $max) {
    if (!is_string($valor)) {
        return '';
    }
    $valor = trim($valor);
    return function_exists('mb_substr') ? mb_substr($valor, 0, $max, 'UTF-8') : substr($valor, 0, $max);
}

/**
 * Filtros de la tabla de registros a partir de GET o POST (evento, estado, agencia, q).
 * Devuelve siempre las cuatro claves con valores ya saneados; lo inválido se ignora ('').
 * evento: '' | 'sin' | id numérico positivo.
 */
function filtrosRegistros(array $entrada) {
    $evento = textoFiltro($entrada['evento'] ?? '', 20);
    if ($evento !== 'sin') {
        $idEvento = idValido($evento);
        $evento = $idEvento === null ? '' : (string) $idEvento;
    }
    $estado = textoFiltro($entrada['estado'] ?? '', 20);
    if (!in_array($estado, ['entro', 'pendiente'], true)) {
        $estado = '';
    }
    return [
        'evento'  => $evento,
        'estado'  => $estado,
        'agencia' => textoFiltro($entrada['agencia'] ?? '', 100),
        'q'       => textoFiltro($entrada['q'] ?? '', 100),
    ];
}

/**
 * Filtros que viajan en un POST de la tabla. Van con prefijo «f_» (f_evento, f_estado, f_agencia, f_q)
 * porque «agencia» ya es un campo del formulario de edición.
 */
function filtrosDePost() {
    $entrada = [];
    foreach (['evento', 'estado', 'agencia', 'q'] as $clave) {
        $entrada[$clave] = $_POST['f_' . $clave] ?? '';
    }
    return filtrosRegistros($entrada);
}

/** Inputs hidden con los filtros activos (prefijo «f_») para los formularios POST de la tabla. */
function camposOcultosFiltros(array $filtros) {
    $html = '';
    foreach (filtrosActivos($filtros) as $clave => $valor) {
        $html .= '<input type="hidden" name="f_' . h($clave) . '" value="' . h($valor) . '">';
    }
    return $html;
}

/** ¿Hay algún filtro activo? */
function hayFiltros(array $filtros) {
    return implode('', $filtros) !== '';
}

/** Solo los filtros activos (para armar query strings). */
function filtrosActivos(array $filtros) {
    return array_filter($filtros, function ($v) { return $v !== ''; });
}

/** Escapa \ % _ para usar el texto dentro de un LIKE. */
function escaparLike($texto) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
}

/** Condiciones WHERE (prepared) para los filtros. Devuelve [sql con «WHERE ...» o '', params]. */
function whereRegistros(array $filtros) {
    $cond = [];
    $params = [];
    if ($filtros['evento'] === 'sin') {
        $cond[] = 'r.evento_id IS NULL';
    } elseif ($filtros['evento'] !== '') {
        $cond[] = 'r.evento_id = :evento';
        $params[':evento'] = (int) $filtros['evento'];
    }
    if ($filtros['estado'] === 'entro') {
        $cond[] = 'r.fecha_entrada IS NOT NULL';
    } elseif ($filtros['estado'] === 'pendiente') {
        $cond[] = 'r.fecha_entrada IS NULL';
    }
    if ($filtros['agencia'] !== '') {
        $cond[] = 'r.agencia = :agencia';
        $params[':agencia'] = $filtros['agencia'];
    }
    if ($filtros['q'] !== '') {
        $like = '%' . escaparLike($filtros['q']) . '%';
        $cond[] = '(r.nombre LIKE :q1 OR r.correo LIKE :q2 OR r.num_empleado LIKE :q3 OR r.id_ticket LIKE :q4)';
        $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $like;
    }
    return [$cond ? ' WHERE ' . implode(' AND ', $cond) : '', $params];
}

/** Devuelve [filas, error]. */
function obtenerRegistros(array $filtros = []) {
    $conn = getConnection();
    if (!$conn) {
        return [[], 'No se pudo conectar a la base de datos.'];
    }
    try {
        createTable();
        $filtros += ['evento' => '', 'estado' => '', 'agencia' => '', 'q' => ''];
        list($where, $params) = whereRegistros($filtros);
        $stmt = $conn->prepare(sqlSelectRegistros() . $where . ' ORDER BY r.fecha_registro DESC');
        $stmt->execute($params);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return [$filas, null];
    } catch (PDOException $e) {
        error_log('registros.php: ' . $e->getMessage());
        return [[], 'No se pudieron leer los registros.'];
    }
}

/** Opciones de los selects de filtro: [eventos [id, nombre], agencias [nombre]]. Lanza PDOException si falla. */
function opcionesFiltros(PDO $conn) {
    $eventos = $conn->query('SELECT id, nombre FROM eventos ORDER BY fecha_inicio DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    // Catálogo + texto ya guardado en registros (UNION DISTINCT hecho en PHP para no depender de las collations).
    $agencias = [];
    $consultas = ['SELECT nombre FROM agencias', 'SELECT DISTINCT agencia AS nombre FROM registros'];
    foreach ($consultas as $sql) {
        foreach ($conn->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $nombre) {
            $nombre = trim((string) $nombre);
            if ($nombre !== '') {
                $agencias[$nombre] = $nombre;
            }
        }
    }
    $agencias = array_values($agencias);
    sort($agencias, SORT_NATURAL | SORT_FLAG_CASE);
    return [$eventos, $agencias];
}

/** Un registro por id numérico (o null si no existe). Lanza PDOException si falla la base. */
function obtenerRegistroPorId(PDO $conn, $id) {
    $stmt = $conn->prepare(sqlSelectRegistros() . ' WHERE r.id = :id');
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

/** Catálogos administrables: tipo => [tabla, singular, plural, vista, longitud máxima]. */
const CATALOGOS = [
    'agencia' => ['agencias', 'agencia', 'agencias', 'agencias', 100],
    'area'    => ['areas',    'área',    'áreas',    'areas',    100],
];
const EVENTO_NOMBRE_MAX = 150;

/** Nombre limpio y su error (o null). */
function validarNombreCatalogo($valor, $max, $etiqueta) {
    $valor = is_string($valor) ? trim(preg_replace('/\s+/u', ' ', $valor)) : '';
    if ($valor === '') {
        return [$valor, "El nombre de $etiqueta es obligatorio."];
    }
    if (mb_strlen($valor, 'UTF-8') > $max) {
        return [$valor, "El nombre de $etiqueta no puede pasar de $max caracteres."];
    }
    return [$valor, null];
}

/** «Y-m-d\TH:i» (datetime-local) a «Y-m-d H:i:00»; null si no es una fecha real. */
function fechaLocalADb($valor) {
    if (!is_string($valor)) {
        return null;
    }
    $valor = trim($valor);
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $formato) {
        $d = DateTime::createFromFormat($formato, $valor);
        if ($d && $d->format($formato) === $valor) {
            return $d->format('Y-m-d H:i:00');
        }
    }
    return null;
}

/** Valor de un DATETIME de MySQL para un input datetime-local. */
function fechaDbALocal($valor) {
    $ts = strtotime((string) $valor);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

function fechaLegible($valor) {
    $ts = strtotime((string) $valor);
    return $ts === false ? (string) $valor : date('d/m/Y H:i', $ts);
}

/** Estado de un evento respecto a «ahora»: vigente, proximo o finalizado. */
function estadoEvento(array $e, $ahora) {
    if ($e['fecha_inicio'] > $ahora) {
        return 'proximo';
    }
    return $e['fecha_fin'] >= $ahora ? 'vigente' : 'finalizado';
}

function mbPrimeraMayuscula($texto) {
    return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
}

/** Mensaje de borrado/alta listo para confirm() en JS. */
function jsConfirm($texto) {
    return h(json_encode($texto, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
}

/**
 * Alta, edición y baja de agencias, áreas y eventos. Prepared statements siempre.
 * Termina siempre con una redirección y un mensaje.
 */
/**
 * Cartel subido y marca de evento principal en el admin de eventos.
 */
function aplicarExtrasEvento(PDO $conn, array $post, array $files, $eventoId) {
    $eventoId = (int) $eventoId;
    $marcarPrincipal = !empty($post['es_principal']);
    if ($marcarPrincipal) {
        $conn->exec('UPDATE eventos SET es_principal = 0');
    }
    $conn->prepare('UPDATE eventos SET es_principal = :p WHERE id = :id')
        ->execute([':p' => $marcarPrincipal ? 1 : 0, ':id' => $eventoId]);

    $archivo = $files['cartel'] ?? [];
    list($rel, $err, $mime, $datos) = guardarCartelEventoArchivo($archivo, $eventoId);
    if ($err !== null) {
        volverConMensaje('error', $err, 'eventos');
    }
    if ($rel !== null && $datos !== null) {
        $prevStmt = $conn->prepare('SELECT cartel FROM eventos WHERE id = :id');
        $prevStmt->execute([':id' => $eventoId]);
        $prevCartel = $prevStmt->fetchColumn();
        if ($prevCartel && $prevCartel !== $rel) {
            eliminarCartelEventoArchivo($prevCartel);
        }
        $stmt = $conn->prepare('UPDATE eventos SET cartel = :c, cartel_mime = :m, cartel_datos = :d WHERE id = :id');
        $stmt->bindValue(':c', $rel, PDO::PARAM_STR);
        $stmt->bindValue(':m', $mime, PDO::PARAM_STR);
        $stmt->bindValue(':d', $datos, PDO::PARAM_LOB);
        $stmt->bindValue(':id', $eventoId, PDO::PARAM_INT);
        $stmt->execute();
    }

    $archivoPie = $files['pase_pie'] ?? [];
    list($relPie, $errPie, $mimePie, $datosPie) = guardarPasePieEventoArchivo($archivoPie, $eventoId);
    if ($errPie !== null) {
        volverConMensaje('error', $errPie, 'eventos');
    }
    if ($relPie !== null && $datosPie !== null) {
        $prevPie = $conn->prepare('SELECT pase_pie FROM eventos WHERE id = :id');
        $prevPie->execute([':id' => $eventoId]);
        $prevRutaPie = $prevPie->fetchColumn();
        if ($prevRutaPie && $prevRutaPie !== $relPie) {
            eliminarPasePieEventoArchivo($prevRutaPie);
        }
        $stmtPie = $conn->prepare('UPDATE eventos SET pase_pie = :c, pase_pie_mime = :m, pase_pie_datos = :d WHERE id = :id');
        $stmtPie->bindValue(':c', $relPie, PDO::PARAM_STR);
        $stmtPie->bindValue(':m', $mimePie, PDO::PARAM_STR);
        $stmtPie->bindValue(':d', $datosPie, PDO::PARAM_LOB);
        $stmtPie->bindValue(':id', $eventoId, PDO::PARAM_INT);
        $stmtPie->execute();
    }
}

function procesarCatalogo(PDO $conn, $accion, array $post, array $files = []) {
    list($operacion, $tipo) = explode('_', $accion, 2);
    $vista = $tipo === 'evento' ? 'eventos' : CATALOGOS[$tipo][3];
    $id = idValido($post['id'] ?? null);
    $sinId = !isset($post['id']) || $post['id'] === '';

    try {
        createTable();

        if ($tipo === 'evento') {
            if ($operacion === 'eliminar') {
                if ($id === null) {
                    volverConMensaje('error', 'Evento no válido.', $vista);
                }
                $cartelStmt = $conn->prepare('SELECT cartel, pase_pie FROM eventos WHERE id = :id');
                $cartelStmt->execute([':id' => $id]);
                $rowDel = $cartelStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $cartelRuta = $rowDel['cartel'] ?? null;
                $pasePieRuta = $rowDel['pase_pie'] ?? null;
                $conn->beginTransaction();
                // Los registros viejos quedan sin evento (conservan todos sus datos)
                $conn->prepare('UPDATE registros SET evento_id = NULL WHERE evento_id = :id')->execute([':id' => $id]);
                $stmt = $conn->prepare('DELETE FROM eventos WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $conn->commit();
                if ($stmt->rowCount()) {
                    eliminarCartelEventoArchivo($cartelRuta);
                    eliminarPasePieEventoArchivo($pasePieRuta);
                }
                volverConMensaje($stmt->rowCount() ? 'ok' : 'error', $stmt->rowCount() ? 'Evento eliminado.' : 'El evento ya no existe.', $vista);
            }

            list($nombre, $err) = validarNombreCatalogo($post['nombre'] ?? '', EVENTO_NOMBRE_MAX, 'el evento');
            $inicio = fechaLocalADb($post['fecha_inicio'] ?? null);
            $fin = fechaLocalADb($post['fecha_fin'] ?? null);
            $regIni = fechaLocalADb($post['registro_inicio'] ?? null);
            $regFin = fechaLocalADb($post['registro_fin'] ?? null);
            if ($err === null && ($inicio === null || $fin === null)) {
                $err = 'Indica el inicio y el fin del evento.';
            } elseif ($err === null && $fin < $inicio) {
                $err = 'El fin del evento no puede ser anterior al inicio.';
            } elseif ($err === null && ($regIni === null || $regFin === null)) {
                $err = 'Indica desde cuándo y hasta cuándo se puede registrar la gente.';
            } elseif ($err === null && $regFin < $regIni) {
                $err = 'El cierre del registro no puede ser anterior a su apertura.';
            }
            if ($err !== null) {
                volverConMensaje('error', $err, $vista);
            }
            $ubicacion = ligaUbicacion($post['ubicacion'] ?? '');
            if (trim((string) ($post['ubicacion'] ?? '')) !== '' && $ubicacion === '') {
                volverConMensaje('error', 'La ubicación debe ser una liga http o https, por ejemplo la de Google Maps.', $vista);
            }
            if ($sinId) {
                $conn->prepare('INSERT INTO eventos (nombre, fecha_inicio, fecha_fin, registro_inicio, registro_fin, ubicacion) VALUES (:n, :i, :f, :ri, :rf, :u)')
                    ->execute([':n' => $nombre, ':i' => $inicio, ':f' => $fin, ':ri' => $regIni, ':rf' => $regFin, ':u' => $ubicacion === '' ? null : $ubicacion]);
                $nuevoId = (int) $conn->lastInsertId();
                aplicarExtrasEvento($conn, $post, $files, $nuevoId);
                volverConMensaje('ok', 'Evento creado.', $vista);
            }
            if ($id === null) {
                volverConMensaje('error', 'Evento no válido.', $vista);
            }
            $stmt = $conn->prepare('UPDATE eventos SET nombre = :n, fecha_inicio = :i, fecha_fin = :f, registro_inicio = :ri, registro_fin = :rf, ubicacion = :u WHERE id = :id');
            $stmt->execute([':n' => $nombre, ':i' => $inicio, ':f' => $fin, ':ri' => $regIni, ':rf' => $regFin, ':u' => $ubicacion === '' ? null : $ubicacion, ':id' => $id]);
            if (!$stmt->rowCount()) {
                $existe = $conn->prepare('SELECT 1 FROM eventos WHERE id = :id');
                $existe->execute([':id' => $id]);
                if (!$existe->fetchColumn()) {
                    volverConMensaje('error', 'El evento ya no existe.', $vista);
                }
            }
            aplicarExtrasEvento($conn, $post, $files, $id);
            volverConMensaje('ok', 'Evento guardado.', $vista);
        }

        // Agencias y áreas (tabla de una lista blanca, nunca del cliente)
        list($tabla, $singular, , , $max) = CATALOGOS[$tipo];
        if ($operacion === 'eliminar') {
            if ($id === null) {
                volverConMensaje('error', "La $singular no es válida.", $vista);
            }
            $stmt = $conn->prepare("DELETE FROM $tabla WHERE id = :id");
            $stmt->execute([':id' => $id]);
            volverConMensaje($stmt->rowCount() ? 'ok' : 'error',
                $stmt->rowCount() ? mbPrimeraMayuscula($singular) . ' eliminada. Los registros anteriores conservan su texto.' : "La $singular ya no existe.", $vista);
        }

        list($nombre, $err) = validarNombreCatalogo($post['nombre'] ?? '', $max, "la $singular");
        if ($err !== null) {
            volverConMensaje('error', $err, $vista);
        }
        if ($sinId) {
            $conn->prepare("INSERT INTO $tabla (nombre) VALUES (:n)")->execute([':n' => $nombre]);
            volverConMensaje('ok', mbPrimeraMayuscula($singular) . ' agregada.', $vista);
        }
        if ($id === null) {
            volverConMensaje('error', "La $singular no es válida.", $vista);
        }
        $stmt = $conn->prepare("UPDATE $tabla SET nombre = :n WHERE id = :id");
        $stmt->execute([':n' => $nombre, ':id' => $id]);
        if (!$stmt->rowCount()) {
            $existe = $conn->prepare("SELECT 1 FROM $tabla WHERE id = :id");
            $existe->execute([':id' => $id]);
            if (!$existe->fetchColumn()) {
                volverConMensaje('error', "La $singular ya no existe.", $vista);
            }
        }
        volverConMensaje('ok', mbPrimeraMayuscula($singular) . ' guardada.', $vista);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            volverConMensaje('error', 'Ya existe un elemento con ese nombre.', $vista);
        }
        error_log('registros.php catálogo: ' . $e->getMessage());
        volverConMensaje('error', 'No se pudo completar la operación.', $vista);
    }
}

const CATALOGO_POR_PAGINA = 10;

/** Página pedida en el query, mínimo 1. */
function paginaPedida() {
    $p = (int) ($_GET['p'] ?? 1);
    return $p < 1 ? 1 : $p;
}

/** Recorta la lista del catálogo a la página actual. */
function paginarCatalogo(array $filas) {
    $total = count($filas);
    $paginas = max(1, (int) ceil($total / CATALOGO_POR_PAGINA));
    $pagina = min(paginaPedida(), $paginas);
    $desde = ($pagina - 1) * CATALOGO_POR_PAGINA;
    return [
        'filas' => array_slice($filas, $desde, CATALOGO_POR_PAGINA),
        'pagina' => $pagina,
        'paginas' => $paginas,
        'total' => $total,
        'desde' => $total ? $desde + 1 : 0,
        'hasta' => min($desde + CATALOGO_POR_PAGINA, $total),
    ];
}

/** Enlaces anterior / siguiente, conservando la vista. */
function renderPaginacion($vista, array $pag) {
    if ($pag['total'] === 0) {
        return;
    }
    $extraQ = !empty($pag['q']) ? '&q=' . rawurlencode((string) $pag['q']) : '';
    $base = 'registros.php?vista=' . rawurlencode($vista) . $extraQ;
    ?>
    <nav class="paginacion" aria-label="Páginas">
        <?php if ($pag['pagina'] > 1): ?>
            <a class="btn btn-sec" href="<?= h($base . '&p=' . ($pag['pagina'] - 1)) ?>">Anterior</a>
        <?php else: ?>
            <span class="btn btn-sec pag-off">Anterior</span>
        <?php endif; ?>
        <span class="pag-info">Página <?= (int) $pag['pagina'] ?> de <?= (int) $pag['paginas'] ?>
            <span class="sec">(<?= (int) $pag['desde'] ?>–<?= (int) $pag['hasta'] ?> de <?= (int) $pag['total'] ?>)</span></span>
        <?php if ($pag['pagina'] < $pag['paginas']): ?>
            <a class="btn btn-sec" href="<?= h($base . '&p=' . ($pag['pagina'] + 1)) ?>">Siguiente</a>
        <?php else: ?>
            <span class="btn btn-sec pag-off">Siguiente</span>
        <?php endif; ?>
    </nav>
    <?php
}

/** Diálogo modal para editar agencias, áreas o eventos. */
function renderDialogoCatalogo($csrf, $pagina) {
    ?>
    <dialog id="dialogCatalogo" class="dialog-admin">
        <form method="post" id="formDialogCatalogo" enctype="multipart/form-data" class="dialog-form" action="registros.php">
            <header class="dialog-cabecera">
                <h2 id="dialogCatalogoTitulo">Editar</h2>
                <button type="button" class="btn-sec btn-icono dialog-cerrar" id="dialogCatalogoCerrar" aria-label="Cerrar" title="Cerrar"><?= icono('cerrar') ?></button>
            </header>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" id="dlg-id" value="">
            <input type="hidden" name="p" id="dlg-p" value="<?= (int) $pagina ?>">
            <div class="dlg-cuerpo">
                <div class="dlg-simple">
                    <label class="etiqueta sec" for="dlg-nombre">Nombre</label>
                    <input type="text" name="nombre" id="dlg-nombre" maxlength="150">
                </div>
                <div class="dlg-evento" hidden>
                    <div class="dlg-grid dlg-grid-evento">
                        <div class="dlg-ancho">
                            <label class="etiqueta sec" for="dlg-ev-nombre">Nombre</label>
                            <input type="text" id="dlg-ev-nombre" maxlength="<?= EVENTO_NOMBRE_MAX ?>">
                        </div>
                        <div class="dlg-par">
                            <div>
                                <label class="etiqueta sec" for="dlg-ev-fecha-inicio">Inicio del evento</label>
                                <input type="datetime-local" name="fecha_inicio" id="dlg-ev-fecha-inicio" required>
                            </div>
                            <div>
                                <label class="etiqueta sec" for="dlg-ev-fecha-fin">Fin del evento</label>
                                <input type="datetime-local" name="fecha_fin" id="dlg-ev-fecha-fin" required>
                            </div>
                        </div>
                        <div class="dlg-par">
                            <div>
                                <label class="etiqueta sec" for="dlg-ev-reg-inicio">Registro desde</label>
                                <input type="datetime-local" name="registro_inicio" id="dlg-ev-reg-inicio" required>
                            </div>
                            <div>
                                <label class="etiqueta sec" for="dlg-ev-reg-fin">Registro hasta</label>
                                <input type="datetime-local" name="registro_fin" id="dlg-ev-reg-fin" required>
                            </div>
                        </div>
                        <div class="dlg-ancho">
                            <label class="etiqueta sec" for="dlg-ev-ubicacion">Ubicación (Google Maps)</label>
                            <input type="url" name="ubicacion" id="dlg-ev-ubicacion" maxlength="500" placeholder="https://maps.app.goo.gl/…">
                        </div>
                        <div class="dlg-ancho">
                            <label class="etiqueta sec" for="dlg-ev-cartel">Cartel nuevo (opcional)</label>
                            <input type="file" name="cartel" id="dlg-ev-cartel" accept="image/jpeg,image/png,image/webp">
                            <img id="dlg-cartel-prev" class="dlg-cartel-prev" alt="" hidden>
                        </div>
                        <div class="dlg-ancho">
                            <label class="etiqueta sec" for="dlg-ev-pase-pie">Imagen inferior del pase QR (opcional)</label>
                            <input type="file" name="pase_pie" id="dlg-ev-pase-pie" accept="image/jpeg,image/png,image/webp">
                            <p class="dlg-ayuda sec">Aparece al pie del PNG al descargar el QR (mapa, indicaciones, etc.).</p>
                            <img id="dlg-pase-pie-prev" class="dlg-cartel-prev" alt="" hidden>
                        </div>
                        <div class="dlg-ancho">
                            <label class="cat-check">
                                <input type="checkbox" name="es_principal" value="1" id="dlg-ev-principal">
                                Evento principal (cartel por defecto en el registro)
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="dialog-pie">
                <button type="submit" class="btn-primario" name="accion" id="dlg-submit" value="guardar_agencia">Guardar</button>
                <button type="button" class="btn btn-sec" id="dialogCatalogoCancelar">Cancelar</button>
            </footer>
        </form>
    </dialog>
    <?php
}

/** Barra superior de catálogos (como filtros en Registros): buscar + icono agregar. */
function renderBarraCatalogo($vista, array $pag, $tipo, $singular, $accionGuardar, $tituloNuevo, $maxNombre = 150) {
    $q = (string) ($pag['q'] ?? '');
    $params = ['vista' => $vista];
    if ($q !== '') {
        $params['q'] = $q;
    }
    $accionVista = 'registros.php?' . http_build_query($params);
    if ((int) $pag['pagina'] > 1) {
        $accionVista .= '&p=' . (int) $pag['pagina'];
    }
    ?>
    <form class="filtros" method="get" action="registros.php" aria-label="Filtros de <?= h($vista) ?>">
        <input type="hidden" name="vista" value="<?= h($vista) ?>">
        <div class="filtro filtro-buscar">
            <label class="etiqueta sec" for="flt-cat-q">Buscar</label>
            <input type="search" id="flt-cat-q" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="Nombre…" autocomplete="off">
        </div>
        <div class="filtro-botones">
            <button type="submit" class="btn-primario">Filtrar</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-sec" href="registros.php?vista=<?= h(rawurlencode($vista)) ?>">Limpiar</a>
            <?php endif; ?>
            <button type="button" class="btn-primario btn-icono js-nuevo-catalogo" title="<?= h($tituloNuevo) ?>" aria-label="<?= h($tituloNuevo) ?>"
                    data-tipo="<?= h($tipo) ?>"
                    data-accion-valor="<?= h($accionGuardar) ?>"
                    data-action="<?= h($accionVista) ?>"
                    data-p="<?= (int) $pag['pagina'] ?>"
                    data-max="<?= (int) $maxNombre ?>"
                    data-titulo-nuevo="<?= h($tituloNuevo) ?>"><?= icono('agregar') ?></button>
        </div>
    </form>
    <?php
}

/** Tabla de agencias o áreas: filtros arriba, tabla abajo, alta/edición en diálogo. */
function renderCatalogoSimple($tipo, array $filas, $csrf, array $pag) {
    list(, $singular, $plural, $vista, $max) = CATALOGOS[$tipo];
    $accionVista = 'registros.php?vista=' . rawurlencode($vista);
    if (($pag['q'] ?? '') !== '') {
        $accionVista .= '&q=' . rawurlencode($pag['q']);
    }
    $accionVista .= '&p=' . (int) $pag['pagina'];
    renderBarraCatalogo($vista, $pag, $tipo, $singular, 'guardar_' . $tipo, 'Nueva ' . $singular, (int) $max);
    ?>
    <div class="tarjeta tabla-wrap">
        <table class="tabla-listado">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Registros</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pag['total']): ?>
                <tr>
                    <td colspan="3" class="vacio-celda">
                        <?php if (($pag['q'] ?? '') !== ''): ?>
                            Ninguna <?= h($singular) ?> coincide con la búsqueda.
                        <?php else: ?>
                            Aún no hay <?= h($plural) ?>. Usa el botón + para agregar.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($filas as $f): $i = (int) $f['id']; ?>
                <tr>
                    <td class="cat-nombre-celda texto" data-label="Nombre"><?= h($f['nombre']) ?></td>
                    <td class="cat-usos" data-label="Registros"><?= (int) $f['usos'] ?></td>
                    <td class="cat-acciones acciones-celda" data-label="Acciones">
                        <div class="fila-acciones">
                        <button type="button" class="btn-fila btn-icono js-editar-catalogo" title="Editar" aria-label="Editar"
                                data-tipo="<?= h($tipo) ?>"
                                data-accion-valor="guardar_<?= h($tipo) ?>"
                                data-action="<?= h($accionVista) ?>"
                                data-id="<?= $i ?>"
                                data-p="<?= (int) $pag['pagina'] ?>"
                                data-max="<?= (int) $max ?>"
                                data-nombre="<?= h($f['nombre']) ?>"><?= icono('editar') ?></button>
                        <form method="post" action="<?= h($accionVista) ?>" class="cat-eliminar-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= $i ?>">
                            <input type="hidden" name="p" value="<?= (int) $pag['pagina'] ?>">
                            <button type="submit" class="btn-sec btn-peligro btn-icono" name="accion" value="eliminar_<?= h($tipo) ?>" formnovalidate
                                    aria-label="Eliminar" title="Eliminar"
                                    onclick="return confirm(<?= jsConfirm('¿Eliminar la ' . $singular . ' «' . $f['nombre'] . '»? Los registros anteriores conservan su texto.') ?>);"><?= icono('eliminar') ?></button>
                        </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pag['total'] > 0) {
            renderPaginacion($vista, $pag);
        } ?>
    </div>
    <?php
}

/** Tabla de eventos: filtros arriba, tabla abajo, alta/edición en diálogo. */
function renderEventos(array $filas, $csrf, $ahora, array $pag) {
    $etiquetas = ['vigente' => 'Vigente', 'proximo' => 'Próximo', 'finalizado' => 'Finalizado'];
    $accionVista = 'registros.php?vista=eventos';
    if (($pag['q'] ?? '') !== '') {
        $accionVista .= '&q=' . rawurlencode($pag['q']);
    }
    $accionVista .= '&p=' . (int) $pag['pagina'];
    renderBarraCatalogo('eventos', $pag, 'evento', 'evento', 'guardar_evento', 'Nuevo evento', EVENTO_NOMBRE_MAX);
    ?>
    <div class="tarjeta tabla-wrap">
        <table class="tabla-listado cat-eventos">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Fechas del evento</th>
                    <th>Periodo de registro</th>
                    <th>Ubicación</th>
                    <th>Cartel</th>
                    <th>Principal</th>
                    <th>Estado</th>
                    <th>Registros</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pag['total']): ?>
                <tr>
                    <td colspan="9" class="vacio-celda">
                        <?php if (($pag['q'] ?? '') !== ''): ?>
                            Ningún evento coincide con la búsqueda.
                        <?php else: ?>
                            Aún no hay eventos. Usa el botón + para crear uno y abrir el registro público.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($filas as $f):
                $estado = estadoEvento($f, $ahora);
                $i = (int) $f['id'];
                $registroAbierto = ($f['registro_inicio'] ?? '') <= $ahora && ($f['registro_fin'] ?? '') >= $ahora;
                $cartelPub = cartelUrlEvento($f);
                $pasePiePub = pasePieUrlEvento($f);
                $payload = [
                    'nombre' => $f['nombre'],
                    'fecha_inicio' => fechaDbALocal($f['fecha_inicio']),
                    'fecha_fin' => fechaDbALocal($f['fecha_fin']),
                    'registro_inicio' => fechaDbALocal($f['registro_inicio'] ?? ''),
                    'registro_fin' => fechaDbALocal($f['registro_fin'] ?? ''),
                    'ubicacion' => $f['ubicacion'] ?? '',
                    'es_principal' => !empty($f['es_principal']),
                    'cartel' => $cartelPub,
                    'pase_pie' => $pasePiePub,
                ];
                $ubicacion = trim((string) ($f['ubicacion'] ?? ''));
            ?>
                <tr>
                    <td class="cat-nombre-celda texto" data-label="Nombre"><?= h($f['nombre']) ?></td>
                    <td class="cat-fechas" data-label="Fechas del evento"><?= h(fechaLegible($f['fecha_inicio'])) ?> — <?= h(fechaLegible($f['fecha_fin'])) ?></td>
                    <td class="cat-fechas" data-label="Periodo de registro"><?= h(fechaLegible($f['registro_inicio'] ?? '')) ?> — <?= h(fechaLegible($f['registro_fin'] ?? '')) ?></td>
                    <td class="cat-ubicacion-celda texto" data-label="Ubicación"><?= $ubicacion !== '' ? '<a href="' . h($ubicacion) . '" target="_blank" rel="noopener">' . h(mb_strlen($ubicacion) > 48 ? mb_substr($ubicacion, 0, 45, 'UTF-8') . '…' : $ubicacion) . '</a>' : '<span class="sec">—</span>' ?></td>
                    <td class="cat-cartel" data-label="Cartel"><?php if ($cartelPub !== ''): ?><img src="<?= h($cartelPub) ?>" alt="" class="cat-cartel-mini" width="52" height="66"><?php else: ?><span class="sec">Sin cartel</span><?php endif; ?></td>
                    <td data-label="Principal"><?= !empty($f['es_principal']) ? '<span class="pastilla vigente">Sí</span>' : '<span class="sec">No</span>' ?></td>
                    <td class="estado" data-label="Estado">
                        <div class="estado-valor">
                        <span class="pastilla <?= h($estado) ?>"><?= h($etiquetas[$estado]) ?></span>
                        <span class="pastilla <?= $registroAbierto ? 'vigente' : 'finalizado' ?>"><?= $registroAbierto ? 'Registro abierto' : 'Registro cerrado' ?></span>
                        </div>
                    </td>
                    <td class="cat-usos" data-label="Registros"><?= (int) $f['usos'] ?></td>
                    <td class="cat-acciones acciones-celda" data-label="Acciones">
                        <div class="fila-acciones">
                        <button type="button" class="btn-fila btn-icono js-editar-catalogo" title="Editar" aria-label="Editar"
                                data-tipo="evento"
                                data-accion-valor="guardar_evento"
                                data-action="<?= h($accionVista) ?>"
                                data-id="<?= $i ?>"
                                data-p="<?= (int) $pag['pagina'] ?>"
                                data-evento="<?= h(json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>"><?= icono('editar') ?></button>
                        <form method="post" action="<?= h($accionVista) ?>" class="cat-eliminar-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= $i ?>">
                            <input type="hidden" name="p" value="<?= (int) $pag['pagina'] ?>">
                            <button type="submit" class="btn-sec btn-peligro btn-icono" name="accion" value="eliminar_evento" formnovalidate
                                    aria-label="Eliminar" title="Eliminar"
                                    onclick="return confirm(<?= jsConfirm('¿Eliminar el evento «' . $f['nombre'] . '»? Los registros anteriores se conservan, pero quedan sin evento.') ?>);"><?= icono('eliminar') ?></button>
                        </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pag['total'] > 0) {
            renderPaginacion('eventos', $pag);
        } ?>
    </div>
    <?php
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
function volverConMensaje($tipo, $texto, $vista = null) {
    $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
    $qs = [];
    if ($vista) {
        $qs['vista'] = $vista;
    }
    $p = (int) ($_POST['p'] ?? 0);
    if ($vista && $p > 1) {
        $qs['p'] = (string) $p;
    }
    if (!$vista) {
        // Registro (tabla): conserva los filtros que vengan en el POST.
        $qs += filtrosActivos(filtrosDePost());
    }
    header('Location: registros.php' . ($qs ? '?' . http_build_query($qs) : ''));
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
    } elseif ($accion === 'salir' || $accion === 'guardar' || $accion === 'eliminar'
        || preg_match('/^(guardar|eliminar)_(agencia|area|evento)$/', (string) $accion)) {
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

        if (preg_match('/^(guardar|eliminar)_(agencia|area|evento)$/', $accion)) {
            procesarCatalogo($conn, $accion, $_POST, $_FILES);
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
            $duplicado = (int) ($e->errorInfo[1] ?? 0) === 1062
                && str_contains($e->getMessage(), 'uniq_empleado_evento');
            volverConMensaje('error', $duplicado
                ? 'Ese número de empleado ya está registrado en este evento.'
                : 'No se pudo completar la operación.');
        }
    }
}

// Descarga: solo con sesión, sin filas ni login para quien no la tenga.
if (isset($_GET['descargar'])) {
    if (!$autenticado) {
        responderTexto(401, 'No autorizado');
    }
    list($filas, $error) = obtenerRegistros(filtrosRegistros($_GET));
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

// Vista: «tabla» (por defecto), «escanear», «agencias», «areas» o «eventos». Editar siempre es de la tabla.
$vistaPedida = (string) ($_GET['vista'] ?? '');
$vista = (in_array($vistaPedida, ['escanear', 'agencias', 'areas', 'eventos'], true) && !isset($_GET['editar'])) ? $vistaPedida : 'tabla';
const TITULOS_VISTA = ['tabla' => 'Registros', 'escanear' => 'Escanear', 'agencias' => 'Agencias', 'areas' => 'Áreas', 'eventos' => 'Eventos'];

$filas = [];
$filtros = filtrosRegistros([]);
$opcionesEventos = [];
$opcionesAgencias = [];
$errorDatos = null;
$pagCatalogo = null;
$ahoraAdmin = date('Y-m-d H:i:s');

// Catálogos (agencias, áreas, eventos): lista con cuántos registros usan cada elemento.
if ($autenticado && in_array($vista, ['agencias', 'areas', 'eventos'], true)) {
    $conn = getConnection();
    if (!$conn) {
        $errorDatos = 'No se pudo conectar a la base de datos.';
    } else {
        try {
            createTable();
            if ($vista === 'eventos') {
                $filas = $conn->query('SELECT e.id, e.nombre, e.fecha_inicio, e.fecha_fin, e.registro_inicio, e.registro_fin, e.ubicacion, e.cartel, e.es_principal,
                        e.pase_pie,
                        (LENGTH(e.cartel_datos) > 0) AS tiene_cartel_blob,
                        (LENGTH(e.pase_pie_datos) > 0) AS tiene_pase_pie_blob,
                        (SELECT COUNT(*) FROM registros r WHERE r.evento_id = e.id) AS usos
                    FROM eventos e ORDER BY e.fecha_inicio DESC, e.id DESC')->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($vista === 'agencias') {
                $filas = $conn->query('SELECT a.id, a.nombre,
                        (SELECT COUNT(*) FROM registros r WHERE r.agencia = a.nombre) AS usos
                    FROM agencias a ORDER BY a.nombre')->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $filas = $conn->query('SELECT a.id, a.nombre,
                        (SELECT COUNT(*) FROM registros r WHERE r.area = a.nombre) AS usos
                    FROM areas a ORDER BY a.nombre')->fetchAll(PDO::FETCH_ASSOC);
            }
            $filtroCatalogoQ = textoFiltro($_GET['q'] ?? '', 100);
            if ($filtroCatalogoQ !== '') {
                $filas = array_values(array_filter($filas, function ($f) use ($filtroCatalogoQ) {
                    return stripos((string) ($f['nombre'] ?? ''), $filtroCatalogoQ) !== false;
                }));
            }
            $pagCatalogo = paginarCatalogo($filas);
            $pagCatalogo['q'] = $filtroCatalogoQ;
            $filas = $pagCatalogo['filas'];
        } catch (PDOException $e) {
            error_log('registros.php: ' . $e->getMessage());
            $errorDatos = 'No se pudo leer la información.';
        }
    }
}
if ($autenticado && $vista === 'tabla') {
    // Tras un POST fallido (422) los filtros vienen como f_*; en GET, con su nombre.
    $filtros = $_SERVER['REQUEST_METHOD'] === 'POST' ? filtrosDePost() : filtrosRegistros($_GET);
    list($filas, $errorDatos) = obtenerRegistros($filtros);

    // Opciones de los selects (si fallan, la tabla se muestra igual con los selects casi vacíos).
    $opcionesEventos = [];
    $opcionesAgencias = [];
    $connOpc = $errorDatos ? null : getConnection();
    if ($connOpc) {
        try {
            list($opcionesEventos, $opcionesAgencias) = opcionesFiltros($connOpc);
        } catch (PDOException $e) {
            error_log('registros.php: ' . $e->getMessage());
        }
    }

    if ($edicion === null && isset($_GET['editar'])) {
        $idEditar = idValido($_GET['editar']);
        $encontrada = null;
        if ($idEditar !== null && $connOpc) {
            // Por id, no del barrido filtrado: se puede editar aunque el filtro excluya la fila.
            try {
                $encontrada = obtenerRegistroPorId($connOpc, $idEditar);
            } catch (PDOException $e) {
                error_log('registros.php: ' . $e->getMessage());
                $errorDatos = 'No se pudo leer el registro.';
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
} elseif ($autenticado && !empty($_SESSION['flash'])) {
    $mensaje = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= h(TITULOS_VISTA[$vista]) ?></title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Mobile first: base = teléfono; desde 768px escritorio (tabla, centrado, tope de ancho). */
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body {
            margin: 0;
            background: #f4f5f7;
            color: #212529;
            font-family: 'Montserrat', sans-serif;
            font-size: 16px;
        }
        button, .btn-fila, .btn { touch-action: manipulation; }
        .sec { color: #6c757d; }
        .etiqueta, button, .btn {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .login-wrap {
            min-height: 100vh;
            min-height: 100dvh;
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
        .login { width: 100%; max-width: 420px; padding: 28px 20px; }
        .login h1 { margin: 0 0 4px; font-size: 22px; font-weight: 700; }
        .login p { margin: 0 0 24px; }
        .login label { display: block; margin-bottom: 8px; }
        .login input[type=password] {
            width: 100%;
            height: 52px;
            border: 0;
            background: #eef0f3;
            padding: 0 14px;
            font: inherit;
            font-size: 16px;
            color: #212529;
            border-radius: 0;
            margin-bottom: 16px;
        }
        .login input:focus { outline: 2px solid #adb5bd; }
        .error { color: #b02a37; margin: 0 0 16px; font-size: 14px; }
        button, .btn { font-family: inherit; cursor: pointer; border-radius: 0; height: 52px; padding: 0 20px; }
        .btn-primario { width: 100%; background: #1a1a1a; color: #fff; border: 0; }
        button.btn-primario.btn-icono,
        button.btn-sec.btn-icono,
        button.btn-fila.btn-icono {
            width: 36px; min-width: 36px; height: 36px; padding: 0; flex: none;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .btn-icono .icono { width: 18px; height: 18px; display: block; }
        .btn-primario:hover { background: #2a2a2a; }
        .btn-sec {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: #212529;
            border: 1px solid #adb5bd;
            text-decoration: none;
        }
        .btn-sec:hover { background: #f4f5f7; }
        .marca {
            display: inline-block;
            width: 152px;
            height: 36px;
            background: #1a1a1a;
            -webkit-mask: url("assets/grupo-vanguardia-logo.png") center / contain no-repeat;
            mask: url("assets/grupo-vanguardia-logo.png") center / contain no-repeat;
        }
        .login .marca { display: block; margin: 0 auto 20px; }
        .panel { width: 100%; max-width: none; margin: 0; padding: 16px clamp(12px, 2vw, 40px) 40px; }
        .marca-barra { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .barra { display: flex; flex-direction: column; gap: 12px; align-items: stretch; margin-bottom: 16px; }
        .barra h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .acciones { display: flex; flex-direction: column; gap: 8px; }
        .acciones form { margin: 0; }
        .acciones .btn, .acciones button { width: 100%; }

        /* Listados admin: tarjetas apiladas en teléfono (solo .tabla-listado) */
        .tabla-wrap { background: transparent; box-shadow: none; border-radius: 0; }
        .tabla-listado,
        .tabla-listado thead,
        .tabla-listado tbody,
        .tabla-listado tr,
        .tabla-listado td { display: block; width: 100%; }
        .tabla-listado thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
        .tabla-listado tr {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            margin-bottom: 12px;
            padding: 8px 16px;
        }
        .tabla-listado td {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px solid #eef0f3;
            text-align: right;
        }
        .tabla-listado td::before {
            content: attr(data-label);
            flex: 0 0 38%;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
        }
        .tabla-listado td:last-child { border-bottom: 0; }
        .tabla-listado td.texto { overflow-wrap: anywhere; word-break: break-word; }
        .tabla-listado td.estado { align-items: center; }
        .tabla-listado td.estado .estado-valor { text-align: right; }
        .tabla-listado td.acciones-celda,
        .tabla-listado td.cat-acciones { display: block; }
        .tabla-listado td.acciones-celda::before,
        .tabla-listado td.cat-acciones::before { display: none; }
        .vacio, .aviso { padding: 48px 16px; text-align: center; color: #6c757d; background: #fff; border-radius: 12px; }

        .pastilla {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.4;
        }
        .pastilla.entro { background: #1a1a1a; color: #fff; }
        .pastilla.pendiente { background: #eef0f3; color: #212529; }
        .fecha-entrada { display: block; margin-top: 4px; font-size: 13px; }

        .fila-acciones { display: flex; gap: 8px; width: 100%; }
        .fila-acciones form { margin: 0; flex: none; }
        .fila-acciones .btn-fila { flex: 1; }
        .btn-fila {
            width: 100%;
            height: 48px;
            padding: 0 12px;
            justify-content: center;
            font-size: 14px;
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

        .mensaje { padding: 14px 16px; margin-bottom: 16px; border-radius: 8px; font-size: 15px; background: #fff; border: 1px solid #adb5bd; }
        .mensaje.ok { border-left: 4px solid #1a1a1a; }
        .mensaje.error { border-left: 4px solid #b02a37; color: #b02a37; }

        .edicion { padding: 20px 16px; margin-bottom: 16px; }
        .edicion h2 { margin: 0 0 4px; font-size: 18px; font-weight: 700; }
        .edicion .ticket { margin: 0 0 20px; overflow-wrap: anywhere; }
        .campos { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 20px; }
        .campos label { display: block; margin-bottom: 6px; }
        .campos input {
            width: 100%;
            height: 52px;
            border: 0;
            background: #eef0f3;
            padding: 0 14px;
            font: inherit;
            font-size: 16px;
            color: #212529;
            border-radius: 0;
        }
        .campos input:focus { outline: 2px solid #adb5bd; }
        .edicion ul { margin: 0 0 16px; padding-left: 20px; color: #b02a37; }
        .edicion .botones { display: flex; flex-direction: column; gap: 8px; }
        .edicion .btn-primario { width: 100%; }
        .edicion .btn-sec { width: 100%; }
        .edicion form { margin: 0; }

        /* Filtros de la tabla: una columna en celular, en fila desde 768px */
        .filtros,
        .cat-alta.listado-toolbar {
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            padding: 16px;
            margin-bottom: 16px;
        }
        .cat-alta.listado-toolbar h2 {
            margin: 0 0 4px;
            font-size: 16px;
            font-weight: 700;
            text-transform: none;
            letter-spacing: 0;
        }
        .filtro { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .filtro select, .filtro input {
            width: 100%;
            height: 52px;
            border: 0;
            background: #eef0f3;
            padding: 0 14px;
            font: inherit;
            font-size: 16px;
            color: #212529;
            border-radius: 0;
        }
        .filtro select { text-overflow: ellipsis; }
        .filtro select:focus, .filtro input:focus { outline: 2px solid #adb5bd; }
        .filtro-botones { display: flex; flex-direction: column; gap: 8px; }
        .filtro-botones .btn-primario, .filtro-botones .btn-sec { width: 100%; }

        @media (min-width: 768px) {
            body { font-size: 14px; }
            .filtros,
            .cat-alta.listado-toolbar { flex-direction: row; flex-wrap: wrap; align-items: flex-end; padding: 16px 20px; margin-bottom: 20px; }
            .listado-toolbar input[type=text],
            .listado-toolbar input[type=url],
            .listado-toolbar input[type=datetime-local] { height: 40px; padding: 0 12px; font-size: 14px; }
            .filtro { flex: 1 1 160px; }
            .filtro-buscar { flex: 2 1 240px; }
            .filtro select, .filtro input { height: 40px; padding: 0 12px; font-size: 14px; }
            .filtro-botones { flex-direction: row; flex: none; }
            .filtro-botones .btn-primario, .filtro-botones .btn-sec { width: auto; }
            .etiqueta, button, .btn { font-size: 12px; }
            .login { max-width: 380px; padding: 32px 40px; }
            .login input[type=password] { height: 40px; padding: 0 12px; }
            button, .btn { height: 40px; }
            .panel { padding: 32px 16px; }
            .marca-barra { gap: 16px; flex-wrap: nowrap; }
            .barra { flex-direction: row; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .acciones { flex-direction: row; }
            .acciones .btn, .acciones button { width: auto; }

            .tabla-wrap { background: #fff; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08); border-radius: 12px; overflow-x: auto; }
            .tabla-listado { display: table; width: 100%; min-width: 0; border-collapse: collapse; }
            .tabla-listado thead { display: table-header-group; position: static; width: auto; height: auto; overflow: visible; clip: auto; background: #f8fafc; }
            .tabla-listado tbody { display: table-row-group; }
            .tabla-listado tr { display: table-row; background: transparent; border-radius: 0; box-shadow: none; margin: 0; padding: 0; }
            .tabla-listado th {
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
            .tabla-listado td { display: table-cell; width: auto; padding: 12px 16px; border-bottom: 1px solid #eef0f3; vertical-align: middle; text-align: left; }
            .tabla-listado td::before { display: none; content: none; }
            .tabla-listado td:last-child { border-bottom: 1px solid #eef0f3; }
            .tabla-listado tr:last-child td { border-bottom: 0; }
            .tabla-listado td.fecha,
            .tabla-listado td.cat-fechas { white-space: nowrap; }
            .tabla-listado td.estado .estado-valor { text-align: left; }
            .tabla-listado td.acciones-celda,
            .tabla-listado td.cat-acciones { display: table-cell; }
            .vacio, .aviso { background: transparent; border-radius: 0; }
            .pastilla, .fecha-entrada { font-size: 12px; }
            .fecha-entrada { white-space: nowrap; }

            .fila-acciones { width: auto; flex-wrap: wrap; gap: 6px; }
            .fila-acciones form, .fila-acciones .btn-fila { flex: none; }
            .btn-fila { width: auto; height: 30px; font-size: 12px; justify-content: flex-start; }

            .mensaje { font-size: 14px; margin-bottom: 20px; }
            .edicion { padding: 24px; margin-bottom: 20px; }
            .edicion h2 { font-size: 16px; }
            .campos { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
            .campos input { height: 40px; padding: 0 12px; }
            .edicion .botones { flex-direction: row; flex-wrap: wrap; }
            .edicion .btn-primario, .edicion .btn-sec { width: auto; }
        }

        /* Pestañas: grandes y fáciles de tocar en el celular (2 columnas; la última ocupa todo el ancho si queda sola) */
        .tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
        .tab:last-child:nth-child(odd) { grid-column: 1 / -1; }
        .tab {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 56px;
            background: #fff;
            color: #212529;
            border: 1px solid #adb5bd;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            touch-action: manipulation;
        }
        .tab[aria-current="page"] { background: #1a1a1a; color: #fff; border-color: #1a1a1a; }
        .tab:not([aria-current="page"]):hover { background: #f4f5f7; }
        #vistaEscaner { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08); padding: 16px; }
        .dialog-admin {
            border: 0;
            border-radius: 16px;
            padding: 0;
            width: min(640px, calc(100vw - 32px));
            max-height: calc(100dvh - 32px);
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.2);
        }
        .dialog-admin::backdrop { background: rgba(17, 24, 39, 0.45); }
        .dialog-form { display: flex; flex-direction: column; max-height: calc(100dvh - 32px); margin: 0; }
        .dialog-cabecera {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid #eef0f3;
        }
        .dialog-cabecera h2 { margin: 0; font-size: 18px; font-weight: 700; }
        .dialog-cerrar { flex: none; }
        .dlg-cuerpo { padding: 16px 20px; overflow: auto; flex: 1; }
        .dlg-cuerpo input[type=text],
        .dlg-cuerpo input[type=url],
        .dlg-cuerpo input[type=email],
        .dlg-cuerpo input[type=datetime-local],
        .dlg-cuerpo input[type=file] {
            width: 100%;
            height: 44px;
            border: 0;
            background: #eef0f3;
            padding: 0 12px;
            font: inherit;
            font-size: 16px;
        }
        .dlg-cuerpo input[type=file] { padding: 8px 12px; height: auto; }
        .dlg-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
        .dlg-grid-evento { display: flex; flex-direction: column; gap: 12px; }
        .dlg-par { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: end; }
        @media (max-width: 520px) {
            .dlg-par { grid-template-columns: 1fr; }
        }
        .dlg-ancho { grid-column: 1 / -1; width: 100%; }
        .dlg-cartel-prev { display: block; max-width: 140px; margin-top: 8px; border-radius: 6px; object-fit: cover; }
        .dlg-ayuda { font-size: 12px; line-height: 1.4; margin: 6px 0 0; }
        .dialog-pie {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 16px 20px;
            border-top: 1px solid #eef0f3;
        }
        .dialog-pie .btn-primario,
        .dialog-pie .btn-sec,
        .dialog-pie a.btn-sec {
            flex: 1 1 calc(50% - 4px);
            width: auto;
            min-width: 0;
            justify-content: center;
            text-align: center;
        }
        .dialog-errores { color: #b02a37; margin: 0 20px 12px; padding-left: 20px; }
        .dialog-ticket { margin: 0 20px 12px; }
        /* Alta de catálogos (misma barra visual que filtros) */
        .cat-alta-form { display: grid; grid-template-columns: 1fr; gap: 10px; align-items: end; width: 100%; }
        .cat-campo { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .cat-campo label { margin: 0; }
        .listado-toolbar input[type=text],
        .listado-toolbar input[type=url],
        .listado-toolbar input[type=datetime-local],
        .listado-toolbar input[type=file] {
            width: 100%;
            height: 52px;
            border: 0;
            background: #eef0f3;
            padding: 0 14px;
            font: inherit;
            font-size: 16px;
            color: #212529;
        }
        .listado-toolbar input[type=file] { padding: 10px 14px; height: auto; }
        .tabla-listado .cat-usos { text-align: center; white-space: nowrap; }
        .tabla-listado .cat-acciones { white-space: nowrap; display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
        .tabla-listado .cat-eliminar-form { display: inline; margin: 0; }
        .tabla-listado .cat-nombre-celda { font-weight: 600; text-align: left; }
        .tabla-listado .cat-fechas { font-size: 13px; }
        .tabla-listado .cat-ubicacion-celda { text-align: left; }
        .tabla-listado .cat-ubicacion-celda a { color: #212529; word-break: break-all; }
        .tabla-listado.cat-eventos .pastilla { display: inline-block; margin: 2px 4px 2px 0; }
        .tabla-listado .cat-cartel-mini { display: inline-block; vertical-align: middle; object-fit: cover; border-radius: 4px; background: #eef0f3; }
        .paginacion { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 12px 16px; border-top: 1px solid #eef0f3; background: #fff; }
        .tabla-wrap .paginacion { border-radius: 0 0 12px 12px; }
        .vacio-celda { text-align: center; color: #6c757d; padding: 32px 16px !important; }
        .vacio-celda::before { display: none !important; }
        .filtro-botones .btn-icono { width: 40px; min-width: 40px; flex: none; }
        .pag-info { font-size: 13px; color: #212529; }
        .pag-off { opacity: 0.4; pointer-events: none; }
        @media (min-width: 768px) {
            .cat-alta-form { grid-template-columns: 1fr auto; }
            .cat-alta-evento { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .cat-alta-evento .cat-nombre,
            .cat-alta-evento .cat-ubicacion,
            .cat-alta-evento .cat-principal-check { grid-column: 1 / -1; }
            .tabla-listado.cat-eventos { table-layout: auto; }
            .tabla-listado.cat-eventos th { white-space: normal; line-height: 1.2; }
            .dlg-grid:not(.dlg-grid-evento) { grid-template-columns: 1fr 1fr; }
            .dialog-admin { width: min(760px, calc(100vw - 48px)); }
            .cat-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; white-space: nowrap; }
        }
        .cat-bloque h2 { margin: 0 0 16px; font-size: 18px; font-weight: 700; }
        .cat-form { margin: 0; display: flex; flex-direction: column; gap: 6px; }
        .cat-form label { margin-top: 10px; }
        .cat-form label:first-of-type { margin-top: 0; }
        .cat-form input[type=text], .cat-form input[type=datetime-local] {
            width: 100%;
            height: 52px;
            border: 0;
            background: #eef0f3;
            padding: 0 14px;
            font: inherit;
            font-size: 16px;
            color: #212529;
            border-radius: 0;
        }
        .cat-form input:focus { outline: 2px solid #adb5bd; }
        .cat-form > .btn-primario { margin-top: 14px; }
        .cat-uso, .cat-estado { margin: 4px 0 0; font-size: 13px; }
        .cat-estado { display: flex; align-items: center; gap: 10px; margin: 0 0 10px; }
        .cat-botones { display: flex; flex-direction: column; gap: 8px; margin-top: 14px; }
        .cat-botones button { width: 100%; }
        .btn-peligro { color: #b02a37; }
        .pastilla.vigente { background: #1a1a1a; color: #fff; }
        .pastilla.proximo { background: #fff; color: #212529; border: 1px solid #adb5bd; }
        .pastilla.finalizado { background: #eef0f3; color: #6c757d; }
        @media (min-width: 768px) {
            .cat-form input[type=text], .cat-form input[type=datetime-local] { height: 40px; padding: 0 12px; font-size: 14px; }
            .cat-bloque { padding: 24px; }
            .cat-bloque h2 { font-size: 16px; }
            .cat-botones { flex-direction: row; }
            .cat-botones button { width: auto; min-width: 140px; }
            .cat-form > .btn-primario { align-self: flex-start; min-width: 140px; width: auto; }
        }
        @media (min-width: 768px) {
            .tab { height: 48px; font-size: 13px; padding: 0 20px; }
            .tabs { display: flex; flex-wrap: wrap; }
            .tab:last-child:nth-child(odd) { grid-column: auto; }
            #vistaEscaner { padding: 28px; }
        }
    </style>
    <?php if ($autenticado && $vista === 'escanear'): ?>
    <link rel="stylesheet" href="escaner.css">
    <?php endif; ?>
</head>
<body>
<?php if (!$autenticado): ?>
    <div class="login-wrap">
        <form class="tarjeta login" method="post" action="registros.php" autocomplete="off">
            <div class="marca" role="img" aria-label="Grupo Vanguardia"></div>
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
            <div class="marca-barra">
                <div class="marca" role="img" aria-label="Grupo Vanguardia"></div>
            <?php if ($vista === 'tabla'): ?>
                <h1>Registros <span class="sec" style="font-weight:400;font-size:14px;">(<?= count($filas) ?>)</span></h1>
            <?php elseif (in_array($vista, ['agencias', 'areas', 'eventos'], true)): ?>
                <h1><?= h(TITULOS_VISTA[$vista]) ?> <span class="sec" style="font-weight:400;font-size:14px;">(<?= (int) ($pagCatalogo['total'] ?? 0) ?>)</span></h1>
            <?php else: ?>
                <h1><?= h(TITULOS_VISTA[$vista]) ?></h1>
            <?php endif; ?>
            </div>
            <div class="acciones">
            <?php if ($vista === 'tabla'): ?>
                <a class="btn btn-sec" href="registros.php?<?= h(http_build_query(['descargar' => '1'] + filtrosActivos($filtros))) ?>">Descargar Excel</a>
            <?php endif; ?>
                <form method="post" action="registros.php">
                    <input type="hidden" name="accion" value="salir">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <button type="submit" class="btn-sec">Salir</button>
                </form>
            </div>
        </div>

        <nav class="tabs" aria-label="Vista">
            <a class="tab" href="registros.php"<?= $vista === 'tabla' ? ' aria-current="page"' : '' ?>>Tabla</a>
            <a class="tab" href="registros.php?vista=escanear"<?= $vista === 'escanear' ? ' aria-current="page"' : '' ?>>Escanear</a>
            <a class="tab" href="registros.php?vista=agencias"<?= $vista === 'agencias' ? ' aria-current="page"' : '' ?>>Agencias</a>
            <a class="tab" href="registros.php?vista=areas"<?= $vista === 'areas' ? ' aria-current="page"' : '' ?>>Áreas</a>
            <a class="tab" href="registros.php?vista=eventos"<?= $vista === 'eventos' ? ' aria-current="page"' : '' ?>>Eventos</a>
        </nav>

        <?php if ($mensaje): ?>
            <div class="mensaje <?= $mensaje['tipo'] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($mensaje['texto']) ?></div>
        <?php endif; ?>

        <?php if ($vista === 'escanear'): ?>
        <div id="vistaEscaner">
            <div class="stats-panel">
                <div class="stat-card total">
                    <div class="stat-icon">📊</div>
                    <div class="stat-info">
                        <div class="stat-value" id="statTotal">0</div>
                        <div class="stat-label">Registrados</div>
                    </div>
                </div>
                <div class="stat-card confirmed">
                    <div class="stat-icon">✅</div>
                    <div class="stat-info">
                        <div class="stat-value" id="statConfirmados">0</div>
                        <div class="stat-label">Confirmados</div>
                    </div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-info">
                        <div class="stat-value" id="statPendientes">0</div>
                        <div class="stat-label">Por Confirmar</div>
                    </div>
                </div>
            </div>

            <div id="scanSection" class="scan-section">
                <div class="header">
                    <h1>Confirmar Entrada</h1>
                    <p>Apunta la cámara al código QR o usa el lector</p>
                </div>

                <div class="camera-section">
                    <div id="cameraBox" class="camera-box">
                        <div id="qrReader"></div>
                        <div id="cameraPlaceholder" class="camera-placeholder">📷 Cámara apagada</div>
                    </div>
                    <div id="cameraStatus" class="camera-status"></div>
                    <button type="button" id="btnCamera" class="btn-camera">Encender cámara</button>
                </div>

                <div class="input-container">
                    <input type="text" id="ticketInput" placeholder="Esperando escaneo..." autocomplete="off">
                    <div class="scan-icon">🎫</div>
                </div>

                <div id="scanResult" class="scan-result info">
                    ✅ Listo para escanear. Escanea el QR con la cámara o el lector.
                </div>

                <div class="recent-scans">
                    <h3>📋 Últimos escaneos</h3>
                    <div id="recentList" class="recent-list">
                        <p class="no-recent">Aún no hay escaneos en esta sesión</p>
                    </div>
                </div>
            </div>

            <div id="confirmSection" class="confirm-section" style="display: none;">
                <div id="confirmContent"></div>
            </div>
        </div>
        <?php elseif ($vista === 'agencias' || $vista === 'areas' || $vista === 'eventos'): ?>
            <?php if ($errorDatos): ?>
                <div class="tarjeta aviso" role="alert"><?= h($errorDatos) ?></div>
            <?php elseif ($vista === 'eventos'): ?>
                <?php renderEventos($filas, $csrf, $ahoraAdmin, $pagCatalogo); ?>
            <?php else: ?>
                <?php renderCatalogoSimple($vista === 'agencias' ? 'agencia' : 'area', $filas, $csrf, $pagCatalogo); ?>
            <?php endif; ?>
            <?php renderDialogoCatalogo($csrf, $pagCatalogo['pagina'] ?? 1); ?>
        <?php else: ?>
        <?php
            $qsFiltros = http_build_query(filtrosActivos($filtros));
            $filtrosActivosHay = hayFiltros($filtros);
            $eventoEnLista = $filtros['evento'] === '' || $filtros['evento'] === 'sin';
            foreach ($opcionesEventos as $oe) {
                if ((string) $oe['id'] === $filtros['evento']) { $eventoEnLista = true; }
            }
            $agenciaEnLista = $filtros['agencia'] === '' || in_array($filtros['agencia'], array_map('strval', $opcionesAgencias), true);
        ?>

        <dialog id="dialogRegistro" class="dialog-admin"<?= $edicion ? ' data-autoopen="1"' : '' ?>>
            <form method="post" action="registros.php" class="dialog-form">
                <header class="dialog-cabecera">
                    <h2>Editar registro</h2>
                    <button type="button" class="btn-sec btn-icono dialog-cerrar" id="dialogRegistroCerrar" aria-label="Cerrar" title="Cerrar"><?= icono('cerrar') ?></button>
                </header>
                <?php if ($edicion): ?>
                <p class="ticket sec dialog-ticket">ID Ticket: <strong><?= h($edicion['id_ticket']) ?></strong></p>
                <?php if ($erroresEdicion): ?>
                    <ul class="dialog-errores" role="alert">
                    <?php foreach ($erroresEdicion as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $edicion['id'] ?>">
                <?= camposOcultosFiltros($filtros) ?>
                <div class="dlg-cuerpo campos dlg-grid">
                <?php foreach (CAMPOS_EDITABLES as $campo => $def): ?>
                    <div>
                        <label class="etiqueta sec" for="f-<?= h($campo) ?>"><?= h($def[0]) ?></label>
                        <input type="<?= $campo === 'correo' ? 'email' : 'text' ?>" id="f-<?= h($campo) ?>" name="<?= h($campo) ?>"
                               value="<?= h($edicion['valores'][$campo] ?? '') ?>" maxlength="<?= (int) $def[1] ?>" required>
                    </div>
                <?php endforeach; ?>
                </div>
                <footer class="dialog-pie">
                    <button type="submit" class="btn-primario">Guardar</button>
                    <a class="btn btn-sec" id="dialogRegistroCancelar" href="registros.php<?= $qsFiltros !== '' ? '?' . h($qsFiltros) : '' ?>">Cancelar</a>
                </footer>
                <?php else: ?>
                <p class="sec">Selecciona un registro para editar.</p>
                <?php endif; ?>
            </form>
        </dialog>

        <form class="filtros" method="get" action="registros.php" aria-label="Filtros de registros">
            <div class="filtro">
                <label class="etiqueta sec" for="flt-evento">Evento</label>
                <select id="flt-evento" name="evento">
                    <option value="">Todos</option>
                    <option value="sin"<?= $filtros['evento'] === 'sin' ? ' selected' : '' ?>>Sin evento</option>
                <?php foreach ($opcionesEventos as $oe): ?>
                    <option value="<?= (int) $oe['id'] ?>"<?= (string) $oe['id'] === $filtros['evento'] ? ' selected' : '' ?>><?= h($oe['nombre']) ?></option>
                <?php endforeach; ?>
                <?php if (!$eventoEnLista): ?>
                    <option value="<?= h($filtros['evento']) ?>" selected>Evento <?= h($filtros['evento']) ?></option>
                <?php endif; ?>
                </select>
            </div>
            <div class="filtro">
                <label class="etiqueta sec" for="flt-estado">Estado</label>
                <select id="flt-estado" name="estado">
                    <option value="">Todos</option>
                    <option value="entro"<?= $filtros['estado'] === 'entro' ? ' selected' : '' ?>>Entró</option>
                    <option value="pendiente"<?= $filtros['estado'] === 'pendiente' ? ' selected' : '' ?>>Pendiente</option>
                </select>
            </div>
            <div class="filtro">
                <label class="etiqueta sec" for="flt-agencia">Agencia</label>
                <select id="flt-agencia" name="agencia">
                    <option value="">Todas</option>
                <?php foreach ($opcionesAgencias as $oa): $oa = (string) $oa; ?>
                    <option value="<?= h($oa) ?>"<?= $oa === $filtros['agencia'] ? ' selected' : '' ?>><?= h($oa) ?></option>
                <?php endforeach; ?>
                <?php if (!$agenciaEnLista): ?>
                    <option value="<?= h($filtros['agencia']) ?>" selected><?= h($filtros['agencia']) ?></option>
                <?php endif; ?>
                </select>
            </div>
            <div class="filtro filtro-buscar">
                <label class="etiqueta sec" for="flt-q">Buscar</label>
                <input type="search" id="flt-q" name="q" value="<?= h($filtros['q']) ?>" maxlength="100" placeholder="Nombre, correo, empleado o ticket" autocomplete="off">
            </div>
            <div class="filtro-botones">
                <button type="submit" class="btn-primario">Filtrar</button>
            <?php if ($filtrosActivosHay): ?>
                <a class="btn btn-sec" href="registros.php">Limpiar</a>
            <?php endif; ?>
            </div>
        </form>

        <div class="tarjeta tabla-wrap">
        <?php if ($errorDatos): ?>
            <div class="aviso" role="alert"><?= h($errorDatos) ?></div>
        <?php elseif (!$filas): ?>
            <div class="vacio"><?= $filtrosActivosHay ? 'Ningún registro coincide con los filtros.' : 'Aún no hay registros.' ?></div>
        <?php else: ?>
            <table class="tabla-listado tabla-registros">
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
                        <td class="estado" data-label="Estado"><div class="estado-valor"><?= htmlEstado($fila) ?></div></td>
                    <?php foreach (array_keys(COLUMNAS_VISTA) as $columna): ?>
                        <td class="<?= $columna === 'fecha_registro' ? 'fecha' : 'texto' ?>" data-label="<?= h(COLUMNAS_VISTA[$columna]) ?>"><?= h(valorCelda($fila, $columna)) ?></td>
                    <?php endforeach; ?>
                        <td class="acciones-celda">
                            <div class="fila-acciones">
                                <a class="btn-fila btn-icono" href="registros.php?<?= h(http_build_query(['editar' => (int) $fila['id']] + filtrosActivos($filtros))) ?>" title="Editar" aria-label="Editar"><?= icono('editar') ?></a>
                                <form method="post" action="registros.php"
                                      onsubmit="return confirm(<?= h(json_encode('¿Eliminar el registro de ' . valorCelda($fila, 'nombre') . '? Esta acción no se puede deshacer.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>);">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $fila['id'] ?>">
                                    <?= camposOcultosFiltros($filtros) ?>
                                    <button type="submit" class="btn-fila eliminar btn-icono" aria-label="Eliminar" title="Eliminar"><?= icono('eliminar') ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($autenticado && ($vista === 'tabla' || in_array($vista, ['agencias', 'areas', 'eventos'], true))): ?>
    <script src="admin-dialog.js"></script>
    <?php endif; ?>
    <?php if ($vista === 'escanear'): ?>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="escaner.js"></script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
