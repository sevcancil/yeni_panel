<?php
// public/pending-invoices.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- 1. FATURA KAYDETME İŞLEMİ (AJAX POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_invoice') {
    ob_clean(); 
    header('Content-Type: application/json');

    try {
        $parent_id = (int)$_POST['parent_id'];
        $invoice_no = temizle($_POST['invoice_no']);
        $date = $_POST['date'];
        $amount = (float)$_POST['amount'];
        $doc_type_selected = $_POST['document_type']; 
        $description = "$doc_type_selected Kesildi: " . $invoice_no;

        // Dosya Yükleme
        $file_path = null;
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
            $filename = 'INV_' . uniqid() . '.' . $ext;
            $upload_path = '../storage/invoices/' . $filename;
            if (!is_dir('../storage/invoices/')) mkdir('../storage/invoices/', 0777, true);
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_path)) {
                $file_path = 'invoices/' . $filename;
            }
        }

        // Ana İşlemi Bul
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$parent_id]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) throw new Exception("Ana işlem bulunamadı.");

        // SQL Transaction Başlat
        $pdo->beginTransaction();

        // ADIM 1: Önce şu ana kadar kesilmiş toplamı al (Veritabanından)
        $stmtPrev = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE parent_id = ? AND doc_type = 'invoice'");
        $stmtPrev->execute([$parent_id]);
        $previous_invoiced = (float)$stmtPrev->fetchColumn();

        // ADIM 2: Yeni Toplamı PHP'de Hesapla (Garanti Yöntem)
        $total_invoiced_now = $previous_invoiced + $amount;

        // ADIM 3: Yeni Fatura Kaydını Ekle (Child)
        // invoice_status'ü 'issued' yapıyoruz ki veritabanında 'pending' kalmasın.
        $sql = "INSERT INTO transactions (
                    parent_id, type, date, amount, description, 
                    customer_id, tour_code_id, department_id,
                    doc_type, payment_status, invoice_status, file_path, document_type, invoice_no,
                    created_by
                ) VALUES (?, 'credit', ?, ?, ?, ?, ?, ?, 'invoice', 'paid', 'issued', ?, ?, ?, ?)";
        
        $stmtInsert = $pdo->prepare($sql);
        $stmtInsert->execute([
            $parent_id, $date, $amount, $description,
            $parent['customer_id'], $parent['tour_code_id'], $parent['department_id'],
            $file_path, $doc_type_selected, $invoice_no, $_SESSION['user_id']
        ]);

        // ADIM 4: Ana İşlemin Durumunu Belirle (Parent)
        // Eğer (Yeni Toplam >= Ana Tutar) ise 'issued' (Tamamlandı) yap
        $new_inv_status = 'to_be_issued';
        if ($total_invoiced_now >= ($parent['amount'] - 0.05)) {
            $new_inv_status = 'issued'; 
        }

        // Ana kaydı güncelle
        $updateSql = "UPDATE transactions SET invoice_no = ?, invoice_status = ? WHERE id = ?";
        $pdo->prepare($updateSql)->execute([$invoice_no, $new_inv_status, $parent_id]);

        log_action($pdo, 'transaction', $parent_id, 'create', "$doc_type_selected Kesildi: $invoice_no ($amount TL)");

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Fatura başarıyla kaydedildi.', 'new_status' => $new_inv_status]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- 2. LİSTELEME MANTIĞI ---
$sql = "SELECT t.*, 
               c.company_name, c.tax_office, c.tax_number, c.tc_number, c.address, c.city, c.country,
               tc.code as tour_code,
               (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE parent_id = t.id AND doc_type = 'invoice') as invoiced_total
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.doc_type = 'invoice_order' 
        AND t.parent_id IS NULL 
        AND t.invoice_status != 'issued' 
        ORDER BY t.date ASC";

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
        .row-priority { background-color: #fff3cd !important; }
        .row-normal { background-color: #ffffff; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="text-success"><i class="fa fa-file-invoice"></i> Fatura Kesilecek Tahsilatlar</h2>
                <p class="text-muted">Müşterilerden alınan siparişler karşılığında kesilmesi gereken faturalar.</p>
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
                            <th class="text-end">Toplam Tutar</th>
                            <th class="text-end">Kalan (Kesilecek)</th>
                            <th class="text-center">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pendings as $row): 
                            // Matematiksel Sağlama: Kalan tutar 0 veya negatifse listeye basma!
                            $remaining = $row['amount'] - $row['invoiced_total'];
                            if ($remaining <= 0.05) continue; 

                            $row_class = ($row['invoice_status'] == 'to_be_issued') ? 'row-priority' : 'row-normal';
                            
                            $status_badge = '';
                            if ($row['invoiced_total'] > 0) {
                                $status_badge = '<span class="badge bg-info text-dark">Kısmi Kesildi</span>';
                            } elseif ($row['invoice_status'] == 'to_be_issued') {
                                $status_badge = '<span class="badge bg-warning text-dark"><i class="fa fa-exclamation-circle"></i> Fatura Kesilecek</span>';
                            } else {
                                $status_badge = '<span class="badge bg-secondary">Onay Bekliyor</span>';
                            }
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td><span class="clickable-id" onclick='openInvoiceModal(<?php echo json_encode($row); ?>, <?php echo $remaining; ?>)'>#<?php echo $row['id']; ?></span></td>
                                <td><?php echo $status_badge; ?></td>
                                <td><?php echo date('d.m.Y', strtotime($row['date'])); ?></td>
                                <td class="fw-bold"><?php echo guvenli_html($row['company_name']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo $row['tour_code']; ?></span></td>
                                <td><?php echo guvenli_html($row['description']); ?></td>
                                <td class="text-end text-muted"><?php echo number_format($row['amount'], 2, ',', '.'); ?> ₺</td>
                                <td class="text-end fw-bold text-danger"><?php echo number_format($remaining, 2, ',', '.'); ?> ₺</td>
                                <td class="text-center">
                                    <button class="btn btn-outline-success btn-sm" onclick='openInvoiceModal(<?php echo json_encode($row); ?>, <?php echo $remaining; ?>)'>
                                        <i class="fa fa-print"></i> Faturayı Kes
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
                        <h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="fa fa-user-tag"></i> Fatura Alıcısı</h6>
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
                        <input type="hidden" name="action" value="create_invoice">
                        <input type="hidden" name="parent_id" id="parent_id">
                        
                        <h6 class="text-secondary fw-bold border-bottom pb-2 mb-3"><i class="fa fa-edit"></i> Fatura Detayları</h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fatura Türü</label>
                                <select name="document_type" class="form-select" required>
                                    <option value="e-Fatura">e-Fatura</option>
                                    <option value="e-Arşiv">e-Arşiv</option>
                                    <option value="Fatura">Kağıt Fatura</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fatura No <span class="text-danger">*</span></label>
                                <input type="text" name="invoice_no" class="form-control" required placeholder="Örn: GIB2024...">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fatura Tarihi</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-success">Fatura Tutarı</label>
                                <input type="number" step="0.01" name="amount" id="invoice_amount" class="form-control fw-bold border-success" required>
                                <div class="form-text">Kısmi fatura kesebilirsiniz.</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Fatura Dosyası (PDF/Görsel)</label>
                            <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.png,.jpeg">
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
            $('#pendingTable').DataTable({ "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }, "order": [] });
        });

        var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));

        function openInvoiceModal(data, remainingAmount) {
            document.getElementById('parent_id').value = data.id;
            document.getElementById('info_company').innerText = data.company_name;
            document.getElementById('info_id').innerText = '#' + data.id;
            document.getElementById('info_tour').innerText = data.tour_code || '-';
            
            var formattedAmount = Number(remainingAmount).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ' + (data.currency || 'TRY');
            document.getElementById('info_amount').innerText = formattedAmount;
            document.getElementById('invoice_amount').value = remainingAmount; 

            var taxInfo = data.tax_number ? (data.tax_office ? data.tax_office + ' VD - ' : '') + data.tax_number : (data.tc_number ? 'TC: ' + data.tc_number : '<span class="text-danger">Vergi/TC Bilgisi Yok!</span>');
            document.getElementById('info_tax').innerHTML = taxInfo;

            var fullAddress = [data.address, data.city, data.country].filter(Boolean).join(', ');
            document.getElementById('info_address').innerText = fullAddress || '-';

            document.getElementById('invoiceForm').reset();
            document.getElementById('parent_id').value = data.id;
            document.getElementById('invoice_amount').value = remainingAmount; 
            
            invoiceModal.show();
        }

        $('#invoiceForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            $.ajax({
                url: 'pending-invoices.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response) {
                    if(response.status === 'success') {
                        invoiceModal.hide();
                        Swal.fire({ icon: 'success', title: 'Başarılı', text: response.message, timer: 1500, showConfirmButton: false }).then(() => { location.reload(); });
                    } else {
                        Swal.fire('Hata', response.message, 'error');
                    }
                },
                error: function() { Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error'); }
            });
        });
    </script>
</body>
</html>