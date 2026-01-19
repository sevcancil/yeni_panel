<?php
// --- NAVBAR İÇİNDE SQL SORGUSU ---
// Bu dosya her sayfada include edildiği için, eksik bilgi sayısını burada hesaplayabiliriz.
// Eğer veritabanı bağlantısı ($pdo) yoksa hata vermesin diye kontrol ediyoruz.
if (isset($pdo)) {
    $sql_missing = "SELECT COUNT(*) FROM customers WHERE tc_number LIKE 'G-TC-%' OR tax_number LIKE 'G-VN-%'";
    $missing_count = $pdo->query($sql_missing)->fetchColumn();
} else {
    $missing_count = 0;
}
?>
<style>
    :root {
        --sidebar-width: 260px;
        --sidebar-width-mini: 70px;
        --top-bar-height: 60px;
    }

    /* --- SIDEBAR TASARIMI --- */
    .sidebar {
        width: var(--sidebar-width);
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1000;
        padding-top: 0;
        transition: width 0.3s;
        overflow-x: hidden;
        overflow-y: auto;
    }

    body {
        padding-left: var(--sidebar-width);
        padding-top: var(--top-bar-height);
        transition: padding-left 0.3s;
    }

    .top-navbar {
        position: fixed;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        height: var(--top-bar-height);
        z-index: 999;
        background-color: #fff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        transition: left 0.3s;
        display: flex;
        align-items: center;
        padding: 0 20px;
    }

    /* MINI SIDEBAR */
    body.sb-collapsed { padding-left: var(--sidebar-width-mini); }
    body.sb-collapsed .sidebar { width: var(--sidebar-width-mini); }
    body.sb-collapsed .top-navbar { left: var(--sidebar-width-mini); }
    
    body.sb-collapsed .sidebar .nav-link span,
    body.sb-collapsed .sidebar .sidebar-heading,
    body.sb-collapsed .sidebar .user-name { display: none; }
    
    body.sb-collapsed .sidebar .nav-link { justify-content: center; padding: 15px 0; }
    body.sb-collapsed .sidebar .nav-link i { margin-right: 0; font-size: 1.2rem; }

    /* MOBIL */
    @media (max-width: 991.98px) {
        .sidebar { transform: translateX(-100%); }
        body { padding-left: 0 !important; }
        .top-navbar { left: 0 !important; }
        body.sb-mobile-open .sidebar { transform: translateX(0); width: var(--sidebar-width); }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sb-mobile-open .sidebar-overlay { display: block; }
    }

    /* STYLES */
    .nav-link { color: rgba(255,255,255, .8); padding: 10px 15px; border-radius: 5px; margin-bottom: 5px; display: flex; align-items: center; white-space: nowrap; }
    .nav-link:hover, .nav-link.active { background-color: rgba(255,255,255, 0.1); color: #fff; }
    .nav-link i { min-width: 30px; text-align: center; margin-right: 10px; }
    
    /* Notification Badge */
    .badge-notification {
        margin-left: auto; /* Sağa yasla */
        background-color: #ffc107;
        color: #000;
        font-size: 0.75rem;
        font-weight: bold;
        padding: 2px 6px;
        border-radius: 4px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }

    /* KIRMIZI LINKLER (ADMIN) */
    .nav-link.text-danger { color: #ff6b6b !important; }
    .nav-link.text-danger:hover { background-color: rgba(220, 53, 69, 0.2); color: #fff !important; }

    .sidebar-heading { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #adb5bd; margin-top: 20px; margin-bottom: 10px; padding-left: 15px; white-space: nowrap; font-weight: bold; }
</style>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="bg-dark text-white sidebar" id="sidebar">
    <div class="d-flex flex-column flex-shrink-0 p-3 h-100">
        
        <a href="index.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none justify-content-center">
            <i class="fa fa-chart-line fa-2x text-primary"></i>
            <span class="fs-4 fw-bold ms-2 user-name">Panel v2</span>
        </a>
        
        <hr>
        
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item">
                <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" title="Ana Sayfa">
                    <i class="fa fa-home"></i> <span>Ana Sayfa</span>
                </a>
            </li>

            <div class="sidebar-heading">FİNANS</div>

            <li>
                <a href="departments.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : ''; ?>" title="Bölümler">
                    <i class="fa fa-sitemap"></i> <span>Bölümler</span>
                </a>
            </li>

            <li>
                <a href="customers.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" title="Cari Hesaplar">
                    <i class="fa fa-address-card"></i> <span>Cari Kartlar</span>
                    <?php if(isset($missing_count) && $missing_count > 0): ?>
                        <span class="badge-notification" title="<?php echo $missing_count; ?> carinin bilgileri eksik!"><?php echo $missing_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li>
                <a href="customers.php" class="nav-link" title="Cari Kart Bakiye">
                    <i class="fa fa-scale-balanced"></i> <span>Cari Kartlar Bakiye</span>
                </a>
            </li>

            <li>
                <a href="payment-orders.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'payment-orders.php' ? 'active' : ''; ?>" title="Ödeme Emirleri">
                    <i class="fa fa-file-invoice"></i> <span>Ödeme Listesi</span>
                </a>
            </li>

            <li>
                <a href="payment-methods.php" class="nav-link" title="Ödeme Yöntemleri">
                    <i class="fa fa-money-check-dollar"></i> <span>Ödeme Yöntemleri</span>
                </a>
            </li>

            <li>
                <a href="collection-channels.php" class="nav-link" title="Tahsilat Kanalları">
                    <i class="fa fa-hand-holding-dollar"></i> <span>Tahsilat Kanalları</span>
                </a>
            </li>

            <li>
                <a href="projects.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'projects.php' ? 'active' : ''; ?>" title="Tur Kodları">
                    <i class="fa fa-plane-departure"></i> <span>Tur Kodları</span>
                </a>
            </li>

            <li>
                <a href="#" class="nav-link" title="Aylık Döküm">
                    <i class="fa fa-chart-pie"></i> <span>Aylık Döküm</span>
                </a>
            </li>

            <li>
                <a href="pending-invoices.php" class="nav-link" title="Fatura Bekleyen">
                    <i class="fa fa-hourglass-half"></i> <span>Fatura Bekleyen</span>
                </a>
            </li>

            <li>
                <a href="transaction-add.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'transaction-add.php' ? 'active' : ''; ?>" title="İşlem Ekle">
                    <i class="fa fa-bolt"></i> <span>Hızlı İşlem</span>
                </a>
            </li>

            <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                <div class="sidebar-heading text-danger">YÖNETİM</div>
                
                <li>
                    <a href="channels.php" class="nav-link text-danger <?php echo basename($_SERVER['PHP_SELF']) == 'channels.php' ? 'active' : ''; ?>" title="Kasa/Banka">
                        <i class="fa fa-wallet"></i> <span>Kasa/Banka</span>
                    </a>
                </li>    
                
                <li>
                    <a href="#" class="nav-link text-danger" title="Raporlar">
                        <i class="fa fa-chart-line"></i> <span>Raporlar</span>
                    </a>
                </li>

                <li>
                    <a href="users.php" class="nav-link text-danger <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" title="Kullanıcılar">
                        <i class="fa fa-users-gear"></i> <span>Kullanıcılar</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
        
        <hr>
        
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                    <i class="fa fa-user"></i>
                </div>
                <strong class="user-name"><?php echo guvenli_html($_SESSION['username']); ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                <li><a class="dropdown-item" href="logout.php"><i class="fa fa-sign-out-alt me-2"></i> Çıkış Yap</a></li>
            </ul>
        </div>
    </div>
</div>

<nav class="top-navbar">
    <button class="btn btn-outline-secondary border-0" id="sidebarToggle">
        <i class="fa fa-bars fa-lg"></i>
    </button>
    <div class="ms-auto d-flex align-items-center">
        <span class="text-muted small me-2 d-none d-md-block">Bugün: <?php echo date('d.m.Y'); ?></span>
    </div>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('sidebarToggle');
        const body = document.body;
        const overlay = document.getElementById('sidebarOverlay');
        const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
        
        if (isCollapsed) { body.classList.add('sb-collapsed'); }

        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (window.innerWidth < 992) {
                body.classList.toggle('sb-mobile-open');
            } else {
                body.classList.toggle('sb-collapsed');
                localStorage.setItem('sidebar-collapsed', body.classList.contains('sb-collapsed'));
            }
        });

        overlay.addEventListener('click', function() {
            body.classList.remove('sb-mobile-open');
        });
    });
</script>