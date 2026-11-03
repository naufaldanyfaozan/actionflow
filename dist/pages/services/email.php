<?php

// Email otomatis dimatikan saat jalan di local (XAMPP/Windows), sama seperti WA di
// services/whatsapp.php, dan otomatis aktif di hosting (Linux).
const EMAIL_NOTIF_ENABLED = PHP_OS_FAMILY !== 'Windows';

require_once __DIR__ . '/../config/app_setting_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';

/**
 * Kirim email HTML lewat mail() bawaan PHP (sendmail hosting). Semua email aplikasi lewat
 * fungsi ini, jadi kalau nanti pindah ke SMTP cukup fungsi ini yang diganti.
 *
 * @param string[] $tujuan alamat email (sudah divalidasi di pengaturan Admin IT)
 */
function kirimEmail(array $tujuan, string $subjek, string $isiHtml): bool
{
    $tujuan = array_values(array_filter($tujuan, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$tujuan) {
        return false;
    }
    if (!EMAIL_NOTIF_ENABLED) {
        error_log('EMAIL SKIPPED (local/testing, email tidak dikirim): to=' . implode(',', $tujuan) . " subjek=$subjek");
        return true;
    }

    $pengirim = (string) appSetting('email_pengirim');
    if ($pengirim === '') {
        $host = preg_replace('/^www\.|:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        $pengirim = 'noreply@' . ($host !== '' ? $host : 'localhost');
    }
    // Nama aplikasi bisa diganti Admin IT - buang karakter yang bisa menyisipkan header.
    $namaPengirim = str_replace(["\r", "\n", '"'], '', brandNama());

    $header = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: "' . $namaPengirim . '" <' . $pengirim . '>',
        'Reply-To: ' . $pengirim,
    ]);
    $subjek = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subjek)) . '?=';

    $ok = mail(implode(',', $tujuan), $subjek, $isiHtml, $header, '-f' . $pengirim);
    if (!$ok) {
        error_log('EMAIL FAILED: to=' . implode(',', $tujuan));
    }
    return $ok;
}
