<?php
// public/api-payments-list.php

// Tüm çıktı tamponlarını temizle ve hataları gizle (JSON bozulmasın diye)
ob_start();
session_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Tamponu temizle ki sadece JSON gitsin
ob_end_clean(); 

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) { throw new Exception("Oturum kapalı."); }

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

    // Sütun Haritası
    $columns_map = [
        0 => 't.id', 
        1 => 't.payment_status', 
        2 => 't.approval_status',
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

    // Temel Sorgu
    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE t.parent_id IS NULL AND t.is_deleted = 0"; 

    // Parametre dizisi
    $params = [];

    // Global Arama
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :global_search 
            OR tc.code LIKE :global_search 
            OR t.invoice_no LIKE :global_search 
            OR t.description LIKE :global_search
            OR t.id LIKE :global_search
        )";
        $params[':global_search'] = "%$search_value%";
    }

    // Sütun Filtreleri
    if (isset($_POST['columns'])) {
        foreach ($_POST['columns'] as $colIdx => $colData) {
            $colSearchVal = $colData['search']['value'] ?? '';
            
            if (!empty($colSearchVal)) {
                // Özel parametre ismi oluştur (çakışmayı önlemek için)
                $paramName = ":col_search_" . $colIdx;

                switch ($colIdx) {
                    case 1: // Durum
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
                    
                    case 2: // Onay
                        if ($colSearchVal == 'approved') $sql_base .= " AND t.approval_status = 'approved' ";
                        if ($colSearchVal == 'pending') $sql_base .= " AND t.approval_status = 'pending' ";
                        if ($colSearchVal == 'rejected') $sql_base .= " AND t.approval_status = 'rejected' ";
                        break;

                    case 3: $sql_base .= " AND t.id LIKE $paramName "; $params[$paramName] = "%$colSearchVal%"; break;
                    case 4: $sql_base .= " AND t.doc_type = $paramName "; $params[$paramName] = $colSearchVal; break;
                    case 5: // Tarih Aralığı
                        if (strpos($colSearchVal, '|') !== false) {
                            $dates = explode('|', $colSearchVal);
                            if (!empty($dates[0])) { $sql_base .= " AND t.date >= :start_date "; $params[':start_date'] = $dates[0]; }
                            if (!empty($dates[1])) { $sql_base .= " AND t.date <= :end_date "; $params[':end_date'] = $dates[1]; }
                        }
                        break;
                    case 6: $sql_base .= " AND d.name LIKE $paramName "; $params[$paramName] = "%$colSearchVal%"; break;
                    case 7: $sql_base .= " AND c.company_name LIKE $paramName "; $params[$paramName] = "%$colSearchVal%"; break;
                    case 8: $sql_base .= " AND tc.code LIKE $paramName "; $params[$paramName] = "%$colSearchVal%"; break;
                    case 9: $sql_base .= " AND t.invoice_no LIKE $paramName "; $params[$paramName] = "%$colSearchVal%"; break;
                    case 10: $sql_base .= " AND t.description LIKE $paramName "; $params[$paramName] = "%$colSearchVal%"; break;
                }
            }
        }
    }

    // Toplam Kayıt Sayısı (Filtresiz)
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE parent_id IS NULL AND is_deleted = 0");
    $total_records = $stmt->fetchColumn();

    // Filtrelenmiş Kayıt Sayısı
    $stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->execute();
    $filtered_records = $stmt->fetchColumn();

    // Verileri Çek
    $sql = "SELECT t.*, 
            IF(t.planned_date IS NOT NULL AND t.planned_date != '0000-00-00', t.planned_date, t.date) as final_date,
            (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE parent_id = t.id AND (type = 'payment_out' OR type = 'payment_in') AND is_deleted = 0) as total_paid,
            d.name as dep_name, c.company_name, c.id as cust_id, tc.code as tour_code, tc.id as tour_id
            " . $sql_base . " 
            ORDER BY $order_by $order_dir 
            LIMIT $start, $length";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
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
        
        // --- Durum ve Renklendirme ---
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
            if ($has_invoice && $paid <= 0) {
                $txt = $is_income ? 'Fatura Kesildi (Tahsilat Bekliyor)' : 'Fatura Alındı (Ödeme Bekliyor)';
                $cls = $is_income ? 'bg-success bg-opacity-75' : 'bg-primary bg-opacity-75';
                $status_badge = '<span class="badge '.$cls.' d-block">'.$txt.'</span>';
                $row_class = $is_income ? 'table-success bg-opacity-10' : 'table-danger bg-opacity-10';
            } else {
                if ($paid > 0) {
                    $txt = $is_income ? 'Kısmi Tahsilat' : 'Kısmi Ödeme';
                    $status_badge = '<span class="badge bg-info text-dark d-block border border-info">'.$txt.'</span>';
                    $row_class = $is_income ? 'table-success bg-opacity-25' : 'table-danger bg-opacity-25';
                } else {
                    $status_badge = '<span class="badge bg-secondary d-block">Planlandı</span>';
                    $row_class = $is_income ? 'table-success bg-opacity-10' : 'table-danger bg-opacity-10';
                }
            }
        }
        
        // Gecikmiş ödeme kontrolü
        if (date('Y-m-d', strtotime($row['date'])) < date('Y-m-d') && !$is_paid) {
            $row_class .= ' border-start border-3 border-danger';
        }

        // --- Onay Sütunu ---
        $approval_html = '';
        if ($row['doc_type'] == 'payment_order') {
            $p_date = date('d.m.Y', strtotime($row['final_date']));
            // Javascript string hatası olmaması için addslashes
            $clean_note = addslashes(htmlspecialchars($row['admin_note'] ?? ''));
            $note_js = !empty($row['admin_note']) ? 'onclick="showNote(\''.$clean_note.'\')"' : '';
            $cursor = !empty($row['admin_note']) ? 'cursor:pointer;' : '';

            if ($row['approval_status'] == 'approved') {
                $approval_html = '<i class="fa fa-check-circle text-success fa-lg" title="Onaylandı" style="'.$cursor.'" '.$note_js.'></i>';
            } elseif ($row['approval_status'] == 'rejected') {
                $approval_html = '<i class="fa fa-times-circle text-danger fa-lg" title="Reddedildi" style="'.$cursor.'" '.$note_js.'></i>';
            } elseif ($row['approval_status'] == 'correction_needed') {
                $approval_html = '<i class="fa fa-exclamation-circle text-warning fa-lg" title="Düzeltme İstendi" style="'.$cursor.'" '.$note_js.'></i>';
            } else {
                $approval_html = '<i class="fa fa-hourglass-start text-secondary fa-lg opacity-50" title="Onay Bekliyor"></i>';
            }
        }

        // --- Diğer HTML İçerikleri ---
        $id_html = '<div class="form-check d-flex align-items-center gap-2 m-0"><input class="form-check-input row-select" type="checkbox" value="'.$row['id'].'" data-id="'.$row['id'].'" data-amount="'.$remaining.'" id="chk_'.$row['id'].'"><label class="form-check-label fw-bold small pt-1 cursor-pointer" for="chk_'.$row['id'].'">#'.$row['id'].'</label></div>';
        $doc_badge = ($is_income) ? '<span class="badge bg-success bg-opacity-10 text-success border border-success">GELİR</span>' : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger">GİDER</span>';
        
        $cari_link = !empty($row['company_name']) ? '<a href="customer-details.php?id='.$row['cust_id'].'" class="text-decoration-none fw-bold text-dark" target="_blank">'.guvenli_html($row['company_name']).'</a>' : '-';
        
        $tour_link = '-';
        if (!empty($row['tour_code'])) {
            $tour_link = '<span class="badge bg-info text-dark cursor-pointer" onclick="openProjectReport('.$row['tour_id'].')" title="Raporu Gör">'.$row['tour_code'].'</span>';
        }

        $date_display = date('d.m.Y', strtotime($row['final_date']));
        if (!empty($row['planned_date']) && $row['planned_date'] != '0000-00-00') {
            $date_display = '<span class="text-primary fw-bold" title="Planlanan Tarih">'.$date_display.'</span>';
        }

        // --- Tutar Gösterimi (HTML) ---
        $amt_tl = '<div class="fw-bold fs-6 text-nowrap">' . number_format($amount, 2, ',', '.') . ' ₺</div>';
        if ($paid > 0 && !$is_paid) {
            $amt_tl .= '<div class="mt-1 small" style="line-height:1.2;">';
            $amt_tl .= '<div class="text-success text-nowrap"><i class="fa fa-check-circle"></i> Öd: '.number_format($paid, 2, ',', '.').'</div>';
            $amt_tl .= '<div class="text-danger fw-bold text-nowrap"><i class="fa fa-hourglass-half"></i> Kal: '.number_format($remaining, 2, ',', '.').'</div>';
            $amt_tl .= '</div>';
        }
        
        $amt_fx = ($row['currency'] != 'TRY') ? number_format((float)$row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        $edit_btn = '<button class="btn btn-sm btn-outline-primary border-0" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>';
        $delete_btn = $can_delete ? '<button class="btn btn-sm btn-outline-danger border-0" onclick="deleteTransaction('.$row['id'].')"><i class="fa fa-trash"></i></button>' : '<button class="btn btn-sm btn-outline-secondary border-0" disabled><i class="fa fa-trash"></i></button>';
        $history_btn = '<button class="btn btn-sm btn-outline-info border-0" onclick="openLogModal('.$row['id'].')"><i class="fa fa-history"></i></button>';

        // --- UTF-8 Temizliği (Hata Kaynağını Önlemek İçin) ---
        // Veritabanından gelen metinlerde bozuk karakter varsa JSON patlar.
        // Bu yüzden metin alanlarını UTF-8'e zorluyoruz.
        $clean_desc = mb_convert_encoding($row['description'] ?? '', 'UTF-8', 'UTF-8');
        $clean_inv = mb_convert_encoding($row['invoice_no'] ?? '', 'UTF-8', 'UTF-8');

        $response_data[] = [
            '', 
            $status_badge, 
            $approval_html, 
            $id_html, 
            $doc_badge, 
            $date_display, 
            mb_convert_encoding($row['dep_name'] ?? '', 'UTF-8', 'UTF-8'), 
            $cari_link, 
            $tour_link, 
            guvenli_html($clean_inv), 
            guvenli_html($clean_desc), 
            $amt_tl, 
            $amt_fx, 
            $edit_btn, 
            $delete_btn, 
            $history_btn, 
            "DT_RowClass" => $row_class
        ];
    }

    $json_output = json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $response_data
    ], JSON_UNESCAPED_UNICODE);

    // Eğer JSON Encode hatası varsa bunu yakala
    if ($json_output === false) {
        throw new Exception("JSON Encode Hatası: " . json_last_error_msg());
    }

    echo $json_output;

} catch (Exception $e) {
    // Hata durumunda boş veri döndür ki tablo kilitlenmesin
    echo json_encode([
        "draw" => isset($_POST['draw']) ? intval($_POST['draw']) : 0,
        "recordsTotal" => 0,
        "recordsFiltered" => 0,
        "data" => [],
        "error" => $e->getMessage()
    ]);
}
?>