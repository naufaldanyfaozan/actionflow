<?php
require_once __DIR__ . '/../config/auth_helper.php';
wajibLogin();

include '../koneksi.php';

$keyword = sqlAman($_GET['keyword'] ?? '');

$query = mysqli_query($koneksi,"
    SELECT
        partnumber,
        namapart,
        type
    FROM master_part
    WHERE
        namapart LIKE '%$keyword%'
        OR partnumber LIKE '%$keyword%'
    LIMIT 20
");

$data = [];

while($row = mysqli_fetch_assoc($query)){
    $data[] = $row;
}

echo json_encode($data);