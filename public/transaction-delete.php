<?php
// public/transaction-delete.php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

// 1. GÜVENLİK KONTROLÜ
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Oturum açmalısınız.']);
    exit;
}

$role = $_SESSION['role'] ?? 'user';
$allowed_roles = ['admin', 'muhasebe']; 

if (!in_array($role, $allowed_roles)) {
    echo json_encode(['status' => 'error', 'message' => 'Yetkiniz yok!']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
            $stmt->execute([$id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($transaction) {
                if ($transaction['is_deleted'] == 1) {
                    echo json_encode(['status' => 'error', 'message' => 'Zaten iptal edilmiş.']);
                    exit;
                }

                $pdo->beginTransaction();

                // --- 1. BAKİYE DÜZELTMELERİ (ANA İŞLEM İÇİN) ---
                $customer_id = $transaction['customer_id'];
                $amount = (float)$transaction['amount'];
                $type = $transaction['type'];
                
                // Cari Bakiyeyi Tersine Çevir
                if ($type == 'debt') {
                    $pdo->prepare("UPDATE customers SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $customer_id]);
                } else {
                    $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $customer_id]);
                }

                // Kasa Bakiyesini Düzelt (Eğer ana işlem direkt ödenmişse)
                // Ama genelde kasa hareketleri child kayıtlardadır. Child kayıtları aşağıda iptal edeceğiz.
                // Eğer bu işlem bir child (ödeme) ise ve tek başına siliniyorsa:
                if ($transaction['parent_id'] > 0 && ($type == 'payment_out' || $type == 'payment_in')) {
                     $channel_id = !empty($transaction['collection_channel_id']) ? $transaction['collection_channel_id'] : ($transaction['payment_method_id'] ?? 0);
                     if($channel_id) {
                         if ($type == 'payment_out') {
                             $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $channel_id]);
                         } elseif ($type == 'payment_in') {
                             $pdo->prepare("UPDATE payment_channels SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $channel_id]);
                         }
                     }
                }

                // --- 2. SOFT DELETE (HEM KENDİSİNİ HEM ÇOCUKLARINI) ---
                // Bu sorgu çok kritik: ID'si bu olan VEYA Parent ID'si bu olan herkesi silinmiş işaretle.
                $updateStmt = $pdo->prepare("UPDATE transactions SET is_deleted = 1 WHERE id = ? OR parent_id = ?");
                $updateStmt->execute([$id, $id]);

                // --- 3. LOG ---
                $log_desc = "İşlem ve bağlı kayıtları iptal edildi. ID: {$id}";
                log_action($pdo, 'transaction', $id, 'delete', $log_desc);

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'İşlem ve alt kayıtları iptal edildi.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Bulunamadı.']);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Hata: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz ID.']);
    }
}
?>