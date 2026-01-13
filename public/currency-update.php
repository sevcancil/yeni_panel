<?php
// public/currency-update.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// Güvenlik: Sadece finans yetkisi olanlar güncelleyebilsin
permission_check('view_finance');

// TCMB Fonksiyonunu dahil et
// Not: app/functions/currency.php dosyasını bir önceki adımda oluşturmuştuk.
require_once '../app/functions/currency.php'; 

if (updateExchangeRates()) {
    // Başarılıysa
    header("Location: index.php?msg=currency_updated");
} else {
    // Hata varsa (İnternet yoksa veya TCMB sitesi çökükse)
    header("Location: index.php?msg=currency_error");
}
exit;
?>