<?php $halamanAdmin = basename($_SERVER['PHP_SELF']); ?>
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">

    <div class="sidebar-brand">
        <a href="./index.php" class="brand-link">
            <img src="<?= htmlspecialchars(brandLogo()) ?>" alt="Logo" class="brand-image opacity-75 shadow" />
            <span class="brand-text fw-light"><?= htmlspecialchars(brandNama()) ?></span>
        </a>
    </div>

    <div class="sidebar-wrapper">
        <nav class="mt-2">
            <ul
                class="nav sidebar-menu flex-column"
                data-lte-toggle="treeview"
                role="menu"
                data-accordion="false">
                <li class="nav-header">ADMIN IT</li>
                <li class="nav-item">
                    <a href="./index.php" class="nav-link <?= $halamanAdmin === 'index.php' ? 'active' : '' ?>">
                        <i class="nav-icon bi bi-sliders"></i>
                        <p>Pengaturan Aturan</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="./notifikasi.php" class="nav-link <?= $halamanAdmin === 'notifikasi.php' ? 'active' : '' ?>">
                        <i class="nav-icon bi bi-bell"></i>
                        <p>Notifikasi WA &amp; Email</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="./identitas.php" class="nav-link <?= $halamanAdmin === 'identitas.php' ? 'active' : '' ?>">
                        <i class="nav-icon bi bi-palette"></i>
                        <p>Identitas Aplikasi</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="./riwayat.php" class="nav-link <?= $halamanAdmin === 'riwayat.php' ? 'active' : '' ?>">
                        <i class="nav-icon bi bi-clock-history"></i>
                        <p>Riwayat Perubahan</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

</aside>
