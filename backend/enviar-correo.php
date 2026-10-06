<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

header('Content-Type: application/json');

function esc($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function generarQRPng($texto) {
    $builder = new Builder(writer: new PngWriter());
    $resultado = $builder->build(data: $texto, size: 300, margin: 10);
    return $resultado->getString();
}

function enviarCorreo($data) {
    if (SMTP_USER === '' || SMTP_PASS === '' || SMTP_FROM === '') {
        return ['success' => false, 'message' => 'El envío de correo no está configurado (faltan SMTP_USER, SMTP_PASS o SMTP_FROM en el entorno)'];
    }

    $requeridos = ['correo', 'nombre', 'idTicket', 'fechaRegistro', 'numEmpleado', 'agencia', 'puesto', 'area'];
    foreach ($requeridos as $campo) {
        if (!isset($data[$campo]) || $data[$campo] === '') {
            return ['success' => false, 'message' => "El campo $campo es requerido"];
        }
    }

    $mail = new PHPMailer(true);

    try {
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, 'Sistema de Registro');
        $mail->addAddress($data['correo'], $data['nombre']);

        $qrImage = generarQRPng($data['idTicket']);
        $cid = 'qr_' . md5(uniqid('', true));

        $mail->addStringEmbeddedImage(
            $qrImage,
            $cid,
            'qr-code.png',
            'base64',
            'image/png'
        );

        $logoCid = 'logo_vanguardia';
        $logoRuta = __DIR__ . '/../assets/grupo-vanguardia-logo.png';
        $logoHtml = '';
        if (is_readable($logoRuta)) {
            $mail->addEmbeddedImage($logoRuta, $logoCid, 'grupo-vanguardia-logo.png', 'base64', 'image/png');
            $logoHtml = '<img src="cid:' . $logoCid . '" alt="Grupo Vanguardia" width="220" style="display:block; margin:0 auto; max-width:220px; height:auto; border:0;">';
        }

        $mail->isHTML(true);
        $mail->Subject = "Registro Confirmado - ID Ticket: " . $data['idTicket'];

        $nombre   = esc($data['nombre']);
        $ticket   = esc($data['idTicket']);
        $fecha    = esc($data['fechaRegistro']);
        $evento   = esc($data['eventoNombre'] ?? '');
        $inicio   = esc($data['eventoInicio'] ?? '');
        $fin      = esc($data['eventoFin'] ?? '');
        $ubicacion = ligaUbicacion($data['eventoUbicacion'] ?? '');
        $ubicacionHtml = $ubicacion === '' ? '' : '<p><a href="' . esc($ubicacion) . '">Ubicación del evento</a></p>';
        $empleado = esc($data['numEmpleado']);
        $agencia  = esc($data['agencia']);
        $puesto   = esc($data['puesto']);
        $area     = esc($data['area']);
        $anio     = date('Y');

        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #ffffff; color: #212529; padding: 28px 20px 8px; text-align: center; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 10px 10px; }
                .ticket-id { font-size: 24px; color: #667eea; font-weight: bold; }
                .info-row { padding: 10px 0; border-bottom: 1px solid #ddd; }
                .qr-container { text-align: center; margin: 20px 0; padding: 20px; background: white; border-radius: 10px; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header' style='background:#ffffff; color:#212529; padding:28px 20px 8px; text-align:center;'>
                    {$logoHtml}
                    <h2 style='margin:16px 0 0; font-size:22px; font-weight:700; color:#212529;'>Registro confirmado</h2>
                </div>
                <div class='content'>
                    <p>Hola <strong>{$nombre}</strong>,</p>
                    <p>Tu registro ha sido completado exitosamente.</p>

                    <div class='ticket-id'>
                        ID Ticket: {$ticket}
                    </div>

                    <div class='info-row'>
                        <strong>Evento:</strong> {$evento}
                    </div>
                    <div class='info-row'>
                        <strong>Inicio del evento:</strong> {$inicio}
                    </div>
                    <div class='info-row'>
                        <strong>Fin del evento:</strong> {$fin}
                    </div>
                    <div class='info-row'>
                        <strong>Fecha de Registro:</strong> {$fecha}
                    </div>
                    <div class='info-row'>
                        <strong>Número de Empleado:</strong> {$empleado}
                    </div>
                    <div class='info-row'>
                        <strong>Agencia:</strong> {$agencia}
                    </div>
                    <div class='info-row'>
                        <strong>Puesto:</strong> {$puesto}
                    </div>
                    <div class='info-row'>
                        <strong>Área:</strong> {$area}
                    </div>

                    <div class='qr-container'>
                        <h3>📱 Código QR del Ticket</h3>
                        <img src='cid:{$cid}' alt='QR Code' style='max-width: 200px;' />
                        {$ubicacionHtml}
                    </div>

                    <p style='margin-top: 20px;'>
                        <strong>Importante:</strong> Guarda tu QR y muéstralo en el ingreso al evento.
                    </p>
                </div>
                <div class='footer'>
                    <p>Este es un correo automático, por favor no responder.</p>
                    <p>&copy; {$anio} Sistema de Registro Vanguardia</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->Body = $message;
        $eventoTxt = (string) ($data['eventoNombre'] ?? '');
        $inicioTxt = (string) ($data['eventoInicio'] ?? '');
        $finTxt = (string) ($data['eventoFin'] ?? '');
        $ubicacionTxt = ligaUbicacion($data['eventoUbicacion'] ?? '');
        $mail->AltBody = "Registro Confirmado\nEvento: {$eventoTxt}\nInicio: {$inicioTxt}\nFin: {$finTxt}\nUbicación: {$ubicacionTxt}\nID Ticket: {$data['idTicket']}\nNombre: {$data['nombre']}\nFecha: {$data['fechaRegistro']}";

        $mail->send();
        return ['success' => true, 'message' => 'Correo enviado exitosamente'];

    } catch (Exception $e) {
        error_log("Error en correo: " . $mail->ErrorInfo);
        return ['success' => false, 'message' => 'No se pudo enviar el correo'];
    }
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Solicitud inválida');
    }
    $result = enviarCorreo($data);
    echo json_encode($result);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
