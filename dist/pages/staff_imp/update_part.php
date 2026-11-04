<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
include '../koneksi.php';

$id         = $_POST['id'];
$namapart   = mysqli_real_escape_string($koneksi, $_POST['namapart']);
$partnumber = mysqli_real_escape_string($koneksi, $_POST['partnumber']);
$type       = mysqli_real_escape_string($koneksi, $_POST['type']);
$customer   = mysqli_real_escape_string($koneksi, $_POST['customer']);

if ($id == "") {
    // INSERT
    mysqli_query($koneksi, "INSERT INTO master_part
        (namapart, partnumber, type, customer)
        VALUES ('$namapart', '$partnumber', '$type', '$customer')");
} else {
    // UPDATE
    $id = (int)$id;
    mysqli_query($koneksi, "UPDATE master_part SET
        namapart='$namapart',
        partnumber='$partnumber',
        type='$type',
        customer='$customer'
        WHERE id=$id");
}

header("Location: index.php");
exit;
