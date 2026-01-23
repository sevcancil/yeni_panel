<?php
// public/customer-details.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. CARİ BİLGİSİNİ ÇEK
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) die("Cari bulunamadı.");

// 2. İŞLEMLERİ ÇEK (Tarih Sırasına Göre - Eskiden Yeniye)
// Not: Bakiye akışını doğru hesaplamak için eskiden yeniye çekiyoruz.
$sql = "SELECT t.*, tc.code as tour_code, d.name as dep_name 
        FROM transactions t 
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id 
        LEFT JOIN departments d ON t.department_id = d.id
        WHERE t.customer_id = ? 
        ORDER BY t.date ASC, t.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. HESAPLAMALAR (AYRIŞTIRILMIŞ)
$official_balance = $customer['opening_balance']; // Resmi Bakiye (Faturası Olan + Ödemeler)
$pending_orders   = 0; // Bekleyen Siparişler (Faturası Yok)
$total_balance    = $customer['opening_balance']; // Genel Toplam

$processed_rows = [];
$running_balance = $customer['opening_balance'];

foreach ($transactions as $row) {
    // Tutar Kontrolü
    $amount = (float)$row['amount'];
    
    // --- MANTIK ---
    // debt (Gider/Alış): Tedarikçi ALACAKLANIR (+). Bizim Borcumuz Artar.
    // payment_out (Ödeme): Tedarikçi BORÇLANIR (-). Bizim Borcumuz Azalır.
    // credit (Gelir/Satış): Müşteri BORÇLANIR (+). Bizim Alacağımız Artar. (Burada işaret ters olabilir, sisteme göre)
    // payment_in (Tahsilat): Müşteri ALACAKLANIR (-). Bizim Alacağımız Azalır.
    
    // Basit Muhasebe Mantığı (Cari Hesaba Göre):
    // Alacak (Credit) Sütunu: Bize Mal/Hizmet verdi (Borcumuz Arttı)
    // Borç (Debit) Sütunu: Biz ona para verdik (Borcumuz Azaldı)
    
    $is_invoice_missing = empty($row['invoice_no']); // Fatura No Yoksa
    
    $effect = 0;
    
    if ($row['type'] == 'debt') {
        // GİDER / ALIŞ (Örn: 300.000 TL PDF Tasarım Hizmeti) -> Bakiye Artar (Bize Borç Yazar)
        $effect = -$amount; // Bakiyeyi EKSİ yönde büyüt (Biz Borçluyuz)
        
        // Eğer Fatura Varsa RESMİ, Yoksa BEKLEYEN
        if ($is_invoice_missing) {
            $pending_orders += $effect; 
        } else {
            $official_balance += $effect;
        }

    } elseif ($row['type'] == 'payment_out') {
        // ÖDEME ÇIKIŞI (Örn: 100.000 TL Ödedik) -> Bakiye Azalır (Borçtan Düşer)
        $effect = $amount; // Bakiyeyi ARTI yönde düzelt
        $official_balance += $effect; // Ödeme her zaman resmidir (Kasa/Banka'dan çıktı)

    } elseif ($row['type'] == 'credit') {
        // GELİR / SATIŞ -> Bakiye Artı (Biz Alacaklıyız)
        $effect = $amount;
        
        if ($is_invoice_missing) {
            $pending_orders += $effect;
        } else {
            $official_balance += $effect;
        }

    } elseif ($row['type'] == 'payment_in') {
        // TAHSİLAT -> Bakiye Eksi (Alacaktan Düşer)
        $effect = -$amount;
        $official_balance += $effect; // Tahsilat her zaman resmidir
    }

    // Genel Toplam (Her şey dahil)
    $total_balance += $effect;
    
    // Tablo için satır verisi hazırla
    $running_balance += $effect;
    
    $processed_rows[] = [
        'data' => $row,
        'effect' => $effect,
        'balance' => $running_balance,
        'is_pending' => $is_invoice_missing && ($row['type'] == 'debt' || $row['type'] == 'credit')
    ];
}

// Bakiye Renkleri ve Metinleri
function getBalanceStyle($balance) {
    if ($balance < 0) return ['class' => 'text-danger', 'text' => number_format(abs($balance), 2) . ' ₺ (BORÇLUYUZ)'];
    if ($balance > 0) return ['class' => 'text-success', 'text' => number_format($balance, 2) . ' ₺ (ALACAKLIYIZ)'];
    return ['class' => 'text-muted', 'text' => '0,00 ₺ (DENK)'];
}

$off_style = getBalanceStyle($official_balance);
$tot_style = getBalanceStyle($total_balance);

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
        .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); color: white; }
        .bg-gradient-info { background: linear-gradient(45deg, #36b9cc, #258391); color: white; }
        .pending-row { background-color: #fffbf0 !important; } /* Hafif sarı */
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 my-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-0 fw-bold text-gray-800">
                    <i class="fa fa-user-tie text-secondary me-2"></i>
                    <?php echo guvenli_html($customer['company_name']); ?>
                </h4>
                <small class="text-muted"><?php echo guvenli_html($customer['customer_code']); ?> | <?php echo ($customer['customer_type']=='legal')?'Tüzel':'Şahıs'; ?></small>
            </div>
            <div>
                <a href="customers.php" class="btn btn-secondary me-2"><i class="fa fa-arrow-left"></i> Listeye Dön</a>
                <button onclick="window.print()" class="btn btn-outline-dark"><i class="fa fa-print"></i> Yazdır</button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow h-100 border-start-lg border-start-primary">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-primary text-uppercase mb-1">
                                    Resmi Bakiye (Muhasebeleşen)</div>
                                <div class="h5 mb-0 fw-bold <?php echo $off_style['class']; ?>">
                                    <?php echo $off_style['text']; ?>
                                </div>
                                <small class="text-muted" style="font-size: 0.75rem;">Faturası girilmiş işlemler ve ödemeler</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-file-invoice-dollar fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow h-100 border-start-lg border-start-warning">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-warning text-uppercase mb-1">
                                    Bekleyen İşlemler (Faturasız)</div>
                                <div class="h5 mb-0 fw-bold text-dark">
                                    <?php 
                                        if ($pending_orders < 0) echo number_format(abs($pending_orders), 2) . ' ₺ (Fatura Bekliyor)';
                                        elseif ($pending_orders > 0) echo number_format($pending_orders, 2) . ' ₺ (Fatura Keseceğiz)';
                                        else echo '0,00 ₺';
                                    ?>
                                </div>
                                <small class="text-muted" style="font-size: 0.75rem;">Henüz faturası girilmemiş siparişler</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow h-100 bg-gradient-light border border-secondary">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-dark text-uppercase mb-1">
                                    Genel / Planlanan Bakiye</div>
                                <div class="h4 mb-0 fw-bold <?php echo $tot_style['class']; ?>">
                                    <?php echo $tot_style['text']; ?>
                                </div>
                                <small class="text-dark opacity-75" style="font-size: 0.75rem;">Resmi + Bekleyen Tümü</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-balance-scale fa-2x text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 fw-bold text-primary">Cari Hesap Hareketleri</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle text-sm">
                        <thead class="table-dark text-white">
                            <tr>
                                <th width="100">Tarih</th>
                                <th width="120">İşlem Türü</th>
                                <th>Açıklama / Proje</th>
                                <th width="120">Belge No</th>
                                <th class="text-end text-warning">Borçlandırma<br><small>(Ödeme/İade)</small></th>
                                <th class="text-end text-success">Alacaklandırma<br><small>(Hizmet/Fatura)</small></th>
                                <th class="text-end" width="150">Bakiye</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-secondary fw-bold">
                                <td><?php echo date('d.m.Y', strtotime($customer['opening_balance_date'])); ?></td>
                                <td>Açılış</td>
                                <td>DEVİR BAKİYESİ</td>
                                <td>-</td>
                                <td class="text-end">
                                    <?php echo ($customer['opening_balance'] > 0) ? number_format($customer['opening_balance'], 2) : '-'; ?>
                                </td>
                                <td class="text-end">
                                    <?php echo ($customer['opening_balance'] < 0) ? number_format(abs($customer['opening_balance']), 2) : '-'; ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($customer['opening_balance'], 2); ?> ₺
                                </td>
                            </tr>

                            <?php foreach ($processed_rows as $p): 
                                $r = $p['data'];
                                $row_style = $p['is_pending'] ? 'pending-row' : '';
                            ?>
                                <tr class="<?php echo $row_style; ?>">
                                    <td><?php echo date('d.m.Y', strtotime($r['date'])); ?></td>
                                    
                                    <td>
                                        <?php if($r['type'] == 'debt'): ?>
                                            <span class="badge bg-danger bg-opacity-75 w-100">Gider / Alış</span>
                                        <?php elseif($r['type'] == 'credit'): ?>
                                            <span class="badge bg-success bg-opacity-75 w-100">Gelir / Satış</span>
                                        <?php elseif($r['type'] == 'payment_out'): ?>
                                            <span class="badge bg-warning text-dark w-100">Ödeme Çıkışı</span>
                                        <?php elseif($r['type'] == 'payment_in'): ?>
                                            <span class="badge bg-info text-dark w-100">Tahsilat</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php echo guvenli_html($r['description']); ?>
                                        <?php if(!empty($r['tour_code'])): ?>
                                            <br><small class="text-primary"><i class="fa fa-hashtag"></i> <?php echo $r['tour_code']; ?></small>
                                        <?php endif; ?>
                                        <?php if($p['is_pending']): ?>
                                            <br><span class="badge bg-warning text-dark border border-warning blink"><i class="fa fa-clock"></i> Fatura Bekliyor</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php 
                                            if(!empty($r['invoice_no'])) echo '<span class="fw-bold">'.guvenli_html($r['invoice_no']).'</span>';
                                            elseif($p['is_pending']) echo '<span class="text-muted fst-italic">-</span>'; 
                                            else echo '<span class="text-muted">Dekont/Makbuz</span>';
                                        ?>
                                    </td>

                                    <td class="text-end fw-bold text-dark">
                                        <?php 
                                            // Payment Out (Ödeme) veya Credit (Bizim Satışımız = Onların Borcu)
                                            if($r['type'] == 'payment_out' || $r['type'] == 'credit') {
                                                echo number_format((float)$r['amount'], 2, ',', '.');
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>

                                    <td class="text-end fw-bold text-dark">
                                        <?php 
                                            // Debt (Gider) veya Payment In (Tahsilat = Onların Alacağı Düşer ama muhasebe tekniği farklıdır, basit gösterelim)
                                            // Basit mantık: Bakiye EKSİYE gidiyorsa buraya yazalım.
                                            if($r['type'] == 'debt' || $r['type'] == 'payment_in') {
                                                echo number_format((float)$r['amount'], 2, ',', '.');
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>

                                    <td class="text-end fw-bold <?php echo ($p['balance'] < 0) ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo number_format($p['balance'], 2, ',', '.'); ?> ₺
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>