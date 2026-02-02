<?php
// app/functions/security.php

// 1. ZAMAN DİLİMİNİ İSTANBUL YAP
date_default_timezone_set('Europe/Istanbul');

// Oturum kontrolü
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// XSS Koruması
function guvenli_html($data) {
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Veri Temizleme
function temizle($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Yetki Kontrolü (Sayfa Başına)
function permission_check($required_perm) {
    if (!has_permission($required_perm)) {
        die('<div style="text-align:center; margin-top:50px; font-family:sans-serif;">
                <h1 style="color:red">⛔ Yetkisiz Erişim</h1>
                <p>Bu işlemi yapmak için yetkiniz bulunmamaktadır.</p>
                <a href="index.php">Ana Sayfaya Dön</a>
             </div>');
    }
    return true;
}

// Yetki Kontrolü (Boolean)
function has_permission($required_perm) {
    if (!isset($_SESSION['user_id'])) return false;
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') return true;
    if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
        return in_array($required_perm, $_SESSION['permissions']);
    }
    return false;
}

// Loglama Fonksiyonu
function log_action($pdo, $module, $record_id, $action, $description) {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'SYSTEM';

    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, module, record_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $module, $record_id, $action, $description, $ip]);
    } catch (PDOException $e) {
        // Log hatası akışı bozmasın
    }
}

// Para Formatı
function para($tutar) {
    return number_format((float)$tutar, 2, ',', '.') . ' ₺';
}

/**
 * AKILLI TARİH GÜNCELLEME (CATCH-UP + POSTPONE)
 * 1. Geçmişte kalmış ödenmemişleri "Bugün"e taşır.
 * 2. Saat 17:00'den sonra ise "Bugün"ü "Yarın"a taşır.
 */
function check_auto_postpone($pdo) {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $hour = (int)date('H');

    // --- SENARYO 1: GEÇMİŞİ TOPARLA (CATCH-UP) ---
    // Dün veya öncesinden kalan, ödenmemiş borçları bul
    $sql_past = "SELECT id, date FROM transactions 
                 WHERE type = 'debt' 
                 AND payment_status != 'paid' 
                 AND date < ?"; // Bugünden küçük olanlar
    
    $stmt_past = $pdo->prepare($sql_past);
    $stmt_past->execute([$today]);
    $past_rows = $stmt_past->fetchAll(PDO::FETCH_ASSOC);

    if ($past_rows) {
        $upd = $pdo->prepare("UPDATE transactions SET date = ? WHERE id = ?");
        foreach ($past_rows as $row) {
            $upd->execute([$today, $row['id']]);
            // Log
            log_action($pdo, 'transaction', $row['id'], 'auto_update', "Geçmiş tarihli işlem sisteme giriş yapılınca bugüne taşındı. ({$row['date']} -> $today)");
        }
    }

    // --- SENARYO 2: MESAİ BİTİMİ (POSTPONE) ---
    // Eğer saat 17:00 ve sonrasıysa, BUGÜNÜN işlerini yarına at
    if ($hour >= 23) {
        $sql_today = "SELECT id, date FROM transactions 
                      WHERE type = 'debt' 
                      AND payment_status != 'paid' 
                      AND date = ?"; // Sadece tarihi BUGÜN olanlar
        
        $stmt_today = $pdo->prepare($sql_today);
        $stmt_today->execute([$today]);
        $today_rows = $stmt_today->fetchAll(PDO::FETCH_ASSOC);

        if ($today_rows) {
            $upd = $pdo->prepare("UPDATE transactions SET date = ? WHERE id = ?");
            foreach ($today_rows as $row) {
                $upd->execute([$tomorrow, $row['id']]);
                // Log
                log_action($pdo, 'transaction', $row['id'], 'auto_postpone', "Gün bittiği için işlem yarına ertelendi. ($today -> $tomorrow)");
            }
        }
    }
}

// Global PDO nesnesi varsa fonksiyonu çalıştır
if (isset($GLOBALS['pdo'])) {
    check_auto_postpone($GLOBALS['pdo']);
}

// Sabit bir şifreleme anahtarı (Bunu sunucuda .env dosyasında saklamak daha güvenlidir ama şimdilik burada tanımlıyoruz)
define('ENCRYPTION_KEY', 'pire2Thor41!!'); 
define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encrypt_data($data) {
    $key = hash('sha256', ENCRYPTION_KEY);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data) {
    $key = hash('sha256', ENCRYPTION_KEY);
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $key, 0, $iv);
}
?>