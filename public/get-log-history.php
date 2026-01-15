<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['module']) || !isset($_POST['record_id'])) {
    echo '<div class="alert alert-danger">Yetkisiz erişim.</div>';
    exit;
}

$module = temizle($_POST['module']);
$record_id = (int)$_POST['record_id'];

// Logları ve yapan kişinin ismini çek
$sql = "SELECT l.*, u.username 
        FROM activity_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE l.module = ? AND l.record_id = ? 
        ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$module, $record_id]);
$logs = $stmt->fetchAll();
?>

<?php if (count($logs) > 0): ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped small">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Kullanıcı</th>
                    <th>İşlem</th>
                    <th>Detay</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo date('d.m.Y H:i', strtotime($log['created_at'])); ?></td>
                        <td class="fw-bold"><?php echo guvenli_html($log['username']); ?></td>
                        <td>
                            <?php 
                                if($log['action'] == 'create') echo '<span class="badge bg-success">Oluşturma</span>';
                                elseif($log['action'] == 'update') echo '<span class="badge bg-warning text-dark">Güncelleme</span>';
                                elseif($log['action'] == 'delete') echo '<span class="badge bg-danger">Silme</span>';
                                else echo '<span class="badge bg-secondary">'.$log['action'].'</span>';
                            ?>
                        </td>
                        <td><?php echo guvenli_html($log['description']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info text-center">Bu kayıt için henüz bir geçmiş bulunamadı.</div>
<?php endif; ?>