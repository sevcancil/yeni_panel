<?php
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
    if ($action == 'toggle_approve') {
        if(!has_permission('approve_payments')) throw new Exception("Yetkiniz yok.");
        
        // Mevcut durumu çek
        $stmt = $pdo->prepare("SELECT is_approved FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        
        $new_status = $current ? 0 : 1;
        $pdo->prepare("UPDATE transactions SET is_approved = ? WHERE id = ?")->execute([$new_status, $id]);
        
        $log_msg = $new_status ? "Ödeme onaylandı." : "Ödeme onayı kaldırıldı.";
        log_action($pdo, 'transaction', $id, 'update', $log_msg);
        
        echo json_encode(['status' => 'success']);
    }

    elseif ($action == 'toggle_priority') {
        if(!has_permission('approve_payments')) throw new Exception("Yetkiniz yok.");
        
        $stmt = $pdo->prepare("SELECT priority FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        
        $new_status = $current ? 0 : 1;
        $pdo->prepare("UPDATE transactions SET priority = ? WHERE id = ?")->execute([$new_status, $id]);
        
        $log_msg = $new_status ? "Ödeme YÜKSEK öncelikli yapıldı." : "Ödeme önceliği normale döndü.";
        log_action($pdo, 'transaction', $id, 'update', $log_msg);
        
        echo json_encode(['status' => 'success']);
    }
    
    // Diğer işlemler (Silme, Düzenleme Kaydetme) buraya eklenebilir...

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>