<?php
// public/api-add-bank.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $customer_id = (int)$_POST['customer_id'];
        $bank_name = temizle($_POST['bank_name']);
        $iban = temizle($_POST['iban']);
        $currency = temizle($_POST['currency']);

        $stmt = $pdo->prepare("INSERT INTO customer_banks (customer_id, bank_name, iban, currency) VALUES (?, ?, ?, ?)");
        $stmt->execute([$customer_id, $bank_name, $iban, $currency]);
        
        echo json_encode(['status' => 'success', 'message' => 'Banka eklendi.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>