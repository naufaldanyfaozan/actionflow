<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
if (!isset($_SESSION["username"])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Belum login']);
    exit;
}
if (($_SESSION['level'] ?? '') !== 'Staff IMP') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}
csrfWajibValid();

include '../koneksi.php';

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['ids'] ?? [];

if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'Data urutan kosong']);
    exit;
}

$stmt = $koneksi->prepare("UPDATE asakai_header SET urutan = ? WHERE id_asakai = ?");

$urutan = 1;
foreach ($ids as $id) {
    $id = (int) $id;
    if ($id <= 0) continue;

    $stmt->bind_param("ii", $urutan, $id);
    $stmt->execute();
    $urutan++;
}

echo json_encode(['success' => true]);
