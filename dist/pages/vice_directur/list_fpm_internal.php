<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';
require_once __DIR__ . '/../config/list_fpm_excel_export.php';
require_once __DIR__ . '/../config/list_fpm_filter_ui.php';
require_once __DIR__ . '/../config/fpm_status_ganda_helper.php';
require_once __DIR__ . '/../config/list_fpm_paginasi_helper.php';
exportListFpmExcelJikaDiminta('List FPM Internal');
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$tglAwalFilter  = $_GET['tgl_awal'] ?? '';
$tglAkhirFilter = $_GET['tgl_akhir'] ?? '';
$mesinFilter  = $_GET['mesin'] ?? '';

$where = "WHERE 1=1";

if (!empty($tglAwalFilter) && !empty($tglAkhirFilter)) {
    $tglAwalEsc  = mysqli_real_escape_string($koneksi, $tglAwalFilter);
    $tglAkhirEsc = mysqli_real_escape_string($koneksi, $tglAkhirFilter);
    $where .= " AND " . sqlTanggalPembuatanFpm() . " BETWEEN '$tglAwalEsc' AND '$tglAkhirEsc'";
}

if (!empty($mesinFilter)) {
    $mesinFilter = mysqli_real_escape_string($koneksi, $mesinFilter);
    $where .= " AND h.machine_no = '$mesinFilter'";
}

$username = $_SESSION['username'];

$tglAwalFilter  = $_GET['tgl_awal'] ?? '';
$tglAkhirFilter = $_GET['tgl_akhir'] ?? '';
$mesinFilter   = $_GET['mesin'] ?? '';
$plantFilter   = $_GET['plant'] ?? '';
$statusFilter  = $_GET['status'] ?? '';
$searchFilter  = trim($_GET['search'] ?? '');

/* ==============================
   DEFAULT PLANT SAAT PERTAMA LOAD
================================= */
if (empty($plantFilter)) {
    if ($username == 'doni') {
        $plantFilter = '1';
    } elseif ($username == 'aji') {
        $plantFilter = '3';
    }
}

// jenis_fpm='NG Machining' dipakai baik oleh alur IMP maupun FPM Internal QA -
// exclude flow_type='QA' di sini supaya FPM Internal QA yang disembunyikan
// (lihat staff/sidebar.php) tidak ikut muncul, tapi tetap tidak mengubah
// perilaku untuk baris IMP asli. FPM Claim Machining tidak ditampilkan di sini.
$where = "WHERE 1=1 AND (
    (h.jenis_fpm IN ('NG Casting','NG Machining') AND h.flow_type != 'QA')
    -- FPM Customer QA disembunyikan dulu: OR (h.jenis_fpm = 'CUSTOMER' AND h.flow_type = 'QA')
)";
// FPM Claim Machining (FPM-Cast) punya modul sendiri (Modul FPM QA) - tidak ditampilkan di sini.
require_once __DIR__ . '/../config/verifikasi_countermeasure_helper.php';
$where .= ' ' . buildVerifikasiFpmFlowClause(false);

/* ==============================
   FILTER BULAN
================================= */
if (!empty($tglAwalFilter) && !empty($tglAkhirFilter)) {
    $tglAwalEsc  = mysqli_real_escape_string($koneksi, $tglAwalFilter);
    $tglAkhirEsc = mysqli_real_escape_string($koneksi, $tglAkhirFilter);
    $where .= " AND " . sqlTanggalPembuatanFpm() . " BETWEEN '$tglAwalEsc' AND '$tglAkhirEsc'";
}

/* ==============================
   FILTER MESIN
================================= */
if (!empty($mesinFilter)) {
    $mesinFilter = mysqli_real_escape_string($koneksi, $mesinFilter);
    $where .= " AND h.machine_no = '$mesinFilter'";
}

/* ==============================
   FILTER PLANT
================================= */
if (!empty($plantFilter)) {
    $plantFilter = mysqli_real_escape_string($koneksi, $plantFilter);
    $where .= " AND h.plant = '$plantFilter'";
}

/* ==============================
   FILTER STATUS
================================= */
$statusCmFilter = $_GET['status_cm'] ?? '';
$where .= sqlFilterStatusCountermeasureFpm($statusCmFilter);

if (!empty($statusFilter)) {

    if (in_array($statusFilter, ['OPEN', 'CLOSED', 'REJECT'], true)) {
        $where .= sqlFilterStatusApprovalFpm($statusFilter);
    }

    // Perlu Approval Saya / Sudah Saya Approve disaring di SQL supaya paginasi bulan ikut benar.
    $where .= sqlFilterApprovalSayaFpm($statusFilter, 'Vice Directure');
}

/*****************************
 FILTER NAMA PART / JENIS MASALAH
 ******************************/
if (!empty($searchFilter)) {

    $search = mysqli_real_escape_string($koneksi, $searchFilter);

    $where .= " AND (
        h.nama_part LIKE '%$search%'
        OR
        h.jenis_masalah LIKE '%$search%'
    )";
}

// Paginasi per bulan masalah terjadi (lihat config/list_fpm_paginasi_helper.php).
$paginasiBulan = siapkanPaginasiBulanFpm($koneksi, $where);
$where .= $paginasiBulan['sql'];

$qFpm = mysqli_query($koneksi, "SELECT
    h.id_fpm,
    h.no_form,
    h.plant,
    h.flow_type,
    h.diterima_qa,
    h.tgl_pembuatan,
    h.nama_part,
    h.machine_no,
    h.jenis_masalah,
    h.status_evaluasi,
    h.tgl_terbit,
    h.diperiksa_oleh,
    h.disetujui_oleh,
    h.diterima_imp,
    h.tgl_reject,
    h.note,
    h.diterima_pic,
    h.status_reject,
    h.status_evaluasi,
    p.tgl AS tgl_jawab,
    p.dibuat,
    p.diperiksa,
    p.disetujui,
    p.disetujui1,

    (
        SELECT COUNT(*) 
        FROM fpm_corrective_action 
        WHERE id_fpm = h.id_fpm 
        AND file IS NOT NULL 
        AND file != ''
    )
    +
    (
        SELECT COUNT(*) 
        FROM fpm_preventive_action 
        WHERE id_fpm = h.id_fpm 
        AND file IS NOT NULL 
        AND file != ''
    ) AS total_file,

    (
    (SELECT COUNT(*) FROM fpm_corrective_action ca 
     WHERE ca.id_fpm = h.id_fpm)

    +

    (SELECT COUNT(*) FROM fpm_preventive_action pa 
     WHERE pa.id_fpm = h.id_fpm)

    +

    (SELECT COUNT(*) FROM fpm_review_dokumen rd 
     WHERE rd.id_fpm = h.id_fpm)
) AS total_all_action,


(
    (SELECT COUNT(*) FROM fpm_corrective_action ca 
     WHERE ca.id_fpm = h.id_fpm 
     AND (ca.status IS NULL OR ca.status != 'CLOSED'))

    +

    (SELECT COUNT(*) FROM fpm_preventive_action pa 
     WHERE pa.id_fpm = h.id_fpm 
     AND (pa.status IS NULL OR pa.status != 'CLOSED'))

    +

    (SELECT COUNT(*) FROM fpm_review_dokumen rd 
     WHERE rd.id_fpm = h.id_fpm 
     AND (rd.status IS NULL OR rd.status != 'CLOSED'))
) AS total_not_closed,
    " . sqlKolomStatusCountermeasureFpm() . ",
    " . sqlKolomDijawabOlehFpm() . "

FROM fpm_header h
LEFT JOIN fpm_pengiriman p 
    ON h.id_fpm = p.id_fpm
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

?>

<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>List FPM Internal</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="List FPM Internal" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            margin: 0;
            color: #333;
        }

        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        thead th {
            background: #f1f3f5;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 12px;
            border-bottom: 1px solid #ddd;
            white-space: nowrap;
        }

        tbody td {
            padding: 12px;
            font-size: 14px;
            border-bottom: 1px solid #eee;
        }

        tbody tr:nth-child(even) {
            background: #fafafa;
        }

        tbody tr:hover {
            background: #eef3ff;
        }

        .action {
            white-space: nowrap;
            text-align: center;
        }

        .btn {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-verify {
            background: #4caf50;
            color: #fff;
        }

        .btn-detail {
            background: #2196f3;
            color: #fff;
            margin-left: 6px;
        }

        .empty-row td {
            text-align: center;
            color: #999;
            font-style: italic;
            padding: 24px;
        }
    </style>

</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="card">
                <div class="card-header">List FPM Internal</div>

                <?php
                $tglAwalFilter  = $_GET['tgl_awal'] ?? '';
                $tglAkhirFilter = $_GET['tgl_akhir'] ?? '';
                $mesinFilter  = $_GET['mesin'] ?? '';

                $qMesin = mysqli_query($koneksi, "SELECT DISTINCT machine_no FROM fpm_header ORDER BY machine_no ASC");
                ?>

                <form method="GET" style="margin-bottom:20px;">
                    <div style="display:flex; gap:15px; align-items:end; flex-wrap:wrap;">

                        <div>
                            <label>Tanggal Awal</label><br>
                            <input type="date" name="tgl_awal" value="<?= htmlspecialchars($tglAwalFilter) ?>" class="form-control">
                        </div>

                        <div>
                            <label>Tanggal Akhir</label><br>
                            <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($tglAkhirFilter) ?>" class="form-control">
                        </div>

                        <div>
                            <label>Mesin</label><br>
                            <select name="mesin" class="form-control">
                                <option value="">-- Semua Mesin --</option>
                                <?php while ($m = mysqli_fetch_assoc($qMesin)): ?>
                                    <option value="<?= $m['machine_no'] ?>"
                                        <?= ($mesinFilter == $m['machine_no']) ? 'selected' : '' ?>>
                                        <?= $m['machine_no'] ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label>Plant</label><br>
                            <select name="plant" class="form-control">
                                <option value="">-- Semua Plant --</option>
                                <option value="1" <?= ($plantFilter == '1') ? 'selected' : '' ?>>Plant 1</option>
                                <option value="2" <?= ($plantFilter == '2') ? 'selected' : '' ?>>Plant 2</option>
                                <option value="3" <?= ($plantFilter == '3') ? 'selected' : '' ?>>Plant 3</option>
                            </select>
                        </div>

                        <div>
                            <label>Status Approval</label><br>
                            <select name="status" class="form-control">
                                <option value="">-- Semua Status --</option>
                                <option value="OPEN" <?= ($statusFilter == 'OPEN') ? 'selected' : '' ?>>Open</option>
                                <option value="CLOSED" <?= ($statusFilter == 'CLOSED') ? 'selected' : '' ?>>Closed</option>
                                <option value="REJECT" <?= ($statusFilter == 'REJECT') ? 'selected' : '' ?>>Reject</option>
                                <option value="PERLU_SAYA" <?= ($statusFilter == 'PERLU_SAYA') ? 'selected' : '' ?>>Perlu Approval Saya</option>
                                <option value="SUDAH_SAYA" <?= ($statusFilter == 'SUDAH_SAYA') ? 'selected' : '' ?>>Sudah Saya Approve</option>
                            </select>
                        </div>

                        <?= dropdownFilterStatusCountermeasureFpm($statusCmFilter) ?>

                        <div style="min-width:320px;">
                            <label>Nama Part / Jenis Masalah</label><br>
                            <input type="text" name="search" class="form-control" placeholder="Ketik kata kunci lalu tekan Enter"
                                value="<?= htmlspecialchars($searchFilter) ?>">
                        </div>

                        <div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Cari</button>
                            <a href="?" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Hapus Semua Filter</a>
                            <?= tombolExportExcelListFpm() ?>
                        </div>

                    </div>
                </form>

                <?= renderFilterUiListFpm() ?>

                <?= renderPaginasiBulanFpm($paginasiBulan) ?>

                <div class="table-wrapper fpm-table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Reg FPM</th>
                                <th>Tgl Pembuatan<br>FPM</th>
                                <th>Nama Part</th>
                                <th>Mesin</th>
                                <th>Jenis Masalah</th>
                                <th>Status<br>Approval</th>
                                <th>Status<br>Countermeasure</th>
                                <th>Tgl Diterima<br>PIC Terkait</th>
                                <th>Tgl Jawab</th>
                                <th>Dept Dituju</th>
                                <th>Approved</th>
                                <th>Waiting<br>Approved</th>
                                <th>Diterima Imp</th>
                                <th>Tanggal<br>Reject</th>
                                <th>Note</th>
                                <th>Lampiran</th>
                                <th>Evaluasi 3 Bulan</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (mysqli_num_rows($qFpm) > 0): ?>
                                <?php $no = 1; ?>
                                <?php $totalBarisTampil = 0; ?>
                                <?php while ($row = mysqli_fetch_assoc($qFpm)):
                                    $approved = '-';
                                    $waiting  = '-';

                                    if (($row['flow_type'] ?? 'IMP') === 'QA') {

                                        if (!empty($row['disetujui']) && $row['disetujui'] == 'Directure') {

                                            $approved = 'Directure';
                                            $waiting  = '-';
                                        } elseif (!empty($row['disetujui1']) && $row['disetujui1'] == 'Dept Head QA') {

                                            $approved = 'Dept Head QA';
                                            $waiting  = 'Directure';
                                        } elseif (!empty($row['diperiksa']) && $row['diperiksa'] == 'Plant Head') {

                                            $approved = 'Plant Head';
                                            $waiting  = 'Dept Head QA';
                                        } elseif (!empty($row['dibuat'])) {

                                            $approved = 'Dept Head';
                                            $waiting  = 'Plant Head';
                                        } elseif (!empty($row['diterima_qa'])) {

                                            $approved = 'Staff QA';
                                            $waiting  = 'Dept Head';
                                        } else {

                                            $approved = '-';
                                            $waiting  = 'Staff QA';
                                        }
                                    } elseif (!empty($row['disetujui']) && $row['disetujui'] == 'Directure') {

                                        $approved = 'Directure';
                                        $waiting  = '-';
                                    } elseif (!empty($row['disetujui1']) && $row['disetujui1'] == 'Vice Directure') {

                                        $approved = 'Vice Directure';
                                        $waiting  = 'Directure';
                                    } elseif (!empty($row['diterima_imp'])) {

                                        $approved = 'Imp Head';
                                        $waiting  = 'Directure';
                                    } elseif (!empty($row['diperiksa']) && $row['diperiksa'] == 'Plant Head') {

                                        $approved = 'Plant Head';
                                        $waiting  = 'Imp Head';
                                    } elseif (!empty($row['dibuat'])) {

                                        $approved = 'Dept Head';
                                        $waiting  = 'Plant Head';
                                    } elseif (!empty($row['diperiksa_oleh']) && !empty($row['disetujui_oleh'])) {

                                        $approved = 'Imp Head';
                                        $waiting  = 'Dept Head';
                                    } else {

                                        $approved = '-';
                                        $waiting  = 'Imp Head';
                                    }

                                    // Filter monitoring "Perlu Approval Saya" / "Sudah Saya Approve"
                                    // (dropdown Status) - Vice Directure "sudah approve" kalau
                                    // disetujui1 sudah bernilai 'Vice Directure'. Catatan: tombol
                                    // approve Vice Directure saat ini selalu nonaktif (alur IMP &
                                    // QA sudah skip Vice Directure - lihat form_fpm.php), jadi
                                    // "Perlu Approval Saya" wajar kalau selalu kosong.
                                    $sudahSayaApprove = (($row['disetujui1'] ?? '') === 'Vice Directure');

                                    if ($statusFilter === 'PERLU_SAYA' && $waiting !== 'Vice Directure') {
                                        continue;
                                    }
                                    if ($statusFilter === 'SUDAH_SAYA' && !$sudahSayaApprove) {
                                        continue;
                                    }

                                    $totalBarisTampil++;
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($row['no_form']) ?></td>
                                        <td><?= date('d-m-Y', strtotime($row['tgl_pembuatan'])) ?></td>
                                        <td><?= htmlspecialchars($row['nama_part']) ?></td>
                                        <td><?= htmlspecialchars($row['machine_no']) ?></td>
                                        <td><?= htmlspecialchars($row['jenis_masalah']) ?></td>
                                        <?php
                                        $statusApproval = statusApprovalFpm($row);
                                        $statusCm = statusCountermeasureFpm($row);
                                        ?>
                                        <td><?= badgeStatusFpm($statusApproval) ?></td>
                                        <td><?= badgeStatusFpm($statusCm) ?></td>
                                        <td>
                                            <?php
                                            if (!empty($row['diterima_pic'])) {
                                                echo date('d-m-Y', strtotime($row['diterima_pic']));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            if (!empty($row['tgl_jawab']) && $row['tgl_jawab'] !== '0000-00-00') {
                                                echo date('d-m-Y', strtotime($row['tgl_jawab']));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td><?= renderDijawabOlehFpm($row) ?></td>
                                        <td><?= $approved ?></td>
                                        <td
                                            <?php if ($waiting == 'Vice Directure'): ?>
                                            style="background-color: yellow; font-weight:bold;"
                                            <?php endif; ?>>
                                            <?= $waiting ?>
                                        </td>
                                        <td>
                                            <?php
                                            if (!empty($row['diterima_imp'])) {
                                                echo date('d-m-Y', strtotime($row['diterima_imp']));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?= !empty($row['tgl_reject']) ? htmlspecialchars($row['tgl_reject']) : '-' ?>
                                        </td>
                                        <td>
                                            <?= !empty($row['note']) ? htmlspecialchars($row['note']) : '-' ?>
                                            <div style="margin-top:4px;">
                                                <?= renderFpmRevisiRingkasan($revisiHistoryByFpm[$row['id_fpm']] ?? []) ?>
                                            </div>
                                        </td>

                                        <td style="text-align:center;">
                                            <?php if ($row['total_file'] > 0): ?>

                                                <a href="lihat_lampiran.php?id=<?= $row['id_fpm'] ?>"
                                                    class="btn btn-warning btn-sm">
                                                    <i class="bi bi-paperclip"></i> Cek
                                                </a>

                                            <?php else: ?>

                                                <span style="color:#aaa;">-</span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php
                                            $evaluasi = strtoupper(trim($row['status_evaluasi'] ?? ''));

                                            if ($evaluasi == 'CLOSED') {
                                                echo '<span class="badge bg-success">CLOSED</span>';
                                            } elseif ($evaluasi == 'OPEN') {
                                                echo '<span class="badge bg-warning">OPEN</span>';
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>

                                        <td class="action">
                                            <a href="form_fpm.php?id=<?= $row['id_fpm'] ?>"
                                                class="btn btn-detail">
                                                Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($totalBarisTampil === 0): ?>
                                    <tr class="empty-row">
                                        <td colspan="9">Tidak ada data yang cocok dengan filter</td>
                                    </tr>
                                <?php endif; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="9">Belum ada data FPM</td>
                                </tr>
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

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        const connectedSortables =
            document.querySelectorAll(".connectedSortable");
        connectedSortables.forEach((connectedSortable) => {
            let sortable = new Sortable(connectedSortable, {
                group: "shared",
                handle: ".card-header",
            });
        });

        const cardHeaders = document.querySelectorAll(
            ".connectedSortable .card-header",
        );
        cardHeaders.forEach((cardHeader) => {
            cardHeader.style.cursor = "move";
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js"></script>

</body>

</html>