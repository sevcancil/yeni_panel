<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = temizle($_POST['name']);
    $type = $_POST['type'];
    $account_number = temizle($_POST['account_number']);
    $initial_balance = (float)$_POST['initial_balance']; // Açılış bakiyesi

    if (empty($name)) {
        $message = '<div class="alert alert-danger">Hesap adı zorunludur!</div>';
    } else {
        $sql = "INSERT INTO payment_channels (name, type, account_number, current_balance) 
                VALUES (:name, :type, :account_number, :balance)";
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute([
                'name' => $name,
                'type' => $type,
                'account_number' => $account_number,
                'balance' => $initial_balance
            ]);
            $message = '<div class="alert alert-success">Hesap başarıyla eklendi!</div>';
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Hata: ' . $e->getMessage() . '</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yeni Kasa/Banka Ekle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Yeni Kasa veya Banka Hesabı</h5>
                    </div>
                    <div class="card-body">
                        
                        <?php echo $message; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Hesap Adı *</label>
                                <input type="text" name="name" class="form-control" placeholder="Örn: Ziraat Bankası" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Hesap Türü</label>
                                <select name="type" class="form-select">
                                    <option value="bank">Banka Hesabı</option>
                                    <option value="cash">Nakit Kasa</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">IBAN / Hesap No</label>
                                <input type="text" name="account_number" class="form-control">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Açılış Bakiyesi (TL)</label>
                                <input type="number" step="0.01" name="initial_balance" class="form-control" value="0.00">
                                <small class="text-muted">Hesapta şu an bulunan para.</small>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>