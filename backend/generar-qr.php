<?php
require_once 'vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

header('Content-Type: image/png');

$text = $_GET['text'] ?? 'Sin texto';

$qrCode = Builder::create()
    ->writer(new PngWriter())
    ->data($text)
    ->size(300)
    ->margin(10)
    ->build();

echo $qrCode->getString();
?>