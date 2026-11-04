<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';
require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
require_once __DIR__ . '/../config/fpm_bukti_aksi_helper.php';
require_once __DIR__ . '/../config/fpm_reschedule_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$id = $_GET['id'] ?? '';

$fpm = [];
$detail = [];
$catatanRevisi = [];

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    $sql2 = "SELECT shift, total_lost_time FROM fpm_detail_masalah WHERE id_fpm = ?";
    $stmt2 = $koneksi->prepare($sql2);
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $detail = $result2->fetch_assoc();

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

    // Reschedule due date (maks 3x) - Staff IMP sering membantu Dept Head mengelola FPM-nya.
    // Disimpan langsung lewat tombol Reschedule (AJAX), lihat config/fpm_reschedule_helper.php.
    $rescheduleLogs   = fpmRescheduleLogs($koneksi, (int) $id);
    $rescheduleCounts = fpmRescheduleCounts($koneksi, (int) $id);
}

$uploadDir = "../uploads/fpm_dt/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* =========================================================
   HANDLE SUBMIT MODAL "CATATAN REVISI" (kirim notif WA)
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
        $_SESSION['username'] ?? ''
    );

    echo "<script>
            alert(" . json_encode($hasil['pesan']) . ");
            window.location='update_countermeasure.php?id=$id_fpm_post';
          </script>";
    exit;
}

/* =========================================================
   HANDLE SUBMIT FORM UTAMA (status + upload file)
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['catatan_revisi_submit'])) {

    $id_fpm = (int) $_POST['id_fpm'];
    $koneksi->begin_transaction();

    // File & actual_date diambil dari DB saat simpan, bukan dari hidden input old_file /
    // input actual_date yang nilainya dari saat halaman ini dibuka. Sebelumnya, kalau PIC
    // upload bukti setelah Staff IMP membuka halaman ini, simpanan Staff IMP menimpa file
    // PIC dengan nilai lama (kosong) - bukti hilang dan PIC mengira upload-nya gagal
    // (kasus FPM-D/04/VIII/2026). actual_date dari form tetap dipakai, kecuali file baris
    // itu sudah berubah sejak halaman dibuka (berarti actual_date-nya ikut milik upload PIC).
    $nilaiFileTerkini = function (string $tabel, $rowId, string $fileForm, string $actualForm) use ($koneksi, $id_fpm): array {
        $rowId = (int) $rowId;
        $stmt = $koneksi->prepare("SELECT file, actual_date FROM $tabel WHERE id = ? AND id_fpm = ? FOR UPDATE");
        $stmt->bind_param("ii", $rowId, $id_fpm);
        $stmt->execute();
        $barisDb = $stmt->get_result()->fetch_assoc();

        if (!$barisDb) {
            return [$fileForm, $actualForm];
        }

        $fileDb = (string) ($barisDb['file'] ?? '');
        if ($fileDb !== $fileForm) {
            return [$fileDb, (string) ($barisDb['actual_date'] ?? '')];
        }
        return [$fileDb, $actualForm];
    };

    try {

        /* =========================
           CORRECTIVE ACTION
        ========================== */

        if (!empty($_POST['corrective']['id'])) {

            foreach ($_POST['corrective']['id'] as $i => $rowId) {

                $tindakan = $_POST['corrective']['tindakan'][$i] ?? '';
                $due_date = !empty($_POST['corrective']['due_date'][$i]) ? $_POST['corrective']['due_date'][$i] : null;
                $actual_date = $_POST['corrective']['actual_date'][$i] ?? '';
                $pic = $_POST['corrective']['pic'][$i] ?? '';
                $status = $_POST['corrective']['status'][$i];
                [$fileName, $actual_date] = $nilaiFileTerkini('fpm_corrective_action', $rowId, (string) ($_POST['corrective']['old_file'][$i] ?? ''), $actual_date);

                // Ditambahkan ke file yang sudah ada (maks FPM_BUKTI_AKSI_MAX_FILE per baris),
                // juga saat baris sudah CLOSED - file pertama di kolom `file`, sisanya di *_files.
                $uploadBaru = ambilUploadBuktiAksi('corrective_file', $rowId);
                if ($uploadBaru) {
                    $jumlahLama = ($fileName !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, 'corrective', (int) $rowId));
                    [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'CORR', 'Corrective Action baris ' . ($i + 1));
                    simpanFileTambahanAksi($koneksi, 'corrective', (int) $rowId, $fileTambahan);
                }

                $stmt = $koneksi->prepare("UPDATE fpm_corrective_action
                    SET tindakan = ?, due_date = ?, actual_date = ?, pic = ?, status = ?, file = ?
                    WHERE id = ? AND id_fpm = ?
                ");

                $stmt->bind_param(
                    "ssssssii",
                    $tindakan,
                    $due_date,
                    $actual_date,
                    $pic,
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

                $tindakan = $_POST['preventive']['tindakan'][$i] ?? '';
                $due_date = !empty($_POST['preventive']['due_date'][$i]) ? $_POST['preventive']['due_date'][$i] : null;
                $actual_date = $_POST['preventive']['actual_date'][$i] ?? '';
                $pic = $_POST['preventive']['pic'][$i] ?? '';
                $status = $_POST['preventive']['status'][$i];
                [$fileName, $actual_date] = $nilaiFileTerkini('fpm_preventive_action', $rowId, (string) ($_POST['preventive']['old_file'][$i] ?? ''), $actual_date);

                $uploadBaru = ambilUploadBuktiAksi('preventive_file', $rowId);
                if ($uploadBaru) {
                    $jumlahLama = ($fileName !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, 'preventive', (int) $rowId));
                    [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'PREV', 'Preventive Action baris ' . ($i + 1));
                    simpanFileTambahanAksi($koneksi, 'preventive', (int) $rowId, $fileTambahan);
                }

                $stmt = $koneksi->prepare("UPDATE fpm_preventive_action
                    SET tindakan = ?, due_date = ?, actual_date = ?, pic = ?, status = ?, file = ?
                    WHERE id = ? AND id_fpm = ?
                ");

                $stmt->bind_param(
                    "ssssssii",
                    $tindakan,
                    $due_date,
                    $actual_date,
                    $pic,
                    $status,
                    $fileName,
                    $rowId,
                    $id_fpm
                );

                $stmt->execute();
            }
        }

        if (!empty($_POST['dokumen']['id'])) {

            foreach ($_POST['dokumen']['id'] as $i => $rowId) {

                $dokumenNama = $_POST['dokumen']['dokumen'][$i] ?? '';
                $due_date = !empty($_POST['dokumen']['due_date'][$i]) ? $_POST['dokumen']['due_date'][$i] : null;
                $actual_date = $_POST['dokumen']['actual_date'][$i] ?? '';
                $pic = $_POST['dokumen']['pic'][$i] ?? '';
                $status = $_POST['dokumen']['status'][$i];
                [$fileName, $actual_date] = $nilaiFileTerkini('fpm_review_dokumen', $rowId, (string) ($_POST['dokumen']['old_file'][$i] ?? ''), $actual_date);

                $uploadBaru = ambilUploadBuktiAksi('dokumen_file', $rowId);
                if ($uploadBaru) {
                    $jumlahLama = ($fileName !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, 'dokumen', (int) $rowId));
                    [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'DOC', 'Update Dokumen baris ' . ($i + 1));
                    simpanFileTambahanAksi($koneksi, 'dokumen', (int) $rowId, $fileTambahan);
                }

                $stmt = $koneksi->prepare("UPDATE fpm_review_dokumen
                    SET dokumen = ?, due_date = ?, actual_date = ?, pic = ?, status = ?, file = ?
                    WHERE id = ? AND id_fpm = ?
                ");

                $stmt->bind_param(
                    "ssssssii",
                    $dokumenNama,
                    $due_date,
                    $actual_date,
                    $pic,
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
                alert('Data berhasil diupdate');
                window.location='list_fpm_downtime.php';
              </script>";
    } catch (Exception $e) {

        $koneksi->rollback();
        echo "Gagal update: " . $e->getMessage();
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
    <title>Form Jawaban FPM DT</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Form Jawaban FPM DT" />
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

        .value {
            font-weight: 500;
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

        .page-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
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
        }

        .btn-catatan {
            background: #f59e0b;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .btn:hover,
        .btn-catatan:hover {
            opacity: .9;
        }

        .btn-action {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            border: none;
            font-size: 16px;
            cursor: pointer;
            line-height: 1;
        }

        .btn-action.add {
            background: #22c55e;
            color: #fff;
        }

        .btn-action.remove {
            background: #ef4444;
            color: #fff;
        }

        .btn-action:hover {
            opacity: 0.85;
        }

        .text-center {
            text-align: center;
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

        textarea {
            width: 100%;
            resize: vertical;
            min-height: 60px;
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

        .file-preview a {
            display: inline-block;
            cursor: pointer;
        }

        /* iframe menangkap klik sendiri sehingga link pembungkusnya tidak jalan;
           matikan pointer-events agar klik PDF membuka file di tab baru seperti gambar. */
        .file-preview iframe {
            width: 100px;
            height: 120px;
            border: 1px solid #ddd;
            border-radius: 4px;
            pointer-events: none;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h1>Update Countermeasure FPM</h1>

                <div class="info-box">
                    <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">

                        <div class="label">No Reg FPM</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['no_reg_fpm'] ?? '') ?>" readonly>

                        <div class="label">Kejadian Berulang ke</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['kejadian_berulang_ke'] ?? '') ?>" readonly>

                        <div class="label">Shift</div>
                        <input type="text" value="<?= htmlspecialchars($detail['shift'] ?? '') ?>" readonly>

                        <div class="label">Nama Part</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>" readonly>

                        <div class="label">Total Lost Time</div>
                        <input type="text" value="<?= htmlspecialchars($detail['total_lost_time'] ?? '') ?>" readonly>

                        <div class="label">No Part</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['no_part'] ?? '') ?>" readonly>

                        <div class="label">Departemen</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['dept'] ?? '') ?>" readonly>

                        <div class="label">Mold #</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['mold_no'] ?? '') ?>" readonly>

                        <div class="label">Tgl Masalah</div>
                        <input type="text" value="<?= !empty($fpm['tgl_ditemukan']) ? date('d-m-Y', strtotime($fpm['tgl_ditemukan'])) : '' ?>" readonly>

                        <div class="label">Model</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['model'] ?? '') ?>" readonly>

                        <div class="label">Tgl Terbit FPM</div>
                        <input type="text" value="<?= !empty($fpm['tgl_terbit']) ? date('d-m-Y', strtotime($fpm['tgl_terbit'])) : '' ?>" readonly>

                        <div class="label">Machine</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>" readonly>

                        <div class="label">Jenis Masalah</div>
                        <input type="text" value="<?= htmlspecialchars($fpm['jenis_masalah'] ?? '') ?>" readonly>

                    </div>
                </div>

                <div class="page-actions">
                    <button type="button" class="btn-catatan" onclick="openCatatanModal(null, null, '', '')">
                        <i class="bi bi-chat-left-text"></i> + Catatan Revisi
                    </button>
                </div>

                <?= renderRiwayatCatatanRevisiCm($catatanRevisi['general'] ?? []) ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_fpm" value="<?= $id ?>">

                    <div class="section-title">Corrective Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table" id="correctiveTable">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>Status</th>
                            <th>File</th>
                            <th>Note</th>
                        </tr>
                        <?php if (!empty($corrective)): ?>
                            <?php foreach ($corrective as $row): ?>
                                <tr>
                                    <td><textarea name="corrective[tindakan][]"><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                    <td>
                                        <input type="date" name="corrective[due_date][]" value="<?= $row['due_date'] ?>">
                                        <?= renderTombolRescheduleFpm('CORRECTIVE', $row, $fpm ?: [], $rescheduleCounts ?? [], $_SESSION['level'] ?? '', $_SESSION['dept'] ?? '') ?>
                                    </td>
                                    <td><input type="date" name="corrective[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <input type="hidden"
                                            name="corrective[id][]"
                                            value="<?= $row['id'] ?>">
                                        <select name="corrective[pic][]">
                                            <option value="<?= $row['pic'] ?? '' ?>"><?= $row['pic'] ?? '' ?></option>
                                            <?php foreach ($deptList as $dept): ?>
                                                <option value="<?= $dept ?>"
                                                    <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                    <?= $dept ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="corrective[status][]"
                                            <?= strtoupper($row['status']) == 'CLOSED' ? 'disabled' : '' ?>>

                                            <option value="OPEN"
                                                <?= strtoupper($row['status']) == 'OPEN' ? 'selected' : '' ?>>
                                                OPEN
                                            </option>

                                            <option value="CLOSED"
                                                <?= strtoupper($row['status']) == 'CLOSED' ? 'selected' : '' ?>>
                                                CLOSED
                                            </option>

                                        </select>

                                        <?php if (strtoupper($row['status']) == 'CLOSED'): ?>
                                            <input type="hidden"
                                                name="corrective[status][]"
                                                value="CLOSED">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'corrective', $row); ?>
                                        <input type="hidden" name="corrective[old_file][]" value="<?= $row['file'] ?>">
                                        <?php foreach ($fileBukti as $fb): ?>
                                            <?= renderPreviewBuktiAksiHapus('corrective', (int) $row['id'], $fb) ?>
                                        <?php endforeach; ?>
                                        <?= renderInputBuktiAksi('corrective_file[' . $row['id'] . ']', count($fileBukti)) ?>
                                        <?= renderTombolMintaRevisiCm('fpm_corrective_action', (int) $row['id'], $row['pic'] ?? '', $row['tindakan'] ?? '') ?>
                                        <?= renderRiwayatCatatanRevisiCm($catatanRevisi['fpm_corrective_action_' . $row['id']] ?? []) ?>
                                    </td>
                                    <td><?= nl2br(htmlspecialchars($row['note'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><textarea name="corrective[tindakan][]"></textarea></td>
                                <td><input type="date" name="corrective[due_date][]"></td>
                                <td><input type="date" name="corrective[actual_date][]"></td>
                                <td>
                                    <select name="corrective[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($deptList as $dept): ?>
                                            <option value="<?= $dept ?>"><?= $dept ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="corrective[status][]">
                                        <option value="Open">Open</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="file" name="corrective_file[]" data-bukti-ui>
                                    <input type="hidden" name="corrective[old_file][]" value="">
                                </td>
                                <td>-</td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Preventive Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table" id="preventiveTable">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>Status</th>
                            <th>File</th>
                            <th>Note</th>
                        </tr>
                        <?php if (!empty($preventive)): ?>
                            <?php foreach ($preventive as $row): ?>
                                <tr>
                                    <td><textarea name="preventive[tindakan][]"><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                    <td>
                                        <input type="date" name="preventive[due_date][]" value="<?= $row['due_date'] ?>">
                                        <?= renderTombolRescheduleFpm('PREVENTIVE', $row, $fpm ?: [], $rescheduleCounts ?? [], $_SESSION['level'] ?? '', $_SESSION['dept'] ?? '') ?>
                                    </td>
                                    <td><input type="date" name="preventive[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <input type="hidden"
                                            name="preventive[id][]"
                                            value="<?= $row['id'] ?>">
                                        <select name="preventive[pic][]">
                                            <option value="<?= $row['pic'] ?? '' ?>"><?= $row['pic'] ?? '' ?></option>
                                            <?php foreach ($deptList as $dept): ?>
                                                <option value="<?= $dept ?>"
                                                    <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                    <?= $dept ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="preventive[status][]"
                                            <?= strtoupper($row['status']) == 'CLOSED' ? 'disabled' : '' ?>>

                                            <option value="OPEN"
                                                <?= strtoupper($row['status']) == 'OPEN' ? 'selected' : '' ?>>
                                                OPEN
                                            </option>

                                            <option value="CLOSED"
                                                <?= strtoupper($row['status']) == 'CLOSED' ? 'selected' : '' ?>>
                                                CLOSED
                                            </option>

                                        </select>

                                        <?php if (strtoupper($row['status']) == 'CLOSED'): ?>
                                            <input type="hidden"
                                                name="preventive[status][]"
                                                value="CLOSED">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'preventive', $row); ?>
                                        <input type="hidden" name="preventive[old_file][]" value="<?= $row['file'] ?>">
                                        <?php foreach ($fileBukti as $fb): ?>
                                            <?= renderPreviewBuktiAksiHapus('preventive', (int) $row['id'], $fb) ?>
                                        <?php endforeach; ?>
                                        <?= renderInputBuktiAksi('preventive_file[' . $row['id'] . ']', count($fileBukti)) ?>
                                        <?= renderTombolMintaRevisiCm('fpm_preventive_action', (int) $row['id'], $row['pic'] ?? '', $row['tindakan'] ?? '') ?>
                                        <?= renderRiwayatCatatanRevisiCm($catatanRevisi['fpm_preventive_action_' . $row['id']] ?? []) ?>
                                    </td>
                                    <td><?= nl2br(htmlspecialchars($row['note'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><textarea name="preventive[tindakan][]"></textarea></td>
                                <td><input type="date" name="preventive[due_date][]"></td>
                                <td><input type="date" name="preventive[actual_date][]"></td>
                                <td>
                                    <select name="preventive[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($deptList as $dept): ?>
                                            <option value="<?= $dept ?>"><?= $dept ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="preventive[status][]">
                                        <option value="Open">Open</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="file" name="preventive_file[]" data-bukti-ui>
                                    <input type="hidden" name="preventive[old_file][]" value="">
                                </td>
                                <td>-</td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Update Dokumen</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table id="dokumenTable">
                        <tr>
                            <th>Dokumen</th>
                            <th>Due Date</th>
                            <th>Actual</th>
                            <th>PIC</th>
                            <th>Status</th>
                            <th>File</th>
                        </tr>
                        <?php if (!empty($dokumen)): ?>
                            <?php foreach ($dokumen as $row): ?>
                                <tr>
                                    <td>
                                        <input list="docType" name="dokumen[dokumen][]"
                                            value="<?= htmlspecialchars($row['dokumen']) ?>">
                                    </td>
                                    <td>
                                        <input type="date" name="dokumen[due_date][]" value="<?= $row['due_date'] ?>">
                                        <?= renderTombolRescheduleFpm('DOKUMEN', $row, $fpm ?: [], $rescheduleCounts ?? [], $_SESSION['level'] ?? '', $_SESSION['dept'] ?? '') ?>
                                    </td>
                                    <td><input type="date" name="dokumen[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <input type="hidden"
                                            name="dokumen[id][]"
                                            value="<?= $row['id'] ?>">
                                        <select name="dokumen[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($deptList as $dept): ?>
                                                <option value="<?= $dept ?>"
                                                    <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                    <?= $dept ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="dokumen[status][]"
                                            <?= strtoupper($row['status']) == 'CLOSED' ? 'disabled' : '' ?>>

                                            <option value="OPEN"
                                                <?= strtoupper($row['status']) == 'OPEN' ? 'selected' : '' ?>>
                                                OPEN
                                            </option>

                                            <option value="CLOSED"
                                                <?= strtoupper($row['status']) == 'CLOSED' ? 'selected' : '' ?>>
                                                CLOSED
                                            </option>

                                        </select>

                                        <?php if (strtoupper($row['status']) == 'CLOSED'): ?>
                                            <input type="hidden"
                                                name="dokumen[status][]"
                                                value="CLOSED">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'dokumen', $row); ?>
                                        <input type="hidden" name="dokumen[old_file][]" value="<?= $row['file'] ?>">
                                        <?php foreach ($fileBukti as $fb): ?>
                                            <?= renderPreviewBuktiAksiHapus('dokumen', (int) $row['id'], $fb) ?>
                                        <?php endforeach; ?>
                                        <?= renderInputBuktiAksi('dokumen_file[' . $row['id'] . ']', count($fileBukti)) ?>
                                        <?= renderTombolMintaRevisiCm('fpm_review_dokumen', (int) $row['id'], $row['pic'] ?? '', $row['dokumen'] ?? '') ?>
                                        <?= renderRiwayatCatatanRevisiCm($catatanRevisi['fpm_review_dokumen_' . $row['id']] ?? []) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <input list="docType" name="dokumen[dokumen][]">
                                </td>
                                <td><input type="date" name="dokumen[due_date][]"></td>
                                <td><input type="date" name="dokumen[actual_date][]"></td>
                                <td>
                                    <select name="dokumen[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($deptList as $dept): ?>
                                            <option value="<?= $dept ?>"><?= $dept ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <select name="dokumen[status][]">
                                        <option value="Open">Open</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="file" name="dokumen_file[]" data-bukti-ui>
                                    <input type="hidden" name="dokumen[old_file][]" value="">
                                </td>
                                <td>-</td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <datalist id="docType">
                        <option value="IK">
                        <option value="FMEA">
                        <option value="QCPC">
                        <option value="Check Sheet">
                    </datalist>

                    <?= renderHistoryRescheduleFpm($rescheduleLogs ?? []) ?>

                    <div class="btn-group">
                        <button class="btn btn-submit">Submit</button>
                        <button class="btn btn-cancel">Cancel</button>
                    </div>

                </form>

                <?= renderAsetRescheduleFpm((int) $id) ?>

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

    <script>
        function addRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const newRow = row.cloneNode(true);
            // tombol reschedule milik baris asal - baris baru belum tersimpan, belum bisa di-reschedule
            newRow.querySelectorAll('.fpm-rs-box').forEach(el => el.remove());

            newRow.querySelectorAll("input, textarea").forEach(el => el.value = "");
            newRow.querySelectorAll("select").forEach(el => el.selectedIndex = 0);

            table.appendChild(newRow);
            updateActionButtons(table);
        }

        function removeRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const rows = table.querySelectorAll("tr");

            if (rows.length > 2) {
                row.remove();
                updateActionButtons(table);
            }
        }

        function updateActionButtons(table) {
            const rows = table.querySelectorAll("tr");
            rows.forEach((row, index) => {
                if (index === 0) return; // skip header
                const addBtn = row.querySelector(".add");
                addBtn.style.display = (index === rows.length - 1) ? "inline-block" : "none";
            });
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
