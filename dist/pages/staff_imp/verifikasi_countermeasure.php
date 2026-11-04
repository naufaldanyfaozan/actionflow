<?php
/**
 * Verifikasi Countermeasure Staff IMP kini tampil di menu "Tugas & Approval" › Tugas Saya
 * (config/verifikasi_imp_view.php). Halaman ini tinggal memproses "Tandai sudah dicek" lalu
 * kembali ke sana; link lama ke halaman ini otomatis diarahkan ke Tugas Saya.
 */
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';
require_once '../config/verifikasi_countermeasure_helper.php';
require_once '../config/countermeasure_revisi_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$username = $_SESSION['username'];
$plantFilter = resolveVerifikasiPlant($username, $_GET['plant'] ?? null);
$kembali = 'tugas_saya.php' . ($plantFilter !== '' ? '?plant=' . urlencode($plantFilter) : '') . '#verifikasi';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tandai_revisi_dicek'])) {
    $hasil = tandaiRevisiDicekImp(
        $koneksi,
        ($_POST['sumber'] ?? '') === 'ASAKAI' ? 'ASAKAI' : 'FPM',
        (int) ($_POST['id_revisi'] ?? 0),
        $username
    );
    echo "<script>alert(" . json_encode($hasil['pesan']) . "); window.location=" . json_encode($kembali) . ";</script>";
    exit;
}

header('Location: ' . $kembali);
exit;
