<?php
// public/api-payments-list.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Hata gizleme (JSON bozulmasın diye)
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    // DataTables Parametreleri
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $length = isset($_POST['length']) ? intval($_POST['length']) : 100;
    $search_value = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    $order_column_index = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : 0;
    $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'desc';

    // Tablo Sütun Haritası
    $columns = [
        0 => 't.id', 
        1 => 't.payment_status',
        2 => 't.id', // İşlemler
        3 => 't.id', // ID
        4 => 'd.name',
        5 => 't.date',
        6 => 't.doc_type',
        7 => 'c.company_name',
        8 => 'tc.code',
        9 => 't.invoice_no',
        10 => 't.amount',
        11 => 't.original_amount',
        12 => 't.id' // Düzenle
    ];

    $order_by = $columns[$order_column_index] ?? 't.date';

    // Sadece ANA işlemleri çek (Parent ID'si NULL olanlar)
    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE t.parent_id IS NULL "; 

    // Arama Filtresi (MEVCUT KODUNUZ)
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :search 
            OR tc.code LIKE :search 
            OR t.invoice_no LIKE :search 
            OR t.description LIKE :search
        )";
    }
    
    // Fatura Bekleyenler Filtresi (Yeni)
    if (isset($_POST['filter_invoice_pending']) && $_POST['filter_invoice_pending'] == 'true') {
        // Fatura durumu 'pending' olanlar listelensin
        $sql_base .= " AND t.invoice_status = 'pending'";
    }
    
    // Toplam Kayıt Sayısı
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE parent_id IS NULL");
    $total_records = $stmt->fetchColumn();

    // Filtreli Kayıt Sayısı
    $stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
    $stmt->execute();
    $filtered_records = $stmt->fetchColumn();

    // Verileri Çek
    $sql = "SELECT t.*, d.name as dep_name, c.company_name, tc.code as tour_code 
            " . $sql_base . " 
            ORDER BY $order_by $order_dir 
            LIMIT $start, $length";

    $stmt = $pdo->prepare($sql);
    if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // JSON Formatı Oluştur
    $response_data = [];
    foreach ($data as $row) {
        
        // --- RENKLENDİRME MANTIĞI (YENİ EKLENDİ) ---
        $row_class = '';
        if ($row['payment_status'] == 'paid') {
            // Ödenmişse soluk gri (Tamamlandı)
            $row_class = 'table-light text-muted'; 
        } elseif ($row['type'] == 'debt') { 
            // Ödeme Emri (Gider) ve Bekliyor -> Sarı/Turuncu
            $row_class = 'table-warning'; 
        } else {
            // Tahsilat (Gelir) ve Bekliyor -> Yeşil
            $row_class = 'table-success'; 
        }

        // 1. Detay (+ butonu) - DataTables CSS ile gelir
        $details_content = ''; 

        // 2. Durum Rozeti
        $status_text = ($row['payment_status'] == 'paid') ? 'Tamamlandı' : 'Planlandı';
        $status_class = ($row['payment_status'] == 'paid') ? 'bg-primary' : 'bg-success';
        $status_badge = '<span class="badge '.$status_class.'">'.$status_text.'</span>';

        // 3. İşlem Butonları (Veritabanı: is_approved, is_priority, needs_control)
        $app_active = $row['is_approved'] ? 'active' : '';
        $prio_active = $row['is_priority'] ? 'active' : '';
        $cont_active = $row['needs_control'] ? 'active' : '';

        $actions = '
        <div class="d-flex justify-content-center gap-2">
            <i class="fa fa-check-circle toggle-btn text-approval '.$app_active.'" 
               onclick="toggleStatus('.$row['id'].', \'approve\', this)" title="Onay"></i>
            
            <i class="fa fa-star toggle-btn text-priority '.$prio_active.'" 
               onclick="toggleStatus('.$row['id'].', \'priority\', this)" title="Öncelik"></i>

            <i class="fa fa-search toggle-btn text-control '.$cont_active.'" 
               onclick="toggleStatus('.$row['id'].', \'check\', this)" title="Kontrol"></i>
        </div>';

        // 4. Belge Tipi
        $doc_text = ($row['doc_type'] == 'invoice_order') ? 'Fatura/Tahsilat' : 'Ödeme Emri';
        $doc_badge = '<span class="badge bg-secondary">'.$doc_text.'</span>';

        // 5. Tutar Formatları
        $tl_amt = number_format($row['amount'], 2, ',', '.') . ' ₺';
        $org_amt = ($row['currency'] != 'TRY') ? number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        $response_data[] = [
            $details_content, // 0. Detay
            $status_badge,    // 1. Durum
            $actions,         // 2. İşlemler
            $row['id'],       // 3. ID
            $row['dep_name'],
            date('d.m.Y', strtotime($row['date'])),
            $doc_badge,
            guvenli_html($row['company_name']),
            $row['tour_code'],
            guvenli_html($row['invoice_no']),
            $tl_amt,
            $org_amt,
            '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>',
            "DT_RowClass" => $row_class // --- RENK SINIFI BURAYA EKLENDİ ---
        ];
    }

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $response_data
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>