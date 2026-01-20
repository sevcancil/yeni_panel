<?php
// app/functions/alerts.php

function get_user_alerts($pdo, $user_id, $is_admin) {
    $alerts = [];

    // --- FİLTRELER ---
    // Yöneticiyse herkesi görsün, değilse sadece kendi oluşturduklarını
    $filter = $is_admin ? "1=1" : "t.created_by = $user_id";
    $cust_filter = $is_admin ? "1=1" : "c.created_by = $user_id";

    // =================================================================
    // 1. SENARYO: TUR KODU EKSİK OLANLAR
    // =================================================================
    // Düzeltme: 'debt' ve 'credit' tiplerini de ekledik ki ana emirlerde de kontrol etsin.
    // Ayrıca boş string ('') kontrolü de eklendi.
    $sql = "SELECT t.id, t.date, t.amount, t.currency, t.type, u.username 
            FROM transactions t 
            LEFT JOIN users u ON t.created_by = u.id
            WHERE (t.tour_code_id IS NULL OR t.tour_code_id = 0 OR t.tour_code_id = '') 
            AND t.type IN ('payment_out', 'payment_in', 'debt', 'credit') 
            AND t.parent_id IS NULL -- Sadece ana işlemleri kontrol et (alt işlemler zaten anaya bağlı)
            AND $filter
            ORDER BY t.date DESC LIMIT 50";
    
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $creator = !empty($row['username']) ? guvenli_html($row['username']) : 'Bilinmeyen';
        $who = $is_admin ? "<strong>$creator</strong>: " : "Siz: ";
        
        // İşlem tipine göre mesajı güzelleştirelim
        $type_label = ($row['type'] == 'debt' || $row['type'] == 'payment_out') ? 'Ödemenin' : 'Tahsilatın';

        $alerts[] = [
            'type' => 'warning',
            'icon' => 'fa-plane-slash',
            'title' => 'Tur Kodu Eksik',
            'msg' => $who . date('d.m.Y', strtotime($row['date'])) . " tarihli $type_label tur kodu girilmedi.",
            'link' => 'javascript:openEditModal('.$row['id'].')', // Modal açar
            'btn_text' => 'Düzelt'
        ];
    }

    // =================================================================
    // 2. SENARYO: VERGİ/TC NO EKSİK OLANLAR (Geçici Kod Kontrolü)
    // =================================================================
    // Mantık: 
    // (Vergi No BOŞ VEYA Geçici) VE (TC No BOŞ VEYA Geçici) ise UYARI VER.
    // Yani ikisinden en az biri "Gerçek" veri ise uyarı verme.
    
    $sql2 = "SELECT c.id, c.company_name, u.username 
             FROM customers c 
             LEFT JOIN users u ON c.created_by = u.id
             WHERE 
             (c.tax_number IS NULL OR c.tax_number = '' OR c.tax_number LIKE 'G-VN-%') 
             AND 
             (c.tc_number IS NULL OR c.tc_number = '' OR c.tc_number LIKE 'G-TC-%') 
             AND $cust_filter
             ORDER BY c.id DESC LIMIT 50";
             
    $stmt2 = $pdo->query($sql2);
    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows2 as $row) {
        $creator = !empty($row['username']) ? guvenli_html($row['username']) : 'Bilinmeyen';
        $who = $is_admin ? "<strong>$creator</strong>: " : "Siz: ";
        
        $alerts[] = [
            'type' => 'danger', // Kırmızı
            'icon' => 'fa-id-card',
            'title' => 'Kimlik Bilgisi Eksik',
            'msg' => $who . "<b>" . guvenli_html($row['company_name']) . "</b> için geçerli bir TC veya Vergi No girilmedi.",
            'link' => 'customer-details.php?id=' . $row['id'], 
            'btn_text' => 'Tamamla'
        ];
    }

    return $alerts;
}
?>