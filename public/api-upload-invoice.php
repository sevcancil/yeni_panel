<?php
// public/api-upload-invoice.php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    require_once '../app/config/database.php';
    require_once '../app/functions/security.php';

    if (!isset($_SESSION['user_id'])) throw new Exception('Oturum açmanız gerekiyor.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Geçersiz istek.');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $invoice_no = isset($_POST['invoice_no']) ? temizle($_POST['invoice_no']) : '';
    $invoice_date = isset($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
    
    if (!$id) throw new Exception('ID eksik.');
    if (empty($invoice_no)) throw new Exception('Fatura numarası girilmedi.');

    // 1. Ana Kayıt
    $stmtParent = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmtParent->execute([$id]);
    $parent = $stmtParent->fetch(PDO::FETCH_ASSOC);
    if (!$parent) throw new Exception('İşlem bulunamadı.');

    // Dosya
    $file_path = null;
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == 0) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) throw new Exception('Geçersiz dosya formatı.');
        $upload_dir = '../storage/invoices/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $filename = 'INV_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $filename)) {
            $file_path = 'invoices/' . $filename;
        }
    }

    $pdo->beginTransaction();

    // 2. ANA KAYDI GÜNCELLE
    // invoice_status = 'issued' yapıyoruz. payment_status DEĞİŞMİYOR.
    $sqlMain = "UPDATE transactions SET invoice_no = ?, invoice_date = ?, invoice_status = 'issued'";
    $paramsMain = [$invoice_no, $invoice_date];
    if ($file_path) { $sqlMain .= ", file_path = ?"; $paramsMain[] = $file_path; }
    $sqlMain .= " WHERE id = ?";
    $paramsMain[] = $id;
    $stmtUpdate = $pdo->prepare($sqlMain);
    $stmtUpdate->execute($paramsMain);

    // 3. CHILD KAYIT (LOG)
    // Tutar: Parent tutarı kadar.
    $child_desc = ($parent['type'] == 'debt') ? "Gider Faturası Girişi ($invoice_no)" : "Gelir Faturası Kesildi ($invoice_no)";

    $sqlChild = "INSERT INTO transactions (
                    parent_id, customer_id, tour_code_id, department_id,
                    type, date, amount, currency, description, 
                    invoice_no, doc_type, file_path, created_by
                ) VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, 'invoice', ?, ?)";
    
    $stmtChild = $pdo->prepare($sqlChild);
    $stmtChild->execute([
        $id, $parent['customer_id'], $parent['tour_code_id'], $parent['department_id'],
        $invoice_date,
        $parent['amount'], 
        $parent['currency'],
        $child_desc,
        $invoice_no,
        $file_path,
        $_SESSION['user_id']
    ]);

    log_action($pdo, 'transaction', $id, 'invoice', "Fatura işlendi: $invoice_no");
    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Fatura sisteme işlendi.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>