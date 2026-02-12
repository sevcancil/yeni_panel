<?php
// public/payment-orders.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Finansal Operasyonlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        .filter-input, .filter-select { width: 100%; padding: 3px; font-size: 0.8rem; border: 1px solid #ced4da; border-radius: 3px; }
        .dt-control { cursor: pointer; text-align: center; vertical-align: middle; color: #0d6efd; }
        .dt-control:hover { color: #0a58ca; }
        #selection-bar { position: fixed; bottom: -100px; left: 0; width: 100%; background-color: #343a40; color: white; padding: 15px 40px; z-index: 1050; transition: bottom 0.3s; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 -2px 10px rgba(0,0,0,0.2); }
        #selection-bar.show { bottom: 0; }
        th { font-size: 0.85rem; white-space: nowrap; vertical-align: middle; }
        td { font-size: 0.85rem; vertical-align: middle; }
        .date-range-container input { font-size: 0.75rem; padding: 2px 4px; }
        optgroup { font-weight: bold; color: #555; background-color: #f8f9fa; }
        .cursor-pointer { cursor: pointer; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 mt-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0 text-dark fw-bold"><i class="fa fa-list-ul text-secondary"></i> Finansal Operasyonlar</h2>
            <div class="d-flex gap-2">
                <a href="payment-approval.php" class="btn btn-outline-dark"><i class="fa fa-check-double"></i> Ödeme Onayı</a>
                <a href="transaction-add.php" class="btn btn-success shadow-sm"><i class="fa fa-plus"></i>Ödeme / Fatura Emri Gir</a>
            </div>
        </div>

        <div class="card shadow border-0">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="paymentTable" class="table table-bordered table-hover table-sm w-100 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="10"></th> 
                                <th width="120">Durum</th> 
                                <th width="30" class="text-center">Onay</th> <th width="60">ID</th> 
                                <th width="60">Belge</th> 
                                <th width="80">Tarih</th> 
                                <th>Bölüm</th> 
                                <th>Cari / Firma</th> 
                                <th>Tur Kodu</th> 
                                <th>Fatura No</th> 
                                <th>Açıklama</th> 
                                <th class="text-end">Tutar (TL)</th> 
                                <th class="text-end">Döviz</th> 
                                <th width="30" class="text-center"><i class="fa fa-edit"></i></th> 
                                <th width="30" class="text-center"><i class="fa fa-trash"></i></th> 
                                <th width="30" class="text-center"><i class="fa fa-history"></i></th> 
                            </tr>
                            <tr class="filters">
                                <td></td> 
                                <td>
                                    <select class="filter-select fw-bold text-dark" data-col-index="1">
                                        <option value="">Tümü</option>
                                        <optgroup label="GELİR"><option value="income_invoice_paid">Tahsil Edildi</option><option value="income_invoice_unpaid">Bekliyor</option></optgroup>
                                        <optgroup label="GİDER"><option value="expense_invoice_paid">Ödendi</option><option value="expense_invoice_unpaid">Bekliyor</option></optgroup>
                                        <optgroup label="DİĞER"><option value="partial">Kısmi</option><option value="planned">Planlandı</option></optgroup>
                                    </select>
                                </td> 
                                <td>
                                    <select class="filter-select text-center" data-col-index="2">
                                        <option value="">-</option>
                                        <option value="approved">✔</option>
                                        <option value="pending">⏳</option>
                                        <option value="rejected">✖</option>
                                    </select>
                                </td>
                                <td><input type="text" class="filter-input" placeholder="ID" data-col-index="3"></td>
                                <td><select class="filter-select" data-col-index="4"><option value="">Tümü</option><option value="invoice_order">Fatura</option><option value="payment_order">Ödeme</option></select></td>
                                <td>
                                    <div class="d-flex flex-column gap-1 date-range-container">
                                        <input type="date" class="form-control form-control-sm date-filter" id="date_start">
                                        <input type="date" class="form-control form-control-sm date-filter" id="date_end">
                                    </div>
                                </td>
                                <td><input type="text" class="filter-input" placeholder="Bölüm" data-col-index="6"></td>
                                <td><input type="text" class="filter-input" placeholder="Cari..." data-col-index="7"></td>
                                <td><input type="text" class="filter-input" placeholder="Tur" data-col-index="8"></td>
                                <td><input type="text" class="filter-input" placeholder="Fatura" data-col-index="9"></td> 
                                <td><input type="text" class="filter-input" placeholder="Açıklama" data-col-index="10"></td>
                                <td></td> <td></td> <td></td> <td></td> <td></td>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="selection-bar">
        <div class="d-flex align-items-center">
            <span class="badge bg-light text-dark me-2 fs-5" id="selected-count">0</span><span class="fs-5">Kayıt Seçildi</span>
        </div>
        <div class="d-flex align-items-center">
            <span class="me-3 fs-4">Toplam: <strong class="text-warning" id="selected-total">0,00 ₺</strong></span>
            <button class="btn btn-primary shadow" onclick="openMergeModal()">
                <i class="fa fa-layer-group"></i> Seçilenleri Birleştir / Öde
            </button>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title">İşlem Düzenle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="editModalBody"></div></div></div></div>
    <div class="modal fade" id="logModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-info text-white"><h5 class="modal-title">İşlem Tarihçesi</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="logModalBody"></div></div></div></div>
    
    <div class="modal fade" id="invoiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white"><h5 class="modal-title">Fatura Girişi</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <form id="invoiceForm" enctype="multipart/form-data">
                        <input type="hidden" name="id" id="inv_trans_id">
                        <div class="mb-3"><label class="form-label fw-bold">Fatura No</label><input type="text" name="invoice_no" id="inv_no_input" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label fw-bold">Fatura Tarihi</label><input type="date" name="invoice_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="mb-3"><label class="form-label">Dosya</label><input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png"></div>
                        <div class="d-grid"><button type="submit" class="btn btn-success">Kaydet</button></div>
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
        // Yönetici Notunu Göster
        window.showNote = function(note) {
            Swal.fire({
                title: 'Yönetici Notu',
                text: note || 'Not girilmemiş.',
                icon: 'info',
                confirmButtonText: 'Tamam'
            });
        };

        function format(d) {
            var div = document.createElement('div');
            div.innerHTML = d[3]; // ID Sütunu (Index 3 oldu)
            var idInput = div.querySelector('input');
            var id = idInput ? idInput.value : '';
            
            var contentDiv = $('<div/>').addClass('p-3 bg-light border rounded m-2').html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> Hareketler yükleniyor...</div>');
            if(id) $.get('get-child-transactions.php?parent_id=' + id, function(content){ contentDiv.html(content); });
            return contentDiv;
        }

        $(document).ready(function() {
            var table = $('#paymentTable').DataTable({
                "processing": true, "serverSide": true, "orderCellsTop": true,
                "ajax": { "url": "api-payments-list.php", "type": "POST" },
                "pageLength": 50, "lengthMenu": [[50, 100, 250], [50, 100, 250]],
                "order": [[ 5, "desc" ]], // Tarih Indexi
                "columns": [
                    { "className": 'dt-control', "orderable": false, "data": 0, "defaultContent": '<i class="fa fa-plus-circle fa-lg text-primary"></i>' }, 
                    { "data": 1 }, // Durum
                    { "data": 2, "className": "text-center" }, // ONAY (YENİ)
                    { "data": 3 }, // ID
                    { "data": 4 }, // Belge
                    { "data": 5 }, // Tarih
                    { "data": 6 }, // Bölüm
                    { "data": 7 }, // Cari
                    { "data": 8 }, // Tur
                    { "data": 9 }, // Fatura
                    { "data": 10 }, // Açıklama
                    { "data": 11, "className": "text-end" }, // Tutar
                    { "data": 12, "className": "text-end" }, // Döviz
                    { "data": 13, "orderable": false, "className": "text-center" }, // Edit
                    { "data": 14, "orderable": false, "className": "text-center" }, // Sil
                    { "data": 15, "orderable": false, "className": "text-center" }  // Geçmiş
                ],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                initComplete: function () {
                    var api = this.api();
                    $('.filter-input, .filter-select').on('keyup change', function (e) {
                        if (e.type === 'change' || e.keyCode == 13) api.column($(this).data('col-index')).search(this.value).draw();
                    });
                    $('.date-filter').on('change', function() {
                        api.column(5).search($('#date_start').val() + '|' + $('#date_end').val()).draw();
                    });
                }
            });

            $('#paymentTable tbody').on('click', 'td.dt-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
                if (row.child.isShown()) { row.child.hide(); tr.removeClass('shown'); }
                else { row.child(format(row.data())).show(); tr.addClass('shown'); }
            });

            $('#paymentTable tbody').on('click', 'tr', function (e) {
                if ($(e.target).closest('a, button, input[type="checkbox"], .dt-control, i.fa-info-circle').length) return;
                var checkbox = $(this).find('.row-select');
                if(checkbox.length) { checkbox.prop('checked', !checkbox.prop('checked')); calculateSelection(); }
            });

            $(document).on('change', '.row-select', function() { calculateSelection(); });
        });

        var selectedIds = [];
        function calculateSelection() {
            selectedIds = [];
            var total = 0.0;
            $('.row-select:checked').each(function() {
                selectedIds.push($(this).data('id'));
                total += parseFloat($(this).data('amount')) || 0;
            });
            $('#selected-count').text(selectedIds.length);
            $('#selected-total').text(total.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' TL');
            if(selectedIds.length > 0) $('#selection-bar').addClass('show'); else $('#selection-bar').removeClass('show');
        }

        window.openMergeModal = function() {
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            $('#editModalBody').load('transaction-merge.php?ids=' + selectedIds.join(','));
        };

        window.deleteTransaction = function(id) {
            Swal.fire({ title: 'Silinsin mi?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sil' }).then((r) => {
                if (r.isConfirmed) $.post('transaction-delete.php', { id: id }, function(res) { 
                    if(res.status==='success') $('#paymentTable').DataTable().ajax.reload(null,false); 
                }, 'json');
            });
        };
        
        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));
        window.openInvoiceModal = function(data) {
            document.getElementById('inv_trans_id').value = data.id;
            document.getElementById('inv_no_input').value = data.invoice_no || '';
            invoiceModal.show();
        };
        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({ url: 'api-upload-invoice.php', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json', success: function(res) {
                if(res.status==='success') { invoiceModal.hide(); Swal.fire('Başarılı','Fatura kaydedildi.','success').then(()=>$('#paymentTable').DataTable().ajax.reload(null,false)); }
                else Swal.fire('Hata',res.message,'error');
            }});
        });

        window.openEditModal = function(id) { new bootstrap.Modal(document.getElementById('editModal')).show(); $('#editModalBody').load('transaction-edit.php?id=' + id); };
        window.openLogModal = function(id) { new bootstrap.Modal(document.getElementById('logModal')).show(); $('#logModalBody').load('get-log-history.php?id=' + id); };
    </script>
    <div class="modal fade" id="projectReportModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fa fa-chart-line me-2"></i> Proje Finansal Raporu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="projectReportContent">
                    <div class="text-center p-5"><div class="spinner-border text-primary"></div><p>Rapor Hazırlanıyor...</p></div>
                </div>
                <div class="modal-footer no-print">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Mevcut scriptlerin altına ekle
        var projectReportModal = new bootstrap.Modal(document.getElementById('projectReportModal'));

        function openProjectReport(id) {
            projectReportModal.show();
            // Rapor içeriğini çek
            $.get('get-project-report.php?id=' + id, function(data) {
                $('#projectReportContent').html(data);
                // Gelen HTML içindeki scriptleri çalıştır (Grafikler için)
                var scripts = document.getElementById('projectReportContent').getElementsByTagName("script");
                for(var i=0; i<scripts.length; i++) { eval(scripts[i].innerText); }
            });
        }
    </script>
</body>
</html>