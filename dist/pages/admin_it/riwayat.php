<?php
require __DIR__ . '/_cek_admin.php';

siapkanTabelAppSetting($koneksi);
$log = [];
$q = $koneksi->query("SELECT setting_key, nilai_lama, nilai_baru, diubah_oleh, diubah_at FROM app_setting_log ORDER BY id DESC LIMIT 500");
while ($q && ($r = $q->fetch_assoc())) {
    $log[] = $r;
}

function tampilNilaiSetting(string $key, ?string $nilai): string
{
    $def = APP_SETTING_DEFINISI[$key] ?? null;
    if ($nilai === null) {
        return '-';
    }
    if ($nilai === '') {
        return '<span class="text-muted">(kosong)</span>';
    }
    // Saklar WA per nomor (lihat config/wa_penerima_helper.php).
    if (($def && $def['tipe'] === 'bool') || strpos($key, 'wa_nomor:') === 0) {
        return $nilai === '1' ? 'Aktif' : 'Nonaktif';
    }
    return htmlspecialchars($nilai . (!empty($def['satuan']) ? ' ' . $def['satuan'] : ''));
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Riwayat Perubahan - Admin IT</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="../../../dist/css/dashboard-modern.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">
            <div class="container-fluid dashboard-wrapper">
                <div class="dashboard-title">
                    Riwayat Perubahan Aturan
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Siapa mengubah aturan apa dan kapan (500 perubahan terakhir).
                    </div>
                </div>

                <div class="card section">
                    <div class="card-body p-0">
                        <?php if (!$log): ?>
                            <div class="text-center text-muted py-5">Belum ada perubahan aturan.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:13.5px">
                                    <thead>
                                        <tr>
                                            <th>Waktu</th>
                                            <th>Aturan</th>
                                            <th>Nilai Lama</th>
                                            <th>Nilai Baru</th>
                                            <th>Diubah Oleh</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($log as $r):
                                            $def = APP_SETTING_DEFINISI[$r['setting_key']] ?? null;
                                        ?>
                                            <tr>
                                                <td style="white-space:nowrap"><?= date('d-m-Y H:i', strtotime($r['diubah_at'])) ?></td>
                                                <td>
                                                    <?php if (strpos($r['setting_key'], 'wa_nomor:') === 0): ?>
                                                        Notifikasi WA nomor <?= htmlspecialchars(substr($r['setting_key'], 9)) ?>
                                                        <div class="text-muted small">Notifikasi WA &amp; Email</div>
                                                    <?php elseif (strpos($r['setting_key'], 'wa_tambah:') === 0): ?>
                                                        Nomor WA tambahan <?= htmlspecialchars(substr($r['setting_key'], 10)) ?>
                                                        <div class="text-muted small">Notifikasi WA &amp; Email</div>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($def['label'] ?? $r['setting_key']) ?>
                                                        <?php if ($def): ?><div class="text-muted small"><?= htmlspecialchars(APP_SETTING_GRUP[$def['grup']]['judul'] ?? '') ?></div><?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= tampilNilaiSetting($r['setting_key'], $r['nilai_lama']) ?></td>
                                                <td class="fw-semibold"><?= tampilNilaiSetting($r['setting_key'], $r['nilai_baru']) ?></td>
                                                <td><?= htmlspecialchars($r['diubah_oleh'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <?php include '../footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>
</body>

</html>
