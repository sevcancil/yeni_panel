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
        0 => 't.id', 1 => 't.payment_status', 2 => 't.id', 3 => 't.id', 
        4 => 't.doc_type', 5 => 't.date', 6 => 'd.name', 7 => 'c.company_name', 
        8 => 'tc.code', 9 => 't.invoice_no', 10 => 't.description', 
        11 => 't.amount', 12 => 't.original_amount', 13 => 't.id'
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
                    case 1: // Durum Filtresi
                        if ($colSearchVal == 'paid') $sql_base .= " AND t.payment_status = 'paid' ";
                        elseif ($colSearchVal == 'unpaid') $sql_base .= " AND t.payment_status = 'unpaid' ";
                        elseif ($colSearchVal == 'partial') $sql_base .= " AND t.payment_status != 'paid' AND (SELECT SUM(amount) FROM transactions WHERE parent_id = t.id AND (type='payment_out' OR type='payment_in') AND is_deleted = 0) > 0 ";
                        break;
                    case 2: // İşlem Durumları
                        if ($colSearchVal == 'approved') $sql_base .= " AND t.is_approved = 1 ";
                        elseif ($colSearchVal == 'priority') $sql_base .= " AND t.is_priority = 1 ";
                        elseif ($colSearchVal == 'control') $sql_base .= " AND t.needs_control = 1 ";
                        break;
                    case 3: $sql_base .= " AND t.id LIKE :col_id "; $column_searches[':col_id'] = "%$colSearchVal%"; break;
                    case 4: $sql_base .= " AND t.doc_type = :col_doc "; $column_searches[':col_doc'] = $colSearchVal; break;
                    // --- TARİH FİLTRESİ (GÜNCELLENDİ) ---
                    case 5:
                        // Gelen veri formatı: "2023-01-01|2023-01-31" (Pipe ile ayrılmış)
                        if (strpos($colSearchVal, '|') !== false) {
                            $dates = explode('|', $colSearchVal);
                            $startDate = $dates[0] ?? '';
                            $endDate = $dates[1] ?? '';

                            if (!empty($startDate) && !empty($endDate)) {
                                $sql_base .= " AND t.date BETWEEN :start_date AND :end_date ";
                                $column_searches[':start_date'] = $startDate;
                                $column_searches[':end_date'] = $endDate;
                            } elseif (!empty($startDate)) {
                                $sql_base .= " AND t.date >= :start_date ";
                                $column_searches[':start_date'] = $startDate;
                            } elseif (!empty($endDate)) {
                                $sql_base .= " AND t.date <= :end_date ";
                                $column_searches[':end_date'] = $endDate;
                            }
                        } else {
                            // Eski usül tek tarih araması (Yedek)
                            $sql_base .= " AND t.date LIKE :col_date "; 
                            $column_searches[':col_date'] = "%$colSearchVal%"; 
                        }
                        break;
                    case 6: $sql_base .= " AND d.name LIKE :col_dept "; $column_searches[':col_dept'] = "%$colSearchVal%"; break;
                    case 7: $sql_base .= " AND c.company_name LIKE :col_cust "; $column_searches[':col_cust'] = "%$colSearchVal%"; break;
                    case 8: $sql_base .= " AND tc.code LIKE :col_tour "; $column_searches[':col_tour'] = "%$colSearchVal%"; break;
                    case 9: $sql_base .= " AND t.invoice_no LIKE :col_inv "; $column_searches[':col_inv'] = "%$colSearchVal%"; break;
                    case 10: $sql_base .= " AND t.description LIKE :col_desc "; $column_searches[':col_desc'] = "%$colSearchVal%"; break;
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

    // TOTAL PAID HESAPLAMASI
    $sql = "SELECT t.*, 
            (SELECT COALESCE(SUM(amount),0) FROM transactions 
             WHERE parent_id = t.id 
             AND (type = 'payment_out' OR type = 'payment_in') 
             AND is_deleted = 0 
            ) as total_paid,
            d.name as dep_name, c.company_name, c.id as cust_id, tc.code as tour_code, tc.id as tour_id,
            c.tax_number, c.tc_number, c.tax_office, c.address, c.city, c.country
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
        
        // Silinme Kontrolü
        $is_deleted = isset($row['is_deleted']) && $row['is_deleted'] == 1;

        // Renklendirme ve Durum
        $row_class = '';
        $status_badge = '';

        if ($is_deleted) {
            $row_class = 'table-danger text-decoration-line-through text-muted';
            $status_badge = '<span class="badge bg-danger d-block">İPTAL EDİLDİ</span>';
        } 
        else {
            if ($remaining <= 0.05) {
                $row_class = 'table-light text-muted';
                $status_badge = '<span class="badge bg-primary d-block">Tamamlandı</span>';
            } else {
                if ($row['doc_type'] == 'payment_order') {
                    $row_class = 'table-danger bg-opacity-10'; // Kırmızı (Gider)
                } elseif ($row['doc_type'] == 'invoice_order') {
                    $row_class = 'table-success bg-opacity-10'; // Yeşil (Gelir)
                }
                
                if (date('Y-m-d', strtotime($row['date'])) < date('Y-m-d')) {
                    $row_class .= ' border-start border-3 border-dark'; // Gecikmiş
                }

                if ($paid > 0) {
                    $status_badge = '<span class="badge bg-info text-dark d-block">Kısmi Ödendi</span>';
                } elseif (!empty($row['invoice_no'])) {
                    $status_badge = '<span class="badge bg-warning text-dark d-block">Faturalandı</span>';
                } else {
                    $status_badge = '<span class="badge bg-secondary d-block">Planlandı</span>';
                }
            }
        }

        // Tutar Görünümü ve ETİKET DÜZELTMESİ (BURASI DEĞİŞTİ)
        $main_amount_display = number_format($amount, 2, ',', '.') . ' ₺';
        
        // Etiket Belirleme: Gelir ise "Tahsil Edilen", Gider ise "Ödenen"
        $paid_label = ($row['doc_type'] == 'invoice_order') ? 'Tahsil Edilen' : 'Ödenen';

        if ($paid > 0 && !$is_deleted) {
            $paid_display = number_format($paid, 2, ',', '.') . ' ₺';
            $rem_display  = number_format($remaining, 2, ',', '.') . ' ₺';
            $paid_icon = ($remaining <= 0.05) ? '<i class="fa fa-check-double"></i>' : '<i class="fa fa-check"></i>';
            $rem_html = ($remaining > 0.05) ? '<br><small class="text-danger fw-bold">Kalan: ' . $rem_display . '</small>' : '';
            
            // Etiket burada kullanılıyor
            $tl_amt_html = '<div class="d-flex flex-column align-items-end">
                                <span class="fw-bold text-dark" style="font-size:1rem;">' . $main_amount_display . '</span>
                                <small class="text-success" style="font-size:0.75rem;">' . $paid_icon . ' ' . $paid_label . ': ' . $paid_display . '</small>' . $rem_html . '
                            </div>';
        } else {
            $tl_amt_html = '<span class="fw-bold text-dark" style="font-size:1rem;">' . $main_amount_display . '</span>';
        }

        // Butonlar
        $app_active = $row['is_approved'] ? 'active approval' : '';
        $prio_active = $row['is_priority'] ? 'active priority' : '';
        $cont_active = $row['needs_control'] ? 'active control' : '';

        $btn_history = '<i class="fa fa-history text-info toggle-btn" onclick="openLogModal('.$row['id'].')" title="Geçmiş"></i>';
        
        $btn_invoice = '';
        if (!$is_deleted && $row['doc_type'] == 'payment_order') {
            $inv_icon_class = empty($row['invoice_no']) ? 'text-primary' : 'text-success';
            $inv_title = empty($row['invoice_no']) ? 'Fatura Girişi Yap' : 'Faturayı Düzenle';
            $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
            $btn_invoice = "<i class='fa fa-file-invoice $inv_icon_class action-icon' onclick='openInvoiceModal($row_json)' title='$inv_title'></i>";
        }
        
        $btn_delete = '';
        if ($can_delete) {
            $btn_delete = '<i class="fa fa-trash text-danger toggle-btn" onclick="deleteTransaction('.$row['id'].')" title="Sil"></i>';
        }

        if ($is_admin && !$is_deleted) {
            $btn_class = 'action-icon';
            $act_approve = 'onclick="toggleStatus('.$row['id'].', \'approve\', this)"';
            $act_priority = 'onclick="toggleStatus('.$row['id'].', \'priority\', this)"';
            $act_check = 'onclick="toggleStatus('.$row['id'].', \'check\', this)"';
        } else {
            $btn_class = 'disabled-btn action-icon';
            $act_approve = ''; $act_priority = ''; $act_check = '';
        }

        if ($is_deleted) {
            $actions = '<div class="text-center text-danger"><i class="fa fa-ban"></i></div>';
        } else {
            $actions = '
            <div class="d-flex justify-content-center gap-3 align-items-center">
                ' . $btn_history . '
                ' . $btn_invoice . ' 
                <i class="fa fa-check-circle '.$btn_class.' '.$app_active.'" '.$act_approve.' title="Onay"></i>
                <i class="fa fa-star '.$btn_class.' '.$prio_active.'" '.$act_priority.' title="Öncelik"></i>
                <i class="fa fa-search '.$btn_class.' '.$cont_active.'" '.$act_check.' title="Kontrol"></i>
                ' . $btn_delete . '
            </div>';
        }

        $doc_badge = ($row['doc_type'] == 'invoice_order') 
            ? '<span class="badge bg-success">GELİR</span>' 
            : '<span class="badge bg-danger">GİDER</span>';

        $org_amt = ($row['currency'] != 'TRY') ? number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        $checkbox_html = '<div class="form-check d-flex justify-content-center align-items-center gap-2">
                            <input class="form-check-input row-select" type="checkbox" data-amount="'.$remaining.'" data-id="'.$row['id'].'">
                            <span class="text-muted small">'.$row['id'].'</span>
                          </div>';

        $cari_link = !empty($row['company_name']) ? '<a href="customer-details.php?id='.$row['cust_id'].'" class="text-decoration-none fw-bold text-dark" target="_blank">'.guvenli_html($row['company_name']).'</a>' : '-';
        $tour_link = !empty($row['tour_code']) ? '<a href="projects.php?id='.$row['tour_id'].'" class="text-decoration-none badge bg-info text-dark" target="_blank">'.$row['tour_code'].'</a>' : '-';

        $response_data[] = [
            '', $status_badge, $actions, $checkbox_html, $doc_badge,
            date('d.m.Y', strtotime($row['date'])),
            $row['dep_name'], $cari_link, $tour_link,
            guvenli_html($row['invoice_no']), guvenli_html($row['description']),
            $tl_amt_html, $org_amt,
            ($is_deleted ? '' : '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>'),
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