<?php
// public/api-payment-actions.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Oturum açmalısınız.']);
    exit;
}

// --- GÜVENLİK KONTROLÜ ---
if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Bu işlem için yetkiniz bulunmuyor.']);
    exit;
}

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);

try {
    if ($action == 'toggle_approve') {
        $stmt = $pdo->prepare("SELECT is_approved FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        $pdo->prepare("UPDATE transactions SET is_approved = ? WHERE id = ?")->execute([$new, $id]);
        
        // Log Ekle
        log_action($pdo, 'transaction', $id, 'update', $new ? "Ödeme Onaylandı" : "Ödeme Onayı Geri Alındı");
        
        echo json_encode(['status' => 'success']);
    }
    elseif ($action == 'toggle_priority') {
        $stmt = $pdo->prepare("SELECT is_priority FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        $pdo->prepare("UPDATE transactions SET is_priority = ? WHERE id = ?")->execute([$new, $id]);
        
        // Log Ekle
        log_action($pdo, 'transaction', $id, 'update', $new ? "Öncelikli Olarak İşaretlendi" : "Öncelik İşareti Kaldırıldı");
        
        echo json_encode(['status' => 'success']);
    }
    elseif ($action == 'toggle_check') {
        $stmt = $pdo->prepare("SELECT needs_control FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        $pdo->prepare("UPDATE transactions SET needs_control = ? WHERE id = ?")->execute([$new, $id]);
        
        // Log Ekle
        log_action($pdo, 'transaction', $id, 'update', $new ? "Kontrol Edilecek Olarak İşaretlendi" : "Kontrol İşareti Kaldırıldı");
        
        echo json_encode(['status' => 'success']);
    }
    else {
        throw new Exception("Geçersiz işlem.");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>