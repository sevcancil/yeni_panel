<?php
// public/get-project-report.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) exit('<div class="alert alert-danger">Yetkisiz erişim.</div>');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. Proje Bilgilerini Çek
$stmt = $pdo->prepare("SELECT * FROM tour_codes WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) exit('<div class="alert alert-danger">Proje bulunamadı.</div>');

// 2. Finansal Özet (Gelir / Gider / Kâr)
// type='credit' -> Gelir (Tahsilat)
// type='debt' -> Gider (Ödeme)
$stats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type = 'debt' THEN amount ELSE 0 END) as total_expense
    FROM transactions 
    WHERE tour_code_id = ? AND parent_id IS NULL
");
$stats->execute([$id]);
$s = $stats->fetch(PDO::FETCH_ASSOC);

$income = $s['total_income'] ?? 0;
$expense = $s['total_expense'] ?? 0;
$profit = $income - $expense;
$profit_class = $profit >= 0 ? 'text-success' : 'text-danger';

// 3. Hareket Detayları
$trans = $pdo->prepare("
    SELECT t.*, c.company_name 
    FROM transactions t
    LEFT JOIN customers c ON t.customer_id = c.id
    WHERE t.tour_code_id = ? AND t.parent_id IS NULL
    ORDER BY t.date DESC
");
$trans->execute([$id]);
$transactions = $trans->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid p-0">
    
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h5 class="mb-0 text-primary">
            <?php echo guvenli_html($project['code']); ?> - <?php echo guvenli_html($project['name']); ?>
        </h5>
        <div>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary me-1">
                <i class="fa fa-print"></i> Yazdır
            </button>
            <button onclick="exportTableToExcel('reportTable', '<?php echo $project['code']; ?>_Rapor')" class="btn btn-sm btn-success">
                <i class="fa fa-file-excel"></i> Excel
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4 text-center">
        <div class="col-md-4">
            <div class="p-3 border rounded bg-success bg-opacity-10">
                <small class="text-success text-uppercase fw-bold">Toplam Gelir</small>
                <h4 class="mb-0 fw-bold text-success"><?php echo number_format($income, 2, ',', '.'); ?> ₺</h4>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 border rounded bg-danger bg-opacity-10">
                <small class="text-danger text-uppercase fw-bold">Toplam Gider</small>
                <h4 class="mb-0 fw-bold text-danger"><?php echo number_format($expense, 2, ',', '.'); ?> ₺</h4>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 border rounded bg-light">
                <small class="text-uppercase fw-bold">Net Kâr / Zarar</small>
                <h4 class="mb-0 fw-bold <?php echo $profit_class; ?>"><?php echo number_format($profit, 2, ',', '.'); ?> ₺</h4>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 offset-md-3">
            <canvas id="projectChart" width="400" height="200"></canvas>
        </div>
    </div>

    <h6 class="fw-bold border-bottom pb-2 mb-2"><i class="fa fa-list"></i> İşlem Dökümü</h6>
    <div class="table-responsive">
        <table id="reportTable" class="table table-sm table-bordered table-striped small">
            <thead class="table-dark">
                <tr>
                    <th>Tarih</th>
                    <th>Cari / Firma</th>
                    <th>Açıklama</th>
                    <th>Belge</th>
                    <th class="text-end">Gelir</th>
                    <th class="text-end">Gider</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($transactions as $t): ?>
                    <tr>
                        <td><?php echo date('d.m.Y', strtotime($t['date'])); ?></td>
                        <td><?php echo guvenli_html($t['company_name']); ?></td>
                        <td><?php echo guvenli_html($t['description']); ?></td>
                        <td><?php echo $t['invoice_no']; ?></td>
                        <td class="text-end text-success fw-bold">
                            <?php echo ($t['type'] == 'credit') ? number_format($t['amount'], 2, ',', '.') : ''; ?>
                        </td>
                        <td class="text-end text-danger fw-bold">
                            <?php echo ($t['type'] == 'debt') ? number_format($t['amount'], 2, ',', '.') : ''; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="fw-bold bg-light">
                    <td colspan="4" class="text-end">GENEL TOPLAM:</td>
                    <td class="text-end text-success"><?php echo number_format($income, 2, ',', '.'); ?></td>
                    <td class="text-end text-danger"><?php echo number_format($expense, 2, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Önceki grafik varsa yok et (Tekrar açılınca üst üste binmesin)
    if(window.myChart instanceof Chart) { window.myChart.destroy(); }

    var ctx = document.getElementById('projectChart').getContext('2d');
    window.myChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Gelir', 'Gider'],
            datasets: [{
                data: [<?php echo $income; ?>, <?php echo $expense; ?>],
                backgroundColor: ['#198754', '#dc3545'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
</script>