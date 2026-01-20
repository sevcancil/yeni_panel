<?php
// public/profile.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
require_once '../app/functions/alerts.php'; // Yeni fonksiyonumuzu çağıralım

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Kullanıcı Bilgilerini Çek
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Uyarıları Getir
$alerts = get_user_alerts($pdo, $user_id, $is_admin);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Profilim ve Uyarılar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .alert-card { transition: transform 0.2s; border-left: 5px solid #ddd; }
        .alert-card:hover { transform: translateX(5px); }
        .alert-card.type-warning { border-left-color: #ffc107; background-color: #fff3cd; }
        .alert-card.type-danger { border-left-color: #dc3545; background-color: #f8d7da; }
        .avatar-circle { width: 100px; height: 100px; background: #0d6efd; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3rem; margin: 0 auto; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container py-5">
        <div class="row">
            
            <div class="col-md-4">
                <div class="card shadow mb-4">
                    <div class="card-body text-center pt-5">
                        <div class="avatar-circle mb-3">
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        </div>
                        <h4 class="mb-0"><?php echo htmlspecialchars($user['name_surname'] ?? $user['username']); ?></h4>
                        <p class="text-muted"><?php echo htmlspecialchars($user['email']); ?></p>
                        <span class="badge bg-secondary"><?php echo strtoupper($user['role']); ?></span>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Kayıt Tarihi</span>
                            <span class="fw-bold"><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3><i class="fa fa-bell text-warning"></i> Bildirimler & Eksik Bilgiler</h3>
                    <span class="badge bg-danger rounded-pill fs-6"><?php echo count($alerts); ?></span>
                </div>

                <?php if (empty($alerts)): ?>
                    <div class="alert alert-success text-center p-5">
                        <i class="fa fa-check-circle fa-4x mb-3"></i>
                        <h4>Harika! Hiçbir eksiğiniz yok.</h4>
                        <p>Tüm kayıtlarınız kurallara uygun görünüyor.</p>
                    </div>
                <?php else: ?>
                    <div class="alert-list">
                        <?php foreach ($alerts as $alert): ?>
                            <div class="card mb-3 alert-card type-<?php echo $alert['type']; ?>">
                                <div class="card-body d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <div class="fs-2 me-3 text-<?php echo $alert['type']; ?>">
                                            <i class="fa <?php echo $alert['icon']; ?>"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1"><?php echo $alert['title']; ?></h6>
                                            <div class="small text-dark"><?php echo $alert['msg']; ?></div>
                                        </div>
                                    </div>
                                    <a href="<?php echo $alert['link']; ?>" class="btn btn-sm btn-outline-dark">
                                        <?php echo $alert['btn_text']; ?> <i class="fa fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">İşlem Düzenle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editModalBody"></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Modal Açma Fonksiyonu (payment-orders.php'deki ile aynı)
        function openEditModal(id) {
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            $('#editModalBody').html('<div class="text-center p-4"><div class="spinner-border text-primary"></div></div>');
            $('#editModalBody').load('transaction-edit.php?id=' + id);
        }
    </script>
</body>
</html>