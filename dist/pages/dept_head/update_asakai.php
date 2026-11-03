<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';
require_once __DIR__ . '/../config/asakai_bukti_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$userDept = $_SESSION['dept'] ?? '';

$id = $_GET['id'];

$qHeader = mysqli_query($koneksi, "SELECT *
    FROM asakai_header
    WHERE id_asakai = '$id'
");
$header = mysqli_fetch_assoc($qHeader);

$qAnalisa = mysqli_query($koneksi, "SELECT *
    FROM asakai_analisa
    WHERE id_asakai = '$id'
    ORDER BY kategori ASC
");

$analisaDT = [];
$analisaNG = [];

mysqli_data_seek($qAnalisa, 0);
while ($a = mysqli_fetch_assoc($qAnalisa)) {
    if ($a['kategori'] == 'DOWN_TIME') {
        $analisaDT[] = $a;
    } else {
        $analisaNG[] = $a;
    }
}

$qDept = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");

$masterDept = [];
while ($d = mysqli_fetch_assoc($qDept)) {
    $masterDept[] = $d['nama_dept'];
}

$cmDT = [];
$qCmDT = mysqli_query($koneksi, "SELECT *
    FROM asakai_countermeasure_downtime
    WHERE id_asakai = '$id'
    ORDER BY due_date ASC
");
while ($r = mysqli_fetch_assoc($qCmDT)) {
    $cmDT[] = $r;
}

$cmNG = [];
$qCmNG = mysqli_query($koneksi, "SELECT *
    FROM asakai_countermeasure_ng
    WHERE id_asakai = '$id'
    ORDER BY due_date ASC
");
while ($r = mysqli_fetch_assoc($qCmNG)) {
    $cmNG[] = $r;
}

function formatTanggal($tanggal)
{
    if (!empty($tanggal) && $tanggal != '0000-00-00') {
        return date('d-m-Y', strtotime($tanggal));
    }
    return '';
}

function rawDateValue($tanggal)
{
    if (!empty($tanggal) && $tanggal != '0000-00-00') {
        return date('Y-m-d', strtotime($tanggal));
    }
    return '';
}

$rescheduleCounts = [];
$qRescheduleCount = mysqli_query($koneksi, "SELECT id_action, jenis_action, COUNT(*) AS jumlah FROM asakai_reschedule_log WHERE id_asakai = '$id' GROUP BY id_action, jenis_action");
while ($r = mysqli_fetch_assoc($qRescheduleCount)) {
    $rescheduleCounts[$r['jenis_action'] . '_' . $r['id_action']] = (int) $r['jumlah'];
}

$rescheduleLogs = [];
$qRescheduleLog = mysqli_query($koneksi, "SELECT * FROM asakai_reschedule_log WHERE id_asakai = '$id' ORDER BY direschedule_at DESC");
while ($r = mysqli_fetch_assoc($qRescheduleLog)) {
    $rescheduleLogs[] = $r;
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Summary Asakai</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Summary Asakai" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1e40af;
            --soft: #f1f5f9;
            --border: #e2e8f0;
            --text: #1e293b;
            --muted: #64748b;
            --danger: #dc2626;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: var(--text);
        }

        .main-container {
            max-width: 1800px;
            /* diperlebar */
            margin: auto;
        }

        .card-clean {
            background: #ffffff;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
        }

        .section-title {
            font-weight: 600;
            font-size: 14px;
            letter-spacing: .5px;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .upload-video-hint {
            display: block;
            margin-top: 4px;
            color: #6b7280;
            font-size: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            /* lebih rapat */
            gap: 12px;
            /* jarak diperkecil */
        }

        .form-group input {
            padding: 6px 8px;
            font-size: 12px;
        }

        .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .form-group input {
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid var(--border);
            font-size: 13px;
        }

        .form-group input:focus {
            border-color: var(--primary);
            outline: none;
        }

        .table-clean textarea {
            padding: 10px;
            font-size: 13px;
        }

        .table-clean th {
            background: var(--soft);
            padding: 10px;
            font-size: 12px;
            text-align: left;
            border-bottom: 2px solid var(--border);
        }

        .table-clean td {
            padding: 8px;
            border-bottom: 1px solid var(--border);
        }

        .table-clean textarea,
        .table-clean input,
        .table-clean select {
            width: 100%;
            font-size: 12px;
            padding: 6px;
            border: 1px solid var(--border);
            border-radius: 5px;
        }

        .button-area {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .btn-clean {
            padding: 10px 22px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }

        .btn-primary-clean {
            background: var(--primary);
            color: white;
        }

        .btn-danger-clean {
            background: var(--danger);
            color: white;
        }

        .cm-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .cm-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 18px;
            letter-spacing: .5px;
        }

        .cm-table textarea {
            min-height: 70px;
        }

        .cm-table th {
            background: #f1f5f9;
            padding: 10px;
            font-size: 12px;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }

        .cm-table td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        .cm-table textarea,
        .cm-table input,
        .cm-table select {
            width: 100%;
            padding: 6px 8px;
            font-size: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }

        .file-preview {
            margin-top: 8px;
        }

        .file-preview a {
            margin-right: 8px;
            display: inline-block;
        }

        .file-preview img {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }

        .file-preview .pdf-icon {
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #dc2626;
            border: 1px solid #e5e7eb;
        }

        .table-clean,
        .cm-table {
            width: 100%;
            table-layout: fixed;
        }

        .btn-reschedule {
            display: block;
            margin: 6px auto 0;
            padding: 4px 10px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            background: #1e3a8a;
            color: #fff;
            cursor: pointer;
        }

        .btn-reschedule:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .reschedule-badge {
            margin-top: 6px;
            font-size: 11px;
            color: #9a3412;
        }

        .reschedule-history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .reschedule-history-table th,
        .reschedule-history-table td {
            border: 1px solid #e2e8f0;
            padding: 8px;
            text-align: center;
        }

        .table-clean textarea,
        .cm-table textarea {
            resize: vertical;
            min-height: 60px;
        }

        /* HEADER TABLE STYLE */
        .header-table-wrap {
            overflow-x: auto;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .header-table th {
            background: #f1f5f9;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            border: 1px solid var(--border);
        }

        .header-table td {
            padding: 8px 10px;
            border: 1px solid var(--border);
            vertical-align: top;
        }

        .header-table label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase;
        }


        .header-table input {
            width: 100%;
            padding: 6px 8px;
            font-size: 12px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: #fafafa;
        }

        /* STATUS BADGE */
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 50px;
            text-align: center;
            min-width: 90px;
            letter-spacing: .5px;
        }

        .status-open {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde68a;
        }

        .status-closed {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="main-container">

                <h4 style="margin-left: 20px;">Upload Perbaikan - ASAKAI</h4><br>
                <?php require_once __DIR__ . '/../config/countermeasure_revisi_helper.php'; ?>
                <div style="margin: 0 20px;"><?= renderBannerRevisiPic($koneksi, 'ASAKAI', (int) $id, $_SESSION['dept'] ?? '') ?></div>

                <form method="POST" action="proses_update_asakai.php" enctype="multipart/form-data">

                    <div class="card-clean">
                        <div class="section-title">INFORMASI HEADER</div>

                        <div class="header-table-wrap">

                            <input type="hidden" name="id_asakai" value="<?= $id ?>">

                            <table class="header-table">
                                <thead>
                                    <tr>
                                        <th>Mesin</th>
                                        <th>Tanggal</th>
                                        <th>Achievement</th>
                                        <th>Cycle Time & Efficiency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <input type="text" name="machine" value="<?= htmlspecialchars($header['machine']) ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Tanggal Asakai</label>
                                            <input type="text" name="tanggal"
                                                value="<?= formatTanggal($header['tanggal']) ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Target</label>
                                            <input type="number" name="target" value="<?= $header['target'] ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Standard</label>
                                            <input type="number" name="standard" value="<?= $header['standard'] ?>">
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <label style="color:#1e3a8a">Nama Part</label>
                                            <input type="text" name="tanggal_problem" value="<?= htmlspecialchars($header['namapart']) ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Tanggal Problem</label>
                                            <input type="text" name="tanggal_problem"
                                                value="<?= formatTanggal($header['tanggal_problem']) ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">OK</label>
                                            <input type="number" name="ok_qty" value="<?= $header['ok_qty'] ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Actual</label>
                                            <input type="number" name="actual" value="<?= $header['actual'] ?>">
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <label style="color:#1e3a8a">Type</label>
                                            <input type="text" name="type" value="<?= htmlspecialchars($header['type']) ?>">
                                        </td>
                                        <td></td>

                                        <td>
                                            <label style="color:#1e3a8a">NG</label>
                                            <input type="number" name="ng_qty" value="<?= $header['ng_qty'] ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">% Efficiency</label>
                                            <input type="number" name="efficiency" value="<?= $header['efficiency'] ?>">
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <label style="color:#1e3a8a">Mold #</label>
                                            <input type="text" name="mold_no" value="<?= htmlspecialchars($header['mold_no']) ?>">
                                        </td>
                                        <td></td>

                                        <td>
                                            <label style="color:#1e3a8a">Down Time</label>
                                            <input type="number" name="downtime" value="<?= $header['downtime'] ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">% NG</label>
                                            <input type="number" name="persen_ng" value="<?= $header['persen_ng'] ?>">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-clean">
                        <div class="section-title">ANALISA - DOWN TIME</div>
                        <div style="overflow-x:auto;">
                            <table class="table-clean">

                                <thead>
                                    <tr>
                                        <th style="width:20%">Problem</th>
                                        <th style="width:17%">Why 1</th>
                                        <th style="width:17%">Why 2</th>
                                        <th style="width:18%">Why 3</th>
                                        <th style="width:18%">Why 4</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- DOWN TIME -->
                                    <?php foreach ($analisaDT as $i => $a) { ?>
                                        <tr>

                                            <td>
                                                <textarea name="problem_dt[<?= $i ?>]" rows="2" readonly><?= htmlspecialchars(!empty($a['problem']) ? $a['problem'] : $header['downtime_problem']) ?></textarea>
                                            </td>

                                            <td><input type="text" name="analisa_dt[<?= $i ?>][analisa1]"
                                                    value="<?= htmlspecialchars($a['analisa1']) ?>" readonly></td>

                                            <td><input type="text" name="analisa_dt[<?= $i ?>][analisa2]"
                                                    value="<?= htmlspecialchars($a['analisa2']) ?>" readonly></td>

                                            <td><input type="text" name="analisa_dt[<?= $i ?>][analisa3]"
                                                    value="<?= htmlspecialchars($a['analisa3']) ?>" readonly></td>

                                            <td><input type="text" name="analisa_dt[<?= $i ?>][analisa4]"
                                                    value="<?= htmlspecialchars($a['analisa4']) ?>" readonly></td>

                                            <input type="hidden" name="analisa_dt_id[<?= $i ?>]"
                                                value="<?= $a['id_analisa'] ?>" readonly>
                                        </tr>
                                    <?php } ?>

                                </tbody>

                            </table>
                        </div>

                        <div class="section-title">ANALISA - NG</div>
                        <div style="overflow-x:auto;">
                            <table class="table-clean">

                                <thead>
                                    <tr>
                                        <th style="width:20%">Problem</th>
                                        <th style="width:17%">Why 1</th>
                                        <th style="width:17%">Why 2</th>
                                        <th style="width:18%">Why 3</th>
                                        <th style="width:18%">Why 4</th>
                                    </tr>
                                </thead>
                                <tbody>

                                    <?php foreach ($analisaNG as $i => $a) { ?>
                                        <tr>

                                            <td>
                                                <textarea name="problem_ng[<?= $i ?>]" rows="2" readonly><?= htmlspecialchars(!empty($a['problem']) ? $a['problem'] : $header['ng_problem']) ?></textarea>
                                            </td>

                                            <td><input type="text" name="analisa_ng[<?= $i ?>][analisa1]"
                                                    value="<?= htmlspecialchars($a['analisa1']) ?>" readonly></td>

                                            <td><input type="text" name="analisa_ng[<?= $i ?>][analisa2]"
                                                    value="<?= htmlspecialchars($a['analisa2']) ?>" readonly></td>

                                            <td><input type="text" name="analisa_ng[<?= $i ?>][analisa3]"
                                                    value="<?= htmlspecialchars($a['analisa3']) ?>" readonly></td>

                                            <td><input type="text" name="analisa_ng[<?= $i ?>][analisa4]"
                                                    value="<?= htmlspecialchars($a['analisa4']) ?>" readonly></td>

                                            <input type="hidden" name="analisa_ng_id[<?= $i ?>]"
                                                value="<?= $a['id_analisa'] ?>" readonly>
                                        </tr>
                                    <?php } ?>

                                </tbody>
                            </table>
                        </div>

                        <div class="cm-card">
                            <div class="cm-title">COUNTERMEASURE - DOWN TIME</div>
                            <small class="upload-video-hint">Maksimal 3 file, video maksimal 10MB</small>

                            <table class="cm-table">
                                <thead>
                                    <tr>
                                        <th style="width:25%">Countermeasure</th>
                                        <th style="width:12%">PIC</th>
                                        <th style="width:12%">Due Date</th>

                                        <th style="width:12%">Status</th>
                                        <th style="width:17%">Upload</th>
                                        <th style="width:22%">Note</th>
                                    </tr>
                                </thead>
                                <tbody>

                                    <?php foreach ($cmDT as $i => $cm) { ?>
                                        <?php $isMyDept = strtoupper(trim($cm['pic'])) == strtoupper(trim($userDept)); ?>
                                        <tr>

                                            <td>
                                                <textarea name="deskripsi_dt[<?= $i ?>]" rows="2" readonly><?= $cm['deskripsi'] ?></textarea>
                                            </td>

                                            <td>
                                                <select disabled>
                                                    <option value="">-- Pilih Dept --</option>
                                                    <?php foreach ($masterDept as $dept) { ?>
                                                        <option value="<?= $dept ?>"
                                                            <?= $cm['pic'] == $dept ? 'selected' : '' ?>>
                                                            <?= $dept ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>

                                                <!-- Hidden agar value tetap terkirim -->
                                                <input type="hidden"
                                                    name="pic_dt[<?= $i ?>]"
                                                    value="<?= htmlspecialchars($cm['pic']) ?>">
                                            </td>

                                            <td>
                                                <?php $jumlahRescheduleRow = $rescheduleCounts['DOWNTIME_' . $cm['id_cm_dt']] ?? 0; ?>
                                                <input type="date" class="due-date-input" name="due_dt[<?= $i ?>]"
                                                    value="<?= rawDateValue($cm['due_date']) ?>" readonly>
                                                <input type="hidden" name="old_due_dt[<?= $i ?>]" value="<?= rawDateValue($cm['due_date']) ?>">
                                                <input type="hidden" class="reschedule-date-input" name="reschedule_date_dt[<?= $i ?>]" value="">
                                                <input type="hidden" class="reschedule-alasan-input" name="reschedule_alasan_dt[<?= $i ?>]" value="">
                                                <button type="button" class="btn-reschedule" onclick="openReschedule(this)" <?= $jumlahRescheduleRow >= 3 ? 'disabled title="Maksimal 3x reschedule tercapai"' : '' ?>>🔄 Reschedule (<?= $jumlahRescheduleRow ?>/3)</button>
                                                <div class="reschedule-badge" style="display:none;"></div>
                                            </td>

                                            
                                            <td>
                                                <?php
                                                $status = strtoupper($cm['status']);
                                                $class = ($status == 'CLOSED') ? 'status-closed' : 'status-open';
                                                ?>

                                                <span class="status-badge <?= $class ?>"><?= $status ?></span>
                                                <input type="hidden" name="status_dt[<?= $i ?>]" value="<?= $status ?>">
                                            </td>

                                            <td>
                                                <?= renderUploadBuktiAsakai($cm, 'dt', $i) ?>
                                            </td>

                                            <td>
                                                <textarea name="note_dt[<?= $i ?>]" rows="2" <?= !$isMyDept ? 'readonly' : '' ?>><?= htmlspecialchars($cm['note_downtime'] ?? '') ?></textarea>
                                            </td>

                                            <input type="hidden" name="cm_dt_id[<?= $i ?>]" value="<?= $cm['id_cm_dt'] ?>">
                                        </tr>
                                    <?php } ?>

                                </tbody>
                            </table>
                        </div>

                        <div class="cm-card">
                            <div class="cm-title">COUNTERMEASURE - NG</div>
                            <small class="upload-video-hint">Maksimal 3 file, video maksimal 10MB</small>

                            <table class="cm-table">
                                <thead>
                                    <tr>
                                        <th style="width:25%">Countermeasure</th>
                                        <th style="width:12%">PIC</th>
                                        <th style="width:12%">Due Date</th>

                                        <th style="width:12%">Status</th>
                                        <th style="width:17%">Upload</th>
                                        <th style="width:22%">Note</th>
                                    </tr>
                                </thead>
                                <tbody>

                                    <?php foreach ($cmNG as $i => $cm) { ?>
                                        <?php $isMyDept = strtoupper(trim($cm['pic'])) == strtoupper(trim($userDept)); ?>
                                        <tr>

                                            <td>
                                                <textarea name="deskripsi_ng[<?= $i ?>]" rows="2" readonly><?= $cm['deskripsi'] ?></textarea>
                                            </td>

                                            <td>
                                                <select disabled>
                                                    <option value="">-- Pilih Dept --</option>
                                                    <?php foreach ($masterDept as $dept) { ?>
                                                        <option value="<?= $dept ?>"
                                                            <?= $cm['pic'] == $dept ? 'selected' : '' ?>>
                                                            <?= $dept ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>

                                                <!-- Hidden agar value tetap terkirim -->
                                                <input type="hidden"
                                                    name="pic_ng[<?= $i ?>]"
                                                    value="<?= htmlspecialchars($cm['pic']) ?>">
                                            </td>

                                            <td>
                                                <?php $jumlahRescheduleRow = $rescheduleCounts['NG_' . $cm['id_cm_ng']] ?? 0; ?>
                                                <input type="date" class="due-date-input" name="due_ng[<?= $i ?>]"
                                                    value="<?= rawDateValue($cm['due_date']) ?>" readonly>
                                                <input type="hidden" name="old_due_ng[<?= $i ?>]" value="<?= rawDateValue($cm['due_date']) ?>">
                                                <input type="hidden" class="reschedule-date-input" name="reschedule_date_ng[<?= $i ?>]" value="">
                                                <input type="hidden" class="reschedule-alasan-input" name="reschedule_alasan_ng[<?= $i ?>]" value="">
                                                <button type="button" class="btn-reschedule" onclick="openReschedule(this)" <?= $jumlahRescheduleRow >= 3 ? 'disabled title="Maksimal 3x reschedule tercapai"' : '' ?>>🔄 Reschedule (<?= $jumlahRescheduleRow ?>/3)</button>
                                                <div class="reschedule-badge" style="display:none;"></div>
                                            </td>

                                        

                                            <td>
                                                <?php
                                                $status = strtoupper($cm['status']);
                                                $class = ($status == 'CLOSED') ? 'status-closed' : 'status-open';
                                                ?>

                                                <span class="status-badge <?= $class ?>"><?= $status ?></span>
                                                <input type="hidden" name="status_ng[<?= $i ?>]" value="<?= $status ?>">
                                            </td>

                                            <td>
                                                <?= renderUploadBuktiAsakai($cm, 'ng', $i) ?>
                                            </td>

                                            <td>
                                                <textarea name="note_ng[<?= $i ?>]" rows="2" <?= !$isMyDept ? 'readonly' : '' ?>><?= htmlspecialchars($cm['note_ng'] ?? '') ?></textarea>
                                            </td>

                                            <input type="hidden" name="cm_ng_id[<?= $i ?>]" value="<?= $cm['id_cm_ng'] ?>">
                                        </tr>
                                    <?php } ?>

                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($rescheduleLogs)): ?>
                            <div class="cm-card">
                                <div class="cm-title">HISTORY RESCHEDULE</div>
                                <table class="reschedule-history-table">
                                    <thead>
                                        <tr>
                                            <th>Jenis</th>
                                            <th>Countermeasure</th>
                                            <th>Due Date Lama</th>
                                            <th>Due Date Baru</th>
                                            <th>Alasan</th>
                                            <th>Untuk</th>
                                            <th>Oleh</th>
                                            <th>Tanggal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rescheduleLogs as $log): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($log['jenis_action']) ?></td>
                                                <td style="text-align:left;"><?= htmlspecialchars($log['deskripsi']) ?></td>
                                                <td><?= htmlspecialchars($log['due_date_lama']) ?></td>
                                                <td><?= htmlspecialchars($log['due_date_baru']) ?></td>
                                                <td style="text-align:left;"><?= htmlspecialchars($log['alasan']) ?></td>
                                                <td><?= htmlspecialchars($log['pic'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($log['direschedule_oleh']) ?></td>
                                                <td><?= htmlspecialchars($log['direschedule_at']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <div class="button-area" style="margin-left: 20px;">
                            <button type="submit" class="btn-clean btn-primary-clean">
                                Submit
                            </button>

                            <button type="button" onclick="history.back()" class="btn-clean btn-danger-clean">
                                Cancel
                            </button>
                        </div>

                </form>
            </div>

            <?php include '../footer.php'; ?>

        </main>

    </div>

    <script>
        (function() {
            const MAX_VIDEO_SIZE = 10 * 1024 * 1024; // 10MB
            const MAX_FILES_CM = 3;
            const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];
            const MAX_TOTAL_UPLOAD_SIZE = 35 * 1024 * 1024; // margin di bawah post_max_size server (40MB)

            function isVideoFile(file) {
                if (file.type && file.type.startsWith('video/')) return true;
                const ext = file.name.split('.').pop().toLowerCase();
                return VIDEO_EXT.includes(ext);
            }

            function hasOversizedVideo(input) {
                for (const file of input.files) {
                    if (isVideoFile(file) && file.size > MAX_VIDEO_SIZE) return true;
                }
                return false;
            }

            document.addEventListener('change', function(e) {
                if (e.target.matches && e.target.matches('input[type="file"]')) {
                    // Bukti countermeasure maksimal 3 file (file1-file3)
                    if (/^upload_(dt|ng)\[/.test(e.target.name) && e.target.files.length > MAX_FILES_CM) {
                        alert('Maksimal upload ' + MAX_FILES_CM + ' file per countermeasure. Silakan pilih ulang.');
                        e.target.value = '';
                        return;
                    }
                    if (hasOversizedVideo(e.target)) {
                        alert('Ukuran video melebihi 10MB. Silakan pilih file video yang lebih kecil.');
                        e.target.value = '';
                    }
                }
            });

            // Kompres gambar di browser sebelum upload supaya request kecil & cepat
            // (foto HP bisa 3-8MB, sering bikin upload lama / diblok 403 oleh server hosting)
            const IMG_MAX_DIMENSION = 1600;
            const IMG_QUALITY = 0.75;
            const IMG_MIN_SIZE_TO_COMPRESS = 300 * 1024; // di bawah 300KB tidak perlu dikompres
            const COMPRESSIBLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

            function compressImage(file) {
                return new Promise(function(resolve) {
                    if (!COMPRESSIBLE_TYPES.includes(file.type) || file.size < IMG_MIN_SIZE_TO_COMPRESS) {
                        resolve(file);
                        return;
                    }

                    const url = URL.createObjectURL(file);
                    const img = new Image();
                    img.onload = function() {
                        URL.revokeObjectURL(url);
                        const scale = Math.min(1, IMG_MAX_DIMENSION / Math.max(img.width, img.height));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.round(img.width * scale);
                        canvas.height = Math.round(img.height * scale);
                        const ctx = canvas.getContext('2d');
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                        canvas.toBlob(function(blob) {
                            if (!blob || blob.size >= file.size) {
                                resolve(file);
                                return;
                            }
                            const newName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                            resolve(new File([blob], newName, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            }));
                        }, 'image/jpeg', IMG_QUALITY);
                    };
                    img.onerror = function() {
                        URL.revokeObjectURL(url);
                        resolve(file);
                    };
                    img.src = url;
                });
            }

            async function compressInputFiles(input) {
                if (!input.files || input.files.length === 0 || typeof DataTransfer === 'undefined') return;
                const dt = new DataTransfer();
                for (const file of input.files) {
                    dt.items.add(await compressImage(file));
                }
                input.files = dt.files;
            }

            function setSubmitLoading(form, loading) {
                const btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    if (!btn.dataset.label) btn.dataset.label = btn.textContent;
                    btn.disabled = loading;
                    btn.textContent = loading ? 'Menyimpan...' : btn.dataset.label;
                }
                if (typeof Swal === 'undefined') return;
                if (loading) {
                    Swal.fire({
                        title: 'Menyimpan data...',
                        text: 'Sedang mengupload file, mohon tunggu dan jangan tutup halaman ini.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: function() {
                            Swal.showLoading();
                        }
                    });
                } else {
                    Swal.close();
                }
            }

            document.querySelectorAll('form').forEach(function(form) {
                let sedangSubmit = false;

                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    if (sedangSubmit) return false; // cegah double submit

                    const fileInputs = form.querySelectorAll('input[type="file"]:not([disabled])');
                    for (const input of fileInputs) {
                        if (hasOversizedVideo(input)) {
                            alert('Ukuran video melebihi 10MB. Perbaiki file sebelum submit.');
                            input.focus();
                            return false;
                        }
                    }

                    sedangSubmit = true;
                    setSubmitLoading(form, true);

                    try {
                        for (const input of fileInputs) {
                            await compressInputFiles(input);
                        }
                    } catch (err) {
                        // kalau kompres gagal, tetap kirim file asli
                    }

                    // Cek total ukuran setelah kompres
                    let totalSize = 0;
                    for (const input of fileInputs) {
                        for (const file of input.files) {
                            totalSize += file.size;
                        }
                    }

                    if (totalSize > MAX_TOTAL_UPLOAD_SIZE) {
                        sedangSubmit = false;
                        setSubmitLoading(form, false);
                        alert('Total ukuran seluruh file yang diupload terlalu besar (maksimal ' + (MAX_TOTAL_UPLOAD_SIZE / (1024 * 1024)) + 'MB). Kurangi jumlah atau ukuran file sebelum submit.');
                        return false;
                    }

                    HTMLFormElement.prototype.submit.call(form);
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

        function changeStatusStyle(select) {
            select.classList.remove("status-open", "status-closed");
            if (select.value === "CLOSED") {
                select.classList.add("status-closed");
            } else {
                select.classList.add("status-open");
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        function rsHariIni() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // Nilai input actual baris -> YYYY-MM-DD (input text di sebagian role berformat DD-MM-YYYY).
        function rsKeIso(tgl) {
            tgl = (tgl || '').trim();
            if (/^\d{4}-\d{2}-\d{2}$/.test(tgl)) return tgl;
            const m = tgl.match(/^(\d{2})-(\d{2})-(\d{4})$/);
            return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
        }

        function rsFormatTgl(iso) {
            const p = (iso || '').split('-');
            return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0] : iso;
        }

        function openReschedule(btn) {
            const cell = btn.closest('td');
            const row = btn.closest('tr');
            const dateInput = cell.querySelector('.due-date-input');
            const rescheduleDateInput = cell.querySelector('.reschedule-date-input');
            const rescheduleAlasanInput = cell.querySelector('.reschedule-alasan-input');
            const badge = cell.querySelector('.reschedule-badge');
            const currentDue = dateInput.value;
            // Upload bukti dari modal dipindahkan ke input upload baris ini, jadi ikut tersimpan
            // saat Submit; actual date otomatis = tanggal upload (proses_update_asakai.php).
            const fileInput = row.querySelector('input[type="file"][name^="upload_dt["], input[type="file"][name^="upload_ng["]');
            const actualInput = row.querySelector('input[name^="actual_dt["], input[name^="actual_ng["]');
            if (actualInput && actualInput.dataset.rsAwal === undefined) actualInput.dataset.rsAwal = actualInput.value;
            const actualLama = rsKeIso(actualInput ? actualInput.dataset.rsAwal : '');

            Swal.fire({
                title: 'Reschedule Due Date',
                html:
                    '<div style="text-align:left">' +
                    '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Due Date Baru</label>' +
                    '<input type="date" id="swalNewDate" class="swal2-input" style="margin:0 0 12px;" value="' + (rescheduleDateInput.value || currentDue) + '">' +
                    '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Alasan Reschedule</label>' +
                    '<textarea id="swalAlasan" class="swal2-textarea" style="margin:0 0 12px;" placeholder="Jelaskan alasan reschedule...">' + (rescheduleAlasanInput.value || '').replace(/</g, '&lt;') + '</textarea>' +
                    (fileInput ?
                        '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Upload Bukti Perbaikan <span id="swalSifatFile" style="font-weight:400;color:#6b7280;">(opsional)</span></label>' +
                        '<input type="file" id="swalFile" multiple accept="image/*,.pdf,video/*" data-bukti-ui style="width:100%;font-size:13px;">' +
                        '<small style="display:block;font-size:11px;color:#6b7280;margin-top:2px;">Maks 3 file, video maks 10MB. Actual date otomatis = tanggal upload; bukti masuk Verifikasi Countermeasure Staff IMP setelah Submit.</small>' +
                        '<small id="swalInfoWajib" style="display:none;font-size:12px;color:#b45309;margin-top:8px;">Due date baru = hari ini, jadi bukti perbaikan wajib diupload.</small>'
                        : '') +
                    '</div>',
                confirmButtonText: 'Simpan',
                showCancelButton: true,
                cancelButtonText: 'Batal',
                didOpen: () => {
                    const file = document.getElementById('swalFile');
                    if (!file) return;
                    // Bukti yang sudah dipilih sebelumnya (buka modal lagi) tetap terbawa.
                    if (fileInput && fileInput.files.length) {
                        const dt = new DataTransfer();
                        Array.from(fileInput.files).forEach(f => dt.items.add(f));
                        file.files = dt.files;
                    }
                    // Kuota file modal = sisa kuota baris ini, tampil sebagai tombol + / ✕.
                    file.dataset.sisa = fileInput ? (fileInput.disabled ? '0' : (fileInput.dataset.sisa || '3')) : '3';
                    if (window.BuktiUpload) {
                        window.BuktiUpload.init(file);
                        window.BuktiUpload.refresh(file);
                    }
                    // Due date baru = hari ini berarti perbaikan selesai hari ini -> bukti wajib.
                    const tglEl = document.getElementById('swalNewDate');
                    const tandaiWajib = () => {
                        const wajib = tglEl.value === rsHariIni();
                        const sifat = document.getElementById('swalSifatFile');
                        sifat.textContent = wajib ? '(wajib)' : '(opsional)';
                        sifat.style.color = wajib ? '#dc2626' : '#6b7280';
                        document.getElementById('swalInfoWajib').style.display = wajib ? 'block' : 'none';
                    };
                    tglEl.addEventListener('change', tandaiWajib);
                    tandaiWajib();
                },
                preConfirm: () => {
                    const newDate = document.getElementById('swalNewDate').value;
                    const alasan = document.getElementById('swalAlasan').value.trim();
                    const fileEl = document.getElementById('swalFile');
                    const files = fileEl ? Array.from(fileEl.files) : [];

                    if (!newDate) {
                        Swal.showValidationMessage('Due date baru wajib diisi');
                        return false;
                    }
                    if (newDate === currentDue) {
                        Swal.showValidationMessage('Due date baru harus berbeda dari due date sebelumnya');
                        return false;
                    }
                    if (!alasan) {
                        Swal.showValidationMessage('Alasan reschedule wajib diisi');
                        return false;
                    }
                    if (fileEl && newDate === rsHariIni() && !files.length) {
                        Swal.showValidationMessage('Due date baru hari ini: upload bukti perbaikan wajib diisi');
                        return false;
                    }
                    if (files.length > 3) {
                        Swal.showValidationMessage('Maksimal 3 file bukti');
                        return false;
                    }
                    const videoBesar = files.find(f => f.type.indexOf('video/') === 0 && f.size > 10 * 1024 * 1024);
                    if (videoBesar) {
                        Swal.showValidationMessage('Video ' + videoBesar.name + ' melebihi 10MB');
                        return false;
                    }
                    return {
                        newDate,
                        alasan,
                        files
                    };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    rescheduleDateInput.value = result.value.newDate;
                    rescheduleAlasanInput.value = result.value.alasan;

                    let infoBukti = '';
                    if (fileInput) {
                        const dt = new DataTransfer();
                        result.value.files.forEach(f => dt.items.add(f));
                        fileInput.files = dt.files;
                        fileInput.dispatchEvent(new Event('change', { bubbles: true }));

                        const pakaiBukti = result.value.files.length > 0;
                        fileInput.dataset.rsDariModal = pakaiBukti ? '1' : '';
                        if (actualInput) {
                            const iso = pakaiBukti ? rsHariIni() : actualLama;
                            actualInput.value = pakaiBukti
                                ? (actualInput.type === 'date' ? iso : rsFormatTgl(iso))
                                : actualInput.dataset.rsAwal;
                        }
                        if (pakaiBukti) {
                            infoBukti = '<br>📎 ' + result.value.files.length + ' file bukti, actual <b>' + rsFormatTgl(rsHariIni()) + '</b>';
                        }
                    }

                    badge.style.display = 'block';
                    badge.innerHTML = '🔄 Due date baru: <b>' + result.value.newDate + '</b><br>Alasan: ' +
                        result.value.alasan.replace(/</g, '&lt;') + infoBukti +
                        '<br><small>Tersimpan setelah klik Submit.</small>' +
                        ' <a href="#" onclick="cancelReschedule(event,this)" style="color:#9a3412;">(batal)</a>';
                }
            });
        }

        function cancelReschedule(e, link) {
            e.preventDefault();
            const cell = link.closest('td');
            const row = link.closest('tr');
            cell.querySelector('.reschedule-date-input').value = '';
            cell.querySelector('.reschedule-alasan-input').value = '';
            const badge = cell.querySelector('.reschedule-badge');
            badge.style.display = 'none';
            badge.innerHTML = '';

            const fileInput = row.querySelector('input[type="file"][name^="upload_dt["], input[type="file"][name^="upload_ng["]');
            if (fileInput && fileInput.dataset.rsDariModal === '1') {
                fileInput.dataset.rsDariModal = '';
                fileInput.value = '';
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            const actualInput = row.querySelector('input[name^="actual_dt["], input[name^="actual_ng["]');
            if (actualInput && actualInput.dataset.rsAwal !== undefined) actualInput.value = actualInput.dataset.rsAwal;
        }
    </script>

    

<?= renderScriptBuktiAsakai() ?>
</body>

</html>