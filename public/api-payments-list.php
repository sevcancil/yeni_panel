<?php
require_once '../app/config/database.php';
require_once '../app/functions/security.php'; // Yetki kontrolü için

// DataTables'dan gelen parametreler
$draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
$start = isset($_POST['start']) ? intval($_POST['start']) : 0;
$length = isset($_POST['length']) ? intval($_POST['length']) : 100; // Varsayılan 100
$search_value = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
$order_column_index = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : 0;
$order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'desc';

// Sıralanabilir Sütunlar (Tablodaki sıraya göre)
$columns = [
    0 => 't.id', // İşlemler (Sıralama yok ama index kaymasın)
    1 => 't.id',
    2 => 'd.name',
    3 => 't.date',
    4 => 't.document_no', // Belge
    5 => 'c.company_name',
    6 => 'tc.code',
    7 => 't.description', // Fatura Detay
    8 => 't.amount', // Tutar TL
    9 => 't.amount'  // Tutar Döviz
];

$order_by = $columns[$order_column_index] ?? 't.date';

// --- SORGULARI HAZIRLA ---
$sql_base = "FROM transactions t 
             LEFT JOIN departments d ON t.department_id = d.id 
             LEFT JOIN customers c ON t.customer_id = c.id 
             LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
             WHERE 1=1 ";

// 1. GENEL ARAMA (Global Search Kutusu)
if (!empty($search_value)) {
    $sql_base .= " AND (
        c.company_name LIKE :search 
        OR tc.code LIKE :search 
        OR t.description LIKE :search 
        OR t.document_no LIKE :search
        OR d.name LIKE :search
    )";
}

// 2. SÜTUN FİLTRELERİ (Excel Mantığı)
// DataTables her sütun için search[value] gönderir
if (!empty($_POST['columns'][2]['search']['value'])) { // Bölüm
    $sql_base .= " AND d.name LIKE '" . $_POST['columns'][2]['search']['value'] . "%'";
}
if (!empty($_POST['columns'][5]['search']['value'])) { // Cari
    $sql_base .= " AND c.company_name LIKE '%" . $_POST['columns'][5]['search']['value'] . "%'";
}
if (!empty($_POST['columns'][6]['search']['value'])) { // Tur Kodu
    $sql_base .= " AND tc.code LIKE '%" . $_POST['columns'][6]['search']['value'] . "%'";
}
// Tarih vb. eklenebilir

// Toplam Kayıt Sayısı (Filtresiz)
$stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
$total_records = $stmt->fetchColumn();

// Filtrelenmiş Kayıt Sayısı
$stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
$stmt->execute();
$filtered_records = $stmt->fetchColumn();

// VERİLERİ ÇEK (Sayfalama ve Sıralama ile)
$sql = "SELECT t.*, d.name as dep_name, c.company_name, tc.code as tour_code, tc.name as tour_name 
        " . $sql_base . " 
        ORDER BY $order_by $order_dir 
        LIMIT $start, $length";

$stmt = $pdo->prepare($sql);
if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- VERİYİ FORMATLA (JSON İÇİN) ---
$response_data = [];
foreach ($data as $row) {
    
    // Yetki Kontrolü (Butonlar İçin)
    $actions = '';
    // Sadece Admin veya Muhasebe (Burada yetki kontrolü fonksiyonunuzu kullanın)
    // Şimdilik session role bakıyoruz veya has_permission fonksiyonu
    if (isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'accountant')) {
        
        // Onay Butonu
        $btn_class = $row['is_approved'] ? 'btn-success' : 'btn-outline-secondary';
        $icon = $row['is_approved'] ? 'fa-check-circle' : 'fa-circle';
        $actions .= '<button class="btn btn-sm '.$btn_class.' me-1" onclick="toggleStatus('.$row['id'].', \'approve\')" title="Onay"><i class="fa '.$icon.'"></i></button>';
        
        // Öncelik Butonu
        $prio_class = $row['priority'] ? 'btn-danger' : 'btn-outline-secondary';
        $prio_icon = $row['priority'] ? 'fa-star' : 'fa-star-o';
        $actions .= '<button class="btn btn-sm '.$prio_class.' me-1" onclick="toggleStatus('.$row['id'].', \'priority\')" title="Öncelik"><i class="fa '.$prio_icon.'"></i></button>';
        
        // Düzenle
        $actions .= '<button class="btn btn-sm btn-primary me-1" onclick="editItem('.$row['id'].')"><i class="fa fa-edit"></i></button>';
    }

    // TL Hesapla (Kayıt anındaki kur ile)
    // Not: Veritabanına kaydederken kur ile çarpıp kaydetmek performans için daha iyidir ama burada hesaplıyoruz.
    $tl_amount = $row['amount'] * $row['exchange_rate'];

    // Orijinal Tutar (Dövizli Görünüm)
    $org_amount = number_format($row['amount'], 2, ',', '.') . ' ' . $row['currency'];

    $response_data[] = [
        $actions,
        $row['id'],
        $row['dep_name'],
        date('d.m.Y', strtotime($row['date'])),
        $row['document_no'], // Belge No
        $row['company_name'],
        '<span class="badge bg-secondary">' . $row['tour_code'] . '</span>',
        $row['description'], // Fatura/Açıklama
        '<strong>' . number_format($tl_amount, 2, ',', '.') . ' ₺</strong>', // TL Karşılığı
        $org_amount, // Döviz Hali
        '<button class="btn btn-sm btn-info text-white"><i class="fa fa-cog"></i></button>' // İşlem Yap
    ];
}

// JSON ÇIKTISI
echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $total_records,
    "recordsFiltered" => $filtered_records,
    "data" => $response_data
]);
?>