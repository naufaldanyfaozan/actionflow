<?php
/**
 * Konten menu "FPM dari Asakai" - di-include dari dalam <main class="app-main"> oleh
 * fpm_asakai.php (Staff IMP & Head IMP). Daftar problem Asakai yang ditandai perlu FPM:
 * belum dibuat (dengan H+ / batas / SP), sudah dibuat, dan dibatalkan.
 *
 * Staff IMP membuat FPM-nya; Head IMP hanya memantau (backup kalau Staff IMP berhalangan
 * - pembuatan FPM tetap dari akun Staff IMP).
 *
 * @var mysqli $koneksi
 * @var bool   $asakaiFpmBisaBuat  true = tampilkan tombol "Buat FPM" (Staff IMP).
 * @var string $halamanAsakai      Halaman detail Asakai role ini (?id=id_asakai).
 * @var array  $halamanFpm         jenis (DOWN_TIME / NG) => halaman detail FPM role ini (?id=id_fpm).
 * @var string $asakaiFpmAnchor    (opsional) anchor link tab, mis. '#asakai-fpm' di Tugas Saya Staff IMP.
 */

require_once __DIR__ . '/asakai_fpm_helper.php';

$plantSaya = asakaiFpmPlant($_SESSION['plant'] ?? '');
$semuaTugas = ambilTugasAsakaiFpmSemua($koneksi, $plantSaya);

$jumlah = ['belum' => 0, 'sp' => 0, 'selesai' => 0, 'batal' => 0];
foreach ($semuaTugas as $t) {
    if (in_array($t['status'], ['normal', 'warning', 'sp'], true)) {
        $jumlah['belum']++;
    }
    if (isset($jumlah[$t['status']])) {
        $jumlah[$t['status']]++;
    }
}

$filter = $_GET['status'] ?? 'belum';
$tugas = array_values(array_filter($semuaTugas, function ($t) use ($filter) {
    if ($filter === 'belum') return in_array($t['status'], ['normal', 'warning', 'sp'], true);
    if ($filter === 'selesai' || $filter === 'batal') return $t['status'] === $filter;
    return true;
}));
if ($filter === 'belum') {
    $urutan = ['sp' => 0, 'warning' => 1, 'normal' => 2];
    usort($tugas, fn($a, $b) => [$urutan[$a['status']], -$a['hari']] <=> [$urutan[$b['status']], -$b['hari']]);
}

$batasSp = appSettingInt('monitor_asakai_fpm_sp');
// Staff IMP: view ini tampil di dalam Tugas Saya - link tab kembali ke bagiannya (#asakai-fpm).
$asakaiFpmAnchor = $asakaiFpmAnchor ?? '';
$tab = function (string $kode, string $label, int $n) use ($filter, $asakaiFpmAnchor) {
    return '<a href="?status=' . $kode . $asakaiFpmAnchor . '" class="btn btn-sm ' . ($filter === $kode ? 'btn-primary' : 'btn-outline-secondary') . '">'
        . $label . ' <span class="badge ' . ($filter === $kode ? 'bg-light text-dark' : 'bg-secondary') . '">' . $n . '</span></a>';
};
?>
<div class="container-fluid dashboard-wrapper">
    <div class="dashboard-title">
        FPM dari Asakai
        <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
            Problem Asakai yang ditandai perlu dibuatkan FPM<?= $plantSaya !== '' ? ' - Plant ' . htmlspecialchars($plantSaya) : '' ?>.
            Batas membuat FPM <?= $batasSp - 1 ?> hari kerja setelah ditandai (Sabtu &amp; Minggu tidak dihitung); lewat dari itu tercatat SP untuk Staff IMP.
        </div>
    </div>

    <?php if (!$asakaiFpmBisaBuat): ?>
        <div class="alert alert-info py-2" style="font-size:14px">
            <i class="bi bi-info-circle"></i> Halaman pantauan untuk Head IMP. Pembuatan FPM tetap dari akun <b>Staff IMP</b> -
            kalau Staff IMP berhalangan, ingatkan atau koordinasikan supaya FPM dibuat sebelum batas akhir.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <?php foreach ([
            ['Belum dibuat', $jumlah['belum'], '#2563eb', 'bi-hourglass-split'],
            ['Sudah kena SP', $jumlah['sp'], '#dc2626', 'bi-exclamation-octagon'],
            ['Sudah dibuat', $jumlah['selesai'], '#16a34a', 'bi-check-circle'],
            ['Dibatalkan', $jumlah['batal'], '#6b7280', 'bi-x-circle'],
        ] as [$label, $n, $warna, $ikon]): ?>
            <div class="col-6 col-md-3">
                <div class="card section h-100" style="border-left:4px solid <?= $warna ?>">
                    <div class="card-body py-3">
                        <div class="text-muted small"><i class="bi <?= $ikon ?>"></i> <?= $label ?></div>
                        <div style="font-size:26px; font-weight:700; color:<?= $warna ?>"><?= $n ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card section">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <?= $tab('belum', 'Belum dibuat', $jumlah['belum']) ?>
            <?= $tab('selesai', 'Sudah dibuat', $jumlah['selesai']) ?>
            <?= $tab('batal', 'Dibatalkan', $jumlah['batal']) ?>
            <?= $tab('semua', 'Semua', count($semuaTugas)) ?>
        </div>
        <div class="card-body p-0">
            <?php if (!$tugas): ?>
                <div class="text-center text-muted py-5">
                    <?= $filter === 'belum' ? 'Tidak ada FPM dari Asakai yang belum dibuat.' : 'Belum ada data.' ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:13.5px">
                        <thead class="table-light">
                            <tr>
                                <th>Asakai</th>
                                <th>Mesin / Part</th>
                                <th>Jenis FPM</th>
                                <th>Ditandai</th>
                                <th>Status</th>
                                <th>FPM</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tugas as $t):
                                $jenis = ASAKAI_FPM_JENIS[$t['jenis']] ?? ['label' => $t['jenis'], 'form' => ''];
                            ?>
                                <tr class="<?= $t['status'] === 'sp' ? 'table-danger' : ($t['status'] === 'warning' ? 'table-warning' : '') ?>">
                                    <td style="white-space:nowrap">
                                        <a href="<?= htmlspecialchars($halamanAsakai) ?>?id=<?= (int) $t['id_asakai'] ?>"><?= monitoringFormatTgl(monitoringTgl($t['tanggal'])) ?></a>
                                        <?php if ($plantSaya === ''): ?><div class="text-muted small">Plant <?= htmlspecialchars($t['plant']) ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($t['machine']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($t['namapart'] . ($t['type'] !== '' ? ' - ' . $t['type'] : '')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($jenis['label']) ?></td>
                                    <td style="white-space:nowrap">
                                        <?= monitoringFormatTgl($t['mulai']) ?>
                                        <div class="text-muted small"><?= htmlspecialchars(($t['dibuat_oleh'] ?: '-') . ' - ' . ($t['cara'] === 'langsung' ? 'buat sekarang' : 'buat nanti')) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($t['status'] === 'selesai'): ?>
                                            <span class="badge bg-success">Sudah dibuat</span>
                                            <div class="text-muted small"><?= date('d-m-Y H:i', strtotime($t['selesai_at'])) ?></div>
                                        <?php elseif ($t['status'] === 'batal'): ?>
                                            <span class="badge bg-secondary">Dibatalkan</span>
                                            <div class="text-muted small"><?= htmlspecialchars(($t['dibatalkan_oleh'] ?? '') . ': ' . ($t['alasan_batal'] ?? '')) ?></div>
                                        <?php elseif ($t['status'] === 'sp'): ?>
                                            <span class="badge bg-danger">SP</span> H+<?= $t['hari'] ?>
                                            <div class="small text-danger">Tercatat SP sejak <?= monitoringFormatTgl($t['tgl_sp']) ?></div>
                                        <?php elseif ($t['status'] === 'warning'): ?>
                                            <span class="badge bg-warning text-dark">H+<?= $t['hari'] ?></span>
                                            <div class="small">Batas akhir <b><?= monitoringFormatTgl($t['batas_akhir']) ?></b>, setelah itu SP</div>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">H+<?= $t['hari'] ?></span>
                                            <div class="small">Batas akhir <b><?= monitoringFormatTgl($t['batas_akhir']) ?></b></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap">
                                        <?php if ($t['status'] === 'selesai'): ?>
                                            <a href="<?= htmlspecialchars($halamanFpm[$t['jenis']] ?? 'form_fpm.php') ?>?id=<?= (int) $t['id_fpm'] ?>" class="fw-semibold"><?= htmlspecialchars($t['no_form']) ?></a>
                                        <?php elseif ($t['status'] === 'batal'): ?>
                                            -
                                        <?php elseif ($asakaiFpmBisaBuat): ?>
                                            <a href="<?= htmlspecialchars($jenis['form']) ?>?asakai=<?= (int) $t['id_asakai'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-plus"></i> Buat FPM</a>
                                        <?php else: ?>
                                            <span class="text-muted small">Menunggu Staff IMP</span>
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
