<?php
// public/customers.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$message = '';

// --- İŞLEM 1: SİLME ---
if (isset($_GET['delete_id'])) {
    if(has_permission('delete_data')) {
        $del_id = (int)$_GET['delete_id'];
        
        $stmt = $pdo->prepare("SELECT company_name FROM customers WHERE id = ?");
        $stmt->execute([$del_id]);
        $del_name = $stmt->fetchColumn();

        $check = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE customer_id = ?");
        $check->execute([$del_id]);
        
        if ($check->fetchColumn() > 0) {
            $message = '<div class="alert alert-danger">Bu cariye ait hareketler var! Silemezsiniz.</div>';
        } else {
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$del_id]);
            log_action($pdo, 'customer', $del_id, 'delete', "$del_name carisi silindi.");
            header("Location: customers.php?msg=deleted");
            exit;
        }
    } else {
        $message = '<div class="alert alert-danger">Silme yetkiniz yok!</div>';
    }
}

// --- İŞLEM 2: EKLEME VE GÜNCELLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verileri Al
    $type = $_POST['customer_type']; 
    $title = temizle($_POST['company_name']); 
    $code = temizle($_POST['customer_code']);
    $is_temporary = isset($_POST['is_temporary']) ? 1 : 0; 
    
    // Kimlik Verileri
    $tc = !empty($_POST['tc_number']) ? temizle($_POST['tc_number']) : null;
    $passport = !empty($_POST['passport_number']) ? temizle($_POST['passport_number']) : null;
    $tax_office = temizle($_POST['tax_office']);
    $tax_number = !empty($_POST['tax_number']) ? temizle($_POST['tax_number']) : null;
    
    // GEÇİCİ KAYIT MANTIĞI
    if ($is_temporary) {
        $random_suffix = time() . rand(100,999);
        if ($type == 'real' && empty($tc) && empty($passport)) { $tc = 'G-TC-' . $random_suffix; } 
        if ($type == 'legal' && empty($tax_number)) { $tax_number = 'G-VN-' . $random_suffix; }
    }

    // Diğerleri
    $contact = temizle($_POST['contact_name']);
    $email = temizle($_POST['email']);
    $phone = temizle($_POST['phone']);
    $fax = temizle($_POST['fax']);
    $country = temizle($_POST['country']);
    $city = temizle($_POST['city']);
    $address = temizle($_POST['address']);
    
    // YETKİ KONTROLÜ
    $has_balance_perm = (isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'muhasebe'));
    $op_balance = ($has_balance_perm && !empty($_POST['opening_balance'])) ? (float)$_POST['opening_balance'] : 0;
    $op_curr = ($has_balance_perm && !empty($_POST['opening_balance_currency'])) ? $_POST['opening_balance_currency'] : 'TRY';
    $op_date = ($has_balance_perm && !empty($_POST['opening_balance_date'])) ? $_POST['opening_balance_date'] : date('Y-m-d');

    // MÜKERRER KONTROLÜ
    $duplicate_error = false;
    $is_edit = isset($_POST['edit_id']) && !empty($_POST['edit_id']);
    $edit_id = $is_edit ? (int)$_POST['edit_id'] : 0;

    $sql_check = "SELECT id FROM customers WHERE ";
    $params_check = [];
    $check_active = false;

    if ($type == 'legal' && !empty($tax_number) && strpos($tax_number, 'G-VN-') === false) {
        $sql_check .= "tax_number = ?";
        $params_check[] = $tax_number;
        $check_active = true;
    } elseif ($type == 'real') {
        if (!empty($tc) && strpos($tc, 'G-TC-') === false) {
            $sql_check .= "tc_number = ?";
            $params_check[] = $tc;
            $check_active = true;
        } elseif (!empty($passport)) {
            $sql_check .= "passport_number = ?";
            $params_check[] = $passport;
            $check_active = true;
        }
    }

    if ($check_active) {
        if ($is_edit) {
            $sql_check .= " AND id != ?";
            $params_check[] = $edit_id;
        }
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute($params_check);
        if ($stmt_check->rowCount() > 0) {
            $duplicate_error = true;
            $message = '<div class="alert alert-danger">HATA: Bu Kimlik/Vergi/Pasaport numarası ile kayıtlı başka bir cari var!</div>';
        }
    }

    if (!$duplicate_error && !empty($title)) {
        if ($is_edit) {
            // GÜNCELLEME
            $sql = "UPDATE customers SET 
                    customer_type=?, customer_code=?, company_name=?, contact_name=?, 
                    tc_number=?, passport_number=?, tax_office=?, tax_number=?, 
                    email=?, phone=?, fax=?, country=?, city=?, address=?
                    WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $type, $code, $title, $contact, $tc, $passport, $tax_office, $tax_number,
                $email, $phone, $fax, $country, $city, $address, $edit_id
            ]);
            log_action($pdo, 'customer', $edit_id, 'update', "$title carisi güncellendi.");
            $message = '<div class="alert alert-success">Cari kart güncellendi!</div>';
        } else {
            // YENİ EKLEME
            $current_balance = $op_balance; 
            $created_by = $_SESSION['user_id'];

            $sql = "INSERT INTO customers (
                customer_type, customer_code, company_name, contact_name, 
                tc_number, passport_number, tax_office, tax_number, 
                email, phone, fax, country, city, address, 
                opening_balance, opening_balance_currency, opening_balance_date, current_balance,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $type, $code, $title, $contact, $tc, $passport, $tax_office, $tax_number,
                $email, $phone, $fax, $country, $city, $address,
                $op_balance, $op_curr, $op_date, $current_balance,
                $created_by
            ]);
            
            $last_id = $pdo->lastInsertId();
            log_action($pdo, 'customer', $last_id, 'create', "$title ($code) yeni cari kartı oluşturuldu.");
            $message = '<div class="alert alert-success">Yeni cari kart oluşturuldu!</div>';
        }
    } elseif (empty($title)) {
        $message = '<div class="alert alert-danger">Cari Başlık/Ünvan zorunludur.</div>';
    }
}

// --- LİSTELEME VE ARAMA MANTIĞI (YENİLENDİ) ---
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$params = [];

// Canlı Bakiye Sorgusu:
// Açılış Bakiyesi + (Toplam Tahsilat - Toplam Ödeme)
$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM transactions WHERE customer_id = c.id) as tx_count,
        (
            c.opening_balance 
            + COALESCE((SELECT SUM(amount) FROM transactions WHERE customer_id = c.id AND type = 'credit'), 0) 
            - COALESCE((SELECT SUM(amount) FROM transactions WHERE customer_id = c.id AND type = 'debt'), 0)
        ) as live_balance 
        FROM customers c";

if ($search) {
    $sql .= " WHERE (c.company_name LIKE ? OR c.contact_name LIKE ? OR c.tax_number LIKE ? OR c.tc_number LIKE ? OR c.customer_code LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"];
}

$sql .= " ORDER BY c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Cari Kartlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .missing-info {
            background-color: #fff3cd; /* Sarımsı arka plan */
            border-left: 5px solid #ffc107;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Cari Kartlar</h2>
            <button type="button" class="btn btn-success" onclick="openModal('add')">
                <i class="fa fa-plus"></i> Yeni Cari Ekle
            </button>
        </div>

        <?php 
            if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Cari kart silindi.</div>';
            echo $message; 
        ?>

        <div class="card mb-3 shadow-sm border-0">
            <div class="card-body p-2 bg-light rounded">
                <form method="GET" action="customers.php" class="row g-2 align-items-center">
                    <div class="col-md-10">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fa fa-search text-muted"></i></span>
                            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Cari Ünvan, Kod, Vergi No, İsim ile ara..." value="<?php echo guvenli_html($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-2 d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary flex-grow-1">Ara</button>
                        <?php if($search): ?>
                            <a href="customers.php" class="btn btn-outline-secondary">Temizle</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Kod</th>
                                <th>Tür</th>
                                <th>Cari Başlık / Ünvan</th>
                                <th class="text-center">İşlem Adedi</th>
                                <th class="text-end">Bakiye</th>
                                <th class="text-center" width="200">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($customers) > 0): ?>
                                <?php foreach ($customers as $c): ?>
                                    
                                    <?php 
                                        $is_missing = false;
                                        if (strpos($c['tc_number'], 'G-TC-') !== false || strpos($c['tax_number'], 'G-VN-') !== false) {
                                            $is_missing = true;
                                        }
                                        $row_class = $is_missing ? 'missing-info' : '';
                                        
                                        // Canlı Bakiye Rengi
                                        $bal_val = $c['live_balance'];
                                        $bal_color = ($bal_val < 0) ? 'text-danger' : (($bal_val > 0) ? 'text-success' : 'text-muted');
                                    ?>

                                    <tr class="<?php echo $row_class; ?>">
                                        <td><span class="badge bg-secondary"><?php echo guvenli_html($c['customer_code']); ?></span></td>
                                        <td>
                                            <?php if($c['customer_type'] == 'real'): ?>
                                                <i class="fa fa-user text-primary" title="Gerçek Kişi"></i>
                                            <?php else: ?>
                                                <i class="fa fa-building text-success" title="Tüzel Kişi"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo guvenli_html($c['company_name']); ?></strong>
                                            
                                            <?php if($is_missing): ?>
                                                <br><span class="badge bg-warning text-dark"><i class="fa fa-exclamation-triangle"></i> EKSİK BİLGİ (Geçici Kayıt)</span>
                                            <?php endif; ?>

                                            <?php if(!empty($c['passport_number'])): ?>
                                                <br><small class="text-muted"><i class="fa fa-globe"></i> Yabancı Uyruklu</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $c['tx_count']; ?></span></td>
                                        
                                        <td class="text-end fw-bold <?php echo $bal_color; ?>">
                                            <?php echo number_format($bal_val, 2, ',', '.'); ?> ₺
                                        </td>
                                        
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="showHistory(<?php echo $c['id']; ?>, '<?php echo guvenli_html($c['company_name']); ?>')" title="Geçmiş">
                                                <i class="fa fa-history"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary" onclick='openModal("edit", <?php echo json_encode($c); ?>)' title="Düzenle">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <a href="customer-details.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-info text-white" title="Ekstre">
                                                <i class="fa fa-list"></i>
                                            </a>
                                            <?php if(has_permission('delete_data')): ?>
                                                <a href="customers.php?delete_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Silmek istediğinize emin misiniz?');">
                                                    <i class="fa fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center p-3 text-muted">Kayıt bulunamadı.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="fa fa-history me-2"></i> İşlem Geçmişi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="text-center fw-bold text-primary mb-3" id="historyTargetName"></h6>
                    <div id="historyContent" class="text-center">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="customerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="customerForm">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="modalTitle">Yeni Cari Kart</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id">
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Cari Türü</label>
                                <select name="customer_type" id="customer_type" class="form-select" onchange="toggleTypeFields(); generateCustomerCode();">
                                    <option value="legal">Tüzel Kişi (Şirket)</option>
                                    <option value="real">Gerçek Kişi (Şahıs)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cari Kodu <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" name="customer_code" id="customer_code" class="form-control fw-bold bg-light" readonly required placeholder="Otomatik">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateCustomerCode()" title="Yeniden Oluştur">
                                        <i class="fa fa-sync"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Cari Başlık / Ünvan *</label>
                                <input type="text" name="company_name" id="company_name" class="form-control" required placeholder="Firma Adı veya Ad Soyad" onblur="generateCustomerCode()">
                            </div>
                        </div>

                        <hr>

                        <h6 class="text-primary"><i class="fa fa-id-card"></i> Kimlik & Vergi Bilgileri</h6>
                        
                        <div class="alert alert-warning d-flex align-items-center mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_temporary" id="is_temporary" onchange="toggleTemporary()">
                                <label class="form-check-label fw-bold" for="is_temporary">
                                    Bilgiler Eksik / Geçici Kayıt Oluştur
                                </label>
                            </div>
                            <div class="ms-3 small">
                                <i class="fa fa-info-circle"></i> Eğer TC veya Vergi No henüz gelmediyse bunu işaretleyin.
                            </div>
                        </div>

                        <div class="row mb-3 p-3 bg-light rounded border">
                            <div class="col-md-6 legal-field">
                                <label class="form-label">Vergi Dairesi</label>
                                <input type="text" name="tax_office" id="tax_office" class="form-control">
                            </div>
                            <div class="col-md-6 legal-field">
                                <label class="form-label fw-bold text-danger">Vergi No *</label>
                                <input type="text" name="tax_number" id="tax_number" class="form-control" placeholder="10 Haneli">
                            </div>

                            <div class="col-12 real-field d-none mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_foreigner" onchange="toggleForeigner()">
                                    <label class="form-check-label fw-bold" for="is_foreigner">
                                        Bu kişi Yabancı Uyruklu (TC Kimlik No yok)
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-6 real-field tr-citizen d-none">
                                <label class="form-label fw-bold text-danger">TC Kimlik No *</label>
                                <input type="text" name="tc_number" id="tc_number" class="form-control" maxlength="11" placeholder="11 Haneli">
                            </div>

                            <div class="col-md-6 real-field foreigner d-none">
                                <label class="form-label fw-bold text-danger">Pasaport No *</label>
                                <input type="text" name="passport_number" id="passport_number" class="form-control">
                            </div>
                        </div>

                        <h6 class="text-primary"><i class="fa fa-address-book"></i> İletişim</h6>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label>Yetkili Kişi</label>
                                <input type="text" name="contact_name" id="contact_name" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label>E-Posta</label>
                                <input type="email" name="email" id="email" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label>Telefon</label>
                                <input type="text" name="phone" id="phone" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label>Fax</label>
                                <input type="text" name="fax" id="fax" class="form-control">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="fw-bold">Ülke <span class="text-danger">*</span></label>
                                <input type="text" name="country" id="country" class="form-control" value="Türkiye" required>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">Şehir <span class="text-danger">*</span></label>
                                <input type="text" name="city" id="city" class="form-control" value="İstanbul" required>
                            </div>
                            <div class="col-md-6">
                                <label>Adres</label>
                                <textarea name="address" id="address" class="form-control" rows="1"></textarea>
                            </div>
                        </div>

                        <?php if(isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'muhasebe')): ?>
                            <hr>
                            <h6 class="text-primary"><i class="fa fa-balance-scale"></i> Açılış / Devir</h6>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label>Devir Bakiyesi</label>
                                    <input type="number" step="0.01" name="opening_balance" id="opening_balance" class="form-control" value="0.00">
                                    <small class="text-muted">Borçlu (+), Alacaklı (-)</small>
                                </div>
                                <div class="col-md-4">
                                    <label>Döviz</label>
                                    <select name="opening_balance_currency" id="opening_balance_currency" class="form-select">
                                        <option value="TRY">TL</option>
                                        <option value="USD">USD</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label>Tarih</label>
                                    <input type="date" name="opening_balance_date" id="opening_balance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        <?php endif; ?>

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
        var modal = new bootstrap.Modal(document.getElementById('customerModal'));
        var historyModal = new bootstrap.Modal(document.getElementById('historyModal'));

        function showHistory(id, name) {
            document.getElementById('historyTargetName').innerText = name + " - Geçmiş İşlemler";
            document.getElementById('historyContent').innerHTML = '<div class="spinner-border text-primary" role="status"></div>';
            historyModal.show();

            var formData = new FormData();
            formData.append('module', 'customer');
            formData.append('record_id', id);

            fetch('get-log-history.php', { method: 'POST', body: formData })
            .then(response => response.text())
            .then(data => { document.getElementById('historyContent').innerHTML = data; })
            .catch(error => { document.getElementById('historyContent').innerHTML = '<div class="alert alert-danger">Veri çekilemedi.</div>'; });
        }

        // --- CARİ KOD ÜRETME ---
        function generateCustomerCode() {
            var name = document.getElementById('company_name').value;
            var type = document.getElementById('customer_type').value; 
            
            if (name.length < 2) return;

            var formData = new FormData();
            formData.append('name', name);
            formData.append('type', type);
            
            fetch('api-generate-code.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('customer_code').value = data.code;
                    }
                });
        }

        // --- GEÇİCİ KAYIT KONTROLÜ ---
        function toggleTemporary() {
            var isTemp = document.getElementById('is_temporary').checked;
            var taxInput = document.getElementById('tax_number');
            var tcInput = document.getElementById('tc_number');
            var passInput = document.getElementById('passport_number');

            if (isTemp) {
                taxInput.required = false;
                tcInput.required = false;
                passInput.required = false;
                taxInput.placeholder = "Otomatik Geçici No Atanacak";
                tcInput.placeholder = "Otomatik Geçici No Atanacak";
            } else {
                taxInput.placeholder = "10 Haneli";
                tcInput.placeholder = "11 Haneli";
                toggleTypeFields();
            }
        }

        function toggleTypeFields() {
            if(document.getElementById('is_temporary').checked) return;

            var type = document.getElementById('customer_type').value;
            var legalFields = document.querySelectorAll('.legal-field');
            var realFields = document.querySelectorAll('.real-field');

            if (type === 'real') {
                legalFields.forEach(el => el.classList.add('d-none'));
                realFields.forEach(el => el.classList.remove('d-none'));
                toggleForeigner();
                document.getElementById('tax_number').required = false;
            } else {
                legalFields.forEach(el => el.classList.remove('d-none'));
                realFields.forEach(el => el.classList.add('d-none'));
                document.getElementById('tax_number').required = true;
                document.getElementById('tc_number').required = false;
                document.getElementById('passport_number').required = false;
            }
        }

        function toggleForeigner() {
            if(document.getElementById('is_temporary').checked) return;

            var isForeigner = document.getElementById('is_foreigner').checked;
            var trFields = document.querySelectorAll('.tr-citizen');
            var foreignFields = document.querySelectorAll('.foreigner');

            if (isForeigner) {
                trFields.forEach(el => el.classList.add('d-none'));
                foreignFields.forEach(el => el.classList.remove('d-none'));
                document.getElementById('passport_number').required = true;
                document.getElementById('tc_number').required = false;
                document.getElementById('tc_number').value = '';
            } else {
                trFields.forEach(el => el.classList.remove('d-none'));
                foreignFields.forEach(el => el.classList.add('d-none'));
                document.getElementById('tc_number').required = true;
                document.getElementById('passport_number').required = false;
                document.getElementById('passport_number').value = '';
            }
        }

        function openModal(mode, data = null) {
            document.getElementById('customerForm').reset();
            document.getElementById('is_foreigner').checked = false;
            document.getElementById('is_temporary').checked = false;
            
            if (mode === 'edit' && data) {
                document.getElementById('modalTitle').innerText = "Cari Kartı Düzenle";
                document.getElementById('edit_id').value = data.id;
                
                document.getElementById('customer_type').value = data.customer_type;
                document.getElementById('customer_code').value = data.customer_code;
                document.getElementById('company_name').value = data.company_name;
                document.getElementById('contact_name').value = data.contact_name;
                
                if ((data.tc_number && data.tc_number.includes('G-TC-')) || 
                    (data.tax_number && data.tax_number.includes('G-VN-'))) {
                    document.getElementById('is_temporary').checked = true;
                }

                if (data.customer_type === 'real' && data.passport_number) {
                    document.getElementById('is_foreigner').checked = true;
                }

                document.getElementById('tc_number').value = data.tc_number;
                document.getElementById('passport_number').value = data.passport_number;
                document.getElementById('tax_office').value = data.tax_office;
                document.getElementById('tax_number').value = data.tax_number;
                document.getElementById('email').value = data.email;
                document.getElementById('phone').value = data.phone;
                document.getElementById('fax').value = data.fax;
                document.getElementById('country').value = data.country;
                document.getElementById('city').value = data.city;
                document.getElementById('address').value = data.address;
                
                // YETKİ KONTROLÜ: EĞER ALANLAR VARSA DOLDUR (YOKSA HATA VERMEZ)
                if(document.getElementById('opening_balance')) {
                    document.getElementById('opening_balance').value = data.opening_balance;
                    document.getElementById('opening_balance_currency').value = data.opening_balance_currency;
                    document.getElementById('opening_balance_date').value = data.opening_balance_date;
                }

            } else {
                document.getElementById('modalTitle').innerText = "Yeni Cari Kart Ekle";
                document.getElementById('edit_id').value = "";
                document.getElementById('country').value = "Türkiye";
                document.getElementById('city').value = "İstanbul";
            }
            
            toggleTypeFields();
            toggleTemporary(); 
            modal.show();
        }
    </script>
    
    <?php
    if (isset($_GET['edit_id'])) {
        $edit_id = (int)$_GET['edit_id'];
        $stmt_edit = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt_edit->execute([$edit_id]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);

        if ($edit_data) {
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var customerData = <?php echo json_encode($edit_data); ?>;
                    openModal('edit', customerData);
                    window.history.replaceState({}, document.title, "customers.php");
                });
            </script>
            <?php
        }
    }
    ?>
</body>
</html>