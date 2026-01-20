<?php
// app/functions/security.php

// 1. ZAMAN DİLİMİNİ İSTANBUL YAP (Çok Önemli)
date_default_timezone_set('Europe/Istanbul');

// Oturum başlatılmadıysa başlat
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

// Loglama
function log_action($pdo, $module, $record_id, $action, $description) {
    if (!isset($_SESSION['user_id'])) return;
    $user_id = $_SESSION['user_id'];
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, module, record_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $module, $record_id, $action, $description, $ip]);
}

// Para Formatı
function para($tutar) {
    return number_format((float)$tutar, 2, ',', '.') . ' ₺';
}

/**
 * OTOMATİK TARİH GÜNCELLEME (17:00 KURALI)
 * Bu fonksiyon her sayfa yenilendiğinde çalışır.
 */
function check_auto_postpone($pdo) {
    // Saat şu an 17 veya daha büyükse (İstanbul Saatine Göre)
    if ((int)date('H') >= 10) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Bugünün tarihi olan, borç (gider) tipindeki ve ödenmemiş (unpaid) işlemleri bul ve yarına ertele
        $sql = "UPDATE transactions 
                SET date = ? 
                WHERE type = 'debt' 
                AND payment_status != 'paid' 
                AND date = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tomorrow, $today]);
    }
}

// Global PDO nesnesi varsa fonksiyonu tetikle
// (Bu dosya database.php'den sonra çağrıldığı için $pdo erişilebilir olmalı)
if (isset($GLOBALS['pdo'])) {
    check_auto_postpone($GLOBALS['pdo']);
}
?>