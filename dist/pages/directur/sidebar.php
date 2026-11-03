<?php require_once __DIR__ . '/../config/branding_helper.php'; ?>
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
                <li class="nav-item menu-open">
                    <a href="./index.php" class="nav-link active">
                        <i class="nav-icon bi bi-speedometer"></i>
                        <p>
                            Dashboard
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
                    <a href="monitoring_approval.php" class="nav-link">
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
                    <a href="rekap_perbaikan.php" class="nav-link">
                        <i class="nav-icon bi bi-calendar-week-fill"></i>
                        <p>
                            Rekap Perbaikan Mingguan
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../cetak_sasaran_mutu.php" class="nav-link">
                        <i class="nav-icon bi bi-printer-fill"></i>
                        <p>
                            Sasaran Mutu
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-clipboard-fill"></i>
                        <p>
                            List FPM
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="list_fpm_downtime.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>List FPM Down Time</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="list_fpm_internal.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>List FPM Internal</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="list_asakai.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Summary Asakai</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-journal-text"></i>
                        <p>
                            LOG TPDP
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="log_tpdp_internal.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>LOG TPDP Internal</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="log_tpdp_downtime.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>LOG TPDP Down Time</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="pilih_modul.php" class="nav-link">
                        <i class="nav-icon bi bi-arrow-left-right"></i>
                        <p>Modul FPM QA</p>
                    </a>
                </li>
                <!-- <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-tree-fill"></i>
                        <p>
                            UI Elements
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./UI/general.html" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>General</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./UI/timeline.html" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Timeline</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-pencil-square"></i>
                        <p>
                            Forms
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./forms/general.html" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>General Elements</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-table"></i>
                        <p>
                            Tables
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./tables/simple.html" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Simple Tables</p>
                            </a>
                        </li>
                    </ul>
                </li> -->
            </ul>

        </nav>
    </div>

</aside>