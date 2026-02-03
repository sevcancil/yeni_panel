<?php
// public/api-save-approval.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error', 'message'=>'Yetkisiz erişim']); exit; }

$action = $_POST['action'] ?? 'save_approval'; // Default action
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) { echo json_encode(['status'=>'error', 'message'=>'Geçersiz ID']); exit; }

try {
    // 1. ÖNCELİK DEĞİŞTİRME (Yıldız)
    if ($action == 'toggle_priority') {
        $curr = $pdo->query("SELECT priority FROM transactions WHERE id=$id")->fetchColumn();
        $next = ($curr == 'low') ? 'medium' : (($curr == 'medium') ? 'high' : 'low');
        $pdo->prepare("UPDATE transactions SET priority = ? WHERE id = ?")->execute([$next, $id]);
        echo json_encode(['status'=>'success']);
    }
    
    // 2. ONAY KAYDETME
    else {
        $status = $_POST['status']; // approved, correction_needed, rejected
        $note = temizle($_POST['admin_note']);
        $priority = $_POST['priority'];
        $posted_date = !empty($_POST['planned_date']) ? $_POST['planned_date'] : null;

        // SENARYO A: Onaylandı ve Tarih Seçildi -> ANA TARİHİ GÜNCELLE
        if ($status === 'approved' && $posted_date) {
            $sql = "UPDATE transactions SET 
                    approval_status = ?, 
                    admin_note = ?, 
                    priority = ?, 
                    planned_date = ?, 
                    date = ? 
                    WHERE id = ?";
            $params = [$status, $note, $priority, $posted_date, $posted_date, $id];
            
            $log_msg = "Ödeme ONAYLANDI. Tarih: $posted_date olarak güncellendi. Not: $note";
        } 
        // SENARYO B: Diğer Durumlar -> ANA TARİHE DOKUNMA
        else {
            // Reddedilirse planlanan tarihi temizleyelim mi? Evet.
            $date_val = ($status === 'approved') ? $posted_date : null;

            $sql = "UPDATE transactions SET 
                    approval_status = ?, 
                    admin_note = ?, 
                    priority = ?,
                    planned_date = ?
                    WHERE id = ?";
            $params = [$status, $note, $priority, $date_val, $id];
            
            $log_msg = "Ödeme durumu güncellendi: $status. Not: $note";
        }

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            log_action($pdo, 'transaction', $id, 'approve', $log_msg);
            echo json_encode(['status'=>'success']);
        } else {
            throw new Exception("Veritabanı hatası.");
        }
    }

} catch (Exception $e) {
    echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
}
?>