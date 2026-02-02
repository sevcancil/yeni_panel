<?php
// public/company-cards.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// YETKİLER
$can_manage = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'muhasebe');
$can_see_details = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'muhasebe');

$message = '';

// --- İŞLEM 1: KART EKLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add' && $can_manage) {
    $bank = temizle($_POST['bank_name']);
    $holder = temizle($_POST['card_holder']);
    $number = str_replace(' ', '', $_POST['card_number']);
    $expiry = temizle($_POST['expiry_date']);
    $cvc = temizle($_POST['cvc']);
    $type = $_POST['card_type'];
    $color = $_POST['color_theme'];
    $ownership = $_POST['ownership'];
    $limit = !empty($_POST['total_limit']) ? (float)$_POST['total_limit'] : 0;
    $balance = !empty($_POST['available_balance']) ? (float)$_POST['available_balance'] : 0;

    if (!empty($bank) && !empty($number)) {
        $enc_number = encrypt_data($number);
        $enc_cvc = encrypt_data($cvc);

        $sql = "INSERT INTO company_cards (bank_name, card_holder, card_number_enc, expiry_date, cvc_enc, card_type, color_theme, ownership, total_limit, available_balance) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$bank, $holder, $enc_number, $expiry, $enc_cvc, $type, $color, $ownership, $limit, $balance]);
        
        $message = '<div class="alert alert-success alert-dismissible fade show">Kart başarıyla eklendi! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// --- İŞLEM 2: KART GÜNCELLEME (YENİ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit' && $can_manage) {
    $id = (int)$_POST['card_id'];
    $bank = temizle($_POST['bank_name']);
    $holder = temizle($_POST['card_holder']);
    $color = $_POST['color_theme'];
    $ownership = $_POST['ownership'];
    $limit = !empty($_POST['total_limit']) ? (float)$_POST['total_limit'] : 0;
    $balance = !empty($_POST['available_balance']) ? (float)$_POST['available_balance'] : 0;

    $sql = "UPDATE company_cards SET bank_name=?, card_holder=?, color_theme=?, ownership=?, total_limit=?, available_balance=? WHERE id=?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bank, $holder, $color, $ownership, $limit, $balance, $id]);

    $message = '<div class="alert alert-success alert-dismissible fade show">Kart bilgileri güncellendi! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// --- İŞLEM 3: KART SİLME ---
if (isset($_GET['delete']) && $can_manage) {
    $pdo->prepare("DELETE FROM company_cards WHERE id = ?")->execute([(int)$_GET['delete']]);
    header("Location: company-cards.php?msg=deleted"); exit;
}

// --- İŞLEM 4: BLOKE ETME / KALDIRMA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'toggle_block' && $can_manage) {
    $card_id = (int)$_POST['card_id'];
    $new_status = (int)$_POST['new_status']; 
    $reason = isset($_POST['block_reason']) ? temizle($_POST['block_reason']) : null;

    $stmt = $pdo->prepare("UPDATE company_cards SET is_blocked = ?, block_reason = ? WHERE id = ?");
    $stmt->execute([$new_status, $reason, $card_id]);
    header("Location: company-cards.php?msg=updated"); exit;
}

// --- ARAMA VE LİSTELEME ---
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$sql = "SELECT * FROM company_cards WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (bank_name LIKE ? OR card_holder LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY is_blocked ASC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

$company_cards = [];
$personal_cards = [];

foreach($all_cards as $c) {
    if($c['ownership'] == 'personal') $personal_cards[] = $c;
    else $company_cards[] = $c;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kart Yönetimi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .credit-card {
            width: 100%; max-width: 400px; height: 240px; border-radius: 15px; color: white; padding: 25px;
            position: relative; box-shadow: 0 10px 20px rgba(0,0,0,0.3); transition: transform 0.3s;
            margin-bottom: 20px; display: flex; flex-direction: column; justify-content: space-between;
            overflow: hidden; 
        }
        .credit-card:hover { transform: translateY(-5px); z-index: 10; }
        
        .card-dark { background: linear-gradient(135deg, #232526, #414345); }
        .card-blue { background: linear-gradient(135deg, #000428, #004e92); }
        .card-red { background: linear-gradient(135deg, #870000, #190a05); }
        .card-gold { background: linear-gradient(135deg, #BF953F, #FCF6BA, #B38728); color: #333; text-shadow: none; }
        .card-blocked { filter: grayscale(100%); opacity: 0.8; }

        .card-number { font-family: 'Courier New', Courier, monospace; font-size: 1.5rem; letter-spacing: 2px; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); cursor: pointer; }
        .card-label { font-size: 0.65rem; opacity: 0.8; text-transform: uppercase; margin-bottom: 2px; }
        .card-val { font-family: 'Courier New', Courier, monospace; font-size: 1rem; font-weight: bold; cursor: pointer; }
        .chip { width: 50px; height: 35px; background: linear-gradient(135deg, #d4af37, #f2d06b); border-radius: 5px; margin-bottom: 10px; position: relative; }
        
        .blocked-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); display: flex; flex-direction: column;
            align-items: center; justify-content: center; z-index: 20;
            text-align: center; color: #fff; padding: 20px;
        }

        .details-overlay {
            position: absolute; bottom: 0; left: 0; width: 100%;
            background: rgba(0,0,0,0.85); color: #fff; padding: 15px;
            transform: translateY(100%); transition: transform 0.3s ease-in-out;
            border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;
            z-index: 15;
        }
        .credit-card.has-details:hover .details-overlay { transform: translateY(0); }

        .clickable:active { opacity: 0.7; }
        .btn-action-group { position: absolute; top: 10px; right: 10px; z-index: 30; }
        .btn-card-action { color: rgba(255,255,255,0.6); margin-left: 8px; cursor: pointer; transition: color 0.2s; font-size: 1.1rem; }
        .btn-card-action:hover { color: #fff; }
        .btn-card-action.danger:hover { color: #ff4d4d; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container my-4">
        
        <div class="row align-items-center mb-4">
            <div class="col-md-4">
                <h2 class="mb-0"><i class="fa fa-wallet text-primary"></i> Kart Portföyü</h2>
            </div>
            <div class="col-md-4">
                <form method="GET" class="d-flex">
                    <input type="text" name="q" class="form-control" placeholder="Banka veya İsim Ara..." value="<?php echo guvenli_html($search); ?>">
                    <button type="submit" class="btn btn-outline-primary ms-1"><i class="fa fa-search"></i></button>
                    <?php if($search): ?><a href="company-cards.php" class="btn btn-outline-secondary ms-1"><i class="fa fa-times"></i></a><?php endif; ?>
                </form>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <?php if($can_manage): ?>
                    <button class="btn btn-primary shadow" data-bs-toggle="modal" data-bs-target="#addCardModal">
                        <i class="fa fa-plus-circle"></i> Yeni Kart Ekle
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $message; ?>

        <h5 class="text-secondary border-bottom pb-2 mb-3"><i class="fa fa-building"></i> Şirket Kartları</h5>
        <div class="row">
            <?php if(empty($company_cards)): ?>
                <div class="col-12 text-muted mb-4">Kayıtlı şirket kartı bulunamadı.</div>
            <?php else: ?>
                <?php foreach($company_cards as $card) { renderCard($card, $can_manage, $can_see_details); } ?>
            <?php endif; ?>
        </div>

        <h5 class="text-secondary border-bottom pb-2 mb-3 mt-4"><i class="fa fa-user-tie"></i> Şahsi / Personel Kartları</h5>
        <div class="row">
            <?php if(empty($personal_cards)): ?>
                <div class="col-12 text-muted">Kayıtlı şahsi kart bulunamadı.</div>
            <?php else: ?>
                <?php foreach($personal_cards as $card) { renderCard($card, $can_manage, $can_see_details); } ?>
            <?php endif; ?>
        </div>

    </div>

    <?php 
    function renderCard($card, $can_manage, $can_see_details) {
        $real_number = decrypt_data($card['card_number_enc']);
        $real_cvc = decrypt_data($card['cvc_enc']);
        $display_number = chunk_split($real_number, 4, ' ');
        
        $icon = 'fa-cc-visa';
        if($card['card_type'] == 'mastercard') $icon = 'fa-cc-mastercard';
        if($card['card_type'] == 'amex') $icon = 'fa-cc-amex';
        if($card['card_type'] == 'troy') $icon = 'fa-credit-card';

        $blocked_class = $card['is_blocked'] ? 'card-blocked' : '';
        $details_class = $can_see_details ? 'has-details' : ''; 
        ?>
        <div class="col-lg-4 col-md-6">
            <div class="credit-card <?php echo $card['color_theme'] . ' ' . $blocked_class . ' ' . $details_class; ?>">
                
                <?php if($can_manage): ?>
                    <div class="btn-action-group">
                        <i class="fa fa-edit btn-card-action" onclick='openEditModal(<?php echo json_encode($card); ?>)' title="Düzenle"></i>

                        <?php if($card['is_blocked']): ?>
                            <i class="fa fa-unlock btn-card-action" onclick="toggleBlock(<?php echo $card['id']; ?>, 0)" title="Blokeyi Kaldır"></i>
                        <?php else: ?>
                            <i class="fa fa-lock btn-card-action" onclick="openBlockModal(<?php echo $card['id']; ?>)" title="Kartı Bloke Et"></i>
                        <?php endif; ?>
                        
                        <a href="company-cards.php?delete=<?php echo $card['id']; ?>" class="btn-card-action danger" onclick="return confirm('Silmek istediğinize emin misiniz?');">
                            <i class="fa fa-trash"></i>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if($card['is_blocked']): ?>
                    <div class="blocked-overlay">
                        <i class="fa fa-lock fa-3x mb-2"></i>
                        <h5 class="fw-bold">KART BLOKE EDİLDİ</h5>
                        <p class="small fst-italic mb-0">"<?php echo guvenli_html($card['block_reason']); ?>"</p>
                    </div>
                <?php endif; ?>

                <?php if($can_see_details): ?>
                    <div class="details-overlay">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><i class="fa fa-tachometer-alt text-warning"></i> Toplam Limit:</span>
                            <span class="fw-bold"><?php echo number_format($card['total_limit'], 2, ',', '.'); ?> ₺</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="fa fa-check-circle text-success"></i> Kullanılabilir:</span>
                            <span class="fw-bold text-success"><?php echo number_format($card['available_balance'], 2, ',', '.'); ?> ₺</span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-start">
                    <div class="chip"></div>
                    <div class="text-end">
                        <i class="fab <?php echo $icon; ?> fa-2x"></i>
                        <div class="small fw-bold mt-1"><?php echo guvenli_html($card['bank_name']); ?></div>
                    </div>
                </div>

                <div class="position-relative text-center my-2">
                    <div class="card-number clickable" onclick="copyToClipboard('<?php echo $real_number; ?>', 'Kart No Kopyalandı!')" title="Kopyalamak için tıkla">
                        <?php echo $display_number; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-end mt-3">
                    <div>
                        <div class="card-label">KART SAHİBİ</div>
                        <div class="card-val"><?php echo guvenli_html($card['card_holder']); ?></div>
                    </div>
                    <div class="text-center">
                        <div class="card-label">SKT</div>
                        <div class="card-val clickable" onclick="copyToClipboard('<?php echo $card['expiry_date']; ?>', 'Tarih Kopyalandı!')">
                            <?php echo $card['expiry_date']; ?>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="card-label">CVC</div>
                        <div class="card-val clickable" onclick="copyToClipboard('<?php echo $real_cvc; ?>', 'CVC Kopyalandı!')">
                            <?php echo $real_cvc; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>

    <div class="modal fade" id="addCardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Yeni Kart Ekle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Kart Aidiyeti</label>
                                <select name="ownership" class="form-select">
                                    <option value="company">Şirket Kartı</option>
                                    <option value="personal">Şahsi / Personel</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Banka Adı</label>
                                <input type="text" name="bank_name" class="form-control" required placeholder="Örn: Garanti">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Kart Sahibi (Ad Soyad)</label>
                            <input type="text" name="card_holder" class="form-control" required style="text-transform: uppercase;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kart Numarası</label>
                            <input type="text" name="card_number" class="form-control font-monospace" required maxlength="19" placeholder="0000 0000 0000 0000">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Son Kul. (AA/YY)</label>
                                <input type="text" name="expiry_date" class="form-control text-center" required placeholder="01/28" maxlength="5">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">CVC Kodu</label>
                                <input type="text" name="cvc" class="form-control text-center" required maxlength="4">
                            </div>
                        </div>
                        
                        <?php if($can_see_details): ?>
                        <div class="row mb-3 bg-light p-2 rounded border">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Toplam Limit</label>
                                <input type="number" step="0.01" name="total_limit" class="form-control form-control-sm" placeholder="0.00">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-success">Kullanılabilir Bakiye</label>
                                <input type="number" step="0.01" name="available_balance" class="form-control form-control-sm" placeholder="0.00">
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Kart Tipi</label>
                                <select name="card_type" class="form-select">
                                    <option value="visa">Visa</option>
                                    <option value="mastercard">Mastercard</option>
                                    <option value="amex">American Express</option>
                                    <option value="troy">Troy</option>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Kart Rengi</label>
                                <select name="color_theme" class="form-select">
                                    <option value="card-dark">Siyah (Dark)</option>
                                    <option value="card-blue">Mavi (Kurumsal)</option>
                                    <option value="card-red">Kırmızı</option>
                                    <option value="card-gold">Altın (Gold)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editCardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="card_id" id="edit_card_id">
                    
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title text-dark"><i class="fa fa-edit"></i> Kart Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info small py-2">
                            <i class="fa fa-info-circle"></i> Kart numarası ve CVC güvenliği için buradan değiştirilemez. Değişiklik gerekirse kartı silip yeniden ekleyin.
                        </div>

                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Kart Aidiyeti</label>
                                <select name="ownership" id="edit_ownership" class="form-select">
                                    <option value="company">Şirket Kartı</option>
                                    <option value="personal">Şahsi / Personel</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Banka Adı</label>
                                <input type="text" name="bank_name" id="edit_bank_name" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Kart Sahibi</label>
                            <input type="text" name="card_holder" id="edit_card_holder" class="form-control" required style="text-transform: uppercase;">
                        </div>

                        <div class="row mb-3 bg-warning bg-opacity-10 p-2 rounded border border-warning">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Toplam Limit</label>
                                <input type="number" step="0.01" name="total_limit" id="edit_total_limit" class="form-control" placeholder="0.00">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-success">Kullanılabilir Bakiye</label>
                                <input type="number" step="0.01" name="available_balance" id="edit_available_balance" class="form-control" placeholder="0.00">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Kart Rengi</label>
                            <select name="color_theme" id="edit_color_theme" class="form-select">
                                <option value="card-dark">Siyah (Dark)</option>
                                <option value="card-blue">Mavi (Kurumsal)</option>
                                <option value="card-red">Kırmızı</option>
                                <option value="card-gold">Altın (Gold)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-warning text-dark fw-bold">Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="blockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="toggle_block">
                    <input type="hidden" name="new_status" value="1">
                    <input type="hidden" name="card_id" id="block_card_id">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fa fa-lock"></i> Kartı Bloke Et</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Bu kartı neden bloke ediyorsunuz? (Diğer kullanıcılar bu açıklamayı görecek)</p>
                        <textarea name="block_reason" class="form-control" rows="3" required placeholder="Örn: Proje X için ayrıldı, Bakiye yetersiz vb."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Bloke Et</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="unblockForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="toggle_block">
        <input type="hidden" name="new_status" value="0">
        <input type="hidden" name="card_id" id="unblock_card_id">
        <input type="hidden" name="block_reason" value="">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function copyToClipboard(text, msg) {
            navigator.clipboard.writeText(text).then(function() {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, timerProgressBar: true
                });
                Toast.fire({ icon: 'success', title: msg });
            }, function(err) {
                console.error('Kopyalama hatası: ', err);
            });
        }

        var blockModal = new bootstrap.Modal(document.getElementById('blockModal'));
        var editModal = new bootstrap.Modal(document.getElementById('editCardModal'));

        function openBlockModal(id) {
            document.getElementById('block_card_id').value = id;
            blockModal.show();
        }

        // DÜZENLEME MODALINI AÇMA
        function openEditModal(card) {
            document.getElementById('edit_card_id').value = card.id;
            document.getElementById('edit_bank_name').value = card.bank_name;
            document.getElementById('edit_card_holder').value = card.card_holder;
            document.getElementById('edit_total_limit').value = card.total_limit;
            document.getElementById('edit_available_balance').value = card.available_balance;
            document.getElementById('edit_ownership').value = card.ownership;
            document.getElementById('edit_color_theme').value = card.color_theme;
            
            editModal.show();
        }

        function toggleBlock(id, status) {
            if(status === 0) {
                Swal.fire({
                    title: 'Bloke kaldırılsın mı?',
                    text: "Kart tekrar kullanıma açılacak.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Evet, Kaldır'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('unblock_card_id').value = id;
                        document.getElementById('unblockForm').submit();
                    }
                });
            }
        }
    </script>
</body>
</html>