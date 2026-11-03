<?php
session_start();
if (!isset($_SESSION["username"])) {
    header("location:cek_login.php");
    exit;
}

include '../koneksi.php';
require_once __DIR__ . '/../config/upload_bukti_ui_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("location:bahan_asakai.php");
    exit;
}

$id_asakai = (int) $_POST['id_asakai'];

$uploadDir = "../uploads/asakai/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function uploadFiles($fieldName, $groupIndex, $rowIndex, $prefix, $uploadDir)
{
    $result = [null, null, null];

    if (!isset($_FILES[$fieldName]['name'][$groupIndex][$rowIndex])) {
        return $result;
    }

    foreach ($_FILES[$fieldName]['name'][$groupIndex][$rowIndex] as $i => $name) {

        if ($i >= 3 || empty($name)) continue;

        if ($_FILES[$fieldName]['error'][$groupIndex][$rowIndex][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if (isVideoUploadTooLarge($name, $_FILES[$fieldName]['size'][$groupIndex][$rowIndex][$i])) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $newName = namaFileUploadUnik($prefix, $ext, $uploadDir);

        $tmp = $_FILES[$fieldName]['tmp_name'][$groupIndex][$rowIndex][$i];

        if (move_uploaded_file($tmp, $uploadDir . $newName)) {
            $result[$i] = $newName;
        }
    }

    return $result;
}

// Countermeasure dihapus lalu di-insert ulang (id berganti) - log reschedule & catatan revisi
// Staff IMP ikut dipindah ke id baru, supaya riwayatnya tidak hilang setelah baris di-CLOSED-kan
// lewat form pembahasan ini (dan hitungan maks 3x reschedule tidak reset).
function pindahkanRiwayatCmAsakai($koneksi, $id_asakai, $jenis, $refTable, $idLama, $idBaru)
{
    $id_asakai = (int) $id_asakai;
    $idLama = (int) $idLama;
    $idBaru = (int) $idBaru;
    if ($idLama <= 0 || $idBaru <= 0 || $idLama === $idBaru) {
        return;
    }

    mysqli_query($koneksi, "UPDATE asakai_reschedule_log SET id_action = $idBaru
        WHERE id_asakai = $id_asakai AND jenis_action = '$jenis' AND id_action = $idLama");
    mysqli_query($koneksi, "UPDATE asakai_catatan_revisi SET ref_id = $idBaru
        WHERE id_asakai = $id_asakai AND ref_table = '$refTable' AND ref_id = $idLama");
}

mysqli_begin_transaction($koneksi);

try {

    $qOldHeader = mysqli_query($koneksi, "SELECT * FROM asakai_header WHERE id_asakai = " . (int)$id_asakai);
    $oldHeader = mysqli_fetch_assoc($qOldHeader) ?: [];

    function keepIfBlank($posted, $old)
    {
        $posted = trim((string)($posted ?? ''));
        return $posted !== '' ? $posted : ($old ?? '');
    }

    $machine          = keepIfBlank($_POST['machine'] ?? null, $oldHeader['machine'] ?? '');
    $tanggal           = keepIfBlank($_POST['tanggal'] ?? null, $oldHeader['tanggal'] ?? '');
    $namapart          = keepIfBlank($_POST['nama_part'] ?? null, $oldHeader['namapart'] ?? '');
    $type              = keepIfBlank($_POST['type'] ?? null, $oldHeader['type'] ?? '');
    $tanggal_problem   = keepIfBlank($_POST['tanggal_problem'] ?? null, $oldHeader['tanggal_problem'] ?? '');
    $target            = keepIfBlank($_POST['target'] ?? null, $oldHeader['target'] ?? 0);
    $standard          = keepIfBlank($_POST['standard'] ?? null, $oldHeader['standard'] ?? 0);
    $ok_qty            = keepIfBlank($_POST['ok_qty'] ?? null, $oldHeader['ok_qty'] ?? 0);
    $actual            = keepIfBlank($_POST['actual'] ?? null, $oldHeader['actual'] ?? 0);
    $ng_qty            = keepIfBlank($_POST['ng_qty'] ?? null, $oldHeader['ng_qty'] ?? 0);
    $efficiency        = keepIfBlank($_POST['efficiency'] ?? null, $oldHeader['efficiency'] ?? 0);
    $mold_no           = keepIfBlank($_POST['mold_no'] ?? null, $oldHeader['mold_no'] ?? '');
    $downtime          = keepIfBlank($_POST['downtime'] ?? null, $oldHeader['downtime'] ?? 0);
    $persen_ng         = keepIfBlank($_POST['persen_ng'] ?? null, $oldHeader['persen_ng'] ?? 0);
    // problem_dt/problem_ng sekarang berisi satu problem per baris analisa
    // (lihat form_pembahasan_asakai.php), gabungkan lagi supaya header tetap
    // menyimpan seluruh problem seperti sebelumnya (dipakai list/detail/export).
    $dtProblemList     = array_values(array_filter(array_map('trim', $_POST['problem_dt'] ?? []), fn($v) => $v !== ''));
    $downtime_problem  = keepIfBlank(implode("\n", $dtProblemList), $oldHeader['downtime_problem'] ?? '');

    $ngProblemList     = array_values(array_filter(array_map('trim', $_POST['problem_ng'] ?? []), fn($v) => $v !== ''));
    $ng_problem        = keepIfBlank($ngProblemList[0] ?? '', $oldHeader['ng_problem'] ?? '');

    $sqlHeader = "UPDATE asakai_header SET
            machine = ?,
            tanggal = ?,
            namapart = ?,
            type = ?,
            tanggal_problem = ?,
            target = ?,
            standard = ?,
            ok_qty = ?,
            actual = ?,
            ng_qty = ?,
            efficiency = ?,
            mold_no = ?,
            downtime = ?,
            persen_ng = ?,
            downtime_problem = ?,
            ng_problem = ?
        WHERE id_asakai = ?";

    $stmtHeader = mysqli_prepare($koneksi, $sqlHeader);
    mysqli_stmt_bind_param(
        $stmtHeader,
        "ssssssssssssssssi",
        $machine,
        $tanggal,
        $namapart,
        $type,
        $tanggal_problem,
        $target,
        $standard,
        $ok_qty,
        $actual,
        $ng_qty,
        $efficiency,
        $mold_no,
        $downtime,
        $persen_ng,
        $downtime_problem,
        $ng_problem,
        $id_asakai
    );
    mysqli_stmt_execute($stmtHeader);

    // Problem NG kedua dst tidak muat di header.ng_problem (cuma 1 kolom),
    // jadi disimpan di asakai_ng_problem seperti alur input awal.
    mysqli_query($koneksi, "DELETE FROM asakai_ng_problem WHERE id_asakai='$id_asakai'");
    for ($k = 1; $k < count($ngProblemList); $k++) {
        $extraNg = mysqli_real_escape_string($koneksi, $ngProblemList[$k]);
        mysqli_query($koneksi, "INSERT INTO asakai_ng_problem (id_asakai, deskripsi) VALUES ('$id_asakai', '$extraNg')");
    }

    // Countermeasure disimpan per problem (id_analisa), jadi harus insert
    // analisa-nya dulu supaya id barunya (mysqli_insert_id) bisa dipakai
    // sebagai id_analisa saat insert countermeasure-nya.
    $oldFilesDT = [];
    $qOldCmDT = mysqli_query($koneksi, "SELECT id_cm_dt, file1, file2, file3 FROM asakai_countermeasure_downtime WHERE id_asakai='$id_asakai'");
    while ($r = mysqli_fetch_assoc($qOldCmDT)) {
        $oldFilesDT[$r['id_cm_dt']] = [$r['file1'], $r['file2'], $r['file3']];
    }

    mysqli_query($koneksi, "DELETE FROM asakai_countermeasure_downtime WHERE id_asakai='$id_asakai'");
    mysqli_query($koneksi, "DELETE FROM asakai_analisa WHERE id_asakai='$id_asakai' AND kategori='DOWN_TIME'");

    $insertedDT = false;
    if (!empty($_POST['analisa_dt'])) {
        foreach ($_POST['analisa_dt'] as $g => $row) {

            $a1 = trim($row['analisa1'] ?? '');
            $a2 = trim($row['analisa2'] ?? '');
            $a3 = trim($row['analisa3'] ?? '');
            $a4 = trim($row['analisa4'] ?? '');

            if ($a1 === '' && $a2 === '' && $a3 === '' && $a4 === '') continue;

            $problem = $_POST['problem_dt'][$g] ?? '';

            $sql = "INSERT INTO asakai_analisa (id_asakai, kategori, problem, analisa1, analisa2, analisa3, analisa4)
                    VALUES (?, 'DOWN_TIME', ?, ?, ?, ?, ?)";

            $stmt = mysqli_prepare($koneksi, $sql);
            mysqli_stmt_bind_param($stmt, "isssss", $id_asakai, $problem, $a1, $a2, $a3, $a4);
            mysqli_stmt_execute($stmt);
            $insertedDT = true;

            $newAnalisaId = mysqli_insert_id($koneksi);

            foreach ($_POST['cm_dt'][$g] ?? [] as $j => $cmRow) {

                $desc = trim($cmRow['deskripsi'] ?? '');
                if ($desc === '') continue;

                $pic    = $cmRow['pic'] ?? '';
                $due    = !empty($cmRow['due']) ? $cmRow['due'] : null;
                $actual = !empty($cmRow['actual']) ? $cmRow['actual'] : null;
                $status = $cmRow['status'] ?? '';
                $note   = $cmRow['note'] ?? '';

                $files = uploadFiles('upload_dt', $g, $j, 'DT', $uploadDir);

                if (array_filter($files)) {
                    $actual = date('Y-m-d');
                }

                $oldId = (int)($cmRow['id'] ?? 0);
                if ($oldId && isset($oldFilesDT[$oldId])) {
                    $files[0] = $files[0] ?? $oldFilesDT[$oldId][0];
                    $files[1] = $files[1] ?? $oldFilesDT[$oldId][1];
                    $files[2] = $files[2] ?? $oldFilesDT[$oldId][2];
                }

                $sql = "INSERT INTO asakai_countermeasure_downtime (id_asakai, id_analisa, deskripsi, pic, due_date, actual_date, status, file1, file2, file3, note_downtime)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)";

                $stmt = mysqli_prepare($koneksi, $sql);
                mysqli_stmt_bind_param(
                    $stmt,
                    "ii" . str_repeat('s', 9),
                    $id_asakai,
                    $newAnalisaId,
                    $desc,
                    $pic,
                    $due,
                    $actual,
                    $status,
                    $files[0],
                    $files[1],
                    $files[2],
                    $note
                );
                mysqli_stmt_execute($stmt);

                pindahkanRiwayatCmAsakai($koneksi, $id_asakai, 'DOWNTIME', 'asakai_countermeasure_downtime', $oldId, mysqli_insert_id($koneksi));
            }
        }
    }

    if (!$insertedDT) {
        // Selalu sisakan baris kategori DOWN_TIME agar header ini tetap
        // muncul di Summary Asakai walaupun analisanya belum diisi.
        $sql = "INSERT INTO asakai_analisa (id_asakai, kategori) VALUES (?, 'DOWN_TIME')";
        $stmt = mysqli_prepare($koneksi, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id_asakai);
        mysqli_stmt_execute($stmt);
    }

    $oldFilesNG = [];
    $qOldCmNG = mysqli_query($koneksi, "SELECT id_cm_ng, file1, file2, file3 FROM asakai_countermeasure_ng WHERE id_asakai='$id_asakai'");
    while ($r = mysqli_fetch_assoc($qOldCmNG)) {
        $oldFilesNG[$r['id_cm_ng']] = [$r['file1'], $r['file2'], $r['file3']];
    }

    mysqli_query($koneksi, "DELETE FROM asakai_countermeasure_ng WHERE id_asakai='$id_asakai'");
    mysqli_query($koneksi, "DELETE FROM asakai_analisa WHERE id_asakai='$id_asakai' AND kategori='NG'");

    $insertedNG = false;
    if (!empty($_POST['analisa_ng'])) {
        foreach ($_POST['analisa_ng'] as $g => $row) {

            $a1 = trim($row['analisa1'] ?? '');
            $a2 = trim($row['analisa2'] ?? '');
            $a3 = trim($row['analisa3'] ?? '');
            $a4 = trim($row['analisa4'] ?? '');

            if ($a1 === '' && $a2 === '' && $a3 === '' && $a4 === '') continue;

            $problem = $_POST['problem_ng'][$g] ?? '';

            $sql = "INSERT INTO asakai_analisa (id_asakai, kategori, problem, analisa1, analisa2, analisa3, analisa4)
                    VALUES (?, 'NG', ?, ?, ?, ?, ?)";

            $stmt = mysqli_prepare($koneksi, $sql);
            mysqli_stmt_bind_param($stmt, "isssss", $id_asakai, $problem, $a1, $a2, $a3, $a4);
            mysqli_stmt_execute($stmt);
            $insertedNG = true;

            $newAnalisaId = mysqli_insert_id($koneksi);

            foreach ($_POST['cm_ng'][$g] ?? [] as $j => $cmRow) {

                $desc = trim($cmRow['deskripsi'] ?? '');
                if ($desc === '') continue;

                $pic    = $cmRow['pic'] ?? '';
                $due    = !empty($cmRow['due']) ? $cmRow['due'] : null;
                $actual = !empty($cmRow['actual']) ? $cmRow['actual'] : null;
                $status = $cmRow['status'] ?? '';
                $note   = $cmRow['note'] ?? '';

                $files = uploadFiles('upload_ng', $g, $j, 'NG', $uploadDir);

                if (array_filter($files)) {
                    $actual = date('Y-m-d');
                }

                $oldId = (int)($cmRow['id'] ?? 0);
                if ($oldId && isset($oldFilesNG[$oldId])) {
                    $files[0] = $files[0] ?? $oldFilesNG[$oldId][0];
                    $files[1] = $files[1] ?? $oldFilesNG[$oldId][1];
                    $files[2] = $files[2] ?? $oldFilesNG[$oldId][2];
                }

                $sql = "INSERT INTO asakai_countermeasure_ng (id_asakai, id_analisa, deskripsi, pic, due_date, actual_date, status, file1, file2, file3, note_ng)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)";

                $stmt = mysqli_prepare($koneksi, $sql);
                mysqli_stmt_bind_param(
                    $stmt,
                    "ii" . str_repeat('s', 9),
                    $id_asakai,
                    $newAnalisaId,
                    $desc,
                    $pic,
                    $due,
                    $actual,
                    $status,
                    $files[0],
                    $files[1],
                    $files[2],
                    $note
                );
                mysqli_stmt_execute($stmt);

                pindahkanRiwayatCmAsakai($koneksi, $id_asakai, 'NG', 'asakai_countermeasure_ng', $oldId, mysqli_insert_id($koneksi));
            }
        }
    }

    if (!$insertedNG) {
        // Selalu sisakan baris kategori NG agar header ini tetap
        // muncul di Summary Asakai walaupun analisanya belum diisi.
        $sql = "INSERT INTO asakai_analisa (id_asakai, kategori) VALUES (?, 'NG')";
        $stmt = mysqli_prepare($koneksi, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id_asakai);
        mysqli_stmt_execute($stmt);
    }

    mysqli_commit($koneksi);

    header("location:bahan_asakai.php");
    exit;
} catch (Exception $e) {

    mysqli_rollback($koneksi);
    echo "Gagal update: " . $e->getMessage();
}
