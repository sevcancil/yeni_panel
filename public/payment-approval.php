<?php
// public/payment-approval.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ödeme Onay Merkezi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .priority-high { border-left: 5px solid #dc3545 !important; background-color: #fff5f5 !important; }
        .priority-medium { border-left: 5px solid #ffc107 !important; }
        .priority-low { border-left: 5px solid #198754 !important; }
        .status-badge { font-size: 0.9rem; padding: 8px 12px; border-radius: 20px; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-0 text-dark fw-bold"><i class="fa fa-check-double text-primary"></i> Ödeme Onay Merkezi</h2>
                <p class="text-muted small mb-0">Ödeme emirlerini inceleyin, önceliklendirin ve onaylayın.</p>
            </div>
            <a href="payment-orders.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> Ödeme Listesine Dön</a>
        </div>

        <div class="card shadow border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="approvalTable" class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="120">İşlem</th>
                                <th width="100">Durum</th>
                                <th>Öncelik</th>
                                <th>Tarih</th>
                                <th>Talep Eden</th>
                                <th>Cari / Açıklama</th>
                                <th class="text-end">Tutar</th>
                                <th width="50" class="text-center">Log</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Ödeme Kararı Ver</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="approveForm">
                        <input type="hidden" name="id" id="app_id">
                        
                        <div class="alert alert-light border mb-3">
                            <h5 id="app_company" class="fw-bold mb-1"></h5>
                            <div class="text-danger fs-4 fw-bold" id="app_amount"></div>
                            <small class="text-muted" id="app_desc"></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Kararınız</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="status" id="st_approved" value="approved" checked onchange="toggleFields(this.value)">
                                <label class="btn btn-outline-success" for="st_approved"><i class="fa fa-check"></i> Onayla</label>

                                <input type="radio" class="btn-check" name="status" id="st_correction" value="correction_needed" onchange="toggleFields(this.value)">
                                <label class="btn btn-outline-warning" for="st_correction"><i class="fa fa-reply"></i> Düzeltme</label>
                                
                                <input type="radio" class="btn-check" name="status" id="st_rejected" value="rejected" onchange="toggleFields(this.value)">
                                <label class="btn btn-outline-danger" for="st_rejected"><i class="fa fa-times"></i> Reddet</label>
                            </div>
                        </div>

                        <div id="plan_date_div" class="mb-3">
                            <label class="form-label fw-bold">Planlanan Ödeme Tarihi</label>
                            <input type="date" name="planned_date" id="planned_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Yönetici Notu</label>
                            <textarea name="admin_note" id="admin_note" class="form-control" rows="2" placeholder="Örn: X Bankasından ödensin..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted">Öncelik Seviyesi</label>
                            <select name="priority" class="form-select form-select-sm">
                                <option value="low">Düşük</option>
                                <option value="medium" selected>Normal</option>
                                <option value="high">Acil / Yüksek</option>
                            </select>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="logModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-info text-white"><h5 class="modal-title">Tarihçe</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="logModalBody"></div></div></div></div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            var table = $('#approvalTable').DataTable({
                "processing": true, "serverSide": true,
                "ajax": { "url": "api-approval-list.php", "type": "POST" },
                "order": [[ 3, "asc" ]], // Tarihe göre
                "columns": [
                    { "data": 0, "orderable": false }, // Buton
                    { "data": 1 }, // Durum Badge
                    { "data": 2 }, // Öncelik
                    { "data": 3 }, // Tarih
                    { "data": 4 }, // Talep Eden
                    { "data": 5 }, // Cari
                    { "data": 6, "className": "text-end" }, // Tutar
                    { "data": 7, "className": "text-center", "orderable": false } // Log
                ],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                "createdRow": function(row, data) {
                    if(data.DT_RowClass) $(row).addClass(data.DT_RowClass);
                }
            });
        });

        function toggleFields(val) {
            if(val === 'approved') $('#plan_date_div').slideDown();
            else $('#plan_date_div').slideUp();
        }

        var approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
        window.openApproveModal = function(data) {
            $('#app_id').val(data.id);
            $('#app_company').text(data.company_name);
            $('#app_amount').text(data.amount_fmt);
            $('#app_desc').text(data.desc);
            
            // Eğer daha önce girilmiş bir tarih ve not varsa doldur
            if(data.note) $('#admin_note').val(data.note); else $('#admin_note').val('');
            if(data.planned_date) $('#planned_date').val(data.planned_date); else $('#planned_date').val('<?php echo date('Y-m-d'); ?>');

            toggleFields('approved');
            approveModal.show();
        };

        $('#approveForm').on('submit', function(e) {
            e.preventDefault();
            $.post('api-save-approval.php', $(this).serialize(), function(res) {
                if(res.status === 'success') {
                    approveModal.hide();
                    Swal.fire({ icon: 'success', title: 'Kaydedildi', timer: 1000, showConfirmButton: false });
                    $('#approvalTable').DataTable().ajax.reload(null, false);
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            }, 'json');
        });

        window.openLogModal = function(id) { new bootstrap.Modal(document.getElementById('logModal')).show(); $('#logModalBody').load('get-log-history.php?id=' + id); };
    </script>
</body>
</html>