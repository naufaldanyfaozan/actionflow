<?php
require __DIR__ . '/_cek_admin.php';
require __DIR__ . '/_setting_ui.php';
require_once __DIR__ . '/../config/wa_penerima_helper.php';
require_once __DIR__ . '/../config/monitoring_approval_helper.php';
require_once __DIR__ . '/../services/whatsapp.php';
require_once __DIR__ . '/../services/email.php';

$penerimaWa = waDaftarPenerima();

// ---------- Simpan (Post-Redirect-Get) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_it_token'], (string) ($_POST['token'] ?? ''))) {
        die('Sesi formulir kedaluwarsa, muat ulang halaman lalu coba lagi.');
    }
    $aksi = (string) ($_POST['aksi'] ?? '');
    $flash = null;

    if ($aksi === 'wa') {
        // Checkbox yang tidak dicentang = nonaktif. Hanya nomor yang ada di wa_map yang diatur.
        $aktif = array_map('strval', array_keys((array) ($_POST['wa_aktif'] ?? [])));
        $nonaktif = array_fill_keys(array_diff(array_keys($penerimaWa), $aktif), true);
        $berubah = simpanWaNonaktif($koneksi, array_keys($penerimaWa), $nonaktif, $_SESSION['username']);
        $flash = ['tipe' => 'success', 'pesan' => $berubah ? "Status WA $berubah nomor diperbarui dan langsung berlaku." : 'Tidak ada perubahan status WA.'];
    } elseif ($aksi === 'wa_tambah') {
        $peran = (string) ($_POST['peran'] ?? '');
        $nama  = (string) ($_POST['nama'] ?? '');
        [$nomor, $error] = waValidasiNomorBaru((string) ($_POST['nomor'] ?? ''));
        $error = $error ?? tambahWaNomor($koneksi, $nomor, $peran, $nama, $_SESSION['username']);
        $flash = $error
            ? ['tipe' => 'danger', 'pesan' => $error, 'form_wa' => ['nomor' => $_POST['nomor'] ?? '', 'peran' => $peran, 'nama' => $nama]]
            : ['tipe' => 'success', 'pesan' => "Nomor $nomor ditambahkan sebagai " . waLabelPeran($peran) . ' dan langsung menerima WA peran tersebut.'];
    } elseif ($aksi === 'wa_hapus') {
        $error = hapusWaNomor($koneksi, (int) ($_POST['id'] ?? 0), $_SESSION['username']);
        $flash = $error ? ['tipe' => 'danger', 'pesan' => $error] : ['tipe' => 'success', 'pesan' => 'Nomor tambahan dihapus.'];
    } elseif ($aksi === 'email') {
        $input = array_intersect_key((array) ($_POST['setting'] ?? []), array_filter(APP_SETTING_DEFINISI, fn($d) => $d['grup'] === 'email'));
        $error = simpanAppSetting($koneksi, $input, $_SESSION['username']);
        $flash = $error
            ? ['tipe' => 'danger', 'pesan' => 'Tidak disimpan: ' . implode(' ', $error), 'input' => $input]
            : ['tipe' => 'success', 'pesan' => 'Pengaturan email SP berhasil disimpan.'];
    } elseif ($aksi === 'tes_email') {
        // Tes memakai email HRD yang sudah TERSIMPAN, terlepas dari saklar aktif/nonaktif.
        $tujuan = array_values(array_filter([(string) appSetting('email_hrd_1'), (string) appSetting('email_hrd_2')]));
        if (!$tujuan) {
            $flash = ['tipe' => 'danger', 'pesan' => 'Simpan minimal 1 email HRD dulu sebelum mengirim email tes.'];
        } elseif (!EMAIL_NOTIF_ENABLED) {
            $flash = ['tipe' => 'warning', 'pesan' => 'Server local (XAMPP): email tidak dikirim. Coba lagi dari hosting.'];
        } else {
            $ok = kirimEmail($tujuan, '[' . brandNama() . '] Email tes notifikasi SP',
                '<p>Ini email tes dari halaman Admin IT ' . htmlspecialchars(brandNama()) . '.</p>'
                . '<p>Kalau email ini diterima, notifikasi SP ke HRD siap dipakai.</p>');
            $flash = $ok
                ? ['tipe' => 'success', 'pesan' => 'Email tes dikirim ke ' . implode(', ', $tujuan) . '. Cek juga folder spam.']
                : ['tipe' => 'danger', 'pesan' => 'Email tes gagal dikirim. Pastikan fungsi mail() / sendmail aktif di hosting dan alamat pengirim memakai domain hosting.'];
        }
    }

    $_SESSION['admin_it_flash'] = $flash;
    $anchor = ['wa' => '#grup-wa', 'wa_tambah' => '#grup-wa-tambah', 'wa_hapus' => '#grup-wa-tambah'][$aksi] ?? '#grup-email';
    header('Location: notifikasi.php' . $anchor);
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
$nonaktifWa = waNomorNonaktif();
$tambahanWa = waNomorTambahan();
$pilihanPeran = waPilihanPeran();
$formWa = $flash['form_wa'] ?? ['nomor' => '', 'peran' => '', 'nama' => ''];
// Nama pemilik nomor (dari nomor tambahan) untuk ditampilkan di tabel utama.
$namaNomorWa = [];
foreach ($tambahanWa as $r) {
    if (trim((string) $r['nama']) !== '') {
        $namaNomorWa[$r['nomor']] = $r['nama'];
    }
}

// Ringkasan status email SP (lihat config/sp_email_hrd_helper.php).
$statusEmailSp = [];
if (siapkanTabelSpFpm($koneksi)) {
    $q = $koneksi->query("SELECT IFNULL(email_hrd_status, 'menunggu') AS s, COUNT(*) AS n FROM fpm_sp_pelanggaran GROUP BY s");
    while ($q && ($r = $q->fetch_assoc())) {
        $statusEmailSp[$r['s']] = (int) $r['n'];
    }
}
$keyEmail = array_keys(array_filter(APP_SETTING_DEFINISI, fn($d) => $d['grup'] === 'email'));
?>
<!doctype html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Notifikasi WA &amp; Email - Admin IT</title>
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

        .adm-input-teks {
            max-width: 360px;
        }

        .adm-info {
            font-size: 11.5px;
            color: #64748b;
        }

        .adm-peran {
            display: inline-block;
            font-size: 11.5px;
            background: #eef2f7;
            border-radius: 6px;
            padding: 1px 7px;
            margin: 1px 2px 1px 0;
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
                    Notifikasi WA &amp; Email
                    <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
                        Aktif / nonaktifkan WhatsApp per nomor penerima dan email SP otomatis ke HRD. Perubahan langsung berlaku
                        dan tercatat di <a href="riwayat.php">Riwayat Perubahan</a>.
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= $flash['tipe'] ?>"><?= htmlspecialchars($flash['pesan']) ?></div>
                <?php endif; ?>

                <!-- ================= WhatsApp per nomor ================= -->
                <form method="POST" class="card adm-card" id="grup-wa">
                    <input type="hidden" name="token" value="<?= $_SESSION['admin_it_token'] ?>">
                    <input type="hidden" name="aksi" value="wa">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                        <span class="me-auto"><i class="bi bi-whatsapp"></i> Notifikasi WhatsApp per Penerima</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-wa-semua="1"><i class="bi bi-check2-all"></i> Aktifkan semua</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-wa-semua="0"><i class="bi bi-slash-circle"></i> Nonaktifkan semua</button>
                    </div>
                    <div class="card-body pb-2">
                        <?php if (!appSettingBool('wa_aktif')): ?>
                            <div class="alert alert-warning py-2 mb-2">Saklar utama WhatsApp sedang <b>Nonaktif</b> - tidak ada WA yang dikirim ke nomor mana pun.
                                Ubah di <a href="index.php#grup-reminder">Pengaturan Aturan &rsaquo; Reminder WhatsApp</a>.</div>
                        <?php else: ?>
                            <div class="adm-info mb-2">Saklar utama WhatsApp: <b>Aktif</b> (ubah di <a href="index.php#grup-reminder">Pengaturan Aturan</a>).
                                Nomor yang dinonaktifkan di bawah ini tidak menerima reminder maupun notifikasi aksi; penerima lain di pesan yang sama tetap dikirimi.</div>
                        <?php endif; ?>
                        <?php if (!WA_NOTIF_ENABLED): ?>
                            <div class="adm-info mb-2"><i class="bi bi-info-circle"></i> Server local (XAMPP): WA memang tidak pernah dikirim dari sini.</div>
                        <?php endif; ?>
                        <?php foreach (['wa_nomor.php' => 'nomor WA penerima', 'env.php' => 'token Fonnte'] as $fileRahasia => $isiRahasia): ?>
                            <?php if (fileRahasiaFpm($fileRahasia) === null): ?>
                                <div class="alert alert-danger py-2 mb-2"><i class="bi bi-exclamation-triangle"></i>
                                    File <b>config/<?= $fileRahasia ?></b> (<?= $isiRahasia ?>) belum ada di server ini - WA tidak bisa terkirim.
                                    Buat dari <code><?= str_replace('.php', '.example.php', $fileRahasia) ?></code> lalu upload ke folder <code>dist/pages/config/</code>.</div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table adm-table mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:22%">Nomor WA</th>
                                        <th>Dipakai untuk</th>
                                        <th style="width:16%">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($penerimaWa as $nomor => $peran):
                                        $aktif = !isset($nonaktifWa[$nomor]);
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= htmlspecialchars($nomor) ?></div>
                                                <?php if (isset($namaNomorWa[$nomor])): ?><div class="adm-info"><?= htmlspecialchars($namaNomorWa[$nomor]) ?></div><?php endif; ?>
                                            </td>
                                            <td><?php foreach ($peran as $p): ?><span class="adm-peran"><?= htmlspecialchars($p) ?></span><?php endforeach; ?></td>
                                            <td>
                                                <div class="form-check form-switch m-0">
                                                    <input class="form-check-input wa-saklar" type="checkbox" role="switch" name="wa_aktif[<?= htmlspecialchars($nomor) ?>]" value="1" <?= $aktif ? 'checked' : '' ?>>
                                                    <label class="form-check-label small"><?= $aktif ? 'Aktif' : 'Nonaktif' ?></label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Simpan Status WA</button>
                        <span class="adm-info">Daftar nomor dari config/wa_map.php ditambah nomor yang ditambahkan di bawah.</span>
                    </div>
                </form>

                <!-- ================= Tambah nomor WA ================= -->
                <div class="card adm-card" id="grup-wa-tambah">
                    <div class="card-header"><i class="bi bi-person-plus"></i> Tambah Nomor WhatsApp</div>
                    <div class="card-body">
                        <div class="adm-info mb-3">
                            Nomor baru langsung menerima semua WA (reminder &amp; notifikasi aksi) milik peran yang dipilih, sama seperti nomor lain di peran itu.
                            Pilih peran yang sama dua kali untuk beberapa orang di satu jabatan.
                        </div>
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="token" value="<?= $_SESSION['admin_it_token'] ?>">
                            <input type="hidden" name="aksi" value="wa_tambah">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1">Nama / keterangan</label>
                                <input type="text" name="nama" maxlength="60" class="form-control form-control-sm" placeholder="mis. Budi (Dept Head PE)" value="<?= htmlspecialchars($formWa['nama']) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1">Nomor WhatsApp</label>
                                <input type="text" name="nomor" inputmode="numeric" class="form-control form-control-sm" placeholder="081234567890" required value="<?= htmlspecialchars($formWa['nomor']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">Peran penerima</label>
                                <select name="peran" class="form-select form-select-sm" required>
                                    <option value="">- Pilih peran -</option>
                                    <?php foreach ($pilihanPeran as $grup => $opsi): ?>
                                        <optgroup label="<?= htmlspecialchars($grup) ?>">
                                            <?php foreach ($opsi as $kunci => $label): ?>
                                                <option value="<?= htmlspecialchars($kunci) ?>" <?= $formWa['peran'] === $kunci ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i> Tambah</button>
                            </div>
                        </form>
                    </div>
                    <?php if ($tambahanWa): ?>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table adm-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Nama / keterangan</th>
                                            <th>Nomor WA</th>
                                            <th>Peran</th>
                                            <th>Ditambahkan</th>
                                            <th style="width:90px"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tambahanWa as $r): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($r['nama'] ?: '-') ?></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($r['nomor']) ?></td>
                                                <td><span class="adm-peran"><?= htmlspecialchars(waLabelPeran($r['peran'])) ?></span></td>
                                                <td class="adm-info"><?= htmlspecialchars($r['dibuat_oleh'] ?? '-') ?>, <?= $r['dibuat_at'] ? date('d-m-Y H:i', strtotime($r['dibuat_at'])) : '-' ?></td>
                                                <td>
                                                    <form method="POST" onsubmit="return confirm('Hapus nomor <?= htmlspecialchars($r['nomor']) ?> dari peran ini?')">
                                                        <input type="hidden" name="token" value="<?= $_SESSION['admin_it_token'] ?>">
                                                        <input type="hidden" name="aksi" value="wa_hapus">
                                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Hapus</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ================= Email SP ke HRD ================= -->
                <form method="POST" class="card adm-card" id="grup-email">
                    <input type="hidden" name="token" value="<?= $_SESSION['admin_it_token'] ?>">
                    <input type="hidden" name="aksi" value="email">
                    <div class="card-header"><i class="bi bi-envelope"></i> Email SP Otomatis ke HRD</div>
                    <div class="card-body pb-0">
                        <div class="adm-info">
                            Setiap ada SP (Surat Peringatan) baru di Monitoring Approval, sistem mengirim 1 email ke HRD berisi pihak yang kena SP,
                            akun terkait, level SP, masa berlaku dan daftar FPM yang terlambat.
                            <?php if (!EMAIL_NOTIF_ENABLED): ?><br><i class="bi bi-info-circle"></i> Server local (XAMPP): email memang tidak pernah dikirim dari sini.<?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table adm-table mb-0">
                            <tbody>
                                <?php foreach ($keyEmail as $key):
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
                                <tr>
                                    <td>
                                        <div class="fw-semibold">Status email SP</div>
                                        <div class="adm-info">Jumlah catatan SP menurut status kirim email.</div>
                                    </td>
                                    <td class="adm-info">
                                        Terkirim: <b><?= $statusEmailSp['terkirim'] ?? 0 ?></b> &middot;
                                        Menunggu / gagal: <b><?= $statusEmailSp['menunggu'] ?? 0 ?></b> &middot;
                                        Tidak dikirim (fitur nonaktif): <b><?= $statusEmailSp['nonaktif'] ?? 0 ?></b> &middot;
                                        Sebelum fitur ada: <b><?= $statusEmailSp['lama'] ?? 0 ?></b>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white d-flex flex-wrap gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Simpan Pengaturan Email</button>
                        <button type="submit" name="aksi" value="tes_email" class="btn btn-outline-secondary btn-sm" formnovalidate>
                            <i class="bi bi-send"></i> Kirim Email Tes
                        </button>
                        <span class="adm-info">Email tes dikirim ke email HRD yang sudah tersimpan, walau fitur masih nonaktif.</span>
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
        function labelSaklar(el) {
            el.nextElementSibling.textContent = el.checked ? 'Aktif' : 'Nonaktif';
        }
        document.querySelectorAll('.form-switch input[type=checkbox]').forEach(function(el) {
            el.addEventListener('change', function() {
                labelSaklar(el);
            });
        });
        document.querySelectorAll('[data-wa-semua]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.wa-saklar').forEach(function(el) {
                    el.checked = btn.dataset.waSemua === '1';
                    labelSaklar(el);
                });
            });
        });
    </script>
</body>

</html>
