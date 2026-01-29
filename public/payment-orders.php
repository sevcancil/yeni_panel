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
        
        .action-icon { 
            color: #d1d1d1; transition: all 0.2s ease-in-out; font-size: 1.2rem; cursor: pointer;
        }
        .action-icon:hover { transform: scale(1.3); color: #888; }
        
        .action-icon.active.approval { color: #198754 !important; }
        .action-icon.active.priority { color: #dc3545 !important; } 
        .action-icon.active.control  { color: #fd7e14 !important; }
        
        .disabled-btn { opacity: 0.2; cursor: not-allowed; pointer-events: none; }
        #selection-bar { position: fixed; bottom: -100px; left: 0; width: 100%; background-color: #343a40; color: white; padding: 15px 40px; z-index: 1050; transition: bottom 0.3s; display: flex; justify-content: space-between; align-items: center; }
        #selection-bar.show { bottom: 0; }
        th { font-size: 0.9rem; white-space: nowrap; }
        td { font-size: 0.9rem; vertical-align: middle; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Finansal Operasyonlar</h2>
            <a href="transaction-add.php" class="btn btn-success"><i class="fa fa-plus"></i> Yeni Talep Oluştur</a>
        </div>

        <div class="card shadow">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="paymentTable" class="table table-bordered table-hover table-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th width="20"></th> <th width="80">Durum</th> <th width="120">İşlemler</th> 
                                <th width="30">ID</th> <th width="80">Belge</th> <th width="80">Tarih</th> 
                                <th>Bölüm</th> <th>Cari / Firma</th> <th>Tur Kodu</th> 
                                <th>Fatura No</th> <th>Açıklama</th> 
                                <th class="text-end">Tutar (TL)</th> <th class="text-end">Döviz</th> <th width="40">Edit</th> 
                            </tr>
                            <tr class="filters">
                                <td></td> 
                                <td><select class="filter-select" data-col-index="1"><option value="">Tümü</option><option value="paid">Tamamlandı</option><option value="unpaid">Planlandı</option><option value="partial">Kısmi</option></select></td> 
                                <td><select class="filter-select" data-col-index="2"><option value="">Filtrele...</option><option value="approved">Onaylılar</option><option value="priority">Öncelikliler</option><option value="control">Kontrol Gereken</option></select></td> 
                                <td><input type="text" class="filter-input" placeholder="ID" data-col-index="3"></td>
                                <td><select class="filter-select" data-col-index="4"><option value="">Tümü</option><option value="invoice_order">Fatura</option><option value="payment_order">Ödeme</option></select></td>
                                <td><input type="text" class="filter-input" placeholder="Tarih" data-col-index="5"></td>
                                <td><input type="text" class="filter-input" placeholder="Bölüm" data-col-index="6"></td>
                                <td><input type="text" class="filter-input" placeholder="Cari Ara..." data-col-index="7"></td>
                                <td><input type="text" class="filter-input" placeholder="Tur Kodu" data-col-index="8"></td>
                                <td><input type="text" class="filter-input" placeholder="Fatura No" data-col-index="9"></td> 
                                <td><input type="text" class="filter-input" placeholder="Açıklama" data-col-index="10"></td>
                                <td></td> <td></td> <td></td> 
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
            <button class="btn btn-primary" onclick="alert('Birleştirme özelliği yakında!')"><i class="fa fa-link"></i> Seçilenleri Birleştir / Öde</button>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title">İşlem Düzenle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="editModalBody"></div></div></div></div>
    
    <div class="modal fade" id="logModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-info text-white"><h5 class="modal-title">İşlem Tarihçesi</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="logModalBody"></div></div></div></div>

    <div class="modal fade" id="invoiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fa fa-file-invoice"></i> Fatura Girişi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3 p-2 small">
                        <strong id="inv_company"></strong><br>
                        Tutar: <span class="text-success fw-bold" id="inv_amount"></span> | ID: <span id="inv_id"></span>
                    </div>
                    <form id="invoiceForm" enctype="multipart/form-data">
                        <input type="hidden" name="id" id="inv_trans_id">
                        <div class="mb-3"><label class="form-label fw-bold">Fatura Numarası</label><input type="text" name="invoice_no" id="inv_no_input" class="form-control" required placeholder="Gelen Fatura No"></div>
                        <div class="mb-3"><label class="form-label fw-bold">Fatura Tarihi</label><input type="date" name="invoice_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="mb-3"><label class="form-label">Fatura Dosyası (PDF/Resim)</label><input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg"></div>
                        <div class="d-grid"><button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Faturayı Kaydet</button></div>
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
        function format(d) {
            var idHtml = d[3]; 
            var id = idHtml.match(/data-id="(\d+)"/) ? idHtml.match(/data-id="(\d+)"/)[1] : idHtml.replace(/[^0-9]/g, '');
            var div = $('<div/>').addClass('p-3 bg-light border rounded m-2').attr('id', 'details-' + id).html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</div>');
            $.get('get-child-transactions.php?parent_id=' + id, function(content){ div.html(content); }).fail(function(){ div.html('Hata.'); });
            return div;
        }

        $(document).ready(function() {
            var table = $('#paymentTable').DataTable({
                "processing": true, "serverSide": true, "orderCellsTop": true,
                "ajax": { "url": "api-payments-list.php", "type": "POST" },
                "pageLength": 50, "lengthMenu": [[50, 100, 250], [50, 100, 250]],
                "order": [[ 5, "desc" ]],
                "columns": [
                    { "className": 'dt-control', "orderable": false, "data": 0, "defaultContent": '<i class="fa fa-plus-circle fa-lg"></i>' }, 
                    { "data": 1 }, { "data": 2, "orderable": false }, { "data": 3 }, { "data": 4 }, 
                    { "data": 5 }, { "data": 6 }, { "data": 7 }, { "data": 8 }, { "data": 9 }, 
                    { "data": 10 }, { "data": 11, "className": "text-end" }, { "data": 12, "className": "text-end" }, { "data": 13, "orderable": false } 
                ],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                initComplete: function () {
                    var api = this.api();
                    $('.filter-input, .filter-select').each(function () {
                        var $el = $(this);
                        $el.off('keyup change').on('keyup change', function (e) {
                            if (e.type === 'change' || e.keyCode == 13) {
                                api.column($el.data('col-index')).search(this.value).draw();
                            }
                        });
                    });
                }
            });

            $('#paymentTable tbody').on('click', 'td.dt-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
                var icon = $(this).find('i');
                if (row.child.isShown()) { row.child.hide(); tr.removeClass('shown'); icon.removeClass('fa-minus-circle').addClass('fa-plus-circle'); }
                else { row.child(format(row.data())).show(); tr.addClass('shown'); icon.removeClass('fa-plus-circle').addClass('fa-minus-circle'); }
            });
        });

        // --- FATURA MODAL FONKSİYONLARI ---
        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));

        function openInvoiceModal(data) {
            document.getElementById('inv_trans_id').value = data.id;
            document.getElementById('inv_company').innerText = $(data.company_name).text() || data.company_name;
            document.getElementById('inv_id').innerText = '#' + data.id;
            
            var tempDiv = document.createElement("div");
            tempDiv.innerHTML = data.amount;
            var amountText = tempDiv.textContent || tempDiv.innerText || "";
            document.getElementById('inv_amount').innerText = amountText;

            document.getElementById('inv_no_input').value = data.invoice_no || '';
            invoiceModal.show();
        }

        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            $.ajax({
                url: 'api-upload-invoice.php',
                type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
                success: function(res) {
                    if(res.status === 'success') {
                        invoiceModal.hide();
                        Swal.fire({ icon: 'success', title: 'Başarılı', text: 'Fatura kaydedildi ve detaylara eklendi.', timer: 1500, showConfirmButton: false }).then(() => {
                            $('#paymentTable').DataTable().ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                    }
                }
            });
        });

        // --- DİĞER FONKSİYONLAR ---
        function deleteTransaction(id) {
            Swal.fire({ title: 'Silinsin mi?', text: "Bu işlem geri alınamaz!", icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Sil' }).then((result) => {
                if (result.isConfirmed) {
                    $.post('transaction-delete.php', { id: id }, function(res) {
                        if(res.status === 'success') { $('#paymentTable').DataTable().ajax.reload(null, false); Swal.fire('Silindi', '', 'success'); }
                        else Swal.fire('Hata', res.message, 'error');
                    }, 'json');
                }
            });
        }

        function toggleStatus(id, type, element) {
            $.post('api-payment-actions.php', { id: id, action: 'toggle_' + type }, function(res) {
                if(res.status === 'success') { 
                    $('#paymentTable').DataTable().ajax.reload(null, false);
                    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
                    Toast.fire({ icon: 'success', title: 'Güncellendi' });
                }
            }, 'json');
        }

        function openEditModal(id) {
            new bootstrap.Modal(document.getElementById('editModal')).show();
            $('#editModalBody').load('transaction-edit.php?id=' + id);
        }

        function openLogModal(id) {
            new bootstrap.Modal(document.getElementById('logModal')).show();
            $('#logModalBody').load('get-log-history.php?id=' + id);
        }
    </script>
</body>
</html>