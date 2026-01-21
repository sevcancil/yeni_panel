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
        .filter-input { width: 100%; padding: 3px; font-size: 0.8rem; border: 1px solid #ced4da; border-radius: 3px; }
        .dt-control { cursor: pointer; text-align: center; vertical-align: middle; color: #0d6efd; }
        .dt-control:hover { color: #0a58ca; }
        
        #selection-bar {
            position: fixed; bottom: -100px; left: 0; width: 100%;
            background-color: #343a40; color: white; padding: 15px 40px;
            z-index: 1050; transition: bottom 0.3s ease-in-out;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
            display: flex; justify-content: space-between; align-items: center;
        }
        #selection-bar.show { bottom: 0; }
        .fa-history {opacity: 1 !important;}
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center">
                <h2 class="mb-0">Finansal Operasyonlar</h2>
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
                                <th width="120">İşlemler</th> 
                                <th width="20">ID</th> 
                                <th>Bölüm</th> 
                                <th>Tarih</th> 
                                <th>Belge</th> 
                                <th>Cari / Firma</th> 
                                <th>Tur Kodu</th> 
                                <th>Fatura</th> 
                                <th class="text-end">Tutar (TL)</th> 
                                <th class="text-end">Döviz</th> 
                                <th width="40">Edit</th> 
                            </tr>
                            
                            <tr class="filters">
                                <td></td> <td></td> <td></td> 
                                <td><input type="text" class="filter-input" placeholder="ID" data-col-index="3"></td>
                                <td><input type="text" class="filter-input" placeholder="Bölüm" data-col-index="4"></td>
                                <td><input type="text" class="filter-input" placeholder="Tarih" data-col-index="5"></td>
                                <td></td> 
                                <td><input type="text" class="filter-input" placeholder="Cari Ara..." data-col-index="7"></td>
                                <td><input type="text" class="filter-input" placeholder="Tur Kodu" data-col-index="8"></td>
                                <td><input type="text" class="filter-input" placeholder="Fatura" data-col-index="9"></td>
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
            <span class="badge bg-light text-dark me-2 fs-5" id="selected-count">0</span>
            <span class="fs-5">Kayıt Seçildi</span>
        </div>
        <div class="d-flex align-items-center">
            <span class="me-3 fs-4">Toplam: <strong class="text-warning" id="selected-total">0,00 ₺</strong></span>
            <button class="btn btn-primary" onclick="alert('Birleştirme özelliği yakında!')"><i class="fa fa-link"></i> Seçilenleri Birleştir / Öde</button>
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

    <div class="modal fade" id="logModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fa fa-history"></i> İşlem Tarihçesi ve Loglar</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="logModalBody">
                    <div class="text-center p-4"><div class="spinner-border text-info"></div></div>
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
            var id = 0;

            // HTML içinden ID'yi güvenli şekilde al
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = idHtml;
            
            // 1. Yöntem: Checkbox'ın data-id özelliğinden al
            var checkbox = tempDiv.querySelector('input[type="checkbox"]');
            if (checkbox) {
                id = checkbox.getAttribute('data-id');
            } 
            // 2. Yöntem: Span içindeki metinden al
            else {
                id = tempDiv.innerText.replace('#', '').trim();
            }

            var div = $('<div/>').addClass('p-3 bg-light border rounded m-2').attr('id', 'details-' + id)
                .html('<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Alt işlemler yükleniyor...</div>');
            
            $.get('get-child-transactions.php?parent_id=' + id, function(content){ div.html(content); })
             .fail(function(){ div.html('<div class="alert alert-danger m-0">Veri yüklenemedi.</div>'); });
            return div;
        }

        $(document).ready(function() {
            var table = $('#paymentTable').DataTable({
                "processing": true,
                "serverSide": true,
                "orderCellsTop": true,
                "ajax": {
                    "url": "api-payments-list.php",
                    "type": "POST",
                    "data": function(d) { }
                },
                "pageLength": 50,
                "lengthMenu": [[50, 100, 250], [50, 100, 250]],
                "order": [[ 5, "desc" ]],
                "columns": [
                    { "className": 'dt-control', "orderable": false, "data": 0, "defaultContent": '<i class="fa fa-plus-circle fa-lg"></i>' }, 
                    { "data": 1 }, 
                    { "data": 2 }, 
                    { "data": 3, "orderable": false }, 
                    { "data": 4 }, 
                    { "data": 5 }, 
                    { "data": 6 }, 
                    { "data": 7 }, 
                    { "data": 8 }, 
                    { "data": 9 }, 
                    { "data": 10, "className": "text-end" }, 
                    { "data": 11, "className": "text-end" }, 
                    { "data": 12, "orderable": false } 
                ],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                
                initComplete: function () {
                    var api = this.api();
                    $('.filter-input').each(function () {
                        var $input = $(this);
                        var colIndex = $input.data('col-index');
                        $input.off('keyup change').on('keyup change', function (e) {
                            if (e.keyCode == 13) {
                                if (api.column(colIndex).search() !== this.value) {
                                    api.column(colIndex).search(this.value).draw();
                                }
                            }
                        });
                    });
                }
            });

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

            $('#paymentTable tbody').on('change', '.row-select', function() {
                var total = 0;
                var count = 0;
                $('.row-select:checked').each(function() {
                    total += parseFloat($(this).data('amount') || 0);
                    count++;
                });
                $('#selected-count').text(count);
                $('#selected-total').text(total.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺');
                
                if(count > 0) $('#selection-bar').addClass('show');
                else $('#selection-bar').removeClass('show');
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
            $('#editModalBody').html('<div class="text-center p-4"><div class="spinner-border text-primary"></div></div>');
            $('#editModalBody').load('transaction-edit.php?id=' + id);
        }

        function openLogModal(id) {
            var modal = new bootstrap.Modal(document.getElementById('logModal'));
            modal.show();
            $('#logModalBody').html('<div class="text-center p-4"><div class="spinner-border text-info"></div></div>');
            $('#logModalBody').load('get-log-history.php?id=' + id, function(response, status, xhr) {
                if (status == "error") $('#logModalBody').html('<div class="alert alert-danger">Hata oluştu.</div>');
            });
        }
    </script>
</body>
</html>