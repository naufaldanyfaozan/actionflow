<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';
require_once __DIR__ . '/../config/upload_bukti_ui_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$row = null;

if ($id > 0) {
    $qRow = mysqli_query($koneksi, "SELECT * FROM asakai_perbaikan_tambahan WHERE id_tambahan = $id");
    $row = mysqli_fetch_assoc($qRow);
    if (!$row) {
        die("Data tidak ditemukan");
    }
}

if (isset($_POST['submit'])) {
    $tanggal    = mysqli_real_escape_string($koneksi, $_POST['tanggal']);
    $plant      = mysqli_real_escape_string($koneksi, $_POST['plant']);
    $namapart   = mysqli_real_escape_string($koneksi, $_POST['namapart']);
    $type       = mysqli_real_escape_string($koneksi, $_POST['type']);
    $partnumber = mysqli_real_escape_string($koneksi, $_POST['partnumber']);
    $problem    = mysqli_real_escape_string($koneksi, $_POST['problem']);
    $countermeasure = mysqli_real_escape_string($koneksi, $_POST['countermeasure']);
    $due_date   = !empty($_POST['due_date']) ? "'" . mysqli_real_escape_string($koneksi, $_POST['due_date']) . "'" : (!empty($_POST['old_due_date']) ? "'" . mysqli_real_escape_string($koneksi, $_POST['old_due_date']) . "'" : "NULL");
    $pic        = mysqli_real_escape_string($koneksi, $_POST['pic']);

    $uploadDir = "../uploads/asakai_tambahan/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // File lama tidak pernah ditimpa: diambil dari DB, upload baru ditolak kecuali user
    // mencentang "Hapus file lama" (lihat simpanFileTunggalTanpaTimpa).
    try {
        $fileName = simpanFileTunggalTanpaTimpa((string) ($row['file'] ?? ''), $_FILES['file'] ?? null, !empty($_POST['hapus_file_lama']), $uploadDir, 'TAMBAHAN');
    } catch (Exception $e) {
        echo "<script>alert(" . json_encode($e->getMessage()) . "); history.back();</script>";
        exit;
    }

    $fileNameEscaped = mysqli_real_escape_string($koneksi, $fileName);

    if ($id > 0) {
        mysqli_query($koneksi, "UPDATE asakai_perbaikan_tambahan SET
            tanggal = '$tanggal',
            plant = '$plant',
            namapart = '$namapart',
            type = '$type',
            partnumber = '$partnumber',
            problem = '$problem',
            countermeasure = '$countermeasure',
            due_date = $due_date,
            pic = '$pic',
            file = '$fileNameEscaped'
            WHERE id_tambahan = $id
        ");
    } else {
        mysqli_query($koneksi, "INSERT INTO asakai_perbaikan_tambahan
            (tanggal, plant, namapart, type, partnumber, problem, countermeasure, due_date, pic, file)
            VALUES
            ('$tanggal', '$plant', '$namapart', '$type', '$partnumber', '$problem', '$countermeasure', $due_date, '$pic', '$fileNameEscaped')
        ");
    }

    header("Location: bahan_asakai.php?tanggal=" . urlencode($tanggal) . "&plant=" . urlencode($plant));
    exit;
}

$qPart = mysqli_query($koneksi, "SELECT namapart, type, partnumber FROM master_part ORDER BY namapart ASC");
$qDept = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");

$defaultTanggal = $row['tanggal'] ?? ($_GET['tanggal'] ?? date('Y-m-d'));
$defaultPlant   = $row['plant'] ?? ($_GET['plant'] ?? '1');
?>

<!DOCTYPE html>
<html>

<head>
    <title><?= $id > 0 ? 'Edit' : 'Tambah' ?> Informasi Perbaikan</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Informasi Tambahan Perbaikan" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #eef2f7, #e3eaf3);
            margin: 0;
        }

        .container-form {
            width: 96%;
            max-width: 700px;
            margin: 30px auto;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            padding: 25px;
        }

        .title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 14px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 13px;
        }

        textarea {
            min-height: 70px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #4e73df;
            outline: none;
            box-shadow: 0 0 0 2px rgba(78, 115, 223, 0.15);
        }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .btn-save {
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-cancel {
            background: #e2e8f0;
            color: #334155;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .file-current {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container-form">
                <div class="card">

                    <div class="title">
                        <?= $id > 0 ? 'Edit' : 'Tambah' ?> Informasi Tambahan Perbaikan
                    </div>

                    <form method="POST" enctype="multipart/form-data">

                        <div class="row-2">
                            <div class="form-group">
                                <label>Tanggal <span style="color:#dc2626">*</span></label>
                                <input type="date" name="tanggal" value="<?= htmlspecialchars($defaultTanggal) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Plant <span style="color:#dc2626">*</span></label>
                                <select name="plant" required>
                                    <option value="1" <?= $defaultPlant == 1 ? 'selected' : '' ?>>Plant 1</option>
                                    <option value="2" <?= $defaultPlant == 2 ? 'selected' : '' ?>>Plant 2</option>
                                    <option value="3" <?= $defaultPlant == 3 ? 'selected' : '' ?>>Plant 3</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Nama Part <span style="color:#dc2626">*</span></label>
                            <select name="namapart" id="namapart" onchange="setPartData(this)" required>
                                <option value="">Pilih</option>
                                <?php
                                mysqli_data_seek($qPart, 0);
                                while ($p = mysqli_fetch_assoc($qPart)) {
                                    $selected = (isset($row['namapart']) && $row['namapart'] == $p['namapart']) ? 'selected' : '';
                                ?>
                                    <option
                                        value="<?= htmlspecialchars($p['namapart']) ?>"
                                        data-partnumber="<?= htmlspecialchars($p['partnumber']) ?>"
                                        data-type="<?= htmlspecialchars($p['type']) ?>"
                                        <?= $selected ?>>
                                        <?= htmlspecialchars($p['namapart']) ?> - <?= htmlspecialchars($p['type']) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="row-2">
                            <div class="form-group">
                                <label>Type</label>
                                <input type="text" name="type" id="type" value="<?= htmlspecialchars($row['type'] ?? '') ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label>Part Number</label>
                                <input type="text" name="partnumber" id="partnumber" value="<?= htmlspecialchars($row['partnumber'] ?? '') ?>" readonly>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Problem <span style="color:#dc2626">*</span></label>
                            <textarea name="problem" required><?= htmlspecialchars($row['problem'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Countermeasure Sementara</label>
                            <textarea name="countermeasure"><?= htmlspecialchars($row['countermeasure'] ?? '') ?></textarea>
                        </div>

                        <div class="row-2">
                            <div class="form-group">
                                <label>Due Date</label>
                                <input type="date" name="due_date" value="<?= htmlspecialchars($row['due_date'] ?? '') ?>">
                                <input type="hidden" name="old_due_date" value="<?= htmlspecialchars($row['due_date'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>PIC / Dept</label>
                                <select name="pic">
                                    <option value="">- Pilih Dept -</option>
                                    <?php while ($d = mysqli_fetch_assoc($qDept)) {
                                        $selected = (isset($row['pic']) && $row['pic'] == $d['nama_dept']) ? 'selected' : '';
                                    ?>
                                        <option value="<?= htmlspecialchars($d['nama_dept']) ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($d['nama_dept']) ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Upload File</label>
                            <input type="file" name="file" <?= !empty($row['file']) ? 'disabled' : '' ?>>
                            <?php if (!empty($row['file'])): ?>
                                <div class="file-current">
                                    File saat ini: <a href="../uploads/asakai_tambahan/<?= htmlspecialchars($row['file']) ?>" target="_blank"><?= htmlspecialchars($row['file']) ?></a>
                                    (kosongkan jika tidak ingin mengganti)
                                </div>
                                <input type="hidden" name="existing_file" value="<?= htmlspecialchars($row['file']) ?>">
                                <?= renderHapusFileLamaTunggal() ?>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: 20px; display:flex; gap:10px;">
                            <button type="submit" name="submit" class="btn-save">Simpan</button>
                            <a href="bahan_asakai.php?tanggal=<?= urlencode($defaultTanggal) ?>&plant=<?= urlencode($defaultPlant) ?>" class="btn-cancel">Batal</a>
                        </div>

                    </form>

                </div>
            </div>

        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        function setPartData(select) {
            var selected = select.options[select.selectedIndex];
            var partnumber = selected.getAttribute("data-partnumber");
            var type = selected.getAttribute("data-type");

            document.getElementById("partnumber").value = partnumber || "";
            document.getElementById("type").value = type || "";
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

</body>

</html>
