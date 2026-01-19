<?php
// public/login.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = temizle($_POST['username']);
    $password = $_POST['password']; 

    if (empty($username) || empty($password)) {
        $error = "Lütfen kullanıcı adı ve şifreyi giriniz.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1");
        $stmt->execute(['username' => $username, 'email' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // SESSION OLUŞTURMA
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role']; // admin veya staff

            // YETKİLERİ YÜKLE (Kritik Nokta)
            // JSON verisini diziye çevirip session'a atıyoruz.
            $perms = json_decode($user['permissions'], true);
            $_SESSION['permissions'] = is_array($perms) ? $perms : [];

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
    <title>Giriş Yap</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 400px; padding: 2rem; border-radius: 10px; background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="login-card">
        <h3 class="text-center mb-4 fw-bold">Panel Girişi</h3>
        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label>Kullanıcı Adı</label>
                <input type="text" class="form-control" name="username" required autofocus>
            </div>
            <div class="mb-3">
                <label>Şifre</label>
                <input type="password" class="form-control" name="password" required>
            </div>
            <div class="d-grid"><button type="submit" class="btn btn-primary btn-lg">Giriş Yap</button></div>
        </form>
        <div class="text-center mt-3 small text-muted">© 2024 Finans Paneli</div>
    </div>
</body>
</html>