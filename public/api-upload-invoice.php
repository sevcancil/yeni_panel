<?php
// public/api-upload-invoice.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status' => 'error', 'message' => 'Oturum kapalı.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)$_POST['id'];
        $invoice_no = temizle($_POST['invoice_no']);
        $invoice_date = $_POST['invoice_date'];

        if (empty($invoice_no)) throw new Exception("Fatura numarası zorunludur.");

        // Dosya Yükleme İşlemi
        $file_path = null;
        $file_sql = ""; // SQL'e eklenecek kısım
        $params = [$invoice_no, $invoice_date, $id];

        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed)) throw new Exception("Sadece PDF ve Resim dosyaları yüklenebilir.");
            
            $upload_dir = '../storage/invoices/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $filename = 'INV_' . $id . '_' . uniqid() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $filename)) {
                $file_path = 'invoices/' . $filename;
                $file_sql = ", file_path = ?"; // Dosya varsa SQL'i güncelle
                
                // Parametre sırasını güncelle (file_path, id'den önce gelmeli)
                // Mevcut params: [no, date, id]
                // Yeni params: [no, date, path, id]
                array_splice($params, 2, 0, $file_path); 
            }
        }

        // Veritabanını Güncelle
        // invoice_status = 'issued' yapıyoruz ki bekleyenlerden düşsün.
        $sql = "UPDATE transactions 
                SET invoice_no = ?, invoice_date = ?, invoice_status = 'issued' $file_sql 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        log_action($pdo, 'transaction', $id, 'update', "Fatura yüklendi. No: $invoice_no");

        echo json_encode(['status' => 'success', 'message' => 'Fatura başarıyla işlendi.']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>