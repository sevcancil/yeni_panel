<?php
// public/users.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Sadece Adminler ve İK Girebilir
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'ik')) {
    die('<div class="alert alert-danger m-5">Yetkisiz Erişim.</div>');
}

$message = '';

// HIZLI KULLANICI EKLEME (Temel Bilgiler)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = temizle($_POST['username']);
    $email = temizle($_POST['email']);
    $password = $_POST['password'];
    $full_name = temizle($_POST['full_name']);
    $role = $_POST['role'];
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $password_hash, $full_name, $role]);
        $message = '<div class="alert alert-success">Kullanıcı oluşturuldu! Detayları düzenlemek için listeyi kullanın.</div>';
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Hata: Kullanıcı adı veya E-posta zaten kullanılıyor.</div>';
    }
}

// Kullanıcıları ve Departmanlarını Çek
$users = $pdo->query("SELECT u.*, d.name as department_name 
                      FROM users u 
                      LEFT JOIN departments d ON u.department_id = d.id 
                      ORDER BY u.id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Personel Listesi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        
        <div class="row">
            <div class="col-md-3">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white fw-bold"><i class="fa fa-user-plus"></i> Hızlı Ekle</div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <form method="POST">
                            <div class="mb-2"><label>Ad Soyad</label><input type="text" name="full_name" class="form-control" required></div>
                            <div class="mb-2"><label>Kullanıcı Adı</label><input type="text" name="username" class="form-control" required></div>
                            <div class="mb-2"><label>E-posta</label><input type="email" name="email" class="form-control" required></div>
                            <div class="mb-2"><label>Şifre</label><input type="password" name="password" class="form-control" required></div>
                            <div class="mb-3">
                                <label>Rol</label>
                                <select name="role" class="form-select">
                                    <option value="staff">Personel</option>
                                    <option value="ik">İnsan Kaynakları</option>
                                    <option value="muhasebe">Muhasebe</option>
                                    <option value="admin">Yönetici (Admin)</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-9">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-users"></i> Personel Listesi</span>
                        <span class="badge bg-secondary"><?php echo count($users); ?> Kişi</span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Personel</th>
                                    <th>Departman</th>
                                    <th>Rol</th>
                                    <th class="text-end">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $u): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-2 fw-bold border" style="width: 40px; height: 40px;">
                                                    <?php echo strtoupper(substr($u['username'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?php echo guvenli_html($u['full_name']); ?></div>
                                                    <small class="text-muted"><?php echo guvenli_html($u['email']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo !empty($u['department_name']) ? '<span class="badge bg-secondary">'.$u['department_name'].'</span>' : '<span class="text-muted small">-</span>'; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $role_colors = ['admin'=>'danger', 'ik'=>'warning', 'muhasebe'=>'success', 'staff'=>'info'];
                                                $color = $role_colors[$u['role']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $color; ?> text-uppercase"><?php echo $u['role']; ?></span>
                                        </td>
                                        <td class="text-end">
                                            <a href="user-details.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fa fa-user-edit"></i> Detay & Düzenle
                                            </a>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>