<?php
// public/get-child-transactions.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

error_reporting(0);
ini_set('display_errors', 0);

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
if($parent_id == 0) exit('<div class="alert alert-danger">Hata.</div>');

// Ana İşlemi Çek
$stmt_p = $pdo->prepare("SELECT invoice_no, type, currency FROM transactions WHERE id = ?");
$stmt_p->execute([$parent_id]);
$parent = $stmt_p->fetch(PDO::FETCH_ASSOC);

$can_manage_finance = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') || (function_exists('has_permission') && has_permission('manage_finance'));

try {
    // GÜNCELLEME: Hem Ödeme Yöntemlerini hem Tahsilat Kanallarını JOIN yapıyoruz
    $sql = "SELECT t.*, 
            cc.title as tahsilat_kanali,
            pm.title as odeme_kanali
            FROM transactions t 
            LEFT JOIN collection_channels cc ON t.collection_channel_id = cc.id
            LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
            WHERE t.parent_id = ? 
            ORDER BY t.date DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent_id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    exit('<div class="alert alert-danger">' . $e->getMessage() . '</div>');
}
?>

<div class="p-3 border-top bg-light">
    
    <?php if(empty($parent['invoice_no'])): ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center py-2 px-3 mb-3 shadow-sm">
            <div>
                <i class="fa fa-exclamation-triangle me-2"></i> 
                <strong>Bu işlemin faturası henüz girilmemiş.</strong>
            </div>
            <?php if($can_manage_finance): ?>
                <button onclick="window.parent.openEditModal(<?php echo $parent_id; ?>)" class="btn btn-sm btn-warning fw-bold text-dark">
                    <i class="fa fa-file-invoice"></i> Faturayı İşle / Düzenle
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="m-0 text-primary fw-bold">
            <i class="fa fa-history me-1"></i> Ödeme ve Tahsilat Hareketleri
        </h6>
        
        <?php if($can_manage_finance): ?>
            <a href="transaction-add-child.php?parent_id=<?php echo $parent_id; ?>" class="btn btn-sm btn-outline-primary shadow-sm">
                <i class="fa fa-plus-circle"></i> Yeni Ödeme / Tahsilat Ekle
            </a>
        <?php endif; ?>
    </div>

    <div class="table-responsive shadow-sm">
        <table class="table table-sm table-bordered bg-white mb-0 align-middle small">
            <thead class="table-light">
                <tr>
                    <th width="100">Tarih</th>
                    <th width="110">Yön</th>
                    <th>Belge Türü</th>
                    <th>Kasa / Kanal</th> 
                    <th>Açıklama</th>
                    <th width="50" class="text-center">Dosya</th>
                    <th class="text-end" width="120">Tutar</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($children) > 0): ?>
                    <?php foreach($children as $c): ?>
                        <tr>
                            <td class="text-center"><?php echo date('d.m.Y', strtotime($c['date'])); ?></td>
                            <td class="text-center">
                                <?php if($c['type'] == 'payment_out'): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger w-100">
                                        <i class="fa fa-arrow-up"></i> Çıkış
                                    </span>
                                <?php elseif($c['type'] == 'payment_in'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success w-100">
                                        <i class="fa fa-arrow-down"></i> Giriş
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold text-dark">
                                <?php echo !empty($c['document_type']) ? guvenli_html($c['document_type']) : '-'; ?>
                            </td>
                            <td>
                                <?php 
                                    if(!empty($c['tahsilat_kanali'])) echo '<span class="badge bg-info text-dark">'.guvenli_html($c['tahsilat_kanali']).'</span>';
                                    elseif(!empty($c['odeme_kanali'])) echo '<span class="badge bg-primary">'.guvenli_html($c['odeme_kanali']).'</span>';
                                    else echo '<span class="text-muted">-</span>';
                                ?>
                            </td>
                            <td class="text-muted">
                                <?php echo guvenli_html($c['description']); ?>
                            </td>
                            <td class="text-center">
                                <?php if(!empty($c['file_path'])): ?>
                                    <a href="../storage/<?php echo $c['file_path']; ?>" target="_blank" class="btn btn-xs btn-outline-dark" title="Görüntüle">
                                        <i class="fa fa-paperclip"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold">
                                <?php 
                                    // Eğer dövizli ödeme yapıldıysa onu da gösterelim
                                    if($c['currency'] != 'TRY' && $c['currency'] != $parent['currency']) {
                                        echo number_format($c['original_amount'], 2) . ' ' . $c['currency'];
                                        echo '<br><small class="text-muted">('.number_format($c['amount'], 2).' TL)</small>';
                                    } else {
                                        echo number_format($c['amount'], 2, ',', '.') . ' ₺';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Henüz hareket girilmedi.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>