<?php
// public/api-merge-transactions.php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    require_once '../app/config/database.php';
    require_once '../app/functions/security.php';

    if (!isset($_SESSION['user_id'])) throw new Exception('Oturum açmanız gerekiyor.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Geçersiz istek.');

    // Verileri Al
    $ids_str = $_POST['ids'] ?? '';
    $type = $_POST['type'] ?? ''; 
    $date = $_POST['date'];
    $description = temizle($_POST['description']);
    $channel_id = (int)$_POST['channel_id'];
    $payment_currency = $_POST['payment_currency'];
    
    // Fatura Bilgileri (Opsiyonel)
    $bulk_invoice = isset($_POST['bulk_invoice_check']);
    $invoice_no = $bulk_invoice ? temizle($_POST['invoice_no']) : null;
    $invoice_date = $bulk_invoice ? $_POST['invoice_date'] : null;

    if (empty($ids_str) || empty($channel_id)) throw new Exception('Eksik bilgi.');

    $ids = explode(',', $ids_str);
    
    // Dosya Yükleme
    $file_path = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $upload_dir = '../storage/documents/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $filename = 'BATCH_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir . $filename)) {
            $file_path = 'documents/' . $filename;
        }
    }

    $pdo->beginTransaction();

    $batch_code = " [TOPLU:" . date('ymdHi') . "]";
    $final_desc = $description . $batch_code;

    $payment_method_id = ($type == 'payment_out') ? $channel_id : null;
    $collection_channel_id = ($type == 'payment_in') ? $channel_id : null;

    $total_processed = 0;

    foreach ($ids as $parent_id) {
        $parent_id = (int)$parent_id;
        
        $stmtMain = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmtMain->execute([$parent_id]);
        $parent = $stmtMain->fetch(PDO::FETCH_ASSOC);
        
        $stmtPaid = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE parent_id = ? AND is_deleted = 0 AND (type='payment_in' OR type='payment_out')");
        $stmtPaid->execute([$parent_id]);
        $already_paid = (float)$stmtPaid->fetchColumn();
        
        $amount_to_pay = $parent['amount'] - $already_paid; // TL kalan

        // 1. ÖDEME KAYDI EKLE
        // Çoklu döviz mantığı (Basitleştirilmiş): amount sütununa TL karşılığı yazılır.
        // Eğer payment_currency parent_currency'den farklıysa kur dönüşümü JS'de yapılmıştı, 
        // ama biz burada sadece kapanan bakiyeyi yazıyoruz.
        
        if ($amount_to_pay > 0.01) {
            $sql = "INSERT INTO transactions (
                        parent_id, type, doc_type, date, amount, original_amount, currency, exchange_rate, description, 
                        customer_id, tour_code_id, department_id,
                        payment_status, file_path, document_type,
                        payment_method_id, collection_channel_id,
                        created_by
                    ) VALUES (?, ?, 'payment_order', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, 'Toplu İşlem', ?, ?, ?)";

            // Not: original_amount burada parent'ın orijinal döviz cinsinden kapanan tutarı olmalı.
            // Eğer parent USD ise ve tamamen kapanıyorsa original_amount yazılmalı.
            $orig_pay = ($parent['currency'] != 'TRY') ? ($parent['original_amount'] - 0) : 0; 
            // Not: above logic assumes full payment. For partial logic in merge, update logic needed.
            // But merge usually implies closing full balance.

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $parent_id, $type, $date, $amount_to_pay, 
                $orig_pay, $parent['currency'], $parent['exchange_rate'], 
                $final_desc,
                $parent['customer_id'], $parent['tour_code_id'], $parent['department_id'],
                $file_path, $payment_method_id, $collection_channel_id, $_SESSION['user_id']
            ]);

            // Ana İşlemi 'Paid' Yap
            $pdo->prepare("UPDATE transactions SET payment_status = 'paid' WHERE id = ?")->execute([$parent_id]);
            $total_processed++;
        }

        // 2. FATURA BİLGİLERİNİ GÜNCELLE (Eğer girildiyse)
        // Bu işlem ANA KAYDI (Parent) günceller.
        if ($bulk_invoice && !empty($invoice_no)) {
            $sqlInvoice = "UPDATE transactions SET invoice_no = ?, invoice_date = ?, invoice_status = 'issued'";
            $params = [$invoice_no, $invoice_date];
            
            // Eğer dosya yüklendiyse ve ana kayıtta dosya yoksa, toplu dosyayı oraya da ata
            if ($file_path) {
                $sqlInvoice .= ", file_path = COALESCE(file_path, ?)"; // Varsa ezme, yoksa yaz
                $params[] = $file_path;
            }
            
            $sqlInvoice .= " WHERE id = ?";
            $params[] = $parent_id;
            
            $pdo->prepare($sqlInvoice)->execute($params);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "$total_processed adet işlem birleştirildi."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>