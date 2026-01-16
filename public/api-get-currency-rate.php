<?php
// public/api-get-currency-rate.php
require_once '../app/config/database.php';
require_once '../app/functions/currency.php'; // Sizin fonksiyon dosyanız

header('Content-Type: application/json');

$code = isset($_GET['code']) ? strtoupper($_GET['code']) : 'TRY';

if ($code == 'TRY') {
    echo json_encode(['status' => 'success', 'rate' => 1.0000]);
    exit;
}

// Sizin fonksiyonunuzu kullanarak veritabanındaki güncel kuru çekiyoruz
$rate = getExchangeRate($code);

if ($rate) {
    echo json_encode(['status' => 'success', 'rate' => $rate]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Kur bulunamadı']);
}
?>