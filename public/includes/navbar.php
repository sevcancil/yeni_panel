<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Muhasebe v1</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Ana Sayfa</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="customers.php">Cari Hesaplar</a> 
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transaction-add.php">Hızlı İşlem Ekle</a>
                </li>
                <?php if(has_permission('view_finance')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="channels.php">Kasa/Banka</a>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="projects.php">Tur Kodları</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="payment-orders.php">Ödeme Emirleri</a>
                </li>
                <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">Kullanıcılar</a>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <span class="nav-link text-white">Merhaba, <?php echo guvenli_html($_SESSION['username']); ?></span>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white ms-2 px-3" href="logout.php">Çıkış</a>
                </li>
            </ul>
        </div>
    </div>
</nav>