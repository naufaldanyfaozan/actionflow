<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';
require_once __DIR__ . '/../config/fpm_lampiran_helper.php';
require_once __DIR__ . '/../config/fpm_aksi_jawab_helper.php';

$id = $_GET['id'] ?? '';

$fpm = [];
$detail = [];

$analisa = [];
$flowout = [];

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    // FPM ini lagi diminta revisi (belum diselesaikan oleh submission ini) -
    // form-nya perlu minta "apa yang diperbaiki" dan itu dicatat ke histori.
    $isRevisiFlow = (($fpm['status_reject'] ?? '') === 'REJECT');

    $sql2 = "SELECT shift, total_lost_time FROM fpm_detail_masalah WHERE id_fpm = ?";
    $stmt2 = $koneksi->prepare($sql2);
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $detail = $result2->fetch_assoc();

    $analisa = [];

    $sql3 = "SELECT * FROM fpm_analisa WHERE id_fpm = ?";
    $stmt3 = $koneksi->prepare($sql3);
    $stmt3->bind_param("i", $id);
    $stmt3->execute();
    $result3 = $stmt3->get_result();

    while ($row = $result3->fetch_assoc()) {

        if ($row['jenis'] == 'FLOW OUT') {
            $flowout[] = $row;
        } else {
            $analisa[] = $row;
        }
    }

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
}

$uploadDir = "../uploads/fpm_dt/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        die("<script>alert('Upload gagal: total ukuran file terlalu besar untuk server (maks " . ini_get('post_max_size') . "). Kurangi ukuran/jumlah file lalu coba lagi.');history.back();</script>");
    }

    $id_fpm = (int) $_POST['id_fpm'];

    $catatanPerbaikan = trim($_POST['catatan_perbaikan'] ?? '');
    if ($isRevisiFlow && $catatanPerbaikan === '') {
        die('Catatan perbaikan wajib diisi - jelaskan bagian mana yang sudah Anda revisi.');
    }

    // ================= FORMAT TANGGAL =================
    $tgl_ditemukan_db = null;
    $tgl_terbit_db    = null;

    if (!empty($_POST['tgl_ditemukan'])) {
        $tgl_ditemukan_db = date('Y-m-d', strtotime($_POST['tgl_ditemukan']));
    }

    if (!empty($_POST['tgl_terbit'])) {
        $tgl_terbit_db = date('Y-m-d', strtotime($_POST['tgl_terbit']));
    }

    $koneksi->begin_transaction();

    try {

        $sql = "UPDATE fpm_header SET
                    kejadian_berulang_ke=?,
                    nama_part=?,
                    no_part=?,
                    dept=?,
                    mold_no=?,
                    tgl_ditemukan=?,
                    model=?,
                    tgl_terbit=?,
                    machine_no=?,
                    jenis_masalah=?
                WHERE id_fpm=?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "isssssssssi",
            $_POST['kejadian_ulang'],
            $_POST['nama_part'],
            $_POST['no_part'],
            $_POST['dept'],
            $_POST['mold_no'],
            $tgl_ditemukan_db,
            $_POST['model'],
            $tgl_terbit_db,
            $_POST['machine_no'],
            $_POST['jenis_masalah'],
            $id_fpm
        );
        $stmt->execute();

        $sql = "UPDATE fpm_detail_masalah SET shift=?, total_lost_time=? WHERE id_fpm=?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "sii",
            $_POST['shift'],
            $_POST['total_lost_time'],
            $id_fpm
        );
        $stmt->execute();

        $koneksi->query("DELETE FROM fpm_analisa WHERE id_fpm='$id_fpm'");

        /* ================= OCCURRENCE ================= */
        if (!empty($_POST['analisa'])) {

            foreach ($_POST['analisa'] as $row) {

                if (empty($row['kategori'])) continue;

                $jenis = 'OCCURRENCE';

                $sql = "INSERT INTO fpm_analisa
                (id_fpm, jenis, kategori, why_1, why_2, why_3, why_4, why_5)
                VALUES (?,?,?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssssss",
                    $id_fpm,
                    $jenis,
                    $row['kategori'],
                    $row['why_1'],
                    $row['why_2'],
                    $row['why_3'],
                    $row['why_4'],
                    $row['why_5']
                );
                $stmt->execute();
            }
        }


        /* ================= FLOW OUT ================= */
        if (!empty($_POST['flowout'])) {

            foreach ($_POST['flowout'] as $row) {

                if (empty($row['kategori'])) continue;

                $jenis = 'FLOW OUT';

                $sql = "INSERT INTO fpm_analisa
                (id_fpm, jenis, kategori, why_1, why_2, why_3, why_4, why_5)
                VALUES (?,?,?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssssss",
                    $id_fpm,
                    $jenis,
                    $row['kategori'],
                    $row['why_1'],
                    $row['why_2'],
                    $row['why_3'],
                    $row['why_4'],
                    $row['why_5']
                );
                $stmt->execute();
            }
        }

        // Baris aksi di-UPDATE per id (bukan hapus semua + insert ulang) - lihat
        // config/fpm_aksi_jawab_helper.php.
        simpanAksiJawabFpm($koneksi, $id_fpm, 'corrective', $corrective ?? [], $uploadDir);

        simpanAksiJawabFpm($koneksi, $id_fpm, 'preventive', $preventive ?? [], $uploadDir);

        simpanAksiJawabFpm($koneksi, $id_fpm, 'dokumen', $dokumen ?? [], $uploadDir);

        // ================== LAMPIRAN FPM (bukti umum, bisa lebih dari 1 file) ==================
        // Simpan lampiran baru dulu: kalau gagal (Exception -> rollback), file lampiran lama
        // yang dicentang hapus belum sempat di-unlink dari disk.
        simpanLampiranBaru($koneksi, $id_fpm, $uploadDir);
        hapusLampiranTerpilih($koneksi, $id_fpm, $uploadDir);

        // ================== UPDATE / INSERT fpm_pengiriman ==================
        $level      = $_SESSION['level'];
        $username   = $_SESSION['username'];

        date_default_timezone_set('Asia/Jakarta');
        $date = date('Y-m-d');

        $cek = $koneksi->prepare("SELECT id FROM fpm_pengiriman WHERE id_fpm = ?");
        $cek->bind_param("i", $id_fpm);
        $cek->execute();
        $resultCek = $cek->get_result();

        if ($resultCek->num_rows > 0) {

            $sqlPengiriman = "UPDATE fpm_pengiriman SET departement = ?, tgl = ?, dibuat = ?, user_dibuat = ?, tgl_dibuat = ? WHERE id_fpm = ?";
            $stmtPengiriman = $koneksi->prepare($sqlPengiriman);
            $stmtPengiriman->bind_param(
                "sssssi",
                $_POST['dept'],
                $date,
                $level,
                $username,
                $date,
                $id_fpm
            );
            $stmtPengiriman->execute();
        } else {

            $sqlPengiriman = "INSERT INTO fpm_pengiriman
          (id_fpm, departement, tgl, dibuat, user_dibuat, tgl_dibuat, diperiksa, disetujui)
          VALUES (?, ?, ?, ?, ?, ?, '', '')";

            $stmtPengiriman = $koneksi->prepare($sqlPengiriman);
            $stmtPengiriman->bind_param(
                "isssss",
                $id_fpm,
                $_POST['dept'],
                $date,
                $level,
                $username,
                $date
            );
            $stmtPengiriman->execute();
        }

        $stmtResetReject = $koneksi->prepare("UPDATE fpm_header SET status_reject = '' WHERE id_fpm = ?");
        $stmtResetReject->bind_param("i", $id_fpm);
        $stmtResetReject->execute();

        if ($isRevisiFlow) {
            catatFpmRevisiHistory($koneksi, $id_fpm, 'REVISI', $catatanPerbaikan, $_SESSION['username'], $_SESSION['level']);
        }

        $koneksi->commit();

        $nama_dept = $fpm['dept'];

        require_once __DIR__ . '/../config/wa_map.php';
        require_once __DIR__ . '/../services/whatsapp.php';

        $waTujuan = array_merge($waPlantHead);

        $pesan = $isRevisiFlow
            ? "FPM dari departemen *$nama_dept* sudah DIREVISI oleh " . $_SESSION['level'] . ". "
                . "Catatan perbaikan: \"$catatanPerbaikan\". "
                . "Mohon dicek kembali. No Form: " . ($fpm['no_form'] ?? $id_fpm)
            : "Terdapat FPM dari departemen *$nama_dept* ditujukan kepada Plant Head. "
                . "Mohon segera ditindaklanjuti. "
                . "No Form: " . ($fpm['no_form'] ?? $id_fpm);

        // Data sudah di-commit - gagal kirim WA jangan sampai bikin user terjebak di halaman
        // kosong (dan submit ulang), cukup dicatat di log + diberi tahu lewat alert.
        $waTarget = implode(',', array_filter(array_map('trim', $waTujuan)));
        $terkirim = $waTarget !== '' && kirimWA($waTarget, $pesan);

        if (!$terkirim) {
            error_log("WA jawab_fpm gagal: id_fpm=$id_fpm dept=$nama_dept target='$waTarget'");
            echo "<script>alert('Data berhasil diupdate, tetapi notifikasi WA ke Plant Head gagal dikirim. Mohon informasikan secara manual.');window.location='list_fpm_downtime.php';</script>";
            exit;
        }

        echo "<script>alert('Data berhasil diupdate');window.location='list_fpm_downtime.php';</script>";
    } catch (Exception $e) {

        $koneksi->rollback();
        echo "<script>alert(" . json_encode("Gagal update: " . $e->getMessage()) . ");history.back();</script>";
    }
}

// ================== AMBIL MASTER DEPT ==================
$master_dept = [];

$qDept = $koneksi->query("SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");

while ($rowDept = $qDept->fetch_assoc()) {
    $master_dept[] = $rowDept['nama_dept'];
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

        .multi-file {
            margin-top: 4px;
        }

        .multi-file-slots {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .multi-file-slot-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-add-file {
            margin-top: 6px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .btn-add-file:hover {
            background: #dbeafe;
        }

        .btn-add-file:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .btn-remove-file {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 4px;
            width: 22px;
            height: 22px;
            line-height: 1;
            cursor: pointer;
            font-size: 13px;
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

        .btn:hover {
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

        .file-preview iframe {
            width: 100px;
            height: 120px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h1>Jawab - Form Penanganan Masalah</h1>

                <?php if ($isRevisiFlow): ?>
                    <div style="border:1px solid #fecaca; background:#fef2f2; border-radius:8px; padding:14px; margin-bottom:16px;">
                        <div style="font-weight:700; color:#dc2626; font-size:13px; margin-bottom:4px;">FPM INI DIMINTA REVISI</div>
                        <div style="font-size:13px; color:#1f2937; white-space:pre-wrap;"><?= nl2br(htmlspecialchars($fpm['note'] ?? '')) ?></div>
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">Tanggal reject: <?= htmlspecialchars($fpm['tgl_reject'] ?? '-') ?></div>
                    </div>
                <?php endif; ?>

                <?php $riwayatRevisi = ambilFpmRevisiHistory($koneksi, (int) $id); ?>
                <?php if ($riwayatRevisi): ?>
                    <details style="margin-bottom:16px;">
                        <summary style="cursor:pointer; font-weight:600; font-size:13px; color:#374151;">Riwayat Revisi (<?= count($riwayatRevisi) ?>)</summary>
                        <div style="margin-top:8px;"><?= renderFpmRevisiHistory($riwayatRevisi) ?></div>
                    </details>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_fpm" value="<?= $id ?>">

                    <?php if ($isRevisiFlow): ?>
                        <div class="info-box" style="border-color:#16a34a;">
                            <div class="label" style="color:#16a34a;">Catatan Perbaikan (wajib diisi)</div>
                            <textarea name="catatan_perbaikan" required rows="3"
                                style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;"
                                placeholder="Jelaskan bagian mana yang sudah Anda perbaiki, supaya yang meminta revisi tahu tanpa perlu tanya lagi..."></textarea>
                        </div>
                    <?php endif; ?>

                    <div class="info-box">
                        <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">

                            <div class="label">No Reg FPM</div>
                            <input type="text" name="no_reg_fpm"
                                value="<?= htmlspecialchars($fpm['no_reg_fpm'] ?? '') ?>" readonly>

                            <div class="label">Kejadian Berulang ke</div>
                            <input type="number" name="kejadian_ulang"
                                value="<?= htmlspecialchars($fpm['kejadian_berulang_ke'] ?? '') ?>" readonly>

                            <div class="label">Shift</div>
                            <input type="text" name="shift"
                                value="<?= htmlspecialchars($detail['shift'] ?? '') ?>" readonly>

                            <div class="label">Nama Part</div>
                            <input type="text" name="nama_part"
                                value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>" readonly>

                            <div class="label">Total Lost Time</div>
                            <input type="number" name="total_lost_time"
                                value="<?= htmlspecialchars($detail['total_lost_time'] ?? '') ?>" readonly>

                            <div class="label">No Part</div>
                            <input type="text" name="no_part"
                                value="<?= htmlspecialchars($fpm['no_part'] ?? '') ?>" readonly>

                            <div class="label">Departemen</div>
                            <input type="text" name="dept"
                                value="<?= htmlspecialchars($fpm['dept'] ?? '') ?>" readonly>

                            <div class="label">Mold #</div>
                            <input type="text" name="mold_no"
                                value="<?= htmlspecialchars($fpm['mold_no'] ?? '') ?>" readonly>

                            <div class="label">Tgl Masalah</div>
                            <input type="text" name="tgl_ditemukan"
                                value="<?= !empty($fpm['tgl_ditemukan']) ? date('d-m-Y', strtotime($fpm['tgl_ditemukan'])) : '' ?>" readonly>

                            <div class="label">Model</div>
                            <input type="text" name="model"
                                value="<?= htmlspecialchars($fpm['model'] ?? '') ?>" readonly>

                            <div class="label">Tgl Terbit FPM</div>
                            <input type="text" name="tgl_terbit"
                                value="<?= !empty($fpm['tgl_terbit']) ? date('d-m-Y', strtotime($fpm['tgl_terbit'])) : '' ?>" readonly>

                            <div class="label">Machine</div>
                            <input type="text" name="machine_no"
                                value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>" readonly>

                            <div class="label">Jenis Masalah</div>
                            <input type="text" name="jenis_masalah"
                                value="<?= htmlspecialchars($fpm['jenis_masalah'] ?? '') ?>" readonly>

                        </div>
                    </div>

                    <div class="section-title">Analisa Masalah (Occurrence)</div>

                    <table id="analisaTable">
                        <tr>
                            <th>Category</th>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                            <th>Action</th>
                        </tr>

                        <?php if (!empty($analisa)): ?>
                            <?php $no = 0;
                            foreach ($analisa as $row): ?>
                                <tr>
                                    <td>
                                        <select name="analisa[<?= $no ?>][kategori]">
                                            <option value="">-- Pilih --</option>
                                            <option value="MAN" <?= $row['kategori'] == 'MAN' ? 'selected' : '' ?>>Man</option>
                                            <option value="METHOD" <?= $row['kategori'] == 'METHOD' ? 'selected' : '' ?>>Method</option>
                                            <option value="MATERIAL" <?= $row['kategori'] == 'MATERIAL' ? 'selected' : '' ?>>Material</option>
                                            <option value="MACHINE" <?= $row['kategori'] == 'MACHINE' ? 'selected' : '' ?>>Machine</option>
                                        </select>
                                    </td>

                                    <td><textarea name="analisa[<?= $no ?>][why_1]"><?= htmlspecialchars($row['why_1']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_2]"><?= htmlspecialchars($row['why_2']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_3]"><?= htmlspecialchars($row['why_3']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_4]"><?= htmlspecialchars($row['why_4']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_5]"><?= htmlspecialchars($row['why_5']) ?></textarea></td>

                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addAnalisaRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php $no++;
                            endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <select name="analisa[0][kategori]">
                                        <option value="">-- Pilih --</option>
                                        <option value="MAN">Man</option>
                                        <option value="METHOD">Method</option>
                                        <option value="MATERIAL">Material</option>
                                        <option value="MACHINE">Machine</option>
                                    </select>
                                </td>

                                <td><textarea name="analisa[0][why_1]"></textarea></td>
                                <td><textarea name="analisa[0][why_2]"></textarea></td>
                                <td><textarea name="analisa[0][why_3]"></textarea></td>
                                <td><textarea name="analisa[0][why_4]"></textarea></td>
                                <td><textarea name="analisa[0][why_5]"></textarea></td>

                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addAnalisaRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="section-title">Analisa Masalah (Flow Out)</div>

                    <table id="flowoutTable">
                        <tr>
                            <th>Category</th>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                            <th>Action</th>
                        </tr>

                        <?php if (!empty($flowout)): ?>
                            <?php $no = 0;
                            foreach ($flowout as $row): ?>
                                <tr>
                                    <td>
                                        <select name="flowout[<?= $no ?>][kategori]">
                                            <option value="">-- Pilih --</option>
                                            <option value="MAN" <?= $row['kategori'] == 'MAN' ? 'selected' : '' ?>>Man</option>
                                            <option value="METHOD" <?= $row['kategori'] == 'METHOD' ? 'selected' : '' ?>>Method</option>
                                            <option value="MATERIAL" <?= $row['kategori'] == 'MATERIAL' ? 'selected' : '' ?>>Material</option>
                                            <option value="MACHINE" <?= $row['kategori'] == 'MACHINE' ? 'selected' : '' ?>>Machine</option>
                                        </select>
                                    </td>

                                    <td><textarea name="flowout[<?= $no ?>][why_1]"><?= htmlspecialchars($row['why_1']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_2]"><?= htmlspecialchars($row['why_2']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_3]"><?= htmlspecialchars($row['why_3']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_4]"><?= htmlspecialchars($row['why_4']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_5]"><?= htmlspecialchars($row['why_5']) ?></textarea></td>

                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addFlowoutRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php $no++;
                            endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td>
                                    <select name="flowout[0][kategori]">
                                        <option value="">-- Pilih --</option>
                                        <option value="MAN">Man</option>
                                        <option value="METHOD">Method</option>
                                        <option value="MATERIAL">Material</option>
                                        <option value="MACHINE">Machine</option>
                                    </select>
                                </td>

                                <td><textarea name="flowout[0][why_1]"></textarea></td>
                                <td><textarea name="flowout[0][why_2]"></textarea></td>
                                <td><textarea name="flowout[0][why_3]"></textarea></td>
                                <td><textarea name="flowout[0][why_4]"></textarea></td>
                                <td><textarea name="flowout[0][why_5]"></textarea></td>

                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addFlowoutRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="section-title">Corrective Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <?= inputIdDimuatAksiJawabFpm('corrective', $corrective ?? []) ?>
                    <table class="action-table" id="correctiveTable">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <!-- <th>Status</th> -->
                            <th>File</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                        <?php if (!empty($corrective)): ?>
                            <?php foreach ($corrective as $row): ?>
                                <tr>
                                    <td><textarea name="corrective[tindakan][]"><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                    <td><input type="date" name="corrective[due_date][]" value="<?= $row['due_date'] ?>"></td>
                                    <td><input type="date" name="corrective[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <select name="corrective[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($master_dept as $dept): ?>
                                                <option value="<?= htmlspecialchars($dept) ?>"
                                                    <?= ($row['pic'] == $dept) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($dept) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <!-- <td>
                                        <select name="corrective[status][]">
                                            <option value="OPEN" <?= ($row['status'] ?? '') == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= ($row['status'] ?? '') == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                    </td> -->
                                    <td>
                                        <input type="file" name="corrective_file[]" data-bukti-ui>
                                        <input type="hidden" name="corrective[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <input type="hidden" name="corrective[old_status][]" value="<?= htmlspecialchars($row['status'] ?? 'OPEN') ?>">
                                        <input type="hidden" name="corrective[row_uid][]" value="<?= $row['id'] ?>">
                                        <?php if (!empty($row['file'])): ?>
                                            <?php
                                            $filePath = "../uploads/fpm_dt/" . $row['file'];
                                            $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <img src="<?= $filePath ?>">
                                                    </a>
                                                <?php elseif ($ext == 'pdf'): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <iframe src="<?= $filePath ?>"></iframe>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach (ambilFileTambahanAksi($koneksi, 'corrective', $row['id']) as $ef): ?>
                                            <?php
                                            $efPath = "../uploads/fpm_dt/" . $ef;
                                            $efExt = strtolower(pathinfo($ef, PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($efExt, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $efPath ?>" target="_blank"><img src="<?= $efPath ?>"></a>
                                                <?php elseif ($efExt == 'pdf'): ?>
                                                    <a href="<?= $efPath ?>" target="_blank"><iframe src="<?= $efPath ?>"></iframe></a>
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="corrective[old_extra_file][<?= $row['id'] ?>][]" value="<?= htmlspecialchars($ef) ?>">
                                        <?php endforeach; ?>
                                        <?php renderMultiFileInput("corrective_extra_file[" . $row['id'] . "][]", "File pendukung lain (PDF/JPG/PNG, maks 5 file)", "+ Tambah File Lain"); ?>
                                    </td>
                                    <td><textarea name="corrective[note][]"><?= htmlspecialchars($row['note'] ?? '') ?></textarea></td>
                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
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
                                        <?php foreach ($master_dept as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>">
                                                <?= htmlspecialchars($dept) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <!-- <td>
                                    <select name="corrective[status][]">
                                        <option value="Open">Open</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </td> -->
                                <td>
                                    <input type="file" name="corrective_file[]" data-bukti-ui>
                                    <input type="hidden" name="corrective[old_status][]" value="OPEN">
                                    <input type="hidden" name="corrective[row_uid][]" value="0">
                                    <?php renderMultiFileInput("corrective_extra_file[0][]", "File pendukung lain (PDF/JPG/PNG, maks 5 file)", "+ Tambah File Lain"); ?>
                                </td>
                                <td><textarea name="corrective[note][]"></textarea></td>
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Preventive Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <?= inputIdDimuatAksiJawabFpm('preventive', $preventive ?? []) ?>
                    <table class="action-table" id="preventiveTable">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <!-- <th>Status</th> -->
                            <th>File</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                        <?php if (!empty($preventive)): ?>
                            <?php foreach ($preventive as $row): ?>
                                <tr>
                                    <td><textarea name="preventive[tindakan][]"><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                    <td><input type="date" name="preventive[due_date][]" value="<?= $row['due_date'] ?>"></td>
                                    <td><input type="date" name="preventive[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <select name="preventive[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($master_dept as $dept): ?>
                                                <option value="<?= htmlspecialchars($dept) ?>"
                                                    <?= ($row['pic'] == $dept) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($dept) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <!-- <td>
                                        <select name="preventive[status][]">
                                            <option value="OPEN" <?= ($row['status'] ?? '') == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= ($row['status'] ?? '') == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                    </td> -->
                                    <td>
                                        <input type="file" name="preventive_file[]" data-bukti-ui>
                                        <input type="hidden" name="preventive[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <input type="hidden" name="preventive[old_status][]" value="<?= htmlspecialchars($row['status'] ?? 'OPEN') ?>">
                                        <input type="hidden" name="preventive[row_uid][]" value="<?= $row['id'] ?>">
                                        <?php if (!empty($row['file'])): ?>
                                            <?php
                                            $filePath = "../uploads/fpm_dt/" . $row['file'];
                                            $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <img src="<?= $filePath ?>">
                                                    </a>
                                                <?php elseif ($ext == 'pdf'): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <iframe src="<?= $filePath ?>"></iframe>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach (ambilFileTambahanAksi($koneksi, 'preventive', $row['id']) as $ef): ?>
                                            <?php
                                            $efPath = "../uploads/fpm_dt/" . $ef;
                                            $efExt = strtolower(pathinfo($ef, PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($efExt, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $efPath ?>" target="_blank"><img src="<?= $efPath ?>"></a>
                                                <?php elseif ($efExt == 'pdf'): ?>
                                                    <a href="<?= $efPath ?>" target="_blank"><iframe src="<?= $efPath ?>"></iframe></a>
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="preventive[old_extra_file][<?= $row['id'] ?>][]" value="<?= htmlspecialchars($ef) ?>">
                                        <?php endforeach; ?>
                                        <?php renderMultiFileInput("preventive_extra_file[" . $row['id'] . "][]", "File pendukung lain (PDF/JPG/PNG, maks 5 file)", "+ Tambah File Lain"); ?>
                                    </td>
                                    <td><textarea name="preventive[note][]"><?= htmlspecialchars($row['note'] ?? '') ?></textarea></td>
                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
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
                                        <?php foreach ($master_dept as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>">
                                                <?= htmlspecialchars($dept) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <!-- <td>
                                    <select name="preventive[status][]">
                                        <option value="Open">Open</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </td> -->
                                <td>
                                    <input type="file" name="preventive_file[]" data-bukti-ui>
                                    <input type="hidden" name="preventive[old_status][]" value="OPEN">
                                    <input type="hidden" name="preventive[row_uid][]" value="0">
                                    <?php renderMultiFileInput("preventive_extra_file[0][]", "File pendukung lain (PDF/JPG/PNG, maks 5 file)", "+ Tambah File Lain"); ?>
                                </td>
                                <td><textarea name="preventive[note][]"></textarea></td>
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Update Dokumen</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <?= inputIdDimuatAksiJawabFpm('dokumen', $dokumen ?? []) ?>
                    <table id="dokumenTable">
                        <tr>
                            <th>Dokumen</th>
                            <th>Due Date</th>
                            <th>Actual</th>
                            <th>PIC</th>
                            <!-- <th>Status</th> -->
                            <th>File</th>
                            <th>Action</th>
                        </tr>
                        <?php if (!empty($dokumen)): ?>
                            <?php foreach ($dokumen as $row): ?>
                                <tr>
                                    <td>
                                        <input list="docType" name="dokumen[dokumen][]"
                                            value="<?= htmlspecialchars($row['dokumen']) ?>">
                                    </td>
                                    <td><input type="date" name="dokumen[due_date][]" value="<?= $row['due_date'] ?>"></td>
                                    <td><input type="date" name="dokumen[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <select name="dokumen[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($master_dept as $dept): ?>
                                                <option value="<?= htmlspecialchars($dept) ?>"
                                                    <?= ($row['pic'] == $dept) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($dept) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <!-- <td>
                                        <select name="dokumen[status][]">
                                            <option value="OPEN" <?= ($row['status'] ?? '') == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= ($row['status'] ?? '') == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                    </td> -->
                                    <td>
                                        <input type="file" name="dokumen_file[]" data-bukti-ui>
                                        <input type="hidden" name="dokumen[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <input type="hidden" name="dokumen[old_status][]" value="<?= htmlspecialchars($row['status'] ?? 'OPEN') ?>">
                                        <input type="hidden" name="dokumen[row_uid][]" value="<?= $row['id'] ?>">
                                        <?php if (!empty($row['file'])): ?>
                                            <?php
                                            $filePath = "../uploads/fpm_dt/" . $row['file'];
                                            $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <img src="<?= $filePath ?>">
                                                    </a>
                                                <?php elseif ($ext == 'pdf'): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <iframe src="<?= $filePath ?>"></iframe>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach (ambilFileTambahanAksi($koneksi, 'dokumen', $row['id']) as $ef): ?>
                                            <?php
                                            $efPath = "../uploads/fpm_dt/" . $ef;
                                            $efExt = strtolower(pathinfo($ef, PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($efExt, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $efPath ?>" target="_blank"><img src="<?= $efPath ?>"></a>
                                                <?php elseif ($efExt == 'pdf'): ?>
                                                    <a href="<?= $efPath ?>" target="_blank"><iframe src="<?= $efPath ?>"></iframe></a>
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="dokumen[old_extra_file][<?= $row['id'] ?>][]" value="<?= htmlspecialchars($ef) ?>">
                                        <?php endforeach; ?>
                                        <?php renderMultiFileInput("dokumen_extra_file[" . $row['id'] . "][]", "File pendukung lain (PDF/JPG/PNG, maks 5 file)", "+ Tambah File Lain"); ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
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
                                        <?php foreach ($master_dept as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>">
                                                <?= htmlspecialchars($dept) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <!-- <td>
                                    <select name="dokumen[status][]">
                                        <option value="Open">Open</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </td> -->
                                <td>
                                    <input type="file" name="dokumen_file[]" data-bukti-ui>
                                    <input type="hidden" name="dokumen[old_status][]" value="OPEN">
                                    <input type="hidden" name="dokumen[row_uid][]" value="0">
                                    <?php renderMultiFileInput("dokumen_extra_file[0][]", "File pendukung lain (PDF/JPG/PNG, maks 5 file)", "+ Tambah File Lain"); ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <datalist id="docType">
                        <option value="IK">
                        <option value="FMEA">
                        <option value="QCPC">
                        <option value="Check Sheet">
                    </datalist>

                    <div class="section-title">Lampiran FPM</div>
                    <small class="upload-video-hint">
                        Upload lampiran/bukti FPM di sini, format PDF/JPG/PNG, maksimal 5 file.
                        Catatan: jangan upload lampiran FPM di kolom File pada Corrective/Preventive/Dokumen di atas -
                        kolom itu khusus untuk bukti countermeasure.
                    </small>

                    <?php $lampiranFpm = ambilLampiranFpm($koneksi, (int) $id); ?>
                    <?php if (!empty($lampiranFpm)): ?>
                        <div style="display:flex; flex-wrap:wrap; gap:12px; margin:10px 0;">
                            <?php foreach ($lampiranFpm as $lp): ?>
                                <?php
                                $filePathLp = "../uploads/fpm_dt/" . $lp['file'];
                                $extLp = strtolower(pathinfo($lp['file'], PATHINFO_EXTENSION));
                                ?>
                                <div style="border:1px solid #e5e7eb; border-radius:6px; padding:8px; text-align:center; font-size:11px; width:110px;">
                                    <?php if (in_array($extLp, ['jpg', 'jpeg', 'png'])): ?>
                                        <a href="<?= $filePathLp ?>" target="_blank">
                                            <img src="<?= $filePathLp ?>" style="width:80px; height:auto; border:1px solid #ddd; border-radius:4px;">
                                        </a>
                                    <?php elseif ($extLp == 'pdf'): ?>
                                        <a href="<?= $filePathLp ?>" target="_blank">
                                            <iframe src="<?= $filePathLp ?>" style="width:90px; height:110px; border:1px solid #ddd; border-radius:4px;"></iframe>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= $filePathLp ?>" target="_blank" style="display:block; word-break:break-all;">
                                            📎 <?= htmlspecialchars($lp['original_name']) ?>
                                        </a>
                                    <?php endif; ?>
                                    <label style="display:flex; align-items:center; justify-content:center; gap:4px; margin-top:6px; color:#dc2626; cursor:pointer;">
                                        <input type="checkbox" name="hapus_lampiran[]" value="<?= (int) $lp['id'] ?>"> Hapus
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php renderMultiFileInput("lampiran_file[]", null); ?>

                    <div class="btn-group">
                        <button class="btn btn-submit">Submit</button>
                        <button class="btn btn-cancel">Cancel</button>
                    </div>

                </form>

            </div>

        </main>
    </div>

    <script>
        (function() {
            const MAX_VIDEO_SIZE = 10 * 1024 * 1024; // 10MB
            const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];

            function isVideoFile(file) {
                if (file.type && file.type.startsWith('video/')) return true;
                const ext = file.name.split('.').pop().toLowerCase();
                return VIDEO_EXT.includes(ext);
            }

            const MULTI_FILE_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

            function isiActualDateOtomatis(fileInput) {
                if (!fileInput.files[0]) return;

                const row = fileInput.closest('tr');
                const actualDateInput = row && row.querySelector('input[name*="[actual_date]"]');
                if (!actualDateInput) return;

                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                actualDateInput.value = yyyy + '-' + mm + '-' + dd;
            }

            document.addEventListener('change', function(e) {
                if (!(e.target.matches && e.target.matches('input[type="file"]'))) return;

                if (e.target.matches('.multi-file-input')) {
                    const file = e.target.files[0];
                    if (file) {
                        const ext = file.name.split('.').pop().toLowerCase();
                        if (!MULTI_FILE_ALLOWED_EXT.includes(ext)) {
                            alert('Hanya file PDF, JPG, JPEG, atau PNG yang diperbolehkan.');
                            e.target.value = '';
                        }
                    }
                } else {
                    const file = e.target.files[0];
                    if (file && isVideoFile(file) && file.size > MAX_VIDEO_SIZE) {
                        alert('Ukuran video melebihi 10MB. Silakan pilih file video yang lebih kecil.');
                        e.target.value = '';
                    }
                }

                // Baris Corrective/Preventive/Dokumen: begitu file lolos validasi & terpilih,
                // isi otomatis Actual Date hari ini - status tetap OPEN (tidak diubah di sini).
                isiActualDateOtomatis(e.target);
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

        function addFlowoutRow(btn) {
            const table = document.getElementById("flowoutTable");
            const rows = table.querySelectorAll("tr");
            const lastRow = rows[rows.length - 1];

            const newIndex = rows.length - 1;

            const newRow = lastRow.cloneNode(true);

            newRow.querySelectorAll("textarea").forEach(el => el.value = "");

            newRow.querySelectorAll("textarea, input[type=hidden]").forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, "[" + newIndex + "]");
            });

            table.appendChild(newRow);
        }

        function addFlowoutRow(button) {
            let table = document.getElementById("flowoutTable");
            let rowCount = table.rows.length - 1; // minus header

            let newRow = table.insertRow();
            newRow.innerHTML = `
        <td>
            <select name="flowout[${rowCount}][kategori]">
                <option value="">-- Pilih --</option>
                <option value="MAN">Man</option>
                <option value="METHOD">Method</option>
                <option value="MATERIAL">Material</option>
                <option value="MACHINE">Machine</option>
            </select>
        </td>
        <td><textarea name="flowout[${rowCount}][why_1]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_2]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_3]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_4]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_5]"></textarea></td>
        <td class="text-center">
            <button type="button" class="btn-action add" onclick="addFlowoutRow(this)">+</button>
            <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
        </td>
    `;
        }

        const MULTI_FILE_MAX = 5;

        function addFileSlot(btn) {
            const wrapper = btn.closest(".multi-file");
            const slots = wrapper.querySelector(".multi-file-slots");
            const count = slots.querySelectorAll('input[type="file"]').length;

            if (count >= MULTI_FILE_MAX) {
                alert("Maksimal " + MULTI_FILE_MAX + " file.");
                return;
            }

            const newInput = document.createElement("input");
            newInput.type = "file";
            newInput.className = "multi-file-input";
            newInput.name = wrapper.dataset.name;
            newInput.accept = wrapper.dataset.accept;

            const slotRow = document.createElement("div");
            slotRow.className = "multi-file-slot-row";
            slotRow.appendChild(newInput);

            const removeBtn = document.createElement("button");
            removeBtn.type = "button";
            removeBtn.className = "btn-remove-file";
            removeBtn.textContent = "×";
            slotRow.appendChild(removeBtn);

            slots.appendChild(slotRow);
            updateAddFileButton(wrapper);
        }

        // Delegated (bukan onclick per elemen) supaya tetap berfungsi walau
        // baris di-clone lewat addRow() saat menambah baris Corrective/Preventive/Dokumen baru.
        document.addEventListener("click", function(e) {
            if (e.target.matches && e.target.matches(".btn-remove-file")) {
                const slotRow = e.target.closest(".multi-file-slot-row");
                const wrapper = e.target.closest(".multi-file");
                if (slotRow) slotRow.remove();
                if (wrapper) updateAddFileButton(wrapper);
            }
        });

        function updateAddFileButton(wrapper) {
            const addBtn = wrapper.querySelector(".btn-add-file");
            const count = wrapper.querySelectorAll('input[type="file"]').length;
            addBtn.disabled = count >= MULTI_FILE_MAX;
        }

        function addAnalisaRow(btn) {
            const table = document.getElementById("analisaTable");
            const rows = table.querySelectorAll("tr");
            const lastRow = rows[rows.length - 1];

            const newIndex = rows.length - 1;

            const newRow = lastRow.cloneNode(true);

            newRow.querySelectorAll("textarea").forEach(el => el.value = "");
            newRow.querySelectorAll("select").forEach(el => el.selectedIndex = 0);

            newRow.querySelectorAll("select, textarea").forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, "[" + newIndex + "]");
            });

            table.appendChild(newRow);
        }

        function addRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const newRow = row.cloneNode(true);

            // reset input & textarea
            newRow.querySelectorAll("input, textarea").forEach(el => {
                el.value = "";
            });

            // reset select
            newRow.querySelectorAll("select").forEach(el => {
                el.selectedIndex = 0;
            });

            // hapus preview file lama
            newRow.querySelectorAll(".file-preview").forEach(el => {
                el.remove();
            });

            // baris baru belum punya file tambahan lama, hapus referensinya
            newRow.querySelectorAll('input[name*="[old_extra_file]"]').forEach(el => {
                el.remove();
            });

            // reset file input
            newRow.querySelectorAll('input[type="file"]').forEach(el => {
                el.value = "";
            });

            // baris baru mulai kosong lagi (tanpa slot file tambahan yang mungkin
            // sudah ditambah di baris asal sebelum di-duplikasi)
            newRow.querySelectorAll(".multi-file-slot-row").forEach(el => {
                el.remove();
            });
            newRow.querySelectorAll(".btn-add-file").forEach(el => {
                el.disabled = false;
            });

            // baris baru butuh row_uid unik supaya upload file tambahan tidak tertukar antar baris
            const freshUid = "new" + Date.now() + Math.floor(Math.random() * 10000);
            newRow.querySelectorAll('input[name$="[row_uid][]"]').forEach(el => {
                el.value = freshUid;
            });
            newRow.querySelectorAll('.multi-file[data-name*="_extra_file["]').forEach(el => {
                el.dataset.name = el.dataset.name.replace(/_extra_file\[.*?\]\[\]$/, "_extra_file[" + freshUid + "][]");
                el.querySelectorAll('input[type="file"]').forEach(inp => inp.name = el.dataset.name);
            });

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
                if (index === 0) return;
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

<?php require_once __DIR__ . '/../config/upload_bukti_ui_helper.php'; echo renderUploadBuktiUI(); ?>
</body>

</html>