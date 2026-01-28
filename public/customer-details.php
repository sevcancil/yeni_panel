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

// 2. İŞLEMLERİ ÇEK (Eskiden Yeniye)
$sql = "SELECT t.*, tc.code as tour_code, d.name as dep_name 
        FROM transactions t 
        LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id 
        LEFT JOIN departments d ON t.department_id = d.id
        WHERE t.customer_id = ? 
        ORDER BY t.date ASC, t.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. HESAPLAMALAR (YENİ MANTIK)
// Resmi Bakiye: Sadece Faturası Olanlar + Ödemeler
// Bekleyen: Faturası Olmayan Siparişler

$official_balance = $customer['opening_balance']; 
$pending_balance  = 0; 

$processed_rows = [];
$running_balance = $customer['opening_balance']; // Tablodaki akış (Resmi)

foreach ($transactions as $row) {
    $amount = (float)$row['amount'];
    $is_invoice_missing = empty($row['invoice_no']);
    
    // --- ETKİ HESABI ---
    // Eğer işlem bir "Borçlanma" (Hizmet Alımı) ise ve Faturası YOKSA -> Resmi Bakiyeyi Etkilemez!
    
    $effect_on_official = 0; // Resmi bakiyeye etkisi
    $effect_on_pending = 0;  // Bekleyen bakiyeye etkisi

    if ($row['type'] == 'debt') {
        // GİDER / ALIŞ
        if ($is_invoice_missing) {
            $effect_on_pending = -$amount; // Fatura yok, sadece bekleyene yaz
        } else {
            $effect_on_official = -$amount; // Fatura var, borca yaz
        }

    } elseif ($row['type'] == 'payment_out') {
        // ÖDEME ÇIKIŞI (Her zaman resmi)
        $effect_on_official = $amount; 

    } elseif ($row['type'] == 'credit') {
        // GELİR / SATIŞ
        if ($is_invoice_missing) {
            $effect_on_pending = $amount;
        } else {
            $effect_on_official = $amount;
        }

    } elseif ($row['type'] == 'payment_in') {
        // TAHSİLAT (Her zaman resmi)
        $effect_on_official = -$amount;
    }

    // Toplamları Güncelle
    $official_balance += $effect_on_official;
    $pending_balance += $effect_on_pending;
    
    // Tablo Akışı (Sadece resmi hareketler bakiyeyi değiştirir)
    $running_balance += $effect_on_official;
    
    $processed_rows[] = [
        'data' => $row,
        'effect_official' => $effect_on_official,
        'balance' => $running_balance,
        'is_pending' => $is_invoice_missing && ($row['type'] == 'debt' || $row['type'] == 'credit')
    ];
}

// Bakiye Renk ve Metin Fonksiyonu
function getBalanceStyle($balance, $is_pending_box = false) {
    if ($balance < 0) {
        $txt = $is_pending_box ? 'Fatura Bekleniyor' : 'BORÇLUYUZ';
        return ['class' => 'text-danger', 'text' => number_format(abs($balance), 2) . ' ₺ (' . $txt . ')'];
    }
    if ($balance > 0) {
        $txt = $is_pending_box ? 'Fatura Keseceğiz' : 'ALACAKLIYIZ (Avans)';
        return ['class' => 'text-success', 'text' => number_format($balance, 2) . ' ₺ (' . $txt . ')'];
    }
    return ['class' => 'text-muted', 'text' => '0,00 ₺ (DENK)'];
}

$off_style = getBalanceStyle($official_balance);
$pend_style = getBalanceStyle($pending_balance, true);

// Genel Toplam (Risk)
$total_risk = $official_balance + $pending_balance;
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
        /* Faturası olmayan satırlar biraz soluk ve italik görünsün ki hesaplaşmaya dahil olmadığı anlaşılsın */
        .pending-row { background-color: #fffbf0 !important; color: #888; font-style: italic; } 
        .table-header-custom th { background-color: #343a40; color: white; border:none; }
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
                <div class="card shadow h-100 card-left-border-primary">
                    <div class="card-body">
                        <div class="text-uppercase text-primary fw-bold text-xs mb-1">Resmi Muhasebe Bakiyesi</div>
                        <div class="h4 mb-0 fw-bold <?php echo $off_style['class']; ?>">
                            <?php echo $off_style['text']; ?>
                        </div>
                        <div class="small text-muted mt-2">
                            <i class="fa fa-check-circle"></i> Sadece <b>Faturalaşmış</b> işlemler ve <b>Ödemeler</b> dahildir.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow h-100 card-left-border-warning">
                    <div class="card-body">
                        <div class="text-uppercase text-warning fw-bold text-xs mb-1">Fatura Bekleyen Tutar</div>
                        <div class="h4 mb-0 fw-bold text-dark">
                            <?php 
                                if ($pending_balance == 0) echo '<span class="text-muted">Yok</span>';
                                else echo number_format(abs($pending_balance), 2, ',', '.') . ' ₺';
                            ?>
                        </div>
                        <div class="small text-muted mt-2">
                            <i class="fa fa-clock"></i> Henüz faturası gelmemiş/kesilmemiş işlemler.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow h-100 card-left-border-success bg-white">
                    <div class="card-body">
                        <div class="text-uppercase text-success fw-bold text-xs mb-1">Toplam İşlem Bakiyesi</div>
                        <div class="h4 mb-0 fw-bold <?php echo $risk_style['class']; ?>">
                            <?php echo $risk_style['text']; ?>
                        </div>
                        <div class="small text-muted mt-2">
                            <i class="fa fa-calculator"></i> Resmi Bakiye + Bekleyenler (Her şey dahil).
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary">Cari Hesap Ekstresi (Resmi)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle">
                        <thead class="table-header-custom">
                            <tr>
                                <th width="100">Tarih</th>
                                <th width="130">İşlem</th>
                                <th>Açıklama / Fatura No</th>
                                
                                <th class="text-end" style="background-color:#d1e7dd; color:#0f5132;">
                                    Borçlandırma<br><small>(Ödeme/İade)</small>
                                </th>
                                <th class="text-end" style="background-color:#f8d7da; color:#842029;">
                                    Alacaklandırma<br><small>(Fatura Tutarı)</small>
                                </th>
                                
                                <th class="text-end" width="160">Resmi Bakiye</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="fw-bold bg-light">
                                <td><?php echo date('d.m.Y', strtotime($customer['opening_balance_date'])); ?></td>
                                <td><span class="badge bg-secondary">Açılış</span></td>
                                <td>DEVİR BAKİYESİ</td>
                                <td class="text-end">
                                    <?php echo ($customer['opening_balance'] > 0) ? number_format($customer['opening_balance'], 2, ',', '.') : '-'; ?>
                                </td>
                                <td class="text-end">
                                    <?php echo ($customer['opening_balance'] < 0) ? number_format(abs($customer['opening_balance']), 2, ',', '.') : '-'; ?>
                                </td>
                                <td class="text-end text-dark">
                                    <?php echo number_format($customer['opening_balance'], 2, ',', '.'); ?> ₺
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
                                            <span class="badge bg-danger bg-opacity-75 w-100">Hizmet/Mal Alışı</span>
                                        <?php elseif($r['type'] == 'credit'): ?>
                                            <span class="badge bg-success bg-opacity-75 w-100">Hizmet/Mal Satışı</span>
                                        <?php elseif($r['type'] == 'payment_out'): ?>
                                            <span class="badge bg-primary w-100">Ödeme Çıkışı</span>
                                        <?php elseif($r['type'] == 'payment_in'): ?>
                                            <span class="badge bg-info text-dark w-100">Tahsilat</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php 
                                            // Fatura No varsa göster, yoksa Uyarı ver
                                            if(!empty($r['invoice_no'])) {
                                                echo '<i class="fa fa-file-invoice text-dark"></i> <strong>'.guvenli_html($r['invoice_no']).'</strong>';
                                            } elseif($p['is_pending']) {
                                                echo '<span class="badge bg-warning text-dark border border-warning"><i class="fa fa-clock"></i> Fatura Bekliyor</span>';
                                                echo ' <small class="text-muted">(Bakiyeye Dahil Değil)</small>';
                                            } else {
                                                echo '<span class="text-muted">Dekont/Makbuz</span>';
                                            }
                                        ?>
                                        <br>
                                        <small class="text-muted"><?php echo guvenli_html($r['description']); ?></small>
                                    </td>

                                    <td class="text-end fw-bold text-success">
                                        <?php 
                                            if($r['type'] == 'payment_out' || $r['type'] == 'credit') {
                                                echo number_format((float)$r['amount'], 2, ',', '.');
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>

                                    <td class="text-end fw-bold text-danger">
                                        <?php 
                                            // Eğer Fatura YOKSA buraya rakam yazma (veya parantez içinde yaz)
                                            if($p['is_pending']) {
                                                echo '<span class="text-muted text-decoration-line-through" title="Fatura bekleniyor">('.number_format((float)$r['amount'], 2, ',', '.').')</span>';
                                            } else {
                                                if($r['type'] == 'debt' || $r['type'] == 'payment_in') {
                                                    echo number_format((float)$r['amount'], 2, ',', '.');
                                                } else {
                                                    echo '-';
                                                }
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