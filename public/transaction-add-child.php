<?php
// public/transaction-add-child.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

// 1. ANA İŞLEMİ ÇEK
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$parent_id]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) die('<div class="alert alert-danger">Ana işlem bulunamadı.</div>');

// 2. BAKİYE HESAPLA
$stmt = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE parent_id = ?");
$stmt->execute([$parent_id]);
$total_paid = (float)$stmt->fetchColumn();
$remaining = $parent['amount'] - $total_paid;

if ($remaining <= 0) {
    die('<div class="alert alert-success m-4">Bu işlemin bakiyesi tamamen kapanmıştır.<br><a href="payment-orders.php" class="btn btn-primary mt-2">Listeye Dön</a></div>');
}

// 3. SEÇENEKLER
$doc_options = [];
$coll_channels = [];
$show_channel_select = false;

if ($parent['type'] == 'credit') {
    // TAHSİLAT (Gelir)
    $doc_options = ['Faturaları', 'Dekont', 'Slip/Pos', 'Tahsilat Makbuzu', 'MM', 'Müşteri Çeki', 'Diğer'];
    $coll_channels = $pdo->query("SELECT * FROM collection_channels ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    $show_channel_select = true;
} else {
    // ÖDEME (Gider)
    $doc_options = ['Faturamız', 'Dekont', 'Mail Order', 'Slip/Pos', 'Tediye Makbuzu', 'MM', 'Çek STH', 'Diğer'];
    $show_channel_select = false;
}

// --- KAYIT İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $date = $_POST['date'];
        $amount = (float)$_POST['amount'];
        $description = temizle($_POST['description']);
        $document_type = $_POST['document_type'];
        $collection_channel_id = !empty($_POST['collection_channel_id']) ? (int)$_POST['collection_channel_id'] : null;

        $child_type = ($parent['type'] == 'debt') ? 'payment_out' : 'payment_in'; 
        
        $file_path = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) throw new Exception("Geçersiz dosya formatı.");
            
            $upload_dir = '../storage/documents/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $filename = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir . $filename)) {
                $file_path = 'documents/' . $filename;
            }
        }

        $sql = "INSERT INTO transactions (
                    parent_id, type, date, amount, description, 
                    customer_id, tour_code_id, department_id,
                    doc_type, payment_status, file_path, document_type, collection_channel_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'payment_order', 'paid', ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $parent_id, $child_type, $date, $amount, $description, 
            $parent['customer_id'], $parent['tour_code_id'], $parent['department_id'],
            $file_path, $document_type, $collection_channel_id
        ]);
        
        $new_total_paid = $total_paid + $amount;
        if ($new_total_paid >= ($parent['amount'] - 0.05)) {
            $pdo->prepare("UPDATE transactions SET payment_status = 'paid', actual_payment_date = ? WHERE id = ?")->execute([$date, $parent_id]);
        }

        $log_desc = "$document_type ile $amount TL işlem girildi.";
        if($collection_channel_id) $log_desc .= " (Tahsilat Kanalı ID: $collection_channel_id)";
        log_action($pdo, 'transaction', $pdo->lastInsertId(), 'create', $log_desc);

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
    <title>İşlem Girişi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { background-color: #f4f6f9; }</style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow border-0">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-file-invoice-dollar"></i> 
                            <?php echo ($parent['type'] == 'debt') ? 'Ödeme Çıkışı (Gider)' : 'Tahsilat Girişi (Gelir)'; ?>
                        </span>
                        <a href="payment-orders.php" class="btn btn-sm btn-light text-primary"><i class="fa fa-times"></i></a>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if(empty($parent['invoice_no'])): ?>
                            <?php if($parent['type'] == 'debt'): ?>
                                <div class="alert alert-danger d-flex align-items-center mb-3 shadow-sm border-danger">
                                    <i class="fa fa-exclamation-triangle fa-2x me-3"></i>
                                    <div>
                                        <h6 class="alert-heading fw-bold mb-0">Dikkat: Fatura Girişi Yapılmamış!</h6>
                                        <small>Bu ödeme emrine ait bir fatura numarası girilmemiş. Ödeme yapmadan önce faturanın geldiğinden emin olunuz.</small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning d-flex align-items-center mb-3 shadow-sm border-warning">
                                    <i class="fa fa-clock fa-2x me-3"></i>
                                    <div>
                                        <h6 class="alert-heading fw-bold mb-0">Uyarı: Fatura Kesilmedi!</h6>
                                        <small>Bu tahsilat işlemi için henüz bir fatura kaydı oluşturulmamış.</small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="alert alert-light border shadow-sm mb-4">
                            <div class="d-flex justify-content-between text-danger fw-bold fs-5">
                                <span>Kalan Bakiye:</span> <span><?php echo number_format($remaining, 2); ?> ₺</span>
                            </div>
                        </div>

                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">İşlem Tarihi</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Tutar (TL)</label>
                                <input type="number" step="0.01" name="amount" class="form-control fw-bold" value="<?php echo $remaining; ?>" max="<?php echo $remaining; ?>" required>
                            </div>

                            <?php if($show_channel_select): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-success">Tahsilat Kanalı (Nereye Yattı?)</label>
                                <select name="collection_channel_id" class="form-select border-success" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($coll_channels as $ch): ?>
                                        <option value="<?php echo $ch['id']; ?>"><?php echo guvenli_html($ch['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Belge Türü</label>
                                <select name="document_type" class="form-select" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($doc_options as $opt): ?>
                                        <option value="<?php echo $opt; ?>"><?php echo $opt; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Belge Görseli (Opsiyonel)</label>
                                <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Açıklama</label>
                                <textarea name="description" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg shadow-sm">Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>