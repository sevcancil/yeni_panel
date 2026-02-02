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

    // Dosya Yükleme İşlemi
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
    // invoice_status = 'issued' yapıyoruz.
    $sqlMain = "UPDATE transactions SET invoice_no = ?, invoice_date = ?, invoice_status = 'issued'";
    $paramsMain = [$invoice_no, $invoice_date];
    if ($file_path) { 
        $sqlMain .= ", file_path = ?"; 
        $paramsMain[] = $file_path; 
    }
    $sqlMain .= " WHERE id = ?";
    $paramsMain[] = $id;
    
    $stmtUpdate = $pdo->prepare($sqlMain);
    $stmtUpdate->execute($paramsMain);

    // 3. CHILD KAYIT KONTROLÜ (GÜNCELLEME Mİ, EKLEME Mİ?)
    // Bu ana işleme bağlı 'invoice' tipinde bir kayıt var mı?
    $stmtCheck = $pdo->prepare("SELECT id FROM transactions WHERE parent_id = ? AND type = 'invoice' LIMIT 1");
    $stmtCheck->execute([$id]);
    $existingChild = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    $child_desc = ($parent['type'] == 'debt') ? "Gider Faturası Girişi ($invoice_no)" : "Gelir Faturası Kesildi ($invoice_no)";

    // Parent verilerini alalım (Döviz/Tutar bilgilerini doğru taşımak için)
    $p_amount = $parent['amount'];
    $p_currency = $parent['currency'];
    $p_original = $parent['original_amount'];
    $p_rate = $parent['exchange_rate'];

    if ($existingChild) {
        // --- VARSA GÜNCELLE ---
        $updateSql = "UPDATE transactions SET 
                      invoice_no = ?, date = ?, description = ?, 
                      amount = ?, currency = ?, original_amount = ?, exchange_rate = ?";
        
        $updateParams = [$invoice_no, $invoice_date, $child_desc, $p_amount, $p_currency, $p_original, $p_rate];

        if ($file_path) {
            $updateSql .= ", file_path = ?";
            $updateParams[] = $file_path;
        }

        $updateSql .= " WHERE id = ?";
        $updateParams[] = $existingChild['id'];

        $pdo->prepare($updateSql)->execute($updateParams);
        
        log_action($pdo, 'transaction', $id, 'update', "Fatura bilgileri güncellendi: $invoice_no");

    } else {
        // --- YOKSA EKLE ---
        $sqlChild = "INSERT INTO transactions (
                        parent_id, customer_id, tour_code_id, department_id,
                        type, date, amount, currency, original_amount, exchange_rate,
                        description, invoice_no, doc_type, file_path, created_by
                    ) VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, ?, ?, 'invoice', ?, ?)";
        
        $stmtChild = $pdo->prepare($sqlChild);
        $stmtChild->execute([
            $id, $parent['customer_id'], $parent['tour_code_id'], $parent['department_id'],
            $invoice_date,
            $p_amount, $p_currency, $p_original, $p_rate,
            $child_desc, $invoice_no, $file_path, $_SESSION['user_id']
        ]);

        log_action($pdo, 'transaction', $id, 'invoice', "Fatura işlendi: $invoice_no");
    }

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Fatura başarıyla kaydedildi.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>