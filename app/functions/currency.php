<?php
// app/functions/currency.php

function updateExchangeRates() {
    global $pdo;
    
    // TCMB XML Adresi
    $url = "https://www.tcmb.gov.tr/kurlar/today.xml";
    
    try {
        $xml = simplexml_load_file($url);
        if (!$xml) return false;

        $updates = [];

        foreach ($xml->Currency as $currency) {
            $code = (string)$currency['Kod'];
            $rate = (float)$currency->ForexBuying; // Efektif Satış Kurunu alıyoruz (Genelde bu kullanılır)
            
            // Eğer BanknoteSelling boşsa ForexSelling (Döviz Satış) al
            if ($rate == 0) {
                $rate = (float)$currency->ForexSelling;
            }

            if (in_array($code, ['USD', 'EUR', 'GBP', 'AUD', 'DKK', 'CHF', 'SEK', 'CAD', 'KWD', 'NOK', 'SAR', 'JPY', 'AED', 'TRY'])) {
                $stmt = $pdo->prepare("UPDATE currencies SET rate = :rate WHERE code = :code");
                $stmt->execute(['rate' => $rate, 'code' => $code]);
            }
        }
        return true;
    } catch (Exception $e) {
        return false; // Kur çekilemedi
    }
}

// Yardımcı Fonksiyon: Kur Getir
function getExchangeRate($code) {
    global $pdo;
    if ($code == 'TRY') return 1.0000;
    
    $stmt = $pdo->prepare("SELECT rate FROM currencies WHERE code = ?");
    $stmt->execute([$code]);
    return $stmt->fetchColumn() ?: 1.0000;
}
?>