<?php
// public/api-payments-list.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $length = isset($_POST['length']) ? intval($_POST['length']) : 100;
    $search_value = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    $order_column_index = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : 0;
    $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'desc';

    // Sütunlar
    $columns = [
        0 => 't.id', 
        1 => 't.id',
        2 => 't.id',
        3 => 't.id',
        4 => 'd.name',
        5 => 't.date',
        6 => 't.doc_type',
        7 => 'c.company_name',
        8 => 'tc.code',
        9 => 't.invoice_no',
        10 => 't.amount',
        11 => 't.original_amount'
    ];

    $order_by = $columns[$order_column_index] ?? 't.date';

    // Sadece Ana İşlemleri Çek
    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE t.parent_id IS NULL "; 

    // Arama Filtreleri
    if (!empty($search_value)) {
        $sql_base .= " AND (c.company_name LIKE :search OR tc.code LIKE :search OR t.invoice_no LIKE :search)";
    }
    // (Diğer sütun filtreleri buraya eklenebilir...)

    // Sayma
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE parent_id IS NULL");
    $total_records = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
    $stmt->execute();
    $filtered_records = $stmt->fetchColumn();

    // Veri Çekme
    $sql = "SELECT t.*, d.name as dep_name, c.company_name, tc.code as tour_code 
            " . $sql_base . " 
            ORDER BY $order_by $order_dir 
            LIMIT $start, $length";

    $stmt = $pdo->prepare($sql);
    if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response_data = [];
    foreach ($data as $row) {
        
        // 1. SORUN ÇÖZÜMÜ: Burayı boş bırakıyoruz, DataTables CSS ile ikonu koyacak.
        $details_content = ''; 

        // 2. SORUN ÇÖZÜMÜ: Butonları her halükarda oluşturuyoruz.
        $app_active = $row['is_approved'] ? 'active' : '';
        $prio_active = (isset($row['is_priority']) && $row['is_priority']) ? 'active' : '';
        
        // Kontrol sütunu var mı? Yoksa hata vermesin.
        $cont_active = (isset($row['needs_control']) && $row['needs_control']) ? 'active' : '';

        $actions = '
        <div class="d-flex justify-content-center gap-2">
            <i class="fa fa-check-circle toggle-btn text-approval '.$app_active.'" 
               onclick="toggleStatus('.$row['id'].', \'is_approve\', this)" title="Onay"></i>
            
            <i class="fa fa-star toggle-btn text-priority '.$prio_active.'" 
               onclick="toggleStatus('.$row['id'].', \'is_priority\', this)" title="Öncelik"></i>

            <i class="fa fa-search toggle-btn text-control '.$cont_active.'" 
               onclick="toggleStatus('.$row['id'].', \'needs_control\', this)" title="Kontrol"></i>
        </div>';

        // Tutar Formatı
        $tl_amt = number_format($row['amount'], 2, ',', '.') . ' ₺';
        $org_amt = ($row['currency'] != 'TRY') ? number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        // Durum Rozeti
        $status_text = ($row['payment_status'] == 'paid') ? 'Tamamlandı' : 'Planlandı';
        $status_class = ($row['payment_status'] == 'paid') ? 'bg-primary' : 'bg-success';
        $status_badge = '<span class="badge '.$status_class.'">'.$status_text.'</span>';

        // Belge Tipi
        $doc_text = ($row['doc_type'] == 'invoice_order') ? 'Fatura/Tahsilat' : 'Ödeme Emri';

        $response_data[] = [
            $details_content, // 0. Detay (Boş string, CSS halledecek)
            $status_badge,    // 1. Durum
            $actions,         // 2. İşlemler (Butonlar)
            $row['id'],       // 3. ID
            $row['dep_name'],
            date('d.m.Y', strtotime($row['date'])),
            $doc_text,
            guvenli_html($row['company_name']),
            $row['tour_code'],
            guvenli_html($row['invoice_no']),
            $tl_amt,
            $org_amt,
            '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>'
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