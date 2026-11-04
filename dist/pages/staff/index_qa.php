<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
// Staff non-QA boleh melihat dashboard ini (read-only); kartu yang menuju halaman
// khusus Staff QA tidak dijadikan link untuk mereka.
$isStaffQa = (($_SESSION['dept'] ?? '') === 'QA');

include __DIR__ . '/../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

// KPI di dashboard ini SENGAJA memakai query & filter bulan yang sama persis dengan
// dept_head_qa/index.php (bukan cuma dipersonalisasi ke 1 username) - supaya angka yang
// dilihat Staff QA dan Dept Head QA selalu sama/konsisten (satu pipeline yang sama,
// cuma beda sudut pandang: Staff QA yang membuat, Dept Head QA yang approve).
$filterBulan = inputBulan('bulan');

$bulanIndo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

if (!empty($filterBulan) && strpos($filterBulan, '-') !== false) {
    $exp = explode('-', $filterBulan);
    $tahun = $exp[0] ?? date('Y');
    $bulan = $exp[1] ?? date('m');
} else {
    $tahun = date('Y');
    $bulan = date('m');
}

$namaPeriode = ($bulanIndo[$bulan] ?? '-') . ' ' . $tahun;

// ================= TOTAL FPM QA Internal DIBUAT =================
// FPM Customer QA disembunyikan dulu di semua role - tidak ikut dihitung.
$qFpmQaBulan = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'QA' AND h.jenis_fpm != 'CUSTOMER'
      AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
");
$totalFpmQaBulan = (int) (mysqli_fetch_assoc($qFpmQaBulan)['total'] ?? 0);

// ================= TOTAL FPM CLAIM MACHINING DIBUAT =================
$qClaimMachBulan = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
");
$totalClaimMachBulan = (int) (mysqli_fetch_assoc($qClaimMachBulan)['total'] ?? 0);

$totalSemuaFpmQaBulan = $totalFpmQaBulan + $totalClaimMachBulan;

// ================= MENUNGGU APPROVAL CLAIM MACHINING (semua bulan) =================
$qPendingClaimMach = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND h.cm_deptheadqa_approved_at IS NULL
      AND (h.status_reject IS NULL OR h.status_reject != 'REJECT')
");
$totalPendingClaimMach = (int) (mysqli_fetch_assoc($qPendingClaimMach)['total'] ?? 0);

// ================= CLAIM MACHINING SUDAH FINAL (Approved Directur) =================
$qClaimMachSelesai = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
      AND h.cm_directur_approved_at IS NOT NULL
");
$totalClaimMachSelesai = (int) (mysqli_fetch_assoc($qClaimMachSelesai)['total'] ?? 0);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <title>Dashboard FPM QA</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Dashboard FPM QA" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="../../../dist/css/dashboard-modern.css" />

    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0" defer></script>
    <script src="../../../dist/js/dashboard-widgets.js" defer></script>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar_qa.php'; ?>

        <main class="app-main">
            <div class="container-fluid dashboard-wrapper">

                <div class="dashboard-title">
                    Dashboard FPM QA
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Ringkasan FPM QA Internal &amp; FPM Claim Machining yang dibuat Staff QA
                    </div>
                </div>

                <div class="filter-box">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-auto fw-semibold">Periode:</div>
                        <div class="col-auto">
                            <input type="month" name="bulan" class="form-control form-control-sm" value="<?= htmlspecialchars($filterBulan) ?>">
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-primary btn-sm">Filter</button>
                            <a href="index_qa.php" class="btn btn-secondary btn-sm">Reset</a>
                        </div>
                    </form>
                </div>

                <?php
                // Perlu Perhatian ditaruh duluan karena itu prioritas utama yang harus
                // dilihat lebih dulu, baru Sasaran Mutu.
                require_once __DIR__ . '/../config/claim_machining_dashboard_widget.php';
                renderPerhatianClaimMachining($koneksi);
                renderSasaranMutuClaimMachining($koneksi, $filterBulan, $namaPeriode);
                ?>

                <div class="row g-3">

                    <div class="col-md-3">
                        <<?= $isStaffQa ? 'a href="list_fpm_claim_machining.php"' : 'div' ?> class="kpi-card kpi-primary" style="text-decoration:none;">
                            <div class="kpi-icon"><i class="bi bi-clipboard-data"></i></div>
                            <div>
                                <div class="kpi-title">Total FPM Dibuat (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $totalSemuaFpmQaBulan ?></div>
                            </div>
                        </<?= $isStaffQa ? 'a' : 'div' ?>>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-primary">
                            <div class="kpi-icon"><i class="bi bi-file-earmark-text"></i></div>
                            <div>
                                <div class="kpi-title">FPM QA Internal (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $totalFpmQaBulan ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <<?= $isStaffQa ? 'a href="list_fpm_claim_machining.php"' : 'div' ?> class="kpi-card kpi-warning" style="text-decoration:none;">
                            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="kpi-title">Claim Machining Menunggu Approval</div>
                                <div class="kpi-value"><?= $totalPendingClaimMach ?></div>
                            </div>
                        </<?= $isStaffQa ? 'a' : 'div' ?>>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-success">
                            <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="kpi-title">Claim Machining Selesai (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $totalClaimMachSelesai ?></div>
                            </div>
                        </div>
                    </div>

                </div>

                <?php
                // Widget yang sama dengan dept_head_qa/index.php, supaya kedua dashboard
                // "disamakan" - lihat claim_machining_dashboard_widget.php untuk catatan
                // keamanannya (aman kalau kolom cm_* belum ada di suatu database).
                require_once __DIR__ . '/../config/claim_machining_dashboard_widget.php';
                renderClaimMachiningDashboardWidget($koneksi, null);
                ?>

            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>

    <script>
        const SELECTOR_SIDEBAR_WRAPPER = ".sidebar-wrapper";
        const Default = { scrollbarTheme: "os-theme-light", scrollbarAutoHide: "leave", scrollbarClickScroll: true };
        document.addEventListener("DOMContentLoaded", function() {
            const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
            if (sidebarWrapper && typeof OverlayScrollbarsGlobal?.OverlayScrollbars !== "undefined") {
                OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                    scrollbars: { theme: Default.scrollbarTheme, autoHide: Default.scrollbarAutoHide, clickScroll: Default.scrollbarClickScroll },
                });
            }
        });
    </script>

</body>

</html>
