<?php

/**
 * Pengaturan aturan aplikasi yang bisa diubah Admin IT lewat halaman admin_it/index.php,
 * tanpa mengubah kode. Nilai disimpan di tabel app_setting; kalau belum pernah diubah
 * (atau database belum siap) dipakai nilai default di APP_SETTING_DEFINISI.
 *
 * Pemakaian di kode: appSettingInt('sp_masa_berlaku_bulan'), appSettingBool('wa_aktif'),
 * appSetting('reminder_jam_mulai').
 *
 * Menambah aturan baru: tambahkan entri di APP_SETTING_DEFINISI, lalu baca nilainya di
 * tempat aturan itu dipakai - halaman Admin IT otomatis menampilkan isian-nya.
 */

const APP_SETTING_GRUP = [
    'monitor'  => ['judul' => 'Monitor Approval & SP', 'ikon' => 'bi-diagram-3'],
    'claim'    => ['judul' => 'FPM Claim Machining', 'ikon' => 'bi-gear-wide-connected'],
    'reminder' => ['judul' => 'Reminder WhatsApp', 'ikon' => 'bi-whatsapp'],
    'upload'   => ['judul' => 'Upload Bukti & Reschedule', 'ikon' => 'bi-cloud-upload'],
    // Grup dengan 'halaman' punya halaman Admin IT sendiri (tidak tampil di index.php).
    'email'    => ['judul' => 'Email SP ke HRD', 'ikon' => 'bi-envelope', 'halaman' => 'notifikasi.php'],
    'branding' => ['judul' => 'Identitas Aplikasi', 'ikon' => 'bi-palette', 'halaman' => 'identitas.php'],
];

// Logo bawaan & folder logo hasil upload Admin IT (relatif ke root project fpm/).
const APP_LOGO_FOLDER = 'src/assets/img/branding/';

// tipe: int | bool | time | text | email | logo. min/max untuk int, maks (panjang) untuk text.
// 'pasangan' = key nilai SP yang harus lebih besar.
const APP_SETTING_DEFINISI = [
    // ---------- Monitor Approval & SP ----------
    'monitor_jawab_warning'      => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 1, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Menjawab FPM (Dept Head) - mulai "Mendekati SP"', 'pasangan' => 'monitor_jawab_sp'],
    'monitor_jawab_sp'           => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 2, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Menjawab FPM (Dept Head) - kena SP'],
    'monitor_periksa_warning'    => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Memeriksa FPM (Plant Head) - mulai "Mendekati SP"', 'pasangan' => 'monitor_periksa_sp'],
    'monitor_periksa_sp'         => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 4, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Memeriksa FPM (Plant Head) - kena SP'],
    'monitor_verifikasi_warning' => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 1, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Verifikasi FPM (Head IMP) - mulai "Mendekati SP"', 'pasangan' => 'monitor_verifikasi_sp'],
    'monitor_verifikasi_sp'      => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 2, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Verifikasi FPM (Head IMP) - kena SP'],
    'monitor_setuju_warning'     => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Menyetujui FPM (Directure) - mulai "Mendekati SP"', 'pasangan' => 'monitor_setuju_sp'],
    'monitor_setuju_sp'          => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 4, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Menyetujui FPM (Directure) - kena SP'],
    'monitor_cm_jawab_warning'   => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 1, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja setelah due date', 'label' => 'Menjawab FPM Claim Machining - mulai "Mendekati SP"', 'pasangan' => 'monitor_cm_jawab_sp'],
    'monitor_cm_jawab_sp'        => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 2, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja setelah due date', 'label' => 'Menjawab FPM Claim Machining - kena SP'],
    'monitor_cm_dh_warning'      => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 1, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Claim Machining: approve jawaban (Dept Head) - mulai "Mendekati SP"', 'pasangan' => 'monitor_cm_dh_sp'],
    'monitor_cm_dh_sp'           => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 2, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Claim Machining: approve jawaban (Dept Head) - kena SP'],
    'monitor_cm_ph_warning'      => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Claim Machining: approve (Plant Head) - mulai "Mendekati SP"', 'pasangan' => 'monitor_cm_ph_sp'],
    'monitor_cm_ph_sp'           => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 4, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Claim Machining: approve (Plant Head) - kena SP'],
    'monitor_cm_dir_warning'     => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Claim Machining: approve (Directure) - mulai "Mendekati SP"', 'pasangan' => 'monitor_cm_dir_sp'],
    'monitor_cm_dir_sp'          => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 4, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Claim Machining: approve (Directure) - kena SP'],
    'sp_masa_berlaku_bulan'      => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 6, 'min' => 1, 'max' => 24, 'satuan' => 'bulan', 'label' => 'Masa berlaku SP', 'info' => 'Terlambat lagi selama SP masih berlaku -> naik level.'],
    'sp_level_maks'              => ['grup' => 'monitor', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 5, 'satuan' => 'level', 'label' => 'Level SP tertinggi', 'info' => 'Mis. 3 = SP 1 -> SP 2 -> SP 3.'],

    // ---------- Claim Machining ----------
    'cm_batas_jawab_baru'        => ['grup' => 'claim', 'tipe' => 'int', 'default' => 5, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Batas jawab - masalah baru', 'info' => 'Dihitung dari tanggal pembuatan FPM.'],
    'cm_batas_jawab_berulang'    => ['grup' => 'claim', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Batas jawab - masalah berulang'],

    // ---------- Reminder WA ----------
    'wa_aktif'                   => ['grup' => 'reminder', 'tipe' => 'bool', 'default' => 1, 'label' => 'Kirim notifikasi WhatsApp', 'info' => 'Matikan untuk menghentikan SEMUA WA (reminder & notifikasi aksi). Di XAMPP/local WA selalu mati.'],
    'reminder_jam_mulai'         => ['grup' => 'reminder', 'tipe' => 'time', 'default' => '08:00', 'label' => 'Reminder dikirim mulai jam'],
    'reminder_jam_selesai'       => ['grup' => 'reminder', 'tipe' => 'time', 'default' => '17:00', 'label' => 'Reminder dikirim sampai jam', 'info' => 'Sabtu & Minggu reminder tidak dikirim.'],
    'reminder_h_due'             => ['grup' => 'reminder', 'tipe' => 'int', 'default' => 2, 'min' => 1, 'max' => 14, 'satuan' => 'hari sebelum due date', 'label' => 'Reminder due date countermeasure (FPM & Asakai)'],
    'reminder_eskalasi_aktif'    => ['grup' => 'reminder', 'tipe' => 'bool', 'default' => 1, 'label' => 'Eskalasi FPM belum dijawab ke Plant Head + Directure'],
    'reminder_eskalasi_hari'     => ['grup' => 'reminder', 'tipe' => 'int', 'default' => 7, 'min' => 1, 'max' => 60, 'satuan' => 'hari', 'label' => 'Eskalasi & due date jawab FPM', 'info' => 'Dihitung dari tanggal FPM diterima Dept.'],
    'reminder_asakai_hari'       => ['grup' => 'reminder', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'satuan' => 'hari kerja', 'label' => 'Reminder Asakai yang masih punya countermeasure belum CLOSED'],
    'reminder_verifikasi_bulan'  => ['grup' => 'reminder', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 24, 'satuan' => 'bulan', 'label' => 'Reminder verifikasi setelah actual date terakhir'],

    // ---------- Upload & Reschedule ----------
    'reschedule_maks'            => ['grup' => 'upload', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 10, 'satuan' => 'kali', 'label' => 'Maksimal reschedule due date per countermeasure FPM'],
    'bukti_fpm_maks_file'        => ['grup' => 'upload', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 10, 'satuan' => 'file', 'label' => 'Maksimal file bukti per countermeasure FPM'],
    'bukti_asakai_maks_file'     => ['grup' => 'upload', 'tipe' => 'int', 'default' => 3, 'min' => 1, 'max' => 3, 'satuan' => 'file', 'label' => 'Maksimal file bukti per countermeasure Asakai', 'info' => 'Maksimal 3 karena slot file di tabel Asakai hanya 3.'],

    // ---------- Email SP ke HRD (halaman notifikasi.php) ----------
    'email_sp_aktif'             => ['grup' => 'email', 'tipe' => 'bool', 'default' => 0, 'label' => 'Kirim email otomatis ke HRD saat ada SP baru', 'info' => 'Selama nonaktif, SP baru tidak dikirim (juga tidak menumpuk untuk dikirim belakangan). Di XAMPP/local email selalu mati.'],
    'email_hrd_1'                => ['grup' => 'email', 'tipe' => 'email', 'default' => '', 'label' => 'Email HRD 1'],
    'email_hrd_2'                => ['grup' => 'email', 'tipe' => 'email', 'default' => '', 'label' => 'Email HRD 2', 'info' => 'Boleh dikosongkan kalau hanya 1 penerima.'],
    'email_pengirim'             => ['grup' => 'email', 'tipe' => 'email', 'default' => '', 'label' => 'Alamat pengirim (From)', 'info' => 'Kosong = noreply@<domain hosting>. Sebaiknya alamat email di domain hosting sendiri supaya tidak masuk spam.'],

    // ---------- Identitas aplikasi (halaman identitas.php) ----------
    'brand_nama'                 => ['grup' => 'branding', 'tipe' => 'text', 'default' => 'ACTIONFLOW', 'maks' => 40, 'wajib' => true, 'label' => 'Nama aplikasi', 'info' => 'Tampil di halaman login, sidebar, judul dashboard, export Excel & pesan WA/email.'],
    'brand_tagline'              => ['grup' => 'branding', 'tipe' => 'text', 'default' => 'Corrective Action, Monitoring & Improvement System', 'maks' => 100, 'label' => 'Tagline (halaman login)'],
    'brand_logo_app'             => ['grup' => 'branding', 'tipe' => 'logo', 'default' => 'src/assets/img/logo2.png', 'label' => 'Logo aplikasi', 'info' => 'Sidebar, halaman pilih modul, login & cetak Sasaran Mutu.'],
    'brand_logo_dokumen'         => ['grup' => 'branding', 'tipe' => 'logo', 'default' => 'src/assets/img/logosti.png', 'label' => 'Logo perusahaan (form FPM)', 'info' => 'Kop dokumen di halaman detail / cetak FPM.'],
];

function siapkanTabelAppSetting(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap === null) {
        $siap = $koneksi->query("CREATE TABLE IF NOT EXISTS app_setting (
                setting_key VARCHAR(60) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                diubah_oleh VARCHAR(50) NULL,
                diubah_at DATETIME NULL
            )")
            && $koneksi->query("CREATE TABLE IF NOT EXISTS app_setting_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(60) NOT NULL,
                nilai_lama VARCHAR(255) NULL,
                nilai_baru VARCHAR(255) NOT NULL,
                diubah_oleh VARCHAR(50) NULL,
                diubah_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_key (setting_key)
            )");
    }
    return $siap;
}

// Semua nilai tersimpan (sekali query per request). Koneksi diambil dari $koneksi global.
function appSettingSemua(bool $muatUlang = false): array
{
    static $cache = null;
    if ($cache !== null && !$muatUlang) {
        return $cache;
    }

    // Belum ada koneksi (helper di-include sebelum koneksi.php) -> pakai default dulu, tanpa
    // di-cache, supaya pemanggilan berikutnya setelah koneksi siap tetap membaca database.
    $koneksi = $GLOBALS['koneksi'] ?? null;
    if (!($koneksi instanceof mysqli) || !siapkanTabelAppSetting($koneksi)) {
        return [];
    }

    $cache = [];
    $q = $koneksi->query("SELECT setting_key, setting_value FROM app_setting");
    while ($q && ($r = $q->fetch_assoc())) {
        $cache[$r['setting_key']] = $r['setting_value'];
    }
    return $cache;
}

function appSetting(string $key)
{
    $def = APP_SETTING_DEFINISI[$key] ?? null;
    if ($def === null) {
        throw new InvalidArgumentException("Setting '$key' tidak dikenal");
    }
    $nilai = appSettingSemua()[$key] ?? null;
    return ($nilai === null || appSettingValidasi($key, $nilai) !== null) ? $def['default'] : $nilai;
}

function appSettingInt(string $key): int
{
    return (int) appSetting($key);
}

function appSettingBool(string $key): bool
{
    return (bool) (int) appSetting($key);
}

// null kalau valid, selain itu pesan kesalahan.
function appSettingValidasi(string $key, $nilai): ?string
{
    $def = APP_SETTING_DEFINISI[$key];
    $nilai = trim((string) $nilai);

    switch ($def['tipe']) {
        case 'int':
            if (!preg_match('/^\d+$/', $nilai)) {
                return 'harus berupa angka';
            }
            if ((int) $nilai < $def['min'] || (int) $nilai > $def['max']) {
                return "harus antara {$def['min']} dan {$def['max']}";
            }
            return null;
        case 'bool':
            return in_array($nilai, ['0', '1'], true) ? null : 'harus aktif / nonaktif';
        case 'time':
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $nilai) ? null : 'format jam harus HH:MM';
        case 'text':
            if ($nilai === '' && !empty($def['wajib'])) {
                return 'wajib diisi';
            }
            return mb_strlen($nilai) <= $def['maks'] ? null : "maksimal {$def['maks']} karakter";
        case 'email':
            return ($nilai === '' || filter_var($nilai, FILTER_VALIDATE_EMAIL)) ? null : 'bukan alamat email yang valid';
        case 'logo':
            // Hanya logo bawaan atau file hasil upload di APP_LOGO_FOLDER yang masih ada.
            $pola = '#^' . preg_quote(APP_LOGO_FOLDER, '#') . '[A-Za-z0-9_-]+\.(png|jpg|webp)$#';
            if ($nilai !== (string) $def['default'] && !preg_match($pola, $nilai)) {
                return 'bukan file logo yang valid';
            }
            return is_file(dirname(__DIR__, 3) . '/' . $nilai) ? null : 'file logo tidak ditemukan';
    }
    return 'tipe tidak dikenal';
}

/**
 * Simpan banyak setting sekaligus. Hanya yang nilainya berubah yang ditulis & dicatat
 * di app_setting_log. @return string[] pesan kesalahan (kosong = berhasil semua).
 */
function simpanAppSetting(mysqli $koneksi, array $input, string $oleh): array
{
    if (!siapkanTabelAppSetting($koneksi)) {
        return ['Tabel pengaturan tidak bisa dibuat: ' . $koneksi->error];
    }

    $error = [];
    $baru  = [];
    foreach (APP_SETTING_DEFINISI as $key => $def) {
        // Isian yang tidak dikirim tidak diubah (termasuk aktif/nonaktif - form mengirim
        // hidden "0" sebelum checkbox-nya, jadi checkbox kosong tetap terkirim sebagai 0).
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $nilai = trim((string) $input[$key]);

        $pesan = appSettingValidasi($key, $nilai);
        if ($pesan !== null) {
            $error[] = $def['label'] . ' ' . $pesan . '.';
            continue;
        }
        $baru[$key] = $def['tipe'] === 'int' ? (string) (int) $nilai : $nilai;
    }

    // Email SP ke HRD hanya boleh diaktifkan kalau minimal 1 email HRD terisi (nilai yang
    // tidak ikut dikirim form dibaca dari yang tersimpan).
    $efektif = fn($k) => $baru[$k] ?? (string) appSetting($k);
    if ($efektif('email_sp_aktif') === '1' && $efektif('email_hrd_1') === '' && $efektif('email_hrd_2') === '') {
        $error[] = 'Isi minimal 1 email HRD sebelum mengaktifkan email SP.';
    }

    // Aturan antar-isian: batas "Mendekati SP" harus lebih kecil dari batas "Kena SP",
    // jam mulai reminder sebelum jam selesai.
    foreach (APP_SETTING_DEFINISI as $key => $def) {
        if (!empty($def['pasangan']) && isset($baru[$key], $baru[$def['pasangan']]) && (int) $baru[$key] >= (int) $baru[$def['pasangan']]) {
            $error[] = $def['label'] . ' harus lebih kecil dari batas kena SP.';
        }
    }
    if (isset($baru['reminder_jam_mulai'], $baru['reminder_jam_selesai']) && $baru['reminder_jam_mulai'] >= $baru['reminder_jam_selesai']) {
        $error[] = 'Jam mulai reminder harus sebelum jam selesai.';
    }

    if ($error) {
        return $error;
    }

    $lama = appSettingSemua(true);
    $stmtSimpan = $koneksi->prepare("INSERT INTO app_setting (setting_key, setting_value, diubah_oleh, diubah_at)
        VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
        diubah_oleh = VALUES(diubah_oleh), diubah_at = VALUES(diubah_at)");
    $stmtLog = $koneksi->prepare("INSERT INTO app_setting_log (setting_key, nilai_lama, nilai_baru, diubah_oleh) VALUES (?, ?, ?, ?)");

    foreach ($baru as $key => $nilai) {
        $nilaiLama = $lama[$key] ?? (string) APP_SETTING_DEFINISI[$key]['default'];
        if ($nilaiLama === $nilai) {
            continue;
        }
        $stmtSimpan->bind_param('sss', $key, $nilai, $oleh);
        $stmtSimpan->execute();
        $stmtLog->bind_param('ssss', $key, $nilaiLama, $nilai, $oleh);
        $stmtLog->execute();
    }

    appSettingSemua(true);
    return [];
}
