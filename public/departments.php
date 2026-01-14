<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Güvenlik: Sadece Adminler
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Bu sayfaya erişim yetkiniz yok.");
}

$message = '';

// --- İŞLEM 1: SİLME (GET ile gelen delete_id varsa) ---
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    // Güvenli silme (Bölüm silinince kayıtlardaki ID null olur)
    $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
    if ($stmt->execute([$del_id])) {
        // Sayfayı temiz URL ile yenile
        header("Location: departments.php?msg=deleted");
        exit;
    }
}

// --- İŞLEM 2: EKLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dept'])) {
    $name = temizle($_POST['name']);
    if (!empty($name)) {
        $check = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
        $check->execute([$name]);
        if ($check->rowCount() > 0) {
            $message = '<div class="alert alert-danger">Bu bölüm zaten mevcut!</div>';
        } else {
            $stmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
            $stmt->execute([$name]);
            $message = '<div class="alert alert-success">Bölüm eklendi!</div>';
        }
    }
}

// --- İŞLEM 3: DÜZENLEME (Modal'dan gelen veri) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_dept'])) {
    $id = (int)$_POST['edit_id'];
    $name = temizle($_POST['edit_name']);
    
    if (!empty($name) && $id > 0) {
        $stmt = $pdo->prepare("UPDATE departments SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        $message = '<div class="alert alert-success">Bölüm güncellendi!</div>';
    }
}

// --- LİSTELEME ---
$sql = "SELECT d.*, (SELECT COUNT(*) FROM transactions WHERE department_id = d.id) as usage_count FROM departments d ORDER BY d.name ASC";
$departments = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Bölüm Yönetimi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Departman Yönetimi</h2>
        </div>
        
        <?php 
            if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Bölüm silindi.</div>';
            echo $message; 
        ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm border-primary mb-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fa fa-plus"></i> Yeni Bölüm Ekle</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="add_dept" value="1">
                            <div class="mb-3">
                                <label class="form-label">Bölüm Adı</label>
                                <input type="text" name="name" class="form-control" placeholder="Örn: İnsan Kaynakları" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Kayıtlı Bölümler</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover table-striped mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Bölüm Adı</th>
                                    <th>İşlem Sayısı</th>
                                    <th class="text-end">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($departments as $d): ?>
                                    <tr>
                                        <td><?php echo $d['id']; ?></td>
                                        <td><strong><?php echo guvenli_html($d['name']); ?></strong></td>
                                        <td>
                                            <?php if($d['usage_count'] > 0): ?>
                                                <span class="badge bg-secondary"><?php echo $d['usage_count']; ?> kayıt</span>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-warning editBtn" 
                                                    data-id="<?php echo $d['id']; ?>" 
                                                    data-name="<?php echo guvenli_html($d['name']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editModal">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            
                                            <a href="departments.php?delete_id=<?php echo $d['id']; ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Bu bölümü silmek istediğinize emin misiniz?');">
                                                <i class="fa fa-trash"></i>
                                            </a>
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

    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">Bölüm Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_dept" value="1">
                        <input type="hidden" name="edit_id" id="modal_edit_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Bölüm Adı</label>
                            <input type="text" name="edit_name" id="modal_edit_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-warning">Değişiklikleri Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Edit butonlarına tıklandığında veriyi modal içine taşı
        const editButtons = document.querySelectorAll('.editBtn');
        const idInput = document.getElementById('modal_edit_id');
        const nameInput = document.getElementById('modal_edit_name');

        editButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                const name = btn.getAttribute('data-name');
                idInput.value = id;
                nameInput.value = name;
            });
        });
    </script>
</body>
</html>