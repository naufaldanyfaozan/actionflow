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

function fixDate($date)
{
    if (!isset($date) || trim($date) === '') {
        return null;
    }
    $d = date_create($date);
    return $d ? $d->format('Y-m-d') : null;
}

try {
    $no_form              = $_POST['no_form'] ?? '';
    $jenis_fpm            = 'Claim Machining';
    $nama_part            = $_POST['nama_part'] ?? '';
    $no_part              = $_POST['no_part'] ?? '';
    $model                = $_POST['model'] ?? '';
    $machine_no           = $_POST['machine'] ?? '';
    $mold_no              = $_POST['mold_no'] ?? '';
    $kejadian_berulang_ke = $_POST['kejadian_berulang_ke'] ?? 0;
    $jenis_masalah        = $_POST['jenis_masalah'] ?? '';
    $jenis_masalah_detail = $_POST['jenis_masalah_detail'] ?? '';

    $tgl_ditemukan = fixDate($_POST['tgl_ditemukan'] ?? '');
    $tgl_pembuatan = fixDate($_POST['tgl_pembuatan'] ?? '');

    $ng_area_percent  = ($_POST['ng_area_percent'] ?? '') !== '' ? (float) $_POST['ng_area_percent'] : null;
    $ng_total_percent = ($_POST['ng_total_percent'] ?? '') !== '' ? (float) $_POST['ng_total_percent'] : null;

    $username = $_SESSION['username'];
    $date_today = date('Y-m-d');

    // Staff QA lintas plant (session plant kosong/tidak relevan), jadi plant FPM Claim
    // Machining ditentukan dari mesin yang dipilih (master_machine.plant), BUKAN dari
    // session plant pembuat - supaya notifikasi WA ke Plant Head (yang di-key per plant,
    // lihat wa_map.php $waMapPlantHead) terkirim ke Plant Head yang benar-benar relevan.
    $plant = '';
    if ($machine_no !== '') {
        $stmtPlantMesin = mysqli_prepare($koneksi, "SELECT plant FROM master_machine WHERE nama_mesin = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtPlantMesin, "s", $machine_no);
        mysqli_stmt_execute($stmtPlantMesin);
        $rowPlantMesin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtPlantMesin));
        $plant = trim($rowPlantMesin['plant'] ?? '');
    }
    if ($plant === '') {
        // Fallback kalau mesin tidak ditemukan/tidak match master_machine.
        $plant = trim(str_ireplace('Plant', '', $_SESSION['plant'] ?? ''));
    }

    $sqlCheck = "SELECT MAX(tgl_ditemukan) as last_date FROM fpm_header WHERE jenis_fpm = ?
        AND jenis_masalah = ?
        AND (
                (nama_part = ? AND mold_no = ?)
                OR
                (machine_no = ?)
            )
    ";

    $stmtCheck = mysqli_prepare($koneksi, $sqlCheck);
    mysqli_stmt_bind_param(
        $stmtCheck,
        "sssss",
        $jenis_fpm,
        $jenis_masalah,
        $nama_part,
        $mold_no,
        $machine_no
    );

    mysqli_stmt_execute($stmtCheck);
    $resultCheck = mysqli_stmt_get_result($stmtCheck);
    $dataCheck = mysqli_fetch_assoc($resultCheck);

    $last_date = $dataCheck['last_date'] ?? null;

    if ($last_date) {
        if (!$tgl_ditemukan) {
            throw new Exception("Tanggal masalah wajib diisi karena sudah ada kejadian sebelumnya ($last_date).");
        }
        if ($tgl_ditemukan <= $last_date) {
            throw new Exception("Tanggal masalah tidak boleh lebih kecil atau sama dengan kejadian sebelumnya ($last_date).");
        }
    }

    $flow_type = 'CLAIMMACH';
    $internal_dept = 'QA';

    $sqlHeader = "INSERT INTO fpm_header (
            no_form,
            no_reg_fpm,
            jenis_fpm,
            flow_type,
            nama_part,
            internal_dept,
            no_part,
            model,
            plant,
            machine_no,
            mold_no,
            kejadian_berulang_ke,
            jenis_masalah,
            jenis_masalah_detail,
            tgl_ditemukan,
            tgl_pembuatan,
            dibuat_oleh,
            diterima_qa,
            user_diterima_qa
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
    ";

    $stmt = mysqli_prepare($koneksi, $sqlHeader);

    mysqli_stmt_bind_param(
        $stmt,
        "sssssssssssssssssss",
        $no_form,
        $no_form,
        $jenis_fpm,
        $flow_type,
        $nama_part,
        $internal_dept,
        $no_part,
        $model,
        $plant,
        $machine_no,
        $mold_no,
        $kejadian_berulang_ke,
        $jenis_masalah,
        $jenis_masalah_detail,
        $tgl_ditemukan,
        $tgl_pembuatan,
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

    if (
        isset($_FILES['dokumen_ng']) && $_FILES['dokumen_ng']['error'] == 0
    ) {

        $ext = strtolower(
            pathinfo($_FILES['dokumen_ng']['name'], PATHINFO_EXTENSION)
        );

        $allow = ['jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allow)) {
            throw new Exception(
                "Format file hanya boleh JPG, JPEG, atau PNG."
            );
        }

        $folder = __DIR__ . '/../upload_fpm/';

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $namaFile = 'FPM_' . $id_fpm . '_' . date('YmdHis') . '.' . $ext;

        if (
            !move_uploaded_file($_FILES['dokumen_ng']['tmp_name'], $folder . $namaFile)
        ) {
            throw new Exception("Gagal upload file.");
        }
    }

    if ($namaFile !== null || $ng_area_percent !== null || $ng_total_percent !== null) {
        $sqlDetail = "INSERT INTO fpm_detail_masalah (id_fpm, file, ng_area_percent, ng_total_percent) VALUES (?, ?, ?, ?)";
        $stmt2 = mysqli_prepare($koneksi, $sqlDetail);
        mysqli_stmt_bind_param($stmt2, "isdd", $id_fpm, $namaFile, $ng_area_percent, $ng_total_percent);
        mysqli_stmt_execute($stmt2);
    }

    // Baris fpm_pengiriman awal supaya update di tahap Plant Head / Dept Head / Directure
    // (yang hanya melakukan UPDATE, bukan INSERT ... ON DUPLICATE) pasti menemukan barisnya.
    // Dept Terkait BELUM diisi di sini - itu baru dipilih Plant Head saat "Terima & Pilih
    // Dept" (lihat plant_head/form_fpm.php), beda dengan alur lama.
    $sqlPengiriman = "INSERT INTO fpm_pengiriman (id_fpm, tgl) VALUES (?, ?)";
    $stmtPengiriman = mysqli_prepare($koneksi, $sqlPengiriman);
    mysqli_stmt_bind_param($stmtPengiriman, "is", $id_fpm, $date_today);
    mysqli_stmt_execute($stmtPengiriman);

    mysqli_commit($koneksi);

    require_once __DIR__ . '/../config/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    // Notif awal ditujukan ke Dept Head QA dulu (alur v3: Dept Head QA approve sebelum
    // diteruskan ke Plant Head) - bukan langsung ke Plant Head seperti alur v2.
    $waTujuan = array_unique(array_filter($waDeptHeadQA ?? []));

    if (!empty($waTujuan)) {
        $waTarget = implode(',', $waTujuan);
        $pesan = "FPM Claim Machining baru dari Staff QA menunggu approval Dept Head QA. No Form: " . $no_form;
        $terkirim = kirimWA($waTarget, $pesan);

        if (!$terkirim) {
            error_log("Gagal kirim WA FPM Claim Machining: $id_fpm");
        }
    }

    header("Location: form_fpm.php?id=" . $id_fpm);
    exit;
} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo "<script>alert(" . json_encode("Gagal menyimpan data FPM : " . $e->getMessage()) . ");history.back();</script>";
}
