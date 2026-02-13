<?php
// public/profile.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
require_once '../app/functions/alerts.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Kullanıcı Bilgilerini Çek (employee_details ile JOIN yaparak)
$stmt = $pdo->prepare("
    SELECT u.*, d.name as dept_name, ed.*,
    (ed.total_leave_days - ed.used_leave_days) as remaining_leave
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN employee_details ed ON u.id = ed.user_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Uyarıları Getir
$alerts = get_user_alerts($pdo, $user_id, $is_admin);

// --- KİŞİSEL GÜNCELLEME İŞLEMİ (POST) ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_self') {
    $phone = temizle($_POST['phone']);
    $addr = temizle($_POST['address']);
    $emer_name = temizle($_POST['emer_name']);
    $emer_phone = temizle($_POST['emer_phone']);
    $iban = temizle($_POST['iban']);
    
    // Şifre Değişimi
    $pass_sql = "";
    $params = [$phone];
    if (!empty($_POST['new_password'])) {
        $pass_sql = ", password = ?";
        $params[] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    }
    $params[] = $user_id;

    // Users tablosunu güncelle
    $pdo->prepare("UPDATE users SET phone=? $pass_sql WHERE id=?")->execute($params);

    // Employee Details tablosunu güncelle (UPSERT)
    $check = $pdo->prepare("SELECT id FROM employee_details WHERE user_id = ?");
    $check->execute([$user_id]);
    
    if ($check->rowCount() > 0) {
        $sql = "UPDATE employee_details SET address=?, emergency_contact_name=?, emergency_contact_phone=?, iban=? WHERE user_id=?";
        $pdo->prepare($sql)->execute([$addr, $emer_name, $emer_phone, $iban, $user_id]);
    } else {
        $sql = "INSERT INTO employee_details (user_id, address, emergency_contact_name, emergency_contact_phone, iban) VALUES (?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$user_id, $addr, $emer_name, $emer_phone, $iban]);
    }
    
    $msg = '<div class="alert alert-success alert-dismissible fade show">Profil bilgileriniz güncellendi. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    // Verileri tazele
    header("Refresh:2"); 
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Profilim</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .alert-card { transition: transform 0.2s; border-left: 5px solid #ddd; }
        .alert-card:hover { transform: translateX(5px); }
        .alert-card.type-warning { border-left-color: #ffc107; background-color: #fff3cd; }
        .alert-card.type-danger { border-left-color: #dc3545; background-color: #f8d7da; }
        .alert-card.type-info { border-left-color: #0dcaf0; background-color: #cff4fc; }
        
        .avatar-circle { width: 90px; height: 90px; background: #0d6efd; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto; }
        
        .nav-tabs .nav-link { color: #555; }
        .nav-tabs .nav-link.active { color: #000; font-weight: bold; border-top: 3px solid #0d6efd; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container py-4">
        
        <?php echo $msg; ?>

        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center pt-4">
                        <div class="avatar-circle mb-3 shadow">
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        </div>
                        <h4 class="mb-0 fw-bold"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h4>
                        <p class="text-muted small"><?php echo htmlspecialchars($user['email']); ?></p>
                        <div class="d-flex justify-content-center gap-2 mb-3">
                            <span class="badge bg-primary"><?php echo strtoupper($user['role']); ?></span>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($user['dept_name'] ?? '-'); ?></span>
                        </div>
                    </div>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Kalan İzin</span>
                            <span class="fw-bold text-success"><?php echo $user['remaining_leave'] ?? 0; ?> Gün</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>İşe Giriş</span>
                            <span><?php echo !empty($user['start_date']) ? date('d.m.Y', strtotime($user['start_date'])) : '-'; ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="col-md-8">
                
                <?php if (!empty($alerts)): ?>
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-warning text-dark fw-bold"><i class="fa fa-bell"></i> Yapılacaklar Listesi</div>
                        <div class="card-body p-2 bg-light">
                            <?php foreach ($alerts as $alert): ?>
                                <div class="alert alert-light border shadow-sm mb-2 d-flex justify-content-between align-items-center p-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fa <?php echo $alert['icon']; ?> fa-lg text-<?php echo $alert['type']; ?> me-3"></i>
                                        <div>
                                            <strong><?php echo $alert['title']; ?></strong>
                                            <div class="small text-muted"><?php echo $alert['msg']; ?></div>
                                        </div>
                                    </div>
                                    <a href="<?php echo $alert['link']; ?>" class="btn btn-sm btn-outline-dark">Git</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white p-0 border-bottom-0">
                        <ul class="nav nav-tabs px-2 pt-2" id="profileTab" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="payroll-tab" data-bs-toggle="tab" href="#payroll" role="tab"><i class="fa fa-file-invoice-dollar"></i> Bordrolarım</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="edit-tab" data-bs-toggle="tab" href="#edit" role="tab"><i class="fa fa-user-edit"></i> Bilgilerimi Düzenle</a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="profileTabContent">
                            
                            <div class="tab-pane fade show active" id="payroll" role="tabpanel">
                                <h6 class="text-primary mb-3">Maaş Bordroları</h6>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover align-middle">
                                        <thead class="table-light"><tr><th>Dönem</th><th>Tarih</th><th>Durum</th><th class="text-end">İşlem</th></tr></thead>
                                        <tbody>
                                            <?php 
                                                $payrolls = $pdo->query("SELECT * FROM payrolls WHERE user_id = $user_id ORDER BY id DESC")->fetchAll();
                                                if(count($payrolls) == 0) echo '<tr><td colspan="4" class="text-center text-muted">Henüz bordro yüklenmemiş.</td></tr>';
                                                
                                                foreach($payrolls as $pay): 
                                            ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo $pay['period_month'].'/'.$pay['period_year']; ?></td>
                                                <td><?php echo date('d.m.Y', strtotime($pay['created_at'])); ?></td>
                                                <td>
                                                    <?php if($pay['is_signed']): ?>
                                                        <span class="badge bg-success" title="İmzalandı: <?php echo $pay['signed_at']; ?>"><i class="fa fa-check-circle"></i> Onaylandı</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark"><i class="fa fa-clock"></i> Onay Bekliyor</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?php echo $pay['file_path']; ?>" target="_blank" class="btn btn-sm btn-info text-white" title="Görüntüle"><i class="fa fa-eye"></i></a>
                                                    <?php if(!$pay['is_signed']): ?>
                                                        <button class="btn btn-sm btn-success" onclick="signPayroll(<?php echo $pay['id']; ?>)" title="Dijital İmzala"><i class="fa fa-signature"></i> Onayla</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="edit" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_self">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Telefon</label>
                                            <input type="text" name="phone" class="form-control" value="<?php echo guvenli_html($user['phone']); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">IBAN</label>
                                            <input type="text" name="iban" class="form-control" value="<?php echo guvenli_html($user['iban']); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted">Adres</label>
                                            <textarea name="address" class="form-control" rows="2"><?php echo guvenli_html($user['address']); ?></textarea>
                                        </div>
                                        
                                        <div class="col-12"><hr class="my-1"></div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted text-danger">Acil Durum Kişisi</label>
                                            <input type="text" name="emer_name" class="form-control" value="<?php echo guvenli_html($user['emergency_contact_name']); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted text-danger">Acil Durum Tel</label>
                                            <input type="text" name="emer_phone" class="form-control" value="<?php echo guvenli_html($user['emergency_contact_phone']); ?>">
                                        </div>

                                        <div class="col-12"><hr class="my-1"></div>

                                        <div class="col-md-12">
                                            <label class="form-label small text-muted">Yeni Şifre (Değiştirmeyecekseniz boş bırakın)</label>
                                            <input type="password" name="new_password" class="form-control bg-light" placeholder="******">
                                        </div>
                                    </div>

                                    <div class="mt-4 text-end">
                                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Güncelle</button>
                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">İşlem Detayı</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editModalBody"></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function openEditModal(id) {
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            $('#editModalBody').load('transaction-edit.php?id=' + id);
        }

        function signPayroll(id) {
            if(confirm('Bu bordroyu okudum, anladım ve dijital olarak onaylıyorum.')) {
                fetch('api-sign-payroll.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id
                })
                .then(r => r.json())
                .then(d => {
                    alert(d.message);
                    if(d.status === 'success') location.reload();
                });
            }
        }
    </script>
</body>
</html>