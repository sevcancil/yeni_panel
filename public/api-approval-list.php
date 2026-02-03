<?php
// public/api-approval-list.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json; charset=utf-8');

// Sadece Gider ve Ödenmemişler
$sql_base = "FROM transactions t 
             LEFT JOIN customers c ON t.customer_id = c.id 
             LEFT JOIN users u ON t.created_by = u.id
             WHERE t.doc_type = 'payment_order' 
             AND t.payment_status != 'paid' 
             AND t.is_deleted = 0 
             AND t.parent_id IS NULL";

// Arama
$search = $_POST['search']['value'] ?? '';
if($search) $sql_base .= " AND (c.company_name LIKE '%$search%' OR t.description LIKE '%$search%')";

$total = $pdo->query("SELECT COUNT(*) $sql_base")->fetchColumn();

// SQL - Tarih Mantığı: IF(planned_date doluysa, onu al, değilse date al) as final_date
$sql = "SELECT t.*, c.company_name, u.full_name as creator_name,
        IF(t.planned_date IS NOT NULL AND t.planned_date != '0000-00-00', t.planned_date, t.date) as final_date
        $sql_base 
        ORDER BY FIELD(t.approval_status, 'pending', 'correction_needed', 'approved', 'rejected'), 
                 FIELD(t.priority, 'high', 'medium', 'low'), 
                 final_date ASC 
        LIMIT " . intval($_POST['start'] ?? 0) . ", " . intval($_POST['length'] ?? 50);

$data = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$results = [];

foreach($data as $row) {
    // Renklendirme (Öncelik ve Duruma Göre)
    $row_class = '';
    if($row['priority'] == 'high' && $row['approval_status'] == 'pending') $row_class = 'priority-high';
    elseif($row['priority'] == 'medium' && $row['approval_status'] == 'pending') $row_class = 'priority-medium';

    // Durum Badge
    $status_badge = '<span class="badge bg-secondary">Bekliyor</span>';
    if($row['approval_status'] == 'approved') $status_badge = '<span class="badge bg-success">Onaylandı</span>';
    if($row['approval_status'] == 'correction_needed') $status_badge = '<span class="badge bg-warning text-dark">Düzeltme</span>';
    if($row['approval_status'] == 'rejected') $status_badge = '<span class="badge bg-danger">Red</span>';

    // Öncelik
    $prio = '<span class="badge bg-light text-dark border">Normal</span>';
    if($row['priority'] == 'high') $prio = '<span class="badge bg-danger">ACİL</span>';
    if($row['priority'] == 'low') $prio = '<span class="badge bg-success">Düşük</span>';

    // TARİH GÖSTERİMİ (Planlanan tarih varsa mavi ve ikonlu)
    $date_display = date('d.m.Y', strtotime($row['final_date']));
    if (!empty($row['planned_date']) && $row['planned_date'] != '0000-00-00') {
        $date_display = '<span class="text-primary fw-bold" title="Planlanan Tarih"><i class="fa fa-calendar-check"></i> ' . $date_display . '</span>';
    }

    // Modal Verisi
    $json = htmlspecialchars(json_encode([
        'id' => $row['id'],
        'company_name' => $row['company_name'],
        'amount_fmt' => number_format($row['amount'], 2).' TL',
        'desc' => $row['description'],
        'note' => $row['admin_note'] ?? '',
        'planned_date' => $row['planned_date']
    ]), ENT_QUOTES, 'UTF-8');

    $results[] = [
        "<button class='btn btn-primary btn-sm w-100' onclick='openApproveModal($json)'><i class='fa fa-edit'></i> Karar Ver</button>",
        $status_badge,
        $prio,
        $date_display, // HESAPLANMIŞ TARİH
        $row['creator_name'],
        "<strong>".guvenli_html($row['company_name'])."</strong><br><small>".guvenli_html($row['description'])."</small>",
        number_format($row['amount'], 2) . ' TL',
        "<button class='btn btn-sm btn-light' onclick='openLogModal({$row['id']})'><i class='fa fa-history'></i></button>",
        "DT_RowClass" => $row_class
    ];
}

echo json_encode(["draw" => intval($_POST['draw'] ?? 1), "recordsTotal" => $total, "recordsFiltered" => $total, "data" => $results]);
?>