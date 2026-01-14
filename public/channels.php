<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
permission_check('view_finance');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- BLOKE İŞLEMİ (POST GELDİYSE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block'])) {
    $id = (int)$_POST['block_id'];
    $status = (int)$_POST['block_status']; // 1: Bloke Et, 0: Kaldır
    $reason = isset($_POST['block_reason']) ? temizle($_POST['block_reason']) : null;

    $stmt = $pdo->prepare("UPDATE payment_channels SET is_blocked = ?, block_reason = ? WHERE id = ?");
    $stmt->execute([$status, $reason, $id]);
    
    // Sayfayı yenile ki POST tekrar etmesin
    header("Location: channels.php");
    exit;
}

// Kurlar
$rates_db = $pdo->query("SELECT code, rate FROM currencies")->fetchAll(PDO::FETCH_KEY_PAIR);
$rates_db['TRY'] = 1.00; 

// Hesapları Çek
$stmt = $pdo->query("SELECT * FROM payment_channels ORDER BY type ASC, id ASC");
$channels = $stmt->fetchAll();

$banks = [];
$cards = [];
$total_asset_tl = 0;
$total_debt_tl = 0;

foreach ($channels as $c) {
    $rate = isset($rates_db[$c['currency']]) ? $rates_db[$c['currency']] : 1.00;
    
    // Kart veya Banka ayrımı
    if ($c['type'] == 'card') {
        $current_debt = $c['credit_limit'] - $c['available_balance'];
        
        // Eğer bloke değilse borç hesabına kat, blokeliyse hesaba katma (İsteğe bağlı, şimdilik katıyoruz)
        $total_debt_tl += ($current_debt * $rate);
        $cards[] = $c;
    } else {
        $tl_val = $c['current_balance'] * $rate;
        $c['tl_balance'] = $tl_val;
        $c['rate_used'] = $rate;
        $total_asset_tl += $tl_val;
        $banks[] = $c;
    }
}
$net_status = $total_asset_tl - $total_debt_tl;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Finansal Durum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-credit {
            background: linear-gradient(135deg, #2c3e50 0%, #000000 100%);
            color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }
        .card-bank { border-left: 5px solid #1cc88a; position: relative; }
        .currency-badge { font-size: 0.8rem; position: absolute; top: 10px; right: 10px; }
        
        /* BLOKE GÖRÜNÜMÜ */
        .blocked-channel {
            opacity: 0.7;
            border: 2px solid #dc3545 !important; /* Kırmızı Çerçeve */
        }
        .blocked-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 12px;
            color: white;
            flex-direction: column;
            text-align: center;
            padding: 10px;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Finansal Durum</h2>
            <a href="sync-channels.php" class="btn btn-success" onclick="return confirm('Google E-Tablodan veriler çekilecek. Onaylıyor musunuz?');">
                <i class="fab fa-google-drive"></i> E-Tablodan Güncelle
            </a>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-success text-white shadow h-100">
                    <div class="card-body text-center">
                        <h5>TOPLAM VARLIK</h5>
                        <h2 class="fw-bold"><?php echo number_format($total_asset_tl, 2, ',', '.'); ?> ₺</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-danger text-white shadow h-100">
                    <div class="card-body text-center">
                        <h5>TOPLAM BORÇ</h5>
                        <h2 class="fw-bold"><?php echo number_format($total_debt_tl, 2, ',', '.'); ?> ₺</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-primary text-white shadow h-100">
                    <div class="card-body text-center">
                        <h5>NET DURUM</h5>
                        <h2 class="fw-bold"><?php echo number_format($net_status, 2, ',', '.'); ?> ₺</h2>
                    </div>
                </div>
            </div>
        </div>

        <hr>

        <h5 class="text-secondary mb-3"><i class="fa fa-university"></i> Banka Hesapları & Kasa</h5>
        <div class="row mb-5">
            <?php foreach ($banks as $b): ?>
                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm card-bank h-100 <?php echo $b['is_blocked'] ? 'blocked-channel' : ''; ?>">
                        
                        <?php if($b['is_blocked']): ?>
                            <div class="blocked-overlay">
                                <i class="fa fa-lock fa-3x mb-2 text-danger"></i>
                                <h5 class="fw-bold text-danger">KULLANIMA KAPALI</h5>
                                <small class="text-white fst-italic">"<?php echo guvenli_html($b['block_reason']); ?>"</small>
                            </div>
                        <?php endif; ?>

                        <div class="card-body position-relative">
                            <span class="badge bg-light text-dark border currency-badge"><?php echo $b['currency']; ?></span>
                            
                            <div class="dropdown" style="position: absolute; top: 10px; right: 50px; z-index: 20;">
                                <button class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></button>
                                <ul class="dropdown-menu">
                                    <?php if($b['is_blocked']): ?>
                                        <li>
                                            <a class="dropdown-item" href="#" onclick="openBlockModal(<?php echo $b['id']; ?>, 0, '')">
                                                <i class="fa fa-unlock text-success"></i> Blokeyi Kaldır
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li>
                                            <a class="dropdown-item" href="#" onclick="openBlockModal(<?php echo $b['id']; ?>, 1, '')">
                                                <i class="fa fa-lock text-danger"></i> Bloke Et / Ayır
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <h5 class="fw-bold text-dark"><?php echo guvenli_html($b['name']); ?></h5>
                            <div class="text-muted small mb-2"><?php echo guvenli_html($b['account_number']); ?></div>
                            
                            <div class="d-flex justify-content-between align-items-end mt-3">
                                <div>
                                    <small class="text-muted d-block">Bakiye</small>
                                    <span class="h4 fw-bold text-success">
                                        <?php echo number_format($b['current_balance'], 2); ?> <?php echo $b['currency']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <h5 class="text-secondary mb-3"><i class="fa fa-credit-card"></i> Kredi Kartları</h5>
        <div class="row">
            <?php foreach ($cards as $c): ?>
                <?php $current_debt_display = $c['credit_limit'] - $c['available_balance']; ?>
                <div class="col-md-4 mb-3">
                    <div class="card card-credit shadow h-100 <?php echo $c['is_blocked'] ? 'blocked-channel' : ''; ?>">
                        
                        <?php if($c['is_blocked']): ?>
                            <div class="blocked-overlay">
                                <i class="fa fa-lock fa-3x mb-2 text-danger"></i>
                                <h5 class="fw-bold text-danger">REZERVE EDİLDİ</h5>
                                <small class="text-white fst-italic">"<?php echo guvenli_html($c['block_reason']); ?>"</small>
                            </div>
                        <?php endif; ?>

                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <h5 class="fw-bold"><i class="fa fa-wifi me-2"></i> <?php echo guvenli_html($c['name']); ?></h5>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-light text-dark me-2"><?php echo $c['currency']; ?></span>
                                    
                                    <div class="dropdown" style="z-index: 20;">
                                        <button class="btn btn-sm btn-link text-white" data-bs-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></button>
                                        <ul class="dropdown-menu">
                                            <?php if($c['is_blocked']): ?>
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="openBlockModal(<?php echo $c['id']; ?>, 0, '')">
                                                        <i class="fa fa-unlock text-success"></i> Blokeyi Kaldır
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="openBlockModal(<?php echo $c['id']; ?>, 1, '')">
                                                        <i class="fa fa-lock text-danger"></i> Bloke Et / Ayır
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-2 mb-3 font-monospace small opacity-75">
                                <?php echo guvenli_html($c['account_number']); ?>
                            </div>

                            <div class="row text-center mt-3 pt-3 border-top border-secondary">
                                <div class="col-4 border-end border-secondary">
                                    <small class="opacity-75" style="font-size:0.7rem;">LİMİT</small><br>
                                    <span class="fw-bold"><?php echo number_format($c['credit_limit'], 0); ?></span>
                                </div>
                                <div class="col-4 border-end border-secondary">
                                    <small class="opacity-75" style="font-size:0.7rem;">KULLANILABİLİR</small><br>
                                    <span class="fw-bold text-info"><?php echo number_format($c['available_balance'], 2); ?></span>
                                </div>
                                <div class="col-4">
                                    <small class="opacity-75" style="font-size:0.7rem;">GÜNCEL BORÇ</small><br>
                                    <span class="fw-bold text-danger"><?php echo number_format($current_debt_display, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <div class="modal fade" id="blockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="blockModalTitle">Hesabı Bloke Et</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="toggle_block" value="1">
                        <input type="hidden" name="block_id" id="modal_block_id">
                        <input type="hidden" name="block_status" id="modal_block_status">
                        
                        <div id="blockReasonDiv">
                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-triangle"></i> Bu hesabı bloke ettiğinizde, işlem ekleme sayfasında <b>seçilemez</b> duruma gelecektir.
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold">Sebep / Not (Zorunlu)</label>
                                <textarea name="block_reason" class="form-control" rows="3" placeholder="Örn: Dell Projesi için 10.000$ ayrıldı, dokunmayın." required></textarea>
                            </div>
                        </div>
                        
                        <div id="unblockMessage" class="d-none">
                            <p>Bu hesabın blokesini kaldırmak üzeresiniz. Onaylıyor musunuz?</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger" id="blockSubmitBtn">Blokeyi Uygula</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openBlockModal(id, status) {
            document.getElementById('modal_block_id').value = id;
            document.getElementById('modal_block_status').value = status;
            
            var modal = new bootstrap.Modal(document.getElementById('blockModal'));
            var title = document.getElementById('blockModalTitle');
            var btn = document.getElementById('blockSubmitBtn');
            var reasonDiv = document.getElementById('blockReasonDiv');
            var unblockMsg = document.getElementById('unblockMessage');
            
            if (status === 1) {
                // Bloke Etme Modu
                title.innerText = "Hesabı / Kartı Bloke Et";
                btn.innerText = "Bloke Et ve Kilitle";
                btn.className = "btn btn-danger";
                reasonDiv.classList.remove('d-none');
                unblockMsg.classList.add('d-none');
                // Reason textarea'yı required yap
                document.querySelector('textarea[name="block_reason"]').required = true;
            } else {
                // Bloke Kaldırma Modu
                title.innerText = "Blokeyi Kaldır";
                btn.innerText = "Blokeyi Kaldır";
                btn.className = "btn btn-success";
                reasonDiv.classList.add('d-none');
                unblockMsg.classList.remove('d-none');
                // Reason textarea'yı required olmaktan çıkar
                document.querySelector('textarea[name="block_reason"]').required = false;
            }
            
            modal.show();
        }
    </script>
</body>
</html>