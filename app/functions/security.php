<?php
// app/functions/security.php

// XSS Koruması: Ekrana basılan her veriyi temizler
function guvenli_html($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Post/Get verilerini temizlemek için
function temizle($data) {
    $data = trim($data); // Baştaki sondaki boşlukları sil
    $data = stripslashes($data); // Ters eğik çizgileri sil
    $data = htmlspecialchars($data); // HTML karakterlerini dönüştür
    return $data;
}

// Yetki Kontrol Fonksiyonu
function permission_check($required_perm) {
    global $pdo;

    // 1. Oturum yoksa at
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    // 2. Kullanıcı verisini ve izinlerini veritabanından taze çek (Session'a güvenme, anlık değişmiş olabilir)
    $stmt = $pdo->prepare("SELECT role, permissions FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // 3. Admin ise her kapı açık
    if ($user['role'] === 'admin') {
        return true;
    }

    // 4. Personel ise izinleri kontrol et
    $permissions = json_decode($user['permissions'], true) ?? [];

    // Eğer izin listesinde yoksa
    if (!in_array($required_perm, $permissions)) {
        // Yetkisiz erişim sayfası veya mesajı
        die('<div style="text-align:center; margin-top:50px; font-family:sans-serif;">
                <h1>⛔ Yetkisiz Erişim</h1>
                <p>Bu işlemi yapmak veya bu sayfayı görmek için yetkiniz bulunmamaktadır.</p>
                <a href="index.php">Ana Sayfaya Dön</a>
             </div>');
    }
    
    return true; // Yetkisi var
}

// Menüde göstermek/gizlemek için boolean (true/false) döndüren versiyonu
function has_permission($required_perm) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return false;
    
    // Performans için session'dan da bakabiliriz ama en güvenlisi db'dir.
    // Şimdilik DB'den çekelim.
    $stmt = $pdo->prepare("SELECT role, permissions FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user['role'] === 'admin') return true;
    
    $permissions = json_decode($user['permissions'], true) ?? [];
    return in_array($required_perm, $permissions);
}
?>