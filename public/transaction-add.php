<?php
// public/transaction-add.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- VERİLERİ ÇEK ---
// 1. Departmanlar
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
// 2. Tur Kodları
$tours = $pdo->query("SELECT * FROM tour_codes WHERE status = 'active' ORDER BY code DESC")->fetchAll(PDO::FETCH_ASSOC);
// 3. Ödeme Yöntemleri (Gider için)
$methods = $pdo->query("SELECT * FROM payment_methods ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
// 4. Tahsilat Kanalları (Gelir için) - YENİ EKLENDİ
$coll_channels = $pdo->query("SELECT * FROM collection_channels ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Form Verileri
        $doc_type = $_POST['doc_type']; // payment_order (Gider) veya invoice_order (Gelir)
        $date = $_POST['date'];
        
        $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;
        if (!$customer_id) throw new Exception("Lütfen bir Cari Hesap seçiniz.");

        $tour_code_id = !empty($_POST['tour_code_id']) ? $_POST['tour_code_id'] : null;
        $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;

        // Tutar İşlemleri
        $amount = (float)$_POST['amount'];
        $currency = $_POST['currency'];
        $exchange_rate = (float)$_POST['exchange_rate'];
        $original_amount = ($currency === 'TRY') ? 0 : $amount;
        
        if ($currency !== 'TRY') {
            $tl_karsiligi = $amount * $exchange_rate;
            $original_amount = $amount;
            $amount = $tl_karsiligi;
        }

        $description = temizle($_POST['description']);
        $invoice_no = temizle($_POST['invoice_no']);
        $invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $invoice_status = isset($_POST['invoice_check']) ? 'pending' : 'issued';
        
        // DEĞİŞKEN ALANLAR
        $recipient_bank_id = null;
        $payment_method_id = null;
        $collection_channel_id = null;

        if ($doc_type === 'payment_order') {
            // ÖDEME İSE: Yöntem ve Banka Kaydet
            $recipient_bank_id = !empty($_POST['bank_id']) ? $_POST['bank_id'] : null;
            $payment_method_id = !empty($_POST['payment_method_id']) ? $_POST['payment_method_id'] : null;
        } else {
            // TAHSİLAT İSE: Tahsilat Kanalı Kaydet
            $collection_channel_id = !empty($_POST['collection_channel_id']) ? $_POST['collection_channel_id'] : null;
        }

        // İşlem Tipi (Borç/Alacak)
        $type = ($doc_type === 'payment_order') ? 'debt' : 'credit';

        // KAYIT
        // Not: Veritabanında collection_channel_id sütunu ekli olmalıdır (Önceki adımda eklemiştik)
        $sql = "INSERT INTO transactions (
                    customer_id, department_id, tour_code_id, type, doc_type, 
                    amount, currency, original_amount, exchange_rate, 
                    date, invoice_date, due_date, invoice_no, description, 
                    payment_status, invoice_status, 
                    recipient_bank_id, payment_method_id, collection_channel_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $customer_id, $department_id, $tour_code_id, $type, $doc_type,
            $amount, $currency, $original_amount, $exchange_rate,
            $date, $invoice_date, $due_date, $invoice_no, $description,
            $invoice_status, 
            $recipient_bank_id, $payment_method_id, $collection_channel_id
        ]);

        $last_id = $pdo->lastInsertId();
        log_action($pdo, 'transaction', $last_id, 'create', "Yeni işlem oluşturuldu: $description ($amount TL)");

        header("Location: payment-orders.php?msg=added");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yeni İşlem Ekle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    
    <style>
        body { background-color: #f4f6f9; }
        .form-section { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .section-title { font-size: 1.1rem; font-weight: bold; color: #495057; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="fa fa-plus-circle text-primary"></i> Yeni Finansal İşlem</h2>
            <a href="payment-orders.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Listeye Dön</a>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="row">
                
                <div class="col-md-8">
                    
                    <div class="form-section">
                        <h6 class="section-title text-primary">İşlem Türü</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Belge Tipi</label>
                                <select name="doc_type" id="doc_type" class="form-select form-select-lg" onchange="toggleFields()">
                                    <option value="payment_order">Ödeme Emri (Gider)</option>
                                    <option value="invoice_order">Fatura / Tahsilat (Gelir)</option>
                                </select>
                                <div id="type_hint" class="form-text text-danger fw-bold mt-1">
                                    <i class="fa fa-arrow-circle-up"></i> Bu işlem KASA ÇIKIŞI (Ödeme) anlamına gelir.
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">İşlem Tarihi</label>
                                <input type="date" name="date" class="form-control form-control-lg" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6 class="section-title">Cari & Proje Bilgileri</h6>
                        <div class="mb-3">
                            <label class="form-label">Cari Hesap <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select name="customer_id" id="customer_id" class="form-select select2" onchange="fetchCustomerDetails()" required>
                                    <option value="">Seçiniz...</option>
                                </select>
                                <button type="button" class="btn btn-outline-primary" onclick="window.open('customers.php', '_blank')"><i class="fa fa-plus"></i></button>
                            </div>
                            <div id="recent_transactions"></div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tur Kodu / Proje</label>
                                <select name="tour_code_id" class="form-select select2">
                                    <option value="">Genel / Projesiz</option>
                                    <?php foreach($tours as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo $t['code'] . ' - ' . $t['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Departman</label>
                                <select name="department_id" class="form-select">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($departments as $d): ?>
                                        <option value="<?php echo $d['id']; ?>"><?php echo $d['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6 class="section-title">Tutar ve Döviz</h6>
                        <div class="row align-items-end">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tutar</label>
                                <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-lg fw-bold" placeholder="0.00" required oninput="calcTL()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Para Birimi</label>
                                <select name="currency" id="currency" class="form-select form-select-lg" onchange="updateRate()">
                                    <option value="TRY">TRY (₺)</option>
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (€)</option>
                                    <option value="GBP">GBP (£)</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label small">Kur</label>
                                <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate" class="form-control" value="1.0000" readonly oninput="calcTL()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label small">TL Karşılığı</label>
                                <input type="text" id="amount_tl_display" class="form-control bg-light fw-bold text-end" readonly>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="col-md-4">
                    
                    <div class="form-section bg-light border-primary border-top border-3">
                        <h6 class="section-title">Finansal Detaylar</h6>
                        
                        <div id="payment_details_group">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Ödeme Yöntemi</label>
                                <select name="payment_method_id" class="form-select">
                                    <option value="">Seçiniz (Nakit, Havale vb.)</option>
                                    <?php foreach($methods as $m): ?>
                                        <option value="<?php echo $m['id']; ?>"><?php echo $m['title']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Alıcı Banka Hesabı</label>
                                <div class="input-group">
                                    <select name="bank_id" id="bank_id" class="form-select">
                                        <option value="">Önce Cari Seçiniz...</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-secondary" onclick="openBankModal()"><i class="fa fa-plus"></i></button>
                                </div>
                                <div class="form-text small">Müşterinin banka bilgisi</div>
                            </div>
                        </div>

                        <div id="collection_details_group" class="d-none">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-success">Hedef Tahsilat Kanalı</label>
                                <select name="collection_channel_id" class="form-select border-success">
                                    <option value="">Planlanan Kanal Seçiniz...</option>
                                    <?php foreach($coll_channels as $ch): ?>
                                        <option value="<?php echo $ch['id']; ?>"><?php echo $ch['title']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">Paranın girmesini planladığınız hesap.</div>
                            </div>
                        </div>

                    </div>

                    <div class="form-section">
                        <h6 class="section-title">Fatura & Vade</h6>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="invoice_check" name="invoice_check" onchange="toggleInvoiceDate()">
                            <label class="form-check-label text-danger fw-bold" for="invoice_check">Faturası Henüz Kesilmedi</label>
                        </div>

                        <div id="invoice_date_div">
                            <div class="mb-2">
                                <label class="form-label">Fatura No</label>
                                <input type="text" name="invoice_no" class="form-control">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Fatura Tarihi</label>
                                <input type="date" name="invoice_date" class="form-control">
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label">Vade Tarihi</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Açıklama</label>
                        <textarea name="description" class="form-control" rows="4"></textarea>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg shadow">
                            <i class="fa fa-save"></i> İşlemi Kaydet
                        </button>
                    </div>

                </div>
            </div>
        </form>
    </div>

    <div class="modal fade" id="addBankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Yeni Banka Hesabı Ekle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modal_customer_id">
                    <div class="mb-2"><label>Banka Adı</label><input type="text" id="modal_bank_name" class="form-control"></div>
                    <div class="mb-2"><label>IBAN</label><input type="text" id="modal_iban" class="form-control"></div>
                    <div class="mb-2"><select id="modal_currency" class="form-select"><option value="TRY">TRY</option><option value="USD">USD</option><option value="EUR">EUR</option></select></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" onclick="saveBank()">Kaydet</button></div>
            </div>
        </div>
    </div>

    <?php $customers = $pdo->query("SELECT id, company_name FROM customers ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC); ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            $('.select2').select2({ theme: 'bootstrap-5', placeholder: "Seçiniz...", allowClear: true });
            
            // Carileri doldur
            var customers = <?php echo json_encode($customers); ?>;
            var customerSelect = $('#customer_id');
            $.each(customers, function(i, c) { customerSelect.append(new Option(c.company_name, c.id)); });
            
            toggleFields(); // Başlangıç durumu için
        });

        // TARİH UYARISI
        $('input[name="date"]').on('change', function() {
            if(new Date($(this).val()) < new Date().setHours(0,0,0,0)) {
                Swal.fire({icon: 'warning', title: 'Dikkat', text: 'Geçmişe dönük işlem giriyorsunuz.'});
            }
        });

        // GİZLE / GÖSTER MANTIĞI
        function toggleFields() {
            var type = document.getElementById('doc_type').value;
            var hint = document.getElementById('type_hint');
            
            var payDiv = document.getElementById('payment_details_group');
            var collDiv = document.getElementById('collection_details_group');

            if (type === 'payment_order') {
                // ÖDEME EMRİ
                hint.innerHTML = '<i class="fa fa-arrow-circle-up"></i> Bu işlem KASA ÇIKIŞI (Ödeme) anlamına gelir.';
                hint.className = 'form-text text-danger fw-bold mt-1';
                
                payDiv.classList.remove('d-none'); // Ödeme Yöntemi GÖSTER
                collDiv.classList.add('d-none');   // Tahsilat Kanalı GİZLE
            } else {
                // FATURA / TAHSİLAT
                hint.innerHTML = '<i class="fa fa-arrow-circle-down"></i> Bu işlem KASA GİRİŞİ (Tahsilat) anlamına gelir.';
                hint.className = 'form-text text-success fw-bold mt-1';
                
                payDiv.classList.add('d-none');    // Ödeme Yöntemi GİZLE
                collDiv.classList.remove('d-none'); // Tahsilat Kanalı GÖSTER
            }
        }

        // ... (Diğer fonksiyonlar: updateRate, calcTL, fetchCustomerDetails, openBankModal aynı kalacak) ...
        function updateRate() {
            var currency = document.getElementById('currency').value;
            var rateInput = document.getElementById('exchange_rate');
            if (currency === 'TRY') { rateInput.value = 1.0000; rateInput.readOnly = true; calcTL(); } 
            else { rateInput.readOnly = false; rateInput.placeholder = "..."; $.get('api-get-currency-rate.php?code='+currency, function(d){ if(d.status==='success') {rateInput.value=d.rate; calcTL();} }, 'json'); }
        }
        function calcTL() {
            var amt = parseFloat(document.getElementById('amount').value)||0; 
            var rate = parseFloat(document.getElementById('exchange_rate').value)||1;
            document.getElementById('amount_tl_display').value = (amt*rate).toLocaleString('tr-TR',{minimumFractionDigits:2}) + ' ₺';
        }
        function fetchCustomerDetails() {
            var id = $('#customer_id').val(); if(!id) return;
            $('#modal_customer_id').val(id);
            $.get('api-get-customer-details.php?id='+id, function(data){
                var bs = $('#bank_id').empty().append('<option value="">Seçiniz...</option>');
                $.each(data.banks, function(i,b){ bs.append('<option value="'+b.id+'">'+b.bank_name+' - '+b.iban+'</option>'); });
                
                var balCol = data.balance < 0 ? 'text-danger' : 'text-success';
                var txt = parseFloat(data.balance).toLocaleString('tr-TR',{minimumFractionDigits:2}) + ' ' + data.currency;
                $('#recent_transactions').html('<div class="alert alert-light border shadow-sm p-2 mt-2"><h6><i class="fa fa-wallet"></i> Bakiye: <strong class="'+balCol+'">'+txt+'</strong></h6></div>');
            }, 'json');
        }
        function openBankModal(){ 
            if(!$('#customer_id').val()) { Swal.fire('Uyarı','Cari seçiniz.','warning'); return; }
            new bootstrap.Modal(document.getElementById('addBankModal')).show(); 
        }
        function saveBank(){
            $.post('api-add-bank.php', {
                customer_id:$('#modal_customer_id').val(), bank_name:$('#modal_bank_name').val(), iban:$('#modal_iban').val(), currency:$('#modal_currency').val()
            }, function(r){ if(r.status==='success'){ bootstrap.Modal.getInstance(document.getElementById('addBankModal')).hide(); fetchCustomerDetails(); Swal.fire('Başarılı','Banka eklendi!','success'); } }, 'json');
        }
        function toggleInvoiceDate(){
            var chk = document.getElementById('invoice_check').checked;
            document.getElementById('invoice_date_div').classList.toggle('d-none', !chk);
        }
    </script>
</body>
</html>