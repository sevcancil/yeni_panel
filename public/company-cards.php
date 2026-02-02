<?php
// public/company-cards.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Yetki: Herkes görebilir ama sadece Admin/Muhasebe ekleyip silebilir.
$can_manage = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'muhasebe');

$message = '';

// --- KART EKLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $bank = temizle($_POST['bank_name']);
    $holder = temizle($_POST['card_holder']);
    $number = str_replace(' ', '', $_POST['card_number']); // Boşlukları sil
    $expiry = temizle($_POST['expiry_date']);
    $cvc = temizle($_POST['cvc']);
    $type = $_POST['card_type'];
    $color = $_POST['color_theme'];

    if (!empty($bank) && !empty($number)) {
        // Şifrele
        $enc_number = encrypt_data($number);
        $enc_cvc = encrypt_data($cvc);

        $stmt = $pdo->prepare("INSERT INTO company_cards (bank_name, card_holder, card_number_enc, expiry_date, cvc_enc, card_type, color_theme) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$bank, $holder, $enc_number, $expiry, $enc_cvc, $type, $color]);
        
        $message = '<div class="alert alert-success">Kart başarıyla eklendi!</div>';
    }
}

// --- KART SİLME ---
if (isset($_GET['delete']) && $can_manage) {
    $pdo->prepare("DELETE FROM company_cards WHERE id = ?")->execute([(int)$_GET['delete']]);
    header("Location: company-cards.php"); exit;
}

// KARTLARI ÇEK
$cards = $pdo->query("SELECT * FROM company_cards ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Şirket Kartları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Kredi Kartı Görsel Tasarımı */
        .credit-card {
            width: 100%;
            max-width: 400px;
            height: 240px;
            border-radius: 15px;
            color: white;
            padding: 25px;
            position: relative;
            box-shadow: 0 10px 20px rgba(0,0,0,0.3);
            transition: transform 0.3s;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .credit-card:hover { transform: translateY(-5px); }
        
        /* Renk Temaları */
        .card-dark { background: linear-gradient(135deg, #232526, #414345); }
        .card-blue { background: linear-gradient(135deg, #000428, #004e92); }
        .card-red { background: linear-gradient(135deg, #870000, #190a05); }
        .card-gold { background: linear-gradient(135deg, #BF953F, #FCF6BA, #B38728); color: #333; text-shadow: none; }
        
        .card-number { font-family: 'Courier New', Courier, monospace; font-size: 1.6rem; letter-spacing: 2px; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); cursor: pointer; }
        .card-label { font-size: 0.7rem; opacity: 0.8; text-transform: uppercase; }
        .card-val { font-family: 'Courier New', Courier, monospace; font-size: 1.1rem; font-weight: bold; cursor: pointer; }
        .chip { width: 50px; height: 35px; background: linear-gradient(135deg, #d4af37, #f2d06b); border-radius: 5px; margin-bottom: 15px; position: relative; overflow: hidden; }
        .chip::after { content:''; position:absolute; top:0; left:0; right:0; bottom:0; border: 1px solid rgba(0,0,0,0.2); border-radius: 5px; }
        
        .copy-hint { position: absolute; top: -30px; left: 50%; transform: translateX(-50%); background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 0.8rem; display: none; white-space: nowrap; }
        .clickable:active + .copy-hint { display: block; }
        
        .delete-btn { position: absolute; top: 10px; right: 10px; color: rgba(255,255,255,0.5); cursor: pointer; z-index: 10; }
        .delete-btn:hover { color: #ff4d4d; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fa fa-credit-card text-primary"></i> Şirket Kredi Kartları</h2>
            <?php if($can_manage): ?>
                <button class="btn btn-primary shadow" data-bs-toggle="modal" data-bs-target="#addCardModal">
                    <i class="fa fa-plus"></i> Yeni Kart Ekle
                </button>
            <?php endif; ?>
        </div>

        <?php echo $message; ?>

        <div class="row">
            <?php if(empty($cards)): ?>
                <div class="col-12 text-center text-muted py-5">
                    <h4><i class="fa fa-folder-open display-4"></i></h4>
                    <p>Henüz eklenmiş bir kart yok.</p>
                </div>
            <?php else: ?>
                <?php foreach($cards as $card): 
                    // Verileri Çöz
                    $real_number = decrypt_data($card['card_number_enc']);
                    $real_cvc = decrypt_data($card['cvc_enc']);
                    // Görsel için formatla (**** **** **** 1234)
                    $display_number = chunk_split($real_number, 4, ' ');
                    
                    $icon = 'fa-cc-visa';
                    if($card['card_type'] == 'mastercard') $icon = 'fa-cc-mastercard';
                    if($card['card_type'] == 'amex') $icon = 'fa-cc-amex';
                ?>
                <div class="col-lg-4 col-md-6">
                    <div class="credit-card <?php echo $card['color_theme']; ?>">
                        <?php if($can_manage): ?>
                            <a href="company-cards.php?delete=<?php echo $card['id']; ?>" class="delete-btn" onclick="return confirm('Bu kartı silmek istediğinize emin misiniz?');"><i class="fa fa-trash"></i></a>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="chip"></div>
                            <div class="text-end">
                                <i class="fab <?php echo $icon; ?> fa-2x"></i>
                                <div class="small fw-bold mt-1"><?php echo guvenli_html($card['bank_name']); ?></div>
                            </div>
                        </div>

                        <div class="position-relative text-center my-2">
                            <div class="card-number clickable" onclick="copyToClipboard('<?php echo $real_number; ?>', 'Kart No Kopyalandı!')">
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
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="addCardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Yeni Kart Ekle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Banka Adı</label>
                            <input type="text" name="bank_name" class="form-control" required placeholder="Örn: Garanti BBVA">
                        </div>
                        <div class="mb-3">
                            <label>Kart Sahibi (Ad Soyad)</label>
                            <input type="text" name="card_holder" class="form-control" required style="text-transform: uppercase;">
                        </div>
                        <div class="mb-3">
                            <label>Kart Numarası</label>
                            <input type="text" name="card_number" class="form-control font-monospace" required maxlength="19" placeholder="0000 0000 0000 0000">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label>Son Kul. (AA/YY)</label>
                                <input type="text" name="expiry_date" class="form-control text-center" required placeholder="01/28" maxlength="5">
                            </div>
                            <div class="col-6 mb-3">
                                <label>CVC Kodu</label>
                                <input type="text" name="cvc" class="form-control text-center" required maxlength="4">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label>Kart Tipi</label>
                                <select name="card_type" class="form-select">
                                    <option value="visa">Visa</option>
                                    <option value="mastercard">Mastercard</option>
                                    <option value="amex">American Express</option>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label>Kart Rengi</label>
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
                        <button type="submit" class="btn btn-primary">Kaydet ve Şifrele</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
    </script>
</body>
</html>