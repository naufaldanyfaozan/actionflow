<?php

/**
 * Saklar notifikasi WA per nomor penerima, diatur Admin IT di admin_it/notifikasi.php.
 *
 * Nomor WA penerima tidak terikat ke akun user, tapi ke dept/peran di config/wa_map.php -
 * jadi yang dinonaktifkan adalah nomornya: semua WA (reminder & notifikasi aksi) ke nomor
 * itu dilewati di kirimWA() (services/whatsapp.php), nomor lain di pesan yang sama tetap
 * dikirimi. Saklar global WA tetap di app_setting 'wa_aktif'.
 *
 * Nomor nonaktif disimpan di tabel wa_nomor_nonaktif; setiap perubahan dicatat di
 * app_setting_log dengan setting_key 'wa_nomor:<nomor>' (nilai '1' aktif / '0' nonaktif).
 *
 * Admin IT juga bisa MENAMBAH nomor untuk suatu peran (tabel wa_nomor_tambahan). Nomor itu
 * digabung ke array di wa_map.php saat file itu di-include, jadi ikut menerima semua WA
 * peran tersebut tanpa mengubah kode. Dicatat di app_setting_log 'wa_tambah:<nomor>'.
 * Kunci peran: 'dept:<DEPT>:staffdept|depthead|planthead', 'var:<nama variabel wa_map>',
 * 'plant:<no plant>' (Plant Head per plant, $waMapPlantHead).
 */

require_once __DIR__ . '/app_setting_helper.php';

// Variabel nomor tunggal di wa_map.php yang bisa ditambah nomornya.
const WA_PERAN_VAR = [
    'waDirecture' => 'Directure',
    'waIMPHead1'  => 'Head IMP Plant 1',
    'waIMPHead3'  => 'Head IMP Plant 3',
    'waStaffIMP1' => 'Staff IMP Plant 1',
    'waStaffIMP3' => 'Staff IMP Plant 3',
];
const WA_PERAN_DEPT = ['staffdept' => 'Staff', 'depthead' => 'Dept Head', 'planthead' => 'Plant Head'];

function siapkanTabelWaTambahan(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap === null) {
        $siap = (bool) $koneksi->query("CREATE TABLE IF NOT EXISTS wa_nomor_tambahan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nomor VARCHAR(20) NOT NULL,
            peran VARCHAR(80) NOT NULL,
            nama VARCHAR(60) NULL,
            dibuat_oleh VARCHAR(50) NULL,
            dibuat_at DATETIME NULL,
            UNIQUE KEY uq_nomor_peran (nomor, peran)
        )");
    }
    return $siap;
}

// Semua nomor tambahan (sekali query per request). Koneksi dari $koneksi global.
function waNomorTambahan(bool $muatUlang = false): array
{
    static $cache = null;
    if ($cache !== null && !$muatUlang) {
        return $cache;
    }
    $koneksi = $GLOBALS['koneksi'] ?? null;
    if (!($koneksi instanceof mysqli) || !siapkanTabelWaTambahan($koneksi)) {
        return [];
    }
    $cache = [];
    $q = $koneksi->query("SELECT id, nomor, peran, nama, dibuat_oleh, dibuat_at FROM wa_nomor_tambahan ORDER BY peran, nomor");
    while ($q && ($r = $q->fetch_assoc())) {
        $cache[] = $r;
    }
    return $cache;
}

function waLabelPeran(string $peran): string
{
    $bagian = explode(':', $peran);
    if ($bagian[0] === 'dept' && count($bagian) === 3) {
        return WA_PERAN_DEPT[$bagian[2]] . ($bagian[2] === 'planthead' ? ' (dept ' . $bagian[1] . ')' : ' ' . $bagian[1]);
    }
    if ($bagian[0] === 'plant') {
        return 'Plant Head Plant ' . $bagian[1];
    }
    return WA_PERAN_VAR[$bagian[1] ?? ''] ?? $peran;
}

// Tambahkan nomor ke daftar yang sudah ada (buang slot kosong & dobel).
function waGabungNomor(array $daftar, array $tambahan): array
{
    return $tambahan ? array_values(array_unique(array_filter(array_merge($daftar, $tambahan)))) : $daftar;
}

// Dipanggil wa_map.php: gabungkan nomor tambahan ke $waMap (dept baru ikut dibuat).
function waGabungTambahanDept(array $waMap): array
{
    foreach (waNomorTambahan() as $r) {
        $bagian = explode(':', $r['peran']);
        if ($bagian[0] === 'dept' && count($bagian) === 3 && isset(WA_PERAN_DEPT[$bagian[2]])) {
            $waMap[$bagian[1]][$bagian[2]] = waGabungNomor($waMap[$bagian[1]][$bagian[2]] ?? [], [$r['nomor']]);
        }
    }
    return $waMap;
}

// Dipanggil wa_map.php: $var = ['waDirecture' => [...], ..., 'waMapPlantHead' => [...]].
function waGabungTambahanVar(array $var): array
{
    foreach (waNomorTambahan() as $r) {
        $bagian = explode(':', $r['peran']);
        if ($bagian[0] === 'var' && isset(WA_PERAN_VAR[$bagian[1] ?? ''])) {
            $var[$bagian[1]] = waGabungNomor($var[$bagian[1]] ?? [], [$r['nomor']]);
        } elseif ($bagian[0] === 'plant' && ($bagian[1] ?? '') !== '') {
            $var['waMapPlantHead'][$bagian[1]] = waGabungNomor($var['waMapPlantHead'][$bagian[1]] ?? [], [$r['nomor']]);
        }
    }
    return $var;
}

/**
 * Pilihan peran untuk form tambah nomor, dikelompokkan: judul grup => [kunci => label].
 * Dept diambil dari wa_map.php + master_dept (dept yang belum punya nomor ikut muncul).
 */
function waPilihanPeran(): array
{
    include __DIR__ . '/wa_map.php';

    $dept = array_keys($waMap ?? []);
    $koneksi = $GLOBALS['koneksi'] ?? null;
    if ($koneksi instanceof mysqli) {
        $q = $koneksi->query("SELECT nama_dept FROM master_dept WHERE nama_dept != 'All Dept'");
        while ($q && ($r = $q->fetch_assoc())) {
            $dept[] = trim($r['nama_dept']);
        }
    }
    $dept = array_unique(array_filter($dept));
    sort($dept);

    $pilihan = ['Manajemen & IMP' => []];
    foreach (WA_PERAN_VAR as $var => $label) {
        $pilihan['Manajemen & IMP']['var:' . $var] = $label;
    }
    foreach (array_keys($waMapPlantHead ?? []) as $plant) {
        $pilihan['Manajemen & IMP']['plant:' . $plant] = 'Plant Head Plant ' . $plant;
    }
    foreach ($dept as $d) {
        foreach (array_keys(WA_PERAN_DEPT) as $kunci) {
            $pilihan['Dept ' . $d]["dept:$d:$kunci"] = waLabelPeran("dept:$d:$kunci");
        }
    }
    return $pilihan;
}

// Validasi & normalisasi nomor input Admin IT. @return array{0: ?string, 1: ?string} [nomor, error]
function waValidasiNomorBaru(string $input): array
{
    $nomor = waNormalNomor($input);
    if (strpos($nomor, '8') === 0) {
        $nomor = '62' . $nomor;
    }
    return preg_match('/^628\d{7,12}$/', $nomor) ? [$nomor, null] : [null, 'Nomor WA tidak valid (contoh: 081234567890 atau 6281234567890).'];
}

function tambahWaNomor(mysqli $koneksi, string $nomor, string $peran, string $nama, string $oleh): ?string
{
    if (!siapkanTabelWaTambahan($koneksi) || !siapkanTabelAppSetting($koneksi)) {
        return 'Tabel nomor WA tidak bisa dibuat: ' . $koneksi->error;
    }
    $semuaPeran = array_merge(...array_values(waPilihanPeran()));
    if (!isset($semuaPeran[$peran])) {
        return 'Peran tidak dikenal.';
    }
    if (in_array(waLabelPeran($peran), waDaftarPenerima()[$nomor] ?? [], true)) {
        return "Nomor $nomor sudah terdaftar untuk " . waLabelPeran($peran) . '.';
    }

    $nama = mb_substr(trim($nama), 0, 60);
    $stmt = $koneksi->prepare("INSERT INTO wa_nomor_tambahan (nomor, peran, nama, dibuat_oleh, dibuat_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('ssss', $nomor, $peran, $nama, $oleh);
    if (!$stmt->execute()) {
        return $koneksi->errno === 1062 ? "Nomor $nomor sudah terdaftar untuk " . waLabelPeran($peran) . '.' : 'Gagal menyimpan: ' . $koneksi->error;
    }

    $key = 'wa_tambah:' . $nomor;
    $nilaiBaru = waLabelPeran($peran) . ($nama !== '' ? " ($nama)" : '');
    $log = $koneksi->prepare("INSERT INTO app_setting_log (setting_key, nilai_lama, nilai_baru, diubah_oleh) VALUES (?, NULL, ?, ?)");
    $log->bind_param('sss', $key, $nilaiBaru, $oleh);
    $log->execute();

    waNomorTambahan(true);
    return null;
}

function hapusWaNomor(mysqli $koneksi, int $id, string $oleh): ?string
{
    $baris = array_values(array_filter(waNomorTambahan(true), fn($r) => (int) $r['id'] === $id))[0] ?? null;
    if ($baris === null) {
        return 'Nomor tambahan tidak ditemukan.';
    }
    $stmt = $koneksi->prepare("DELETE FROM wa_nomor_tambahan WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $key = 'wa_tambah:' . $baris['nomor'];
    $nilaiLama = waLabelPeran($baris['peran']) . ($baris['nama'] !== null && $baris['nama'] !== '' ? " ({$baris['nama']})" : '');
    $nilaiBaru = '(dihapus)';
    $log = $koneksi->prepare("INSERT INTO app_setting_log (setting_key, nilai_lama, nilai_baru, diubah_oleh) VALUES (?, ?, ?, ?)");
    $log->bind_param('ssss', $key, $nilaiLama, $nilaiBaru, $oleh);
    $log->execute();

    waNomorTambahan(true);
    return null;
}

function siapkanTabelWaNonaktif(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap === null) {
        $siap = (bool) $koneksi->query("CREATE TABLE IF NOT EXISTS wa_nomor_nonaktif (
            nomor VARCHAR(20) PRIMARY KEY,
            diubah_oleh VARCHAR(50) NULL,
            diubah_at DATETIME NULL
        )");
    }
    return $siap;
}

// Samakan format nomor: hanya angka, awalan 0 -> 62 (format di wa_map.php).
function waNormalNomor(string $nomor): string
{
    $nomor = preg_replace('/\D/', '', $nomor);
    return strpos($nomor, '0') === 0 ? '62' . substr($nomor, 1) : $nomor;
}

// Daftar nomor yang dinonaktifkan (nomor => true). Koneksi dari $koneksi global.
function waNomorNonaktif(bool $muatUlang = false): array
{
    static $cache = null;
    if ($cache !== null && !$muatUlang) {
        return $cache;
    }
    $koneksi = $GLOBALS['koneksi'] ?? null;
    if (!($koneksi instanceof mysqli) || !siapkanTabelWaNonaktif($koneksi)) {
        return [];
    }
    $cache = [];
    $q = $koneksi->query("SELECT nomor FROM wa_nomor_nonaktif");
    while ($q && ($r = $q->fetch_assoc())) {
        $cache[$r['nomor']] = true;
    }
    return $cache;
}

// Buang nomor nonaktif dari target kirimWA() ("62xx,62yy"). Return '' kalau semua nonaktif.
function waSaringNomorAktif(string $target): string
{
    $nonaktif = waNomorNonaktif();
    $aktif = [];
    foreach (explode(',', $target) as $nomor) {
        $nomor = trim($nomor);
        if ($nomor === '') {
            continue;
        }
        if (isset($nonaktif[waNormalNomor($nomor)])) {
            error_log("WA SKIPPED (nomor dinonaktifkan Admin IT): to=$nomor");
            continue;
        }
        $aktif[] = $nomor;
    }
    return implode(',', $aktif);
}

/**
 * Semua nomor penerima di config/wa_map.php beserta perannya, untuk tabel di halaman Admin IT.
 * @return array<string, string[]> nomor => daftar peran (mis. "Dept Head PE")
 */
function waDaftarPenerima(): array
{
    include __DIR__ . '/wa_map.php';

    $daftar = [];
    $tambah = function (array $nomorList, string $peran) use (&$daftar) {
        foreach ($nomorList as $nomor) {
            $nomor = waNormalNomor((string) $nomor);
            if ($nomor !== '' && !in_array($peran, $daftar[$nomor] ?? [], true)) {
                $daftar[$nomor][] = $peran;
            }
        }
    };

    $labelPeran = ['staffdept' => 'Staff', 'depthead' => 'Dept Head', 'planthead' => 'Plant Head'];
    foreach ($waMap ?? [] as $dept => $peran) {
        foreach ($labelPeran as $kunci => $label) {
            // Plant Head sama untuk banyak dept - cukup satu label.
            $tambah($peran[$kunci] ?? [], $kunci === 'planthead' ? $label : "$label $dept");
        }
    }
    foreach ($waMapPlantHead ?? [] as $plant => $nomorList) {
        $tambah($nomorList, "Plant Head Plant $plant");
    }
    $tambah($waIMPHead1 ?? [], 'Head IMP Plant 1');
    $tambah($waIMPHead3 ?? [], 'Head IMP Plant 3');
    $tambah($waStaffIMP1 ?? [], 'Staff IMP Plant 1');
    $tambah($waStaffIMP3 ?? [], 'Staff IMP Plant 3');
    $tambah($waDirecture ?? [], 'Directure');

    ksort($daftar);
    return $daftar;
}

/**
 * Simpan daftar nomor nonaktif yang baru (nomor => true) untuk nomor-nomor di $nomorDiatur.
 * Hanya nomor yang statusnya berubah yang ditulis & dicatat di riwayat.
 */
function simpanWaNonaktif(mysqli $koneksi, array $nomorDiatur, array $nonaktifBaru, string $oleh): int
{
    if (!siapkanTabelWaNonaktif($koneksi) || !siapkanTabelAppSetting($koneksi)) {
        return 0;
    }
    $lama = waNomorNonaktif(true);

    $stmtNonaktif = $koneksi->prepare("INSERT INTO wa_nomor_nonaktif (nomor, diubah_oleh, diubah_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE diubah_oleh = VALUES(diubah_oleh), diubah_at = VALUES(diubah_at)");
    $stmtAktif = $koneksi->prepare("DELETE FROM wa_nomor_nonaktif WHERE nomor = ?");
    $stmtLog = $koneksi->prepare("INSERT INTO app_setting_log (setting_key, nilai_lama, nilai_baru, diubah_oleh) VALUES (?, ?, ?, ?)");

    $berubah = 0;
    foreach ($nomorDiatur as $nomor) {
        $nomor = waNormalNomor((string) $nomor);
        if ($nomor === '' || isset($lama[$nomor]) === isset($nonaktifBaru[$nomor])) {
            continue;
        }
        if (isset($nonaktifBaru[$nomor])) {
            $stmtNonaktif->bind_param('ss', $nomor, $oleh);
            $stmtNonaktif->execute();
            [$nilaiLama, $nilaiBaru] = ['1', '0'];
        } else {
            $stmtAktif->bind_param('s', $nomor);
            $stmtAktif->execute();
            [$nilaiLama, $nilaiBaru] = ['0', '1'];
        }
        $key = 'wa_nomor:' . $nomor;
        $stmtLog->bind_param('ssss', $key, $nilaiLama, $nilaiBaru, $oleh);
        $stmtLog->execute();
        $berubah++;
    }

    waNomorNonaktif(true);
    return $berubah;
}
