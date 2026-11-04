<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
include '../koneksi.php';

$id = (int)$_GET['id'];
mysqli_query($koneksi, "DELETE FROM master_machine WHERE id='$id'");

header("location:index.php");
