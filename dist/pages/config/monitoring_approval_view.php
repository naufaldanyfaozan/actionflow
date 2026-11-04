<?php
/**
 * Konten halaman "Monitoring Approval" - di-include dari dalam <main class="app-main">
 * oleh monitoring_approval.php setiap role. Semua role melihat data yang sama supaya
 * saling tahu FPM sedang menunggu siapa; baris yang menunggu user login ditandai.
 *
 * Istilah di halaman ini sengaja dibuat untuk orang awam: SP = Surat Peringatan yang
 * diterima pihak yang ditunggu kalau tahapnya melewati batas waktu (hari kerja).
 *
 * @var mysqli $koneksi
 * @var string $fpmDetailPage Halaman detail FPM role ini (mis. 'form_fpm.php').
 */

require_once __DIR__ . '/monitoring_approval_helper.php';

$fpmDetailPage = $fpmDetailPage ?? 'form_fpm.php';

$semuaData = ambilMonitoringApproval($koneksi);
catatPelanggaranSpFpm($koneksi, $semuaData);
$dataSp = ambilRiwayatSpFpm($koneksi);

$filterPlant    = $_GET['plant'] ?? '';
$filterJenis    = $_GET['jenis'] ?? '';
$filterDept     = $_GET['dept'] ?? '';
$filterStatus   = $_GET['status'] ?? '';
$filterMenunggu = $_GET['menunggu'] ?? '';
$filterCari     = trim($_GET['cari'] ?? '');
$filterSaya     = !empty($_GET['saya']);

// Pihak yang dianggap "saya" untuk penanda baris, dari level & dept/plant user login.
// Staff / Staff IMP bukan penerima SP - mereka membantu atasannya (Dept Head / Head IMP).
$levelSaya = $_SESSION['level'] ?? '';
$deptSaya  = trim($_SESSION['dept'] ?? '');
$plantSaya = monitoringLabelPlant($_SESSION['plant'] ?? '');
$pihakSaya = [];
$sayaPembantu = in_array($levelSaya, ['Staff', 'Staff IMP'], true);
if (in_array($levelSaya, ['Dept Head', 'Staff'], true) && $deptSaya !== '') {
    $pihakSaya[] = 'Dept Head ' . $deptSaya;
} elseif ($levelSaya === 'Plant Head') {
    $pihakSaya[] = 'Plant Head';
} elseif ($levelSaya === 'Directure') {
    $pihakSaya[] = 'Directure';
} elseif ($levelSaya === 'Dept Head QA') {
    $pihakSaya[] = 'Dept Head QA';
} elseif (in_array($levelSaya, ['Head IMP', 'Staff IMP'], true)) {
    $pihakSaya[] = trim('Head IMP ' . $plantSaya);
    if ($plantSaya === '') {
        $pihakSaya[] = 'Head IMP Plant 1';
        $pihakSaya[] = 'Head IMP Plant 3';
    }
}
// SP Staff IMP sendiri (lupa membuat FPM dari pembahasan Asakai, lihat asakai_fpm_helper.php).
$pihakStaffImpSaya = $levelSaya === 'Staff IMP' ? trim('Staff IMP ' . $plantSaya) : null;
if ($pihakStaffImpSaya !== null) {
    $pihakSaya[] = $pihakStaffImpSaya;
}
$labelSaya = $sayaPembantu ? 'Dept Anda' : 'Anda';

$daftarMenunggu = array_values(array_unique(array_column($semuaData, 'menunggu')));
sort($daftarMenunggu);

// Dept yang punya FPM di alur approval saja, supaya pilihan filter tidak berisi dept kosong.
$daftarDept = array_values(array_unique(array_filter(array_column($semuaData, 'dept'))));
sort($daftarDept);

$data = array_values(array_filter($semuaData, function ($row) use ($filterPlant, $filterJenis, $filterDept, $filterStatus, $filterMenunggu, $filterCari, $filterSaya, $pihakSaya) {
    if ($filterPlant !== '' && $row['plant'] !== $filterPlant) return false;
    if ($filterJenis !== '' && $row['jenis'] !== $filterJenis) return false;
    if ($filterDept !== '' && $row['dept'] !== $filterDept) return false;
    if ($filterStatus !== '' && $row['status'] !== $filterStatus) return false;
    if ($filterMenunggu !== '' && $row['menunggu'] !== $filterMenunggu) return false;
    if ($filterCari !== '' && stripos($row['no_form'] . ' ' . $row['dept'], $filterCari) === false) return false;
    if ($filterSaya && !in_array($row['menunggu'], $pihakSaya, true)) return false;
    return true;
}));

$jumlah = ['sp' => 0, 'warning' => 0, 'normal' => 0, 'info' => 0];
$rekapPihak = [];
foreach ($data as $row) {
    $jumlah[$row['status']]++;
    $p = $row['menunggu'];
    $rekapPihak[$p] = $rekapPihak[$p] ?? ['total' => 0, 'warning' => 0, 'sp' => 0];
    $rekapPihak[$p]['total']++;
    if (isset($rekapPihak[$p][$row['status']])) {
        $rekapPihak[$p][$row['status']]++;
    }
}
uasort($rekapPihak, fn($a, $b) => [$b['sp'], $b['warning'], $b['total']] <=> [$a['sp'], $a['warning'], $a['total']]);

$jumlahSaya = count(array_filter($semuaData, fn($row) => in_array($row['menunggu'], $pihakSaya, true) && $row['status'] === 'sp'));

$statusInfo = [
    'sp'      => ['label' => 'Kena SP',                 'class' => 'mon-badge-sp',      'filter' => 'Kena SP (lewat batas waktu)'],
    'warning' => ['label' => 'Mendekati SP',            'class' => 'mon-badge-warning', 'filter' => 'Mendekati SP'],
    'normal'  => ['label' => 'Masih dalam batas waktu', 'class' => 'mon-badge-normal',  'filter' => 'Masih dalam batas waktu'],
    'info'    => ['label' => 'Tanpa batas waktu',       'class' => 'mon-badge-info',    'filter' => 'Tanpa batas waktu'],
];

// SP aktif / riwayat SP ikut filter Menunggu, Dept & "hanya saya".
$cocokPihakSp = function (string $pihak) use ($filterMenunggu, $filterDept, $filterSaya, $pihakSaya) {
    if ($filterMenunggu !== '' && $pihak !== $filterMenunggu) return false;
    if ($filterDept !== '' && stripos($pihak, $filterDept) === false) return false;
    if ($filterSaya && !in_array($pihak, $pihakSaya, true)) return false;
    return true;
};
$spAktif   = array_filter($dataSp['aktif'], $cocokPihakSp, ARRAY_FILTER_USE_KEY);
$spRiwayat = array_values(array_filter($dataSp['riwayat'], fn($r) => $cocokPihakSp($r['pihak'])));

$spSaya = null;
foreach ($pihakSaya as $pihak) {
    if (isset($dataSp['aktif'][$pihak]) && (!$spSaya || $dataSp['aktif'][$pihak]['level'] > $spSaya['level'])) {
        $spSaya = $dataSp['aktif'][$pihak];
    }
}

if (!function_exists('badgeLevelSp')) {
    function badgeLevelSp(int $level): string
    {
        return '<span class="mon-sp-level mon-sp-level-' . $level . '">SP ' . $level . '</span>';
    }
}

$urlFilter = function (array $ubah) {
    $q = array_merge($_GET, $ubah);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};
?>
<style>
    .mon-badge-sp,
    .mon-badge-warning,
    .mon-badge-normal,
    .mon-badge-info {
        font-weight: 700;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-block;
        white-space: nowrap;
    }

    .mon-badge-sp {
        background: #fee2e2;
        color: #b91c1c;
    }

    .mon-badge-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .mon-badge-normal {
        background: #d1fae5;
        color: #047857;
    }

    .mon-badge-info {
        background: #e2e8f0;
        color: #475569;
    }

    .mon-table {
        font-size: 13px;
    }

    .mon-table th {
        background: #f8fafc;
        font-size: 12px;
        color: #475569;
        vertical-align: bottom;
    }

    .mon-table td {
        vertical-align: top;
    }

    .mon-table tr.mon-row-sp td:first-child {
        box-shadow: inset 3px 0 0 #ef4444;
    }

    .mon-table tr.mon-row-warning td:first-child {
        box-shadow: inset 3px 0 0 #f59e0b;
    }

    .mon-table tr.mon-row-saya {
        background: #eff6ff;
    }

    .mon-hari {
        font-weight: 800;
        font-size: 15px;
        white-space: nowrap;
    }

    .mon-sub {
        font-size: 11.5px;
        color: #64748b;
    }

    .mon-menunggu {
        font-weight: 700;
        color: #0f172a;
    }

    .mon-rule-table td,
    .mon-rule-table th {
        font-size: 12.5px;
        padding: 6px 8px;
    }

    .mon-pihak {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .mon-pihak a {
        text-decoration: none;
        color: inherit;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 14px;
        min-width: 190px;
        box-shadow: 0 1px 4px rgba(15, 23, 42, 0.05);
    }

    .mon-pihak a.aktif {
        border-color: #3b82f6;
        background: #eff6ff;
    }

    .mon-pihak .nama {
        font-weight: 700;
        font-size: 13px;
    }

    .mon-pihak .angka {
        font-size: 12px;
        color: #475569;
        margin-top: 4px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .mon-cara-baca {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 22px;
        font-size: 13px;
        color: #334155;
    }

    .mon-cara-baca>div {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .mon-sp-level {
        font-weight: 800;
        font-size: 11.5px;
        padding: 3px 9px;
        border-radius: 6px;
        display: inline-block;
        white-space: nowrap;
    }

    .mon-sp-level-1 {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fcd34d;
    }

    .mon-sp-level-2 {
        background: #ffedd5;
        color: #9a3412;
        border: 1px solid #fb923c;
    }

    .mon-sp-level-3 {
        background: #dc2626;
        color: #fff;
        border: 1px solid #b91c1c;
    }

    .mon-aturan-sp {
        font-size: 13px;
        color: #334155;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed #e2e8f0;
    }
</style>

<?php
// Satu menu dengan Tugas Saya di sidebar - pindah lewat tab.
require_once __DIR__ . '/tugas_saya_helper.php';
renderTabTugasApproval($koneksi, 'monitor');
?>

<div class="container-fluid dashboard-wrapper">

    <div class="dashboard-title">
        Monitoring Approval FPM
        <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
            Lihat setiap FPM sedang menunggu siapa. Setiap tahap punya batas waktu (hari kerja).
            Jika batas waktu terlewati, pihak yang ditunggu mendapat <strong>SP (Surat Peringatan)</strong>.
        </div>
    </div>

    <div class="card section">
        <div class="card-body py-3">
            <div class="fw-bold mb-2"><i class="bi bi-info-circle"></i> Cara membaca status</div>
            <div class="mon-cara-baca">
                <div><span class="mon-badge-normal">Masih dalam batas waktu</span> belum terlambat</div>
                <div><span class="mon-badge-warning">Mendekati SP</span> batas waktu hampir habis, segera selesaikan</div>
                <div><span class="mon-badge-sp">Kena SP</span> batas waktu terlewati, pihak yang ditunggu mendapat Surat Peringatan</div>
                <div><span class="mon-badge-info">Tanpa batas waktu</span> tahap ini belum punya aturan SP</div>
            </div>
            <div class="mon-aturan-sp">
                <strong>Aturan SP (mengikuti HR):</strong>
                SP berlaku <strong><?= MONITORING_SP_MASA_BERLAKU_BULAN ?> bulan</strong>.
                Jika terlambat lagi selama SP masih berlaku, naik level:
                <?= badgeLevelSp(1) ?> &rarr; <?= badgeLevelSp(2) ?> &rarr; <?= badgeLevelSp(3) ?> (tertinggi).
                Masa <?= MONITORING_SP_MASA_BERLAKU_BULAN ?> bulan dihitung ulang dari SP terakhir. Beberapa FPM yang terlambat di hari yang sama dihitung <strong>1 SP</strong>.
            </div>
        </div>
    </div>

    <?php if ($spSaya): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-envelope-exclamation-fill"></i>
            <div>
                <?php if ($sayaPembantu): ?>
                    <strong><?= htmlspecialchars($spSaya['pihak']) ?></strong> sedang menjalani
                <?php else: ?>
                    Anda sedang menjalani
                <?php endif; ?>
                <?= badgeLevelSp($spSaya['level']) ?> sejak <?= monitoringFormatTgl($spSaya['tgl']) ?>,
                berlaku sampai <strong><?= monitoringFormatTgl($spSaya['berlaku_sampai']) ?></strong>.
                <?php if ($spSaya['level'] < MONITORING_SP_LEVEL_MAKS): ?>
                    Jika ada FPM terlambat lagi sebelum tanggal itu, naik menjadi <?= badgeLevelSp($spSaya['level'] + 1) ?>.
                <?php else: ?>
                    Ini level SP tertinggi.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($pihakSaya && $jumlahSaya > 0): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>
                <?php if ($sayaPembantu): ?>
                    <strong><?= $jumlahSaya ?> FPM</strong> milik <strong><?= htmlspecialchars(implode(' / ', $pihakSaya)) ?></strong> masih belum selesai
                    padahal sudah melewati batas waktu (tercatat sebagai pelanggaran SP). Bantu segera selesaikan.
                <?php else: ?>
                    <strong><?= $jumlahSaya ?> FPM</strong> yang menunggu Anda masih belum selesai padahal sudah melewati batas waktu
                    (tercatat sebagai pelanggaran SP). Segera selesaikan.
                <?php endif; ?>
                <a href="<?= htmlspecialchars($urlFilter(['saya' => 1, 'status' => 'sp'])) ?>" class="alert-link">Lihat FPM-nya</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="filter-box">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto fw-semibold">Plant:</div>
            <div class="col-auto">
                <select name="plant" class="form-control form-control-sm">
                    <option value="">-- All Plant --</option>
                    <option value="1" <?= $filterPlant === '1' ? 'selected' : '' ?>>Plant 1</option>
                    <option value="3" <?= $filterPlant === '3' ? 'selected' : '' ?>>Plant 3</option>
                </select>
            </div>

            <div class="col-auto fw-semibold">Jenis:</div>
            <div class="col-auto">
                <select name="jenis" class="form-control form-control-sm">
                    <option value="">-- Semua Jenis --</option>
                    <?php foreach (['Down Time', 'Internal', 'Claim Machining'] as $j): ?>
                        <option value="<?= $j ?>" <?= $filterJenis === $j ? 'selected' : '' ?>><?= $j ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto fw-semibold">Dept:</div>
            <div class="col-auto">
                <select name="dept" class="form-control form-control-sm">
                    <option value="">-- Semua Dept --</option>
                    <?php foreach ($daftarDept as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= $filterDept === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto fw-semibold">Status:</div>
            <div class="col-auto">
                <select name="status" class="form-control form-control-sm">
                    <option value="">-- Semua Status --</option>
                    <?php foreach ($statusInfo as $key => $info): ?>
                        <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $info['filter'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto fw-semibold">Menunggu:</div>
            <div class="col-auto">
                <select name="menunggu" class="form-control form-control-sm">
                    <option value="">-- Semua Pihak --</option>
                    <?php foreach ($daftarMenunggu as $pihak): ?>
                        <option value="<?= htmlspecialchars($pihak) ?>" <?= $filterMenunggu === $pihak ? 'selected' : '' ?>><?= htmlspecialchars($pihak) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <input type="text" name="cari" class="form-control form-control-sm" placeholder="Cari No FPM / Dept" value="<?= htmlspecialchars($filterCari) ?>">
            </div>

            <?php if ($pihakSaya): ?>
                <div class="col-auto form-check ms-2">
                    <input class="form-check-input" type="checkbox" name="saya" value="1" id="monSaya" <?= $filterSaya ? 'checked' : '' ?>>
                    <label class="form-check-label" for="monSaya">Hanya yang menunggu <?= strtolower($labelSaya) ?></label>
                </div>
            <?php endif; ?>

            <div class="col-auto">
                <?php if (($_GET['modul'] ?? '') === 'qa'): ?><input type="hidden" name="modul" value="qa"><?php endif; ?>
                <button class="btn btn-primary btn-sm">Filter</button>
                <a href="?<?= ($_GET['modul'] ?? '') === 'qa' ? 'modul=qa' : '' ?>" class="btn btn-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon"><i class="bi bi-diagram-3"></i></div>
                <div>
                    <div class="kpi-title">FPM Menunggu Approval</div>
                    <div class="kpi-value"><?= count($data) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="kpi-title">Masih Dalam Batas Waktu</div>
                    <div class="kpi-value"><?= $jumlah['normal'] + $jumlah['info'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="kpi-title">Mendekati SP</div>
                    <div class="kpi-value"><?= $jumlah['warning'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon"><i class="bi bi-envelope-exclamation"></i></div>
                <div>
                    <div class="kpi-title">Lewat Batas Waktu (Kena SP)</div>
                    <div class="kpi-value"><?= $jumlah['sp'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 section">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-bold mb-1"><i class="bi bi-people-fill"></i> FPM Sedang Menunggu Siapa</div>
                    <div class="mon-sub mb-2">Klik kotak untuk melihat FPM milik pihak tersebut.</div>
                    <?php if (!$rekapPihak): ?>
                        <div class="text-muted small">Tidak ada FPM yang sedang menunggu approval.</div>
                    <?php else: ?>
                        <div class="mon-pihak">
                            <?php foreach ($rekapPihak as $pihak => $r): ?>
                                <a href="<?= htmlspecialchars($urlFilter(['menunggu' => $filterMenunggu === $pihak ? '' : $pihak])) ?>"
                                    class="<?= $filterMenunggu === $pihak ? 'aktif' : '' ?>">
                                    <div class="nama"><?= htmlspecialchars($pihak) ?><?= in_array($pihak, $pihakSaya, true) ? ' <span class="badge bg-primary">' . $labelSaya . '</span>' : '' ?></div>
                                    <div class="angka">
                                        <span>Menunggu <?= $r['total'] ?> FPM</span>
                                        <?php if ($r['warning'] > 0): ?><span class="mon-badge-warning"><?= $r['warning'] ?> mendekati SP</span><?php endif; ?>
                                        <?php if ($r['sp'] > 0): ?><span class="mon-badge-sp"><?= $r['sp'] ?> lewat batas</span><?php endif; ?>
                                    </div>
                                    <?php if (isset($dataSp['aktif'][$pihak])): ?>
                                        <div class="angka">
                                            <?= badgeLevelSp($dataSp['aktif'][$pihak]['level']) ?>
                                            <span>s/d <?= monitoringFormatTgl($dataSp['aktif'][$pihak]['berlaku_sampai']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-bold mb-2"><i class="bi bi-calendar-check"></i> Batas Waktu Setiap Tahap</div>
                    <table class="table table-sm mon-rule-table mb-1">
                        <thead>
                            <tr>
                                <th>Tahap</th>
                                <th>Penanggung Jawab</th>
                                <th>Mendekati SP</th>
                                <th>Kena SP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (monitoringAturanApproval() as $aturan): ?>
                                <tr>
                                    <td><?= htmlspecialchars($aturan['label']) ?></td>
                                    <td><?= htmlspecialchars($aturan['pihak']) ?></td>
                                    <td style="white-space:nowrap">Hari ke-<?= $aturan['warning'] ?></td>
                                    <td style="white-space:nowrap"><strong>Hari ke-<?= $aturan['sp'] ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="mon-sub">
                        Hari dihitung dalam hari kerja (Sabtu &amp; Minggu tidak dihitung), mulai dari tahap sebelumnya selesai.
                        Khusus Claim Machining, dihitung setelah due date jawab (<?= appSettingInt('cm_batas_jawab_baru') ?> hari kerja untuk masalah baru, <?= appSettingInt('cm_batas_jawab_berulang') ?> hari kerja untuk masalah berulang).
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card section">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="fw-bold"><i class="bi bi-envelope-exclamation"></i> SP yang Sedang Berlaku</div>
                <?php if ($spRiwayat): ?>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#monRiwayatSp">
                        <i class="bi bi-clock-history"></i> Riwayat SP (<?= count($spRiwayat) ?>)
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!$spAktif): ?>
                <div class="text-muted small">Tidak ada yang sedang menjalani SP.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mon-table mb-0">
                        <thead>
                            <tr>
                                <th>Pihak</th>
                                <th>Level SP</th>
                                <th>SP Diberikan</th>
                                <th>Berlaku Sampai</th>
                                <th>Penyebab (FPM terlambat)</th>
                                <th>Jika terlambat lagi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($spAktif as $pihak => $s): ?>
                                <tr class="<?= in_array($pihak, $pihakSaya, true) ? 'mon-row-saya' : '' ?>">
                                    <td class="mon-menunggu">
                                        <?= htmlspecialchars($pihak) ?>
                                        <?php if (in_array($pihak, $pihakSaya, true)): ?> <span class="badge bg-primary"><?= $pihak === $pihakStaffImpSaya ? 'Anda' : $labelSaya ?></span><?php endif; ?>
                                    </td>
                                    <td><?= badgeLevelSp($s['level']) ?></td>
                                    <td style="white-space:nowrap"><?= monitoringFormatTgl($s['tgl']) ?></td>
                                    <td style="white-space:nowrap">
                                        <?= monitoringFormatTgl($s['berlaku_sampai']) ?>
                                        <div class="mon-sub">sisa <?= (int) floor((strtotime($s['berlaku_sampai']) - strtotime(date('Y-m-d'))) / 86400) + 1 ?> hari</div>
                                    </td>
                                    <td class="mon-sub" style="max-width:520px">
                                        <?= count($s['fpm']) ?> FPM:
                                        <?= implode(', ', array_map(fn($f) => !empty($f['asakai'])
                                            ? '<span title="' . htmlspecialchars($f['tahap']) . '">' . htmlspecialchars($f['no_form']) . '</span>'
                                            : '<a href="' . htmlspecialchars($fpmDetailPage) . '?id=' . $f['id_fpm'] . '" title="'
                                            . htmlspecialchars($f['tahap']) . '">' . htmlspecialchars($f['no_form']) . '</a>', $s['fpm'])) ?>
                                    </td>
                                    <td>
                                        <?= $s['level'] < MONITORING_SP_LEVEL_MAKS
                                            ? 'Naik ke ' . badgeLevelSp($s['level'] + 1)
                                            : badgeLevelSp(MONITORING_SP_LEVEL_MAKS) . ' <span class="mon-sub">diperpanjang ' . MONITORING_SP_MASA_BERLAKU_BULAN . ' bulan</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($spRiwayat): ?>
                <div class="collapse mt-3" id="monRiwayatSp">
                    <div class="fw-semibold mb-1">Riwayat SP</div>
                    <div class="table-responsive">
                        <table class="table table-sm mon-table mb-0">
                            <thead>
                                <tr>
                                    <th>Tanggal SP</th>
                                    <th>Pihak</th>
                                    <th>Level</th>
                                    <th>Berlaku Sampai</th>
                                    <th>Penyebab (FPM terlambat)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($spRiwayat as $s): ?>
                                    <tr>
                                        <td style="white-space:nowrap"><?= monitoringFormatTgl($s['tgl']) ?></td>
                                        <td><?= htmlspecialchars($s['pihak']) ?></td>
                                        <td><?= badgeLevelSp($s['level']) ?></td>
                                        <td style="white-space:nowrap"><?= monitoringFormatTgl($s['berlaku_sampai']) ?></td>
                                        <td class="mon-sub">
                                            <?= implode(', ', array_map(fn($f) => htmlspecialchars($f['no_form']), $s['fpm'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($s['aktif']): ?>
                                                <span class="mon-badge-sp">Masih berlaku</span>
                                            <?php elseif ($s['berlaku_sampai'] >= date('Y-m-d')): ?>
                                                <span class="mon-badge-info">Diganti SP berikutnya</span>
                                            <?php else: ?>
                                                <span class="mon-badge-normal">Sudah berakhir</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card section">
        <div class="card-body p-0">
            <?php if (!$data): ?>
                <div class="text-center text-muted py-5">Tidak ada FPM sesuai filter yang dipilih.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mon-table mb-0">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No FPM</th>
                                <th>Jenis</th>
                                <th>Dept</th>
                                <th>Tahap Sekarang</th>
                                <th>Tahap Sebelumnya</th>
                                <th>Menunggu</th>
                                <th>Menunggu Sejak</th>
                                <th>Sudah Berjalan</th>
                                <th>Batas Waktu Habis</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $i => $row):
                                $info   = $statusInfo[$row['status']];
                                $isSaya = in_array($row['menunggu'], $pihakSaya, true);
                                $ket    = $row['keterangan'];
                                $ketPendek = mb_strlen($ket) > 110 ? mb_substr($ket, 0, 110) . '…' : $ket;
                            ?>
                                <tr class="mon-row-<?= $row['status'] ?><?= $isSaya ? ' mon-row-saya' : '' ?>">
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($fpmDetailPage) ?>?id=<?= $row['id_fpm'] ?>" class="fw-semibold" style="white-space:nowrap"><?= htmlspecialchars($row['no_form']) ?></a>
                                        <?php if ($row['plant'] !== ''): ?><div class="mon-sub">Plant <?= htmlspecialchars($row['plant']) ?></div><?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['jenis']) ?></td>
                                    <td><?= htmlspecialchars($row['dept'] ?: '-') ?></td>
                                    <td>
                                        <?= htmlspecialchars($row['tahap']) ?>
                                        <?php if ($ketPendek !== ''): ?><div class="mon-sub" title="<?= htmlspecialchars($ket) ?>"><?= htmlspecialchars($ketPendek) ?></div><?php endif; ?>
                                    </td>
                                    <td class="mon-sub"><?= htmlspecialchars($row['dari']) ?></td>
                                    <td>
                                        <span class="mon-menunggu"><?= htmlspecialchars($row['menunggu']) ?></span>
                                        <?php if ($isSaya): ?> <span class="badge bg-primary"><?= $labelSaya ?></span><?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap"><?= monitoringFormatTgl($row['mulai']) ?></td>
                                    <td>
                                        <span class="mon-hari"><?= $row['hari'] ?> hari</span>
                                        <div class="mon-sub">hari kerja</div>
                                    </td>
                                    <td style="white-space:nowrap">
                                        <?php if ($row['tgl_sp']): ?>
                                            <?= monitoringFormatTgl($row['tgl_sp']) ?>
                                            <div class="mon-sub">hari kerja ke-<?= $row['batas_sp'] ?></div>
                                        <?php else: ?>
                                            <span class="mon-sub">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?= $info['class'] ?>"><?= $info['label'] ?></span>
                                        <?php if ($row['status'] === 'sp'): ?>
                                            <div class="mon-sub mt-1">
                                                Pelanggaran SP untuk <strong><?= htmlspecialchars($row['menunggu']) ?></strong>
                                                <?php if ($row['lewat_sp'] > 0): ?><br>lewat <?= $row['lewat_sp'] ?> hari kerja dari batas<?php endif; ?>
                                            </div>
                                        <?php elseif ($row['status'] === 'warning'): ?>
                                            <div class="mon-sub mt-1">Selesaikan sebelum <?= monitoringFormatTgl($row['tgl_sp']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
