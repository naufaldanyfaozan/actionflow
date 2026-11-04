<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
if (($_SESSION['dept'] ?? '') !== 'QA') { header("location:index.php"); exit; }

include '../koneksi.php';

if (isset($_GET['hapus'])) {
    $id = intval($_GET['hapus']);
    mysqli_query($koneksi, "DELETE FROM master_part WHERE id=$id");
    header("Location: master_part.php");
    exit;
}

$where = "";
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!empty($search)) {
    $search_safe = mysqli_real_escape_string($koneksi, $search);
    $where = "WHERE namapart LIKE '%$search_safe%'";
}

$query = "SELECT * FROM master_part $where ORDER BY id ASC";
$data = mysqli_query($koneksi, $query);

?>

<!DOCTYPE html>
<html>

<head>
    <title>Master Part</title>
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

</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar_qa.php'; ?>

        <main class="app-main p-4">

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-semibold">Database Part</h5>

                </div>

                <div class="card-body">

                    <?php
                    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                    ?>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <form method="GET" action="">
                                <div class="input-group">
                                    <input type="text"
                                        name="search"
                                        class="form-control"
                                        placeholder="Cari Nama Part..."
                                        value="<?= htmlspecialchars($search) ?>">

                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search"></i>
                                    </button>

                                    <?php if (!empty($search)) { ?>
                                        <a href="master_part.php" class="btn btn-secondary">
                                            <i class="bi bi-x-lg"></i>
                                        </a>
                                    <?php } ?>
                                </div>
                            </form><br>

                            <a href="add_part.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg"></i> Tambah Part
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <!-- <th style="width:70px;">No</th> -->
                                    <th>Nama Part</th>
                                    <th>Part Number</th>
                                    <th>Type</th>
                                    <th>Customer</th>
                                    <th style="width:150px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = mysqli_fetch_assoc($data)) { ?>
                                    <tr>
                                        <!-- <td class="text-center fw-semibold">
                                            <?= $row['id'] ?>
                                        </td> -->
                                        <td><?= htmlspecialchars($row['namapart']) ?></td>
                                        <td><?= htmlspecialchars($row['partnumber']) ?></td>
                                        <td><?= htmlspecialchars($row['type']) ?></td>
                                        <td><?= htmlspecialchars($row['customer']) ?></td>
                                        <td class="text-center">
                                            <a href="add_part.php?id=<?= $row['id'] ?>"
                                                class="btn btn-outline-warning btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <a href="master_part.php?hapus=<?= $row['id'] ?>"
                                                class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Yakin hapus data?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
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