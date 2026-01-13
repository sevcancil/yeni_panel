<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
permission_check('approve_payment');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

// İşlemi Çek
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND payment_status = 'unpaid'");
$stmt->execute([$id]);
$transaction = $stmt->fetch();

if (!$transaction) {
    header("Location: pending.php");
    exit;
}

$channels = $pdo->query("SELECT id, name, current_balance FROM payment_channels ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel_id = $_POST['payment_channel_id'];
    $pay_date = $_POST['date'];

    if (empty($channel_id) || empty($pay_date)) {
        $message = '<div class="alert alert-danger">Kasa ve Tarih seçmelisiniz!</div>';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. İşlemi Güncelle (Ödendi yap)
            $sql = "UPDATE transactions SET payment_status = 'paid', payment_channel_id = :pcid WHERE id = :id";
            $pdo->prepare($sql)->execute(['pcid' => $channel_id, 'id' => $id]);

            // 2. KASA GÜNCELLEME
            if ($transaction['type'] == 'debt') {
                // Satış faturasıydı (Alacak), Tahsilat yaptık -> Kasa Artar
                $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance + ? WHERE id = ?")->execute([$transaction['amount'], $channel_id]);
            } else {
                // Gider faturasıydı (Borç), Ödeme yaptık -> Kasa Azalır
                $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance - ? WHERE id = ?")->execute([$transaction['amount'], $channel_id]);
            }

            // 3. (YENİ) CARİ BAKİYE GÜNCELLEME (Borcu Kapat)
            if ($transaction['type'] == 'debt') {
                // Satış faturasıydı (Müşteri Borçluydu), ödedi -> Cari Düşmeli (-)
                $pdo->prepare("UPDATE customers SET current_balance = current_balance - ? WHERE id = ?")->execute([$transaction['amount'], $transaction['customer_id']]);
            } else {
                // Gider faturasıydı (Biz Borçluyduk -5000), ödedik -> Cari Artmalı (+5000) ki 0 olsun.
                $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?")->execute([$transaction['amount'], $transaction['customer_id']]);
            }

            $pdo->commit();
            header("Location: pending.php?msg=success");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">Hata: ' . $e->getMessage() . '</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ödeme Tamamla</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">İşlemi Tamamla / Kapat</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <b>İşlem Tutarı:</b> <?php echo number_format($transaction['amount'], 2); ?> ₺<br>
                            <?php echo $transaction['type'] == 'debt' ? 'Bu tutar tahsil edilecek (Kasa Artacak, Cari Düşecek).' : 'Bu tutar ödenecek (Kasa Azalacak, Cari Borç Kapanacak).'; ?>
                        </div>
                        <?php echo $message; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Hangi Kasadan/Bankadan?</label>
                                <select name="payment_channel_id" class="form-select" required>
                                    <?php foreach($channels as $ch) echo "<option value='{$ch['id']}'>{$ch['name']} ({$ch['current_balance']} ₺)</option>"; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tarih</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <button type="submit" class="btn btn-success w-100">Onayla</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>