<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/list_fpm_excel_export.php';
require_once __DIR__ . '/../config/list_fpm_filter_ui.php';
exportListFpmExcelJikaDiminta('List FPM QA - Dept Head QA');
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';

$tglAwalFilter  = $_GET['tgl_awal'] ?? '';
$tglAkhirFilter = $_GET['tgl_akhir'] ?? '';
$jenisFilter  = $_GET['jenis'] ?? '';
$deptFilter   = $_GET['dept'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$searchFilter = trim($_GET['search'] ?? '');

// FPM Internal QA (jenis_fpm='NG Machining') disembunyikan dari list ini dulu -
// datanya tetap ada di DB, cuma tidak ditampilkan (lihat sidebar.php).
$where = "WHERE h.flow_type = 'QA' AND h.jenis_fpm != 'NG Machining' AND p.diperiksa = 'Plant Head'";

if (!empty($tglAwalFilter) && !empty($tglAkhirFilter)) {
    $tglAwalEsc  = mysqli_real_escape_string($koneksi, $tglAwalFilter);
    $tglAkhirEsc = mysqli_real_escape_string($koneksi, $tglAkhirFilter);
    $where .= " AND h.tgl_pembuatan BETWEEN '$tglAwalEsc' AND '$tglAkhirEsc'";
}

if (!empty($jenisFilter)) {
    $jenisFilter = mysqli_real_escape_string($koneksi, $jenisFilter);
    if ($jenisFilter === 'CUSTOMER') {
        $where .= " AND h.jenis_fpm = 'CUSTOMER'";
    } else {
        $where .= " AND h.jenis_fpm = '$jenisFilter'";
    }
}

if (!empty($deptFilter)) {
    $deptFilter = mysqli_real_escape_string($koneksi, $deptFilter);
    $where .= " AND h.dept = '$deptFilter'";
}

if (!empty($statusFilter)) {
    if ($statusFilter === 'REVISI') {
        $where .= " AND h.status_reject = 'REJECT'";
    } elseif ($statusFilter === 'DIAPPROVE') {
        $where .= " AND (h.status_reject IS NULL OR h.status_reject != 'REJECT') AND p.disetujui1 = 'Dept Head QA'";
    } elseif ($statusFilter === 'MENUNGGU') {
        $where .= " AND (h.status_reject IS NULL OR h.status_reject != 'REJECT') AND (p.disetujui1 IS NULL OR p.disetujui1 != 'Dept Head QA')";
    }
}

if (!empty($searchFilter)) {
    $search = mysqli_real_escape_string($koneksi, $searchFilter);
    $where .= " AND (h.nama_part LIKE '%$search%' OR h.jenis_masalah LIKE '%$search%' OR h.no_form LIKE '%$search%')";
}

$qFpm = mysqli_query($koneksi, "SELECT
    h.id_fpm,
    h.no_form,
    h.jenis_fpm,
    h.tgl_pembuatan,
    h.nama_part,
    h.dept,
    h.status_reject,
    h.note,
    p.disetujui1,
    p.user_disetujui1,
    p.disetujui,
    p.user_disetujui,

    (
        (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm)
        + (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm)
        + (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
    ) AS total_all_action,

    (
        (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND (ca.status IS NULL OR ca.status != 'CLOSED'))
        + (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND (pa.status IS NULL OR pa.status != 'CLOSED'))
        + (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm AND (rd.status IS NULL OR rd.status != 'CLOSED'))
    ) AS total_not_closed

    FROM fpm_header h
    JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
    $where
    ORDER BY h.id_fpm DESC
");

// Ambil riwayat revisi semua FPM di halaman ini sekaligus (1 query, bukan
// query per baris) - dipakai buat kolom Catatan supaya kelihatan histori
// reject/revisinya, bukan cuma alasan reject yang terakhir.
$idFpmDiHalaman = [];
while ($rowTmp = mysqli_fetch_assoc($qFpm)) {
    $idFpmDiHalaman[] = $rowTmp['id_fpm'];
}
mysqli_data_seek($qFpm, 0);
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
    <title>List FPM QA - Dept Head QA</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />

    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; margin: 0; color: #333; }
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 10px 25px rgba(0,0,0,.05); }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        thead th { background: #f1f3f5; font-size: 13px; font-weight: 600; text-transform: uppercase; padding: 12px; border-bottom: 1px solid #ddd; white-space: nowrap; }
        tbody td { padding: 12px; font-size: 14px; border-bottom: 1px solid #eee; }
        tbody tr:hover { background: #eef3ff; }
        .btn-detail { background: #2196f3; color: #fff; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px; text-decoration: none; }
        .empty-row td { text-align: center; color: #999; font-style: italic; padding: 24px; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 20px; }
        .filter-group { display: flex; flex-direction: column; min-width: 160px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
        .filter-group.grow { flex: 1; min-width: 220px; }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">
            <div class="card">
                <div style="font-size:18px; font-weight:600; margin-bottom:16px;">List FPM QA (Menunggu / Sudah Diapprove)</div>

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
                        <label>Jenis FPM</label>
                        <select name="jenis" class="form-control">
                            <option value="">-- Semua Jenis --</option>
                            <option value="CUSTOMER" <?= $jenisFilter === 'CUSTOMER' ? 'selected' : '' ?>>Customer</option>
                        </select>
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
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">-- Semua Status --</option>
                            <option value="MENUNGGU" <?= $statusFilter === 'MENUNGGU' ? 'selected' : '' ?>>Menunggu</option>
                            <option value="DIAPPROVE" <?= $statusFilter === 'DIAPPROVE' ? 'selected' : '' ?>>Diapprove</option>
                            <option value="REVISI" <?= $statusFilter === 'REVISI' ? 'selected' : '' ?>>Revisi</option>
                        </select>
                    </div>

                    <div class="filter-group grow">
                        <label>Cari No Form / Nama Part / Jenis Masalah</label>
                        <input type="text" name="search" class="form-control" placeholder="Ketik kata kunci lalu tekan Enter"
                            value="<?= htmlspecialchars($searchFilter) ?>">
                    </div>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Cari</button>
                            <a href="list_fpm_internal.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Hapus Semua Filter</a>
                            <?= tombolExportExcelListFpm() ?>
                        </div>
                    </div>
                </form>

                <?= renderFilterUiListFpm() ?>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Form</th>
                                <th>Jenis FPM</th>
                                <th>Tgl Pembuatan</th>
                                <th>Nama Part</th>
                                <th>Dept</th>
                                <th>Status Approval</th>
                                <th>Status FPM</th>
                                <th>Note Revisi</th>
                                <th>Approval Dept Head QA</th>
                                <th>Approval Directure</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($qFpm) > 0): $no = 1; ?>
                                <?php while ($row = mysqli_fetch_assoc($qFpm)): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($row['no_form']) ?></td>
                                        <td><?= htmlspecialchars($row['jenis_fpm']) ?></td>
                                        <td><?= !empty($row['tgl_pembuatan']) ? date('d-m-Y', strtotime($row['tgl_pembuatan'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($row['nama_part']) ?></td>
                                        <td><?= htmlspecialchars($row['dept']) ?></td>
                                        <td>
                                            <?php if ($row['status_reject'] === 'REJECT'): ?>
                                                <span class="badge bg-danger">REVISI</span>
                                            <?php elseif (!empty($row['disetujui1']) && $row['disetujui1'] === 'Dept Head QA'): ?>
                                                <span class="badge bg-success">DIAPPROVE</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">MENUNGGU</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['total_all_action'] > 0 && $row['total_not_closed'] == 0): ?>
                                                <span class="badge bg-success">CLOSED</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">OPEN</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= !empty($row['note']) ? htmlspecialchars($row['note']) : '-' ?>
                                            <div style="margin-top:4px;">
                                                <?= renderFpmRevisiRingkasan($revisiHistoryByFpm[$row['id_fpm']] ?? []) ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($row['user_disetujui1'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['user_disetujui'] ?? '-') ?></td>
                                        <td><a href="form_fpm.php?id=<?= $row['id_fpm'] ?>" class="btn-detail">Detail</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr class="empty-row"><td colspan="12">Belum ada FPM QA yang perlu diapprove</td></tr>
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
