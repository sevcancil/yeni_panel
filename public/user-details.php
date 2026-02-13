<?php
// public/user-details.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$target_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
$my_role = $_SESSION['role'];
$my_id = $_SESSION['user_id'];
$msg = '';

// Yetki Kontrolü: Başkasının profiline bakıyorsa ve Admin/İK değilse engelle
if ($target_user_id != $my_id && $my_role != 'admin' && $my_role != 'ik') {
    die('<div class="alert alert-danger m-5">Bu profili görüntüleme yetkiniz yok.</div>');
}

// --- PHP İŞLEM HAVUZU (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. TEMEL BİLGİLER & ŞİFRE & YETKİLER
    if (isset($_POST['action']) && $_POST['action'] == 'update_basic') {
        if ($my_role == 'admin' || $my_role == 'ik' || $my_id == $target_user_id) {
            $fname = temizle($_POST['full_name']);
            $mail = temizle($_POST['email']);
            $phone = temizle($_POST['phone']);
            
            // Şifre Değişikliği Varsa
            $pass_sql = "";
            $params = [$fname, $mail, $phone];
            if (!empty($_POST['new_password'])) {
                $pass_sql = ", password = ?";
                $params[] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            }

            // Admin ise Rol ve Yetkileri Güncelle
            $role_sql = "";
            if ($my_role == 'admin') {
                $role_sql = ", role = ?, department_id = ?, permissions = ?";
                $params[] = $_POST['role'];
                $params[] = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
                $params[] = isset($_POST['perms']) ? json_encode($_POST['perms']) : '[]';
            }

            $params[] = $target_user_id; // WHERE id = ?

            $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? $pass_sql $role_sql WHERE id=?");
            $stmt->execute($params);
            $msg = '<div class="alert alert-success">Bilgiler güncellendi.</div>';
        }
    }

    // 2. İK VE ÖZLÜK BİLGİLERİ (Sadece Admin/İK)
    if (isset($_POST['action']) && $_POST['action'] == 'update_hr' && ($my_role == 'admin' || $my_role == 'ik')) {
        // ... (Bu kısım önceki koddaki gibi) ...
        // UPSERT mantığı ile employee_details tablosunu güncelle
        $check = $pdo->prepare("SELECT id FROM employee_details WHERE user_id = ?");
        $check->execute([$target_user_id]);
        
        // Verileri al
        $tc = $_POST['tc_no']; $sal = (float)$_POST['salary']; $total_leave = (int)$_POST['total_leave_days'];
        // Diğer alanlar... (Kısaltma yaptım, tam kodu aşağıda)
        
        if ($check->rowCount() > 0) {
            $sql = "UPDATE employee_details SET tc_no=?, salary=?, total_leave_days=?, birth_date=?, address=?, emergency_contact_name=?, emergency_contact_phone=?, iban=? WHERE user_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tc, $sal, $total_leave, $_POST['birth_date'], $_POST['address'], $_POST['emer_name'], $_POST['emer_phone'], $_POST['iban'], $target_user_id]);
        } else {
            $sql = "INSERT INTO employee_details (user_id, tc_no, salary, total_leave_days) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$target_user_id, $tc, $sal, $total_leave]);
        }
        $msg = '<div class="alert alert-success">Özlük bilgileri kaydedildi.</div>';
    }

    // 3. BORDRO YÜKLEME (Sadece İK/Admin)
    if (isset($_POST['action']) && $_POST['action'] == 'upload_payroll' && ($my_role == 'admin' || $my_role == 'ik')) {
        if (!empty($_FILES['payroll_file']['name'])) {
            $ext = strtolower(pathinfo($_FILES['payroll_file']['name'], PATHINFO_EXTENSION));
            if ($ext != 'pdf') { $msg = '<div class="alert alert-danger">Sadece PDF yüklenebilir.</div>'; }
            else {
                $path = '../storage/payrolls/' . uniqid() . '.pdf';
                // Klasör yoksa oluştur
                if (!is_dir('../storage/payrolls')) mkdir('../storage/payrolls', 0777, true);
                
                if (move_uploaded_file($_FILES['payroll_file']['tmp_name'], $path)) {
                    $stmt = $pdo->prepare("INSERT INTO payrolls (user_id, period_month, period_year, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$target_user_id, $_POST['p_month'], $_POST['p_year'], $path, $my_id]);
                    $msg = '<div class="alert alert-success">Bordro yüklendi.</div>';
                }
            }
        }
    }

    // 4. İZİN EKLEME (Sadece İK/Admin)
    if (isset($_POST['action']) && $_POST['action'] == 'add_leave' && ($my_role == 'admin' || $my_role == 'ik')) {
        $start = new DateTime($_POST['start_date']);
        $end = new DateTime($_POST['end_date']);
        $diff = $start->diff($end);
        $days = $diff->days + 1; // +1 gün dahil

        $stmt = $pdo->prepare("INSERT INTO leaves (user_id, type, start_date, end_date, days, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$target_user_id, $_POST['leave_type'], $_POST['start_date'], $_POST['end_date'], $days, $_POST['description']]);
        
        // Kullanılan izni güncelle
        $pdo->prepare("UPDATE employee_details SET used_leave_days = used_leave_days + ? WHERE user_id = ?")->execute([$days, $target_user_id]);
        
        $msg = '<div class="alert alert-success">İzin eklendi.</div>';
    }
}

// --- VERİ ÇEKME ---
$stmt = $pdo->prepare("
    SELECT u.*, d.name as dept_name, ed.*, 
    (ed.total_leave_days - ed.used_leave_days) as remaining_leave
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN employee_details ed ON u.id = ed.user_id
    WHERE u.id = ?
");
$stmt->execute([$target_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) die("Kullanıcı bulunamadı.");

$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$user_perms = json_decode($user['permissions'] ?? '[]', true);
if (!is_array($user_perms)) $user_perms = [];

// Yetki Listesi (Checkboxlar için)
$perm_definitions = [
    'manage_finance' => 'Finans (Ekle/Düzenle)',
    'approve_payment' => 'Ödeme Onayı Verme',
    'view_reports' => 'Tüm Raporları Görme',
    'delete_data' => 'Kayıt Silme Yetkisi'
];

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Profil: <?php echo guvenli_html($user['full_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .nav-tabs .nav-link { color: #555; font-weight: 500; }
        .nav-tabs .nav-link.active { color: #000; border-top: 3px solid #0d6efd; font-weight: bold; background: #fff; }
        .tab-content { background: #fff; border: 1px solid #dee2e6; border-top: none; padding: 20px; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4 mb-5">
        <?php echo $msg; ?>

        <div class="card shadow-sm mb-3 border-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-dark text-white rounded-circle d-flex align-items-center justify-content-center me-3 fs-3" style="width: 70px; height: 70px;">
                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-bold"><?php echo guvenli_html($user['full_name']); ?></h3>
                        <div class="text-muted"><?php echo guvenli_html($user['dept_name'] ?? 'Departman Atanmamış'); ?></div>
                        <span class="badge bg-primary"><?php echo strtoupper($user['role']); ?></span>
                    </div>
                    <div class="ms-auto text-end">
                        <a href="users.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> Geri</a>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs" id="myTab" role="tablist">
            <li class="nav-item"><a class="nav-link active" id="home-tab" data-bs-toggle="tab" href="#home" role="tab"><i class="fa fa-user-cog"></i> Hesap & Yetki</a></li>
            <?php if($my_role == 'admin' || $my_role == 'ik'): ?>
                <li class="nav-item"><a class="nav-link" id="hr-tab" data-bs-toggle="tab" href="#hr" role="tab"><i class="fa fa-id-card"></i> İK & Özlük</a></li>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link" id="payroll-tab" data-bs-toggle="tab" href="#payroll" role="tab"><i class="fa fa-file-invoice-dollar"></i> Bordrolar</a></li>
            <li class="nav-item"><a class="nav-link" id="leave-tab" data-bs-toggle="tab" href="#leave" role="tab"><i class="fa fa-umbrella-beach"></i> İzinler</a></li>
        </ul>

        <div class="tab-content shadow-sm" id="myTabContent">
            
            <div class="tab-pane fade show active" id="home" role="tabpanel">
                <form method="POST">
                    <input type="hidden" name="action" value="update_basic">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3 text-primary">Kişisel Bilgiler</h5>
                            <div class="mb-3"><label>Ad Soyad</label><input type="text" name="full_name" class="form-control" value="<?php echo guvenli_html($user['full_name']); ?>" required></div>
                            <div class="mb-3"><label>E-posta</label><input type="email" name="email" class="form-control" value="<?php echo guvenli_html($user['email']); ?>" required></div>
                            <div class="mb-3"><label>Telefon</label><input type="text" name="phone" class="form-control" value="<?php echo guvenli_html($user['phone']); ?>"></div>
                            
                            <hr>
                            <h5 class="mb-3 text-danger"><i class="fa fa-key"></i> Şifre Değiştir</h5>
                            <div class="mb-3">
                                <label>Yeni Şifre (Değiştirmek istemiyorsanız boş bırakın)</label>
                                <input type="password" name="new_password" class="form-control" autocomplete="new-password" placeholder="******">
                            </div>
                        </div>

                        <?php if($my_role == 'admin'): ?>
                        <div class="col-md-6 border-start">
                            <h5 class="mb-3 text-primary">Rol ve Yetkiler</h5>
                            <div class="mb-3">
                                <label>Rol (Sistem Erişimi)</label>
                                <select name="role" class="form-select">
                                    <option value="staff" <?php echo ($user['role']=='staff')?'selected':''; ?>>Personel (Kısıtlı)</option>
                                    <option value="ik" <?php echo ($user['role']=='ik')?'selected':''; ?>>İnsan Kaynakları</option>
                                    <option value="muhasebe" <?php echo ($user['role']=='muhasebe')?'selected':''; ?>>Muhasebe</option>
                                    <option value="admin" <?php echo ($user['role']=='admin')?'selected':''; ?>>Yönetici (Admin)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Departman (Veri Kısıtlaması İçin)</label>
                                <select name="department_id" class="form-select">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($departments as $d): ?>
                                        <option value="<?php echo $d['id']; ?>" <?php echo ($user['department_id']==$d['id'])?'selected':''; ?>><?php echo $d['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="p-3 bg-light rounded border">
                                <label class="fw-bold mb-2">Ekstra Yetkiler:</label>
                                <?php foreach($perm_definitions as $key => $label): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="perms[]" value="<?php echo $key; ?>" id="p_<?php echo $key; ?>" <?php echo in_array($key, $user_perms) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="p_<?php echo $key; ?>"><?php echo $label; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 text-end">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Kaydet ve Güncelle</button>
                    </div>
                </form>
            </div>

            <?php if($my_role == 'admin' || $my_role == 'ik'): ?>
            <div class="tab-pane fade" id="hr" role="tabpanel">
                <form method="POST">
                    <input type="hidden" name="action" value="update_hr">
                    <div class="row">
                        <div class="col-md-3 mb-3"><label>TC Kimlik No</label><input type="text" name="tc_no" class="form-control fw-bold" value="<?php echo guvenli_html($user['tc_no']); ?>" maxlength="11"></div>
                        <div class="col-md-3 mb-3"><label>Doğum Tarihi</label><input type="date" name="birth_date" class="form-control" value="<?php echo $user['birth_date']; ?>"></div>
                        <div class="col-md-3 mb-3"><label>Maaş (Net)</label><input type="number" step="0.01" name="salary" class="form-control" value="<?php echo $user['salary']; ?>"></div>
                        <div class="col-md-3 mb-3"><label>Yıllık İzin Hakkı</label><input type="number" name="total_leave_days" class="form-control" value="<?php echo $user['total_leave_days'] ?? 14; ?>"></div>
                        
                        <div class="col-md-12 mb-3"><label>Adres</label><textarea name="address" class="form-control" rows="2"><?php echo guvenli_html($user['address']); ?></textarea></div>
                        
                        <div class="col-md-6 mb-3"><label>Acil Durum Kişisi</label><input type="text" name="emer_name" class="form-control" value="<?php echo guvenli_html($user['emergency_contact_name']); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Acil Durum Tel</label><input type="text" name="emer_phone" class="form-control" value="<?php echo guvenli_html($user['emergency_contact_phone']); ?>"></div>
                        <div class="col-md-6 mb-3"><label>IBAN</label><input type="text" name="iban" class="form-control" value="<?php echo guvenli_html($user['iban']); ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Özlük Bilgilerini Kaydet</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="tab-pane fade" id="payroll" role="tabpanel">
                <div class="row">
                    <?php if($my_role == 'admin' || $my_role == 'ik'): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card bg-light border-primary h-100">
                            <div class="card-body">
                                <h6 class="fw-bold text-primary"><i class="fa fa-upload"></i> Yeni Bordro Yükle</h6>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="upload_payroll">
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <select name="p_month" class="form-select form-select-sm">
                                                <?php for($i=1;$i<=12;$i++) echo "<option value='$i'>$i. Ay</option>"; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select name="p_year" class="form-select form-select-sm">
                                                <option value="2026">2026</option>
                                                <option value="2025">2025</option>
                                            </select>
                                        </div>
                                    </div>
                                    <input type="file" name="payroll_file" class="form-control form-control-sm mb-2" accept=".pdf" required>
                                    <button type="submit" class="btn btn-sm btn-primary w-100">Yükle</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-<?php echo ($my_role=='admin'||$my_role=='ik') ? '8' : '12'; ?>">
                        <table class="table table-striped table-hover">
                            <thead><tr><th>Dönem</th><th>Yüklenme</th><th>Durum</th><th>İşlem</th></tr></thead>
                            <tbody>
                                <?php 
                                    $payrolls = $pdo->query("SELECT * FROM payrolls WHERE user_id = $target_user_id ORDER BY id DESC")->fetchAll();
                                    foreach($payrolls as $pay):
                                ?>
                                <tr>
                                    <td><?php echo $pay['period_month'].'/'.$pay['period_year']; ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($pay['created_at'])); ?></td>
                                    <td>
                                        <?php if($pay['is_signed']): ?>
                                            <span class="badge bg-success" title="<?php echo $pay['signed_at']; ?>"><i class="fa fa-check"></i> İmzalandı</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Bekliyor</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo $pay['file_path']; ?>" target="_blank" class="btn btn-sm btn-info text-white"><i class="fa fa-eye"></i></a>
                                        <?php if(!$pay['is_signed'] && $target_user_id == $my_id): ?>
                                            <button class="btn btn-sm btn-success" onclick="signPayroll(<?php echo $pay['id']; ?>)">İmzala</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="leave" role="tabpanel">
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center mb-3">
                            <div class="card-header bg-success text-white">Kalan İzin</div>
                            <div class="card-body">
                                <h1 class="display-4 fw-bold"><?php echo $user['remaining_leave'] ?? 0; ?></h1>
                                <small>Gün</small>
                            </div>
                        </div>
                        <?php if($my_role == 'admin' || $my_role == 'ik'): ?>
                            <button class="btn btn-warning w-100 mb-3" data-bs-toggle="modal" data-bs-target="#leaveModal"><i class="fa fa-plus"></i> İzin Ekle</button>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-9">
                        <table class="table table-bordered">
                            <thead><tr><th>Tip</th><th>Tarihler</th><th>Gün</th><th>Açıklama</th></tr></thead>
                            <tbody>
                                <?php 
                                    $leaves = $pdo->query("SELECT * FROM leaves WHERE user_id = $target_user_id ORDER BY start_date DESC")->fetchAll();
                                    foreach($leaves as $l):
                                ?>
                                <tr>
                                    <td><?php echo guvenli_html($l['type']); ?></td>
                                    <td><?php echo date('d.m', strtotime($l['start_date'])) . ' - ' . date('d.m.Y', strtotime($l['end_date'])); ?></td>
                                    <td class="fw-bold text-center"><?php echo $l['days']; ?></td>
                                    <td><?php echo guvenli_html($l['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="leaveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">İzin Kaydı Gir</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_leave">
                        <div class="mb-3">
                            <label>İzin Türü</label>
                            <select name="leave_type" class="form-select">
                                <option value="Yıllık İzin">Yıllık İzin</option>
                                <option value="Rapor">Rapor / Hastalık</option>
                                <option value="Mazeret">Mazeret İzni</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3"><label>Başlangıç</label><input type="date" name="start_date" class="form-control" required></div>
                            <div class="col-6 mb-3"><label>Bitiş (Dahil)</label><input type="date" name="end_date" class="form-control" required></div>
                        </div>
                        <div class="mb-3"><label>Açıklama</label><input type="text" name="description" class="form-control"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function signPayroll(id) {
            if(confirm('Bu bordroyu onaylıyor musunuz?')) {
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