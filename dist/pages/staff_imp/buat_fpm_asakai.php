<?php
session_start();
include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

if (!isset($_SESSION['username'])) {
    header("location:cek_login.php");
}

$username = $_SESSION['username'];

$qUser = mysqli_query($koneksi, "SELECT * FROM user WHERE username='$username'");
$user = mysqli_fetch_assoc($qUser);

$plantUser = ($user['username'] == 'doni') ? 1 : 3;

// Tentukan plant berdasarkan username
if ($user['username'] == 'doni') {
    $plantUser = 1;
} elseif ($user['username'] == 'aji') {
    $plantUser = 3;
} else {
    $plantUser = 1; // default jika user lain
}

// Ambil machine sesuai plant user
$qMachine = mysqli_query($koneksi, "SELECT * 
    FROM master_machine 
    WHERE plant = '$plantUser'
    ORDER BY nama_mesin ASC
");

$qPart = mysqli_query($koneksi, "SELECT * FROM master_part ORDER BY namapart ASC");

?>

<!DOCTYPE html>
<html>

<head>
    <title>Form Asakai</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Form Input Asakai" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #eef2f7, #e3eaf3);
            margin: 0;
        }

        .container {
            width: 96%;
            margin: 30px auto;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            padding: 25px;
        }

        .title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        .title span {
            background: #007bff;
            color: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            margin-left: 10px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            font-size: 13px;
        }

        th,
        td {
            padding: 8px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }

        tbody tr:hover {
            background: #f8fbff;
        }

        .group1 {
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
        }

        .group2 {
            background: linear-gradient(45deg, #1cc88a, #17a673);
            color: white;
        }

        .group3 {
            background: linear-gradient(45deg, #e74a3b, #be2617);
            color: white;
        }

        input {
            width: 110%;
            padding: 6px 8px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 12px;
            transition: 0.2s;
        }

        input:focus {
            border-color: #4e73df;
            outline: none;
            box-shadow: 0 0 0 2px rgba(78, 115, 223, 0.15);
        }

        .btn-add {
            background: #1cc88a;
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-add:hover {
            background: #17a673;
            transform: scale(1.05);
        }

        .btn-save {
            margin-top: 20px;
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 30px;
            font-weight: 500;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 4px 12px rgba(78, 115, 223, 0.3);
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(78, 115, 223, 0.4);
        }

        .btn-remove {
            background: #e74a3b;
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.2s;
            margin-left: 4px;
        }

        .btn-remove:hover {
            background: #be2617;
            transform: scale(1.05);
        }

        select {
            width: 100%;
            padding: 6px 8px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 12px;
        }

        .ng-problem-list,
        .dt-problem-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .ng-problem-item,
        .dt-problem-item {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .ng-problem-item input,
        .dt-problem-item input {
            width: 100%;
        }

        .btn-add-ng,
        .btn-remove-ng,
        .btn-add-dt,
        .btn-remove-dt {
            flex: 0 0 auto;
            border: none;
            color: white;
            width: 22px;
            height: 22px;
            line-height: 22px;
            padding: 0;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-add-ng,
        .btn-add-dt {
            background: #1cc88a;
        }

        .btn-add-ng:hover,
        .btn-add-dt:hover {
            background: #17a673;
        }

        .btn-remove-ng,
        .btn-remove-dt {
            background: #e74a3b;
        }

        .btn-remove-ng:hover,
        .btn-remove-dt:hover {
            background: #be2617;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="card">

                <div class="title">
                    Form Bahan Asakai
                    <span>Plant <?= $plantUser ?></span>
                </div>

                <form method="POST" action="save_asakai.php">

                    <div class="table-wrapper">
                        <table id="asakaiTable">
                            <thead>
                                <tr>
                                    <th rowspan="2">Tanggal Asakai</th>
                                    <th rowspan="2">Tanggal Problem</th>
                                    <th rowspan="2">Machine <span style="color:#dc2626">*</span></th>
                                    <th rowspan="2">Nama Part <span style="color:#dc2626">*</span></th>
                                    <th rowspan="2">Mold #</th>

                                    <th colspan="4" class="group1">Achievement</th>
                                    <th colspan="4" class="group2">Cycle Time & Efficiency</th>
                                    <th colspan="2" class="group3">Problem</th>

                                    <th rowspan="2">+</th>
                                </tr>

                                <tr>
                                    <th>Target</th>
                                    <th>OK</th>
                                    <th>NG</th>
                                    <th>Down Time</th>

                                    <th>Std</th>
                                    <th>Act</th>
                                    <th>Eff</th>
                                    <th>NG</th>

                                    <th>Down Time</th>
                                    <th>NG</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>

                                    <td>
                                        <input type="date" name="tanggal[]"
                                            value="<?= date('Y-m-d') ?>" readonly>
                                        <input type="hidden" name="plant[]" value="<?= $plantUser ?>">
                                    </td>

                                    <td>
                                        <input type="date" name="tanggal_problem[]" required
                                            max="<?= date('Y-m-d', strtotime('-1 day')) ?>">
                                    </td>

                                    <td>
                                        <select name="machine[]" required>
                                            <option value="">Pilih</option>
                                            <?php
                                            mysqli_data_seek($qMachine, 0);
                                            while ($m = mysqli_fetch_assoc($qMachine)) { ?>
                                                <option value="<?= $m['nama_mesin']; ?>">
                                                    <?= $m['nama_mesin']; ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </td>

                                    <td>
                                        <select name="namapart[]" onchange="setPartData(this)" required>
                                            <option value="">Pilih</option>
                                            <?php
                                            mysqli_data_seek($qPart, 0);
                                            while ($p = mysqli_fetch_assoc($qPart)) { ?>
                                                <option
                                                    value="<?= $p['namapart']; ?>"
                                                    data-partnumber="<?= $p['partnumber']; ?>"
                                                    data-type="<?= $p['type']; ?>">
                                                    <?= $p['namapart']; ?> - <?= $p['type']; ?> - <?= $p['customer']; ?>
                                                </option>
                                            <?php } ?>
                                        </select>

                                        <input type="hidden" name="partnumber[]">
                                        <input type="hidden" name="type[]">
                                    </td>

                                    <td><input type="text" name="mold_no[]"></td>

                                    <td><input type="text" name="target[]"></td>
                                    <td><input type="text" name="ok_qty[]"></td>
                                    <td><input type="text" name="ng_qty[]"></td>
                                    <td><input type="text" name="downtime[]"></td>

                                    <td><input type="text" name="standard[]"></td>
                                    <td><input type="text" name="actual[]"></td>
                                    <td><input type="text" step="0.01" name="efficiency[]"></td>
                                    <td><input type="text" name="ct_ng[]"></td>

                                    <td>
                                        <input type="hidden" name="row_uid[]" class="row-uid" value="0">
                                        <div class="dt-problem-list">
                                            <div class="dt-problem-item">
                                                <input type="text" name="downtime_problem[0][]" placeholder="Down time problem">
                                                <button type="button" class="btn-add-dt" onclick="addDtProblem(this)">+</button>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="ng-problem-list">
                                            <div class="ng-problem-item">
                                                <input type="text" name="ng_problem[0][]" placeholder="NG problem">
                                                <button type="button" class="btn-add-ng" onclick="addNgProblem(this)">+</button>
                                            </div>
                                        </div>
                                    </td>

                                    <td style="white-space:nowrap;">
                                        <button type="button" class="btn-add" onclick="addRow()">+</button>
                                        <button type="button" class="btn-remove" onclick="removeRow(this)">−</button>
                                    </td>

                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" name="save" class="btn-save">
                        Save Data
                    </button>

                </form>

            </div>

        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        let rowUidCounter = 1;

        function addRow() {
            var table = document.getElementById("asakaiTable")
                .getElementsByTagName('tbody')[0];

            var newRow = table.rows[0].cloneNode(true);

            var today = new Date().toISOString().split('T')[0];

            var inputs = newRow.querySelectorAll("input");
            inputs.forEach(input => {

                if (input.name === "tanggal[]") {
                    input.value = today;
                } else if (input.type === "hidden" && input.name === "plant[]") {} else if (input.type === "hidden") {
                    input.value = "";
                } else {
                    input.value = "";
                }
            });

            var selects = newRow.querySelectorAll("select");
            selects.forEach(select => {
                select.selectedIndex = 0;
            });

            var newUid = rowUidCounter++;
            newRow.querySelector(".row-uid").value = newUid;

            var dtList = newRow.querySelector(".dt-problem-list");
            dtList.innerHTML = '<div class="dt-problem-item">' +
                '<input type="text" name="downtime_problem[' + newUid + '][]" placeholder="Down time problem">' +
                '<button type="button" class="btn-add-dt" onclick="addDtProblem(this)">+</button>' +
                '</div>';

            var ngList = newRow.querySelector(".ng-problem-list");
            ngList.innerHTML = '<div class="ng-problem-item">' +
                '<input type="text" name="ng_problem[' + newUid + '][]" placeholder="NG problem">' +
                '<button type="button" class="btn-add-ng" onclick="addNgProblem(this)">+</button>' +
                '</div>';

            table.appendChild(newRow);
        }

        function addDtProblem(button) {
            var item = button.closest(".dt-problem-item");
            var list = item.closest(".dt-problem-list");
            var uid = list.closest("tr").querySelector(".row-uid").value;

            var newItem = document.createElement("div");
            newItem.className = "dt-problem-item";
            newItem.innerHTML = '<input type="text" name="downtime_problem[' + uid + '][]" placeholder="Down time problem">' +
                '<button type="button" class="btn-remove-dt" onclick="removeDtProblem(this)">−</button>';

            list.appendChild(newItem);
        }

        function removeDtProblem(button) {
            var list = button.closest(".dt-problem-list");
            if (list.querySelectorAll(".dt-problem-item").length > 1) {
                button.closest(".dt-problem-item").remove();
            } else {
                button.closest(".dt-problem-item").querySelector("input").value = "";
            }
        }

        function addNgProblem(button) {
            var item = button.closest(".ng-problem-item");
            var list = item.closest(".ng-problem-list");
            var uid = list.closest("tr").querySelector(".row-uid").value;

            var newItem = document.createElement("div");
            newItem.className = "ng-problem-item";
            newItem.innerHTML = '<input type="text" name="ng_problem[' + uid + '][]" placeholder="NG problem">' +
                '<button type="button" class="btn-remove-ng" onclick="removeNgProblem(this)">−</button>';

            list.appendChild(newItem);
        }

        function removeNgProblem(button) {
            var list = button.closest(".ng-problem-list");
            if (list.querySelectorAll(".ng-problem-item").length > 1) {
                button.closest(".ng-problem-item").remove();
            } else {
                button.closest(".ng-problem-item").querySelector("input").value = "";
            }
        }

        function removeRow(button) {
            var row = button.closest("tr");
            var table = document.getElementById("asakaiTable").getElementsByTagName('tbody')[0];

            if (table.rows.length > 1) {
                row.remove();
            } else {
                alert("Minimal harus ada 1 baris data.");
            }
        }

        function setPartData(select) {
            var selected = select.options[select.selectedIndex];

            var partnumber = selected.getAttribute("data-partnumber");
            var type = selected.getAttribute("data-type");

            var row = select.closest("tr");

            row.querySelector("input[name='partnumber[]']").value = partnumber || "";
            row.querySelector("input[name='type[]']").value = type || "";
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