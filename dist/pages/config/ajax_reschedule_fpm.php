<?php

/**
 * Endpoint tombol "Reschedule" countermeasure FPM - lihat config/fpm_reschedule_helper.php.
 * POST: id_fpm, jenis (CORRECTIVE/PREVENTIVE/DOKUMEN), id_action, due_date_baru, alasan,
 * file bukti[0][] (opsional, wajib kalau due_date_baru = hari ini; actual_date otomatis = hari ini).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'pesan' => 'Sesi login habis, silakan login ulang.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'pesan' => 'Metode tidak diizinkan.']);
    exit;
}

if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    echo json_encode(['ok' => false, 'pesan' => 'Upload gagal: total ukuran file terlalu besar untuk server (maks ' . ini_get('post_max_size') . '). Kurangi ukuran/jumlah file lalu coba lagi.']);
    exit;
}

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/fpm_reschedule_helper.php';

$hasil = rescheduleCountermeasureFpm(
    $koneksi,
    (int) ($_POST['id_fpm'] ?? 0),
    (string) ($_POST['jenis'] ?? ''),
    (int) ($_POST['id_action'] ?? 0),
    (string) ($_POST['due_date_baru'] ?? ''),
    (string) ($_POST['alasan'] ?? ''),
    (string) $_SESSION['username'],
    (string) ($_SESSION['level'] ?? ''),
    (string) ($_SESSION['dept'] ?? ''),
    ambilUploadBuktiAksi('bukti', 0)
);

echo json_encode($hasil);
