<?php
// public/transaction-add.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- VERİLERİ ÇEK ---
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$tours = $pdo->query("SELECT * FROM tour_codes WHERE status = 'active' ORDER BY code DESC")->fetchAll(PDO::FETCH_ASSOC);
$methods = $pdo->query("SELECT * FROM payment_methods ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$coll_channels = $pdo->query("SELECT * FROM collection_channels ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Form Verileri
        $doc_type = $_POST['doc_type']; 
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
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        
        // --- FATURA DURUM MANTIĞI ---
        $invoice_no = null;
        $invoice_date = null;
        $invoice_status = 'pending';

        if ($doc_type === 'payment_order') {
            // GİDER
            $invoice_no = !empty($_POST['invoice_no']) ? temizle($_POST['invoice_no']) : null;
            $invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : null;
            $invoice_status = ($invoice_no) ? 'issued' : 'pending';
        } else {
            // GELİR
            if (isset($_POST['issue_invoice_check'])) {
                $invoice_status = 'to_be_issued'; 
            } else {
                $invoice_status = 'waiting_approval';
            }
        }

        // --- DOSYA YÜKLEME ---
        $file_path = null;
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
        
        $recipient_bank_id = null;
        $payment_method_id = null;
        $collection_channel_id = null;

        if ($doc_type === 'payment_order') {
            $recipient_bank_id = !empty($_POST['bank_id']) ? $_POST['bank_id'] : null;
            $payment_method_id = !empty($_POST['payment_method_id']) ? $_POST['payment_method_id'] : null;
        } else {
            $collection_channel_id = !empty($_POST['collection_channel_id']) ? $_POST['collection_channel_id'] : null;
        }

        $type = ($doc_type === 'payment_order') ? 'debt' : 'credit';
        $created_by = $_SESSION['user_id'];

        $sql = "INSERT INTO transactions (
                    customer_id, department_id, tour_code_id, type, doc_type, 
                    amount, currency, original_amount, exchange_rate, 
                    date, invoice_date, due_date, invoice_no, description, 
                    payment_status, invoice_status, 
                    recipient_bank_id, payment_method_id, collection_channel_id,
                    created_by, file_path
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $customer_id, $department_id, $tour_code_id, $type, $doc_type,
            $amount, $currency, $original_amount, $exchange_rate,
            $date, $invoice_date, $due_date, $invoice_no, $description,
            $invoice_status, 
            $recipient_bank_id, $payment_method_id, $collection_channel_id,
            $created_by, $file_path
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
        
        /* Bakiye Kartı Stili */
        .balance-card { border-radius: 10px; padding: 15px; color: white; margin-bottom: 20px; }
        .bg-gradient-success { background: linear-gradient(45deg, #198754, #20c997); }
        .bg-gradient-danger { background: linear-gradient(45deg, #dc3545, #ff6b6b); }
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

        <form method="POST" enctype="multipart/form-data">
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
                        <h6 class="section-title">Fatura</h6>
                        
                        <div id="payment_invoice_fields">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Gelen Fatura No</label>
                                <input type="text" name="invoice_no" class="form-control" placeholder="Varsa giriniz...">
                                <div class="form-text small">Boş bırakılırsa "Fatura Gelmedi" sayılır.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Fatura Tarihi</label>
                                <input type="date" name="invoice_date" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Fatura Görseli/PDF</label>
                                <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                            </div>
                        </div>

                        <div id="collection_invoice_fields" class="d-none">
                            <div class="alert alert-warning">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="issue_invoice_check" name="issue_invoice_check">
                                    <label class="form-check-label fw-bold" for="issue_invoice_check">
                                        Fatura Kesilsin
                                    </label>
                                </div>
                                <small class="d-block mt-2">
                                    <i class="fa fa-info-circle"></i> İşaretlenirse: "Fatura Kesilecek"<br>
                                    İşaretlenmezse: "Fatura Onayı Bekliyor"
                                </small>
                            </div>
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

        <div id="customer-history-section" class="mt-5 d-none">
            
            <hr class="border-3 border-dark my-4">
            
            <div id="balance-card-container"></div>

            <h4 class="mb-3 text-secondary"><i class="fa fa-history"></i> Seçilen Carinin Son İşlemleri</h4>
            
            <div class="card shadow">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Tarih</th>
                                    <th>Tür</th>
                                    <th>Açıklama</th>
                                    <th>Tur Kodu</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-end">Bakiye (Anlık)</th>
                                </tr>
                            </thead>
                            <tbody id="history-table-body">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

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
            var customers = <?php echo json_encode($customers); ?>;
            var customerSelect = $('#customer_id');
            $.each(customers, function(i, c) { customerSelect.append(new Option(c.company_name, c.id)); });
            toggleFields();
        });

        function toggleFields() {
            var type = document.getElementById('doc_type').value;
            var hint = document.getElementById('type_hint');
            var payDiv = document.getElementById('payment_details_group');
            var collDiv = document.getElementById('collection_details_group');
            var payInvDiv = document.getElementById('payment_invoice_fields');
            var collInvDiv = document.getElementById('collection_invoice_fields');

            if (type === 'payment_order') {
                hint.innerHTML = '<i class="fa fa-arrow-circle-up"></i> Bu işlem KASA ÇIKIŞI (Ödeme) anlamına gelir.';
                hint.className = 'form-text text-danger fw-bold mt-1';
                payDiv.classList.remove('d-none'); collDiv.classList.add('d-none');
                payInvDiv.classList.remove('d-none'); collInvDiv.classList.add('d-none');
            } else {
                hint.innerHTML = '<i class="fa fa-arrow-circle-down"></i> Bu işlem KASA GİRİŞİ (Tahsilat) anlamına gelir.';
                hint.className = 'form-text text-success fw-bold mt-1';
                payDiv.classList.add('d-none'); collDiv.classList.remove('d-none');
                payInvDiv.classList.add('d-none'); collInvDiv.classList.remove('d-none');
            }
        }

        // --- GÜNCELLENEN MÜŞTERİ GETİRME VE GEÇMİŞ ---
        function fetchCustomerDetails() {
            var id = $('#customer_id').val(); 
            
            if(!id) {
                $('#customer-history-section').addClass('d-none');
                return;
            }

            $('#modal_customer_id').val(id);
            
            $.get('api-get-customer-details.php?id='+id + '&history=1', function(data){
                
                // 1. Bankaları Doldur
                var bs = $('#bank_id').empty().append('<option value="">Seçiniz...</option>');
                $.each(data.banks, function(i,b){ bs.append('<option value="'+b.id+'">'+b.bank_name+' - '+b.iban+'</option>'); });
                
                // 2. Bakiye Kartını Göster
                var balClass = data.balance < 0 ? 'bg-gradient-danger' : 'bg-gradient-success';
                var balText = data.balance < 0 ? 'BORÇLU (Ödeme Yapılmalı)' : 'ALACAKLI (Bizden Alacağı Var / Avans)';
                if(Math.abs(data.balance) < 0.01) { balClass = 'bg-secondary'; balText = 'BAKİYE SIFIR'; }

                var htmlCard = `
                    <div class="balance-card ${balClass}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0 fw-bold">${parseFloat(Math.abs(data.balance)).toLocaleString('tr-TR', {minimumFractionDigits: 2})} ${data.currency}</h3>
                                <small>${balText}</small>
                            </div>
                            <i class="fa fa-wallet fa-3x opacity-50"></i>
                        </div>
                    </div>`;
                $('#balance-card-container').html(htmlCard);

                // 3. Geçmiş Tablosunu Doldur
                var tbody = $('#history-table-body').empty();
                if(data.history && data.history.length > 0) {
                    $.each(data.history, function(i, h) {
                        var typeBadge = h.type === 'debt' ? '<span class="badge bg-danger">Borç (Gider)</span>' : '<span class="badge bg-success">Alacak (Gelir)</span>';
                        var row = `
                            <tr>
                                <td>${h.date}</td>
                                <td>${typeBadge}</td>
                                <td>${h.description}</td>
                                <td>${h.tour_code || '-'}</td>
                                <td class="text-end fw-bold">${parseFloat(h.amount).toLocaleString('tr-TR', {minimumFractionDigits: 2})}</td>
                                <td class="text-end">${h.balance_after ? parseFloat(h.balance_after).toLocaleString('tr-TR', {minimumFractionDigits: 2}) : '-'}</td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                } else {
                    tbody.append('<tr><td colspan="6" class="text-center text-muted">Kayıtlı işlem bulunamadı.</td></tr>');
                }

                // Bölümü Göster
                $('#customer-history-section').removeClass('d-none');

            }, 'json');
        }

        // ... Diğer yardımcı fonksiyonlar (updateRate, calcTL, openBankModal, saveBank) aynen kalacak ...
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
        function openBankModal(){ 
            if(!$('#customer_id').val()) { Swal.fire('Uyarı','Cari seçiniz.','warning'); return; }
            new bootstrap.Modal(document.getElementById('addBankModal')).show(); 
        }
        function saveBank(){
            $.post('api-add-bank.php', {
                customer_id:$('#modal_customer_id').val(), bank_name:$('#modal_bank_name').val(), iban:$('#modal_iban').val(), currency:$('#modal_currency').val()
            }, function(r){ if(r.status==='success'){ bootstrap.Modal.getInstance(document.getElementById('addBankModal')).hide(); fetchCustomerDetails(); Swal.fire('Başarılı','Banka eklendi!','success'); } }, 'json');
        }
    </script>
</body>
</html>