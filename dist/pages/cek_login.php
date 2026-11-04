<?php

require_once __DIR__ . '/config/auth_helper.php';
mulaiSessionAman();

include 'koneksi.php';
require_once __DIR__ . '/config/login_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header("location:index.html");
	exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if ($username === '' || $password === '') {
	header("location:index.html?login_error=1");
	exit;
}

// Terlalu banyak percobaan gagal -> tolak tanpa memeriksa password (lihat config/login_helper.php)
if (loginSedangDikunci($koneksi, $username, $ip)) {
	header("location:index.html?login_error=terkunci");
	exit;
}

// Password diverifikasi dengan hash; password lama (teks biasa) otomatis di-hash saat login
$data = loginVerifikasi($koneksi, $username, $password);

if ($data !== null) {

	loginBersihkanGagal($koneksi, $username);

	// ID session baru setelah login (cegah session fixation) + token CSRF baru
	session_regenerate_id(true);
	unset($_SESSION['csrf_token']);

	$_SESSION['username'] 	= $data['username'];
	$_SESSION['level']    	= $data['level'];
	$_SESSION['dept']     	= $data['dept'];
	$_SESSION['plant'] 		= $data['plant'];

	if ($data['level'] == "Staff IMP") { //Staff IMP

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Staff IMP";

		header("location:staff_imp/index.php");
	} else if ($data['level'] == "Staff") { // Staff

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Staff";

		header("location:staff/pilih_modul.php");
	} else if ($data['level'] == "Head IMP") { // Head IMP

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Head IMP";

		header("location:head_imp/index.php");
	} else if ($data['level'] == "Dept Head") { // Dept Head

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Dept Head";

		header("location:dept_head/pilih_modul.php");
	} else if ($data['level'] == "Plant Head") { // Plant Head

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Plant Head";

		header("location:plant_head/pilih_modul.php");
	} else if ($data['level'] == "Dept Head QA") { // Dept Head QA

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Dept Head QA";

		header("location:dept_head_qa/index.php");
	} else if ($data['level'] == "Vice Directure") { // Vice Directur

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Vice Directure";

		header("location:vice_directur/pilih_modul.php");
	} else if ($data['level'] == "Directure") { // Directur

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Directure";

		header("location:directur/pilih_modul.php");
	} else if ($data['level'] == "Admin IT") { // Admin IT - pengaturan aturan aplikasi

		$_SESSION['username'] = $username;
		$_SESSION['level'] = "Admin IT";

		header("location:admin_it/index.php");
	} else {
		echo "<script>alert('Username belum terdaftar / Koreksi kembali kesesuaian Username dan Password anda ! ');
	document.location = 'index.php';
	</script>";
	}
} else {
	loginCatatGagal($koneksi, $username, $ip);
	header("location:index.html?login_error=1");
}
