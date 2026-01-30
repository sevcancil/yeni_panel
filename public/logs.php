<?php
// public/logs.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// LOGLARI ÇEK (Tablo adı: activity_logs)
$sql = "SELECT l.*, u.full_name as user_name 
        FROM activity_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.created_at DESC LIMIT 500";
$logs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Sistem Log Kayıtları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h3 class="mb-4 text-secondary"><i class="fa fa-history"></i> Sistem Hareket Kayıtları (Log)</h3>
        
        <div class="card shadow">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="logTable" class="table table-striped table-hover table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Tarih</th>
                                <th>Kullanıcı</th>
                                <th>Modül</th>
                                <th>İşlem</th>
                                <th>Kayıt ID</th>
                                <th>Açıklama / Detay</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($logs as $log): 
                                // Tablodaki sütun adları: id, user_id, module, action, record_id, description, ip_address, created_at
                                $badge_class = 'bg-secondary';
                                if($log['action'] == 'delete') $badge_class = 'bg-danger';
                                elseif($log['action'] == 'create') $badge_class = 'bg-success';
                                elseif($log['action'] == 'update') $badge_class = 'bg-primary';
                                elseif($log['action'] == 'invoice') $badge_class = 'bg-info text-dark';
                            ?>
                            <tr>
                                <td><?php echo $log['id']; ?></td>
                                <td style="white-space:nowrap;"><?php echo date('d.m.Y H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><i class="fa fa-user"></i> <?php echo htmlspecialchars($log['user_name'] ?? 'Sistem'); ?></td>
                                <td><?php echo strtoupper($log['module']); ?></td>
                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo strtoupper($log['action']); ?></span></td>
                                <td>#<?php echo $log['record_id']; ?></td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#logTable').DataTable({
                "order": [[ 0, "desc" ]],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json" }
            });
        });
    </script>
</body>
</html>