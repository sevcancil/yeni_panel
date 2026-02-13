<?php
session_start();
require_once '../app/config/database.php';

if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status' => 'error', 'message' => 'Oturum kapalı']); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payroll_id = (int)$_POST['id'];
    $user_id = $_SESSION['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR']; // İmzalayanın IP adresi

    // Bordro bu kullanıcıya mı ait?
    $check = $pdo->prepare("SELECT id FROM payrolls WHERE id = ? AND user_id = ?");
    $check->execute([$payroll_id, $user_id]);
    
    if ($check->rowCount() > 0) {
        $stmt = $pdo->prepare("UPDATE payrolls SET is_signed = 1, signed_at = NOW(), sign_ip = ? WHERE id = ?");
        if ($stmt->execute([$ip_address, $payroll_id])) {
            echo json_encode(['status' => 'success', 'message' => 'Bordro dijital olarak onaylandı.', 'date' => date('d.m.Y H:i')]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Onaylama hatası']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Bu belge size ait değil veya bulunamadı.']);
    }
}
?>