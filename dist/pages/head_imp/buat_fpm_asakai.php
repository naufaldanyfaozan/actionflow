<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

$queryDept = mysqli_query(
    $koneksi,
    "SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC"
);

$deptList = [];
while ($d = mysqli_fetch_assoc($queryDept)) {
    $deptList[] = $d;
}

$queryPart = mysqli_query($koneksi, "SELECT namapart, type, partnumber FROM master_part ORDER BY namapart ASC");

$queryMachine = mysqli_query($koneksi, "SELECT nama_mesin FROM master_machine ORDER BY nama_mesin ASC");

$queryJenisMasalah = mysqli_query($koneksi, "SELECT masalah FROM jenis_masalah ORDER BY masalah ASC");

?>

<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Create Asakai</title>

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
            width: auto;
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

        .ng-problem-item {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
        }

        .ng-problem-item input {
            flex: 1;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h2>FORM ASAKAI</h2>

                <form method="POST" action="proses_asakai_simpan.php">

                    <div class="grid-header">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="text" id="date" name="tanggal" class="form-control">

                            <input type="hidden" name="submit_asakai" value="1">
                        </div>

                        <div class="form-group">
                            <label>Machine <span style="color:#dc2626">*</span></label>
                            <select name="machine" class="form-control" required>
                                <option value="">Pilih</option>
                                <?php while ($m = mysqli_fetch_assoc($queryMachine)) { ?>
                                    <option value="<?= htmlspecialchars($m['nama_mesin']); ?>">
                                        <?= htmlspecialchars($m['nama_mesin']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Nama Part <span style="color:#dc2626">*</span></label>
                            <select class="form-control" name="nama_part" required>
                                <option value="">Pilih</option>
                                <?php while ($p = mysqli_fetch_assoc($queryPart)) { ?>
                                    <option
                                        value="<?= htmlspecialchars($p['partnumber']); ?>">
                                        <?= htmlspecialchars($p['namapart']); ?> -
                                        <?= htmlspecialchars($p['type']); ?>
                                        (<?= htmlspecialchars($p['partnumber']); ?>)
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-group"><label>Mold #</label><input type="text" name="mold_no"></div>
                    </div>

                    <div class="grid-header">
                        <div class="form-group"><label>Target</label><input type="text" name="target"></div>
                        <div class="form-group"><label>OK</label><input type="text" name="ok_qty"></div>
                        <div class="form-group"><label>NG</label><input type="text" name="ng_qty"></div>
                        <div class="form-group"><label>Down Time</label><input type="text" name="downtime"></div>
                    </div>

                    <div class="grid-header">
                        <div class="form-group"><label>Standard</label><input type="text" name="standard"></div>
                        <div class="form-group"><label>Actual</label><input type="text" name="actual"></div>
                        <div class="form-group"><label>% Efficiency</label><input type="text" name="efficiency"></div>
                        <div class="form-group"><label>% NG</label><input type="text" name="persen_ng"></div>
                    </div>

                    <h3>Problem Description</h3>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label>DownTime Problem</label>
                        <input
                            type="text"
                            name="downtime_problem"
                            list="list_jenis_masalah"
                            placeholder="Ketik atau pilih masalah downtime">
                    </div>

                    <div class="form-group">
                        <label>NG Problem</label>
                        <div id="ngProblemList">
                            <div class="ng-problem-item">
                                <input
                                    type="text"
                                    name="ng_problem[]"
                                    list="list_jenis_masalah"
                                    placeholder="Ketik atau pilih masalah NG">
                                <button type="button" class="btn-action btn-add btn-sm" onclick="addNgProblem()">+</button>
                            </div>
                        </div>
                    </div>

                    <datalist id="list_jenis_masalah">
                        <?php while ($jm = mysqli_fetch_assoc($queryJenisMasalah)) { ?>
                            <option value="<?= htmlspecialchars($jm['masalah']); ?>">
                            <?php } ?>
                    </datalist>

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
                                    <td><input type="text" style="width:100%" name="analisa_down[1]"></td>
                                    <td><input type="text" style="width:100%" name="analisa_down[2]"></td>
                                    <td><input type="text" style="width:100%" name="analisa_down[3]"></td>
                                    <td><input type="text" style="width:100%" name="analisa_down[4]"></td>
                                </tr>
                                <tr>
                                    <td><b>NG</b></td>
                                    <td><input type="text" style="width:100%" name="analisa_ng[1]"></td>
                                    <td><input type="text" style="width:100%" name="analisa_ng[2]"></td>
                                    <td><input type="text" style="width:100%" name="analisa_ng[3]"></td>
                                    <td><input type="text" style="width:100%" name="analisa_ng[4]"></td>
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
                                    <th width="120">PIC <span style="color:#dc2626">*</span></th>
                                    <th width="150">Due Date</th>
                                    <th width="120">Status</th>
                                    <th width="100">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><input type="text" name="cm_down_desc[]" style="width:95%"></td>
                                    <td>
                                        <select name="cm_down_pic[]" class="form-control" required>
                                            <option value="">Pilih</option>
                                            <?php foreach ($deptList as $d) { ?>
                                                <option value="<?= htmlspecialchars($d['nama_dept']); ?>">
                                                    <?= htmlspecialchars($d['nama_dept']); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                    <td><input name="cm_down_due[]" type="date" style="width:90%"></td>
                                    <td>
                                        <select name="cm_down_status[]" class="form-control">
                                            <option value="">Pilih</option>
                                            <option value="OPEN">OPEN</option>
                                            <option value="CLOSED">CLOSED</option>
                                        </select>
                                    </td>

                                    <td>
                                        <button type="button" class="btn-action btn-add btn-sm" onclick="addRow('tableDownTime')">+</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin: 20px 0 8px 0;">Table: NG</p>
                    <div class="table-container">
                        <table id="tableNG">
                            <thead>
                                <tr>
                                    <th>Deskripsi</th>
                                    <th width="120">PIC <span style="color:#dc2626">*</span></th>
                                    <th width="150">Due Date</th>
                                    <th width="120">Status</th>
                                    <th width="100">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><input type="text" style="width:95%" name="cm_ng_desc[]"></td>

                                    <td>
                                        <select name="cm_ng_pic[]" class="form-control" required>
                                            <option value="">Pilih</option>
                                            <?php foreach ($deptList as $d) { ?>
                                                <option value="<?= htmlspecialchars($d['nama_dept']); ?>">
                                                    <?= htmlspecialchars($d['nama_dept']); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </td>

                                    <td><input type="date" name="cm_ng_due[]" style="width:90%"></td>

                                    <td>
                                        <select name="cm_ng_status[]" class="form-control">
                                            <option value="">Pilih</option>
                                            <option value="OPEN">OPEN</option>
                                            <option value="CLOSED">CLOSED</option>
                                        </select>
                                    </td>

                                    <td>
                                        <button type="button" class="btn-action btn-add btn-sm" onclick="addRow('tableNG')">+</button>
                                    </td>

                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button class="btn-submit" type="submit">SUBMIT DATA</button>

                </form>

            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        flatpickr("#date", {
            dateFormat: "d/m/Y"
        });
    </script>

    <script>
        function addRow(tableId) {
            const tableBody = document
                .getElementById(tableId)
                .getElementsByTagName('tbody')[0];

            const templateRow = tableBody.rows[0];
            const newRow = templateRow.cloneNode(true);

            newRow.querySelectorAll('input').forEach(input => {
                input.value = '';
            });

            newRow.querySelectorAll('select').forEach(select => {
                select.selectedIndex = 0;
            });

            const actionCell = newRow.cells[newRow.cells.length - 1];
            actionCell.innerHTML = `
                <button type="button" class="btn-action btn-add btn-sm"
                    onclick="addRow('${tableId}')">+</button>
                <button type="button" class="btn-action btn-remove btn-sm"
                    onclick="removeRow(this)">-</button>
            `;

            tableBody.appendChild(newRow);
        }

        function removeRow(btn) {
            const row = btn.closest('tr');
            const tbody = row.parentNode;

            if (tbody.rows.length > 1) {
                row.remove();
            }
        }

        function addNgProblem() {
            const container = document.getElementById('ngProblemList');
            const div = document.createElement('div');
            div.className = 'ng-problem-item';
            div.innerHTML = `
                <input type="text" name="ng_problem[]" list="list_jenis_masalah" placeholder="Ketik atau pilih masalah NG">
                <button type="button" class="btn-action btn-remove btn-sm" onclick="this.closest('.ng-problem-item').remove()">-</button>
            `;
            container.appendChild(div);
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