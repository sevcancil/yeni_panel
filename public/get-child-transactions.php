<?php
// public/get-child-transactions.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
if($parent_id == 0) exit('<div class="alert alert-danger">Geçersiz işlem ID.</div>');

// Yetki Kontrolü: Admin veya Muhasebe yetkisi
// has_permission fonksiyonu yoksa hata vermesin diye function_exists kontrolü ekledim.
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');
$has_finance_perm = (function_exists('has_permission') && has_permission('manage_finance'));

$can_manage_finance = ($is_admin || $has_finance_perm);

try {
    // Geçmiş İşlemleri Çek
    // DÜZELTME 1: pc.name yerine pc.title kullanıldı (Veritabanı şemana göre)
    $sql = "SELECT t.*, pc.title as kasa_adi 
            FROM transactions t 
            LEFT JOIN collection_channels pc ON t.payment_channel_id = pc.id
            WHERE t.parent_id = ? 
            ORDER BY t.date DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent_id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    exit('<div class="alert alert-danger">Veritabanı Hatası: ' . $e->getMessage() . '</div>');
}
?>

<div class="p-2">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="m-0 text-primary"><i class="fa fa-history"></i> Ödeme ve Tahsilat Geçmişi (Mavi Bölge)</h6>
        
        <?php if($can_manage_finance): ?>
            <a href="transaction-add-child.php?parent_id=<?php echo $parent_id; ?>" class="btn btn-sm btn-primary">
                <i class="fa fa-lira-sign"></i> Ödeme/Tahsilat Gir
            </a>
        <?php endif; ?>
    </div>

    <table class="table table-sm table-bordered bg-white small mb-0">
        <thead class="table-primary">
            <tr>
                <th>Tarih</th>
                <th>İşlem Tipi</th>
                <th>Kasa / Kanal</th>
                <th>Açıklama</th>
                <th class="text-end">Tutar</th>
            </tr>
        </thead>
        <tbody>
            <?php if(count($children) > 0): ?>
                <?php foreach($children as $c): ?>
                    <tr>
                        <td><?php echo date('d.m.Y', strtotime($c['date'])); ?></td>
                        <td>
                            <?php 
                                // DÜZELTME 2: Type kontrolünü 'debt'/'credit' yapına göre uyarladım.
                                // Parent debt ise, child debt (ödeme çıkışı) olur.
                                if($c['type'] == 'debt' || $c['type'] == 'payment_out'): 
                            ?>
                                <span class="badge bg-danger">Ödeme Çıkışı</span>
                            <?php else: ?>
                                <span class="badge bg-success">Tahsilat Girişi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                                // Boş gelirse tire koy
                                echo !empty($c['kasa_adi']) ? guvenli_html($c['kasa_adi']) : '-'; 
                            ?>
                        </td>
                        <td><?php echo guvenli_html($c['description']); ?></td>
                        <td class="text-end fw-bold">
                            <?php echo number_format($c['amount'], 2, ',', '.'); ?> ₺
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-3">
                        <i class="fa fa-info-circle"></i> Henüz gerçekleşen bir ödeme veya tahsilat kaydı yok.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>