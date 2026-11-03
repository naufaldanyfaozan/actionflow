<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';
?>

<!DOCTYPE html>
<html>

<head>
    <title>Master Machine</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container mt-5">
                <h3 class="mb-4">Master Machine</h3>

                <a href="tambah_mesin.php" class="btn btn-primary mb-3">+ Tambah Data</a>

                <div class="card shadow-sm">
                    <div class="card-body">

                        <form method="GET" class="row mb-3">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control"
                                    placeholder="Cari nama mesin..."
                                    value="<?= isset($_GET['search']) ? $_GET['search'] : '' ?>">
                            </div>

                            <div class="col-md-3">
                                <select name="plant" class="form-select">
                                    <option value="">-- Semua Plant --</option>
                                    <option value="1" <?= (isset($_GET['plant']) && $_GET['plant'] == '1') ? 'selected' : '' ?>>Plant 1</option>
                                    <option value="2" <?= (isset($_GET['plant']) && $_GET['plant'] == '2') ? 'selected' : '' ?>>Plant 2</option>
                                    <option value="3" <?= (isset($_GET['plant']) && $_GET['plant'] == '3') ? 'selected' : '' ?>>Plant 3</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="master_mesin.php" class="btn btn-secondary">
                                    Reset
                                </a>
                            </div>
                        </form>

                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>No</th>
                                    <th>Nama Mesin</th>
                                    <th>Plant</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $where = [];

                                if (!empty($_GET['search'])) {
                                    $search = mysqli_real_escape_string($koneksi, $_GET['search']);
                                    $where[] = "nama_mesin LIKE '%$search%'";
                                }

                                if (!empty($_GET['plant'])) {
                                    $plant = mysqli_real_escape_string($koneksi, $_GET['plant']);
                                    $where[] = "plant = '$plant'";
                                }

                                $whereSQL = "";
                                if (count($where) > 0) {
                                    $whereSQL = "WHERE " . implode(" AND ", $where);
                                }

                                $query = "SELECT * FROM master_machine $whereSQL ORDER BY id DESC";
                                $data = mysqli_query($koneksi, $query);
                                while ($d = mysqli_fetch_array($data)) {
                                ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td><?= $d['nama_mesin']; ?></td>
                                        <td><?= $d['plant']; ?></td>
                                        <td>
                                            <a href="edit_mesin.php?id=<?= $d['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                            <a href="hapus_mesin.php?id=<?= $d['id']; ?>" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Yakin hapus data?')">Hapus</a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
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