<?php

// Identitas aplikasi untuk halaman login (index.html masih HTML statis, jadi nama & logo
// diisi lewat fetch ke file ini). Publik - hanya berisi data yang memang tampil di login.
include __DIR__ . '/koneksi.php';
require_once __DIR__ . '/config/branding_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

echo json_encode([
    'nama'    => brandNama(),
    'tagline' => brandTagline(),
    // Logo bawaan tidak dikirim - login memakai ikon bawaannya sendiri.
    'logo'    => appSetting('brand_logo_app') !== APP_SETTING_DEFINISI['brand_logo_app']['default'] ? brandLogo('app', '../../') : null,
]);
