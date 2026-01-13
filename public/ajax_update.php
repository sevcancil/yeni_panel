<?php
// public/ajax_update.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }

// Verileri Al
$id = (int)$_POST['id'];
$field = $_POST['field']; // is_approved, is_priority, needs_control
$value = (int)$_POST['value'];

// Güvenlik: Sadece yetkili onay verebilir
if ($field == 'is_approved' && !has_permission('approve_payment')) {
    echo "Yetkisiz";
    exit;
}

// Güncelle
$allowed_fields = ['is_approved', 'is_priority', 'needs_control'];
if (in_array($field, $allowed_fields)) {
    $stmt = $pdo->prepare("UPDATE transactions SET $field = :val WHERE id = :id");
    $stmt->execute(['val' => $value, 'id' => $id]);
    echo "OK";
}
?>