<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include __DIR__ . '/../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

// Filter bulan yang sama polanya dengan dept_head_qa/index.php, dipakai buat section
// "Sasaran Mutu - FPM Claim Machining" di bawah. Vice Directur tidak punya menu akses
// langsung ke FPM Claim Machining (lihat sidebar_qa.php), tapi widget & section ini
// murni tampilan (tidak ada link ke halaman yang tidak bisa diakses role ini), jadi
// aman ditampilkan sebagai info monitoring read-only.
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

    <style>
        .shortcut-card {
            display: block;
            background: #fff;
            border-radius: 14px;
            padding: 24px 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
            text-decoration: none;
            color: #1e293b;
            height: 100%;
            border: 2px solid transparent;
            transition: 0.15s;
        }

        .shortcut-card:hover {
            border-color: #0d6efd;
            color: #1e293b;
            transform: translateY(-2px);
        }

        .shortcut-card i {
            font-size: 32px;
            color: #0d6efd;
            margin-bottom: 10px;
            display: block;
        }

        .shortcut-card h6 {
            font-weight: 600;
            margin-bottom: 4px;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar_qa.php'; ?>

        <main class="app-main">
            <div class="container-fluid dashboard-wrapper">

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
                renderClaimMachiningDashboardWidget($koneksi, null);
                ?>

                <h4 style="font-weight:600; margin: 24px 0 16px;">
                    <i class="bi bi-patch-check-fill"></i> Modul FPM QA
                </h4>

                <div class="row g-3">
                    <div class="col-md-4">
                        <a href="log_tpdp_internal_qa.php" class="shortcut-card">
                            <i class="bi bi-journal-text"></i>
                            <h6>LOG TPDP Internal QA</h6>
                            <p class="text-muted mb-0" style="font-size:13px;">Log TPDP internal khusus QA.</p>
                        </a>
                    </div>
                </div>

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
