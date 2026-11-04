<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';

$id_fpm = $_POST['id_fpm'] ?? 0;

if (!$id_fpm) {
    die("ID tidak ditemukan");
}

$nama_part          = $_POST['nama_part'] ?? '';
$no_part            = $_POST['no_part'] ?? '';
$model              = $_POST['model'] ?? '';
$machine_no         = $_POST['machine_no'] ?? '';
$mold_no            = $_POST['mold_no'] ?? '';
$jenis_masalah      = $_POST['jenis_masalah'] ?? '';
$kejadian           = $_POST['kejadian_berulang_ke'] ?? 0;
$dept               = $_POST['dept'] ?? '';
$tgl_ditemukan      = $_POST['tgl_ditemukan'] ?? '';
$tgl_pembuatan      = $_POST['tgl_pembuatan'] ?? '';
$tgl_terbit         = $_POST['tgl_terbit'] ?? '';
$total_lost_time    = $_POST['total_lost_time'] ?? 0;
$shift              = $_POST['shift'] ?? '';

$sql = "UPDATE fpm_header SET
            nama_part = ?,
            no_part = ?,
            model = ?,
            machine_no = ?,
            mold_no = ?,
            jenis_masalah = ?,
            kejadian_berulang_ke = ?,
            dept = ?,
            tgl_ditemukan = ?,
            tgl_pembuatan = ?,
            tgl_terbit = ?
        WHERE id_fpm = ?";

$stmt = $koneksi->prepare($sql);

$stmt->bind_param(
    "ssssssissssi",
    $nama_part,
    $no_part,
    $model,
    $machine_no,
    $mold_no,
    $jenis_masalah,
    $kejadian,
    $dept,
    $tgl_ditemukan,
    $tgl_pembuatan,
    $tgl_terbit,
    $id_fpm
);

$updateHeader = $stmt->execute();

$sqlDetail = "UPDATE fpm_detail_masalah SET shift = ?, total_lost_time = ? WHERE id_fpm = ?";

$stmtDetail = $koneksi->prepare($sqlDetail);
$stmtDetail->bind_param("ssi", $shift, $total_lost_time, $id_fpm);
$updateDetail = $stmtDetail->execute();

if ($updateHeader && $updateDetail) {
    echo "<script>
        alert('Data berhasil diupdate');
        window.location='form_fpm.php?id=$id_fpm';
    </script>";
} else {
    die(pesanErrorDb('Gagal update', $koneksi));
}
