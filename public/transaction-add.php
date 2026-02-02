<?php
// public/transaction-add.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Kullanıcı ve Sabit Veriler
$user_id = $_SESSION['user_id'];
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$tours = $pdo->query("SELECT * FROM tour_codes WHERE status = 'active' ORDER BY code DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- GET İLE GELEN (KUR FARKI VS.) VERİLERİ HAZIRLA ---
$pre_doc_type = $_GET['doc_type'] ?? 'payment_order';
$pre_amount   = $_GET['amount'] ?? '';
$pre_desc     = $_GET['desc'] ?? '';
$pre_customer = $_GET['customer_id'] ?? '';
$pre_date     = $_GET['date'] ?? date('Y-m-d');
$is_auto_fill = ($pre_amount != '');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $doc_type = $_POST['doc_type']; 
        $date = $_POST['date'];
        $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;
        
        if (!$customer_id) throw new Exception("Lütfen bir Cari Hesap seçiniz.");

        $amount = (float)$_POST['amount'];
        $currency = $_POST['currency'];
        $exchange_rate = (float)$_POST['exchange_rate'];
        $original_amount = ($currency === 'TRY') ? 0 : $amount;
        
        if ($currency !== 'TRY') {
            $amount = $amount * $exchange_rate;
        }

        $description = temizle($_POST['description']);
        $recipient_bank_id = null;

        // ÖDEME KAYNAĞI VE DETAYLARI
        if ($doc_type === 'payment_order' && !empty($_POST['payment_source_simple'])) {
            $simple_source = temizle($_POST['payment_source_simple']);
            
            if ($simple_source == 'Havale/EFT') {
                // Banka seçimi zorunlu değil ama varsa alalım
                $recipient_bank_id = !empty($_POST['bank_id']) ? $_POST['bank_id'] : null;
                $description .= " [Yöntem: Havale/EFT]";
            } 
            elseif ($simple_source == 'Yurtdışı Banka') {
                // SWIFT Bilgilerini Açıklamaya Ekle
                $swift_code = !empty($_POST['swift_code']) ? temizle($_POST['swift_code']) : '-';
                $foreign_bank = !empty($_POST['foreign_bank_name']) ? temizle($_POST['foreign_bank_name']) : '-';
                $description .= " [Transfer: Yurtdışı - Banka: $foreign_bank - SWIFT: $swift_code]";
            }
            elseif ($simple_source == 'Kredi Kartı') {
                $description .= " [Yöntem: Kredi Kartı]";
            }
            else {
                $description .= " [Yöntem: " . $simple_source . "]";
            }
        }

        // FATURA DURUMU
        $invoice_status = 'pending'; // Varsayılan: Planlandı
        $invoice_date = null;

        // Sadece GELİR (Tahsilat) ise ve Fatura Kesilsin işaretliyse
        if ($doc_type === 'invoice_order' && isset($_POST['issue_invoice_check'])) {
            $invoice_status = 'to_be_issued'; // Fatura Kesilecek
            $invoice_date = !empty($_POST['invoice_issue_date']) ? $_POST['invoice_issue_date'] : $date;
        }

        $type = ($doc_type === 'payment_order') ? 'debt' : 'credit';

        $sql = "INSERT INTO transactions (
                    customer_id, department_id, tour_code_id, type, doc_type, 
                    amount, currency, original_amount, exchange_rate, 
                    date, description, payment_status, invoice_status, 
                    invoice_date, recipient_bank_id, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $customer_id, $_POST['department_id'] ?? null, $_POST['tour_code_id'] ?? null, $type, $doc_type,
            $amount, $currency, $original_amount, $exchange_rate,
            $date, $description, $invoice_status, 
            $invoice_date, $recipient_bank_id, $user_id
        ]);

        // Loglama ve Yönlendirme
        $last_id = $pdo->lastInsertId();
        log_action($pdo, 'transaction', $last_id, 'create', "Yeni işlem oluşturuldu. Tutar: $amount TL");
        
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
        .form-section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .section-title { font-size: 1.1rem; font-weight: bold; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 15px; }
        .balance-card { border-radius: 10px; padding: 15px; margin-bottom: 10px; color: #fff; }
        .bg-official { background: linear-gradient(45deg, #0d6efd, #0a58ca); }
        .bg-pending { background: linear-gradient(45deg, #ffc107, #ffca2c); color: #333 !important; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="fa fa-plus-circle text-primary"></i> Yeni Finansal İşlem</h2>
            <a href="payment-orders.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Listeye Dön</a>
        </div>

        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST">
            <div class="row">
                <div class="col-md-8">
                    
                    <div class="form-section">
                        <h6 class="section-title text-primary">İşlem Türü</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Belge Tipi</label>
                                <select name="doc_type" id="doc_type" class="form-select form-select-lg" onchange="toggleFields()">
                                    <option value="payment_order" <?php echo ($pre_doc_type == 'payment_order' ? 'selected' : ''); ?>>Ödeme Emri (Gider)</option>
                                    <option value="invoice_order" <?php echo ($pre_doc_type == 'invoice_order' ? 'selected' : ''); ?>>Tahsilat Emri (Gelir)</option>
                                </select>
                                <div id="type_hint" class="form-text text-danger fw-bold mt-1">
                                    <i class="fa fa-arrow-circle-up"></i> KASA ÇIKIŞI (Ödeme)
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">İşlem Tarihi</label>
                                <input type="date" name="date" class="form-control form-control-lg" value="<?php echo $pre_date; ?>" required>
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
                                <select name="tour_code_id" id="tour_code_id" class="form-select select2" onchange="autoSelectDepartment()">
                                    <option value="">Genel / Projesiz</option>
                                    <?php foreach($tours as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo $t['code'] . ' - ' . $t['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Departman</label>
                                <select name="department_id" id="department_id" class="form-select">
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
                                <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-lg fw-bold" 
                                       placeholder="0.00" required oninput="calcTL()" value="<?php echo $pre_amount; ?>">
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
                                <label class="form-label fw-bold text-primary">Ödeme Yöntemi</label>
                                <select name="payment_source_simple" id="payment_source_simple" class="form-select border-primary" onchange="togglePaymentSource()">
                                    <option value="Nakit">Nakit</option>
                                    <option value="Kredi Kartı">Kredi Kartı</option>
                                    <option value="Havale/EFT">Havale / EFT (Yurt İçi)</option>
                                    <option value="Yurtdışı Banka">Yurtdışı Banka (SWIFT)</option>
                                </select>
                            </div>
                            
                            <div class="mb-3 d-none" id="recipient_bank_div">
                                <label class="form-label">Alıcı Banka Hesabı (Cari)</label>
                                <div class="input-group">
                                    <select name="bank_id" id="bank_id" class="form-select">
                                        <option value="">Önce Cari Seçiniz...</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-secondary" onclick="openBankModal()"><i class="fa fa-plus"></i></button>
                                </div>
                            </div>

                            <div class="mb-3 d-none" id="swift_div">
                                <div class="alert alert-info py-2 small"><i class="fa fa-globe"></i> Yurtdışı transferi detayları.</div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Yurtdışı Banka Adı</label>
                                    <input type="text" name="foreign_bank_name" class="form-control" placeholder="Örn: Deutsche Bank">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">SWIFT / BIC Kodu</label>
                                    <input type="text" name="swift_code" class="form-control fw-bold" placeholder="Örn: DEUTDEDB...">
                                </div>
                            </div>
                        </div>

                        <div id="collection_details_group" class="d-none">
                            <div class="alert alert-warning border-warning p-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="issue_invoice_check" name="issue_invoice_check" onchange="toggleInvoiceDate()">
                                    <label class="form-check-label fw-bold text-dark" for="issue_invoice_check">
                                        Fatura Kesilsin
                                    </label>
                                </div>
                                
                                <div id="invoice_date_div" class="d-none mt-2">
                                    <label class="form-label small fw-bold">Fatura Tarihi</label>
                                    <input type="date" name="invoice_issue_date" class="form-control form-control-sm border-warning" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <hr class="my-2">
                                <small class="text-muted">Seçilirse 'Fatura Kesilecek' listesine düşer.</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Açıklama</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo guvenli_html($pre_desc); ?></textarea>
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
            <div class="row mb-3">
                <div class="col-md-6" id="official-balance-container"></div>
                <div class="col-md-6" id="pending-balance-container"></div>
            </div>
            <h4 class="mb-3 text-secondary"><i class="fa fa-history"></i> Seçilen Carinin Son İşlemleri</h4>
            <div class="card shadow"><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-hover mb-0"><thead class="table-dark"><tr><th>Tarih</th><th>Tür</th><th>Açıklama</th><th>Tur Kodu</th><th class="text-end">Tutar</th></tr></thead><tbody id="history-table-body"></tbody></table></div></div></div>
        </div>
    </div>

    <div class="modal fade" id="addBankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white"><h5 class="modal-title">Banka Ekle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
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

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            $('.select2').select2({ theme: 'bootstrap-5', placeholder: "Seçiniz...", allowClear: true });
            
            // Müşterileri Doldur
            var customers = <?php echo json_encode($pdo->query("SELECT id, company_name FROM customers ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC)); ?>;
            var sel = $('#customer_id');
            $.each(customers, function(i, c) { sel.append(new Option(c.company_name, c.id)); });

            // --- OTOMATİK DOLDURMA (KUR FARKI) ---
            var preCust = "<?php echo $pre_customer; ?>";
            var isAuto = "<?php echo $is_auto_fill ? '1' : ''; ?>";

            if(preCust) {
                $('#customer_id').val(preCust).trigger('change');
                fetchCustomerDetails();
            }

            if(isAuto === '1') {
                $('#issue_invoice_check').prop('checked', true);
                toggleInvoiceDate(); 
            }

            toggleFields();
        });

        function toggleFields() {
            var type = document.getElementById('doc_type').value;
            var hint = document.getElementById('type_hint');
            var payDiv = document.getElementById('payment_details_group');
            var collDiv = document.getElementById('collection_details_group');

            if (type === 'payment_order') {
                hint.innerHTML = '<i class="fa fa-arrow-circle-up"></i> KASA ÇIKIŞI (Ödeme)';
                hint.className = 'form-text text-danger fw-bold mt-1';
                payDiv.classList.remove('d-none');
                collDiv.classList.add('d-none'); // Ödeme emrinde fatura kutusu GİZLİ
                togglePaymentSource();
            } else {
                hint.innerHTML = '<i class="fa fa-arrow-circle-down"></i> KASA GİRİŞİ (Tahsilat)';
                hint.className = 'form-text text-success fw-bold mt-1';
                payDiv.classList.add('d-none');
                collDiv.classList.remove('d-none'); // Gelirde AÇIK
            }
        }

        function toggleInvoiceDate() {
            var chk = document.getElementById('issue_invoice_check');
            var div = document.getElementById('invoice_date_div');
            if (chk.checked) div.classList.remove('d-none'); else div.classList.add('d-none');
        }

        function togglePaymentSource() {
            var src = document.getElementById('payment_source_simple').value;
            var bankDiv = document.getElementById('recipient_bank_div');
            var swiftDiv = document.getElementById('swift_div');

            // Hepsini gizle
            bankDiv.classList.add('d-none');
            swiftDiv.classList.add('d-none');

            if (src === 'Havale/EFT') {
                bankDiv.classList.remove('d-none');
            } else if (src === 'Yurtdışı Banka') {
                swiftDiv.classList.remove('d-none');
            }
        }

        function autoSelectDepartment() {
            var tid = $('#tour_code_id').val();
            if(tid) {
                $.get('api-get-tour-details.php?id=' + tid, function(d) {
                    if(d.status === 'success') $('#department_id').val(d.department_id);
                }, 'json');
            }
        }

        function fetchCustomerDetails() {
            var id = $('#customer_id').val(); 
            if(!id) { $('#customer-history-section').addClass('d-none'); return; }
            $('#modal_customer_id').val(id);
            
            $.get('api-get-customer-details.php?id='+id, function(data){
                var bs = $('#bank_id').empty().append('<option value="">Seçiniz...</option>');
                if(data.banks) {
                    $.each(data.banks, function(i,b){ bs.append('<option value="'+b.id+'">'+b.bank_name+' - '+b.iban+'</option>'); });
                }
                
                var offBal = parseFloat(data.official_balance);
                var offText = offBal < 0 ? 'BORÇLUYUZ' : 'ALACAKLIYIZ';
                if(Math.abs(offBal) < 0.01) offText = 'DENK';
                var htmlOff = `<div class="balance-card bg-official shadow"><div class="d-flex justify-content-between align-items-center"><div><small class="text-white-50">Resmi Bakiye (Faturalı)</small><h4 class="mb-0 fw-bold">${offBal.toLocaleString('tr-TR', {minimumFractionDigits: 2})} ${data.currency}</h4><small>${offText}</small></div><i class="fa fa-file-invoice fa-2x opacity-50"></i></div></div>`;
                $('#official-balance-container').html(htmlOff);

                var penBal = parseFloat(data.pending_balance);
                var penText = penBal < 0 ? 'Fatura Bekleyen (Gider)' : 'Fatura Kesilecek (Gelir)';
                if(Math.abs(penBal) < 0.01) penText = '-';
                var htmlPen = `<div class="balance-card bg-pending shadow"><div class="d-flex justify-content-between align-items-center"><div><small class="text-dark-50">Bekleyen (Faturasız)</small><h4 class="mb-0 fw-bold text-dark">${penBal.toLocaleString('tr-TR', {minimumFractionDigits: 2})} ${data.currency}</h4><small class="text-dark">${penText}</small></div><i class="fa fa-clock fa-2x text-dark opacity-50"></i></div></div>`;
                $('#pending-balance-container').html(htmlPen);

                var tbody = $('#history-table-body').empty();
                if(data.history && data.history.length > 0) {
                    $.each(data.history, function(i, h) {
                        var typeClass = h.type === 'debt' ? 'text-danger' : 'text-success';
                        var row = `<tr><td>${h.date}</td><td class="${typeClass} fw-bold">${h.type_label}</td><td>${h.description}</td><td>${h.tour_code || '-'}</td><td class="text-end fw-bold">${parseFloat(h.amount).toLocaleString('tr-TR', {minimumFractionDigits: 2})}</td></tr>`;
                        tbody.append(row);
                    });
                } else { tbody.append('<tr><td colspan="5" class="text-center text-muted">Kayıtlı emir bulunamadı.</td></tr>'); }
                $('#customer-history-section').removeClass('d-none');
            }, 'json');
        }

        function updateRate() {
            var c = $('#currency').val();
            var r = $('#exchange_rate');
            if (c === 'TRY') { r.val(1.0000).prop('readonly', true); calcTL(); } 
            else { 
                r.prop('readonly', false).attr('placeholder', '...'); 
                $.get('api-get-currency-rate.php?code='+c, function(d){ 
                    if(d.status==='success') { r.val(d.rate); calcTL(); } 
                }, 'json'); 
            }
        }
        function calcTL() {
            var amt = parseFloat($('#amount').val())||0; 
            var rate = parseFloat($('#exchange_rate').val())||1;
            $('#amount_tl_display').val((amt*rate).toLocaleString('tr-TR',{minimumFractionDigits:2}) + ' ₺');
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