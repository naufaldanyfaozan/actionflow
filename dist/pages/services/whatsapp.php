<?php

// Notifikasi WA otomatis dimatikan saat jalan di local (XAMPP/Windows) supaya tidak
// nyasar ke nomor staff asli waktu testing, dan otomatis aktif lagi di hosting (Linux)
// tanpa perlu diubah manual - sama seperti deteksi local/hosting di koneksi.php.
const WA_NOTIF_ENABLED = PHP_OS_FAMILY !== 'Windows';

require_once __DIR__ . '/../config/app_setting_helper.php';
require_once __DIR__ . '/../config/wa_penerima_helper.php';
require_once __DIR__ . '/../config/file_rahasia.php';

function kirimWA($target, $pesan)
{
    if (!WA_NOTIF_ENABLED) {
        error_log("WA SKIPPED (local/testing, notifikasi tidak dikirim): to=$target");
        return true;
    }

    // Saklar WA dari halaman Admin IT. Return false (bukan true) supaya reminder harian
    // tidak dicatat "sudah terkirim" dan tetap dikirim setelah WA diaktifkan lagi.
    if (!appSettingBool('wa_aktif')) {
        error_log("WA SKIPPED (dimatikan Admin IT): to=$target");
        return false;
    }

    // Nomor yang dinonaktifkan Admin IT dibuang; kalau tidak tersisa, sama seperti saklar
    // global: return false supaya reminder tetap dikirim setelah nomornya diaktifkan lagi.
    $target = waSaringNomorAktif((string) $target);
    if ($target === '') {
        return false;
    }

    // Token tidak masuk git - config/env.php dibuat dari env.example.php (lihat file_rahasia.php).
    $fileEnv = fileRahasiaFpm('env.php');
    if ($fileEnv === null) {
        error_log("WA GAGAL (config/env.php berisi token Fonnte belum dibuat): to=$target");
        return false;
    }
    $config = require $fileEnv;
    $token  = $config['FONNTE_TOKEN'];

    $pesan .= "\n\n_Pesan otomatis dari sistem FPM, mohon tidak membalas pesan ini (No Reply)._";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'target' => $target,
            'message' => $pesan,
            'delay' => 5,
            'countryCode' => '62',
        ],
        CURLOPT_HTTPHEADER => [
            "Authorization: $token"
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if (curl_errno($curl)) {
        error_log("WA ERROR: " . curl_error($curl));
        curl_close($curl);
        return false;
    }

    curl_close($curl);

    $json = json_decode($response, true);

    if ($httpCode !== 200 || empty($json['status']) || $json['status'] !== true) {
        error_log("WA FAILED: " . $response);
        return false;
    }

    return true;
}
