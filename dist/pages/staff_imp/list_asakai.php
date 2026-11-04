<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';
require_once __DIR__ . '/../config/asakai_summary_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$qMesin = mysqli_query($koneksi, "SELECT nama_mesin FROM master_machine ORDER BY nama_mesin ASC");
$qPic = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");
$qProblem = mysqli_query($koneksi, " SELECT downtime_problem AS problem FROM asakai_header WHERE downtime_problem IS NOT NULL
AND downtime_problem <> ''

UNION

SELECT ng_problem FROM asakai_header WHERE ng_problem IS NOT NULL AND ng_problem <> ''

UNION

SELECT deskripsi FROM asakai_ng_problem WHERE deskripsi IS NOT NULL AND deskripsi <> '' ORDER BY problem ASC
");

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

// Pilihan untuk filter Nama Part & Type
$qNamaPart = mysqli_query($koneksi, "SELECT DISTINCT namapart FROM asakai_header WHERE namapart IS NOT NULL AND namapart <> '' ORDER BY namapart ASC");
$qType = mysqli_query($koneksi, "SELECT DISTINCT type FROM asakai_header WHERE type IS NOT NULL AND type <> '' ORDER BY type ASC");

$conditions = [];
$username = $_SESSION['username'];

if (empty($_GET['plant'])) {
    if ($username == 'doni') {
        $_GET['plant'] = '1';
    } elseif ($username == 'aji') {
        $_GET['plant'] = '3';
    }
}

// Default tanggal = hari ini saat pertama kali halaman dibuka
if (!isset($_GET['tgl_awal']) || $_GET['tgl_awal'] == '') {
    $_GET['tgl_awal'] = date('Y-m-d');
}

if (!isset($_GET['tgl_akhir']) || $_GET['tgl_akhir'] == '') {
    $_GET['tgl_akhir'] = date('Y-m-d');
}

// Kegiatan hari Senin membahas problem hari Jumat & Sabtu sebelumnya,
// jadi kalau tanggal awal=akhir yang dipilih jatuh pada hari Senin,
// gabungkan otomatis ke Jumat + Sabtu sebelumnya.
if ($_GET['tgl_awal'] === $_GET['tgl_akhir'] && date('N', strtotime($_GET['tgl_awal'])) == 1) {
    $_GET['tgl_awal']  = date('Y-m-d', strtotime($_GET['tgl_awal'] . ' -3 days'));
    $_GET['tgl_akhir'] = date('Y-m-d', strtotime($_GET['tgl_akhir'] . ' -2 days'));
}

if (!empty($_GET['tgl_awal']) && !empty($_GET['tgl_akhir'])) {
    $tglAwalObj  = DateTime::createFromFormat('Y-m-d', $_GET['tgl_awal']);
    $tglAkhirObj = DateTime::createFromFormat('Y-m-d', $_GET['tgl_akhir']);
    if ($tglAwalObj && $tglAkhirObj) {
        $tglAwalDb  = $tglAwalObj->format('Y-m-d');
        $tglAkhirDb = $tglAkhirObj->format('Y-m-d');
        $conditions[] = "h.tanggal BETWEEN '$tglAwalDb' AND '$tglAkhirDb'";
    }
}

if (!empty($_GET['plant'])) {
    $plantInput = mysqli_real_escape_string($koneksi, $_GET['plant']);
    $conditions[] = "h.plant = '$plantInput'";
}

if (!empty($_GET['machine'])) {
    $machine = mysqli_real_escape_string($koneksi, $_GET['machine']);
    $conditions[] = "h.machine = '$machine'";
}

if (!empty($_GET['pic'])) {

    $pic = mysqli_real_escape_string($koneksi, $_GET['pic']);

    $conditions[] = "(
        cd.pic = '$pic'
        OR
        cn.pic = '$pic'
    )";
}

if (!empty($_GET['problem'])) {

    $problem = mysqli_real_escape_string($koneksi, $_GET['problem']);

    $conditions[] = "(
        h.downtime_problem LIKE '%$problem%'
        OR
        h.ng_problem LIKE '%$problem%'
        OR
        EXISTS (SELECT 1 FROM asakai_ng_problem anp WHERE anp.id_asakai = h.id_asakai AND anp.deskripsi LIKE '%$problem%')
    )";
}

// Filter tambahan: Nama Part / Part Number, Type, Kategori, Status
$conditions = array_merge($conditions, kondisiFilterTambahanAsakai($koneksi, $_GET));

$whereClause = "";
if (count($conditions) > 0) {
    $whereClause = "WHERE " . implode(" AND ", $conditions);
}

$query = mysqli_query($koneksi, "SELECT 
        h.id_asakai,
        h.tanggal,
        h.plant,
        h.machine,
        h.namapart,
        h.type,
        h.partnumber,
        h.ok_qty,
        h.ng_qty,
        h.efficiency,
        h.downtime,
        h.target,
        h.standard,
        h.actual,
        h.persen_ng,

        cd.file1 AS file1_downtime,
        cd.file2 AS file2_downtime,
        cd.file3 AS file3_downtime,

        cn.file1 AS file1_ng,
        cn.file2 AS file2_ng,
        cn.file3 AS file3_ng,

        a.id_analisa,

        a.analisa1, a.analisa2, a.analisa3, a.analisa4,

        a.kategori,
        CONCAT_WS('<br>', a.analisa1, '.',a.analisa2, '.',a.analisa3, '.',a.analisa4) AS analisa,

        CASE
            WHEN a.kategori = 'DOWN_TIME' THEN COALESCE(NULLIF(a.problem, ''), h.downtime_problem)
            WHEN a.kategori = 'NG' THEN COALESCE(NULLIF(a.problem, ''), TRIM(BOTH '\n' FROM CONCAT_WS('\n', NULLIF(h.ng_problem, ''), ngp.extra_ng)))
        END AS problem,

        cd.id_cm_dt AS id_cm_dt,
        cd.deskripsi AS cm_downtime,
        cd.pic AS pic_downtime,
        cd.due_date AS due_downtime,
        cd.status AS status_downtime,
        cd.note_downtime AS note_downtime,

        cn.id_cm_ng AS id_cm_ng,
        cn.deskripsi AS cm_ng,
        cn.pic AS pic_ng,
        cn.due_date AS due_ng,
        cn.status AS status_ng,
        cn.note_ng AS note_ng

    FROM asakai_header h
    LEFT JOIN asakai_analisa a
        ON a.id_asakai = h.id_asakai
    LEFT JOIN (
        SELECT id_asakai, GROUP_CONCAT(deskripsi SEPARATOR '\n') AS extra_ng
        FROM asakai_ng_problem
        GROUP BY id_asakai
    ) ngp ON ngp.id_asakai = h.id_asakai
    LEFT JOIN asakai_countermeasure_downtime cd
        ON cd.id_asakai = h.id_asakai
        AND a.kategori = 'DOWN_TIME'
        -- countermeasure milik problem/analisa ini saja; data lama (id_analisa 0/NULL)
        -- tidak punya tautan, jadi tetap ikut ke tiap problem sekategori (duplikatnya
        -- dibuang di PHP lewat buangCountermeasureDuplikat()).
        AND (cd.id_analisa = a.id_analisa OR IFNULL(cd.id_analisa, 0) = 0)
    LEFT JOIN asakai_countermeasure_ng cn
        ON cn.id_asakai = h.id_asakai
        AND a.kategori = 'NG'
        AND (cn.id_analisa = a.id_analisa OR IFNULL(cn.id_analisa, 0) = 0)

    $whereClause
    ORDER BY
        h.tanggal DESC,
        h.urutan ASC,
        a.kategori ASC
");

if (!$query) {
    die(pesanErrorDb('Gagal memuat ringkasan Asakai', $koneksi));
}

$tglFilterDelivery = mysqli_real_escape_string($koneksi, $_GET['tgl_akhir']);

$query_delivery = mysqli_query($koneksi, "SELECT
    m.nama_part,
    m.part_number,
    mp.type AS part_type,
    m.act_stock,
    m.delivery_per_hari,
    m.sisa_stock,
    m.news_delivery,
    c.countermeasure,
    c.due_date,
    c.pic
FROM `fpm_delivery_monitor` m
LEFT JOIN `master_part` mp ON mp.partnumber = m.part_number
LEFT JOIN `fpm_delivery_monitor_countermeasure` c
    ON m.id_monitor = c.id_monitor
WHERE m.tanggal = '$tglFilterDelivery'" . kondisiFilterPartStock($koneksi, $_GET, 'm.nama_part', 'm.part_number') . "
ORDER BY m.id_monitor ASC");
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
            --primary-color: #2563eb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --bg-color: #f8fafc;
            --text-color: #1e293b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
        }

        .container {
            width: 98%;
            max-width: 98vw;
            margin: 20px auto;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        h2,
        h3 {
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 24px;
        }

        .filter-section {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            outline: none;
        }

        .table-wrapper {
            max-height: 650px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #cbd5e1;
            margin-bottom: 40px;
            /* Jarak antar tabel */
        }

        table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            font-size: 13px;
            border: 1px solid #cbd5e1;
        }

        th,
        td {
            padding: 10px;
            border: 1px solid #e2e8f0;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background-color: #2c6ed7;
            color: white;
            font-weight: 600;
            text-align: center;
        }

        /* Styling khusus untuk tabel Delivery/Stock sesuai gambar */
        .thead-orange th {
            background-color: #fce4d6 !important;
            color: #000000 !important;
            font-weight: bold;
            border: 1px solid #b4c6e7;
        }

        .text-danger-custom {
            color: #ff0000;
            font-weight: bold;
        }

        tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        tr:hover {
            background-color: #e2e8f0;
            transition: 0.2s;
        }

        .group-start td {
            border-top: 2px solid #415166 !important;
        }


        /* pemisah antar problem dalam satu mesin (mis. 2 problem DOWN TIME) */

        .problem-start td {

            border-top: 2px solid #94a3b8 !important;

        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
        }

        .status-open {
            background-color: #fef3c7;
            color: #967e08;
        }

        .status-closed {
            background-color: #dcfce7;
            color: #087833;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            transition: all 0.2s;
            margin-right: 4px;
        }

        .btn-edit {
            background-color: #eff6ff;
            color: var(--primary-color);
        }

        .btn-edit:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-update {
            background-color: #ecfdf5;
            color: var(--success-color);
        }

        .btn-update:hover {
            background-color: var(--success-color);
            color: white;
        }

        .text-danger-custom {
            color: #dc3545;
            background-color: #f8d7da;
            font-weight: bold;
        }

        .print-toolbar {
            display: inline-flex;
            gap: 6px;
            margin-bottom: 16px;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #printArea,
            #printArea * {
                visibility: visible;
            }

            html,
            body,
            .app-wrapper,
            .app-main {
                overflow: visible !important;
                height: auto !important;
                max-height: none !important;
            }

            #printArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }

            .no-print {
                display: none !important;
            }

            .table-wrapper {
                max-height: none !important;
                overflow: visible !important;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
            }

            thead th {
                position: static !important;
            }
        }

        /* Tabel summary: tiap kolom punya lebar minimum supaya kata (tanggal, status, PIC, dst.)
           tidak terpotong ke baris baru; kalau layar sempit tabel di-scroll ke samping. */
        .tabel-summary-asakai th,
        .tabel-summary-asakai td {
            padding: 6px 8px;
            font-size: 12px;
            line-height: 1.35;
            word-break: normal;
            overflow-wrap: break-word;
            vertical-align: middle;
        }

        .tabel-summary-asakai thead th { white-space: nowrap; font-size: 11.5px; }
        .tabel-summary-asakai thead th:nth-child(1) { min-width: 105px; }
        .tabel-summary-asakai thead th:nth-child(2) { width: 1%; }
        .tabel-summary-asakai thead th:nth-child(3) { width: 1%; }
        .tabel-summary-asakai thead th:nth-child(4) { min-width: 150px; }
        .tabel-summary-asakai thead th:nth-child(5) { width: 1%; }
        .tabel-summary-asakai thead th:nth-child(6) { min-width: 130px; }
        .tabel-summary-asakai thead th:nth-child(7) { width: 1%; }
        .tabel-summary-asakai thead th:nth-child(8) { min-width: 130px; white-space: normal; }
        .tabel-summary-asakai thead th:nth-child(9) { min-width: 200px; }
        .tabel-summary-asakai thead th:nth-child(10) { min-width: 180px; }
        .tabel-summary-asakai thead th:nth-child(11) { min-width: 280px; }
        .tabel-summary-asakai thead th:nth-child(12) { min-width: 95px; }
        .tabel-summary-asakai thead th:nth-child(13) { min-width: 105px; }
        .tabel-summary-asakai thead th:nth-child(14) { min-width: 105px; }
        .tabel-summary-asakai thead th:nth-child(15) { min-width: 180px; }
        .tabel-summary-asakai thead th:nth-child(16) { width: 1%; }
        .tabel-summary-asakai thead th:nth-child(17) { width: 1%; }

        .tabel-summary-asakai td.col-fit { white-space: nowrap; }

        .tabel-summary-asakai .status-badge,
        .tabel-summary-asakai .btn { white-space: nowrap; }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">

                <span class="print-toolbar no-print">
                    <a href="export_asakai.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn btn-outline-secondary btn-sm" style="text-decoration:none;">
                        📊 Export Excel
                    </a>
                </span>

                <div id="printArea">
                <h2>Summary Asakai</h2>

                <form method="GET" class="filter-section no-print">
                    <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
                        <div>
                            <label>Tanggal Awal:</label>
                            <input type="date" name="tgl_awal"
                                value="<?= isset($_GET['tgl_awal']) ? htmlspecialchars($_GET['tgl_awal']) : '' ?>">
                        </div>

                        <div>
                            <label>Tanggal Akhir:</label>
                            <input type="date" name="tgl_akhir"
                                value="<?= isset($_GET['tgl_akhir']) ? htmlspecialchars($_GET['tgl_akhir']) : '' ?>">
                        </div>

                        <div>
                            <label>Plant:</label>
                            <select name="plant" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <option value="">-- Semua Plant --</option>
                                <option value="1" <?= (isset($_GET['plant']) && $_GET['plant'] == '1') ? 'selected' : '' ?>>Plant 1</option>
                                <option value="2" <?= (isset($_GET['plant']) && $_GET['plant'] == '2') ? 'selected' : '' ?>>Plant 2</option>
                                <option value="3" <?= (isset($_GET['plant']) && $_GET['plant'] == '3') ? 'selected' : '' ?>>Plant 3</option>
                            </select>
                        </div>

                        <div>
                            <label>Mesin</label>
                            <select name="machine" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Semua Mesin</option>
                                <?php while ($m = mysqli_fetch_assoc($qMesin)) { ?>
                                    <option
                                        value="<?= htmlspecialchars($m['nama_mesin']) ?>"
                                        <?= (isset($_GET['machine']) && $_GET['machine'] == $m['nama_mesin']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['nama_mesin']) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div>
                            <label>PIC</label>
                            <select name="pic" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Semua PIC</option>
                                <?php while ($p = mysqli_fetch_assoc($qPic)) { ?>
                                    <option
                                        value="<?= htmlspecialchars($p['nama_dept']) ?>"
                                        <?= (isset($_GET['pic']) && $_GET['pic'] == $p['nama_dept']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['nama_dept']) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div>
                            <label>Problem</label>
                            <input type="text" name="problem" list="problemList" placeholder="Cari Problem..."
                                value="<?= isset($_GET['problem']) ? htmlspecialchars($_GET['problem']) : '' ?>"
                                style="width:260px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                            <datalist id="problemList">
                                <?php while ($pr = mysqli_fetch_assoc($qProblem)) { ?>
                                    <option value="<?= htmlspecialchars($pr['problem']) ?>">
                                    <?php } ?>
                            </datalist>
                        </div>

                        <div>
                            <label>Nama Part / Part No.</label>
                            <input type="text" name="nama_part" list="namaPartList" placeholder="Cari Nama Part..."
                                value="<?= isset($_GET['nama_part']) ? htmlspecialchars($_GET['nama_part']) : '' ?>"
                                style="width:220px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                            <datalist id="namaPartList">
                                <?php while ($np = mysqli_fetch_assoc($qNamaPart)) { ?>
                                    <option value="<?= htmlspecialchars($np['namapart']) ?>">
                                    <?php } ?>
                            </datalist>
                        </div>

                        <div>
                            <label>Type</label>
                            <select name="type" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Semua Type</option>
                                <?php while ($t = mysqli_fetch_assoc($qType)) { ?>
                                    <option
                                        value="<?= htmlspecialchars($t['type']) ?>"
                                        <?= (isset($_GET['type']) && $_GET['type'] == $t['type']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['type']) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div>
                            <label>Kategori</label>
                            <select name="kategori" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Semua Kategori</option>
                                <option value="DOWN_TIME" <?= (isset($_GET['kategori']) && $_GET['kategori'] == 'DOWN_TIME') ? 'selected' : '' ?>>Down Time</option>
                                <option value="NG" <?= (isset($_GET['kategori']) && $_GET['kategori'] == 'NG') ? 'selected' : '' ?>>NG</option>
                            </select>
                        </div>

                        <div>
                            <label>Status</label>
                            <select name="status" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Semua Status</option>
                                <option value="OPEN" <?= (isset($_GET['status']) && $_GET['status'] == 'OPEN') ? 'selected' : '' ?>>Open</option>
                                <option value="CLOSED" <?= (isset($_GET['status']) && $_GET['status'] == 'CLOSED') ? 'selected' : '' ?>>Closed</option>
                                <option value="OVERDUE" <?= (isset($_GET['status']) && $_GET['status'] == 'OVERDUE') ? 'selected' : '' ?>>Overdue (lewat due date)</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-update" style="margin-top: 20px;">Filter Data</button>
                        <a href="list_asakai.php" class="btn btn-edit" style="margin-top: 20px; text-decoration: none;">Reset</a>
                    </div>
                </form>

                <div class="table-wrapper">
                    <table class="tabel-summary-asakai">
                        <thead>
                            <tr>
                                <th>ASAKAI DATE</th>
                                <th>PLANT</th>
                                <th>MESIN</th>
                                <th>NAMA PART</th>
                                <th>TYPE</th>
                                <th>PART NUMBER</th>
                                <th>ACHIEVEMENT</th>
                                <th>CYCLE TIME & EFFICIENCY</th>
                                <th>PROBLEM</th>
                                <th>ANALISIS</th>
                                <th>COUNTERMEASURE</th>
                                <th>PIC</th>
                                <th>DUE DATE</th>
                                <th>LAMPIRAN</th>
                                <th>NOTE</th>
                                <th>STATUS</th>
                                <th>ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="asakaiData">
                            <?php if (mysqli_num_rows($query) > 0): ?>
                                <?php
                                $data = [];
                                while ($row = mysqli_fetch_assoc($query)) {
                                    // id_asakai disertakan di key supaya 2 submission berbeda
                                    // untuk mesin+tanggal yang sama tidak tergabung jadi satu
                                    // blok dengan 1 tombol Update yang hanya mengarah ke salah satunya.
                                    $key = $row['tanggal'] . '|' . $row['machine'] . '|' . $row['id_asakai'];
                                    $data[$key][] = $row;
                                }

                                // Countermeasure is joined to every analisa row of the same
                                // id_asakai+kategori, so the same countermeasure can appear in
                                // more than one problem/analisa group below. Track ids already
                                // shown so repeats render blank instead of duplicating.
                                $shownCm = [];

                                foreach ($data as $rows):
                                    $groupProblem = [];
                                    foreach ($rows as $r) {
                                        $key = $r['id_analisa'] ?? ($r['problem'] . '|' . $r['analisa']);
                                        $groupProblem[$key][] = $r;
                                    }

                                    $groupProblem = buangCountermeasureDuplikat($groupProblem, $shownCm);
                                    $rowspanMachine = array_sum(array_map('count', $groupProblem));
                                    $firstMachine = true;

                                    // Nomor problem per kategori (DOWN TIME #1, #2, ...) kalau ada lebih dari 1.
                                    $jumlahPerKategori = array_count_values(array_map(fn($g) => (string)($g[0]['kategori'] ?? ''), $groupProblem));
                                    $nomorPerKategori = [];

                                    foreach ($groupProblem as $problems):

                                        $kategoriProblem = (string)($problems[0]['kategori'] ?? '');

                                        $nomorPerKategori[$kategoriProblem] = ($nomorPerKategori[$kategoriProblem] ?? 0) + 1;

                                        $labelProblem = labelProblemAsakai($kategoriProblem, $nomorPerKategori[$kategoriProblem], $jumlahPerKategori[$kategoriProblem] ?? 1);
                                        $rowspanProblem = count($problems);
                                        $firstProblem = true;

                                        foreach ($problems as $r):
                                            if ($r['kategori'] === 'DOWN_TIME') {
                                                $label  = 'DOWN TIME';
                                                $cm     = $r['cm_downtime'];
                                                $pic    = $r['pic_downtime'];
                                                $rawDue = $r['due_downtime'];
                                                $status = $r['status_downtime'];
                                                $note   = $r['note_downtime'];
                                            } elseif ($r['kategori'] === 'NG') {
                                                $label  = 'NG';
                                                $cm     = $r['cm_ng'];
                                                $pic    = $r['pic_ng'];
                                                $rawDue = $r['due_ng'];
                                                $status = $r['status_ng'];
                                                $note   = $r['note_ng'];
                                            } else {
                                                $label  = '-';
                                                $cm     = null;
                                                $pic    = null;
                                                $rawDue = null;
                                                $status = '';
                                                $note   = null;
                                            }

                                            if (!empty($r['_cm_kosong'])) {

                                                // Semua countermeasure problem ini sudah tampil di problem lain (data lama).

                                                $cm     = null;

                                                $pic    = null;

                                                $rawDue = null;

                                                $status = '';

                                                $note   = null;

                                            }

                                            if (!empty($rawDue) && $rawDue !== '0000-00-00') {
                                                $due = date('d-m-Y', strtotime($rawDue));
                                            } else {
                                                $due = '';
                                            }
                                ?>
                                            <tr class="<?= $firstMachine ? 'group-start' : ($firstProblem ? 'problem-start' : '') ?>">
                                                <?php if ($firstMachine): ?>
                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <?= date('d-m-Y', strtotime($r['tanggal'])) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>" class="col-fit">
                                                        <?= htmlspecialchars($r['plant']) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>" class="col-fit">
                                                        <?= htmlspecialchars($r['machine']) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <?= htmlspecialchars($r['namapart']) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>" class="col-fit">
                                                        <?= htmlspecialchars($r['type']) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <?= htmlspecialchars($r['partnumber']) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>" class="col-fit">
                                                        T : <?= $r['target'] ?><br>
                                                        OK : <?= $r['ok_qty'] ?><br>
                                                        NG : <?= $r['ng_qty'] ?><br>
                                                        DT : <?= $r['downtime'] ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        Std : <?= $r['standard'] ?><br>
                                                        Act : <?= $r['actual'] ?><br>
                                                        Eff : <?= $r['efficiency'] ?> %<br>
                                                        NG : <?= $r['persen_ng'] ?> %
                                                    </td>
                                                <?php endif; ?>

                                                <?php if ($firstProblem): ?>
                                                    <td rowspan="<?= $rowspanProblem ?>">
                                                        <strong><?= $labelProblem ?></strong><br>
                                                        <?= nl2br(htmlspecialchars($r['problem'])) ?>
                                                    </td>
                                                    <td rowspan="<?= $rowspanProblem ?>">
                                                        <?= formatAnalisaAsakai($r) ?>
                                                    </td>
                                                <?php endif; ?>

                                                <td><?= nl2br(htmlspecialchars($cm)) ?></td>
                                                <td><?= htmlspecialchars($pic) ?></td>
                                                <td><?= $due ?></td>
                                                <td>
                                                    <?php
                                                    $hasFile = false;
                                                    if ($r['kategori'] === 'DOWN_TIME') {
                                                        $files = [$r['file1_downtime'], $r['file2_downtime'], $r['file3_downtime']];
                                                    } elseif ($r['kategori'] === 'NG') {
                                                        $files = [$r['file1_ng'], $r['file2_ng'], $r['file3_ng']];
                                                    } else {
                                                        $files = [];
                                                    }

                                                    foreach ($files as $f) {
                                                        if (!empty($f)) {
                                                            $hasFile = true;
                                                            break;
                                                        }
                                                    }

                                                    if ($hasFile):
                                                    ?>
                                                        <a href="detail_countermeasure_asakai.php?id=<?= $r['id_asakai'] ?>" class="btn btn-edit">Cek File</a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= !empty($note) ? nl2br(htmlspecialchars($note)) : '-' ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    if (strtolower($status) === 'open') {
                                                        $statusClass = 'status-open';
                                                    } elseif (strtolower($status) === 'closed') {
                                                        $statusClass = 'status-closed';
                                                    }
                                                    ?>
                                                    <span class="status-badge <?= $statusClass ?>">
                                                        <?= htmlspecialchars($status) ?>
                                                    </span>
                                                </td>
                                                <?php if ($firstMachine): ?>
                                                    <td rowspan="<?= $rowspanMachine ?>">
                                                        <a href="update_asakai.php?id=<?= $r['id_asakai'] ?>" class="btn btn-update">Update</a>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                <?php
                                            $firstMachine = false;
                                            $firstProblem = false;
                                        endforeach;
                                    endforeach;
                                endforeach;
                                ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="17" style="text-align:center;">Data tidak ditemukan</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 50px;">
                    <h3 style="text-align: left; font-size: 18px; font-weight: bold; border-left: 5px solid #fce4d6; padding-left: 10px;">
                        Delivery & Sisa Stock Part
                    </h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr class="thead-orange">
                                    <th style="width: 50px;">No.</th>
                                    <th>Nama Part</th>
                                    <th>Type</th>
                                    <th>Part Number</th>
                                    <th>Stock FG</th>
                                    <th>Delivery / hari</th>
                                    <th>Kemampuan Delivery</th>
                                    <th>News Delivery</th>
                                    <th>Countermeasure</th>
                                    <th>Due Date</th>
                                    <th>PIC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (mysqli_num_rows($query_delivery) > 0):
                                    $no = 1;
                                    while ($row_del = mysqli_fetch_assoc($query_delivery)):
                                        $stockClass = ($row_del['act_stock'] < $row_del['delivery_per_hari']) ? 'class="text-danger-custom"' : '';
                                        $sisaStockClass = ($row_del['sisa_stock'] < 0) ? 'class="text-danger-custom"' : '';

                                        $due_date_display = '';
                                        if (!empty($row_del['due_date']) && $row_del['due_date'] != '0000-00-00') {
                                            $due_date_display = date('d-m-Y', strtotime($row_del['due_date']));
                                        }
                                ?>
                                        <tr>
                                            <td style="text-align: center;"><?= $no++; ?>.</td>
                                            <td><?= htmlspecialchars($row_del['nama_part']); ?></td>
                                            <td><?= htmlspecialchars($row_del['part_type']); ?></td>
                                            <td><?= htmlspecialchars($row_del['part_number']); ?></td>
                                            <td <?= $stockClass ?>>
                                                <?= number_format($row_del['act_stock']) ?>
                                            </td>

                                            <td style="text-align: right;"><?= number_format($row_del['delivery_per_hari']); ?></td>

                                            <td style="text-align: right; font-weight: bold;" <?= $sisaStockClass; ?>><?= htmlspecialchars($row_del['sisa_stock']); ?></td>
                                            <td><?= htmlspecialchars($row_del['news_delivery']); ?></td>
                                            <td><?= htmlspecialchars($row_del['countermeasure']); ?></td>
                                            <td style="text-align: center;"><?= $due_date_display; ?></td>
                                            <td><?= htmlspecialchars($row_del['pic']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" style="text-align:center; padding: 20px;">Data stock part tidak ditemukan untuk tanggal/plant terpilih.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="margin-top: 50px;">
                    <h3 style="text-align: left; font-size: 18px; font-weight: bold; border-left: 5px solid #fce4d6; padding-left: 10px;">
                        Stock Casting - Machining
                    </h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr class="thead-orange">
                                    <th style="width: 50px;">No.</th>
                                    <th>Part ( Stock Casting )</th>
                                    <th>Type</th>
                                    <th>Part Number</th>
                                    <th>DCM</th>
                                    <th>Min Stock (3x Deliv)</th>
                                    <th>Stock PPC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $query_casting = mysqli_query($koneksi, "SELECT
                                        sc.id_casting,
                                        sc.part_number,
                                        sc.nama_part,
                                        mp.type AS part_type,
                                        sc.dcm,
                                        sc.min_stock,
                                        sc.stock_ppc
                                    FROM stock_casting_monitor sc
                                    LEFT JOIN master_part mp ON mp.partnumber = sc.part_number
                                    WHERE sc.tanggal = '$tglFilterDelivery'" . kondisiFilterPartStock($koneksi, $_GET, 'sc.nama_part', 'sc.part_number') . "
                                    ORDER BY sc.id_casting ASC
                                ");

                                if (mysqli_num_rows($query_casting) > 0):
                                    $noCst = 1;
                                    while ($row_cst = mysqli_fetch_assoc($query_casting)):
                                        $ppc = (int)$row_cst['stock_ppc'];
                                        $ppcDisplay = $ppc < 0 ? '(' . number_format(abs($ppc)) . ')' : number_format($ppc);
                                ?>
                                        <tr>
                                            <td style="text-align: center;"><?= $noCst++; ?>.</td>
                                            <td><?= htmlspecialchars($row_cst['nama_part']); ?></td>
                                            <td><?= htmlspecialchars($row_cst['part_type']); ?></td>
                                            <td><?= htmlspecialchars($row_cst['part_number']); ?></td>
                                            <td style="text-align: center;"><?= number_format($row_cst['dcm']); ?></td>
                                            <td style="text-align: right;"><?= number_format($row_cst['min_stock']); ?></td>
                                            <td style="text-align: center; background:#fff;<?= $ppc < 0 ? ' color:#ff0000; font-weight:700;' : '' ?>"><?= $ppcDisplay; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center; padding: 20px;">Data stock casting tidak ditemukan untuk tanggal terpilih.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                </div>
            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>
    <script src="../../../dist/js/print-export.js"></script>

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