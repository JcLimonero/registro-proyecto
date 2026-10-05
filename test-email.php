<?php
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'eventosvgd@gmail.com';
    $mail->Password = 'stuh dewv dtue iohz';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    
    $mail->setFrom('tu-correo@gmail.com', 'Prueba');
    $mail->addAddress('tu-correo@gmail.com');
    $mail->Subject = 'Test de Correo';
    $mail->Body = 'Este es un correo de prueba';
    
    $mail->send();
    echo "Correo enviado exitosamente";
} catch (Exception $e) {
    echo "Error: " . $mail->ErrorInfo;
}
?>