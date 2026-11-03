<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';

// Logika Tambah Data
if (isset($_POST['tambah'])) {
    $masalah = mysqli_real_escape_string($koneksi, $_POST['masalah']);

    // 1. Cek apakah value sudah ada di database
    $cek_data = mysqli_query($koneksi, "SELECT * FROM jenis_masalah WHERE masalah = '$masalah'");

    if (mysqli_num_rows($cek_data) > 0) {
        // 2. Jika ada yang sama, beri alert dan gagalkan
        echo "<script>
                alert('Gagal! Data masalah sudah ada di database.');
                window.location.href='master_problem.php';
              </script>";
    } else {
        // 3. Jika unik, lanjutkan eksekusi INSERT
        $sql = "INSERT INTO jenis_masalah (masalah) VALUES ('$masalah')";
        if (mysqli_query($koneksi, $sql)) {
            header("Location: master_problem.php");
        } else {
            echo "Error: " . mysqli_error($koneksi);
        }
    }
}
// Logika Hapus Data
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    mysqli_query($koneksi, "DELETE FROM jenis_masalah WHERE id=$id");
    header("Location: master_problem.php");
}
