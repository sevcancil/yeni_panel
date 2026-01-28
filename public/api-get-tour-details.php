<?php
// public/api-get-tour-details.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tour_id > 0) {
    $stmt = $pdo->prepare("SELECT department_id FROM tour_codes WHERE id = ?");
    $stmt->execute([$tour_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo json_encode(['status' => 'success', 'department_id' => $result['department_id']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Kayıt bulunamadı']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz ID']);
}
?>