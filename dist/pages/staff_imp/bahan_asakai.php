<?php
session_start();
include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

if (isset($_GET['hapus'])) {
    $id_hapus = intval($_GET['hapus']);
    mysqli_query($koneksi, "DELETE FROM asakai_countermeasure_downtime WHERE id_asakai=$id_hapus");
    mysqli_query($koneksi, "DELETE FROM asakai_countermeasure_ng WHERE id_asakai=$id_hapus");
    mysqli_query($koneksi, "DELETE FROM asakai_analisa WHERE id_asakai=$id_hapus");
    mysqli_query($koneksi, "DELETE FROM asakai_ng_problem WHERE id_asakai=$id_hapus");
    mysqli_query($koneksi, "DELETE FROM asakai_header WHERE id_asakai=$id_hapus");
    header("Location: bahan_asakai.php?tanggal=" . urlencode($_GET['tanggal'] ?? date('Y-m-d')) . "&plant=" . urlencode($_GET['plant'] ?? '') . "&grup=" . urlencode($_GET['grup'] ?? 'auto'));
    exit;
}

if (isset($_GET['hapus_tambahan'])) {
    $id_hapus_tambahan = intval($_GET['hapus_tambahan']);
    mysqli_query($koneksi, "DELETE FROM asakai_perbaikan_tambahan WHERE id_tambahan=$id_hapus_tambahan");
    header("Location: bahan_asakai.php?tanggal=" . urlencode($_GET['tanggal'] ?? date('Y-m-d')) . "&plant=" . urlencode($_GET['plant'] ?? '') . "&grup=" . urlencode($_GET['grup'] ?? 'auto'));
    exit;
}

$tanggal = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$grup = isset($_GET['grup']) ? $_GET['grup'] : 'auto';

// Cari tanggal Jumat & Sabtu terakhir pada/sebelum tanggal acuan.
function cariJumatSabtu($tanggalAcuan)
{
    $ts = strtotime($tanggalAcuan);
    $jumat = (date('N', $ts) == 5) ? $tanggalAcuan : date('Y-m-d', strtotime('last friday', $ts));
    $sabtu = date('Y-m-d', strtotime($jumat . ' +1 day'));
    return [$jumat, $sabtu];
}

// Kegiatan hari H membahas problem hari H-1 (kemarin), kecuali hari Senin
// yang membahas problem hari Jumat & Sabtu sebelumnya (gabungan 1 halaman).
// Selain otomatis berdasarkan hari sebenarnya di server, kelompok pembahasan
// juga bisa dipilih manual lewat tombol (parameter "grup"), supaya kalau
// deteksi otomatis meleset (mis. libur nasional), staff tetap bisa membuka
// kelompok yang benar tanpa harus mengubah kode.
$isSeninGabungan = false;
if ($grup === 'jumsab') {
    $isSeninGabungan = true;
    $tanggalList = cariJumatSabtu($tanggal);
} elseif ($grup === 'h1') {
    $tanggalList = [date('Y-m-d', strtotime($tanggal . ' -1 day'))];
} else {
    $grup = 'auto';
    $tanggalList = [date('Y-m-d', strtotime($tanggal . ' -1 day'))];
    if (date('N', strtotime($tanggal)) == 1) {
        $isSeninGabungan = true;
        $tanggalList = cariJumatSabtu($tanggal);
    }
}

// Tanggal 1 di awal bulan otomatis menggabungkan problem tanggal terakhir
// bulan sebelumnya (30 atau 31, mengikuti jumlah hari bulan tsb), supaya
// input di akhir bulan tetap ikut dibahas di tanggal 1.
$isAkhirBulanGabungan = false;
$tanggalAkhirBulanLalu = null;
if (date('j', strtotime($tanggal)) == 1) {
    $isAkhirBulanGabungan = true;
    $tanggalAkhirBulanLalu = date('Y-m-t', strtotime($tanggal . ' -1 month'));
    if (!in_array($tanggalAkhirBulanLalu, $tanggalList)) {
        $tanggalList[] = $tanggalAkhirBulanLalu;
    }
}

$username = $_SESSION['username'] ?? '';
$plant = '';

if (isset($_GET['plant']) && $_GET['plant'] !== '') {
    $plant = $_GET['plant'];
} else {
    if ($username == 'doni') {
        $plant = 1;
    } elseif ($username == 'aji') {
        $plant = 3;
    }
}

$tanggalListEscaped = array_map(fn($t) => "'" . mysqli_real_escape_string($koneksi, $t) . "'", $tanggalList);
$where = "WHERE tanggal_problem IN (" . implode(',', $tanggalListEscaped) . ")";

if (!empty($plant)) {
    $where .= " AND plant = '$plant'";
}

$query = mysqli_query($koneksi, "SELECT ah.*,
        EXISTS (
            SELECT 1
            FROM asakai_analisa aa
            WHERE aa.id_asakai = ah.id_asakai
            AND aa.analisa1 IS NOT NULL
            AND TRIM(aa.analisa1) != ''
        ) AS sudah_dibahas,
        TRIM(BOTH '\n' FROM CONCAT_WS('\n', NULLIF(ah.ng_problem, ''), ngp.extra_ng)) AS ng_problem_list
    FROM asakai_header ah
    LEFT JOIN (
        SELECT id_asakai, GROUP_CONCAT(deskripsi SEPARATOR '\n') AS extra_ng
        FROM asakai_ng_problem
        GROUP BY id_asakai
    ) ngp ON ngp.id_asakai = ah.id_asakai
    $where
    ORDER BY ah.tanggal_problem ASC, ah.urutan ASC
");

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
    LEFT JOIN fpm_delivery_monitor_countermeasure fdmc ON fdm.id_monitor = fdmc.id_monitor
    WHERE fdm.tanggal = '$tanggal'
    ORDER BY fdm.id_monitor ASC
");

$where_tambahan = "WHERE tanggal = '$tanggal'";
if (!empty($plant)) {
    $where_tambahan .= " AND plant = '$plant'";
}
$query_tambahan = mysqli_query($koneksi, "SELECT * FROM asakai_perbaikan_tambahan $where_tambahan ORDER BY id_tambahan ASC");

$query_dept = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept ORDER BY id ASC");
$list_dept = [];
if ($query_dept) {
    while ($dept = mysqli_fetch_assoc($query_dept)) {
        $list_dept[] = $dept['nama_dept'];
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>List Pembahasan Asakai</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Create Asakai" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
        }

        .card-custom {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border-radius: 12px;
            text-align: center;
        }

        thead tr:first-child th {
            color: #fff;
            text-align: center;
            padding: 12px;
            font-size: 13px;
        }

        .th-main {
            background: #38bdf8;
        }

        .th-achievement {
            background: #60a5fa;
        }

        .th-ct {
            background: #a78bfa;
        }

        .th-problem {
            background: #f87171;
        }

        .sub-achievement {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .sub-ct {
            background: #ddd6fe;
            color: #4c1d95;
        }

        .sub-problem {
            background: #fecaca;
            color: #7f1d1d;
        }

        .th-delivery {
            background: #ffd8be !important;
            color: #472f1e !important;
            font-weight: 600;
        }

        .text-danger-custom {
            color: #dc3545;
            font-weight: bold;
        }

        .table-input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 13px;
            transition: 0.1s;
            background-color: #ffffff;
        }

        .table-input:focus {
            border-color: #f97316;
            outline: none;
            box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.2);
        }

        tbody tr {
            transition: 0.2s;
        }

        tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        td {
            padding: 10px;
            font-size: 13px;
            text-align: center;
            color: #1e293b;
        }

        .badge-plant1 {
            background: #dbeafe;
            color: #1d4ed8;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-plant3 {
            background: #dcfce7;
            color: #166534;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
        }

        .content-wrapper-custom {
            padding: 20px;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }

        th,
        td {
            border: 1px solid #e2e8f0;
        }

        tbody tr:hover {
            background: #f0f9ff;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="content-wrapper-custom">
                <div class="card-custom">
                    <div class="page-title">
                        <i class="bi bi-clipboard-data"></i> List Pembahasan Asakai
                    </div>

                    <?php if ($isSeninGabungan): ?>
                        <div class="alert alert-info" style="margin-bottom:15px;">
                            <i class="bi bi-info-circle"></i>
                            <?php if ($grup === 'jumsab'): ?>
                                Kelompok pembahasan dipilih manual: menggabungkan problem
                            <?php else: ?>
                                Tanggal yang dipilih adalah hari Senin, jadi list ini otomatis menggabungkan problem
                            <?php endif; ?>
                            <strong><?= date('d-m-Y', strtotime($tanggalList[0])) ?> (Jumat)</strong> dan
                            <strong><?= date('d-m-Y', strtotime($tanggalList[1])) ?> (Sabtu)</strong> dalam 1 halaman.
                        </div>
                    <?php endif; ?>

                    <div style="margin-bottom:15px;">
                        <span class="text-muted" style="margin-right:8px;">Kelompok Pembahasan:</span>
                        <a href="?tanggal=<?= urlencode($tanggal) ?>&plant=<?= urlencode($plant) ?>&grup=auto"
                            class="btn btn-sm <?= ($grup === 'auto') ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Otomatis
                        </a>
                        <a href="?tanggal=<?= urlencode($tanggal) ?>&plant=<?= urlencode($plant) ?>&grup=h1"
                            class="btn btn-sm <?= ($grup === 'h1') ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Problem Kemarin (H-1)
                        </a>
                        <a href="?tanggal=<?= urlencode($tanggal) ?>&plant=<?= urlencode($plant) ?>&grup=jumsab"
                            class="btn btn-sm <?= ($grup === 'jumsab') ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Gabungan Jumat + Sabtu
                        </a>
                    </div>

                    <?php if ($isAkhirBulanGabungan): ?>
                        <div class="alert alert-info" style="margin-bottom:15px;">
                            <i class="bi bi-info-circle"></i>
                            Tanggal yang dipilih adalah tanggal 1, jadi list ini otomatis menggabungkan problem
                            tanggal <strong><?= date('d-m-Y', strtotime($tanggalAkhirBulanLalu)) ?></strong>
                            (akhir bulan sebelumnya) dalam 1 halaman.
                        </div>
                    <?php endif; ?>

                    <form method="GET" class="row g-2 align-items-end" style="margin-bottom:15px;">
                        <input type="hidden" name="grup" value="<?= htmlspecialchars($grup) ?>">
                        <div class="col-md-3">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="tanggal" id="tanggal" class="form-control"
                                value="<?= htmlspecialchars($tanggal) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Plant</label>
                            <select name="plant" class="form-control">
                                <option value="">Semua Plant</option>
                                <option value="1" <?= ($plant == 1) ? 'selected' : '' ?>>Plant 1</option>
                                <option value="2" <?= ($plant == 2) ? 'selected' : '' ?>>Plant 2</option>
                                <option value="3" <?= ($plant == 3) ? 'selected' : '' ?>>Plant 3</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                        </div>
                    </form>

                    <div style="margin-bottom:10px;">
                        <button type="button" id="btnSimpanUrutan" class="btn btn-success btn-sm" disabled>
                            <i class="bi bi-save"></i> Simpan Urutan
                        </button>
                        <small class="text-muted">Geser baris pakai ikon <i class="bi bi-grip-vertical"></i> untuk mengubah urutan pembahasan.</small>
                    </div>

                    <div class="table-responsive" style="border-radius:12px; overflow-x:auto; overflow-y:hidden; margin-bottom: 35px;">
                        <table>
                            <thead>
                                <tr>
                                    <th rowspan="2" class="th-main"></th>
                                    <th rowspan="2" class="th-main">No</th>
                                    <th rowspan="2" class="th-main">Tanggal Asakai</th>
                                    <th rowspan="2" class="th-main">Tanggal Problem</th>
                                    <th rowspan="2" class="th-main">Plant</th>
                                    <th rowspan="2" class="th-main">Machine</th>
                                    <th rowspan="2" class="th-main">Nama Part</th>
                                    <th rowspan="2" class="th-main">Type</th>
                                    <th rowspan="2" class="th-main">Mold #</th>
                                    <th colspan="4" class="th-achievement">Achievement</th>
                                    <th colspan="4" class="th-ct">Cycle Time & Efficiency</th>
                                    <th colspan="2" class="th-problem">Problem</th>
                                    <th rowspan="2" class="th-main">Keterangan</th>
                                    <th rowspan="2" class="th-main">Action</th>
                                </tr>
                                <tr>
                                    <th class="sub-achievement">Target</th>
                                    <th class="sub-achievement">OK</th>
                                    <th class="sub-achievement">NG</th>
                                    <th class="sub-achievement">DT</th>
                                    <th class="sub-ct">Standard</th>
                                    <th class="sub-ct">Actual</th>
                                    <th class="sub-ct">Efficiency</th>
                                    <th class="sub-ct">% NG</th>
                                    <th class="sub-problem">Down Time</th>
                                    <th class="sub-problem">NG</th>
                                </tr>
                            </thead>
                            <tbody id="asakaiRows">
                                <?php
                                $no = 1;
                                while ($row = mysqli_fetch_assoc($query)) {
                                ?>
                                    <tr data-id="<?= $row['id_asakai'] ?>">
                                        <td class="drag-handle" style="cursor:move; text-align:center;">
                                            <i class="bi bi-grip-vertical"></i>
                                        </td>
                                        <td><?= $no++ ?></td>
                                        <td><?= date('d-m-Y', strtotime($row['tanggal'])) ?></td>
                                        <td><?= date('d-m-Y', strtotime($row['tanggal_problem'])) ?></td>
                                        <td>
                                            <?= ($row['plant'] == 1) ? '<span class="badge-plant1">1</span>' : '<span class="badge-plant3">3</span>' ?>
                                        </td>
                                        <td><?= $row['machine'] ?></td>
                                        <td><?= $row['namapart'] ?></td>
                                        <td><?= $row['type'] ?></td>
                                        <td><?= $row['mold_no'] ?></td>
                                        <td><?= number_format($row['target']) ?></td>
                                        <td><?= number_format($row['ok_qty']) ?></td>
                                        <td><?= number_format($row['ng_qty']) ?></td>
                                        <td><?= number_format($row['downtime']) ?></td>
                                        <td><?= $row['standard'] ?></td>
                                        <td><?= $row['actual'] ?></td>
                                        <td><?= $row['efficiency'] ?>%</td>
                                        <td><?= $row['persen_ng'] ?>%</td>
                                        <td><?= $row['downtime_problem'] ?></td>
                                        <td><?= nl2br(htmlspecialchars($row['ng_problem_list'])) ?></td>
                                        <td>
                                            <?= $row['sudah_dibahas'] ? '<span class="badge bg-success">Sudah Dibahas</span>' : '<span class="badge bg-warning">Belum Dibahas</span>' ?>
                                        </td>
                                        <td>
                                            <a href="form_pembahasan_asakai.php?id=<?= $row['id_asakai'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> Bahas
                                            </a>
                                            <a href="bahan_asakai.php?hapus=<?= $row['id_asakai'] ?>&tanggal=<?= urlencode($tanggal) ?>&plant=<?= urlencode($plant) ?>&grup=<?= urlencode($grup) ?>"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Yakin hapus data asakai ini? Data yang sudah dibahas juga akan ikut terhapus.')">
                                                <i class="bi bi-trash"></i> Hapus
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
                        <div class="page-title m-0">
                            <i class="bi bi-tools"></i> Informasi Tambahan Perbaikan (<?= date('d-m-Y', strtotime($tanggal)) ?>)
                        </div>
                        <a href="tambahan_perbaikan.php?tanggal=<?= urlencode($tanggal) ?>&plant=<?= urlencode($plant) ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg"></i> Tambah Info
                        </a>
                    </div>

                    <div class="table-responsive" style="border-radius:12px; overflow:hidden; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                        <table class="table table-bordered align-middle m-0">
                            <thead>
                                <tr>
                                    <th class="th-delivery">No</th>
                                    <th class="th-delivery">Nama Part</th>
                                    <th class="th-delivery">Type</th>
                                    <th class="th-delivery">Part Number</th>
                                    <th class="th-delivery">Problem</th>
                                    <th class="th-delivery">Countermeasure</th>
                                    <th class="th-delivery">Due Date</th>
                                    <th class="th-delivery">Actual</th>
                                    <th class="th-delivery">PIC</th>
                                    <th class="th-delivery">File</th>
                                    <th class="th-delivery">Status</th>
                                    <th class="th-delivery">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $noTambahan = 1;
                                if (mysqli_num_rows($query_tambahan) > 0):
                                    while ($t = mysqli_fetch_assoc($query_tambahan)):
                                ?>
                                        <tr>
                                            <td><?= $noTambahan++ ?></td>
                                            <td><?= htmlspecialchars($t['namapart']) ?></td>
                                            <td><?= htmlspecialchars($t['type']) ?></td>
                                            <td><?= htmlspecialchars($t['partnumber']) ?></td>
                                            <td class="text-start"><?= nl2br(htmlspecialchars($t['problem'])) ?></td>
                                            <td class="text-start"><?= nl2br(htmlspecialchars($t['countermeasure'])) ?></td>
                                            <td><?= !empty($t['due_date']) ? date('d-m-Y', strtotime($t['due_date'])) : '-' ?></td>
                                            <td><?= !empty($t['actual_date']) ? date('d-m-Y', strtotime($t['actual_date'])) : '-' ?></td>
                                            <td><?= htmlspecialchars($t['pic']) ?></td>
                                            <td>
                                                <?php if (!empty($t['file'])): ?>
                                                    <a href="../uploads/asakai_tambahan/<?= htmlspecialchars($t['file']) ?>" target="_blank">Lihat</a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= strtoupper($t['status']) === 'CLOSED' ? '<span class="badge bg-success">CLOSED</span>' : '<span class="badge bg-warning">OPEN</span>' ?>
                                            </td>
                                            <td style="white-space:nowrap;">
                                                <a href="tambahan_perbaikan.php?id=<?= $t['id_tambahan'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <a href="update_countermeasure_tambahan.php?id=<?= $t['id_tambahan'] ?>" class="btn btn-sm btn-outline-success">Update CM</a>
                                                <a href="bahan_asakai.php?hapus_tambahan=<?= $t['id_tambahan'] ?>&tanggal=<?= urlencode($tanggal) ?>&plant=<?= urlencode($plant) ?>&grup=<?= urlencode($grup) ?>"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Yakin hapus informasi tambahan ini?')">Hapus</a>
                                            </td>
                                        </tr>
                                    <?php
                                    endwhile;
                                else:
                                    ?>
                                    <tr>
                                        <td colspan="12" class="text-muted py-4">Tidak ada informasi tambahan pada tanggal ini.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
                        <div class="page-title m-0">
                            <i class="bi bi-truck"></i> Monitoring Stock & Delivery Part (<?= date('d-m-Y', strtotime($tanggal)) ?>)
                        </div>
                    </div>

                    <form action="update_delivery.php" method="POST">
                        <input type="hidden" name="tanggal_filter" value="<?= htmlspecialchars($tanggal) ?>">

                        <div class="table-responsive" style="border-radius:12px; overflow:hidden; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                            <table class="table table-bordered align-middle m-0">
                                <thead>
                                    <tr>
                                        <th class="th-delivery" style="width: 22%;">Nama Part</th>
                                        <th class="th-delivery" style="width: 5%;">Stock<br>Finish Good</th>
                                        <th class="th-delivery" style="width: 5%;">Delivery / hari</th>
                                        <th class="th-delivery" style="width: 5%;">Kemampuan Delivery</th>
                                        <th class="th-delivery" style="width: 18%;">News Delivery</th>
                                        <th class="th-delivery" style="width: 18%;">Countermeasure</th>
                                        <th class="th-delivery" style="width: 8%;">Due Date</th>
                                        <th class="th-delivery" style="width: 8%;">PIC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $i = 0;
                                    if (mysqli_num_rows($query_delivery) > 0) {
                                        while ($del = mysqli_fetch_assoc($query_delivery)) {
                                            $is_stock_minus = ($del['act_stock'] < $del['delivery_per_hari']) ? 'text-danger fw-bold bg-danger-subtle' : '';
                                            $is_sisa_minus = ($del['sisa_stock'] < 0) ? 'text-danger' : '';
                                    ?>
                                            <tr>
                                                <td class="text-start"><?= ($i + 1) . '. ' . htmlspecialchars($del['nama_part']) ?></td>
                                                <td class="<?= $is_stock_minus ?>"><?= $del['act_stock'] ?></td>
                                                <td><?= $del['delivery_per_hari'] ?></td>
                                                <td class="<?= $is_sisa_minus ?>"><strong><?= number_format($del['sisa_stock'], 1) ?></strong></td>
                                                <td class="text-start"><?= htmlspecialchars($del['news_delivery']) ?></td>

                                                <td>
                                                    <input type="hidden" name="data[<?= $i ?>][id_monitor]" value="<?= $del['id_monitor'] ?>">
                                                    <input type="hidden" name="data[<?= $i ?>][id_countermeasure]" value="<?= $del['id_countermeasure'] ?>">

                                                    <input type="text" name="data[<?= $i ?>][countermeasure]" class="table-input"
                                                        value="<?= htmlspecialchars($del['countermeasure'] ?? '') ?>" placeholder="Isi Countermeasure...">
                                                </td>
                                                <td>
                                                    <input type="date" name="data[<?= $i ?>][due_date]" class="table-input p-1"
                                                        value="<?= $del['due_date'] ?? '' ?>">
                                                    <input type="hidden" name="data[<?= $i ?>][old_due_date]" value="<?= $del['due_date'] ?? '' ?>">
                                                </td>
                                                <td>
                                                    <select name="data[<?= $i ?>][pic]" class="table-input">
                                                        <option value="">- PIC -</option>
                                                        <?php
                                                        foreach ($list_dept as $dept) {
                                                            $selected = ($del['pic'] == $dept) ? 'selected' : '';
                                                        ?>
                                                            <option value="<?= htmlspecialchars($dept) ?>" <?= $selected ?>><?= htmlspecialchars($dept) ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php
                                            $i++;
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="8" class="text-muted py-4">Tidak ada data monitoring delivery pada tanggal ini.</td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($i > 0) { ?>
                            <div class="text-end mb-3">
                                <button type="submit" name="submit_update_delivery" class="btn btn-success px-4 shadow-sm">
                                    <i class="bi bi-save"></i> Simpan Countermeasure
                                </button>
                            </div>
                        <?php } ?>
                    </form>

                    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
                        <div class="page-title m-0">
                            <i class="bi bi-box-seam"></i> Stock Casting - Machining (<?= date('d-m-Y', strtotime($tanggal)) ?>)
                        </div>
                    </div>

                    <div class="table-responsive" style="border-radius:12px; overflow:hidden; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                        <table class="table table-bordered align-middle m-0">
                            <thead>
                                <tr>
                                    <th class="th-delivery">No</th>
                                    <th class="th-delivery">Part ( Stock Casting )</th>
                                    <th class="th-delivery">DCM</th>
                                    <th class="th-delivery">Min Stock (3x Deliv)</th>
                                    <th class="th-delivery">Stock PPC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $query_casting = mysqli_query($koneksi, "SELECT
                                        id_casting,
                                        part_number,
                                        nama_part,
                                        dcm,
                                        min_stock,
                                        stock_ppc
                                    FROM stock_casting_monitor
                                    WHERE tanggal = '$tanggal'
                                    ORDER BY id_casting ASC
                                ");

                                if (mysqli_num_rows($query_casting) > 0):
                                    $noCst = 1;
                                    while ($row_cst = mysqli_fetch_assoc($query_casting)):
                                        $ppc = (int)$row_cst['stock_ppc'];
                                        $ppcDisplay = $ppc < 0 ? '(' . number_format(abs($ppc)) . ')' : number_format($ppc);
                                ?>
                                        <tr>
                                            <td><?= $noCst++ ?></td>
                                            <td class="text-start"><?= htmlspecialchars($row_cst['nama_part']) ?></td>
                                            <td><?= number_format($row_cst['dcm']) ?></td>
                                            <td><?= number_format($row_cst['min_stock']) ?></td>
                                            <td style="background:#fff;<?= $ppc < 0 ? ' color:#ff0000; font-weight:700;' : '' ?>"><?= $ppcDisplay ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-muted py-4">Tidak ada data stock casting pada tanggal ini.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        window.onload = function() {
            let inputTanggal = document.getElementById("tanggal");

            if (!inputTanggal.value) {
                let today = new Date().toISOString().split('T')[0];
                inputTanggal.value = today;
            }
        };
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

    <script>
        (function() {
            const rows = document.getElementById('asakaiRows');
            const btnSimpan = document.getElementById('btnSimpanUrutan');
            if (!rows || !btnSimpan) return;

            new Sortable(rows, {
                handle: '.drag-handle',
                animation: 150,
                onSort: function() {
                    btnSimpan.disabled = false;
                }
            });

            btnSimpan.addEventListener('click', function() {
                const ids = Array.from(rows.querySelectorAll('tr')).map(tr => tr.dataset.id);

                btnSimpan.disabled = true;
                btnSimpan.innerHTML = '<i class="bi bi-hourglass-split"></i> Menyimpan...';

                fetch('update_urutan_asakai.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            ids
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        btnSimpan.innerHTML = '<i class="bi bi-save"></i> Simpan Urutan';
                        if (data.success) {
                            const tempNo = rows.querySelectorAll('tr');
                            tempNo.forEach((tr, i) => {
                                tr.children[1].textContent = i + 1;
                            });
                        } else {
                            btnSimpan.disabled = false;
                            alert('Gagal menyimpan urutan: ' + (data.message || 'Unknown error'));
                        }
                    })
                    .catch(() => {
                        btnSimpan.disabled = false;
                        btnSimpan.innerHTML = '<i class="bi bi-save"></i> Simpan Urutan';
                        alert('Gagal menyimpan urutan.');
                    });
            });
        })();
    </script>

</body>

</html>