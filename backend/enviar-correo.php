<?php
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

function enviarCorreo($data) {
    $mail = new PHPMailer(true);
    
    try {
        
        $mail->SMTPDebug = 0; 
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'eventosvgd@gmail.com';
        $mail->Password   = 'stuh dewv dtue iohz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        

        $mail->setFrom('eventosvgd@gmail.com', 'Sistema de Registro');
        $mail->addAddress($data['correo'], $data['nombre']);
        
    
        $qrData = generarQRBase64($data['idTicket']);
        $qrImage = base64_decode($qrData);
        

        $cid = 'qr_' . md5(uniqid());
        
  
        $mail->addStringEmbeddedImage(
            $qrImage,         
            $cid,             
            'qr-code.png',    
            'base64',         
            'image/png'       
        );
        
     
        $mail->isHTML(true);
        $mail->Subject = "Registro Confirmado - ID Ticket: " . $data['idTicket'];
        
  
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #000102 0%, #edf6ff 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 10px 10px; }
                .ticket-id { font-size: 24px; color: #667eea; font-weight: bold; }
                .info-row { padding: 10px 0; border-bottom: 1px solid #ddd; }
                .qr-container { text-align: center; margin: 20px 0; padding: 20px; background: white; border-radius: 10px; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🎫 ¡Registro Confirmado!</h2>
                </div>
                <div class='content'>
                    <p>Hola <strong>{$data['nombre']}</strong>,</p>
                    <p>Tu registro ha sido completado exitosamente.</p>
                    
                    <div class='ticket-id'>
                        ID Ticket: {$data['idTicket']}
                    </div>
                    
                    <div class='info-row'>
                        <strong>Fecha de Registro:</strong> {$data['fechaRegistro']}
                    </div>
                    <div class='info-row'>
                        <strong>Número de Empleado:</strong> {$data['numEmpleado']}
                    </div>
                    <div class='info-row'>
                        <strong>Agencia:</strong> {$data['agencia']}
                    </div>
                    <div class='info-row'>
                        <strong>Puesto:</strong> {$data['puesto']}
                    </div>
                    <div class='info-row'>
                        <strong>Área:</strong> {$data['area']}
                    </div>
                    
           
                    <div class='qr-container'>
                        <h3>📱 Código QR del Ticket</h3>
                        <img src='cid:{$cid}' alt='QR Code' style='max-width: 200px;' />
						    <p><small>ubicación del evento</small></p>
                        <p><small>https://maps.app.goo.gl/dLn5LBQcoTNA1g9j6</small></p>
                    </div>
                    
                    <p style='margin-top: 20px;'>
                        <strong>Importante:</strong> Guarda tu QR y muestraloen el ingreso al evento.
                    </p>
                </div>
                <div class='footer'>
                    <p>Este es un correo automático, por favor no responder.</p>
<p>&copy; " . date('Y') . " Sistema de Registro Vanguardia</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $message;
        $mail->AltBody = "Registro Confirmado\nID Ticket: {$data['idTicket']}\nNombre: {$data['nombre']}\nFecha: {$data['fechaRegistro']}";
        
        $mail->send();
        return ['success' => true, 'message' => 'Correo enviado exitosamente'];
        
    } catch (Exception $e) {
        error_log("Error en correo: " . $mail->ErrorInfo);
        return ['success' => false, 'message' => "Error: {$mail->ErrorInfo}"];
    }
}

function generarQRBase64($texto) {
    $url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($texto);
    $qrData = @file_get_contents($url);
    
    if ($qrData === false) {
        $url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($texto);
        $qrData = @file_get_contents($url);
    }
    
    if ($qrData === false) {
        $qrData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAMgAAADICAYAAACtWK6eAAAAAXNSR0IArs4c6QAAAARzQklUCAgICHwIZAAAAU5JREFUeJztwTEBAAAAwqD1T20JT6AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA4GcC/AAA//8DAI4G+wIAAAAASUVORK5CYII=');
    }
    
    return base64_encode($qrData);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $result = enviarCorreo($data);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>