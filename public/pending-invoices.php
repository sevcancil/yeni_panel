<?php
// public/pending-invoices.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// SORGULAMA MANTIĞI:
// 1. Tip: invoice_order (Tahsilat Emri / Gelir)
// 2. Ana İşlem (parent_id IS NULL)
// 3. Fatura No BOŞ olanlar (NULL veya '')
$sql = "SELECT t.*, c.company_name, tc.code as tour_code 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.doc_type = 'invoice_order' 
        AND t.parent_id IS NULL 
        AND (t.invoice_no IS NULL OR t.invoice_no = '')
        ORDER BY t.date ASC"; // Eskiden yeniye (Acil olanlar üstte)

$pendings = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Fatura Bekleyen Tahsilatlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="text-danger"><i class="fa fa-file-invoice"></i> Fatura Bekleyen Tahsilatlar</h2>
                <p class="text-muted">Parası planlanmış veya tahsil edilmiş ancak henüz faturası kesilip sisteme girilmemiş işlemler.</p>
            </div>
            <a href="payment-orders.php" class="btn btn-secondary">Tüm Liste</a>
        </div>

        <div class="card shadow">
            <div class="card-body">
                <table id="pendingTable" class="table table-hover table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tarih</th>
                            <th>Cari / Müşteri</th>
                            <th>Tur Kodu</th>
                            <th>Açıklama</th>
                            <th class="text-end">Tutar</th>
                            <th class="text-center">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pendings as $row): ?>
                            <tr>
                                <td><?php echo date('d.m.Y', strtotime($row['date'])); ?></td>
                                <td class="fw-bold"><?php echo guvenli_html($row['company_name']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo $row['tour_code']; ?></span></td>
                                <td><?php echo guvenli_html($row['description']); ?></td>
                                <td class="text-end text-success fw-bold">
                                    <?php echo number_format($row['amount'], 2, ',', '.'); ?> ₺
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-primary btn-sm" onclick='openInvoiceModal(<?php echo json_encode($row); ?>)'>
                                        <i class="fa fa-upload"></i> Fatura Yükle
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="invoiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Fatura Bilgisi Gir</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="invoiceForm" enctype="multipart/form-data">
                        <input type="hidden" name="id" id="trans_id">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small">Cari</label>
                            <input type="text" id="display_company" class="form-control-plaintext fw-bold" readonly>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fatura No <span class="text-danger">*</span></label>
                                <input type="text" name="invoice_no" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fatura Tarihi</label>
                                <input type="date" name="invoice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Fatura Dosyası (PDF/Resim)</label>
                            <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success">Kaydet ve Listeden Düşür</button>
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
            $('#pendingTable').DataTable({
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" },
                "order": [[ 0, "asc" ]] // Tarihe göre eskiden yeniye
            });
        });

        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));

        function openInvoiceModal(data) {
            document.getElementById('trans_id').value = data.id;
            document.getElementById('display_company').value = data.company_name + ' (' + Number(data.amount).toLocaleString('tr-TR') + ' ₺)';
            document.getElementById('invoiceForm').reset();
            invoiceModal.show();
        }

        // Form Gönderimi (AJAX)
        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            $.ajax({
                url: 'api-upload-invoice.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response) {
                    if(response.status === 'success') {
                        invoiceModal.hide();
                        Swal.fire({
                            icon: 'success',
                            title: 'Harika!',
                            text: 'Fatura işlendi ve listeden kaldırıldı.',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload(); // Sayfayı yenile
                        });
                    } else {
                        Swal.fire('Hata', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error');
                }
            });
        });
    </script>
</body>
</html>