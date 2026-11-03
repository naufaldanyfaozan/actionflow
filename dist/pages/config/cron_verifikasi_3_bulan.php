<?php
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/wa_map.php';
require_once __DIR__ . '/../services/whatsapp.php';
require_once __DIR__ . '/app_setting_helper.php';

$today = date('Y-m-d');

$queryFpm = mysqli_query($koneksi, "SELECT d.id_fpm, h.no_form
    FROM fpm_detail_masalah d
    JOIN fpm_header h ON h.id_fpm = d.id_fpm");

while ($rowFpm = mysqli_fetch_assoc($queryFpm)) {

    $id_fpm  = $rowFpm['id_fpm'];
    $no_form = $rowFpm['no_form'];

    $queryTanggal = mysqli_query($koneksi, "SELECT MAX(actual_date) as last_date FROM (
            SELECT actual_date FROM fpm_corrective_action WHERE id_fpm='$id_fpm'
            UNION ALL
            SELECT actual_date FROM fpm_preventive_action WHERE id_fpm='$id_fpm'
            UNION ALL
            SELECT actual_date FROM fpm_review_dokumen WHERE id_fpm='$id_fpm'
        ) as gabungan
    ");

    $dataTanggal = mysqli_fetch_assoc($queryTanggal);
    $last_date = $dataTanggal['last_date'];

    if (!$last_date) continue;

    $tanggal_awal = new DateTime($last_date);
    $tanggal_sekarang = new DateTime($today);
    $selisih = $tanggal_awal->diff($tanggal_sekarang);

    $total_bulan = ($selisih->y * 12) + $selisih->m;

    // Jumlah bulan diatur Admin IT (app_setting reminder_verifikasi_bulan), default 3.
    if ($total_bulan < appSettingInt('reminder_verifikasi_bulan')) continue;

    $cekVerifikasi = mysqli_query($koneksi, "SELECT verification_3_bulan,
               verification_3_bulan2, verification_3_bulan3 FROM fpm_detail_masalah
        WHERE id_fpm='$id_fpm'");

    $dataVerif = mysqli_fetch_assoc($cekVerifikasi);

    if (
        !empty($dataVerif['verification_3_bulan']) ||
        !empty($dataVerif['verification_3_bulan2']) ||
        !empty($dataVerif['verification_3_bulan3'])
    ) {
        continue;
    }

    $pesan = "🔔 NOTIFIKASI VERIFIKASI 3 BULAN\n\n"
        . "No Form: $no_form\n"
        . "Tanggal terakhir action: $last_date\n\n"
        . "Sudah melewati 3 bulan dan belum dilakukan verifikasi.\n"
        . "Mohon segera lakukan verifikasi.";

    kirimWaBanyak($waStaffIMP1, $pesan);
    kirimWaBanyak($waStaffIMP3, $pesan);
}

echo "Selesai cek verifikasi 3 bulan";

// Dibungkus lewat kirimWA() di services/whatsapp.php supaya ikut gating
// WA_NOTIF_ENABLED (mati otomatis di local/XAMPP, aktif otomatis di hosting)
// dan tidak lagi pakai token hardcode terpisah. Nama fungsi TIDAK boleh
// "kirimWa" - nama fungsi PHP case-insensitive dan akan bentrok/redeclare
// dengan kirimWA() di services/whatsapp.php.
function kirimWaBanyak($targets, $pesan)
{
    if (!$targets || !is_array($targets)) return;

    foreach ($targets as $target) {
        if (empty($target)) continue;
        kirimWA($target, $pesan);
    }
}
