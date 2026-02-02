<?php
// public/get-child-transactions.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) exit;

$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

if (!$parent_id) { echo "ID Hatası"; exit; }

// 1. ÖNCE ANA İŞLEMİ (PARENT) ÇEK
$stmtParent = $pdo->prepare("SELECT t.*, c.company_name FROM transactions t LEFT JOIN customers c ON t.customer_id = c.id WHERE t.id = ?");
$stmtParent->execute([$parent_id]);
$parent = $stmtParent->fetch(PDO::FETCH_ASSOC);

$parent_json = htmlspecialchars(json_encode($parent), ENT_QUOTES, 'UTF-8');

// 2. BAĞLI ALT İŞLEMLERİ ÇEK
$sql = "SELECT t.*, u.full_name as user_name,
        pm.title as method_name, cc.title as channel_name
        FROM transactions t
        LEFT JOIN users u ON t.created_by = u.id
        LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
        LEFT JOIN collection_channels cc ON t.collection_channel_id = cc.id
        WHERE t.parent_id = ?
        ORDER BY t.date DESC, t.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$parent_id]);
$children = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- YENİ: BAKİYE HESAPLAMA (BUTONU GİZLEMEK İÇİN) ---
$total_paid = 0;
foreach ($children as $child) {
    // Sadece silinmemiş ödeme/tahsilat hareketlerini topla
    if (!isset($child['is_deleted']) || $child['is_deleted'] == 0) {
        if ($child['type'] == 'payment_in' || $child['type'] == 'payment_out') {
            $total_paid += $child['amount'];
        }
    }
}
$remaining_balance = $parent['amount'] - $total_paid;
// Eğer kalan tutar 0.05'ten küçükse (kuruş farklarını yoksay) işlem bitmiş demektir
$is_transaction_completed = ($remaining_balance <= 0.05);


// --- BUTON METİNLERİ, İKONLARI VE TAMAMLANMA MESAJI AYARLAMA ---

// 1. Fatura Butonu Mantığı
if (!empty($parent['invoice_no'])) {
    $invoice_btn_text = 'Faturayı Düzenle';
    $invoice_btn_class = 'btn-success text-white';
    $invoice_btn_icon = 'fa-check-double';
} else {
    $invoice_btn_text = ($parent['doc_type'] == 'invoice_order') ? 'Fatura Kes/Yükle' : 'Gelen Faturayı İşle';
    $invoice_btn_class = 'btn-outline-dark';
    $invoice_btn_icon = 'fa-file-invoice';
}

// 2. Yeni Hareket (Öde/Tahsil Et) ve Tamamlanma Mesajı Mantığı
$add_child_btn_text = 'Yeni Hareket Ekle';
$add_child_btn_icon = 'fa-plus';
$completion_text = 'İşlem Tamamlandı'; // Varsayılan

if ($parent['type'] == 'debt' || $parent['doc_type'] == 'payment_order') {
    $add_child_btn_text = 'Öde';
    $add_child_btn_icon = 'fa-arrow-up';
    $completion_text = 'Ödeme Tamamlandı'; // GİDER İÇİN
} elseif ($parent['type'] == 'credit' || $parent['doc_type'] == 'invoice_order') {
    $add_child_btn_text = 'Tahsil Et';
    $add_child_btn_icon = 'fa-arrow-down';
    $completion_text = 'Tahsilat Tamamlandı'; // GELİR İÇİN
}

// --- İPTAL UYARISI ---
if (isset($parent['is_deleted']) && $parent['is_deleted'] == 1) {
    echo '<div class="alert alert-danger fw-bold text-center m-2"><i class="fa fa-ban"></i> BU İŞLEM İPTAL EDİLMİŞTİR!</div>';
}
?>

<div class="table-responsive">
    <?php if(empty($children)): ?>
        <div class="alert alert-warning m-0 p-2 text-center small"><i class="fa fa-info-circle"></i> Henüz bir işlem hareketi (ödeme/fatura) girilmemiş.</div>
    <?php else: ?>
        <table class="table table-sm table-bordered mb-0 bg-white">
            <thead class="table-light">
                <tr>
                    <th>Tarih</th>
                    <th>İşlem Türü</th>
                    <th>Açıklama / Belge</th>
                    <th>Kasa/Banka</th>
                    <th class="text-end">Tutar</th>
                    <th>Kullanıcı</th>
                    <th width="50">Dosya</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($children as $child): 
                    $style_class = ""; $icon = ""; $type_text = "";
                    $is_child_deleted = isset($child['is_deleted']) && $child['is_deleted'] == 1;

                    if ($child['type'] == 'payment_out') {
                        $style_class = "table-danger bg-opacity-10"; 
                        $icon = '<i class="fa fa-arrow-up text-danger"></i>';
                        $type_text = "Ödeme Çıkışı";
                    } elseif ($child['type'] == 'payment_in') {
                        $style_class = "table-success bg-opacity-10"; 
                        $icon = '<i class="fa fa-arrow-down text-success"></i>';
                        $type_text = "Tahsilat Girişi";
                    } elseif ($child['type'] == 'invoice' || $child['type'] == 'invoice_log') {
                        $style_class = "table-info bg-opacity-10"; 
                        $icon = '<i class="fa fa-file-invoice text-primary"></i>';
                        $type_text = "Fatura İşlendi";
                    } else {
                        $type_text = "İşlem";
                    }

                    if ($is_child_deleted) {
                        $style_class = "table-secondary text-decoration-line-through text-muted";
                        $type_text .= " (İPTAL)";
                        $icon = '<i class="fa fa-ban text-danger"></i>';
                    }

                    $file_link = '-';
                    if (!empty($child['file_path'])) {
                        $file_link = '<a href="../storage/'.$child['file_path'].'" target="_blank" class="btn btn-xs btn-outline-secondary" title="Dosyayı Gör"><i class="fa fa-paperclip"></i></a>';
                    }
                ?>
                <tr class="<?php echo $style_class; ?>">
                    <td><?php echo date('d.m.Y', strtotime($child['date'])); ?></td>
                    <td><?php echo $icon . ' ' . $type_text; ?></td>
                    <td>
                        <?php echo guvenli_html($child['description']); ?>
                        <?php if(!empty($child['invoice_no'])): ?>
                            <br><small class="fw-bold text-dark">Belge No: <?php echo guvenli_html($child['invoice_no']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                            if ($child['method_name']) echo $child['method_name'];
                            elseif ($child['channel_name']) echo $child['channel_name'];
                            else echo '-';
                        ?>
                    </td>
                    
                    <td class="text-end fw-bold">
                        <?php 
                            $show_curr = $child['currency'];
                            $final_original_amount = 0;
                            $final_tl_amount = 0;

                            // 1. Dövizli İşlem mi?
                            if ($show_curr != 'TRY') {
                                if ($child['original_amount'] > 0) {
                                    $final_original_amount = $child['original_amount'];
                                } else {
                                    // Zeki Kur Hesabı
                                    $rate_to_use = $child['exchange_rate'];
                                    // Eğer child kur 1 ise ama parent kur doğruysa, parent kuru kullan
                                    if ($rate_to_use <= 1.01 && $parent['currency'] == $show_curr && $parent['exchange_rate'] > 1) {
                                        $rate_to_use = $parent['exchange_rate'];
                                    }

                                    if ($rate_to_use > 0) {
                                        $final_original_amount = $child['amount'] / $rate_to_use;
                                    } else {
                                        $final_original_amount = $child['amount'];
                                    }
                                }
                                $final_tl_amount = $child['amount'];
                            } else {
                                // TL İşlem
                                $final_original_amount = $child['amount'];
                                $show_curr = 'TL';
                            }

                            // Ekrana Yazdırma
                            $display_text = number_format($final_original_amount, 2, ',', '.') . ' ' . $show_curr;

                            if ($child['type'] == 'invoice' || $child['type'] == 'invoice_log' || $is_child_deleted) {
                                echo '<span class="text-muted">(' . $display_text . ')</span>';
                            } else {
                                echo $display_text;
                            }

                            if ($show_curr != 'TL' && $final_tl_amount > 0) {
                                echo '<div class="text-muted fw-normal" style="font-size:0.7rem">(' . number_format($final_tl_amount, 2, ',', '.') . ' TL)</div>';
                            }
                        ?>
                    </td>

                    <td class="small text-muted"><?php echo guvenli_html($child['user_name']); ?></td>
                    <td class="text-center"><?php echo $file_link; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <?php if(!isset($parent['is_deleted']) || $parent['is_deleted'] == 0): ?>
    <div class="d-flex justify-content-end mt-2 gap-2">
        <button class="btn btn-sm <?php echo $invoice_btn_class; ?> shadow-sm" onclick='openInvoiceModal(<?php echo $parent_json; ?>)'>
            <i class="fa <?php echo $invoice_btn_icon; ?>"></i> <?php echo $invoice_btn_text; ?>
        </button>
        
        <?php if(!$is_transaction_completed): ?>
            <a href="transaction-add-child.php?parent_id=<?php echo $parent_id; ?>" class="btn btn-sm btn-primary shadow-sm">
                <i class="fa <?php echo $add_child_btn_icon; ?>"></i> <?php echo $add_child_btn_text; ?>
            </a>
        <?php else: ?>
            <span class="btn btn-sm btn-outline-success disabled border-0 fw-bold">
                <i class="fa fa-check-circle"></i> <?php echo $completion_text; ?>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>