<?php

require_once __DIR__ . '/app_setting_helper.php';

// Aturan due date jawab FPM Claim Machining: default maksimal 5 hari kerja untuk problem baru,
// 3 hari kerja untuk problem berulang (Sabtu & Minggu tidak dihitung) - angkanya diatur Admin IT
// (app_setting cm_batas_jawab_baru / cm_batas_jawab_berulang). kejadian_berulang_ke
// bernilai 0 pada kejadian pertama (lihat staff/cek_kejadian_berulang.php), jadi >= 1
// berarti kejadian berulang.
function hitungDueDateJawabClaimMachining(string $tglPembuatanYmd, int $kejadianBerulangKe): string
{
    $hariKerja = appSettingInt($kejadianBerulangKe >= 1 ? 'cm_batas_jawab_berulang' : 'cm_batas_jawab_baru');
    $tanggal = new DateTime($tglPembuatanYmd);

    while ($hariKerja > 0) {
        $tanggal->modify('+1 day');
        if ($tanggal->format('N') < 6) {
            $hariKerja--;
        }
    }

    return $tanggal->format('Y-m-d');
}
