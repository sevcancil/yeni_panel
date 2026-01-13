<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
// Bu satır security.php'den sonra gelmeli
permission_check('view_finance');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->query("SELECT * FROM payment_channels ORDER BY id ASC");
$channels = $stmt->fetchAll();

$total_money = 0;
foreach($channels as $c) {
    $total_money += $c['current_balance'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kasa ve Bankalar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Kasa & Banka Durumu</h2>
            <a href="channel-add.php" class="btn btn-primary"><i class="fa fa-plus"></i> Yeni Hesap Ekle</a>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h4>Toplam Varlık</h4>
                        <h1 class="display-4 fw-bold"><?php echo number_format($total_money, 2, ',', '.'); ?> ₺</h1>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <?php foreach ($channels as $channel): ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 shadow-sm border-start border-4 <?php echo $channel['type'] == 'bank' ? 'border-primary' : 'border-warning'; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="card-title fw-bold"><?php echo guvenli_html($channel['name']); ?></h5>
                                    <span class="badge <?php echo $channel['type'] == 'bank' ? 'bg-primary' : 'bg-warning text-dark'; ?>">
                                        <?php echo $channel['type'] == 'bank' ? 'Banka' : 'Nakit Kasa'; ?>
                                    </span>
                                </div>
                                <i class="fa <?php echo $channel['type'] == 'bank' ? 'fa-building-columns text-primary' : 'fa-wallet text-warning'; ?> fa-2x"></i>
                            </div>
                            
                            <hr>
                            
                            <div class="mt-3">
                                <small class="text-muted">Güncel Bakiye</small>
                                <h3 class="text-success"><?php echo number_format($channel['current_balance'], 2, ',', '.'); ?> ₺</h3>
                            </div>

                            <?php if($channel['account_number']): ?>
                                <div class="mt-2 text-muted small">
                                    <i class="fa fa-hashtag"></i> <?php echo guvenli_html($channel['account_number']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>