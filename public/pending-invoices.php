<?php
// public/pending-invoices.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- SORGULAMA MANTIĞI (SADECE TAHSİLATLAR) ---
// 1. Tip: invoice_order (Gelir/Tahsilat)
// 2. Fatura No: BOŞ (Henüz kesilmemiş)
// 3. Sıralama: Önce 'Fatura Kesilecek' (to_be_issued) olanlar, sonra eskiden yeniye tarih.

$sql = "SELECT t.*, 
               c.company_name, c.tax_office, c.tax_number, c.tc_number, c.address, c.city, c.country,
               tc.code as tour_code 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.doc_type = 'invoice_order' 
        AND t.parent_id IS NULL 
        AND (t.invoice_no IS NULL OR t.invoice_no = '')
        ORDER BY 
           CASE 
               WHEN t.invoice_status = 'to_be_issued' THEN 1  -- En Üstte (Acil)
               ELSE 2                                         -- Sonra Diğerleri
           END ASC,
           t.date ASC";

$pendings = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Fatura Kesilecek İşlemler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        .clickable-id { cursor: pointer; color: #0d6efd; text-decoration: underline; font-weight: bold; }
        .clickable-id:hover { color: #0a58ca; }
        .info-label { font-weight: bold; color: #555; width: 120px; display: inline-block; }
        
        /* Satır Renklendirmeleri */
        .row-priority { background-color: #fff3cd !important; } /* Sarı - Kesilecek */
        .row-normal { background-color: #ffffff; } /* Beyaz - Onay Bekleyen */
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="text-success"><i class="fa fa-file-invoice"></i> Fatura Kesilecek Tahsilatlar</h2>
                <p class="text-muted">Müşterilerden alınan paralar karşılığında kesmemiz gereken faturaların listesi.</p>
            </div>
            <a href="payment-orders.php" class="btn btn-secondary">Tüm Liste</a>
        </div>

        <div class="card shadow">
            <div class="card-body">
                <table id="pendingTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="50">ID</th>
                            <th>Durum</th>
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
                            <?php
                                // Satır Stili ve Durum Rozeti
                                $row_class = 'row-normal';
                                $status_badge = '<span class="badge bg-secondary">Onay Bekliyor</span>';
                                $btn_text = 'Faturayı Kes';
                                $btn_class = 'btn-outline-primary';

                                if ($row['invoice_status'] == 'to_be_issued') {
                                    // ÖNCELİKLİ (Kesilecek)
                                    $row_class = 'row-priority';
                                    $status_badge = '<span class="badge bg-warning text-dark"><i class="fa fa-exclamation-circle"></i> Fatura Kesilecek</span>';
                                    $btn_class = 'btn-success'; // Yeşil buton (Harekete geçirici)
                                }
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td>
                                    <span class="clickable-id" onclick='openInvoiceModal(<?php echo json_encode($row); ?>)'>
                                        #<?php echo $row['id']; ?>
                                    </span>
                                </td>
                                <td><?php echo $status_badge; ?></td>
                                <td><?php echo date('d.m.Y', strtotime($row['date'])); ?></td>
                                <td class="fw-bold"><?php echo guvenli_html($row['company_name']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo $row['tour_code']; ?></span></td>
                                <td><?php echo guvenli_html($row['description']); ?></td>
                                <td class="text-end fw-bold text-success">
                                    <?php echo number_format($row['amount'], 2, ',', '.'); ?> ₺
                                </td>
                                <td class="text-center">
                                    <button class="btn <?php echo $btn_class; ?> btn-sm" onclick='openInvoiceModal(<?php echo json_encode($row); ?>)'>
                                        <i class="fa fa-print"></i> <?php echo $btn_text; ?>
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fa fa-print"></i> Fatura Kesim İşlemi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    
                    <div class="alert alert-light border shadow-sm p-3 mb-4">
                        <h6 class="text-success fw-bold border-bottom pb-2 mb-3">
                            <i class="fa fa-user-tag"></i> Fatura Alıcısı
                        </h6>
                        <div class="row">
                            <div class="col-md-7 border-end">
                                <div class="mb-2"><span class="info-label">Ünvan:</span> <strong id="info_company" class="text-dark fs-5"></strong></div>
                                <div class="mb-2"><span class="info-label">Vergi/TC No:</span> <span id="info_tax"></span></div>
                                <div class="mb-2"><span class="info-label">Adres:</span> <span id="info_address" class="text-muted"></span></div>
                            </div>
                            <div class="col-md-5 ps-4">
                                <div class="mb-2"><span class="info-label">İşlem ID:</span> <span id="info_id" class="badge bg-secondary"></span></div>
                                <div class="mb-2"><span class="info-label">Kesilecek Tutar:</span> <strong id="info_amount" class="text-success fs-4"></strong></div>
                                <div class="mb-2"><span class="info-label">Tur Kodu:</span> <span id="info_tour" class="fw-bold"></span></div>
                            </div>
                        </div>
                    </div>

                    <form id="invoiceForm" enctype="multipart/form-data">
                        <input type="hidden" name="id" id="trans_id">
                        
                        <h6 class="text-secondary fw-bold border-bottom pb-2 mb-3">
                            <i class="fa fa-edit"></i> Fatura Detayları
                        </h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Kesilen Fatura No <span class="text-danger">*</span></label>
                                <input type="text" name="invoice_no" class="form-control form-control-lg" required placeholder="Örn: GIB2024...">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fatura Tarihi</label>
                                <input type="date" name="invoice_date" class="form-control form-control-lg" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Fatura Dosyası (PDF/Görsel)</label>
                            <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
                            <div class="form-text">Kestiğiniz faturanın PDF veya görselini buraya yükleyin.</div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg shadow-sm">
                                <i class="fa fa-check-double"></i> Kaydet ve Tamamla
                            </button>
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
                "order": [] // SQL'deki özel sıralamayı koru
            });
        });

        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));

        function openInvoiceModal(data) {
            document.getElementById('trans_id').value = data.id;
            
            // Bilgi Kartı
            document.getElementById('info_company').innerText = data.company_name;
            document.getElementById('info_id').innerText = '#' + data.id;
            document.getElementById('info_tour').innerText = data.tour_code || '-';
            
            var formattedAmount = Number(data.amount).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ' + (data.currency || 'TRY');
            document.getElementById('info_amount').innerText = formattedAmount;

            // Vergi Bilgisi
            var taxInfo = '';
            if (data.tax_number) {
                taxInfo = (data.tax_office ? data.tax_office + ' VD - ' : '') + data.tax_number;
            } else if (data.tc_number) {
                taxInfo = 'TC: ' + data.tc_number;
            } else {
                taxInfo = '<span class="text-danger">Vergi/TC Bilgisi Yok!</span>';
            }
            document.getElementById('info_tax').innerHTML = taxInfo;

            // Adres
            var fullAddress = [data.address, data.city, data.country].filter(Boolean).join(', ');
            document.getElementById('info_address').innerText = fullAddress || '-';

            document.getElementById('invoiceForm').reset();
            invoiceModal.show();
        }

        // Form Gönderimi (AJAX)
        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            $.ajax({
                url: 'api-upload-invoice.php', // Bu dosya daha önce oluşturulmuştu, aynı mantıkla çalışır
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
                            title: 'İşlem Başarılı',
                            text: 'Fatura kaydedildi ve işlem tamamlandı.',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
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