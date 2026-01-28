<?php
// public/customer-details.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) die("Cari bulunamadı.");

// İŞLEMLERİ ÇEK
$sql = "SELECT t.*, tc.code as tour_code, d.name as dep_name 
        FROM transactions t 
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id 
        LEFT JOIN departments d ON t.department_id = d.id
        WHERE t.customer_id = ? 
        ORDER BY t.date ASC, t.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- HESAPLAMA MANTIĞI ---
$official_balance = $customer['opening_balance']; 
$pending_order_balance = 0; 

$processed_rows = [];
$running_balance = $customer['opening_balance'];

// Fatura olarak kabul edilecek tanımlar
$invoice_doc_names = ['Fatura', 'e-Fatura', 'e-Arşiv', 'Proforma', 'Gelen Fatura', 'Kesilen Fatura'];

foreach ($transactions as $row) {
    $amount = (float)$row['amount'];
    
    // 1. BU SATIR BİR FATURA MI? (GÜÇLENDİRİLMİŞ KONTROL)
    // doc_type 'invoice' İSE veya document_type 'e-Fatura' vb. İSE
    $is_invoice = ($row['doc_type'] == 'invoice' || in_array($row['document_type'], $invoice_doc_names));
    
    // 2. BU SATIR BİR SİPARİŞ (ANA İŞLEM) Mİ?
    // Parent ID'si yoksa VE Fatura değilse VE Ödeme değilse -> Sipariştir.
    $is_parent_order = (empty($row['parent_id']) && !$is_invoice && strpos($row['type'], 'payment') === false);

    $effect_on_official = 0;

    // --- SENARYO A: SİPARİŞ / EMRİ (Ana İşlem) ---
    // Sadece "Bekleyen" bakiyesine eklenir.
    if ($is_parent_order) {
        if ($row['type'] == 'debt') $pending_order_balance -= $amount; 
        else $pending_order_balance += $amount;
    }

    // --- SENARYO B: FATURA (Resmileşen İşlem) ---
    elseif ($is_invoice) {
        if ($row['type'] == 'debt' || $row['type'] == 'payment_out') { 
            // Gider Faturası (Borçlanma)
            // Not: Bazen sistem debt yerine payment_out olarak kaydedebiliyor, ikisini de kontrol ediyoruz.
            $effect_on_official = -$amount; // Borcumuz resmileşti (-)
            $pending_order_balance += $amount; // Bekleyen borçtan düştük (+ yaparak negatifi azalttık)
        } else {
            // Gelir Faturası (Alacaklanma)
            $effect_on_official = $amount; // Alacağımız resmileşti (+)
            $pending_order_balance -= $amount; // Bekleyen alacaktan düştük
        }
    } 
    
    // --- SENARYO C: ÖDEME / TAHSİLAT ---
    else {
        // Eğer Fatura değilse ve Ana işlem değilse, kesinlikle Ödemedir.
        if ($row['type'] == 'payment_out') $effect_on_official = $amount; // Ödedik (+)
        elseif ($row['type'] == 'payment_in') $effect_on_official = -$amount; // Aldık (-)
    }

    $official_balance += $effect_on_official;
    $running_balance += $effect_on_official;

    $processed_rows[] = [
        'data' => $row,
        'effect_official' => $effect_on_official,
        'balance' => $running_balance,
        'is_invoice_row' => $is_invoice,
        'is_order_row' => $is_parent_order
    ];
}

function getBalanceStyle($balance) {
    if ($balance < -0.01) return ['class' => 'text-danger', 'text' => number_format(abs($balance), 2) . ' ₺ (BORÇLUYUZ)'];
    if ($balance > 0.01) return ['class' => 'text-success', 'text' => number_format($balance, 2) . ' ₺ (ALACAKLIYIZ)'];
    return ['class' => 'text-muted', 'text' => '0,00 ₺ (DENK)'];
}

$off_style = getBalanceStyle($official_balance);
$risk_style = getBalanceStyle($official_balance + $pending_order_balance);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo guvenli_html($customer['company_name']); ?> - Ekstre</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-left-primary { border-left: 5px solid #0d6efd; }
        .card-left-warning { border-left: 5px solid #ffc107; }
        .card-left-dark { border-left: 5px solid #212529; }
        .row-invoice { background-color: #fff3cd; } 
        .row-payment { background-color: #d1e7dd; } 
        .row-order { background-color: #f8f9fa; color: #888; font-style: italic; }
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
                <div class="card shadow h-100 card-left-primary">
                    <div class="card-body">
                        <div class="text-uppercase text-primary fw-bold text-xs mb-1">Resmi Muhasebe Bakiyesi</div>
                        <div class="h4 mb-0 fw-bold <?php echo $off_style['class']; ?>"><?php echo $off_style['text']; ?></div>
                        <small class="text-muted">Faturası Kesilmiş İşlemler ve Ödemeler</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow h-100 card-left-warning">
                    <div class="card-body">
                        <div class="text-uppercase text-warning fw-bold text-xs mb-1">Fatura Bekleyen Siparişler</div>
                        <div class="h4 mb-0 fw-bold text-dark">
                            <?php echo number_format(abs($pending_order_balance), 2, ',', '.') . ' ₺'; ?>
                        </div>
                        <small class="text-muted">Henüz faturalaşmamış kısım</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow h-100 card-left-dark">
                    <div class="card-body">
                        <div class="text-uppercase text-dark fw-bold text-xs mb-1">Genel Toplam (Net Durum)</div>
                        <div class="h4 mb-0 fw-bold <?php echo $risk_style['class']; ?>"><?php echo $risk_style['text']; ?></div>
                        <small class="text-muted">Resmi Bakiye + Bekleyen Siparişler</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0 align-middle text-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Tarih</th> <th>Tür</th> <th>Açıklama / Belge</th>
                                <th class="text-end">Borç (Hizmet/Fatura)</th>
                                <th class="text-end">Alacak (Ödeme)</th>
                                <th class="text-end">Bakiye (Resmi)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-light fw-bold">
                                <td><?php echo date('d.m.Y', strtotime($customer['opening_balance_date'])); ?></td>
                                <td>Açılış</td> <td>DEVİR</td>
                                <td class="text-end"><?php echo ($customer['opening_balance'] < 0) ? number_format(abs($customer['opening_balance']), 2) : '-'; ?></td>
                                <td class="text-end"><?php echo ($customer['opening_balance'] > 0) ? number_format($customer['opening_balance'], 2) : '-'; ?></td>
                                <td class="text-end"><?php echo number_format($customer['opening_balance'], 2); ?> ₺</td>
                            </tr>
                            <?php foreach ($processed_rows as $p): $r = $p['data']; 
                                $bg_class = '';
                                if ($p['is_invoice_row']) $bg_class = 'row-invoice';
                                elseif ($p['is_order_row']) $bg_class = 'row-order';
                                else $bg_class = 'row-payment';
                            ?>
                                <tr class="<?php echo $bg_class; ?>">
                                    <td><?php echo date('d.m.Y', strtotime($r['date'])); ?></td>
                                    <td>
                                        <?php 
                                            if ($p['is_invoice_row']) echo '<span class="badge bg-warning text-dark">FATURA</span>';
                                            elseif ($p['is_order_row']) echo '<span class="badge bg-secondary">SİPARİŞ/TALEP</span>';
                                            else echo '<span class="badge bg-success">ÖDEME/TAHSİLAT</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            echo guvenli_html($r['description']);
                                            if(!empty($r['invoice_no'])) echo '<br><strong>No: '.guvenli_html($r['invoice_no']).'</strong>';
                                            
                                            // Ek Detaylar
                                            if(!empty($r['tour_code'])) echo ' <span class="badge bg-info text-dark"><i class="fa fa-hashtag"></i> '.$r['tour_code'].'</span>';
                                            if(!empty($r['dep_name'])) echo ' <span class="badge bg-light text-dark border">'.$r['dep_name'].'</span>';
                                        ?>
                                    </td>
                                    
                                    <td class="text-end text-danger fw-bold">
                                        <?php 
                                            // Gider Faturası veya Tahsilat
                                            // Fatura İSE VE (Türü Debt veya Payment Out ise - Bazen sistem payment_out olarak kaydedebilir)
                                            if (($p['is_invoice_row'] && $r['type'] == 'debt') || $r['type'] == 'payment_in') 
                                                echo number_format($r['amount'], 2);
                                            // Bilgi Amaçlı: Gider Siparişi
                                            elseif ($r['type'] == 'debt' && $p['is_order_row']) 
                                                echo '<span class="text-muted opacity-50" title="Fatura Bekleniyor">(' . number_format($r['amount'], 2) . ')</span>';
                                            else echo '-';
                                        ?>
                                    </td>

                                    <td class="text-end text-success fw-bold">
                                        <?php 
                                            // Ödeme veya Gelir Faturası
                                            if (($p['is_invoice_row'] && $r['type'] == 'credit') || $r['type'] == 'payment_out') 
                                                echo number_format($r['amount'], 2);
                                            // Bilgi Amaçlı: Gelir Siparişi
                                            elseif ($r['type'] == 'credit' && $p['is_order_row']) 
                                                echo '<span class="text-muted opacity-50" title="Fatura Keseceğiz">(' . number_format($r['amount'], 2) . ')</span>';
                                            else echo '-';
                                        ?>
                                    </td>

                                    <td class="text-end fw-bold">
                                        <?php 
                                            // Sadece resmi işlemlerde bakiye değişir
                                            if ($p['effect_official'] == 0 && !$p['is_invoice_row']) echo '<span class="text-muted small fst-italic">(Bekliyor)</span>';
                                            else echo number_format($p['balance'], 2) . ' ₺'; 
                                        ?>
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