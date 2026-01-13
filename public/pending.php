<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Bekleyen (Unpaid) İşlemleri Çek
// type='debt' (Satış Faturası) -> Bizim alacağımız var -> Tahsil Et
// type='credit' (Alış Faturası) -> Bizim borcumuz var -> Ödeme Yap
$sql = "SELECT t.*, c.company_name, tc.code as tour_code 
        FROM transactions t 
        JOIN customers c ON t.customer_id = c.id 
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
        WHERE t.payment_status = 'unpaid' 
        ORDER BY t.date ASC";
$pendings = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Bekleyen Ödemeler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <h2 class="mb-4">Bekleyen Tahsilat ve Ödemeler</h2>
        
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tarih</th>
                            <th>Cari / Firma</th>
                            <th>Tür</th>
                            <th>Açıklama</th>
                            <th>Proje</th>
                            <th>Tutar</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pendings as $p): ?>
                            <tr>
                                <td><?php echo date('d.m.Y', strtotime($p['date'])); ?></td>
                                <td><?php echo guvenli_html($p['company_name']); ?></td>
                                <td>
                                    <?php if($p['type'] == 'debt'): ?>
                                        <span class="badge bg-primary">Alacak (Satış)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Borç (Gider)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo guvenli_html($p['description']); ?>
                                    <?php if($p['invoice_no']) echo "<br><small class='text-muted'>Fat: {$p['invoice_no']}</small>"; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo guvenli_html($p['tour_code']); ?></span></td>
                                <td class="fw-bold">
                                    <?php echo number_format($p['amount'], 2, ',', '.'); ?> ₺
                                </td>
                                <td>
                                    <?php if(has_permission('approve_payment')): ?>
                                        <a href="complete-payment.php..." class="btn btn-warning">Ödeme Yap</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(count($pendings) == 0): ?>
                            <tr><td colspan="7" class="text-center p-3">Bekleyen işlem yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>