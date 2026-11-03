<?php
session_start();
if (!isset($_SESSION["username"])) header("location:../cek_login.php");

include '../koneksi.php';
require_once __DIR__ . '/../config/fpm_foto_edit_helper.php';

$id = $_GET['id'] ?? '';

$fpm = [];
$detail = [];

// Input tanggal di form berformat dd-mm-yyyy, kolom DB berformat Y-m-d.
function fixDate($date)
{
    $date = trim($date ?? '');
    if ($date === '') return null;
    $d = DateTime::createFromFormat('d-m-Y', $date) ?: date_create($date);
    return $d ? $d->format('Y-m-d') : null;
}

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    $sql2 = "SELECT shift, total_lost_time, file, file2, file3
             FROM fpm_detail_masalah 
             WHERE id_fpm = ?";
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
        $analisa[$row['kategori']] = $row;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id_fpm = (int) $_POST['id_fpm'];

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
        $tgl_ditemukan = fixDate($_POST['tgl_ditemukan'] ?? '');
        $tgl_terbit    = fixDate($_POST['tgl_terbit'] ?? '');
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "isssssssssi",
            $_POST['kejadian_ulang'],
            $_POST['nama_part'],
            $_POST['no_part'],
            $_POST['dept'],
            $_POST['mold_no'],
            $tgl_ditemukan,
            $_POST['model'],
            $tgl_terbit,
            $_POST['machine_no'],
            $_POST['jenis_masalah'],
            $id_fpm
        );
        $stmt->execute();

        $sql = "UPDATE fpm_detail_masalah 
                SET shift=?, total_lost_time=? 
                WHERE id_fpm=?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "sii",
            $_POST['shift'],
            $_POST['total_lost_time'],
            $id_fpm
        );
        $stmt->execute();

        simpanFotoEdit($koneksi, $id_fpm);

        if (isset($_POST['analisa'])) {
            $koneksi->query("DELETE FROM fpm_analisa WHERE id_fpm='$id_fpm'");

            foreach ($_POST['analisa'] as $kategori => $value) {

                $sql = "INSERT INTO fpm_analisa
                        (id_fpm,kategori,why_1,why_2,why_3,why_4,why_5)
                        VALUES (?,?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "issssss",
                    $id_fpm,
                    $kategori,
                    $value['why_1'],
                    $value['why_2'],
                    $value['why_3'],
                    $value['why_4'],
                    $value['why_5']
                );
                $stmt->execute();
            }
        }

        if (isset($_POST['corrective'])) {
            $koneksi->query("DELETE FROM fpm_corrective_action WHERE id_fpm='$id_fpm'");

            foreach ($_POST['corrective']['tindakan'] as $i => $tindakan) {

                if ($tindakan == '') continue;

                $due_date    = !empty($_POST['corrective']['due_date'][$i]) ? $_POST['corrective']['due_date'][$i] : null;
                $actual_date = !empty($_POST['corrective']['actual_date'][$i]) ? $_POST['corrective']['actual_date'][$i] : null;

                $sql = "INSERT INTO fpm_corrective_action
                        (id_fpm,tindakan,due_date,actual_date,pic,status)
                        VALUES (?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssss",
                    $id_fpm,
                    $tindakan,
                    $due_date,
                    $actual_date,
                    $_POST['corrective']['pic'][$i],
                    $_POST['corrective']['status'][$i]
                );
                $stmt->execute();
            }
        }

        if (isset($_POST['preventive'])) {
            $koneksi->query("DELETE FROM fpm_preventive_action WHERE id_fpm='$id_fpm'");

            foreach ($_POST['preventive']['tindakan'] as $i => $tindakan) {

                if ($tindakan == '') continue;

                $due_date    = !empty($_POST['preventive']['due_date'][$i]) ? $_POST['preventive']['due_date'][$i] : null;
                $actual_date = !empty($_POST['preventive']['actual_date'][$i]) ? $_POST['preventive']['actual_date'][$i] : null;

                $sql = "INSERT INTO fpm_preventive_action
                        (id_fpm,tindakan,due_date,actual_date,pic,status)
                        VALUES (?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssss",
                    $id_fpm,
                    $tindakan,
                    $due_date,
                    $actual_date,
                    $_POST['preventive']['pic'][$i],
                    $_POST['preventive']['status'][$i]
                );
                $stmt->execute();
            }
        }

        if (isset($_POST['dokumen'])) {
            $koneksi->query("DELETE FROM fpm_review_dokumen WHERE id_fpm='$id_fpm'");

            foreach ($_POST['dokumen']['dokumen'] as $i => $dok) {

                if ($dok == '') continue;

                $due_date    = !empty($_POST['dokumen']['due_date'][$i]) ? $_POST['dokumen']['due_date'][$i] : null;
                $actual_date = !empty($_POST['dokumen']['actual_date'][$i]) ? $_POST['dokumen']['actual_date'][$i] : null;

                $sql = "INSERT INTO fpm_review_dokumen
                        (id_fpm,dokumen,due_date,actual_date,pic,status)
                        VALUES (?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssss",
                    $id_fpm,
                    $dok,
                    $due_date,
                    $actual_date,
                    $_POST['dokumen']['pic'][$i],
                    $_POST['dokumen']['status'][$i]
                );
                $stmt->execute();
            }
        }

        // ================== SINKRON DEPT fpm_pengiriman ==================
        // Edit oleh Staff IMP bukan langkah approval: jangan sentuh dibuat/tgl
        // (dipakai list sebagai status Approved/Waiting & Tgl Jawab), dan jangan
        // buat baris baru. Hanya samakan departement bila barisnya sudah ada.
        $stmtPengiriman = $koneksi->prepare("UPDATE fpm_pengiriman SET departement = ? WHERE id_fpm = ?");
        $stmtPengiriman->bind_param("si", $_POST['dept'], $id_fpm);
        $stmtPengiriman->execute();


        $koneksi->commit();
        echo "<script>alert('Data berhasil diupdate');window.location='list_fpm_downtime.php';</script>";
    } catch (Exception $e) {

        $koneksi->rollback();
        echo "Gagal update: " . $e->getMessage();
    }
}

// ================== AMBIL DATA MASTER DEPT ==================
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

            /* kiri dan kanan sama besar */
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
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h1>Revisi Form Penanganan Masalah</h1>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_fpm" value="<?= $id ?>">

                    <div class="info-box">
                        <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">

                            <div class="label">No Reg FPM</div>
                            <input type="text" name="no_reg_fpm"
                                value="<?= htmlspecialchars($fpm['no_reg_fpm'] ?? '') ?>" >

                            <div class="label">Kejadian Berulang ke</div>
                            <input type="number" name="kejadian_ulang"
                                value="<?= htmlspecialchars($fpm['kejadian_berulang_ke'] ?? '') ?>" >

                            <div class="label">Shift</div>
                            <input type="text" name="shift"
                                value="<?= htmlspecialchars($detail['shift'] ?? '') ?>" >

                            <div class="label">Nama Part</div>
                            <input type="text" name="nama_part"
                                value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>" >

                            <div class="label">Total Lost Time</div>
                            <input type="number" name="total_lost_time"
                                value="<?= htmlspecialchars($detail['total_lost_time'] ?? '') ?>" >

                            <div class="label">No Part</div>
                            <input type="text" name="no_part"
                                value="<?= htmlspecialchars($fpm['no_part'] ?? '') ?>" >

                            <div class="label">Departemen</div>
                            <input type="text" name="dept"
                                value="<?= htmlspecialchars($fpm['dept'] ?? '') ?>" >

                            <div class="label">Mold #</div>
                            <input type="text" name="mold_no"
                                value="<?= htmlspecialchars($fpm['mold_no'] ?? '') ?>" >

                            <div class="label">Tgl Masalah</div>
                            <input type="text" name="tgl_ditemukan"
                                value="<?= !empty($fpm['tgl_ditemukan']) ? date('d-m-Y', strtotime($fpm['tgl_ditemukan'])) : '' ?>" >

                            <div class="label">Model</div>
                            <input type="text" name="model"
                                value="<?= htmlspecialchars($fpm['model'] ?? '') ?>" >

                            <div class="label">Tgl Terbit FPM</div>
                            <input type="text" name="tgl_terbit"
                                value="<?= !empty($fpm['tgl_terbit']) ? date('d-m-Y', strtotime($fpm['tgl_terbit'])) : '' ?>" >

                            <div class="label">Machine</div>
                            <input type="text" name="machine_no"
                                value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>" >

                            <div class="label">Jenis Masalah</div>
                            <input type="text" name="jenis_masalah"
                                value="<?= htmlspecialchars($fpm['jenis_masalah'] ?? '') ?>" >

                        </div>
                    </div>

                    <?php renderFotoEditFields($detail); ?>

                    <!-- ANALISA MASALAH
                    <div class="section-title">Analisa Masalah (Occurrence)</div>

                    <table>
                        <tr>
                            <th>Category</th>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                        </tr>
                        <tr>
                            <td>Man</td>
                            <td><input name="analisa[MAN][why_1]"
                                    value="<?= htmlspecialchars($analisa['MAN']['why_1'] ?? '') ?>"></td>
                            <td><input name="analisa[MAN][why_2]"
                                    value="<?= htmlspecialchars($analisa['MAN']['why_2'] ?? '') ?>"></td>
                            <td><input name="analisa[MAN][why_3]"
                                    value="<?= htmlspecialchars($analisa['MAN']['why_3'] ?? '') ?>"></td>
                            <td><input name="analisa[MAN][why_4]"
                                    value="<?= htmlspecialchars($analisa['MAN']['why_4'] ?? '') ?>"></td>
                            <td><input name="analisa[MAN][why_5]"
                                    value="<?= htmlspecialchars($analisa['MAN']['why_5'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td>Method</td>
                            <td><input name="analisa[METHOD][why_1]"
                                    value="<?= htmlspecialchars($analisa['METHOD']['why_1'] ?? '') ?>"></td>
                            <td><input name="analisa[METHOD][why_2]"
                                    value="<?= htmlspecialchars($analisa['METHOD']['why_2'] ?? '') ?>"></td>
                            <td><input name="analisa[METHOD][why_3]"
                                    value="<?= htmlspecialchars($analisa['METHOD']['why_3'] ?? '') ?>"></td>
                            <td><input name="analisa[METHOD][why_4]"
                                    value="<?= htmlspecialchars($analisa['METHOD']['why_4'] ?? '') ?>"></td>
                            <td><input name="analisa[METHOD][why_5]"
                                    value="<?= htmlspecialchars($analisa['METHOD']['why_5'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td>Material</td>
                            <td><input name="analisa[MATERIAL][why_1]"
                                    value="<?= htmlspecialchars($analisa['MATERIAL']['why_1'] ?? '') ?>"></td>
                            <td><input name="analisa[MATERIAL][why_2]"
                                    value="<?= htmlspecialchars($analisa['MATERIAL']['why_2'] ?? '') ?>"></td>
                            <td><input name="analisa[MATERIAL][why_3]"
                                    value="<?= htmlspecialchars($analisa['MATERIAL']['why_3'] ?? '') ?>"></td>
                            <td><input name="analisa[MATERIAL][why_4]"
                                    value="<?= htmlspecialchars($analisa['MATERIAL']['why_4'] ?? '') ?>"></td>
                            <td><input name="analisa[MATERIAL][why_5]"
                                    value="<?= htmlspecialchars($analisa['MATERIAL']['why_5'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td>Machine</td>
                            <td><input name="analisa[MACHINE][why_1]"
                                    value="<?= htmlspecialchars($analisa['MACHINE']['why_1'] ?? '') ?>"></td>
                            <td><input name="analisa[MACHINE][why_2]"
                                    value="<?= htmlspecialchars($analisa['MACHINE']['why_2'] ?? '') ?>"></td>
                            <td><input name="analisa[MACHINE][why_3]"
                                    value="<?= htmlspecialchars($analisa['MACHINE']['why_3'] ?? '') ?>"></td>
                            <td><input name="analisa[MACHINE][why_4]"
                                    value="<?= htmlspecialchars($analisa['MACHINE']['why_4'] ?? '') ?>"></td>
                            <td><input name="analisa[MACHINE][why_5]"
                                    value="<?= htmlspecialchars($analisa['MACHINE']['why_5'] ?? '') ?>"></td>
                        </tr>
                    </table>

                    <div class="section-title">Flow Out</div>

                    <table>
                        <tr>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                        </tr>
                        <tr>
                            <td><input name="analisa[FLOWOUT][why_1]"
                                    value="<?= htmlspecialchars($analisa['FLOWOUT']['why_1'] ?? '') ?>"></td>
                            <td><input name="analisa[FLOWOUT][why_2]"
                                    value="<?= htmlspecialchars($analisa['FLOWOUT']['why_2'] ?? '') ?>"></td>
                            <td><input name="analisa[FLOWOUT][why_3]"
                                    value="<?= htmlspecialchars($analisa['FLOWOUT']['why_3'] ?? '') ?>"></td>
                            <td><input name="analisa[FLOWOUT][why_4]"
                                    value="<?= htmlspecialchars($analisa['FLOWOUT']['why_4'] ?? '') ?>"></td>
                            <td><input name="analisa[FLOWOUT][why_5]"
                                    value="<?= htmlspecialchars($analisa['FLOWOUT']['why_5'] ?? '') ?>"></td>
                        </tr>
                    </table>

                    <div class="section-title">Corrective Action</div>
                    <table class="action-table" id="correctiveTable">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>Status</th>
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
                                        <select name="corrective[status][]">
                                            <option value="OPEN" <?= $row['status'] == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= $row['status'] == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                    </td>
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
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Preventive Action</div>
                    <table class="action-table" id="preventiveTable">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>Status</th>
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
                                        <select name="preventive[status][]">
                                            <option value="OPEN" <?= $row['status'] == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                            <option value="CLOSED" <?= $row['status'] == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                        </select>
                                    </td>
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
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Update Dokumen</div>
                    <table id="dokumenTable">
                        <tr>
                            <th>Dokumen</th>
                            <th>Due Date</th>
                            <th>Actual</th>
                            <th>PIC</th>
                            <th>Status</th>
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
                                            <?php foreach ($deptList as $dept): ?>
                                                <option value="<?= $dept ?>"
                                                    <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                    <?= $dept ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="dokumen[status][]">
                                            <option value="Open" <?= $row['status'] == 'Open' ? 'selected' : '' ?>>Open</option>
                                            <option value="Closed" <?= $row['status'] == 'Closed' ? 'selected' : '' ?>>Closed</option>
                                        </select>
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
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table> -->

                    <datalist id="docType">
                        <option value="IK">
                        <option value="FMEA">
                        <option value="QCPC">
                        <option value="Check Sheet">
                    </datalist>

                    <div class="btn-group">
                        <button class="btn btn-submit">Submit</button>
                        <button class="btn btn-cancel">Cancel</button>
                    </div>

                </form>

            </div>

        </main>
    </div>

    <script>
        function addRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const newRow = row.cloneNode(true);

            // reset value input
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