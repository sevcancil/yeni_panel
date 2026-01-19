<?php
// public/get-child-transactions.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

error_reporting(0);
ini_set('display_errors', 0);

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
if($parent_id == 0) exit('<div class="alert alert-danger">Hata.</div>');

$can_manage_finance = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') || (function_exists('has_permission') && has_permission('manage_finance'));

try {
    // collection_channels tablosuna JOIN yapıyoruz
    $sql = "SELECT t.*, cc.title as tahsilat_kanali 
            FROM transactions t 
            LEFT JOIN collection_channels cc ON t.collection_channel_id = cc.id
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
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="m-0 text-primary fw-bold">
            <i class="fa fa-history me-1"></i> Gerçekleşen İşlemler
        </h6>
        
        <?php if($can_manage_finance): ?>
            <a href="transaction-add-child.php?parent_id=<?php echo $parent_id; ?>" class="btn btn-sm btn-outline-primary shadow-sm">
                <i class="fa fa-plus-circle"></i> Ödeme/Tahsilat Gir
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
                    <th>Kasa / Kanal</th> <th>Açıklama</th>
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
                                <?php if(!empty($c['tahsilat_kanali'])): ?>
                                    <span class="badge bg-info text-dark"><?php echo guvenli_html($c['tahsilat_kanali']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
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
                                <?php echo number_format($c['amount'], 2, ',', '.'); ?> ₺
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">
                            <i class="fa fa-info-circle mb-1"></i><br>
                            Henüz kayıt yok.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>