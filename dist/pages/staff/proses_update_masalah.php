<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
if (($_SESSION['dept'] ?? '') !== 'QA') { header("location:index.php"); exit; }
include '../koneksi.php';

if (isset($_POST['update'])) {
    $id = (int)$_POST['id'];
    $masalah = mysqli_real_escape_string($koneksi, $_POST['masalah']);


    $query = "UPDATE jenis_masalah SET masalah='$masalah' WHERE id='$id'";

    if (mysqli_query($koneksi, $query)) {
        header("location:master_problem.php?pesan=update_berhasil");
    } else {
        echo pesanErrorDb('Gagal menyimpan data', $koneksi);
    }
}
