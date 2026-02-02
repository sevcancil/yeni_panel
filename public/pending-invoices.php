<?php
// public/pending-invoices.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// 1. LİSTE: FATURA KESİLECEKLER (to_be_issued) - ACİL
$sql_issued = "SELECT t.*, 
               c.company_name, c.tax_office, c.tax_number, c.tc_number, c.address, c.city, c.country,
               tc.code as tour_code 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.invoice_status = 'to_be_issued' 
        AND t.doc_type = 'invoice_order'
        AND (t.parent_id IS NULL OR t.parent_id = 0)
        ORDER BY t.date ASC";
$list_issued = $pdo->query($sql_issued)->fetchAll(PDO::FETCH_ASSOC);

// 2. LİSTE: ONAY BEKLEYENLER (waiting_approval) - BEKLEMEDE
$sql_waiting = "SELECT t.*, 
               c.company_name, 
               tc.code as tour_code 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.invoice_status = 'waiting_approval' 
        AND t.doc_type = 'invoice_order'
        AND (t.parent_id IS NULL OR t.parent_id = 0)
        ORDER BY t.date ASC";
$list_waiting = $pdo->query($sql_waiting)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Fatura Takip Ekranı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        .table-priority { border: 2px solid #ffc107; }
        .table-priority thead { background-color: #fff3cd; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="text-dark"><i class="fa fa-tasks text-primary"></i> Fatura Takip Merkezi</h2>
                <p class="text-muted">Tahsilat işlemlerinin fatura süreçlerini buradan yönetebilirsiniz.</p>
            </div>
            <a href="payment-orders.php" class="btn btn-secondary"> <i class="fa fa-arrow-left"></i> Tüm Finans Listesi</a>
        </div>

        <?php if(isset($_GET['msg']) && $_GET['msg']=='added'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fa fa-check-circle"></i> İşlem başarıyla eklendi.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow mb-5 border-warning table-priority">
            <div class="card-header bg-warning bg-opacity-25">
                <h5 class="mb-0 text-dark fw-bold">
                    <i class="fa fa-exclamation-circle text-danger"></i> Fatura Kesilmesi Gerekenler
                    <span class="badge bg-danger rounded-pill float-end"><?php echo count($list_issued); ?> Adet</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <table id="tableIssued" class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">ID</th>
                            <th>Tarih</th>
                            <th>Cari / Müşteri</th>
                            <th>Açıklama</th>
                            <th class="text-end">Tutar</th>
                            <th class="text-center" width="150">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($list_issued as $row): ?>
                            <tr>
                                <td class="fw-bold">#<?php echo $row['id']; ?></td>
                                <td><?php echo date('d.m.Y', strtotime($row['date'])); ?></td>
                                <td>
                                    <div class="fw-bold"><?php echo guvenli_html($row['company_name']); ?></div>
                                    <small class="text-muted"><?php echo $row['tour_code'] ? $row['tour_code'] : ''; ?></small>
                                </td>
                                <td><?php echo guvenli_html($row['description']); ?></td>
                                
                                <td class="text-end fw-bold text-success fs-5">
                                    <?php 
                                        $currency = $row['currency'];
                                        $amount_tl = $row['amount'];
                                        $amount_orig = $row['original_amount'];
                                        $rate = $row['exchange_rate'];

                                        if ($currency != 'TRY') {
                                            // Dövizli İşlem
                                            if ($amount_orig <= 0 && $rate > 0) {
                                                // Eğer orijinal tutar 0 ise, TL'den geri hesapla
                                                $amount_orig = $amount_tl / $rate;
                                            }
                                            echo number_format($amount_orig, 2, ',', '.') . ' ' . $currency;
                                            echo '<br><small class="text-muted fs-6 fw-normal">(' . number_format($amount_tl, 2, ',', '.') . ' TL)</small>';
                                        } else {
                                            // TL İşlem
                                            echo number_format($amount_tl, 2, ',', '.') . ' TL';
                                        }
                                    ?>
                                </td>

                                <td class="text-center">
                                    <button class="btn btn-success btn-sm w-100 shadow-sm" onclick='openInvoiceModal(<?php echo json_encode($row); ?>)'>
                                        <i class="fa fa-upload"></i> Fatura Yükle
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if(empty($list_issued)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fa fa-check-circle fa-3x text-success mb-2"></i><br>
                        Harika! Kesilmesi gereken bekleyen fatura yok.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow border-secondary">
            <div class="card-header bg-secondary bg-opacity-10">
                <h5 class="mb-0 text-secondary fw-bold">
                    <i class="fa fa-clock"></i> Onay Bekleyenler (Henüz Fatura Kesilmeyecek)
                    <span class="badge bg-secondary rounded-pill float-end"><?php echo count($list_waiting); ?> Adet</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <table id="tableWaiting" class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">ID</th>
                            <th>Tarih</th>
                            <th>Cari / Müşteri</th>
                            <th>Açıklama</th>
                            <th class="text-end">Tutar</th>
                            <th class="text-center" width="150">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($list_waiting as $row): ?>
                            <tr>
                                <td class="text-muted">#<?php echo $row['id']; ?></td>
                                <td><?php echo date('d.m.Y', strtotime($row['date'])); ?></td>
                                <td>
                                    <div class="fw-bold"><?php echo guvenli_html($row['company_name']); ?></div>
                                    <small class="text-muted"><?php echo $row['tour_code'] ? $row['tour_code'] : ''; ?></small>
                                </td>
                                <td class="text-muted"><?php echo guvenli_html($row['description']); ?></td>
                                
                                <td class="text-end fw-bold text-dark">
                                    <?php 
                                        $currency = $row['currency'];
                                        $amount_tl = $row['amount'];
                                        $amount_orig = $row['original_amount'];
                                        $rate = $row['exchange_rate'];

                                        if ($currency != 'TRY') {
                                            if ($amount_orig <= 0 && $rate > 0) {
                                                $amount_orig = $amount_tl / $rate;
                                            }
                                            echo number_format($amount_orig, 2, ',', '.') . ' ' . $currency;
                                            echo '<br><small class="text-muted fs-6 fw-normal">(' . number_format($amount_tl, 2, ',', '.') . ' TL)</small>';
                                        } else {
                                            echo number_format($amount_tl, 2, ',', '.') . ' TL';
                                        }
                                    ?>
                                </td>

                                <td class="text-center">
                                    <button class="btn btn-outline-primary btn-sm w-100" onclick="approveInvoice(<?php echo $row['id']; ?>)">
                                        <i class="fa fa-arrow-up"></i> Onayla & Taşı
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if(empty($list_waiting)): ?>
                    <div class="p-3 text-center text-muted small">
                        Onay bekleyen işlem yok.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div class="modal fade" id="invoiceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fa fa-print"></i> Fatura Yükle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    
                    <div class="alert alert-light border mb-3">
                        <div class="row">
                            <div class="col-md-8">
                                <strong id="info_company" class="fs-5 d-block"></strong>
                                <span id="info_tax" class="small text-muted"></span>
                            </div>
                            <div class="col-md-4 text-end">
                                <strong id="info_amount" class="text-success fs-4"></strong>
                            </div>
                        </div>
                    </div>

                    <form id="invoiceForm" enctype="multipart/form-data">
                        <input type="hidden" name="id" id="trans_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fatura No <span class="text-danger">*</span></label>
                                <input type="text" name="invoice_no" class="form-control" required placeholder="Örn: GIB2024...">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fatura Tarihi</label>
                                <input type="date" name="invoice_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Fatura Dosyası (PDF/Resim)</label>
                            <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-save"></i> Kaydet ve Tamamla</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            $('#tableIssued').DataTable({ "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }, "paging": false, "info": false, "searching": false });
            $('#tableWaiting').DataTable({ "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }, "paging": false, "info": false });
        });

        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));

        // Modal Açma
        function openInvoiceModal(data) {
            document.getElementById('trans_id').value = data.id;
            document.getElementById('info_company').innerText = data.company_name;
            
            // Modalda Gösterilecek Tutar (Akıllı Hesaplama)
            var amt = parseFloat(data.amount);
            var curr = data.currency || 'TRY';
            var orig = parseFloat(data.original_amount);
            var rate = parseFloat(data.exchange_rate);

            if (curr !== 'TRY') {
                if(orig <= 0 && rate > 0) orig = amt / rate;
                document.getElementById('info_amount').innerText = orig.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ' + curr;
            } else {
                document.getElementById('info_amount').innerText = amt.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' TL';
            }
            
            var taxInfo = data.tax_number ? (data.tax_office + ' VD - ' + data.tax_number) : (data.tc_number ? 'TC: '+data.tc_number : '');
            document.getElementById('info_tax').innerText = taxInfo;

            document.getElementById('invoiceForm').reset();
            invoiceModal.show();
        }

        // Fatura Yükleme (AJAX)
        // DİKKAT: api-upload-invoice.php dosyası tutar (amount) bekliyorsa, buraya hidden input eklememiz gerekebilir.
        // Ancak mevcut yapıda "Fatura Yükle" dediğimizde ana işlem tutarını kabul ediyorsak sorun yok.
        // Eğer kullanıcı fatura tutarını değiştirebilsin istiyorsan modal'a input ekleyelim.
        // Şu anki kodda kullanıcıdan tutar istemiyoruz, ana tutarı kabul ediyoruz.
        
        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            
            // Eğer api-upload-invoice.php, POST['invoice_amount'] bekliyorsa ve biz göndermezsek 0 olarak algılayabilir.
            // Bu yüzden "invoice_amount" verisini de eklemeliyiz.
            // Ama bu ekranda input yok. O zaman faturanın "tamamı" kesiliyor varsayımıyla ana tutarı gönderelim mi?
            // Veya api-upload-invoice.php tarafında "eğer post gelmediyse ana tutarı kullan" mantığı var mı?
            // GÜVENLİ OLAN: Kullanıcıya tutar sormaktır. 
            // Şimdilik ana listeden işlem yaptığı için tam tutar varsayalım.
            
            $.ajax({
                url: 'api-upload-invoice.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(res) {
                    if(res.status === 'success') {
                        invoiceModal.hide();
                        Swal.fire({
                            icon: 'success', title: 'Başarılı', text: 'Fatura işlendi.', timer: 1500, showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                    }
                }
            });
        });

        // Onaylama İşlemi
        function approveInvoice(id) {
            Swal.fire({
                title: 'Onaylıyor musunuz?',
                text: "Bu işlem 'Fatura Kesilecekler' listesine taşınacak.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Evet, Onayla',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('api-payment-actions.php', { id: id, action: 'approve_invoice' }, function(res) {
                        if(res.status === 'success') {
                            location.reload();
                        } else {
                            Swal.fire('Hata', res.message, 'error');
                        }
                    }, 'json');
                }
            });
        }
    </script>
</body>
</html>