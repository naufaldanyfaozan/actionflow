<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

?>

<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Create FPM Down Time</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Create FPM Down Time" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f3f6fb;
        }

        .form-wrapper {
            background: #ffffff;
            max-width: 720px;
            margin: auto;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
        }

        .form-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2b2b2b;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td {
            padding: 10px;
            vertical-align: middle;
        }

        .label {
            width: 35%;
            font-weight: 500;
            color: #333;
        }

        input,
        select {
            width: 100%;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #cfd6e0;
            font-size: 14px;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: #0f6cbd;
        }

        .auto {
            background: #fff44f;
        }

        .option {
            background: #aee4ff;
        }

        .manual {
            background: #f8a14a;
        }

        .option-manual {
            background: #7be495;
        }

        .datepick {
            background: #cfcfcf;
        }

        .footer {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
        }

        .footer button {
            flex: 1;
            margin: 0 5px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .footer button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-create {
            background: #0f6cbd;
        }

        .btn-check {
            background: #0078d4;
        }

        .btn-approve {
            background: #106ebe;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="form-title">Create Blank FPM</div>

            <table>
                <tr>
                    <td class="label">No Reg FPM</td>
                    <td><input type="text" class="auto" readonly></td>
                </tr>
                <tr>
                    <td class="label">Jenis FPM</td>
                    <td>
                        <select class="option">
                            <option>Pilih</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="label">Nama Part</td>
                    <td>
                        <select class="option">
                            <option>Pilih</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="label">No Part</td>
                    <td><input type="text" class="auto" readonly></td>
                </tr>
                <tr>
                    <td class="label">Model / Type</td>
                    <td><input type="text" class="auto" readonly></td>
                </tr>
                <tr>
                    <td class="label">Machine</td>
                    <td>
                        <select class="option">
                            <option>Pilih</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="label">Mold #</td>
                    <td><input type="text"></td>
                </tr>
                <tr>
                    <td class="label">Jenis Masalah</td>
                    <td>
                        <select class="option">
                            <option>Pilih/Manual</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="label">Kejadian Berulang Ke</td>
                    <td><input type="number"></td>
                </tr>
                <tr>
                    <td class="label">Shift</td>
                    <td>
                        <select class="option">
                            <option>Pilih</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="label">Total Lost Time</td>
                    <td><input type="text"></td>
                </tr>
                <tr>
                    <td class="label">Dept</td>
                    <td>
                        <select class="option">
                            <option>Pilih</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="label">Tanggal Masalah</td>
                    <td><input type="date" class="datepick"></td>
                </tr>
                <tr>
                    <td class="label">Tanggal Pembuatan FPM</td>
                    <td><input type="date" class="datepick"></td>
                </tr>
                <tr>
                    <td class="label">Tanggal Terbit FPM</td>
                    <td><input type="date" class="datepick"></td>
                </tr>
            </table>

            <div class="footer">
                <button class="btn-create">Dibuat</button>
                
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