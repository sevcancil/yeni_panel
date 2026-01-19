<?php
// app/functions/security.php

if (session_status() === PHP_SESSION_NONE) { session_start(); }

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

/**
 * Yetki Kontrolü (Sayfa Başına Koymak İçin)
 * Yetki yoksa sayfayı durdurur ve hata mesajı basar.
 */
function permission_check($required_perm) {
    if (!has_permission($required_perm)) {
        die('<div style="text-align:center; margin-top:50px; font-family:sans-serif;">
                <h1 style="color:red">⛔ Yetkisiz Erişim</h1>
                <p>Bu işlemi yapmak için yetkiniz bulunmamaktadır.</p>
                <a href="index.php" style="padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;">Ana Sayfaya Dön</a>
             </div>');
    }
    return true;
}

/**
 * Yetki Kontrolü (Boolean Döner)
 * Menüde buton gizlemek/göstermek için kullanılır.
 */
function has_permission($required_perm) {
    // 1. Oturum yoksa yetki yok
    if (!isset($_SESSION['user_id'])) return false;

    // 2. Admin ise her şeye yetkisi var
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') return true;

    // 3. Personel ise yetkiler dizisini kontrol et
    // Yetkiler login sırasında session'a yüklenmiş olmalı.
    if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
        return in_array($required_perm, $_SESSION['permissions']);
    }

    return false;
}

// Loglama Fonksiyonu
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
?>