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
    $can_delete = ($role === 'admin' || $role === 'muhasebe');

    // DataTables Parametreleri
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $length = isset($_POST['length']) ? intval($_POST['length']) : 50;
    $search_value = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    
    // Sıralama
    $order_column_index = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : 5; 
    $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'desc';

    // Sütun Haritası (SQL Sıralaması İçin)
    // DİKKAT: Tablodaki th sırasıyla birebir aynı olmalı.
    // 0:Child, 1:Durum, 2:ONAY(Yeni), 3:ID, 4:Belge, 5:Tarih, 6:Bölüm, 7:Cari, 8:Tur, 9:FatNo, 10:Desc, 11:Tutar, 12:Döviz, 13:Edit, 14:Sil, 15:History
    $columns_map = [
        0 => 't.id', 
        1 => 't.payment_status', 
        2 => 't.approval_status', // YENİ EKLENEN
        3 => 't.id', 
        4 => 't.doc_type', 
        5 => 'final_date', 
        6 => 'd.name', 
        7 => 'c.company_name', 
        8 => 'tc.code', 
        9 => 't.invoice_no', 
        10 => 't.description', 
        11 => 't.amount', 
        12 => 't.original_amount',
        13 => 't.id', 
        14 => 't.id', 
        15 => 't.id'
    ];

    $order_by = $columns_map[$order_column_index] ?? 'final_date';

    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE t.parent_id IS NULL AND t.is_deleted = 0"; 

    // Global Arama
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :global_search 
            OR tc.code LIKE :global_search 
            OR t.invoice_no LIKE :global_search 
            OR t.description LIKE :global_search
            OR t.id LIKE :global_search
        )";
    }

    // Sütun Filtreleri (INDEXLERE DİKKAT!)
    // Tabloya "Onay" sütunu eklediğimiz için (Index 2), ondan sonraki tüm sütunların indexi 1 kaydı.
    // HTML'deki inputların data-col-index değerlerini de buna göre ayarladım.
    $column_searches = [];
    if (isset($_POST['columns'])) {
        foreach ($_POST['columns'] as $colIdx => $colData) {
            $colSearchVal = $colData['search']['value'] ?? '';
            
            if (!empty($colSearchVal)) {
                switch ($colIdx) {
                    case 1: // Durum (Ödeme/Tahsilat)
                        if ($colSearchVal == 'income_invoice_paid') {
                            $sql_base .= " AND t.doc_type='invoice_order' AND t.invoice_status='issued' AND t.payment_status='paid' ";
                        } elseif ($colSearchVal == 'income_invoice_unpaid') {
                            $sql_base .= " AND t.doc_type='invoice_order' AND t.invoice_status='issued' AND t.payment_status!='paid' ";
                        } elseif ($colSearchVal == 'income_paid_no_invoice') {
                            $sql_base .= " AND t.doc_type='invoice_order' AND t.payment_status='paid' AND (t.invoice_no IS NULL OR t.invoice_no = '') ";
                        } elseif ($colSearchVal == 'expense_invoice_paid') {
                            $sql_base .= " AND t.doc_type='payment_order' AND t.invoice_status='issued' AND t.payment_status='paid' ";
                        } elseif ($colSearchVal == 'expense_invoice_unpaid') {
                            $sql_base .= " AND t.doc_type='payment_order' AND t.invoice_status='issued' AND t.payment_status!='paid' ";
                        } elseif ($colSearchVal == 'expense_paid_no_invoice') {
                            $sql_base .= " AND t.doc_type='payment_order' AND t.payment_status='paid' AND (t.invoice_no IS NULL OR t.invoice_no = '') ";
                        } elseif ($colSearchVal == 'partial') {
                            $sql_base .= " AND t.payment_status != 'paid' AND (SELECT SUM(amount) FROM transactions WHERE parent_id = t.id AND (type='payment_out' OR type='payment_in') AND is_deleted = 0) > 0 ";
                        } elseif ($colSearchVal == 'planned') {
                            $sql_base .= " AND t.payment_status = 'unpaid' AND (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE parent_id = t.id AND (type='payment_out' OR type='payment_in') AND is_deleted = 0) <= 0 ";
                        }
                        break;
                    
                    case 2: // ONAY FİLTRESİ (YENİ - İstersen buraya onay durumu filtresi ekleyebiliriz)
                        if ($colSearchVal == 'approved') $sql_base .= " AND t.approval_status = 'approved' ";
                        if ($colSearchVal == 'pending') $sql_base .= " AND t.approval_status = 'pending' ";
                        if ($colSearchVal == 'rejected') $sql_base .= " AND t.approval_status = 'rejected' ";
                        break;

                    case 3: $sql_base .= " AND t.id LIKE :col_id "; $column_searches[':col_id'] = "%$colSearchVal%"; break;
                    case 4: $sql_base .= " AND t.doc_type = :col_doc "; $column_searches[':col_doc'] = $colSearchVal; break;
                    case 5: // Tarih
                        if (strpos($colSearchVal, '|') !== false) {
                            $dates = explode('|', $colSearchVal);
                            if (!empty($dates[0])) { $sql_base .= " AND t.date >= :start_date "; $column_searches[':start_date'] = $dates[0]; }
                            if (!empty($dates[1])) { $sql_base .= " AND t.date <= :end_date "; $column_searches[':end_date'] = $dates[1]; }
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

    // Toplam Kayıt
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE parent_id IS NULL AND is_deleted = 0");
    $total_records = $stmt->fetchColumn();

    // Filtrelenmiş
    $stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    if (!empty($search_value)) { $stmt->bindValue(':global_search', "%$search_value%"); }
    foreach ($column_searches as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->execute();
    $filtered_records = $stmt->fetchColumn();

    // Veri Çekme
    $sql = "SELECT t.*, 
            IF(t.planned_date IS NOT NULL AND t.planned_date != '0000-00-00', t.planned_date, t.date) as final_date,
            (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE parent_id = t.id AND (type = 'payment_out' OR type = 'payment_in') AND is_deleted = 0) as total_paid,
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
        
        $is_paid = ($remaining <= 0.05);
        $has_invoice = ($row['invoice_status'] == 'issued' || !empty($row['invoice_no']));
        $is_income = ($row['doc_type'] == 'invoice_order');
        
        // --- Durum Sütunu ---
        $row_class = '';
        $status_badge = '';

        if ($is_paid) {
            if ($has_invoice) {
                $txt = $is_income ? 'Fatura Kesildi + Tahsilat OK' : 'Fatura Alındı + Ödeme OK';
                $cls = $is_income ? 'bg-success' : 'bg-primary';
                $status_badge = '<span class="badge '.$cls.' d-block">'.$txt.'</span>';
                $row_class = 'table-light text-muted'; 
            } else {
                $txt = $is_income ? 'Tahsil Edildi / Faturasız' : 'Ödendi / Faturasız';
                $status_badge = '<span class="badge bg-warning text-dark d-block">(!) '.$txt.'</span>';
                $row_class = 'table-warning'; 
            }
        } else {
            if ($has_invoice) {
                $txt = $is_income ? 'Fatura Kesildi (Tahsilat Bekliyor)' : 'Fatura Alındı (Ödeme Bekliyor)';
                $cls = $is_income ? 'bg-success bg-opacity-75' : 'bg-primary bg-opacity-75';
                $status_badge = '<span class="badge '.$cls.' d-block">'.$txt.'</span>';
                $row_class = $is_income ? 'table-success bg-opacity-10' : 'table-danger bg-opacity-10';
            } else {
                if ($paid > 0) {
                    $status_badge = '<span class="badge bg-info text-dark d-block">Kısmi İşlem</span>';
                    $row_class = $is_income ? 'table-success bg-opacity-25' : 'table-danger bg-opacity-25';
                } else {
                    $status_badge = '<span class="badge bg-secondary d-block">Planlandı</span>';
                    $row_class = $is_income ? 'table-success bg-opacity-10' : 'table-danger bg-opacity-10';
                }
            }
        }
        
        if (date('Y-m-d', strtotime($row['date'])) < date('Y-m-d') && !$is_paid) {
            $row_class .= ' border-start border-3 border-danger';
        }

        // --- ONAY SÜTUNU (YENİ EKLENEN TİK/ÇARPI) ---
        $approval_html = '';
        if ($row['doc_type'] == 'payment_order') { // Sadece giderlerde

            $p_date = date('d.m.Y', strtotime($row['final_date'])); 
            $js_params = "'$note', '$p_date'";
            // Not varsa onclick eventine notu gönderiyoruz
            $note_js = !empty($row['admin_note']) ? 'onclick="showNote(\''.guvenli_html($row['admin_note']).'\')"' : '';
            $cursor = !empty($row['admin_note']) ? 'cursor:pointer;' : '';

            if ($row['approval_status'] == 'approved') {
                $approval_html = '<i class="fa fa-check-circle text-success fa-lg" title="Onaylandı" style="'.$cursor.'" '.$note_js.'></i>';
            } elseif ($row['approval_status'] == 'rejected') {
                $approval_html = '<i class="fa fa-times-circle text-danger fa-lg" title="Reddedildi" style="'.$cursor.'" '.$note_js.'></i>';
            } elseif ($row['approval_status'] == 'correction_needed') {
                $approval_html = '<i class="fa fa-exclamation-circle text-warning fa-lg" title="Düzeltme İstendi" style="'.$cursor.'" '.$note_js.'></i>';
            } else {
                // Onay Bekliyor
                $approval_html = '<i class="fa fa-hourglass-start text-secondary fa-lg opacity-50" title="Onay Bekliyor"></i>';
            }
        }

        // --- ID Sütunu (Checkbox dahil) ---
        $id_html = '
        <div class="form-check d-flex align-items-center gap-2 m-0">
            <input class="form-check-input row-select" type="checkbox" 
                   value="'.$row['id'].'" 
                   data-id="'.$row['id'].'" 
                   data-amount="'.$remaining.'"
                   id="chk_'.$row['id'].'">
            <label class="form-check-label fw-bold small pt-1 cursor-pointer" for="chk_'.$row['id'].'">#'.$row['id'].'</label>
        </div>';

        // Belge Tipi
        $doc_badge = ($is_income) 
            ? '<span class="badge bg-success bg-opacity-10 text-success border border-success">GELİR</span>' 
            : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger">GİDER</span>';

        // Linkler
        $cari_link = !empty($row['company_name']) ? '<a href="customer-details.php?id='.$row['cust_id'].'" class="text-decoration-none fw-bold text-dark" target="_blank">'.guvenli_html($row['company_name']).'</a>' : '-';
        $tour_link = '-';
            if (!empty($row['tour_code'])) {
                // projects.php değil, doğrudan JS fonksiyonunu çağırıyoruz.
                // openProjectReport fonksiyonunu payment-orders.php içinde tanımlayacağız.
                $tour_link = '<span class="badge bg-info text-dark cursor-pointer" onclick="openProjectReport('.$row['tour_id'].')" title="Proje Raporunu Gör" style="cursor:pointer;">'.$row['tour_code'].'</span>';
            }

        // --- TARİH GÖSTERİMİ (KRİTİK NOKTA) ---
        // Artık veritabanından gelen 'final_date' alias'ını kullanıyoruz.
        // Bu alias SQL sorgusunda: IF(planned_date doluysa, planned_date, değilse date) olarak ayarlandı.
        $date_display = date('d.m.Y', strtotime($row['final_date']));

        // Eğer planlanan tarih ise rengini mavi yapalım ki belli olsun
        if (!empty($row['planned_date']) && $row['planned_date'] != '0000-00-00') {
            $date_display = '<span class="text-primary fw-bold" title="Planlanan Tarih">'.$date_display.'</span>';
        }

        // Tutar
        $amt_tl = number_format($amount, 2, ',', '.') . ' ₺';
        $amt_fx = ($row['currency'] != 'TRY') ? number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        // Butonlar
        $edit_btn = '<button class="btn btn-sm btn-outline-primary border-0" onclick="openEditModal('.$row['id'].')" title="Düzenle"><i class="fa fa-edit"></i></button>';
        $delete_btn = $can_delete ? '<button class="btn btn-sm btn-outline-danger border-0" onclick="deleteTransaction('.$row['id'].')" title="Sil"><i class="fa fa-trash"></i></button>' : '<button class="btn btn-sm btn-outline-secondary border-0" disabled><i class="fa fa-trash"></i></button>';
        $history_btn = '<button class="btn btn-sm btn-outline-info border-0" onclick="openLogModal('.$row['id'].')" title="Geçmiş & Loglar"><i class="fa fa-history"></i></button>';

        $response_data[] = [
            '', // 0
            $status_badge, // 1
            $approval_html, // 2 (YENİ ONAY SÜTUNU)
            $id_html, // 3
            $doc_badge, // 4
            $date_display, // 5
            $row['dep_name'], // 6
            $cari_link, // 7
            $tour_link, // 8
            guvenli_html($row['invoice_no']), // 9
            guvenli_html($row['description']), // 10
            $amt_tl, // 11
            $amt_fx, // 12
            $edit_btn, // 13
            $delete_btn, // 14
            $history_btn, // 15
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