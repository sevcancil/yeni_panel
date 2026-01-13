<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

// Mevcut veriyi çek
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$stmt->execute(['id' => $id]);
$customer = $stmt->fetch();

if (!$customer) {
    header("Location: customers.php");
    exit;
}

// Form Gönderildi mi?
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = temizle($_POST['company_name']);
    $contact_name = temizle($_POST['contact_name']);
    $phone = temizle($_POST['phone']);
    $email = temizle($_POST['email']);
    $address = temizle($_POST['address']);
    $tax_office = temizle($_POST['tax_office']);
    $tax_number = temizle($_POST['tax_number']);

    if (empty($company_name)) {
        $message = '<div class="alert alert-danger">Firma adı boş olamaz!</div>';
    } else {
        $sql = "UPDATE customers SET 
                company_name = :company_name,
                contact_name = :contact_name,
                phone = :phone,
                email = :email,
                address = :address,
                tax_office = :tax_office,
                tax_number = :tax_number
                WHERE id = :id";
        
        $updateStmt = $pdo->prepare($sql);
        try {
            $updateStmt->execute([
                'company_name' => $company_name,
                'contact_name' => $contact_name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'tax_office' => $tax_office,
                'tax_number' => $tax_number,
                'id' => $id
            ]);
            // Güncel veriyi tekrar çekelim ki formda eski veri kalmasın
            $stmt->execute(['id' => $id]);
            $customer = $stmt->fetch();
            
            $message = '<div class="alert alert-success">Bilgiler güncellendi!</div>';
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
    <title>Düzenle: <?php echo guvenli_html($customer['company_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Müşteri Düzenle</h5>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Firma Ünvanı *</label>
                                <input type="text" name="company_name" class="form-control" value="<?php echo guvenli_html($customer['company_name']); ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Yetkili</label>
                                    <input type="text" name="contact_name" class="form-control" value="<?php echo guvenli_html($customer['contact_name']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Telefon</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo guvenli_html($customer['phone']); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Vergi Dairesi</label>
                                    <input type="text" name="tax_office" class="form-control" value="<?php echo guvenli_html($customer['tax_office']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Vergi No / TC</label>
                                    <input type="text" name="tax_number" class="form-control" value="<?php echo guvenli_html($customer['tax_number']); ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label>E-posta</label>
                                <input type="email" name="email" class="form-control" value="<?php echo guvenli_html($customer['email']); ?>">
                            </div>
                            <div class="mb-3">
                                <label>Adres</label>
                                <textarea name="address" class="form-control" rows="3"><?php echo guvenli_html($customer['address']); ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-warning w-100">Güncelle</button>
                            <a href="customers.php" class="btn btn-light w-100 mt-2">İptal</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>