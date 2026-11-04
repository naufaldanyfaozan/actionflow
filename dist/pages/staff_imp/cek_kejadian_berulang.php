<?php
require_once __DIR__ . '/../config/auth_helper.php';
wajibLogin();
include '../koneksi.php';

$jenis_fpm     = $_POST['jenis_fpm'] ?? '';
$nama_part     = $_POST['nama_part'] ?? '';
$machine       = $_POST['machine'] ?? '';
$mold_no       = $_POST['mold_no'] ?? '';

$jenis_masalah = $_POST['jenis_masalah'] ?? '';

$jenis_fpm     = mysqli_real_escape_string($koneksi, $jenis_fpm);
$nama_part     = mysqli_real_escape_string($koneksi, $nama_part);
$machine       = mysqli_real_escape_string($koneksi, $machine);
$mold_no       = mysqli_real_escape_string($koneksi, $mold_no);

$jenis_masalah = mysqli_real_escape_string($koneksi, $jenis_masalah);

// Problem berulang = Nama Part + Mold # + Jenis Masalah sama
$whereCondition = "
    TRIM(nama_part) = TRIM('$nama_part')
    AND TRIM(mold_no) = TRIM('$mold_no')
    AND TRIM(jenis_masalah) = TRIM('$jenis_masalah')
";

$queryMax = "SELECT MAX(kejadian_berulang_ke) as max_ke FROM fpm_header WHERE $whereCondition";
$dataMax = mysqli_fetch_assoc(mysqli_query($koneksi, $queryMax));

$max_ke = $dataMax['max_ke'];

if ($max_ke === null) {
    $next = 0;
} else {
    $next = $max_ke + 1;
}

$queryLast = "SELECT no_reg_fpm, tgl_ditemukan
FROM fpm_header
WHERE $whereCondition
ORDER BY tgl_ditemukan DESC
LIMIT 1";

$dataLast = mysqli_fetch_assoc(mysqli_query($koneksi, $queryLast));

echo json_encode([
    "next" => $next,
    "last_date" => $dataLast['tgl_ditemukan'] ?? null,
    "last_no_reg_fpm" => $dataLast['no_reg_fpm'] ?? null
]);
