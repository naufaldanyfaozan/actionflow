<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

if (($_SESSION['dept'] ?? '') !== 'QA') {
    die('Halaman ini hanya untuk Staff QA.');
}

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';

$id = $_GET['id'] ?? '';

$fpm = [];

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ? AND flow_type = 'QA'";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    $sql2 = "SELECT * FROM fpm_detail_masalah WHERE id_fpm = ?";
    $stmt2 = $koneksi->prepare($sql2);
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $detail = $stmt2->get_result()->fetch_assoc() ?: [];
}

if (!$fpm) {
    die('Data FPM QA tidak ditemukan');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id_fpm = $_POST['id_fpm'];

    $catatanPerbaikan = trim($_POST['catatan_perbaikan'] ?? '');
    if ($catatanPerbaikan === '') {
        die('Catatan perbaikan wajib diisi - jelaskan bagian mana yang sudah Anda revisi.');
    }

    $tgl_ditemukan_db = !empty($_POST['tgl_ditemukan']) ? date('Y-m-d', strtotime($_POST['tgl_ditemukan'])) : null;

    $ng_area_percent  = ($_POST['ng_area_percent'] ?? '') !== '' ? (float) $_POST['ng_area_percent'] : null;
    $ng_total_percent = ($_POST['ng_total_percent'] ?? '') !== '' ? (float) $_POST['ng_total_percent'] : null;

    $koneksi->begin_transaction();

    try {

        $sql = "UPDATE fpm_header SET
                    nama_part=?,
                    no_part=?,
                    dept=?,
                    internal_dept=?,
                    mold_no=?,
                    tgl_ditemukan=?,
                    model=?,
                    machine_no=?,
                    jenis_masalah=?,
                    jenis_masalah_detail=?,
                    status_reject=''
                WHERE id_fpm=?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "ssssssssssi",
            $_POST['nama_part'],
            $_POST['no_part'],
            $_POST['dept'],
            $_POST['dept'],
            $_POST['mold_no'],
            $tgl_ditemukan_db,
            $_POST['model'],
            $_POST['machine_no'],
            $_POST['jenis_masalah'],
            $_POST['jenis_masalah_detail'],
            $id_fpm
        );
        $stmt->execute();

        $cekDetail = $koneksi->prepare("SELECT id FROM fpm_detail_masalah WHERE id_fpm = ?");
        $cekDetail->bind_param("i", $id_fpm);
        $cekDetail->execute();
        $resultDetail = $cekDetail->get_result();

        if ($resultDetail->num_rows > 0) {
            $sqlDetail = "UPDATE fpm_detail_masalah SET ng_area_percent = ?, ng_total_percent = ? WHERE id_fpm = ?";
            $stmtDetail = $koneksi->prepare($sqlDetail);
            $stmtDetail->bind_param("ddi", $ng_area_percent, $ng_total_percent, $id_fpm);
            $stmtDetail->execute();
        } else {
            $sqlDetail = "INSERT INTO fpm_detail_masalah (id_fpm, ng_area_percent, ng_total_percent) VALUES (?, ?, ?)";
            $stmtDetail = $koneksi->prepare($sqlDetail);
            $stmtDetail->bind_param("idd", $id_fpm, $ng_area_percent, $ng_total_percent);
            $stmtDetail->execute();
        }

        catatFpmRevisiHistory($koneksi, (int) $id_fpm, 'REVISI', $catatanPerbaikan, $_SESSION['username'], $_SESSION['level'] ?? 'Staff QA');

        $koneksi->commit();

        require_once __DIR__ . '/../config/fpm_qa_wa_helper.php';
        require_once __DIR__ . '/../services/whatsapp.php';

        // Ditujukan ke tahap approval yang sedang menunggu (yang di-reset oleh pihak
        // yang meminta revisi), bukan selalu ke Dept Head QA.
        $approverBerikutnya = approverBerikutnyaFpmQa($koneksi, (int) $id_fpm);

        if ($approverBerikutnya && !empty($approverBerikutnya['wa'])) {
            $pesan = "FPM QA (No Form: " . ($fpm['no_form'] ?? $id_fpm) . ") sudah DIREVISI oleh Staff QA. "
                . "Catatan perbaikan: \"$catatanPerbaikan\". Mohon dicek kembali dan di-approve ({$approverBerikutnya['tahap']}).";

            if (!kirimWA(implode(',', $approverBerikutnya['wa']), $pesan)) {
                error_log("Gagal kirim WA revisi FPM QA: $id_fpm");
            }
        }

        echo "<script>alert('Revisi berhasil disimpan');window.location='form_fpm.php?id=$id_fpm';</script>";
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
    <title>Revisi FPM Internal QA</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />

    <style>
        * { box-sizing: border-box; font-family: "Segoe UI", Roboto, Arial, sans-serif; }
        body { background: #f8fafc; margin: 0; color: #1f2933; }
        .container { max-width: 900px; margin: 30px auto; background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,.05); }
        h1 { text-align: center; font-size: 20px; margin-bottom: 20px; text-transform: uppercase; }
        .info-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 25px; background: #f9fafb; }
        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px 30px; }
        .label { font-weight: 600; color: #6b7280; text-align: left; }
        .info-grid input, .info-grid select { width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; background: #fff; }
        .btn-group { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .btn-submit { background: #2563eb; color: #fff; }
        .btn-cancel { background: #ef4444; color: #fff; text-decoration: none; display: inline-flex; align-items: center; }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h1>Revisi FPM Internal QA</h1>

                <?php if ($fpm['status_reject'] === 'REJECT'): ?>
                    <div class="info-box" style="background:#fee2e2; border-color:#fecaca;">
                        <strong>Alasan Revisi:</strong> <?= htmlspecialchars($fpm['note'] ?? '') ?>
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">Tanggal reject: <?= htmlspecialchars($fpm['tgl_reject'] ?? '-') ?></div>
                    </div>
                <?php endif; ?>

                <?php $riwayatRevisi = ambilFpmRevisiHistory($koneksi, (int) $id); ?>
                <?php if ($riwayatRevisi): ?>
                    <details class="info-box" style="padding:12px 15px;">
                        <summary style="cursor:pointer; font-weight:600; font-size:13px; color:#374151;">Riwayat Revisi (<?= count($riwayatRevisi) ?>)</summary>
                        <div style="margin-top:8px;"><?= renderFpmRevisiHistory($riwayatRevisi) ?></div>
                    </details>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="id_fpm" value="<?= $id ?>">

                    <div class="info-box" style="border-color:#16a34a;">
                        <div class="label" style="color:#16a34a;">Catatan Perbaikan (wajib diisi)</div>
                        <textarea name="catatan_perbaikan" required rows="3"
                            style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"
                            placeholder="Jelaskan bagian mana yang sudah Anda perbaiki, supaya Dept Head QA tahu tanpa perlu tanya lagi..."></textarea>
                    </div>

                    <div class="info-box">
                        <div class="info-grid">

                            <div class="label">Nama Part</div>
                            <input type="text" name="nama_part" value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>">

                            <div class="label">No Part</div>
                            <input type="text" name="no_part" value="<?= htmlspecialchars($fpm['no_part'] ?? '') ?>">

                            <div class="label">Model</div>
                            <input type="text" name="model" value="<?= htmlspecialchars($fpm['model'] ?? '') ?>">

                            <div class="label">Machine</div>
                            <input type="text" name="machine_no" value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>">

                            <div class="label">Mold #</div>
                            <input type="text" name="mold_no" value="<?= htmlspecialchars($fpm['mold_no'] ?? '') ?>">

                            <div class="label">Dept Terkait</div>
                            <select name="dept">
                                <?php foreach ($deptList as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept) ?>" <?= ($fpm['dept'] ?? '') === $dept ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="label">Tgl Masalah</div>
                            <input type="text" name="tgl_ditemukan"
                                value="<?= !empty($fpm['tgl_ditemukan']) ? date('d-m-Y', strtotime($fpm['tgl_ditemukan'])) : '' ?>">

                            <div class="label">Jenis Masalah</div>
                            <input type="text" name="jenis_masalah" value="<?= htmlspecialchars($fpm['jenis_masalah'] ?? '') ?>">

                            <div class="label">Detail Jenis Masalah</div>
                            <input type="text" name="jenis_masalah_detail" value="<?= htmlspecialchars($fpm['jenis_masalah_detail'] ?? '') ?>">

                            <div class="label">NG Area (%)</div>
                            <input type="number" step="0.01" min="0" max="100" name="ng_area_percent"
                                value="<?= htmlspecialchars($detail['ng_area_percent'] ?? '') ?>">

                            <div class="label">NG Total (%)</div>
                            <input type="number" step="0.01" min="0" max="100" name="ng_total_percent"
                                value="<?= htmlspecialchars($detail['ng_total_percent'] ?? '') ?>">

                        </div>
                    </div>

                    <div class="btn-group">
                        <button class="btn btn-submit">Simpan Revisi</button>
                        <a href="list_fpm_qa_semua.php" class="btn btn-cancel">Cancel</a>
                    </div>

                </form>

            </div>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>

</body>

</html>
