<?php
/**
 * Verifikasi Countermeasure Staff QA kini tampil di menu "Tugas & Approval" › Tugas Saya
 * (config/verifikasi_qa_view.php). Halaman ini tinggal memproses "Tandai sudah dicek" /
 * "Tandai sudah dibaca" lalu kembali ke sana; link lama ke halaman ini (mis. dari
 * update_countermeasure_qa.php) otomatis diarahkan ke Tugas Saya.
 */
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

if (($_SESSION['dept'] ?? '') !== 'QA') {
    die('Akses ditolak: fitur Verifikasi Countermeasure hanya untuk Staff QA.');
}

include '../koneksi.php';
require_once '../config/verifikasi_countermeasure_helper.php';
require_once '../config/countermeasure_revisi_helper.php';
require_once '../config/fpm_reschedule_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$kembali = 'tugas_saya.php?modul=qa#verifikasi';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tandai_revisi_dicek'])) {
    $hasil = tandaiRevisiDicekImp($koneksi, 'FPM', (int) ($_POST['id_revisi'] ?? 0), $_SESSION['username'], CM_REVISI_ROLE_QA);
    echo "<script>alert(" . json_encode($hasil['pesan']) . "); window.location=" . json_encode($kembali) . ";</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tandai_reschedule_dibaca'])) {
    $hasil = tandaiRescheduleDibaca($koneksi, (int) ($_POST['id_reschedule'] ?? 0), $_SESSION['username'], true);
    echo "<script>alert(" . json_encode($hasil['pesan']) . "); window.location=" . json_encode($kembali) . ";</script>";
    exit;
}

header('Location: ' . $kembali);
exit;
