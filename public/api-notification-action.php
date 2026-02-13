<?php
// public/api-notification-action.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status' => 'error', 'message' => 'Oturum kapalı']); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// 1. MESAJ GÖNDERME
if ($action == 'send' && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'muhasebe')) {
    $receiver = (int)$_POST['receiver_id'];
    $title = temizle($_POST['title']);
    $msg = temizle($_POST['message']);

    $stmt = $pdo->prepare("INSERT INTO notifications (sender_id, receiver_id, title, message) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$user_id, $receiver, $title, $msg])) {
        echo json_encode(['status' => 'success', 'message' => 'Bildirim gönderildi.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Hata oluştu.']);
    }
}

// 2. MESAJI OKUNDU İŞARETLEME
elseif ($action == 'read') {
    $msg_id = (int)$_POST['id'];
    // Güvenlik: Sadece alıcı veya genel mesaj ise okuyabilir
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (receiver_id = ? OR receiver_id = 0)");
    $stmt->execute([$msg_id, $user_id]);
    echo json_encode(['status' => 'success']);
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz işlem']);
}
?>