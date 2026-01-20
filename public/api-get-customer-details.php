<?php
// public/api-get-customer-details.php

// Oturum ve Güvenlik Başlatma
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Yetki Kontrolü (Login olmamışsa veri verme)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fetch_full_history = isset($_GET['history']) && $_GET['history'] == 1;

if ($id > 0) {
    try {
        // 1. Müşteri Bakiyesini ve Para Birimini Çek
        $cust = $pdo->prepare("SELECT current_balance, opening_balance_currency FROM customers WHERE id = ?");
        $cust->execute([$id]);
        $customer_data = $cust->fetch(PDO::FETCH_ASSOC);

        // 2. Bankaları Çek
        $banks = $pdo->prepare("SELECT * FROM customer_banks WHERE customer_id = ? ORDER BY id DESC");
        $banks->execute([$id]);
        $banks_data = $banks->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. İşlem Geçmişini Çek
        // Eğer 'history=1' parametresi varsa son 50 işlemi, yoksa son 5 işlemi getir (Özet için)
        $limit = $fetch_full_history ? 50 : 5;
        
        $sql_history = "SELECT t.id, t.date, t.type, t.doc_type, t.amount, t.description, tc.code as tour_code 
                        FROM transactions t 
                        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                        WHERE t.customer_id = ? 
                        ORDER BY t.date DESC, t.id DESC 
                        LIMIT $limit";
                        
        $history = $pdo->prepare($sql_history);
        $history->execute([$id]);
        $history_data = $history->fetchAll(PDO::FETCH_ASSOC);

        // JSON Çıktısı
        echo json_encode([
            'status' => 'success',
            'balance' => $customer_data['current_balance'] ?? 0,
            'currency' => $customer_data['opening_balance_currency'] ?? 'TRY',
            'banks' => $banks_data,
            'history' => $history_data
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz ID', 'banks' => [], 'history' => []]);
}
?>