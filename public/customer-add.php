<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$message = '';

// Form Gönderildi mi?
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verileri al ve temizle
    $company_name = temizle($_POST['company_name']);
    $contact_name = temizle($_POST['contact_name']);
    $tax_office = temizle($_POST['tax_office']);
    $tax_number = temizle($_POST['tax_number']);
    $phone = temizle($_POST['phone']);
    $email = temizle($_POST['email']);
    $address = temizle($_POST['address']);

    if (empty($company_name)) {
        $message = '<div class="alert alert-danger">Firma Ünvanı zorunludur!</div>';
    } else {
        // Veritabanına Ekle
        $sql = "INSERT INTO customers (company_name, contact_name, tax_office, tax_number, phone, email, address) 
                VALUES (:company_name, :contact_name, :tax_office, :tax_number, :phone, :email, :address)";
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute([
                'company_name' => $company_name,
                'contact_name' => $contact_name,
                'tax_office' => $tax_office,
                'tax_number' => $tax_number,
                'phone' => $phone,
                'email' => $email,
                'address' => $address
            ]);
            $message = '<div class="alert alert-success">Cari hesap başarıyla oluşturuldu!</div>';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Cari Ekle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Yeni Cari Kart Ekle</h5>
                        <a href="customers.php" class="btn btn-sm btn-light">Listeye Dön</a>
                    </div>
                    <div class="card-body">
                        
                        <?php echo $message; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Firma Ünvanı / Ad Soyad *</label>
                                <input type="text" name="company_name" class="form-control" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Yetkili Kişi</label>
                                    <input type="text" name="contact_name" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Telefon</label>
                                    <input type="text" name="phone" class="form-control">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vergi Dairesi</label>
                                    <input type="text" name="tax_office" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vergi No / TC</label>
                                    <input type="text" name="tax_number" class="form-control">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">E-posta</label>
                                <input type="email" name="email" class="form-control">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Adres</label>
                                <textarea name="address" class="form-control" rows="3"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>