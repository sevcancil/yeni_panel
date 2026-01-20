<?php
// public/api-payments-list.php

// 1. Çıktı Tamponlama
ob_start();

session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Tamponu temizle
ob_end_clean();

// 2. JSON Başlığı
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

try {
    // --- YETKİ KONTROLÜ ---
    $is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

    // DataTables Parametreleri
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $length = isset($_POST['length']) ? intval($_POST['length']) : 50;
    
    // GENEL ARAMA (Global Search)
    $search_value = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    
    // SIRALAMA
    $order_column_index = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : 5;
    $order_dir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'desc';

    // Tablo Sütun Haritası (Index => Veritabanı Sütunu)
    $columns_map = [
        0 => 't.id', 
        1 => 't.payment_status',
        2 => 't.id', 
        3 => 't.id', 
        4 => 'd.name',
        5 => 't.date',
        6 => 't.doc_type',
        7 => 'c.company_name',
        8 => 'tc.code',
        9 => 't.invoice_no',
        10 => 't.amount',
        11 => 't.original_amount',
        12 => 't.id'
    ];

    $order_by = $columns_map[$order_column_index] ?? 't.date';

    // SQL Temeli
    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE t.parent_id IS NULL "; 

    // --- 1. GENEL ARAMA (Global Search) ---
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :global_search 
            OR tc.code LIKE :global_search 
            OR t.invoice_no LIKE :global_search 
            OR t.description LIKE :global_search
            OR t.id LIKE :global_search
        )";
    }

    // --- 2. KOLON BAZLI ARAMA (Column Search - YENİ EKLENDİ) ---
    $column_searches = [];
    if (isset($_POST['columns'])) {
        foreach ($_POST['columns'] as $colIdx => $colData) {
            $colSearchVal = $colData['search']['value'] ?? '';
            
            if (!empty($colSearchVal)) {
                // Kolon indexine göre hangi sütunda arama yapılacağını belirle
                switch ($colIdx) {
                    case 3: // ID
                        $sql_base .= " AND t.id LIKE :col_search_id ";
                        $column_searches[':col_search_id'] = "%$colSearchVal%";
                        break;
                    case 4: // Bölüm
                        $sql_base .= " AND d.name LIKE :col_search_dept ";
                        $column_searches[':col_search_dept'] = "%$colSearchVal%";
                        break;
                    case 5: // Tarih
                        // Tarih formatı YYYY-MM-DD ise direk, değilse LIKE
                        $sql_base .= " AND t.date LIKE :col_search_date ";
                        $column_searches[':col_search_date'] = "%$colSearchVal%";
                        break;
                    case 7: // Cari / Firma
                        $sql_base .= " AND c.company_name LIKE :col_search_customer ";
                        $column_searches[':col_search_customer'] = "%$colSearchVal%";
                        break;
                    case 8: // Tur Kodu
                        $sql_base .= " AND tc.code LIKE :col_search_tour ";
                        $column_searches[':col_search_tour'] = "%$colSearchVal%";
                        break;
                    case 9: // Fatura No
                        $sql_base .= " AND t.invoice_no LIKE :col_search_inv ";
                        $column_searches[':col_search_inv'] = "%$colSearchVal%";
                        break;
                }
            }
        }
    }

    // --- EKSTRA FİLTRELER ---
    if (isset($_POST['filter_invoice_pending']) && $_POST['filter_invoice_pending'] == 'true') {
        $sql_base .= " AND t.invoice_status = 'pending'";
    }

    // --- TOPLAM KAYIT SAYISI (Filtresiz) ---
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE parent_id IS NULL");
    $total_records = $stmt->fetchColumn();

    // --- FİLTRELENMİŞ KAYIT SAYISI ---
    $stmt = $pdo->prepare("SELECT COUNT(*) " . $sql_base);
    
    // Bind Parametreleri (Global Arama)
    if (!empty($search_value)) { $stmt->bindValue(':global_search', "%$search_value%"); }
    
    // Bind Parametreleri (Kolon Arama)
    foreach ($column_searches as $key => $val) { $stmt->bindValue($key, $val); }
    
    $stmt->execute();
    $filtered_records = $stmt->fetchColumn();

    // --- VERİ ÇEKME ---
    $sql = "SELECT t.*, 
            (SELECT SUM(amount) FROM transactions WHERE parent_id = t.id) as total_paid,
            d.name as dep_name, c.company_name, tc.code as tour_code 
            " . $sql_base . " 
            ORDER BY $order_by $order_dir 
            LIMIT $start, $length";

    $stmt = $pdo->prepare($sql);
    
    // Bind Parametreleri (Tekrar Bind Etmemiz Gerek)
    if (!empty($search_value)) { $stmt->bindValue(':global_search', "%$search_value%"); }
    foreach ($column_searches as $key => $val) { $stmt->bindValue($key, $val); }
    
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- VERİ FORMATLAMA ---
    $response_data = [];
    foreach ($data as $row) {
        
        // Hesaplamalar
        $amount = (float)$row['amount'];
        $paid = (float)$row['total_paid'];
        $remaining = $amount - $paid;

        // Tutar Görünümü
        $main_amount_display = number_format($amount, 2, ',', '.') . ' ₺';
        
        if ($paid > 0) {
            $paid_display = number_format($paid, 2, ',', '.') . ' ₺';
            $paid_icon = ($remaining <= 0.05) ? '<i class="fa fa-check-double"></i>' : '<i class="fa fa-check"></i>';
            $tl_amt_html = '<div class="d-flex flex-column align-items-end"><span class="fw-bold text-dark" style="font-size:1rem;">' . $main_amount_display . '</span><small class="text-success fw-bold" style="font-size:0.75rem;">' . $paid_icon . ' Ödenen: ' . $paid_display . '</small></div>';
        } else {
            $tl_amt_html = '<span class="fw-bold text-dark" style="font-size:1rem;">' . $main_amount_display . '</span>';
        }

        // Durum Belirleme
        $today = date('Y-m-d');
        $trans_date = date('Y-m-d', strtotime($row['date']));
        $row_class = '';

        if ($remaining <= 0.05) {
            $status_text = 'Tamamlandı';
            $status_class = 'bg-primary'; 
            $row_class = 'table-light text-muted';
        } else {
            if ($paid > 0) {
                $lbl = ($row['type'] == 'debt') ? 'Kısmi Ödeme' : 'Kısmi Tahsilat';
                $status_text = $lbl . '<br><small style="font-size:0.7em;">Kalan: '.number_format($remaining, 2, ',', '.').'</small>';
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

        // Fatura Uyarısı
        $invoice_display = '';
        if (!empty($row['invoice_no'])) {
            $invoice_display = '<span class="fw-bold text-dark">'.guvenli_html($row['invoice_no']).'</span>';
        } else {
            if ($row['type'] == 'debt') {
                $invoice_display = '<span class="badge bg-danger text-white"><i class="fa fa-exclamation-circle"></i> Fatura Gelmedi</span>';
            } else {
                // Tahsilat Durumu
                if ($row['invoice_status'] == 'to_be_issued') {
                    $invoice_display = '<span class="badge bg-warning text-dark"><i class="fa fa-clock"></i> Fatura Kesilecek</span>';
                } elseif ($row['invoice_status'] == 'waiting_approval') {
                    $invoice_display = '<span class="badge bg-secondary text-white">Onay Bekliyor</span>';
                } else {
                    $invoice_display = '<span class="badge bg-light text-dark border">Fatura Yok</span>';
                }
            }
        }

        // Yetkiler
        $app_active = $row['is_approved'] ? 'active' : '';
        $prio_active = $row['is_priority'] ? 'active' : '';
        $cont_active = $row['needs_control'] ? 'active' : '';

        // Log Butonu
        $btn_history = '<i class="fa fa-history text-info toggle-btn" 
                           onclick="openLogModal('.$row['id'].')" 
                           title="İşlem Geçmişi" 
                           style="cursor:pointer; font-size:1.2rem; opacity:1 !important;"></i>';

        if ($is_admin) {
            $btn_class = 'toggle-btn';
            $act_approve = 'onclick="toggleStatus('.$row['id'].', \'approve\', this)"';
            $act_priority = 'onclick="toggleStatus('.$row['id'].', \'priority\', this)"';
            $act_check = 'onclick="toggleStatus('.$row['id'].', \'check\', this)"';
            $title_suffix = '';
        } else {
            $btn_class = 'disabled-btn';
            $act_approve = ''; $act_priority = ''; $act_check = '';
            $title_suffix = ' (Yetki Yok)';
        }

        $actions = '
        <div class="d-flex justify-content-center gap-3 align-items-center">
            ' . $btn_history . '
            <i class="fa fa-check-circle '.$btn_class.' text-approval '.$app_active.'" 
               '.$act_approve.' title="Onay'.$title_suffix.'"></i>
            <i class="fa fa-star '.$btn_class.' text-priority '.$prio_active.'" 
               '.$act_priority.' title="Öncelik'.$title_suffix.'"></i>
            <i class="fa fa-search '.$btn_class.' text-control '.$cont_active.'" 
               '.$act_check.' title="Kontrol'.$title_suffix.'"></i>
        </div>';

        $doc_text = ($row['doc_type'] == 'invoice_order') ? 'Tahsilat' : 'Ödeme Emri';
        $doc_badge = '<span class="badge bg-secondary">'.$doc_text.'</span>';
        $org_amt = ($row['currency'] != 'TRY') ? number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'] : '-';

        $checkbox_html = '<div class="form-check d-flex justify-content-center align-items-center gap-2">
                            <input class="form-check-input row-select" type="checkbox" data-amount="'.$remaining.'" data-id="'.$row['id'].'">
                            <span class="text-muted small">'.$row['id'].'</span>
                          </div>';

        $response_data[] = [
            '', // 0: Detay
            $status_badge, // 1: Durum
            $actions, // 2: İşlemler
            $checkbox_html, // 3: ID ve Checkbox
            $row['dep_name'], // 4: Bölüm
            date('d.m.Y', strtotime($row['date'])), // 5: Tarih
            $doc_badge, // 6: Belge
            guvenli_html($row['company_name']), // 7: Cari
            $row['tour_code'], // 8: Tur
            $invoice_display, // 9: Fatura
            $tl_amt_html, // 10: Tutar
            $org_amt, // 11: Döviz
            '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>', // 12: Edit
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