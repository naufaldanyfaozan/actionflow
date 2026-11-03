<?php

include '../koneksi.php';

$keyword = $_GET['keyword'] ?? '';

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