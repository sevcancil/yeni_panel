<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$message = '';

// --- İŞLEM 1: SİLME ---
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
            log_action($pdo, 'project', $del_id, 'delete', $del_rec['name'] . " (" . $del_rec['code'] . ") projesi silindi.");
            header("Location: projects.php?msg=deleted");
            exit;
        }
    } else {
        $message = '<div class="alert alert-danger">Silme yetkiniz yok!</div>';
    }
}

// --- İŞLEM 2: EKLEME VE GÜNCELLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = temizle($_POST['code']);
    $name = temizle($_POST['name']);
    $employer = temizle($_POST['employer']);
    $start_date = $_POST['start_date'];
    
    $is_edit = isset($_POST['edit_id']) && !empty($_POST['edit_id']);
    $edit_id = $is_edit ? (int)$_POST['edit_id'] : 0;

    $sql_check = "SELECT id FROM tour_codes WHERE code = ?";
    $params_check = [$code];
    
    if ($is_edit) {
        $sql_check .= " AND id != ?";
        $params_check[] = $edit_id;
    }
    
    $check = $pdo->prepare($sql_check);
    $check->execute($params_check);

    if ($check->rowCount() > 0) {
        $message = '<div class="alert alert-danger">Bu Tur Kodu zaten kullanılıyor!</div>';
    } else {
        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE tour_codes SET code = ?, name = ?, employer = ?, start_date = ? WHERE id = ?");
            $stmt->execute([$code, $name, $employer, $start_date, $edit_id]);
            log_action($pdo, 'project', $edit_id, 'update', "$code - $name projesi güncellendi.");
            $message = '<div class="alert alert-success">Proje güncellendi!</div>';
        } else {
            $stmt = $pdo->prepare("INSERT INTO tour_codes (code, name, employer, start_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name, $employer, $start_date]);
            log_action($pdo, 'project', $pdo->lastInsertId(), 'create', "$code - $name yeni projesi oluşturuldu.");
            $message = '<div class="alert alert-success">Yeni proje oluşturuldu!</div>';
        }
    }
}

$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();

$sql = "SELECT t.*, 
        (SELECT SUM(amount) FROM transactions WHERE tour_code_id = t.id AND type = 'credit') as total_income,
        (SELECT SUM(amount) FROM transactions WHERE tour_code_id = t.id AND type = 'debt') as total_expense
        FROM tour_codes t 
        ORDER BY t.start_date DESC";
$projects = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Projeler / Tur Kodları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        @media print {
            body * { visibility: hidden; }
            #reportModal, #reportModal * { visibility: visible; }
            #reportModal { position: absolute; left: 0; top: 0; width: 100%; height: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Tur Kodları / Projeler</h2>
            <button type="button" class="btn btn-success" onclick="openModal('add')">
                <i class="fa fa-magic"></i> Yeni İş Tanımla
            </button>
        </div>

        <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Proje silindi.</div>'; echo $message; ?>

        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Kod</th>
                                <th>Etkinlik / İş Adı</th>
                                <th>Organizasyon Temsilcisi</th>
                                <th>İşin Tarih</th>
                                <th class="text-end">Gelir</th>
                                <th class="text-end">Gider</th>
                                <th class="text-end">Kâr/Zarar</th>
                                <th class="text-center">Durum</th>
                                <th class="text-center" width="180">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                <?php foreach($projects as $p): ?>
                    <?php 
                        $income = $p['total_income'] ?? 0;
                        $expense = $p['total_expense'] ?? 0;
                        $profit = $income - $expense; 
                        
                        // RENK BELİRLEME
                        // Kâr >= 0 ise Yeşil (success), Zarar ise Kırmızı (danger)
                        $badge_class = ($profit >= 0) ? 'bg-success' : 'bg-danger';
                    ?>
                    <tr>
                        <td>
                            <span class="badge <?php echo $badge_class; ?> fs-6">
                                <?php echo guvenli_html($p['code']); ?>
                            </span>
                        </td>
                        <td><strong><?php echo guvenli_html($p['name']); ?></strong></td>
                        <td><?php echo guvenli_html($p['employer']); ?></td>
                        <td><?php echo date('d.m.Y', strtotime($p['start_date'])); ?></td>
                        
                        <td class="text-end text-success"><?php echo number_format($income, 2, ',', '.'); ?> ₺</td>
                        <td class="text-end text-danger"><?php echo number_format($expense, 2, ',', '.'); ?> ₺</td>
                        
                        <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($profit, 2, ',', '.'); ?> ₺
                        </td>
                        
                        <td class="text-center">
                            <?php if($p['status'] == 'active'): ?>
                                <span class="badge bg-info text-dark">Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Tamamlandı</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-info text-white" onclick="openReport(<?php echo $p['id']; ?>)" title="Finansal Rapor">
                                <i class="fa fa-chart-pie"></i>
                            </button>
                            
                            <button type="button" class="btn btn-sm btn-primary" onclick='openModal("edit", <?php echo json_encode($p); ?>)' title="Düzenle">
                                <i class="fa fa-edit"></i>
                            </button>
                            
                            <?php if(has_permission('delete_data')): ?>
                                <a href="projects.php?delete_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Projeyi silmek istediğinize emin misiniz?');" title="Sil">
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
                        
                        <div class="alert alert-info small">
                            <i class="fa fa-info-circle"></i> Tur kodu formatı: <b>Etkinlik Tarihi - Org.Temsilcisi - İş Adı - Bölüm</b>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bölüm</label>
                                <select id="deptSelect" class="form-select" onchange="generateCode()">
                                    <option value="" data-short="">Seçiniz...</option>
                                    <?php foreach($departments as $d): 
                                        $short = strtoupper(substr(str_replace(['İ','ı','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'], ['I','i','S','s','G','g','U','u','O','o','C','c'], $d['name']), 0, 4));
                                    ?>
                                        <option value="<?php echo $d['id']; ?>" data-short="<?php echo $short; ?>"><?php echo guvenli_html($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>İşin Tarihi</label>
                                <input type="date" id="dateInput" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required onchange="generateCode()">
                            </div>
                        </div>
                        <div class="mb-3"><label>Organizasyon Temsilcisi</label><input type="text" id="employerInput" name="employer" class="form-control" oninput="generateCode()"></div>
                        <div class="mb-3"><label>İş Adı</label><input type="text" id="nameInput" name="name" class="form-control" required oninput="generateCode()"></div>
                        <div class="mb-3"><label>Tur Kodu</label><div class="input-group"><input type="text" id="codeInput" name="code" class="form-control fw-bold" required><button type="button" class="btn btn-outline-secondary" onclick="generateCode()"><i class="fa fa-sync"></i></button></div></div>
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fa fa-chart-line me-2"></i> Proje Finansal Raporu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="reportContent">
                    <div class="text-center p-5"><div class="spinner-border text-primary"></div><p>Rapor Hazırlanıyor...</p></div>
                </div>
                <div class="modal-footer no-print">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        var projectModal = new bootstrap.Modal(document.getElementById('projectModal'));
        var reportModal = new bootstrap.Modal(document.getElementById('reportModal'));

        function openReport(id) {
            reportModal.show();
            fetch('get-project-report.php?id=' + id)
                .then(res => res.text())
                .then(data => {
                    document.getElementById('reportContent').innerHTML = data;
                    var scripts = document.getElementById('reportContent').getElementsByTagName("script");
                    for(var i=0; i<scripts.length; i++) { eval(scripts[i].innerText); }
                });
        }

        function exportTableToExcel(tableID, filename = ''){
            var downloadLink;
            var dataType = 'application/vnd.ms-excel';
            var tableSelect = document.getElementById(tableID);
            var tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
            
            filename = filename?filename+'.xls':'excel_data.xls';
            downloadLink = document.createElement("a");
            document.body.appendChild(downloadLink);
            
            if(navigator.msSaveOrOpenBlob){
                var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
                navigator.msSaveOrOpenBlob( blob, filename);
            }else{
                downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
                downloadLink.download = filename;
                downloadLink.click();
            }
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
            } else {
                document.getElementById('modalTitle').innerText = "Yeni İş / Tur Tanımla";
                document.getElementById('edit_id').value = "";
                document.getElementById('dateInput').value = new Date().toISOString().split('T')[0];
            }
            projectModal.show();
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