<?php
require_once __DIR__ . '/../config/branding_helper.php';
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';

if (!isset($koneksi)) {
  die("Variabel koneksi tidak ada");
}

$filterBulan = !empty($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');

$userPlant = $_SESSION['plant'] ?? '';
$userPlant = trim(str_ireplace('Plant', '', $userPlant));

$filterPlant = $_GET['plant'] ?? $userPlant;

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

$whereFPM     = "WHERE DATE_FORMAT(tgl_terbit, '%Y-%m') = '$filterBulan'";
$whereAsakai  = "WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$filterBulan'";

if (!empty($filterPlant)) {
  $whereFPM .= " AND plant = '$filterPlant'";
  $whereAsakai .= " AND plant = '$filterPlant'";
}

// Countermeasure Over Due Date ditampilkan TANPA filter bulan: selama belum CLOSED
// tetap muncul supaya mudah dimonitor. Filter plant tetap berlaku.
$whereFPMOverdue = "WHERE 1=1";
$whereAsakaiOverdue = "WHERE 1=1";
if (!empty($filterPlant)) {
  $whereFPMOverdue .= " AND plant = '$filterPlant'";
  $whereAsakaiOverdue .= " AND plant = '$filterPlant'";
}

include '../config/dashboard_severity_helper.php';
include '../config/dashboard_ringkasan_helper.php';

// ================= RINGKASAN SEKARANG VS BULAN LALU =================
$filterBulanPrev = date('Y-m', strtotime($filterBulan . '-01 -1 month'));
$whereFPMPrev    = "WHERE DATE_FORMAT(tgl_terbit, '%Y-%m') = '$filterBulanPrev'";
$whereAsakaiPrev = "WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$filterBulanPrev'";
if (!empty($filterPlant)) {
  $whereFPMPrev .= " AND plant = '$filterPlant'";
  $whereAsakaiPrev .= " AND plant = '$filterPlant'";
}

$ringkasanSekarang = hitungRingkasanBulan($koneksi, $whereFPM, $whereAsakai);
$ringkasanLalu      = hitungRingkasanBulan($koneksi, $whereFPMPrev, $whereAsakaiPrev);

$trenOverdue    = hitungTrenKpi($ringkasanSekarang['total_overdue'], $ringkasanLalu['total_overdue']);
$trenCompletion = hitungTrenKpi($ringkasanSekarang['completion_rate'], $ringkasanLalu['completion_rate']);
$trenBerulang   = hitungTrenKpi($ringkasanSekarang['problem_berulang'], $ringkasanLalu['problem_berulang']);
$trenEfisiensi  = hitungTrenKpi($ringkasanSekarang['efisiensi_rata'], $ringkasanLalu['efisiensi_rata']);
$trenDowntime   = hitungTrenKpi(abs($ringkasanSekarang['downtime_menit']), abs($ringkasanLalu['downtime_menit']));
$trenNgRate     = hitungTrenKpi($ringkasanSekarang['ng_rate'], $ringkasanLalu['ng_rate']);

$targetMutu = sasaranMutuTarget();

$trenBulanan = trenBulananFpm($koneksi, $filterBulan, $filterPlant, 6);

$perbandinganPlant = perbandinganAntarPlant($koneksi, $filterBulan);

$trenDeptBulanan = trenDepartemenBulanan($koneksi, $filterBulan, $filterPlant, 6);

$totalAsakaiSekarang = (int) (mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM asakai_header $whereAsakai"))['total'] ?? 0);
$totalAsakaiLalu     = (int) (mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM asakai_header $whereAsakaiPrev"))['total'] ?? 0);
$trenAsakai          = hitungTrenKpi($totalAsakaiSekarang, $totalAsakaiLalu);

// FPM sudah/belum dijawab (fpm_pengiriman.tgl terisi) - dihitung total semua dept
// jadi 1 tetapkan sekali (bukan per-dept) supaya ringkas. Dihitung juga untuk bulan
// lalu supaya ada pembanding tren, konsisten dengan KPI lain di dashboard ini.
function hitungJawabFpm($koneksi, string $whereFPM): array
{
    $row = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
        SUM(CASE WHEN EXISTS (SELECT 1 FROM fpm_pengiriman p WHERE p.id_fpm = h.id_fpm AND p.tgl IS NOT NULL) THEN 1 ELSE 0 END) AS sudah_dijawab,
        SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM fpm_pengiriman p WHERE p.id_fpm = h.id_fpm AND p.tgl IS NOT NULL) THEN 1 ELSE 0 END) AS belum_dijawab
        FROM fpm_header h $whereFPM"));
    return [
        'sudah' => (int) ($row['sudah_dijawab'] ?? 0),
        'belum' => (int) ($row['belum_dijawab'] ?? 0),
    ];
}

$jawabSekarang = hitungJawabFpm($koneksi, $whereFPM);
$jawabLalu     = hitungJawabFpm($koneksi, $whereFPMPrev);

$totalSudahDijawab = $jawabSekarang['sudah'];
$totalBelumDijawab = $jawabSekarang['belum'];

$trenSudahDijawab = hitungTrenKpi($jawabSekarang['sudah'], $jawabLalu['sudah']);
$trenBelumDijawab = hitungTrenKpi($jawabSekarang['belum'], $jawabLalu['belum']);

// FPM dari BULAN LALU yang sampai sekarang masih belum dijawab - ini yang paling
// perlu didorong karena sudah "kelewat" satu periode tapi belum ada respon sama
// sekali (beda dari overdue countermeasure yang FPM-nya sudah dijawab tapi
// tindakannya telat).
$queryBelumDijawabLalu = mysqli_query($koneksi, "SELECT id_fpm, no_reg_fpm, nama_part, machine_no, internal_dept, tgl_terbit
    FROM fpm_header h
    $whereFPMPrev
    AND NOT EXISTS (SELECT 1 FROM fpm_pengiriman p WHERE p.id_fpm = h.id_fpm AND p.tgl IS NOT NULL)
    ORDER BY tgl_terbit ASC");

$belumDijawabLalu = [];
while ($row = mysqli_fetch_assoc($queryBelumDijawabLalu)) {
  $belumDijawabLalu[] = $row;
}

// Rincian sudah/belum dijawab per dept - dept yang paling banyak belum dijawab
// ditaruh di atas supaya langsung kelihatan mana yang perlu didorong.
$queryJawabDept = mysqli_query($koneksi, "SELECT h.internal_dept,
    SUM(CASE WHEN EXISTS (SELECT 1 FROM fpm_pengiriman p WHERE p.id_fpm = h.id_fpm AND p.tgl IS NOT NULL) THEN 1 ELSE 0 END) AS sudah_dijawab,
    SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM fpm_pengiriman p WHERE p.id_fpm = h.id_fpm AND p.tgl IS NOT NULL) THEN 1 ELSE 0 END) AS belum_dijawab,
    COUNT(*) AS total
    FROM fpm_header h $whereFPM
    GROUP BY h.internal_dept
    ORDER BY belum_dijawab DESC, h.internal_dept ASC");

$jawabDept = [];
while ($row = mysqli_fetch_assoc($queryJawabDept)) {
  $jawabDept[] = [
    'dept' => $row['internal_dept'] ?: '-',
    'sudah' => (int) $row['sudah_dijawab'],
    'belum' => (int) $row['belum_dijawab'],
    'total' => (int) $row['total'],
  ];
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

$asakaiOverdue = [];
while ($row = mysqli_fetch_assoc($queryAsakaiOverdue)) {
  $asakaiOverdue[] = $row;
}

$itemKritis = itemKritisGabungan($fpmOverdue, $asakaiOverdue);

$tanggalDelivery = $_GET['tanggal_delivery'] ?? date('Y-m-d');
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
  <link rel="stylesheet" href="../../../dist/css/dashboard-modern.css?v=<?= @filemtime(__DIR__ . '/../../css/dashboard-modern.css') ?>" />

  <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0" defer></script>
  <script src="../../../dist/js/dashboard-widgets.js?v=<?= @filemtime(__DIR__ . '/../../js/dashboard-widgets.js') ?>" defer></script>
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
            Corrective Action, Monitoring & Improvement System
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
            </div>
          </form>
        </div>

        <div class="dash-tabs">
          <button type="button" class="dash-tab-btn active" data-tab-target="#tab-ringkasan">📊 Ringkasan Sasaran Mutu</button>
          <button type="button" class="dash-tab-btn" data-tab-target="#tab-plant">🏭 Perbandingan Plant</button>
          <button type="button" class="dash-tab-btn" data-tab-target="#tab-delivery">🚚 Delivery &amp; Stock</button>
        </div>

        <div id="tab-ringkasan" class="dash-tab-panel active">

        <?php require_once __DIR__ . '/../config/sasaran_mutu_tambahan_widget.php'; ?>

        <div class="row g-3">

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#ef4444;">
              <div class="kpi-big-title"><i class="bi bi-alarm-fill"></i> Butuh Perhatian (<?= htmlspecialchars($namaPeriode) ?>)</div>
              <div class="kpi-big-value"><?= $ringkasanSekarang['total_overdue'] ?></div>
              <?= renderTrenBadge($trenOverdue, 'down') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#10b981;">
              <div class="kpi-big-title"><i class="bi bi-check-circle"></i> Completion Rate</div>
              <div class="kpi-big-value"><?= $ringkasanSekarang['completion_rate'] ?><span class="unit">%</span></div>
              <?= renderTrenBadge($trenCompletion, 'up') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#f59e0b;">
              <div class="kpi-big-title"><i class="bi bi-arrow-repeat"></i> Problem Berulang</div>
              <div class="kpi-big-value"><?= $ringkasanSekarang['problem_berulang'] ?></div>
              <?= renderTrenBadge($trenBerulang, 'down') ?>
            </div>
          </div>

        </div>

        <div class="row section g-3">

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#2563eb;">
              <div class="kpi-big-title"><i class="bi bi-speedometer2"></i> Efisiensi Rata-rata</div>
              <div class="kpi-big-value"><?= $ringkasanSekarang['efisiensi_rata'] ?><span class="unit">%</span></div>
              <?= renderTrenBadge($trenEfisiensi, 'up') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#ef4444;">
              <div class="kpi-big-title"><i class="bi bi-graph-down-arrow"></i> NG Rate</div>
              <div class="kpi-big-value"><?= $ringkasanSekarang['ng_rate'] ?><span class="unit">%</span></div>
              <?= renderTrenBadge($trenNgRate, 'down') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#f97316;">
              <div class="kpi-big-title"><i class="bi bi-tools"></i> Downtime</div>
              <div class="kpi-big-value"><?= abs($ringkasanSekarang['downtime_menit']) ?><span class="unit">menit</span></div>
              <?= renderTrenBadge($trenDowntime, 'down') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#7c3aed;">
              <div class="kpi-big-title"><i class="bi bi-clipboard-data"></i> Total Asakai</div>
              <div class="kpi-big-value"><?= $totalAsakaiSekarang ?></div>
              <?= renderTrenBadge($trenAsakai, 'netral') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#10b981;">
              <div class="kpi-big-title"><i class="bi bi-reply-fill"></i> FPM Sudah Dijawab</div>
              <div class="kpi-big-value"><?= $totalSudahDijawab ?></div>
              <?= renderTrenBadge($trenSudahDijawab, 'up') ?>
            </div>
          </div>

          <div class="col-md">
            <div class="kpi-big-card" style="--kpi-accent:#ef4444;">
              <div class="kpi-big-title"><i class="bi bi-reply"></i> FPM Belum Dijawab</div>
              <div class="kpi-big-value"><?= $totalBelumDijawab ?></div>
              <?= renderTrenBadge($trenBelumDijawab, 'down') ?>
            </div>
          </div>

        </div>

        <!-- Tabel/data yang berwarna merah (item kritis & belum dijawab) sengaja
             ditaruh di sini, tepat setelah KPI umum, supaya tanpa scroll langsung
             kelihatan masalah apa yang harus segera dibereskan. -->
        <?php if (!empty($belumDijawabLalu)): ?>
        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-danger text-white">
                <i class="bi bi-clock-history"></i> FPM Bulan Lalu (<?= htmlspecialchars($bulanIndo[substr($filterBulanPrev, 5, 2)] ?? $filterBulanPrev) ?>) yang Masih Belum Dijawab
              </div>
              <div class="card-body">
                <div class="critical-list" id="belumDijawabLaluList">
                  <?php foreach ($belumDijawabLalu as $i => $b): ?>
                    <a href="jawab_fpm.php?id=<?= $b['id_fpm'] ?>"
                      class="critical-item <?= $i >= 5 ? 'table-extra-row' : '' ?>"
                      title="Klik untuk jawab FPM ini">
                      <div class="critical-item-days"><?= (int) floor((strtotime(date('Y-m-d')) - strtotime($b['tgl_terbit'])) / 86400) ?><small>hari lalu</small></div>
                      <div class="critical-item-body">
                        <div class="critical-item-title"><?= htmlspecialchars($b['no_reg_fpm'] ?: ('FPM #' . $b['id_fpm'])) ?> - <?= htmlspecialchars($b['nama_part'] ?? '-') ?></div>
                        <div class="critical-item-meta">Dept: <?= htmlspecialchars($b['internal_dept'] ?: '-') ?> &middot; Mesin: <?= htmlspecialchars($b['machine_no'] ?? '-') ?> &middot; Terbit: <?= htmlspecialchars($b['tgl_terbit']) ?></div>
                      </div>
                      <div class="critical-item-badge">Belum Dijawab</div>
                    </a>
                  <?php endforeach; ?>
                </div>
                <?php if (count($belumDijawabLalu) > 5): ?>
                  <button type="button" class="table-expand-toggle" data-expand-toggle="belumDijawabLaluList"
                    data-label-expand="Lihat <?= count($belumDijawabLalu) - 5 ?> lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($belumDijawabLalu) - 5 ?> lainnya &#9662;</button>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>
        <?php endif; ?>

        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-danger text-white">
                <i class="bi bi-exclamation-octagon-fill"></i> Item Kritis - Perlu Tindak Lanjut Segera (semua periode, selama belum CLOSED)
              </div>
              <div class="card-body">
                <?php if (empty($itemKritis)): ?>
                  <div class="text-center text-muted py-3">Tidak ada countermeasure overdue yang belum CLOSED. 🎉</div>
                <?php else: ?>
                  <div class="list-pagination-toolbar">
                    Tampilkan
                    <select class="list-pagination-select" data-paginate-size-for="criticalList">
                      <option value="10">10</option>
                      <option value="50">50</option>
                      <option value="100">100</option>
                      <option value="200">200</option>
                    </select>
                    baris
                  </div>
                  <div class="critical-list" id="criticalList" data-paginate="10">
                    <?php foreach ($itemKritis as $i => $item): ?>
                      <a href="<?= htmlspecialchars($item['link']) ?>"
                        class="critical-item <?= $item['hari_telat'] < 7 ? 'severity-medium' : '' ?>"
                        title="Klik untuk tindak lanjuti">
                        <div class="critical-item-days"><?= $item['hari_telat'] ?><small>hari telat</small></div>
                        <div class="critical-item-body">
                          <div class="critical-item-title"><?= htmlspecialchars($item['deskripsi']) ?></div>
                          <div class="critical-item-meta">PIC: <?= htmlspecialchars($item['pic']) ?> &middot; <?= htmlspecialchars($item['konteks']) ?></div>
                        </div>
                        <div class="critical-item-badge"><?= htmlspecialchars($item['tipe']) ?></div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                  <div class="list-pagination-nav">
                    <button type="button" class="list-pagination-btn" data-paginate-prev="criticalList">&laquo; Sebelumnya</button>
                    <span class="list-pagination-info" data-paginate-info="criticalList"></span>
                    <button type="button" class="list-pagination-btn" data-paginate-next="criticalList">Berikutnya &raquo;</button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>

        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-primary text-white">
                Rincian FPM Sudah / Belum Dijawab per Departemen
              </div>
              <div class="card-body table-responsive">
                <?php if (empty($jawabDept)): ?>
                  <div class="text-center text-muted py-3">Tidak ada data pada periode ini</div>
                <?php else: ?>
                  <table id="jawabDeptTable" class="table table-bordered text-center align-middle">
                    <thead>
                      <tr>
                        <th>Departemen</th>
                        <th>Total FPM</th>
                        <th>Sudah Dijawab</th>
                        <th>Belum Dijawab</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($jawabDept as $i => $d): ?>
                        <tr class="<?= $i >= 5 ? 'table-extra-row' : '' ?>">
                          <td><?= htmlspecialchars($d['dept']) ?></td>
                          <td><?= $d['total'] ?></td>
                          <td class="text-success fw-semibold"><?= $d['sudah'] ?></td>
                          <td class="text-danger fw-semibold"><?= $d['belum'] ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <?php if (count($jawabDept) > 5): ?>
                    <button type="button" class="table-expand-toggle" data-expand-toggle="jawabDeptTable"
                      data-label-expand="Lihat <?= count($jawabDept) - 5 ?> dept lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($jawabDept) - 5 ?> dept lainnya &#9662;</button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>

        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-dark text-white">
                <i class="bi bi-graph-up"></i> Progres Penanganan Masalah per Departemen (6 Bulan Terakhir)
              </div>
              <div class="card-body table-responsive">
                <?php if (empty($trenDeptBulanan['dept'])): ?>
                  <div class="text-center text-muted py-3">Belum ada data pada periode ini.</div>
                <?php else: ?>
                  <table class="table table-bordered text-center align-middle" style="font-size:12px;">
                    <thead>
                      <tr>
                        <th style="text-align:left;">Departemen</th>
                        <?php foreach ($trenDeptBulanan['bulan'] as $b): ?>
                          <th><?= htmlspecialchars($bulanIndo[substr($b, 5, 2)] ?? $b) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($trenDeptBulanan['dept'] as $dept => $perBulan): ?>
                        <tr>
                          <td style="text-align:left; font-weight:600;"><?= htmlspecialchars($dept) ?></td>
                          <?php foreach ($trenDeptBulanan['bulan'] as $b): ?>
                            <?php
                              $sel = $perBulan[$b] ?? null;
                              $tooltip = 'Tidak ada data';
                              if ($sel !== null) {
                                $tooltip = $sel['closed'] . ' dari ' . $sel['total'] . ' countermeasure selesai';
                                if (!empty($sel['items'])) {
                                  $tampil = array_map(
                                    fn($t) => trim(preg_replace('/\s+/', ' ', $t)),
                                    array_slice($sel['items'], 0, 6)
                                  );
                                  $tooltip .= "\nBelum selesai:\n- " . implode("\n- ", $tampil);
                                  $sisa = count($sel['items']) - count($tampil);
                                  if ($sisa > 0) {
                                    $tooltip .= "\n+ $sisa lainnya";
                                  }
                                }
                              }
                            ?>
                            <td class="<?= $sel === null ? 'text-muted' : ($sel['percent'] >= 80 ? 'text-success fw-bold' : ($sel['percent'] >= 50 ? 'text-warning fw-bold' : 'text-danger fw-bold')) ?>"
                              title="<?= htmlspecialchars($tooltip) ?>">
                              <?= $sel === null ? '-' : $sel['percent'] . '%' ?>
                            </td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div class="text-muted" style="font-size:11px; margin-top:6px;">% countermeasure closed per bulan (FPM + Asakai digabung). Hijau &ge;80%, kuning &ge;50%, merah &lt;50%. Arahkan kursor ke angka untuk lihat detail.</div>
                <?php endif; ?>
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

        <div class="text-center" style="margin: 8px 0 4px; font-size: 12px;">
          <a href="list_fpm_downtime.php">Lihat detail per Part/Mesin/Jenis Masalah &rarr;</a>
        </div>

        <div class="row section g-3">

          <div class="col-md-12">
            <div class="card h-100">
              <div class="card-header bg-primary text-white">
                Tren 6 Bulan Terakhir - Masalah Dibuat vs Selesai
              </div>
              <div class="card-body">
                <div class="chart-medium">
                  <canvas id="trenBulananChart"></canvas>
                </div>
              </div>
            </div>
          </div>

        </div>

        </div><!-- /#tab-ringkasan -->

        <div id="tab-plant" class="dash-tab-panel">

          <div class="row section g-3">

            <div class="col-md-12">
              <div class="card h-100">
                <div class="card-header bg-dark text-white">
                  <i class="bi bi-building"></i> Perbandingan Antar Plant - <?= htmlspecialchars($namaPeriode) ?>
                </div>
                <div class="card-body table-responsive">
                  <?php if (empty($perbandinganPlant)): ?>
                    <div class="text-center text-muted py-3">Belum ada data pada periode ini.</div>
                  <?php else: ?>
                    <table class="table table-bordered text-center align-middle">
                      <thead>
                        <tr>
                          <th rowspan="2" style="text-align:left; vertical-align:middle;">Plant</th>
                          <th colspan="3" class="bg-primary text-white">FPM</th>
                          <th colspan="6" class="bg-success text-white">Asakai</th>
                        </tr>
                        <tr>
                          <th>Total</th>
                          <th>Closed</th>
                          <th>Problem Berulang</th>
                          <th>Total</th>
                          <th>Closed</th>
                          <th>Open</th>
                          <th>NG Rate</th>
                          <th>Downtime (menit)</th>
                          <th>Efisiensi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($perbandinganPlant as $p): ?>
                          <tr>
                            <td style="text-align:left; font-weight:600;">Plant <?= htmlspecialchars($p['plant']) ?></td>
                            <td><?= $p['total_fpm'] ?></td>
                            <td><?= $p['total_fpm_closed'] ?></td>
                            <td class="<?= $p['total_berulang'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $p['total_berulang'] ?></td>
                            <td><?= $p['total_asakai'] ?></td>
                            <td class="text-success fw-semibold"><?= $p['asakai_cm_closed'] ?></td>
                            <td class="text-danger fw-semibold"><?= $p['asakai_cm_open'] ?></td>
                            <td class="<?= $p['ng_rate'] <= $targetMutu['ng_rate'] ? 'text-success' : 'text-danger' ?> fw-semibold"><?= $p['ng_rate'] ?>%</td>
                            <td><?= abs($p['downtime_menit']) ?></td>
                            <td class="<?= $p['efisiensi_rata'] >= $targetMutu['efisiensi_rata'] ? 'text-success' : 'text-danger' ?> fw-semibold"><?= $p['efisiensi_rata'] ?>%</td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                    <div class="text-muted" style="font-size:11px; margin-top:6px;">Data seluruh plant untuk periode <?= htmlspecialchars($namaPeriode) ?> (tidak terpengaruh filter Plant di atas). NG Rate hijau = memenuhi target sasaran mutu (&le;<?= $targetMutu['ng_rate'] ?>%), merah = belum.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

          </div>

        </div><!-- /#tab-plant -->

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
        $tanggalCasting = $_GET['tanggal_casting'] ?? date('Y-m-d');
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

    </main>

    <?php include '../footer.php'; ?>

  </div>

  <script>
    /* chart.js dimuat "defer" (lihat <head>) supaya tidak memblokir parsing HTML -
       sidebar jadi langsung responsif tanpa menunggu chart.js selesai di-load dari
       CDN. Konsekuensinya, pemakaian Chart di sini WAJIB ditunda sampai
       DOMContentLoaded supaya window.Chart sudah pasti ada. */
    document.addEventListener('DOMContentLoaded', function () {
      renderTrenBulananChart(
        'trenBulananChart',
        <?= json_encode($trenBulanan['label']) ?>,
        <?= json_encode($trenBulanan['total']) ?>,
        <?= json_encode($trenBulanan['closed']) ?>
      );
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
  </script>

</body>

</html>