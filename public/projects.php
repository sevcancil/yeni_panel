<?php
// public/projects.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$message = '';

// --- SİLME İŞLEMİ ---
if (isset($_GET['delete_id'])) {
    if(has_permission('delete_data')) {
        $del_id = (int)$_GET['delete_id'];
        $stmt = $pdo->prepare("SELECT name, code FROM tour_codes WHERE id = ?");
        $stmt->execute([$del_id]);
        $del_rec = $stmt->fetch();
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE tour_code_id = ?");
        $check->execute([$del_id]);
        
        if ($check->fetchColumn() > 0) {
            $message = '<div class="alert alert-danger">Bu projeye ait finansal hareketler var! Silemezsiniz.</div>';
        } else {
            $stmt = $pdo->prepare("DELETE FROM tour_codes WHERE id = ?");
            $stmt->execute([$del_id]);
            log_action($pdo, 'project', $del_id, 'delete', ($del_rec['name'] ?? 'Bilinmeyen') . " projesi silindi.");
            header("Location: projects.php?msg=deleted");
            exit;
        }
    } else {
        $message = '<div class="alert alert-danger">Silme yetkiniz yok!</div>';
    }
}

// --- KAYDETME / GÜNCELLEME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = temizle($_POST['code']);
    $name = temizle($_POST['name']);
    $employer = temizle($_POST['employer']);
    $start_date = $_POST['start_date'];
    
    // Yeni Alanlar
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $status = $_POST['status'] ?? 'active';

    $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
    $is_edit = isset($_POST['edit_id']) && !empty($_POST['edit_id']);
    $edit_id = $is_edit ? (int)$_POST['edit_id'] : 0;

    // Kod tekrarı kontrolü
    $sql_check = "SELECT id FROM tour_codes WHERE code = ?";
    $params_check = [$code];
    if ($is_edit) { $sql_check .= " AND id != ?"; $params_check[] = $edit_id; }
    $check = $pdo->prepare($sql_check);
    $check->execute($params_check);

    if ($check->rowCount() > 0) {
        $message = '<div class="alert alert-danger">Bu Tur Kodu zaten kullanılıyor!</div>';
    } else {
        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE tour_codes SET code=?, name=?, employer=?, start_date=?, end_date=?, status=?, department_id=? WHERE id=?");
            $stmt->execute([$code, $name, $employer, $start_date, $end_date, $status, $department_id, $edit_id]);
            log_action($pdo, 'project', $edit_id, 'update', "$code - $name güncellendi.");
            $message = '<div class="alert alert-success">Proje güncellendi!</div>';
        } else {
            $creator_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("INSERT INTO tour_codes (code, name, employer, start_date, end_date, status, department_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name, $employer, $start_date, $end_date, $status, $department_id, $creator_id]);
            log_action($pdo, 'project', $pdo->lastInsertId(), 'create', "$code - $name oluşturuldu.");
            $message = '<div class="alert alert-success">Yeni proje oluşturuldu!</div>';
        }
    }
}

// --- FİLTRE DEĞİŞKENLERİ ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($page < 1) $page = 1;
$limit = 100; 
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'active';
$filter_profit = isset($_GET['filter_profit']) ? $_GET['filter_profit'] : '';
$sort_profit = isset($_GET['sort_profit']) ? $_GET['sort_profit'] : '';

// --- DİNAMİK SQL OLUŞTURMA (Önemli Kısım) ---

// 1. İç Sorgu (Veri ve Hesaplamalar)
// Arama ve Statü filtresini performans için İÇ sorguya koyuyoruz.
$inner_where = " WHERE 1=1 ";
$inner_params = [];

if ($search !== '') {
    $inner_where .= " AND (t.code LIKE ? OR t.name LIKE ? OR t.employer LIKE ?)";
    $inner_params[] = "%$search%";
    $inner_params[] = "%$search%";
    $inner_params[] = "%$search%";
}

if ($filter_status !== 'all') {
    $inner_where .= " AND t.status = ?";
    $inner_params[] = $filter_status;
}

$base_query = "
    SELECT t.*, d.name as department_name, u.username as creator_name,
    COALESCE((SELECT SUM(amount) FROM transactions WHERE tour_code_id = t.id AND type = 'credit' AND is_deleted=0), 0) as total_income,
    COALESCE((SELECT SUM(amount) FROM transactions WHERE tour_code_id = t.id AND type = 'debt' AND is_deleted=0), 0) as total_expense
    FROM tour_codes t 
    LEFT JOIN departments d ON t.department_id = d.id
    LEFT JOIN users u ON t.created_by = u.id
    $inner_where
";

// 2. Dış Sorgu (Kâr/Zarar Filtreleme ve Sıralama)
// İç sorgudan gelen 'total_income' ve 'total_expense' alanlarını burada filtreliyoruz.
$outer_where = " WHERE 1=1 ";
$outer_params = $inner_params; // İçerideki parametreleri aynen al

if ($filter_profit === 'profit') {
    $outer_where .= " AND (total_income - total_expense) >= 0";
} elseif ($filter_profit === 'loss') {
    $outer_where .= " AND (total_income - total_expense) < 0";
}

// Sıralama
$order_by = " ORDER BY start_date DESC "; // Varsayılan
if ($sort_profit === 'desc') {
    $order_by = " ORDER BY (total_income - total_expense) DESC ";
} elseif ($sort_profit === 'asc') {
    $order_by = " ORDER BY (total_income - total_expense) ASC ";
}

// 3. Toplam Kayıt Sayısını Bulma (Sayfalama İçin)
// LIMIT olmadan sorguyu çalıştırıp sayısını almalıyız
$count_sql = "SELECT COUNT(*) FROM ($base_query) AS counted_table $outer_where";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($outer_params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// 4. Nihai Sorgu (Limitli)
$final_sql = "SELECT * FROM ($base_query) AS project_data $outer_where $order_by LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($final_sql);
$stmt->execute($outer_params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Projeler / Tur Kodları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.19.3/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .pagination { justify-content: center; margin-top: 20px; }
        .row-completed { background-color: #f8f9fa; opacity: 0.7; }
        .badge-status-active { background-color: #198754; }
        .badge-status-completed { background-color: #6c757d; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Tur Kodları / Projeler</h2>
            <div>
                <button type="button" class="btn btn-secondary me-2" onclick="exportBulkToExcel()">
                    <i class="fa fa-file-excel"></i> Toplu Liste İndir
                </button>
                <button type="button" class="btn btn-success" onclick="openModal('add')">
                    <i class="fa fa-magic"></i> Yeni İş Tanımla
                </button>
            </div>
        </div>

        <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Proje silindi.</div>'; echo $message; ?>

        <div class="card shadow mb-4 bg-light">
            <div class="card-body">
                <form method="GET" action="projects.php">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Kod, İş Adı veya Temsilci Ara..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="filter_status" class="form-select">
                                <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Aktif İşler</option>
                                <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Tamamlananlar</option>
                                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>Tümü</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="filter_profit" class="form-select">
                                <option value="">Tüm Finansal Durum</option>
                                <option value="profit" <?php echo $filter_profit == 'profit' ? 'selected' : ''; ?>>Kârda Olanlar</option>
                                <option value="loss" <?php echo $filter_profit == 'loss' ? 'selected' : ''; ?>>Zararda Olanlar</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="sort_profit" class="form-select">
                                <option value="">Sıralama: Tarih (Varsayılan)</option>
                                <option value="desc" <?php echo $sort_profit == 'desc' ? 'selected' : ''; ?>>Kâr: Yüksekten Düşüğe</option>
                                <option value="asc" <?php echo $sort_profit == 'asc' ? 'selected' : ''; ?>>Kâr: Düşükten Yükseğe</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search"></i> Filtrele</button>
                            <a href="projects.php" class="btn btn-outline-secondary" title="Temizle"><i class="fa fa-times"></i></a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0 align-middle" id="projectsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Kod</th>
                                <th>Bölüm</th> 
                                <th>Etkinlik / İş Adı</th>
                                <th>Temsilci</th>
                                <th>Başlangıç</th>
                                <th class="text-center">Oluşturan</th>
                                <th class="text-end">Gelir</th>
                                <th class="text-end">Gider</th>
                                <th class="text-end">Kâr/Zarar</th>
                                <th class="text-center">Durum</th>
                                <th class="text-center" width="160">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                <?php if(count($projects) == 0): ?>
                    <tr><td colspan="11" class="text-center py-4 text-muted">Kriterlerinize uygun proje bulunamadı.</td></tr>
                <?php endif; ?>
                
                <?php foreach($projects as $p): ?>
                    <?php 
                        $income = $p['total_income'];
                        $expense = $p['total_expense'];
                        $profit = $income - $expense; 
                        $badge_class = ($profit >= 0) ? 'bg-success' : 'bg-danger';
                        
                        $creator = !empty($p['creator_name']) ? htmlspecialchars($p['creator_name']) : '-';
                        $create_date = !empty($p['created_at']) ? date('d.m.Y', strtotime($p['created_at'])) : '';
                        
                        $row_class = ($p['status'] == 'completed') ? 'row-completed' : '';
                        $status_badge = ($p['status'] == 'active') ? '<span class="badge badge-status-active">Aktif</span>' : '<span class="badge badge-status-completed">Bitti</span>';
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><span class="badge <?php echo $badge_class; ?> fs-6"><?php echo guvenli_html($p['code']); ?></span></td>
                        <td><?php echo !empty($p['department_name']) ? '<span class="badge bg-light text-dark border">'.guvenli_html($p['department_name']).'</span>' : '-'; ?></td>
                        <td>
                            <strong><?php echo guvenli_html($p['name']); ?></strong>
                            <?php if(!empty($p['end_date'])): ?>
                                <br><small class="text-muted" style="font-size:0.75rem;">Bitiş: <?php echo date('d.m.Y', strtotime($p['end_date'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo guvenli_html($p['employer']); ?></td>
                        <td><?php echo date('d.m.Y', strtotime($p['start_date'])); ?></td>
                        
                        <td class="text-center">
                            <div class="fw-bold text-dark" style="font-size:0.85rem;"><?php echo $creator; ?></div>
                        </td>

                        <td class="text-end text-success"><?php echo number_format($income, 2, ',', '.'); ?></td>
                        <td class="text-end text-danger"><?php echo number_format($expense, 2, ',', '.'); ?></td>
                        <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($profit, 2, ',', '.'); ?>
                        </td>
                        <td class="text-center"><?php echo $status_badge; ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-info text-white" onclick='openReport(<?php echo $p['id']; ?>, <?php echo json_encode($p['code']); ?>, <?php echo json_encode($p['name']); ?>)' title="Rapor">
                                <i class="fa fa-chart-pie"></i>
                            </button>
                            
                            <button type="button" class="btn btn-sm btn-primary" onclick='openModal("edit", <?php echo json_encode($p); ?>)' title="Düzenle">
                                <i class="fa fa-edit"></i>
                            </button>
                            
                            <?php if(has_permission('delete_data')): ?>
                                <a href="projects.php?delete_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Silmek istediğinize emin misiniz?');" title="Sil">
                                    <i class="fa fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
                    </table>
                </div>

                <?php if($total_pages > 1): ?>
                <nav aria-label="Sayfalama" class="mt-3 border-top pt-3">
                    <ul class="pagination pagination-sm">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=1&search=<?php echo urlencode($search); ?>&filter_status=<?php echo $filter_status; ?>&filter_profit=<?php echo $filter_profit; ?>&sort_profit=<?php echo $sort_profit; ?>">İlk</a>
                        </li>

                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&filter_status=<?php echo $filter_status; ?>&filter_profit=<?php echo $filter_profit; ?>&sort_profit=<?php echo $sort_profit; ?>">Önceki</a>
                        </li>
                        
                        <li class="page-item disabled"><span class="page-link text-dark fw-bold">Sayfa <?php echo $page; ?> / <?php echo $total_pages; ?></span></li>

                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&filter_status=<?php echo $filter_status; ?>&filter_profit=<?php echo $filter_profit; ?>&sort_profit=<?php echo $sort_profit; ?>">Sonraki</a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center small text-muted mb-3">Toplam <?php echo $total_records; ?> kayıt bulundu.</div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

    <div class="modal fade" id="projectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="projectForm">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalTitle">Yeni İş / Tur Tanımla</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bölüm</label>
                                <select id="deptSelect" name="department_id" class="form-select" onchange="generateCode()">
                                    <option value="" data-short="">Seçiniz...</option>
                                    <?php foreach($departments as $d): 
                                        $short = strtoupper(substr(str_replace(['İ','ı','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'], ['I','i','S','s','G','g','U','u','O','o','C','c'], $d['name']), 0, 4));
                                    ?>
                                        <option value="<?php echo $d['id']; ?>" data-short="<?php echo $short; ?>"><?php echo guvenli_html($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>İşin Başlangıç Tarihi</label>
                                <input type="date" id="dateInput" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required onchange="generateCode()">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label>Organizasyon Temsilcisi</label>
                            <input type="text" id="employerInput" name="employer" class="form-control" oninput="generateCode()">
                        </div>
                        <div class="mb-3">
                            <label>İş Adı</label>
                            <input type="text" id="nameInput" name="name" class="form-control" required oninput="generateCode()">
                        </div>
                        <div class="mb-3">
                            <label>Tur Kodu</label>
                            <div class="input-group">
                                <input type="text" id="codeInput" name="code" class="form-control fw-bold" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="generateCode()"><i class="fa fa-sync"></i></button>
                            </div>
                        </div>

                        <hr>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Durum</label>
                                <select name="status" id="statusSelect" class="form-select" onchange="toggleEndDate()">
                                    <option value="active">Aktif (Devam Ediyor)</option>
                                    <option value="completed">Tamamlandı (Bitti)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Bitiş Tarihi (Tahmini/Gerçek)</label>
                                <input type="date" name="end_date" id="endDateInput" class="form-control">
                            </div>
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

    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-xl"> <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="reportModalTitle"><i class="fa fa-chart-line me-2"></i> Proje Finansal Raporu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="reportContent">
                    <div class="text-center p-5"><div class="spinner-border text-primary"></div><p>Rapor Hazırlanıyor...</p></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        var projectModal = new bootstrap.Modal(document.getElementById('projectModal'));
        var reportModal = new bootstrap.Modal(document.getElementById('reportModal'));
        
        var currentProjectCode = "";
        var currentProjectName = "";

        function openReport(id, code, name) {
            currentProjectCode = code;
            currentProjectName = name;
            document.getElementById('reportModalTitle').innerHTML = '<i class="fa fa-chart-line me-2"></i> ' + code + ' - ' + name + ' Finansal Raporu';
            document.getElementById('reportContent').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p>Rapor Hazırlanıyor...</p></div>';
            reportModal.show();
            fetch('get-project-report.php?id=' + id)
                .then(res => res.text())
                .then(data => {
                    document.getElementById('reportContent').innerHTML = data;
                    var scripts = document.getElementById('reportContent').getElementsByTagName("script");
                    for(var i=0; i<scripts.length; i++) { eval(scripts[i].innerText); }
                });
        }

        // Export Fonksiyonları (SheetJS ile mevcut tabloyu indirir)
        // NOT: Buradaki export sadece ekrandaki 100 kaydı indirir. Toplu indirme yukarıda ayrı.
        var bulkProjectsData = [
            ["Kod", "Bölüm", "Etkinlik / İş Adı", "Organizasyon Temsilcisi", "İşin Tarihi", "Gelir", "Gider", "Kâr/Zarar", "Durum"]
        ];

        <?php foreach($projects as $p): 
            $inc = $p['total_income'] ?? 0;
            $exp = $p['total_expense'] ?? 0;
            $prof = $inc - $exp;
            $stat = $p['status'] == 'active' ? 'Aktif' : 'Tamamlandı';
        ?>
        bulkProjectsData.push([
            "<?php echo guvenli_html($p['code']); ?>",
            "<?php echo guvenli_html($p['department_name'] ?? '-'); ?>",
            "<?php echo addslashes($p['name']); ?>",
            "<?php echo addslashes($p['employer']); ?>",
            "<?php echo date('d.m.Y', strtotime($p['start_date'])); ?>",
            <?php echo $inc; ?>,
            <?php echo $exp; ?>,
            <?php echo $prof; ?>,
            "<?php echo $stat; ?>"
        ]);
        <?php endforeach; ?>

        function exportBulkToExcel() {
            if(bulkProjectsData.length <= 1) { alert("İndirilecek kayıt bulunamadı."); return; }
            var ws = XLSX.utils.aoa_to_sheet(bulkProjectsData);
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Projeler");
            XLSX.writeFile(wb, "Projeler_Listesi.xlsx");
        }

        function openModal(mode, data = null) {
            document.getElementById('projectForm').reset();
            if (mode === 'edit' && data) {
                document.getElementById('modalTitle').innerText = "Projeyi Düzenle";
                document.getElementById('edit_id').value = data.id;
                document.getElementById('nameInput').value = data.name;
                document.getElementById('employerInput').value = data.employer; 
                document.getElementById('codeInput').value = data.code;
                document.getElementById('dateInput').value = data.start_date;
                if(data.department_id) document.getElementById('deptSelect').value = data.department_id;
                
                // Yeni Alanlar
                document.getElementById('statusSelect').value = data.status;
                document.getElementById('endDateInput').value = data.end_date;

            } else {
                document.getElementById('modalTitle').innerText = "Yeni İş / Tur Tanımla";
                document.getElementById('edit_id').value = "";
                document.getElementById('dateInput').value = new Date().toISOString().split('T')[0];
                document.getElementById('deptSelect').value = "";
                document.getElementById('statusSelect').value = "active";
            }
            toggleEndDate();
            projectModal.show();
        }

        // Durum değişince bitiş tarihi kontrolü
        function toggleEndDate() {
            var st = document.getElementById('statusSelect').value;
            var ed = document.getElementById('endDateInput');
            if (st === 'completed' && !ed.value) {
                // Tamamlandı seçilirse ve tarih boşsa bugünü ata
                ed.value = new Date().toISOString().split('T')[0];
            }
        }

        function generateCode() {
            let dateVal = document.getElementById('dateInput').value; 
            let datePart = "000000";
            if(dateVal) {
                let d = new Date(dateVal);
                let day = ("0" + d.getDate()).slice(-2);
                let month = ("0" + (d.getMonth() + 1)).slice(-2);
                let year = d.getFullYear().toString().substring(2);
                datePart = day + month + year;
            }

            let deptSelect = document.getElementById('deptSelect');
            let deptPart = "";
            if (deptSelect.selectedIndex > 0) {
                deptPart = "-" + deptSelect.options[deptSelect.selectedIndex].getAttribute('data-short');
            }

            function getShortCode(text) {
                if (!text) return "";
                let clean = text.toUpperCase().replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C');
                let words = clean.trim().split(/\s+/);
                let result = "";
                if (words.length === 1) {
                    let word = words[0];
                    let numbers = word.replace(/[^0-9]/g, ''); 
                    let letters = word.replace(/[0-9]/g, '').substring(0, 4);
                    result = letters + numbers;
                } else {
                    words.forEach(word => {
                        if (!isNaN(word)) {
                            if (word.length === 4 && (word.startsWith("19") || word.startsWith("20"))) { result += word.substring(2); } 
                            else { result += word; }
                        } else if(word.length > 1 && word !== 'VE' && word !== 'ILE') { 
                            result += word.substring(0,1);
                        }
                    });
                }
                return result;
            }

            let empPart = getShortCode(document.getElementById('employerInput').value);
            if(empPart) empPart = "-" + empPart;
            let namePart = getShortCode(document.getElementById('nameInput').value);
            if(namePart) namePart = "-" + namePart;

            document.getElementById('codeInput').value = datePart + empPart + namePart + deptPart;
        }
    </script>
</body>
</html>