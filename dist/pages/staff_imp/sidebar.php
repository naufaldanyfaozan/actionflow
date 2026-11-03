<?php
require_once __DIR__ . '/../config/branding_helper.php';
$verifikasiBadgeCount = 0;
$revisiDiperbaikiBadgeCount = 0;
if (isset($koneksi) && isset($_SESSION['username'])) {
    require_once __DIR__ . '/../config/verifikasi_countermeasure_helper.php';
    $verifikasiPlant = resolveVerifikasiPlant($_SESSION['username'], null);
    $verifikasiBadgeCount = countPendingCountermeasureVerifikasi($koneksi, $verifikasiPlant)['total'];
    require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
    $revisiDiperbaikiBadgeCount = count(ambilRevisiPerluDicekImp($koneksi, $verifikasiPlant));
    // Reschedule due date dari Staff / Dept Head tidak perlu diverifikasi Staff IMP,
    // jadi tidak ikut dihitung di badge menu ini.
}
?>
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
                    <a href="verifikasi_countermeasure.php" class="nav-link">
                        <i class="nav-icon bi bi-patch-check-fill"></i>
                        <p>
                            Verifikasi Countermeasure
                            <?php if ($verifikasiBadgeCount > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $verifikasiBadgeCount ?></span>
                            <?php endif; ?>
                            <?php if ($revisiDiperbaikiBadgeCount > 0): ?>
                                <span class="badge bg-primary ms-1" title="Revisi sudah diperbaiki PIC, perlu dicek"><i class="bi bi-arrow-counterclockwise"></i> <?= $revisiDiperbaikiBadgeCount ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-box-seam-fill"></i>
                        <p>
                            Buat FPM
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="buat_fpm_down_time.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>FPM Down Time</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="buat_fpm_ng_internal.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>FPM NG Internal</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="buat_fpm_asakai.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Buat Summary ASAKAI</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-clipboard-fill"></i>
                        <p>
                            List FPM & Asakai
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
                            <a href="bahan_asakai.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>List Pembahasan Asakai</p>
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
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-pencil-fill"></i>
                        <p>
                            Master Database
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="master_part.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Master Produk</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="master_mesin.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Master Machine</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="master_problem.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Master Problem</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-pencil-fill"></i>
                        <p>
                            Informasi Stock
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="problem_stock.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Input Stock Finish Good</p>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="problem_stock_casting.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Input Stock Casting - Machining</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- <li class="nav-item">
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