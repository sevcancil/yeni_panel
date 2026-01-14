<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// YETKİ KONTROLLERİ
// Bu değişkenleri aşağıda HTML içinde kullanacağız.
$can_view_finance = has_permission('view_finance'); // Kasa ve Parayı görme yetkisi

// --- VERİLERİ ÇEK (Sadece yetki varsa çek) ---

// 1. Toplam Kasa/Banka Varlığı
$total_cash = 0;
if ($can_view_finance) {
    $stmt = $pdo->query("SELECT SUM(current_balance) FROM payment_channels");
    $total_cash = $stmt->fetchColumn() ?: 0;
}

// 2. Toplam Alacaklar & Borçlar (Finans yetkisine bağladık)
$total_receivables = 0;
$total_payables = 0;
if ($can_view_finance) {
    $stmt = $pdo->query("SELECT SUM(current_balance) FROM customers WHERE current_balance > 0");
    $total_receivables = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT SUM(current_balance) FROM customers WHERE current_balance < 0");
    $total_payables = abs($stmt->fetchColumn() ?: 0);
}

// 3. Bekleyen İşlemler (Herkes görebilsin mi? Genelde Finans yetkisi ister ama operasyonel de olabilir)
// Biz şimdilik bunu da finans yetkisine bağlayalım veya herkese açık yapalım. 
// "Fatura kesebilen" (manage_invoices) herkes bekleyenleri görsün diyelim.
$can_manage_invoices = has_permission('manage_invoices');
$pending_count = 0;
if ($can_manage_invoices || $can_view_finance) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE payment_status = 'unpaid'");
    $pending_count = $stmt->fetchColumn();
}

// 4. Son 5 Hareket (Sadece yetkisi olan görsün)
$recent_transactions = [];
if ($can_view_finance) {
    $sql = "SELECT t.*, c.company_name 
            FROM transactions t 
            JOIN customers c ON t.customer_id = c.id 
            ORDER BY t.date DESC, t.id DESC LIMIT 5";
    $recent_transactions = $pdo->query($sql)->fetchAll();
}

// 5. Grafik Verisi (Sadece yetkisi olan görsün)
$sales_total = 0;
$expense_total = 0;
if ($can_view_finance) {
    $stmt = $pdo->query("SELECT type, SUM(amount) as total FROM transactions GROUP BY type");
    $chart_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $sales_total = ($chart_data['sales_invoice'] ?? 0) + ($chart_data['payment_in'] ?? 0); // Fatura + Tahsilat (Kabaca)
    $expense_total = ($chart_data['purchase_invoice'] ?? 0) + ($chart_data['payment_out'] ?? 0); 
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yönetim Paneli Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-box {
            border-radius: 10px;
            border: none;
            color: white;
            padding: 20px;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-box h3 { font-size: 2.5rem; font-weight: bold; margin: 0; }
        .card-box p { font-size: 1.1rem; opacity: 0.8; margin: 0; }
        .card-box .icon {
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 4rem;
            opacity: 0.2;
        }
        .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); }
        .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); }
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); }
        .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); }
        
        .blur-text { filter: blur(5px); user-select: none; }
    </style>
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Kurları Çek
    $currencies = $pdo->query("SELECT * FROM currencies WHERE code != 'TRY'")->fetchAll();
    ?>

    <div class="container mt-3">
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'currency_updated'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa fa-check-circle"></i> Döviz kurları TCMB üzerinden başarıyla güncellendi.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'currency_error'): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa fa-exclamation-triangle"></i> Hata: TCMB'den veri çekilemedi. İnternet bağlantınızı kontrol edin.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($can_view_finance): ?>
        <div class="card shadow-sm border-0 mb-4 bg-dark text-white">
            <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap">
                
                <div class="d-flex align-items-center">
                    <span class="badge bg-danger me-3">TCMB Kurları</span>
                    <?php foreach($currencies as $curr): ?>
                        <div class="me-4 d-flex align-items-center">
                            <span class="fw-bold text-warning me-1"><?php echo $curr['code']; ?>:</span>
                            <span class="font-monospace"><?php echo $curr['rate']; ?> ₺</span>
                            <?php 
                                // Son güncelleme saati eski mi? (Bugünün tarihi değilse uyar)
                                $is_old = (date('Y-m-d') != date('Y-m-d', strtotime($curr['updated_at'])));
                                if($is_old) echo '<i class="fa fa-clock text-danger ms-1" title="Eski Kur!"></i>';
                            ?>
                        </div>
                    <?php endforeach; ?>
                    <small class="text-danger ms-2" style="font-size: 0.8rem;">
                        (Son Güncelleme: <?php echo date('d.m.Y H:i', strtotime($currencies[0]['updated_at'])); ?>)
                    </small>
                </div>

                <div>
                    <a href="currency-update.php" class="btn btn-sm btn-outline-warning">
                        <i class="fa fa-sync-alt"></i> Şimdi Güncelle
                    </a>
                </div>

            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="container pb-5">
        
        <div class="row">
            <div class="col-md-3">
                <div class="card-box bg-gradient-primary">
                    <div class="inner">
                        <?php if($can_view_finance): ?>
                            <h3><?php echo number_format($total_cash, 2, ',', '.'); ?> ₺</h3>
                        <?php else: ?>
                            <h3><i class="fa fa-lock"></i> Gizli</h3>
                        <?php endif; ?>
                        <p>Kasa & Banka Varlığı</p>
                    </div>
                    <div class="icon"><i class="fa fa-wallet"></i></div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card-box bg-gradient-success">
                    <div class="inner">
                        <?php if($can_view_finance): ?>
                            <h3><?php echo number_format($total_receivables, 2, ',', '.'); ?> ₺</h3>
                        <?php else: ?>
                            <h3>***</h3>
                        <?php endif; ?>
                        <p>Toplam Alacaklar</p>
                    </div>
                    <div class="icon"><i class="fa fa-hand-holding-dollar"></i></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card-box bg-gradient-danger">
                    <div class="inner">
                        <?php if($can_view_finance): ?>
                            <h3><?php echo number_format($total_payables, 2, ',', '.'); ?> ₺</h3>
                        <?php else: ?>
                            <h3>***</h3>
                        <?php endif; ?>
                        <p>Toplam Borçlarımız</p>
                    </div>
                    <div class="icon"><i class="fa fa-file-invoice-dollar"></i></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card-box bg-gradient-warning text-white">
                    <div class="inner">
                        <h3><?php echo $pending_count; ?></h3>
                        <p>Bekleyen İşlem</p>
                    </div>
                    <div class="icon"><i class="fa fa-clock"></i></div>
                    <?php if($can_manage_invoices): ?>
                        <a href="payment-orders.php" class="stretched-link"></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            
            <div class="col-md-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-white fw-bold">
                        <i class="fa fa-chart-pie me-1"></i> Gelir / Gider Durumu
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <?php if($can_view_finance): ?>
                            <canvas id="myChart"></canvas>
                        <?php else: ?>
                            <div class="text-center text-muted">
                                <i class="fa fa-lock fa-3x mb-3"></i><br>
                                Bu veriyi görme yetkiniz yok.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="fa fa-list me-1"></i> Son Hareketler</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if($can_view_finance): ?>
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>Cari</th>
                                        <th>İşlem</th>
                                        <th class="text-end">Tutar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_transactions as $t): ?>
                                        <tr>
                                            <td><?php echo date('d.m', strtotime($t['date'])); ?></td>
                                            <td><?php echo guvenli_html($t['company_name']); ?></td>
                                            <td><small><?php echo guvenli_html($t['description']); ?></small></td>
                                            <td class="text-end fw-bold">
                                                <?php echo number_format($t['amount'], 2, ',', '.'); ?> ₺
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="p-5 text-center text-muted">
                                <i class="fa fa-eye-slash fa-2x mb-2"></i><br>
                                Finansal hareketleri görme yetkiniz bulunmamaktadır.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if($can_view_finance): ?>
    <script>
    var ctx = document.getElementById('myChart').getContext('2d');
    var myChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Gelirler', 'Giderler'],
            datasets: [{
                data: [<?php echo $sales_total; ?>, <?php echo $expense_total; ?>],
                backgroundColor: ['#4e73df', '#e74a3b']
            }]
        },
        options: { responsive: true }
    });
    </script>
    <?php endif; ?>
</body>
</html>