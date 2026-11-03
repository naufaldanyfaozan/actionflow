<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/list_fpm_excel_export.php';
require_once __DIR__ . '/../config/list_fpm_filter_ui.php';
exportListFpmExcelJikaDiminta('List FPM Claim Machining');
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';
require_once __DIR__ . '/../config/fpm_catatan_revisi_helper.php';
require_once __DIR__ . '/../config/fpm_claim_approval_helper.php';
require_once __DIR__ . '/../config/fpm_status_ganda_helper.php';
require_once __DIR__ . '/../config/list_fpm_paginasi_helper.php';

$tglAwalFilter  = $_GET['tgl_awal'] ?? '';
$tglAkhirFilter = $_GET['tgl_akhir'] ?? '';
$deptFilter   = $_GET['dept'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$searchFilter = trim($_GET['search'] ?? '');

$where = "WHERE h.flow_type = 'CLAIMMACH'";

if (!empty($tglAwalFilter) && !empty($tglAkhirFilter)) {
    $tglAwalEsc  = mysqli_real_escape_string($koneksi, $tglAwalFilter);
    $tglAkhirEsc = mysqli_real_escape_string($koneksi, $tglAkhirFilter);
    $where .= " AND " . sqlTanggalPembuatanFpm() . " BETWEEN '$tglAwalEsc' AND '$tglAkhirEsc'";
}

if (!empty($deptFilter)) {
    $deptFilter = mysqli_real_escape_string($koneksi, $deptFilter);
    $where .= " AND h.dept = '$deptFilter'";
}

$statusCmFilter = $_GET['status_cm'] ?? '';
$where .= sqlFilterStatusApprovalFpm($statusFilter === 'REVISI' ? 'REJECT' : $statusFilter);
$where .= sqlFilterStatusCountermeasureFpm($statusCmFilter);

if (!empty($searchFilter)) {
    $search = mysqli_real_escape_string($koneksi, $searchFilter);
    $where .= " AND (h.no_form LIKE '%$search%' OR h.nama_part LIKE '%$search%')";
}

// Paginasi per bulan masalah terjadi (lihat config/list_fpm_paginasi_helper.php).
$paginasiBulan = siapkanPaginasiBulanFpm($koneksi, $where);
$where .= $paginasiBulan['sql'];

$qFpm = mysqli_query($koneksi, "SELECT h.id_fpm, h.no_form, h.jenis_fpm, h.tgl_pembuatan, h.nama_part,
    h.dept, h.dibuat_oleh, h.status_reject, h.note, h.status_evaluasi,
    h.cm_plant_head_diterima_at, h.cm_dept_head_diterima_at, h.cm_dept_head_dijawab_at, h.cm_dept_head_approved_at,
    h.cm_plant_head_approved_at, h.cm_directur_approved_at,
    p.diperiksa, p.user_diperiksa, p.disetujui1, p.user_disetujui1, p.disetujui, p.user_disetujui,

    (
        (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm)
        + (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm)
        + (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
    ) AS total_all_action,

    (
        (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND (ca.status IS NULL OR ca.status != 'CLOSED'))
        + (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND (pa.status IS NULL OR pa.status != 'CLOSED'))
        + (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm AND (rd.status IS NULL OR rd.status != 'CLOSED'))
    ) AS total_not_closed,
    " . sqlKolomStatusCountermeasureFpm() . ",
    " . sqlKolomDijawabOlehFpm() . "

    FROM fpm_header h
    LEFT JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
    $where
    ORDER BY h.id_fpm DESC
");

$rows = mysqli_fetch_all($qFpm, MYSQLI_ASSOC) ?: [];

// Ambil riwayat revisi semua FPM di halaman ini sekaligus (1 query, bukan
// query per baris) - dipakai buat kolom Catatan supaya kelihatan histori
// reject/revisinya, bukan cuma alasan reject yang terakhir.
$idFpmDiHalaman = array_column($rows, 'id_fpm');
$revisiHistoryByFpm = ambilFpmRevisiHistoryBanyak($koneksi, $idFpmDiHalaman);

$masterDept = [];
$qDept = mysqli_query($koneksi, "SELECT DISTINCT nama_dept FROM master_dept ORDER BY nama_dept ASC");
while ($rowDept = mysqli_fetch_assoc($qDept)) {
    $masterDept[] = $rowDept['nama_dept'];
}
?>

<!doctype html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>List FPM Claim Machining</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />

    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; margin: 0; color: #333; }
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 10px 25px rgba(0,0,0,.05); }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; }
        thead th { background: #f1f3f5; font-size: 13px; font-weight: 600; text-transform: uppercase; padding: 12px; border-bottom: 1px solid #ddd; white-space: nowrap; }
        tbody td { padding: 12px; font-size: 14px; border-bottom: 1px solid #eee; }
        tbody tr:hover { background: #eef3ff; }
        .btn-jawab { background: #198754; color: #fff; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px; text-decoration: none; }
        .btn-detail { background: #2196f3; color: #fff; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px; text-decoration: none; }
        .action-buttons { display: flex; flex-wrap: wrap; gap: 6px; }
        .empty-row td { text-align: center; color: #999; font-style: italic; padding: 24px; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 20px; }
        .filter-group { display: flex; flex-direction: column; min-width: 160px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
        .filter-group.grow { flex: 1; min-width: 220px; }
        .waiting-cell { background-color: #fff3cd; font-weight: 600; }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar_qa.php'; ?>

        <main class="app-main">
            <div class="card">
                <div style="font-size:18px; font-weight:600; margin-bottom:16px;">List FPM Claim Machining</div>

                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label>Tanggal Awal</label>
                        <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tglAwalFilter) ?>">
                    </div>

                    <div class="filter-group">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tglAkhirFilter) ?>">
                    </div>

                    <div class="filter-group">
                        <label>Dept Terkait</label>
                        <select name="dept" class="form-control">
                            <option value="">-- Semua Dept --</option>
                            <?php foreach ($masterDept as $d): ?>
                                <option value="<?= htmlspecialchars($d) ?>" <?= $deptFilter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Status Approval</label>
                        <select name="status" class="form-control">
                            <option value="">-- Semua Status --</option>
                            <option value="OPEN" <?= $statusFilter === 'OPEN' ? 'selected' : '' ?>>Open</option>
                            <option value="CLOSED" <?= $statusFilter === 'CLOSED' ? 'selected' : '' ?>>Closed</option>
                            <option value="REVISI" <?= $statusFilter === 'REVISI' ? 'selected' : '' ?>>Revisi</option>
                        </select>
                    </div>

                    <?= dropdownFilterStatusCountermeasureFpm($statusCmFilter, 'filter-group') ?>

                    <div class="filter-group grow">
                        <label>Cari No Form / Nama Part</label>
                        <input type="text" name="search" class="form-control" placeholder="Ketik kata kunci lalu tekan Enter"
                            value="<?= htmlspecialchars($searchFilter) ?>">
                    </div>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Cari</button>
                            <a href="list_fpm_claim_machining.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Hapus Semua Filter</a>
                            <?= tombolExportExcelListFpm() ?>
                        </div>
                    </div>
                </form>

                <?= renderFilterUiListFpm() ?>

                <?= renderPaginasiBulanFpm($paginasiBulan) ?>

                <div class="fpm-table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Form</th>
                                <th>Tgl Pembuatan</th>
                                <th>Nama Part</th>
                                <th>Dept Terkait</th>
                                <th>Dept Dituju</th>
                                <th>Approved</th>
                                <th>Waiting Approved</th>
                                <th>Status Approval</th>
                                <th>Status Countermeasure</th>
                                <th>Evaluasi 3 Bulan</th>
                                <th>Note Revisi</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): $no = 1; ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php $statusAlur = hitungStatusApprovalClaimMach($row); ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($row['no_form']) ?></td>
                                        <td><?= !empty($row['tgl_pembuatan']) ? date('d-m-Y', strtotime($row['tgl_pembuatan'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($row['nama_part']) ?></td>
                                        <td><?= !empty($row['dept']) ? htmlspecialchars($row['dept']) : '<em>Menunggu Plant Head</em>' ?></td>
                                        <td><?= renderDijawabOlehFpm($row) ?></td>
                                        <td><?= htmlspecialchars($statusAlur['approved']) ?></td>
                                        <td class="<?= $statusAlur['waiting'] !== '-' ? 'waiting-cell' : '' ?>"><?= htmlspecialchars($statusAlur['waiting']) ?></td>
                                        <td><?= badgeStatusFpm(statusApprovalFpm($row, 'REVISI')) ?></td>
                                        <td><?= badgeStatusFpm(statusCountermeasureFpm($row)) ?></td>
                                        <td>
                                            <?php
                                            $evaluasi = strtoupper(trim($row['status_evaluasi'] ?? ''));

                                            if ($evaluasi === 'CLOSED') {
                                                echo '<span class="badge bg-success">CLOSED</span>';
                                            } elseif ($evaluasi === 'OPEN') {
                                                echo '<span class="badge bg-warning">OPEN</span>';
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?= !empty($row['note']) ? htmlspecialchars($row['note']) : '-' ?>
                                            <div style="margin-top:4px;">
                                                <?= renderFpmRevisiRingkasan($revisiHistoryByFpm[$row['id_fpm']] ?? []) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="jawab_fpm_claim_machining.php?id=<?= $row['id_fpm'] ?>" class="btn-jawab">Jawab</a>
                                                <a href="form_fpm.php?id=<?= $row['id_fpm'] ?>" class="btn-detail">Detail</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row"><td colspan="13">Belum ada data FPM Claim Machining</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>

</body>

</html>
