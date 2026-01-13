<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Yeni Proje Ekleme İşlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project'])) {
    $code = temizle($_POST['code']);
    $name = temizle($_POST['name']);
    $start_date = $_POST['start_date'];

    $stmt = $pdo->prepare("INSERT INTO tour_codes (code, name, start_date) VALUES (?, ?, ?)");
    $stmt->execute([$code, $name, $start_date]);
    header("Location: projects.php");
    exit;
}

// Projeleri ve Bakiyelerini Çek (JOIN ile transactions tablosundan topla)
// type='credit' (Gelir), type='debt' (Gider) olarak kabul etmiştik.
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
    <title>Projeler ve Tur Kodları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fa fa-plus-circle"></i> Yeni Proje / Tur Tanımla</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="add_project" value="1">
                            <div class="mb-3">
                                <label>Tur Kodu *</label>
                                <input type="text" name="code" class="form-control" placeholder="Örn: 2026-DELL-01" required>
                            </div>
                            <div class="mb-3">
                                <label>Etkinlik Adı *</label>
                                <input type="text" name="name" class="form-control" placeholder="Örn: Dell Forum 2026" required>
                            </div>
                            <div class="mb-3">
                                <label>Başlangıç Tarihi</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Oluştur</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Aktif Projeler ve Kâr/Zarar Durumu</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Kod</th>
                                    <th>Etkinlik Adı</th>
                                    <th class="text-end">Gelir</th>
                                    <th class="text-end">Gider</th>
                                    <th class="text-end">Kâr/Zarar</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($projects as $p): ?>
                                    <?php 
                                        $income = $p['total_income'] ?? 0;
                                        $expense = $p['total_expense'] ?? 0;
                                        $profit = $income - $expense; // Bizim mantıkta credit=tahsilat(gelir), debt=borç(gider)
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?php echo guvenli_html($p['code']); ?></span></td>
                                        <td><?php echo guvenli_html($p['name']); ?></td>
                                        <td class="text-end text-success"><?php echo number_format($income, 2); ?> ₺</td>
                                        <td class="text-end text-danger"><?php echo number_format($expense, 2); ?> ₺</td>
                                        <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($profit, 2); ?> ₺
                                        </td>
                                        <td>
                                            <?php if($p['status'] == 'active'): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Bitti</span>
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
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>