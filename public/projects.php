<?php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Bölümleri Çek (Dropdown için)
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();

// Yeni Proje Ekleme İşlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project'])) {
    $code = temizle($_POST['code']); // Otomatik oluşturulan kod buraya gelir
    $name = temizle($_POST['name']);
    $start_date = $_POST['start_date'];
    
    // Not: Veritabanında tour_codes tablosuna department_id eklemedik henüz. 
    // Şimdilik sadece kodu oluşturmak için kullanıyoruz. 
    // İleride tour_codes tablosuna department_id sütunu eklersek buraya da ekleriz.

    // Aynı koddan var mı kontrolü
    $check = $pdo->prepare("SELECT id FROM tour_codes WHERE code = ?");
    $check->execute([$code]);
    
    if($check->rowCount() > 0) {
        $error = "Bu Tur Kodu zaten kullanılıyor! Lütfen kodu değiştirin.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO tour_codes (code, name, start_date) VALUES (?, ?, ?)");
        $stmt->execute([$code, $name, $start_date]);
        header("Location: projects.php");
        exit;
    }
}

// Projeleri ve Bakiyelerini Çek
$sql = "SELECT t.*, 
        (SELECT SUM(amount) FROM transactions WHERE tour_code_id = t.id AND type = 'credit') as total_income,
        (SELECT SUM(amount) FROM transactions WHERE tour_code_id = t.id AND type = 'debt') as total_expense
        FROM tour_codes t 
        ORDER BY t.start_date DESC";
$projects = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Projeler ve Tur Kodları</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        
        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm sticky-top" style="top: 80px; z-index: 1;">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fa fa-magic"></i> Yeni İş / Tur Tanımla</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="add_project" value="1">
                            
                            <div class="mb-3">
                                <label class="form-label">Bölüm Seçin</label>
                                <select id="deptSelect" class="form-select" onchange="generateCode()">
                                    <option value="" data-short="">Seçiniz...</option>
                                    <?php foreach($departments as $d): ?>
                                        <?php 
                                            // Basit bir kısaltma mantığı: İlk kelimeyi al, Türkçe karakterleri temizle
                                            $short = strtoupper(substr(str_replace(['İ','ı','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'], ['I','i','S','s','G','g','U','u','O','o','C','c'], $d['name']), 0, 4));
                                        ?>
                                        <option value="<?php echo $d['id']; ?>" data-short="<?php echo $short; ?>">
                                            <?php echo guvenli_html($d['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">İşin Beklenen Tarihi</label>
                                <input type="date" id="dateInput" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="generateCode()">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Etkinlik / İş Adı *</label>
                                <input type="text" id="nameInput" name="name" class="form-control" placeholder="Örn: Dell Technologies Forum 2026" required oninput="generateCode()">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold text-primary">Otomatik Tur Kodu</label>
                                <div class="input-group">
                                    <input type="text" id="codeInput" name="code" class="form-control fw-bold" placeholder="Otomatik oluşacak..." required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateCode()" title="Yeniden Oluştur"><i class="fa fa-sync"></i></button>
                                </div>
                                <small class="text-muted" style="font-size: 11px;">Format: Tarih - İsim Kısaltma - Bölüm</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Projeyi Oluştur</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Aktif Projeler ve Kâr/Zarar Durumu</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Kod</th>
                                    <th>Etkinlik Adı</th>
                                    <th class="text-end">Gelir</th>
                                    <th class="text-end">Gider</th>
                                    <th class="text-end">Kâr/Zarar</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($projects as $p): ?>
                                    <?php 
                                        $income = $p['total_income'] ?? 0;
                                        $expense = $p['total_expense'] ?? 0;
                                        $profit = $income - $expense; 
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?php echo guvenli_html($p['code']); ?></span></td>
                                        <td><?php echo guvenli_html($p['name']); ?></td>
                                        <td class="text-end text-success"><?php echo number_format($income, 2); ?> ₺</td>
                                        <td class="text-end text-danger"><?php echo number_format($expense, 2); ?> ₺</td>
                                        <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($profit, 2); ?> ₺
                                        </td>
                                        <td>
                                            <?php if($p['status'] == 'active'): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Bitti</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function generateCode() {
            // 1. Tarihi Al ve Formatla (YYYY-MM-DD -> DD-MM-YY)
            let dateVal = document.getElementById('dateInput').value; // 2026-01-14
            let datePart = "000000";
            if(dateVal) {
                let d = new Date(dateVal);
                let day = ("0" + d.getDate()).slice(-2);
                let month = ("0" + (d.getMonth() + 1)).slice(-2);
                let year = d.getFullYear().toString(); 
                datePart = day + "-" + month + "-" + year;
            }

            // 2. Bölüm Kısaltmasını Al
            let deptSelect = document.getElementById('deptSelect');
            let deptPart = "";
            if (deptSelect.selectedIndex > 0) {
                deptPart = "-" + deptSelect.options[deptSelect.selectedIndex].getAttribute('data-short');
            }

            // 3. Etkinlik İsmini Kısalt (En Zor Kısım)
            let nameVal = document.getElementById('nameInput').value;
            let namePart = "GENEL";

            if (nameVal) {
                // Türkçe karakterleri temizle
                let cleanName = nameVal.replace(/İ/g,'I').replace(/ı/g,'i').replace(/Ş/g,'S').replace(/ş/g,'s').replace(/Ğ/g,'G').replace(/ğ/g,'g').replace(/Ü/g,'U').replace(/ü/g,'u').replace(/Ö/g,'O').replace(/ö/g,'o').replace(/Ç/g,'C').replace(/ç/g,'c');
                
                // Kelimelere ayır
                let words = cleanName.trim().split(/\s+/);
                
                if (words.length === 1) {
                    // Tek kelimeyse: İlk 4 harf + Varsa sayı
                    let word = words[0];
                    let numbers = word.replace(/[^0-9]/g, ''); // İçindeki sayıları al
                    let letters = word.replace(/[0-9]/g, '').substring(0, 4).toUpperCase();
                    namePart = letters + numbers;
                } else {
                    // Çok kelimeyse: Baş harfleri + Sayılar
                    namePart = "";
                    let numbersAccumulated = ""; // Yılı veya sayıları toplamak için

                    words.forEach(word => {
                        // Eğer kelime tamamen sayı ise (Örn: 2026)
                        if (!isNaN(word)) {
                            // Yıl ise son 2 hanesini al (2026 -> 26)
                            if (word.length === 4 && (word.startsWith("19") || word.startsWith("20"))) {
                                numbersAccumulated += word.substring(2);
                            } else {
                                numbersAccumulated += word;
                            }
                        } else {
                            // Kelime ise baş harfini al
                            // "Technologies" gibi uzun kelimelerden kaçınmak yerine standart baş harf alıyoruz
                            // Ama "ve", "ile" gibi bağlaçları atlayabiliriz
                            if(word.length > 2) { 
                                namePart += word.substring(0,1).toUpperCase();
                            }
                        }
                    });
                    namePart += numbersAccumulated;
                }
            }

            // 4. Birleştir
            // Örnek Çıktı: 14-01-2026-DTF26-YONE
            let finalCode = datePart + "-" + namePart + deptPart;
            
            document.getElementById('codeInput').value = finalCode;
        }
    </script>
</body>
</html>