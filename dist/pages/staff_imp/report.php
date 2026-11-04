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
$filterPlant = inputPlant($_GET['plant'] ?? $userPlant);

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

require_once __DIR__ . '/../config/dashboard_data_fetcher.php';
require_once __DIR__ . '/../config/report_engine.php';

$plantLabel = !empty($filterPlant) ? 'Plant ' . $filterPlant : '';

$data = fetchDashboardData($koneksi, $filterBulan, $filterPlant);
$insights = generateReportInsights($data, $namaPeriode, $plantLabel);

function severityBadgeClass(string $severity): string
{
    return match ($severity) {
        'Tinggi' => 'bg-danger',
        'Sedang' => 'bg-warning text-dark',
        default => 'bg-success',
    };
}

function percentTextClass(float $percent): string
{
    if ($percent >= 80) return 'text-success';
    if ($percent >= 50) return 'text-warning';
    return 'text-danger';
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Laporan Insight Dashboard</title>

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
            <div class="container-fluid dashboard-wrapper">

                <div class="dashboard-title">
                    Laporan Insight Dashboard
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Ringkasan otomatis & rekomendasi tindak lanjut
                    </div>
                </div>

                <div class="filter-box">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-auto fw-semibold">Periode:</div>
                        <div class="col-auto">
                            <input type="month" name="bulan" class="form-control form-control-sm" value="<?= htmlspecialchars($filterBulan) ?>">
                        </div>

                        <div class="col-auto fw-semibold">Plant:</div>
                        <div class="col-auto">
                            <select name="plant" class="form-control form-control-sm">
                                <option value="">-- All Plant --</option>
                                <option value="1" <?= ($filterPlant == '1') ? 'selected' : '' ?>>Plant 1</option>
                                <option value="3" <?= ($filterPlant == '3') ? 'selected' : '' ?>>Plant 3</option>
                            </select>
                        </div>

                        <div class="col-auto">
                            <button class="btn btn-primary btn-sm">Filter</button>
                            <a href="report.php" class="btn btn-secondary btn-sm">Reset</a>
                            <a href="report_export.php?bulan=<?= urlencode($filterBulan) ?>&plant=<?= urlencode($filterPlant) ?>" class="btn-export">
                                <i class="bi bi-file-earmark-word"></i> Download Word
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> Kembali ke Dashboard
                            </a>
                        </div>
                    </form>
                </div>

                <div class="card section">
                    <div class="card-header bg-primary text-white">Ringkasan Eksekutif</div>
                    <div class="card-body">
                        <p style="margin: 0; line-height: 1.7;"><?= htmlspecialchars($insights['executive_summary']) ?></p>
                    </div>
                </div>

                <div class="card section">
                    <div class="card-header bg-danger text-white">Rekomendasi Tindakan Prioritas</div>
                    <div class="card-body">
                        <?php if (empty($insights['priority_actions'])): ?>
                            <p class="text-muted mb-0">Tidak ada tindakan prioritas pada periode ini.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th style="width: 110px;">Prioritas</th>
                                            <th style="width: 200px;">Kategori</th>
                                            <th>Rekomendasi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($insights['priority_actions'] as $action): ?>
                                            <tr>
                                                <td><span class="badge <?= severityBadgeClass($action['severity']) ?>"><?= $action['severity'] ?></span></td>
                                                <td><?= htmlspecialchars($action['category']) ?></td>
                                                <td><?= htmlspecialchars($action['text']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row section g-3">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white">Departemen Perlu Perhatian — FPM</div>
                            <div class="card-body table-responsive">
                                <table class="table table-bordered text-center align-middle">
                                    <thead>
                                        <tr>
                                            <th>Department</th>
                                            <th>Open</th>
                                            <th>Closed</th>
                                            <th>% Closed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($insights['dept_ranking']['fpm'])): ?>
                                            <tr>
                                                <td colspan="4">Tidak ada data</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($insights['dept_ranking']['fpm'] as $d): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($d['dept']) ?></td>
                                                    <td><?= $d['open'] ?></td>
                                                    <td><?= $d['closed'] ?></td>
                                                    <td class="fw-bold <?= percentTextClass($d['percent']) ?>"><?= $d['percent'] ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-success text-white">Departemen Perlu Perhatian — Asakai</div>
                            <div class="card-body table-responsive">
                                <table class="table table-bordered text-center align-middle">
                                    <thead>
                                        <tr>
                                            <th>Department</th>
                                            <th>Open</th>
                                            <th>Closed</th>
                                            <th>% Closed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($insights['dept_ranking']['asakai'])): ?>
                                            <tr>
                                                <td colspan="4">Tidak ada data</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($insights['dept_ranking']['asakai'] as $d): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($d['dept']) ?></td>
                                                    <td><?= $d['open'] ?></td>
                                                    <td><?= $d['closed'] ?></td>
                                                    <td class="fw-bold <?= percentTextClass($d['percent']) ?>"><?= $d['percent'] ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card section">
                    <div class="card-header bg-danger text-white">Countermeasure Overdue</div>
                    <div class="card-body table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th>Sumber</th>
                                    <th>PIC</th>
                                    <th>Deskripsi</th>
                                    <th>Due Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($insights['overdue'])): ?>
                                    <tr>
                                        <td colspan="4">Tidak ada countermeasure overdue</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($insights['overdue'] as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['source']) ?></td>
                                            <td><?= htmlspecialchars($row['pic'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['deskripsi'] ?? '-') ?></td>
                                            <td class="text-danger fw-bold"><?= htmlspecialchars($row['due_date'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row section g-3">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-warning text-dark">Part / Mesin Bermasalah Berulang</div>
                            <div class="card-body table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>Jenis</th>
                                            <th>Nama</th>
                                            <th>Jumlah</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($insights['recurring_problems'])): ?>
                                            <tr>
                                                <td colspan="3">Tidak ada masalah berulang</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($insights['recurring_problems'] as $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['jenis']) ?></td>
                                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                                    <td><?= $row['total'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-info text-white">Risiko Stock Part</div>
                            <div class="card-body table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>Nama Part</th>
                                            <th>Stock FG</th>
                                            <th>Delivery/Hari</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($insights['stock_risk'])): ?>
                                            <tr>
                                                <td colspan="3">Tidak ada part berisiko</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($insights['stock_risk'] as $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['nama_part'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars((string) ($row['act_stock'] ?? '-')) ?></td>
                                                    <td><?= htmlspecialchars((string) ($row['delivery_per_hari'] ?? '-')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
