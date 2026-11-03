<?php
session_start();
include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$tanggal = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');

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

$where = "WHERE tanggal = '$tanggal'";

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
        ) AS sudah_dibahas
    FROM asakai_header ah
    $where
    ORDER BY CAST(ah.machine AS UNSIGNED) ASC
");
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

        tbody tr {
            transition: 0.2s;
        }

        tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        tbody tr:hover {
            background: #e0f2fe;
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

                    <form method="GET" class="row g-2 align-items-end" style="margin-bottom:15px;">

                        <div class="col-md-3">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="tanggal" id="tanggal" class="form-control"
                                value="<?= isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d') ?>">
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

                    <div class="table-responsive" style="border-radius:12px; overflow:hidden;">

                        <table>
                            <tr>
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

                            <?php
                            $no = 1;
                            while ($row = mysqli_fetch_assoc($query)) {

                                $effClass = ($row['efficiency'] >= 85) ? 'efficiency-good' : 'efficiency-low';
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= date('d-m-Y', strtotime($row['tanggal'])) ?></td>
                                    <td><?= date('d-m-Y', strtotime($row['tanggal_problem'])) ?></td>

                                    <td>
                                        <?php if ($row['plant'] == 1) { ?>
                                            <span class="badge-plant1">1</span>
                                        <?php } else { ?>
                                            <span class="badge-plant3">3</span>
                                        <?php } ?>
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
                                    <td><?= $row['ng_problem'] ?></td>

                                    <td>
                                        <?php if ($row['sudah_dibahas']) { ?>
                                            <span class="badge bg-success">Sudah Dibahas</span>
                                        <?php } else { ?>
                                            <span class="badge bg-warning">Belum Dibahas</span>
                                        <?php } ?>
                                    </td>

                                    <td>
                                        <a href="form_pembahasan_asakai.php?id=<?= $row['id_asakai'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> Bahas
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>

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

</body>

</html>