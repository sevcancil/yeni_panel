<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Yetki Kontrolü
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- VERİLERİ ÇEK ---
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT * FROM customers ORDER BY company_name")->fetchAll();
$projects = $pdo->query("SELECT * FROM tour_codes WHERE status='active' ORDER BY code DESC")->fetchAll();
$payment_methods = $pdo->query("SELECT * FROM payment_methods ORDER BY title")->fetchAll(); 
$collection_channels = $pdo->query("SELECT * FROM collection_channels ORDER BY title")->fetchAll(); 

// --- KAYIT İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_transaction'])) {
    
    // 1. Temel Veriler
    $doc_type = $_POST['doc_type']; // payment_order veya invoice_order
    $date = $_POST['date'];
    $customer_id = (int)$_POST['customer_id'];
    $tour_code_id = !empty($_POST['tour_code_id']) ? (int)$_POST['tour_code_id'] : null;
    $department_id = (int)$_POST['department_id'];
    
    // 2. Yönü Belirle (EKSİK OLAN KISIM BURASIYDI)
    // Ödeme Emri ise -> debt (Gider), Fatura/Tahsilat ise -> credit (Gelir)
    $type = ($doc_type == 'payment_order') ? 'debt' : 'credit';

    // 3. Tutar ve Döviz
    $original_amount = (float)$_POST['amount']; // Kullanıcının girdiği (Örn: 100 USD)
    $currency = $_POST['currency'];
    $exchange_rate = (float)$_POST['exchange_rate'];
    
    // TL Hesapla (Ana Tutar her zaman TL kaydedilir)
    $amount_tl = $original_amount * $exchange_rate; 
    
    // 4. Detaylar ve Banka (EKSİK OLAN KISIMLAR)
    $description = temizle($_POST['description']);
    $bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null; // Gider ise banka
    
    // Fatura Durumu
    $invoice_status = isset($_POST['invoice_check']) ? 'issued' : 'pending';
    $invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : null;

    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO transactions (
            type, doc_type, date, customer_id, tour_code_id, department_id, 
            amount, original_amount, currency, exchange_rate, description, 
            payment_status, is_approved, recipient_bank_id, invoice_status, invoice_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', 0, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $type, $doc_type, $date, $customer_id, $tour_code_id, $department_id,
            $amount_tl, $original_amount, $currency, $exchange_rate, $description,
            $bank_id, $invoice_status, $invoice_date
        ]);

        $last_id = $pdo->lastInsertId();

        // LOGLAMA
        $log_desc = ($type == 'debt') ? "Ödeme emri girildi. Tutar: $original_amount $currency" : "Tahsilat beklentisi girildi. Tutar: $original_amount $currency";
        log_action($pdo, 'transaction', $last_id, 'create', $log_desc);

        $pdo->commit();
        header("Location: payment-orders.php?msg=added");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Hata: " . $e->getMessage();
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
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                
                <div class="card shadow">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fa fa-plus-circle"></i> Yeni Finansal İşlem</h5>
                        <a href="payment-orders.php" class="btn btn-sm btn-light text-primary">Listeye Dön</a>
                    </div>
                    <div class="card-body">
                        
                        <?php if(isset($error)) echo '<div class="alert alert-danger">'.$error.'</div>'; ?>

                        <form method="POST">
                            <input type="hidden" name="submit_transaction" value="1">

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">İşlem Tipi (Belge)</label>
                                    <select name="doc_type" id="doc_type" class="form-select form-select-lg" onchange="toggleFields()">
                                        <option value="payment_order">📤 Ödeme Emri (Gider / Borç)</option>
                                        <option value="invoice_order">📥 Satış Faturası / Tahsilat (Gelir / Alacak)</option>
                                    </select>
                                    <div id="type_hint" class="form-text text-danger fw-bold mt-1">Bu işlem KASA ÇIKIŞI (Ödeme) anlamına gelir.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Planlanan Tarih</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <hr>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Cari Hesap / Firma *</label>
                                    <select name="customer_id" id="customer_id" class="form-select select2" required onchange="fetchCustomerDetails()">
                                        <option value="">Seçiniz...</option>
                                        <?php foreach($customers as $c): ?>
                                            <option value="<?php echo $c['id']; ?>"><?php echo guvenli_html($c['company_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="recent_transactions" class="mt-2 small text-muted"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tur Kodu / Proje</label>
                                    <select name="tour_code_id" class="form-select select2">
                                        <option value="">(Genel / Projesiz)</option>
                                        <?php foreach($projects as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"><?php echo $p['code'] . ' - ' . $p['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Seçilmezse "Genel Gider" olarak işlenir.</div>
                                </div>
                            </div>

                            <div class="row mb-3 bg-light p-3 rounded mx-1 border">
                                <div class="col-md-3">
                                    <label class="form-label">Tutar</label>
                                    <input type="number" step="0.01" name="amount" id="amount" class="form-control fw-bold" placeholder="0.00" required oninput="calcTL()">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Birim</label>
                                    <select name="currency" id="currency" class="form-select" onchange="updateRate()">
                                        <option value="TRY">TRY</option>
                                        <option value="USD">USD</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kur (Düzenlenebilir)</label>
                                    <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate" class="form-control" value="1.0000" oninput="calcTL()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">TL Karşılığı (Otomatik)</label>
                                    <input type="text" id="amount_tl_display" class="form-control bg-white text-end fw-bold fs-5" readonly value="0,00 ₺">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Bölüm / Departman</label>
                                    <select name="department_id" class="form-select" required>
                                        <?php foreach($departments as $d): ?>
                                            <option value="<?php echo $d['id']; ?>"><?php echo $d['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6" id="bank_field_group">
                                    <label class="form-label">Alıcı Banka Hesabı (IBAN)</label>
                                    <div class="input-group">
                                        <select name="bank_id" id="bank_id" class="form-select">
                                            <option value="">Önce Cari Seçiniz...</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-success" onclick="openBankModal()">
                                            <i class="fa fa-plus"></i> Ekle
                                        </button>
                                    </div>
                                    <div class="form-text small" id="iban_preview"></div>
                                </div>

                                <div class="col-md-6 d-none" id="collection_field_group">
                                    <label class="form-label">Tahsilat Yöntemi</label>
                                    <select name="collection_channel_id" class="form-select">
                                        <option value="">Seçiniz...</option>
                                        <?php foreach($collection_channels as $cc): ?>
                                            <option value="<?php echo $cc['id']; ?>"><?php echo $cc['title']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Açıklama / Notlar</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Fatura numarası, hizmet detayı vb."></textarea>
                            </div>

                            <div class="form-check mb-3 p-3 border rounded bg-white">
                                <input class="form-check-input" type="checkbox" name="invoice_check" id="invoice_check" onchange="toggleInvoiceDate()">
                                <label class="form-check-label fw-bold" for="invoice_check">
                                    Faturası Kesildi / Elimizde
                                </label>
                                
                                <div class="mt-2 d-none" id="invoice_date_div">
                                    <label class="small text-muted">Fatura Tarihi:</label>
                                    <input type="date" name="invoice_date" class="form-control form-control-sm w-50">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 btn-lg">
                                <i class="fa fa-save"></i> İşlemi Kaydet
                            </button>

                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal fade" id="addBankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Yeni Banka Hesabı Ekle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="bankForm">
                        <input type="hidden" id="modal_customer_id">
                        <div class="mb-3">
                            <label>Banka Adı</label>
                            <input type="text" id="modal_bank_name" class="form-control" placeholder="Örn: Garanti BBVA" required>
                        </div>
                        <div class="mb-3">
                            <label>IBAN</label>
                            <input type="text" id="modal_iban" class="form-control" placeholder="TR..." required>
                        </div>
                        <div class="mb-3">
                            <label>Döviz Cinsi</label>
                            <select id="modal_currency" class="form-select">
                                <option value="TRY">TRY</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-success" onclick="saveBank()">Kaydet</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
        $(document).ready(function() {
            $('.select2').select2({ theme: 'bootstrap-5', placeholder: "Seçiniz...", allowClear: true });
            
            // TARİH KONTROLÜ (Geçmiş Tarih Uyarısı)
            $('input[name="date"]').on('change', function() {
                var selectedDate = new Date($(this).val());
                var today = new Date();
                today.setHours(0,0,0,0); // Saat farkını sıfırla

                if(selectedDate < today) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Dikkat!',
                        text: 'Geçmişe dönük işlem giriyorsunuz. Bu işlem raporlamalarda sapmaya neden olabilir.',
                        confirmButtonText: 'Anladım, Devam Et'
                    });
                }
            });
        });

        // 1. İŞLEM TİPİ
        function toggleFields() {
            var type = document.getElementById('doc_type').value;
            var hint = document.getElementById('type_hint');
            var bankDiv = document.getElementById('bank_field_group');
            var colDiv = document.getElementById('collection_field_group');

            if (type === 'payment_order') {
                hint.innerHTML = '<i class="fa fa-arrow-circle-up"></i> Bu işlem KASA ÇIKIŞI (Ödeme) anlamına gelir.';
                hint.className = 'form-text text-danger fw-bold mt-1';
                bankDiv.classList.remove('d-none'); 
                colDiv.classList.add('d-none');
            } else {
                hint.innerHTML = '<i class="fa fa-arrow-circle-down"></i> Bu işlem KASA GİRİŞİ (Tahsilat) anlamına gelir.';
                hint.className = 'form-text text-success fw-bold mt-1';
                bankDiv.classList.add('d-none');
                colDiv.classList.remove('d-none');
            }
        }

        // 2. KUR
        function updateRate() {
            var currency = document.getElementById('currency').value;
            var rateInput = document.getElementById('exchange_rate');

            if (currency === 'TRY') {
                rateInput.value = 1.0000;
                rateInput.readOnly = true;
                calcTL();
            } else {
                rateInput.readOnly = false;
                rateInput.placeholder = "Kur getiriliyor...";
                
                $.get('api-get-currency-rate.php?code=' + currency, function(data) {
                    if (data.status === 'success') {
                        rateInput.value = data.rate;
                        calcTL();
                    }
                }, 'json');
            }
        }

        function calcTL() {
            var amount = parseFloat(document.getElementById('amount').value) || 0;
            var rate = parseFloat(document.getElementById('exchange_rate').value) || 1;
            var tl = amount * rate;
            document.getElementById('amount_tl_display').value = tl.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ₺';
        }

        // 3. CARİ DETAYLARI (GÜNCEL BAKİYE VE AÇIKLAMA EKLENDİ)
        function fetchCustomerDetails() {
            var id = $('#customer_id').val();
            if(!id) return;

            $('#modal_customer_id').val(id);

            $.get('api-get-customer-details.php?id=' + id, function(data) {
                // A. Bankalar
                var bankSelect = $('#bank_id');
                bankSelect.empty().append('<option value="">Seçiniz...</option>');
                if(data.banks.length > 0) {
                    $.each(data.banks, function(i, bank) {
                        bankSelect.append('<option value="'+bank.id+'">'+bank.bank_name+' - '+bank.iban+'</option>');
                    });
                }

                // B. Bakiye ve Geçmiş (YENİ KISIM)
                var infoHtml = '';
                
                // Bakiye Gösterimi
                var balanceColor = data.balance < 0 ? 'text-danger' : 'text-success';
                var balanceText = parseFloat(data.balance).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + data.currency;
                
                infoHtml += '<div class="alert alert-light border shadow-sm p-2 mt-2">';
                infoHtml += '<h6><i class="fa fa-wallet"></i> Güncel Bakiye: <strong class="'+balanceColor+'">' + balanceText + '</strong></h6>';
                
                // Geçmiş İşlemler
                if(data.history.length > 0) {
                    infoHtml += '<hr class="my-2"><small class="text-muted">Son İşlemler:</small><ul class="mb-0 ps-3 small">';
                    $.each(data.history, function(i, h) {
                        // Açıklamayı da ekledik
                        var desc = h.description ? ' - ' + h.description : ''; 
                        var amt = parseFloat(h.original_amount).toLocaleString('tr-TR', {minimumFractionDigits: 2});
                        infoHtml += '<li>' + h.date + ': <strong>' + amt + ' ' + h.currency + '</strong>' + desc + '</li>';
                    });
                    infoHtml += '</ul>';
                } else {
                    infoHtml += '<small class="text-muted">Henüz işlem kaydı yok.</small>';
                }
                infoHtml += '</div>';
                
                $('#recent_transactions').html(infoHtml);

            }, 'json');
        }

        // 4. BANKA EKLEME
        function openBankModal() { /* ... (Değişiklik Yok) ... */ 
            var customerId = $('#customer_id').val();
            if (!customerId) { alert("Lütfen önce bir Cari Kart seçiniz."); return; }
            new bootstrap.Modal(document.getElementById('addBankModal')).show();
        }

        function saveBank() { /* ... (Değişiklik Yok) ... */ 
            var data = {
                customer_id: $('#modal_customer_id').val(),
                bank_name: $('#modal_bank_name').val(),
                iban: $('#modal_iban').val(),
                currency: $('#modal_currency').val()
            };
            $.post('api-add-bank.php', data, function(response) {
                if (response.status === 'success') {
                    bootstrap.Modal.getInstance(document.getElementById('addBankModal')).hide();
                    fetchCustomerDetails();
                    alert("Banka eklendi!");
                } else { alert("Hata: " + response.message); }
            }, 'json');
        }

        function toggleInvoiceDate() { /* ... (Değişiklik Yok) ... */ 
            var checked = document.getElementById('invoice_check').checked;
            var div = document.getElementById('invoice_date_div');
            if(checked) div.classList.remove('d-none');
            else div.classList.add('d-none');
        }
    </script>
</body>
</html>