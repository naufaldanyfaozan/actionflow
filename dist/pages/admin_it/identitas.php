<?php
require __DIR__ . '/_cek_admin.php';
require __DIR__ . '/_setting_ui.php';

// Logo upload disimpan di <root project>/APP_LOGO_FOLDER dengan nama unik; file lama tidak
// dihapus supaya riwayat perubahan tetap bisa ditelusuri.
const LOGO_MAKS_BYTE = 2 * 1024 * 1024;
const LOGO_TIPE = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$logoSlot = ['brand_logo_app' => 'logo_app', 'brand_logo_dokumen' => 'logo_dokumen'];

// @return array{0: ?string, 1: ?string} [path relatif root project, pesan error]
function simpanUploadLogo(array $file, string $prefix): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'upload gagal'];
    }
    if ($file['size'] > LOGO_MAKS_BYTE) {
        return [null, 'ukuran maksimal 2 MB'];
    }
    $info = @getimagesize($file['tmp_name']);
    $ext = $info ? (LOGO_TIPE[$info['mime']] ?? null) : null;
    if ($ext === null) {
        return [null, 'harus gambar PNG, JPG atau WEBP'];
    }

    $folder = dirname(__DIR__, 3) . '/' . APP_LOGO_FOLDER;
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        return [null, 'folder logo tidak bisa dibuat'];
    }
    $nama = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!pindahkanUploadAman($file['tmp_name'], $folder . $nama)) {
        return [null, 'file tidak bisa disimpan'];
    }
    return [APP_LOGO_FOLDER . $nama, null];
}

// ---------- Simpan (Post-Redirect-Get) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_it_token'], (string) ($_POST['token'] ?? ''))) {
        die('Sesi formulir kedaluwarsa, muat ulang halaman lalu coba lagi.');
    }

    $input = array_intersect_key((array) ($_POST['setting'] ?? []), ['brand_nama' => 1, 'brand_tagline' => 1]);
    $error = [];
    $uploadBaru = [];

    if (!empty($_POST['reset_semua'])) {
        foreach (APP_SETTING_DEFINISI as $key => $def) {
            if ($def['grup'] === 'branding') {
                $input[$key] = (string) $def['default'];
            }
        }
    } else {
        foreach ($logoSlot as $key => $field) {
            if (!empty($_POST['reset_logo'][$key])) {
                $input[$key] = (string) APP_SETTING_DEFINISI[$key]['default'];
                continue;
            }
            [$path, $pesan] = simpanUploadLogo($_FILES[$field] ?? [], $field);
            if ($pesan !== null) {
                $error[] = APP_SETTING_DEFINISI[$key]['label'] . ': ' . $pesan . '.';
            } elseif ($path !== null) {
                $input[$key] = $path;
                $uploadBaru[] = $path;
            }
        }
    }

    if (!$error) {
        $error = simpanAppSetting($koneksi, $input, $_SESSION['username']);
    }
    if ($error) {
        // Logo yang sudah terlanjur di-upload tidak dipakai - buang.
        foreach ($uploadBaru as $path) {
            @unlink(dirname(__DIR__, 3) . '/' . $path);
        }
    }

    $_SESSION['admin_it_flash'] = $error
        ? ['tipe' => 'danger', 'pesan' => 'Tidak disimpan: ' . implode(' ', $error), 'input' => $input]
        : ['tipe' => 'success', 'pesan' => !empty($_POST['reset_semua'])
            ? 'Identitas aplikasi dikembalikan ke bawaan.'
            : 'Identitas aplikasi berhasil disimpan dan langsung tampil di seluruh halaman.'];
    header('Location: identitas.php');
    exit;
}

$flash = $_SESSION['admin_it_flash'] ?? null;
unset($_SESSION['admin_it_flash']);

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
    <title>Identitas Aplikasi - Admin IT</title>
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

        .adm-table td {
            vertical-align: middle;
            font-size: 13.5px;
        }

        .adm-input-teks {
            max-width: 420px;
        }

        .adm-info {
            font-size: 11.5px;
            color: #64748b;
        }

        .adm-logo {
            width: 120px;
            height: 72px;
            object-fit: contain;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            padding: 6px;
        }

        .adm-logo-gelap {
            background: #343a40;
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
                    Identitas Aplikasi
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Ganti nama, tagline dan logo sesuai perusahaan pemakai. Berlaku di halaman login, sidebar semua role,
                        judul dashboard, form FPM, export Excel serta pesan WA &amp; email.
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= $flash['tipe'] ?>"><?= htmlspecialchars($flash['pesan']) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="card adm-card">
                    <input type="hidden" name="token" value="<?= $_SESSION['admin_it_token'] ?>">
                    <div class="card-header"><i class="bi bi-palette"></i> Nama &amp; Logo</div>
                    <div class="card-body p-0">
                        <table class="table adm-table mb-0">
                            <tbody>
                                <?php foreach (['brand_nama', 'brand_tagline'] as $key):
                                    $def = APP_SETTING_DEFINISI[$key];
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
                                <?php foreach ($logoSlot as $key => $field):
                                    $def = APP_SETTING_DEFINISI[$key];
                                    $pakaiBawaan = appSetting($key) === $def['default'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($def['label']) ?></div>
                                            <div class="adm-info"><?= htmlspecialchars($def['info']) ?></div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                                <img src="<?= htmlspecialchars(brandLogo($key === 'brand_logo_dokumen' ? 'dokumen' : 'app')) ?>" alt="Logo saat ini"
                                                    class="adm-logo <?= $key === 'brand_logo_app' ? 'adm-logo-gelap' : '' ?>">
                                                <div>
                                                    <input type="file" name="<?= $field ?>" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm" style="max-width:320px">
                                                    <div class="adm-info mt-1">PNG / JPG / WEBP, maks. 2 MB. <?= $key === 'brand_logo_app' ? 'Tampil di sidebar gelap - disarankan logo dengan latar transparan.' : '' ?></div>
                                                    <?php if (!$pakaiBawaan): ?>
                                                        <div class="form-check mt-1">
                                                            <input class="form-check-input" type="checkbox" name="reset_logo[<?= $key ?>]" value="1" id="reset_<?= $field ?>">
                                                            <label class="form-check-label small" for="reset_<?= $field ?>">Kembalikan ke logo bawaan</label>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="adm-info mt-1"><?= infoSettingAdmin($key, $meta) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white d-flex flex-wrap gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Simpan Identitas</button>
                        <button type="submit" name="reset_semua" value="1" class="btn btn-outline-secondary btn-sm" formnovalidate
                            onclick="return confirm('Kembalikan nama, tagline dan kedua logo ke bawaan?')">
                            <i class="bi bi-arrow-counterclockwise"></i> Kembalikan semua ke bawaan
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <?php include '../footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>
</body>

</html>
