<?php
// CONTOH format config/env.php - token API Fonnte untuk kirim WhatsApp.
//
// Cara pakai: salin file ini menjadi env.php (folder yang sama) lalu isi tokennya (dari
// dashboard fonnte.com). env.php di-.gitignore supaya token tidak ikut tersimpan di git.
// Di dalam productionflow simpan di config/fpm/env.php.
return [
  'FONNTE_TOKEN' => '',
];
