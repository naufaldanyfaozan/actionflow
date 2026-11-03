<?php
session_start();
if (!isset($_SESSION["username"])) {
    header("location:cek_login.php");
    exit;
}

if (($_SESSION['dept'] ?? '') !== 'QA') {
    die('Halaman ini hanya untuk Staff QA.');
}

include __DIR__ . '/../koneksi.php';

mysqli_begin_transaction($koneksi);

function fixDateCust($date)
{
    if (!isset($date) || trim($date) === '') {
        return null;
    }
    $d = date_create($date);
    return $d ? $d->format('Y-m-d') : null;
}

try {
    $no_form              = $_POST['no_form'] ?? '';
    $customer_nama        = $_POST['customer_nama'] ?? '';
    $nama_part            = $_POST['nama_part'] ?? '';
    $no_part              = $_POST['no_part'] ?? '';
    $model                = $_POST['model'] ?? '';
    $dept                 = $_POST['dept'] ?? '';
    $jenis_masalah_detail = $_POST['jenis_masalah_detail'] ?? '';

    $tgl_ditemukan = fixDateCust($_POST['tgl_ditemukan'] ?? '');
    $tgl_pembuatan = fixDateCust($_POST['tgl_pembuatan'] ?? '');

    $username = $_SESSION['username'];
    $plant = trim(str_ireplace('Plant', '', $_SESSION['plant'] ?? ''));
    $date_today = date('Y-m-d');

    $jenis_fpm = 'CUSTOMER';
    $flow_type = 'QA';

    $sqlHeader = "INSERT INTO fpm_header (
            no_form,
            no_reg_fpm,
            jenis_fpm,
            flow_type,
            nama_part,
            customer_nama,
            no_part,
            model,
            plant,
            dept,
            jenis_masalah_detail,
            tgl_ditemukan,
            tgl_pembuatan,
            tgl_terbit,
            dibuat_oleh,
            diterima_qa,
            user_diterima_qa
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
    ";

    $stmt = mysqli_prepare($koneksi, $sqlHeader);

    mysqli_stmt_bind_param(
        $stmt,
        "sssssssssssssssss",
        $no_form,
        $no_form,
        $jenis_fpm,
        $flow_type,
        $nama_part,
        $customer_nama,
        $no_part,
        $model,
        $plant,
        $dept,
        $jenis_masalah_detail,
        $tgl_ditemukan,
        $tgl_pembuatan,
        $date_today,
        $username,
        $date_today,
        $username
    );

    mysqli_stmt_execute($stmt);

    $id_fpm = mysqli_insert_id($koneksi);

    $namaFile = null;

    $errUpload = pesanErrorUpload($_FILES['dokumen_ng']['error'] ?? UPLOAD_ERR_NO_FILE, 'Foto/Dokumen NG');
    if ($errUpload !== null) {
        throw new Exception($errUpload);
    }

    if (isset($_FILES['dokumen_ng']) && $_FILES['dokumen_ng']['error'] == 0) {

        $ext = strtolower(pathinfo($_FILES['dokumen_ng']['name'], PATHINFO_EXTENSION));
        $allow = ['jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allow)) {
            throw new Exception("Format file hanya boleh JPG, JPEG, atau PNG.");
        }

        $folder = __DIR__ . '/../upload_fpm/';

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $namaFile = 'FPM_' . $id_fpm . '_' . date('YmdHis') . '.' . $ext;

        if (!move_uploaded_file($_FILES['dokumen_ng']['tmp_name'], $folder . $namaFile)) {
            throw new Exception("Gagal upload file.");
        }
    }

    if ($namaFile !== null) {
        $sqlDetail = "INSERT INTO fpm_detail_masalah (id_fpm, file) VALUES (?, ?)";
        $stmt2 = mysqli_prepare($koneksi, $sqlDetail);
        mysqli_stmt_bind_param($stmt2, "is", $id_fpm, $namaFile);
        mysqli_stmt_execute($stmt2);
    }

    // baris fpm_pengiriman awal, supaya update ditahap Plant Head / Dept Head QA / Directure
    // (yang hanya melakukan UPDATE, bukan INSERT ... ON DUPLICATE) pasti menemukan barisnya.
    $sqlPengiriman = "INSERT INTO fpm_pengiriman (id_fpm, departement) VALUES (?, ?)";
    $stmtPengiriman = mysqli_prepare($koneksi, $sqlPengiriman);
    mysqli_stmt_bind_param($stmtPengiriman, "is", $id_fpm, $dept);
    mysqli_stmt_execute($stmtPengiriman);

    // $dept masih kosong di sini - dept terkait tidak dipilih saat pembuatan FPM.
    // Dept (dan notifikasi WA ke Dept Head-nya) baru ditentukan Staff QA lewat
    // jawab_fpm_qa.php saat FPM ini dibahas untuk menentukan perbaikannya.
    mysqli_commit($koneksi);

    header("Location: form_fpm.php?id=" . $id_fpm);
    exit;
} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo "<script>alert(" . json_encode("Gagal menyimpan data FPM : " . $e->getMessage()) . ");history.back();</script>";
}
