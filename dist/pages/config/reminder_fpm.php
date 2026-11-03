<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

set_time_limit(0);

include __DIR__ . '/../koneksi.php';

if (mysqli_connect_errno()) {
    die("Koneksi database gagal : " . mysqli_connect_error());
}

date_default_timezone_set("Asia/Jakarta");

include __DIR__ . '/wa_map.php';
require_once __DIR__ . '/../services/whatsapp.php';
require_once __DIR__ . '/app_setting_helper.php';

$currentTime = date("H:i");

// Jam kirim reminder diatur Admin IT (app_setting reminder_jam_mulai / reminder_jam_selesai).
if ($currentTime < appSetting('reminder_jam_mulai') || $currentTime > appSetting('reminder_jam_selesai')) {
    exit;
}

$day = date("N");

if ($day >= 6) {
    exit;
}

// Dibungkus lewat kirimWA() di services/whatsapp.php supaya ikut gating
// WA_NOTIF_ENABLED (mati otomatis di local/XAMPP, aktif otomatis di hosting)
// dan tidak lagi pakai token hardcode terpisah.
// Return true kalau minimal 1 nomor berhasil dikirimi - dipakai supaya log harian
// hanya dicatat saat WA benar-benar terkirim (gagal kirim dicoba lagi di run cron
// berikutnya hari itu, bukan hilang sampai besok).
function sendWA($targets, $message)
{
    if (!$targets || !is_array($targets)) return false;

    $terkirim = false;

    foreach ($targets as $target) {
        if ($target == "") continue;
        if (kirimWA($target, $message)) $terkirim = true;
    }

    return $terkirim;
}

// FPM IMP (Internal / Down Time) - FPM QA & Claim Machining punya reminder sendiri.
function isFlowImp($flowType)
{
    return !in_array(trim($flowType ?? ''), ['QA', 'CLAIMMACH'], true);
}

function hariKerjaLewat($tanggal)
{

    if (!$tanggal) return 0;

    $start = new DateTime($tanggal);
    $today = new DateTime();

    $days = 0;

    while ($start < $today) {

        $start->modify('+1 day');

        if ($start->format('N') < 6) {
            $days++;
        }
    }

    return $days;
}

function sudahKirimHariIni($koneksi, $ref_id, $jenis)
{

    $today = date("Y-m-d");

    $q = mysqli_query($koneksi, "SELECT id 
    FROM wa_reminder_log 
    WHERE id_fpm='$ref_id'
    AND jenis_reminder='$jenis'
    AND tanggal='$today'");

    return mysqli_num_rows($q) > 0;
}

function sudahKirimSejak($koneksi, $ref_id, $jenis, $sejak)
{
    $ref_id = (int) $ref_id;

    $q = mysqli_query($koneksi, "SELECT id
    FROM wa_reminder_log
    WHERE id_fpm='$ref_id'
    AND jenis_reminder='$jenis'
    AND tanggal >= '$sejak'
    LIMIT 1");

    return $q && mysqli_num_rows($q) > 0;
}

// H-2 (default, diatur Admin IT lewat reminder_h_due) sebelum due date. Script tidak jalan
// Sabtu/Minggu, jadi kalau H-2 jatuh di akhir pekan reminder dimajukan ke hari kerja
// sebelumnya (Jumat) supaya tidak terlewat.
function tanggalReminderH2($dueDate)
{
    $d = new DateTime(date('Y-m-d', strtotime($dueDate . ' -' . appSettingInt('reminder_h_due') . ' days')));
    while ($d->format('N') >= 6) {
        $d->modify('-1 day');
    }
    return $d->format('Y-m-d');
}

function simpanLogWA($koneksi, $ref_id, $jenis)
{

    $today = date("Y-m-d");

    mysqli_query($koneksi, "INSERT INTO wa_reminder_log
    (id_fpm,jenis_reminder,tanggal)
    VALUES
    ('$ref_id','$jenis','$today')")
        or die(mysqli_error($koneksi));
}

/*
=====================================================
REMINDER PROSES FPM (flow "reminder approved")
=====================================================
*/

$q = mysqli_query($koneksi, "SELECT
h.id_fpm,
h.no_form,
h.dept,
h.internal_dept,
h.flow_type,
h.plant,
h.disetujui_oleh,
h.tgl_disetujui_imp,
h.diterima_pic,
p.tgl_dibuat,
p.tgl_diperiksa,
p.tgl_disetujui,
h.diterima_imp,
(SELECT COUNT(*) FROM fpm_corrective_action WHERE id_fpm = h.id_fpm)
    + (SELECT COUNT(*) FROM fpm_preventive_action WHERE id_fpm = h.id_fpm) AS jml_countermeasure
FROM fpm_header h
LEFT JOIN fpm_pengiriman p
ON h.id_fpm = p.id_fpm
");

$today = date("Y-m-d");

while ($row = mysqli_fetch_assoc($q)) {

    $id_fpm            = $row['id_fpm'];
    $no_form           = $row['no_form'];
    // Dept terkait (yang wajib menjawab) ada di kolom dept - internal_dept tidak ikut
    // berubah saat Staff IMP mengganti dept terkait lewat Edit FPM, jadi reminder dulu
    // bisa nyasar ke Dept Head dept lama.
    $nama_dept         = trim($row['dept'] ?? '') !== '' ? $row['dept'] : $row['internal_dept'];
    $plant             = $row['plant'];
    $tgl_disetujui_imp = $row['tgl_disetujui_imp']; //head imp approve
    $tgl_dibuat        = $row['tgl_dibuat']; //dept head
    $tgl_diperiksa     = $row['tgl_diperiksa']; //plant head
    $diterima_imp      = $row['diterima_imp']; //imp verifikasi
    $tgl_disetujui     = $row['tgl_disetujui']; //directure

    $waDeptHead  = $waMap[$nama_dept]['depthead'] ?? [];
    $waPlantHead = $waMap[$nama_dept]['planthead'] ?? [];

    if ($plant == '1') {
        $waStaffIMP = $waStaffIMP1 ?? [];
        $waIMPHead  = $waIMPHead1 ?? [];
    } elseif ($plant == '3') {
        $waStaffIMP = $waStaffIMP3 ?? [];
        $waIMPHead  = $waIMPHead3 ?? [];
    } else {
        $waStaffIMP = [];
        $waIMPHead  = [];
    }

    /*
    ================================
    RULE 1: belum dijawab Dept terkait, dihitung dari tgl_disetujui_imp.
    WA ke Dept Head setiap hari kerja sampai FPM dijawab (corrective/preventive
    action sudah diisi). Dulu patokannya fpm_pengiriman.tgl_dibuat, yang cuma
    terisi kalau Dept Head menjawab sendiri lewat jawab_fpm.php - FPM yang dijawab
    Staff dept / di-approve lewat tombol Approved tetap ditagih "segera dijawab".
    ================================
    */

    if (
        isFlowImp($row['flow_type'])
        && trim($row['disetujui_oleh'] ?? '') === 'Imp Head'
        && (int) $row['jml_countermeasure'] === 0
        && hariKerjaLewat($tgl_disetujui_imp) >= 1
        && !sudahKirimHariIni($koneksi, $id_fpm, 'jawab_fpm')
    ) {

        $infoDue = '';
        if (!empty($row['diterima_pic']) && $row['diterima_pic'] !== '0000-00-00') {
            // Due date jawab = N hari sejak diterima Dept (reminder_eskalasi_hari, default 7).
            $dueJawab = date('Y-m-d', strtotime($row['diterima_pic'] . ' +' . appSettingInt('reminder_eskalasi_hari') . ' days'));
            $sisaHari = (int) floor((strtotime($dueJawab) - strtotime($today)) / 86400);
            $infoDue  = "\n Due Date Jawab : " . date('d-m-Y', strtotime($dueJawab))
                . ($sisaHari < 0 ? " (sudah lewat " . abs($sisaHari) . " hari)" : ($sisaHari === 0 ? " (hari ini)" : " ($sisaHari hari lagi)"));
        }

        $msg = "*Reminder FPM Belum Dijawab*\n\n FPM Untuk Dept : $nama_dept\n No FPM : $no_form$infoDue\n Mohon segera dijawab.";

        if (sendWA($waDeptHead, $msg)) {
            simpanLogWA($koneksi, $id_fpm, 'jawab_fpm');
        }
    }

    /*
    ================================
    RULE 2: belum diperiksa Plant Head, dihitung dari tgl_dibuat
    ================================
    */

    if ($tgl_dibuat && !$tgl_diperiksa) {

        if (hariKerjaLewat($tgl_dibuat) >= 1) {

            if (!sudahKirimHariIni($koneksi, $id_fpm, 'periksa_fpm')) {

                $msg = "*Reminder Pemeriksaan FPM*\n\n FPM Dari Dept : $nama_dept\n No FPM : $no_form\n Mohon segera diperiksa.";

                sendWA($waPlantHead, $msg);

                simpanLogWA($koneksi, $id_fpm, 'periksa_fpm');
            }
        }
    }

    /*
    ================================
    RULE 3: belum diverifikasi IMP, dihitung dari tgl_diperiksa
    ================================
    */

    if ($tgl_diperiksa && !$diterima_imp) {

        if (hariKerjaLewat($tgl_diperiksa) >= 1) {

            if (!sudahKirimHariIni($koneksi, $id_fpm, 'review_imp')) {

                $msg = "*Reminder Verifikasi IMP*\n\n FPM Dept : $nama_dept\n No FPM : $no_form\n Mohon segera diverifikasi.";

                sendWA($waStaffIMP, $msg);
                sendWA($waIMPHead, $msg);

                simpanLogWA($koneksi, $id_fpm, 'review_imp');
            }
        }
    }

    /*
    ================================
    RULE 4: belum disetujui Directure, dihitung dari diterima_imp
    ================================
    */

    if ($diterima_imp && !$tgl_disetujui) {

        if (hariKerjaLewat($diterima_imp) >= 1) {

            if (!sudahKirimHariIni($koneksi, $id_fpm, 'setuju_directure')) {

                $msg = "*Reminder Persetujuan FPM*\n\n FPM Dept : $nama_dept\n No FPM : $no_form\n Mohon segera disetujui.";

                sendWA($waDirecture, $msg);

                simpanLogWA($koneksi, $id_fpm, 'setuju_directure');
            }
        }
    }
}




/*
=====================================================
REMINDER COUNTERMEASURE (flow "reminder countermeasure")
=====================================================
*/

/*
================================
RULE 1 & 2: FPM sudah diterima PIC (diterima_pic) tapi corrective &
preventive action belum ada satupun -> eskalasi ke Plant Head + Directure
jika lebih dari H+7. Reminder harian ke Dept Head (termasuk info due date
jawab H+7) sudah dikirim RULE 1 reminder proses di atas, jadi tidak dikirim
dobel di sini.
================================
*/

$qBelumDijawab = mysqli_query($koneksi, "SELECT
    h.id_fpm,
    h.no_form,
    h.dept,
    h.internal_dept,
    h.flow_type,
    h.diterima_pic,
    (SELECT COUNT(*) FROM fpm_corrective_action WHERE id_fpm = h.id_fpm) AS jml_corrective,
    (SELECT COUNT(*) FROM fpm_preventive_action WHERE id_fpm = h.id_fpm) AS jml_preventive
    FROM fpm_header h
    WHERE h.diterima_pic IS NOT NULL AND h.diterima_pic != ''
");

while ($row = mysqli_fetch_assoc($qBelumDijawab)) {

    if ($row['jml_corrective'] > 0 || $row['jml_preventive'] > 0) continue;
    if (!isFlowImp($row['flow_type'])) continue;

    $id_fpm    = $row['id_fpm'];
    $no_form   = $row['no_form'];
    $nama_dept = trim($row['dept'] ?? '') !== '' ? $row['dept'] : $row['internal_dept'];

    $waPlantHead = $waMap[$nama_dept]['planthead'] ?? [];

    $selisih = floor((strtotime($today) - strtotime($row['diterima_pic'])) / 86400);

    // Eskalasi bisa dimatikan / diubah jumlah harinya oleh Admin IT (reminder_eskalasi_*).
    if (appSettingBool('reminder_eskalasi_aktif') && $selisih > appSettingInt('reminder_eskalasi_hari')) {

        if (!sudahKirimHariIni($koneksi, $id_fpm, 'belum_dijawab_7hari')) {

            $msg = "*Reminder Eskalasi FPM*\n\n Dept : $nama_dept\n No FPM : $no_form\n FPM sudah lebih dari " . appSettingInt('reminder_eskalasi_hari') . " hari belum dijawab. Mohon segera ditindak lanjuti.";

            $kirimPh  = sendWA($waPlantHead, $msg);
            $kirimDir = sendWA($waDirecture, $msg);

            if ($kirimPh || $kirimDir) {
                simpanLogWA($koneksi, $id_fpm, 'belum_dijawab_7hari');
            }
        }
    }
}

/*
================================
RULE 3: per baris corrective/preventive action, lihat due_date.
H-2 sebelum due date -> notif Dept Head. Sudah lewat due date dan
status belum CLOSED -> notif Dept Head PIC setiap hari kerja (+ eskalasi
Plant Head & Directure) sampai Staff IMP men-CLOSED-kan countermeasure-nya.
Status CLOSED tidak dikirim WA ke siapapun.
================================
*/

$q2 = mysqli_query($koneksi, "SELECT
    c.id,
    c.id_fpm,
    c.tindakan,
    c.pic,
    c.due_date,
    c.status,
    h.no_form,
    h.dept,
    'CORRECTIVE' as jenis
FROM fpm_corrective_action c
JOIN fpm_header h ON c.id_fpm = h.id_fpm

UNION ALL

SELECT
    p.id,
    p.id_fpm,
    p.tindakan,
    p.pic,
    p.due_date,
    p.status,
    h.no_form,
    h.dept,
    'PREVENTIVE' as jenis
FROM fpm_preventive_action p
JOIN fpm_header h ON p.id_fpm = h.id_fpm
");

while ($row = mysqli_fetch_assoc($q2)) {

    $id        = $row['id'];
    // PIC countermeasure = nama dept; kosong -> pakai dept terkait FPM.
    $nama_dept = trim($row['pic'] ?? '') !== '' ? trim($row['pic']) : $row['dept'];
    $duedate   = $row['due_date'];
    $status    = strtoupper(trim($row['status'] ?? ''));
    $no_form   = $row['no_form'];

    // Over due = status belum CLOSED (bukan cuma persis 'OPEN'), sama dengan
    // definisi over due di dashboard.
    if ($status === "CLOSED") continue;
    if (!$duedate || $duedate === '0000-00-00') continue;

    $sisa = floor((strtotime($duedate) - strtotime($today)) / 86400);

    $waDeptHead  = $waMap[$nama_dept]['depthead'] ?? [];
    $waPlantHead = $waMap[$nama_dept]['planthead'] ?? [];

    // id corrective & preventive berasal dari tabel berbeda (bisa sama angkanya),
    // jadi jenis log-nya dibedakan supaya yang satu tidak dianggap "sudah dikirim
    // hari ini" gara-gara yang lain.
    $suffixLog = $row['jenis'] === 'PREVENTIVE' ? '_prev' : '';

    $detail = " Dept PIC : $nama_dept\n No FPM : $no_form\n "
        . ucfirst(strtolower($row['jenis'])) . " Action : " . $row['tindakan']
        . "\n Due Date : " . date('d-m-Y', strtotime($duedate));

    /*
    H-2 REMINDER (sekali; maju ke Jumat kalau H-2 jatuh di akhir pekan).
    Berlaku juga untuk countermeasure FPM Claim Machining (tabel yang sama).
    */

    $tglH2 = tanggalReminderH2($duedate);

    if ($sisa >= 0 && $today >= $tglH2 && !sudahKirimSejak($koneksi, $id, 'due_h2' . $suffixLog, $tglH2)) {

        $msg = "*Reminder Due Date Countermeasure*\n\n$detail\n "
            . ($sisa == 0 ? "Due date countermeasure HARI INI" : "Due date countermeasure $sisa hari lagi")
            . ", mohon segera ditindak lanjuti.";

        if (sendWA($waDeptHead, $msg)) {
            simpanLogWA($koneksi, $id, 'due_h2' . $suffixLog);
        }
    }

    /*
    OVERDUE (belum CLOSED)
    */

    if ($sisa < 0) {

        if (!sudahKirimHariIni($koneksi, $id, 'overduedate' . $suffixLog)) {

            $msg = "*Reminder Over Due Date Countermeasure*\n\n$detail (lewat " . abs($sisa) . " hari)"
                . "\n Countermeasure sudah melewati due date dan belum CLOSED, mohon segera ditindak lanjuti.";

            $kirimDh  = sendWA($waDeptHead, $msg);
            $kirimPh  = sendWA($waPlantHead, $msg);
            $kirimDir = sendWA($waDirecture, $msg);

            if ($kirimDh || $kirimPh || $kirimDir) {
                simpanLogWA($koneksi, $id, 'overduedate' . $suffixLog);
            }
        }
    }
}

/*
=====================================================
REMINDER JAWAB FPM CLAIM MACHINING (flow_type='CLAIMMACH')
=====================================================
Dept tujuan (dipilih Plant Head saat Terima, disimpan di fpm_header.dept) wajib
menjawab paling lambat 5 hari kerja (problem baru) / 3 hari kerja (problem berulang)
dari tgl_pembuatan - lihat hitungDueDateJawabClaimMachining(). Lewat due date dan
belum dijawab -> WA ke Dept Head + Staff dept tujuan, sekali per hari sampai dijawab.
*/

require_once __DIR__ . '/fpm_claim_machining_helper.php';

$qClaimBelumJawab = mysqli_query($koneksi, "SELECT h.id_fpm, h.no_form, h.dept,
        h.tgl_pembuatan, h.kejadian_berulang_ke
    FROM fpm_header h
    WHERE h.flow_type = 'CLAIMMACH'
      AND h.cm_plant_head_diterima_at IS NOT NULL
      AND h.cm_dept_head_dijawab_at IS NULL
      AND (h.status_evaluasi IS NULL OR h.status_evaluasi != 'CLOSED')
");

while ($qClaimBelumJawab && $row = mysqli_fetch_assoc($qClaimBelumJawab)) {

    $id_fpm    = $row['id_fpm'];
    $no_form   = $row['no_form'];
    $nama_dept = $row['dept'];

    if (!isset($waMap[$nama_dept])) continue;

    $berulang = (int) $row['kejadian_berulang_ke'] >= 1;
    $dueDate  = hitungDueDateJawabClaimMachining($row['tgl_pembuatan'] ?: $today, (int) $row['kejadian_berulang_ke']);

    if ($dueDate >= $today) continue;

    if (!sudahKirimHariIni($koneksi, $id_fpm, 'cm_belum_jawab')) {

        $batas = $berulang
            ? appSettingInt('cm_batas_jawab_berulang') . ' hari kerja (masalah berulang)'
            : appSettingInt('cm_batas_jawab_baru') . ' hari kerja (masalah tidak berulang)';

        $msg = "*Reminder Over Due Jawab FPM Claim Machining*\n\n Dept : $nama_dept\n No FPM : $no_form\n Due Date Jawab : " . date('d-m-Y', strtotime($dueDate))
            . "\n Batas jawab $batas sudah terlewati dan FPM belum dijawab. Mohon segera dijawab.";

        sendWA($waMap[$nama_dept]['depthead'] ?? [], $msg);
        sendWA($waMap[$nama_dept]['staffdept'] ?? [], $msg);

        simpanLogWA($koneksi, $id_fpm, 'cm_belum_jawab');
    }
}

// Catat SP baru dari Monitor Approval & email ke HRD (kalau diaktifkan Admin IT) - supaya
// tetap jalan walau tidak ada yang membuka web hari itu (biasanya terpicu dari sidebar).
require_once __DIR__ . '/monitoring_approval_helper.php';
hitungSpMonitoringApproval($koneksi);
