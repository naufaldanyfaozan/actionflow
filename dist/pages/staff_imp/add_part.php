<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

$id = $_GET['id'] ?? '';
$namapart = $partnumber = $type = $customer = "";

if ($id != "") {
    // Gunakan prepared statement jika memungkinkan untuk keamanan (SQL Injection)
    $query = mysqli_query($koneksi, "SELECT * FROM master_part WHERE id=$id");
    $data = mysqli_fetch_assoc($query);

    $namapart   = $data['namapart'] ?? '';
    $partnumber = $data['partnumber'] ?? '';
    $type       = $data['type'] ?? '';
    $customer   = $data['customer'] ?? '';
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Form Add Part</title>
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
            font-family: 'Source Sans 3', sans-serif;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
        }

        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border-radius: 8px;
            padding: 0.6rem 1rem;
            border: 1px solid #dee2e6;
        }

        .form-control:focus {
            border-color: #3b71ca;
            box-shadow: 0 0 0 0.2rem rgba(59, 113, 202, 0.15);
        }

        .btn-custom {
            padding: 0.6rem 2rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
    </style>

</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= $id ? "Edit" : "Tambah" ?> Master Part</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="#">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Form Part</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="card mt-2">
                                <form method="POST" action="update_part.php">
                                    <div class="card-body p-4">
                                        <input type="hidden" name="id" value="<?= $id ?>">

                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Nama Part <span style="color:#dc2626">*</span></label>
                                                <input type="text" name="namapart" class="form-control"
                                                    placeholder="Masukkan nama part"
                                                    value="<?= htmlspecialchars($namapart) ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Part Number <span style="color:#dc2626">*</span></label>
                                                <input type="text" name="partnumber" class="form-control"
                                                    placeholder="Contoh: PN-12345"
                                                    value="<?= htmlspecialchars($partnumber) ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Type <span style="color:#dc2626">*</span></label>
                                                <input type="text" name="type" class="form-control"
                                                    placeholder="Tipe material/mesin"
                                                    value="<?= htmlspecialchars($type) ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Customer <span style="color:#dc2626">*</span></label>
                                                <input type="text" name="customer" class="form-control"
                                                    placeholder="Nama perusahaan customer"
                                                    value="<?= htmlspecialchars($customer) ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card-footer bg-white p-4 text-end">
                                        <a href="master_part.php" class="btn btn-light btn-custom me-2">Batal</a>
                                        <button type="submit" class="btn btn-primary btn-custom shadow-sm">
                                            <i class="bi bi-save me-2"></i> Simpan Data
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
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