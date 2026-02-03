<?php
// public/transaction-edit.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) { 
    if($_SERVER['REQUEST_METHOD'] === 'POST') { echo json_encode(['status'=>'error', 'message'=>'Oturum kapalı.']); exit; }
    exit('<div class="alert alert-danger">Yetkisiz erişim.</div>'); 
}

$role = $_SESSION['role'] ?? 'user';
// Onaylı işlemi düzenleme yetkisi (Burayı kendi yetki sistemine göre ayarlayabilirsin)
$can_edit_approved = ($role === 'admin' || $role === 'muhasebe');

// --- GÜNCELLEME İŞLEMİ (AJAX POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0); 
    ini_set('display_errors', 0);

    try {
        $post_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        // 1. ESKİ VERİYİ ÇEK (Değişiklik kontrolü ve Güvenlik için)
        $stmt_old = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt_old->execute([$post_id]);
        $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_data) throw new Exception("İşlem bulunamadı.");

        // 2. ONAY KONTROLÜ (GÜVENLİK)
        if ($old_data['approval_status'] === 'approved' && !$can_edit_approved) {
            throw new Exception("Bu işlem onaylanmıştır. Düzenleme yetkiniz yok.");
        }

        // Verileri Al
        $date = $_POST['date'] ?? date('Y-m-d');
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $tour_code_id = !empty($_POST['tour_code_id']) ? (int)$_POST['tour_code_id'] : null;
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $doc_type = $_POST['doc_type_hidden'] ?? $old_data['doc_type']; 
        
        // Tutar ve Döviz Hesaplama
        $input_amount = (float)($_POST['amount'] ?? 0);
        $currency = $_POST['currency'] ?? 'TRY';
        $exchange_rate = (float)($_POST['exchange_rate'] ?? 1);
        
        if (trim($currency) === 'TRY') {
            $amount_tl = $input_amount;
            $original_amount = 0; 
            $exchange_rate = 1.0000;
        } else {
            $original_amount = $input_amount;
            $amount_tl = $input_amount * $exchange_rate;
        }

        $description = temizle($_POST['description'] ?? '');
        $recipient_bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
        $payment_channel_id = !empty($_POST['collection_channel_id']) ? (int)$_POST['collection_channel_id'] : null;
        
        // Fatura Bilgileri
        $invoice_no = temizle($_POST['invoice_no'] ?? '');
        $invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : null;
        
        // Fatura Durumu
        $invoice_status = $old_data['invoice_status'];
        if ($doc_type == 'payment_order') {
            $invoice_status = (!empty($invoice_no)) ? 'issued' : 'pending';
        } else {
            if (isset($_POST['issue_invoice_check'])) {
                $invoice_status = 'to_be_issued'; 
            } else {
                if ($invoice_status != 'issued') {
                    $invoice_status = 'waiting_approval';
                }
            }
        }

        // Dosya Yükleme
        $file_path = $old_data['file_path'];
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) throw new Exception("Geçersiz dosya formatı.");
            
            $upload_dir = '../storage/documents/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $filename = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $filename)) {
                $file_path = 'documents/' . $filename;
            }
        }

        // 3. GÜNCELLEME SORGUSU
        $sql = "UPDATE transactions SET 
                date=?, customer_id=?, tour_code_id=?, department_id=?, 
                amount=?, original_amount=?, currency=?, exchange_rate=?, description=?, 
                recipient_bank_id=?, collection_channel_id=?, 
                invoice_no=?, invoice_date=?, invoice_status=?, file_path=?
                WHERE id=?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $date, $customer_id, $tour_code_id, $department_id,
            $amount_tl, $original_amount, $currency, $exchange_rate, $description,
            $recipient_bank_id, $payment_channel_id,
            $invoice_no, $invoice_date, $invoice_status, $file_path,
            $post_id
        ]);

        // 4. DETAYLI LOG OLUŞTURMA (Diff Logic)
       $changes = [];

        if ($old_data['date'] != $date) 
            $changes[] = "Tarih: " . date('d.m.Y', strtotime($old_data['date'])) . " -> " . date('d.m.Y', strtotime($date));
        
        // Tutar
        if (abs((float)$old_data['amount'] - $amount_tl) > 0.01) 
            $changes[] = "Tutar: " . number_format($old_data['amount'], 2) . " -> " . number_format($amount_tl, 2);
        
        // Döviz
        if ($old_data['currency'] != $currency) 
            $changes[] = "Döviz: {$old_data['currency']} -> $currency";
        
        // --- CARİ İSİM LOGLAMA (GÜNCELLENDİ) ---
        if ($old_data['customer_id'] != $customer_id) {
            // Eski Cari İsmini Bul
            $old_name_stmt = $pdo->prepare("SELECT company_name FROM customers WHERE id = ?");
            $old_name_stmt->execute([$old_data['customer_id']]);
            $old_name = $old_name_stmt->fetchColumn() ?: 'Bilinmiyor';

            // Yeni Cari İsmini Bul
            $new_name_stmt = $pdo->prepare("SELECT company_name FROM customers WHERE id = ?");
            $new_name_stmt->execute([$customer_id]);
            $new_name = $new_name_stmt->fetchColumn() ?: 'Bilinmiyor';

            $changes[] = "Cari: $old_name -> $new_name";
        }
        
        // --- TUR KODU İSİM LOGLAMA (GÜNCELLENDİ) ---
        // Not: Tur kodu boş (NULL) olabilir, bunu kontrol etmeliyiz.
        if ($old_data['tour_code_id'] != $tour_code_id) {
            $old_code = 'Yok';
            if (!empty($old_data['tour_code_id'])) {
                $stmt_tc = $pdo->prepare("SELECT code FROM tour_codes WHERE id = ?");
                $stmt_tc->execute([$old_data['tour_code_id']]);
                $old_code = $stmt_tc->fetchColumn() ?: 'Silinmiş';
            }

            $new_code = 'Yok';
            if (!empty($tour_code_id)) {
                $stmt_tc = $pdo->prepare("SELECT code FROM tour_codes WHERE id = ?");
                $stmt_tc->execute([$tour_code_id]);
                $new_code = $stmt_tc->fetchColumn() ?: 'Bilinmiyor';
            }

            $changes[] = "Tur Kodu: $old_code -> $new_code";
        }
            
        if ($old_data['description'] != $description) 
            $changes[] = "Açıklama Güncellendi";

        if ($old_data['invoice_no'] != $invoice_no) 
            $changes[] = "Fatura No: '{$old_data['invoice_no']}' -> '$invoice_no'";

        $log_msg = empty($changes) ? "Kayıt güncellendi (Detay yok)" : "Düzenlendi: " . implode(', ', $changes);
        
        // Logu Kaydet
        log_action($pdo, 'transaction', $post_id, 'update', $log_msg);

        ob_end_clean();
        echo json_encode(['status' => 'success', 'message' => 'Kayıt başarıyla güncellendi.']);
        exit;

    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- FORM GÖSTERİMİ (GET) ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$t) exit('<div class="alert alert-danger">Kayıt bulunamadı.</div>');

// Onaylı İşlem Kontrolü (Formu göstermeden önce)
if ($t['approval_status'] === 'approved' && !$can_edit_approved) {
    exit('<div class="alert alert-warning text-center p-4">
            <i class="fa fa-lock fa-3x mb-3 text-warning"></i><br>
            <strong>Bu işlem onaylanmıştır.</strong><br>
            Onaylanan işlemler üzerinde değişiklik yapılamaz.<br>
            <small class="text-muted">Değişiklik için yöneticinizle görüşün.</small>
          </div>');
}

$customers = $pdo->query("SELECT id, company_name FROM customers ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, code FROM tour_codes WHERE status='active' ORDER BY code DESC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$collection_channels = $pdo->query("SELECT id, title FROM collection_channels ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

$customer_banks = [];
if ($t['customer_id']) {
    try {
        $cb_stmt = $pdo->prepare("SELECT * FROM customer_banks WHERE customer_id = ?"); 
        $cb_stmt->execute([$t['customer_id']]);
        $customer_banks = $cb_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) { $customer_banks = []; }
}

$db_currency = trim($t['currency']);
$display_amount = ($db_currency === 'TRY') ? $t['amount'] : $t['original_amount'];
$display_rate = ($t['exchange_rate'] > 0) ? $t['exchange_rate'] : 1.0000;
?>

<form id="editForm" enctype="multipart/form-data" onsubmit="return updateTransaction(event)">
    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
    <input type="hidden" name="doc_type_hidden" id="edit_doc_type_hidden" value="<?php echo $t['doc_type']; ?>">

    <div class="row mb-3">
        <div class="col-md-4">
            <label class="form-label">Tarih</label>
            <input type="date" name="date" class="form-control" value="<?php echo $t['date']; ?>" required>
        </div>
        <div class="col-md-8">
            <label class="form-label">Cari / Firma</label>
            <select name="customer_id" class="form-select" required>
                <?php foreach($customers as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $t['customer_id'] ? 'selected' : ''; ?>>
                        <?php echo guvenli_html($c['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <label class="form-label">Tur Kodu</label>
            <select name="tour_code_id" class="form-select">
                <option value="">Genel / Projesiz</option>
                <?php foreach($projects as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $p['id'] == $t['tour_code_id'] ? 'selected' : ''; ?>>
                        <?php echo $p['code']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Bölüm</label>
            <select name="department_id" class="form-select">
                <option value="">Seçiniz...</option>
                <?php foreach($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $d['id'] == $t['department_id'] ? 'selected' : ''; ?>>
                        <?php echo $d['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row mb-3 bg-light p-3 rounded border mx-0">
        <div class="col-md-4">
            <label class="form-label small fw-bold">Tutar</label>
            <input type="number" step="0.01" name="amount" id="edit_amount" class="form-control fw-bold" 
                   value="<?php echo (float)$display_amount; ?>" oninput="calcEditTL()" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Birim</label>
            <select name="currency" id="edit_currency" class="form-select" onchange="updateEditRate()">
                <option value="TRY" <?php echo $db_currency === 'TRY' ? 'selected' : ''; ?>>TRY</option>
                <option value="USD" <?php echo $db_currency === 'USD' ? 'selected' : ''; ?>>USD</option>
                <option value="EUR" <?php echo $db_currency === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                <option value="GBP" <?php echo $db_currency === 'GBP' ? 'selected' : ''; ?>>GBP</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Kur</label>
            <input type="number" step="0.0001" name="exchange_rate" id="edit_rate" class="form-control" 
                   value="<?php echo (float)$display_rate; ?>" oninput="calcEditTL()">
        </div>
        <div class="col-md-2 d-flex align-items-end justify-content-end">
            <span id="edit_tl_result" class="fw-bold text-success small mb-2"></span>
        </div>
    </div>

    <div class="row mb-3">
        <?php if($t['doc_type'] == 'payment_order'): ?>
            <div class="col-12">
                <label class="form-label text-danger">Alıcı Banka</label>
                <select name="bank_id" id="edit_bank_select" class="form-select">
                    <option value="">Seçiniz...</option>
                    <?php foreach($customer_banks as $cb): ?>
                        <option value="<?php echo $cb['id']; ?>" <?php echo $cb['id'] == $t['recipient_bank_id'] ? 'selected' : ''; ?>>
                            <?php echo $cb['bank_name'] . ' - ' . $cb['iban']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="col-12">
                <label class="form-label text-success">Tahsilat Kanalı</label>
                <select name="collection_channel_id" class="form-select">
                    <option value="">Seçiniz...</option>
                    <?php foreach($collection_channels as $cc): ?>
                        <option value="<?php echo $cc['id']; ?>" <?php echo $cc['id'] == $t['collection_channel_id'] ? 'selected' : ''; ?>>
                            <?php echo $cc['title']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <div class="mb-3 border p-3 rounded">
        <h6 class="text-muted border-bottom pb-2">Fatura & Belge Durumu</h6>
        <?php if($t['doc_type'] == 'payment_order'): ?>
            <div id="payment_invoice_fields">
                <div class="row mb-2">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Gelen Fatura No</label>
                        <input type="text" name="invoice_no" class="form-control" value="<?php echo guvenli_html($t['invoice_no']); ?>" placeholder="Fatura No Giriniz">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fatura Tarihi</label>
                        <input type="date" name="invoice_date" class="form-control" value="<?php echo $t['invoice_date']; ?>">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Belge / Fatura Dosyası</label>
                    <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                    <?php if(!empty($t['file_path'])): ?>
                        <div class="mt-1">
                            <a href="../storage/<?php echo $t['file_path']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fa fa-file"></i> Mevcut Dosyayı Gör
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div id="collection_invoice_fields">
                <div class="alert alert-warning mb-0">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="issue_invoice_check" name="issue_invoice_check" 
                            <?php echo ($t['invoice_status'] == 'to_be_issued' || $t['invoice_status'] == 'issued') ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="issue_invoice_check">Fatura Kesilsin</label>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label">Açıklama</label>
        <textarea name="description" class="form-control" rows="2"><?php echo guvenli_html($t['description']); ?></textarea>
    </div>

    <div class="modal-footer px-0 pb-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
        <button type="submit" class="btn btn-primary" id="btnSave">Değişiklikleri Kaydet</button>
    </div>
</form>

<script>
    function calcEditTL() {
        var amt = parseFloat(document.getElementById('edit_amount').value) || 0;
        var rate = parseFloat(document.getElementById('edit_rate').value) || 1;
        var tl = amt * rate;
        document.getElementById('edit_tl_result').innerText = tl.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺';
    }

    function updateEditRate() {
        var currency = document.getElementById('edit_currency').value;
        var rateInput = document.getElementById('edit_rate');
        
        if (currency === 'TRY') { 
            rateInput.value = 1.0000; 
            rateInput.readOnly = true; 
            calcEditTL(); 
        } else { 
            rateInput.readOnly = false; 
            rateInput.placeholder = "Yükleniyor...";
            
            $.get('api-get-currency-rate.php?code='+currency, function(d){ 
                if(d && d.status === 'success') {
                    rateInput.value = d.rate; 
                    calcEditTL();
                } 
            }, 'json'); 
        }
    }

    function updateTransaction(e) {
        e.preventDefault();
        var form = document.getElementById('editForm');
        var formData = new FormData(form);
        var btn = document.getElementById('btnSave');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Kaydediliyor...';

        $.ajax({
            url: 'transaction-edit.php', 
            type: 'POST',
            data: formData,
            contentType: false, 
            processData: false, 
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    var modalEl = document.getElementById('editModal');
                    var modal = bootstrap.Modal.getInstance(modalEl);
                    modal.hide();
                    
                    // Ana Tabloyu Yenile (Nereden açıldığına bakarak)
                    if(window.parent && window.parent.jQuery && window.parent.jQuery('#paymentTable').length) {
                        window.parent.jQuery('#paymentTable').DataTable().ajax.reload(null, false); 
                    } else if ($('#paymentTable').length) {
                        $('#paymentTable').DataTable().ajax.reload(null, false);
                    } else if ($('#approvalTable').length) { // Onay sayfasından açıldıysa orayı da yenile
                        $('#approvalTable').DataTable().ajax.reload(null, false);
                    }
                    
                    Swal.fire({ icon: 'success', title: 'Başarılı', text: res.message, timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            },
            error: function(xhr) {
                console.error(xhr.responseText);
                Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error');
            },
            complete: function() {
                btn.disabled = false;
                btn.innerHTML = 'Değişiklikleri Kaydet';
            }
        });
        return false;
    }
    
    // Açılışta hesapla
    calcEditTL();
</script>