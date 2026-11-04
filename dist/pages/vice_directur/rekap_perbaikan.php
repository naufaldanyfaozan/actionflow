<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$filterBulan = inputBulan('bulan');

$userPlant = $_SESSION['plant'] ?? '';
$userPlant = trim(str_ireplace('Plant', '', $userPlant));

$filterPlant  = $_GET['plant'] ?? $userPlant;
$filterDept   = $_GET['dept'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$bulanIndo = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maret',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Desember'
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

require_once __DIR__ . '/../config/weekly_repair_recap_fetcher.php';
$recap = fetchWeeklyRepairRecap($koneksi, $filterBulan, $filterPlant, $filterDept, $filterStatus);
$fpmTargetPage = 'form_fpm.php';

$masterDept = [];
$qDept = $koneksi->query("SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");
while ($rowDept = $qDept->fetch_assoc()) {
    $masterDept[] = $rowDept['nama_dept'];
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Rekap Perbaikan Mingguan</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="../../../dist/css/dashboard-modern.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">
            <?php require __DIR__ . '/../config/weekly_repair_recap_view.php'; ?>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>

    <script>
        const SELECTOR_SIDEBAR_WRAPPER = ".sidebar-wrapper";
        const Default = {
            scrollbarTheme: "os-theme-light",
            scrollbarAutoHide: "leave",
            scrollbarClickScroll: true,
        };
        document.addEventListener("DOMContentLoaded", function() {
            const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
            if (
                sidebarWrapper &&
                typeof OverlayScrollbarsGlobal?.OverlayScrollbars !== "undefined"
            ) {
                OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                    scrollbars: {
                        theme: Default.scrollbarTheme,
                        autoHide: Default.scrollbarAutoHide,
                        clickScroll: Default.scrollbarClickScroll,
                    },
                });
            }
        });
    </script>

</body>

</html>
