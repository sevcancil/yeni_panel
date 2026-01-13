<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Bu sayfaya sadece "admin" girebilir!
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Bu sayfaya sadece Yöneticiler girebilir.");
}

$message = '';

// KULLANICI EKLEME / GÜNCELLEME İŞLEMİ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = temizle($_POST['username']);
    $email = temizle($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role']; // admin veya staff
    
    // Formdan gelen yetkileri JSON yap
    // Eğer admin seçildiyse yetkiler ["all"] olur, değilse seçilenler.
    $perms = isset($_POST['perms']) ? json_encode($_POST['perms']) : '[]';
    if ($role === 'admin') { $perms = '["all"]'; }

    // Şifre boş değilse hashle (Güncelleme için boş bırakılabilir mantığı eklemiyoruz, direkt ekleme yapıyoruz şimdilik)
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, permissions) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $password_hash, $role, $perms]);
        $message = '<div class="alert alert-success">Kullanıcı başarıyla oluşturuldu!</div>';
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Hata: Kullanıcı adı veya E-posta zaten var.</div>';
    }
}

// Kullanıcıları Listele
$users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kullanıcı Yönetimi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Yeni Kullanıcı Oluştur</h5>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label>Kullanıcı Adı</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>E-posta</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Şifre</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Rol</label>
                                <select name="role" class="form-select" id="roleSelect" onchange="togglePerms()">
                                    <option value="staff">Personel (Kısıtlı Yetki)</option>
                                    <option value="admin">Yönetici (Tam Yetki)</option>
                                </select>
                            </div>

                            <div class="mb-3" id="permList">
                                <label class="fw-bold mb-2">Personel Yetkileri:</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="perms[]" value="view_dashboard" checked>
                                    <label class="form-check-label">Dashboard'u Görebilir</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="perms[]" value="view_finance">
                                    <label class="form-check-label">Kasa/Banka Bakiyelerini Görebilir</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="perms[]" value="manage_invoices">
                                    <label class="form-check-label">Fatura Kesebilir / İşlem Ekleyebilir</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="perms[]" value="delete_data">
                                    <label class="form-check-label text-danger">Kayıt Silebilir (Tehlikeli)</label>
                                </div>
                                 <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="perms[]" value="approve_payment">
                                    <label class="form-check-label text-primary">Ödeme Onaylayabilir</label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Kullanıcıyı Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header">Kullanıcılar</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Kullanıcı</th>
                                    <th>Rol</th>
                                    <th>Yetkiler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $u): ?>
                                    <tr>
                                        <td>
                                            <?php echo guvenli_html($u['username']); ?><br>
                                            <small class="text-muted"><?php echo guvenli_html($u['email']); ?></small>
                                        </td>
                                        <td>
                                            <?php if($u['role']=='admin'): ?>
                                                <span class="badge bg-danger">Yönetici</span>
                                            <?php else: ?>
                                                <span class="badge bg-info text-dark">Personel</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $p = json_decode($u['permissions'], true);
                                                if(is_array($p)) {
                                                    foreach($p as $perm) {
                                                        echo "<span class='badge bg-secondary me-1'>$perm</span>";
                                                    }
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePerms() {
            var role = document.getElementById('roleSelect').value;
            var list = document.getElementById('permList');
            if(role === 'admin') {
                list.style.display = 'none'; // Admin ise yetki seçmeye gerek yok
            } else {
                list.style.display = 'block';
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>