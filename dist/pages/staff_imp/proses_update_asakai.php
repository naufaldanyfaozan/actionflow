<?php
require_once __DIR__ . '/../config/auth_helper.php';
wajibLogin();
mulaiSessionAman();
include '../koneksi.php';
require_once __DIR__ . '/../config/asakai_bukti_helper.php';

date_default_timezone_set('Asia/Jakarta');
$tanggalHariIni = date('Y-m-d');

$id_asakai = (int) ($_POST['id_asakai'] ?? 0);

if ($id_asakai <= 0) {
    die("ID tidak valid");
}

$uploadDir = "../uploads/asakai/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
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

    $d = DateTime::createFromFormat('d-m-Y', $str);
    if ($d && $d->format('d-m-Y') === $str) {
        return $d->format('Y-m-d');
    }

    $d = DateTime::createFromFormat('Y-m-d', $str);
    if ($d && $d->format('Y-m-d') === $str) {
        return $d->format('Y-m-d');
    }

    return null;
}

$tanggal          = parseTanggalInput($_POST['tanggal'] ?? '');
$tanggal_problem  = parseTanggalInput($_POST['tanggal_problem'] ?? '');
$machine      = mysqli_real_escape_string($koneksi, $_POST['machine'] ?? '');
$nama_part    = mysqli_real_escape_string($koneksi, $_POST['nama_part'] ?? '');
$type         = mysqli_real_escape_string($koneksi, $_POST['type'] ?? '');
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

$ngProblemInput = array_values(array_filter(array_map('trim', $_POST['ng_problem_list'] ?? []), fn($v) => $v !== ''));

mysqli_query($koneksi, "
    UPDATE asakai_header SET
        tanggal = " . ($tanggal ? "'$tanggal'" : "tanggal") . ",
        machine = '$machine',
        namapart = '$nama_part',
        type = '$type',
        mold_no = '$mold',
        tanggal_problem = " . ($tanggal_problem ? "'$tanggal_problem'" : "tanggal_problem") . ",
        target = $target,
        ok_qty = $ok_qty,
        ng_qty = $ng_qty,
        downtime = $downtime,
        standard = '$standard',
        actual = '$actual',
        efficiency = $efficiency,
        persen_ng = $persen_ng,
        downtime_problem = '" . mysqli_real_escape_string($koneksi, $downtime_problem) . "',
        ng_problem = " . (!empty($ngProblemInput) ? "'" . mysqli_real_escape_string($koneksi, $ngProblemInput[0]) . "'" : "ng_problem") . "
    WHERE id_asakai = $id_asakai
");

if (!empty($ngProblemInput)) {
    mysqli_query($koneksi, "DELETE FROM asakai_ng_problem WHERE id_asakai = $id_asakai");
}
for ($k = 1; $k < count($ngProblemInput); $k++) {
    $extraNg = mysqli_real_escape_string($koneksi, $ngProblemInput[$k]);
    mysqli_query($koneksi, "INSERT INTO asakai_ng_problem (id_asakai, deskripsi) VALUES ($id_asakai, '$extraNg')");
}

if (!empty($_POST['analisa_dt_id'])) {
    foreach ($_POST['analisa_dt_id'] as $i => $id_analisa) {

        $id_analisa = (int)$id_analisa;

        $a1 = mysqli_real_escape_string($koneksi, $_POST['analisa_dt'][$i]['analisa1'] ?? '');
        $a2 = mysqli_real_escape_string($koneksi, $_POST['analisa_dt'][$i]['analisa2'] ?? '');
        $a3 = mysqli_real_escape_string($koneksi, $_POST['analisa_dt'][$i]['analisa3'] ?? '');
        $a4 = mysqli_real_escape_string($koneksi, $_POST['analisa_dt'][$i]['analisa4'] ?? '');
        $problem = mysqli_real_escape_string($koneksi, $_POST['problem_dt'][$i] ?? '');

        mysqli_query($koneksi, "
            UPDATE asakai_analisa SET
                problem = '$problem',
                analisa1 = '$a1',
                analisa2 = '$a2',
                analisa3 = '$a3',
                analisa4 = '$a4'
            WHERE id_analisa = $id_analisa AND id_asakai = $id_asakai
        ");
    }
}

if (!empty($_POST['analisa_ng_id'])) {
    foreach ($_POST['analisa_ng_id'] as $i => $id_analisa) {

        $id_analisa = (int)$id_analisa;

        $a1 = mysqli_real_escape_string($koneksi, $_POST['analisa_ng'][$i]['analisa1'] ?? '');
        $a2 = mysqli_real_escape_string($koneksi, $_POST['analisa_ng'][$i]['analisa2'] ?? '');
        $a3 = mysqli_real_escape_string($koneksi, $_POST['analisa_ng'][$i]['analisa3'] ?? '');
        $a4 = mysqli_real_escape_string($koneksi, $_POST['analisa_ng'][$i]['analisa4'] ?? '');
        $problem = mysqli_real_escape_string($koneksi, $_POST['problem_ng'][$i] ?? '');

        mysqli_query($koneksi, "
            UPDATE asakai_analisa SET
                problem = '$problem',
                analisa1 = '$a1',
                analisa2 = '$a2',
                analisa3 = '$a3',
                analisa4 = '$a4'
            WHERE id_analisa = $id_analisa AND id_asakai = $id_asakai
        ");
    }
}

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

        // Jika ada file yang benar-benar terupload → paksa actual_date = hari ini (status mengikuti pilihan staff_imp di form)
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

        // Jika ada file yang benar-benar terupload → paksa actual_date = hari ini (status mengikuti pilihan staff_imp di form)
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
