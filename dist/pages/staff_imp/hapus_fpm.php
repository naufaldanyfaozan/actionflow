<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

if (($_SESSION['level'] ?? '') !== 'Staff IMP') {
    die('Halaman ini hanya untuk Staff IMP.');
}

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/hapus_fpm_helper.php';

$id = (int) ($_GET['id'] ?? 0);

$allowedRedirect = ['list_fpm_internal.php', 'list_fpm_downtime.php'];
$redirect = $_GET['back'] ?? 'list_fpm_internal.php';

if (!in_array($redirect, $allowedRedirect, true)) {
    $redirect = 'list_fpm_internal.php';
}

if ($id > 0) {
    hapusFpmLengkap($koneksi, $id);
}

header("Location: $redirect");
exit;
