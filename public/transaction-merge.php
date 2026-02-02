<?php
// public/transaction-merge.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) exit("Erişim reddedildi.");

$ids_str = $_GET['ids'] ?? '';
if (empty($ids_str)) exit("İşlem seçilmedi.");

$ids = explode(',', $ids_str);
$ids = array_map('intval', $ids);
$in_query = implode(',', $ids);

// Seçilen İşlemleri Çek
$sql = "SELECT t.*, c.company_name, c.id as customer_id 
        FROM transactions t 
        LEFT JOIN customers c ON t.customer_id = c.id 
        WHERE t.id IN ($in_query)";
$items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) exit("Kayıt bulunamadı.");

// Validasyonlar ve Toplamlar
$first_type = $items[0]['type']; 
$first_cust = $items[0]['customer_id'];
$valid = true;
$error_msg = "";
$currency_basket = []; 

foreach ($items as $item) {
    if ($item['customer_id'] != $first_cust) {
        $valid = false; $error_msg = "Sadece aynı cariye ait işlemler birleştirilebilir.";
    }
    if ($item['type'] != $first_type) {
        $valid = false; $error_msg = "Gelir ve Gider kalemleri aynı anda birleştirilemez.";
    }

    // Kalan Tutar Hesabı
    $paid = $pdo->query("SELECT SUM(amount) FROM transactions WHERE parent_id = {$item['id']} AND is_deleted = 0 AND (type='payment_in' OR type='payment_out')")->fetchColumn();
    
    // Dövizli işlemde original_amount üzerinden gitmek daha doğru
    if($item['currency'] != 'TRY' && $item['original_amount'] > 0) {
        $paid_orig = $pdo->query("SELECT SUM(original_amount) FROM transactions WHERE parent_id = {$item['id']} AND is_deleted = 0")->fetchColumn();
        $rem = $item['original_amount'] - $paid_orig;
    } else {
        $rem = $item['amount'] - $paid;
    }

    if ($rem > 0.01) {
        $curr = $item['currency'];
        if (!isset($currency_basket[$curr])) $currency_basket[$curr] = 0;
        $currency_basket[$curr] += $rem;
    }
}

// Ayarlar
$action_title = ($first_type == 'debt') ? "Toplu Ödeme Yap (Gider)" : "Toplu Tahsilat Al (Gelir)";
$bg_class = ($first_type == 'debt') ? "bg-danger" : "bg-success";

// Kasa/Banka Listesi
$channels_table = ($first_type == 'debt') ? 'payment_methods' : 'collection_channels';
$channels = $pdo->query("SELECT id, title FROM $channels_table ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
$channel_label = ($first_type == 'debt') ? "Ödeme Kaynağı" : "Tahsilat Kanalı";

// Sepet Gösterimi
$basket_text = [];
foreach($currency_basket as $cr => $am) {
    $basket_text[] = number_format($am, 2) . ' ' . $cr;
}
$basket_display = implode(' + ', $basket_text);
?>

<?php if (!$valid): ?>
    <div class="alert alert-danger text-center">
        <i class="fa fa-exclamation-triangle fa-2x mb-2"></i><br>
        <strong>İşlem Yapılamaz!</strong><br>
        <?php echo $error_msg; ?>
    </div>
<?php else: ?>

    <div class="alert <?php echo $bg_class; ?> text-white">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><?php echo count($items); ?> Adet İşlem (<?php echo $items[0]['company_name']; ?>)</strong>
        </div>
        <div class="p-2 bg-white text-dark rounded text-center">
            <small class="text-muted">Toplam Borç/Alacak:</small><br>
            <span class="fs-5 fw-bold"><?php echo $basket_display; ?></span>
        </div>
    </div>

    <div class="mb-3">
        <button class="btn btn-sm btn-outline-secondary w-100" type="button" data-bs-toggle="collapse" data-bs-target="#collapseList">
            Seçilen İşlemleri Göster / Gizle <i class="fa fa-chevron-down"></i>
        </button>
        <div class="collapse mt-2" id="collapseList">
            <ul class="list-group small">
                <?php foreach($items as $it): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>#<?php echo $it['id']; ?></strong> 
                            <?php echo guvenli_html($it['description']); ?>
                        </div>
                        <span><?php echo number_format($it['amount'], 2); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <form id="mergeForm" enctype="multipart/form-data">
        <input type="hidden" name="ids" value="<?php echo $ids_str; ?>">
        <input type="hidden" name="type" value="<?php echo ($first_type == 'debt' ? 'payment_out' : 'payment_in'); ?>">
        <input type="hidden" name="basket_json" value='<?php echo json_encode($currency_basket); ?>'>

        <div class="card bg-light border-0 mb-3">
            <div class="card-body">
                <h6 class="text-primary fw-bold mb-3"><i class="fa fa-calculator"></i> Ödeme Hesaplayıcı</h6>
                
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Ödeme Yapılacak Para Birimi</label>
                        <select name="payment_currency" id="payment_currency" class="form-select" onchange="calculateFinalTotal()">
                            <option value="TRY">TRY (Türk Lirası)</option>
                            <option value="USD">USD (Amerikan Doları)</option>
                            <option value="EUR">EUR (Euro)</option>
                            <option value="GBP">GBP (Sterlin)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Toplam Ödenecek Tutar</label>
                        <input type="text" id="final_display_total" class="form-control fw-bold fs-5 text-end" readonly>
                        <input type="hidden" name="final_amount" id="final_amount">
                    </div>
                </div>

                <div id="exchange_rates_container" class="mt-3 border-top pt-2">
                    </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">İşlem Tarihi</label>
                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold"><?php echo $channel_label; ?></label>
                <select name="channel_id" class="form-select" required>
                    <option value="">Seçiniz...</option>
                    <?php foreach($channels as $ch): ?>
                        <option value="<?php echo $ch['id']; ?>"><?php echo guvenli_html($ch['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-12 mb-3">
                <div class="card border-warning">
                    <div class="card-header bg-warning bg-opacity-10 p-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="bulk_invoice_check" name="bulk_invoice_check" onchange="toggleBulkInvoice()">
                            <label class="form-check-label fw-bold text-dark" for="bulk_invoice_check">
                                Bu işlem için Fatura Bilgisi Gir / Faturaları Kapat
                            </label>
                        </div>
                    </div>
                    <div class="card-body p-3 d-none" id="bulk_invoice_div">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label small fw-bold">Fatura Numarası</label>
                                <input type="text" name="invoice_no" class="form-control form-control-sm" placeholder="Toplu Fatura No">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label small fw-bold">Fatura Tarihi</label>
                                <input type="date" name="invoice_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <small class="text-danger"><i class="fa fa-info-circle"></i> Dikkat: Seçilen tüm işlemlerin fatura numarası bu bilgiyle güncellenecek ve durumları 'Fatura Kesildi/Alındı' yapılacaktır.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-12 mb-3">
                <label class="form-label">Açıklama</label>
                <input type="text" name="description" class="form-control" placeholder="Örn: Toplu Kapama..." required>
            </div>

            <div class="col-md-12 mb-3">
                <label class="form-label">Ortak Belge / Dekont / Fatura Görseli</label>
                <input type="file" name="document" class="form-control">
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa fa-check-circle"></i> İşlemi Tamamla
            </button>
        </div>
    </form>

    <script>
        var basket = <?php echo json_encode($currency_basket); ?>;

        $(document).ready(function() {
            renderRateInputs();
        });

        function toggleBulkInvoice() {
            var chk = document.getElementById('bulk_invoice_check');
            var div = document.getElementById('bulk_invoice_div');
            if(chk.checked) div.classList.remove('d-none'); else div.classList.add('d-none');
        }

        function renderRateInputs() {
            var container = $('#exchange_rates_container');
            container.empty();
            var targetCurr = $('#payment_currency').val();

            $.each(basket, function(curr, amount) {
                if (curr !== targetCurr) {
                    var inputHtml = `
                        <div class="row mb-2 align-items-center">
                            <div class="col-4 small fw-bold">${curr} -> ${targetCurr} Kuru</div>
                            <div class="col-4">
                                <input type="number" step="0.0001" class="form-control form-control-sm rate-input" 
                                       data-from="${curr}" data-to="${targetCurr}" 
                                       id="rate_${curr}_${targetCurr}" placeholder="Kur Giriniz" value="1" oninput="calculateFinalTotal()">
                            </div>
                            <div class="col-4 small text-muted text-end">
                                ${amount.toLocaleString()} ${curr}
                            </div>
                        </div>
                    `;
                    container.append(inputHtml);
                    fetchRate(curr, targetCurr);
                }
            });
            calculateFinalTotal();
        }

        function fetchRate(from, to) {
            if(to === 'TRY') {
                $.get('api-get-currency-rate.php?code='+from, function(d){ 
                    if(d.status==='success') {
                        $('#rate_'+from+'_TRY').val(d.rate);
                        calculateFinalTotal();
                    }
                }, 'json');
            }
        }

        function calculateFinalTotal() {
            var targetCurr = $('#payment_currency').val();
            var finalTotal = 0;

            $.each(basket, function(curr, amount) {
                if (curr === targetCurr) {
                    finalTotal += amount;
                } else {
                    var rate = parseFloat($('#rate_'+curr+'_'+targetCurr).val()) || 0;
                    finalTotal += (amount * rate);
                }
            });

            $('#final_display_total').val(finalTotal.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + targetCurr);
            $('#final_amount').val(finalTotal.toFixed(2));
        }

        $('#payment_currency').on('change', function() {
            renderRateInputs();
        });

        $('#mergeForm').on('submit', function(e) {
            e.preventDefault();
            
            var missingRate = false;
            $('.rate-input').each(function() { if($(this).val() <= 0) missingRate = true; });
            if(missingRate) { Swal.fire('Eksik Bilgi', 'Lütfen kurları giriniz.', 'warning'); return; }

            var formData = new FormData(this);
            $('.rate-input').each(function() { formData.append($(this).attr('id'), $(this).val()); });

            Swal.fire({
                title: 'Onaylıyor musunuz?',
                text: "Toplu işlem ve (varsa) fatura tanımlaması yapılacak.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Evet, Onayla'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api-merge-transactions.php',
                        type: 'POST',
                        data: formData,
                        contentType: false,
                        processData: false,
                        dataType: 'json',
                        success: function(res) {
                            if(res.status === 'success') {
                                Swal.fire('Başarılı', 'Toplu işlem tamamlandı.', 'success').then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Hata', res.message, 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Hata', 'Sunucu hatası.', 'error');
                        }
                    });
                }
            });
        });
    </script>

<?php endif; ?>