<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';

$id = (int) $_GET['id'];

// Ambil semua countermeasure downtime
$qDowntime = mysqli_query($koneksi, "
    SELECT * FROM asakai_countermeasure_downtime
    WHERE id_asakai = '$id'
");

// Ambil semua countermeasure NG
$qNG = mysqli_query($koneksi, "
    SELECT * FROM asakai_countermeasure_ng
    WHERE id_asakai = '$id'
");
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Detail Countermeasure</title>

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
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
        }

        .container {
            max-width: 1100px;
            margin: auto;
            padding: 30px 15px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 30px;
            text-align: center;
            color: #1e293b;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 8px;
            background: #e2e8f0;
            color: #334155;
        }

        .card-counter {
            background: #ffffff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            transition: 0.2s ease-in-out;
        }

        .card-counter:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.08);
        }

        .grid-info {
            display: grid;
            grid-template-columns: 150px 1fr;
            row-gap: 10px;
            column-gap: 15px;
            font-size: 14px;
        }

        .label {
            font-weight: 600;
            color: #64748b;
        }

        .value {
            color: #1e293b;
        }

        .description-box {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.6;
        }

        .status-badge {
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-open {
            background: #fef9c3;
            color: #854d0e;
        }

        .status-closed {
            background: #dcfce7;
            color: #166534;
        }

        .file-wrapper {
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px dashed #cbd5e1;
        }

        .file-link {
            display: inline-block;
            margin: 5px 8px 5px 0;
            padding: 7px 14px;
            font-size: 13px;
            border-radius: 8px;
            text-decoration: none;
            background: #e0f2fe;
            color: #075985;
            font-weight: 500;
            transition: 0.2s;
        }

        .file-link:hover {
            background: #bae6fd;
        }

        .empty-text {
            font-size: 13px;
            color: #94a3b8;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .grid-info {
                grid-template-columns: 1fr;
            }
        }

        .file-box {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
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

                <h4 class="mb-4">Detail Countermeasure Asakai</h4>

                <!-- ===================== DOWNTIME ===================== -->

                <h5 class="mb-3">Countermeasure Down Time</h5>

                <?php if (mysqli_num_rows($qDowntime) == 0): ?>

                    <div class="alert alert-secondary">
                        Tidak ada data countermeasure downtime
                    </div>

                <?php else: ?>

                    <?php while ($d = mysqli_fetch_assoc($qDowntime)): ?>

                        <!-- ====== TABEL DATA DOWNTIME ====== -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                Countermeasure Down Time
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered mb-0">
                                    <tr>
                                        <th width="20%">Deskripsi</th>
                                        <td><?= nl2br(htmlspecialchars($d['deskripsi'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Due Date</th>
                                        <td>
                                            <?= $d['due_date'] ? date('d-m-Y', strtotime($d['due_date'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Actual Date</th>
                                        <td>
                                            <?= $d['actual_date'] ? date('d-m-Y', strtotime($d['actual_date'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>PIC</th>
                                        <td><?= htmlspecialchars($d['pic']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status</th>
                                        <td>
                                            <span class="badge bg-<?= $d['status'] == 'CLOSED' ? 'success' : 'warning' ?>">
                                                <?= $d['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Note</th>
                                        <td><?= !empty($d['note_downtime']) ? nl2br(htmlspecialchars($d['note_downtime'])) : '-' ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- ====== LAMPIRAN DOWNTIME ====== -->
                        <?php for ($i = 1; $i <= 3; $i++): ?>
                            <?php if (!empty($d["file$i"])): ?>

                                <div class="file-box">

                                    <div class="file-title">
                                        Lampiran <?= $i ?>
                                    </div>

                                    <?php
                                    $ext = strtolower(pathinfo($d["file$i"], PATHINFO_EXTENSION));
                                    $file = "../uploads/asakai/" . $d["file$i"];

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
                        <?php endfor; ?>

                    <?php endwhile; ?>

                <?php endif; ?>


                <!-- ===================== NG ===================== -->

                <h5 class="mt-5 mb-3">Countermeasure NG</h5>

                <?php if (mysqli_num_rows($qNG) == 0): ?>

                    <div class="alert alert-secondary">
                        Tidak ada data countermeasure NG
                    </div>

                <?php else: ?>

                    <?php while ($n = mysqli_fetch_assoc($qNG)): ?>

                        <!-- ====== TABEL DATA NG ====== -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                Countermeasure NG
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered mb-0">
                                    <tr>
                                        <th width="20%">Deskripsi</th>
                                        <td><?= nl2br(htmlspecialchars($n['deskripsi'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Due Date</th>
                                        <td>
                                            <?= $n['due_date'] ? date('d-m-Y', strtotime($n['due_date'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Actual Date</th>
                                        <td>
                                            <?= $n['actual_date'] ? date('d-m-Y', strtotime($n['actual_date'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>PIC</th>
                                        <td><?= htmlspecialchars($n['pic']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status</th>
                                        <td>
                                            <span class="badge bg-<?= $n['status'] == 'CLOSED' ? 'success' : 'warning' ?>">
                                                <?= $n['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Note</th>
                                        <td><?= !empty($n['note_ng']) ? nl2br(htmlspecialchars($n['note_ng'])) : '-' ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- ====== LAMPIRAN NG ====== -->
                        <?php for ($i = 1; $i <= 3; $i++): ?>
                            <?php if (!empty($n["file$i"])): ?>

                                <div class="file-box">

                                    <div class="file-title">
                                        Lampiran <?= $i ?>
                                    </div>

                                    <?php
                                    $ext = strtolower(pathinfo($n["file$i"], PATHINFO_EXTENSION));
                                    $file = "../uploads/asakai/" . $n["file$i"];

                                    if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                                        echo "<img src='$file'>";
                                    } elseif ($ext == 'pdf') {
                                        echo "<iframe src='$file'></iframe>";
                                    } else {
                                        echo "<a href='$file' target='_blank' class='btn btn-success'>Download File</a>";
                                    }
                                    ?>

                                </div>

                            <?php endif; ?>
                        <?php endfor; ?>

                    <?php endwhile; ?>

                <?php endif; ?>

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