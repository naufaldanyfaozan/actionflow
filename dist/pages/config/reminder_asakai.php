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

/*
================================
BATASI JAM & HARI KERJA
================================
*/
$currentTime = date("H:i");
// Jam kirim diatur Admin IT (app_setting reminder_jam_mulai / reminder_jam_selesai).
if ($currentTime < appSetting('reminder_jam_mulai') || $currentTime > appSetting('reminder_jam_selesai')) exit;

$day = date("N");
if ($day >= 6) exit; // Sabtu Minggu stop

/*
================================
FUNCTION SEND WA
================================
Dibungkus lewat kirimWA() di services/whatsapp.php supaya ikut gating
WA_NOTIF_ENABLED (mati otomatis di local/XAMPP, aktif otomatis di hosting)
dan tidak lagi pakai token hardcode terpisah.
*/
// Return true kalau minimal 1 nomor berhasil dikirimi - log harian hanya dicatat
// saat WA benar-benar terkirim, supaya gagal kirim dicoba lagi di run berikutnya.
function sendWA($targets, $message)
{
    if (!$targets || !is_array($targets)) return false;

    $terkirim = false;

    foreach ($targets as $target) {
        if (empty($target)) continue;
        if (kirimWA($target, $message)) $terkirim = true;
    }

    return $terkirim;
}

/*
================================
TANGGAL REMINDER H-2
================================
H-2 sebelum due date. Script tidak jalan Sabtu/Minggu, jadi kalau H-2 jatuh di
akhir pekan reminder dimajukan ke hari kerja sebelumnya (Jumat) supaya tidak
terlewat.
*/
function tanggalReminderH2($dueDate)
{
    $d = new DateTime(date('Y-m-d', strtotime($dueDate . ' -' . appSettingInt('reminder_h_due') . ' days')));
    while ($d->format('N') >= 6) {
        $d->modify('-1 day');
    }
    return $d->format('Y-m-d');
}

/*
================================
HITUNG HARI KERJA
================================
*/
function hariKerjaLewat($tanggal)
{
    if (!$tanggal) return 0;

    $start = new DateTime(date('Y-m-d', strtotime($tanggal)));
    $today = new DateTime(date('Y-m-d'));

    $days = 0;

    while ($start < $today) {
        $start->modify('+1 day');
        if ($start->format('N') < 6) {
            $days++;
        }
    }

    return $days;
}

/*
================================
CEK SUDAH KIRIM (LOG WA)
================================
Tabel wa_reminder_log tidak punya kolom id_asakai - dulu query ke kolom itu gagal
dan script berhenti fatal (mysqli_num_rows(false)) sebelum satu WA pun terkirim.
ID referensi Asakai disimpan di kolom id_fpm (kolom referensi umum log ini);
jenis_reminder Asakai selalu berawalan 'asakai_' sehingga tidak bentrok dengan
reminder FPM yang id-nya kebetulan sama.
*/
function sudahKirimSejak($koneksi, $ref_id, $jenis, $sejak)
{
    $ref_id = (int) $ref_id;
    $jenis  = mysqli_real_escape_string($koneksi, $jenis);
    $sejak  = mysqli_real_escape_string($koneksi, $sejak);

    $q = mysqli_query($koneksi, "SELECT id FROM wa_reminder_log
        WHERE id_fpm = '$ref_id'
        AND jenis_reminder = '$jenis' AND tanggal >= '$sejak'
        LIMIT 1
    ");

    return $q && mysqli_num_rows($q) > 0;
}

function sudahKirimHariIni($koneksi, $ref_id, $jenis)
{
    return sudahKirimSejak($koneksi, $ref_id, $jenis, date("Y-m-d"));
}

/*
================================
SIMPAN LOG WA
================================
*/
function simpanLogWA($koneksi, $ref_id, $jenis)
{
    $today  = date("Y-m-d");
    $ref_id = (int) $ref_id;
    $jenis  = mysqli_real_escape_string($koneksi, $jenis);

    mysqli_query($koneksi, "INSERT INTO wa_reminder_log (id_fpm, jenis_reminder, tanggal)
        VALUES ('$ref_id', '$jenis', '$today')");
}

/*
================================
REMINDER 3 HARI DARI TANGGAL ASAKAI
1 ASAKAI = 1 NOTIF
================================
*/
// asakai_header tidak punya kolom dept (dulu query ini gagal & script berhenti) -
// dept tujuan diambil dari PIC countermeasure yang belum CLOSED.
$qHeader3Hari = mysqli_query($koneksi, "SELECT id_asakai, tanggal, machine, namapart, partnumber, downtime_problem, ng_problem
    FROM asakai_header
    WHERE tanggal <= DATE_SUB(CURDATE(), INTERVAL " . appSettingInt('reminder_asakai_hari') . " DAY)");

while ($qHeader3Hari && $h = mysqli_fetch_assoc($qHeader3Hari)) {

    $id_asakai = (int) $h['id_asakai'];
    $tanggal   = $h['tanggal'];
    $machine   = $h['machine'];
    $namapart  = $h['namapart'];
    $partnumber = $h['partnumber'];

    $problemList = array_filter([$h['downtime_problem'], $h['ng_problem']], fn($v) => trim((string) $v) !== '');
    $problem     = !empty($problemList) ? implode(' / ', $problemList) : '-';

    if (!$tanggal) continue;

    $hariLewat = hariKerjaLewat($tanggal);
    if ($hariLewat < appSettingInt('reminder_asakai_hari')) continue;

    $qCek = mysqli_query($koneksi, "SELECT DISTINCT TRIM(x.pic) AS pic FROM (
            SELECT pic, status FROM asakai_countermeasure_downtime
            WHERE id_asakai = '$id_asakai'
            UNION ALL
            SELECT pic, status FROM asakai_countermeasure_ng
            WHERE id_asakai = '$id_asakai'
        ) x
        WHERE UPPER(TRIM(IFNULL(x.status, ''))) != 'CLOSED'
    ");

    if (!$qCek || mysqli_num_rows($qCek) == 0) continue;

    // 1 Asakai = 1 notif (sekali saja, bukan harian) - pengingat harian sudah
    // ditangani reminder H-2 & over due per countermeasure di bawah.
    if (sudahKirimSejak($koneksi, $id_asakai, 'asakai_3hari', '2000-01-01')) continue;

    $waDeptHead = [];
    while ($p = mysqli_fetch_assoc($qCek)) {
        $waDeptHead = array_merge($waDeptHead, $waMap[$p['pic']]['depthead'] ?? []);
    }
    $waDeptHead = array_values(array_unique($waDeptHead));
    if (empty($waDeptHead)) continue;

    $msg = "*Reminder Perbaikan Asakai*\n\n"
        . "Tanggal Asakai : $tanggal\n"
        . "Mesin : $machine\n"
        . "Part : $namapart ($partnumber)\n"
        . "Problem : $problem\n\n"
        . "Terdapat countermeasure yang BELUM CLOSED.\n\n"
        . "Mohon segera dilakukan perbaikan.";

    if (sendWA($waDeptHead, $msg)) {
        simpanLogWA($koneksi, $id_asakai, 'asakai_3hari');
    }
}

/*
================================
H-2 & OVERDUE (PER ACTION)
================================
*/
// Log dicatat per BARIS countermeasure (id_cm_dt / id_cm_ng), bukan per id_asakai -
// dulu 1 Asakai dengan beberapa countermeasure cuma 1 yang terkirim per hari.
// Jenis log dibedakan per tabel karena id kedua tabel bisa sama angkanya.
$tables = [
    'asakai_countermeasure_downtime' => ['id' => 'id_cm_dt', 'suffix' => '_dt'],
    'asakai_countermeasure_ng'       => ['id' => 'id_cm_ng', 'suffix' => '_ng'],
];

$today = date("Y-m-d");

foreach ($tables as $tableName => $meta) {

    $q = mysqli_query($koneksi, "SELECT c.{$meta['id']} AS id_cm, c.id_asakai, c.pic, c.deskripsi, c.due_date, c.status,
            h.machine, h.namapart, h.partnumber
        FROM $tableName c
        JOIN asakai_header h ON h.id_asakai = c.id_asakai
        WHERE c.due_date IS NOT NULL AND c.due_date != '0000-00-00'");

    while ($q && $row = mysqli_fetch_assoc($q)) {

        $id         = $row['id_cm'];
        $nama_dept  = trim($row['pic'] ?? '');
        $deskripsi  = $row['deskripsi'];
        $duedate    = $row['due_date'];
        $status     = strtoupper(trim($row['status'] ?? ''));
        $machine    = $row['machine'];
        $namapart   = $row['namapart'];
        $partnumber = $row['partnumber'];

        if ($status == "CLOSED") continue;

        $sisa = (int) floor((strtotime($duedate) - strtotime($today)) / 86400);

        $waStaffdept = $waMap[$nama_dept]['staffdept'] ?? [];
        $waDeptHead  = $waMap[$nama_dept]['depthead'] ?? [];
        $waPlantHead = $waMap[$nama_dept]['planthead'] ?? [];

        $dueTampil = date('d-m-Y', strtotime($duedate));

        /*
        ================================
        H-2 (sekali; maju ke Jumat kalau H-2 jatuh di akhir pekan)
        ================================
        */
        $tglH2 = tanggalReminderH2($duedate);
        $jenisH2 = 'asakai_h2' . $meta['suffix'];

        if ($sisa >= 0 && $today >= $tglH2 && !sudahKirimSejak($koneksi, $id, $jenisH2, $tglH2)) {

            $msg = "*Reminder Asakai Countermeasure*\n\n"
                . "Dept : $nama_dept\n"
                . "Mesin : $machine\n"
                . "Part : $namapart ($partnumber)\n"
                . "Masalah : $deskripsi\n"
                . "Due Date : $dueTampil\n\n"
                . ($sisa === 0 ? "Due date HARI INI.\n" : "Due date tinggal $sisa hari lagi.\n")
                . "Mohon segera ditindak lanjuti.";

            $kirimStaff = sendWA($waStaffdept, $msg);
            $kirimDh    = sendWA($waDeptHead, $msg);

            if ($kirimStaff || $kirimDh) {
                simpanLogWA($koneksi, $id, $jenisH2);
            }
        }

        /*
        ================================
        OVERDUE - setiap hari kerja sampai CLOSED
        ================================
        */
        $jenisOverdue = 'asakai_overdue' . $meta['suffix'];

        if ($sisa < 0 && !sudahKirimHariIni($koneksi, $id, $jenisOverdue)) {

            $msg = "*Reminder Over Due Date Asakai*\n\n"
                . "Dept : $nama_dept\n"
                . "Mesin : $machine\n"
                . "Part : $namapart ($partnumber)\n"
                . "Masalah : $deskripsi\n"
                . "Due Date : $dueTampil (lewat " . abs($sisa) . " hari)\n\n"
                . "Countermeasure sudah melewati due date dan belum CLOSED.\n"
                . "Mohon segera diselesaikan.";

            $kirimDh  = sendWA($waDeptHead, $msg);
            $kirimPh  = sendWA($waPlantHead, $msg);
            $kirimDir = sendWA($waDirecture, $msg);

            if ($kirimDh || $kirimPh || $kirimDir) {
                simpanLogWA($koneksi, $id, $jenisOverdue);
            }
        }
    }
}
