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

// HEM ANA İŞLEMLERİ HEM ALT İŞLEMLERİ (FATURA/ÖDEME) ÇEK
$sql = "SELECT t.*, tc.code as tour_code, d.name as dep_name 
        FROM transactions t 
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id 
        LEFT JOIN departments d ON t.department_id = d.id
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
    
    // --- BURASI DÜZELTİLDİ: STATÜ KONTROLÜ ---
    // Bir işlem ne zaman resmidir?
    // 1. Ödeme/Tahsilat ise her zaman resmidir.
    // 2. Fatura ise (type=invoice) veya Faturası kesilmiş sipariş ise (invoice_status=issued).
    
    // Önce bu satırın bir child fatura kaydı mı yoksa ana sipariş mi olduğunu anlayalım.
    $is_child_invoice = ($type == 'invoice');
    
    // Eğer bu satır bir "Fatura Girişi" (Child) ise:
    // Bu satırın kendisine özel bir matematiksel etkisi yoktur çünkü ana siparişin durumunu değiştirdik.
    // Sadece görsel olarak orada durur.
    
    // Eğer bu satır "Ana Sipariş" (debt/credit) ise:
    // Durumuna bakarız. 'issued' ise Resmiye yazar, değilse Bekleyene yazar.
    
    $effect_official = 0;
    $is_pending_row = false; 
    
    if ($type == 'debt') {
        // GİDER SİPARİŞİ
        if ($row['invoice_status'] == 'issued') {
            // Faturası Girilmiş -> Resmi Borç
            $effect_official = -$amount;
        } else {
            // Faturası Yok -> Bekleyen Borç
            $pending_debt += $amount;
            $is_pending_row = true;
        }
    } 
    elseif ($type == 'credit') {
        // GELİR SİPARİŞİ
        if ($row['invoice_status'] == 'issued') {
            // Faturası Kesilmiş -> Resmi Alacak
            $effect_official = $amount;
        } else {
            // Faturası Yok -> Bekleyen Alacak
            $pending_credit += $amount;
            $is_pending_row = true;
        }
    }
    elseif ($type == 'payment_out') {
        // ÖDEME (Borç Düşer/Bakiye Artar)
        $effect_official = $amount; 
    } 
    elseif ($type == 'payment_in') {
        // TAHSİLAT (Alacak Düşer/Bakiye Azalır)
        $effect_official = -$amount;
    }
    
    // Child Invoice satırlarını bakiyeye katmıyoruz (Ana satır zaten katıldı)
    // Sadece görsel olarak listeliyoruz.

    // Toplamları İşle
    $official_balance += $effect_official;
    $running_balance += $effect_official;
    
    $processed_rows[] = [
        'data' => $row,
        'effect_official' => $effect_official,
        'balance' => $running_balance,
        'is_pending' => $is_pending_row,
        'is_invoice_log' => $is_child_invoice, // Görsel ayrım için
        'is_child' => ($row['parent_id'] > 0)
    ];
}

// Bakiye Renk ve Metin
function getBalanceStyle($balance) {
    if ($balance < -0.01) return ['class' => 'text-danger', 'text' => number_format(abs($balance), 2, ',', '.') . ' ₺ (BORÇLUYUZ)'];
    if ($balance > 0.01) return ['class' => 'text-success', 'text' => number_format($balance, 2, ',', '.') . ' ₺ (ALACAKLIYIZ)'];
    return ['class' => 'text-muted', 'text' => '0,00 ₺ (DENK)'];
}

$off_style = getBalanceStyle($official_balance);
$total_risk = $official_balance + ($pending_credit - $pending_debt);
$risk_style = getBalanceStyle($total_risk);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo guvenli_html($customer['company_name']); ?> - Ekstre</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-left-border-primary { border-left: 5px solid #0d6efd; }
        .card-left-border-warning { border-left: 5px solid #ffc107; }
        .card-left-border-success { border-left: 5px solid #198754; }
        
        .pending-row { background-color: #fff3cd !important; color: #856404; } /* Sarımtırak (Bekleyen) */
        .invoice-row { background-color: #e2e3e5 !important; color: #383d41; } /* Gri (Fatura Logu) */
        .official-row { background-color: #fff !important; } /* Beyaz (Resmileşmiş Sipariş) */
        
        .table-header-custom th { background-color: #343a40; color: white; border:none; padding: 12px; }
        .balance-box { min-height: 120px; display: flex; flex-direction: column; justify-content: center; }
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
                <div class="card shadow h-100 card-left-border-primary">
                    <div class="card-body balance-box">
                        <div class="text-uppercase text-primary fw-bold text-xs mb-2">Resmi Bakiye</div>
                        <div class="h3 mb-0 fw-bold <?php echo $off_style['class']; ?>"><?php echo $off_style['text']; ?></div>
                        <div class="small text-muted mt-2 border-top pt-2"><i class="fa fa-check-circle"></i> Faturalaşmış Net Durum</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow h-100 card-left-border-warning">
                    <div class="card-body balance-box">
                        <div class="text-uppercase text-warning fw-bold text-xs mb-2">Fatura Bekleyen (Siparişler)</div>
                        <div class="row">
                            <div class="col-6 border-end"><small class="d-block text-muted">Gelecek</small><span class="fw-bold text-success"><?php echo number_format($pending_credit, 2); ?> ₺</span></div>
                            <div class="col-6"><small class="d-block text-muted">Gidecek</small><span class="fw-bold text-danger"><?php echo number_format($pending_debt, 2); ?> ₺</span></div>
                        </div>
                        <div class="small text-muted mt-2 border-top pt-2"><i class="fa fa-clock"></i> Henüz faturası gelmemiş</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow h-100 card-left-border-success bg-white">
                    <div class="card-body balance-box">
                        <div class="text-uppercase text-success fw-bold text-xs mb-2">Genel Toplam (Risk)</div>
                        <div class="h3 mb-0 fw-bold <?php echo $risk_style['class']; ?>"><?php echo $risk_style['text']; ?></div>
                        <div class="small text-muted mt-2 border-top pt-2"><i class="fa fa-calculator"></i> Resmi + Bekleyen Hepsi</div>
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
                                <th width="100">Tarih</th>
                                <th width="140">İşlem Türü</th>
                                <th>Açıklama / Belge</th>
                                <th class="text-end" style="background-color:#ffecec; color:#842029;">Borç</th>
                                <th class="text-end" style="background-color:#e8f5e9; color:#0f5132;">Alacak</th>
                                <th class="text-end bg-dark text-white">Resmi Bakiye</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="fw-bold bg-light border-bottom">
                                <td><?php echo date('d.m.Y', strtotime($customer['opening_balance_date'])); ?></td>
                                <td>DEVİR</td>
                                <td>AÇILIŞ BAKİYESİ</td>
                                <td class="text-end"><?php echo ($customer['opening_balance']<0)?number_format(abs($customer['opening_balance']),2):'-'; ?></td>
                                <td class="text-end"><?php echo ($customer['opening_balance']>0)?number_format($customer['opening_balance'],2):'-'; ?></td>
                                <td class="text-end text-dark bg-light border-start"><?php echo number_format($customer['opening_balance'], 2); ?> ₺</td>
                            </tr>
                            <?php foreach ($processed_rows as $p): 
                                $r = $p['data'];
                                
                                // Stil Belirleme
                                $row_class = 'official-row';
                                if ($p['is_pending']) $row_class = 'pending-row';
                                elseif ($p['is_invoice_log']) $row_class = 'invoice-row';

                                $amount_fmt = number_format((float)$r['amount'], 2, ',', '.');
                                $indent = $p['is_child'] ? '<i class="fa fa-level-up-alt fa-rotate-90 text-primary ms-3 me-2"></i>' : '';
                                
                                // Tip Etiketi
                                $badge = '';
                                if ($r['type']=='debt') {
                                    $badge = $p['is_pending'] 
                                        ? '<span class="badge bg-warning text-dark"><i class="fa fa-clock"></i> Sipariş (Faturasız)</span>' 
                                        : '<span class="badge bg-danger">Gider Faturası</span>';
                                }
                                elseif ($r['type']=='credit') {
                                    $badge = $p['is_pending']
                                        ? '<span class="badge bg-warning text-dark"><i class="fa fa-clock"></i> Sipariş (Faturasız)</span>'
                                        : '<span class="badge bg-success">Gelir Faturası</span>';
                                }
                                elseif ($r['type']=='invoice') $badge='<span class="badge bg-secondary text-white"><i class="fa fa-info-circle"></i> Fatura Girişi</span>';
                                elseif ($r['type']=='payment_out') $badge='<span class="badge bg-primary">Ödeme</span>';
                                elseif ($r['type']=='payment_in') $badge='<span class="badge bg-dark">Tahsilat</span>';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><?php echo date('d.m.Y', strtotime($r['date'])); ?></td>
                                    <td><?php echo $badge; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php echo $indent; ?>
                                            <div>
                                                <strong><?php echo guvenli_html($r['invoice_no']); ?></strong>
                                                <div class="small text-muted"><?php echo guvenli_html($r['description']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end text-danger bg-light bg-opacity-50">
                                        <?php if ($p['effect_official'] < 0) echo number_format(abs($p['effect_official']), 2, ',', '.'); 
                                              elseif ($p['is_pending'] && $r['type']=='debt') echo '<span class="text-muted">('.$amount_fmt.')</span>'; 
                                              else echo '-'; ?>
                                    </td>
                                    <td class="text-end text-success bg-light bg-opacity-50">
                                        <?php if ($p['effect_official'] > 0) echo number_format($p['effect_official'], 2, ',', '.'); 
                                              elseif ($p['is_pending'] && $r['type']=='credit') echo '<span class="text-muted">('.$amount_fmt.')</span>'; 
                                              else echo '-'; ?>
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