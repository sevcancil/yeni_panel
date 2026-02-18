<?php
// public/api-calendar-events.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

// FullCalendar start ve end parametrelerini gönderir (ISO formatında)
$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d', strtotime('+1 month'));

// Vade Tarihi (due_date) varsa onu, yoksa İşlem Tarihini (date) baz alalım.
// Sadece silinmemiş kayıtlar.
$sql = "SELECT t.id, t.date, t.due_date, t.amount, t.currency, t.type, t.description, c.company_name 
        FROM transactions t 
        LEFT JOIN customers c ON t.customer_id = c.id 
        WHERE t.is_deleted = 0 
        AND (
            (t.due_date BETWEEN ? AND ?) OR 
            (t.due_date IS NULL AND t.date BETWEEN ? AND ?)
        )";

$stmt = $pdo->prepare($sql);
$stmt->execute([$start, $end, $start, $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$events = [];

foreach ($rows as $row) {
    // Tarih Belirleme: Vade tarihi varsa o, yoksa işlem tarihi
    $event_date = !empty($row['due_date']) ? $row['due_date'] : $row['date'];
    
    // Renk Belirleme
    // Gelir (Tahsilat) -> Yeşil, Gider (Ödeme) -> Kırmızı
    $color = '#6c757d'; // Varsayılan gri
    $is_income = ($row['type'] == 'payment_in' || $row['type'] == 'credit');
    
    if ($is_income) {
        $color = '#198754'; // Yeşil (Success)
        $prefix = '+';
    } else {
        $color = '#dc3545'; // Kırmızı (Danger)
        $prefix = '-';
    }

    // Başlık Formatı: [Firma Adı] - [Tutar]
    $title = mb_substr($row['company_name'], 0, 15) . '... | ' . number_format($row['amount'], 2, ',', '.') . ' ' . $row['currency'];

    $events[] = [
        'id' => $row['id'],
        'title' => $title,
        'start' => $event_date,
        'color' => $color,
        'extendedProps' => [
            'full_title' => $row['company_name'],
            'amount' => number_format($row['amount'], 2, ',', '.') . ' ' . $row['currency'],
            'description' => $row['description'],
            'type_label' => $is_income ? 'Tahsilat / Gelir' : 'Ödeme / Gider'
        ]
    ];
}

echo json_encode($events);
?>