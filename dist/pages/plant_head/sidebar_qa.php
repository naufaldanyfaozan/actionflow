<?php require_once __DIR__ . '/../config/branding_helper.php'; ?>
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">

    <div class="sidebar-brand">

        <a href="./index_qa.php" class="brand-link">
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
                <li class="nav-item menu-open">
                    <a href="./index_qa.php" class="nav-link active">
                        <i class="nav-icon bi bi-speedometer"></i>
                        <p>
                            Dashboard FPM QA
                        </p>
                    </a>
                </li>

                <?php
                $monitoringSpBadgeCount = 0;
                if (isset($koneksi) && $koneksi instanceof mysqli) {
                    require_once __DIR__ . '/../config/monitoring_approval_helper.php';
                    $monitoringSpBadgeCount = hitungSpMonitoringApproval($koneksi);
                }
                ?>
                <li class="nav-item">
                    <a href="monitoring_approval.php?modul=qa" class="nav-link">
                        <i class="nav-icon bi bi-diagram-3-fill"></i>
                        <p>
                            Monitor Approval
                            <?php if ($monitoringSpBadgeCount > 0): ?>
                                <span class="badge bg-danger ms-1" title="Jumlah FPM yang sudah kena SP (Surat Peringatan)"><?= $monitoringSpBadgeCount ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../cetak_sasaran_mutu.php?kategori=claim_machining" class="nav-link">
                        <i class="nav-icon bi bi-printer-fill"></i>
                        <p>
                            Sasaran Mutu
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="list_fpm_claim_machining.php" class="nav-link">
                        <i class="nav-icon bi bi-clipboard-check"></i>
                        <p>
                            List FPM Claim Machining
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-journal-text"></i>
                        <p>
                            LOG TPDP QA
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <!-- LOG TPDP Internal QA disembunyikan - FPM QA sekarang hanya Claim Machining.
                             log_tpdp_internal_qa.php tetap ada, tidak dihapus. -->
                        <!-- FPM QA Claim Customer disembunyikan dulu (mungkin dibutuhkan lagi nanti) -
                             log_tpdp_customer.php tetap ada, tidak dihapus.
                        <li class="nav-item">
                            <a href="log_tpdp_customer.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>LOG TPDP Customer</p>
                            </a>
                        </li>
                        -->
                        <li class="nav-item">
                            <a href="log_tpdp_claim_machining.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>LOG TPDP Claim Machining</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="pilih_modul.php" class="nav-link">
                        <i class="nav-icon bi bi-arrow-left-right"></i>
                        <p>Ganti Modul</p>
                    </a>
                </li>
            </ul>

        </nav>
    </div>

</aside>
