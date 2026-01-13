<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Kurları Güncelle (Sayfa her açıldığında kontrol eder - Performans için cron job daha iyidir ama şimdilik böyle olsun)
updateExchangeRates();

$message = '';
$customers = $pdo->query("SELECT id, company_name FROM customers ORDER BY company_name ASC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$currencies = $pdo->query("SELECT * FROM currencies")->fetchAll();
$projects = $pdo->query("SELECT id, code, name FROM tour_codes WHERE status='active' ORDER BY start_date DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verileri Al
    $customer_id = $_POST['customer_id'];
    $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
    $tour_code_id = !empty($_POST['tour_code_id']) ? $_POST['tour_code_id'] : null;
    $type = $_POST['type']; 
    $doc_type = $_POST['doc_type']; // Fatura Emri / Ödeme Emri
    
    // Döviz Hesaplamaları
    $currency = $_POST['currency'];
    $original_amount = (float)$_POST['amount']; // Girilen dövizli tutar
    $exchange_rate = getExchangeRate($currency);
    $tl_amount = $original_amount * $exchange_rate; // TL Karşılığı

    $date = $_POST['date'];
    $payment_status = 'unpaid'; // Patron onaylayana kadar ödenmez, bekler.
    $description = temizle($_POST['description']);
    $invoice_no = temizle($_POST['invoice_no']);

    if (empty($customer_id) || empty($original_amount) || empty($date)) {
        $message = '<div class="alert alert-danger">Zorunlu alanları doldurun!</div>';
    } else {
        try {
            $pdo->beginTransaction();

            $db_type = ($type == 'sales_invoice') ? 'debt' : 'credit'; // Gelir / Gider

            $sql = "INSERT INTO transactions (customer_id, department_id, tour_code_id, type, doc_type, currency, exchange_rate, original_amount, amount, description, date, payment_status, invoice_no) 
                    VALUES (:cid, :did, :tcid, :type, :dtype, :curr, :rate, :orig_amt, :tl_amt, :desc, :date, :pstatus, :inv)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'cid' => $customer_id, 'did' => $department_id, 'tcid' => $tour_code_id,
                'type' => $db_type, 'dtype' => $doc_type,
                'curr' => $currency, 'rate' => $exchange_rate, 'orig_amt' => $original_amount, 'tl_amt' => $tl_amount,
                'desc' => $description, 'date' => $date, 'pstatus' => $payment_status, 'inv' => $invoice_no
            ]);

            // Cari Bakiye Güncelle (TL Olarak)
            if ($db_type == 'debt') {
                $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?")->execute([$tl_amount, $customer_id]);
            } else {
                $pdo->prepare("UPDATE customers SET current_balance = current_balance - ? WHERE id = ?")->execute([$tl_amount, $customer_id]);
            }

            $pdo->commit();
            $message = '<div class="alert alert-success">İşlem başarıyla eklendi! Kur: '.$exchange_rate.'</div>';
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
    <title>Fatura / Talimat Girişi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-9">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Fatura / Ödeme Emri Oluştur</h5>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <form method="POST">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Belge Tipi</label>
                                    <select name="doc_type" class="form-select">
                                        <option value="invoice_order">Fatura Emri</option>
                                        <option value="payment_order">Ödeme Emri</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">İşlem Yönü</label>
                                    <select name="type" class="form-select">
                                        <option value="sales_invoice">Gelir (Satış)</option>
                                        <option value="purchase_invoice" selected>Gider (Alış/Harcama)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Firma / Cari</label>
                                    <select name="customer_id" class="form-select" required>
                                        <option value="">Seçiniz...</option>
                                        <?php foreach($customers as $c) echo "<option value='{$c['id']}'>{$c['company_name']}</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Talep Eden Bölüm</label>
                                    <select name="department_id" class="form-select">
                                        <option value="">Seçiniz...</option>
                                        <?php foreach($departments as $d) echo "<option value='{$d['id']}'>{$d['name']}</option>"; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">Para Birimi</label>
                                    <select name="currency" class="form-select">
                                        <?php foreach($currencies as $curr): ?>
                                            <option value="<?php echo $curr['code']; ?>">
                                                <?php echo $curr['code']; ?> (Kur: <?php echo $curr['rate']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Tutar</label>
                                    <input type="number" step="0.01" name="amount" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Ödeme Tarihi</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Proje / Tur Kodu</label>
                                    <select name="tour_code_id" class="form-select">
                                        <option value="">Genel</option>
                                        <?php foreach($projects as $p) echo "<option value='{$p['id']}'>{$p['code']} - {$p['name']}</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Fatura No</label>
                                    <input type="text" name="invoice_no" class="form-control">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Açıklama</label>
                                <input type="text" name="description" class="form-control">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Kaydet ve Onaya Gönder</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>