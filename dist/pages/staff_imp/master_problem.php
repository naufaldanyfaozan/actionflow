<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';

$limit = 50;
$page = isset($_GET['halaman']) ? (int)$_GET['halaman'] : 1;
$mulai = ($page > 1) ? ($page * $limit) - $limit : 0;

$search = isset($_GET['search']) ? mysqli_real_escape_string($koneksi, $_GET['search']) : '';

$query_count = "SELECT COUNT(*) AS total FROM jenis_masalah WHERE masalah LIKE '%$search%'";
$result_count = mysqli_query($koneksi, $query_count);
$row_count = mysqli_fetch_assoc($result_count);
$total_data = $row_count['total'];
$total_pages = ceil($total_data / $limit);

$query_data = "SELECT * FROM jenis_masalah WHERE masalah LIKE '%$search%' ORDER BY id DESC LIMIT $mulai, $limit";
$result = mysqli_query($koneksi, $query_data);

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Master Problem</title>

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
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6f9;
        }

        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
            border-radius: 0.75rem;
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, .05);
            padding: 1.25rem;
        }

        .table thead th {
            background-color: #f8f9fa;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            border-top: none;
        }

        .btn-action {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            border-radius: 0.5rem;
        }

        .form-control,
        .form-select {
            border-radius: 0.5rem;
            padding: 0.6rem 1rem;
        }

        .pagination .page-link {
            border-radius: 0.5rem;
            margin: 0 2px;
            border: none;
            color: #333;
        }

        .pagination .active .page-link {
            background-color: #007bff;
            color: white;
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
                            <h3 class="mb-0">Database Jenis Masalah</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="#">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Jenis Masalah</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-plus-circle me-2"></i>Tambah Jenis Masalah</h5>
                        </div>
                        <div class="card-body">
                            <form action="proses_masalah.php" method="POST">
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold text-muted">Deskripsi Masalah <span style="color:#dc2626">*</span></label>
                                        <input type="text" name="masalah" class="form-control" placeholder="Contoh: Mesin Overheat" required>
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button type="submit" name="tambah" class="btn btn-primary w-100 shadow-sm btn-sm" style="height: 46px;">
                                            <i class="bi bi-save me-2"></i>Tambah Data
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-md-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-md-0"><i class="bi bi-table me-2"></i>Daftar Masalah</h5>

                            <form method="GET" action="" class="d-flex gap-2">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari masalah..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                                <?php if ($search != ''): ?>
                                    <a href="master_problem.php" class="btn btn-light btn-sm text-nowrap">Reset</a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Deskripsi Masalah</th>
                                            <th class="text-end pe-4" width="200">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($result) > 0): ?>
                                            <?php while ($row = mysqli_fetch_assoc($result)) { ?>
                                                <tr>
                                                    <td class="fw-medium"><?php echo $row['masalah']; ?></td>
                                                    <td class="text-end pe-4">
                                                        <button type="button"
                                                            class="btn btn-action btn-outline-warning me-1 btn-edit"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalEdit"
                                                            data-id="<?php echo $row['id']; ?>"
                                                            data-masalah="<?php echo htmlspecialchars($row['masalah']); ?>"
                                                            data-kategori="<?php echo htmlspecialchars($row['kategori']); ?>">
                                                            <i class="bi bi-pencil-square"></i> Edit
                                                        </button>
                                                        <a href="proses_masalah.php?hapus=<?php echo $row['id']; ?>" class="btn btn-action btn-outline-danger" onclick="return confirm('Hapus data ini?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                    Data tidak ditemukan.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="card-footer bg-white py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-muted">Menampilkan <?php echo mysqli_num_rows($result); ?> dari <?php echo $total_data; ?> data</span>
                                <?php if ($total_pages > 1): ?>
                                    <nav>
                                        <ul class="pagination pagination-sm mb-0">
                                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?halaman=<?php echo $page - 1; ?>&search=<?php echo $search; ?>"><i class="bi bi-chevron-left"></i></a>
                                            </li>
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?halaman=<?php echo $i; ?>&search=<?php echo $search; ?>"><?php echo $i; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?halaman=<?php echo $page + 1; ?>&search=<?php echo $search; ?>"><i class="bi bi-chevron-right"></i></a>
                                            </li>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <div class="modal fade" id="modalEdit" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Jenis Masalah</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="proses_update_masalah.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit-id">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Deskripsi Masalah <span style="color:#dc2626">*</span></label>
                            <input type="text" name="masalah" id="edit-masalah" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Kategori</label>
                            <input type="text" name="kategori" id="edit-kategori" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="update" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modalEdit = document.getElementById('modalEdit');
            modalEdit.addEventListener('show.bs.modal', function(event) {
                // Tombol yang memicu modal
                const button = event.relatedTarget;

                // Ekstrak info dari atribut data-*
                const id = button.getAttribute('data-id');
                const masalah = button.getAttribute('data-masalah');
                const kategori = button.getAttribute('data-kategori');

                // Update isi input di modal
                modalEdit.querySelector('#edit-id').value = id;
                modalEdit.querySelector('#edit-masalah').value = masalah;
                modalEdit.querySelector('#edit-kategori').value = kategori;
            });
        });
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