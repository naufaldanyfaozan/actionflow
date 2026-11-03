<?php
include 'koneksi.php';

// Logika Tambah Data
if (isset($_POST['tambah'])) {
    $masalah = $_POST['masalah'];
    $kategori = $_POST['kategori'];

    $sql = "INSERT INTO jenis_masalah (masalah, kategori) VALUES ('$masalah', '$kategori')";
    if (mysqli_query($conn, $sql)) {
        header("Location: index.php");
    }
}

// Logika Hapus Data
if (isset($_GET['hapus'])) {
    $id = $_GET['hapus'];
    mysqli_query($conn, "DELETE FROM jenis_masalah WHERE id=$id");
    header("Location: index.php");
}
