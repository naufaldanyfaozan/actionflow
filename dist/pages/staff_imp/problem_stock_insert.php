<?php

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "database_monitor"
);

$tanggal          = $_POST['tanggal'];
$part_number      = $_POST['part_number'];
$nama_part        = $_POST['nama_part'];
$act_stock        = $_POST['act_stock'];
$delivery_per_hari = $_POST['delivery_per_hari'];
$sisa_stock       = $_POST['sisa_stock'];
$news_delivery    = $_POST['news_delivery'];
$countermeasure   = $_POST['countermeasure'];
$due_date         = $_POST['due_date'];
$pic              = $_POST['pic'];

$sql = "INSERT INTO fpm_delivery_monitor
(
    tanggal,
    part_number,
    nama_part,
    stock_casting,
    act_stock,
    delivery_per_hari,
    sisa_stock,
    news_delivery,
    countermeasure,
    due_date,
    pic
)
VALUES
(
    '$tanggal',
    '$part_number',
    '$nama_part',
    '0',
    '$act_stock',
    '$delivery_per_hari',
    '$sisa_stock',
    '$news_delivery',
    '$countermeasure',
    '$due_date',
    '$pic'
)
";

$conn->query($sql);

echo json_encode([
    'status' => 'success'
]);
