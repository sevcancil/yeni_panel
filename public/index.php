<?php
// public/index.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
require_once '../app/functions/alerts.php'; // Sistem uyarılarını buradan çekiyoruz

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$is_admin = ($role === 'admin' || $role === 'muhasebe');

// --- 1. İSTATİSTİKLER (KPI) ---
// Bu ayki toplam gelir/gider (Onaylanmış)
$current_month = date('Y-m');
$sql_stats = "SELECT 
    SUM(CASE WHEN type = 'payment_in' THEN amount ELSE 0 END) as total_in,
    SUM(CASE WHEN type = 'payment_out' THEN amount ELSE 0 END) as total_out
    FROM transactions 
    WHERE is_deleted = 0 AND approval_status = 'approved' 
    AND DATE_FORMAT(date, '%Y-%m') = ?";
$stmt = $pdo->prepare($sql_stats);
$stmt->execute([$current_month]);
$stats = $stmt->fetch();

$balance = $stats['total_in'] - $stats['total_out'];

// --- 2. BİLDİRİMLERİ TOPLA ---
// A) Sistem Uyarıları (alerts.php'den gelenler)
$system_alerts = get_user_alerts($pdo, $user_id, $is_admin);

// B) Muhasebe Mesajları (Veritabanından)
// Hem bana özel (receiver_id = user_id) hem de herkese (receiver_id = 0) gönderilenler
$sql_msgs = "SELECT n.*, u.full_name as sender_name 
             FROM notifications n 
             JOIN users u ON n.sender_id = u.id 
             WHERE (n.receiver_id = ? OR n.receiver_id = 0) 
             AND n.is_read = 0 
             ORDER BY n.created_at DESC";
$stmt_msgs = $pdo->prepare($sql_msgs);
$stmt_msgs->execute([$user_id]);
$messages = $stmt_msgs->fetchAll();

// --- 3. SON 5 İŞLEM (Hızlı Bakış) ---
$sql_recent = "SELECT t.*, c.company_name 
               FROM transactions t 
               LEFT JOIN customers c ON t.customer_id = c.id
               WHERE t.is_deleted = 0 
               ORDER BY t.created_at DESC LIMIT 5";
$recent_trans = $pdo->query($sql_recent)->fetchAll();

// --- 4. GRAFİK VERİSİ (Son 6 Ay) ---
$chart_labels = [];
$chart_income = [];
$chart_expense = [];
for ($i = 5; $i >= 0; $i--) {
    $d = date("Y-m", strtotime("-$i months"));
    $chart_labels[] = date("M Y", strtotime("-$i months"));
    
    // Basitçe o ayın toplamlarını çekelim
    $inc = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='payment_in' AND is_deleted=0 AND DATE_FORMAT(date, '%Y-%m')='$d'")->fetchColumn();
    $exp = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='payment_out' AND is_deleted=0 AND DATE_FORMAT(date, '%Y-%m')='$d'")->fetchColumn();
    
    $chart_income[] = $inc ?? 0;
    $chart_expense[] = $exp ?? 0;
}

// Personel Listesi (Modal İçin)
if($is_admin) {
    $users = $pdo->query("SELECT id, full_name FROM users WHERE id != $user_id")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yönetim Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .icon-box { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 24px; }
        .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); color: white; }
        .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); color: white; }
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); color: white; }
        .card-hover:hover { transform: translateY(-3px); transition: 0.3s; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }
        .notification-item { border-left: 4px solid #eee; transition: 0.2s; }
        .notification-item:hover { background-color: #f8f9fa; }
        .notification-item.msg { border-left-color: #0d6efd; }
        .notification-item.alert { border-left-color: #ffc107; }
        .notification-item.danger { border-left-color: #dc3545; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-0 fw-bold text-gray-800">Hoş Geldiniz, <?php echo $_SESSION['full_name'] ?? 'Kullanıcı'; ?> 👋</h4>
                <small class="text-muted"><?php echo date('d F Y, l'); ?></small>
            </div>
            
            <?php if($is_admin): ?>
            <div>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#msgModal">
                    <i class="fa fa-paper-plane"></i> Bildirim Gönder
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100 card-hover">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-gradient-success me-3"><i class="fa fa-arrow-down"></i></div>
                        <div>
                            <div class="text-muted small fw-bold text-uppercase">Bu Ay Tahsilat</div>
                            <div class="h4 mb-0 fw-bold text-success"><?php echo number_format($stats['total_in'], 2, ',', '.'); ?> ₺</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100 card-hover">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-gradient-danger me-3"><i class="fa fa-arrow-up"></i></div>
                        <div>
                            <div class="text-muted small fw-bold text-uppercase">Bu Ay Ödeme</div>
                            <div class="h4 mb-0 fw-bold text-danger"><?php echo number_format($stats['total_out'], 2, ',', '.'); ?> ₺</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100 card-hover">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-gradient-primary me-3"><i class="fa fa-wallet"></i></div>
                        <div>
                            <div class="text-muted small fw-bold text-uppercase">Aylık Nakit Akışı</div>
                            <div class="h4 mb-0 fw-bold <?php echo $balance >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                <?php echo ($balance > 0 ? '+' : '') . number_format($balance, 2, ',', '.'); ?> ₺
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            
            <div class="col-lg-5 mb-4">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-primary"><i class="fa fa-bell"></i> Bildirim Merkezi</h6>
                        <span class="badge bg-danger rounded-pill"><?php echo count($system_alerts) + count($messages); ?></span>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        
                        <?php if (empty($system_alerts) && empty($messages)): ?>
                            <div class="text-center p-5 text-muted">
                                <i class="fa fa-check-circle fa-3x mb-3 text-light"></i>
                                <p>Harika! Okunmamış bildirim veya eksik bilgi yok.</p>
                            </div>
                        <?php endif; ?>

                        <?php foreach($messages as $msg): ?>
                            <div class="p-3 border-bottom notification-item msg" id="msg-<?php echo $msg['id']; ?>">
                                <div class="d-flex justify-content-between">
                                    <strong class="text-primary"><i class="fa fa-envelope"></i> <?php echo guvenli_html($msg['sender_name']); ?></strong>
                                    <small class="text-muted"><?php echo date('d.m H:i', strtotime($msg['created_at'])); ?></small>
                                </div>
                                <div class="mt-1 mb-2 fw-bold"><?php echo guvenli_html($msg['title']); ?></div>
                                <div class="text-secondary small mb-2"><?php echo nl2br(guvenli_html($msg['message'])); ?></div>
                                <button onclick="markAsRead(<?php echo $msg['id']; ?>)" class="btn btn-sm btn-outline-primary py-0" style="font-size: 0.75rem;">Okudum</button>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach($system_alerts as $alert): 
                            $border_class = ($alert['type'] == 'danger') ? 'danger' : 'alert';
                            $icon_color = ($alert['type'] == 'danger') ? 'text-danger' : 'text-warning';
                        ?>
                            <div class="p-3 border-bottom notification-item <?php echo $border_class; ?>">
                                <div class="d-flex">
                                    <div class="me-3 mt-1 <?php echo $icon_color; ?>">
                                        <i class="fa <?php echo $alert['icon']; ?> fa-lg"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark"><?php echo $alert['title']; ?></div>
                                        <div class="small text-muted my-1"><?php echo $alert['msg']; ?></div>
                                        <a href="<?php echo $alert['link']; ?>" class="btn btn-sm btn-light border btn-block w-100 text-start">
                                            <?php echo $alert['btn_text']; ?> <i class="fa fa-arrow-right float-end mt-1"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                
                <div class="card shadow border-0 mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-dark">Son 6 Ay Gelir/Gider Dengesi</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="dashboardChart" style="height: 250px;"></canvas>
                    </div>
                </div>

                <div class="card shadow border-0">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark">Son Eklenen İşlemler</h6>
                        <a href="payment-orders.php" class="btn btn-sm btn-light">Tümü</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Tarih</th><th>Cari</th><th>Tutar</th><th>Durum</th></tr></thead>
                            <tbody>
                                <?php foreach($recent_trans as $t): 
                                    $cls = ($t['type'] == 'payment_in' || $t['type'] == 'credit') ? 'text-success' : 'text-danger';
                                    $sign = ($t['type'] == 'payment_in' || $t['type'] == 'credit') ? '+' : '-';
                                ?>
                                <tr>
                                    <td><?php echo date('d.m', strtotime($t['date'])); ?></td>
                                    <td><?php echo mb_substr($t['company_name'] ?? '-', 0, 20); ?></td>
                                    <td class="fw-bold <?php echo $cls; ?>"><?php echo $sign . number_format($t['amount'], 2, ',', '.'); ?> ₺</td>
                                    <td>
                                        <?php if($t['approval_status'] == 'approved'): ?>
                                            <i class="fa fa-check-circle text-success" title="Onaylı"></i>
                                        <?php else: ?>
                                            <i class="fa fa-clock text-warning" title="Bekliyor"></i>
                                        <?php endif; ?>
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

    <?php if($is_admin): ?>
    <div class="modal fade" id="msgModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="notificationForm">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fa fa-paper-plane"></i> Bildirim Gönder</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Alıcı</label>
                            <select name="receiver_id" class="form-select" required>
                                <option value="0">📢 HERKES (Duyuru)</option>
                                <?php foreach($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo guvenli_html($u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Konu</label>
                            <input type="text" name="title" class="form-control" placeholder="Örn: Tur Kodu Eksikliği" required>
                        </div>
                        <div class="mb-3">
                            <label>Mesaj</label>
                            <textarea name="message" class="form-control" rows="4" placeholder="Mesajınızı buraya yazın..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- GRAFİK ---
        const ctx = document.getElementById('dashboardChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    { label: 'Tahsilat', data: <?php echo json_encode($chart_income); ?>, borderColor: '#198754', tension: 0.3, fill: false },
                    { label: 'Ödeme', data: <?php echo json_encode($chart_expense); ?>, borderColor: '#dc3545', tension: 0.3, fill: false }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });

        // --- BİLDİRİM OKUNDU ---
        function markAsRead(id) {
            fetch('api-notification-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=read&id=' + id
            }).then(() => {
                document.getElementById('msg-' + id).remove();
            });
        }

        // --- BİLDİRİM GÖNDER ---
        document.getElementById('notificationForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'send');
            
            fetch('api-notification-action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if(data.status === 'success') location.reload();
            });
        });
    </script>
</body>
</html>