<?php
// public/customer-details.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. Müşteri Bilgisi
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$stmt->execute(['id' => $id]);
$customer = $stmt->fetch();

if (!$customer) { header("Location: customers.php"); exit; }

// 2. Hareketler (Eskiden yeniye sıralı ki yürüyen bakiye hesaplayalım)
$transStmt = $pdo->prepare("SELECT * FROM transactions WHERE customer_id = :id ORDER BY date ASC, id ASC");
$transStmt->execute(['id' => $id]);
$transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);

// Toplamlar
$total_debt = 0;   // Bize borçlandığı (Satışlarımız)
$total_credit = 0; // Bizim borçlandığımız (Alışlarımız/Tahsilatlar)
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo guvenli_html($customer['company_name']); ?> - Ekstre</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); color: white; }
        .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); color: white; }
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); color: white; }
    </style>
</head>
<body class="bg-light">
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container my-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-0 fw-bold text-dark"><?php echo guvenli_html($customer['company_name']); ?></h2>
                <span class="text-muted">Cari Hesap Ekstresi</span>
            </div>
            <a href="customers.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> Listeye Dön</a>
        </div>

        <div class="row g-3 mb-4">
            <?php 
                foreach ($transactions as $t) {
                    if ($t['type'] == 'debt' || $t['type'] == 'payment_out') { 
                        // Müşteriye Borç Yazdık (Satış Yaptık veya Ödeme Çıktık - Burası kafa karıştırabilir)
                        // Sizin sistemde: 'debt' = Gider (Bizim Borcumuz/Alış Faturası)
                        // 'credit' = Gelir (Bizim Alacağımız/Satış Faturası)
                        // 'payment_out' = Ödeme Yaptık (Borcumuz azaldı)
                        // 'payment_in' = Tahsilat Yaptık (Alacağımız azaldı)
                        
                        // Muhasebe Mantığına Göre:
                        // Cari Borçlanırsa (Borç Sütunu) -> Biz Mal Satmışızdır.
                        // Cari Alacaklanırsa (Alacak Sütunu) -> Biz Mal Almışızdır veya Tahsilat Yapmışızdır.
                        
                        // Sizin sistemde 'debt' tipi 'Gider/Alış Faturası' olarak kurgulanmıştı.
                        // Yani Cari Alacaklanır. (Biz borçlanırız)
                        if($t['type'] == 'debt') $total_credit += $t['amount']; // Alış Faturası -> Cari Alacak
                        if($t['type'] == 'credit') $total_debt += $t['amount']; // Satış Faturası -> Cari Borç
                        
                        // Ödeme/Tahsilat
                        if($t['type'] == 'payment_out') $total_debt += $t['amount']; // Biz Ödedik -> Cari Borçlandı (Borcumuz düştü)
                        if($t['type'] == 'payment_in') $total_credit += $t['amount']; // Biz Tahsil Ettik -> Cari Alacaklandı (Alacağı düştü)
                    }
                }
                $current_balance = $total_debt - $total_credit;
            ?>
            
            <div class="col-md-4">
                <div class="card shadow-sm border-start border-primary border-4 h-100">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Toplam Borç (Satış/Ödeme)</div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_debt, 2, ',', '.'); ?> ₺</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-start border-success border-4 h-100">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Toplam Alacak (Alış/Tahsilat)</div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_credit, 2, ',', '.'); ?> ₺</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow h-100 <?php echo $current_balance >= 0 ? 'bg-gradient-primary' : 'bg-gradient-danger'; ?>">
                    <div class="card-body text-white">
                        <div class="text-uppercase small fw-bold mb-1">Güncel Bakiye</div>
                        <div class="h2 mb-0 fw-bold"><?php echo number_format(abs($current_balance), 2, ',', '.'); ?> ₺</div>
                        <small><?php echo $current_balance >= 0 ? ' (Müşteri Borçlu)' : ' (Biz Borçluyuz)'; ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-white">
                <h6 class="m-0 font-weight-bold text-primary">Hesap Hareketleri</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light text-secondary small">
                            <tr>
                                <th>Tarih</th>
                                <th>İşlem Türü</th>
                                <th>Belge No</th>
                                <th>Açıklama</th>
                                <th class="text-end">Borç</th>
                                <th class="text-end">Alacak</th>
                                <th class="text-end">Bakiye</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $running_balance = 0;
                            if (count($transactions) > 0): 
                                foreach ($transactions as $t): 
                                    // Yürüyen Bakiye Hesabı
                                    $borc = 0;
                                    $alacak = 0;
                                    $islem_adi = '';
                                    $badge = '';

                                    // TİP ANALİZİ (Önemli Kısım)
                                    // Sizin kurgunuza göre:
                                    // debt = Gider (Alış Faturası) -> Cari Alacaklanır
                                    // credit = Gelir (Satış Faturası) -> Cari Borçlanır
                                    // payment_out = Ödeme Yaptık -> Cari Borçlanır
                                    // payment_in = Tahsilat Yaptık -> Cari Alacaklanır

                                    if ($t['type'] == 'debt') { // Alış Faturası
                                        $alacak = $t['amount'];
                                        $islem_adi = 'Alış Faturası (Gider)';
                                        $badge = 'bg-danger bg-opacity-75';
                                    } elseif ($t['type'] == 'credit') { // Satış Faturası
                                        $borc = $t['amount'];
                                        $islem_adi = 'Satış Faturası (Gelir)';
                                        $badge = 'bg-success bg-opacity-75';
                                    } elseif ($t['type'] == 'payment_out') { // Biz Ödedik
                                        $borc = $t['amount'];
                                        $islem_adi = 'Ödeme Çıkışı (Nakit/Banka)';
                                        $badge = 'bg-primary';
                                    } elseif ($t['type'] == 'payment_in') { // Tahsil Ettik
                                        $alacak = $t['amount'];
                                        $islem_adi = 'Tahsilat Girişi';
                                        $badge = 'bg-info text-dark';
                                    }

                                    $running_balance += ($borc - $alacak);
                            ?>
                                <tr>
                                    <td class="small"><?php echo date('d.m.Y', strtotime($t['date'])); ?></td>
                                    <td><span class="badge <?php echo $badge; ?> rounded-pill fw-normal"><?php echo $islem_adi; ?></span></td>
                                    <td class="small text-muted"><?php echo !empty($t['invoice_no']) ? $t['invoice_no'] : '-'; ?></td>
                                    <td class="small"><?php echo guvenli_html($t['description']); ?></td>
                                    
                                    <td class="text-end fw-bold text-dark">
                                        <?php echo $borc > 0 ? number_format($borc, 2, ',', '.') : ''; ?>
                                    </td>
                                    <td class="text-end fw-bold text-dark">
                                        <?php echo $alacak > 0 ? number_format($alacak, 2, ',', '.') : ''; ?>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $running_balance >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                        <?php echo number_format(abs($running_balance), 2, ',', '.') . ($running_balance >= 0 ? ' (B)' : ' (A)'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center p-4 text-muted">Hareket yok.</td></tr>
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