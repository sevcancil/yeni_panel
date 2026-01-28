<?php
// public/transaction-add-child.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'payment'; // 'payment' veya 'invoice'

// 1. ANA İŞLEMİ ÇEK
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$parent_id]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) die('<div class="alert alert-danger m-4">Ana işlem bulunamadı.</div>');

// 2. LİMİT KONTROLLERİ (Sadece bilgilendirme amaçlı)
// Fatura modu için: Daha önce girilen faturalar
// Ödeme modu için: Daha önce yapılan ödemeler
$filter_doc = ($mode == 'invoice') ? "doc_type = 'invoice'" : "doc_type != 'invoice'";
$stmt = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE parent_id = ? AND $filter_doc");
$stmt->execute([$parent_id]);
$total_processed = (float)$stmt->fetchColumn();
$remaining = $parent['amount'] - $total_processed; 

// Başlık ve Ayarlar
if ($mode == 'invoice') {
    $page_title = "Yeni Fatura Girişi";
    $card_class = "border-warning";
    $header_class = "bg-warning text-dark";
    $submit_class = "btn-warning text-dark";
    $doc_options = ['Fatura', 'e-Fatura', 'e-Arşiv', 'Proforma'];
    $amount_label = "Fatura Tutarı";
} else {
    $page_title = ($parent['type'] == 'debt') ? 'Ödeme Çıkışı (Gider)' : 'Tahsilat Girişi (Gelir)';
    $card_class = "border-primary";
    $header_class = "bg-primary text-white";
    $submit_class = "btn-primary";
    $amount_label = "Ödenen Tutar";
    
    if ($parent['type'] == 'credit') {
        $doc_options = ['Dekont', 'Slip/Pos', 'Tahsilat Makbuzu', 'Diğer'];
        $channels = $pdo->query("SELECT id, title FROM collection_channels ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
        $channel_label = "Tahsilat Kanalı";
        $channel_db_col = "collection_channel_id";
    } else {
        $doc_options = ['Dekont', 'Mail Order', 'Slip/Pos', 'Tediye Makbuzu', 'Diğer'];
        $channels = $pdo->query("SELECT id, title FROM payment_methods ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
        $channel_label = "Ödeme Kaynağı";
        $channel_db_col = "payment_method_id";
    }
}

// --- KAYIT İŞLEMİ ---
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $date = $_POST['date'];
        $amount = (float)$_POST['amount'];
        $description = temizle($_POST['description']);
        $document_type = $_POST['document_type'];
        $invoice_no = isset($_POST['invoice_no']) ? temizle($_POST['invoice_no']) : null;

        $payment_method_id = null;
        $collection_channel_id = null;
        $doc_type_db = ($mode == 'invoice') ? 'invoice' : 'payment_order';
        
        // TÜR BELİRLEME
        // Fatura ise: Ana işlem neyse o (Debt -> Debt) ki borcu resmileştirsin.
        // Ödeme ise: Ana işlemin tersi (Debt -> Payment Out) ki borcu düşürsün.
        if ($mode == 'invoice') {
            $child_type = $parent['type']; 
        } else {
            $child_type = ($parent['type'] == 'debt') ? 'payment_out' : 'payment_in';
            $selected_channel_id = !empty($_POST['channel_id']) ? (int)$_POST['channel_id'] : null;
            if (isset($channel_db_col)) {
                if ($channel_db_col == 'payment_method_id') $payment_method_id = $selected_channel_id;
                if ($channel_db_col == 'collection_channel_id') $collection_channel_id = $selected_channel_id;
            }
        }

        // Dosya Yükleme
        $file_path = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) throw new Exception("Geçersiz dosya formatı.");
            
            $subfolder = ($mode == 'invoice') ? 'invoices/' : 'documents/';
            $upload_dir = '../storage/' . $subfolder;
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = ($mode == 'invoice' ? 'INV_' : 'PAY_') . uniqid() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir . $filename)) {
                $file_path = $subfolder . $filename;
            }
        }

        $created_by = $_SESSION['user_id'];

        $sql = "INSERT INTO transactions (
                    parent_id, type, date, amount, description, 
                    customer_id, tour_code_id, department_id,
                    doc_type, payment_status, file_path, document_type, invoice_no,
                    payment_method_id, collection_channel_id,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $parent_id, 
            $child_type, 
            $date, 
            $amount, 
            $description, 
            $parent['customer_id'], 
            $parent['tour_code_id'], 
            $parent['department_id'],
            $doc_type_db, 
            $file_path, 
            $document_type, 
            $invoice_no,
            $payment_method_id, 
            $collection_channel_id,
            $created_by
        ]);
        
        // Sadece ÖDEME yapıldığında ana işlemin durumunu güncelle (Fatura durumu etkilemez)
        if ($mode == 'payment') {
            // Toplam ödenen (sadece ödemeler)
            $stmt_check = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE parent_id = ? AND doc_type != 'invoice'");
            $stmt_check->execute([$parent_id]);
            $real_paid = (float)$stmt_check->fetchColumn();
            
            if ($real_paid >= ($parent['amount'] - 0.05)) {
                $pdo->prepare("UPDATE transactions SET payment_status = 'paid', actual_payment_date = ? WHERE id = ?")->execute([$date, $parent_id]);
            } else {
                // Kısmi ödeme varsa
                if ($real_paid > 0) {
                     // payment_status enum olduğu için veritabanında 'partial' yoksa dokunmuyoruz,
                     // ama listelemede hesaplayarak gösteriyoruz.
                }
            }
        }

        $log_action = ($mode == 'invoice') ? "Fatura Eklendi ($invoice_no)" : "Finansal Hareket";
        log_action($pdo, 'transaction', $parent_id, 'create', "$log_action: " . number_format($amount, 2) . " TL");

        $pdo->commit();
        header("Location: payment-orders.php?msg=success");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { background-color: #f4f6f9; }</style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow border-0 <?php echo $card_class; ?>">
                    <div class="card-header <?php echo $header_class; ?> d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="fa fa-file-signature"></i> <?php echo $page_title; ?></span>
                        <a href="payment-orders.php" class="btn btn-sm btn-light bg-opacity-50 border-0"><i class="fa fa-times"></i></a>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if($remaining > 0): ?>
                            <div class="alert alert-light border shadow-sm mb-4">
                                <div class="d-flex justify-content-between text-dark fw-bold">
                                    <span>Kalan <?php echo ($mode=='invoice') ? 'Fatura' : 'Ödeme'; ?> Hakkı:</span> 
                                    <span><?php echo number_format($remaining, 2); ?> TL</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if(!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">İşlem Tarihi</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><?php echo $amount_label; ?> (TL)</label>
                                <input type="number" step="0.01" name="amount" class="form-control fw-bold" placeholder="0.00" required>
                            </div>

                            <?php if ($mode == 'invoice'): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Fatura Numarası <span class="text-danger">*</span></label>
                                    <input type="text" name="invoice_no" class="form-control" required placeholder="Fatura No giriniz...">
                                </div>
                            <?php endif; ?>

                            <?php if ($mode == 'payment'): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?php echo $channel_label; ?></label>
                                    <select name="channel_id" class="form-select" required>
                                        <option value="">Seçiniz...</option>
                                        <?php foreach($channels as $ch): ?>
                                            <option value="<?php echo $ch['id']; ?>"><?php echo guvenli_html($ch['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Belge Türü</label>
                                <select name="document_type" class="form-select" required>
                                    <?php foreach($doc_options as $opt): ?>
                                        <option value="<?php echo $opt; ?>"><?php echo $opt; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Belge / Dosya</label>
                                <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Açıklama</label>
                                <textarea name="description" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn <?php echo $submit_class; ?> btn-lg shadow-sm">Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>