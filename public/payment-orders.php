<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Filtreler
$where = "WHERE payment_status = 'unpaid'"; // Sadece ödenmemişler
$params = [];

if (!empty($_GET['dept_id'])) {
    $where .= " AND t.department_id = ?";
    $params[] = $_GET['dept_id'];
}
if (!empty($_GET['q'])) {
    $where .= " AND (c.company_name LIKE ? OR t.description LIKE ?)";
    $params[] = "%".$_GET['q']."%";
    $params[] = "%".$_GET['q']."%";
}

// Sorgu
$sql = "SELECT t.*, c.company_name, d.name as dept_name, tc.code as tour_code 
        FROM transactions t 
        JOIN customers c ON t.customer_id = c.id 
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        $where
        ORDER BY t.date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Bölümleri Çek (Filtre için)
$depts = $pdo->query("SELECT * FROM departments")->fetchAll();

$can_approve = has_permission('approve_payment');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ödeme Emirleri ve Onay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .toggle-btn { cursor: pointer; opacity: 0.3; transition: 0.3s; font-size: 1.2rem; }
        .toggle-btn.active { opacity: 1; transform: scale(1.2); }
        .text-approval.active { color: green; }
        .text-priority.active { color: red; }
        .text-control.active { color: orange; }
        .table-sm td, .table-sm th { vertical-align: middle; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="card mb-4 bg-light">
            <div class="card-body py-3">
                <form class="row g-3 align-items-center">
                    <div class="col-auto">
                        <input type="text" name="q" class="form-control" placeholder="Cari veya Açıklama Ara..." value="<?php echo $_GET['q'] ?? ''; ?>">
                    </div>
                    <div class="col-auto">
                        <select name="dept_id" class="form-select">
                            <option value="">Tüm Bölümler</option>
                            <?php foreach($depts as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo (isset($_GET['dept_id']) && $_GET['dept_id'] == $d['id']) ? 'selected' : ''; ?>>
                                    <?php echo $d['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Filtrele</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-header bg-dark text-white d-flex justify-content-between">
                <h5 class="mb-0">Ödeme Listesi</h5>
                <span>Toplam Kayıt: <?php echo count($orders); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered table-sm mb-0">
                        <thead class="table-secondary text-center">
                            <tr>
                                <th width="120">İşlemler</th> <th>Tarih</th>
                                <th>Bölüm</th>
                                <th>Belge</th>
                                <th>Cari / Açıklama</th>
                                <th>Proje</th>
                                <th>Orijinal Tutar</th>
                                <th>TL Karşılığı</th>
                                <th width="100">Öde</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($orders as $o): ?>
                                <tr>
                                    <td class="text-center">
                                        <i class="fa fa-check-circle toggle-btn text-approval <?php echo $o['is_approved'] ? 'active' : ''; ?>" 
                                           onclick="<?php echo $can_approve ? "toggleStatus({$o['id']}, 'is_approved', this)" : "alert('Yetkiniz yok!')"; ?>"
                                           title="Ödeme Onayı"></i>
                                        
                                        <i class="fa fa-exclamation-circle toggle-btn text-priority ms-2 <?php echo $o['is_priority'] ? 'active' : ''; ?>" 
                                           onclick="toggleStatus(<?php echo $o['id']; ?>, 'is_priority', this)"
                                           title="Acil / Öncelikli"></i>

                                        <i class="fa fa-search toggle-btn text-control ms-2 <?php echo $o['needs_control'] ? 'active' : ''; ?>" 
                                           onclick="toggleStatus(<?php echo $o['id']; ?>, 'needs_control', this)"
                                           title="Kontrol Edilecek"></i>
                                    </td>

                                    <td class="text-center"><?php echo date('d.m.Y', strtotime($o['date'])); ?></td>
                                    <td><?php echo guvenli_html($o['dept_name'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $o['doc_type'] == 'invoice_order' ? 'bg-info' : 'bg-secondary'; ?>">
                                            <?php echo $o['doc_type'] == 'invoice_order' ? 'Fatura E.' : 'Ödeme E.'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo guvenli_html($o['company_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo guvenli_html($o['description']); ?></small>
                                    </td>
                                    <td><?php echo guvenli_html($o['tour_code']); ?></td>
                                    
                                    <td class="text-end font-monospace">
                                        <?php 
                                            if($o['currency'] != 'TRY') {
                                                echo number_format($o['original_amount'], 2) . ' ' . $o['currency'];
                                                echo '<br><small class="text-muted">Kur: '.$o['exchange_rate'].'</small>';
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-end fw-bold font-monospace">
                                        <?php echo number_format($o['amount'], 2, ',', '.'); ?> ₺
                                    </td>

                                    <td class="text-center">
                                        <?php if($o['is_approved']): ?>
                                            <a href="complete-payment.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-success w-100">
                                                <i class="fa fa-lira-sign"></i> Öde
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary w-100" disabled>Bekliyor</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleStatus(id, field, element) {
            // Şimdiki durumu al (active class var mı?)
            var currentStatus = element.classList.contains('active') ? 1 : 0;
            var newStatus = currentStatus === 1 ? 0 : 1; // Tersi yap

            // AJAX İsteği
            var formData = new FormData();
            formData.append('id', id);
            formData.append('field', field);
            formData.append('value', newStatus);

            fetch('ajax_update.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if(data.trim() === 'OK') {
                    // Görseli güncelle
                    if(newStatus === 1) {
                        element.classList.add('active');
                    } else {
                        element.classList.remove('active');
                    }
                    // Eğer onay butonuysa sayfayı yenile ki "Öde" butonu açılsın
                    if(field === 'is_approved') {
                        location.reload(); 
                    }
                } else {
                    alert('Hata: ' + data);
                }
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>