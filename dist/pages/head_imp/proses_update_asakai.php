<?php
require_once __DIR__ . '/../config/auth_helper.php';
wajibLogin();
mulaiSessionAman();
include '../koneksi.php';
require_once __DIR__ . '/../config/asakai_bukti_helper.php';

date_default_timezone_set('Asia/Jakarta');
$tanggalHariIni = date('Y-m-d');

$id_asakai = (int) ($_POST['id_asakai'] ?? 0);

$uploadDir = "../uploads/asakai/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($id_asakai > 0) {
    $ngProblemInput = array_values(array_filter(array_map('trim', $_POST['ng_problem_list'] ?? []), fn($v) => $v !== ''));

    if (!empty($ngProblemInput)) {
        $ng_problem = $ngProblemInput[0];
        mysqli_query($koneksi, "UPDATE asakai_header SET ng_problem = '" . mysqli_real_escape_string($koneksi, $ng_problem) . "' WHERE id_asakai = $id_asakai");

        mysqli_query($koneksi, "DELETE FROM asakai_ng_problem WHERE id_asakai = $id_asakai");
        for ($k = 1; $k < count($ngProblemInput); $k++) {
            $extraNg = mysqli_real_escape_string($koneksi, $ngProblemInput[$k]);
            mysqli_query($koneksi, "INSERT INTO asakai_ng_problem (id_asakai, deskripsi) VALUES ($id_asakai, '$extraNg')");
        }
    }
}

function uploadFiles($fieldName, $rowIndex, $prefix, $uploadDir)
{
    $result = [null, null, null];

    if (!isset($_FILES[$fieldName]['name'][$rowIndex])) {
        return $result;
    }

    foreach ($_FILES[$fieldName]['name'][$rowIndex] as $i => $name) {

        if ($i >= 3 || empty($name)) continue;

        if ($_FILES[$fieldName]['error'][$rowIndex][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if (isVideoUploadTooLarge($name, $_FILES[$fieldName]['size'][$rowIndex][$i])) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $newName = namaFileUploadUnik($prefix, $ext, $uploadDir);

        $tmp = $_FILES[$fieldName]['tmp_name'][$rowIndex][$i];

        if (pindahkanUploadAman($tmp, $uploadDir . $newName)) {
            $result[$i] = $newName;
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

if (!empty($_POST['cm_dt_id'])) {
foreach ($_POST['cm_dt_id'] as $i => $cm_id) {

    $cm_id     = (int)$cm_id;
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

    // Jika ada file yang benar-benar terupload → paksa actual_date = hari ini (status tetap menunggu verifikasi staff_imp)
    if (array_filter($files)) {
        $actual = $tanggalHariIni;
    }

    mysqli_query($koneksi, "
        UPDATE asakai_countermeasure_downtime SET
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

    $cm_id     = (int)$cm_id;
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

    // Jika ada file yang benar-benar terupload → paksa actual_date = hari ini (status tetap menunggu verifikasi staff_imp)
    if (array_filter($files)) {
        $actual = $tanggalHariIni;
    }

    mysqli_query($koneksi, "
        UPDATE asakai_countermeasure_ng SET
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

header("Location: list_asakai.php");
exit;
