<?php
// public/api-approve-action.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error', 'message'=>'Yetkisiz erişim']); exit; }

// ID ve Action Kontrolü
$action = $_POST['action'] ?? '';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) { echo json_encode(['status'=>'error', 'message'=>'Geçersiz ID']); exit; }

try {
    // 1. ÖNCELİK DEĞİŞTİRME (Yıldız Tıklama)
    if ($action == 'toggle_priority') {
        $curr = $pdo->query("SELECT priority FROM transactions WHERE id=$id")->fetchColumn();
        // Döngü: low -> medium -> high -> low
        $next = ($curr == 'low') ? 'medium' : (($curr == 'medium') ? 'high' : 'low');
        
        $pdo->prepare("UPDATE transactions SET priority = ? WHERE id = ?")->execute([$next, $id]);
        echo json_encode(['status'=>'success']);
    }
    
    // 2. ONAY KAYDETME (Modal Submit)
    elseif ($action == 'save_approval') {
        $status = $_POST['status']; // approved, correction_needed, rejected
        $note = temizle($_POST['admin_note']);
        $priority = $_POST['priority'];
        
        // Tarih boş gelirse NULL yap ki veritabanı hata vermesin
        $posted_date = !empty($_POST['planned_date']) ? $_POST['planned_date'] : null;

        // SENARYO A: Onaylandı VE Tarih Seçildi
        // Hem 'planned_date' hem de 'date' güncellenir.
        if ($status === 'approved' && $posted_date) {
            $sql = "UPDATE transactions SET 
                    approval_status = ?, 
                    admin_note = ?, 
                    priority = ?, 
                    planned_date = ?, 
                    date = ? 
                    WHERE id = ?";
            
            $params = [
                $status,       // 1. ?
                $note,         // 2. ?
                $priority,     // 3. ?
                $posted_date,  // 4. ? (planned_date)
                $posted_date,  // 5. ? (date - ANA TARİH)
                $id            // 6. ? (id)
            ];
            
            $log_msg = "Ödeme ONAYLANDI. Tarih: $posted_date olarak güncellendi. Not: $note";
        } 
        // SENARYO B: Diğer Durumlar (Red, Düzeltme veya Tarihsiz Onay)
        // Ana 'date' sütununa dokunulmaz.
        else {
            // Eğer onaylanmadıysa planlanan tarihi sıfırla (NULL yap)
            $date_to_save = ($status === 'approved') ? $posted_date : null;

            $sql = "UPDATE transactions SET 
                    approval_status = ?, 
                    admin_note = ?, 
                    priority = ?,
                    planned_date = ?
                    WHERE id = ?";
            
            $params = [
                $status,       // 1. ?
                $note,         // 2. ?
                $priority,     // 3. ?
                $date_to_save, // 4. ? (planned_date)
                $id            // 5. ? (id)
            ];
            
            $log_msg = "Ödeme durumu güncellendi: $status. Not: $note";
        }

        // Sorguyu Çalıştır
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            // Log Kaydı
            log_action($pdo, 'transaction', $id, 'approve', $log_msg);
            echo json_encode(['status'=>'success', 'message' => 'İşlem başarıyla kaydedildi.']);
        } else {
            throw new Exception("Veritabanı güncelleme hatası.");
        }
    }
    else {
        echo json_encode(['status'=>'error', 'message'=>'Geçersiz işlem türü.']);
    }

} catch (Exception $e) {
    // Hata detayını döndür (Debugging için)
    echo json_encode(['status'=>'error', 'message' => $e->getMessage()]);
}
?>