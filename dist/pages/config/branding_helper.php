<?php

/**
 * Identitas aplikasi (nama, tagline, logo) yang bisa diganti Admin IT lewat
 * admin_it/identitas.php - dipakai sidebar, dashboard, halaman login, export & pesan WA,
 * supaya web bisa dipasang di perusahaan lain tanpa mengubah kode.
 *
 * Nilai disimpan di app_setting (brand_nama, brand_tagline, brand_logo_app,
 * brand_logo_dokumen); path logo relatif ke root project fpm/.
 */

require_once __DIR__ . '/app_setting_helper.php';

function brandNama(): string
{
    return (string) appSetting('brand_nama');
}

function brandTagline(): string
{
    return (string) appSetting('brand_tagline');
}

// $jenis: 'app' (logo aplikasi) | 'dokumen' (logo perusahaan di form FPM).
// $keRoot: path relatif dari halaman pemanggil ke root project - default untuk halaman di
// folder role (dist/pages/<role>/).
function brandLogo(string $jenis = 'app', string $keRoot = '../../../'): string
{
    return $keRoot . appSetting($jenis === 'dokumen' ? 'brand_logo_dokumen' : 'brand_logo_app');
}
