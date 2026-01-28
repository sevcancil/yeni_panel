<?php
// public/api-payments-list.php

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    require_once '../app/config/database.php';
    require_once '../app/functions/security.php';

    // DataTables Parametreleri
    $draw = isset($_REQUEST['draw']) ? intval($_REQUEST['draw']) : 1;
    $start = isset($_REQUEST['start']) ? intval($_REQUEST['start']) : 0;
    $length = isset($_REQUEST['length']) ? intval($_REQUEST['length']) : 50;
    $search_value = isset($_REQUEST['search']['value']) ? $_REQUEST['search']['value'] : '';
    $order_col_idx = isset($_REQUEST['order'][0]['column']) ? $_REQUEST['order'][0]['column'] : 5;
    $order_dir = isset($_REQUEST['order'][0]['dir']) ? $_REQUEST['order'][0]['dir'] : 'desc';

    // Veritabanı Sütun Eşleşmesi (Sıralama İçin)
    $columns_map = [
        0 => 't.id', 1 => 't.payment_status', 2 => 't.id', 3 => 't.id', 
        4 => 't.type', 5 => 't.date', 6 => 'd.name', 7 => 'c.company_name', 
        8 => 'tc.code', 9 => 't.invoice_no', 10 => 't.description', 
        11 => 't.amount', 12 => 't.currency', 13 => 't.id'
    ];
    $order_by = $columns_map[$order_col_idx] ?? 't.date';

    // Ana Sorgu
    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE (t.parent_id IS NULL OR t.parent_id = 0) "; 

    // Arama
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :search OR tc.code LIKE :search 
            OR t.description LIKE :search OR t.invoice_no LIKE :search OR t.id LIKE :search
        )";
    }

    // Sayaçlar
    $total_records = $pdo->query("SELECT COUNT(*) FROM transactions WHERE (parent_id IS NULL OR parent_id = 0)")->fetchColumn();
    $stmtFilter = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    if (!empty($search_value)) { $stmtFilter->bindValue(':search', "%$search_value%"); }
    $stmtFilter->execute();
    $filtered_records = $stmtFilter->fetchColumn();

    // Veri Çekme
    $sqlData = "SELECT t.*, d.name as dep_name, c.company_name, c.id as cust_id, tc.code as tour_code,
                (SELECT COALESCE(SUM(amount),0) FROM transactions t2 WHERE t2.parent_id = t.id AND (t2.type = 'payment_out' OR t2.type = 'payment_in')) as total_paid,
                (SELECT GROUP_CONCAT(CONCAT('<b>', invoice_no, '</b>: ', FORMAT(amount, 2), ' TL') SEPARATOR '<br>') 
                 FROM transactions t3 WHERE t3.parent_id = t.id AND (t3.document_type LIKE '%Fatura%' OR t3.document_type LIKE '%Arşiv%' OR (t3.invoice_no IS NOT NULL AND t3.invoice_no != '')) AND t3.type NOT IN ('payment_out', 'payment_in')
                ) as invoices_html
                " . $sql_base . " ORDER BY $order_by $order_dir LIMIT $start, $length";

    $stmt = $pdo->prepare($sqlData);
    if (!empty($search_value)) { $stmt->bindValue(':search', "%$search_value%"); }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response_data = [];
    foreach ($data as $row) {
        $amount = (float)$row['amount'];
        $paid = (float)$row['total_paid']; 
        $remaining = $amount - $paid;

        // Renklendirme
        $row_class = '';
        if ($remaining <= 0.05) $row_class = 'table-light text-muted';
        elseif ($row['type'] == 'debt') $row_class = 'table-danger bg-opacity-10';
        else $row_class = 'table-success bg-opacity-10';

        // HTML Parçaları
        $inv_display = !empty($row['invoice_no']) ? '<b>'.guvenli_html($row['invoice_no']).'</b>' : '';
        if(!empty($row['invoices_html'])) $inv_display .= ($inv_display ? '<br>' : '') . $row['invoices_html'];
        if(empty($inv_display)) $inv_display = '<span class="text-muted small">Fatura Bekleniyor</span>';

        $amt_html = '<div class="fw-bold text-dark fs-6">' . number_format($amount, 2, ',', '.') . ' ₺</div>';
        if ($paid > 0 && $remaining > 0.05) {
            $amt_html .= '<div class="small text-success">Ödenen: ' . number_format($paid, 2, ',', '.') . '</div>';
            $amt_html .= '<div class="small text-danger fw-bold">Kalan: ' . number_format($remaining, 2, ',', '.') . '</div>';
        }

        $status_badge = ($remaining <= 0.05) ? '<span class="badge bg-primary">Tamamlandı</span>' : (($paid > 0) ? '<span class="badge bg-info text-dark">Kısmi</span>' : '<span class="badge bg-secondary">Planlandı</span>');
        $type_badge = ($row['type'] == 'debt') ? '<span class="badge bg-danger"><i class="fa fa-arrow-up"></i> GİDER</span>' : '<span class="badge bg-success"><i class="fa fa-arrow-down"></i> GELİR</span>';
        
        $cari_link = $row['company_name'] ? '<a href="customer-details.php?id='.$row['cust_id'].'" target="_blank" class="fw-bold text-decoration-none text-dark">'.guvenli_html($row['company_name']).'</a>' : '-';
        $tour_link = $row['tour_code'] ? '<span class="badge bg-info text-dark">'.$row['tour_code'].'</span>' : '-';

        $actions = '<div class="d-flex justify-content-center gap-2">
            <i class="fa fa-history text-info toggle-btn" onclick="openLogModal('.$row['id'].')" title="Geçmiş"></i>
            <i class="fa fa-check-circle action-icon '.($row['is_approved']?'active approval':'').'" onclick="toggleStatus('.$row['id'].', \'approve\', this)"></i>
            <i class="fa fa-trash text-danger toggle-btn" onclick="deleteTransaction('.$row['id'].')"></i>
        </div>';

        // YENİ YAPI: İsimlendirilmiş Anahtarlar (JSON Object Olsa Bile JS Okuyabilecek)
        $response_data[] = [
            "detail_btn" => "",
            "status"     => $status_badge,
            "actions"    => $actions,
            "checkbox"   => '<input class="form-check-input row-select" type="checkbox" data-id="'.$row['id'].'">',
            "type"       => $type_badge,
            "date"       => date('d.m.Y', strtotime($row['date'])),
            "department" => $row['dep_name'],
            "customer"   => $cari_link,
            "tour"       => $tour_link,
            "invoice"    => $inv_display,
            "desc"       => guvenli_html($row['description']),
            "amount"     => $amt_html,
            "currency"   => $row['currency'],
            "edit_btn"   => '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>',
            "DT_RowClass"=> $row_class // DataTables özel sınıfı
        ];
    }

    echo json_encode(["draw" => $draw, "recordsTotal" => $total_records, "recordsFiltered" => $filtered_records, "data" => $response_data], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>