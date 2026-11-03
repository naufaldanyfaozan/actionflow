<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

$id = $_GET['id'] ?? 0;

/* HEADER */
$qHeader = mysqli_query($koneksi, "SELECT * FROM asakai_header WHERE id_asakai='$id'");
$header = mysqli_fetch_assoc($qHeader);

/* ANALISA */
$qAnalisa = mysqli_query($koneksi, "
    SELECT * FROM asakai_analisa 
    WHERE id_asakai='$id'
    ORDER BY kategori ASC
");

$analisaDT = [];
$analisaNG = [];
while ($a = mysqli_fetch_assoc($qAnalisa)) {
    if ($a['kategori'] == 'DOWN_TIME') $analisaDT[] = $a;
    if ($a['kategori'] == 'NG') $analisaNG[] = $a;
}

/* COUNTERMEASURE */
$cmDT = [];
$qCmDT = mysqli_query($koneksi, "
    SELECT * FROM asakai_countermeasure_downtime
    WHERE id_asakai='$id'
    ORDER BY due_date ASC
");
while ($r = mysqli_fetch_assoc($qCmDT)) $cmDT[] = $r;

$cmNG = [];
$qCmNG = mysqli_query($koneksi, "
    SELECT * FROM asakai_countermeasure_ng
    WHERE id_asakai='$id'
    ORDER BY due_date ASC
");
while ($r = mysqli_fetch_assoc($qCmNG)) $cmNG[] = $r;

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Detail Asakai</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Detail Asakai" />
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
            --primary-hover: #1d4ed8;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --danger: #ef4444;
            --success: #22c55e;
        }

        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            background-color: #f4f6f9;
            margin: 0;
        }

        .container {
            background: var(--bg-card);
            max-width: 950px;
            width: 100%;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        h2 {
            text-align: center;
            margin-bottom: 30px;
            font-weight: 600;
            color: var(--text-main);
            border-bottom: 2px solid var(--primary-color);
            display: inline-block;
            padding-bottom: 5px;
            width: 100%;
        }

        h3 {
            font-size: 1rem;
            margin: 25px 0 15px 0;
            color: var(--primary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-left: 4px solid var(--primary-color);
            padding-left: 10px;
        }

        .grid-header {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        input[type="text"],
        input[type="date"],
        textarea {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.2s;
            outline: none;
        }

        input:focus,
        textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea {
            width: 100%;
            resize: vertical;
            min-height: 60px;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th {
            background-color: #f1f5f9;
            color: var(--text-muted);
            font-weight: 600;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 25px;
            height: 25px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: 0.2s;
            margin-right: 5px;
        }

        .btn-add {
            background-color: var(--success);
            color: white;
        }

        .btn-add:hover {
            background-color: #16a34a;
        }

        .btn-remove {
            background-color: var(--danger);
            color: white;
        }

        .btn-remove:hover {
            background-color: #dc2626;
        }

        .btn-submit {
            width: 100%;
            background-color: var(--primary-color);
            color: white;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 30px;
            transition: background 0.2s;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
        }

        @media (max-width: 600px) {
            .grid-header {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h2>DETAIL ASAKAI</h2>

                <div class="grid-header">
                    <input type="hidden" name="id_asakai" value="<?= $id ?>">
                    <div class="form-group"><label>Mesin</label>
                        <input type="text" value="<?= $header['machine'] ?>" readonly>
                    </div>

                    <div class="form-group"><label>Tanggal Asakai</label>
                        <?php
                        $tanggal = $header['tanggal'] ?? '';

                        if (!empty($tanggal) && $tanggal != '0000-00-00') {
                            $tanggalFormat = date('d-m-Y', strtotime($tanggal));
                        } else {
                            $tanggalFormat = '-';
                        }
                        ?>

                        <input type="text" value="<?= $tanggalFormat ?>" readonly>
                    </div>

                    <div class="form-group"><label>Tanggal Problem</label>
                        <?php
                        $tanggal = $header['tanggal_problem'] ?? '';

                        if (!empty($tanggal) && $tanggal != '0000-00-00') {
                            $tanggalFormat = date('d-m-Y', strtotime($tanggal));
                        } else {
                            $tanggalFormat = '-';
                        }
                        ?>

                        <input type="text" value="<?= $tanggalFormat ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Nama Part</label>
                        <input type="text" value="<?= $header['namapart'] ?>" readonly>
                    </div>

                </div>

                <div class="grid-header">

                    <div class="form-group">
                        <label>Type</label>
                        <input type="text" value="<?= $header['type'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Part Number</label>
                        <input type="text" value="<?= $header['partnumber'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Mold #</label>
                        <input type="text" value="<?= $header['mold_no'] ?>" readonly>
                    </div>

                </div>

                <div class="grid-header">
                    <div class="form-group">
                        <label>Target</label>
                        <input type="text" value="<?= $header['target'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>OK</label>
                        <input type="text" value="<?= $header['ok_qty'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>NG</label>
                        <input type="text" value="<?= $header['ng_qty'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Down Time</label>
                        <input type="text" value="<?= $header['downtime'] ?>" readonly>
                    </div>
                </div>

                <div class="grid-header">
                    <div class="form-group">
                        <label>Standard</label>
                        <input type="text" value="<?= $header['standard'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Actual</label>
                        <input type="text" value="<?= $header['actual'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>% Efficiency</label>
                        <input type="text" value="<?= $header['efficiency'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>% NG</label>
                        <input type="text" value="<?= $header['persen_ng'] ?>" readonly>
                    </div>
                </div>

                <h3>Problem Description</h3>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>DownTime Problem</label>
                    <textarea readonly><?= $header['downtime_problem'] ?></textarea>
                </div>
                <div class="form-group">
                    <label>NG Problem</label>
                    <textarea readonly><?= $header['ng_problem'] ?></textarea>
                </div>

                <h3>Analisa Masalah</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="150">Kategori</th>
                                <th>Why 1</th>
                                <th>Why 2</th>
                                <th>Why 3</th>
                                <th>Why 4</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><b>Down Time</b></td>
                                <td><?= $analisaDT[0]['analisa1'] ?? '' ?></td>
                                <td><?= $analisaDT[0]['analisa2'] ?? '' ?></td>
                                <td><?= $analisaDT[0]['analisa3'] ?? '' ?></td>
                                <td><?= $analisaDT[0]['analisa4'] ?? '' ?></td>
                            </tr>
                            <tr>
                                <td><b>NG</b></td>
                                <td><?= $analisaNG[0]['analisa1'] ?? '' ?></td>
                                <td><?= $analisaNG[0]['analisa2'] ?? '' ?></td>
                                <td><?= $analisaNG[0]['analisa3'] ?? '' ?></td>
                                <td><?= $analisaNG[0]['analisa4'] ?? '' ?></td>
                            </tr>
                        </tbody>

                    </table>
                </div>

                <h3>Countermeasure</h3>

                <p style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 8px;">Table: Down Time</p>
                <div class="table-container">
                    <table id="tableDownTime">
                        <thead>
                            <tr>
                                <th>Deskripsi</th>
                                <th width="120">PIC</th>
                                <th width="150">Due Date</th>
                                <th width="150">Actual Date</th>
                                <th width="120">Status</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cmDT as $cm) { ?>
                                <tr>
                                    <td>
                                        <b><?= $cm['deskripsi'] ?></b>

                                        <?php
                                        $files = array_filter([
                                            $cm['file1'],
                                            $cm['file2'],
                                            $cm['file3']
                                        ]);
                                        ?>

                                        <?php if (!empty($files)) { ?>
                                            <div style="margin-top:6px;font-size:0.85rem">

                                                <ul style="margin:4px 0 0 15px;padding:0">
                                                    <?php foreach ($files as $f) { ?>
                                                        <?php
                                                        $filePath = "../uploads/asakai/" . $f;
                                                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                                                        ?>

                                                        <li style="margin-bottom:10px">
                                                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) { ?>

                                                                <!-- PREVIEW GAMBAR -->
                                                                <img src="<?= $filePath ?>"
                                                                    alt="Lampiran"
                                                                    style="max-width:200px;border:1px solid #ddd;border-radius:6px">

                                                            <?php } elseif ($ext === 'pdf') { ?>

                                                                <!-- PREVIEW PDF -->
                                                                <iframe src="<?= $filePath ?>"
                                                                    width="100%"
                                                                    height="300"
                                                                    style="border:1px solid #ddd;border-radius:6px"></iframe>

                                                            <?php } else { ?>

                                                                <!-- FILE LAIN -->
                                                                <a href="<?= $filePath ?>" target="_blank">
                                                                    <i class="bi bi-paperclip"></i> <?= $f ?>
                                                                </a>

                                                            <?php } ?>
                                                        </li>

                                                    <?php } ?>
                                                </ul>
                                            </div>
                                        <?php } ?>
                                    </td>

                                    <td><?= $cm['pic'] ?></td>
                                    <td><?= date('d-m-Y', strtotime($cm['due_date'])) ?></td>
                                    <td>
                                        <?= (!empty($cm['actual_date']) && $cm['actual_date'] != '0000-00-00')
                                            ? date('d-m-Y', strtotime($cm['actual_date']))
                                            : '-' ?>
                                    </td>

                                    <td><?= $cm['status'] ?></td>

                                    <td><?= !empty($cm['note_downtime']) ? nl2br(htmlspecialchars($cm['note_downtime'])) : '-' ?></td>

                                </tr>
                            <?php } ?>
                        </tbody>

                    </table>
                </div>

                <p style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin: 20px 0 8px 0;">Table: NG</p>
                <div class="table-container">
                    <table id="tableNG">
                        <thead>
                            <tr>
                                <th>Deskripsi</th>
                                <th width="120">PIC</th>
                                <th width="150">Due Date</th>
                                <th width="150">Actual Date</th>
                                <th width="120">Status</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cmNG as $cm) { ?>
                                <tr>
                                    <td>
                                        <b><?= $cm['deskripsi'] ?></b>

                                        <?php
                                        $files = array_filter([
                                            $cm['file1'],
                                            $cm['file2'],
                                            $cm['file3']
                                        ]);
                                        ?>

                                        <?php if (!empty($files)) { ?>
                                            <div style="margin-top:6px;font-size:0.85rem">

                                                <ul style="margin:4px 0 0 15px;padding:0">
                                                    <?php foreach ($files as $f) { ?>
                                                        <?php
                                                        $filePath = "../uploads/asakai/" . $f;
                                                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                                                        ?>

                                                        <li style="margin-bottom:10px">
                                                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) { ?>

                                                                <!-- PREVIEW GAMBAR -->
                                                                <img src="<?= $filePath ?>"
                                                                    alt="Lampiran"
                                                                    style="max-width:200px;border:1px solid #ddd;border-radius:6px">

                                                            <?php } elseif ($ext === 'pdf') { ?>

                                                                <!-- PREVIEW PDF -->
                                                                <iframe src="<?= $filePath ?>"
                                                                    width="100%"
                                                                    height="300"
                                                                    style="border:1px solid #ddd;border-radius:6px"></iframe>

                                                            <?php } else { ?>

                                                                <!-- FILE LAIN -->
                                                                <a href="<?= $filePath ?>" target="_blank">
                                                                    <i class="bi bi-paperclip"></i> <?= $f ?>
                                                                </a>

                                                            <?php } ?>
                                                        </li>

                                                    <?php } ?>
                                                </ul>
                                            </div>
                                        <?php } ?>
                                    </td>

                                    <td><?= $cm['pic'] ?></td>
                                    <td><?= date('d-m-Y', strtotime($cm['due_date'])) ?></td>
                                    <td>
                                        <?= (!empty($cm['actual_date']) && $cm['actual_date'] != '0000-00-00')
                                            ? date('d-m-Y', strtotime($cm['actual_date']))
                                            : '-' ?>
                                    </td>

                                    <td><?= $cm['status'] ?></td>

                                    <td><?= !empty($cm['note_ng']) ? nl2br(htmlspecialchars($cm['note_ng'])) : '-' ?></td>

                                </tr>
                            <?php } ?>
                        </tbody>

                    </table>
                </div>

            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        function addRow(tableId) {
            const table = document.getElementById(tableId).getElementsByTagName('tbody')[0];
            const newRow = table.insertRow();

            for (let i = 0; i < 4; i++) {
                let cell = newRow.insertCell(i);
                let inputType = (i === 2) ? "date" : "text";
                cell.innerHTML = `<input type="${inputType}" style="width:${i === 0 ? '95%' : '90%'}">`;
            }

            let actionCell = newRow.insertCell(4);
            actionCell.innerHTML = `
            <button type="button" class="btn-action btn-add" onclick="addRow('${tableId}')">+</button>
            <button type="button" class="btn-action btn-remove" onclick="removeRow(this)">-</button>
        `;
        }

        function removeRow(btn) {
            const row = btn.parentNode.parentNode;
            row.parentNode.removeChild(row);
        }
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