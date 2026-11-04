<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';

$id_fpm = (int) ($_GET['id'] ?? 0);

$qCorrective = mysqli_query($koneksi, "SELECT * 
    FROM fpm_corrective_action 
    WHERE id_fpm='$id_fpm'
");

$qPreventive = mysqli_query($koneksi, "SELECT * 
    FROM fpm_preventive_action 
    WHERE id_fpm='$id_fpm'
");

$qFile = mysqli_query($koneksi, "SELECT 'Corrective Action' AS sumber, file 
FROM fpm_corrective_action 
WHERE id_fpm='$id_fpm' AND file IS NOT NULL AND file!=''

UNION ALL

SELECT 'Preventive Action' AS sumber, file 
FROM fpm_preventive_action 
WHERE id_fpm='$id_fpm' AND file IS NOT NULL AND file!=''

");

?>
<!DOCTYPE html>
<html>

<head>

    <title>Lampiran FPM</title>

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="List FPM Down Time" />
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
            background: #f4f6f9;
            font-family: 'Inter', sans-serif;
        }

        .card {
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .file-box {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 20px;
            background: #fff;
        }

        .file-title {
            font-weight: 600;
            margin-bottom: 10px;
        }

        iframe {
            width: 100%;
            height: 500px;
            border: none;
        }

        img {
            max-width: 100%;
            border-radius: 8px;
        }
    </style>

</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container mt-4">

                <div class="card p-4">

                    <h4 class="mb-4">Lampiran FPM</h4>

                    <?php if (mysqli_num_rows($qCorrective) == 0): ?>

                        <div class="alert alert-secondary">
                            Tidak ada data corrective
                        </div>

                    <?php else: ?>

                        <?php while ($c = mysqli_fetch_assoc($qCorrective)): ?>

                            <!-- ====== TABEL DATA CORRECTIVE ====== -->
                            <div class="card mb-4">
                                <div class="card-header bg-primary text-white">
                                    Corrective Action
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered mb-0">
                                        <tr>
                                            <th width="20%">Tindakan</th>
                                            <td><?= $c['tindakan'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Due Date</th>
                                            <td><?= $c['due_date'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Actual Date</th>
                                            <td><?= $c['actual_date'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>PIC</th>
                                            <td><?= $c['pic'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status</th>
                                            <td>
                                                <span class="badge bg-<?= $c['status'] == 'CLOSED' ? 'success' : 'warning' ?>">
                                                    <?= $c['status'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- ====== LAMPIRAN ====== -->
                            <?php if (!empty($c['file'])): ?>

                                <div class="file-box">

                                    <div class="file-title">
                                        Lampiran Corrective
                                    </div>

                                    <?php
                                    $ext = strtolower(pathinfo($c['file'], PATHINFO_EXTENSION));
                                    $file = "../uploads/fpm_dt/" . $c['file'];

                                    if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                                        echo "<img src='$file'>";
                                    } elseif ($ext == 'pdf') {
                                        echo "<iframe src='$file'></iframe>";
                                    } else {
                                        echo "<a href='$file' target='_blank' class='btn btn-primary'>Download File</a>";
                                    }
                                    ?>

                                </div>

                            <?php endif; ?>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    <!-- ================= PREVENTIVE ACTION ================= -->
                    <?php if (mysqli_num_rows($qPreventive) == 0): ?>

                        <div class="alert alert-secondary">
                            Tidak ada data preventive
                        </div>

                    <?php else: ?>

                        <?php while ($p = mysqli_fetch_assoc($qPreventive)): ?>

                            <!-- ====== TABEL DATA PREVENTIVE ====== -->
                            <div class="card mb-4">
                                <div class="card-header bg-success text-white">
                                    Preventive Action
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered mb-0">
                                        <tr>
                                            <th width="20%">Tindakan</th>
                                            <td><?= $p['tindakan'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Due Date</th>
                                            <td><?= $p['due_date'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>PIC</th>
                                            <td><?= $p['pic'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status</th>
                                            <td>
                                                <span class="badge bg-<?= $p['status'] == 'CLOSED' ? 'success' : 'warning' ?>">
                                                    <?= $p['status'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- ====== LAMPIRAN PREVENTIVE ====== -->
                            <?php if (!empty($p['file'])): ?>

                                <div class="file-box">

                                    <div class="file-title">
                                        Lampiran Preventive
                                    </div>

                                    <?php
                                    $ext = strtolower(pathinfo($p['file'], PATHINFO_EXTENSION));
                                    $file = "../uploads/fpm_dt/" . $p['file'];

                                    if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                                        echo "<img src='$file'>";
                                    } elseif ($ext == 'pdf') {
                                        echo "<iframe src='$file'></iframe>";
                                    } else {
                                        echo "<a href='$file' target='_blank' class='btn btn-primary'>Download File</a>";
                                    }
                                    ?>

                                </div>

                            <?php endif; ?>

                        <?php endwhile; ?>

                    <?php endif; ?>

                </div>

            </div>

        </main>
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