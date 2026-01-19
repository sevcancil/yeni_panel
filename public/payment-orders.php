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
        .filter-input { width: 100%; padding: 3px; font-size: 0.8rem; border: 1px solid #ced4da; }
        
        .dt-control { 
            cursor: pointer; text-align: center; vertical-align: middle; color: #0d6efd;
        }
        .dt-control:hover { color: #0a58ca; }
        
        /* Renkler */
        .text-approval.active { color: #198754; } /* Yeşil */
        .text-priority.active { color: #dc3545; } /* Kırmızı */
        .text-control.active { color: #fd7e14; } /* Turuncu */
        
        /* ADMIN İÇİN (TIKLANABİLİR) */
        .toggle-btn { 
            opacity: 0.3; transition: all 0.2s; font-size: 1.2rem; margin: 0 4px; cursor: pointer; 
        }
        .toggle-btn:hover { transform: scale(1.2); opacity: 0.7; }
        .toggle-btn.active { opacity: 1; transform: scale(1.1); }

        /* PERSONEL İÇİN (TIKLANAMAZ) - YENİ EKLENDİ */
        .disabled-btn {
            opacity: 0.3; font-size: 1.2rem; margin: 0 4px; cursor: not-allowed;
        }
        .disabled-btn.active { opacity: 1; } /* Aktifse net görünsün ama tıklanmasın */
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center">
                <h2 class="mb-0">Finansal Operasyonlar</h2>
                
                <div class="form-check form-switch ms-4 pt-1">
                    <input class="form-check-input cursor-pointer" type="checkbox" id="filterInvoicePending" style="width: 3em; height: 1.5em;">
                    <label class="form-check-label fw-bold text-danger ms-2 pt-1" for="filterInvoicePending">
                        <i class="fa fa-file-invoice"></i> Fatura Bekleyenler
                    </label>
                </div>
            </div>

            <a href="transaction-add.php" class="btn btn-success"><i class="fa fa-plus"></i> Yeni Talep Oluştur</a>
        </div>

        <div class="card shadow">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="paymentTable" class="table table-bordered table-hover table-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th width="20"></th> 
                                <th width="70">Durum</th> 
                                <th width="90">İşlemler</th> 
                                <th width="30">ID</th> 
                                <th>Bölüm</th>
                                <th>Tarih</th>
                                <th>Belge</th>
                                <th>Cari / Firma</th>
                                <th>Tur Kodu</th>
                                <th>Fatura</th>
                                <th class="text-end">Tutar (TL)</th>
                                <th class="text-end">Döviz</th>
                                <th width="40">Düzenle</th>
                            </tr>
                            <tr class="filters">
                                <td></td> <td></td> <td></td> 
                                <td><input type="text" class="filter-input" placeholder="ID"></td>
                                <td><input type="text" class="filter-input" placeholder="Bölüm"></td>
                                <td><input type="text" class="filter-input" placeholder="Tarih"></td>
                                <td></td> 
                                <td><input type="text" class="filter-input" placeholder="Cari Ara..."></td>
                                <td><input type="text" class="filter-input" placeholder="Tur Kodu"></td>
                                <td><input type="text" class="filter-input" placeholder="Fatura"></td>
                                <td></td> <td></td> <td></td> 
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">İşlem Düzenle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editModalBody">
                    <div class="text-center p-4"><div class="spinner-border text-primary"></div></div>
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
            var id = d[3];
            var div = $('<div/>').addClass('p-3 bg-light border rounded m-2').attr('id', 'details-' + id)
                .html('<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</div>');
            $.get('get-child-transactions.php?parent_id=' + id, function(content){ div.html(content); })
             .fail(function(){ div.html('<div class="alert alert-danger m-0">Veri yüklenemedi.</div>'); });
            return div;
        }

        $(document).ready(function() {
            var table = $('#paymentTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "api-payments-list.php",
                    "type": "POST",
                    "data": function(d) {
                        d.filter_invoice_pending = $('#filterInvoicePending').is(':checked');
                    }
                },
                "pageLength": 50,
                "lengthMenu": [[50, 100, 250], [50, 100, 250]],
                "order": [[ 5, "desc" ]],
                "columns": [
                    { "className": 'dt-control', "orderable": false, "data": 0, "defaultContent": '<i class="fa fa-plus-circle fa-lg"></i>' },
                    { "data": 1 }, { "data": 2 }, { "data": 3 }, { "data": 4 }, { "data": 5 },
                    { "data": 6 }, { "data": 7 }, { "data": 8 }, { "data": 9 },
                    { "data": 10, "className": "text-end" }, { "data": 11, "className": "text-end" }, { "data": 12, "orderable": false }
                ],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                "orderCellsTop": true
            });

            $('#filterInvoicePending').on('change', function() { table.ajax.reload(); });

            $('#paymentTable tbody').on('click', 'td.dt-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
                var icon = $(this).find('i');
                if (row.child.isShown()) {
                    row.child.hide(); tr.removeClass('shown'); icon.removeClass('fa-minus-circle').addClass('fa-plus-circle');
                } else {
                    row.child(format(row.data())).show(); tr.addClass('shown'); icon.removeClass('fa-plus-circle').addClass('fa-minus-circle');
                }
            });

            $('#paymentTable thead tr.filters .filter-input').on('keyup change', function(e) {
                if (e.keyCode == 13 || e.type == 'change') {
                    var colIndex = $(this).parent().index();
                    table.column(colIndex).search(this.value).draw();
                }
            });
        });

        function toggleStatus(id, type, element) {
            var action = 'toggle_' + type;
            $(element).css('opacity', '0.5');
            $.post('api-payment-actions.php', { id: id, action: action }, function(response) {
                if(response.status === 'success') {
                    $('#paymentTable').DataTable().ajax.reload(null, false);
                    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 1500, timerProgressBar: true });
                    Toast.fire({ icon: 'success', title: 'Güncellendi' });
                } else {
                    Swal.fire('Hata', response.message, 'error');
                    $(element).css('opacity', '1');
                }
            }, 'json');
        }

        function openEditModal(id) {
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            $('#editModalBody').load('transaction-edit.php?id=' + id);
        }
    </script>
</body>
</html>