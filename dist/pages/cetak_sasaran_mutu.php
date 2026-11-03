<?php
require_once __DIR__ . '/config/branding_helper.php';
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include 'koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

// Satu baris daftar "Analisa Masalah" format laporan QMR: "Nama Part #Mold - Detail Masalah".
function itemFpmSasaranMutu(array $row, string $status): array
{
    $label = trim($row['nama_part'] ?? '') ?: '-';
    if (trim((string) ($row['mold_no'] ?? '')) !== '') {
        $label .= ' #' . trim($row['mold_no']);
    }
    $masalah = trim($row['jenis_masalah_detail'] ?? '') ?: trim($row['jenis_masalah'] ?? '');
    if ($masalah !== '') {
        $label .= ' - ' . $masalah;
    }

    return [
        'id'       => (int) $row['id_fpm'],
        'label'    => $label,
        'status'   => $status,
        'berulang' => (int) ($row['kejadian_berulang_ke'] ?? 0) >= 1,
    ];
}

// Akar masalah (why terakhir yang terisi di tiap baris analisa 5-why) + tindakan
// corrective/preventive beserta foto buktinya, untuk bagian "Tindakan Perbaikan".
function tindakanFpmSasaranMutu($koneksi, int $idFpm): array
{
    $akar = [];
    $stmt = mysqli_prepare($koneksi, "SELECT why_1, why_2, why_3, why_4, why_5, why_6, why_7, why_8, why_9, why_10
        FROM fpm_analisa WHERE id_fpm = ? ORDER BY id ASC");
    mysqli_stmt_bind_param($stmt, "i", $idFpm);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $terakhir = '';
        foreach ($row as $why) {
            if (trim((string) $why) !== '') {
                $terakhir = trim($why);
            }
        }
        if ($terakhir !== '' && !in_array($terakhir, $akar, true)) {
            $akar[] = $terakhir;
        }
    }

    $aksi = [];
    $sumber = [
        ['fpm_corrective_action', 'fpm_corrective_files', 'corrective_id'],
        ['fpm_preventive_action', 'fpm_preventive_files', 'preventive_id'],
    ];
    foreach ($sumber as [$tabel, $tabelFile, $kolomFk]) {
        $stmt = mysqli_prepare($koneksi, "SELECT id, tindakan, due_date, actual_date, status, file, note
            FROM $tabel WHERE id_fpm = ? AND tindakan IS NOT NULL AND tindakan != '' ORDER BY id ASC");
        mysqli_stmt_bind_param($stmt, "i", $idFpm);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $files = trim($row['file'] ?? '') !== '' ? [trim($row['file'])] : [];
            $qFile = mysqli_query($koneksi, "SELECT file FROM $tabelFile WHERE $kolomFk = " . (int) $row['id']);
            while ($qFile && $f = mysqli_fetch_assoc($qFile)) {
                $files[] = $f['file'];
            }
            $row['files'] = $files;
            $aksi[] = $row;
        }
    }

    return ['akar' => $akar, 'aksi' => $aksi];
}

// Format tanggal gaya laporan Excel: 7-Aug-26.
function tanggalSasaranMutu($tanggal): string
{
    $tanggal = trim((string) $tanggal);
    if ($tanggal === '' || str_starts_with($tanggal, '0000')) {
        return '';
    }
    $ts = strtotime($tanggal);
    return $ts ? date('j-M-y', $ts) : $tanggal;
}

// ================= FILTER =================
$kategori = $_GET['kategori'] ?? 'fpm_internal';
$filterBulan = !empty($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');

$bulanIndo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$exp = explode('-', $filterBulan);
$tahun = $exp[0] ?? date('Y');
$bulan = $exp[1] ?? date('m');
$namaPeriode = ($bulanIndo[$bulan] ?? '-') . ' ' . $tahun;
$filterBulanEsc = mysqli_real_escape_string($koneksi, $filterBulan);
$tahunEsc = mysqli_real_escape_string($koneksi, $tahun);

$kategoriList = [
    'fpm_internal'     => 'FPM Internal',
    'fpm_downtime'     => 'FPM Down Time',
    'claim_machining'  => 'FPM Claim Machining',
    'asakai'           => 'Asakai',
];
if (!isset($kategoriList[$kategori])) {
    $kategori = 'fpm_internal';
}
$judulKategori = $kategoriList[$kategori];

// Semua variabel di bawah ini dinormalisasi supaya template render-nya (di bagian
// bawah file) sama persis untuk 4 kategori, walau query & tabel sumbernya beda-beda.
$labelBulanTahunIni = [];
$totalPerBulan = [];
$dibahasPerBulan = [];
$statusPieLabels = [];
$statusPieValues = [];
$statusPieColors = ['#9ca3af', '#facc15', '#22c55e', '#ef4444'];
$cmOpen = 0;
$cmClosed = 0;
$problemBerulangSupported = true;
$totalBaru = 0;
$totalBerulang = 0;
$klasifikasiSupported = true;
$klasifikasiLabel = [];
$klasifikasiTotal = [];
$klasifikasiCaveat = '';
$satuanUnit = 'FPM';
$totalUnitBulanIni = 0;
$totalDibahasBulanIni = 0;
// Kolom kanan laporan: daftar Analisa Masalah per status + Tindakan Perbaikan
// (hanya untuk item problem berulang, sesuai format laporan Excel QMR).
$daftarItem = [];          // [id, label, status, berulang]
$urutanGrupItem = [];      // urutan grup status di daftar Analisa Masalah
$tindakanBerulang = [];    // [nomor, label, akar[], aksi[]]
$tindakanSupported = true;

foreach ($bulanIndo as $noBulan => $namaBulan) {
    $labelBulanTahunIni[] = strtoupper(substr($namaBulan, 0, 3)) . "'" . substr($tahun, 2, 2);
}

// ============================================================
// KATEGORI: FPM Internal / FPM Down Time (sumber data sama, fpm_header, beda
// jenis_fpm)
// ============================================================
if ($kategori === 'fpm_internal' || $kategori === 'fpm_downtime') {

    if ($kategori === 'fpm_internal') {
        $jenisFpmList = ['NG Casting', 'NG Machining'];
        $excludeFlowQa = true;
    } else {
        $jenisFpmList = ['Down Time'];
        $excludeFlowQa = false;
    }

    $jenisEsc = array_map(fn($j) => "'" . mysqli_real_escape_string($koneksi, $j) . "'", $jenisFpmList);
    $inClause = implode(',', $jenisEsc);
    $flowQaClause = $excludeFlowQa ? "AND (h.flow_type IS NULL OR h.flow_type != 'QA')" : '';
    $flowQaClauseH2 = $excludeFlowQa ? "AND (h2.flow_type IS NULL OR h2.flow_type != 'QA')" : '';

    // ---- Chart tren 1 tahun ----
    $queryTren = mysqli_query($koneksi, "SELECT DATE_FORMAT(h.tgl_terbit, '%Y-%m') AS bulan, COUNT(*) AS total,
        SUM(CASE WHEN (
            (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
        ) > 0 THEN 1 ELSE 0 END) AS sudah_dibahas
        FROM fpm_header h
        WHERE h.jenis_fpm IN ($inClause) $flowQaClause
          AND DATE_FORMAT(h.tgl_terbit, '%Y') = '$tahunEsc'
        GROUP BY bulan ORDER BY bulan ASC");
    $totalPerBulanRaw = [];
    $dibahasPerBulanRaw = [];
    while ($row = mysqli_fetch_assoc($queryTren)) {
        $totalPerBulanRaw[$row['bulan']] = (int) $row['total'];
        $dibahasPerBulanRaw[$row['bulan']] = (int) $row['sudah_dibahas'];
    }
    foreach ($bulanIndo as $noBulan => $namaBulan) {
        $key = "$tahun-$noBulan";
        $totalPerBulan[] = $totalPerBulanRaw[$key] ?? 0;
        $dibahasPerBulan[] = $dibahasPerBulanRaw[$key] ?? 0;
    }

    // ---- Status FPM (pie 1) ----
    $qStatusFpm = mysqli_query($koneksi, "SELECT h.id_fpm, h.nama_part, h.mold_no, h.jenis_masalah,
            h.jenis_masalah_detail, h.kejadian_berulang_ke, h.status_reject, p.disetujui,
            (
                (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
            ) AS jml_aksi
        FROM fpm_header h
        LEFT JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
        WHERE h.jenis_fpm IN ($inClause) $flowQaClause
          AND DATE_FORMAT(h.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
        ORDER BY h.tgl_terbit ASC, h.id_fpm ASC
    ");
    $statusFpm = ['Belum Bahas' => 0, 'Bahas Sebagian' => 0, 'Sudah Approval' => 0, 'Revisi, Belum Approval' => 0];
    while ($row = mysqli_fetch_assoc($qStatusFpm)) {
        if (($row['status_reject'] ?? '') === 'REJECT') {
            $statusItem = 'Revisi, Belum Approval';
        } elseif (trim($row['disetujui'] ?? '') === 'Directure') {
            $statusItem = 'Sudah Approval';
        } elseif ((int) $row['jml_aksi'] > 0) {
            $statusItem = 'Bahas Sebagian';
        } else {
            $statusItem = 'Belum Bahas';
        }
        $statusFpm[$statusItem]++;
        $daftarItem[] = itemFpmSasaranMutu($row, $statusItem);
    }
    $urutanGrupItem = ['Sudah Approval', 'Bahas Sebagian', 'Revisi, Belum Approval', 'Belum Bahas'];
    $statusPieLabels = array_keys($statusFpm);
    $statusPieValues = array_values($statusFpm);

    // ---- Status Countermeasure (pie 2) ----
    $qCmStatus = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
        SUM(CASE WHEN gabungan.status IS NULL OR gabungan.status != 'CLOSED' THEN 1 ELSE 0 END) AS total_open,
        SUM(CASE WHEN gabungan.status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
        FROM (
            SELECT ca.id_fpm, ca.status FROM fpm_corrective_action ca
            JOIN fpm_header h ON h.id_fpm = ca.id_fpm
            WHERE h.jenis_fpm IN ($inClause) $flowQaClause
              AND DATE_FORMAT(h.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
            UNION ALL
            SELECT pa.id_fpm, pa.status FROM fpm_preventive_action pa
            JOIN fpm_header h ON h.id_fpm = pa.id_fpm
            WHERE h.jenis_fpm IN ($inClause) $flowQaClause
              AND DATE_FORMAT(h.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
        ) AS gabungan
    ")) ?: [];
    $cmOpen = (int) ($qCmStatus['total_open'] ?? 0);
    $cmClosed = (int) ($qCmStatus['total_closed'] ?? 0);

    // ---- Problem baru vs berulang (pie 3) ----
    $qBaruBerulang = mysqli_query($koneksi, "SELECT
            SUM(CASE WHEN kejadian_berulang_ke IS NULL OR kejadian_berulang_ke = 0 THEN 1 ELSE 0 END) AS baru,
            SUM(CASE WHEN kejadian_berulang_ke >= 1 THEN 1 ELSE 0 END) AS berulang
        FROM fpm_header h
        WHERE h.jenis_fpm IN ($inClause) $flowQaClause
          AND DATE_FORMAT(h.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
    ");
    $rowBaruBerulang = mysqli_fetch_assoc($qBaruBerulang) ?: [];
    $totalBaru = (int) ($rowBaruBerulang['baru'] ?? 0);
    $totalBerulang = (int) ($rowBaruBerulang['berulang'] ?? 0);

    // ---- Klasifikasi problem (pie 4) ----
    $qKlasifikasi = mysqli_query($koneksi, "SELECT jenis_masalah, COUNT(*) AS total
        FROM fpm_header h
        WHERE h.jenis_fpm IN ($inClause) $flowQaClause
          AND DATE_FORMAT(h.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
          AND jenis_masalah IS NOT NULL AND jenis_masalah != ''
        GROUP BY jenis_masalah ORDER BY total DESC");
    while ($row = mysqli_fetch_assoc($qKlasifikasi)) {
        $klasifikasiLabel[] = $row['jenis_masalah'];
        $klasifikasiTotal[] = (int) $row['total'];
    }


    $totalUnitBulanIni = array_sum($statusPieValues);
    $totalDibahasBulanIni = $statusFpm['Bahas Sebagian'] + $statusFpm['Sudah Approval'] + $statusFpm['Revisi, Belum Approval'];

}

// ============================================================
// KATEGORI: FPM Claim Machining
// ============================================================
if ($kategori === 'claim_machining') {

    $satuanUnit = 'FPM Claim Machining';

    // ---- Chart tren 1 tahun ----
    $queryTren = mysqli_query($koneksi, "SELECT DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') AS bulan, COUNT(*) AS total,
        SUM(CASE WHEN h.cm_dept_head_dijawab_at IS NOT NULL THEN 1 ELSE 0 END) AS sudah_dibahas
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y') = '$tahunEsc'
        GROUP BY bulan ORDER BY bulan ASC");
    $totalPerBulanRaw = [];
    $dibahasPerBulanRaw = [];
    while ($row = mysqli_fetch_assoc($queryTren)) {
        $totalPerBulanRaw[$row['bulan']] = (int) $row['total'];
        $dibahasPerBulanRaw[$row['bulan']] = (int) $row['sudah_dibahas'];
    }
    foreach ($bulanIndo as $noBulan => $namaBulan) {
        $key = "$tahun-$noBulan";
        $totalPerBulan[] = $totalPerBulanRaw[$key] ?? 0;
        $dibahasPerBulan[] = $dibahasPerBulanRaw[$key] ?? 0;
    }

    // ---- Status FPM (pie 1) ----
    $qStatus = mysqli_query($koneksi, "SELECT h.id_fpm, h.nama_part, h.mold_no, h.jenis_masalah,
            h.jenis_masalah_detail, h.kejadian_berulang_ke,
            h.status_reject, h.cm_dept_head_dijawab_at, h.cm_directur_approved_at
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulanEsc'
        ORDER BY h.tgl_pembuatan ASC, h.id_fpm ASC
    ");
    $statusCm = ['Belum Bahas' => 0, 'Bahas Sebagian' => 0, 'Sudah Approve' => 0, 'Revisi Belum ACC' => 0];
    while ($row = mysqli_fetch_assoc($qStatus)) {
        if (($row['status_reject'] ?? '') === 'REJECT') {
            $statusItem = 'Revisi Belum ACC';
        } elseif (!empty($row['cm_directur_approved_at'])) {
            $statusItem = 'Sudah Approve';
        } elseif (!empty($row['cm_dept_head_dijawab_at'])) {
            $statusItem = 'Bahas Sebagian';
        } else {
            $statusItem = 'Belum Bahas';
        }
        $statusCm[$statusItem]++;
        $daftarItem[] = itemFpmSasaranMutu($row, $statusItem);
    }
    $urutanGrupItem = ['Sudah Approve', 'Bahas Sebagian', 'Revisi Belum ACC', 'Belum Bahas'];
    $statusPieLabels = array_keys($statusCm);
    $statusPieValues = array_values($statusCm);

    // ---- Status Countermeasure (pie 2) ----
    $qCmStatus = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
        SUM(CASE WHEN gabungan.status IS NULL OR gabungan.status != 'CLOSED' THEN 1 ELSE 0 END) AS total_open,
        SUM(CASE WHEN gabungan.status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
        FROM (
            SELECT ca.id_fpm, ca.status FROM fpm_corrective_action ca
            JOIN fpm_header h ON h.id_fpm = ca.id_fpm WHERE h.flow_type = 'CLAIMMACH'
            UNION ALL
            SELECT pa.id_fpm, pa.status FROM fpm_preventive_action pa
            JOIN fpm_header h ON h.id_fpm = pa.id_fpm WHERE h.flow_type = 'CLAIMMACH'
        ) AS gabungan
    ")) ?: [];
    $cmOpen = (int) ($qCmStatus['total_open'] ?? 0);
    $cmClosed = (int) ($qCmStatus['total_closed'] ?? 0);

    // ---- Problem baru vs berulang (pie 3) ----
    $qBaruBerulang = mysqli_query($koneksi, "SELECT
            SUM(CASE WHEN kejadian_berulang_ke IS NULL OR kejadian_berulang_ke = 0 THEN 1 ELSE 0 END) AS baru,
            SUM(CASE WHEN kejadian_berulang_ke >= 1 THEN 1 ELSE 0 END) AS berulang
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulanEsc'
    ");
    $rowBaruBerulang = mysqli_fetch_assoc($qBaruBerulang) ?: [];
    $totalBaru = (int) ($rowBaruBerulang['baru'] ?? 0);
    $totalBerulang = (int) ($rowBaruBerulang['berulang'] ?? 0);

    // ---- Klasifikasi problem (pie 4) ----
    $qKlasifikasi = mysqli_query($koneksi, "SELECT jenis_masalah, COUNT(*) AS total
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulanEsc'
          AND jenis_masalah IS NOT NULL AND jenis_masalah != ''
        GROUP BY jenis_masalah ORDER BY total DESC");
    while ($row = mysqli_fetch_assoc($qKlasifikasi)) {
        $klasifikasiLabel[] = $row['jenis_masalah'];
        $klasifikasiTotal[] = (int) $row['total'];
    }


    $totalUnitBulanIni = array_sum($statusPieValues);
    $totalDibahasBulanIni = $statusCm['Bahas Sebagian'] + $statusCm['Sudah Approve'] + $statusCm['Revisi Belum ACC'];

}

// ============================================================
// KATEGORI: Asakai (skema tabel beda total dari FPM)
// ============================================================
if ($kategori === 'asakai') {

    $satuanUnit = 'Asakai';
    $statusPieColors = ['#9ca3af', '#22c55e']; // cuma 2 status (tidak ada tahap approval berjenjang seperti FPM)

    // ---- Chart tren 1 tahun ----
    $queryTren = mysqli_query($koneksi, "SELECT LEFT(ah.tanggal, 7) AS bulan, COUNT(*) AS total,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM asakai_analisa aa
            WHERE aa.id_asakai = ah.id_asakai AND aa.analisa1 IS NOT NULL AND TRIM(aa.analisa1) != ''
        ) THEN 1 ELSE 0 END) AS sudah_dibahas
        FROM asakai_header ah
        WHERE LEFT(ah.tanggal, 4) = '$tahunEsc'
        GROUP BY bulan ORDER BY bulan ASC");
    $totalPerBulanRaw = [];
    $dibahasPerBulanRaw = [];
    while ($row = mysqli_fetch_assoc($queryTren)) {
        $totalPerBulanRaw[$row['bulan']] = (int) $row['total'];
        $dibahasPerBulanRaw[$row['bulan']] = (int) $row['sudah_dibahas'];
    }
    foreach ($bulanIndo as $noBulan => $namaBulan) {
        $key = "$tahun-$noBulan";
        $totalPerBulan[] = $totalPerBulanRaw[$key] ?? 0;
        $dibahasPerBulan[] = $dibahasPerBulanRaw[$key] ?? 0;
    }

    // ---- Status Asakai (pie 1) - cuma 2 state, tidak ada jenjang approval ----
    $qStatus = mysqli_query($koneksi, "SELECT
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM asakai_analisa aa
                WHERE aa.id_asakai = ah.id_asakai AND aa.analisa1 IS NOT NULL AND TRIM(aa.analisa1) != ''
            ) THEN 1 ELSE 0 END) AS sudah_dibahas,
            SUM(CASE WHEN NOT EXISTS (
                SELECT 1 FROM asakai_analisa aa
                WHERE aa.id_asakai = ah.id_asakai AND aa.analisa1 IS NOT NULL AND TRIM(aa.analisa1) != ''
            ) THEN 1 ELSE 0 END) AS belum_dibahas
        FROM asakai_header ah
        WHERE LEFT(ah.tanggal, 7) = '$filterBulanEsc'
    ");
    $rowStatus = mysqli_fetch_assoc($qStatus) ?: [];
    $statusPieLabels = ['Belum Dibahas', 'Sudah Dibahas'];

    // Daftar item Asakai bulan ini (untuk kolom Analisa Masalah).
    $qItemAsakai = mysqli_query($koneksi, "SELECT ah.id_asakai, ah.tanggal, ah.machine, ah.namapart,
            COALESCE(NULLIF(ah.downtime_problem, ''), ah.ng_problem) AS problem,
            EXISTS (
                SELECT 1 FROM asakai_analisa aa
                WHERE aa.id_asakai = ah.id_asakai AND aa.analisa1 IS NOT NULL AND TRIM(aa.analisa1) != ''
            ) AS sudah_dibahas
        FROM asakai_header ah
        WHERE LEFT(ah.tanggal, 7) = '$filterBulanEsc'
        ORDER BY ah.tanggal ASC, ah.id_asakai ASC");
    while ($qItemAsakai && $row = mysqli_fetch_assoc($qItemAsakai)) {
        $label = trim(($row['machine'] ?? '') . ' - ' . ($row['namapart'] ?? ''), ' -');
        if (trim($row['problem'] ?? '') !== '') {
            $label .= ' - ' . mb_strimwidth(trim($row['problem']), 0, 60, '...');
        }
        $daftarItem[] = [
            'id'       => (int) $row['id_asakai'],
            'label'    => $label,
            'status'   => $row['sudah_dibahas'] ? 'Sudah Dibahas' : 'Belum Dibahas',
            'berulang' => false,
        ];
    }
    $urutanGrupItem = ['Sudah Dibahas', 'Belum Dibahas'];
    $statusPieValues = [(int) ($rowStatus['belum_dibahas'] ?? 0), (int) ($rowStatus['sudah_dibahas'] ?? 0)];

    // ---- Status Countermeasure (pie 2) ----
    $qCmStatus = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
        SUM(CASE WHEN gabungan.status IS NULL OR gabungan.status != 'CLOSED' THEN 1 ELSE 0 END) AS total_open,
        SUM(CASE WHEN gabungan.status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
        FROM (
            SELECT cd.id_asakai, cd.status FROM asakai_countermeasure_downtime cd
            JOIN asakai_header ah ON ah.id_asakai = cd.id_asakai WHERE LEFT(ah.tanggal, 7) = '$filterBulanEsc'
            UNION ALL
            SELECT cn.id_asakai, cn.status FROM asakai_countermeasure_ng cn
            JOIN asakai_header ah ON ah.id_asakai = cn.id_asakai WHERE LEFT(ah.tanggal, 7) = '$filterBulanEsc'
        ) AS gabungan
    ")) ?: [];
    $cmOpen = (int) ($qCmStatus['total_open'] ?? 0);
    $cmClosed = (int) ($qCmStatus['total_closed'] ?? 0);

    // ---- Problem berulang: TIDAK ADA datanya untuk Asakai (tidak ada kolom/mekanisme
    // deteksi kejadian berulang seperti fpm_header.kejadian_berulang_ke) ----
    $problemBerulangSupported = false;

    // ---- Klasifikasi problem: Asakai cuma teks bebas (downtime_problem/ng_problem),
    // tidak ada master kategori - dikelompokkan apa adanya per teks, bisa terpecah. ----
    $qKlasifikasi = mysqli_query($koneksi, "SELECT problem, COUNT(*) AS total FROM (
            SELECT downtime_problem AS problem FROM asakai_header
            WHERE LEFT(tanggal, 7) = '$filterBulanEsc' AND downtime_problem IS NOT NULL AND downtime_problem != ''
            UNION ALL
            SELECT ng_problem AS problem FROM asakai_header
            WHERE LEFT(tanggal, 7) = '$filterBulanEsc' AND ng_problem IS NOT NULL AND ng_problem != ''
        ) t GROUP BY problem ORDER BY total DESC LIMIT 8");
    while ($row = mysqli_fetch_assoc($qKlasifikasi)) {
        $klasifikasiLabel[] = mb_strimwidth($row['problem'], 0, 40, '...');
        $klasifikasiTotal[] = (int) $row['total'];
    }
    $klasifikasiCaveat = 'Dikelompokkan dari teks bebas (bukan kategori baku) - kemungkinan terpecah kalau redaksinya berbeda-beda.';

    $totalUnitBulanIni = array_sum($statusPieValues);
    $totalDibahasBulanIni = (int) ($rowStatus['sudah_dibahas'] ?? 0);

    // Asakai tidak punya data problem berulang, jadi bagian Tindakan Perbaikan
    // (khusus problem berulang) tidak bisa diisi.
    $tindakanSupported = false;
}
// ============================================================
// Penomoran daftar Analisa Masalah (urut per grup status, nomor lanjut antar grup
// seperti laporan Excel) + data Tindakan Perbaikan untuk item problem berulang.
// ============================================================
$grupItem = array_fill_keys($urutanGrupItem, []);
foreach ($daftarItem as $item) {
    $grupItem[$item['status']][] = $item;
}
$nomorItem = 0;
$itemBerulang = [];
foreach ($grupItem as $namaGrup => $items) {
    foreach ($items as $i => $item) {
        $grupItem[$namaGrup][$i]['nomor'] = ++$nomorItem;
        if ($item['berulang']) {
            $itemBerulang[] = $grupItem[$namaGrup][$i];
        }
    }
}
if ($tindakanSupported) {
    foreach ($itemBerulang as $item) {
        $tindakanBerulang[] = $item + tindakanFpmSasaranMutu($koneksi, $item['id']);
    }
}

$tsPeriode = mktime(0, 0, 0, (int) $bulan, 1, (int) $tahun);
$periodeSingkat = strtoupper(date('M', $tsPeriode)) . "'" . date('y', $tsPeriode); // JUL'26
$periodeKop = date('M-y', $tsPeriode);                                          // Jul-26
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Cetak Sasaran Mutu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0" defer></script>

    <style>
        /* Tampilan meniru format laporan Excel "Laporan Pencapaian Sasaran Mutu
           Manajemen" (FOR/QMR/03/00/01): kop bergaris, kiri grafik + comment,
           kanan daftar Analisa Masalah + Tindakan Perbaikan problem berulang. */
        * { box-sizing: border-box; }
        body {
            font-family: Calibri, 'Segoe UI', Arial, sans-serif;
            background: #eef2f7;
            margin: 0;
            color: #000;
        }
        .toolbar {
            background: #fff;
            padding: 14px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .toolbar select, .toolbar input {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
        }
        .toolbar button {
            padding: 7px 16px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary-custom { background: #0d6efd; color: #fff; }
        .btn-print-custom { background: #16a34a; color: #fff; }

        .laporan-wrapper {
            max-width: 1280px;
            margin: 20px auto;
            background: #fff;
            padding: 16px 18px 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,.08);
            font-size: 12px;
        }

        /* ---------- Kop ---------- */
        .form-code { text-align: right; font-size: 10px; margin-bottom: 4px; }
        .form-code span { border: 1px solid #000; padding: 1px 8px; }
        .kop {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 260px;
            gap: 20px;
            align-items: start;
        }
        table.kop-tabel { width: 100%; border-collapse: collapse; }
        table.kop-tabel td { border: 1px solid #000; padding: 2px 8px; text-align: center; }
        table.kop-tabel .logo { width: 90px; vertical-align: bottom; padding: 4px; }
        table.kop-tabel .logo img { width: 52px; display: block; margin: 0 auto 4px; }
        table.kop-tabel .logo div { font-size: 10px; font-weight: 700; }
        table.kop-tabel .judul { font-size: 16px; font-weight: 700; }
        table.kop-tabel .subjudul { font-size: 14px; }
        table.kop-tabel .bulan-label { width: 90px; font-size: 13px; }
        table.kop-tabel .bulan-val { font-size: 13px; }
        table.ttd { width: 100%; border-collapse: collapse; }
        table.ttd td { border: 1px solid #000; width: 33.33%; text-align: center; font-size: 10px; }
        table.ttd tr:first-child td { height: 62px; }
        .psmm { font-size: 11px; margin: 10px 0 6px; }

        /* ---------- Isi 2 kolom ---------- */
        .isi {
            display: grid;
            grid-template-columns: minmax(0, 60fr) minmax(0, 40fr);
            border: 1px solid #000;
        }
        .kolom-kiri { border-right: 1px solid #000; padding: 4px; min-width: 0; }
        .kolom-kanan { min-width: 0; }

        .panel { border: 3px double #000; background: #fff; }
        .chart-panel { padding: 6px 8px; }
        .chart-panel .chart-title { text-align: center; font-size: 15px; font-weight: 700; margin: 2px 0 4px; }
        .chart-panel .chart-area { height: 290px; background: #ccf5f8; }

        .pie-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 4px;
            margin-top: 10px;
        }
        .pie-row .panel { padding: 6px 4px; text-align: center; min-width: 0; }
        .pie-title { font-size: 12px; font-weight: 700; line-height: 1.3; min-height: 48px; }
        .pie-area { height: 230px; }
        .pie-unsupported { font-size: 11px; color: #64748b; padding-top: 70px; }
        .pie-caveat { font-size: 9px; color: #64748b; margin-top: 2px; }

        .comment { padding: 10px 6px 4px; font-size: 12px; line-height: 1.45; }
        .comment .comment-label { margin-bottom: 16px; }
        .comment ol { list-style: none; margin: 0; padding: 0; }

        /* ---------- Kolom kanan ---------- */
        table.analisa, table.tindakan { width: 100%; border-collapse: collapse; font-size: 11px; }
        table.analisa th, table.tindakan th {
            font-weight: 700; padding: 12px 4px; border-bottom: 1px solid #000; text-align: center;
        }
        table.analisa th:last-child, table.analisa td:last-child { width: 110px; border-left: 1px solid #000; text-align: center; }
        table.analisa td { padding: 2px 4px; border-bottom: 1px dotted #cbd5e1; vertical-align: top; }
        table.analisa td.grup { font-weight: 700; padding-top: 4px; }
        table.analisa td .no { display: inline-block; width: 18px; }

        table.tindakan { border-top: 1px solid #000; }
        table.tindakan th { text-decoration: underline; font-weight: 400; padding: 4px; }
        table.tindakan th:first-child { text-align: left; }
        table.tindakan th:not(:first-child), table.tindakan td:not(:first-child) {
            width: 64px; text-align: center; vertical-align: middle; white-space: nowrap;
        }
        table.tindakan td { padding: 2px 4px; vertical-align: top; }
        table.tindakan tr.item-judul td { font-weight: 700; padding-top: 12px; }
        table.tindakan .akar { margin: 0; }
        table.tindakan .bukti { display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0; }
        table.tindakan .bukti img { max-width: 150px; max-height: 110px; border: 1px solid #e2e8f0; }
        table.tindakan .hasil { margin-top: 2px; }
        .kosong { color: #64748b; padding: 8px 4px; }

        @media (max-width: 900px) {
            .kop { grid-template-columns: minmax(0, 1fr); }
            .isi { grid-template-columns: minmax(0, 1fr); }
            .laporan-wrapper { padding: 10px; margin: 10px 0; }
            .kolom-kiri { border-right: none; border-bottom: 1px solid #000; }
            .pie-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .laporan-wrapper { box-shadow: none; margin: 0; max-width: 100%; padding: 0; }
            .chart-panel .chart-area { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.tindakan tr { break-inside: avoid; }
            @page { size: A3 portrait; margin: 8mm; }
        }
    </style>
</head>

<body>

    <div class="toolbar no-print">
        <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label style="font-size:13px; font-weight:600;">Kategori:</label>
            <select name="kategori">
                <?php foreach ($kategoriList as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $val === $kategori ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>

            <label style="font-size:13px; font-weight:600;">Bulan:</label>
            <input type="month" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>">

            <button type="submit" class="btn-primary-custom">Tampilkan</button>
        </form>
        <button type="button" class="btn-print-custom" onclick="window.print()"><i class="bi bi-printer"></i> Cetak / PDF</button>
        <a href="javascript:history.back()" style="font-size:13px; color:#64748b; margin-left:auto;">&larr; Kembali ke Dashboard</a>
    </div>

    <div class="laporan-wrapper">

        <div class="form-code"><span>FOR/QMR/03/00/01</span></div>

        <div class="kop">
            <table class="kop-tabel">
                <tr>
                    <td class="logo" rowspan="3">
                        <img src="<?= htmlspecialchars(brandLogo('app', '../../')) ?>" alt="Logo">
                        <div>Die Casting</div>
                    </td>
                    <td class="judul" colspan="2">Laporan Pencapaian Sasaran Mutu Manajemen</td>
                </tr>
                <tr>
                    <td class="subjudul" colspan="2"><?= strtoupper(htmlspecialchars($judulKategori)) ?></td>
                </tr>
                <tr>
                    <td class="bulan-label">Bulan</td>
                    <td class="bulan-val"><?= htmlspecialchars($periodeKop) ?></td>
                </tr>
            </table>
            <table class="ttd">
                <tr><td></td><td></td><td></td></tr>
                <tr><td>Dibuat</td><td>Diperiksa</td><td>Disetujui</td></tr>
            </table>
        </div>

        <div class="psmm">Status PSMM :</div>

        <div class="isi">

            <!-- ================= KOLOM KIRI ================= -->
            <div class="kolom-kiri">

                <div class="panel chart-panel">
                    <div class="chart-title"><?= strtoupper(htmlspecialchars($judulKategori)) ?> PERFORMANCE - <?= htmlspecialchars($periodeSingkat) ?></div>
                    <div class="chart-area"><canvas id="trenTahunanChart"></canvas></div>
                </div>

                <div class="pie-row">
                    <div class="panel">
                        <div class="pie-title">STATUS <?= strtoupper(htmlspecialchars($satuanUnit)) ?><br><?= htmlspecialchars($periodeSingkat) ?></div>
                        <div class="pie-area"><canvas id="statusPieChart"></canvas></div>
                    </div>
                    <div class="panel">
                        <div class="pie-title">STATUS COUNTERMEASURE<br><?= strtoupper(htmlspecialchars($satuanUnit)) ?> <?= htmlspecialchars($periodeSingkat) ?></div>
                        <div class="pie-area"><canvas id="cmStatusChart"></canvas></div>
                    </div>
                    <div class="panel">
                        <div class="pie-title">STATUS <?= strtoupper(htmlspecialchars($satuanUnit)) ?><br>PROBLEM BERULANG<br><?= htmlspecialchars($periodeSingkat) ?></div>
                        <?php if ($problemBerulangSupported): ?>
                            <div class="pie-area"><canvas id="problemBerulangChart"></canvas></div>
                        <?php else: ?>
                            <div class="pie-unsupported">Data problem berulang<br>belum tersedia untuk <?= htmlspecialchars($judulKategori) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="panel">
                        <div class="pie-title">KLASIFIKASI PROBLEM<br><?= htmlspecialchars($periodeSingkat) ?></div>
                        <?php if (empty($klasifikasiLabel)): ?>
                            <div class="pie-unsupported">Tidak ada data</div>
                        <?php else: ?>
                            <div class="pie-area"><canvas id="klasifikasiChart"></canvas></div>
                            <?php if (!empty($klasifikasiCaveat)): ?>
                                <div class="pie-caveat"><?= htmlspecialchars($klasifikasiCaveat) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="comment">
                    <div class="comment-label">Comment:</div>
                    <strong>
                        Total <?= htmlspecialchars($judulKategori) ?> pada bulan <?= strtoupper(htmlspecialchars($namaPeriode)) ?>
                        Sebanyak <?= $totalUnitBulanIni ?> <?= htmlspecialchars($satuanUnit) ?>
                        &amp; <?= $totalDibahasBulanIni ?> <?= htmlspecialchars($satuanUnit) ?> sudah di bahas
                    </strong>
                    <?php if (!$problemBerulangSupported): ?>
                        <div>Data problem berulang belum tersedia untuk kategori ini.</div>
                    <?php elseif (!empty($itemBerulang)): ?>
                        <div>Terdapat <?= count($itemBerulang) ?> <?= htmlspecialchars($satuanUnit) ?> status problem berulang:</div>
                        <ol>
                            <?php foreach ($itemBerulang as $item): ?>
                                <li><?= $item['nomor'] ?>. <?= htmlspecialchars($item['label']) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <div>Tidak ada status problem berulang pada periode ini.</div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ================= KOLOM KANAN ================= -->
            <div class="kolom-kanan">

                <table class="analisa">
                    <thead>
                        <tr>
                            <th>Analisa Masalah</th>
                            <th>Problem Berulang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grupItem as $namaGrup => $items): ?>
                            <tr><td class="grup"><?= htmlspecialchars($satuanUnit) ?> <?= htmlspecialchars($namaGrup) ?></td><td></td></tr>
                            <?php if (empty($items)): ?>
                                <tr><td>-</td><td></td></tr>
                            <?php endif; ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><span class="no"><?= $item['nomor'] ?></span><?= htmlspecialchars($item['label']) ?></td>
                                    <td><?= $item['berulang'] ? '&radic;' : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <table class="tindakan">
                    <thead>
                        <tr>
                            <th>Tindakan perbaikan</th>
                            <th>Due Date</th>
                            <th>Actual</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$tindakanSupported): ?>
                            <tr><td colspan="4" class="kosong">Data problem berulang belum tersedia untuk <?= htmlspecialchars($judulKategori) ?>.</td></tr>
                        <?php elseif (empty($tindakanBerulang)): ?>
                            <tr><td colspan="4" class="kosong">Tidak ada problem berulang pada periode ini.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($tindakanBerulang as $tb): ?>
                            <tr class="item-judul">
                                <td colspan="4"><?= $tb['nomor'] ?>&nbsp;&nbsp;<?= htmlspecialchars($tb['label']) ?></td>
                            </tr>
                            <?php if (!empty($tb['akar'])): ?>
                                <tr>
                                    <td colspan="4">
                                        <?php foreach ($tb['akar'] as $akar): ?>
                                            <p class="akar"># <?= nl2br(htmlspecialchars($akar)) ?></p>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if (empty($tb['aksi'])): ?>
                                <tr><td colspan="4" class="kosong">Belum ada tindakan perbaikan.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($tb['aksi'] as $aksi): ?>
                                <tr>
                                    <td>
                                        &rarr; <?= nl2br(htmlspecialchars($aksi['tindakan'])) ?>
                                        <?php
                                        $gambar = array_filter($aksi['files'], function ($f) {
                                            return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'])
                                                && file_exists(__DIR__ . '/uploads/fpm_dt/' . $f);
                                        });
                                        ?>
                                        <?php if (!empty($gambar)): ?>
                                            <div class="bukti">
                                                <?php foreach ($gambar as $f): ?>
                                                    <a href="uploads/fpm_dt/<?= rawurlencode($f) ?>" target="_blank"><img src="uploads/fpm_dt/<?= rawurlencode($f) ?>" alt=""></a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim($aksi['note'] ?? '') !== ''): ?>
                                            <div class="hasil">=&gt; <?= nl2br(htmlspecialchars(trim($aksi['note']))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(tanggalSasaranMutu($aksi['due_date'])) ?></td>
                                    <td><?= htmlspecialchars(tanggalSasaranMutu($aksi['actual_date'])) ?></td>
                                    <td><?= strtoupper($aksi['status'] ?? '') === 'CLOSED' ? 'CLOSED' : 'OPEN' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Halaman ini berdiri sendiri (tidak memuat dashboard-widgets.js seperti
            // dashboard lain), jadi plugin angka pada chart (chartjs-plugin-datalabels)
            // harus didaftarkan manual di sini - kalau tidak, opsi "datalabels" di tiap
            // chart di bawah tidak akan pernah muncul sama sekali.
            if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
                Chart.register(ChartDataLabels);
            }

            // Warna mengikuti palet default Excel supaya mirip laporan aslinya.
            const excel = { biru: '#5B9BD5', oranye: '#ED7D31', abu: '#A5A5A5', kuning: '#FFC000', hijau: '#70AD47', biruTua: '#4472C4' };

            new Chart(document.getElementById('trenTahunanChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labelBulanTahunIni) ?>,
                    datasets: [
                        { label: <?= json_encode(strtoupper($satuanUnit) . ' YANG DIBAHAS') ?>, data: <?= json_encode($dibahasPerBulan) ?>, backgroundColor: '#FFD966' },
                        { label: <?= json_encode('TOTAL ' . strtoupper($satuanUnit)) ?>, data: <?= json_encode($totalPerBulan) ?>, backgroundColor: '#FF00FF' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, font: { weight: 'bold' } },
                            title: { display: true, text: <?= json_encode('(FREKUENSI ' . strtoupper($satuanUnit) . ')') ?>, font: { weight: 'bold' } }
                        },
                        x: { grid: { display: false }, ticks: { font: { weight: 'bold', size: 12 } } }
                    },
                    plugins: {
                        legend: { position: 'bottom', align: 'end', labels: { boxWidth: 10, font: { size: 10 } } },
                        datalabels: { display: (ctx) => ctx.dataset.data[ctx.dataIndex] > 0, anchor: 'end', align: 'top', offset: 0, color: '#000', font: { size: 10 } }
                    }
                }
            });

            const pieOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 9 } } },
                    datalabels: { display: true, color: '#000', anchor: 'end', align: 'end', offset: 2, font: { size: 10 }, formatter: (v) => v > 0 ? v : '' }
                },
                layout: { padding: 14 }
            };

            new Chart(document.getElementById('statusPieChart'), {
                type: 'pie',
                data: {
                    labels: <?= json_encode(array_map('strtoupper', $statusPieLabels)) ?>,
                    datasets: [{ data: <?= json_encode($statusPieValues) ?>, backgroundColor: [excel.biru, excel.abu, excel.hijau, excel.kuning] }]
                },
                options: pieOptions
            });

            new Chart(document.getElementById('cmStatusChart'), {
                type: 'pie',
                data: {
                    labels: ['CM OPEN', 'CM CLOSED'],
                    datasets: [{ data: [<?= (int) $cmOpen ?>, <?= (int) $cmClosed ?>], backgroundColor: [excel.biru, excel.oranye] }]
                },
                options: pieOptions
            });

            <?php if ($problemBerulangSupported): ?>
            new Chart(document.getElementById('problemBerulangChart'), {
                type: 'pie',
                data: {
                    labels: ['NEW PROBLEM', 'PROBLEM BERULANG'],
                    datasets: [{ data: [<?= (int) $totalBaru ?>, <?= (int) $totalBerulang ?>], backgroundColor: [excel.biru, excel.oranye] }]
                },
                options: pieOptions
            });
            <?php endif; ?>

            <?php if (!empty($klasifikasiLabel)): ?>
            new Chart(document.getElementById('klasifikasiChart'), {
                type: 'pie',
                data: {
                    labels: <?= json_encode($klasifikasiLabel) ?>,
                    datasets: [{ data: <?= json_encode($klasifikasiTotal) ?>, backgroundColor: [excel.kuning, excel.hijau, excel.biru, excel.oranye, excel.abu, excel.biruTua, '#9E480E', '#636363', '#997300', '#264478'] }]
                },
                options: pieOptions
            });
            <?php endif; ?>
        });
    </script>

</body>

</html>
