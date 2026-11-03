<?php
// Tombol "Perlu FPM" di pembahasan Asakai (form_pembahasan_asakai.php): buat sekarang /
// buat nanti / batalkan. Lihat config/asakai_fpm_helper.php.
session_start();
if (!isset($_SESSION["username"])) {
    header("location:cek_login.php");
    exit;
}

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/asakai_fpm_helper.php';

$idAsakai = (int) ($_POST['id_asakai'] ?? 0);
$jenis    = (string) ($_POST['jenis'] ?? '');
$aksi     = (string) ($_POST['aksi'] ?? '');
$user     = (string) $_SESSION['username'];
$kembali  = 'form_pembahasan_asakai.php?id=' . $idAsakai . '#fpm-asakai';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset(ASAKAI_FPM_JENIS[$jenis])) {
    header('Location: ' . $kembali);
    exit;
}

if ($aksi === 'batal') {
    $error = batalkanAsakaiFpm($koneksi, $idAsakai, $jenis, $user, (string) ($_POST['alasan'] ?? ''));
    $_SESSION['flash_asakai_fpm'] = $error !== null
        ? ['tipe' => 'danger', 'pesan' => $error]
        : ['tipe' => 'success', 'pesan' => 'Tugas FPM ' . ASAKAI_FPM_JENIS[$jenis]['label'] . ' dibatalkan.'];
    header('Location: ' . $kembali);
    exit;
}

$cara  = $aksi === 'langsung' ? 'langsung' : 'nanti';
$error = tandaiAsakaiPerluFpm($koneksi, $idAsakai, $jenis, $cara, $user);

if ($error === null && $cara === 'langsung') {
    header('Location: ' . ASAKAI_FPM_JENIS[$jenis]['form'] . '?asakai=' . $idAsakai);
    exit;
}

$_SESSION['flash_asakai_fpm'] = $error !== null
    ? ['tipe' => 'danger', 'pesan' => $error]
    : ['tipe' => 'success', 'pesan' => 'Dicatat: FPM ' . ASAKAI_FPM_JENIS[$jenis]['label'] . ' perlu dibuat. Pengingat muncul di Dashboard sampai FPM-nya dibuat.'];
header('Location: ' . $kembali);
exit;
