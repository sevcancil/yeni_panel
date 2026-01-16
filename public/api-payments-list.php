<?php
// public/api-payments-list.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Hataları gizle (JSON formatını bozmaması için)
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

    // Sütun Haritası
    $columns = [
        0 => 't.id', 
        1 => 't.id',
        2 => 'd.name',
        3 => 't.date',
        4 => 't.doc_type',
        5 => 'c.company_name',
        6 => 'tc.code',
        7 => 't.invoice_no',
        8 => 't.amount',
        9 => 't.original_amount',
        10 => 't.id'
    ];

    $order_by = $columns[$order_column_index] ?? 't.date';

    // --- SQL HAZIRLIĞI ---
    $sql_base = "FROM transactions t 
                 LEFT JOIN departments d ON t.department_id = d.id 
                 LEFT JOIN customers c ON t.customer_id = c.id 
                 LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id
                 WHERE 1=1 "; // DÜZELTME: 'unpaid' filtresi kaldırıldı, hepsi gelsin.

    // 1. GENEL ARAMA
    if (!empty($search_value)) {
        $sql_base .= " AND (
            c.company_name LIKE :search 
            OR tc.code LIKE :search 
            OR t.invoice_no LIKE :search 
            OR t.description LIKE :search
            OR d.name LIKE :search
        )";
    }

    // 2. SÜTUN FİLTRELERİ
    if (!empty($_POST['columns'][1]['search']['value'])) { 
        $sql_base .= " AND t.id = " . intval($_POST['columns'][1]['search']['value']);
    }
    if (!empty($_POST['columns'][2]['search']['value'])) { 
        $sql_base .= " AND d.name LIKE '%" . $_POST['columns'][2]['search']['value'] . "%'";
    }
    if (!empty($_POST['columns'][3]['search']['value'])) { 
        $sql_base .= " AND t.date LIKE '%" . $_POST['columns'][3]['search']['value'] . "%'";
    }
    if (!empty($_POST['columns'][5]['search']['value'])) { 
        $sql_base .= " AND c.company_name LIKE '%" . $_POST['columns'][5]['search']['value'] . "%'";
    }
    if (!empty($_POST['columns'][6]['search']['value'])) { 
        $sql_base .= " AND tc.code LIKE '%" . $_POST['columns'][6]['search']['value'] . "%'";
    }
    if (!empty($_POST['columns'][7]['search']['value'])) { 
        $sql_base .= " AND t.invoice_no LIKE '%" . $_POST['columns'][7]['search']['value'] . "%'";
    }

    // Toplam Kayıt
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
    $total_records = $stmt->fetchColumn();

    // Filtrelenmiş Kayıt
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

    // --- JSON FORMATLAMA ---
    
    // Yetki Kontrolü (DÜZELTME: has_permission kullanıldı)
    $can_approve = has_permission('approve_payment') || (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');

    $response_data = [];
    foreach ($data as $row) {
        
        // 1. SÜTUN: İŞLEMLER (BUTONLAR)
        $actions = '';
        if ($can_approve) {
            $app_active = $row['is_approved'] ? 'active' : '';
            
            // Veritabanında priority veya is_priority olabilir
            $prio_val = isset($row['priority']) ? $row['priority'] : (isset($row['is_priority']) ? $row['is_priority'] : 0);
            $prio_active = $prio_val ? 'active' : '';
            
            $cont_active = isset($row['needs_control']) && $row['needs_control'] ? 'active' : '';

            $actions = '
            <div class="d-flex justify-content-center gap-2">
                <i class="fa fa-check-circle toggle-btn text-approval '.$app_active.'" 
                   onclick="toggleStatus('.$row['id'].', \'approve\', this)" 
                   title="Ödeme Onayı"></i>
                
                <i class="fa fa-exclamation-circle toggle-btn text-priority '.$prio_active.'" 
                   onclick="toggleStatus('.$row['id'].', \'priority\', this)" 
                   title="Acil / Öncelikli"></i>

                <i class="fa fa-search toggle-btn text-control '.$cont_active.'" 
                   onclick="toggleStatus('.$row['id'].', \'check\', this)" 
                   title="Kontrol Edilecek"></i>
            </div>';
        } else {
            $actions = '<small class="text-muted">Yetki Yok</small>';
        }

        // Döviz
        $currency_display = '-';
        if ($row['currency'] != 'TRY') {
            $currency_display = number_format($row['original_amount'], 2, ',', '.') . ' ' . $row['currency'];
            $currency_display .= '<br><small class="text-muted" style="font-size:10px">Kur: '.$row['exchange_rate'].'</small>';
        }

        // Belge Tipi Rozeti
        $doc_badge = '';
        if($row['doc_type'] == 'invoice_order') $doc_badge = '<span class="badge bg-info text-dark">Fatura</span>';
        elseif($row['doc_type'] == 'payment_order') $doc_badge = '<span class="badge bg-warning text-dark">Ödeme</span>';
        else $doc_badge = '<span class="badge bg-secondary">Diğer</span>';

        // Satır Verisi
        $response_data[] = [
            $actions, // 0. İşlemler
            $row['id'], // 1. ID
            $row['dep_name'], // 2. Bölüm
            date('d.m.Y', strtotime($row['date'])), // 3. Tarih
            $doc_badge, // 4. Belge
            guvenli_html($row['company_name']), // 5. Cari
            $row['tour_code'] ? '<span class="badge bg-secondary">'.$row['tour_code'].'</span>' : '-', // 6. Tur
            guvenli_html($row['invoice_no']), // 7. Fatura
            '<strong>' . number_format($row['amount'], 2, ',', '.') . ' ₺</strong>', // 8. Tutar TL
            $currency_display, // 9. Döviz
            '<button class="btn btn-sm btn-primary" onclick="openEditModal('.$row['id'].')"><i class="fa fa-edit"></i></button>' // 10. İşlem Yap
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