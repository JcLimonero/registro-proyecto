<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

$text = isset($_GET['text']) && is_string($_GET['text']) ? $_GET['text'] : 'Sin texto';

if ($text === '' || strlen($text) > 200) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parámetro text inválido (1 a 200 caracteres)';
    exit;
}

$builder = new Builder(writer: new PngWriter());
$qrCode = $builder->build(data: $text, size: 300, margin: 10);

header('Content-Type: image/png');
echo $qrCode->getString();
?>
