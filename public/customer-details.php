<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// URL'den ID'yi al (örn: customer-details.php?id=5)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. Müşteri Bilgilerini Çek
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$stmt->execute(['id' => $id]);
$customer = $stmt->fetch();

// Müşteri yoksa listeye geri at
if (!$customer) {
    header("Location: customers.php");
    exit;
}

// 2. Müşterinin Hareketlerini Çek (Tarih sırasına göre)
$transStmt = $pdo->prepare("SELECT * FROM transactions WHERE customer_id = :id ORDER BY date DESC, id DESC");
$transStmt->execute(['id' => $id]);
$transactions = $transStmt->fetchAll();

// Toplamları Hesapla (İstatistik için)
$total_debt = 0;
$total_credit = 0;

foreach ($transactions as $t) {
    if ($t['type'] == 'debt') {
        $total_debt += $t['amount'];
    } else {
        $total_credit += $t['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo guvenli_html($customer['company_name']); ?> - Detaylar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><?php echo guvenli_html($customer['company_name']); ?> <small class="text-muted h5">Ekstresi</small></h1>
            <a href="customers.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Geri Dön</a>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-light border-primary">
                    <div class="card-body">
                        <h5 class="card-title text-primary">Toplam Satış (Borç)</h5>
                        <h3><?php echo number_format($total_debt, 2, ',', '.'); ?> ₺</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-light border-success">
                    <div class="card-body">
                        <h5 class="card-title text-success">Toplam Tahsilat (Alacak)</h5>
                        <h3><?php echo number_format($total_credit, 2, ',', '.'); ?> ₺</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white <?php echo $customer['current_balance'] >= 0 ? 'bg-danger' : 'bg-success'; ?>">
                    <div class="card-body">
                        <h5 class="card-title">Güncel Bakiye</h5>
                        <h3><?php echo number_format($customer['current_balance'], 2, ',', '.'); ?> ₺</h3>
                        <small><?php echo $customer['current_balance'] > 0 ? '(Müşteri Borçlu)' : ($customer['current_balance'] < 0 ? '(Biz Borçluyuz)' : ''); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-dark text-white">
                İşlem Geçmişi
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                        <th>Tarih</th>
                        <th>İşlem Türü</th>
                        <th>Açıklama</th>
                        <th class="text-end">Borç</th>
                        <th class="text-end">Alacak</th>
                        <th class="text-center">İşlem</th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($transactions) > 0): ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y', strtotime($t['date'])); ?></td>
                                    <td>
                                        <?php if ($t['type'] == 'debt'): ?>
                                            <span class="badge bg-primary">Satış Faturası</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Tahsilat</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo guvenli_html($t['description']); ?></td>
                                    <td class="text-end text-danger fw-bold">
                                        <?php echo $t['type'] == 'debt' ? number_format($t['amount'], 2, ',', '.') . ' ₺' : '-'; ?>
                                    </td>
                                    <td class="text-end text-success fw-bold">
                                        <?php echo $t['type'] == 'credit' ? number_format($t['amount'], 2, ',', '.') . ' ₺' : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if(has_permission('delete_data')): ?>
                                            <a href="transaction-delete.php?id=..." class="btn btn-danger">Sil</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center p-4">Bu müşteriye ait henüz bir işlem bulunmuyor.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>