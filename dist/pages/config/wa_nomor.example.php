<?php
// CONTOH format config/wa_nomor.php - nomor WA penerima notifikasi FPM.
//
// Cara pakai: salin file ini menjadi wa_nomor.php (folder yang sama), lalu isi nomor asli
// (format 62xxx, tanpa + / spasi). wa_nomor.php di-.gitignore supaya nomor pribadi
// karyawan tidak ikut tersimpan di git. Di dalam productionflow simpan di config/fpm/.
// Nomor juga bisa ditambah dari Admin IT > Notifikasi WA & Email tanpa edit file.

return [
    // Per dept (nama dept sama dengan kolom dept di tabel user).
    'waMap' => [
        'PRO-CAST' => [
            'staffdept'     => ['628xxxxxxxxxx'],
            'depthead'      => ['628xxxxxxxxxx'],
            'planthead'     => ['628xxxxxxxxxx'],
        ],
        'QA' => [
            'staffdept'     => ['628xxxxxxxxxx'],
            'depthead'      => ['628xxxxxxxxxx'],
            'planthead'     => [''],
        ],
        // ... dept lain dengan format yang sama
    ],

    'waIMPHead1'       => ['628xxxxxxxxxx'], // Head IMP Plant 1
    'waIMPHead3'       => ['628xxxxxxxxxx'], // Head IMP Plant 3
    'waStaffIMP1'      => ['628xxxxxxxxxx'], // Staff IMP Plant 1
    'waStaffIMP3'      => ['628xxxxxxxxxx'], // Staff IMP Plant 3
    'waDirecture'      => ['628xxxxxxxxxx'],
    'waVice_Directure' => [''],

    // Plant Head per plant (alur FPM Claim Machining).
    'waMapPlantHead' => [
        '1' => ['628xxxxxxxxxx'],
        '2' => [''],
        '3' => [''],
    ],
];
