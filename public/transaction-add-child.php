<?php
// public/transaction-add-child.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

// Ana işlemi çek (Bilgileri görmek için)
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$parent_id]);
$parent = $stmt->fetch();

if (!$parent) die("Ana işlem bulunamadı.");

// Tahsilat/Ödeme Kanallarını Çek
$channels = $pdo->query("SELECT * FROM collection_channels ORDER BY title")->fetchAll(); // Kasa/Banka listesi

// --- KAYIT İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $amount = (float)$_POST['amount'];
    $description = temizle($_POST['description']);
    $channel_id = (int)$_POST['channel_id'];

    // Ana işlem GİDER ise bu bir 'payment_out', GELİR ise 'payment_in'
    $type = ($parent['type'] == 'debt') ? 'payment_out' : 'payment_in'; 
    
    // Alt işlem kaydı
    $sql = "INSERT INTO transactions (parent_id, type, date, amount, description, payment_channel_id, customer_id, tour_code_id, department_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent_id, $type, $date, $amount, $description, $channel_id, $parent['customer_id'], $parent['tour_code_id'], $parent['department_id']]);

    // Ana işlemin durumunu güncelle (Tamamı ödendiyse 'paid' yap)
    // Şimdilik basitçe 'paid' yapıyoruz, ileride kalan tutar kontrolü ekleriz.
    $pdo->prepare("UPDATE transactions SET payment_status = 'paid' WHERE id = ?")->execute([$parent_id]);

    header("Location: payment-orders.php?msg=paid");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ödeme/Tahsilat Gir</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow" style="max-width: 600px; margin: auto;">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">İşlem Gerçekleştir</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong>İlgili Kayıt:</strong> <?php echo number_format($parent['amount'], 2); ?> TL <br>
                    <?php echo $parent['description']; ?>
                </div>

                <form method="POST">
                    <div class="mb-3">
                        <label>Tarih</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>Ödenen/Alınan Tutar</label>
                        <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo $parent['amount']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>Kasa / Banka Seçimi</label>
                        <select name="channel_id" class="form-select" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach($channels as $ch): ?>
                                <option value="<?php echo $ch['id']; ?>"><?php echo $ch['title']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Açıklama</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-success w-100">Kaydet ve Tamamla</button>
                    <a href="payment-orders.php" class="btn btn-link w-100 mt-2">İptal</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>