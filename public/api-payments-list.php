<?php
// public/api-payments-list.php

ob_start();
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

try {
    $role = $_SESSION['role'] ?? '';
    $is_admin = ($role === 'admin');
    $can_delete = ($role === 'admin' || $role === 'muhasebe');

    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $length = isset($_POST['length']) ? intval($_POST['length']) : 50;
    $search_value = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    $order_column_index = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : 5;
    $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'desc';

    $columns_map = [
        0 => 't.id', 
        1 => 't.payment_status',
        2 => 't.id',             
        3 => 't.id',             
        4 => 't.doc_type',       
        5 => 't.date',           
        6 => 'd.name',           
        7 => 'c.company_name',   
        8 => 'tc.code',          
        9 => 't.invoice_no',     
        10 => 't.description',   
        11 => 't.amount',        
        12 => 't.original_amount', 
        13 => 't.id'             
    ];

    $order_by = $columns_map[$order_column_index] ?? 't.date';

    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE t.parent_id IS NULL "; 

    // Genel Arama
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :global_search 
            OR tc.code LIKE :global_search 
            OR t.invoice_no LIKE :global_search 
            OR t.description LIKE :global_search
            OR t.id LIKE :global_search
        )";
    }

    // Kolon Filtreleme
    $column_searches = [];
    if (isset($_POST['columns'])) {
        foreach ($_POST['columns'] as $colIdx => $colData) {
            $colSearchVal = $colData['search']['value'] ?? '';
            
            if (!empty($colSearchVal)) {
                switch ($colIdx) {
                    case 1: // Durum (Ödeme Durumu)
                        if ($colSearchVal == 'paid') $sql_base .= " AND t.payment_status = 'paid' ";
                        elseif ($colSearchVal == 'unpaid') $sql_base .= " AND t.payment_status = 'unpaid' ";
                        elseif ($colSearchVal == 'partial') $sql_base .= " AND t.payment_status != 'paid' AND (SELECT SUM(amount) FROM transactions WHERE parent_id = t.id) > 0 ";
                        break;
                    case 2: // YENİ: İŞLEMLER (Onay, Öncelik, Kontrol)
                        if ($colSearchVal == 'approved') $sql_base .= " AND t.is_approved = 1 ";
                        elseif ($colSearchVal == 'priority') $sql_base .= " AND t.is_priority = 1 ";
                        elseif ($colSearchVal == 'control') $sql_base .= " AND t.needs_control = 1 ";
                        break;
                    case 3: // ID
                        $sql_base .= " AND t.id LIKE :col_id "; $column_searches[':col_id'] = "%$colSearchVal%"; break;
                    case 4: // Belge
                        $sql_base .= " AND t.doc_type = :col_doc "; $column_searches[':col_doc'] = $colSearchVal; break;
                    case 5: // Tarih
                        $sql_base .= " AND t.date LIKE :col_date "; $column_searches[':col_date'] = "%$colSearchVal%"; break;
                    case 6: // Bölüm
                        $sql_base .= " AND d.name LIKE :col_dept "; $column_searches[':col_dept'] = "%$colSearchVal%"; break;
                    case 7: // Cari
                        $sql_base .= " AND c.company_name LIKE :col_cust "; $column_searches[':col_cust'] = "%$colSearchVal%"; break;
                    case 8: // Tur
                        $sql_base .= " AND tc.code LIKE :col_tour "; $column_searches[':col_tour'] = "%$colSearchVal%"; break;
                    case 9: // Fatura
                        $sql_base .= " AND t.invoice_no LIKE :col_inv "; $column_searches[':col_inv'] = "%$colSearchVal%"; break;
                    case 10: // Açıklama
                        $sql_base .= " AND t.description LIKE :col_desc "; $column_searches[':col_desc'] = "%$colSearchVal%"; break;
                }
            }
        }
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE parent_id IS NULL");
    $total_records = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    if (!empty($search_value)) { $stmt->bindValue(':global_search', "%$search_value%"); }
    foreach ($column_searches as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->execute();
    $filtered_records = $stmt->fetchColumn();

    $sql = "SELECT t.*, 
            (SELECT SUM(amount) FROM transactions WHERE parent_id = t.id) as total_paid,
            d.name as dep_name, c.company_name, c.id as cust_id, tc.code as tour_code, tc.id as tour_id 
            " . $sql_base . " 
            ORDER BY $order_by $order_dir 
            LIMIT $start, $length";

    $stmt = $pdo->prepare($sql);
    if (!empty($search_value)) { $stmt->bindValue(':global_search', "%$search_value%"); }
    foreach ($column_searches as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response_data = [];
    foreach ($data as $row) {
        
        $amount = (float)$row['amount'];
        $paid = (float)$row['total_paid'];
        $remaining = $amount - $paid;

        // Tutar Görünümü
        $main_amount_display = number_format($amount, 2, ',', '.') . ' ₺';
        
        if ($paid > 0) {
            $paid_display = number_format($paid, 2, ',', '.') . ' ₺';
            $rem_display  = number_format($remaining, 2, ',', '.') . ' ₺';
            $paid_icon = ($remaining <= 0.05) ? '<i class="fa fa-check-double"></i>' : '<i class="fa fa-check"></i>';
            $rem_html = ($remaining > 0.05) ? '<br><small class="text-danger fw-bold">Kalan: ' . $rem_display . '</small>' : '';

            $tl_amt_html = '<div class="d-flex flex-column align-items-end">
                                <span class="fw-bold text-dark" style="font-size:1rem;">' . $main_amount_display . '</span>
                                <small class="text-success" style="font-size:0.75rem;">' . $paid_icon . ' Ödenen: ' . $paid_display . '</small>
                                ' . $rem_html . '
                            </div>';
        } else {
            $tl_amt_html = '<span class="fw-bold text-dark" style="font-size:1rem;">' . $main_amount_display . '</span>';
        }

        // Durum
        $today = date('Y-m-d');
        $trans_date = date('Y-m-d', strtotime($row['date']));
        $row_class = '';

        if ($remaining <= 0.05) {
            $status_text = 'Tamamlandı';
            $status_class = 'bg-primary'; 
            $row_class = 'table-light text-muted';
        } else {
            if ($paid > 0) {
                $status_text = 'Kısmi'; 
                $status_class = 'bg-info text-dark';
            } else {
                $status_text = 'Planlandı';
                $status_class = 'bg-secondary';
            }

            if ($trans_date < $today) {
                $row_class = 'table-danger border-danger'; 
                $status_text .= ' <br><strong class="text-danger blink">(Gecikmiş)</strong>';
            } elseif ($trans_date == $today) {
                $row_class = 'table-warning border-warning fw-bold';
                $status_text .= ' <br><strong class="text-dark">(Bugün)</strong>';
            } else {
                $row_class = 'table-success bg-opacity-10';
            }
        }
        $status_badge = '<span class="badge '.$status_class.' d-block">'.$status_text.'</span>';

        // İşlemler Butonları (DÜZELTİLDİ: Sınıflar Eklendi)
        $app_active = $row['is_approved'] ? 'active approval' : '';
        $prio_active = $row['is_priority'] ? 'active priority' : '';
        $cont_active = $row['needs_control'] ? 'active control' : '';

        $btn_history = '<i class="fa fa-history text-info toggle-btn" onclick="openLogModal('.$row['id'].')" title="Geçmiş"></i>';
        
        $btn_delete = '';
        if ($can_delete) {
            $btn_delete = '<i class="fa fa-trash text-danger toggle-btn" onclick="deleteTransaction('.$row['id'].')" title="Sil"></i>';
        }

        if ($is_admin) {
            $btn_class = 'action-icon'; // Yeni CSS sınıfı
            $act_approve = 'onclick="toggleStatus('.$row['id'].', \'approve\', this)"';
            $act_priority = 'onclick="toggleStatus('.$row['id'].', \'priority\', this)"';
            $act_check = 'onclick="toggleStatus('.$row['id'].', \'check\', this)"';
        } else {
            $btn_class = 'disabled-btn action-icon';
            $act_approve = ''; $act_priority = ''; $act_check = '';
        }

        $actions = '
        <div class="d-flex justify-content-center gap-3 align-items-center">
            ' . $btn_history . '
            <i class="fa fa-check-circle '.$btn_class.' '.$app_active.'" '.$act_approve.' title="Onay"></i>
            <i class="fa fa-star '.$btn_class.' '.$prio_active.'" '.$act_priority.' title="Öncelik"></i>
            <i class="fa fa-search '.$btn_class.' '.$cont_active.'" '.$act_check.' title="Kontrol"></i>
            ' . $btn_delete . '
        </div>';

        $doc_text = ($row['doc_type'] == 'invoice_order') ? 'Fatura' : 'Ödeme';
        $doc_badge = '<span class="badge bg-secondary">'.$doc_text.'</span>';
        $org_amt = ($row['currency'] != 'TRY') ? number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        $checkbox_html = '<div class="form-check d-flex justify-content-center align-items-center gap-2">
                            <input class="form-check-input row-select" type="checkbox" data-amount="'.$remaining.'" data-id="'.$row['id'].'">
                            <span class="text-muted small">'.$row['id'].'</span>
                          </div>';

        $cari_link = !empty($row['company_name']) ? '<a href="customer-details.php?id='.$row['cust_id'].'" class="text-decoration-none fw-bold text-dark" target="_blank">'.guvenli_html($row['company_name']).'</a>' : '-';
        $tour_link = !empty($row['tour_code']) ? '<a href="projects.php?id='.$row['tour_id'].'" class="text-decoration-none badge bg-info text-dark" target="_blank">'.$row['tour_code'].'</a>' : '-';

        $response_data[] = [
            '', // 0
            $status_badge, // 1
            $actions, // 2
            $checkbox_html, // 3
            $row['doc_type'], // 4
            date('d.m.Y', strtotime($row['date'])), // 5
            $row['dep_name'], // 6
            $cari_link, // 7
            $tour_link, // 8
            guvenli_html($row['invoice_no']), // 9
            guvenli_html($row['description']), // 10
            $tl_amt_html, // 11
            $org_amt, // 12
            '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>', // 13
            "DT_RowClass" => $row_class 
        ];
    }

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>