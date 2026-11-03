<?php
// Deteksi otomatis local (XAMPP/Windows) vs hosting (Linux) berdasarkan OS,
// supaya kredensial yang salah untuk environment saat ini tidak pernah
// benar-benar dicoba (jadi tidak akan pernah memicu warning connect gagal).
// Dengan begini file ini tidak perlu diubah lagi saat pindah antara local dan hosting.
// Nilai kredensialnya ada di config/db_config.php (satu tempat untuk seluruh modul FPM).
mysqli_report(MYSQLI_REPORT_OFF);

// db_config.php tidak masuk git (berisi password) - dibuat dari config/db_config.example.php.
// Di dalam productionflow file itu tidak ikut subtree fpm/, jadi kredensial langsung diambil
// dari config pusat productionflow (sama seperti "kait" di dalam db_config.php).
$dbConfigFile = __DIR__ . '/config/db_config.php';
$pfDbConfig   = __DIR__ . '/../../../config/productionflow_db.php';

if (is_file($dbConfigFile)) {
	$dbConfigFpm = require $dbConfigFile;
} elseif (is_file($pfDbConfig)) {
	require_once $pfDbConfig;
	$pfDbFpm = productionflowDb('fpm');
	$dbConfigFpm = ['local' => $pfDbFpm, 'hosting' => $pfDbFpm];
} else {
	// Tanpa cek ini, file config yang belum diupload ke hosting hanya muncul sebagai HTTP 500 kosong.
	die('Konfigurasi database tidak ditemukan: buat file dist/pages/config/db_config.php (contoh: db_config.example.php) lalu upload ke server.');
}

$kredensialLocal   = array_values($dbConfigFpm['local']);
$kredensialHosting = array_values($dbConfigFpm['hosting']);

$kredensialUtama    = (PHP_OS_FAMILY === 'Windows') ? $kredensialLocal : $kredensialHosting;
$kredensialCadangan = (PHP_OS_FAMILY === 'Windows') ? $kredensialHosting : $kredensialLocal;

$koneksi = @mysqli_connect(...$kredensialUtama);

if (!$koneksi) {
	$koneksi = @mysqli_connect(...$kredensialCadangan);
}

// Check connection
if (mysqli_connect_errno()) {
	echo "Koneksi database gagal : " . mysqli_connect_error();
}

define('MAX_VIDEO_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
const VIDEO_FILE_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];

function isVideoUploadTooLarge($fileName, $fileSize)
{
	$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
	return in_array($ext, VIDEO_FILE_EXTENSIONS) && $fileSize > MAX_VIDEO_UPLOAD_SIZE;
}

// Pesan untuk kode error $_FILES[...]['error']. null = tidak ada masalah (sukses / input file kosong).
// Dipakai supaya upload yang gagal di server (mis. foto melebihi upload_max_filesize hosting)
// tidak diam-diam dilewati sementara actual_date tetap terisi.
function pesanErrorUpload($errorCode, $label)
{
	$errorCode = (int) $errorCode;
	if ($errorCode === UPLOAD_ERR_OK || $errorCode === UPLOAD_ERR_NO_FILE) return null;

	if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
		return "File pada $label melebihi batas ukuran upload server (maks " . ini_get('upload_max_filesize') . "). Kecilkan/kompres file lalu upload ulang.";
	}
	if ($errorCode === UPLOAD_ERR_PARTIAL) {
		return "File pada $label hanya terupload sebagian (koneksi terputus). Silakan upload ulang.";
	}
	return "Server gagal menerima file pada $label (kode error $errorCode). Silakan coba lagi atau hubungi admin.";
}
