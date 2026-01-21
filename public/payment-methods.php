<?php
// public/payment-methods.php
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
        
        $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ?");
        $stmt->execute([$del_id]);
        
        header("Location: payment-methods.php?msg=deleted");
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
            $stmt = $pdo->prepare("UPDATE payment_methods SET title = ? WHERE id = ?");
            $stmt->execute([$title, $edit_id]);
            $message = '<div class="alert alert-success">Ödeme yöntemi güncellendi!</div>';
        } else {
            // EKLEME
            $stmt = $pdo->prepare("INSERT INTO payment_methods (title) VALUES (?)");
            $stmt->execute([$title]);
            $message = '<div class="alert alert-success">Yeni ödeme yöntemi eklendi!</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Başlık boş olamaz.</div>';
    }
}

// LİSTELEME
$methods = $pdo->query("SELECT * FROM payment_methods ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ödeme Yöntemleri</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Ödeme Yöntemleri</h2>
            <button type="button" class="btn btn-primary" onclick="openModal('add')">
                <i class="fa fa-plus"></i> Yeni Ekle
            </button>
        </div>

        <?php 
            if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Kayıt silindi.</div>';
            echo $message; 
        ?>

        <div class="card mb-3 shadow-sm border-0">
            <div class="card-body p-2 bg-light rounded">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fa fa-search text-muted"></i></span>
                    <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Ödeme yöntemi ara...">
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle" id="methodsTable">
                        <thead class="table-light">
                            <tr>
                                <th width="10%">#ID</th>
                                <th>Ödeme Yöntemi Adı</th>
                                <th>Eklenme Tarihi</th>
                                <th class="text-center" width="15%">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($methods as $m): ?>
                                <tr>
                                    <td><?php echo $m['id']; ?></td>
                                    <td class="fw-bold search-col"><?php echo guvenli_html($m['title']); ?></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($m['created_at'])); ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-warning" onclick='openModal("edit", <?php echo json_encode($m); ?>)'>
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        
                                        <?php if(has_permission('delete_data')): ?>
                                            <a href="payment-methods.php?delete_id=<?php echo $m['id']; ?>" 
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
                    <div id="noResults" class="text-center p-3 text-muted d-none">
                        Kayıt bulunamadı.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="methodModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="modalTitle">Yeni Ödeme Yöntemi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Başlık</label>
                            <input type="text" name="title" id="title" class="form-control" required placeholder="Örn: Mail Order">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var modal = new bootstrap.Modal(document.getElementById('methodModal'));

        function openModal(mode, data = null) {
            if (mode === 'edit' && data) {
                document.getElementById('modalTitle').innerText = "Yöntemi Düzenle";
                document.getElementById('edit_id').value = data.id;
                document.getElementById('title').value = data.title;
            } else {
                document.getElementById('modalTitle').innerText = "Yeni Ödeme Yöntemi";
                document.getElementById('edit_id').value = "";
                document.getElementById('title').value = "";
            }
            modal.show();
        }

        // --- ANLIK ARAMA FONKSİYONU ---
        document.getElementById('searchInput').addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#methodsTable tbody tr');
            var noResults = document.getElementById('noResults');
            var hasVisible = false;

            rows.forEach(function(row) {
                var text = row.querySelector('.search-col').innerText.toLowerCase();
                if (text.includes(filter)) {
                    row.classList.remove('d-none');
                    hasVisible = true;
                } else {
                    row.classList.add('d-none');
                }
            });

            if(hasVisible) {
                noResults.classList.add('d-none');
            } else {
                noResults.classList.remove('d-none');
            }
        });
    </script>
</body>
</html>