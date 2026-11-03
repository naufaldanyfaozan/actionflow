<?php
require_once __DIR__ . '/../config/branding_helper.php';
$verifikasiQaBadgeCount = 0;
$revisiDiperbaikiQaBadgeCount = 0;
$rescheduleQaBadgeCount = 0;
if (isset($koneksi) && (($_SESSION['dept'] ?? '') === 'QA')) {
    require_once __DIR__ . '/../config/verifikasi_countermeasure_helper.php';
    $verifikasiQaBadgeCount = countPendingCountermeasureVerifikasi($koneksi, '', true)['fpm'];
    require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
    $revisiDiperbaikiQaBadgeCount = count(ambilRevisiPerluDicekQa($koneksi));
    require_once __DIR__ . '/../config/fpm_reschedule_helper.php';
    $rescheduleQaBadgeCount = hitungRescheduleBelumDibaca($koneksi, true);
}
?>
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

                <?php if (($_SESSION['dept'] ?? '') === 'QA'): ?>
                <li class="nav-item">
                    <a href="verifikasi_countermeasure.php" class="nav-link">
                        <i class="nav-icon bi bi-patch-check-fill"></i>
                        <p>
                            Verifikasi Countermeasure
                            <?php if ($verifikasiQaBadgeCount > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $verifikasiQaBadgeCount ?></span>
                            <?php endif; ?>
                            <?php if ($revisiDiperbaikiQaBadgeCount > 0): ?>
                                <span class="badge bg-primary ms-1" title="Revisi sudah diperbaiki PIC, perlu dicek"><i class="bi bi-arrow-counterclockwise"></i> <?= $revisiDiperbaikiQaBadgeCount ?></span>
                            <?php endif; ?>
                            <?php if ($rescheduleQaBadgeCount > 0): ?>
                                <span class="badge bg-warning text-dark ms-1" title="Countermeasure di-reschedule, belum dibaca"><i class="bi bi-calendar-event"></i> <?= $rescheduleQaBadgeCount ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-file-earmark-check-fill"></i>
                        <p>
                            Dept QA
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <!-- FPM Internal QA disembunyikan dulu (mungkin dibutuhkan lagi nanti) -
                             buat_fpm_qa_internal.php dan list_fpm_qa_semua.php tetap ada, tidak dihapus. -->
                        <!-- FPM QA Claim Customer disembunyikan dulu (mungkin dibutuhkan lagi nanti) -
                             buat_fpm_customer_qa.php dan list_fpm_qa_semua.php tetap ada, tidak dihapus.
                        <li class="nav-item">
                            <a href="buat_fpm_customer_qa.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Buat FPM NG Customer</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="list_fpm_qa_semua.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>List &amp; Jawab FPM QA</p>
                            </a>
                        </li>
                        -->
                        <li class="nav-item">
                            <a href="buat_fpm_claim_machining.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Buat FPM Claim Machining</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="list_fpm_claim_machining.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>List FPM Claim Machining</p>
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
                            <a href="master_problem.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>Master Problem</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="nav-icon bi bi-journal-text"></i>
                        <p>
                            LOG TPDP QA
                            <i class="nav-arrow bi bi-chevron-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <!-- LOG TPDP Internal QA dulu di sini, tapi sub-alur FPM Internal QA
                             (flow_type='QA') sedang dinonaktifkan jadi selalu kosong - diganti
                             tampilkan Claim Machining (flow_type='CLAIMMACH', no_form FPM-Cast)
                             yang datanya aktif dipakai, supaya tidak ada menu yang mubazir. -->
                        <li class="nav-item">
                            <a href="log_tpdp_claim_machining.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>LOG TPDP Claim Machining</p>
                            </a>
                        </li>
                        <!-- FPM QA Claim Customer disembunyikan dulu (mungkin dibutuhkan lagi nanti) -
                             log_tpdp_customer.php tetap ada, tidak dihapus.
                        <li class="nav-item">
                            <a href="log_tpdp_customer.php" class="nav-link">
                                <i class="nav-icon bi bi-circle"></i>
                                <p>LOG TPDP Customer</p>
                            </a>
                        </li>
                        -->
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
