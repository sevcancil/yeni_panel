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

// HEM ANA İŞLEMLERİ HEM ALT İŞLEMLERİ ÇEK
$sql = "SELECT t.*, 
               p.type as parent_type, 
               p.payment_status as parent_payment_status,
               tc.code as tour_code 
        FROM transactions t 
        LEFT JOIN transactions p ON t.parent_id = p.id 
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id 
        WHERE t.customer_id = ? 
        ORDER BY 
            (CASE WHEN t.parent_id > 0 THEN t.parent_id ELSE t.id END) ASC, 
            t.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- HESAPLAMA MOTORU ---
$official_balance = $customer['opening_balance']; 
$pending_debt = 0;   
$pending_credit = 0; 

$processed_rows = [];
$running_balance = $customer['opening_balance']; 

foreach ($transactions as $row) {
    $amount = (float)$row['amount'];
    $type = $row['type'];
    $is_deleted = isset($row['is_deleted']) && $row['is_deleted'] == 1;

    // Sütun Değerleri (Her satır için sıfırlanır)
    $col_siparis = 0;   // Sütun 1: Sipariş (Etkisiz)
    $col_para = 0;      // Sütun 2: Ödendi (Nakit Hareketler)
    $col_fatura = 0;    // Sütun 3: Tahsil Edildi (Fatura Hareketleri)
    
    $effect_on_balance = 0;
    $is_pending_row = false; 
    $is_child = ($row['parent_id'] > 0);

    if ($is_deleted) {
        $effect_on_balance = 0;
    } else {
        
        // 1. ANA SİPARİŞLER
        if ($type == 'debt') {
            $col_siparis = $amount;
            if ($row['invoice_status'] != 'issued') {
                $pending_debt += $amount;
                $is_pending_row = true;
            }
        } 
        elseif ($type == 'credit') {
            $col_siparis = $amount;
            if ($row['invoice_status'] != 'issued') {
                $pending_credit += $amount;
                $is_pending_row = true;
            }
        }

        // 2. FATURALAR
        elseif ($type == 'invoice') {
            if ($row['parent_type'] == 'debt') {
                // GİDER FATURASI (+)
                $col_fatura = $amount; 
                $effect_on_balance = $amount; 
            } 
            elseif ($row['parent_type'] == 'credit') {
                // GELİR FATURASI (-)
                $col_fatura = -$amount; 
                $effect_on_balance = -$amount;
            }
        }

        // 3. PARA HAREKETLERİ
        elseif ($type == 'payment_out') {
            // ÖDEME ÇIKIŞI (-)
            $col_para = -$amount; 
            $effect_on_balance = -$amount;
        } 
        elseif ($type == 'payment_in') {
            // TAHSİLAT GİRİŞİ (+)
            $col_para = $amount; 
            $effect_on_balance = $amount;
        }
    }

    $official_balance += $effect_on_balance;
    $running_balance += $effect_on_balance;
    
    $orig_amt = ($row['original_amount'] > 0) ? $row['original_amount'] : $amount;
    $currency_display = ($row['currency'] != 'TRY') ? number_format($orig_amt, 2) . ' ' . $row['currency'] : '';

    $processed_rows[] = [
        'data' => $row,
        'col_siparis' => $col_siparis,
        'col_para' => $col_para,
        'col_fatura' => $col_fatura,
        'balance' => $running_balance,
        'is_pending' => $is_pending_row,
        'is_invoice' => ($type == 'invoice'),
        'is_child' => $is_child,
        'is_deleted' => $is_deleted,
        'currency_display' => $currency_display
    ];
}

// Bakiye Renklendirme
function getBalanceStyle($balance) {
    if ($balance > 0.01) return ['class' => 'text-danger', 'text' => number_format($balance, 2, ',', '.') . ' ₺ <small>(BORÇLUYUZ)</small>'];
    if ($balance < -0.01) return ['class' => 'text-success', 'text' => number_format(abs($balance), 2, ',', '.') . ' ₺ <small>(ALACAKLIYIZ)</small>'];
    return ['class' => 'text-muted', 'text' => '0,00 ₺ <small>(KAPANDI)</small>'];
}

$off_style = getBalanceStyle($official_balance);
$total_risk = $official_balance + $pending_debt - $pending_credit;
$risk_style = getBalanceStyle($total_risk);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo guvenli_html($customer['company_name']); ?> - Detay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-left-primary { border-left: 5px solid #0d6efd; }
        .card-left-warning { border-left: 5px solid #ffc107; }
        .card-left-success { border-left: 5px solid #198754; }
        /* .card-left-info kaldırıldı çünkü 4. kart yok */
        
        .row-parent { background-color: #e9ecef !important; color: #495057; font-weight: 600; border-top: 3px solid #dee2e6; }
        .row-child { background-color: #fff !important; }
        .row-deleted { background-color: #ffe6e6 !important; color: #b02a37 !important; text-decoration: line-through; opacity: 0.7; }
        .row-deleted .badge { opacity: 0.5; }

        .child-indent { padding-left: 40px !important; position: relative; }
        .child-icon { position: absolute; left: 15px; top: 15px; color: #adb5bd; }

        .table-header-custom th { background-color: #343a40; color: white; border:none; padding: 12px; font-weight: 500; }
        .balance-box { min-height: 100px; display: flex; flex-direction: column; justify-content: center; }
        
        .amt-box { font-weight: bold; display: block; }
        .curr-sub { font-size: 0.75rem; color: #6c757d; font-weight: normal; display: block; }
        
        /* Renk Sınıfları */
        .val-pos { color: #dc3545; } 
        .val-neg { color: #198754; } 
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 my-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-0 fw-bold text-gray-800">
                    <i class="fa fa-building text-secondary me-2"></i>
                    <?php echo guvenli_html($customer['company_name']); ?>
                </h4>
                <small class="text-muted">
                    <?php echo guvenli_html($customer['city']); ?>
                </small>
            </div>
            <div class="d-flex gap-2">
                <a href="customers.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Listeye Dön</a>
                <button onclick="window.print()" class="btn btn-outline-dark"><i class="fa fa-print"></i> Yazdır</button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow h-100 card-left-primary">
                    <div class="card-body balance-box">
                        <div class="text-uppercase text-primary fw-bold text-xs mb-1">Resmi Bakiye</div>
                        <div class="h4 mb-0 fw-bold <?php echo $off_style['class']; ?>"><?php echo $off_style['text']; ?></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card shadow h-100 card-left-warning">
                    <div class="card-body balance-box">
                        <div class="text-uppercase text-warning fw-bold text-xs mb-1">Fatura Bekleyen</div>
                        <div class="row g-0">
                            <div class="col-6 border-end pe-2">
                                <small class="d-block text-muted" style="font-size:0.7rem;">Gelecek (Alacak)</small>
                                <span class="fw-bold text-success"><?php echo number_format($pending_credit, 2, ',', '.'); ?> ₺</span>
                            </div>
                            <div class="col-6 ps-2">
                                <small class="d-block text-muted" style="font-size:0.7rem;">Gidecek (Borç)</small>
                                <span class="fw-bold text-danger"><?php echo number_format($pending_debt, 2, ',', '.'); ?> ₺</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow h-100 card-left-success">
                    <div class="card-body balance-box">
                        <div class="text-uppercase text-success fw-bold text-xs mb-1">Genel Toplam (Risk)</div>
                        <div class="h4 mb-0 fw-bold <?php echo $risk_style['class']; ?>"><?php echo $risk_style['text']; ?></div>
                        <small class="text-muted" style="font-size: 0.75rem;">(Resmi + Bekleyen Siparişler)</small>
                    </div>
                </div>
            </div>
            
            </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-white">
                <h6 class="m-0 fw-bold text-primary"><i class="fa fa-list-ol"></i> Hesap Hareketleri</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle" style="font-size: 0.9rem;">
                        <thead class="table-header-custom">
                            <tr>
                                <th width="50">ID</th>
                                <th width="90">Tarih</th>
                                <th width="80">Tur Kodu</th>
                                <th width="120">İşlem Türü</th>
                                <th>Açıklama / Belge</th>
                                <th class="text-end bg-light text-secondary" width="130">TUTAR (SİPARİŞ)</th>
                                <th class="text-end" width="130" style="background-color:#ffecec; color:#842029;">ÖDENDİ (PARA)</th>
                                <th class="text-end" width="130" style="background-color:#e8f5e9; color:#0f5132;">TAHSİL EDİLDİ (FATURA)</th>
                                <th class="text-end bg-dark text-white" width="140">BAKİYE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="fw-bold bg-light border-bottom">
                                <td>-</td>
                                <td><?php echo date('d.m.Y', strtotime($customer['opening_balance_date'])); ?></td>
                                <td>-</td>
                                <td><span class="badge bg-secondary">DEVİR</span></td>
                                <td>AÇILIŞ BAKİYESİ</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end text-dark bg-light border-start"><?php echo number_format($customer['opening_balance'], 2, ',', '.'); ?> ₺</td>
                            </tr>

                            <?php foreach ($processed_rows as $p): 
                                $r = $p['data'];
                                $row_class = $p['is_deleted'] ? 'row-deleted' : ($p['is_child'] ? 'row-child' : 'row-parent');
                                $id_display = $p['is_child'] ? '' : '<strong>#'.$r['id'].'</strong>';
                                $tour_display = !empty($r['tour_code']) ? '<span class="badge bg-info text-dark">'.$r['tour_code'].'</span>' : '-';

                                $badge = '';
                                if ($r['type']=='debt') $badge = '<span class="badge bg-secondary">Sipariş (Gider)</span>';
                                elseif ($r['type']=='credit') $badge = '<span class="badge bg-secondary">Sipariş (Gelir)</span>';
                                elseif ($r['type']=='invoice') $badge = '<span class="badge bg-dark">FATURA</span>';
                                elseif ($r['type']=='payment_out') $badge = '<span class="badge bg-primary">Ödeme</span>';
                                elseif ($r['type']=='payment_in') $badge = '<span class="badge bg-success">Tahsilat</span>';
                                if($p['is_deleted']) $badge .= ' <span class="badge bg-danger">İPTAL</span>';

                                $desc_class = $p['is_child'] ? 'child-indent' : '';
                                $indent_icon = $p['is_child'] ? '<i class="fa fa-level-up-alt fa-rotate-90 child-icon"></i>' : '';
                                
                                $color_para = ($p['col_para'] < 0) ? 'text-danger' : 'text-success'; 
                                $color_fatura = ($p['col_fatura'] > 0) ? 'text-danger' : 'text-success';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td class="text-center"><?php echo $id_display; ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($r['date'])); ?></td>
                                    <td><?php echo $tour_display; ?></td>
                                    <td><?php echo $badge; ?></td>
                                    
                                    <td class="<?php echo $desc_class; ?>">
                                        <?php echo $indent_icon; ?>
                                        <strong><?php echo guvenli_html($r['invoice_no']); ?></strong>
                                        <div class="text-muted text-truncate" style="max-width:300px;"><?php echo guvenli_html($r['description']); ?></div>
                                    </td>

                                    <td class="text-end bg-light text-secondary">
                                        <?php if($p['col_siparis'] > 0): ?>
                                            <span class="amt-box"><?php echo number_format($p['col_siparis'], 2, ',', '.'); ?></span>
                                            <span class="curr-sub"><?php echo $p['currency_display']; ?></span>
                                        <?php else: echo '-'; endif; ?>
                                    </td>

                                    <td class="text-end bg-light bg-opacity-50">
                                        <?php if($p['col_para'] != 0): ?>
                                            <span class="amt-box <?php echo $color_para; ?>"><?php echo number_format($p['col_para'], 2, ',', '.'); ?></span>
                                            <span class="curr-sub"><?php echo $p['currency_display']; ?></span>
                                        <?php else: echo '-'; endif; ?>
                                    </td>

                                    <td class="text-end bg-light bg-opacity-50">
                                        <?php if($p['col_fatura'] != 0): ?>
                                            <span class="amt-box <?php echo $color_fatura; ?>"><?php echo number_format($p['col_fatura'], 2, ',', '.'); ?></span>
                                            <span class="curr-sub"><?php echo $p['currency_display']; ?></span>
                                        <?php else: echo '-'; endif; ?>
                                    </td>

                                    <td class="text-end fw-bold border-start text-dark">
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