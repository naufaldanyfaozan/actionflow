<?php
require __DIR__ . '/_cek_admin.php';
require __DIR__ . '/_setting_ui.php';

// ---------- Simpan / reset (Post-Redirect-Get) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_it_token'], (string) ($_POST['token'] ?? ''))) {
        die('Sesi formulir kedaluwarsa, muat ulang halaman lalu coba lagi.');
    }

    $input = (array) ($_POST['setting'] ?? []);
    $grupReset = (string) ($_POST['reset_grup'] ?? '');
    if ($grupReset !== '') {
        $input = [];
        foreach (APP_SETTING_DEFINISI as $key => $def) {
            if ($def['grup'] === $grupReset) {
                $input[$key] = (string) $def['default'];
            }
        }
    }

    $error = simpanAppSetting($koneksi, $input, $_SESSION['username']);
    $_SESSION['admin_it_flash'] = $error
        ? ['tipe' => 'danger', 'pesan' => 'Tidak disimpan: ' . implode(' ', $error), 'input' => $input]
        : ['tipe' => 'success', 'pesan' => $grupReset !== ''
            ? 'Pengaturan "' . (APP_SETTING_GRUP[$grupReset]['judul'] ?? $grupReset) . '" dikembalikan ke default.'
            : 'Pengaturan berhasil disimpan dan langsung berlaku.'];

    header('Location: index.php' . ($grupReset !== '' ? '#grup-' . urlencode($grupReset) : ''));
    exit;
}

$flash = $_SESSION['admin_it_flash'] ?? null;
unset($_SESSION['admin_it_flash']);

// Nilai yang tampil: input terakhir kalau gagal validasi, selain itu nilai tersimpan / default.
$nilaiTampil = function (string $key) use ($flash) {
    if (($flash['tipe'] ?? '') === 'danger' && array_key_exists($key, $flash['input'] ?? [])) {
        return $flash['input'][$key];
    }
    return appSetting($key);
};

$meta = metaSettingAdmin($koneksi);
?>
<!doctype html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Pengaturan Aturan - Admin IT</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="../../../dist/css/dashboard-modern.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .adm-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .06);
            margin-bottom: 18px;
        }

        .adm-card .card-header {
            background: #fff;
            border-bottom: 1px solid #eef2f7;
            border-radius: 14px 14px 0 0;
            font-weight: 700;
        }

        .adm-table td,
        .adm-table th {
            vertical-align: middle;
            font-size: 13.5px;
        }

        .adm-table th {
            font-size: 12px;
            color: #475569;
            background: #f8fafc;
        }

        .adm-input {
            max-width: 110px;
        }

        .adm-input-group {
            max-width: 330px;
        }

        .adm-info {
            font-size: 11.5px;
            color: #64748b;
        }

        .adm-simpan {
            position: sticky;
            bottom: 0;
            z-index: 5;
            background: rgba(248, 250, 252, .95);
            border-top: 1px solid #e2e8f0;
            padding: 12px 0;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">
            <div class="container-fluid dashboard-wrapper">
                <div class="dashboard-title">
                    Pengaturan Aturan
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Ubah aturan yang dipakai web <?= htmlspecialchars(brandNama()) ?> tanpa mengubah kode. Perubahan langsung berlaku setelah disimpan
                        dan tercatat di <a href="riwayat.php">Riwayat Perubahan</a>.
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= $flash['tipe'] ?>"><?= htmlspecialchars($flash['pesan']) ?></div>
                <?php endif; ?>

                <form method="POST" id="formSetting">
                    <input type="hidden" name="token" value="<?= $_SESSION['admin_it_token'] ?>">

                    <?php foreach (APP_SETTING_GRUP as $grup => $infoGrup):
                        if (!empty($infoGrup['halaman'])) continue; // diatur di halaman sendiri
                        $keys = array_keys(array_filter(APP_SETTING_DEFINISI, fn($d) => $d['grup'] === $grup));
                        // Pasangan "Mendekati SP" / "Kena SP" ditampilkan satu baris.
                        $kunciKenaSp = array_filter(array_map(fn($k) => APP_SETTING_DEFINISI[$k]['pasangan'] ?? null, $keys));
                    ?>
                        <div class="card adm-card" id="grup-<?= $grup ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span class="me-auto"><i class="bi <?= $infoGrup['ikon'] ?>"></i> <?= htmlspecialchars($infoGrup['judul']) ?></span>
                                <button type="submit" name="reset_grup" value="<?= $grup ?>" class="btn btn-outline-secondary btn-sm" formnovalidate
                                    onclick="return confirm('Kembalikan semua pengaturan &quot;<?= htmlspecialchars($infoGrup['judul']) ?>&quot; ke nilai default?')">
                                    <i class="bi bi-arrow-counterclockwise"></i> Kembalikan ke default
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <?php if ($kunciKenaSp): ?>
                                    <table class="table adm-table mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width:34%">Tahap approval</th>
                                                <th>Mendekati SP mulai hari ke-</th>
                                                <th>Kena SP mulai hari ke-</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($keys as $key):
                                                $def = APP_SETTING_DEFINISI[$key];
                                                if (empty($def['pasangan'])) continue;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?= htmlspecialchars(explode(' - ', $def['label'])[0]) ?></div>
                                                        <div class="adm-info">Satuan: <?= htmlspecialchars($def['satuan']) ?></div>
                                                    </td>
                                                    <td><?= inputSettingAdmin($key, $nilaiTampil($key)) ?>
                                                        <div class="adm-info mt-1"><?= infoSettingAdmin($key, $meta) ?></div>
                                                    </td>
                                                    <td><?= inputSettingAdmin($def['pasangan'], $nilaiTampil($def['pasangan'])) ?>
                                                        <div class="adm-info mt-1"><?= infoSettingAdmin($def['pasangan'], $meta) ?></div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>

                                <table class="table adm-table mb-0">
                                    <tbody>
                                        <?php foreach ($keys as $key):
                                            $def = APP_SETTING_DEFINISI[$key];
                                            if (!empty($def['pasangan']) || in_array($key, $kunciKenaSp, true)) continue;
                                        ?>
                                            <tr>
                                                <td style="width:34%">
                                                    <div class="fw-semibold"><?= htmlspecialchars($def['label']) ?></div>
                                                    <?php if (!empty($def['info'])): ?><div class="adm-info"><?= htmlspecialchars($def['info']) ?></div><?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= inputSettingAdmin($key, $nilaiTampil($key)) ?>
                                                    <div class="adm-info mt-1"><?= infoSettingAdmin($key, $meta) ?></div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="adm-simpan d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Semua Perubahan</button>
                        <span class="adm-info">Hanya isian yang nilainya berubah yang disimpan &amp; dicatat di riwayat.</span>
                    </div>
                </form>
            </div>
        </main>

        <?php include '../footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>
    <script>
        // Label Aktif/Nonaktif ikut berubah saat switch digeser.
        document.querySelectorAll('.form-switch input[type=checkbox]').forEach(function(el) {
            el.addEventListener('change', function() {
                el.nextElementSibling.textContent = el.checked ? 'Aktif' : 'Nonaktif';
            });
        });
    </script>
</body>

</html>
