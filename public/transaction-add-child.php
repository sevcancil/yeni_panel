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

if (!$parent) die('<div class="alert alert-danger m-4">Ana işlem bulunamadı.</div>');

// 2. BAKİYE HESAPLA
$stmt = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE parent_id = ?");
$stmt->execute([$parent_id]);
$total_paid = (float)$stmt->fetchColumn();
$remaining = $parent['amount'] - $total_paid; // Kalan (TL Cinsinden veya ana para biriminden)

if ($remaining <= 0.01) {
    die('<div class="alert alert-success m-4">Bu işlemin bakiyesi tamamen kapanmıştır.<br><a href="payment-orders.php" class="btn btn-primary mt-2">Listeye Dön</a></div>');
}

// 3. SEÇENEKLERİ HAZIRLA
$doc_options = [];
$channels = []; 
$channel_label = "";
$channel_db_col = "";

if ($parent['type'] == 'credit') {
    $doc_options = ['Faturaları', 'Dekont', 'Slip/Pos', 'Tahsilat Makbuzu', 'MM', 'Müşteri Çeki', 'Diğer'];
    $channels = $pdo->query("SELECT id, title FROM collection_channels ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    $channel_label = "Tahsilat Kanalı";
    $channel_db_col = "collection_channel_id";
} else {
    $doc_options = ['Faturamız', 'Dekont', 'Mail Order', 'Slip/Pos', 'Tediye Makbuzu', 'MM', 'Çek STH', 'Diğer'];
    $channels = $pdo->query("SELECT id, title FROM payment_methods ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    $channel_label = "Ödeme Kaynağı";
    $channel_db_col = "payment_method_id";
}

// --- KAYIT İŞLEMİ ---
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $date = $_POST['date'];
        
        // DÖVİZ HESAPLAMA MANTIĞI
        $pay_currency = $_POST['currency']; // Ödenen Para Birimi
        $pay_amount = (float)$_POST['pay_amount']; // Ödenen Miktar (Örn: 1000 USD)
        $rate = (float)$_POST['exchange_rate']; // Kur (Örn: 30)
        
        $base_amount = 0; // Veritabanındaki 'amount' (TL Karşılığı - Bakiyeden düşecek tutar)
        
        if ($pay_currency === 'TRY') {
            $base_amount = $pay_amount;
            $rate = 1.0000;
        } else {
            $base_amount = $pay_amount * $rate; // 1000 * 30 = 30000 TL
        }

        // Bakiye kontrolü (Opsiyonel: İsterseniz fazla ödemeye izin verebilirsiniz)
        // if ($base_amount > ($remaining + 1)) throw new Exception("Ödeme tutarı kalan bakiyeyi aşıyor!");

        $description = temizle($_POST['description']);
        $document_type = $_POST['document_type'];
        $selected_channel_id = !empty($_POST['channel_id']) ? (int)$_POST['channel_id'] : null;

        $payment_method_id = ($channel_db_col == 'payment_method_id') ? $selected_channel_id : null;
        $collection_channel_id = ($channel_db_col == 'collection_channel_id') ? $selected_channel_id : null;
        $child_type = ($parent['type'] == 'debt') ? 'payment_out' : 'payment_in'; 
        
        // Dosya Yükleme
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

        $created_by = $_SESSION['user_id'];

        $sql = "INSERT INTO transactions (
                    parent_id, type, date, amount, original_amount, currency, exchange_rate, description, 
                    customer_id, tour_code_id, department_id,
                    doc_type, payment_status, file_path, document_type, 
                    payment_method_id, collection_channel_id,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'payment_order', 'paid', ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $parent_id, 
            $child_type, 
            $date, 
            $base_amount, // Bakiyeden düşecek TL tutarı
            $pay_amount,  // Orijinal ödenen tutar (Dövizli)
            $pay_currency,
            $rate,
            $description, 
            $parent['customer_id'], 
            $parent['tour_code_id'], 
            $parent['department_id'],
            $file_path, 
            $document_type, 
            $payment_method_id, 
            $collection_channel_id,
            $created_by
        ]);
        
        // DURUM GÜNCELLEME
        $new_total_paid = $total_paid + $base_amount;
        if ($new_total_paid >= ($parent['amount'] - 0.05)) {
            $pdo->prepare("UPDATE transactions SET payment_status = 'paid', actual_payment_date = ? WHERE id = ?")->execute([$date, $parent_id]);
        }

        log_action($pdo, 'transaction', $parent_id, 'create', "Hareket Girildi: $pay_amount $pay_currency ($document_type)");

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
                        
                        <div class="alert alert-light border shadow-sm mb-4">
                            <div class="d-flex justify-content-between text-danger fw-bold fs-5">
                                <span>Kalan Bakiye:</span> 
                                <span id="remaining_display" data-val="<?php echo $remaining; ?>"><?php echo number_format($remaining, 2); ?> TL</span>
                            </div>
                        </div>

                        <?php if(!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">İşlem Tarihi</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="row mb-3 p-2 bg-light rounded border mx-0">
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small fw-bold">Para Birimi</label>
                                    <select name="currency" id="currency" class="form-select" onchange="updateRate()">
                                        <option value="TRY">TRY (₺)</option>
                                        <option value="USD">USD ($)</option>
                                        <option value="EUR">EUR (€)</option>
                                        <option value="GBP">GBP (£)</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small fw-bold">Ödenen Tutar</label>
                                    <input type="number" step="0.01" name="pay_amount" id="pay_amount" class="form-control fw-bold" placeholder="0.00" required oninput="calcBase()">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small fw-bold">Kur</label>
                                    <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate" class="form-control" value="1.0000" readonly oninput="calcBase()">
                                </div>
                                <div class="col-12 mt-1 text-end">
                                    <small class="text-muted">Bakiyeden Düşecek: <span id="calc_result" class="fw-bold text-dark">0.00 TL</span></small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold <?php echo ($parent['type']=='credit')?'text-success':'text-primary'; ?>">
                                    <?php echo $channel_label; ?>
                                </label>
                                <select name="channel_id" class="form-select border-<?php echo ($parent['type']=='credit')?'success':'primary'; ?>" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($channels as $ch): ?>
                                        <option value="<?php echo $ch['id']; ?>"><?php echo guvenli_html($ch['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

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

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script>
        function updateRate() {
            var currency = document.getElementById('currency').value;
            var rateInput = document.getElementById('exchange_rate');
            
            if (currency === 'TRY') { 
                rateInput.value = 1.0000; 
                rateInput.readOnly = true; 
                calcBase(); 
            } else { 
                rateInput.readOnly = false; 
                rateInput.placeholder = "Yükleniyor...";
                
                // Kur API'sini çağır (Mevcut yapınızda bu dosya var)
                $.get('api-get-currency-rate.php?code='+currency, function(d){ 
                    if(d.status === 'success') {
                        rateInput.value = d.rate; 
                        calcBase();
                    } 
                }, 'json'); 
            }
        }

        function calcBase() {
            var amt = parseFloat(document.getElementById('pay_amount').value) || 0;
            var rate = parseFloat(document.getElementById('exchange_rate').value) || 1;
            var total = amt * rate;
            
            document.getElementById('calc_result').innerText = total.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' TL';
        }
    </script>
</body>
</html>