<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$id = (int) ($_GET['id'] ?? 0);

$qHeader = mysqli_query($koneksi, "SELECT * FROM asakai_header
    WHERE id_asakai = '$id' ");
$header = mysqli_fetch_assoc($qHeader);

$qAnalisa = mysqli_query($koneksi, "SELECT * FROM asakai_analisa
    WHERE id_asakai = '$id' ORDER BY kategori ASC");

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

// Baris asakai_analisa yang belum pernah dibahas (baru dibuat dari
// buat_fpm_asakai.php) selalu punya kolom problem kosong - jadi belum ada
// "analisa nyata" tersimpan per problem. Dalam kondisi itu kita bangun ulang
// barisnya dari problem yang tersimpan di header, satu baris per problem,
// supaya tiap problem punya kotak analisa sendiri (bukan digabung).
function hasSavedProblem($rows)
{
    foreach ($rows as $r) {
        if (trim($r['problem'] ?? '') !== '') return true;
    }
    return false;
}

if (!hasSavedProblem($analisaDT)) {
    // downtime_problem berisi beberapa problem yang digabung baris-baru saat
    // input di buat_fpm_asakai.php, pecah jadi satu baris analisa per problem.
    $dtProblems = array_values(array_filter(
        array_map('trim', explode("\n", $header['downtime_problem'] ?? '')),
        fn($p) => $p !== ''
    ));
    if (count($dtProblems) == 0) $dtProblems = [''];

    $analisaDT = [];
    foreach ($dtProblems as $p) {
        $analisaDT[] = [
            'id_analisa' => '',
            'problem' => $p,
            'analisa1' => '',
            'analisa2' => '',
            'analisa3' => '',
            'analisa4' => ''
        ];
    }
}

if (!hasSavedProblem($analisaNG)) {
    // ng_problem di header cuma menyimpan problem NG pertama, sisanya ada di
    // asakai_ng_problem. Gabungkan lalu pecah jadi satu baris analisa per problem.
    $ngProblems = [];
    if (trim($header['ng_problem'] ?? '') !== '') {
        $ngProblems[] = trim($header['ng_problem']);
    }

    $qNgExtra = mysqli_query($koneksi, "SELECT deskripsi FROM asakai_ng_problem
        WHERE id_asakai = '$id' ORDER BY id_ng_problem ASC");
    while ($r = mysqli_fetch_assoc($qNgExtra)) {
        $d = trim($r['deskripsi']);
        if ($d !== '') $ngProblems[] = $d;
    }

    if (count($ngProblems) == 0) $ngProblems = [''];

    $analisaNG = [];
    foreach ($ngProblems as $p) {
        $analisaNG[] = [
            'id_analisa' => '',
            'problem' => $p,
            'analisa1' => '',
            'analisa2' => '',
            'analisa3' => '',
            'analisa4' => ''
        ];
    }
}

$qDept = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");

$masterDept = [];
while ($d = mysqli_fetch_assoc($qDept)) {
    $masterDept[] = $d['nama_dept'];
}

$cmDT = [];
$qCmDT = mysqli_query($koneksi, "SELECT * FROM asakai_countermeasure_downtime
    WHERE id_asakai = '$id' ORDER BY due_date ASC");
while ($r = mysqli_fetch_assoc($qCmDT)) {
    $cmDT[] = $r;
}

$cmNG = [];
$qCmNG = mysqli_query($koneksi, "SELECT * FROM asakai_countermeasure_ng
    WHERE id_asakai = '$id' ORDER BY due_date ASC");

while ($r = mysqli_fetch_assoc($qCmNG)) {
    $cmNG[] = $r;
}

// Kelompokkan countermeasure per problem (id_analisa) supaya tiap problem bisa
// menampilkan form countermeasure-nya sendiri. Countermeasure lama yang dibuat
// sebelum fitur ini ada belum punya id_analisa (kunci 'legacy') - itu nanti
// ditampilkan di bawah problem pertama saja supaya datanya tidak hilang.
function groupCmByAnalisa(array $cmRows, string $noteKey, string $idKey): array
{
    $out = [];
    foreach ($cmRows as $r) {
        $key = !empty($r['id_analisa']) ? $r['id_analisa'] : 'legacy';
        $out[$key][] = [
            'id_cm' => $r[$idKey],
            'deskripsi' => $r['deskripsi'],
            'pic' => $r['pic'],
            'due_date' => $r['due_date'],
            'actual_date' => $r['actual_date'],
            'status' => $r['status'],
            'note' => $r[$noteKey] ?? '',
        ];
    }
    return $out;
}

$cmDTGrouped = groupCmByAnalisa($cmDT, 'note_downtime', 'id_cm_dt');
$cmNGGrouped = groupCmByAnalisa($cmNG, 'note_ng', 'id_cm_ng');

// Render kotak-kotak Analisa (analisa1..analisa4) untuk satu problem. Baris
// analisa2-4 cuma dirender kalau sudah pernah diisi - kotak baru ditambah
// lewat tombol + (lihat addAnalisaField() di JS), maksimal 4 kotak.
function renderAnalisaFields(string $prefix, int $group, array $a): string
{
    $nextNum = 2;
    for ($k = 2; $k <= 4; $k++) {
        if (!empty($a['analisa' . $k])) $nextNum = $k + 1;
    }

    ob_start();
?>
    <div class="analisa-fields" data-next="<?= $nextNum ?>">
        <div class="analisa-field-row">
            <input type="text" name="analisa_<?= $prefix ?>[<?= $group ?>][analisa1]" value="<?= htmlspecialchars($a['analisa1'] ?? '') ?>">
        </div>
        <?php for ($k = 2; $k <= 4; $k++) {
            if (empty($a['analisa' . $k])) continue;
        ?>
            <div class="analisa-field-row">
                <input type="text" name="analisa_<?= $prefix ?>[<?= $group ?>][analisa<?= $k ?>]" value="<?= htmlspecialchars($a['analisa' . $k]) ?>">
                <button type="button" onclick="removeAnalisaField(this)" class="btn-clean btn-danger-clean btn-sm">-</button>
            </div>
        <?php } ?>
    </div>
<?php
    return ob_get_clean();
}

// Render tabel countermeasure untuk satu problem ($group = index problem di
// $analisaDT/$analisaNG). $prefix 'dt' atau 'ng' menentukan nama field
// (cm_dt[...]/cm_ng[...], upload_dt[...]/upload_ng[...]).
function renderCmTable(string $prefix, int $group, array $rows, array $masterDept): string
{
    if (count($rows) == 0) {
        $rows = [[
            'id_cm' => '',
            'deskripsi' => '',
            'pic' => '',
            'due_date' => '',
            'actual_date' => '',
            'status' => '',
            'note' => ''
        ]];
    }

    ob_start();
?>
    <table class="cm-table" data-group="<?= $group ?>" data-next="<?= count($rows) ?>">
        <thead>
            <tr>
                <th style="width:20%">Countermeasure</th>
                <th style="width:12%">PIC</th>
                <th style="width:12%">Due Date</th>
                <th style="width:12%">Actual</th>
                <th style="width:12%">Status</th>
                <th style="width:16%">Upload</th>
                <th style="width:16%">Note</th>
                <th style="width:16%">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $j => $row) { ?>
                <tr>
                    <td>
                        <textarea name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][deskripsi]"><?= htmlspecialchars($row['deskripsi'] ?? '') ?></textarea>
                        <input type="hidden" name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][id]" value="<?= $row['id_cm'] ?? '' ?>">
                    </td>

                    <td>
                        <select name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][pic]">
                            <option value="">-- Pilih Dept --</option>
                            <?php foreach ($masterDept as $dept) { ?>
                                <option value="<?= $dept ?>" <?= (($row['pic'] ?? '') == $dept ? 'selected' : '') ?>>
                                    <?= $dept ?>
                                </option>
                            <?php } ?>
                        </select>
                    </td>

                    <td><input type="date" name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][due]" value="<?= $row['due_date'] ?? '' ?>"></td>
                    <td><input type="date" name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][actual]" value="<?= $row['actual_date'] ?? '' ?>"></td>

                    <td>
                        <select name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][status]">
                            <option value="">-- Pilih Status --</option>
                            <option value="OPEN" <?= (($row['status'] ?? '') == 'OPEN' ? 'selected' : '') ?>>OPEN</option>
                            <option value="CLOSED" <?= (($row['status'] ?? '') == 'CLOSED' ? 'selected' : '') ?>>CLOSED</option>
                        </select>
                    </td>

                    <td><input type="file" name="upload_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][]" accept="image/*,.pdf,video/*" multiple data-bukti-ui data-sisa="3"></td>

                    <td><textarea name="cm_<?= $prefix ?>[<?= $group ?>][<?= $j ?>][note]"><?= htmlspecialchars($row['note'] ?? '') ?></textarea></td>

                    <td class="text-center">
                        <button type="button" onclick="addCmRow(this, '<?= $prefix ?>')" class="btn-clean btn-success-clean btn-sm">+</button>
                        <button type="button" onclick="removeRow(this)" class="btn-clean btn-danger-clean btn-sm">-</button>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php
    return ob_get_clean();
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

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1e3a8a;
            --bg: #f5f7fa;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #1f2937;
            --muted: #6b7280;
            --danger: #dc2626;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .main-container {
            max-width: 1350px;
            margin: auto;
            padding: 20px;
        }

        .card-clean,
        .cm-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 22px;
        }

        .section-title,
        .cm-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 14px;
            text-transform: uppercase;
        }

        .upload-video-hint {
            display: block;
            margin-top: -8px;
            margin-bottom: 14px;
            color: #6b7280;
            font-size: 12px;
            text-transform: none;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label,
        .header-table label {
            font-size: 10px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 6px 8px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        textarea {
            min-height: 70px;
            resize: vertical;
        }

        .table-clean,
        .cm-table,
        .header-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .table-clean th,
        .header-table th {
            background: #f1f5f9;
            font-size: 12px;
            font-weight: 600;
        }

        th,
        td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        .header-table th,
        .header-table td {
            border: 1px solid var(--border);
        }

        .button-area {
            display: flex;
            gap: 12px;
            margin: 20px 0 0 20px;
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

        .btn-danger-clean {
            background: var(--danger);
            color: #fff;
        }

        .file-preview {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .file-preview img {
            width: 55px;
            height: 55px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--border);
            transition: 0.2s;
        }

        .file-preview img:hover {
            transform: scale(1.05);
        }

        .pdf-icon {
            width: 70px;
            height: 70px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        input[type="file"] {
            font-size: 11px;
            padding: 4px;
        }

        .btn-success-clean {
            background: #16a34a;
            color: #fff;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
        }

        tr.cm-row>td {
            background: #fafbfc;
            border-bottom: 2px solid var(--border);
            padding-top: 4px;
        }

        .analisa-field-row {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-bottom: 6px;
        }

        .analisa-field-row:last-child {
            margin-bottom: 0;
        }

        .analisa-field-row input {
            flex: 1;
        }

        .cm-subtitle {
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
            margin: 4px 0 8px 0;
            text-transform: uppercase;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="main-container">

                <h4 style="margin-left: 20px;">Pembahasan Problem ASAKAI</h4><br>

                <?php
                // Problem yang perlu dibuatkan FPM - lihat config/asakai_fpm_helper.php.
                // Di luar <form> utama karena tiap tombol punya form sendiri.
                require_once __DIR__ . '/../config/asakai_fpm_helper.php';
                $tugasFpm = ambilTugasAsakaiFpm($koneksi, $id);
                $adaProblem = [
                    'DOWN_TIME' => hasSavedProblem($analisaDT),
                    'NG'        => hasSavedProblem($analisaNG),
                ];
                if (!in_array(true, $adaProblem, true)) {
                    $adaProblem = ['DOWN_TIME' => true, 'NG' => true];
                }
                $flashFpm = $_SESSION['flash_asakai_fpm'] ?? null;
                unset($_SESSION['flash_asakai_fpm']);
                ?>
                <div class="card-clean" id="fpm-asakai">
                    <div class="section-title">PERLU DIBUATKAN FPM?</div>
                    <?php if ($flashFpm): ?>
                        <div class="alert alert-<?= $flashFpm['tipe'] ?> py-2"><?= htmlspecialchars($flashFpm['pesan']) ?></div>
                    <?php endif; ?>
                    <div style="font-size:12.5px; color:#6b7280; margin-bottom:12px;">
                        Kalau problem Asakai ini perlu FPM, pilih <b>Buat sekarang</b> (form FPM terbuka dengan header dari Asakai ini)
                        atau <b>Buat nanti</b>. Yang belum dibuat muncul sebagai peringatan di Dashboard; lewat
                        <?= appSettingInt('monitor_asakai_fpm_sp') - 1 ?> hari kerja tercatat <b>SP</b> untuk Staff IMP.
                        Tidak perlu FPM? Abaikan saja.
                    </div>
                    <table class="table-clean">
                        <tbody>
                            <?php foreach (ASAKAI_FPM_JENIS as $kodeJenis => $infoJenis):
                                if (empty($adaProblem[$kodeJenis]) && !isset($tugasFpm[$kodeJenis])) continue;
                                $t = $tugasFpm[$kodeJenis] ?? null;
                                $status = $t['status'] ?? null;
                            ?>
                                <tr>
                                    <td style="width:170px; font-weight:600;">FPM <?= htmlspecialchars($infoJenis['label']) ?></td>
                                    <td>
                                        <?php if ($status === 'selesai'): ?>
                                            <span class="badge bg-success">Sudah dibuat</span>
                                            <a href="<?= $kodeJenis === 'NG' ? 'form_fpm_internal.php' : 'form_fpm.php' ?>?id=<?= (int) $t['id_fpm'] ?>" class="fw-semibold ms-1"><?= htmlspecialchars($t['no_form']) ?></a>
                                            <span style="color:#6b7280; font-size:12px;">(<?= date('d-m-Y H:i', strtotime($t['selesai_at'])) ?>)</span>
                                        <?php elseif ($status !== null && $status !== 'batal'): ?>
                                            <?php if ($status === 'sp'): ?>
                                                <span class="badge bg-danger">SP</span> Terlambat - tercatat SP sejak <?= monitoringFormatTgl($t['tgl_sp']) ?>.
                                            <?php elseif ($status === 'warning'): ?>
                                                <span class="badge bg-warning text-dark">H+<?= $t['hari'] ?></span> Belum dibuat - batas akhir <b><?= monitoringFormatTgl($t['batas_akhir']) ?></b>, setelah itu SP.
                                            <?php else: ?>
                                                <span class="badge bg-info text-dark">Perlu dibuat</span> Batas akhir <b><?= monitoringFormatTgl($t['batas_akhir']) ?></b>.
                                            <?php endif; ?>
                                            <a href="<?= $infoJenis['form'] ?>?asakai=<?= $id ?>" class="btn btn-primary btn-sm ms-2"><i class="bi bi-file-earmark-plus"></i> Buat FPM sekarang</a>
                                            <?php if ($t['mulai'] === date('Y-m-d')): ?>
                                                <form method="POST" action="aksi_asakai_fpm.php" class="d-inline-flex gap-1 align-items-center ms-2"
                                                    onsubmit="return confirm('Batalkan tugas FPM <?= htmlspecialchars($infoJenis['label']) ?> untuk Asakai ini?')">
                                                    <input type="hidden" name="id_asakai" value="<?= $id ?>">
                                                    <input type="hidden" name="jenis" value="<?= $kodeJenis ?>">
                                                    <input type="hidden" name="aksi" value="batal">
                                                    <input type="text" name="alasan" placeholder="Alasan batal (salah tandai)" required style="width:210px">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm" style="white-space:nowrap">Batalkan</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <form method="POST" action="aksi_asakai_fpm.php" class="d-inline">
                                                <input type="hidden" name="id_asakai" value="<?= $id ?>">
                                                <input type="hidden" name="jenis" value="<?= $kodeJenis ?>">
                                                <button type="submit" name="aksi" value="langsung" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-plus"></i> Buat sekarang</button>
                                                <button type="submit" name="aksi" value="nanti" class="btn btn-outline-warning btn-sm ms-1"
                                                    onclick="return confirm('Tandai FPM <?= htmlspecialchars($infoJenis['label']) ?> perlu dibuat nanti? Kalau belum dibuat sampai batasnya, Staff IMP kena SP.')"><i class="bi bi-alarm"></i> Buat nanti</button>
                                            </form>
                                            <?php if ($status === 'batal'): ?>
                                                <span style="color:#6b7280; font-size:12px;" class="ms-2">Pernah dibatalkan: <?= htmlspecialchars($t['alasan_batal'] ?? '') ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="proses_asakai_simpan.php" enctype="multipart/form-data">

                    <div class="card-clean">
                        <div class="section-title">INFORMASI HEADER</div>

                        <div class="header-table-wrap">

                            <input type="hidden" name="id_asakai" value="<?= $id ?>">

                            <table class="header-table">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Tanggal</th>
                                        <th>Achievement</th>
                                        <th>Cycle Time & Efficiency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <label style="color:#1e3a8a">Mesin</label>
                                            <input type="text" name="machine" value="<?= htmlspecialchars($header['machine']) ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Tanggal Asakai</label>
                                            <input type="text" name="tanggal" value="<?= htmlspecialchars($header['tanggal']) ?>">
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
                                            <input type="text" name="nama_part" value="<?= htmlspecialchars($header['namapart']) ?>">
                                        </td>

                                        <td>
                                            <label style="color:#1e3a8a">Tanggal Problem</label>
                                            <input type="text" name="tanggal_problem" value="<?= htmlspecialchars($header['tanggal_problem']) ?>">
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
                                        <td><label style="color:#1e3a8a">Mold #</label>
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
                        <div class="section-title">ANALISA & COUNTERMEASURE - DOWN TIME</div>
                        <table class="table-clean">

                            <thead>
                                <tr>
                                    <th width="42%">Problem</th>
                                    <th width="42%">Analisa</th>
                                    <th width="16%">Action</th>
                                </tr>
                            </thead>

                            <tbody id="tbody-analisa-dt">
                                <?php foreach ($analisaDT as $i => $a) { ?>
                                    <tr class="analisa-row" data-group="<?= $i ?>">

                                        <td>
                                            <textarea name="problem_dt[<?= $i ?>]" rows="2"><?= htmlspecialchars(!empty($a['problem']) ? $a['problem'] : $header['downtime_problem']) ?></textarea>
                                        </td>

                                        <td><?= renderAnalisaFields('dt', $i, $a) ?></td>

                                        <input type="hidden" name="analisa_dt_id[<?= $i ?>]"
                                            value="<?= $a['id_analisa'] ?>">

                                        <td class="text-center">
                                            <button type="button" onclick="addAnalisaField(this, 'dt')" class="btn-clean btn-success-clean btn-sm">+</button>
                                            <button type="button" onclick="removeProblemGroup(this)" class="btn-clean btn-danger-clean btn-sm">-</button>
                                        </td>
                                    </tr>
                                <?php } ?>

                                <?php foreach ($analisaDT as $i => $a) {
                                    $rowsForGroup = $cmDTGrouped[$a['id_analisa']] ?? [];
                                    if ($i === 0) {
                                        $rowsForGroup = array_merge($rowsForGroup, $cmDTGrouped['legacy'] ?? []);
                                    }
                                ?>
                                    <tr class="cm-row" data-group="<?= $i ?>">
                                        <td colspan="3">
                                            <div class="cm-subtitle">Countermeasure - Down Time</div>
                                            <small class="upload-video-hint">Maksimal upload video 10MB</small>
                                            <?= renderCmTable('dt', $i, $rowsForGroup, $masterDept) ?>
                                        </td>
                                    </tr>
                                <?php } ?>

                            </tbody>

                        </table>

                    </div>

                    <div class="card-clean">
                        <div class="section-title">ANALISA & COUNTERMEASURE - NG</div>
                        <table class="table-clean">

                            <thead>
                                <tr>
                                    <th width="42%">Problem</th>
                                    <th width="42%">Analisa</th>
                                    <th width="16%">Action</th>
                                </tr>
                            </thead>

                            <tbody id="tbody-analisa-ng">

                                <?php foreach ($analisaNG as $i => $a) { ?>
                                    <tr class="analisa-row" data-group="<?= $i ?>">

                                        <td>
                                            <textarea name="problem_ng[<?= $i ?>]" rows="2"><?= htmlspecialchars(!empty($a['problem']) ? $a['problem'] : $header['ng_problem']) ?></textarea>
                                        </td>

                                        <td><?= renderAnalisaFields('ng', $i, $a) ?></td>

                                        <input type="hidden" name="analisa_ng_id[<?= $i ?>]"
                                            value="<?= $a['id_analisa'] ?>">

                                        <td class="text-center">
                                            <button type="button" onclick="addAnalisaField(this, 'ng')" class="btn-clean btn-success-clean btn-sm">+</button>
                                            <button type="button" onclick="removeProblemGroup(this)" class="btn-clean btn-danger-clean btn-sm">-</button>
                                        </td>
                                    </tr>
                                <?php } ?>

                                <?php foreach ($analisaNG as $i => $a) {
                                    $rowsForGroup = $cmNGGrouped[$a['id_analisa']] ?? [];
                                    if ($i === 0) {
                                        $rowsForGroup = array_merge($rowsForGroup, $cmNGGrouped['legacy'] ?? []);
                                    }
                                ?>
                                    <tr class="cm-row" data-group="<?= $i ?>">
                                        <td colspan="3">
                                            <div class="cm-subtitle">Countermeasure - NG</div>
                                            <small class="upload-video-hint">Maksimal upload video 10MB</small>
                                            <?= renderCmTable('ng', $i, $rowsForGroup, $masterDept) ?>
                                        </td>
                                    </tr>
                                <?php } ?>

                            </tbody>
                        </table>
                    </div>

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
        function checkAutoStatus(dueInput, actualInput, statusSelect) {
            if (dueInput.value && actualInput.value) {
                let dueDate = new Date(dueInput.value);
                let actualDate = new Date(actualInput.value);

                if (actualDate >= dueDate) {
                    statusSelect.value = "CLOSED";
                } else {
                    statusSelect.value = "OPEN";
                }
            }
        }

        function bindAutoCloseRow(row) {
            let due = row.querySelector('input[name$="[due]"]');
            let actual = row.querySelector('input[name$="[actual]"]');
            let status = row.querySelector('select[name$="[status]"]');

            if (due && actual && status) {
                actual.addEventListener("change", function() {
                    checkAutoStatus(due, actual, status);
                });
                due.addEventListener("change", function() {
                    checkAutoStatus(due, actual, status);
                });
            }
        }

        function initAutoClose() {
            document.querySelectorAll('table.cm-table tbody tr').forEach(bindAutoCloseRow);
        }

        document.addEventListener("DOMContentLoaded", function() {
            initAutoClose();
        });
    </script>

    <script>
        function deptOptionsHtml() {
            return `<option value="">-- Pilih Dept --</option><?php foreach ($masterDept as $dept) { ?><option value="<?= $dept ?>"><?= $dept ?></option><?php } ?>`;
        }

        // Satu baris countermeasure di dalam grup problem ke-g (index cm ke-j).
        function cmRowTemplate(prefix, g, j) {
            return `
    <tr>
        <td>
            <textarea name="cm_${prefix}[${g}][${j}][deskripsi]"></textarea>
            <input type="hidden" name="cm_${prefix}[${g}][${j}][id]" value="">
        </td>

        <td>
            <select name="cm_${prefix}[${g}][${j}][pic]">${deptOptionsHtml()}</select>
        </td>

        <td><input type="date" name="cm_${prefix}[${g}][${j}][due]"></td>
        <td><input type="date" name="cm_${prefix}[${g}][${j}][actual]"></td>

        <td>
            <select name="cm_${prefix}[${g}][${j}][status]">
                <option value="">-- Pilih Status --</option>
                <option value="OPEN">OPEN</option>
                <option value="CLOSED">CLOSED</option>
            </select>
        </td>

        <td><input type="file" name="upload_${prefix}[${g}][${j}][]" accept="image/*,.pdf,video/*" multiple data-bukti-ui data-sisa="3"></td>

        <td><textarea name="cm_${prefix}[${g}][${j}][note]"></textarea></td>

        <td class="text-center">
            <button type="button" onclick="addCmRow(this, '${prefix}')" class="btn-clean btn-success-clean btn-sm">+</button>
            <button type="button" onclick="removeRow(this)" class="btn-clean btn-danger-clean btn-sm">-</button>
        </td>
    </tr>`;
        }

        // Tambah kotak Analisa baru (analisa2..analisa4) di dalam problem yang
        // sama, dibaca dari data-next milik .analisa-fields. Problem dan
        // Countermeasure-nya tidak ikut bertambah - maksimal 4 kotak analisa.
        function addAnalisaField(btn, prefix) {
            let analisaRow = btn.closest('tr');
            let container = analisaRow.querySelector('.analisa-fields');
            let g = analisaRow.dataset.group;
            let next = parseInt(container.dataset.next, 10);

            if (next > 4) {
                alert('Maksimal 4 kotak analisa.');
                return;
            }

            let fieldName = 'analisa_' + prefix;
            let html = `
    <div class="analisa-field-row">
        <input type="text" name="${fieldName}[${g}][analisa${next}]">
        <button type="button" onclick="removeAnalisaField(this)" class="btn-clean btn-danger-clean btn-sm">-</button>
    </div>`;

            container.insertAdjacentHTML('beforeend', html);
            container.dataset.next = next + 1;
        }

        function removeAnalisaField(btn) {
            btn.closest('.analisa-field-row').remove();
        }

        // Tambah baris countermeasure baru di dalam grup problem yang sama
        // dengan tombol yang diklik (dibaca dari data-group tabel cm-table).
        function addCmRow(btn, prefix) {
            let table = btn.closest('table.cm-table');
            let g = table.dataset.group;
            let j = parseInt(table.dataset.next, 10);
            let tbody = table.querySelector('tbody');

            tbody.insertAdjacentHTML("beforeend", cmRowTemplate(prefix, g, j));
            table.dataset.next = j + 1;
            bindAutoCloseRow(tbody.lastElementChild);
        }

        // Hapus 1 problem beserta tabel countermeasure-nya (minimal 1 problem harus tersisa).
        // Baris cm-row dicari lewat data-group karena tidak lagi bersebelahan
        // langsung dengan analisa-row (semua cm-row dikumpulkan di bawah).
        function removeProblemGroup(btn) {
            let analisaRow = btn.closest("tr");
            let tbody = analisaRow.parentNode;
            let groupCount = tbody.querySelectorAll("tr.analisa-row").length;

            if (groupCount <= 1) {
                alert("Minimal harus ada 1 problem!");
                return;
            }

            let g = analisaRow.dataset.group;
            let cmRow = tbody.querySelector(`tr.cm-row[data-group="${g}"]`);
            if (cmRow) cmRow.remove();
            analisaRow.remove();
        }

        function removeRow(btn) {
            let row = btn.closest("tr");
            let tbody = row.parentNode;

            if (tbody.rows.length > 1) {
                row.remove();
            } else {
                alert("Minimal harus ada 1 baris!");
            }
        }
    </script>

    <script>
        (function() {
            const MAX_VIDEO_SIZE = 10 * 1024 * 1024; // 10MB
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
        // Kalau baris Problem diisi, Analisa di baris yang sama wajib diisi juga
        // (dan sebaliknya kosong = tidak wajib) supaya user tidak lupa mengisi analisa.
        (function() {
            function validateAnalisaSection(tbodyId, sectionLabel) {
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
                        alert('Analisa ' + sectionLabel + ' wajib diisi karena Problem sudah diisi.');
                        (analisaFields[0] || problemField).focus();
                        return false;
                    }
                }
                return true;
            }

            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    if (!validateAnalisaSection('tbody-analisa-dt', 'Down Time')) {
                        e.preventDefault();
                        return false;
                    }
                    if (!validateAnalisaSection('tbody-analisa-ng', 'NG')) {
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

<?php require_once __DIR__ . '/../config/upload_bukti_ui_helper.php'; echo renderUploadBuktiUI(); ?>
</body>

</html>