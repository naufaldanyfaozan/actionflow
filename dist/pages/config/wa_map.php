<?php

// Nomor WA asli karyawan tidak disimpan di file ini (file ini masuk git) - ada di
// config/wa_nomor.php yang di-.gitignore, formatnya lihat wa_nomor.example.php.
// Kalau file itu belum dibuat, semua daftar di bawah kosong (tidak ada WA terkirim).
require_once __DIR__ . '/file_rahasia.php';
$waNomorFile = fileRahasiaFpm('wa_nomor.php');
$waNomorData = $waNomorFile ? (require $waNomorFile) : [];
if (!$waNomorFile) {
    error_log('WA: config/wa_nomor.php tidak ditemukan - buat dari wa_nomor.example.php, tidak ada nomor penerima.');
}

$waMap = $waNomorData['waMap'] ?? [];

// Nomor yang ditambahkan Admin IT (menu Notifikasi WA & Email) ikut digabung ke daftar di
// file ini - lihat config/wa_penerima_helper.php.
require_once __DIR__ . '/wa_penerima_helper.php';
$waMap = waGabungTambahanDept($waMap);

$waStaff        = [];
$waDeptHead     = [];
$waPlantHead    = [];

if (isset($nama_dept) && isset($waMap[$nama_dept])) {
    $waStaff        = $waMap[$nama_dept]['staffdept'] ?? [];
    $waDeptHead     = $waMap[$nama_dept]['depthead'] ?? [];
    $waPlantHead    = $waMap[$nama_dept]['planthead'] ?? [];
}

$waIMPHead1 = $waNomorData['waIMPHead1'] ?? [];

$waIMPHead3 = $waNomorData['waIMPHead3'] ?? [];

$waStaffIMP1 = $waNomorData['waStaffIMP1'] ?? [];

$waStaffIMP3 = $waNomorData['waStaffIMP3'] ?? [];

$waDirecture = $waNomorData['waDirecture'] ?? [];

$waVice_Directure = $waNomorData['waVice_Directure'] ?? [];

$waStaffQA = $waMap['QA']['staffdept'] ?? [];
$waDeptHeadQA = $waMap['QA']['depthead'] ?? [];

// Dipakai khusus alur FPM Claim Machining v3: notifikasi ke Plant Head (setelah Dept Head QA
// approve, dan lagi setelah Dept Head tujuan menjawab) ditujukan sesuai field `plant` pada
// FPM (bukan per-dept seperti $waMap di atas) - key-nya angka plant ('1','2','3', dst,
// hasil trim(str_ireplace('Plant','',...)) seperti dipakai proses_fpm_claim_machining.php).
// Notifikasi paling awal (dari Staff QA saat membuat FPM) sekarang ditujukan ke Dept Head QA
// ($waDeptHeadQA di bawah), bukan ke Plant Head lagi.
$waMapPlantHead = $waNomorData['waMapPlantHead'] ?? [];

extract(waGabungTambahanVar(compact('waDirecture', 'waIMPHead1', 'waIMPHead3', 'waStaffIMP1', 'waStaffIMP3', 'waMapPlantHead')));
