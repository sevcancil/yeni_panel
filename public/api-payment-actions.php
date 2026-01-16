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

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);

try {
    // 1. ONAY İŞLEMİ (is_approved)
    if ($action == 'toggle_approve') {
        // İsterseniz yetki kontrolünü açabilirsiniz:
        // if(!has_permission('approve_payment')) throw new Exception("Yetkiniz yok.");
        
        $stmt = $pdo->prepare("SELECT is_approved FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        
        $pdo->prepare("UPDATE transactions SET is_approved = ? WHERE id = ?")->execute([$new, $id]);
        
        // Log (activity_logs tablonuza uygun)
        log_action($pdo, 'transaction', $id, 'update', $new ? "Ödeme onaylandı." : "Ödeme onayı geri alındı.");
        
        echo json_encode(['status' => 'success']);
    }

    // 2. ÖNCELİK İŞLEMİ (is_priority)
    elseif ($action == 'toggle_priority') {
        $stmt = $pdo->prepare("SELECT is_priority FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        
        $pdo->prepare("UPDATE transactions SET is_priority = ? WHERE id = ?")->execute([$new, $id]);
        echo json_encode(['status' => 'success']);
    }

    // 3. KONTROL İŞLEMİ (needs_control)
    elseif ($action == 'toggle_check') {
        $stmt = $pdo->prepare("SELECT needs_control FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        
        $pdo->prepare("UPDATE transactions SET needs_control = ? WHERE id = ?")->execute([$new, $id]);
        echo json_encode(['status' => 'success']);
    }

    else {
        throw new Exception("Geçersiz işlem.");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>