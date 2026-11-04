<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/jenis_masalah_helper.php';
require_once __DIR__ . '/../config/asakai_fpm_helper.php';

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
    $jenis_fpm            = $_POST['jenis_fpm'] ?? '';
    $nama_part            = $_POST['nama_part'] ?? '';
    $no_part              = $_POST['no_part'] ?? '';
    $model                = $_POST['model'] ?? '';
    $machine_no           = $_POST['machine'] ?? '';
    $mold_no              = $_POST['mold_no'] ?? '';
    $kejadian_berulang_ke = $_POST['kejadian_berulang_ke'] ?? 1;
    $dept                 = $_POST['dept'] ?? '';
    $jenis_masalah        = $_POST['jenis_masalah'] ?? '';
    $jenis_masalah_detail = $_POST['jenis_masalah_detail'] ?? '';

    // FPM dari Asakai: problem Asakai yang belum ada di master Jenis Masalah ditambahkan dulu.
    daftarkanJenisMasalahDariAsakai($koneksi, (int) ($_POST['id_asakai'] ?? 0), 'NG', $jenis_masalah);

    // Jenis Masalah hanya boleh dari master (lihat config/jenis_masalah_helper.php).
    if (!jenisMasalahTerdaftar($koneksi, $jenis_masalah)) {
        throw new Exception(pesanJenisMasalahTidakTerdaftar($jenis_masalah));
    }

    // $shift           = $_POST['shift'] ?? '';
    // $total_lost_time = $_POST['total_lost_time'] ?? '';

    $tgl_ditemukan = fixDate($_POST['tgl_ditemukan'] ?? '');
    $tgl_pembuatan = fixDate($_POST['tgl_pembuatan'] ?? '');

    $ng_area_percent  = ($_POST['ng_area_percent'] ?? '') !== '' ? (float) $_POST['ng_area_percent'] : null;
    $ng_total_percent = ($_POST['ng_total_percent'] ?? '') !== '' ? (float) $_POST['ng_total_percent'] : null;

    $username = $_SESSION['username'];

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

    $plantMap = [
        'doni' => '1',
        'aji'  => '3'
    ];

    $plant = $plantMap[$username] ?? null;

    $sqlHeader = "INSERT INTO fpm_header (
            no_form,
            no_reg_fpm,
            jenis_fpm,
            nama_part,
            internal_dept,
            no_part,
            model,
            plant,
            machine_no,
            mold_no,
            kejadian_berulang_ke,
            dept,
            jenis_masalah,
            jenis_masalah_detail,
            tgl_ditemukan,
            tgl_pembuatan,
            dibuat_oleh
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
        $nama_part,
        $dept,
        $no_part,
        $model,
        $plant,
        $machine_no,
        $mold_no,
        $kejadian_berulang_ke,
        $dept,
        $jenis_masalah,
        $jenis_masalah_detail,
        $tgl_ditemukan,
        $tgl_pembuatan,
        $username
    );

    mysqli_stmt_execute($stmt);

    $id_fpm = mysqli_insert_id($koneksi);

    // Dokumen / Foto NG bisa sampai 3 file: file pertama di kolom `file`
    // (seperti sebelumnya), file kedua & ketiga di file2 / file3.
    $namaFiles = [null, null, null];

    if (isset($_FILES['dokumen_ng']['name']) && is_array($_FILES['dokumen_ng']['name'])) {
        $allow = ['jpg', 'jpeg', 'png'];
        $folder = __DIR__ . '/../upload_fpm/';
        $slot = 0;

        foreach ($_FILES['dokumen_ng']['name'] as $k => $namaAsli) {
            if ($_FILES['dokumen_ng']['error'][$k] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($slot >= 3) {
                throw new Exception("Dokumen / Foto NG maksimal 3 file.");
            }

            if ($_FILES['dokumen_ng']['error'][$k] !== UPLOAD_ERR_OK) {
                throw new Exception("Gagal upload file " . htmlspecialchars($namaAsli) . ".");
            }

            $ext = strtolower(pathinfo($namaAsli, PATHINFO_EXTENSION));

            if (!in_array($ext, $allow)) {
                throw new Exception(
                    "Format file hanya boleh JPG, JPEG, atau PNG."
                );
            }

            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            $namaFile = 'FPM_' . $id_fpm . '_' . date('YmdHis') . '_' . ($slot + 1) . '.' . $ext;

            if (
                !pindahkanUploadAman($_FILES['dokumen_ng']['tmp_name'][$k], $folder . $namaFile)
            ) {
                throw new Exception("Gagal upload file.");
            }

            $namaFiles[$slot] = $namaFile;
            $slot++;
        }
    }

    if ($namaFiles[0] !== null || $ng_area_percent !== null || $ng_total_percent !== null) {
        $sqlDetail = "INSERT INTO fpm_detail_masalah (id_fpm, file, file2, file3, ng_area_percent, ng_total_percent) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt2 = mysqli_prepare($koneksi, $sqlDetail);
        mysqli_stmt_bind_param($stmt2, "isssdd", $id_fpm, $namaFiles[0], $namaFiles[1], $namaFiles[2], $ng_area_percent, $ng_total_percent);
        mysqli_stmt_execute($stmt2);
    }

    $nama_dept = $dept;

    require_once __DIR__ . '/../config/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    if ($plant == '1') {
        $waIMPHeadFinal = $waIMPHead1 ?? [];
    } elseif ($plant == '3') {
        $waIMPHeadFinal = $waIMPHead3 ?? [];
    } else {
        $waIMPHeadFinal = [];
    }

    if (empty($waIMPHeadFinal)) {
        die("Error: Daftar nomor WA Dept Head kosong atau departemen '$nama_dept' tidak terdaftar di wa_map.php");
    }

    $waTarget = implode(',', $waIMPHeadFinal);
    $pesan = "*FPM INTERNAL*\nTerdapat FPM Internal untuk departemen *$nama_dept* ditujukan kepada IMP Head. Mohon segera ditindaklanjuti. No Form: " . $no_form;

    $terkirim = kirimWA($waTarget, $pesan);

    if (!$terkirim) {
        error_log("Gagal kirim WA FPM: " . $id_fpm);
    }

    mysqli_commit($koneksi);

    // FPM dari pembahasan Asakai -> tugasnya selesai (lihat config/asakai_fpm_helper.php).
    // Setelah commit: pembuatan tabel otomatis (DDL) memicu commit implisit di MySQL.
    tautkanFpmKeAsakai($koneksi, (int) $id_fpm, $no_form, 'NG', (int) ($_POST['id_asakai'] ?? 0), $plant, $machine_no, $nama_part);

    header("Location: form_fpm_internal.php?id=" . $id_fpm);
    exit;
} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo "Gagal menyimpan data FPM : " . $e->getMessage();
}
