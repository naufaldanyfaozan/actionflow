<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$conditions = [];

$username = $_SESSION['username'];

if (empty($_GET['plant'])) {
    if ($username == 'doni') {
        $_GET['plant'] = '1';
    } elseif ($username == 'aji') {
        $_GET['plant'] = '3';
    }
}

if (!empty($_GET['tanggal'])) {
    $tglInput = $_GET['tanggal'];
    $dateObj = DateTime::createFromFormat('Y-m-d', $tglInput);
    if ($dateObj) {
        $tglDb = $dateObj->format('Y-m-d');
        $conditions[] = "h.tanggal = '$tglDb'";
    }
}

if (!empty($_GET['plant'])) {
    $plantInput = mysqli_real_escape_string($koneksi, $_GET['plant']);
    $conditions[] = "h.plant = '$plantInput'";
}

$whereClause = "";
if (count($conditions) > 0) {
    $whereClause = "WHERE " . implode(" AND ", $conditions);
}

$query = mysqli_query($koneksi, "SELECT 
        h.id_asakai,
        h.tanggal,
        h.plant,
        h.machine,
        h.ok_qty,
        h.ng_qty,
        h.efficiency,
        h.downtime,
        h.target,
        h.standard,
        h.actual,
        h.persen_ng,

        cd.file1 AS file1_downtime,
        cd.file2 AS file2_downtime,
        cd.file3 AS file3_downtime,

        cn.file1 AS file1_ng,
        cn.file2 AS file2_ng,
        cn.file3 AS file3_ng,

        a.kategori,
        CONCAT_WS('<br>', a.analisa1, '.',a.analisa2, '.',a.analisa3, '.',a.analisa4) AS analisa,

        CASE 
            WHEN a.kategori = 'DOWN_TIME' THEN h.downtime_problem
            WHEN a.kategori = 'NG' THEN h.ng_problem
        END AS problem,

        cd.deskripsi AS cm_downtime,
        cd.pic AS pic_downtime,
        cd.due_date AS due_downtime,
        cd.status AS status_downtime,

        cn.deskripsi AS cm_ng,
        cn.pic AS pic_ng,
        cn.due_date AS due_ng,
        cn.status AS status_ng

    FROM asakai_header h
    INNER JOIN asakai_analisa a 
        ON a.id_asakai = h.id_asakai
    LEFT JOIN asakai_countermeasure_downtime cd 
    ON cd.id_asakai = h.id_asakai
    AND a.kategori = 'DOWN_TIME'

LEFT JOIN asakai_countermeasure_ng cn 
    ON cn.id_asakai = h.id_asakai
    AND a.kategori = 'NG'

    $whereClause
    ORDER BY 
        h.tanggal DESC,
        h.machine ASC,
        a.kategori ASC
");

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Summary Asakai</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Summary Asakai" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --bg-color: #f8fafc;
            --text-color: #1e293b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
        }

        .container {
            width: 98%;
            max-width: 98vw;
            margin: 20px auto;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        h2 {
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 24px;
        }

        .filter-section {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            outline: none;
        }

        .table-wrapper {
            max-height: 650px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #cbd5e1;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background-color: #2c6ed7;
        }

        table {
            border-collapse: separate;
            border-spacing: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            border: 1px solid #cbd5e1;
        }

        th,
        td {
            padding: 10px;
            border: 1px solid #e2e8f0;
        }

        tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .group-start td {
            border-top: 2px solid #415166 !important;
        }

        tbody tr:hover {
            background-color: #e2e8f0;
            transition: 0.2s;
        }

        th {
            background-color: #2c6ed7;
            color: white;
            font-weight: 600;
            text-align: center;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
        }

        .status-open {
            background-color: #fef3c7;
            color: #967e08;
        }

        .status-closed {
            background-color: #dcfce7;
            color: #087833;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            transition: all 0.2s;
            margin-right: 4px;
        }

        .btn-edit {
            background-color: #eff6ff;
            color: var(--primary-color);
        }

        .btn-edit:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-update {
            background-color: #ecfdf5;
            color: var(--success-color);
        }

        .btn-update:hover {
            background-color: var(--success-color);
            color: white;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h2>Summary Asakai</h2>

                <form method="GET" class="filter-section">
                    <div style="display: flex; gap: 15px; align-items: center;">
                        <div>
                            <label>Tanggal:</label>
                            <input type="date" name="tanggal"
                                value="<?= isset($_GET['tanggal']) ? htmlspecialchars($_GET['tanggal']) : '' ?>">
                        </div>

                        <div>
                            <label>Plant:</label>
                            <select name="plant" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <option value="">-- Semua Plant --</option>
                                <option value="1" <?= (isset($_GET['plant']) && $_GET['plant'] == '1') ? 'selected' : '' ?>>Plant 1</option>
                                <option value="2" <?= (isset($_GET['plant']) && $_GET['plant'] == '2') ? 'selected' : '' ?>>Plant 2</option>
                                <option value="3" <?= (isset($_GET['plant']) && $_GET['plant'] == '3') ? 'selected' : '' ?>>Plant 3</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-update" style="margin-top: 20px;">Filter Data</button>
                        <a href="list_asakai.php" class="btn btn-edit" style="margin-top: 20px; text-decoration: none;">Reset</a>
                    </div>
                </form>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>ASAKAI DATE</th>
                                <th>PLANT</th>
                                <th>MESIN</th>
                                <th>ACHIEVEMENT</th>
                                <th>CYCLE TIME & EFFICIENCY</th>
                                <th>PROBLEM</th>
                                <th>ANALISIS</th>
                                <th>COUNTERMEASURE</th>
                                <th>PIC</th>
                                <th>DUE DATE</th>
                                <th>LAMPIRAN</th>
                                <th>STATUS</th>
                                <th>ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="asakaiData">

                            <?php if (mysqli_num_rows($query) > 0): ?>

                                <?php
                                $data = [];

                                while ($row = mysqli_fetch_assoc($query)) {
                                    $key = $row['tanggal'] . '|' . $row['machine'];
                                    $data[$key][] = $row;
                                }

                                foreach ($data as $rows):

                                    $rowspanMachine = count($rows);
                                    $firstMachine = true;

                                    $groupProblem = [];
                                    foreach ($rows as $r) {
                                        $key = $r['problem'] . '|' . $r['analisa'];
                                        $groupProblem[$key][] = $r;
                                    }

                                    foreach ($groupProblem as $problems):

                                        $rowspanProblem = count($problems);
                                        $firstProblem = true;

                                        foreach ($problems as $r):

                                            if ($r['kategori'] === 'DOWN_TIME') {
                                                $label  = 'DOWN TIME';
                                                $cm     = $r['cm_downtime'];
                                                $pic    = $r['pic_downtime'];
                                                $rawDue = $r['due_downtime'];
                                                $status = $r['status_downtime'];
                                            } else {
                                                $label  = 'NG';
                                                $cm     = $r['cm_ng'];
                                                $pic    = $r['pic_ng'];
                                                $rawDue = $r['due_ng'];
                                                $status = $r['status_ng'];
                                            }

                                            if (!empty($rawDue) && $rawDue !== '0000-00-00') {
                                                $due = date('d-m-Y', strtotime($rawDue));
                                            } else {
                                                $due = '';
                                            }
                                ?>

                                            <tr class="<?= $firstMachine ? 'group-start' : '' ?>">

                                                <?php if ($firstMachine): ?>

                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <?= date('d-m-Y', strtotime($r['tanggal'])) ?>
                                                    </td>

                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <?= htmlspecialchars($r['plant']) ?>
                                                    </td>

                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <?= htmlspecialchars($r['machine']) ?>
                                                    </td>

                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        T : <?= $r['target'] ?><br>
                                                        OK : <?= $r['ok_qty'] ?><br>
                                                        NG : <?= $r['ng_qty'] ?><br>
                                                        DT : <?= $r['downtime'] ?>
                                                    </td>

                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        Std : <?= $r['standard'] ?><br>
                                                        Act : <?= $r['actual'] ?><br>
                                                        Eff : <?= $r['efficiency'] ?> %<br>
                                                        NG : <?= $r['persen_ng'] ?> %
                                                    </td>

                                                <?php endif; ?>

                                                <?php if ($firstProblem): ?>

                                                    <td rowspan="<?= $rowspanProblem ?>">
                                                        <strong><?= $label ?></strong><br>
                                                        <?= nl2br(htmlspecialchars($r['problem'])) ?>
                                                    </td>

                                                    <td rowspan="<?= $rowspanProblem ?>">
                                                        <?= $r['analisa'] ?>
                                                    </td>

                                                <?php endif; ?>

                                                <td><?= nl2br(htmlspecialchars($cm)) ?></td>

                                                <td><?= htmlspecialchars($pic) ?></td>

                                                <td><?= $due ?></td>

                                                <td>
                                                    <?php
                                                    $hasFile = false;

                                                    if ($r['kategori'] === 'DOWN_TIME') {
                                                        $files = [
                                                            $r['file1_downtime'],
                                                            $r['file2_downtime'],
                                                            $r['file3_downtime']
                                                        ];
                                                    } else {
                                                        $files = [
                                                            $r['file1_ng'],
                                                            $r['file2_ng'],
                                                            $r['file3_ng']
                                                        ];
                                                    }

                                                    foreach ($files as $f) {
                                                        if (!empty($f)) {
                                                            $hasFile = true;
                                                            break;
                                                        }
                                                    }

                                                    if ($hasFile):
                                                    ?>
                                                        <a href="detail_countermeasure_asakai.php?id=<?= $r['id_asakai'] ?>"
                                                            class="btn btn-edit">Cek File</a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    $statusClass = '';

                                                    if (strtolower($status) === 'open') {
                                                        $statusClass = 'status-open';
                                                    } elseif (strtolower($status) === 'closed') {
                                                        $statusClass = 'status-closed';
                                                    }
                                                    ?>
                                                    <span class="status-badge <?= $statusClass ?>">
                                                        <?= htmlspecialchars($status) ?>
                                                    </span>
                                                </td>

                                                <?php if ($firstMachine): ?>

                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <a href="update_asakai.php?id=<?= $r['id_asakai'] ?>" class="btn btn-update">Update</a>
                                                        <!-- <a href="detail_fpm_asakai.php?id=<?= $r['id_asakai'] ?>" class="btn btn-edit">Detail</a> -->
                                                    </td>

                                                <?php endif; ?>

                                            </tr>

                                            <?php
                                            $firstMachine = false;
                                            $firstProblem = false;
                                            ?>

                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="11" style="text-align:center;">
                                        Data tidak ditemukan
                                    </td>
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