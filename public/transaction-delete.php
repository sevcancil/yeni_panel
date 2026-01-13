<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php'; // <-- BU SATIR EKSİKTİ, EKLENDİ.

// 1. Yetki Kontrolü (Artık çalışacak)
permission_check('delete_data');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id) {
    // İşlemi bul
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $transaction = $stmt->fetch();

    if ($transaction) {
        $customer_id = $transaction['customer_id'];
        $channel_id = $transaction['payment_channel_id']; // Hangi kasa?
        $amount = $transaction['amount']; // TL Tutarı
        $type = $transaction['type']; // debt/credit
        $status = $transaction['payment_status']; // paid/unpaid

        try {
            $pdo->beginTransaction();

            // SİLME İŞLEMİNDE BAKİYE GERİ ALMA MANTIĞI:
            // Eğer işlem "Bekliyor" (unpaid) ise: Sadece Cari Bakiye etkilenmiştir. Kasa etkilenmemiştir.
            // Eğer işlem "Ödendi" (paid) ise: Hem Cari hem Kasa etkilenmiştir.

            // A. CARİ BAKİYEYİ DÜZELT (Her durumda cariye işlenmişti, o yüzden geri alıyoruz)
            if ($type == 'debt') {
                // Satış (Borçlandırma) siliniyor -> Cariyi Düşür (-)
                $pdo->prepare("UPDATE customers SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $customer_id]);
            } else {
                // Alış/Tahsilat (Alacaklandırma) siliniyor -> Cariyi Artır (+)
                $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $customer_id]);
            }

            // B. KASA BAKİYESİNİ DÜZELT (Sadece 'paid' ise kasa hareket görmüştür)
            if ($status == 'paid' && $channel_id) {
                // Hareketin Tersi Yapılır
                // İşlem eklenirken: Satış(debt) -> Kasa+, Gider(credit) -> Kasa-
                // Silinirken: Satış(debt) -> Kasa-, Gider(credit) -> Kasa+
                
                // NOT: Veritabanında type 'debt' ise (Satış Faturası) ve ödenmişse kasaya para girmiştir.
                // Veritabanında type 'credit' ise (Gider Faturası) ve ödenmişse kasadan para çıkmıştır.
                
                // Ancak "Tahsilat" (payment_in) transaction tablosunda 'credit' olarak tutuluyordu (Müşteri alacağı düşsün diye).
                // "Ödeme" (payment_out) transaction tablosunda 'debt' olarak tutuluyordu (Bizim borç düşsün diye).
                
                // BURADA BİR MANTIK KARMAŞASI OLMAMASI İÇİN "transaction-add.php" MANTIĞINA BAKALIM:
                // Satış (sales_invoice/debt) -> Cari artar. Ödendiyse Kasa Artar.
                // Alış (purchase_invoice/credit) -> Cari düşer. Ödendiyse Kasa Azalır.
                // Tahsilat (payment_in/credit) -> Cari düşer. Kasa Artar.
                // Ödeme (payment_out/debt) -> Cari artar (Borç kapanır). Kasa Azalır.

                // Gördüğünüz gibi 'debt' hem Kasa Artışı hem Kasa Azalışı olabiliyor.
                // Bu yüzden 'doc_type' veya işlem tipini tam bilmemiz lazım.
                // Neyse ki 'amount' pozitif sayı.
                
                // BASİT ÇÖZÜM: Transaction tablosunda 'doc_type' sütunu eklemiştik.
                // Eğer doc_type yoksa eski usul type'a bakarız.
                
                $doc_type = $transaction['doc_type'] ?? '';

                // Kasa Geri Alma İşlemi
                if ($doc_type == 'sales_invoice' || $doc_type == 'payment_in' || ($type == 'debt' && $doc_type == '')) {
                    // Para girmişti, şimdi siliyoruz -> Kasadan Düş (-)
                    // DİKKAT: Eski kodda payment_in 'credit' olarak kaydediliyordu.
                    // transaction-add.php'de payment_in -> credit yapmistik.
                    // O zaman Type üzerinden gidelim:
                    
                    // Veritabanına bakıyoruz:
                    // payment_in -> credit kaydedildi. Kasa arttı. Silince Kasa azalmalı.
                    // payment_out -> debt kaydedildi. Kasa azaldı. Silince Kasa artmalı.
                    // sales_invoice -> debt kaydedildi. Kasa arttı. Silince Kasa azalmalı.
                    // purchase_invoice -> credit kaydedildi. Kasa azaldı. Silince Kasa artmalı.
                    
                    if ($type == 'debt') {
                       // Debt genelde satış faturasıdır (Kasa artmıştır) -> Kasayı Azalt.
                       // AMA payment_out (Ödeme Yap) da debt idi (Cari borç kapama). O zaman Kasa azalmıştı -> Kasayı Artır.
                       
                       if ($doc_type == 'payment_out') {
                           // Ödeme siliniyor (Kasa azalmıştı) -> Kasayı Artır (+)
                           $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $channel_id]);
                       } else {
                           // Satış Faturası siliniyor (Kasa artmıştı) -> Kasayı Azalt (-)
                           $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $channel_id]);
                       }
                    } else {
                        // Credit (Alış Faturası veya Tahsilat)
                        if ($doc_type == 'payment_in' || $doc_type == '') { 
                            // Tahsilat siliniyor (Kasa artmıştı) -> Kasayı Azalt (-)
                            // Not: Eski kayıtlarda doc_type boş olabilir, varsayılan tahsilat mantığı.
                             $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $channel_id]);
                        } else {
                            // Alış Faturası (purchase_invoice) siliniyor (Kasa azalmıştı) -> Kasayı Artır (+)
                            $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $channel_id]);
                        }
                    }
                }
            }
            
            // 3. İŞLEMİ SİL
            $delStmt = $pdo->prepare("DELETE FROM transactions WHERE id = :id");
            $delStmt->execute(['id' => $id]);

            $pdo->commit();
            
            header("Location: customer-details.php?id=" . $customer_id . "&msg=deleted");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Hata: " . $e->getMessage());
        }
    }
}
header("Location: customers.php");
?>