<?php
// public/get-child-transactions.php
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

if($parent_id == 0) exit;

$sql = "SELECT t.*, pc.name as kasa_adi 
        FROM transactions t 
        LEFT JOIN payment_channels pc ON t.payment_channel_id = pc.id
        WHERE parent_id = ? ORDER BY date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$parent_id]);
$children = $stmt->fetchAll();

if(count($children) == 0) {
    echo '<div class="alert alert-warning m-0 p-2 small"><i class="fa fa-info-circle"></i> Bu emre bağlı henüz bir ödeme veya işlem yapılmamış.</div>';
    // Ödeme Yap Butonu Buraya Konabilir
    echo '<a href="transaction-add-child.php?parent_id='.$parent_id.'" class="btn btn-sm btn-success mt-2">Ödeme/Tahsilat Gir</a>';
    return;
}
?>

<table class="table table-sm table-bordered bg-white small mb-0">
    <thead class="table-info">
        <tr>
            <th>Tarih</th>
            <th>İşlem Türü</th>
            <th>Kasa/Banka</th>
            <th>Açıklama</th>
            <th class="text-end">Tutar</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($children as $c): ?>
            <tr>
                <td><?php echo date('d.m.Y', strtotime($c['date'])); ?></td>
                <td>
                    <?php 
                        if($c['type'] == 'payment_out') echo '<span class="badge bg-danger">Ödeme Çıkışı</span>';
                        elseif($c['type'] == 'payment_in') echo '<span class="badge bg-success">Tahsilat Girişi</span>';
                        else echo $c['type'];
                    ?>
                </td>
                <td><?php echo guvenli_html($c['kasa_adi']); ?></td>
                <td><?php echo guvenli_html($c['description']); ?></td>
                <td class="text-end fw-bold"><?php echo number_format($c['amount'], 2, ',', '.'); ?> ₺</td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>