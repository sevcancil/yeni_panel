<?php
// public/api-report-data.php
require_once '../app/config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) { exit; }

$action = $_GET['action'] ?? '';
$param = $_GET['param'] ?? '';

$sql = "";
$params = [];

switch ($action) {
    // 1. Aylık Detay
    case 'month_detail':
        $sql = "SELECT t.date, t.doc_type, tc.code, c.company_name, t.description, t.amount, t.currency 
                FROM transactions t
                LEFT JOIN customers c ON t.customer_id = c.id
                LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                WHERE t.is_deleted = 0 AND DATE_FORMAT(COALESCE(t.planned_date, t.date), '%Y-%m') = ?
                ORDER BY t.date ASC";
        $params = [$param];
        break;

    // 2. Güncel İşler (Tümü)
    case 'active_projects':
        $sql = "SELECT tc.code as Tur_Kodu, tc.name as Is_Adi, tc.employer as Isveren, d.name as Bolum, tc.start_date as Tarih 
                FROM tour_codes tc 
                LEFT JOIN departments d ON tc.department_id = d.id 
                WHERE tc.start_date >= CURDATE() 
                ORDER BY tc.start_date ASC";
        break;

    // 3. En Karlı İşler (Tümü)
    case 'all_profit':
    case 'top_profit': // Grafik verisi için de aynısı
        $sql = "SELECT tc.code, tc.name, 
                (SUM(CASE WHEN t.doc_type='invoice_order' THEN t.amount ELSE 0 END) - 
                 SUM(CASE WHEN t.doc_type='payment_order' THEN t.amount ELSE 0 END)) as Net_Kar
                FROM transactions t JOIN tour_codes tc ON t.tour_code_id = tc.id
                WHERE t.is_deleted = 0 GROUP BY tc.id HAVING Net_Kar > 0 ORDER BY Net_Kar DESC";
        break;

    // 4. En Zararlı İşler (Tümü)
    case 'all_loss':
    case 'top_loss':
        $sql = "SELECT tc.code, tc.name, 
                (SUM(CASE WHEN t.doc_type='invoice_order' THEN t.amount ELSE 0 END) - 
                 SUM(CASE WHEN t.doc_type='payment_order' THEN t.amount ELSE 0 END)) as Net_Zarar
                FROM transactions t JOIN tour_codes tc ON t.tour_code_id = tc.id
                WHERE t.is_deleted = 0 GROUP BY tc.id HAVING Net_Zarar < 0 ORDER BY Net_Zarar ASC";
        break;

    // 5. Bölüm Gelirleri (Grafik İçin)
    case 'dept_income':
        $sql = "SELECT d.name as Bolum, SUM(t.amount) as Toplam_Gelir
                FROM transactions t JOIN departments d ON t.department_id = d.id
                WHERE t.doc_type='invoice_order' AND t.is_deleted = 0 GROUP BY d.id ORDER BY Toplam_Gelir DESC";
        break;

    // 6. Bölüm Giderleri (Grafik İçin)
    case 'dept_expense':
        $sql = "SELECT d.name as Bolum, SUM(t.amount) as Toplam_Gider
                FROM transactions t JOIN departments d ON t.department_id = d.id
                WHERE t.doc_type='payment_order' AND t.is_deleted = 0 GROUP BY d.id ORDER BY Toplam_Gider DESC";
        break;
}

if ($sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($data);
} else {
    echo json_encode([]);
}
?>