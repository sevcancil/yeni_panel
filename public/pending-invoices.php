<?php
// public/pending-invoices.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// 1. LİSTE: FATURA KESİLECEKLER (to_be_issued)
// Bu liste zaten doğru çalışıyordu, dokunmadık.
$sql_issued = "SELECT t.*, 
               c.company_name, c.tax_office, c.tax_number, c.tc_number, c.address, c.city, c.country,
               tc.code as tour_code 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.invoice_status = 'to_be_issued' 
        AND t.doc_type = 'invoice_order'
        AND (t.parent_id IS NULL OR t.parent_id = 0)
        ORDER BY t.invoice_date ASC, t.date ASC"; 
$list_issued = $pdo->query($sql_issued)->fetchAll(PDO::FETCH_ASSOC);

// 2. LİSTE: ONAY BEKLEYENLER
// --- DÜZELTME: Hem 'waiting_approval' hem de 'no_invoice' olanları çekiyoruz ---
// Çünkü transaction-add.php'de artık varsayılan olarak 'waiting_approval' basıyoruz.
// Eski kayıtlar veya 'no_invoice' olarak işaretlenenler de görünsün diye IN() kullandım.
$sql_waiting = "SELECT t.*, 
               c.company_name, 
               tc.code as tour_code 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.invoice_status IN ('waiting_approval', 'no_invoice') 
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
        .row-overdue { background-color: #ffe6e6 !important; } /* Gecikmiş Fatura Rengi */
        .row-today { background-color: #fff8e1 !important; } /* Bugün Kesilecek Rengi */
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
                            <th>Fatura Tarihi (Planlanan)</th> <th>Cari / Müşteri</th>
                            <th>Açıklama</th>
                            <th class="text-end">Tutar</th>
                            <th class="text-center" width="150">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($list_issued as $row): 
                            $plan_date = !empty($row['invoice_date']) ? $row['invoice_date'] : $row['date'];
                            $today = date('Y-m-d');
                            $row_class = '';
                            $date_badge = '';

                            if ($plan_date < $today) {
                                $row_class = 'row-overdue';
                                $date_badge = '<span class="badge bg-danger">GECİKMİŞ</span>';
                            } elseif ($plan_date == $today) {
                                $row_class = 'row-today';
                                $date_badge = '<span class="badge bg-warning text-dark">BUGÜN</span>';
                            }
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="fw-bold">#<?php echo $row['id']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa fa-calendar-alt text-muted"></i>
                                        <strong><?php echo date('d.m.Y', strtotime($plan_date)); ?></strong>
                                        <?php echo $date_badge; ?>
                                    </div>
                                    <small class="text-muted d-block mt-1">İşlem Tarihi: <?php echo date('d.m.Y', strtotime($row['date'])); ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo guvenli_html($row['company_name']); ?></div>
                                    <small class="text-muted"><?php echo $row['tour_code'] ? $row['tour_code'] : ''; ?></small>
                                </td>
                                <td><?php echo guvenli_html($row['description']); ?></td>
                                <td class="text-end fw-bold text-success fs-5">
                                    <?php echo number_format($row['amount'], 2, ',', '.') . ' TL'; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-success btn-sm w-100 shadow-sm" onclick='openInvoiceModal(<?php echo json_encode($row); ?>, "<?php echo $plan_date; ?>")'>
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
                            <th>Tarih</th> <th>Cari / Müşteri</th>
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
                                    <?php echo number_format($row['amount'], 2, ',', '.') . ' TL'; ?>
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
                                <input type="date" name="invoice_date" id="modal_invoice_date" class="form-control" required>
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
            $('#tableIssued').DataTable({ "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }, "paging": false, "info": false, "searching": false, "ordering": false });
            $('#tableWaiting').DataTable({ "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }, "paging": false, "info": false });
        });

        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));

        function openInvoiceModal(data, planDate) {
            document.getElementById('trans_id').value = data.id;
            document.getElementById('info_company').innerText = data.company_name;
            document.getElementById('modal_invoice_date').value = planDate;
            document.getElementById('info_amount').innerText = parseFloat(data.amount).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' TL';
            
            var taxInfo = data.tax_number ? (data.tax_office + ' VD - ' + data.tax_number) : (data.tc_number ? 'TC: '+data.tc_number : '');
            document.getElementById('info_tax').innerText = taxInfo;

            document.getElementById('invoiceForm').reset();
            setTimeout(() => { document.getElementById('modal_invoice_date').value = planDate; }, 100);
            
            invoiceModal.show();
        }

        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            $.ajax({
                url: 'api-upload-invoice.php', type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
                success: function(res) {
                    if(res.status === 'success') {
                        invoiceModal.hide();
                        Swal.fire({ icon: 'success', title: 'Başarılı', text: 'Fatura işlendi.', timer: 1500, showConfirmButton: false }).then(() => location.reload());
                    } else { Swal.fire('Hata', res.message, 'error'); }
                }
            });
        });

        function approveInvoice(id) {
            Swal.fire({
                title: 'Onaylıyor musunuz?', text: "Bu işlem 'Fatura Kesilecekler' listesine taşınacak.", icon: 'question', showCancelButton: true, confirmButtonText: 'Evet, Onayla', cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('api-payment-actions.php', { id: id, action: 'approve_invoice' }, function(res) {
                        if(res.status === 'success') { location.reload(); } 
                        else { Swal.fire('Hata', res.message, 'error'); }
                    }, 'json');
                }
            });
        }
    </script>
</body>
</html>