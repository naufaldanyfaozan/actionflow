<?php

// Semua halaman admin_it hanya untuk akun level "Admin IT".
session_start();
if (!isset($_SESSION['username'])) {
    header('location:../index.html');
    exit;
}
if (($_SESSION['level'] ?? '') !== 'Admin IT') {
    http_response_code(403);
    die('Halaman ini hanya untuk Admin IT.');
}

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/app_setting_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';

if (empty($_SESSION['admin_it_token'])) {
    $_SESSION['admin_it_token'] = bin2hex(random_bytes(16));
}
