<?php
// public/users.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Sadece Adminler Girebilir
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('<div class="alert alert-danger m-5">Yetkisiz Erişim.</div>');
}

$message = '';

// KULLANICI EKLEME
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = temizle($_POST['username']);
    $email = temizle($_POST['email']);
    $password = $_POST['password'];
    $full_name = temizle($_POST['full_name']); // Ad Soyad eklendi
    $role = $_POST['role'];
    
    // Yetkileri JSON yap
    $perms = isset($_POST['perms']) ? json_encode($_POST['perms']) : '[]';
    if ($role === 'admin') { $perms = '["all"]'; } // Admin tam yetkilidir

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, permissions) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $password_hash, $full_name, $role, $perms]);
        $message = '<div class="alert alert-success">Kullanıcı oluşturuldu!</div>';
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Hata: Kullanıcı adı veya E-posta zaten kullanılıyor.</div>';
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();

// PROJE YETKİLERİ (Burası önemli, kodlarda kullandığımız yetkilerle aynı olmalı)
$perm_list = [
    'manage_finance' => 'Finans Yönetimi (Ödeme/Tahsilat Gir)',
    'approve_payment' => 'Ödeme Onaylama',
    'view_reports' => 'Raporları Görüntüleme',
    'delete_data' => 'Kayıt Silme (Dikkat)'
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kullanıcı Yönetimi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white fw-bold">Yeni Kullanıcı</div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <form method="POST">
                            <div class="mb-2"><label>Ad Soyad</label><input type="text" name="full_name" class="form-control" required></div>
                            <div class="mb-2"><label>Kullanıcı Adı</label><input type="text" name="username" class="form-control" required></div>
                            <div class="mb-2"><label>E-posta</label><input type="email" name="email" class="form-control" required></div>
                            <div class="mb-2"><label>Şifre</label><input type="password" name="password" class="form-control" required></div>
                            
                            <div class="mb-3">
                                <label>Rol</label>
                                <select name="role" class="form-select" id="roleSelect" onchange="togglePerms()">
                                    <option value="staff">Personel</option>
                                    <option value="admin">Yönetici (Admin)</option>
                                </select>
                            </div>

                            <div class="mb-3 bg-light p-2 border rounded" id="permList">
                                <label class="fw-bold mb-2 small text-uppercase text-muted">Personel Yetkileri:</label>
                                <?php foreach($perm_list as $key => $label): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="perms[]" value="<?php echo $key; ?>" id="p_<?php echo $key; ?>">
                                        <label class="form-check-label small" for="p_<?php echo $key; ?>"><?php echo $label; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header fw-bold">Mevcut Kullanıcılar</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Kullanıcı</th><th>Rol</th><th>Yetkiler</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $u): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo guvenli_html($u['full_name']); ?></div>
                                            <small class="text-muted"><?php echo guvenli_html($u['username']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo ($u['role']=='admin') ? '<span class="badge bg-danger">Admin</span>' : '<span class="badge bg-info text-dark">Personel</span>'; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $p = json_decode($u['permissions'], true);
                                                if($u['role'] == 'admin') echo '<small class="text-muted">Tam Yetki</small>';
                                                elseif(is_array($p)) {
                                                    foreach($p as $perm) {
                                                        // Yetki kodunu (approve_payment) okunabilir hale getiriyoruz
                                                        $label = $perm_list[$perm] ?? $perm;
                                                        echo "<span class='badge bg-secondary me-1 mb-1'>$label</span>";
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
            // Admin seçilirse yetki listesini gizle (Kafa karışmasın, zaten yetkisi var)
            list.style.display = (role === 'admin') ? 'none' : 'block';
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>