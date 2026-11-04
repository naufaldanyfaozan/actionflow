<?php
require_once __DIR__ . '/../config/auth_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';
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

// Staff QA cuma menangani FPM Internal QA & FPM NG Customer (flow_type = 'QA'),
// bukan FPM Down Time / Asakai / stock milik staff dept lain - jadi dashboard-nya
// discope ke situ saja. FPM QA juga tidak pernah mengisi tgl_terbit (kolom itu
// punya alur FPM Down Time), makanya filter periodenya pakai tgl_pembuatan.
$isQA = (($_SESSION['dept'] ?? '') === 'QA');

if ($isQA) {
  $whereFPM = "WHERE flow_type = 'QA' AND DATE_FORMAT(tgl_pembuatan, '%Y-%m') = '$filterBulan'";
} else {
  $whereFPM = "WHERE DATE_FORMAT(tgl_terbit, '%Y-%m') = '$filterBulan'";
}
$whereAsakai = "WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$filterBulan'";

if (!empty($filterPlant)) {
  $whereFPM .= " AND plant = '$filterPlant'";
  $whereAsakai .= " AND plant = '$filterPlant'";
}

// Countermeasure Over Due Date ditampilkan TANPA filter bulan: selama belum CLOSED
// tetap muncul supaya mudah dimonitor. Filter plant tetap berlaku.
$whereFPMOverdue = $isQA ? "WHERE flow_type = 'QA'" : "WHERE 1=1";
$whereAsakaiOverdue = "WHERE 1=1";
if (!empty($filterPlant)) {
  $whereFPMOverdue .= " AND plant = '$filterPlant'";
  $whereAsakaiOverdue .= " AND plant = '$filterPlant'";
}

include '../config/dashboard_severity_helper.php';

if ($isQA) {
  // Efisiensi mesin dari Asakai tidak relevan untuk Staff QA, jadi cuma hitung
  // problem berulang dari FPM QA-nya sendiri (sama seperti dashboard Dept Head QA).
  $severityKpi = ['efisiensi_rata' => 0, 'problem_berulang' => hitungProblemBerulangFpm($koneksi, $whereFPM)];
} else {
  $severityKpi = hitungSeverityKpi($koneksi, $whereAsakai, $whereFPM);
}
$topBerulang = topProblemBerulang($koneksi, $whereFPM, 10);

// ================= FPM PER BULAN =================
// FPM QA tidak pernah mengisi tgl_terbit (kolom itu punya alur FPM Down Time),
// jadi bulannya diambil dari tgl_pembuatan supaya tidak selalu NULL.
$kolomBulanFPM = $isQA ? 'tgl_pembuatan' : 'tgl_terbit';
$queryFPM = mysqli_query($koneksi, "SELECT DATE_FORMAT($kolomBulanFPM, '%Y-%m') as bulan,
jenis_fpm, COUNT(*) as total FROM fpm_header $whereFPM GROUP BY bulan, jenis_fpm ORDER BY bulan ASC");

$fpmData = [];
while ($row = mysqli_fetch_assoc($queryFPM)) {
  $fpmData[$row['bulan']][$row['jenis_fpm']] = $row['total'];
}

// ================= INTERNAL DEPT =================
$queryDept = mysqli_query($koneksi, "SELECT 
h.internal_dept, 
COUNT(h.id_fpm) AS total,

-- SUDAH DIJAWAB
SUM(
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM fpm_pengiriman p 
            WHERE p.id_fpm = h.id_fpm 
            AND p.tgl IS NOT NULL
        )
        THEN 1 ELSE 0
    END
) AS sudah_dijawab,

-- OVERDUE (>7 HARI KERJA)
SUM(
    CASE 
        WHEN 
        NOT EXISTS (
            SELECT 1 FROM fpm_pengiriman p 
            WHERE p.id_fpm = h.id_fpm 
            AND p.tgl IS NOT NULL
        )
        AND
        (
            (
                DATEDIFF(CURDATE(), h.$kolomBulanFPM)
                - (WEEKDAY(CURDATE()) - WEEKDAY(h.$kolomBulanFPM))
                - ( (DATEDIFF(CURDATE(), h.$kolomBulanFPM) + WEEKDAY(h.$kolomBulanFPM)) DIV 7 ) * 2
            ) > 7
        )
        THEN 1 ELSE 0
    END
) AS overdue,

-- BELUM DIJAWAB (<=7 HARI KERJA)
SUM(
    CASE 
        WHEN 
        NOT EXISTS (
            SELECT 1 FROM fpm_pengiriman p 
            WHERE p.id_fpm = h.id_fpm 
            AND p.tgl IS NOT NULL
        )
        AND
        (
            (
                DATEDIFF(CURDATE(), h.$kolomBulanFPM)
                - (WEEKDAY(CURDATE()) - WEEKDAY(h.$kolomBulanFPM))
                - ( (DATEDIFF(CURDATE(), h.$kolomBulanFPM) + WEEKDAY(h.$kolomBulanFPM)) DIV 7 ) * 2
            ) <= 7
        )
        THEN 1 ELSE 0
    END
) AS belum_dijawab

FROM fpm_header h
$whereFPM
GROUP BY h.internal_dept
ORDER BY h.internal_dept ASC
");

$deptLabel = [];
$deptTotal = [];
$deptSudah = [];
$deptBelum = [];
$deptOverdue = [];

while ($row = mysqli_fetch_assoc($queryDept)) {
  $deptLabel[] = $row['internal_dept'];
  $deptTotal[] = $row['total'];
  $deptSudah[] = $row['sudah_dijawab'];
  $deptBelum[] = $row['belum_dijawab'];
  $deptOverdue[] = $row['overdue'];
}

// ================= ASAKAI PER MACHINE =================
// Asakai bukan bagian dari FPM QA (Internal/Customer), jadi dilewati untuk Staff QA.
$machineLabel = [];
$machineTotal = [];
$machineLinks = [];

if (!$isQA) {
  $queryAsakaiMachine = mysqli_query($koneksi, "SELECT machine, COUNT(*) as total FROM asakai_header
$whereAsakai GROUP BY machine ORDER BY total DESC");

  while ($row = mysqli_fetch_assoc($queryAsakaiMachine)) {
    $machineLabel[] = $row['machine'];
    $machineTotal[] = $row['total'];
    $machineLinks[] = 'list_asakai.php?' . http_build_query([
      'machine' => $row['machine'],
      'plant' => $filterPlant,
      'tgl_awal' => date('Y-m-d', strtotime($filterBulan . '-01')),
      'tgl_akhir' => date('Y-m-t', strtotime($filterBulan . '-01')),
    ]);
  }
}

// ================= FPM OPEN / CLOSED =================
if ($isQA) {
  // FPM QA tidak punya jenis_fpm 'DOWN TIME' - closed/open-nya dihitung dari
  // ada/tidaknya corrective + preventive + review dokumen yang masih terbuka,
  // sama seperti dashboard Dept Head QA.
  $queryFpmStatus = mysqli_query($koneksi, "SELECT
    COUNT(*) AS total,
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
  FROM fpm_header h
  $whereFPM
  ");

  $fpmStatus = mysqli_fetch_assoc($queryFpmStatus);
  $totalClosed = (int) ($fpmStatus['total_closed'] ?? 0);
  $totalOpen   = (int) ($fpmStatus['total'] ?? 0) - $totalClosed;
} else {
  $queryFpmStatus = mysqli_query($koneksi, "SELECT

SUM(
    CASE
        WHEN (
            EXISTS (
                SELECT 1 FROM fpm_corrective_action ca
                WHERE ca.id_fpm = h.id_fpm
                AND ca.status != 'CLOSED'
            )
            OR
            EXISTS (
                SELECT 1 FROM fpm_preventive_action pa
                WHERE pa.id_fpm = h.id_fpm
                AND pa.status != 'CLOSED'
            )
        )
        THEN 1 ELSE 0
    END
) as total_open,

SUM(
    CASE
        WHEN (
            NOT EXISTS (
                SELECT 1 FROM fpm_corrective_action ca
                WHERE ca.id_fpm = h.id_fpm
                AND ca.status != 'CLOSED'
            )
            AND
            NOT EXISTS (
                SELECT 1 FROM fpm_preventive_action pa
                WHERE pa.id_fpm = h.id_fpm
                AND pa.status != 'CLOSED'
            )
        )
        THEN 1 ELSE 0
    END
) as total_closed

FROM fpm_header h
$whereFPM
");

  $fpmStatus = mysqli_fetch_assoc($queryFpmStatus);

  $totalOpen   = $fpmStatus['total_open'] ?? 0;
  $totalClosed = $fpmStatus['total_closed'] ?? 0;
}

// ================= FPM STATUS PER DEPT (Corrective + Preventive + Review Dokumen) =================
$queryFpmDeptStatus = mysqli_query($koneksi, "SELECT h.internal_dept,

SUM(
    CASE 
        WHEN
        (
            -- ADA DATA ACTION
            (
                (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
            ) > 0

            AND

            -- TIDAK ADA YANG SELAIN CLOSED
            NOT EXISTS (
                SELECT 1 FROM fpm_corrective_action ca 
                WHERE ca.id_fpm = h.id_fpm 
                AND ca.status != 'CLOSED'
            )
            AND
            NOT EXISTS (
                SELECT 1 FROM fpm_preventive_action pa 
                WHERE pa.id_fpm = h.id_fpm 
                AND pa.status != 'CLOSED'
            )
            AND
            NOT EXISTS (
                SELECT 1 FROM fpm_review_dokumen rd 
                WHERE rd.id_fpm = h.id_fpm 
                AND rd.status != 'CLOSED'
            )
        )
        THEN 1 ELSE 0
    END
) AS total_closed,

SUM(
    CASE 
        WHEN
        (
            -- TIDAK ADA DATA
            (
                (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
            ) = 0

            OR

            -- MASIH ADA YANG OPEN
            EXISTS (
                SELECT 1 FROM fpm_corrective_action ca 
                WHERE ca.id_fpm = h.id_fpm 
                AND ca.status != 'CLOSED'
            )
            OR
            EXISTS (
                SELECT 1 FROM fpm_preventive_action pa 
                WHERE pa.id_fpm = h.id_fpm 
                AND pa.status != 'CLOSED'
            )
            OR
            EXISTS (
                SELECT 1 FROM fpm_review_dokumen rd 
                WHERE rd.id_fpm = h.id_fpm 
                AND rd.status != 'CLOSED'
            )
        )
        THEN 1 ELSE 0
    END
) AS total_open

FROM fpm_header h
$whereFPM
GROUP BY h.internal_dept
ORDER BY h.internal_dept ASC
");

$fpmDeptStatus = [];

while ($row = mysqli_fetch_assoc($queryFpmDeptStatus)) {
  $fpmDeptStatus[] = [
    'dept' => $row['internal_dept'],
    'open' => $row['total_open'],
    'closed' => $row['total_closed']
  ];
}

// ================= ASAKAI STATUS PER DEPT =================
// Asakai bukan bagian dari FPM QA, jadi dilewati untuk Staff QA.
$asakaiDept = [];

if (!$isQA) {
  $queryAsakaiDept = mysqli_query($koneksi, "SELECT pic AS dept,
    SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) AS total_open,
    SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
FROM (

    SELECT ng.pic, ng.status, ng.due_date
    FROM asakai_countermeasure_ng ng
    JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai
    $whereAsakai

    UNION ALL

    SELECT dt.pic, dt.status, dt.due_date
    FROM asakai_countermeasure_downtime dt
    JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai
    $whereAsakai

) AS gabungan

GROUP BY pic
ORDER BY pic ASC
");

  while ($row = mysqli_fetch_assoc($queryAsakaiDept)) {
    $total = $row['total_open'] + $row['total_closed'];
    $percent = $total > 0 ? round(($row['total_closed'] / $total) * 100, 2) : 0;

    $asakaiDept[] = [
      'dept' => $row['dept'],
      'open' => $row['total_open'],
      'closed' => $row['total_closed'],
      'percent' => $percent
    ];
  }
}

// ================= FPM ACTION STATUS PER DEPT (Corrective + Preventive) =================
$queryFpmActionDept = mysqli_query($koneksi, "SELECT pic AS dept,
    SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) AS total_open,
    SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
FROM (

    SELECT ca.pic, ca.status, ca.due_date
    FROM fpm_corrective_action ca
    JOIN fpm_header h ON ca.id_fpm = h.id_fpm
    $whereFPM

    UNION ALL

    SELECT pa.pic, pa.status, pa.due_date
    FROM fpm_preventive_action pa
    JOIN fpm_header h ON pa.id_fpm = h.id_fpm
    $whereFPM

) AS gabungan

GROUP BY pic
ORDER BY pic ASC
");

$fpmActionDept = [];

while ($row = mysqli_fetch_assoc($queryFpmActionDept)) {

  $total = $row['total_open'] + $row['total_closed'];
  $percent = $total > 0
    ? round(($row['total_closed'] / $total) * 100, 2)
    : 0;

  $fpmActionDept[] = [
    'dept' => $row['dept'],
    'open' => $row['total_open'],
    'closed' => $row['total_closed'],
    'percent' => $percent
  ];
}

// ================= ANALISA JENIS MASALAH =================

// TOP JENIS MASALAH
$queryJenisMasalah = mysqli_query($koneksi, "SELECT
    jenis_masalah,
    COUNT(*) AS total
FROM fpm_header
$whereFPM
GROUP BY jenis_masalah
ORDER BY total DESC
LIMIT 10
");

$jenisMasalahLabel = [];
$jenisMasalahTotal = [];

while ($row = mysqli_fetch_assoc($queryJenisMasalah)) {
  $jenisMasalahLabel[] = $row['jenis_masalah'];
  $jenisMasalahTotal[] = $row['total'];
}


// TOP PART + JENIS MASALAH
$queryPartMasalah = mysqli_query($koneksi, "SELECT
    CONCAT(nama_part,' - ',jenis_masalah) AS label,
    COUNT(*) AS total
FROM fpm_header
$whereFPM
GROUP BY nama_part, jenis_masalah
ORDER BY total DESC
LIMIT 15
");

$partMasalahLabel = [];
$partMasalahTotal = [];

while ($row = mysqli_fetch_assoc($queryPartMasalah)) {
  $partMasalahLabel[] = $row['label'];
  $partMasalahTotal[] = $row['total'];
}


// TOP MACHINE + JENIS MASALAH
$queryMachineMasalah = mysqli_query($koneksi, "SELECT
    CONCAT(machine_no,' - ',jenis_masalah) AS label,
    COUNT(*) AS total
FROM fpm_header
$whereFPM
GROUP BY machine_no, jenis_masalah
ORDER BY total DESC
LIMIT 15
");

$machineMasalahLabel = [];
$machineMasalahTotal = [];

while ($row = mysqli_fetch_assoc($queryMachineMasalah)) {
  $machineMasalahLabel[] = $row['label'];
  $machineMasalahTotal[] = $row['total'];
}

// ================= FPM OVERDUE COUNTERMEASURE =================
$queryFpmOverdue = mysqli_query($koneksi, "SELECT pic, tindakan AS deskripsi, due_date, status, id_fpm, machine_no, nama_part
FROM (
    SELECT ca.pic, ca.tindakan, ca.due_date, ca.status, ca.actual_date, h.id_fpm, h.machine_no, h.nama_part
    FROM fpm_corrective_action ca
    JOIN fpm_header h ON ca.id_fpm = h.id_fpm
    $whereFPMOverdue

    UNION ALL

    SELECT pa.pic, pa.tindakan, pa.due_date, pa.status, pa.actual_date, h.id_fpm, h.machine_no, h.nama_part
    FROM fpm_preventive_action pa
    JOIN fpm_header h ON pa.id_fpm = h.id_fpm
    $whereFPMOverdue

    UNION ALL

    SELECT rd.pic, rd.dokumen AS tindakan, rd.due_date, rd.status, rd.actual_date, h.id_fpm, h.machine_no, h.nama_part
    FROM fpm_review_dokumen rd
    JOIN fpm_header h ON rd.id_fpm = h.id_fpm
    $whereFPMOverdue

) AS gabungan
-- Tetap tampil selama belum CLOSED, walau bukti sudah diupload (actual_date terisi)
WHERE (status IS NULL OR status != 'CLOSED')
AND due_date < CURDATE()
ORDER BY due_date ASC
");

$fpmOverdue = [];
while ($row = mysqli_fetch_assoc($queryFpmOverdue)) {
  $fpmOverdue[] = $row;
}


// ================= ASAKAI OVERDUE COUNTERMEASURE =================
// Asakai bukan bagian dari FPM QA, jadi dilewati untuk Staff QA.
$asakaiOverdue = [];

if (!$isQA) {
  $queryAsakaiOverdue = mysqli_query($koneksi, "SELECT pic, deskripsi, due_date, status, id_asakai, machine, namapart
FROM (
    SELECT ng.pic, ng.deskripsi, ng.due_date, ng.status, ng.actual_date, ah.id_asakai, ah.machine, ah.namapart
    FROM asakai_countermeasure_ng ng
    JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai
    $whereAsakaiOverdue

    UNION ALL

    SELECT dt.pic, dt.deskripsi, dt.due_date, dt.status, dt.actual_date, ah.id_asakai, ah.machine, ah.namapart
    FROM asakai_countermeasure_downtime dt
    JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai
    $whereAsakaiOverdue

) AS gabungan
-- Tetap tampil selama belum CLOSED, walau bukti sudah diupload (actual_date terisi)
WHERE (status IS NULL OR status != 'CLOSED')
AND due_date < CURDATE()
ORDER BY due_date ASC
");

  while ($row = mysqli_fetch_assoc($queryAsakaiOverdue)) {
    $asakaiOverdue[] = $row;
  }
}

// ================= EXPORT EXCEL TABEL OVERDUE =================
if (!empty($_GET['export_overdue'])) {
  require_once __DIR__ . '/../config/overdue_excel_export.php';
  exportOverdueCountermeasureExcel($_GET['export_overdue'], $fpmOverdue, $asakaiOverdue, (string) $filterPlant);
}

// Delivery Monitor & Stock Casting adalah data staff Improvement, bukan FPM QA.
$tanggalDelivery = inputTanggal($_GET['tanggal_delivery'] ?? '', date('Y-m-d'));
$query_delivery = null;

if (!$isQA) {
  $query_delivery = mysqli_query($koneksi, "SELECT
        fdm.id_monitor,
        fdm.nama_part,
        fdm.act_stock,
        fdm.delivery_per_hari,
        fdm.sisa_stock,
        fdm.news_delivery,
        fdmc.id_countermeasure,
        fdmc.countermeasure,
        fdmc.due_date,
        fdmc.pic
    FROM fpm_delivery_monitor fdm
    LEFT JOIN fpm_delivery_monitor_countermeasure fdmc
        ON fdm.id_monitor = fdmc.id_monitor
    WHERE DATE(fdm.tanggal) = '$tanggalDelivery'
    ORDER BY fdm.id_monitor ASC
");
}
?>

<!doctype html>
<html lang="en">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>Dashboard</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="title" content="AdminLTE v4 | Dashboard" />
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
          <?= htmlspecialchars(brandNama()) ?> Dashboard
          <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
            <?= $isQA ? 'FPM QA (Internal)' : 'Corrective Action, Monitoring &amp; Improvement System' ?>
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
                <option value="1" <?= ($filterPlant == '1') ? 'selected' : '' ?>>
                  Plant 1
                </option>
                <option value="3" <?= ($filterPlant == '3') ? 'selected' : '' ?>>
                  Plant 3
                </option>
              </select>
            </div>

            <div class="col-auto">
              <button class="btn btn-primary btn-sm">Filter</button>
              <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
              <button type="button" class="btn-export" onclick="exportDashboardExcel()">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
              </button>
              <button type="button" class="btn-report" onclick="openReport()">
                <i class="bi bi-file-earmark-text"></i> Laporan
              </button>
            </div>
          </form>
        </div>

        <?php
        $totalFPM = array_sum(array_map('array_sum', $fpmData));
        $totalAsakai = array_sum($machineTotal);
        $totalFpmOverdue = count($fpmOverdue);
        $totalAsakaiOverdue = count($asakaiOverdue);
        ?>

        <div class="dash-tabs">
          <button type="button" class="dash-tab-btn active" data-tab-target="#tab-ringkasan">📊 Ringkasan Sasaran Mutu</button>
          <?php if (!$isQA): ?>
            <button type="button" class="dash-tab-btn" data-tab-target="#tab-delivery">🚚 Delivery &amp; Stock</button>
          <?php endif; ?>
        </div>

        <div id="tab-ringkasan" class="dash-tab-panel active">

        <?php
        require_once __DIR__ . '/../config/claim_machining_dashboard_widget.php';
        require_once __DIR__ . '/../config/sasaran_mutu_tambahan_widget.php';
        ?>

        <h3 style="text-align: left; font-size: 16px; font-weight: bold; border-left: 5px solid #f5c2c7; padding-left: 10px; color:#b02a37;">
          <i class="bi bi-exclamation-octagon-fill"></i> Perlu Perhatian Segera
        </h3>

        <div class="row g-3">

          <div class="<?= $isQA ? 'col-md-6' : 'col-md-4' ?>">
            <div class="kpi-card kpi-danger">
              <div class="kpi-icon"><i class="bi bi-alarm-fill"></i></div>
              <div>
                <div class="kpi-title">Countermeasure FPM Overdue</div>
                <div class="kpi-value"><?= $totalFpmOverdue ?></div>
              </div>
            </div>
          </div>

          <?php if (!$isQA): ?>
            <div class="col-md-4">
              <div class="kpi-card kpi-danger">
                <div class="kpi-icon"><i class="bi bi-alarm-fill"></i></div>
                <div>
                  <div class="kpi-title">Countermeasure Asakai Overdue</div>
                  <div class="kpi-value"><?= $totalAsakaiOverdue ?></div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="<?= $isQA ? 'col-md-6' : 'col-md-4' ?>">
            <div class="kpi-card kpi-warning">
              <div class="kpi-icon"><i class="bi bi-arrow-repeat"></i></div>
              <div>
                <div class="kpi-title">Problem Berulang</div>
                <div class="kpi-value"><?= $severityKpi['problem_berulang'] ?></div>
              </div>
            </div>
          </div>

        </div>

        <div class="row section g-3">

          <div class="<?= $isQA ? 'col-md-4' : 'col-md-3' ?>">
            <div class="kpi-card kpi-primary">
              <div class="kpi-icon"><i class="bi bi-tools"></i></div>
              <div>
                <div class="kpi-title"><?= $isQA ? 'Total FPM QA' : 'Total FPM Down Time' ?></div>
                <div class="kpi-value"><?= $totalFPM ?></div>
              </div>
            </div>
          </div>

          <div class="<?= $isQA ? 'col-md-4' : 'col-md-3' ?>">
            <div class="kpi-card kpi-primary">
              <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
              <div>
                <div class="kpi-title">Total FPM Closed</div>
                <div class="kpi-value"><?= $totalClosed ?></div>
              </div>
            </div>
          </div>

          <div class="<?= $isQA ? 'col-md-4' : 'col-md-3' ?>">
            <div class="kpi-card kpi-warning">
              <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
              <div>
                <div class="kpi-title">Total FPM Open</div>
                <div class="kpi-value"><?= $totalOpen ?></div>
              </div>
            </div>
          </div>

          <?php if (!$isQA): ?>
            <div class="col-md-3">
              <div class="kpi-card kpi-success">
                <div class="kpi-icon"><i class="bi bi-clipboard-data"></i></div>
                <div>
                  <div class="kpi-title">Total Asakai</div>
                  <div class="kpi-value"><?= $totalAsakai ?></div>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>

        <?php if (!$isQA): ?>
        <div class="row section g-3">

          <div class="col-md-12">
            <div class="kpi-card kpi-success">
              <div class="kpi-icon"><i class="bi bi-speedometer2"></i></div>
              <div>
                <div class="kpi-title">Efisiensi Rata-rata</div>
                <div class="kpi-value"><?= $severityKpi['efisiensi_rata'] ?>%</div>
              </div>
            </div>
          </div>

        </div>
        <?php endif; ?>

        <!-- Tabel/data yang berwarna merah (overdue & problem berulang) sengaja
             ditaruh di sini, tepat setelah KPI umum, supaya tanpa scroll langsung
             kelihatan masalah apa yang harus segera dibereskan. -->
        <div class="row section g-3">

          <!-- ================= FPM OVERDUE ================= -->
          <div class="<?= $isQA ? 'col-md-12' : 'col-md-6' ?>">
            <div class="card h-100">
              <div class="card-header bg-danger text-white position-relative">
                Countermeasure FPM Over Due Date
                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export_overdue' => 'fpm']))) ?>" class="btn btn-sm btn-light position-absolute top-50 end-0 translate-middle-y me-2 py-0 px-2" title="Export tabel ini ke Excel">
                  <i class="bi bi-file-earmark-excel text-success"></i> Excel
                </a>
              </div>
              <div class="card-body table-responsive">
                <?php if (!empty($fpmOverdue)): ?>
                  <div class="list-pagination-toolbar">
                    Tampilkan
                    <select class="list-pagination-select" data-paginate-size-for="fpmOverdueTable">
                      <option value="10">10</option>
                      <option value="50">50</option>
                      <option value="100">100</option>
                      <option value="200">200</option>
                    </select>
                    baris
                  </div>
                <?php endif; ?>
                <table id="fpmOverdueTable" class="table table-bordered table-sm text-center align-middle" <?= !empty($fpmOverdue) ? 'data-paginate="10"' : '' ?>>
                  <thead>
                    <tr>
                      <th>PIC</th>
                      <th>Mesin</th>
                      <th>Part</th>
                      <th>Tindakan / Dokumen</th>
                      <th>Due Date</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($fpmOverdue)) : ?>
                      <tr>
                        <td colspan="6">Tidak ada countermeasure over due date</td>
                      </tr>
                    <?php else : ?>
                      <?php foreach ($fpmOverdue as $i => $row):
                        $overdueLink = $isQA ? 'jawab_fpm_qa.php?id=' . $row['id_fpm'] : 'edit_fpm_downtime.php?id=' . $row['id_fpm'];
                      ?>
                        <tr style="cursor:pointer;" onclick="window.location='<?= $overdueLink ?>'" title="Klik untuk update countermeasure FPM ini">
                          <td><?= $row['pic'] ?></td>
                          <td><?= $row['machine_no'] ?></td>
                          <td><?= $row['nama_part'] ?></td>
                          <td><?= $row['deskripsi'] ?></td>
                          <td class="text-danger fw-bold"><?= $row['due_date'] ?></td>
                          <td><span class="badge bg-danger"><?= $row['status'] ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
                <?php if (!empty($fpmOverdue)): ?>
                  <div class="list-pagination-nav">
                    <button type="button" class="list-pagination-btn" data-paginate-prev="fpmOverdueTable">&laquo; Sebelumnya</button>
                    <span class="list-pagination-info" data-paginate-info="fpmOverdueTable"></span>
                    <button type="button" class="list-pagination-btn" data-paginate-next="fpmOverdueTable">Berikutnya &raquo;</button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!$isQA): ?>
            <!-- ================= ASAKAI OVERDUE ================= -->
            <div class="col-md-6">
              <div class="card h-100">
                <div class="card-header bg-danger text-white position-relative">
                  Countermeasure Asakai Over Due Date
                  <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export_overdue' => 'asakai']))) ?>" class="btn btn-sm btn-light position-absolute top-50 end-0 translate-middle-y me-2 py-0 px-2" title="Export tabel ini ke Excel">
                    <i class="bi bi-file-earmark-excel text-success"></i> Excel
                  </a>
                </div>
                <div class="card-body table-responsive">
                  <?php if (!empty($asakaiOverdue)): ?>
                  <div class="list-pagination-toolbar">
                    Tampilkan
                    <select class="list-pagination-select" data-paginate-size-for="asakaiOverdueTable">
                      <option value="10">10</option>
                      <option value="50">50</option>
                      <option value="100">100</option>
                      <option value="200">200</option>
                    </select>
                    baris
                  </div>
                <?php endif; ?>
                <table id="asakaiOverdueTable" class="table table-bordered table-sm text-center align-middle" <?= !empty($asakaiOverdue) ? 'data-paginate="10"' : '' ?>>
                    <thead>
                      <tr>
                        <th>PIC</th>
                        <th>Mesin</th>
                        <th>Part</th>
                        <th>Tindakan</th>
                        <th>Due Date</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($asakaiOverdue)) : ?>
                        <tr>
                          <td colspan="6">Tidak ada countermeasure over due date</td>
                        </tr>
                      <?php else : ?>
                        <?php foreach ($asakaiOverdue as $i => $row): ?>
                          <tr style="cursor:pointer;" onclick="window.location='update_asakai.php?id=<?= $row['id_asakai'] ?>'" title="Klik untuk update countermeasure Asakai ini">
                            <td><?= $row['pic'] ?></td>
                            <td><?= $row['machine'] ?></td>
                            <td><?= $row['namapart'] ?></td>
                            <td><?= $row['deskripsi'] ?></td>
                            <td class="text-danger fw-bold"><?= $row['due_date'] ?></td>
                            <td><span class="badge bg-danger"><?= $row['status'] ?></span></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                  <?php if (!empty($asakaiOverdue)): ?>
                  <div class="list-pagination-nav">
                    <button type="button" class="list-pagination-btn" data-paginate-prev="asakaiOverdueTable">&laquo; Sebelumnya</button>
                    <span class="list-pagination-info" data-paginate-info="asakaiOverdueTable"></span>
                    <button type="button" class="list-pagination-btn" data-paginate-next="asakaiOverdueTable">Berikutnya &raquo;</button>
                  </div>
                <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>

        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-danger text-white">
                Top Problem Berulang (Part / Mesin dengan Kejadian Berulang)
              </div>
              <div class="card-body table-responsive">
                <?php if ($topBerulang): ?>
                  <table id="topBerulangTable" class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Tanggal Ditemukan</th>
                        <th>Part</th>
                        <th>Mesin</th>
                        <th>Jenis Masalah</th>
                        <th>Departemen</th>
                        <th class="text-center">Kejadian Ke-</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($topBerulang as $i => $b): ?>
                        <tr class="<?= $i >= 5 ? 'table-extra-row' : '' ?>">
                          <td><?= htmlspecialchars($b['tgl_ditemukan'] ?? '-') ?></td>
                          <td><?= htmlspecialchars($b['nama_part'] ?? '-') ?></td>
                          <td><?= htmlspecialchars($b['machine_no'] ?? '-') ?></td>
                          <td><?= htmlspecialchars($b['jenis_masalah'] ?? '-') ?></td>
                          <td><?= htmlspecialchars($b['internal_dept'] ?? '-') ?></td>
                          <td class="text-center"><span class="badge bg-danger"><?= (int)$b['kejadian_berulang_ke'] ?>x</span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <?php if (count($topBerulang) > 5): ?>
                    <button type="button" class="table-expand-toggle" data-expand-toggle="topBerulangTable"
                      data-label-expand="Lihat <?= count($topBerulang) - 5 ?> lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($topBerulang) - 5 ?> lainnya &#9662;</button>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="text-center text-muted py-3">Tidak ada problem berulang tercatat pada periode ini.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>

        <?php if ($isQA): ?>
          <?php
          // FPM QA (Internal, sub jenis 'NG Machining') di atas belum ada datanya sama
          // sekali (fitur ini memang sedang dinonaktifkan dulu - lihat sidebar_qa.php),
          // sedangkan FPM Claim Machining sudah aktif berjalan. Section di bawah ini
          // TAMBAHAN, tidak menghapus/mengubah metrik FPM QA Internal yang sudah ada,
          // cuma dilengkapi supaya Staff QA tetap lihat data yang benar-benar ada.
          // Ditaruh SETELAH "Perlu Perhatian Segera" karena itu prioritas utama.
          renderPerhatianClaimMachining($koneksi);
          renderSasaranMutuClaimMachining($koneksi, $filterBulan, $namaPeriode);
          ?>
        <?php endif; ?>

        <div class="row section g-3">

          <div class="<?= $isQA ? 'col-md-12' : 'col-md-6' ?>">
            <div class="card h-100">
              <div class="card-header bg-primary text-white">
                Total Jenis FPM per Bulan
              </div>
              <div class="card-body">
                <div class="chart-large">
                  <canvas id="fpmChart"></canvas>
                </div>
              </div>
            </div>
          </div>

          <?php if (!$isQA): ?>
            <div class="col-md-6">
              <div class="card h-100">
                <div class="card-header bg-primary text-white">
                  Distribusi FPM per Departemen
                </div>
                <div class="card-body">
                  <div class="chart-large">
                    <canvas id="deptDistribusiChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>

        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-primary text-white">
                % Countermeasure Closed per Departemen<?= $isQA ? '' : ' (FPM vs Asakai)' ?>
              </div>
              <div class="card-body">
                <div class="chart-large">
                  <canvas id="deptCombinedChart"></canvas>
                </div>
              </div>
            </div>
          </div>

        </div>

        <?php
        // Widget FPM Claim Machining sementara dimatikan di role selain Dept Head QA -
        // migration kolom cm_* untuk fitur ini belum dijalankan di database hosting.
        // require_once __DIR__ . '/../config/claim_machining_dashboard_widget.php';
        // renderClaimMachiningDashboardWidget($koneksi, $_SESSION['username'] ?? null);
        ?>

        <?php if (!$isQA): ?>
        <div class="row section g-3">

            <div class="col-md-12">
              <div class="card h-100">
                <div class="card-header bg-success text-white">
                  Total Asakai per Machine
                </div>
                <div class="card-body">
                  <?php if (empty($machineLabel)): ?>
                    <div class="text-center text-muted py-3">Tidak ada data pada periode ini</div>
                  <?php else: ?>
                    <div class="chart-large" style="height:<?= max(260, count($machineLabel) * 28) ?>px;">
                      <canvas id="machineChart"></canvas>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

        </div>
        <?php endif; // !$isQA (Total Asakai per Machine) ?>

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
                <?php if (empty($jenisMasalahLabel) && empty($partMasalahLabel) && empty($machineMasalahLabel)): ?>
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

        </div><!-- /#tab-ringkasan -->

        <?php if (!$isQA): ?>
        <div id="tab-delivery" class="dash-tab-panel">
        <div style="margin-top: 0;">
          <h3 style="text-align: left; font-size: 18px; font-weight: bold; border-left: 5px solid #fce4d6; padding-left: 10px;">
            Delivery & Sisa Stock Part
          </h3>

          <div class="card mb-3">
            <div class="card-body py-2">
              <form method="GET" class="row g-2 align-items-end">

                <!-- mempertahankan filter dashboard -->
                <input type="hidden" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>">
                <input type="hidden" name="plant" value="<?= htmlspecialchars($filterPlant) ?>">

                <div class="col-md-3">
                  <label class="form-label fw-semibold">Tanggal Delivery</label>
                  <input
                    type="date"
                    name="tanggal_delivery"
                    class="form-control form-control-sm"
                    value="<?= $tanggalDelivery ?>">
                </div>

                <div class="col-auto">
                  <button type="submit" class="btn btn-primary btn-sm">
                    Filter
                  </button>
                </div>

              </form>
            </div>
          </div>

          <div class="card delivery-card">

            <div class="table-responsive">

              <table class="delivery-table">

                <thead>
                  <tr class="thead-orange">
                    <th style="width: 50px;">No.</th>
                    <th>Nama Part</th>
                    <th>Act Stock</th>
                    <th>Delivery / hari</th>
                    <th>Kemampuan Delivery</th>
                    <th>News Delivery</th>
                    <th>Countermeasure</th>
                    <th>Due Date</th>
                    <th>PIC</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  if (mysqli_num_rows($query_delivery) > 0):
                    $no = 1;
                    while ($row_del = mysqli_fetch_assoc($query_delivery)):
                      $sisaStockClass = ($row_del['sisa_stock'] < 0) ? 'class="text-danger-custom"' : '';

                      $due_date_display = '';
                      if (!empty($row_del['due_date']) && $row_del['due_date'] != '0000-00-00') {
                        $due_date_display = date('d-m-Y', strtotime($row_del['due_date']));
                      }
                  ?>
                      <?php
                      $stockClass = '';

                      if ($row_del['act_stock'] <= $row_del['delivery_per_hari']) {
                        $stockClass = 'stock-danger';
                      }

                      ?>

                      <tr>

                        <td><?= $no++ ?>.</td>

                        <td><?= htmlspecialchars($row_del['nama_part']) ?></td>

                        <td class="<?= $stockClass ?>">
                          <?= number_format($row_del['act_stock']) ?>
                        </td>

                        <td>
                          <?= number_format($row_del['delivery_per_hari']) ?>
                        </td>

                        <td class="sisa-stock">
                          <?= number_format($row_del['sisa_stock'], 2) ?>
                        </td>

                        <td><?= htmlspecialchars($row_del['news_delivery']) ?></td>

                        <td><?= htmlspecialchars($row_del['countermeasure']) ?></td>

                        <td><?= $due_date_display ?></td>

                        <td><?= htmlspecialchars($row_del['pic']) ?></td>

                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="9" style="text-align:center; padding: 20px;">Data stock part tidak ditemukan untuk tanggal/plant terpilih.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <?php
        $tanggalCasting = inputTanggal($_GET['tanggal_casting'] ?? '', date('Y-m-d'));
        $query_casting = mysqli_query($koneksi, "SELECT
                id_casting,
                part_number,
                nama_part,
                dcm,
                min_stock,
                stock_ppc
            FROM stock_casting_monitor
            WHERE tanggal = '$tanggalCasting'
            ORDER BY id_casting ASC
        ");
        ?>

        <div style="margin-top: 50px;">
          <h3 style="text-align: left; font-size: 18px; font-weight: bold; border-left: 5px solid #fce4d6; padding-left: 10px;">
            Stock Casting - Machining
          </h3>

          <div class="card mb-3">
            <div class="card-body py-2">
              <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>">
                <input type="hidden" name="plant" value="<?= htmlspecialchars($filterPlant) ?>">
                <input type="hidden" name="tanggal_delivery" value="<?= htmlspecialchars($tanggalDelivery) ?>">
                <div class="col-md-3">
                  <label class="form-label fw-semibold">Tanggal Stock Casting</label>
                  <input type="date" name="tanggal_casting" class="form-control form-control-sm"
                         value="<?= $tanggalCasting ?>">
                </div>
                <div class="col-auto">
                  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                </div>
              </form>
            </div>
          </div>

          <div class="card casting-card">
            <div class="table-responsive">
              <table class="casting-table">
                <thead>
                  <tr class="thead-orange">
                    <th style="width: 50px;">No.</th>
                    <th>Part ( Stock Casting )</th>
                    <th>DCM</th>
                    <th>Min Stock (3x Deliv)</th>
                    <th>Stock PPC</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  if (mysqli_num_rows($query_casting) > 0):
                    $noCst = 1;
                    while ($row_cst = mysqli_fetch_assoc($query_casting)):
                      $ppc = (int)$row_cst['stock_ppc'];
                      $ppcDisplay = $ppc < 0 ? '(' . number_format(abs($ppc)) . ')' : number_format($ppc);
                  ?>
                      <tr>
                        <td><?= $noCst++ ?>.</td>
                        <td><?= htmlspecialchars($row_cst['nama_part']) ?></td>
                        <td><?= number_format($row_cst['dcm']) ?></td>
                        <td><?= number_format($row_cst['min_stock']) ?></td>
                        <td class="stock-ppc-col<?= $ppc < 0 ? ' negative' : '' ?>"><?= $ppcDisplay ?></td>
                      </tr>
                  <?php endwhile; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" style="text-align:center; padding: 20px;">Data stock casting tidak ditemukan untuk tanggal terpilih.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
        </div><!-- /#tab-delivery -->
        <?php endif; // !$isQA (Delivery & Sisa Stock Part + Stock Casting - Machining) ?>
      </div>

    </main>

    <?php include '../footer.php'; ?>

  </div>

  <script>
    /* ================= TOTAL JENIS FPM PER BULAN =================
       chart.js dimuat dengan atribut "defer" (lihat <head>) supaya tidak
       memblokir parsing HTML - sidebar jadi bisa langsung dipakai (termasuk
       expand submenu) tanpa menunggu chart.js selesai di-load dari CDN.
       Konsekuensinya, pemakaian Chart di sini WAJIB ditunda sampai
       DOMContentLoaded supaya window.Chart sudah pasti ada. */
    document.addEventListener('DOMContentLoaded', function () {
    const fpmRaw = <?= json_encode($fpmData) ?>;
    const labels = Object.keys(fpmRaw);

    new Chart(document.getElementById('fpmChart'), {
      type: 'bar',
      data: {
        labels: labels,
        <?php if ($isQA): ?>
        datasets: [{
            label: 'INTERNAL (NG Machining)',
            data: labels.map(b => fpmRaw[b]['NG Machining'] ?? 0),
            backgroundColor: '#f98909',
            borderRadius: 6
          }
          <?php /* FPM Customer disembunyikan dulu (mungkin dibutuhkan lagi nanti) - dataset
                    CUSTOMER sengaja tidak ditampilkan di sini, bukan dihapus permanen. */ ?>
        ]
        <?php else: ?>
        datasets: [{
            label: 'DOWN TIME',
            data: labels.map(b => fpmRaw[b]['Down Time'] ?? 0),
            backgroundColor: '#f98909',
            borderRadius: 6
          },
          {
            // FPM-N (NG Internal IMP) disimpan dengan jenis_fpm 'NG Casting' / 'NG Machining'
            label: 'INTERNAL',
            data: labels.map(b => Number(fpmRaw[b]['NG Casting'] ?? 0) + Number(fpmRaw[b]['NG Machining'] ?? 0)),
            backgroundColor: '#0d6efd',
            borderRadius: 6
          }
        ]
        <?php endif; ?>
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          },
          x: {
            grid: {
              display: false
            }
          }
        },
        plugins: {
          datalabels: {
            display: true,
            anchor: 'end',
            align: 'top',
            offset: 4,
            color: '#1e293b',
            font: { weight: 'bold', size: 13 }
          }
        }
      }
    });

    /* ================= CHART GABUNGAN (menggantikan tabel-tabel lama) ================= */
    renderDeptDistribusiChart(
      'deptDistribusiChart',
      <?= json_encode($deptLabel) ?>,
      <?= json_encode(array_map('intval', $deptSudah)) ?>,
      <?= json_encode(array_map('intval', $deptBelum)) ?>,
      <?= json_encode(array_map('intval', $deptOverdue)) ?>
    );

    renderDeptCombinedChart(
      'deptCombinedChart',
      <?= json_encode($fpmActionDept) ?>,
      <?= json_encode($asakaiDept) ?>
    );

    renderMachineChart(
      'machineChart',
      <?= json_encode($machineLabel) ?>,
      <?= json_encode(array_map('intval', $machineTotal)) ?>,
      <?= json_encode($machineLinks) ?>
    );

    renderTopMasalahChart('topMasalahChart', '.chart-tab-switch', {
      jenis: { labels: <?= json_encode($jenisMasalahLabel) ?>, values: <?= json_encode(array_map('intval', $jenisMasalahTotal)) ?> },
      part: { labels: <?= json_encode($partMasalahLabel) ?>, values: <?= json_encode(array_map('intval', $partMasalahTotal)) ?> },
      mesin: { labels: <?= json_encode($machineMasalahLabel) ?>, values: <?= json_encode(array_map('intval', $machineMasalahTotal)) ?> }
    });
    });


  </script>

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

    function exportDashboardExcel() {
      const form = document.querySelector('.filter-box form');
      const bulan = form.querySelector('[name="bulan"]').value;
      const plant = form.querySelector('[name="plant"]').value;
      const params = new URLSearchParams();
      if (bulan) params.set('bulan', bulan);
      if (plant) params.set('plant', plant);
      window.location.href = 'export_dashboard.php?' + params.toString();
    }

    function openReport() {
      const form = document.querySelector('.filter-box form');
      const bulan = form.querySelector('[name="bulan"]').value;
      const plant = form.querySelector('[name="plant"]').value;
      const params = new URLSearchParams();
      if (bulan) params.set('bulan', bulan);
      if (plant) params.set('plant', plant);
      window.location.href = 'report.php?' + params.toString();
    }
  </script>

</body>

</html>