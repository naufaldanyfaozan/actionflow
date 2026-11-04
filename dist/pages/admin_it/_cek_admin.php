<?php

// Semua halaman admin_it hanya untuk akun level "Admin IT" (session aman, login, level & CSRF
// lewat config/auth_helper.php; token admin_it_token di bawah tetap dipakai form Admin IT).
require_once __DIR__ . '/../config/auth_helper.php';
wajibLogin(['Admin IT']);

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/app_setting_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';

if (empty($_SESSION['admin_it_token'])) {
    $_SESSION['admin_it_token'] = bin2hex(random_bytes(16));
}
