<?php
// public/payment-orders.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- ÖZET İSTATİSTİKLERİ HESAPLA ---
$stats = [
    'expected_debt' => 0,    // Beklenen Ödemeler (Siparişler)
    'expected_credit' => 0,  // Beklenen Alacaklar (Tahsilat Emirleri)
    'confirmed_debt' => 0,   // Kesinleşmiş Borçlar (Faturalı)
    'confirmed_credit' => 0  // Kesinleşmiş Alacaklar (Faturalı)
];

/*
   MANTIK (Senin Veritabanı Yapına Göre):
   - type = 'debt' -> Gider Siparişi
   - type = 'credit' -> Gelir Siparişi
   - invoice_status -> 'pending', 'to_be_issued', 'waiting_approval' -> Beklenen
   - invoice_status -> 'issued' -> Kesinleşmiş
*/

$sqlStats = "SELECT 
    -- Beklenen Ödemeler (Gider Siparişi + Faturası Tamamlanmamış)
    COALESCE(SUM(CASE WHEN type = 'debt' AND (invoice_status != 'issued' OR invoice_status IS NULL) THEN amount ELSE 0 END), 0) as exp_debt,
    
    -- Beklenen Alacaklar (Gelir Siparişi + Faturası Tamamlanmamış)
    COALESCE(SUM(CASE WHEN type = 'credit' AND (invoice_status != 'issued' OR invoice_status IS NULL) THEN amount ELSE 0 END), 0) as exp_credit,
    
    -- Kesinleşmiş Borçlar (Gider + Faturası Kesilmiş + Henüz Ödenmemiş)
    COALESCE(SUM(CASE WHEN type = 'debt' AND invoice_status = 'issued' THEN amount ELSE 0 END), 0) as conf_debt,
    
    -- Kesinleşmiş Alacaklar (Gelir + Faturası Kesilmiş + Henüz Tahsil Edilmemiş)
    COALESCE(SUM(CASE WHEN type = 'credit' AND invoice_status = 'issued' THEN amount ELSE 0 END), 0) as conf_credit

FROM transactions 
WHERE (parent_id IS NULL OR parent_id = 0) 
AND payment_status != 'paid'"; // Sadece aktif (kapanmamış) dosyalar

try {
    $rowStats = $pdo->query($sqlStats)->fetch(PDO::FETCH_ASSOC);
    if($rowStats) {
        $stats['expected_debt'] = $rowStats['exp_debt'];
        $stats['expected_credit'] = $rowStats['exp_credit'];
        $stats['confirmed_debt'] = $rowStats['conf_debt'];
        $stats['confirmed_credit'] = $rowStats['conf_credit'];
    }
} catch (Exception $e) {
    // Hata olursa 0 kalır, sayfa çökmez.
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Finansal Operasyonlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    <style>
        body { background-color: #f4f6f9; }
        
        /* Özet Kartları */
        .stat-card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; color: white; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .icon-box { opacity: 0.3; font-size: 2.5rem; position: absolute; right: 20px; top: 20px; }
        
        .bg-exp-debt { background: linear-gradient(45deg, #ff9966, #ff5e62); } /* Beklenen Gider */
        .bg-exp-cred { background: linear-gradient(45deg, #56ab2f, #a8e063); } /* Beklenen Gelir */
        .bg-conf-debt { background: linear-gradient(45deg, #cb2d3e, #ef473a); } /* Kesin Gider */
        .bg-conf-cred { background: linear-gradient(45deg, #11998e, #38ef7d); } /* Kesin Gelir */

        /* Tablo Satır Renkleri - api-payments-list.php ile uyumlu */
        .row-debt { background-color: #fff5f5 !important; } 
        .row-credit { background-color: #f0fff4 !important; } 
        .row-completed { background-color: #f8f9fa !important; color: #aaa; } 
        .table-hover tbody tr:hover td { background-color: #e9ecef; }

        /* Butonlar */
        .action-icon { cursor: pointer; font-size: 1.2rem; transition: 0.2s; color: #ccc; }
        .action-icon:hover { color: #555; transform: scale(1.2); }
        .action-icon.active.approval { color: #198754; } 
        .action-icon.active.priority { color: #ffc107; } 
        .action-icon.active.control { color: #0d6efd; }
        .toggle-btn { cursor: pointer; margin-right: 5px; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 mt-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-0 fw-bold text-dark"><i class="fa fa-money-bill-wave text-primary"></i> Finansal Operasyonlar</h3>
                <p class="text-muted mb-0">Ödeme ve Tahsilat Emirleri Yönetimi</p>
            </div>
            <div>
                <a href="transaction-add.php" class="btn btn-primary shadow-sm btn-lg">
                    <i class="fa fa-plus-circle"></i> Yeni İşlem Ekle
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card bg-exp-debt h-100">
                    <div class="card-body">
                        <h6 class="text-uppercase mb-1" style="font-size: 0.8rem; opacity: 0.9;">Beklenen Ödemeler</h6>
                        <h3 class="mb-0 fw-bold"><?php echo number_format($stats['expected_debt'], 2, ',', '.'); ?> ₺</h3>
                        <small style="opacity: 0.8;">Sipariş (Fatura Bekleniyor)</small>
                        <i class="fa fa-file-invoice-dollar icon-box"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card bg-conf-debt h-100">
                    <div class="card-body">
                        <h6 class="text-uppercase mb-1" style="font-size: 0.8rem; opacity: 0.9;">Kesinleşmiş Borçlar</h6>
                        <h3 class="mb-0 fw-bold"><?php echo number_format($stats['confirmed_debt'], 2, ',', '.'); ?> ₺</h3>
                        <small style="opacity: 0.8;">Faturası Gelmiş (Ödeme Bekliyor)</small>
                        <i class="fa fa-money-check-alt icon-box"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card bg-exp-cred h-100">
                    <div class="card-body">
                        <h6 class="text-uppercase mb-1" style="font-size: 0.8rem; opacity: 0.9;">Beklenen Alacaklar</h6>
                        <h3 class="mb-0 fw-bold"><?php echo number_format($stats['expected_credit'], 2, ',', '.'); ?> ₺</h3>
                        <small style="opacity: 0.8;">Sipariş (Fatura Kesilecek)</small>
                        <i class="fa fa-hand-holding-usd icon-box"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card bg-conf-cred h-100">
                    <div class="card-body">
                        <h6 class="text-uppercase mb-1" style="font-size: 0.8rem; opacity: 0.9;">Kesinleşmiş Alacaklar</h6>
                        <h3 class="mb-0 fw-bold"><?php echo number_format($stats['confirmed_credit'], 2, ',', '.'); ?> ₺</h3>
                        <small style="opacity: 0.8;">Faturası Kesilmiş (Tahsilat Bekliyor)</small>
                        <i class="fa fa-check-double icon-box"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow border-0">
            <div class="card-body">
                
                <div class="row mb-3 gx-2">
                    <div class="col-auto"><button class="btn btn-outline-secondary active filter-btn" data-status="">Tümü</button></div>
                    <div class="col-auto"><button class="btn btn-outline-success filter-btn" data-status="paid">Tamamlananlar</button></div>
                    <div class="col-auto"><button class="btn btn-outline-warning filter-btn" data-status="partial">Kısmi</button></div>
                    <div class="col-auto"><button class="btn btn-outline-danger filter-btn" data-status="unpaid">Ödenmeyenler</button></div>
                    <div class="col-auto ms-auto"><button class="btn btn-outline-primary action-filter-btn" data-action="approved"><i class="fa fa-check-circle"></i> Onaylılar</button></div>
                    <div class="col-auto"><button class="btn btn-outline-warning action-filter-btn" data-action="priority"><i class="fa fa-star"></i> Öncelikliler</button></div>
                </div>

                <div class="table-responsive">
                    <table id="paymentTable" class="table table-hover align-middle w-100" style="font-size: 0.9rem;">
                        <thead class="table-dark text-white">
                            <tr>
                                <th></th> <th>Durum</th>
                                <th>İşlemler</th>
                                <th>ID</th>
                                <th>Tür</th>
                                <th>Tarih</th>
                                <th>Bölüm</th>
                                <th>Cari / Firma</th>
                                <th>Tur Kodu</th>
                                <th>Fatura No</th>
                                <th>Açıklama</th>
                                <th class="text-end">Tutar</th>
                                <th>Döviz</th>
                                <th>Düzenle</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="11" class="text-end fw-bold">Sayfa Toplamı:</th>
                                <th class="text-end fw-bold" id="pageTotal">0.00 ₺</th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script id="child-template" type="text/x-handlebars-template">
        <div class="p-3 bg-light border rounded">
            <div class="text-center"><i class="fa fa-spinner fa-spin text-muted"></i> Yükleniyor...</div>
        </div>
    </script>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php include 'transaction-edit.php'; ?>
    <?php include 'get-log-history.php'; ?>

    <script>
        var table;
        $(document).ready(function() {
            
            table = $('#paymentTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "api-payments-list.php",
                    "type": "POST",
                    "error": function (xhr, error, thrown) {
                        console.error("DataTables Hatası:", xhr.responseText);
                        Swal.fire("Veri Hatası", "Tablo yüklenemedi. Sunucu yanıt vermiyor.", "error");
                    }
                },
                // BURASI GÜNCELLENDİ: ARTIK İSİMLİ VERİ KULLANIYORUZ
                "columns": [
                    { "data": "detail_btn", "className": 'dt-control', "orderable": false, "defaultContent": '' },
                    { "data": "status" },     // Durum
                    { "data": "actions", "orderable": false },    // İşlemler
                    { "data": "checkbox", "orderable": false },   // Seçim
                    { "data": "type" },       // Tür
                    { "data": "date" },       // Tarih
                    { "data": "department" }, // Bölüm
                    { "data": "customer" },   // Cari
                    { "data": "tour" },       // Tur
                    { "data": "invoice", "orderable": false },    // Fatura
                    { "data": "desc" },       // Açıklama
                    { "data": "amount", "className": "text-end" },// Tutar
                    { "data": "currency" },   // Döviz
                    { "data": "edit_btn", "orderable": false }    // Düzenle
                ],
                "order": [[5, "desc"]], // Tarihe göre sırala (Date sütunu 5. sırada)
                "pageLength": 50,
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }
            });

            // Alt Detay Açma/Kapama Kodu Aynen Kalıyor...
            $('#paymentTable tbody').on('click', 'td.dt-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
        
                if (row.child.isShown()) {
                    row.child.hide();
                    tr.removeClass('shown');
                } else {
                    var rowData = row.data();
                    // ID'yi güvenli çekme
                    var checkboxHtml = rowData.checkbox; 
                    var db_id = $(checkboxHtml).data('id'); 
                    
                    if(db_id) {
                        row.child('<div class="text-center p-3"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</div>').show();
                        tr.addClass('shown');
                        $.get('get-child-transactions.php?parent_id=' + db_id, function(data) {
                            row.child(data).show();
                        });
                    }
                }
            });
            
            // ... Filtreleme kodları aynen kalıyor ...
            $('.filter-btn').on('click', function() {
                $('.filter-btn').removeClass('active');
                $(this).addClass('active');
                var status = $(this).data('status');
                table.column(1).search(status).draw(); // Status sütunu index 1
            });
            
            $('.action-filter-btn').on('click', function() {
                var action = $(this).data('action');
                if($(this).hasClass('active')) {
                    $(this).removeClass('active');
                    table.column(2).search('').draw();
                } else {
                    $('.action-filter-btn').removeClass('active');
                    $(this).addClass('active');
                    table.column(2).search(action).draw();
                }
            });
        });

        // Durum Değiştir
        function toggleStatus(id, action, btn) {
            $.post('api-payment-actions.php', { id: id, action: action }, function(res) {
                if(res.status === 'success') {
                    $(btn).toggleClass('active');
                    if (action == 'approve') $(btn).toggleClass('approval');
                    if (action == 'priority') $(btn).toggleClass('priority');
                    if (action == 'control') $(btn).toggleClass('control');
                    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
                    Toast.fire({ icon: 'success', title: res.message });
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            }, 'json');
        }

        // Silme
        function deleteTransaction(id) {
            Swal.fire({
                title: 'Emin misiniz?',
                text: "Bu işlem silinecek!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Evet, Sil'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('transaction-delete.php', { id: id }, function(res) {
                        if(res.status === 'success') {
                            table.ajax.reload(null, false);
                            Swal.fire('Silindi!', 'Kayıt silindi.', 'success');
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