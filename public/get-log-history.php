<?php
// public/get-log-history.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) exit('<div class="alert alert-danger">Yetkisiz erişim.</div>');

// Parametreleri Al (POST veya GET)
$id = isset($_REQUEST['record_id']) ? (int)$_REQUEST['record_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$module = isset($_REQUEST['module']) ? $_REQUEST['module'] : 'transaction'; // Varsayılan transaction

if ($id <= 0) exit('<div class="alert alert-warning">Kayıt bulunamadı.</div>');

try {
    // 1. ADIM: KAYDI İLK OLUŞTURAN KİŞİYİ BUL
    $creator_name = "Bilinmiyor";
    $creation_date = "-";

    if ($module == 'customer') {
        $stmtCreator = $pdo->prepare("SELECT u.full_name, u.username, c.created_at FROM customers c LEFT JOIN users u ON c.created_by = u.id WHERE c.id = ?");
    } elseif ($module == 'transaction') {
        $stmtCreator = $pdo->prepare("SELECT u.full_name, u.username, t.created_at FROM transactions t LEFT JOIN users u ON t.created_by = u.id WHERE t.id = ?");
    }

    if (isset($stmtCreator)) {
        $stmtCreator->execute([$id]);
        $creator_data = $stmtCreator->fetch(PDO::FETCH_ASSOC);
        if ($creator_data) {
            $creator_name = !empty($creator_data['full_name']) ? $creator_data['full_name'] : $creator_data['username'];
            $creation_date = date('d.m.Y H:i', strtotime($creator_data['created_at']));
        }
    }

    // 2. ADIM: LOGLARI ÇEK
    // record_id VE parent_id loglarını birleştiriyoruz (Alt işlem logları da görünsün diye)
    // Eğer transaction ise hem kendi ID'si hem de bu ID'nin parent olduğu child işlemlerin loglarını çekebiliriz.
    // Ancak basitlik için şimdilik sadece record_id'ye bakıyoruz.
    
    $sql = "SELECT l.*, u.full_name, u.username 
            FROM activity_logs l 
            LEFT JOIN users u ON l.user_id = u.id 
            WHERE l.module = ? AND l.record_id = ? 
            ORDER BY l.created_at DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$module, $id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    exit('<div class="alert alert-danger">Hata: ' . $e->getMessage() . '</div>');
}
?>

<div class="alert alert-primary d-flex align-items-center justify-content-between py-2 px-3 mb-3">
    <div>
        <small class="text-primary-50 d-block">Kaydı Oluşturan</small>
        <strong><i class="fa fa-user-plus"></i> <?php echo guvenli_html($creator_name); ?></strong>
    </div>
    <div class="text-end">
        <small class="text-primary-50 d-block">Oluşturulma Tarihi</small>
        <strong><?php echo $creation_date; ?></strong>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-sm table-striped table-hover small mb-0">
        <thead class="table-dark">
            <tr>
                <th width="140">Tarih</th>
                <th width="150">Kullanıcı</th>
                <th>İşlem Detayı</th>
                <th width="100">Tür</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($logs) > 0): ?>
                <?php foreach ($logs as $log): ?>
                    <?php 
                        // Renklendirme
                        $badge_class = 'bg-secondary';
                        $icon = 'fa-info-circle';
                        
                        if($log['action'] == 'create') { $badge_class = 'bg-success'; $icon = 'fa-plus'; }
                        if($log['action'] == 'update') { $badge_class = 'bg-warning text-dark'; $icon = 'fa-edit'; }
                        if($log['action'] == 'delete') { $badge_class = 'bg-danger'; $icon = 'fa-trash'; }
                        if($log['action'] == 'approve') { $badge_class = 'bg-primary'; $icon = 'fa-check-double'; }
                        
                        $user_name = !empty($log['full_name']) ? $log['full_name'] : ($log['username'] ?? 'Sistem');
                    ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i', strtotime($log['created_at'])); ?></td>
                        <td class="fw-bold text-primary">
                            <i class="fa fa-user-circle"></i> <?php echo guvenli_html($user_name); ?>
                        </td>
                        <td><?php echo guvenli_html($log['description']); ?></td>
                        <td>
                            <span class="badge <?php echo $badge_class; ?>">
                                <i class="fa <?php echo $icon; ?>"></i> <?php echo strtoupper($log['action']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        <i class="fa fa-history fa-2x mb-2"></i><br>
                        Bu işlem için henüz ek bir aktivite logu yok.<br>
                        <small>(Sadece oluşturma bilgisi mevcut)</small>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>