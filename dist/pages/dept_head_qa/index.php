<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include __DIR__ . '/../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$filterBulan = !empty($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');

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

// FPM Customer QA disembunyikan dulu di semua role - tidak ikut di dashboard.
$whereFPM = "WHERE h.flow_type = 'QA' AND h.jenis_fpm != 'CUSTOMER' AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'";

include __DIR__ . '/../config/dashboard_severity_helper.php';
$problemBerulangQA = hitungProblemBerulangFpm($koneksi, $whereFPM);
$topBerulangQA = topProblemBerulang($koneksi, $whereFPM, 10);
$rankingPicQA = rankingDepartemenPenanganan($koneksi, $whereFPM, null, 10);

// ================= KPI =================
$kpi = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT

    COUNT(*) AS total,

    SUM(CASE WHEN h.status_reject = 'REJECT' THEN 1 ELSE 0 END) AS total_revisi,

    SUM(CASE WHEN
        (
            (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
        ) > 0
        AND NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND (ca.status IS NULL OR ca.status != 'CLOSED'))
        AND NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND (pa.status IS NULL OR pa.status != 'CLOSED'))
        AND NOT EXISTS (SELECT 1 FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm AND (rd.status IS NULL OR rd.status != 'CLOSED'))
    THEN 1 ELSE 0 END) AS total_closed

FROM fpm_header h $whereFPM"));

$totalFPM     = (int) ($kpi['total'] ?? 0);
$totalRevisi  = (int) ($kpi['total_revisi'] ?? 0);
$totalClosed  = (int) ($kpi['total_closed'] ?? 0);
$totalOpen    = $totalFPM - $totalClosed;

$qPending = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
    WHERE h.flow_type = 'QA' AND h.jenis_fpm != 'CUSTOMER'
      AND p.diperiksa = 'Plant Head'
      AND (p.disetujui1 IS NULL OR p.disetujui1 = '')
      AND (h.status_reject IS NULL OR h.status_reject = '')
");
$totalPending = (int) (mysqli_fetch_assoc($qPending)['total'] ?? 0);

$qApproved = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM fpm_pengiriman p
    JOIN fpm_header h ON h.id_fpm = p.id_fpm
    WHERE p.disetujui1 = 'Dept Head QA' AND h.jenis_fpm != 'CUSTOMER'");
$totalApproved = (int) (mysqli_fetch_assoc($qApproved)['total'] ?? 0);

// ================= MENUNGGU APPROVAL CLAIM MACHINING =================
// Alur v3: begitu Staff QA membuat FPM Claim Machining, Dept Head QA approve DULU
// sebelum diteruskan ke Plant Head (lihat cm_deptheadqa_approved_at) - dihitung
// dari semua bulan (bukan discope ke $filterBulan) supaya antrian yang menunggu
// tidak "hilang" dari dashboard cuma karena beda bulan pembuatannya.
$qPendingClaimMach = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND h.cm_deptheadqa_approved_at IS NULL
      AND (h.status_reject IS NULL OR h.status_reject != 'REJECT')
");
$totalPendingClaimMach = (int) (mysqli_fetch_assoc($qPendingClaimMach)['total'] ?? 0);

// ================= TOTAL FPM CLAIM MACHINING (bulan berjalan) =================
// Ditambahkan supaya dashboard tidak terlihat kosong kalau belum ada FPM QA
// (Customer/Internal) - dashboard ini sebelumnya cuma menghitung flow_type='QA',
// belum pernah menghitung total flow_type='CLAIMMACH' kecuali KPI "Menunggu
// Approval Claim Machining" di atas (yang cuma menghitung yang belum diapprove).
$qClaimMachBulan = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
");
$totalClaimMachBulan = (int) (mysqli_fetch_assoc($qClaimMachBulan)['total'] ?? 0);

$qClaimMachSelesai = mysqli_query($koneksi, "SELECT COUNT(*) AS total
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
      AND h.cm_directur_approved_at IS NOT NULL
");
$totalClaimMachSelesai = (int) (mysqli_fetch_assoc($qClaimMachSelesai)['total'] ?? 0);

// ================= DISTRIBUSI PER DEPARTEMEN =================
$queryDept = mysqli_query($koneksi, "SELECT h.dept, COUNT(*) AS total,

    SUM(CASE WHEN h.status_reject = 'REJECT' THEN 1 ELSE 0 END) AS revisi,

    SUM(CASE WHEN
        (
            (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
        ) > 0
        AND NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND (ca.status IS NULL OR ca.status != 'CLOSED'))
        AND NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND (pa.status IS NULL OR pa.status != 'CLOSED'))
        AND NOT EXISTS (SELECT 1 FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm AND (rd.status IS NULL OR rd.status != 'CLOSED'))
    THEN 1 ELSE 0 END) AS closed

    FROM fpm_header h
    $whereFPM
    GROUP BY h.dept ORDER BY total DESC");

$deptLabel = [];
$deptTotal = [];
$deptClosed = [];
$deptOpen = [];
$deptRevisi = [];

while ($row = mysqli_fetch_assoc($queryDept)) {
    $deptLabel[]  = $row['dept'] ?: '-';
    $deptTotal[]  = (int) $row['total'];
    $deptClosed[] = (int) $row['closed'];
    $deptOpen[]   = (int) $row['total'] - (int) $row['closed'];
    $deptRevisi[] = (int) $row['revisi'];
}

// ================= JENIS MASALAH TERBANYAK =================
$queryJenisMasalah = mysqli_query($koneksi, "SELECT jenis_masalah, COUNT(*) AS total
    FROM fpm_header h $whereFPM AND jenis_masalah IS NOT NULL AND jenis_masalah != ''
    GROUP BY jenis_masalah ORDER BY total DESC LIMIT 10");

$jenisMasalahLabel = [];
$jenisMasalahTotal = [];
while ($row = mysqli_fetch_assoc($queryJenisMasalah)) {
    $jenisMasalahLabel[] = $row['jenis_masalah'];
    $jenisMasalahTotal[] = (int) $row['total'];
}

// ================= PART BERMASALAH TERBANYAK =================
$queryPart = mysqli_query($koneksi, "SELECT CONCAT(nama_part, ' - ', jenis_masalah) AS label, COUNT(*) AS total
    FROM fpm_header h $whereFPM AND nama_part IS NOT NULL AND nama_part != ''
    GROUP BY nama_part, jenis_masalah ORDER BY total DESC LIMIT 10");

$partLabel = [];
$partTotal = [];
while ($row = mysqli_fetch_assoc($queryPart)) {
    $partLabel[] = $row['label'];
    $partTotal[] = (int) $row['total'];
}

// ================= MESIN BERMASALAH TERBANYAK =================
$queryMesin = mysqli_query($koneksi, "SELECT CONCAT(machine_no, ' - ', jenis_masalah) AS label, COUNT(*) AS total
    FROM fpm_header h $whereFPM AND machine_no IS NOT NULL AND machine_no != ''
    GROUP BY machine_no, jenis_masalah ORDER BY total DESC LIMIT 10");

$mesinLabel = [];
$mesinTotal = [];
while ($row = mysqli_fetch_assoc($queryMesin)) {
    $mesinLabel[] = $row['label'];
    $mesinTotal[] = (int) $row['total'];
}

// ================= COUNTERMEASURE OVERDUE (FPM QA) =================
$queryOverdue = mysqli_query($koneksi, "SELECT pic, tindakan AS deskripsi, due_date, status, h.no_form, h.id_fpm, h.machine_no, h.nama_part
    FROM (
        SELECT ca.pic, ca.tindakan, ca.due_date, ca.status, ca.actual_date, ca.id_fpm
        FROM fpm_corrective_action ca
        JOIN fpm_header h2 ON ca.id_fpm = h2.id_fpm WHERE h2.flow_type = 'QA' AND h2.jenis_fpm != 'CUSTOMER'
        UNION ALL
        SELECT pa.pic, pa.tindakan, pa.due_date, pa.status, pa.actual_date, pa.id_fpm
        FROM fpm_preventive_action pa
        JOIN fpm_header h2 ON pa.id_fpm = h2.id_fpm WHERE h2.flow_type = 'QA' AND h2.jenis_fpm != 'CUSTOMER'
    ) AS gabungan
    JOIN fpm_header h ON h.id_fpm = gabungan.id_fpm
    WHERE gabungan.status != 'CLOSED'
      AND (gabungan.actual_date IS NULL OR gabungan.actual_date = '')
      AND gabungan.due_date < CURDATE()
    ORDER BY gabungan.due_date ASC
    LIMIT 20");

$overdueData = mysqli_fetch_all($queryOverdue, MYSQLI_ASSOC) ?: [];
?>

<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Dashboard - Dept Head QA</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Dashboard Dept Head QA" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="../../../dist/css/dashboard-modern.css" />

    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0" defer></script>
    <script src="../../../dist/js/dashboard-widgets.js" defer></script>
    <!-- bootstrap.min.css terpisah ini dihapus dari sini - adminlte.css sudah membundel
         Bootstrap 5 lengkap (grid, card, dst), jadi file ini cuma duplikat dari versi
         bootstrap yang beda (5.3.0 vs 5.3.2) dan menimpa beberapa style dasar AdminLTE
         (mis. .nav-link) karena urutannya dimuat belakangan - salah satu dugaan penyebab
         submenu sidebar (.nav-treeview) di halaman dashboard kadang tidak merespon. -->
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">
            <div class="container-fluid dashboard-wrapper">

                <div class="dashboard-title">
                    Dashboard Dept Head QA
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Approval FPM QA (Internal) setelah lolos Plant Head, sebelum diteruskan ke Directure
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
                            <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
                        </div>
                    </form>
                </div>

                <?php require_once __DIR__ . '/../config/claim_machining_dashboard_widget.php'; ?>

                <h3 style="text-align: left; font-size: 16px; font-weight: bold; border-left: 5px solid #f5c2c7; padding-left: 10px; color:#b02a37; margin-top: 24px;">
                    <i class="bi bi-exclamation-octagon-fill"></i> Perlu Perhatian Segera
                </h3>

                <div class="row g-3">

                    <div class="col-md-3">
                        <a href="list_fpm_claim_machining.php" class="kpi-card kpi-danger" style="text-decoration:none;">
                            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="kpi-title">Menunggu Approval Claim Machining</div>
                                <div class="kpi-value"><?= $totalPendingClaimMach ?></div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-danger">
                            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="kpi-title">Menunggu Approval FPM QA Saya</div>
                                <div class="kpi-value"><?= $totalPending ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-danger">
                            <div class="kpi-icon"><i class="bi bi-alarm-fill"></i></div>
                            <div>
                                <div class="kpi-title">Countermeasure FPM QA Overdue</div>
                                <div class="kpi-value"><?= count($overdueData) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-warning">
                            <div class="kpi-icon"><i class="bi bi-arrow-repeat"></i></div>
                            <div>
                                <div class="kpi-title">Problem Berulang (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $problemBerulangQA ?></div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row section g-3">

                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-danger text-white">
                                Countermeasure FPM QA Over Due Date
                            </div>
                            <div class="card-body table-responsive">
                                <?php if (!empty($overdueData)): ?>
                                    <div class="list-pagination-toolbar">
                                        Tampilkan
                                        <select class="list-pagination-select" data-paginate-size-for="fpmqaOverdueTable">
                                            <option value="10">10</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                            <option value="200">200</option>
                                        </select>
                                        baris
                                    </div>
                                <?php endif; ?>
                                <table id="fpmqaOverdueTable" class="table table-bordered table-sm text-center align-middle" <?= !empty($overdueData) ? 'data-paginate="10"' : '' ?>>
                                    <thead>
                                        <tr>
                                            <th>No Form</th>
                                            <th>PIC</th>
                                            <th>Tindakan</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th>Mesin</th>
                                            <th>Part</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($overdueData)): ?>
                                            <tr><td colspan="8">Tidak ada countermeasure over due date</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($overdueData as $i => $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['no_form']) ?></td>
                                                    <td><?= htmlspecialchars($row['pic']) ?></td>
                                                    <td><?= htmlspecialchars($row['deskripsi']) ?></td>
                                                    <td class="text-danger fw-bold"><?= htmlspecialchars($row['due_date']) ?></td>
                                                    <td><span class="badge bg-danger"><?= htmlspecialchars($row['status']) ?></span></td>
                                                    <td><?= htmlspecialchars($row['machine_no'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($row['nama_part'] ?? '') ?></td>
                                                    <td>
                                                        <a href="form_fpm.php?id=<?= $row['id_fpm'] ?>" class="btn btn-outline-primary btn-sm">Detail</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <?php if (!empty($overdueData)): ?>
                                    <div class="list-pagination-nav">
                                        <button type="button" class="list-pagination-btn" data-paginate-prev="fpmqaOverdueTable">&laquo; Sebelumnya</button>
                                        <span class="list-pagination-info" data-paginate-info="fpmqaOverdueTable"></span>
                                        <button type="button" class="list-pagination-btn" data-paginate-next="fpmqaOverdueTable">Berikutnya &raquo;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

                <h3 style="text-align: left; font-size: 16px; font-weight: bold; border-left: 5px solid #cfe2ff; padding-left: 10px; color:#084298; margin-top: 32px;">
                    <i class="bi bi-eye-fill"></i> Wajib Dimonitor
                </h3>

                <?php
                // Widget FPM Claim Machining - fungsi ini sudah aman kalau kolom cm_*
                // belum ada (mysqli_report OFF -> query gagal cuma return false, widget
                // sembunyikan diri sendiri, tidak fatal error - lihat claim_machining_
                // dashboard_widget.php). TETAP PASTIKAN semua migration cm_* (migration_
                // 2026_09_04, _07, _07b, dan _2026_09_14_fpm_claim_machining_dept_head_
                // approval) sudah dijalankan di database hosting sebelum deploy, supaya
                // widget ini benar-benar tampil datanya (bukan cuma "aman tidak error").
                require_once __DIR__ . '/../config/claim_machining_dashboard_widget.php';
                renderClaimMachiningDashboardWidget($koneksi, null);
                ?>


                <div class="row section g-3">

                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-danger text-white">
                                Top Problem Berulang (Part / Mesin dengan Kejadian Berulang)
                            </div>
                            <div class="card-body table-responsive">
                                <?php if ($topBerulangQA): ?>
                                    <table id="topBerulangQaTable" class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th>Tanggal</th>
                                                <th>Part</th>
                                                <th>Mesin</th>
                                                <th>Jenis Masalah</th>
                                                <th class="text-center">Kejadian Ke-</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topBerulangQA as $i => $b): ?>
                                                <tr class="<?= $i >= 5 ? 'table-extra-row' : '' ?>">
                                                    <td><?= htmlspecialchars($b['tgl_ditemukan'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($b['nama_part'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($b['machine_no'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($b['jenis_masalah'] ?? '-') ?></td>
                                                    <td class="text-center"><span class="badge bg-danger"><?= (int)$b['kejadian_berulang_ke'] ?>x</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php if (count($topBerulangQA) > 5): ?>
                                        <button type="button" class="table-expand-toggle" data-expand-toggle="topBerulangQaTable"
                                            data-label-expand="Lihat <?= count($topBerulangQA) - 5 ?> lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($topBerulangQA) - 5 ?> lainnya &#9662;</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-3">Tidak ada problem berulang tercatat pada periode ini.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-dark text-white">
                                Ranking PIC &mdash; Penanganan Countermeasure QA
                            </div>
                            <div class="card-body table-responsive">
                                <?php if ($rankingPicQA): ?>
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th>PIC</th>
                                                <th class="text-center">Total</th>
                                                <th class="text-center">Open</th>
                                                <th>% Open</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rankingPicQA as $r):
                                                $persen = (float)$r['persen_open'];
                                                $badgeClass = $persen >= 50 ? 'bg-danger' : ($persen >= 25 ? 'bg-warning text-dark' : 'bg-success');
                                            ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($r['pic']) ?></td>
                                                    <td class="text-center"><?= (int)$r['total'] ?></td>
                                                    <td class="text-center"><?= (int)$r['total_open'] ?></td>
                                                    <td><span class="badge <?= $badgeClass ?>"><?= $persen ?>%</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="text-center text-muted py-3">Belum ada data countermeasure pada periode ini.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

                <?php
                // Sementara dashboard Dept Head QA difokuskan ke poin yang perlu perhatian
                // & wajib dimonitor saja. Sasaran Mutu dan statistik umum di bawah ini
                // disembunyikan dulu (bukan dihapus) - ubah ke true untuk menampilkan lagi.
                $tampilkanStatistikLengkap = false;
                ?>
                <?php if ($tampilkanStatistikLengkap): ?>

                <?php
                // Ditaruh SETELAH "Perlu Perhatian Segera" karena itu prioritas utama
                // yang harus dilihat duluan.
                renderSasaranMutuClaimMachining($koneksi, $filterBulan, $namaPeriode);
                ?>

                <div class="row section g-3">

                    <div class="col-md-3">
                        <div class="kpi-card kpi-primary">
                            <div class="kpi-icon"><i class="bi bi-clipboard-data"></i></div>
                            <div>
                                <div class="kpi-title">Total FPM QA (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $totalFPM ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-warning">
                            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="kpi-title">Menunggu Approval Saya</div>
                                <div class="kpi-value"><?= $totalPending ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-success">
                            <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="kpi-title">Sudah Saya Approve</div>
                                <div class="kpi-value"><?= $totalApproved ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card kpi-warning">
                            <div class="kpi-icon"><i class="bi bi-arrow-repeat"></i></div>
                            <div>
                                <div class="kpi-title">Diminta Revisi (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $totalRevisi ?></div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row section g-3">

                    <div class="col-md-6">
                        <a href="list_fpm_claim_machining.php" class="kpi-card kpi-primary" style="text-decoration:none;">
                            <div class="kpi-icon"><i class="bi bi-clipboard-data"></i></div>
                            <div>
                                <div class="kpi-title">Total FPM Claim Machining (<?= htmlspecialchars($namaPeriode) ?>)</div>
                                <div class="kpi-value"><?= $totalClaimMachBulan ?></div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-6">
                        <div class="kpi-card kpi-success">
                            <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="kpi-title">FPM Claim Machining Selesai (Approved Directur)</div>
                                <div class="kpi-value"><?= $totalClaimMachSelesai ?></div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row section g-3">

                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white">
                                Rekap FPM QA per Departemen Terkait (<?= htmlspecialchars($namaPeriode) ?>)
                            </div>
                            <div class="card-body table-responsive">
                                <table class="table table-bordered text-center align-middle">
                                    <thead>
                                        <tr>
                                            <th>Departemen Terkait</th>
                                            <th>Total</th>
                                            <th>Open</th>
                                            <th>Closed</th>
                                            <th>Revisi</th>
                                            <th>% Closed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($deptLabel)): ?>
                                            <tr><td colspan="6">Tidak ada data pada periode ini</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($deptLabel as $i => $dept): ?>
                                                <?php $percent = $deptTotal[$i] > 0 ? round(($deptClosed[$i] / $deptTotal[$i]) * 100, 2) : 0; ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($dept) ?></td>
                                                    <td><?= $deptTotal[$i] ?></td>
                                                    <td class="text-danger fw-semibold"><?= $deptOpen[$i] ?></td>
                                                    <td class="text-success fw-semibold"><?= $deptClosed[$i] ?></td>
                                                    <td class="text-warning fw-semibold"><?= $deptRevisi[$i] ?></td>
                                                    <td>
                                                        <span class="fw-bold <?= $percent >= 80 ? 'text-success' : ($percent >= 50 ? 'text-warning' : 'text-danger') ?>">
                                                            <?= $percent ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row section g-3">

                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-danger text-white">
                                Top Masalah Terbanyak
                            </div>
                            <div class="card-body">
                                <div class="chart-tab-switch">
                                    <button type="button" class="active" data-key="jenis">Jenis Masalah</button>
                                    <button type="button" data-key="part">Part</button>
                                    <button type="button" data-key="mesin">Mesin</button>
                                </div>
                                <?php if (empty($jenisMasalahLabel) && empty($partLabel) && empty($mesinLabel)): ?>
                                    <div class="text-center text-muted py-3">Tidak ada data pada periode ini</div>
                                <?php else: ?>
                                    <div class="chart-large">
                                        <canvas id="topMasalahChart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

                <?php endif; ?>

            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        /* chart.js dimuat "defer" (lihat <head>) supaya tidak memblokir parsing HTML -
           sidebar jadi langsung responsif tanpa menunggu chart.js selesai di-load dari
           CDN. Konsekuensinya, pemakaian Chart di sini WAJIB ditunda sampai
           DOMContentLoaded supaya window.Chart sudah pasti ada. */
        document.addEventListener('DOMContentLoaded', function () {
        renderTopMasalahChart('topMasalahChart', '.chart-tab-switch', {
            jenis: { labels: <?= json_encode($jenisMasalahLabel) ?>, values: <?= json_encode($jenisMasalahTotal) ?> },
            part: { labels: <?= json_encode($partLabel) ?>, values: <?= json_encode($partTotal) ?> },
            mesin: { labels: <?= json_encode($mesinLabel) ?>, values: <?= json_encode($mesinTotal) ?> }
        });
        });

    </script>

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
