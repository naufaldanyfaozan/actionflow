<?php
require_once __DIR__ . '/../config/branding_helper.php';
// Badge Verifikasi Countermeasure kini masuk badge menu "Tugas & Approval".
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
                <?php
                // Satu menu "Tugas & Approval" (Tugas Saya + Monitor Approval sebagai tab). Blok di atas
                // tetap dipanggil karena sekaligus mencatat pelanggaran SP.
                require_once __DIR__ . '/../config/tugas_saya_helper.php';
                renderMenuTugasSaya($koneksi ?? null, true);
                ?>

                <li class="nav-item">
                    <a href="../cetak_sasaran_mutu.php?kategori=claim_machining" class="nav-link">
                        <i class="nav-icon bi bi-printer-fill"></i>
                        <p>
                            Sasaran Mutu
                        </p>
                    </a>
                </li>

                <?php if (($_SESSION['dept'] ?? '') === 'QA'): ?>
                <?php // Verifikasi Countermeasure kini ada di menu "Tugas & Approval" › Tugas Saya. ?>
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
