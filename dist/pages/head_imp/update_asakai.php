<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';
require_once __DIR__ . '/../config/asakai_bukti_helper.php';

$id = (int) ($_GET['id'] ?? 0);

$qHeader = mysqli_query($koneksi, "SELECT * FROM asakai_header WHERE id_asakai = '$id'");
$header = mysqli_fetch_assoc($qHeader);

$qAnalisa = mysqli_query($koneksi, "SELECT * FROM asakai_analisa WHERE id_asakai = '$id' ORDER BY kategori ASC");

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

$cmDT = [];
$qCmDT = mysqli_query($koneksi, "SELECT * FROM asakai_countermeasure_downtime WHERE id_asakai = '$id' ORDER BY due_date ASC");
while ($r = mysqli_fetch_assoc($qCmDT)) {
    $cmDT[] = $r;
}

$cmNG = [];
$qCmNG = mysqli_query($koneksi, "SELECT * FROM asakai_countermeasure_ng WHERE id_asakai = '$id' ORDER BY due_date ASC");
while ($r = mysqli_fetch_assoc($qCmNG)) {
    $cmNG[] = $r;
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

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
            max-width: 1400px;
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
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
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

        .table-clean {
            width: 100%;
            border-collapse: collapse;
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

        .badge {
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 20px;
            background: #e0e7ff;
            color: var(--primary);
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

        .cm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .btn-reschedule {
            display: block;
            margin: 6px auto 0;
            padding: 4px 10px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            background: #1e40af;
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

        .status-open {
            color: #dc2626;
            font-weight: 600;
        }


        .status-closed {
            color: #16a34a;
            font-weight: 600;
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

                <form method="POST" action="proses_update_asakai.php" enctype="multipart/form-data">

                    <div class="card-clean">
                        <div class="section-title">INFORMASI HEADER</div>

                        <div class="form-grid">

                            <input type="hidden" name="id_asakai" value="<?= $id ?>">

                            <?php
                            function inputField($label, $name, $value, $type = "text")
                            {
                                echo '<div class="form-group">
                                    <label>' . $label . '</label>
                                    <input type="' . $type . '" name="' . $name . '" value="' . $value . '">
                                </div>';
                            }
                            ?>

                            <?php inputField("Tanggal", "tanggal", date('Y-m-d', strtotime($header['tanggal'])), "date"); ?>
                            <?php inputField("Machine", "machine", htmlspecialchars($header['machine'])); ?>
                            <?php inputField("Nama Part", "nama_part", htmlspecialchars($header['namapart'])); ?>
                            <?php inputField("Type", "type", htmlspecialchars($header['type'])); ?>
                            <?php inputField("Part Number", "partnumber", htmlspecialchars($header['partnumber'])); ?>
                            <?php inputField("Mold #", "mold_no", htmlspecialchars($header['mold_no'])); ?>
                            <?php inputField("Target", "target", $header['target'], "number"); ?>
                            <?php inputField("OK", "ok_qty", $header['ok_qty'], "number"); ?>
                            <?php inputField("NG", "ng_qty", $header['ng_qty'], "number"); ?>
                            <?php inputField("Down Time", "downtime", $header['downtime'], "number"); ?>
                            <?php inputField("Standard", "standard", $header['standard'], "number"); ?>
                            <?php inputField("Actual", "actual", $header['actual'], "number"); ?>
                            <?php inputField("% Efficiency", "efficiency", $header['efficiency'], "number"); ?>
                            <?php inputField("% NG", "persen_ng", $header['persen_ng'], "number"); ?>

                        </div>
                    </div>

                    <div class="card-clean">
                        <div class="section-title">ANALISA - DOWN TIME</div>
                        <table class="table-clean">

                            <thead>
                                <tr>
                                    <th width="15%">Kategori</th>
                                    <th width="15%">Problem</th>
                                    <th>Why 1</th>
                                    <th>Why 2</th>
                                    <th>Why 3</th>
                                    <th>Why 4</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-analisa-dt">

                                <?php foreach ($analisaDT as $i => $a) { ?>
                                    <tr>
                                        <td><span class="badge">DOWN TIME</span></td>

                                        <td>
                                            <textarea name="problem_dt[<?= $i ?>]" rows="2"><?= htmlspecialchars(!empty($a['problem']) ? $a['problem'] : $header['downtime_problem']) ?></textarea>
                                        </td>

                                        <td><input type="text" name="analisa_dt[<?= $i ?>][analisa1]"
                                                value="<?= htmlspecialchars($a['analisa1']) ?>"></td>

                                        <td><input type="text" name="analisa_dt[<?= $i ?>][analisa2]"
                                                value="<?= htmlspecialchars($a['analisa2']) ?>"></td>

                                        <td><input type="text" name="analisa_dt[<?= $i ?>][analisa3]"
                                                value="<?= htmlspecialchars($a['analisa3']) ?>"></td>

                                        <td><input type="text" name="analisa_dt[<?= $i ?>][analisa4]"
                                                value="<?= htmlspecialchars($a['analisa4']) ?>"></td>

                                        <input type="hidden" name="analisa_dt_id[<?= $i ?>]"
                                            value="<?= $a['id_analisa'] ?>">
                                    </tr>
                                <?php } ?>

                            </tbody>

                        </table>

                    </div>

                    <div class="card-clean">
                        <div class="section-title">ANALISA - NG</div>
                        <table class="table-clean">

                            <thead>
                                <tr>
                                    <th width="15%">Kategori</th>
                                    <th>Why 1</th>
                                    <th>Why 2</th>
                                    <th>Why 3</th>
                                    <th>Why 4</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-analisa-ng">

                                <?php foreach ($analisaNG as $i => $a) { ?>
                                    <tr>
                                        <td><span class="badge">NG</span></td>

                                        <td><input type="text" name="analisa_ng[<?= $i ?>][analisa1]"
                                                value="<?= htmlspecialchars($a['analisa1']) ?>"></td>

                                        <td><input type="text" name="analisa_ng[<?= $i ?>][analisa2]"
                                                value="<?= htmlspecialchars($a['analisa2']) ?>"></td>

                                        <td><input type="text" name="analisa_ng[<?= $i ?>][analisa3]"
                                                value="<?= htmlspecialchars($a['analisa3']) ?>"></td>

                                        <td><input type="text" name="analisa_ng[<?= $i ?>][analisa4]"
                                                value="<?= htmlspecialchars($a['analisa4']) ?>"></td>

                                        <input type="hidden" name="analisa_ng_id[<?= $i ?>]"
                                            value="<?= $a['id_analisa'] ?>">
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
                                    <th style="width:22%">Countermeasure</th>
                                    <th style="width:12%">PIC</th>
                                    <th style="width:12%">Due Date</th>
                                    <th style="width:12%">Actual</th>
                                    <th style="width:9%">Status</th>
                                    <th style="width:13%">Upload</th>
                                    <th style="width:20%">Note</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php foreach ($cmDT as $i => $cm) { ?>
                                    <tr>

                                        <td>
                                            <textarea name="deskripsi_dt[<?= $i ?>]" rows="2"><?= $cm['deskripsi'] ?></textarea>
                                        </td>

                                        <td>
                                            <input type="text" name="pic_dt[<?= $i ?>]" value="<?= $cm['pic'] ?>">
                                        </td>

                                        <td>
                                            <?php $jumlahRescheduleRow = $rescheduleCounts['DOWNTIME_' . $cm['id_cm_dt']] ?? 0; ?>
                                            <input type="date" class="due-date-input" name="due_dt[<?= $i ?>]" value="<?= $cm['due_date'] ?>" readonly>
                                            <input type="hidden" name="old_due_dt[<?= $i ?>]" value="<?= $cm['due_date'] ?>">
                                            <input type="hidden" class="reschedule-date-input" name="reschedule_date_dt[<?= $i ?>]" value="">
                                            <input type="hidden" class="reschedule-alasan-input" name="reschedule_alasan_dt[<?= $i ?>]" value="">
                                            <button type="button" class="btn-reschedule" onclick="openReschedule(this)" <?= $jumlahRescheduleRow >= 3 ? 'disabled title="Maksimal 3x reschedule tercapai"' : '' ?>>🔄 Reschedule (<?= $jumlahRescheduleRow ?>/3)</button>
                                            <div class="reschedule-badge" style="display:none;"></div>
                                        </td>

                                        <td>
                                            <input type="date" name="actual_dt[<?= $i ?>]" value="<?= $cm['actual_date'] ?>">
                                        </td>

                                        <td>
                                            <select name="status_dt[<?= $i ?>]">
                                                <option value="OPEN" <?= $cm['status'] == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                                <option value="CLOSED" <?= $cm['status'] == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                            </select>
                                        </td>

                                        <td>
                                            <?= renderUploadBuktiAsakai($cm, 'dt', $i) ?>
                                        </td>

                                        <td>
                                            <textarea name="note_dt[<?= $i ?>]" rows="2"><?= htmlspecialchars($cm['note_downtime'] ?? '') ?></textarea>
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
                                    <th style="width:22%">Countermeasure</th>
                                    <th style="width:12%">PIC</th>
                                    <th style="width:12%">Due Date</th>
                                    <th style="width:12%">Actual</th>
                                    <th style="width:9%">Status</th>
                                    <th style="width:13%">Upload</th>
                                    <th style="width:20%">Note</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php foreach ($cmNG as $i => $cm) { ?>
                                    <tr>

                                        <td>
                                            <textarea name="deskripsi_ng[<?= $i ?>]" rows="2"><?= $cm['deskripsi'] ?></textarea>
                                        </td>

                                        <td>
                                            <input type="text" name="pic_ng[<?= $i ?>]" value="<?= $cm['pic'] ?>">
                                        </td>

                                        <td>
                                            <?php $jumlahRescheduleRow = $rescheduleCounts['NG_' . $cm['id_cm_ng']] ?? 0; ?>
                                            <input type="date" class="due-date-input" name="due_ng[<?= $i ?>]" value="<?= $cm['due_date'] ?>" readonly>
                                            <input type="hidden" name="old_due_ng[<?= $i ?>]" value="<?= $cm['due_date'] ?>">
                                            <input type="hidden" class="reschedule-date-input" name="reschedule_date_ng[<?= $i ?>]" value="">
                                            <input type="hidden" class="reschedule-alasan-input" name="reschedule_alasan_ng[<?= $i ?>]" value="">
                                            <button type="button" class="btn-reschedule" onclick="openReschedule(this)" <?= $jumlahRescheduleRow >= 3 ? 'disabled title="Maksimal 3x reschedule tercapai"' : '' ?>>🔄 Reschedule (<?= $jumlahRescheduleRow ?>/3)</button>
                                            <div class="reschedule-badge" style="display:none;"></div>
                                        </td>

                                        <td>
                                            <input type="date" name="actual_ng[<?= $i ?>]" value="<?= $cm['actual_date'] ?>">
                                        </td>

                                        <td>
                                            <select name="status_ng[<?= $i ?>]">
                                                <option value="OPEN" <?= $cm['status'] == 'OPEN' ? 'selected' : '' ?>>OPEN</option>
                                                <option value="CLOSED" <?= $cm['status'] == 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
                                            </select>
                                        </td>

                                        <td>
                                            <?= renderUploadBuktiAsakai($cm, 'ng', $i) ?>
                                        </td>

                                        <td>
                                            <textarea name="note_ng[<?= $i ?>]" rows="2"><?= htmlspecialchars($cm['note_ng'] ?? '') ?></textarea>
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
                            Update Data
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

            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const fileInputs = form.querySelectorAll('input[type="file"]');
                    for (const input of fileInputs) {
                        if (hasOversizedVideo(input)) {
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
        // Kalau Problem Down Time diisi, Analisa (Why 1-4) di baris yang sama wajib diisi salah satu.
        // Kosong-kosong tetap boleh submit (tidak ada problem = tidak wajib analisa).
        (function() {
            function validateRowPairs(tbodyId, sectionLabel) {
                const tbody = document.getElementById(tbodyId);
                if (!tbody) return true;

                for (const row of tbody.rows) {
                    const problemField = row.querySelector('textarea[name^="problem_"]');
                    if (!problemField) continue;

                    const analisaFields = row.querySelectorAll('input[type="text"][name^="analisa_"]');
                    const hasProblem = problemField.value.trim() !== '';
                    const hasAnalisa = Array.from(analisaFields).some(function(f) {
                        return f.value.trim() !== '';
                    });

                    if (hasProblem && !hasAnalisa) {
                        alert('Analisa ' + sectionLabel + ' wajib diisi (minimal salah satu Why) karena Problem sudah diisi.');
                        (analisaFields[0] || problemField).focus();
                        return false;
                    }
                }
                return true;
            }

            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    if (!validateRowPairs('tbody-analisa-dt', 'Down Time')) {
                        e.preventDefault();
                        return false;
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