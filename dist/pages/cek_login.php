<?php

session_start();

include 'koneksi.php';

$username = $_POST['username'];
$password = $_POST['password'];

$stmt = mysqli_prepare($koneksi, "SELECT * FROM user WHERE username=? AND password=?");
mysqli_stmt_bind_param($stmt, "ss", $username, $password);
mysqli_stmt_execute($stmt);
$login = mysqli_stmt_get_result($stmt);
$cek = mysqli_num_rows($login);

if ($cek > 0) {

	$data = mysqli_fetch_assoc($login);

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
	header("location:index.html?login_error=1");
}
