<?php
// public/api-get-tour-details.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

if (!isset($_POST['tour_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$tour_id = intval($_POST['tour_id']);

// Turun bağlı olduğu departmanı çekiyoruz
$stmt = $pdo->prepare("SELECT department_id FROM tour_codes WHERE id = ?");
$stmt->execute([$tour_id]);
$tour = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'department_id' => $tour['department_id'] ?? null
]);