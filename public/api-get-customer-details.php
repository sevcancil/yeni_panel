<?php
// public/api-get-customer-details.php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    require_once '../app/config/database.php';

    if (!isset($_GET['id'])) throw new Exception('ID eksik');
    $id = (int)$_GET['id'];

    // 1. CARİ BİLGİSİ
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) throw new Exception('Cari bulunamadı');

    // 2. BANKA BİLGİLERİ
    $banks = $pdo->prepare("SELECT id, bank_name, iban, currency FROM customer_banks WHERE customer_id = ?");
    $banks->execute([$id]);
    $bank_list = $banks->fetchAll(PDO::FETCH_ASSOC);

    // 3. BAKİYE VE GEÇMİŞ HESAPLAMA
    // Tüm işlemleri çek (Hesap doğru olsun diye hepsi lazım)
    $sql = "SELECT t.*, tc.code as tour_code 
            FROM transactions t 
            LEFT JOIN tour_codes tc ON t.tour_code_id = tc.id 
            WHERE t.customer_id = ? 
            ORDER BY t.date DESC, t.id DESC"; // En son işlemler en üstte
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $official_balance = $customer['opening_balance']; // Resmi (Faturalaşmış)
    $pending_balance = 0; // Bekleyen (Faturasız)
    
    $history_output = []; // Ekrana basılacak sade liste

    // Bakiyeyi baştan sona hesaplamak için işlemleri tersten (eskiden yeniye) taramamız lazım
    // Ancak veritabanından DESC çektik (son işlemleri listede göstermek için).
    // O yüzden hesaplamayı "Toplamdan Geriye" değil, "Parça Parça" yapacağız.
    // Basitlik adına: Tüm bakiyeyi döngüyle toplayalım.

    foreach ($transactions as $row) {
        $amt = (float)$row['amount'];
        
        // Fatura Durumu (Resmi mi?)
        $is_official = !empty($row['invoice_no']) && $row['invoice_status'] != 'waiting_approval' && $row['invoice_status'] != 'to_be_issued';
        if ($row['type'] == 'payment_out' || $row['type'] == 'payment_in') {
            $is_official = true; // Nakit hareketler hep resmidir
        }

        // --- HESAPLAMA ---
        if ($row['type'] == 'debt') {
            // GİDER
            if (!$is_official) $pending_balance -= $amt;
            else $official_balance -= $amt;
        } 
        elseif ($row['type'] == 'payment_out') {
            // ÖDEME (Borç Düşer / +)
            $official_balance += $amt;
        } 
        elseif ($row['type'] == 'credit') {
            // GELİR
            if (!$is_official) $pending_balance += $amt;
            else $official_balance += $amt;
        } 
        elseif ($row['type'] == 'payment_in') {
            // TAHSİLAT (Alacak Düşer / -)
            $official_balance -= $amt;
        }

        // --- LİSTELEME FİLTRESİ ---
        // Sadece Ana Emirler (Siparişler) görünsün istedin
        // Yani parent_id'si olmayanlar (veya 0 olanlar)
        if ( ($row['parent_id'] == 0 || $row['parent_id'] == NULL) && count($history_output) < 10 ) {
            $type_label = '';
            if($row['type'] == 'debt') $type_label = 'Ödeme Emri (Gider)';
            elseif($row['type'] == 'credit') $type_label = 'Tahsilat Emri (Gelir)';
            else $type_label = 'İşlem';

            $history_output[] = [
                'date' => date('d.m.Y', strtotime($row['date'])),
                'type' => $row['type'],
                'type_label' => $type_label,
                'description' => $row['description'],
                'tour_code' => $row['tour_code'],
                'amount' => $amt,
                'is_pending' => !$is_official
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'company_name' => $customer['company_name'],
        'currency' => $customer['opening_balance_currency'] ?? 'TRY',
        'official_balance' => $official_balance,
        'pending_balance' => $pending_balance,
        'banks' => $bank_list,
        'history' => $history_output
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>