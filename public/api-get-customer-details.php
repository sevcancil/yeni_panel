<?php
// public/api-get-customer-details.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // 1. Bankaları Çek
    $banks = $pdo->prepare("SELECT * FROM customer_banks WHERE customer_id = ? ORDER BY id DESC");
    $banks->execute([$id]);
    $banks_data = $banks->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Müşteri Bakiyesini Çek (YENİ)
    $cust = $pdo->prepare("SELECT current_balance, opening_balance_currency FROM customers WHERE id = ?");
    $cust->execute([$id]);
    $customer_data = $cust->fetch(PDO::FETCH_ASSOC);
    
    // 3. Son 5 İşlemi Çek (Açıklama Dahil)
    $history = $pdo->prepare("SELECT t.*, tc.code as tour_code 
                            FROM transactions t 
                            LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                            WHERE t.customer_id = ? 
                            ORDER BY t.date DESC LIMIT 5");
    $history->execute([$id]);
    $history_data = $history->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'banks' => $banks_data, 
        'history' => $history_data,
        'balance' => $customer_data['current_balance'] ?? 0, // Bakiye
        'currency' => $customer_data['opening_balance_currency'] ?? 'TRY' // Para Birimi
    ]);
} else {
    echo json_encode(['banks' => [], 'history' => []]);
}
?>