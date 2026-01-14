<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Güvenlik: Sadece finans yetkisi veya admin
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$message = '';

// --- İŞLEM 1: SİLME ---
if (isset($_GET['delete_id'])) {
    if(has_permission('delete_data')) {
        $del_id = (int)$_GET['delete_id'];
        
        $stmt = $pdo->prepare("DELETE FROM collection_channels WHERE id = ?");
        $stmt->execute([$del_id]);
        
        header("Location: collection-channels.php?msg=deleted");
        exit;
    } else {
        $message = '<div class="alert alert-danger">Silme yetkiniz yok!</div>';
    }
}

// --- İŞLEM 2: EKLEME VE GÜNCELLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = temizle($_POST['title']);
    $edit_id = isset($_POST['edit_id']) && !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

    if (!empty($title)) {
        if ($edit_id > 0) {
            // GÜNCELLEME
            $stmt = $pdo->prepare("UPDATE collection_channels SET title = ? WHERE id = ?");
            $stmt->execute([$title, $edit_id]);
            $message = '<div class="alert alert-success">Tahsilat kanalı güncellendi!</div>';
        } else {
            // EKLEME
            $stmt = $pdo->prepare("INSERT INTO collection_channels (title) VALUES (?)");
            $stmt->execute([$title]);
            $message = '<div class="alert alert-success">Yeni tahsilat kanalı eklendi!</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Başlık boş olamaz.</div>';
    }
}

// LİSTELEME
$channels = $pdo->query("SELECT * FROM collection_channels ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Tahsilat Kanalları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Tahsilat Kanalları</h2>
            <button type="button" class="btn btn-success" onclick="openModal('add')">
                <i class="fa fa-plus"></i> Yeni Ekle
            </button>
        </div>

        <?php 
            if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Kayıt silindi.</div>';
            echo $message; 
        ?>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="10%">#ID</th>
                            <th>Kanal Adı</th>
                            <th>Eklenme Tarihi</th>
                            <th class="text-center" width="15%">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($channels as $c): ?>
                            <tr>
                                <td><?php echo $c['id']; ?></td>
                                <td class="fw-bold text-success"><?php echo guvenli_html($c['title']); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($c['created_at'])); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-warning" onclick='openModal("edit", <?php echo json_encode($c); ?>)'>
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    
                                    <?php if(has_permission('delete_data')): ?>
                                        <a href="collection-channels.php?delete_id=<?php echo $c['id']; ?>" 
                                           class="btn btn-sm btn-danger"
                                           onclick="return confirm('Silmek istediğinize emin misiniz?');">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="channelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="modalTitle">Yeni Tahsilat Kanalı</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Başlık</label>
                            <input type="text" name="title" id="title" class="form-control" required placeholder="Örn: Sanal POS">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-success">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var modal = new bootstrap.Modal(document.getElementById('channelModal'));

        function openModal(mode, data = null) {
            if (mode === 'edit' && data) {
                document.getElementById('modalTitle').innerText = "Kanalı Düzenle";
                document.getElementById('edit_id').value = data.id;
                document.getElementById('title').value = data.title;
            } else {
                document.getElementById('modalTitle').innerText = "Yeni Tahsilat Kanalı";
                document.getElementById('edit_id').value = "";
                document.getElementById('title').value = "";
            }
            modal.show();
        }
    </script>
</body>
</html>