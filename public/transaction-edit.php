<?php
// public/transaction-edit.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) exit('<div class="alert alert-danger">Yetkisiz erişim.</div>');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- GÜNCELLEME İŞLEMİ (KAYDET BUTONUNA BASILINCA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $id = (int)$_POST['id'];
        
        // Eski veriyi al (Log için)
        $old_stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
        $old_stmt->execute([$id]);
        $old_data = $old_stmt->fetch();

        // Verileri Al
        $date = $_POST['date'];
        $customer_id = (int)$_POST['customer_id'];
        $tour_code_id = !empty($_POST['tour_code_id']) ? (int)$_POST['tour_code_id'] : null;
        $department_id = (int)$_POST['department_id'];
        
        // --- DÖVİZ HESAPLAMA (ÖNEMLİ DEĞİŞİKLİK) ---
        $original_amount = (float)$_POST['amount']; // Kullanıcının girdiği (Örn: 100)
        $currency = $_POST['currency'];
        $exchange_rate = (float)$_POST['exchange_rate'];
        
        // Ana tutarı her zaman TL olarak kaydediyoruz
        $amount_tl = $original_amount * $exchange_rate; 

        $description = temizle($_POST['description']);
        
        // Banka veya Kasa Seçimi
        $recipient_bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
        $payment_channel_id = !empty($_POST['collection_channel_id']) ? (int)$_POST['collection_channel_id'] : null;

        $invoice_no = temizle($_POST['invoice_no']);
        $invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : null;
        
        // Fatura durumu
        $invoice_status = ($invoice_no || $invoice_date) ? 'issued' : 'pending';

        // GÜNCELLEME SORGUSU (original_amount EKLENDİ)
        $sql = "UPDATE transactions SET 
                date=?, customer_id=?, tour_code_id=?, department_id=?, 
                amount=?, original_amount=?, currency=?, exchange_rate=?, description=?, 
                recipient_bank_id=?, payment_channel_id=?, 
                invoice_no=?, invoice_date=?, invoice_status=?
                WHERE id=?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $date, $customer_id, $tour_code_id, $department_id,
            $amount_tl, $original_amount, $currency, $exchange_rate, $description,
            $recipient_bank_id, $payment_channel_id,
            $invoice_no, $invoice_date, $invoice_status,
            $id
        ]);

        log_action($pdo, 'transaction', $id, 'update', "İşlem güncellendi. Eski Tutar: {$old_data['original_amount']} {$old_data['currency']}, Yeni: $original_amount $currency");

        echo json_encode(['status' => 'success', 'message' => 'Kayıt başarıyla güncellendi.']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- FORM GÖSTERİMİ (POPUP AÇILINCA) ---
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$id]);
$t = $stmt->fetch();

if (!$t) exit('<div class="alert alert-danger">Kayıt bulunamadı.</div>');

// Listeleri Çek
$customers = $pdo->query("SELECT * FROM customers ORDER BY company_name")->fetchAll();
$projects = $pdo->query("SELECT * FROM tour_codes WHERE status='active' ORDER BY code DESC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$collection_channels = $pdo->query("SELECT * FROM collection_channels ORDER BY title")->fetchAll();

// Cari Bankalarını Çek
$customer_banks = [];
if ($t['customer_id']) {
    $cb_stmt = $pdo->prepare("SELECT * FROM customer_banks WHERE customer_id = ?");
    $cb_stmt->execute([$t['customer_id']]);
    $customer_banks = $cb_stmt->fetchAll();
}
?>

<form id="editForm" onsubmit="return updateTransaction(event)">
    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
    <input type="hidden" name="doc_type" id="edit_doc_type" value="<?php echo $t['doc_type']; ?>">

    <div class="row mb-3">
        <div class="col-md-4">
            <label class="form-label">Tarih</label>
            <input type="date" name="date" class="form-control" value="<?php echo $t['date']; ?>" required>
        </div>
        <div class="col-md-8">
            <label class="form-label">Cari / Firma</label>
            <select name="customer_id" class="form-select" required onchange="fetchEditBanks(this.value)">
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
                <option value="">Genel</option>
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
                <?php foreach($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $d['id'] == $t['department_id'] ? 'selected' : ''; ?>>
                        <?php echo $d['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row mb-3 bg-light p-2 rounded border mx-1">
        <div class="col-md-4">
            <label class="form-label">Tutar</label>
            <input type="number" step="0.01" name="amount" id="edit_amount" class="form-control fw-bold" value="<?php echo $t['original_amount']; ?>" oninput="calcEditTL()">
        </div>
        <div class="col-md-3">
            <label class="form-label">Birim</label>
            <select name="currency" id="edit_currency" class="form-select" onchange="calcEditTL()">
                <option value="TRY" <?php echo $t['currency'] == 'TRY' ? 'selected' : ''; ?>>TRY</option>
                <option value="USD" <?php echo $t['currency'] == 'USD' ? 'selected' : ''; ?>>USD</option>
                <option value="EUR" <?php echo $t['currency'] == 'EUR' ? 'selected' : ''; ?>>EUR</option>
                <option value="GBP" <?php echo $t['currency'] == 'GBP' ? 'selected' : ''; ?>>GBP</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Kur</label>
            <input type="number" step="0.0001" name="exchange_rate" id="edit_rate" class="form-control" value="<?php echo $t['exchange_rate']; ?>" oninput="calcEditTL()">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <span id="edit_tl_result" class="fw-bold text-muted small"></span>
        </div>
    </div>

    <div class="row mb-3">
        <?php if($t['doc_type'] == 'payment_order'): ?>
            <div class="col-12">
                <label class="form-label">Alıcı Banka</label>
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
                <label class="form-label">Tahsilat Kanalı</label>
                <select name="collection_channel_id" class="form-select">
                    <option value="">Seçiniz...</option>
                    <?php foreach($collection_channels as $cc): ?>
                        <option value="<?php echo $cc['id']; ?>" <?php echo $cc['id'] == $t['payment_channel_id'] ? 'selected' : ''; ?>>
                            <?php echo $cc['title']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <label class="form-label">Fatura No</label>
            <input type="text" name="invoice_no" class="form-control" value="<?php echo guvenli_html($t['invoice_no']); ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Fatura Tarihi</label>
            <input type="date" name="invoice_date" class="form-control" value="<?php echo $t['invoice_date']; ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">Açıklama</label>
        <textarea name="description" class="form-control" rows="2"><?php echo guvenli_html($t['description']); ?></textarea>
    </div>

    <div class="d-flex justify-content-end">
        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">İptal</button>
        <button type="submit" class="btn btn-primary">Değişiklikleri Kaydet</button>
    </div>
</form>

<script>
    function calcEditTL() {
        var amt = parseFloat(document.getElementById('edit_amount').value) || 0;
        var rate = parseFloat(document.getElementById('edit_rate').value) || 1;
        var tl = amt * rate;
        document.getElementById('edit_tl_result').innerText = tl.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺';
    }
    
    function fetchEditBanks(customerId) {
        if(document.getElementById('edit_doc_type').value !== 'payment_order') return;
        var bankSelect = $('#edit_bank_select');
        bankSelect.html('<option>Yükleniyor...</option>');
        
        $.get('api-get-customer-details.php?id=' + customerId, function(data) {
            bankSelect.empty().append('<option value="">Seçiniz...</option>');
            if(data.banks.length > 0) {
                $.each(data.banks, function(i, b) {
                    bankSelect.append('<option value="'+b.id+'">'+b.bank_name+' - '+b.iban+'</option>');
                });
            } else {
                bankSelect.append('<option value="">Kayıtlı banka yok</option>');
            }
        }, 'json');
    }

    function updateTransaction(e) {
        e.preventDefault();
        var formData = $('#editForm').serialize();

        $.post('transaction-edit.php', formData, function(res) {
            if(res.status === 'success') {
                var modalEl = document.getElementById('editModal');
                var modal = bootstrap.Modal.getInstance(modalEl);
                modal.hide();
                $('#paymentTable').DataTable().ajax.reload(null, false);
                Swal.fire({ icon: 'success', title: 'Güncellendi', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        }, 'json');
        return false;
    }
    calcEditTL();
</script>