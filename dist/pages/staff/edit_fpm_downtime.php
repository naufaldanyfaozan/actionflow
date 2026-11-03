<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
if (!isset($_SESSION["username"])) header("location:../cek_login.php");

include '../koneksi.php';
require_once __DIR__ . '/../config/fpm_bukti_aksi_helper.php';
require_once __DIR__ . '/../config/fpm_reschedule_helper.php';
require_once __DIR__ . '/../config/fpm_aksi_pendukung_helper.php';

$userDept = $_SESSION['dept'] ?? '';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$uploadDir = "../uploads/fpm_dt/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$id = $_GET['id'] ?? '';

$fpm = [];
$detail = [];

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    $sql2 = "SELECT shift, total_lost_time FROM fpm_detail_masalah WHERE id_fpm = ?";
    $stmt2 = $koneksi->prepare($sql2);
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $detail = $result2->fetch_assoc();

    $analisa_occurrence = [];
    $analisa_flowout = [];

    $sql3 = "SELECT * FROM fpm_analisa WHERE id_fpm = ?";
    $stmt3 = $koneksi->prepare($sql3);
    $stmt3->bind_param("i", $id);
    $stmt3->execute();
    $result3 = $stmt3->get_result();

    while ($row = $result3->fetch_assoc()) {

        if (
            empty($row['why_1']) &&
            empty($row['why_2']) &&
            empty($row['why_3']) &&
            empty($row['why_4']) &&
            empty($row['why_5'])
        ) {
            continue;
        }

        if ($row['jenis'] === 'OCCURRENCE') {
            $analisa_occurrence[] = $row;
        } elseif ($row['jenis'] === 'FLOW OUT') {
            $analisa_flowout[] = $row;
        }
    }

    $corrective = [];

    $sql4 = "SELECT * FROM fpm_corrective_action WHERE id_fpm = ?";
    $stmt4 = $koneksi->prepare($sql4);
    $stmt4->bind_param("i", $id);
    $stmt4->execute();
    $result4 = $stmt4->get_result();

    while ($row = $result4->fetch_assoc()) {
        $corrective[] = $row;
    }

    $preventive = [];

    $sql5 = "SELECT * FROM fpm_preventive_action WHERE id_fpm = ?";
    $stmt5 = $koneksi->prepare($sql5);
    $stmt5->bind_param("i", $id);
    $stmt5->execute();
    $result5 = $stmt5->get_result();

    while ($row = $result5->fetch_assoc()) {
        $preventive[] = $row;
    }

    $dokumen = [];

    $sql6 = "SELECT * FROM fpm_review_dokumen WHERE id_fpm = ?";
    $stmt6 = $koneksi->prepare($sql6);
    $stmt6->bind_param("i", $id);
    $stmt6->execute();
    $result6 = $stmt6->get_result();

    while ($row = $result6->fetch_assoc()) {
        $dokumen[] = $row;
    }

    // Reschedule due date disimpan langsung lewat tombol Reschedule (AJAX). Log lama dari
    // masa id baris masih berganti tiap simpan tetap terhitung lewat teks tindakan - lihat
    // config/fpm_reschedule_helper.php.
    $rescheduleLogs   = fpmRescheduleLogs($koneksi, (int) $id);
    $rescheduleCounts = fpmRescheduleCounts($koneksi, (int) $id);

    // Dokumen pendukung (bukan bukti): per baris aksi & lampiran umum FPM.
    $pendukungAksi = ambilPendukungAksiFpm($koneksi, (int) $id);
    $lampiranFpm   = ambilLampiranFpm($koneksi, (int) $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        die("<script>alert('Upload gagal: total ukuran file terlalu besar untuk server (maks " . ini_get('post_max_size') . "). Kurangi ukuran/jumlah file lalu coba lagi.');history.back();</script>");
    }

    $id_fpm = (int) $_POST['id_fpm'];

    $today = date('Y-m-d');

    // Baris corrective/preventive/dokumen lama di-UPDATE per id, bukan dihapus lalu
    // di-insert ulang. Dengan hapus + insert ulang, id baris berganti tiap simpan
    // (catatan revisi Staff IMP, log reschedule & file tambahan yang menempel ke id
    // lama jadi yatim), dan file/status diambil dari urutan baris - simpanan form
    // yang dibuka sebelum orang lain upload bisa menimpa bukti orang itu.
    //
    // Mencari baris DB untuk baris form ke-$i: lewat hidden input id; form lama yang
    // masih terbuka sebelum perubahan ini (belum punya hidden id) jatuh ke urutan.
    $barisDbUntukForm = function (array $barisDb, string $section, int $i): ?array {
        $idForm = (int) ($_POST[$section]['id'][$i] ?? 0);
        if (!isset($_POST[$section]['id'])) {
            return $barisDb[$i] ?? null;
        }
        foreach ($barisDb as $baris) {
            if ((int) $baris['id'] === $idForm) return $baris;
        }
        return null;
    };

    // File baris = isi DB saat simpan (bukan hidden input old_file dari saat form dibuka).
    // Kalau file di DB sudah berubah sejak form dibuka (orang lain upload), actual_date
    // dari DB juga dipakai karena itu milik upload tersebut.
    $fileDanActualLama = function (?array $baris, string $fileForm, string $actualForm): array {
        if ($baris === null) return [$fileForm, $actualForm];
        $fileDb = (string) ($baris['file'] ?? '');
        if ($fileDb !== $fileForm) {
            return [$fileDb, (string) ($baris['actual_date'] ?? '')];
        }
        return [$fileDb, $actualForm];
    };

    // UPDATE baris lama per id (id tidak berganti); INSERT hanya kalau barisnya
    // memang belum ada di DB.
    $simpanBarisAksi = function (string $tabel, ?array $barisLama, array $data) use ($koneksi, $id_fpm) {
        $kolom = array_keys($data);
        $nilai = array_values($data);

        if ($barisLama !== null) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", $kolom));
            $stmt = $koneksi->prepare("UPDATE $tabel SET $set WHERE id = ? AND id_fpm = ?");
            $nilai[] = (int) $barisLama['id'];
            $nilai[] = $id_fpm;
            $stmt->bind_param(str_repeat('s', count($kolom)) . 'ii', ...$nilai);
        } else {
            $placeholder = implode(', ', array_fill(0, count($kolom) + 1, '?'));
            $stmt = $koneksi->prepare("INSERT INTO $tabel (id_fpm, " . implode(', ', $kolom) . ") VALUES ($placeholder)");
            array_unshift($nilai, $id_fpm);
            $stmt->bind_param('i' . str_repeat('s', count($kolom)), ...$nilai);
        }

        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data $tabel: " . $stmt->error);
        }

        return $barisLama !== null ? (int) $barisLama['id'] : (int) $koneksi->insert_id;
    };

    $hasOccurrence = false;
    foreach ($_POST['analisa'] ?? [] as $row) {
        if (($row['jenis'] ?? '') === 'OCCURRENCE') {
            $hasOccurrence = true;
            break;
        }
    }

    if (!$hasOccurrence) {
        die('Tidak bisa submit karena Analisa Masalah (Occurrence) belum diisi.');
    }

    $hasCorrective = false;
    foreach ($_POST['corrective']['tindakan'] ?? [] as $tindakan) {
        if (trim($tindakan) !== '') {
            $hasCorrective = true;
            break;
        }
    }

    if (!$hasCorrective) {
        die('Tidak bisa submit karena Corrective Action belum diisi.');
    }

    $koneksi->begin_transaction();

    try {

        $sql = "UPDATE fpm_header SET
                    kejadian_berulang_ke=?,
                    nama_part=?,
                    no_part=?,
                    dept=?,
                    mold_no=?,
                    tgl_ditemukan=?,
                    model=?,
                    tgl_terbit=?,
                    machine_no=?,
                    jenis_masalah=?
                WHERE id_fpm=?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "isssssssssi",
            $_POST['kejadian_ulang'],
            $_POST['nama_part'],
            $_POST['no_part'],
            $_POST['dept'],
            $_POST['mold_no'],
            $_POST['tgl_ditemukan'],
            $_POST['model'],
            $_POST['tgl_terbit'],
            $_POST['machine_no'],
            $_POST['jenis_masalah'],
            $id_fpm
        );
        $stmt->execute();

        $sql = "UPDATE fpm_detail_masalah SET shift=?, total_lost_time=? WHERE id_fpm=?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param(
            "sii",
            $_POST['shift'],
            $_POST['total_lost_time'],
            $id_fpm
        );
        $stmt->execute();

        foreach ($_POST['analisa'] as $row) {

            $id_analisa = $row['id_analisa'] ?? '';
            $kategori   = $row['kategori'] ?? '';
            $jenis      = $row['jenis'] ?? 'OCCURRENCE';

            // Analisa di halaman ini read-only. Baris yang Why-nya tidak ikut terkirim (textarea
            // Flow Out tanpa name) jangan di-UPDATE - dulu why_1..why_5 jadi NULL, Flow Out hilang.
            if ($id_analisa != '' && !array_key_exists('why_1', $row)) continue;

            if ($id_analisa != '') {

                $sql = "UPDATE fpm_analisa SET
                    kategori=?,
                    why_1=?,
                    why_2=?,
                    why_3=?,
                    why_4=?,
                    why_5=?
                WHERE id=?";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "ssssssi",
                    $kategori,
                    $row['why_1'],
                    $row['why_2'],
                    $row['why_3'],
                    $row['why_4'],
                    $row['why_5'],
                    $id_analisa
                );
                $stmt->execute();
            } else {

                $sql = "INSERT INTO fpm_analisa
                (id_fpm, kategori, why_1, why_2, why_3, why_4, why_5, jenis)
                VALUES (?,?,?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssssss",
                    $id_fpm,
                    $kategori,
                    $row['why_1'],
                    $row['why_2'],
                    $row['why_3'],
                    $row['why_4'],
                    $row['why_5'],
                    $jenis
                );
                $stmt->execute();
            }
        }

        foreach ($_POST['corrective']['tindakan'] as $i => $tindakan) {

            if ($tindakan == '') continue;

            $barisLama = $barisDbUntukForm($corrective ?? [], 'corrective', $i);
            [$fileName, $old_actual] = $fileDanActualLama(
                $barisLama,
                (string) ($_POST['corrective']['old_file'][$i] ?? ''),
                (string) ($_POST['corrective']['old_actual_date'][$i] ?? '')
            );

            // Bisa lebih dari 1 file (maks FPM_BUKTI_AKSI_MAX_FILE per baris), ditambahkan ke
            // file yang sudah ada - file pertama di kolom `file`, sisanya di tabel file tambahan
            // (disimpan setelah barisnya tersimpan, lihat $simpanBarisAksi di bawah).
            $uploadBaru = ambilUploadBuktiAksi('corrective_file', $i);
            $jumlahLama = ($fileName !== '' ? 1 : 0)
                + ($barisLama !== null ? count(ambilFileTambahanAksi($koneksi, 'corrective', (int) $barisLama['id'])) : 0);
            [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'CORR', 'Corrective Action baris ' . ($i + 1));
            $fileBaruTersimpan = !empty($uploadBaru);

            $due_date = !empty($_POST['corrective']['due_date'][$i]) ? $_POST['corrective']['due_date'][$i] : ($_POST['corrective']['old_due_date'][$i] ?? null);

            $actual_date = $old_actual;
            // actual_date hanya diisi kalau file benar-benar tersimpan, bukan sekadar nama file terkirim
            if ($fileBaruTersimpan) {
                $actual_date = $today;
            }

            // Status diambil dari data DB ($corrective, hasil query di awal file), bukan
            // dari hidden input old_status yang bisa dimanipulasi client - status CLOSED
            // cuma boleh diset lewat Verifikasi Countermeasure Staff QA.
            $statusLama = strtoupper(trim($barisLama['status'] ?? 'OPEN'));
            $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';
            $note = $_POST['corrective']['note'][$i] ?? '';

            $idBaris = $simpanBarisAksi('fpm_corrective_action', $barisLama, [
                'tindakan'    => $tindakan,
                'due_date'    => $due_date,
                'actual_date' => $actual_date,
                'pic'         => $_POST['corrective']['pic'][$i],
                'status'      => $status,
                'file'        => $fileName,
                'note'        => $note,
            ]);
            simpanFileTambahanAksi($koneksi, 'corrective', $idBaris, $fileTambahan);
        }

        // ================== PERBAIKAN LANJUTAN - CORRECTIVE ACTION BARU ==================
        foreach ($_POST['corrective_baru']['tindakan'] ?? [] as $i => $tindakanBaru) {

            $tindakanBaru = trim($tindakanBaru);
            if ($tindakanBaru === '') continue;

            $dueDateBaru    = $_POST['corrective_baru']['due_date'][$i] ?? null;
            $picBaru        = $_POST['corrective_baru']['pic'][$i] ?? '';
            $noteBaru       = $_POST['corrective_baru']['note'][$i] ?? '';
            $actualDateBaru = '';
            $statusBaru     = 'OPEN';
            $fileBaru       = '';

            $sqlBaru = "INSERT INTO fpm_corrective_action (id_fpm, tindakan, due_date, actual_date, pic, status, file, note)
                        VALUES (?,?,?,?,?,?,?,?)";
            $stmtBaru = $koneksi->prepare($sqlBaru);
            $stmtBaru->bind_param(
                "isssssss",
                $id_fpm,
                $tindakanBaru,
                $dueDateBaru,
                $actualDateBaru,
                $picBaru,
                $statusBaru,
                $fileBaru,
                $noteBaru
            );
            $stmtBaru->execute();
        }

        foreach ($_POST['preventive']['tindakan'] as $i => $tindakan) {

            if ($tindakan == '') continue;

            $barisLama = $barisDbUntukForm($preventive ?? [], 'preventive', $i);
            [$fileName, $old_actual] = $fileDanActualLama(
                $barisLama,
                (string) ($_POST['preventive']['old_file'][$i] ?? ''),
                (string) ($_POST['preventive']['old_actual_date'][$i] ?? '')
            );

            // Lihat catatan di Corrective Action - maks FPM_BUKTI_AKSI_MAX_FILE file per baris.
            $uploadBaru = ambilUploadBuktiAksi('preventive_file', $i);
            $jumlahLama = ($fileName !== '' ? 1 : 0)
                + ($barisLama !== null ? count(ambilFileTambahanAksi($koneksi, 'preventive', (int) $barisLama['id'])) : 0);
            [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'PREV', 'Preventive Action baris ' . ($i + 1));
            $fileBaruTersimpan = !empty($uploadBaru);

            $due_date    = !empty($_POST['preventive']['due_date'][$i]) ? $_POST['preventive']['due_date'][$i] : ($_POST['preventive']['old_due_date'][$i] ?? null);

            $actual_date = $old_actual;

            // actual_date hanya diisi kalau file benar-benar tersimpan, bukan sekadar nama file terkirim
            if ($fileBaruTersimpan) {
                $actual_date = $today;
            }

            // Status diambil dari data DB ($preventive), bukan dari hidden input yang bisa
            // dimanipulasi client.
            $statusLama = strtoupper(trim($barisLama['status'] ?? 'OPEN'));
            $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';
            $note = $_POST['preventive']['note'][$i] ?? '';

            $idBaris = $simpanBarisAksi('fpm_preventive_action', $barisLama, [
                'tindakan'    => $tindakan,
                'due_date'    => $due_date,
                'actual_date' => $actual_date,
                'pic'         => $_POST['preventive']['pic'][$i],
                'status'      => $status,
                'file'        => $fileName,
                'note'        => $note,
            ]);
            simpanFileTambahanAksi($koneksi, 'preventive', $idBaris, $fileTambahan);
        }

        // ================== PERBAIKAN LANJUTAN - PREVENTIVE ACTION BARU ==================
        foreach ($_POST['preventive_baru']['tindakan'] ?? [] as $i => $tindakanBaru) {

            $tindakanBaru = trim($tindakanBaru);
            if ($tindakanBaru === '') continue;

            $dueDateBaru    = $_POST['preventive_baru']['due_date'][$i] ?? null;
            $picBaru        = $_POST['preventive_baru']['pic'][$i] ?? '';
            $noteBaru       = $_POST['preventive_baru']['note'][$i] ?? '';
            $actualDateBaru = '';
            $statusBaru     = 'OPEN';
            $fileBaru       = '';

            $sqlBaru = "INSERT INTO fpm_preventive_action (id_fpm, tindakan, due_date, actual_date, pic, status, file, note)
                        VALUES (?,?,?,?,?,?,?,?)";
            $stmtBaru = $koneksi->prepare($sqlBaru);
            $stmtBaru->bind_param(
                "isssssss",
                $id_fpm,
                $tindakanBaru,
                $dueDateBaru,
                $actualDateBaru,
                $picBaru,
                $statusBaru,
                $fileBaru,
                $noteBaru
            );
            $stmtBaru->execute();
        }

        foreach ($_POST['dokumen']['dokumen'] ?? [] as $i => $dok) {

            $barisLama = $barisDbUntukForm($dokumen ?? [], 'dokumen', $i);

            if ($dok == '') {
                // Nama dokumen dikosongkan = baris dihapus (sama seperti perilaku lama
                // saat semua baris dihapus lalu hanya yang terisi di-insert ulang).
                if ($barisLama !== null) {
                    $idHapus = (int) $barisLama['id'];
                    $stmtHapus = $koneksi->prepare("DELETE FROM fpm_review_dokumen WHERE id = ? AND id_fpm = ?");
                    $stmtHapus->bind_param("ii", $idHapus, $id_fpm);
                    $stmtHapus->execute();
                }
                continue;
            }

            [$fileName, $old_actual] = $fileDanActualLama(
                $barisLama,
                (string) ($_POST['dokumen']['old_file'][$i] ?? ''),
                (string) ($_POST['dokumen']['old_actual_date'][$i] ?? '')
            );

            // Lihat catatan di Corrective Action - maks FPM_BUKTI_AKSI_MAX_FILE file per baris.
            $uploadBaru = ambilUploadBuktiAksi('dokumen_file', $i);
            $jumlahLama = ($fileName !== '' ? 1 : 0)
                + ($barisLama !== null ? count(ambilFileTambahanAksi($koneksi, 'dokumen', (int) $barisLama['id'])) : 0);
            [$fileName, $fileTambahan] = prosesUploadBuktiAksi($uploadBaru, $fileName, $jumlahLama, $uploadDir, 'DOC', 'Update Dokumen baris ' . ($i + 1));
            $fileBaruTersimpan = !empty($uploadBaru);

            $due_date    = !empty($_POST['dokumen']['due_date'][$i]) ? $_POST['dokumen']['due_date'][$i] : (!empty($_POST['dokumen']['old_due_date'][$i]) ? $_POST['dokumen']['old_due_date'][$i] : null);
            $actual_date = $old_actual !== '' ? $old_actual : null;

            // actual_date hanya diisi kalau file benar-benar tersimpan, bukan sekadar nama file terkirim
            if ($fileBaruTersimpan) {
                $actual_date = $today;
            }

            // Status diambil dari data DB ($dokumen), bukan dari hidden input yang bisa
            // dimanipulasi client.
            $statusLama = strtoupper(trim($barisLama['status'] ?? 'OPEN'));
            $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';

            $idBaris = $simpanBarisAksi('fpm_review_dokumen', $barisLama, [
                'dokumen'     => $dok,
                'due_date'    => $due_date,
                'actual_date' => $actual_date,
                'pic'         => $_POST['dokumen']['pic'][$i],
                'status'      => $status,
                'file'        => $fileName,
            ]);
            simpanFileTambahanAksi($koneksi, 'dokumen', $idBaris, $fileTambahan);
        }

        // ================== DOKUMEN PENDUKUNG (bukan bukti perbaikan) ==================
        // Tidak menyentuh status/actual_date/file bukti. Simpan yang baru dulu: kalau gagal
        // (Exception -> rollback), file lama yang dicentang hapus belum ikut terhapus.
        simpanPendukungAksiBaru($koneksi, $id_fpm, $uploadDir, $_SESSION['username'] ?? '');
        hapusPendukungTerpilih($koneksi, $id_fpm, $uploadDir);
        simpanLampiranBaru($koneksi, $id_fpm, $uploadDir);
        hapusLampiranTerpilih($koneksi, $id_fpm, $uploadDir);

        // ================== UPDATE / INSERT fpm_pengiriman ==================
        $username = $_SESSION['level'];

        $cek = $koneksi->prepare("SELECT id FROM fpm_pengiriman WHERE id_fpm = ?");
        $cek->bind_param("i", $id_fpm);
        $cek->execute();
        $resultCek = $cek->get_result();

        if ($resultCek->num_rows > 0) {

            // Kolom tgl (tanggal jawaban pertama kali dibuat) SENGAJA tidak ikut
            // di-update di sini. Sebelumnya kolom ini diisi ulang dari
            // tgl_ditemukan (field yang beda konteks, dan bisa kosong), sehingga
            // kalau FPM ini di-update lagi setelah diminta revisi, tanggal di
            // form cetak (PENGIRIMAN SETELAH PERBAIKAN) ikut hilang padahal yang
            // seharusnya berubah cuma nama (dibuat/departement).
            $sqlPengiriman = "UPDATE fpm_pengiriman
                      SET departement = ?,
                          dibuat = ?
                      WHERE id_fpm = ?";
            $stmtPengiriman = $koneksi->prepare($sqlPengiriman);
            $stmtPengiriman->bind_param(
                "ssi",
                $_POST['dept'],
                $username,
                $id_fpm
            );
            $stmtPengiriman->execute();
        } else {

            // Belum pernah ada baris fpm_pengiriman untuk FPM ini - baru dibuat
            // sekarang, jadi tgl diisi tanggal hari ini (bukan tgl_ditemukan).
            $tglPengiriman = date('Y-m-d');

            $sqlPengiriman = "INSERT INTO fpm_pengiriman
                      (id_fpm, departement, tgl, dibuat, diperiksa, disetujui)
                      VALUES (?, ?, ?, ?, '', '')";
            $stmtPengiriman = $koneksi->prepare($sqlPengiriman);
            $stmtPengiriman->bind_param(
                "isss",
                $id_fpm,
                $_POST['dept'],
                $tglPengiriman,
                $username
            );
            $stmtPengiriman->execute();
        }


        $koneksi->commit();
        // Revisi dari Staff IMP yang barisnya baru diupload ulang -> tandai & kabari Staff IMP.
        require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
        sinkronRevisiSetelahSimpanPic($koneksi, 'FPM', (int) $id_fpm, $_SESSION['username'] ?? '', $_SESSION['dept'] ?? '');
        echo "<script>alert('Data berhasil diupdate');window.location='list_fpm_downtime.php';</script>";
    } catch (Exception $e) {

        $koneksi->rollback();
        echo "<script>alert(" . json_encode("Gagal update: " . $e->getMessage()) . ");history.back();</script>";
    }
}

// ================== AMBIL DATA MASTER DEPT ==================
$deptList = [];
$qDept = $koneksi->query("SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");
while ($rowDept = $qDept->fetch_assoc()) {
    $deptList[] = $rowDept['nama_dept'];
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Form Jawaban FPM DT</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Form Jawaban FPM DT" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --border: #e5e7eb;
            --bg: #f8fafc;
            --text: #1f2933;
            --muted: #6b7280;
        }

        * {
            box-sizing: border-box;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        body {
            background: var(--bg);
            margin: 0;
            color: var(--text);
        }

        .container {
            max-width: 1200px;
            margin: auto;
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .05);
        }

        h1 {
            text-align: center;
            font-size: 20px;
            margin-bottom: 20px;
            text-transform: uppercase;
        }

        .info-box {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
            background: #f9fafb;
        }

        .value {
            font-weight: 500;
        }

        .section-title {
            margin: 25px 0 10px;
            font-weight: 600;
            font-size: 15px;
        }

        .upload-video-hint {
            display: block;
            margin-top: -8px;
            margin-bottom: 10px;
            color: #6b7280;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table th,
        table td {
            border: 1px solid var(--border);
            padding: 8px;
            text-align: center;
            font-size: 13px;
        }

        table th {
            background: #f1f5f9;
            font-weight: 600;
        }

        table td:first-child {
            font-weight: 600;
            background: #f8fafc;
        }

        textarea {
            resize: vertical;
            min-height: 60px;
        }

        .action-table td {
            vertical-align: top;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-submit {
            background: var(--primary);
            color: #fff;
        }

        .btn-cancel {
            background: #ef4444;
            color: #fff;
        }

        .btn:hover {
            opacity: .9;
        }

        .btn-action {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            border: none;
            font-size: 16px;
            cursor: pointer;
            line-height: 1;
        }

        .btn-action.add {
            background: #22c55e;
            color: #fff;
        }

        .btn-action.remove {
            background: #ef4444;
            color: #fff;
        }

        .btn-action:hover {
            opacity: 0.85;
        }

        .text-center {
            text-align: center;
        }

        .info-grid {
            display: grid;
            gap: 20px 100px;
        }

        .label {
            font-weight: 600;
            color: var(--muted);
            text-align: left;
        }

        .info-grid input,
        .info-grid select {
            width: 150%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
        }

        .info-grid input[readonly] {
            background: #f3f4f6;
        }

        textarea {
            width: 100%;
            resize: vertical;
            min-height: 60px;
        }

        .file-preview {
            margin-top: 5px;
        }

        .file-preview img {
            width: 80px;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .file-preview iframe {
            width: 100px;
            height: 120px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">
                <h1>Update Countermeasure - FPM</h1>
                <?php require_once __DIR__ . '/../config/countermeasure_revisi_helper.php'; ?>
                <?= renderBannerRevisiPic($koneksi, 'FPM', (int) $id, $_SESSION['dept'] ?? '') ?>

                <form method="POST" enctype="multipart/form-data" id="editFpmForm">
                    <input type="hidden" name="id_fpm" value="<?= $id ?>">

                    <div class="info-box">
                        <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">

                            <div class="label">No Reg FPM</div>
                            <input type="text" name="no_reg_fpm"
                                value="<?= htmlspecialchars($fpm['no_reg_fpm'] ?? '') ?>" readonly>

                            <div class="label">Kejadian Berulang ke</div>
                            <input type="number" name="kejadian_ulang"
                                value="<?= htmlspecialchars($fpm['kejadian_berulang_ke'] ?? '') ?>" readonly>

                            <div class="label">Shift</div>
                            <input type="text" name="shift"
                                value="<?= htmlspecialchars($detail['shift'] ?? '') ?>" readonly>

                            <div class="label">Nama Part</div>
                            <input type="text" name="nama_part"
                                value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>" readonly>

                            <div class="label">Total Lost Time</div>
                            <input type="number" name="total_lost_time"
                                value="<?= htmlspecialchars($detail['total_lost_time'] ?? '') ?>" readonly>

                            <div class="label">No Part</div>
                            <input type="text" name="no_part"
                                value="<?= htmlspecialchars($fpm['no_part'] ?? '') ?>" readonly>

                            <div class="label">Departemen</div>
                            <input type="text" name="dept"
                                value="<?= htmlspecialchars($fpm['dept'] ?? '') ?>" readonly>

                            <div class="label">Mold #</div>
                            <input type="text" name="mold_no"
                                value="<?= htmlspecialchars($fpm['mold_no'] ?? '') ?>" readonly>

                            <div class="label">Tgl Masalah</div>
                            <input type="text" name="tgl_ditemukan"
                                value="<?= $fpm['tgl_ditemukan'] ?>" readonly>

                            <div class="label">Model</div>
                            <input type="text" name="model"
                                value="<?= htmlspecialchars($fpm['model'] ?? '') ?>" readonly>

                            <div class="label">Tgl Terbit FPM</div>
                            <input type="text" name="tgl_terbit"
                                value="<?= $fpm['tgl_terbit'] ?>" readonly>

                            <div class="label">Machine</div>
                            <input type="text" name="machine_no"
                                value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>" readonly>

                            <div class="label">Jenis Masalah</div>
                            <input type="text" name="jenis_masalah"
                                value="<?= htmlspecialchars($fpm['jenis_masalah'] ?? '') ?>" readonly>

                        </div>
                    </div>

                    <div class="section-title">Analisa Masalah (Occurrence)</div>

                    <table id="analisaTable">
                        <tr>
                            <th>Category</th>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                            <!-- <th>Action</th> -->
                        </tr>

                        <?php $no = 0;
                        foreach ($analisa_occurrence as $row): ?>
                            <tr>
                                <td>
                                    <input type="text"
                                        value="<?= htmlspecialchars($row['kategori']) ?>"
                                        readonly>

                                    <input type="hidden"
                                        name="analisa[<?= $no ?>][kategori]"
                                        value="<?= htmlspecialchars($row['kategori']) ?>">

                                    <input type="hidden"
                                        name="analisa[<?= $no ?>][id_analisa]"
                                        value="<?= $row['id'] ?>">

                                    <input type="hidden"
                                        name="analisa[<?= $no ?>][jenis]"
                                        value="<?= $row['jenis'] ?>">
                                </td>

                                <td><textarea name="analisa[<?= $no ?>][why_1]" readonly><?= htmlspecialchars($row['why_1']) ?></textarea></td>
                                <td><textarea name="analisa[<?= $no ?>][why_2]" readonly><?= htmlspecialchars($row['why_2']) ?></textarea></td>
                                <td><textarea name="analisa[<?= $no ?>][why_3]" readonly><?= htmlspecialchars($row['why_3']) ?></textarea></td>
                                <td><textarea name="analisa[<?= $no ?>][why_4]" readonly><?= htmlspecialchars($row['why_4']) ?></textarea></td>
                                <td><textarea name="analisa[<?= $no ?>][why_5]" readonly><?= htmlspecialchars($row['why_5']) ?></textarea></td>

                                <!-- <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addAnalisaRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td> -->
                            </tr>
                        <?php $no++;
                        endforeach; ?>

                    </table>

                    <div class="section-title">Analisa Masalah (Flow Out)</div>

                    <table>
                        <tr>
                            <th>Category</th>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                        </tr>
                        <?php foreach ($analisa_flowout as $row): ?>
                            <tr>
                                <td>
                                    <input type="text"
                                        value="<?= htmlspecialchars($row['kategori']) ?>"
                                        readonly>

                                    <input type="hidden"
                                        name="analisa[<?= $no ?>][kategori]"
                                        value="<?= htmlspecialchars($row['kategori']) ?>">

                                    <input type="hidden"
                                        name="analisa[<?= $no ?>][id_analisa]"
                                        value="<?= $row['id'] ?>">

                                    <input type="hidden"
                                        name="analisa[<?= $no ?>][jenis]"
                                        value="<?= $row['jenis'] ?>">
                                </td>

                                <td><textarea readonly><?= htmlspecialchars($row['why_1']) ?></textarea></td>
                                <td><textarea readonly><?= htmlspecialchars($row['why_2']) ?></textarea></td>
                                <td><textarea readonly><?= htmlspecialchars($row['why_3']) ?></textarea></td>
                                <td><textarea readonly><?= htmlspecialchars($row['why_4']) ?></textarea></td>
                                <td><textarea readonly><?= htmlspecialchars($row['why_5']) ?></textarea></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>

                    <div class="section-title">Corrective Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table" id="correctiveTable">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <!-- <th>Actual Date</th> -->
                            <th>PIC</th>
                            <th>Status</th>
                            <th>File</th>
                            <th>Note</th>
                            <!-- <th>Action</th> -->
                        </tr>

                        <?php foreach ($corrective as $i => $row): ?>
                            <tr>
                                <input type="hidden" name="corrective[id][]" value="<?= $row['id'] ?>">
                                <td><textarea name="corrective[tindakan][]" readonly><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                <td>
                                    <input type="date" class="due-date-input" name="corrective[due_date][]" value="<?= $row['due_date'] ?>" readonly>
                                    <?= renderTombolRescheduleFpm('CORRECTIVE', $row, $fpm, $rescheduleCounts, $_SESSION['level'] ?? '', $userDept) ?>
                                </td>
                                <input type="hidden" name="corrective[old_status][]" value="<?= $row['status'] ?>">
                                <input type="hidden" name="corrective[old_actual_date][]" value="<?= $row['actual_date'] ?>">
                                <input type="hidden" name="corrective[old_due_date][]" value="<?= $row['due_date'] ?>">
                                <td>
                                    <select name="corrective[pic][]">
                                        <option value="<?= $row['pic'] ?? '' ?>"><?= $row['pic'] ?? '' ?></option>
                                        <?php foreach ($deptList as $dept): ?>
                                            <option value="<?= $dept ?>"
                                                <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                <?= $dept ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span style="
                                                padding:4px 10px;
                                                border-radius:6px;
                                                font-weight:600;
                                                font-size:12px;
                                                color:#fff;
                                                background:<?= $row['status'] == 'CLOSED' ? '#16a34a' : '#ef4444' ?>;
                                            ">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= renderJudulBuktiAksi() ?>
                                    <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'corrective', $row); ?>
                                    <?php foreach ($fileBukti as $fb): ?>
                                        <?= renderPreviewBuktiAksi($fb) ?>
                                    <?php endforeach; ?>
                                    <?= renderInputBuktiAksi('corrective_file[' . $i . ']', count($fileBukti)) ?>
                                    <input type="hidden" name="corrective[old_file][]" value="<?= $row['file'] ?>">
                                    </div>
                                    <?= renderPendukungAksi('corrective', (int) $row['id'], $pendukungAksi['corrective'][(int) $row['id']] ?? []) ?>
                                </td>
                                <td>
                                    <textarea name="corrective[note][]" <?= ($row['pic'] !== $userDept) ? 'readonly' : '' ?>><?= htmlspecialchars($row['note'] ?? '') ?></textarea>
                                </td>
                                <!-- <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td> -->
                            </tr>
                        <?php endforeach; ?>


                    </table>

                    <label style="display:flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;margin:10px 0;">
                        <input type="checkbox" id="correctiveLanjutanToggle" onchange="toggleLanjutan('corrective')">
                        Perbaikan Lanjutan
                    </label>
                    <table class="action-table" id="correctiveLanjutanTable" style="display:none;">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <th>PIC</th>
                            <th>Note</th>
                            <th>Aksi</th>
                        </tr>
                        <tr class="lanjutan-row">
                            <td><textarea name="corrective_baru[tindakan][]"></textarea></td>
                            <td><input type="date" name="corrective_baru[due_date][]"></td>
                            <td>
                                <select name="corrective_baru[pic][]">
                                    <option value="">-- Pilih Dept --</option>
                                    <?php foreach ($deptList as $dept): ?>
                                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><textarea name="corrective_baru[note][]"></textarea></td>
                            <td class="text-center">
                                <button type="button" class="btn-action add" onclick="addLanjutanRow(this, 'corrective')">+</button>
                                <button type="button" class="btn-action remove" onclick="removeLanjutanRow(this)" style="display:none;">−</button>
                            </td>
                        </tr>
                    </table>

                    <div class="section-title">Preventive Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table" id="preventiveTable">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <!-- <th>Actual Date</th> -->
                            <th>PIC</th>
                            <th>Status</th>
                            <th>File</th>
                            <th>Note</th>
                            <!-- <th>Action</th> -->
                        </tr>

                        <?php foreach ($preventive as $i => $row): ?>
                            <tr>
                                <input type="hidden" name="preventive[id][]" value="<?= $row['id'] ?>">
                                <td><textarea name="preventive[tindakan][]" readonly><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                <td>
                                    <input type="date" class="due-date-input" name="preventive[due_date][]" value="<?= $row['due_date'] ?>" readonly>
                                    <?= renderTombolRescheduleFpm('PREVENTIVE', $row, $fpm, $rescheduleCounts, $_SESSION['level'] ?? '', $userDept) ?>
                                </td>
                                <input type="hidden" name="preventive[old_status][]" value="<?= $row['status'] ?>">
                                <input type="hidden" name="preventive[old_actual_date][]" value="<?= $row['actual_date'] ?>">
                                <input type="hidden" name="preventive[old_due_date][]" value="<?= $row['due_date'] ?>">
                                <td>
                                    <select name="preventive[pic][]">
                                        <option value="<?= $row['pic'] ?? '' ?>"><?= $row['pic'] ?? '' ?></option>
                                        <?php foreach ($deptList as $dept): ?>
                                            <option value="<?= $dept ?>"
                                                <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                <?= $dept ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span style="
                                            padding:4px 10px;
                                            border-radius:6px;
                                            font-weight:600;
                                            font-size:12px;
                                            color:#fff;
                                            background:<?= $row['status'] == 'CLOSED' ? '#16a34a' : '#ef4444' ?>;
                                        ">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= renderJudulBuktiAksi() ?>
                                    <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'preventive', $row); ?>
                                    <?php foreach ($fileBukti as $fb): ?>
                                        <?= renderPreviewBuktiAksi($fb) ?>
                                    <?php endforeach; ?>
                                    <?= renderInputBuktiAksi('preventive_file[' . $i . ']', count($fileBukti)) ?>
                                    <input type="hidden" name="preventive[old_file][]" value="<?= $row['file'] ?>">
                                    </div>
                                    <?= renderPendukungAksi('preventive', (int) $row['id'], $pendukungAksi['preventive'][(int) $row['id']] ?? []) ?>
                                </td>
                                <td>
                                    <textarea name="preventive[note][]" <?= ($row['pic'] !== $userDept) ? 'readonly' : '' ?>><?= htmlspecialchars($row['note'] ?? '') ?></textarea>
                                </td>
                                <!-- <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td> -->
                            </tr>
                        <?php endforeach; ?>

                    </table>

                    <label style="display:flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;margin:10px 0;">
                        <input type="checkbox" id="preventiveLanjutanToggle" onchange="toggleLanjutan('preventive')">
                        Perbaikan Lanjutan
                    </label>
                    <table class="action-table" id="preventiveLanjutanTable" style="display:none;">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <th>PIC</th>
                            <th>Note</th>
                            <th>Aksi</th>
                        </tr>
                        <tr class="lanjutan-row">
                            <td><textarea name="preventive_baru[tindakan][]"></textarea></td>
                            <td><input type="date" name="preventive_baru[due_date][]"></td>
                            <td>
                                <select name="preventive_baru[pic][]">
                                    <option value="">-- Pilih Dept --</option>
                                    <?php foreach ($deptList as $dept): ?>
                                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><textarea name="preventive_baru[note][]"></textarea></td>
                            <td class="text-center">
                                <button type="button" class="btn-action add" onclick="addLanjutanRow(this, 'preventive')">+</button>
                                <button type="button" class="btn-action remove" onclick="removeLanjutanRow(this)" style="display:none;">−</button>
                            </td>
                        </tr>
                    </table>

                    <div class="section-title">Update Dokumen</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table id="dokumenTable">
                        <tr>
                            <th>Dokumen</th>
                            <th>Due Date</th>
                            <!-- <th>Actual</th> -->
                            <th>PIC</th>
                            <th>Status</th>
                            <th>File</th>
                            <!-- <th>Action</th> -->
                        </tr>

                        <?php foreach ($dokumen as $i => $row): ?>
                            <tr>
                                <input type="hidden" name="dokumen[id][]" value="<?= $row['id'] ?>">
                                <td>
                                    <input list="docType" name="dokumen[dokumen][]"
                                        value="<?= htmlspecialchars($row['dokumen']) ?>">
                                </td>
                                <td>
                                    <input type="date" name="dokumen[due_date][]" value="<?= $row['due_date'] ?>">
                                    <?= renderTombolRescheduleFpm('DOKUMEN', $row, $fpm, $rescheduleCounts, $_SESSION['level'] ?? '', $userDept) ?>
                                </td>
                                <input type="hidden" name="dokumen[old_status][]" value="<?= $row['status'] ?>">
                                <input type="hidden" name="dokumen[old_actual_date][]" value="<?= $row['actual_date'] ?>">
                                <input type="hidden" name="dokumen[old_due_date][]" value="<?= $row['due_date'] ?>">
                                <td>
                                    <select name="dokumen[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($deptList as $dept): ?>
                                            <option value="<?= $dept ?>"
                                                <?= ($row['pic'] ?? '') == $dept ? 'selected' : '' ?>>
                                                <?= $dept ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span style="
                                            padding:4px 10px;
                                            border-radius:6px;
                                            font-weight:600;
                                            font-size:12px;
                                            color:#fff;
                                            background:<?= strtoupper($row['status']) == 'CLOSED' ? '#16a34a' : '#ef4444' ?>;
                                        ">
                                        <?= htmlspecialchars(strtoupper($row['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= renderJudulBuktiAksi() ?>
                                    <?php $fileBukti = semuaFileBuktiAksi($koneksi, 'dokumen', $row); ?>
                                    <?php foreach ($fileBukti as $fb): ?>
                                        <?= renderPreviewBuktiAksi($fb) ?>
                                    <?php endforeach; ?>
                                    <?= renderInputBuktiAksi('dokumen_file[' . $i . ']', count($fileBukti)) ?>
                                    <input type="hidden" name="dokumen[old_file][]" value="<?= $row['file'] ?>">
                                    </div>
                                    <?= renderPendukungAksi('dokumen', (int) $row['id'], $pendukungAksi['dokumen'][(int) $row['id']] ?? []) ?>
                                </td>
                                <!-- <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td> -->
                            </tr>
                        <?php endforeach; ?>

                    </table>

                    <?= renderHistoryRescheduleFpm($rescheduleLogs ?? []) ?>

                    <datalist id="docType">
                        <option value="IK">
                        <option value="FMEA">
                        <option value="QCPC">
                        <option value="Check Sheet">
                    </datalist>

                    <div class="section-title">Lampiran Pendukung FPM</div>
                    <small class="upload-video-hint">
                        Dokumen pendukung untuk FPM ini secara umum (bukan bukti perbaikan), format PDF/JPG/PNG, maksimal 5 file per submit.
                        Untuk dokumen pendukung satu perbaikan tertentu, pakai "+ Dokumen Pendukung" di baris perbaikannya.
                    </small>

                    <?php if (!empty($lampiranFpm)): ?>
                        <div style="display:flex; flex-wrap:wrap; gap:12px; margin:10px 0;">
                            <?php foreach ($lampiranFpm as $lp): ?>
                                <?php
                                $filePathLp = "../uploads/fpm_dt/" . $lp['file'];
                                $extLp = strtolower(pathinfo($lp['file'], PATHINFO_EXTENSION));
                                ?>
                                <div style="border:1px solid #e5e7eb; border-radius:6px; padding:8px; text-align:center; font-size:11px; width:110px;">
                                    <?php if (in_array($extLp, ['jpg', 'jpeg', 'png'])): ?>
                                        <a href="<?= htmlspecialchars($filePathLp) ?>" target="_blank">
                                            <img src="<?= htmlspecialchars($filePathLp) ?>" style="width:80px; height:auto; border:1px solid #ddd; border-radius:4px;">
                                        </a>
                                    <?php elseif ($extLp == 'pdf'): ?>
                                        <a href="<?= htmlspecialchars($filePathLp) ?>" target="_blank">
                                            <iframe src="<?= htmlspecialchars($filePathLp) ?>" style="width:90px; height:110px; border:1px solid #ddd; border-radius:4px;"></iframe>
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($filePathLp) ?>" target="_blank" style="display:block; word-break:break-all;">
                                        <?= htmlspecialchars($lp['original_name']) ?>
                                    </a>
                                    <label style="display:flex; align-items:center; justify-content:center; gap:4px; margin-top:6px; color:#dc2626; cursor:pointer;">
                                        <input type="checkbox" name="hapus_lampiran[]" value="<?= (int) $lp['id'] ?>"> Hapus
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php renderMultiFileInput("lampiran_file[]", null, '+ Lampiran Pendukung FPM'); ?>

                    <div class="btn-group">
                        <button class="btn btn-submit">Submit</button>
                        <button class="btn btn-cancel">Cancel</button>
                    </div>

                </form>

            </div>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?= renderAsetRescheduleFpm((int) $id, '../config/ajax_reschedule_fpm.php', false) ?>

    <script>
        function toggleLanjutan(type) {
            const checkbox = document.getElementById(type + 'LanjutanToggle');
            const table = document.getElementById(type + 'LanjutanTable');
            table.style.display = checkbox.checked ? 'table' : 'none';
        }

        function addLanjutanRow(btn, type) {
            const table = document.getElementById(type + 'LanjutanTable');
            const rows = table.querySelectorAll('tr.lanjutan-row');
            if (rows.length >= 5) return;

            const newRow = rows[rows.length - 1].cloneNode(true);
            newRow.querySelectorAll('textarea, input[type="date"]').forEach(el => el.value = '');
            newRow.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
            newRow.querySelector('.remove').style.display = 'inline-block';
            table.appendChild(newRow);
            updateLanjutanButtons(table);
        }

        function removeLanjutanRow(btn) {
            const row = btn.closest('tr');
            const table = row.closest('table');
            row.remove();
            updateLanjutanButtons(table);
        }

        function updateLanjutanButtons(table) {
            const rows = table.querySelectorAll('tr.lanjutan-row');
            rows.forEach((row, idx) => {
                const addBtn = row.querySelector('.add');
                addBtn.style.display = (idx === rows.length - 1 && rows.length < 5) ? 'inline-block' : 'none';
            });
        }
    </script>

    <script>
        (function() {
            const MAX_VIDEO_SIZE = 10 * 1024 * 1024; // 10MB
            const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];

            function isVideoFile(file) {
                if (file.type && file.type.startsWith('video/')) return true;
                const ext = file.name.split('.').pop().toLowerCase();
                return VIDEO_EXT.includes(ext);
            }

            document.addEventListener('change', function(e) {
                if (e.target.matches && e.target.matches('input[type="file"]')) {
                    // Input bukti bisa berisi beberapa file - cek semuanya, bukan cuma yang pertama.
                    const file = Array.from(e.target.files).find(f => isVideoFile(f) && f.size > MAX_VIDEO_SIZE);
                    if (file) {
                        alert('Ukuran video melebihi 10MB. Silakan pilih file video yang lebih kecil.');
                        e.target.value = '';
                    }
                }
            });

            document.getElementById('editFpmForm').addEventListener('submit', function(e) {
                const fileInputs = document.querySelectorAll('#editFpmForm input[type="file"]');
                for (const input of fileInputs) {
                    const file = Array.from(input.files).find(f => isVideoFile(f) && f.size > MAX_VIDEO_SIZE);
                    if (file) {
                        alert('Ukuran video melebihi 10MB. Perbaiki file sebelum submit.');
                        e.preventDefault();
                        input.focus();
                        return false;
                    }
                }
            });
        })();

        document.getElementById('editFpmForm').addEventListener('submit', function(e) {
            const analisaRows = document.querySelectorAll('#analisaTable tr').length - 1;
            const correctiveRows = document.querySelectorAll('#correctiveTable tr').length - 1;

            if (analisaRows < 1 || correctiveRows < 1) {
                e.preventDefault();
                alert('Analisa Masalah (Occurrence) dan Corrective Action wajib diisi sebelum submit.');
            }
        });

        function addRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const newRow = row.cloneNode(true);
            // tombol reschedule milik baris asal - baris baru belum tersimpan, belum bisa di-reschedule
            newRow.querySelectorAll('.fpm-rs-box').forEach(el => el.remove());

            newRow.querySelectorAll("input, textarea").forEach(el => el.value = "");
            newRow.querySelectorAll("select").forEach(el => el.selectedIndex = 0);

            table.appendChild(newRow);
            updateActionButtons(table);
        }

        function removeRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const rows = table.querySelectorAll("tr");

            if (rows.length > 2) {
                row.remove();
                updateActionButtons(table);
            }
        }

        function updateActionButtons(table) {
            const rows = table.querySelectorAll("tr");
            rows.forEach((row, index) => {
                if (index === 0) return; // skip header
                const addBtn = row.querySelector(".add");
                addBtn.style.display = (index === rows.length - 1) ? "inline-block" : "none";
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>

    <script>
        const SELECTOR_SIDEBAR_WRAPPER = ".sidebar-wrapper";
        const Default = {
            scrollbarTheme: "os-theme-light",
            scrollbarAutoHide: "leave",
            scrollbarClickScroll: true,
        };
        document.addEventListener("DOMContentLoaded", function() {
            const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
            if (
                sidebarWrapper &&
                typeof OverlayScrollbarsGlobal?.OverlayScrollbars !== "undefined"
            ) {
                OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                    scrollbars: {
                        theme: Default.scrollbarTheme,
                        autoHide: Default.scrollbarAutoHide,
                        clickScroll: Default.scrollbarClickScroll,
                    },
                });
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        const connectedSortables =
            document.querySelectorAll(".connectedSortable");
        connectedSortables.forEach((connectedSortable) => {
            let sortable = new Sortable(connectedSortable, {
                group: "shared",
                handle: ".card-header",
            });
        });

        const cardHeaders = document.querySelectorAll(
            ".connectedSortable .card-header",
        );
        cardHeaders.forEach((cardHeader) => {
            cardHeader.style.cursor = "move";
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js"></script>

    <?= renderScriptBuktiAksi() ?>
</body>

</html>