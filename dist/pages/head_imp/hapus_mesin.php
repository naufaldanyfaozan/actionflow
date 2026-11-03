<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");
include '../koneksi.php';

$id = (int)$_GET['id'];
mysqli_query($koneksi, "DELETE FROM master_machine WHERE id='$id'");

header("location:index.php");
