<?php
/**
 * Helper keamanan bersama (tanpa session) - dimuat otomatis dari koneksi.php & auth_helper.php.
 *
 *  - pesanErrorDb()        : detail error database hanya ke error_log, user melihat pesan umum
 *  - inputBulan()/inputTanggal()/inputPlant()/sqlAman() : validasi nilai filter dari URL/form
 *  - pindahkanUploadAman() : pengganti move_uploaded_file() dengan whitelist ekstensi + cek MIME
 *  - buatFolderUpload()    : mkdir folder upload dengan izin 0755 (bukan 0777)
 */

if (!function_exists('pesanErrorDb')) {

    /**
     * Catat detail error DB ke log server, kembalikan pesan umum untuk ditampilkan ke user.
     * @param string $konteks mis. "Gagal menyimpan data" (tanpa detail teknis)
     * @param mixed  $sumber  mysqli / mysqli_stmt / string detail error
     */
    function pesanErrorDb(string $konteks, $sumber = null): string
    {
        $detail = '';
        if ($sumber instanceof mysqli || $sumber instanceof mysqli_stmt) {
            $detail = $sumber->error;
        } elseif (is_string($sumber)) {
            $detail = $sumber;
        }
        $ref = substr(bin2hex(random_bytes(3)), 0, 6);
        $lokasi = $_SERVER['SCRIPT_NAME'] ?? (PHP_SAPI === 'cli' ? 'cli' : '-');
        error_log("[FPM DB ref:$ref] $lokasi - $konteks: $detail");
        return "$konteks. Silakan coba lagi atau hubungi Admin IT (ref: $ref).";
    }

    // ---------- Validasi nilai input yang masuk ke SQL ----------

    /** Nilai bulan format Y-m dari $_GET[$key]; tidak valid / kosong = bulan ini. */
    function inputBulan(string $key = 'bulan'): string
    {
        $v = $_GET[$key] ?? '';
        return (is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) ? $v : date('Y-m');
    }

    /** Nilai tanggal format Y-m-d; tidak valid / kosong = $default. */
    function inputTanggal($nilai, string $default): string
    {
        if (!is_string($nilai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return $default;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $nilai));
        return checkdate($m, $d, $y) ? $nilai : $default;
    }

    /** Kode plant di tabel hanya angka ('1', '3'); selain angka dibuang. */
    function inputPlant($nilai): string
    {
        return is_scalar($nilai) ? preg_replace('/\D/', '', (string) $nilai) : '';
    }

    /** Escape string untuk SQL lama yang belum memakai prepared statement. */
    function sqlAman($nilai): string
    {
        $nilai = is_scalar($nilai) ? (string) $nilai : '';
        $koneksi = $GLOBALS['koneksi'] ?? null;
        if ($koneksi instanceof mysqli) {
            return mysqli_real_escape_string($koneksi, $nilai);
        }
        // Belum ada koneksi: escape setara mysqli_real_escape_string untuk charset utf8mb4
        return strtr($nilai, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"]);
    }

    // ---------- Upload file ----------

    // Gabungan semua format yang memang dipakai fitur upload FPM / Asakai / logo.
    define('UPLOAD_EXT_AMAN', [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv', 'm4v',
    ]);

    /**
     * true kalau isi file sesuai ekstensinya. Ekstensi harus ada di UPLOAD_EXT_AMAN, lalu MIME
     * hasil deteksi isi file (fileinfo) dicocokkan per kelompok - file script/HTML yang
     * di-rename jadi .jpg / .pdf ditolak.
     */
    function uploadIsiSesuai(string $tmpPath, string $namaTujuan): bool
    {
        $ext = strtolower(pathinfo($namaTujuan, PATHINFO_EXTENSION));
        if (!in_array($ext, UPLOAD_EXT_AMAN, true)) {
            return false;
        }
        if (!function_exists('finfo_open') || !is_file($tmpPath)) {
            return true; // tanpa ekstensi fileinfo hanya whitelist ekstensi yang berlaku
        }
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string) finfo_file($fi, $tmpPath) : '';
        if ($fi) {
            finfo_close($fi);
        }
        if ($mime === '') {
            return true;
        }

        // Isi yang bisa dieksekusi / dirender sebagai halaman selalu ditolak
        if (preg_match('~(php|html|javascript|x-sh|shellscript|x-executable|x-dosexec|x-msdownload|x-perl|x-python)~i', $mime)) {
            return false;
        }

        $kelompok = [
            'gambar' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'pdf'    => ['pdf'],
            'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
            'video'  => ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv', 'm4v'],
        ];
        if (in_array($ext, $kelompok['gambar'], true)) {
            return strpos($mime, 'image/') === 0;
        }
        if (in_array($ext, $kelompok['pdf'], true)) {
            return in_array($mime, ['application/pdf', 'application/x-pdf'], true);
        }
        if (in_array($ext, $kelompok['office'], true)) {
            // docx/xlsx = zip, doc/xls lama = CDF; beberapa server melaporkan octet-stream
            return (bool) preg_match('~^application/(vnd\.|msword|zip|x-zip|octet-stream|cdfv2|x-ole-storage|encrypted)~i', $mime);
        }
        // video
        return strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0
            || in_array($mime, ['application/octet-stream', 'application/x-matroska', 'application/vnd.rn-realmedia'], true);
    }

    /**
     * Pengganti move_uploaded_file(): menolak file yang ekstensi/isinya tidak diizinkan.
     * Signature sama, jadi pemanggil lama cukup ganti nama fungsi - jalur "gagal simpan"
     * yang sudah ada di tiap pemanggil tetap dipakai.
     */
    function pindahkanUploadAman(string $tmpPath, string $tujuan): bool
    {
        if (!uploadIsiSesuai($tmpPath, $tujuan)) {
            error_log('[FPM upload] ditolak (format/isi tidak sesuai): ' . basename($tujuan));
            return false;
        }
        return move_uploaded_file($tmpPath, $tujuan);
    }

    /** Buat folder upload bila belum ada (izin 0755). */
    function buatFolderUpload(string $folder): bool
    {
        return is_dir($folder) || mkdir($folder, 0755, true);
    }
}
