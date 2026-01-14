<?php
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
        $check = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE customer_id = ?");
        $check->execute([$del_id]);
        if ($check->fetchColumn() > 0) {
            $message = '<div class="alert alert-danger">Bu cariye ait hareketler var! Silemezsiniz.</div>';
        } else {
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$del_id]);
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
    
    // Kimlik Verileri
    $tc = !empty($_POST['tc_number']) ? temizle($_POST['tc_number']) : null;
    $passport = !empty($_POST['passport_number']) ? temizle($_POST['passport_number']) : null;
    $tax_office = temizle($_POST['tax_office']);
    $tax_number = !empty($_POST['tax_number']) ? temizle($_POST['tax_number']) : null;
    
    // Diğerleri
    $contact = temizle($_POST['contact_name']);
    $email = temizle($_POST['email']);
    $phone = temizle($_POST['phone']);
    $fax = temizle($_POST['fax']);
    $country = temizle($_POST['country']);
    $city = temizle($_POST['city']);
    $address = temizle($_POST['address']);
    
    $op_balance = !empty($_POST['opening_balance']) ? (float)$_POST['opening_balance'] : 0;
    $op_curr = $_POST['opening_balance_currency'];
    $op_date = !empty($_POST['opening_balance_date']) ? $_POST['opening_balance_date'] : date('Y-m-d');

    // MÜKERRER KONTROLÜ
    $duplicate_error = false;
    $is_edit = isset($_POST['edit_id']) && !empty($_POST['edit_id']);
    $edit_id = $is_edit ? (int)$_POST['edit_id'] : 0;

    $sql_check = "SELECT id FROM customers WHERE ";
    $params_check = [];
    $check_active = false;

    // Kural: Tüzel ise Vergi No, Gerçek (TC'li) ise TC, Yabancı ise Pasaport kontrol et
    if ($type == 'legal' && !empty($tax_number)) {
        $sql_check .= "tax_number = ?";
        $params_check[] = $tax_number;
        $check_active = true;
    } elseif ($type == 'real') {
        if (!empty($tc)) {
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
            $message = '<div class="alert alert-success">Cari kart güncellendi!</div>';
        } else {
            // YENİ EKLEME
            $current_balance = $op_balance; 
            $sql = "INSERT INTO customers (
                customer_type, customer_code, company_name, contact_name, 
                tc_number, passport_number, tax_office, tax_number, 
                email, phone, fax, country, city, address, 
                opening_balance, opening_balance_currency, opening_balance_date, current_balance
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $type, $code, $title, $contact, $tc, $passport, $tax_office, $tax_number,
                $email, $phone, $fax, $country, $city, $address,
                $op_balance, $op_curr, $op_date, $current_balance
            ]);
            $message = '<div class="alert alert-success">Yeni cari kart oluşturuldu!</div>';
        }
    } elseif (empty($title)) {
        $message = '<div class="alert alert-danger">Cari Başlık/Ünvan zorunludur.</div>';
    }
}

// LİSTELEME
$sql = "SELECT c.*, (SELECT COUNT(*) FROM transactions WHERE customer_id = c.id) as tx_count FROM customers c ORDER BY c.id DESC";
$customers = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Cari Kartlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Cari Kartlar</h2>
            <button type="button" class="btn btn-success" onclick="openModal('add')">
                <i class="fa fa-plus"></i> Yeni Cari Ekle
            </button>
        </div>

        <?php 
            if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo '<div class="alert alert-warning">Cari kart silindi.</div>';
            echo $message; 
        ?>

        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Kod</th>
                                <th>Tür</th>
                                <th>Cari Başlık / Ünvan</th>
                                <th class="text-center">İşlem Adedi</th>
                                <th class="text-end">Bakiye</th>
                                <th class="text-center">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($customers) > 0): ?>
                                <?php foreach ($customers as $c): ?>
                                    <tr>
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
                                            <?php if(!empty($c['passport_number'])): ?>
                                                <br><small class="text-muted"><i class="fa fa-globe"></i> Yabancı Uyruklu</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $c['tx_count']; ?></span></td>
                                        <td class="text-end fw-bold">
                                            <?php echo number_format($c['current_balance'], 2, ',', '.'); ?> ₺
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-primary" onclick='openModal("edit", <?php echo json_encode($c); ?>)'>
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
                                <tr><td colspan="6" class="text-center p-3">Kayıtlı cari hesap yok.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
                                <select name="customer_type" id="customer_type" class="form-select" onchange="toggleTypeFields()">
                                    <option value="legal">Tüzel Kişi (Şirket)</option>
                                    <option value="real">Gerçek Kişi (Şahıs)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cari Kodu *</label>
                                <input type="text" name="customer_code" id="customer_code" class="form-control" required placeholder="Örn: C-001">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Cari Başlık / Ünvan *</label>
                                <input type="text" name="company_name" id="company_name" class="form-control" required placeholder="Firma Adı veya Ad Soyad">
                            </div>
                        </div>

                        <hr>

                        <h6 class="text-primary"><i class="fa fa-id-card"></i> Kimlik & Vergi Bilgileri</h6>
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
                                <label>Ülke</label>
                                <input type="text" name="country" id="country" class="form-control" value="Türkiye">
                            </div>
                            <div class="col-md-3">
                                <label>Şehir</label>
                                <input type="text" name="city" id="city" class="form-control" value="İstanbul">
                            </div>
                            <div class="col-md-6">
                                <label>Adres</label>
                                <textarea name="address" id="address" class="form-control" rows="1"></textarea>
                            </div>
                        </div>

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

        function toggleTypeFields() {
            var type = document.getElementById('customer_type').value;
            var legalFields = document.querySelectorAll('.legal-field');
            var realFields = document.querySelectorAll('.real-field');

            if (type === 'real') {
                // Gerçek Kişi
                legalFields.forEach(el => el.classList.add('d-none'));
                realFields.forEach(el => el.classList.remove('d-none'));
                
                // Yabancı kontrolünü çalıştır
                toggleForeigner();
                
                // Vergi No Zorunluluğunu Kaldır
                document.getElementById('tax_number').required = false;
            } else {
                // Tüzel Kişi
                legalFields.forEach(el => el.classList.remove('d-none'));
                realFields.forEach(el => el.classList.add('d-none'));
                
                // Vergi No Zorunlu
                document.getElementById('tax_number').required = true;
                // TC ve Pasaport Zorunluluğunu Kaldır
                document.getElementById('tc_number').required = false;
                document.getElementById('passport_number').required = false;
            }
        }

        function toggleForeigner() {
            var isForeigner = document.getElementById('is_foreigner').checked;
            var trFields = document.querySelectorAll('.tr-citizen');
            var foreignFields = document.querySelectorAll('.foreigner');

            if (isForeigner) {
                // Yabancı: Pasaport Göster
                trFields.forEach(el => el.classList.add('d-none'));
                foreignFields.forEach(el => el.classList.remove('d-none'));
                
                document.getElementById('passport_number').required = true;
                document.getElementById('tc_number').required = false;
                document.getElementById('tc_number').value = ''; // TC'yi temizle
            } else {
                // Yerli: TC Göster
                trFields.forEach(el => el.classList.remove('d-none'));
                foreignFields.forEach(el => el.classList.add('d-none'));
                
                document.getElementById('tc_number').required = true;
                document.getElementById('passport_number').required = false;
                document.getElementById('passport_number').value = ''; // Pasaportu temizle
            }
        }

        function openModal(mode, data = null) {
            document.getElementById('customerForm').reset();
            // Checkbox'ı sıfırla
            document.getElementById('is_foreigner').checked = false;
            
            if (mode === 'edit' && data) {
                document.getElementById('modalTitle').innerText = "Cari Kartı Düzenle";
                document.getElementById('edit_id').value = data.id;
                
                document.getElementById('customer_type').value = data.customer_type;
                document.getElementById('customer_code').value = data.customer_code;
                document.getElementById('company_name').value = data.company_name;
                document.getElementById('contact_name').value = data.contact_name;
                
                // Yabancı mı kontrolü
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
                
                document.getElementById('opening_balance').value = data.opening_balance;
                document.getElementById('opening_balance_currency').value = data.opening_balance_currency;
                document.getElementById('opening_balance_date').value = data.opening_balance_date;

            } else {
                document.getElementById('modalTitle').innerText = "Yeni Cari Kart Ekle";
                document.getElementById('edit_id').value = "";
                document.getElementById('country').value = "Türkiye";
                document.getElementById('city').value = "İstanbul";
            }
            
            toggleTypeFields();
            modal.show();
        }
    </script>
</body>
</html>