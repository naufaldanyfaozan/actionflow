<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Perbaikan FPM</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Bukti Perbaikan FPM" />
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
            --primary-color: #007bff;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --bg-color: #f8f9fa;
            --border-color: #dee2e6;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: var(--bg-color);
            margin: 0;
        }

        .container {
            background: #fff;
            max-width: 700px;
            margin: auto;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        h4 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px;
            margin-bottom: 30px;
        }

        .info-grid label {
            font-weight: 600;
            color: #666;
            align-self: center;
        }

        .info-grid input {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: #fcfcfc;
        }

        .action-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        .action-table th {
            text-align: left;
            padding: 12px;
            background: #f1f3f5;
            font-size: 14px;
        }

        .action-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .btn-action {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-weight: bold;
            font-size: 18px;
            transition: opacity 0.2s;
        }

        .btn-plus {
            background-color: var(--success-color);
        }

        .btn-minus {
            background-color: var(--danger-color);
            margin-left: 5px;
        }

        .btn-action:hover {
            opacity: 0.8;
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
        }

        .action-label {
            font-weight: bold;
            display: block;
        }

        .action-sub {
            font-size: 12px;
            color: #888;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h4>Upload Bukti Perbaikan</h4>

                <form action="/submit-path" method="POST" enctype="multipart/form-data">
                    <div class="info-grid">
                        <label>No Reg FPM</label><input type="text" name="no_reg" readonly>
                        <label>Nama Part</label><input type="text" name="nama_part" readonly>
                        <label>Model/Type</label><input type="text" name="type" readonly>
                        <label>Mold #</label><input type="text" name="mold" readonly>
                        <label>Mesin</label><input type="text" name="mesin" readonly>
                        <label>Jenis Masalah</label><input type="text" name="jenis_masalah" readonly>
                    </div>

                    <table class="action-table" id="tableCorrective">
                        <thead>
                            <tr>
                                <th width="40%">Corrective Action</th>
                                <th width="45%">Upload Doc</th>
                                <th width="15%">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="action-sub">Option 1</span></td>
                                <td><input type="file" name="corrective_files[]"></td>
                                <td><button type="button" class="btn-action btn-plus" onclick="addRow('tableCorrective', 'corrective_files[]')">+</button></td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="action-table" id="tablePreventive">
                        <thead>
                            <tr>
                                <th width="40%">Preventive Action</th>
                                <th width="45%">Upload Doc</th>
                                <th width="15%">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="action-sub">Option 1</span></td>
                                <td><input type="file" name="preventive_files[]"></td>
                                <td><button type="button" class="btn-action btn-plus" onclick="addRow('tablePreventive', 'preventive_files[]')">+</button></td>
                            </tr>
                        </tbody>
                    </table>

                    <button type="submit" class="btn-submit btn-sm">Submit Data</button>
                </form>
            </div>
        </main>
    </div>

    <script>
        function addRow(tableId, inputName) {
            const table = document.getElementById(tableId).getElementsByTagName('tbody')[0];
            const newRow = table.insertRow();

            const rowCount = table.rows.length;

            const cell1 = newRow.insertCell(0);
            cell1.innerHTML = `<span class="action-sub">Option ${rowCount}</span>`;

            const cell2 = newRow.insertCell(1);
            cell2.innerHTML = `<input type="file" name="${inputName}">`;

            const cell3 = newRow.insertCell(2);
            cell3.innerHTML = `
            <button type="button" class="btn-action btn-plus" onclick="addRow('${tableId}', '${inputName}')">+</button>
            <button type="button" class="btn-action btn-minus" onclick="removeRow(this)">-</button>
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