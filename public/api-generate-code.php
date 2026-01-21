<?php
// public/api-generate-code.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$type = isset($_POST['type']) ? $_POST['type'] : 'real'; // Varsayılan: real

if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'İsim boş']);
    exit;
}

// 1. TÜR ÖNEKİ (S- veya T-)
// real -> S (Şahıs), legal -> T (Tüzel)
$type_prefix = ($type === 'legal') ? 'T-' : 'S-';

// 2. İSİM ÖNEKİ (BAŞ HARFLER)
// Kelimeleri ayır ve baş harflerini al (Max 3 harf)
$clean_name = mb_strtoupper(str_replace(['İ','ı','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'], ['I','i','S','s','G','g','U','u','O','o','C','c'], $name));
$words = explode(' ', $clean_name);
$name_prefix = '';

foreach ($words as $word) {
    if (!empty($word) && is_numeric($word) == false) {
        $name_prefix .= mb_substr($word, 0, 1);
    }
}

// Eğer isim öneki çok kısaysa veya boşsa ismin ilk 3 harfini al
if (strlen($name_prefix) < 2) {
    $name_prefix = mb_substr($clean_name, 0, 3);
}
// İsim önekini 3 harfle sınırla
$name_prefix = substr($name_prefix, 0, 3);

// 3. NİHAİ ÖNEK
// Örnek: S-AY (Şahıs - Ahmet Yılmaz)
$final_prefix = $type_prefix . $name_prefix;

// 4. SIRA NUMARASI BULMA
// Bu prefix ile başlayan en son kodu bul
$stmt = $pdo->prepare("SELECT customer_code FROM customers WHERE customer_code LIKE ? ORDER BY customer_code DESC LIMIT 1");
$stmt->execute([$final_prefix . '%']);
$last_code = $stmt->fetchColumn();

$next_num = 1;

if ($last_code) {
    // Mevcut koddan sayıyı ayıkla (Örn: S-AY00005 -> 00005)
    // Prefix uzunluğu kadar kısmı kesip atıyoruz
    $num_part = substr($last_code, strlen($final_prefix));
    if (is_numeric($num_part)) {
        $next_num = (int)$num_part + 1;
    }
}

// 5. YENİ KODU OLUŞTUR
// Format: PREFIX + 5 Haneli Sayı (Örn: S-AY00001)
$new_code = $final_prefix . str_pad($next_num, 5, '0', STR_PAD_LEFT);

echo json_encode(['status' => 'success', 'code' => $new_code]);
?>