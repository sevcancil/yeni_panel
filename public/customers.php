<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Veritabanından müşterileri çek (Son eklenen en üstte)
$stmt = $pdo->query("SELECT * FROM customers ORDER BY id DESC");
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Hesaplar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Cari Hesaplar</h2>
            <a href="customer-add.php" class="btn btn-success"><i class="fa fa-plus"></i> Yeni Ekle</a>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>#ID</th>
                                <th>Firma Ünvanı</th>
                                <th>Yetkili</th>
                                <th>Telefon</th>
                                <th>Bakiye</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($customers) > 0): ?>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?php echo guvenli_html($customer['id']); ?></td>
                                        <td><strong><?php echo guvenli_html($customer['company_name']); ?></strong></td>
                                        <td><?php echo guvenli_html($customer['contact_name']); ?></td>
                                        <td><?php echo guvenli_html($customer['phone']); ?></td>
                                        <td>
                                            <?php echo number_format($customer['current_balance'], 2, ',', '.'); ?> ₺
                                        </td>
                                        <td>
                                            <a href="customer-edit.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-primary"><i class="fa fa-edit"></i> Düzenle</a>
                                            <a href="customer-details.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-info text-white"><i class="fa fa-list"></i> Ekstre</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">Henüz kayıtlı cari hesap bulunamadı.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>