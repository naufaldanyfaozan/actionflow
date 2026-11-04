<?php
/**
 * Login aman: password di-hash (password_hash / password_verify) + pembatasan percobaan gagal.
 *
 * Migrasi bertahap - TIDAK perlu reset password semua user:
 *  - Kolom user.password diperlebar ke VARCHAR(255) otomatis (siapkanKolomPasswordHash()).
 *  - Akun yang password-nya masih teks biasa tetap bisa login (dibandingkan oleh MySQL,
 *    sama seperti sebelumnya), lalu saat itu juga password-nya diganti dengan hash.
 *  - Akun baru yang ditambahkan manual lewat phpMyAdmin dengan password teks biasa ikut
 *    ter-hash otomatis saat login pertama.
 *
 * Percobaan gagal dicatat di tabel login_gagal. Batas & lama kunci diatur Admin IT
 * (grup "Keamanan Login": login_maks_gagal, login_kunci_menit).
 * Struktur tabel: config/migration_2026_11_04_keamanan_login.sql
 */

require_once __DIR__ . '/app_setting_helper.php';

/** Lebarkan kolom password supaya muat hash (60+ karakter). true = kolom sudah siap. */
function siapkanKolomPasswordHash(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap !== null) {
        return $siap;
    }
    $q = $koneksi->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'password'");
    $panjang = $q ? (int) ($q->fetch_row()[0] ?? 0) : 0;
    if ($panjang >= 255) {
        return $siap = true;
    }
    $siap = (bool) $koneksi->query("ALTER TABLE `user` MODIFY `password` VARCHAR(255) NOT NULL");
    if (!$siap) {
        error_log('Login: kolom user.password gagal diperlebar: ' . $koneksi->error);
    }
    return $siap;
}

function siapkanTabelLoginGagal(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap === null) {
        $siap = (bool) $koneksi->query("CREATE TABLE IF NOT EXISTS login_gagal (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                waktu DATETIME NOT NULL,
                KEY idx_username_waktu (username, waktu),
                KEY idx_ip_waktu (ip, waktu)
            )");
    }
    return $siap;
}

/** true kalau akun ini (atau IP ini) sedang dikunci karena terlalu banyak gagal. */
function loginSedangDikunci(mysqli $koneksi, string $username, string $ip): bool
{
    if (!siapkanTabelLoginGagal($koneksi)) {
        return false;
    }
    $maks = appSettingInt('login_maks_gagal');
    $menit = appSettingInt('login_kunci_menit');
    $batas = date('Y-m-d H:i:s', time() - $menit * 60);

    $stmt = $koneksi->prepare("SELECT
            SUM(username = ?) AS gagal_akun,
            SUM(ip = ?) AS gagal_ip
        FROM login_gagal WHERE waktu >= ? AND (username = ? OR ip = ?)");
    $stmt->bind_param('sssss', $username, $ip, $batas, $username, $ip);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    return (int) ($r['gagal_akun'] ?? 0) >= $maks || (int) ($r['gagal_ip'] ?? 0) >= $maks * 4;
}

function loginCatatGagal(mysqli $koneksi, string $username, string $ip): void
{
    if (!siapkanTabelLoginGagal($koneksi)) {
        return;
    }
    $waktu = date('Y-m-d H:i:s');
    $username = mb_substr($username, 0, 50);
    $stmt = $koneksi->prepare("INSERT INTO login_gagal (username, ip, waktu) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $username, $ip, $waktu);
    $stmt->execute();
    // Catatan lama (> 1 hari) tidak dipakai lagi
    $koneksi->query("DELETE FROM login_gagal WHERE waktu < NOW() - INTERVAL 1 DAY");
}

function loginBersihkanGagal(mysqli $koneksi, string $username): void
{
    if (!siapkanTabelLoginGagal($koneksi)) {
        return;
    }
    $stmt = $koneksi->prepare("DELETE FROM login_gagal WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
}

/** Baris user kalau username + password cocok, null kalau tidak. */
function loginVerifikasi(mysqli $koneksi, string $username, string $password): ?array
{
    $kolomSiap = siapkanKolomPasswordHash($koneksi);

    $stmt = $koneksi->prepare("SELECT * FROM user WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $tersimpan = (string) $row['password'];
        $id = (int) $row['id'];

        if (!empty(password_get_info($tersimpan)['algo'])) {
            if (!password_verify($password, $tersimpan)) {
                continue;
            }
            if ($kolomSiap && password_needs_rehash($tersimpan, PASSWORD_DEFAULT)) {
                loginSimpanHash($koneksi, $id, $password);
            }
            return $row;
        }

        // Password lama (teks biasa): dibandingkan oleh MySQL seperti sebelumnya, lalu di-hash
        $cek = $koneksi->prepare("SELECT 1 FROM user WHERE id = ? AND password = ?");
        $cek->bind_param('is', $id, $password);
        $cek->execute();
        if (!$cek->get_result()->fetch_row()) {
            continue;
        }
        if ($kolomSiap) {
            loginSimpanHash($koneksi, $id, $password);
        }
        return $row;
    }
    return null;
}

function loginSimpanHash(mysqli $koneksi, int $id, string $password): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $koneksi->prepare("UPDATE user SET password = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $id);
    if (!$stmt->execute()) {
        error_log('Login: gagal menyimpan hash password user id ' . $id . ': ' . $stmt->error);
    }
}
