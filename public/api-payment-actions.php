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
    // 1. ONAY
    if ($action == 'toggle_is_approved') {
        if(!has_permission('approve_payment')) throw new Exception("Yetkiniz yok.");
        
        $stmt = $pdo->prepare("SELECT is_approved FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        
        $pdo->prepare("UPDATE transactions SET is_approved = ? WHERE id = ?")->execute([$new, $id]);
        log_action($pdo, 'transaction', $id, 'update', $new ? "Ödeme onaylandı." : "Ödeme onayı kaldırıldı.");
        echo json_encode(['status' => 'success']);
    }

    // 2. ÖNCELİK (priority veya is_priority)
    elseif ($action == 'toggle_priority') {
        // Hangi sütunu kullandığımızı kontrol edelim, genelde 'priority'
        $col = 'priority'; 
        // Veritabanında is_priority varsa onu kullanın (Sizin DB yapınıza göre değişebilir)
        
        $stmt = $pdo->prepare("SELECT $col FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        
        $pdo->prepare("UPDATE transactions SET $col = ? WHERE id = ?")->execute([$new, $id]);
        log_action($pdo, 'transaction', $id, 'update', $new ? "Ödeme ACİL olarak işaretlendi." : "Ödeme önceliği normal.");
        echo json_encode(['status' => 'success']);
    }

    // 3. KONTROL (needs_control)
    elseif ($action == 'toggle_needs_control') {
        $stmt = $pdo->prepare("SELECT needs_control FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current ? 0 : 1;
        
        $pdo->prepare("UPDATE transactions SET needs_control = ? WHERE id = ?")->execute([$new, $id]);
        echo json_encode(['status' => 'success']);
    }

    else {
        throw new Exception("Geçersiz işlem tipi: $action");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>