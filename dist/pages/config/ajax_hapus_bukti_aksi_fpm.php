<?php

/**
 * Endpoint tombol hapus file bukti countermeasure FPM (salah upload) - lihat
 * hapusFileBuktiAksi() di config/fpm_bukti_aksi_helper.php.
 * POST: id_fpm, jenis (corrective/preventive/dokumen), id_aksi, file.
 * Hanya Dept Head, Staff IMP dan Staff QA.
 */

require_once __DIR__ . '/auth_helper.php';
mulaiSessionAman();
header('Content-Type: application/json; charset=utf-8');

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

$level = (string) ($_SESSION['level'] ?? '');
$dept = strtoupper(trim((string) ($_SESSION['dept'] ?? '')));
if (!in_array($level, ['Dept Head', 'Staff IMP'], true) && !($level === 'Staff' && $dept === 'QA')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'pesan' => 'Hanya Dept Head, Staff IMP dan Staff QA yang bisa menghapus file.']);
    exit;
}

// Token CSRF dikirim otomatis (header X-CSRF-Token) oleh skrip auth_helper di halaman pemanggil.
csrfWajibValid();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/fpm_bukti_aksi_helper.php';

echo json_encode(hapusFileBuktiAksi(
    $koneksi,
    (int) ($_POST['id_fpm'] ?? 0),
    (string) ($_POST['jenis'] ?? ''),
    (int) ($_POST['id_aksi'] ?? 0),
    (string) ($_POST['file'] ?? ''),
    __DIR__ . '/../uploads/fpm_dt/'
));
