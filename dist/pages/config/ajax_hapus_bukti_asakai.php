<?php

/**
 * Endpoint tombol hapus file bukti countermeasure Asakai (salah upload) - lihat
 * hapusFileBuktiAsakai() di config/asakai_bukti_helper.php.
 * POST: jenis (dt/ng), id_cm, file. Hanya Dept Head dan Staff IMP.
 */

session_start();
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

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/asakai_bukti_helper.php';

if (!asakaiBolehHapusBukti()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'pesan' => 'Hanya Dept Head dan Staff IMP yang bisa menghapus file.']);
    exit;
}

echo json_encode(hapusFileBuktiAsakai(
    $koneksi,
    (string) ($_POST['jenis'] ?? ''),
    (int) ($_POST['id_cm'] ?? 0),
    (string) ($_POST['file'] ?? ''),
    __DIR__ . '/../uploads/asakai/'
));
