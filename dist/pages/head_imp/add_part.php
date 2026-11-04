<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';

$id = (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) ? $_GET['id'] : '';
$namapart = $partnumber = $type = $customer = "";

if ($id != "") {
    $query = mysqli_query($koneksi, "SELECT * FROM master_part WHERE id=$id");
    $data = mysqli_fetch_assoc($query);

    $namapart   = $data['namapart'];
    $partnumber = $data['partnumber'];
    $type       = $data['type'];
    $customer   = $data['customer'];
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

</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main p-4">

            <h2><?= $id ? "Edit" : "Tambah" ?> Part</h2>

            <form method="POST" action="update_part.php">
                <input type="hidden" name="id" value="<?= $id ?>">

                <label>Nama Part <span style="color:#dc2626">*</span></label><br>
                <input type="text" name="namapart" value="<?= htmlspecialchars($namapart) ?>" required>
                <br><br>

                <label>Part Number <span style="color:#dc2626">*</span></label><br>
                <input type="text" name="partnumber" value="<?= htmlspecialchars($partnumber) ?>" required>
                <br><br>

                <label>Type <span style="color:#dc2626">*</span></label><br>
                <input type="text" name="type" value="<?= htmlspecialchars($type) ?>" required>
                <br><br>

                <label>Customer <span style="color:#dc2626">*</span></label><br>
                <input type="text" name="customer" value="<?= htmlspecialchars($customer) ?>" required>
                <br><br>

                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                
            </form>
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