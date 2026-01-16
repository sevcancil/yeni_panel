<?php
// public/api-get-customer-details.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // 1. Bankaları Çek
    $banks = $pdo->query("SELECT * FROM customer_banks WHERE customer_id = $id ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Son 5 İşlemi Çek (Mükerrer kontrolü için)
    $history = $pdo->query("SELECT t.*, tc.code as tour_code 
                            FROM transactions t 
                            LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                            WHERE t.customer_id = $id 
                            ORDER BY t.date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['banks' => $banks, 'history' => $history]);
} else {
    echo json_encode(['banks' => [], 'history' => []]);
}
?>