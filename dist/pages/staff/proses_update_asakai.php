<?php
session_start();
include '../koneksi.php';
require_once __DIR__ . '/../config/asakai_bukti_helper.php';

date_default_timezone_set('Asia/Jakarta');
$tanggalHariIni = date('Y-m-d');

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$userDept = $_SESSION['dept'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    die("Upload gagal: total ukuran file yang diupload terlalu besar untuk server. Kembali dan kurangi ukuran/jumlah file, lalu coba lagi. <br><a href='javascript:history.back()'>Kembali</a>");
}

$id_asakai = (int) ($_POST['id_asakai'] ?? 0);

if ($id_asakai <= 0) {
    die("ID tidak valid");
}

$uploadDir = "../uploads/asakai/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$gagalUpload = [];

function uploadFiles($fieldName, $rowIndex, $prefix, $uploadDir)
{
    $result = [null, null, null];

    if (!isset($_FILES[$fieldName]['name'][$rowIndex])) {
        return $result;
    }

    foreach ($_FILES[$fieldName]['name'][$rowIndex] as $i => $name) {

        if ($i >= 3 || empty($name)) continue;

        // Upload yang gagal tidak lagi dilewati diam-diam - dicatat supaya user tahu harus upload ulang.
        $labelBaris = "Countermeasure $prefix baris " . ($rowIndex + 1) . " ($name)";

        $errUpload = pesanErrorUpload($_FILES[$fieldName]['error'][$rowIndex][$i], $labelBaris);
        if ($errUpload !== null) {
            $GLOBALS['gagalUpload'][] = $errUpload;
            continue;
        }

        if (isVideoUploadTooLarge($name, $_FILES[$fieldName]['size'][$rowIndex][$i])) {
            $GLOBALS['gagalUpload'][] = "Video pada $labelBaris melebihi 10MB.";
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $newName = namaFileUploadUnik($prefix, $ext, $uploadDir);

        $tmp = $_FILES[$fieldName]['tmp_name'][$rowIndex][$i];

        if (move_uploaded_file($tmp, $uploadDir . $newName)) {
            $result[$i] = $newName;
        } else {
            $GLOBALS['gagalUpload'][] = "Server gagal menyimpan file pada $labelBaris.";
        }
    }

    return $result;
}

function parseTanggalInput($str)
{
    $str = trim((string)($str ?? ''));
    if ($str === '') return null;

    $d = DateTime::createFromFormat('Y-m-d', $str);
    if ($d && $d->format('Y-m-d') === $str) {
        return $d->format('Y-m-d');
    }

    $d = DateTime::createFromFormat('d-m-Y', $str);
    if ($d && $d->format('d-m-Y') === $str) {
        return $d->format('Y-m-d');
    }

    return null;
}

$tanggal      = $_POST['tanggal'] ?? null;
$machine      = mysqli_real_escape_string($koneksi, $_POST['machine'] ?? '');
$nama_part    = mysqli_real_escape_string($koneksi, $_POST['nama_part'] ?? '');
$type         = mysqli_real_escape_string($koneksi, $_POST['type'] ?? '');
$part_number  = mysqli_real_escape_string($koneksi, $_POST['partnumber'] ?? '');
$mold         = mysqli_real_escape_string($koneksi, $_POST['mold_no'] ?? '');

$target       = (int)($_POST['target'] ?? 0);
$ok_qty       = (int)($_POST['ok_qty'] ?? 0);
$ng_qty       = (int)($_POST['ng_qty'] ?? 0);
$downtime     = (int)($_POST['downtime'] ?? 0);

$standard     = mysqli_real_escape_string($koneksi, $_POST['standard'] ?? '');
$actual       = mysqli_real_escape_string($koneksi, $_POST['actual'] ?? '');

$efficiency   = (float)($_POST['efficiency'] ?? 0);
$persen_ng    = (float)($_POST['persen_ng'] ?? 0);

$downtime_problem = $_POST['problem_dt'][0] ?? '';
$ng_problem       = $_POST['problem_ng'][0] ?? '';

if (!empty($_POST['cm_dt_id'])) {
    foreach ($_POST['cm_dt_id'] as $i => $cm_id) {

        $cm_id = (int)$cm_id;

        $deskripsi = mysqli_real_escape_string($koneksi, $_POST['deskripsi_dt'][$i] ?? '');
        $pic       = mysqli_real_escape_string($koneksi, $_POST['pic_dt'][$i] ?? '');
        $due       = parseTanggalInput($_POST['due_dt'][$i] ?? '');
        $actual    = parseTanggalInput($_POST['actual_dt'][$i] ?? '');
        $status    = $_POST['status_dt'][$i] ?? null;
        $note      = mysqli_real_escape_string($koneksi, $_POST['note_dt'][$i] ?? '');

        $rescheduleDate   = parseTanggalInput($_POST['reschedule_date_dt'][$i] ?? '');
        $rescheduleAlasan = trim($_POST['reschedule_alasan_dt'][$i] ?? '');
        $oldDue           = parseTanggalInput($_POST['old_due_dt'][$i] ?? '');

        if ($rescheduleDate && $oldDue && $rescheduleAlasan !== '' && $rescheduleDate !== $oldDue) {
            $cekCount = mysqli_query($koneksi, "SELECT COUNT(*) AS jumlah FROM asakai_reschedule_log WHERE id_action = $cm_id AND jenis_action = 'DOWNTIME'");
            $jumlahReschedule = (int) mysqli_fetch_assoc($cekCount)['jumlah'];

            if ($jumlahReschedule < 3) {
                $direscheduleOleh = mysqli_real_escape_string($koneksi, $_SESSION['username'] ?? '');
                $alasanEsc = mysqli_real_escape_string($koneksi, $rescheduleAlasan);
                mysqli_query($koneksi, "INSERT INTO asakai_reschedule_log
                    (id_asakai, id_action, jenis_action, deskripsi, pic, due_date_lama, due_date_baru, alasan, direschedule_oleh)
                    VALUES ($id_asakai, $cm_id, 'DOWNTIME', '$deskripsi', '$pic', '$oldDue', '$rescheduleDate', '$alasanEsc', '$direscheduleOleh')");

                $due = $rescheduleDate;
            }
        }

        $files = uploadFiles('upload_dt', $i, 'DT', $uploadDir);

        // Cek apakah ada file yang benar-benar terupload
        $adaFileUpload = array_filter($files);

        // Jika ada file → paksa actual_date = hari ini
        if (!empty($adaFileUpload)) {
            $actual = $tanggalHariIni;
        }

        mysqli_query($koneksi, "UPDATE asakai_countermeasure_downtime SET
        deskripsi = '$deskripsi',
        pic = '$pic',
        due_date = " . ($due ? "'$due'" : "due_date") . ",
        actual_date = " . ($actual ? "'$actual'" : "actual_date") . ",
        status = " . ($status ? "'$status'" : "status") . ",
        note_downtime = '$note',
        " . sqlSlotFileAsakai($koneksi, 'dt', (int) $cm_id, $files, $uploadDir) . "
    WHERE id_cm_dt = $cm_id AND id_asakai = $id_asakai
");
    }
}

if (!empty($_POST['cm_ng_id'])) {
    foreach ($_POST['cm_ng_id'] as $i => $cm_id) {

        $cm_id = (int)$cm_id;

        $deskripsi = mysqli_real_escape_string($koneksi, $_POST['deskripsi_ng'][$i] ?? '');
        $pic       = mysqli_real_escape_string($koneksi, $_POST['pic_ng'][$i] ?? '');
        $due       = parseTanggalInput($_POST['due_ng'][$i] ?? '');
        $actual    = parseTanggalInput($_POST['actual_ng'][$i] ?? '');
        $status    = $_POST['status_ng'][$i] ?? null;
        $note      = mysqli_real_escape_string($koneksi, $_POST['note_ng'][$i] ?? '');

        $rescheduleDate   = parseTanggalInput($_POST['reschedule_date_ng'][$i] ?? '');
        $rescheduleAlasan = trim($_POST['reschedule_alasan_ng'][$i] ?? '');
        $oldDue           = parseTanggalInput($_POST['old_due_ng'][$i] ?? '');

        if ($rescheduleDate && $oldDue && $rescheduleAlasan !== '' && $rescheduleDate !== $oldDue) {
            $cekCount = mysqli_query($koneksi, "SELECT COUNT(*) AS jumlah FROM asakai_reschedule_log WHERE id_action = $cm_id AND jenis_action = 'NG'");
            $jumlahReschedule = (int) mysqli_fetch_assoc($cekCount)['jumlah'];

            if ($jumlahReschedule < 3) {
                $direscheduleOleh = mysqli_real_escape_string($koneksi, $_SESSION['username'] ?? '');
                $alasanEsc = mysqli_real_escape_string($koneksi, $rescheduleAlasan);
                mysqli_query($koneksi, "INSERT INTO asakai_reschedule_log
                    (id_asakai, id_action, jenis_action, deskripsi, pic, due_date_lama, due_date_baru, alasan, direschedule_oleh)
                    VALUES ($id_asakai, $cm_id, 'NG', '$deskripsi', '$pic', '$oldDue', '$rescheduleDate', '$alasanEsc', '$direscheduleOleh')");

                $due = $rescheduleDate;
            }
        }

        $files = uploadFiles('upload_ng', $i, 'NG', $uploadDir);

        $adaFileUpload = array_filter($files);

        if (!empty($adaFileUpload)) {
            $actual = $tanggalHariIni;
        }

        mysqli_query($koneksi, "UPDATE asakai_countermeasure_ng SET
        deskripsi = '$deskripsi',
        pic = '$pic',
        due_date = " . ($due ? "'$due'" : "due_date") . ",
        actual_date = " . ($actual ? "'$actual'" : "actual_date") . ",
        status = " . ($status ? "'$status'" : "status") . ",
        note_ng = '$note',
        " . sqlSlotFileAsakai($koneksi, 'ng', (int) $cm_id, $files, $uploadDir) . "
    WHERE id_cm_ng = $cm_id AND id_asakai = $id_asakai
");
    }
}

// Revisi dari Staff IMP yang barisnya baru diupload ulang -> tandai & kabari Staff IMP.
require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
sinkronRevisiSetelahSimpanPic($koneksi, 'ASAKAI', $id_asakai, $_SESSION['username'] ?? '', $_SESSION['dept'] ?? '');

if (!empty($gagalUpload)) {
    $pesan = "Data tersimpan, tetapi file berikut GAGAL diupload (actual date baris tersebut tidak diubah). Silakan upload ulang:\n- " . implode("\n- ", $gagalUpload);
    echo "<script>alert(" . json_encode($pesan) . ");window.location='update_asakai.php?id=$id_asakai';</script>";
    exit;
}

header("Location: list_asakai.php");
exit;
