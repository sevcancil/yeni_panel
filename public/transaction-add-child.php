<?php
// public/transaction-add-child.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

// 1. ANA İŞLEMİ VE DETAYLARINI ÇEK
$sql = "SELECT t.*, 
        c.company_name, 
        tc.code as tour_code, 
        d.name as department_name
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        LEFT JOIN departments d ON t.department_id = d.id
        WHERE t.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$parent_id]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) die('<div class="alert alert-danger m-4">Ana işlem bulunamadı.</div>');

// 2. MÜŞTERİ BANKA BİLGİLERİNİ ÇEK
$stmtBanks = $pdo->prepare("SELECT * FROM customer_banks WHERE customer_id = ?");
$stmtBanks->execute([$parent['customer_id']]);
$customer_banks = $stmtBanks->fetchAll(PDO::FETCH_ASSOC);

// 3. BAKİYE HESAPLA
$stmt = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE parent_id = ? AND (type = 'payment_out' OR type = 'payment_in') AND is_deleted = 0");
$stmt->execute([$parent_id]);
$total_paid = (float)$stmt->fetchColumn();

// Kalan Bakiye
$remaining = $parent['amount'] - $total_paid; 

if ($remaining <= 0.01) {
    die('<div class="alert alert-success m-4 text-center"><h3><i class="fa fa-check-circle"></i> Ödeme Tamamlandı</h3>Bu işlemin ödemesi/tahsilatı tamamen tamamlanmıştır.<br><a href="payment-orders.php" class="btn btn-primary mt-3">Listeye Dön</a></div>');
}

// 4. AYARLAR
$doc_options = []; $channels = []; $channel_label = ""; $channel_db_col = ""; $amount_label = "Ödenen Tutar"; $page_title = "Ödeme Girişi"; $bg_class = "bg-danger text-white";

if ($parent['type'] == 'credit') {
    // TAHSİLAT
    $doc_options = ['Faturaları', 'Dekont', 'Slip/Pos', 'Tahsilat Makbuzu', 'MM', 'Müşteri Çeki', 'Diğer'];
    $channels = $pdo->query("SELECT id, title FROM collection_channels ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    $channel_label = "Tahsilat Kanalı (Kasa/Banka)";
    $channel_db_col = "collection_channel_id";
    $amount_label = "Tahsil Edilen Tutar";
    $page_title = "Tahsilat Girişi";
    $bg_class = "bg-success text-white";
} else {
    // ÖDEME
    $doc_options = ['Faturamız', 'Dekont', 'Mail Order', 'Slip/Pos', 'Tediye Makbuzu', 'MM', 'Çek STH'];
    $channels = $pdo->query("SELECT id, title FROM payment_methods ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    $channel_label = "Ödeme Kaynağı (Kasa/Banka)";
    $channel_db_col = "payment_method_id";
    $amount_label = "Ödenen Tutar";
    $page_title = "Ödeme Çıkışı";
    $bg_class = "bg-danger text-white";
}

// Ana İşlem Kuru (Hesaplama için)
$parent_rate = ($parent['exchange_rate'] > 0) ? (float)$parent['exchange_rate'] : 1.0;
$parent_currency = $parent['currency'];

// --- KAYIT ---
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $date = $_POST['date'];
        $pay_currency = $_POST['currency']; 
        $pay_amount = (float)$_POST['pay_amount']; 
        $rate = (float)$_POST['exchange_rate']; 
        
        $base_amount = ($pay_currency === 'TRY') ? $pay_amount : ($pay_amount * $rate);

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
            $parent_id, $child_type, $date, $base_amount, $pay_amount, $pay_currency, $rate,
            $description, $parent['customer_id'], $parent['tour_code_id'], $parent['department_id'],
            $file_path, $document_type, $payment_method_id, $collection_channel_id, $created_by
        ]);
        
        $last_child_id = $pdo->lastInsertId();

        // KASA GÜNCELLEME
        if ($selected_channel_id) {
            // Bakiyeler payment_channels tablosundaysa burayı açabiliriz.
            // Şimdilik sadece log.
        }

        // DURUM GÜNCELLEME
        $new_total_paid = $total_paid + $base_amount;
        if ($new_total_paid >= ($parent['amount'] - 0.05)) {
            $pdo->prepare("UPDATE transactions SET payment_status = 'paid', actual_payment_date = ? WHERE id = ?")->execute([$date, $parent_id]);
        }

        log_action($pdo, 'transaction', $parent_id, 'update', "Ödeme/Tahsilat Eklendi: " . number_format($pay_amount, 2) . " " . $pay_currency); 
        log_action($pdo, 'transaction', $last_child_id, 'create', "Hareket Kaydı Oluşturuldu.");

        $pdo->commit();

        // --- YÖNLENDİRME MANTIĞI (GÜNCELLENDİ) ---
        // Eğer kullanıcı kur farkı faturası kesmek istediyse, formdan gelen URL'ye git.
        // Yoksa normal listeye dön.
        if (!empty($_POST['next_url'])) {
            header("Location: " . $_POST['next_url']);
        } else {
            header("Location: payment-orders.php?msg=success");
        }
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
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    <style>
        body { background-color: #f4f6f9; }
        .detail-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0; font-size: 0.9rem; }
        .detail-label { font-weight: 600; color: #666; }
        .detail-val { font-weight: bold; color: #333; }
        .bank-item { border-left: 3px solid #0d6efd; background-color: #f8f9fa; padding: 8px; margin-bottom: 5px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            
            <div class="col-md-5">
                <div class="card shadow border-0 mb-3">
                    <div class="card-header bg-light border-bottom">
                        <h5 class="mb-0 text-secondary"><i class="fa fa-info-circle"></i> İşlem Detayları</h5>
                    </div>
                    <div class="card-body">
                        
                        <div class="detail-row">
                            <span class="detail-label">Cari / Firma:</span>
                            <span class="detail-val text-primary"><?php echo guvenli_html($parent['company_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">İşlem Türü:</span>
                            <span class="detail-val">
                                <?php echo ($parent['type']=='debt') ? '<span class="badge bg-danger">Ödeme Emri (Gider)</span>' : '<span class="badge bg-success">Tahsilat Emri (Gelir)</span>'; ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Tur Kodu:</span>
                            <span class="detail-val"><?php echo !empty($parent['tour_code']) ? $parent['tour_code'] : '-'; ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Departman:</span>
                            <span class="detail-val"><?php echo !empty($parent['department_name']) ? $parent['department_name'] : '-'; ?></span>
                        </div>
                        
                        <div class="alert alert-secondary mt-3 mb-0 py-2 small">
                            <strong>Açıklama:</strong><br>
                            <?php echo guvenli_html($parent['description']); ?>
                        </div>

                        <?php if(!empty($parent['description']) && (strpos(strtolower($parent['description']), 'iban') !== false || strpos(strtolower($parent['description']), 'banka') !== false)): ?>
                            <div class="alert alert-warning mt-2 py-2 small">
                                <i class="fa fa-university"></i> <strong>Banka Bilgisi Algılandı:</strong><br>
                                Bu işlemin açıklamasında banka/iban bilgisi geçiyor. Lütfen kontrol ediniz.
                            </div>
                        <?php endif; ?>

                        <hr>
                        
                        <div class="row text-center mb-3">
                            <div class="col-6 border-end">
                                <small class="d-block text-muted">Toplam Tutar</small>
                                <strong class="fs-5 text-dark">
                                    <?php 
                                        echo number_format($parent['original_amount'], 2) . ' ' . $parent['currency']; 
                                        if($parent['currency'] != 'TRY') echo '<br><small class="text-muted fw-normal" style="font-size:0.75rem">('.number_format($parent['amount'], 2).' TL)</small>';
                                    ?>
                                </strong>
                            </div>
                            <div class="col-6">
                                <small class="d-block text-muted">Kalan Bakiye</small>
                                <strong class="fs-5 text-danger">
                                    <?php echo number_format($remaining, 2); ?> TL
                                </strong>
                            </div>
                        </div>

                        <?php if(!empty($customer_banks)): ?>
                            <div class="card border-primary bg-primary bg-opacity-10">
                                <div class="card-body py-2">
                                    <h6 class="text-primary fw-bold mb-2 small"><i class="fa fa-university"></i> Cari Banka Hesapları</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach($customer_banks as $bank): ?>
                                            <div class="bank-item position-relative">
                                                <div class="d-flex justify-content-between">
                                                    <span class="fw-bold small"><?php echo guvenli_html($bank['bank_name']); ?></span>
                                                    <span class="badge bg-secondary"><?php echo $bank['currency']; ?></span>
                                                </div>
                                                <div class="font-monospace small text-dark mt-1"><?php echo guvenli_html($bank['iban']); ?></div>
                                                <button class="btn btn-sm btn-link p-0 position-absolute bottom-0 end-0 me-2 mb-1" 
                                                        onclick="navigator.clipboard.writeText('<?php echo $bank['iban']; ?>'); alert('IBAN Kopyalandı!');" 
                                                        title="Kopyala">
                                                    <i class="fa fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if($parent['type'] == 'debt'): ?>
                                <div class="alert alert-warning small py-2"><i class="fa fa-exclamation-triangle"></i> Bu carinin kayıtlı banka hesabı bulunamadı.</div>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card shadow border-0">
                    <div class="card-header <?php echo $bg_class; ?> d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="fa fa-wallet"></i> <?php echo $page_title; ?></span>
                        <a href="payment-orders.php" class="btn btn-sm btn-light text-dark"><i class="fa fa-times"></i> İptal</a>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if(!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="childForm">
                            <input type="hidden" name="next_url" id="next_url" value="">
                            
                            <input type="hidden" id="parent_rate" value="<?php echo $parent_rate; ?>">
                            <input type="hidden" id="parent_currency" value="<?php echo $parent_currency; ?>">
                            <input type="hidden" id="parent_type" value="<?php echo $parent['type']; ?>"> <input type="hidden" id="customer_id" value="<?php echo $parent['customer_id']; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-bold">İşlem Tarihi</label>
                                <input type="date" name="date" id="date" class="form-control form-control-lg" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="card bg-light border-0 mb-3">
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Para Birimi</label>
                                            <select name="currency" id="currency" class="form-select" onchange="updateRate()">
                                                <option value="TRY" <?php echo ($parent['currency']=='TRY')?'selected':''; ?>>TRY (₺)</option>
                                                <option value="USD" <?php echo ($parent['currency']=='USD')?'selected':''; ?>>USD ($)</option>
                                                <option value="EUR" <?php echo ($parent['currency']=='EUR')?'selected':''; ?>>EUR (€)</option>
                                                <option value="GBP" <?php echo ($parent['currency']=='GBP')?'selected':''; ?>>GBP (£)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold"><?php echo $amount_label; ?></label>
                                            <input type="number" step="0.01" name="pay_amount" id="pay_amount" class="form-control fw-bold" placeholder="0.00" required oninput="calcBase()">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Kur</label>
                                            <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate" class="form-control" value="1.0000" readonly oninput="calcBase()">
                                        </div>
                                        <div class="col-12 text-end mt-2">
                                            <small class="text-muted">Bakiyeden Düşecek Tutar: <span id="calc_result" class="fw-bold text-dark">0.00 TL</span></small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold text-primary"><?php echo $channel_label; ?></label>
                                <select name="channel_id" id="channel_id" class="form-select form-select-lg" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($channels as $ch): ?>
                                        <option value="<?php echo $ch['id']; ?>"><?php echo guvenli_html($ch['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Belge Türü</label>
                                    <select name="document_type" class="form-select" required>
                                        <option value="">Seçiniz...</option>
                                        <?php foreach($doc_options as $opt): ?>
                                            <option value="<?php echo $opt; ?>"><?php echo $opt; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Belge Dosyası</label>
                                    <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Açıklama (Opsiyonel)</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Örn: 1. Taksit ödemesi..."></textarea>
                            </div>
                            
                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-success btn-lg shadow-sm">
                                    <i class="fa fa-check-circle"></i> İşlemi Kaydet ve Tamamla
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            // Select2 Başlatma
            $('#channel_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Yazarak arayın veya seçin...'
            });
            
            // Eğer Ana işlem dövizli ise formu ona göre hazırla
            var pCurr = $('#parent_currency').val();
            if(pCurr !== 'TRY') {
                $('#currency').val(pCurr).trigger('change');
            }
        });

        function updateRate() {
            var currency = document.getElementById('currency').value;
            var rateInput = document.getElementById('exchange_rate');
            if (currency === 'TRY') { rateInput.value = 1.0000; rateInput.readOnly = true; calcBase(); } 
            else { 
                rateInput.readOnly = false; rateInput.placeholder = "Kur Alınıyor...";
                $.get('api-get-currency-rate.php?code='+currency, function(d){ 
                    if(d.status === 'success') { rateInput.value = d.rate; calcBase(); } 
                }, 'json'); 
            }
        }
        function calcBase() {
            var amt = parseFloat(document.getElementById('pay_amount').value) || 0;
            var rate = parseFloat(document.getElementById('exchange_rate').value) || 1;
            var total = amt * rate;
            document.getElementById('calc_result').innerText = total.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' TL';
        }
        
        window.onload = function() {
            updateRate();
        };

        // --- KUR FARKI HESAPLAMA VE YÖNLENDİRME (JS) ---
        $('#childForm').on('submit', function(e) {
            e.preventDefault(); // Formu durdur
            
            var parentCurr = $('#parent_currency').val();
            var payCurr = $('#currency').val();
            var shouldRedirect = false;
            
            // Kur farkı sadece Dövizli işlemlerde ve aynı kur tipindeyse hesaplanır
            if (parentCurr !== 'TRY' && parentCurr === payCurr) {
                var oldRate = parseFloat($('#parent_rate').val()) || 1;
                var newRate = parseFloat($('#exchange_rate').val()) || 1;
                var payAmount = parseFloat($('#pay_amount').val()) || 0;
                var parentType = $('#parent_type').val(); // debt veya credit

                // Fark Hesapla
                var diffTotal = (newRate - oldRate) * payAmount;
                
                // Fark 0.01'den büyükse sor (Eksi veya Artı)
                if (Math.abs(diffTotal) > 0.01) {
                    var title = "";
                    var text = "";
                    var confirmBtn = "";
                    var redirectUrl = "";
                    var amountStr = Math.abs(diffTotal).toFixed(2);
                    var custId = $('#customer_id').val();
                    var dateVal = $('#date').val();

                    // SENARYO 1: GELİR (TAHSİLAT)
                    if (parentType === 'credit') {
                        if (diffTotal > 0) {
                            // Kar -> Fatura Kesilecek
                            title = "Kur Farkı Geliri: " + diffTotal.toLocaleString('tr-TR') + " TL";
                            text = "Lehinize kur farkı oluştu. Kur farkı faturası kesmek ister misiniz?";
                            confirmBtn = "Evet, Fatura Kes";
                            redirectUrl = "transaction-add.php?doc_type=invoice_order&desc=" + encodeURIComponent("Kur Farkı Faturası (Gelir)") + "&amount=" + amountStr + "&customer_id=" + custId + "&date=" + dateVal;
                        } else {
                            // Zarar -> Fatura Alınacak
                            title = "Kur Farkı Gideri: " + Math.abs(diffTotal).toLocaleString('tr-TR') + " TL";
                            text = "Aleyhinize kur farkı oluştu. Karşı taraftan fatura isteyip sisteme girmek ister misiniz?";
                            confirmBtn = "Evet, Fatura Girişi Yap";
                            redirectUrl = "transaction-add.php?doc_type=payment_order&desc=" + encodeURIComponent("Kur Farkı Faturası (Gider)") + "&amount=" + amountStr + "&customer_id=" + custId + "&date=" + dateVal;
                        }
                    }
                    // SENARYO 2: GİDER (ÖDEME)
                    else if (parentType === 'debt') {
                        if (diffTotal > 0) {
                            // Zarar (Daha çok TL çıktı) -> Fatura Alınacak
                            title = "Kur Farkı Gideri: " + diffTotal.toLocaleString('tr-TR') + " TL";
                            text = "Aleyhinize kur farkı oluştu. Karşı taraftan fatura isteyip sisteme girmek ister misiniz?";
                            confirmBtn = "Evet, Fatura Girişi Yap";
                            redirectUrl = "transaction-add.php?doc_type=payment_order&desc=" + encodeURIComponent("Kur Farkı Faturası (Gelen)") + "&amount=" + amountStr + "&customer_id=" + custId + "&date=" + dateVal;
                        } else {
                            // Kar (Daha az TL çıktı) -> Fatura Kesilecek
                            title = "Kur Farkı Geliri: " + Math.abs(diffTotal).toLocaleString('tr-TR') + " TL";
                            text = "Lehinize kur farkı oluştu. Kur farkı faturası kesmek ister misiniz?";
                            confirmBtn = "Evet, Fatura Kes";
                            redirectUrl = "transaction-add.php?doc_type=invoice_order&desc=" + encodeURIComponent("Kur Farkı Faturası (Gelir)") + "&amount=" + amountStr + "&customer_id=" + custId + "&date=" + dateVal;
                        }
                    }

                    // SweetAlert ile Sor
                    Swal.fire({
                        title: title,
                        text: text,
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: confirmBtn,
                        cancelButtonText: 'Hayır, Sadece Kaydet'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Evet dedi -> URL'yi inputa yaz ve submit et
                            $('#next_url').val(redirectUrl);
                        } else {
                            // Hayır dedi -> URL boş kalsın
                            $('#next_url').val('');
                        }
                        // Her türlü formu gönder (PHP kaydedecek)
                        document.getElementById('childForm').submit();
                    });
                    return; // Fonksiyonu burada kes, SweetAlert cevabını bekliyor
                }
            }

            // Fark yoksa direkt gönder
            document.getElementById('childForm').submit();
        });
    </script>
</body>
</html>