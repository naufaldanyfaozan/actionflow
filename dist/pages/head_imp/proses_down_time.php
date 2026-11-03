<?php
session_start();
if (!isset($_SESSION["username"])) {
    header("location:cek_login.php");
    exit;
}

include '../koneksi.php';

mysqli_begin_transaction($koneksi);

function fixDate($date)
{
    if (!isset($date) || trim($date) === '') {
        return null;
    }

    $d = date_create($date);
    return $d ? $d->format('Y-m-d') : null;
}

try {

    $no_form              = $_POST['no_form'];
    $customer_type        = $_POST['customer_type'];
    $nama_part            = $_POST['nama_part'];
    $no_part              = $_POST['no_part'];
    $model                = $_POST['model'];
    $machine_no           = $_POST['machine'];
    $mold_no              = $_POST['mold_no'];
    $kejadian_berulang_ke = $_POST['kejadian_berulang_ke'];
    $dept                 = $_POST['dept'];
    $tgl_ditemukan        = fixDate($_POST['tgl_ditemukan'] ?? null);
    $tgl_pembuatan        = fixDate($_POST['tgl_pembuatan'] ?? null);
    $tgl_terbit           = fixDate($_POST['tgl_terbit'] ?? null);

    $shift           = $_POST['shift'];
    $total_lost_time = $_POST['total_lost_time'];

    $username = $_SESSION['username'];

    $plantMap = [
        'mustika' => '1',
        'safri'   => '3'
    ];

    $plant = $plantMap[$username] ?? null;

    $sqlHeader = "INSERT INTO fpm_header (
            no_form,
            no_reg_fpm,
            customer_type,
            nama_part,
            internal_dept,
            no_part,
            model,
            plant,
            machine_no,
            mold_no,
            kejadian_berulang_ke,
            dept,
            tgl_ditemukan,
            tgl_pembuatan,
            tgl_terbit,
            dibuat_oleh,
            status_evaluasi
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'OPEN'
        )
    ";

    $stmt = mysqli_prepare($koneksi, $sqlHeader);
    mysqli_stmt_bind_param(
        $stmt,
        "ssssssssssisssss",
        $no_form,
        $no_form,
        $customer_type,
        $nama_part,
        $dept,
        $no_part,
        $model,
        $plant,
        $machine_no,
        $mold_no,
        $kejadian_berulang_ke,
        $dept,
        $tgl_ditemukan,
        $tgl_pembuatan,
        $tgl_terbit,
        $username
    );

    mysqli_stmt_execute($stmt);

    $id_fpm = mysqli_insert_id($koneksi);

    $sqlDetail = "INSERT INTO fpm_detail_masalah (
            id_fpm,
            shift,
            total_lost_time,
            pic
        ) VALUES (?,?,?,?)
    ";

    $stmt2 = mysqli_prepare($koneksi, $sqlDetail);
    mysqli_stmt_bind_param(
        $stmt2,
        "isss",
        $id_fpm,
        $shift,
        $total_lost_time,
        $dept
    );
    mysqli_stmt_execute($stmt2);

    mysqli_commit($koneksi);

    header("Location: form_fpm.php?id_fpm=" . $id_fpm);
    exit;
} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo "Gagal menyimpan data FPM : " . $e->getMessage();
}
