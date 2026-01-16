<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ödeme Listesi</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <style>
        .filter-input { width: 100%; padding: 4px; font-size: 0.85rem; border: 1px solid #ced4da; border-radius: 4px; }
        table.dataTable thead th { vertical-align: middle; white-space: nowrap; font-size: 0.9rem; }
        table.dataTable tbody td { vertical-align: middle; font-size: 0.9rem; }
        
        /* SİZİN CSS KODLARINIZ */
        .toggle-btn { cursor: pointer; opacity: 0.3; transition: all 0.2s; font-size: 1.2rem; }
        .toggle-btn:hover { transform: scale(1.2); opacity: 0.6; }
        .toggle-btn.active { opacity: 1; transform: scale(1.1); }
        
        .text-approval.active { color: #198754; } /* Yeşil */
        .text-priority.active { color: #dc3545; } /* Kırmızı */
        .text-control.active  { color: #fd7e14; } /* Turuncu */
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Ödeme Listesi</h2>
            <a href="transaction-add.php" class="btn btn-success"><i class="fa fa-plus"></i> Yeni İşlem Ekle</a>
        </div>

        <div class="card shadow">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="paymentTable" class="table table-striped table-bordered table-hover table-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th width="90" class="text-center">İşlemler</th>
                                <th width="30">ID</th>
                                <th>Bölüm</th>
                                <th>Tarih</th>
                                <th>Belge</th>
                                <th>Cari Kart</th>
                                <th>Tur Kodu</th>
                                <th>Fatura No</th>
                                <th class="text-end">Tutar (TL)</th>
                                <th class="text-end">Döviz</th>
                                <th width="40">Düzenle</th>
                            </tr>
                            <tr class="filters">
                                <td></td> 
                                <td><input type="text" class="filter-input" placeholder="ID"></td>
                                <td><input type="text" class="filter-input" placeholder="Bölüm"></td>
                                <td><input type="text" class="filter-input" placeholder="Tarih"></td>
                                <td></td>
                                <td><input type="text" class="filter-input" placeholder="Cari Ara..."></td>
                                <td><input type="text" class="filter-input" placeholder="Tur Kodu"></td>
                                <td><input type="text" class="filter-input" placeholder="Fatura"></td>
                                <td></td> 
                                <td></td> 
                                <td></td> 
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
        $(document).ready(function() {
            var table = $('#paymentTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "api-payments-list.php",
                    "type": "POST"
                },
                "pageLength": 100,
                "order": [[ 3, "desc" ]],
                "columns": [
                    { "orderable": false, "data": 0 }, // İşlemler (API'den 0. index)
                    { "data": 1 },
                    { "data": 2 },
                    { "data": 3 },
                    { "data": 4, "orderable": false },
                    { "data": 5 },
                    { "data": 6 },
                    { "data": 7 },
                    { "data": 8, "className": "text-end" },
                    { "data": 9, "className": "text-end" },
                    { "orderable": false, "data": 10 }
                ],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                "orderCellsTop": true
            });

            // Filtreleme
            $('#paymentTable thead tr.filters .filter-input').on('keyup change', function(e) {
                if (e.keyCode == 13 || e.type == 'change') {
                    var colIndex = $(this).parent().index();
                    table.column(colIndex).search(this.value).draw();
                }
            });
        });

        // --- BUTON TIKLAMA İŞLEMİ ---
        function toggleStatus(id, type, element) {
            // Tipi API formatına çevir
            var action = 'toggle_' + type; // Örn: toggle_is_approved, toggle_priority

            $.post('api-payment-actions.php', { id: id, action: action }, function(response) {
                if(response.status === 'success') {
                    // Sayfayı yenilemeden tabloyu güncelle
                    $('#paymentTable').DataTable().ajax.reload(null, false);
                    
                    const Toast = Swal.mixin({
                        toast: true, position: 'top-end', showConfirmButton: false, timer: 1500, timerProgressBar: true
                    });
                    Toast.fire({ icon: 'success', title: 'Güncellendi' });
                } else {
                    Swal.fire('Hata', response.message, 'error');
                }
            }, 'json');
        }

        function openEditModal(id) {
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            $('#editModalBody').html('<div class="alert alert-info text-center">Düzenleme formu yükleniyor... ID: '+id+'</div>');
        }
    </script>
</body>
</html>