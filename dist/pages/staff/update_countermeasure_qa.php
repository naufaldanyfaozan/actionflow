<?php
session_start();
if (!isset($_SESSION["username"])) header("location:../cek_login.php");

if (($_SESSION['dept'] ?? '') !== 'QA') {
    die('Akses ditolak: fitur Verifikasi Countermeasure hanya untuk Staff QA.');
}

include '../koneksi.php';
require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
require_once __DIR__ . '/../config/fpm_bukti_aksi_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$id = $_GET['id'] ?? '';

$fpm = [];

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    // ================== AMBIL CORRECTIVE ==================
    $corrective = [];

    $sql4 = "SELECT * FROM fpm_corrective_action WHERE id_fpm = ?";
    $stmt4 = $koneksi->prepare($sql4);
    $stmt4->bind_param("i", $id);
    $stmt4->execute();
    $result4 = $stmt4->get_result();

    while ($row = $result4->fetch_assoc()) {
        $corrective[] = $row;
    }

    // ================== AMBIL PREVENTIVE ==================
    $preventive = [];

    $sql5 = "SELECT * FROM fpm_preventive_action WHERE id_fpm = ?";
    $stmt5 = $koneksi->prepare($sql5);
    $stmt5->bind_param("i", $id);
    $stmt5->execute();
    $result5 = $stmt5->get_result();

    while ($row = $result5->fetch_assoc()) {
        $preventive[] = $row;
    }

    // ================== AMBIL DOKUMEN ==================
    $dokumen = [];

    $sql6 = "SELECT * FROM fpm_review_dokumen WHERE id_fpm = ?";
    $stmt6 = $koneksi->prepare($sql6);
    $stmt6->bind_param("i", $id);
    $stmt6->execute();
    $result6 = $stmt6->get_result();

    while ($row = $result6->fetch_assoc()) {
        $dokumen[] = $row;
    }

    // ================== AMBIL RIWAYAT CATATAN REVISI ==================
    $catatanRevisi = kelompokkanCatatanRevisiCm(ambilCatatanRevisiCm($koneksi, 'FPM', (int) $id));
}

if (!$fpm) {
    die('Data FPM tidak ditemukan');
}

// Staff QA hanya memverifikasi FPM Claim Machining (no_form FPM-Cast, flow_type CLAIMMACH);
// FPM Down Time (FPM-D) & NG Internal (FPM-N) diverifikasi Staff IMP.
$isClaimMachQa = ($fpm['flow_type'] ?? '') === 'CLAIMMACH'
    || ($fpm['jenis_fpm'] ?? '') === 'Claim Machining'
    || strpos($fpm['no_form'] ?? '', 'FPM-Cast/') === 0;
if (!$isClaimMachQa ||(int) ($_POST['id_fpm'] ?? $fpm['id_fpm']) !== (int) $fpm['id_fpm']) {
    die('Akses ditolak: Staff QA hanya memverifikasi countermeasure FPM Claim Machining (FPM-Cast).');
}

$uploadDir = "../uploads/fpm_dt/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* =========================================================
   HANDLE CATATAN REVISI KE PIC (sama seperti Staff IMP)
========================================================== */
if (isset($_POST['catatan_revisi_submit']) && isset($_POST['id_fpm'])) {

    $id_fpm_post = (int) $_POST['id_fpm'];
    $refId = ($_POST['ref_id'] ?? '') !== '' ? (int) $_POST['ref_id'] : null;

    $hasil = kirimCatatanRevisiCm(
        $koneksi,
        'FPM',
        $id_fpm_post,
        trim($_POST['ref_table'] ?? ''),
        $refId,
        $_POST['dept_tujuan'] ?? '',
        $_POST['catatan'] ?? '',
        $_SESSION['username'] ?? '',
        CM_REVISI_ROLE_QA
    );

    echo "<script>
            alert(" . json_encode($hasil['pesan']) . ");
            window.location='update_countermeasure_qa.php?id=$id_fpm_post';
          </script>";
    exit;
}

/* =========================================================
   HANDLE SUBMIT FORM (status + upload file)
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        die("<script>alert('Upload gagal: total ukuran file terlalu besar untuk server (maks " . ini_get('post_max_size') . "). Kurangi ukuran/jumlah file lalu coba lagi.');history.back();</script>");
    }

    $id_fpm = (int) $_POST['id_fpm'];
    $koneksi->begin_transaction();

    try {

        /* =========================
           CORRECTIVE ACTION
        ========================== */

        if (!empty($_POST['corrective']['id'])) {

            foreach ($_POST['corrective']['id'] as $i => $rowId) {

                $status = strtoupper(trim($_POST['corrective']['status'][$i] ?? 'OPEN')) === 'CLOSED' ? 'CLOSED' : 'OPEN';
                $fileName = fileUtamaBuktiAksi($koneksi, 'corrective', (int) $rowId, $id_fpm);
                if ($fileName === null) {
                    throw new Exception('Baris Corrective Action ' . ($i + 1) . ' tidak ditemukan. Muat ulang halaman lalu coba lagi.');
                }

                // Upload baru ditambahkan ke file yang ada - maks FPM_BUKTI_AKSI_MAX_FILE, juga saat CLOSED.
                $uploadBaru = ambilUploadBuktiAksi('corrective_file', $rowId);
                if ($uploadBaru) {
                    $jumlahLama = ($fileName !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, 'corrective', (int) $rowId));
                    [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'CORR', 'Corrective Action baris ' . ($i + 1));
                    simpanFileTambahanAksi($koneksi, 'corrective', (int) $rowId, $fileTambahan);
                }
                $stmt = $koneksi->prepare("UPDATE fpm_corrective_action
                    SET status = ?, file = ?
                    WHERE id = ? AND id_fpm = ?
                ");

                $stmt->bind_param(
                    "ssii",
                    $status,
                    $fileName,
                    $rowId,
                    $id_fpm
                );

                $stmt->execute();
            }
        }

        /* =========================
           PREVENTIVE ACTION
        ========================== */

        if (!empty($_POST['preventive']['id'])) {

            foreach ($_POST['preventive']['id'] as $i => $rowId) {

                $status = strtoupper(trim($_POST['preventive']['status'][$i] ?? 'OPEN')) === 'CLOSED' ? 'CLOSED' : 'OPEN';
                $fileName = fileUtamaBuktiAksi($koneksi, 'preventive', (int) $rowId, $id_fpm);
                if ($fileName === null) {
                    throw new Exception('Baris Preventive Action ' . ($i + 1) . ' tidak ditemukan. Muat ulang halaman lalu coba lagi.');
                }

                $uploadBaru = ambilUploadBuktiAksi('preventive_file', $rowId);
                if ($uploadBaru) {
                    $jumlahLama = ($fileName !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, 'preventive', (int) $rowId));
                    [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'PREV', 'Preventive Action baris ' . ($i + 1));
                    simpanFileTambahanAksi($koneksi, 'preventive', (int) $rowId, $fileTambahan);
                }

                $stmt = $koneksi->prepare("UPDATE fpm_preventive_action
                    SET status = ?, file = ?
                    WHERE id = ? AND id_fpm = ?
                ");

                $stmt->bind_param(
                    "ssii",
                    $status,
                    $fileName,
                    $rowId,
                    $id_fpm
                );

                $stmt->execute();
            }
        }

        /* =========================
           UPDATE DOKUMEN
        ========================== */

        if (!empty($_POST['dokumen']['id'])) {

            foreach ($_POST['dokumen']['id'] as $i => $rowId) {

                $status = strtoupper(trim($_POST['dokumen']['status'][$i] ?? 'OPEN')) === 'CLOSED' ? 'CLOSED' : 'OPEN';
                $fileName = fileUtamaBuktiAksi($koneksi, 'dokumen', (int) $rowId, $id_fpm);
                if ($fileName === null) {
                    throw new Exception('Baris Update Dokumen ' . ($i + 1) . ' tidak ditemukan. Muat ulang halaman lalu coba lagi.');
                }

                $uploadBaru = ambilUploadBuktiAksi('dokumen_file', $rowId);
                if ($uploadBaru) {
                    $jumlahLama = ($fileName !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, 'dokumen', (int) $rowId));
                    [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'DOC', 'Update Dokumen baris ' . ($i + 1));
                    simpanFileTambahanAksi($koneksi, 'dokumen', (int) $rowId, $fileTambahan);
                }

                $stmt = $koneksi->prepare("UPDATE fpm_review_dokumen
                    SET status = ?, file = ?
                    WHERE id = ? AND id_fpm = ?
                ");

                $stmt->bind_param(
                    "ssii",
                    $status,
                    $fileName,
                    $rowId,
                    $id_fpm
                );

                $stmt->execute();
            }
        }

        $koneksi->commit();

        echo "<script>
                alert('Status countermeasure berhasil diverifikasi');
                window.location='verifikasi_countermeasure.php';
              </script>";
        exit;
    } catch (Exception $e) {

        $koneksi->rollback();
        echo "<script>alert(" . json_encode("Gagal update: " . $e->getMessage()) . ");history.back();</script>";
    }
}

$deptList = [];
$qDept = $koneksi->query("SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");
while ($rowDept = $qDept->fetch_assoc()) {
    $deptList[] = $rowDept['nama_dept'];
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Verifikasi Countermeasure FPM</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Verifikasi Countermeasure FPM" />
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
            --primary: #2563eb;
            --border: #e5e7eb;
            --bg: #f8fafc;
            --text: #1f2933;
            --muted: #6b7280;
        }

        * {
            box-sizing: border-box;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        body {
            background: var(--bg);
            margin: 0;
            color: var(--text);
        }

        .container {
            max-width: 1200px;
            margin: auto;
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .05);
        }

        h1 {
            text-align: center;
            font-size: 20px;
            margin-bottom: 20px;
            text-transform: uppercase;
        }

        .info-box {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
            background: #f9fafb;
        }

        .section-title {
            margin: 25px 0 10px;
            font-weight: 600;
            font-size: 15px;
        }

        .upload-video-hint {
            display: block;
            margin-top: -8px;
            margin-bottom: 10px;
            color: #6b7280;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table th,
        table td {
            border: 1px solid var(--border);
            padding: 8px;
            text-align: center;
            font-size: 13px;
        }

        table th {
            background: #f1f5f9;
            font-weight: 600;
        }

        table td:first-child {
            font-weight: 600;
            background: #f8fafc;
        }

        textarea {
            width: 100%;
            resize: vertical;
            min-height: 60px;
        }

        .action-table td {
            vertical-align: top;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-submit {
            background: var(--primary);
            color: #fff;
        }

        .btn-cancel {
            background: #ef4444;
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn:hover {
            opacity: .9;
        }

        .info-grid {
            display: grid;
            gap: 20px 100px;
        }

        .label {
            font-weight: 600;
            color: var(--muted);
            text-align: left;
        }

        .info-grid input,
        .info-grid select {
            width: 150%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
        }

        .info-grid input[readonly] {
            background: #f3f4f6;
        }

        .file-preview {
            margin-top: 5px;
        }

        .file-preview img {
            width: 80px;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .file-preview iframe {
            width: 100px;
            height: 120px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        select[disabled] {
            background: #f3f4f6;
            color: #6b7280;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h1>Verifikasi Countermeasure FPM</h1>

                <div class="info-box">
                    <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">
                        <div class="label">No Form</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['no_form'] ?? $fpm['no_reg_fpm'] ?? '') ?>" readonly>

                        <div class="label">Plant</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['plant'] ?? '') ?>" readonly>

                        <div class="label">Nama Part</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>" readonly>

                        <div class="label">Machine</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>" readonly>
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; margin:-10px 0 10px;">
                    <button type="button" onclick="openCatatanModal(null, null, '', '')"
                        style="background:#f59e0b; color:#fff; border:none; border-radius:6px; padding:8px 14px; font-size:13px; font-weight:600; cursor:pointer;">
                        <i class="bi bi-chat-left-text"></i> + Catatan Revisi
                    </button>
                </div>

                <?= renderRiwayatCatatanRevisiCm($catatanRevisi['general'] ?? []) ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_fpm" value="<?= (int) $id ?>">

                    <div class="section-title">Corrective Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>Note</th>
                            <th>File</th>
                            <th>Status</th>
                        </tr>
                        <?php if (!empty($corrective)): ?>
                            <?php foreach ($corrective as $row): ?>
                                <tr>
                                    <td><?= nl2br(htmlspecialchars($row['tindakan'] ?? '')) ?></td>
                                    <td><?= !empty($row['due_date']) ? date('d-m-Y', strtotime($row['due_date'])) : '-' ?></td>
                                    <td><?= !empty($row['actual_date']) ? date('d-m-Y', strtotime($row['actual_date'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($row['pic'] ?? '') ?></td>
                                    <td><?= nl2br(htmlspecialchars($row['note'] ?? '')) ?></td>
                                    <td>
                                        <input type="hidden" name="corrective[id][]" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="corrective[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'corrective', $row); ?>
                                        <?php foreach ($fileBukti as $fb): ?>
                                            <?= renderPreviewBuktiAksiHapus('corrective', (int) $row['id'], $fb) ?>
                                        <?php endforeach; ?>
                                        <?= renderInputBuktiAksi('corrective_file[' . (int) $row['id'] . ']', count($fileBukti)) ?>
                                        <?= renderTombolMintaRevisiCm('fpm_corrective_action', (int) $row['id'], $row['pic'] ?? '', $row['tindakan'] ?? '') ?>
                                        <?= renderRiwayatCatatanRevisiCm($catatanRevisi['fpm_corrective_action_' . $row['id']] ?? []) ?>
                                    </td>
                                    <td>
                                        <select name="corrective[status][]" <?= strtoupper($row['status'] ?? '') == 'CLOSED' ? 'disabled' : '' ?>>
                                            <option value="OPEN" <?= strtoupper($row['status'] ?? '') == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= strtoupper($row['status'] ?? '') == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                        <?php if (strtoupper($row['status'] ?? '') == 'CLOSED'): ?>
                                            <input type="hidden" name="corrective[status][]" value="CLOSED">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">Tidak ada Corrective Action.</td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="section-title">Preventive Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>Note</th>
                            <th>File</th>
                            <th>Status</th>
                        </tr>
                        <?php if (!empty($preventive)): ?>
                            <?php foreach ($preventive as $row): ?>
                                <tr>
                                    <td><?= nl2br(htmlspecialchars($row['tindakan'] ?? '')) ?></td>
                                    <td><?= !empty($row['due_date']) ? date('d-m-Y', strtotime($row['due_date'])) : '-' ?></td>
                                    <td><?= !empty($row['actual_date']) ? date('d-m-Y', strtotime($row['actual_date'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($row['pic'] ?? '') ?></td>
                                    <td><?= nl2br(htmlspecialchars($row['note'] ?? '')) ?></td>
                                    <td>
                                        <input type="hidden" name="preventive[id][]" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="preventive[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'preventive', $row); ?>
                                        <?php foreach ($fileBukti as $fb): ?>
                                            <?= renderPreviewBuktiAksiHapus('preventive', (int) $row['id'], $fb) ?>
                                        <?php endforeach; ?>
                                        <?= renderInputBuktiAksi('preventive_file[' . (int) $row['id'] . ']', count($fileBukti)) ?>
                                        <?= renderTombolMintaRevisiCm('fpm_preventive_action', (int) $row['id'], $row['pic'] ?? '', $row['tindakan'] ?? '') ?>
                                        <?= renderRiwayatCatatanRevisiCm($catatanRevisi['fpm_preventive_action_' . $row['id']] ?? []) ?>
                                    </td>
                                    <td>
                                        <select name="preventive[status][]" <?= strtoupper($row['status'] ?? '') == 'CLOSED' ? 'disabled' : '' ?>>
                                            <option value="OPEN" <?= strtoupper($row['status'] ?? '') == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= strtoupper($row['status'] ?? '') == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                        <?php if (strtoupper($row['status'] ?? '') == 'CLOSED'): ?>
                                            <input type="hidden" name="preventive[status][]" value="CLOSED">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">Tidak ada Preventive Action.</td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="section-title">Update Dokumen</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table>
                        <tr>
                            <th>Dokumen</th>
                            <th>Due Date</th>
                            <th>Actual</th>
                            <th>PIC</th>
                            <th>File</th>
                            <th>Status</th>
                        </tr>
                        <?php if (!empty($dokumen)): ?>
                            <?php foreach ($dokumen as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['dokumen'] ?? '') ?></td>
                                    <td><?= !empty($row['due_date']) ? date('d-m-Y', strtotime($row['due_date'])) : '-' ?></td>
                                    <td><?= !empty($row['actual_date']) ? date('d-m-Y', strtotime($row['actual_date'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($row['pic'] ?? '') ?></td>
                                    <td>
                                        <input type="hidden" name="dokumen[id][]" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="dokumen[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'dokumen', $row); ?>
                                        <?php foreach ($fileBukti as $fb): ?>
                                            <?= renderPreviewBuktiAksiHapus('dokumen', (int) $row['id'], $fb) ?>
                                        <?php endforeach; ?>
                                        <?= renderInputBuktiAksi('dokumen_file[' . (int) $row['id'] . ']', count($fileBukti)) ?>
                                        <?= renderTombolMintaRevisiCm('fpm_review_dokumen', (int) $row['id'], $row['pic'] ?? '', $row['dokumen'] ?? '') ?>
                                        <?= renderRiwayatCatatanRevisiCm($catatanRevisi['fpm_review_dokumen_' . $row['id']] ?? []) ?>
                                    </td>
                                    <td>
                                        <select name="dokumen[status][]" <?= strtoupper($row['status'] ?? '') == 'CLOSED' ? 'disabled' : '' ?>>
                                            <option value="OPEN" <?= strtoupper($row['status'] ?? '') == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= strtoupper($row['status'] ?? '') == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                        <?php if (strtoupper($row['status'] ?? '') == 'CLOSED'): ?>
                                            <input type="hidden" name="dokumen[status][]" value="CLOSED">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">Tidak ada dokumen.</td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="btn-group">
                        <button class="btn btn-submit">Simpan Verifikasi</button>
                        <a href="verifikasi_countermeasure.php" class="btn btn-cancel">Batal</a>
                    </div>

                </form>

            </div>

        </main>
    </div>

    <?= renderModalCatatanRevisiCm($deptList, 'id_fpm', (int) $id) ?>
    <?= renderScriptBuktiAksi() ?>
    <?= renderScriptHapusBuktiAksi((int) $id) ?>

    <script>
        (function() {
            const MAX_VIDEO_SIZE = 10 * 1024 * 1024; // 10MB
            const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];

            function isVideoFile(file) {
                if (file.type && file.type.startsWith('video/')) return true;
                const ext = file.name.split('.').pop().toLowerCase();
                return VIDEO_EXT.includes(ext);
            }

            document.addEventListener('change', function(e) {
                if (e.target.matches && e.target.matches('input[type="file"]')) {
                    const file = e.target.files[0];
                    if (file && isVideoFile(file) && file.size > MAX_VIDEO_SIZE) {
                        alert('Ukuran video melebihi 10MB. Silakan pilih file video yang lebih kecil.');
                        e.target.value = '';
                    }
                }
            });

            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const fileInputs = form.querySelectorAll('input[type="file"]');
                    for (const input of fileInputs) {
                        const file = input.files[0];
                        if (file && isVideoFile(file) && file.size > MAX_VIDEO_SIZE) {
                            alert('Ukuran video melebihi 10MB. Perbaiki file sebelum submit.');
                            e.preventDefault();
                            input.focus();
                            return false;
                        }
                    }
                });
            });
        })();
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

    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js"></script>

</body>

</html>
