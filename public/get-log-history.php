<?php
// public/get-log-history.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) exit('<div class="alert alert-danger">Yetkisiz erişim.</div>');

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    // SORGUSU GÜNCELLENDİ: name_surname kaldırıldı, sadece username çekiliyor.
    $sql = "SELECT l.*, u.username 
            FROM activity_logs l 
            LEFT JOIN users u ON l.user_id = u.id 
            WHERE l.module = 'transaction' AND l.record_id = ? 
            ORDER BY l.created_at DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    exit('<div class="alert alert-danger">Hata: ' . $e->getMessage() . '</div>');
}
?>

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
                        
                        // Kullanıcı Adı Gösterimi (GÜNCELLENDİ)
                        $user_name = $log['username'] ?? 'Silinmiş Kullanıcı';
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
                        Bu işlem için henüz kayıtlı bir geçmiş yok.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>