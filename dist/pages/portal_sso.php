<?php

// Jembatan Single Sign-On dari portal productionflow ke FPM. User harus sudah
// login di portal DAN dicentang aksesnya ke modul "fpm" lewat admin_akses.php.
// Session FPM di-set dari data akun FPM ASLI (tabel `user` di database fpm) -
// bukan data portal - supaya dept/plant/level yang dipakai fitur-fitur FPM
// tetap konsisten dengan yang selama ini berjalan (login manual).

session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/productionflow/config/portal_db.php';

if (!isset($_SESSION['pf_username'])) {
	header("location: ../../../index.php");
	exit;
}

$pfUsername = $_SESSION['pf_username'];

$portalDb = portalDbConnect();
if (!$portalDb) {
	die("Koneksi database portal gagal");
}

$akses = portalResolveModuleAccess($portalDb, $pfUsername, 'fpm');

if (!$akses['granted']) {
	header("location: ../../../dashboard.php?sso_error=akses_belum_diatur&modul=FPM");
	exit;
}

$targetUsername = $akses['target_username'];

include 'koneksi.php'; // $koneksi ke database fpm

$stmt = $koneksi->prepare("SELECT * FROM user WHERE username = ?");
$stmt->bind_param("s", $targetUsername);
$stmt->execute();
$fpmUser = $stmt->get_result()->fetch_assoc();

if (!$fpmUser) {
	header("location: ../../../dashboard.php?sso_error=akun_belum_ada&modul=FPM");
	exit;
}

portalClearOtherModuleSessionKeys();

$_SESSION['username'] = $fpmUser['username'];
$_SESSION['level']    = $fpmUser['level'];
$_SESSION['dept']     = $fpmUser['dept'];
$_SESSION['plant']    = $fpmUser['plant'];

switch ($fpmUser['level']) {
	case 'Staff IMP':
		header("location: staff_imp/index.php");
		break;
	case 'Staff':
		header("location: staff/pilih_modul.php");
		break;
	case 'Head IMP':
		header("location: head_imp/index.php");
		break;
	case 'Dept Head':
		header("location: dept_head/pilih_modul.php");
		break;
	case 'Plant Head':
		header("location: plant_head/pilih_modul.php");
		break;
	case 'Dept Head QA':
		header("location: dept_head_qa/index.php");
		break;
	case 'Vice Directure':
		header("location: vice_directur/pilih_modul.php");
		break;
	case 'Directure':
		header("location: directur/pilih_modul.php");
		break;
	case 'Admin IT':
		header("location: admin_it/index.php");
		break;
	default:
		header("location: ../../../dashboard.php?sso_error=level_tidak_dikenal&modul=FPM");
}
exit;
