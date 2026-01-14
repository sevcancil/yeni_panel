<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

permission_check('view_finance');

// --- AYARLAR ---
$csv_url = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS38wyctf1ijNuPK072z6DxY__QkUKjz4fLTpZlMdpGxhg0KZeEC7_FvUJ72KJ3UQfY4GtmpGgAzZOI/pub?gid=0&single=true&output=csv'; 

// Link Kontrolü
if (strpos($csv_url, 'output=csv') === false) {
    die('<div class="alert alert-danger">HATA: Yapıştırdığınız link CSV formatında değil!</div>');
}

// 1. KURLARI ÇEK
$rates = ['TRY' => 1.00];
try {
    $xml = simplexml_load_file("https://www.tcmb.gov.tr/kurlar/today.xml");
    if ($xml) {
        foreach ($xml->Currency as $currency) {
            $code = (string)$currency['Kod'];
            $rate = (float)$currency->BanknoteSelling > 0 ? (float)$currency->BanknoteSelling : (float)$currency->ForexSelling;
            $rates[$code] = $rate;
        }
    }
} catch (Exception $e) { }

// 2. CSV VERİSİNİ ÇEK
$csv_data = file_get_contents($csv_url);
if ($csv_data === false) { header("Location: channels.php?msg=sync_error"); exit; }

$rows = str_getcsv($csv_data, "\n");
$count = 0;

try {
    $pdo->beginTransaction();

    foreach ($rows as $index => $row) {
        if ($index === 0) continue; // Başlık satırını atla

        $data = str_getcsv($row, ",");

        // Google Sheet Sütunları
        $name_raw = isset($data[0]) ? trim($data[0]) : '';
        
        // --- TEMİZLİK ---
        // B Sütununu al, görünmez karakterleri sil ve küçült
        $type_raw = isset($data[1]) ? $data[1] : 'bank';
        $type_raw = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $type_raw); 
        $type_raw = mb_strtolower(trim($type_raw)); 

        $currency = isset($data[2]) ? trim($data[2]) : 'TRY';
        $acc_no   = isset($data[3]) ? trim($data[3]) : '';
        
        // Rakamları temizle
        $col_E    = isset($data[4]) ? (float)str_replace(',','.',$data[4]) : 0; 
        $col_F    = isset($data[5]) ? (float)str_replace(',','.',$data[5]) : 0; 
        $col_G    = isset($data[6]) ? (float)str_replace(',','.',$data[6]) : 0; 

        if (empty($name_raw)) continue;

        // --- TÜR ALGILAMA (KATIDIR) ---
        // Sadece 'card' yazıyorsa karttır. Geri kalan her şey bankadır.
        if ($type_raw === 'card') {
            $type = 'card';
        } else {
            $type = 'bank';
        }

        // --- VERİ EŞLEŞTİRME (MATEMATİK YOK) ---
        // Excel ne diyorsa o.
        
        $balance   = $col_E; // E Sütunu -> Bakiye (current_balance)
        $available = $col_F; // F Sütunu -> Kullanılabilir (available_balance)
        $debt      = $col_G; // G Sütunu -> Ekstre Borcu (statement_debt)
        $limit     = 0;

        if ($type == 'card') {
            // Kart ise E sütunu aynı zamanda Limittir
            $limit = $col_E; 
        } else {
            // Banka ise borç yoktur
            $debt = 0; 
        }

        // Veritabanı İşlemi
        $stmt = $pdo->prepare("SELECT id FROM payment_channels WHERE name = ?");
        $stmt->execute([$name_raw]);
        $channel = $stmt->fetch();

        if ($channel) {
            $sql = "UPDATE payment_channels SET type=?, currency=?, account_number=?, current_balance=?, credit_limit=?, statement_debt=?, available_balance=? WHERE id=?";
            $pdo->prepare($sql)->execute([$type, $currency, $acc_no, $balance, $limit, $debt, $available, $channel['id']]);
        } else {
            $sql = "INSERT INTO payment_channels (name, type, currency, account_number, current_balance, credit_limit, statement_debt, available_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name_raw, $type, $currency, $acc_no, $balance, $limit, $debt, $available]);
        }
        $count++;
    }

    $pdo->commit();
    header("Location: channels.php?msg=synced&count=$count");

} catch (Exception $e) {
    $pdo->rollBack();
    die("Hata: " . $e->getMessage());
}
?>