<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';

$id = (int)$_GET['id'];
$data = mysqli_query($koneksi, "SELECT * FROM master_machine WHERE id='$id'");
$d = mysqli_fetch_array($data);

if (isset($_POST['update'])) {
    $nama = $_POST['nama_mesin'];
    $plant = $_POST['plant'];

    mysqli_query($koneksi, "UPDATE master_machine 
        SET nama_mesin='$nama', plant='$plant' 
        WHERE id='$id'");

    header("location:index.php");
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h4>Edit Data</h4>
            <form method="POST">
                <div class="mb-3">
                    <label>Nama Mesin <span style="color:#dc2626">*</span></label>
                    <input type="text" name="nama_mesin" class="form-control" value="<?= $d['nama_mesin']; ?>" required>
                </div>

                <div class="mb-3">
                    <label>Plant <span style="color:#dc2626">*</span></label>
                    <input type="number" name="plant" class="form-control" value="<?= $d['plant']; ?>" required>
                </div>

                <button type="submit" name="update" class="btn btn-primary">Update</button>
                <a href="index.php" class="btn btn-secondary">Kembali</a>
            </form>
        </div>
    </div>
</div>

</body>
</html>