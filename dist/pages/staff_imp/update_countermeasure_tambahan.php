<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';
require_once __DIR__ . '/../config/upload_bukti_ui_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    die("ID tidak valid");
}

$saved = false;

if (isset($_POST['submit'])) {
    $countermeasure = mysqli_real_escape_string($koneksi, $_POST['countermeasure']);
    $due_date    = !empty($_POST['due_date']) ? "'" . mysqli_real_escape_string($koneksi, $_POST['due_date']) . "'" : (!empty($_POST['old_due_date']) ? "'" . mysqli_real_escape_string($koneksi, $_POST['old_due_date']) . "'" : "NULL");
    $actual_date = !empty($_POST['actual_date']) ? "'" . mysqli_real_escape_string($koneksi, $_POST['actual_date']) . "'" : (!empty($_POST['old_actual_date']) ? "'" . mysqli_real_escape_string($koneksi, $_POST['old_actual_date']) . "'" : "NULL");
    $pic         = mysqli_real_escape_string($koneksi, $_POST['pic']);
    $status      = ($_POST['status'] ?? 'OPEN') === 'CLOSED' ? 'CLOSED' : 'OPEN';

    $uploadDir = "../uploads/asakai_tambahan/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // File lama tidak pernah ditimpa: diambil dari DB, upload baru ditolak kecuali user
    // mencentang "Hapus file lama" (lihat simpanFileTunggalTanpaTimpa).
    $qFileLama = mysqli_query($koneksi, "SELECT file FROM asakai_perbaikan_tambahan WHERE id_tambahan = $id");
    $fileLama = (string) ((mysqli_fetch_assoc($qFileLama) ?: [])['file'] ?? '');
    $fileName = $fileLama;
    if ($status === 'OPEN') {
        try {
            $fileName = simpanFileTunggalTanpaTimpa($fileLama, $_FILES['file'] ?? null, !empty($_POST['hapus_file_lama']), $uploadDir, 'CM');
        } catch (Exception $e) {
            echo "<script>alert(" . json_encode($e->getMessage()) . "); history.back();</script>";
            exit;
        }
    }

    $fileNameEscaped = mysqli_real_escape_string($koneksi, $fileName);

    mysqli_query($koneksi, "UPDATE asakai_perbaikan_tambahan SET
        countermeasure = '$countermeasure',
        due_date = $due_date,
        actual_date = $actual_date,
        pic = '$pic',
        status = '$status',
        file = '$fileNameEscaped'
        WHERE id_tambahan = $id
    ");

    $saved = true;
}

$qRow = mysqli_query($koneksi, "SELECT * FROM asakai_perbaikan_tambahan WHERE id_tambahan = $id");
$row = mysqli_fetch_assoc($qRow);
if (!$row) {
    die("Data tidak ditemukan");
}

$qDept = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");
$isClosed = strtoupper($row['status']) === 'CLOSED';
?>

<!DOCTYPE html>
<html>

<head>
    <title>Update Countermeasure</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
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
            --primary: #1e3a8a;
            --bg: #f5f7fa;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #1f2937;
            --muted: #6b7280;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .main-container {
            max-width: 700px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .card-clean {
            background: var(--card);
            border-radius: 14px;
            padding: 20px 22px;
            margin-bottom: 22px;
            border: 1px solid var(--border);
        }

        .section-title {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .6px;
            color: var(--primary);
            margin-bottom: 14px;
            text-transform: uppercase;
        }

        .info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 16px;
            margin-bottom: 8px;
        }

        .info-item label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .info-item div {
            font-size: 13px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px 10px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        textarea {
            min-height: 70px;
            resize: vertical;
        }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .status-open {
            background: #fef9c3;
            color: #854d0e;
            border-color: #fde047;
        }

        .status-closed {
            background: #dcfce7;
            color: #166534;
            border-color: #22c55e;
        }

        .btn-clean {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }

        .btn-primary-clean {
            background: var(--primary);
            color: #fff;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }

        .file-preview img {
            width: 90px;
            height: 90px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        .file-preview iframe {
            width: 100%;
            height: 300px;
            border: 1px solid var(--border);
            border-radius: 6px;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="main-container">

        <h4 style="margin-top:20px;">Update Countermeasure - Informasi Tambahan Perbaikan</h4>

        <?php if ($saved): ?>
            <div class="alert-success">Data berhasil disimpan.</div>
        <?php endif; ?>

        <div class="card-clean">
            <div class="section-title">Informasi Problem</div>
            <div class="info-row">
                <div class="info-item">
                    <label>Nama Part</label>
                    <div><?= htmlspecialchars($row['namapart']) ?></div>
                </div>
                <div class="info-item">
                    <label>Type</label>
                    <div><?= htmlspecialchars($row['type']) ?></div>
                </div>
                <div class="info-item">
                    <label>Part Number</label>
                    <div><?= htmlspecialchars($row['partnumber']) ?></div>
                </div>
                <div class="info-item">
                    <label>Tanggal</label>
                    <div><?= htmlspecialchars($row['tanggal']) ?></div>
                </div>
            </div>
            <div class="info-item">
                <label>Problem</label>
                <div><?= nl2br(htmlspecialchars($row['problem'])) ?></div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="card-clean">
                <div class="section-title">Countermeasure</div>

                <input type="hidden" name="existing_file" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                <input type="hidden" name="old_due_date" value="<?= htmlspecialchars($row['due_date'] ?? '') ?>">
                <input type="hidden" name="old_actual_date" value="<?= htmlspecialchars($row['actual_date'] ?? '') ?>">

                <div class="form-group">
                    <label>Countermeasure</label>
                    <textarea name="countermeasure" <?= $isClosed ? 'readonly' : '' ?>><?= htmlspecialchars($row['countermeasure'] ?? '') ?></textarea>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" value="<?= htmlspecialchars($row['due_date'] ?? '') ?>" <?= $isClosed ? 'readonly' : '' ?>>
                    </div>

                    <div class="form-group">
                        <label>Actual Date</label>
                        <input type="date" name="actual_date" value="<?= htmlspecialchars($row['actual_date'] ?? '') ?>" <?= $isClosed ? 'readonly' : '' ?>>
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>PIC / Dept</label>
                        <select name="pic" <?= $isClosed ? 'disabled' : '' ?>>
                            <option value="">- Pilih Dept -</option>
                            <?php while ($d = mysqli_fetch_assoc($qDept)) {
                                $selected = ($row['pic'] == $d['nama_dept']) ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($d['nama_dept']) ?>" <?= $selected ?>>
                                    <?= htmlspecialchars($d['nama_dept']) ?>
                                </option>
                            <?php } ?>
                        </select>
                        <?php if ($isClosed): ?>
                            <input type="hidden" name="pic" value="<?= htmlspecialchars($row['pic'] ?? '') ?>">
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="<?= $isClosed ? 'status-closed' : 'status-open' ?>" <?= $isClosed ? 'disabled' : '' ?>>
                            <option value="OPEN" <?= !$isClosed ? 'selected' : '' ?>>OPEN</option>
                            <option value="CLOSED" <?= $isClosed ? 'selected' : '' ?>>CLOSED</option>
                        </select>
                        <?php if ($isClosed): ?>
                            <input type="hidden" name="status" value="CLOSED">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Upload File</label>
                    <input type="file" name="file" <?= ($isClosed || !empty($row['file'])) ? 'disabled' : '' ?>>

                    <?php if (!empty($row['file'])):
                        $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                        $fileUrl = '../uploads/asakai_tambahan/' . $row['file'];
                    ?>
                        <div class="file-preview" style="margin-top:8px;">
                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                <a href="<?= $fileUrl ?>" target="_blank"><img src="<?= $fileUrl ?>"></a>
                            <?php elseif ($ext === 'pdf'): ?>
                                <iframe src="<?= $fileUrl ?>"></iframe>
                            <?php else: ?>
                                <a href="<?= $fileUrl ?>" target="_blank">Lihat File</a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isClosed): ?><?= renderHapusFileLamaTunggal() ?><?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!$isClosed): ?>
                    <button type="submit" name="submit" class="btn-clean btn-primary-clean">Simpan Countermeasure</button>
                <?php else: ?>
                    <p style="font-size:12px; color: var(--muted);">Data ini sudah CLOSED dan tidak dapat diubah lagi.</p>
                <?php endif; ?>
            </div>
        </form>

    </div>

</body>

</html>
