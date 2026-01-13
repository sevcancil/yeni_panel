<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Eğer kullanıcı zaten giriş yapmışsa direkt panele at
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Form gönderildi mi?
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verileri temizle
    $username = temizle($_POST['username']);
    $password = $_POST['password']; 

    if (empty($username) || empty($password)) {
        $error = "Lütfen kullanıcı adı ve şifreyi giriniz.";
    } else {
        // DÜZELTME BURADA YAPILDI:
        // Parametre isimlerini :username ve :email olarak ayırdık.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1");
        
        // execute içine her iki parametre için de aynı girilen değeri gönderiyoruz.
        $stmt->execute([
            'username' => $username,
            'email'    => $username
        ]);
        
        $user = $stmt->fetch();

        // Kullanıcı var mı ve Şifre doğru mu?
        if ($user && password_verify($password, $user['password'])) {
            // Giriş Başarılı! Oturum verilerini kaydet
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            // Panele yönlendir
            header("Location: index.php");
            exit;
        } else {
            $error = "Hatalı kullanıcı adı veya şifre!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Yeni Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            background: white;
        }
        .login-title {
            text-align: center;
            margin-bottom: 2rem;
            color: #333;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <h3 class="login-title">Panel Girişi</h3>
        
        <?php if($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label">Kullanıcı Adı veya E-posta</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Şifre</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">Giriş Yap</button>
            </div>
        </form>
        <div class="text-center mt-3">
            <small class="text-muted">© 2024 Yeni Muhasebe</small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>