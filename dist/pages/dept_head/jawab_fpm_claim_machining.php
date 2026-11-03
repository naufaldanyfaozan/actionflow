<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

// FPM Claim Machining: dept mana pun boleh mengisi/update perbaikan, jadi
// TIDAK ada gate dept==='QA' di sini (beda dengan jawab_fpm_qa.php).

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/fpm_reschedule_helper.php';
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';

$id = $_GET['id'] ?? '';

$fpm = [];
$detail = [];

$analisa = [];
$flowout = [];

if ($id != '') {

    $sql = "SELECT * FROM fpm_header WHERE id_fpm = ? AND flow_type = 'CLAIMMACH'";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fpm = $result->fetch_assoc();

    $sql2 = "SELECT ng_area_percent, ng_total_percent FROM fpm_detail_masalah WHERE id_fpm = ?";
    $stmt2 = $koneksi->prepare($sql2);
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $detail = $result2->fetch_assoc();

    $sql3 = "SELECT * FROM fpm_analisa WHERE id_fpm = ?";
    $stmt3 = $koneksi->prepare($sql3);
    $stmt3->bind_param("i", $id);
    $stmt3->execute();
    $result3 = $stmt3->get_result();

    while ($row = $result3->fetch_assoc()) {

        if ($row['jenis'] == 'FLOW OUT') {
            $flowout[] = $row;
        } else {
            $analisa[] = $row;
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

    // Reschedule due date (maks 3x) disimpan langsung lewat tombol Reschedule (AJAX) -
    // lihat config/fpm_reschedule_helper.php.
    $rescheduleLogs   = fpmRescheduleLogs($koneksi, (int) $id);
    $rescheduleCounts = fpmRescheduleCounts($koneksi, (int) $id);
}

if (!$fpm) {
    die('Data FPM Claim Machining tidak ditemukan');
}

$uploadDir = "../uploads/fpm_dt/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        die("<script>alert('Upload gagal: total ukuran file terlalu besar untuk server (maks " . ini_get('post_max_size') . "). Kurangi ukuran/jumlah file lalu coba lagi.');history.back();</script>");
    }

    $id_fpm = (int) $_POST['id_fpm'];

    // Baris countermeasure di DB saat simpan, per id - sumber file lama & status lama.
    $barisCorrectiveById = array_column($corrective ?? [], null, 'id');
    $barisPreventiveById = array_column($preventive ?? [], null, 'id');
    $barisDokumenById    = array_column($dokumen ?? [], null, 'id');

    $koneksi->begin_transaction();

    try {

        // Field info FPM (kejadian berulang, NG%, nama/no part, mold, tgl masalah, model,
        // machine, jenis masalah) sengaja TIDAK di-UPDATE di sini - field itu di-lock untuk
        // Dept Head dkk, hanya Staff QA yang boleh mengubahnya lewat
        // staff/jawab_fpm_claim_machining.php.

        $koneksi->query("DELETE FROM fpm_analisa WHERE id_fpm='$id_fpm'");

        /* ================= OCCURRENCE ================= */
        if (!empty($_POST['analisa'])) {

            foreach (array_values($_POST['analisa']) as $i => $row) {

                // Baris yang Why-nya terisi tapi Category kosong dulu dibuang diam-diam
                // (jawaban user hilang tanpa pesan, kasus FPM id 53) - sekarang ditolak.
                if (empty($row['kategori'])) {
                    if (trim(implode('', array_intersect_key($row, array_flip(['why_1', 'why_2', 'why_3', 'why_4', 'why_5'])))) !== '') {
                        throw new Exception("Analisa Masalah (Occurrence) baris " . ($i + 1) . ": Category belum dipilih, padahal Why sudah diisi.");
                    }
                    continue;
                }

                $jenis = 'OCCURRENCE';

                $sql = "INSERT INTO fpm_analisa
                (id_fpm, jenis, kategori, why_1, why_2, why_3, why_4, why_5)
                VALUES (?,?,?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssssss",
                    $id_fpm,
                    $jenis,
                    $row['kategori'],
                    $row['why_1'],
                    $row['why_2'],
                    $row['why_3'],
                    $row['why_4'],
                    $row['why_5']
                );
                $stmt->execute();
            }
        }

        /* ================= FLOW OUT ================= */
        if (!empty($_POST['flowout'])) {

            foreach (array_values($_POST['flowout']) as $i => $row) {

                // Baris yang Why-nya terisi tapi Category kosong dulu dibuang diam-diam
                // (jawaban user hilang tanpa pesan, kasus FPM id 53) - sekarang ditolak.
                if (empty($row['kategori'])) {
                    if (trim(implode('', array_intersect_key($row, array_flip(['why_1', 'why_2', 'why_3', 'why_4', 'why_5'])))) !== '') {
                        throw new Exception("Analisa Masalah (Flow Out) baris " . ($i + 1) . ": Category belum dipilih, padahal Why sudah diisi.");
                    }
                    continue;
                }

                $jenis = 'FLOW OUT';

                $sql = "INSERT INTO fpm_analisa
                (id_fpm, jenis, kategori, why_1, why_2, why_3, why_4, why_5)
                VALUES (?,?,?,?,?,?,?,?)";

                $stmt = $koneksi->prepare($sql);
                $stmt->bind_param(
                    "isssssss",
                    $id_fpm,
                    $jenis,
                    $row['kategori'],
                    $row['why_1'],
                    $row['why_2'],
                    $row['why_3'],
                    $row['why_4'],
                    $row['why_5']
                );
                $stmt->execute();
            }
        }

        $koneksi->query("DELETE FROM fpm_corrective_action WHERE id_fpm='$id_fpm'");

        foreach ($_POST['corrective']['tindakan'] ?? [] as $i => $tindakan) {

            if ($tindakan == '') continue;

            // File yang sudah ada TIDAK ditimpa upload baru (diambil dari DB, bukan hidden input
            // old_file yang bisa basi); upload di baris yang sudah punya file jadi file tambahan.
            $barisCorrectiveDb = $barisCorrectiveById[(int) ($_POST['corrective']['id_lama'][$i] ?? 0)] ?? null;
            [$fileName, $fileJadiTambahan] = fileUtamaJawabTanpaTimpa(
                $koneksi, 'corrective', $barisCorrectiveDb, 'corrective_file', $i, $uploadDir, 'CORR', 'Corrective Action baris ' . ($i + 1)
            );

            $due_date    = !empty($_POST['corrective']['due_date'][$i]) ? $_POST['corrective']['due_date'][$i] : null;
            // fpm_corrective_action.actual_date adalah varchar NOT NULL, jadi string kosong bukan null.
            $actual_date = $_POST['corrective']['actual_date'][$i] ?? '';

            // Dept Head/Staff tidak boleh menutup status sendiri walau due_date/actual_date
            // sudah sama dan file sudah diupload - status CLOSED cuma boleh diset lewat
            // fitur Verifikasi Countermeasure oleh Staff QA. Di sini status lama (kalau
            // ada) sekadar dipertahankan supaya submit ulang tidak membalikkan status yang
            // sudah diverifikasi Staff QA jadi OPEN lagi.
            $statusLama = strtoupper(trim($barisCorrectiveDb['status'] ?? 'OPEN'));
            $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';

            $note = $_POST['corrective']['note'][$i] ?? '';

            $sql = "INSERT INTO fpm_corrective_action
            (id_fpm,tindakan,due_date,actual_date,pic,status,file,note)
            VALUES (?,?,?,?,?,?,?,?)";

            $stmt = $koneksi->prepare($sql);
            $stmt->bind_param(
                "isssssss",
                $id_fpm,
                $tindakan,
                $due_date,
                $actual_date,
                $_POST['corrective']['pic'][$i],
                $status,
                $fileName,
                $note
            );
            $stmt->execute();

            // Baris dihapus lalu di-insert ulang (id berganti) - log reschedule ikut pindah ke id baru.
            $idBaru = (int) $koneksi->insert_id;
            pindahkanRescheduleLogFpm($koneksi, (int) $id_fpm, 'CORRECTIVE', (int) ($_POST['corrective']['id_lama'][$i] ?? 0), $idBaru);
            simpanFileTambahanAksi($koneksi, 'corrective', $idBaru, $fileJadiTambahan);
        }

        $koneksi->query("DELETE FROM fpm_preventive_action WHERE id_fpm='$id_fpm'");

        foreach ($_POST['preventive']['tindakan'] ?? [] as $i => $tindakan) {

            if ($tindakan == '') continue;

            // File yang sudah ada TIDAK ditimpa upload baru (diambil dari DB, bukan hidden input
            // old_file yang bisa basi); upload di baris yang sudah punya file jadi file tambahan.
            $barisPreventiveDb = $barisPreventiveById[(int) ($_POST['preventive']['id_lama'][$i] ?? 0)] ?? null;
            [$fileName, $fileJadiTambahan] = fileUtamaJawabTanpaTimpa(
                $koneksi, 'preventive', $barisPreventiveDb, 'preventive_file', $i, $uploadDir, 'PREV', 'Preventive Action baris ' . ($i + 1)
            );

            $due_date    = !empty($_POST['preventive']['due_date'][$i]) ? $_POST['preventive']['due_date'][$i] : null;
            $actual_date = $_POST['preventive']['actual_date'][$i] ?? '';

            // Lihat catatan di blok Corrective Action - status CLOSED cuma boleh diset
            // lewat Verifikasi Countermeasure Staff QA, di sini cuma dipertahankan.
            $statusLama = strtoupper(trim($barisPreventiveDb['status'] ?? 'OPEN'));
            $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';

            $note = $_POST['preventive']['note'][$i] ?? '';

            $sql = "INSERT INTO fpm_preventive_action
            (id_fpm,tindakan,due_date,actual_date,pic,status,file,note)
            VALUES (?,?,?,?,?,?,?,?)";

            $stmt = $koneksi->prepare($sql);
            $stmt->bind_param(
                "isssssss",
                $id_fpm,
                $tindakan,
                $due_date,
                $actual_date,
                $_POST['preventive']['pic'][$i],
                $status,
                $fileName,
                $note
            );
            $stmt->execute();

            // Baris dihapus lalu di-insert ulang (id berganti) - log reschedule ikut pindah ke id baru.
            $idBaru = (int) $koneksi->insert_id;
            pindahkanRescheduleLogFpm($koneksi, (int) $id_fpm, 'PREVENTIVE', (int) ($_POST['preventive']['id_lama'][$i] ?? 0), $idBaru);
            simpanFileTambahanAksi($koneksi, 'preventive', $idBaru, $fileJadiTambahan);
        }

        $koneksi->query("DELETE FROM fpm_review_dokumen WHERE id_fpm='$id_fpm'");

        foreach ($_POST['dokumen']['dokumen'] ?? [] as $i => $dok) {

            if ($dok == '') continue;

            // File yang sudah ada TIDAK ditimpa upload baru (diambil dari DB, bukan hidden input
            // old_file yang bisa basi); upload di baris yang sudah punya file jadi file tambahan.
            $barisDokumenDb = $barisDokumenById[(int) ($_POST['dokumen']['id_lama'][$i] ?? 0)] ?? null;
            [$fileName, $fileJadiTambahan] = fileUtamaJawabTanpaTimpa(
                $koneksi, 'dokumen', $barisDokumenDb, 'dokumen_file', $i, $uploadDir, 'DOC', 'Update Dokumen baris ' . ($i + 1)
            );

            $due_date    = !empty($_POST['dokumen']['due_date'][$i]) ? $_POST['dokumen']['due_date'][$i] : null;
            // fpm_review_dokumen.actual_date adalah varchar NOT NULL, jadi string kosong bukan null.
            $actual_date = $_POST['dokumen']['actual_date'][$i] ?? '';

            // Lihat catatan di blok Corrective Action - status CLOSED cuma boleh diset
            // lewat Verifikasi Countermeasure Staff QA, diambil dari data DB ($dokumen),
            // bukan dari hidden input yang bisa dimanipulasi client.
            $statusLama = strtoupper(trim($barisDokumenDb['status'] ?? 'OPEN'));
            $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';

            $sql = "INSERT INTO fpm_review_dokumen
            (id_fpm,dokumen,due_date,actual_date,pic,status,file)
            VALUES (?,?,?,?,?,?,?)";

            $stmt = $koneksi->prepare($sql);
            $stmt->bind_param(
                "issssss",
                $id_fpm,
                $dok,
                $due_date,
                $actual_date,
                $_POST['dokumen']['pic'][$i],
                $status,
                $fileName
            );
            $stmt->execute();

            // Baris dihapus lalu di-insert ulang (id berganti) - log reschedule ikut pindah ke id baru.
            $idBaru = (int) $koneksi->insert_id;
            pindahkanRescheduleLogFpm($koneksi, (int) $id_fpm, 'DOKUMEN', (int) ($_POST['dokumen']['id_lama'][$i] ?? 0), $idBaru);
            simpanFileTambahanAksi($koneksi, 'dokumen', $idBaru, $fileJadiTambahan);
        }

        // Catat tanggal pembahasan di fpm_pengiriman.tgl. Dept sudah tetap sejak FPM dibuat,
        // jadi kolom departement diisi ulang dengan nilai yang sama (bukan dari form).
        $tglBahas = date('Y-m-d');
        $deptTerkait = $fpm['dept'] ?? '';

        $cekPengiriman = $koneksi->prepare("SELECT id FROM fpm_pengiriman WHERE id_fpm = ?");
        $cekPengiriman->bind_param("i", $id_fpm);
        $cekPengiriman->execute();
        $resultCekPengiriman = $cekPengiriman->get_result();

        if ($resultCekPengiriman->num_rows > 0) {
            $sqlPengiriman = "UPDATE fpm_pengiriman SET departement = ?, tgl = ? WHERE id_fpm = ?";
            $stmtPengiriman = $koneksi->prepare($sqlPengiriman);
            $stmtPengiriman->bind_param("ssi", $deptTerkait, $tglBahas, $id_fpm);
        } else {
            $sqlPengiriman = "INSERT INTO fpm_pengiriman (id_fpm, departement, tgl) VALUES (?, ?, ?)";
            $stmtPengiriman = $koneksi->prepare($sqlPengiriman);
            $stmtPengiriman->bind_param("iss", $id_fpm, $deptTerkait, $tglBahas);
        }
        $stmtPengiriman->execute();

        // cm_dept_head_dijawab_at cuma diisi sekali (dijawab pertama kali) - update
        // berikutnya tidak mengubah tanggal/nama penjawab pertama. tgl_terbit juga cuma
        // diisi sekali (tanggal submit pertama) - dipakai buat kolom "TGL TERBIT" di
        // form cetak, TIDAK lagi ditampilkan di kolom TGL tabel PENGIRIMAN SETELAH
        // PERBAIKAN (lihat form_fpm.php).
        $koneksi->query("UPDATE fpm_header
            SET cm_dept_head_dijawab_at = COALESCE(cm_dept_head_dijawab_at, NOW()),
                cm_dept_head_dijawab_oleh = COALESCE(cm_dept_head_dijawab_oleh, '" . $koneksi->real_escape_string($_SESSION['username']) . "'),
                tgl_terbit = COALESCE(tgl_terbit, '$tglBahas')
            WHERE id_fpm = '$id_fpm'");

        // FPM yang sedang diminta revisi (oleh Dept Head / Plant Head / Directur / Dept
        // Head QA) dianggap sudah direvisi begitu perbaikannya disubmit ulang di sini -
        // status REVISI dihapus (kembali OPEN) dan dicatat ke histori revisi. Sebelumnya
        // status_reject tidak pernah di-reset di alur Claim Machining, jadi FPM nyangkut
        // "REVISI" terus walau perbaikan sudah disubmit.
        $sedangRevisi = (($fpm['status_reject'] ?? '') === 'REJECT');
        if ($sedangRevisi) {
            $koneksi->query("UPDATE fpm_header SET status_reject = '', catatan_target_dept = NULL WHERE id_fpm = '$id_fpm'");
            catatFpmRevisiHistory($koneksi, $id_fpm, 'REVISI', 'Perbaikan FPM Claim Machining disubmit ulang', $_SESSION['username'] ?? '', $_SESSION['level'] ?? '');
        }

        // SOP: Jawab -> Approval Dept Terkait -> Approval Plant Head -> Approval Direktur.
        // Kalau yang submit adalah Dept Head dari dept terkait itu sendiri, submit ini
        // sekaligus = approval Dept Terkait (penjawab = approver), jadi FPM langsung
        // menunggu approval Plant Head. Kalau yang submit Staff, tetap menunggu Dept Head
        // approve lewat form_fpm.php.
        $autoApproveDeptHead = ($_SESSION['dept'] ?? '') !== ''
            && ($_SESSION['dept'] ?? '') === ($fpm['dept'] ?? '')
            && empty($fpm['cm_dept_head_approved_at']);
        if ($autoApproveDeptHead) {
            $koneksi->query("UPDATE fpm_header
                SET cm_dept_head_approved_at = NOW(),
                    cm_dept_head_approved_oleh = '" . $koneksi->real_escape_string($_SESSION['username']) . "'
                WHERE id_fpm = '$id_fpm'");
        }

        $koneksi->commit();

        require_once __DIR__ . '/../config/wa_map.php';
        require_once __DIR__ . '/../services/whatsapp.php';

        global $waMap, $waMapPlantHead, $waStaffQA;

        // Notifikasi ditujukan ke dept head + staff dari setiap dept yang jadi PIC
        // countermeasure (corrective/preventive), bukan cuma dept terkait di header FPM.
        $picDepts = [$deptTerkait];

        foreach (($_POST['corrective']['pic'] ?? []) as $picDept) {
            if (!empty($picDept)) {
                $picDepts[] = $picDept;
            }
        }

        foreach (($_POST['preventive']['pic'] ?? []) as $picDept) {
            if (!empty($picDept)) {
                $picDepts[] = $picDept;
            }
        }

        $picDepts = array_unique($picDepts);
        $waTujuan = [];

        foreach ($picDepts as $picDept) {
            $waTujuan = array_merge(
                $waTujuan,
                $waMap[$picDept]['depthead'] ?? [],
                $waMap[$picDept]['staffdept'] ?? []
            );
        }

        $waTujuan = array_unique(array_filter($waTujuan));

        if (!empty($waTujuan)) {
            $waTarget = implode(',', $waTujuan);
            $pesan = "FPM Claim Machining (dept terkait: *$deptTerkait*) sudah diupdate perbaikannya. No Form: " . ($fpm['no_form'] ?? $id_fpm);
            $terkirim = kirimWA($waTarget, $pesan);

            if (!$terkirim) {
                error_log("Gagal kirim WA update perbaikan FPM Claim Machining: $id_fpm");
            }
        }

        // Alur SOP "Flow FPM Claim Machining": setelah dijawab, FPM masih perlu diapprove
        // dulu oleh Dept Head (dept terkait) via form_fpm.php sebelum Plant Head dinotif -
        // jadi notifikasi ke Plant Head TIDAK dikirim di sini lagi (lihat
        // dept_head/form_fpm.php, handler approve_dept_head_claim_mach). Staff QA tetap
        // dinotif di sini sebagai info (FYI) bahwa jawaban sudah masuk.
        $waStaffQaTujuan = array_unique(array_filter($waStaffQA ?? []));

        if (!empty($waStaffQaTujuan)) {
            $waTargetStaffQa = implode(',', $waStaffQaTujuan);
            $pesanStaffQa = "FPM Claim Machining (dept terkait: *$deptTerkait*) sudah dijawab Dept Head. No Form: " . ($fpm['no_form'] ?? $id_fpm);
            $terkirimStaffQa = kirimWA($waTargetStaffQa, $pesanStaffQa);

            if (!$terkirimStaffQa) {
                error_log("Gagal kirim WA FYI Staff QA FPM Claim Machining: $id_fpm");
            }
        }

        if ($autoApproveDeptHead) {
            $plantKeyApprove = trim(str_ireplace('Plant', '', $fpm['plant'] ?? ''));
            $waApprovalTujuan = array_unique(array_filter($waMapPlantHead[$plantKeyApprove] ?? []));

            if (!empty($waApprovalTujuan)) {
                $pesanApproval = "FPM Claim Machining (dept terkait: *$deptTerkait*) sudah dijawab & diapprove Dept Head, mohon di-approve. No Form: " . ($fpm['no_form'] ?? $id_fpm);
                if (!kirimWA(implode(',', $waApprovalTujuan), $pesanApproval)) {
                    error_log("Gagal kirim WA approval Plant Head FPM Claim Machining: $id_fpm");
                }
            }
        }

        echo "<script>alert('Hasil perbaikan berhasil disimpan');window.location='list_fpm_claim_machining.php';</script>";
    } catch (Exception $e) {

        $koneksi->rollback();
        echo "<script>alert(" . json_encode("Gagal update: " . $e->getMessage()) . ");history.back();</script>";
    }
}

$master_dept = [];

$qDept = $koneksi->query("SELECT nama_dept FROM master_dept ORDER BY nama_dept ASC");

while ($rowDept = $qDept->fetch_assoc()) {
    $master_dept[] = $rowDept['nama_dept'];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Update Perbaikan FPM Claim Machining</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
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
                <h1>Update Perbaikan - FPM Claim Machining</h1>

                <?php
                // Revisi countermeasure dari Staff QA: upload perbaikannya lewat menu Permintaan Revisi
                // (halaman ini menyimpan ulang semua baris sehingga id barisnya berganti).
                require_once __DIR__ . '/../config/countermeasure_revisi_helper.php';
                echo renderBannerRevisiPic($koneksi, 'FPM', (int) $id, $_SESSION['dept'] ?? '');
                ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_fpm" value="<?= $id ?>">

                    <div class="info-box">
                        <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">

                            <div class="label">No Reg FPM</div>
                            <input type="text" name="no_reg_fpm"
                                value="<?= htmlspecialchars($fpm['no_reg_fpm'] ?? '') ?>" readonly>

                            <div class="label">Kejadian Berulang ke</div>
                            <input type="number" name="kejadian_ulang"
                                value="<?= htmlspecialchars($fpm['kejadian_berulang_ke'] ?? '') ?>" readonly>

                            <div class="label">NG Area (%)</div>
                            <input type="number" step="0.01" min="0" max="100" name="ng_area_percent"
                                value="<?= htmlspecialchars($detail['ng_area_percent'] ?? '') ?>" readonly>

                            <div class="label">NG Total (%)</div>
                            <input type="number" step="0.01" min="0" max="100" name="ng_total_percent"
                                value="<?= htmlspecialchars($detail['ng_total_percent'] ?? '') ?>" readonly>

                            <div class="label">Nama Part</div>
                            <input type="text" name="nama_part"
                                value="<?= htmlspecialchars($fpm['nama_part'] ?? '') ?>" readonly>

                            <div class="label">No Part</div>
                            <input type="text" name="no_part"
                                value="<?= htmlspecialchars($fpm['no_part'] ?? '') ?>" readonly>

                            <div class="label">Departemen Terkait</div>
                            <input type="text" value="<?= htmlspecialchars($fpm['dept'] ?? '') ?>" readonly>

                            <div class="label">Mold #</div>
                            <input type="text" name="mold_no"
                                value="<?= htmlspecialchars($fpm['mold_no'] ?? '') ?>" readonly>

                            <div class="label">Tgl Masalah</div>
                            <input type="text" name="tgl_ditemukan"
                                value="<?= !empty($fpm['tgl_ditemukan']) ? date('d-m-Y', strtotime($fpm['tgl_ditemukan'])) : '' ?>" readonly>

                            <div class="label">Model</div>
                            <input type="text" name="model"
                                value="<?= htmlspecialchars($fpm['model'] ?? '') ?>" readonly>

                            <div class="label">Machine</div>
                            <input type="text" name="machine_no"
                                value="<?= htmlspecialchars($fpm['machine_no'] ?? '') ?>" readonly>

                            <div class="label">Jenis Masalah</div>
                            <input type="text" name="jenis_masalah"
                                value="<?= htmlspecialchars($fpm['jenis_masalah'] ?? '') ?>" readonly>

                            <div class="label">Detail Jenis Masalah</div>
                            <input type="text" name="jenis_masalah_detail"
                                value="<?= htmlspecialchars($fpm['jenis_masalah_detail'] ?? '') ?>" readonly>

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
                            <th>Action</th>
                        </tr>

                        <?php if (!empty($analisa)): ?>
                            <?php $no = 0;
                            foreach ($analisa as $row): ?>
                                <tr>
                                    <td>
                                        <select name="analisa[<?= $no ?>][kategori]">
                                            <option value="">-- Pilih --</option>
                                            <option value="MAN" <?= $row['kategori'] == 'MAN' ? 'selected' : '' ?>>Man</option>
                                            <option value="METHOD" <?= $row['kategori'] == 'METHOD' ? 'selected' : '' ?>>Method</option>
                                            <option value="MATERIAL" <?= $row['kategori'] == 'MATERIAL' ? 'selected' : '' ?>>Material</option>
                                            <option value="MACHINE" <?= $row['kategori'] == 'MACHINE' ? 'selected' : '' ?>>Machine</option>
                                        </select>
                                    </td>

                                    <td><textarea name="analisa[<?= $no ?>][why_1]"><?= htmlspecialchars($row['why_1']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_2]"><?= htmlspecialchars($row['why_2']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_3]"><?= htmlspecialchars($row['why_3']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_4]"><?= htmlspecialchars($row['why_4']) ?></textarea></td>
                                    <td><textarea name="analisa[<?= $no ?>][why_5]"><?= htmlspecialchars($row['why_5']) ?></textarea></td>

                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addAnalisaRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php $no++;
                            endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <select name="analisa[0][kategori]">
                                        <option value="">-- Pilih --</option>
                                        <option value="MAN">Man</option>
                                        <option value="METHOD">Method</option>
                                        <option value="MATERIAL">Material</option>
                                        <option value="MACHINE">Machine</option>
                                    </select>
                                </td>

                                <td><textarea name="analisa[0][why_1]"></textarea></td>
                                <td><textarea name="analisa[0][why_2]"></textarea></td>
                                <td><textarea name="analisa[0][why_3]"></textarea></td>
                                <td><textarea name="analisa[0][why_4]"></textarea></td>
                                <td><textarea name="analisa[0][why_5]"></textarea></td>

                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addAnalisaRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="section-title">Analisa Masalah (Flow Out)</div>

                    <table id="flowoutTable">
                        <tr>
                            <th>Category</th>
                            <th>Why 1</th>
                            <th>Why 2</th>
                            <th>Why 3</th>
                            <th>Why 4</th>
                            <th>Why 5</th>
                            <th>Action</th>
                        </tr>

                        <?php if (!empty($flowout)): ?>
                            <?php $no = 0;
                            foreach ($flowout as $row): ?>
                                <tr>
                                    <td>
                                        <select name="flowout[<?= $no ?>][kategori]">
                                            <option value="">-- Pilih --</option>
                                            <option value="MAN" <?= $row['kategori'] == 'MAN' ? 'selected' : '' ?>>Man</option>
                                            <option value="METHOD" <?= $row['kategori'] == 'METHOD' ? 'selected' : '' ?>>Method</option>
                                            <option value="MATERIAL" <?= $row['kategori'] == 'MATERIAL' ? 'selected' : '' ?>>Material</option>
                                            <option value="MACHINE" <?= $row['kategori'] == 'MACHINE' ? 'selected' : '' ?>>Machine</option>
                                        </select>
                                    </td>

                                    <td><textarea name="flowout[<?= $no ?>][why_1]"><?= htmlspecialchars($row['why_1']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_2]"><?= htmlspecialchars($row['why_2']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_3]"><?= htmlspecialchars($row['why_3']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_4]"><?= htmlspecialchars($row['why_4']) ?></textarea></td>
                                    <td><textarea name="flowout[<?= $no ?>][why_5]"><?= htmlspecialchars($row['why_5']) ?></textarea></td>

                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addFlowoutRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php $no++;
                            endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td>
                                    <select name="flowout[0][kategori]">
                                        <option value="">-- Pilih --</option>
                                        <option value="MAN">Man</option>
                                        <option value="METHOD">Method</option>
                                        <option value="MATERIAL">Material</option>
                                        <option value="MACHINE">Machine</option>
                                    </select>
                                </td>

                                <td><textarea name="flowout[0][why_1]"></textarea></td>
                                <td><textarea name="flowout[0][why_2]"></textarea></td>
                                <td><textarea name="flowout[0][why_3]"></textarea></td>
                                <td><textarea name="flowout[0][why_4]"></textarea></td>
                                <td><textarea name="flowout[0][why_5]"></textarea></td>

                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addFlowoutRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="section-title">Corrective Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table" id="correctiveTable">
                        <tr>
                            <th>Corrective Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>File</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                        <?php if (!empty($corrective)): ?>
                            <?php foreach ($corrective as $row): ?>
                                <tr>
                                    <td><textarea name="corrective[tindakan][]"><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                    <td>
                                        <input type="date" name="corrective[due_date][]" value="<?= $row['due_date'] ?>">
                                        <?= renderTombolRescheduleFpm('CORRECTIVE', $row, $fpm, $rescheduleCounts ?? [], $_SESSION['level'] ?? '', $_SESSION['dept'] ?? '') ?>
                                        <input type="hidden" name="corrective[id_lama][]" value="<?= (int) $row['id'] ?>">
                                    </td>
                                    <td><input type="date" name="corrective[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <select name="corrective[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($master_dept as $dept): ?>
                                                <option value="<?= htmlspecialchars($dept) ?>"
                                                    <?= ($row['pic'] == $dept) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($dept) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="file" name="corrective_file[]" data-bukti-ui>
                                        <input type="hidden" name="corrective[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <input type="hidden" name="corrective[old_status][]" value="<?= htmlspecialchars($row['status'] ?? 'OPEN') ?>">
                                        <?php if (!empty($row['file'])): ?>
                                            <?php
                                            $filePath = "../uploads/fpm_dt/" . $row['file'];
                                            $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <img src="<?= $filePath ?>">
                                                    </a>
                                                <?php elseif ($ext == 'pdf'): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <iframe src="<?= $filePath ?>"></iframe>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><textarea name="corrective[note][]"><?= htmlspecialchars($row['note'] ?? '') ?></textarea></td>
                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><textarea name="corrective[tindakan][]"></textarea></td>
                                <td>
                                    <input type="date" name="corrective[due_date][]">
                                    <input type="hidden" name="corrective[id_lama][]" value="">
                                </td>
                                <td><input type="date" name="corrective[actual_date][]"></td>
                                <td>
                                    <select name="corrective[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($master_dept as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="file" name="corrective_file[]" data-bukti-ui>
                                    <input type="hidden" name="corrective[old_status][]" value="OPEN">
                                </td>
                                <td><textarea name="corrective[note][]"></textarea></td>
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Preventive Action</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table class="action-table" id="preventiveTable">
                        <tr>
                            <th>Preventive Action</th>
                            <th>Due Date</th>
                            <th>Actual Date</th>
                            <th>PIC</th>
                            <th>File</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                        <?php if (!empty($preventive)): ?>
                            <?php foreach ($preventive as $row): ?>
                                <tr>
                                    <td><textarea name="preventive[tindakan][]"><?= htmlspecialchars($row['tindakan']) ?></textarea></td>
                                    <td>
                                        <input type="date" name="preventive[due_date][]" value="<?= $row['due_date'] ?>">
                                        <?= renderTombolRescheduleFpm('PREVENTIVE', $row, $fpm, $rescheduleCounts ?? [], $_SESSION['level'] ?? '', $_SESSION['dept'] ?? '') ?>
                                        <input type="hidden" name="preventive[id_lama][]" value="<?= (int) $row['id'] ?>">
                                    </td>
                                    <td><input type="date" name="preventive[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <select name="preventive[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($master_dept as $dept): ?>
                                                <option value="<?= htmlspecialchars($dept) ?>"
                                                    <?= ($row['pic'] == $dept) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($dept) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="file" name="preventive_file[]" data-bukti-ui>
                                        <input type="hidden" name="preventive[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <input type="hidden" name="preventive[old_status][]" value="<?= htmlspecialchars($row['status'] ?? 'OPEN') ?>">
                                        <?php if (!empty($row['file'])): ?>
                                            <?php
                                            $filePath = "../uploads/fpm_dt/" . $row['file'];
                                            $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <img src="<?= $filePath ?>">
                                                    </a>
                                                <?php elseif ($ext == 'pdf'): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <iframe src="<?= $filePath ?>"></iframe>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><textarea name="preventive[note][]"><?= htmlspecialchars($row['note'] ?? '') ?></textarea></td>
                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><textarea name="preventive[tindakan][]"></textarea></td>
                                <td>
                                    <input type="date" name="preventive[due_date][]">
                                    <input type="hidden" name="preventive[id_lama][]" value="">
                                </td>
                                <td><input type="date" name="preventive[actual_date][]"></td>
                                <td>
                                    <select name="preventive[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($master_dept as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="file" name="preventive_file[]" data-bukti-ui>
                                    <input type="hidden" name="preventive[old_status][]" value="OPEN">
                                </td>
                                <td><textarea name="preventive[note][]"></textarea></td>
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <div class="section-title">Update Dokumen</div>
                    <small class="upload-video-hint">Maksimal upload video 10MB</small>
                    <table id="dokumenTable">
                        <tr>
                            <th>Dokumen</th>
                            <th>Due Date</th>
                            <th>Actual</th>
                            <th>PIC</th>
                            <th>File</th>
                            <th>Action</th>
                        </tr>
                        <?php if (!empty($dokumen)): ?>
                            <?php foreach ($dokumen as $row): ?>
                                <tr>
                                    <td>
                                        <input list="docType" name="dokumen[dokumen][]"
                                            value="<?= htmlspecialchars($row['dokumen']) ?>">
                                    </td>
                                    <td>
                                        <input type="date" name="dokumen[due_date][]" value="<?= $row['due_date'] ?>">
                                        <?= renderTombolRescheduleFpm('DOKUMEN', $row, $fpm, $rescheduleCounts ?? [], $_SESSION['level'] ?? '', $_SESSION['dept'] ?? '') ?>
                                        <input type="hidden" name="dokumen[id_lama][]" value="<?= (int) $row['id'] ?>">
                                    </td>
                                    <td><input type="date" name="dokumen[actual_date][]" value="<?= $row['actual_date'] ?>"></td>
                                    <td>
                                        <select name="dokumen[pic][]">
                                            <option value="">-- Pilih Dept --</option>
                                            <?php foreach ($master_dept as $dept): ?>
                                                <option value="<?= htmlspecialchars($dept) ?>"
                                                    <?= ($row['pic'] == $dept) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($dept) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="file" name="dokumen_file[]" data-bukti-ui>
                                        <input type="hidden" name="dokumen[old_file][]" value="<?= htmlspecialchars($row['file'] ?? '') ?>">
                                        <input type="hidden" name="dokumen[old_status][]" value="<?= htmlspecialchars($row['status'] ?? 'OPEN') ?>">
                                        <?php if (!empty($row['file'])): ?>
                                            <?php
                                            $filePath = "../uploads/fpm_dt/" . $row['file'];
                                            $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
                                            ?>
                                            <div class="file-preview">
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <img src="<?= $filePath ?>">
                                                    </a>
                                                <?php elseif ($ext == 'pdf'): ?>
                                                    <a href="<?= $filePath ?>" target="_blank">
                                                        <iframe src="<?= $filePath ?>"></iframe>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                        <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <input list="docType" name="dokumen[dokumen][]">
                                </td>
                                <td>
                                    <input type="date" name="dokumen[due_date][]">
                                    <input type="hidden" name="dokumen[id_lama][]" value="">
                                </td>
                                <td><input type="date" name="dokumen[actual_date][]"></td>
                                <td>
                                    <select name="dokumen[pic][]">
                                        <option value="">-- Pilih Dept --</option>
                                        <?php foreach ($master_dept as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="file" name="dokumen_file[]" data-bukti-ui>
                                    <input type="hidden" name="dokumen[old_status][]" value="OPEN">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn-action add" onclick="addRow(this)">+</button>
                                    <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </table>

                    <datalist id="docType">
                        <option value="IK">
                        <option value="FMEA">
                        <option value="QCPC">
                        <option value="Check Sheet">
                    </datalist>

                    <?= renderHistoryRescheduleFpm($rescheduleLogs ?? []) ?>

                    <div class="btn-group">
                        <button class="btn btn-submit">Simpan Perbaikan</button>
                        <a href="list_fpm_claim_machining.php" class="btn btn-cancel" style="text-decoration:none; display:inline-flex; align-items:center;">Cancel</a>
                    </div>

                </form>

                <?= renderAsetRescheduleFpm((int) $id) ?>

            </div>

        </main>
    </div>

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
                    const file = e.target.files[0];
                    if (file && isVideoFile(file) && file.size > MAX_VIDEO_SIZE) {
                        alert('Ukuran video melebihi 10MB. Silakan pilih file video yang lebih kecil.');
                        e.target.value = '';
                    }
                }
            });

            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    // Baris analisa yang Why-nya diisi tapi Category kosong tidak akan tersimpan.
                    for (const [tableId, label] of [['analisaTable', 'Occurrence'], ['flowoutTable', 'Flow Out']]) {
                        const rows = form.querySelectorAll('#' + tableId + ' tr');
                        for (let i = 1; i < rows.length; i++) {
                            const select = rows[i].querySelector('select');
                            const adaWhy = Array.from(rows[i].querySelectorAll('textarea')).some(t => t.value.trim() !== '');
                            if (select && select.value === '' && adaWhy) {
                                alert('Analisa Masalah (' + label + ') baris ' + i + ': pilih Category dulu, kalau tidak isi Why-nya tidak akan tersimpan.');
                                e.preventDefault();
                                select.focus();
                                return false;
                            }
                        }
                    }

                    const fileInputs = form.querySelectorAll('input[type="file"]');
                    for (const input of fileInputs) {
                        const file = input.files[0];
                        if (file && isVideoFile(file) && file.size > MAX_VIDEO_SIZE) {
                            alert('Ukuran video melebihi 10MB. Perbaiki file sebelum submit.');
                            e.preventDefault();
                            input.focus();
                            return false;
                        }
                    }
                });
            });
        })();

        // Index baris baru = index terbesar yang ada + 1. Dulu pakai jumlah baris, jadi setelah
        // ada baris yang dihapus, baris baru bisa memakai index yang sama dengan baris lama dan
        // isinya menimpa jawaban yang sudah diketik saat disubmit.
        function nextRowIndex(table, prefix) {
            let max = -1;
            table.querySelectorAll('[name^="' + prefix + '["]').forEach(el => {
                const m = el.name.match(/^\w+\[(\d+)\]/);
                if (m) max = Math.max(max, parseInt(m[1], 10));
            });
            return max + 1;
        }

        function addFlowoutRow(button) {
            let table = document.getElementById("flowoutTable");
            let rowCount = nextRowIndex(table, "flowout");

            let newRow = table.insertRow();
            newRow.innerHTML = `
        <td>
            <select name="flowout[${rowCount}][kategori]">
                <option value="">-- Pilih --</option>
                <option value="MAN">Man</option>
                <option value="METHOD">Method</option>
                <option value="MATERIAL">Material</option>
                <option value="MACHINE">Machine</option>
            </select>
        </td>
        <td><textarea name="flowout[${rowCount}][why_1]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_2]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_3]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_4]"></textarea></td>
        <td><textarea name="flowout[${rowCount}][why_5]"></textarea></td>
        <td class="text-center">
            <button type="button" class="btn-action add" onclick="addFlowoutRow(this)">+</button>
            <button type="button" class="btn-action remove" onclick="removeRow(this)">−</button>
        </td>
    `;
        }

        function addAnalisaRow(btn) {
            const table = document.getElementById("analisaTable");
            const rows = table.querySelectorAll("tr");
            const lastRow = rows[rows.length - 1];

            const newIndex = nextRowIndex(table, "analisa");

            const newRow = lastRow.cloneNode(true);

            newRow.querySelectorAll("textarea").forEach(el => el.value = "");
            newRow.querySelectorAll("select").forEach(el => el.selectedIndex = 0);

            newRow.querySelectorAll("select, textarea").forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, "[" + newIndex + "]");
            });

            table.appendChild(newRow);
        }

        function addRow(btn) {
            const row = btn.closest("tr");
            const table = row.closest("table");
            const newRow = row.cloneNode(true);
            // tombol reschedule milik baris asal - baris baru belum tersimpan, belum bisa di-reschedule
            newRow.querySelectorAll('.fpm-rs-box').forEach(el => el.remove());

            newRow.querySelectorAll("input, textarea").forEach(el => {
                el.value = "";
            });

            newRow.querySelectorAll("select").forEach(el => {
                el.selectedIndex = 0;
            });

            newRow.querySelectorAll(".file-preview").forEach(el => {
                el.remove();
            });

            newRow.querySelectorAll('input[type="file"]').forEach(el => {
                el.value = "";
            });

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
                if (index === 0) return;
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

<?php require_once __DIR__ . '/../config/upload_bukti_ui_helper.php'; echo renderUploadBuktiUI(); ?>
</body>

</html>
