<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Form Input Asakai</title>

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
                <h2>FORM ASAKAI</h2>

                <div class="grid-header">
                    <div class="form-group"><label>No ID</label><input type="text" placeholder="..."></div>
                    <div class="form-group"><label>Date</label><input type="date"></div>
                    <div class="form-group">
                        <label>Nama Part</label>
                        <select class="form-control" name="nama_part">
                            <option value="">-- Pilih Nama Part --</option>
                            <option value="Part A">Part A</option>
                            <option value="Part B">Part B</option>
                            <option value="Part C">Part C</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Mold #</label><input type="text"></div>
                </div>

                <div class="grid-header">
                    <div class="form-group"><label>Target</label><input type="text"></div>
                    <div class="form-group"><label>OK</label><input type="text"></div>
                    <div class="form-group"><label>NG</label><input type="text"></div>
                    <div class="form-group"><label>Down Time</label><input type="text"></div>
                </div>

                <div class="grid-header">
                    <div class="form-group"><label>Standard</label><input type="text"></div>
                    <div class="form-group"><label>Actual</label><input type="text"></div>
                    <div class="form-group"><label>% Efficiency</label><input type="text"></div>
                    <div class="form-group"><label>% NG</label><input type="text"></div>
                </div>

                <h3>Problem Description</h3>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>DownTime Problem</label>
                    <textarea placeholder="Jelaskan masalah downtime..."></textarea>
                </div>
                <div class="form-group">
                    <label>NG Problem</label>
                    <textarea placeholder="Jelaskan masalah NG..."></textarea>
                </div>

                <h3>Analisa Masalah</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="150">Kategori</th>
                                <th>Analisa 1</th>
                                <th>Analisa 2</th>
                                <th>Analisa 3</th>
                                <th>Analisa 4</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><b>Down Time</b></td>
                                <td><input type="text" style="width:100%"></td>
                                <td><input type="text" style="width:100%"></td>
                                <td><input type="text" style="width:100%"></td>
                                <td><input type="text" style="width:100%"></td>
                            </tr>
                            <tr>
                                <td><b>NG</b></td>
                                <td><input type="text" style="width:100%"></td>
                                <td><input type="text" style="width:100%"></td>
                                <td><input type="text" style="width:100%"></td>
                                <td><input type="text" style="width:100%"></td>
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
                                <th width="120">Status</th>
                                <th width="100">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" style="width:95%"></td>
                                <td><input type="text" style="width:90%"></td>
                                <td><input type="date" style="width:90%"></td>
                                <td><input type="text" style="width:90%"></td>
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
                                <th width="120">PIC</th>
                                <th width="150">Due Date</th>
                                <th width="120">Status</th>
                                <th width="100">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" style="width:95%"></td>
                                <td><input type="text" style="width:90%"></td>
                                <td><input type="date" style="width:90%"></td>
                                <td><input type="text" style="width:90%"></td>
                                <td>
                                    <button type="button" class="btn-action btn-add btn-sm" onclick="addRow('tableNG')">+</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button class="btn-submit">SUBMIT DATA</button>
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