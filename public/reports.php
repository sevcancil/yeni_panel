<?php
// public/reports.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- RENK PALETİ (Grafikler İçin) ---
$colors = [
    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', 
    '#858796', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c'
];

// 1. KARTLAR: ÖNGÖRÜLEN vs BEKLEYEN
$stats = [
    'forecast_income' => 0, 'pending_income'  => 0,
    'forecast_expense'=> 0, 'pending_expense' => 0
];

$sql_inc = "SELECT 
            SUM(CASE WHEN invoice_status != 'issued' THEN amount ELSE 0 END) as forecast,
            SUM(CASE WHEN invoice_status = 'issued' AND payment_status != 'paid' THEN (amount - (SELECT COALESCE(SUM(amount),0) FROM transactions t2 WHERE t2.parent_id = t1.id)) ELSE 0 END) as pending
            FROM transactions t1 WHERE doc_type = 'invoice_order' AND is_deleted = 0 AND parent_id IS NULL";
$row_inc = $pdo->query($sql_inc)->fetch();
$stats['forecast_income'] = $row_inc['forecast'] ?? 0;
$stats['pending_income'] = $row_inc['pending'] ?? 0;

$sql_exp = "SELECT 
            SUM(CASE WHEN invoice_status != 'issued' THEN amount ELSE 0 END) as forecast,
            SUM(CASE WHEN invoice_status = 'issued' AND payment_status != 'paid' THEN (amount - (SELECT COALESCE(SUM(amount),0) FROM transactions t2 WHERE t2.parent_id = t1.id)) ELSE 0 END) as pending
            FROM transactions t1 WHERE doc_type = 'payment_order' AND is_deleted = 0 AND parent_id IS NULL";
$row_exp = $pdo->query($sql_exp)->fetch();
$stats['forecast_expense'] = $row_exp['forecast'] ?? 0;
$stats['pending_expense'] = $row_exp['pending'] ?? 0;

// 2. GRAFİK: AYLIK KAR/ZARAR
$monthly_data = []; $labels = []; $incomes = []; $expenses = [];
for ($i = 11; $i >= 0; $i--) {
    $date = date("Y-m", strtotime("-$i months"));
    $month_name = date("M Y", strtotime("-$i months"));
    $inc = $pdo->query("SELECT SUM(amount) FROM transactions WHERE (doc_type='invoice_order') AND is_deleted=0 AND DATE_FORMAT(COALESCE(planned_date, date), '%Y-%m') = '$date'")->fetchColumn();
    $exp = $pdo->query("SELECT SUM(amount) FROM transactions WHERE (doc_type='payment_order') AND is_deleted=0 AND DATE_FORMAT(COALESCE(planned_date, date), '%Y-%m') = '$date'")->fetchColumn();
    $labels[] = $month_name;
    $incomes[] = $inc ?? 0;
    $expenses[] = $exp ?? 0;
    $monthly_data[] = ['date' => $date, 'label' => $month_name]; 
}

// 3. GRAFİKLER: BÖLÜM BAZLI ANALİZ
$dept_names = [];
$dept_income_data = [];
$dept_expense_data = [];
$dept_profit_data = [];
$dept_bg_colors = [];

$sql_dept = "SELECT d.name, 
             SUM(CASE WHEN t.doc_type='invoice_order' THEN t.amount ELSE 0 END) as income,
             SUM(CASE WHEN t.doc_type='payment_order' THEN t.amount ELSE 0 END) as expense
             FROM transactions t
             JOIN departments d ON t.department_id = d.id
             WHERE t.is_deleted = 0
             GROUP BY d.id ORDER BY income DESC";
$depts = $pdo->query($sql_dept)->fetchAll();
$ci = 0;
foreach($depts as $d) {
    $dept_names[] = $d['name'];
    $dept_income_data[] = $d['income'];
    $dept_expense_data[] = $d['expense'];
    $dept_profit_data[] = $d['income'] - $d['expense'];
    $dept_bg_colors[] = $colors[$ci % count($colors)];
    $ci++;
}

// 4. TABLOLAR: TOP 5 KAR/ZARAR
$sql_top_profit = "SELECT tc.code, tc.name, 
                   (SUM(CASE WHEN t.doc_type='invoice_order' THEN t.amount ELSE 0 END) - 
                    SUM(CASE WHEN t.doc_type='payment_order' THEN t.amount ELSE 0 END)) as profit
                   FROM transactions t JOIN tour_codes tc ON t.tour_code_id = tc.id
                   WHERE t.is_deleted = 0 GROUP BY tc.id ORDER BY profit DESC LIMIT 5";
$top_profits = $pdo->query($sql_top_profit)->fetchAll();

$sql_top_loss = "SELECT tc.code, tc.name, 
                   (SUM(CASE WHEN t.doc_type='invoice_order' THEN t.amount ELSE 0 END) - 
                    SUM(CASE WHEN t.doc_type='payment_order' THEN t.amount ELSE 0 END)) as profit
                   FROM transactions t JOIN tour_codes tc ON t.tour_code_id = tc.id
                   WHERE t.is_deleted = 0 GROUP BY tc.id ORDER BY profit ASC LIMIT 5";
$top_losses = $pdo->query($sql_top_loss)->fetchAll();

// 5. YENİ: GÜNCEL İŞLER (Tarihi geçmemiş projeler) - Limit 20
$sql_active = "SELECT tc.*, d.name as dept_name 
               FROM tour_codes tc 
               LEFT JOIN departments d ON tc.department_id = d.id 
               WHERE tc.start_date >= CURDATE() 
               ORDER BY tc.start_date ASC LIMIT 20";
$active_projects = $pdo->query($sql_active)->fetchAll();

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Finansal Kokpit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.19.3/package/dist/xlsx.full.min.js"></script>
    <style>
        .card-counter { box-shadow: 2px 2px 10px #DADADA; padding: 20px 10px; background-color: #fff; height: 100px; border-radius: 5px; position: relative; overflow: hidden; }
        .card-counter i { font-size: 4em; opacity: 0.2; position: absolute; right: 10px; top: 10px; }
        .card-counter .count-numbers { font-size: 24px; font-weight: bold; display: block; margin-top: 10px; z-index: 2; position: relative; }
        .card-counter .count-name { font-style: italic; text-transform: uppercase; opacity: 0.9; font-size: 12px; display: block; z-index: 2; position: relative; font-weight: 600; }
        .card-counter.primary { background-color: #007bff; color: #FFF; }
        .card-counter.danger { background-color: #ef5350; color: #FFF; }
        .card-counter.success { background-color: #66bb6a; color: #FFF; }
        .card-counter.info { background-color: #26c6da; color: #FFF; }
        .card-counter.warning { background-color: #fd7e14; color: #FFF; }
        .chart-container { position: relative; height: 250px; width: 100%; }
        .btn-excel-sm { position: absolute; top: 10px; right: 10px; z-index: 10; font-size: 0.8rem; padding: 2px 8px; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-dark"><i class="fa fa-chart-line text-primary"></i> Finansal Kokpit</h2>
            <div>
                <button onclick="downloadAllChartsData()" class="btn btn-success me-2"><i class="fa fa-file-excel"></i> Grafik Verilerini İndir</button>
                <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa fa-print"></i> Yazdır</button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card-counter info">
                    <i class="fa fa-clock"></i>
                    <span class="count-numbers"><?php echo number_format($stats['forecast_income'], 0, ',', '.'); ?> ₺</span>
                    <span class="count-name">Öngörülen Gelir (Sipariş)</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-counter success">
                    <i class="fa fa-check-double"></i>
                    <span class="count-numbers"><?php echo number_format($stats['pending_income'], 0, ',', '.'); ?> ₺</span>
                    <span class="count-name">Bekleyen Tahsilat (Fatura)</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-counter warning">
                    <i class="fa fa-hourglass-half"></i>
                    <span class="count-numbers"><?php echo number_format($stats['forecast_expense'], 0, ',', '.'); ?> ₺</span>
                    <span class="count-name">Öngörülen Gider (Sipariş)</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-counter danger">
                    <i class="fa fa-exclamation-triangle"></i>
                    <span class="count-numbers"><?php echo number_format($stats['pending_expense'], 0, ',', '.'); ?> ₺</span>
                    <span class="count-name">Bekleyen Ödeme (Fatura)</span>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow border-0">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-primary">Aylık Finansal Trend (Son 12 Ay)</h6>
                        <small class="text-muted">Sütunlara tıklayarak detay indirebilirsiniz.</small>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 350px;">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-white fw-bold">Bölüm Bazlı Kâr/Zarar</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="deptProfitChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-white fw-bold text-success">Gelir Dağılımı (Oran)</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="deptIncomeChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-white fw-bold text-danger">Gider Dağılımı (Oran)</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="deptExpenseChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-lg-6 mb-4">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-info text-white fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-calendar-alt"></i> Güncel / Gelecek İşler (İlk 20)</span>
                        <button onclick="downloadExcel('active_projects', 'Guncel_Isler_Listesi')" class="btn btn-sm btn-light text-dark fw-bold"><i class="fa fa-file-excel"></i> Tümünü İndir</button>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover table-striped mb-0 text-sm">
                            <thead class="table-light"><tr><th>Tarih</th><th>Kod</th><th>İş Adı</th><th>Bölüm</th></tr></thead>
                            <tbody>
                                <?php foreach($active_projects as $ap): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y', strtotime($ap['start_date'])); ?></td>
                                    <td class="fw-bold"><?php echo $ap['code']; ?></td>
                                    <td><?php echo mb_substr($ap['name'], 0, 30); ?>...</td>
                                    <td><span class="badge bg-secondary"><?php echo $ap['dept_name']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="row h-100">
                    <div class="col-12 mb-3">
                        <div class="card shadow border-0">
                            <div class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center">
                                <span><i class="fa fa-trophy"></i> En Kârlı İşler (Top 5)</span>
                                <button onclick="downloadExcel('all_profit', 'Karli_Isler_Tam_Liste')" class="btn btn-sm btn-light text-success fw-bold"><i class="fa fa-download"></i> Tam Liste</button>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <?php foreach($top_profits as $t): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold"><?php echo $t['code']; ?></td>
                                        <td><?php echo $t['name']; ?></td>
                                        <td class="pe-3 text-end text-success fw-bold">+<?php echo number_format($t['profit'], 0, ',', '.'); ?> ₺</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card shadow border-0">
                            <div class="card-header bg-danger text-white fw-bold d-flex justify-content-between align-items-center">
                                <span><i class="fa fa-arrow-down"></i> En Çok Zarar Edenler (Top 5)</span>
                                <button onclick="downloadExcel('all_loss', 'Zarar_Eden_Isler_Tam_Liste')" class="btn btn-sm btn-light text-danger fw-bold"><i class="fa fa-download"></i> Tam Liste</button>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <?php foreach($top_losses as $t): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold"><?php echo $t['code']; ?></td>
                                        <td><?php echo $t['name']; ?></td>
                                        <td class="pe-3 text-end text-danger fw-bold"><?php echo number_format($t['profit'], 0, ',', '.'); ?> ₺</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        // Veri Setleri (PHP'den JS'ye)
        const monthlyDataMeta = <?php echo json_encode($monthly_data); ?>;
        const deptLabels = <?php echo json_encode($dept_names); ?>;
        const deptColors = <?php echo json_encode($dept_bg_colors); ?>;
        
        // 1. AYLIK GRAFİK
        new Chart(document.getElementById('monthlyChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [
                    { label: 'Gelir', data: <?php echo json_encode($incomes); ?>, backgroundColor: '#198754' },
                    { label: 'Gider', data: <?php echo json_encode($expenses); ?>, backgroundColor: '#dc3545' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                onClick: (e, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        downloadExcel('month_detail', monthlyDataMeta[index].label + '_Detay', monthlyDataMeta[index].date);
                    }
                }
            }
        });

        // 2. BÖLÜM GRAFİKLERİ
        const pieOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } };

        new Chart(document.getElementById('deptProfitChart'), {
            type: 'bar',
            data: {
                labels: deptLabels,
                datasets: [{ label: 'Kâr/Zarar', data: <?php echo json_encode($dept_profit_data); ?>, backgroundColor: deptColors }]
            },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } }
        });

        new Chart(document.getElementById('deptIncomeChart'), {
            type: 'doughnut',
            data: { labels: deptLabels, datasets: [{ data: <?php echo json_encode($dept_income_data); ?>, backgroundColor: deptColors }] },
            options: pieOptions
        });

        new Chart(document.getElementById('deptExpenseChart'), {
            type: 'pie',
            data: { labels: deptLabels, datasets: [{ data: <?php echo json_encode($dept_expense_data); ?>, backgroundColor: deptColors }] },
            options: pieOptions
        });

        // --- EXCEL İNDİRME MERKEZİ ---
        function downloadExcel(type, filename, extraParam = '') {
            let url = 'api-report-data.php?action=' + type;
            if (extraParam) url += '&param=' + extraParam;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (!data || data.length === 0) { alert('Veri bulunamadı.'); return; }
                    const ws = XLSX.utils.json_to_sheet(data);
                    const wb = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(wb, ws, "Rapor");
                    XLSX.writeFile(wb, filename + ".xlsx");
                });
        }

        // Tüm Grafikleri İndir (Çoklu Sekme)
        async function downloadAllChartsData() {
            const wb = XLSX.utils.book_new();
            
            // Sırayla verileri çek ve ekle
            const actions = [
                { act: 'dept_income', name: 'Bolum_Gelirleri' },
                { act: 'dept_expense', name: 'Bolum_Giderleri' },
                { act: 'top_profit', name: 'En_Karli_Isler' },
                { act: 'top_loss', name: 'En_Zararli_Isler' }
            ];

            for (let item of actions) {
                let res = await fetch('api-report-data.php?action=' + item.act);
                let data = await res.json();
                if(data.length > 0) {
                    let ws = XLSX.utils.json_to_sheet(data);
                    XLSX.utils.book_append_sheet(wb, ws, item.name);
                }
            }
            XLSX.writeFile(wb, "Detayli_Grafik_Verileri.xlsx");
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>