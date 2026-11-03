<?php

/**
 * Email otomatis ke HRD (maks. 2 alamat) setiap ada SP (Surat Peringatan) baru dari Monitor
 * Approval FPM. Diaktifkan / dinonaktifkan Admin IT di admin_it/notifikasi.php
 * (app_setting email_sp_aktif, email_hrd_1, email_hrd_2, email_pengirim).
 *
 * Status kirim per baris fpm_sp_pelanggaran.email_hrd_status:
 *   NULL       = menunggu dikirim
 *   terkirim   = sudah diemail ke HRD
 *   nonaktif   = tercatat saat fitur email mati (tidak dikirim belakangan)
 *   lokal      = tercatat di XAMPP/local (email tidak pernah dikirim dari local)
 *   lama       = sudah ada sebelum fitur ini dibuat
 * Gagal kirim tetap NULL dan dicoba lagi paling cepat 1 jam kemudian (email_hrd_dicoba_at),
 * supaya halaman tidak mencoba kirim di setiap page load.
 *
 * Dipanggil dari catatPelanggaranSpFpm() (monitoring_approval_helper.php), yang jalan di
 * setiap sidebar & cron reminder_fpm.php.
 */

require_once __DIR__ . '/app_setting_helper.php';
require_once __DIR__ . '/branding_helper.php';
require_once __DIR__ . '/../services/email.php';

function prosesEmailSpHrd(mysqli $koneksi): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }
    $sudah = true;

    $tujuan = array_values(array_filter([(string) appSetting('email_hrd_1'), (string) appSetting('email_hrd_2')]));
    if (!appSettingBool('email_sp_aktif') || !$tujuan) {
        $koneksi->query("UPDATE fpm_sp_pelanggaran SET email_hrd_status = 'nonaktif' WHERE email_hrd_status IS NULL");
        return;
    }
    if (!EMAIL_NOTIF_ENABLED) {
        $koneksi->query("UPDATE fpm_sp_pelanggaran SET email_hrd_status = 'lokal' WHERE email_hrd_status IS NULL");
        return;
    }

    $menunggu = [];
    $q = $koneksi->query("SELECT id FROM fpm_sp_pelanggaran WHERE email_hrd_status IS NULL
        AND (email_hrd_dicoba_at IS NULL OR email_hrd_dicoba_at < NOW() - INTERVAL 1 HOUR)");
    while ($q && ($r = $q->fetch_assoc())) {
        $menunggu[(int) $r['id']] = true;
    }
    if (!$menunggu) {
        return;
    }

    // 1 email per SP (pihak + tanggal), berisi semua FPM yang terlambat di SP itu.
    foreach (ambilRiwayatSpFpm($koneksi)['riwayat'] as $sp) {
        $idBaru = array_values(array_filter(array_column($sp['fpm'], 'id'), fn($id) => isset($menunggu[$id])));
        if (!$idBaru) {
            continue;
        }
        $terkirim = kirimEmail($tujuan, emailSpHrdSubjek($sp), emailSpHrdIsi($koneksi, $sp));
        $daftarId = implode(',', array_map('intval', $idBaru));
        $koneksi->query($terkirim
            ? "UPDATE fpm_sp_pelanggaran SET email_hrd_status = 'terkirim', email_hrd_dicoba_at = NOW() WHERE id IN ($daftarId)"
            : "UPDATE fpm_sp_pelanggaran SET email_hrd_dicoba_at = NOW() WHERE id IN ($daftarId)");
    }
}

function emailSpHrdSubjek(array $sp): string
{
    return '[' . brandNama() . '] SP ' . $sp['level'] . ' - ' . $sp['pihak'];
}

// Akun user yang memegang peran pihak SP (mis. "Dept Head PE" -> akun Dept Head dept PE),
// supaya HRD tahu orangnya. Kosong kalau tidak ketemu.
function emailSpHrdAkunPihak(mysqli $koneksi, string $pihak): array
{
    $level = null;
    $filter = '';
    foreach (['Dept Head QA', 'Dept Head', 'Plant Head', 'Head IMP', 'Directure'] as $lvl) {
        if (stripos($pihak, $lvl) === 0) {
            $level = $lvl;
            $filter = trim(substr($pihak, strlen($lvl)));
            break;
        }
    }
    if ($level === null) {
        return [];
    }

    if ($level === 'Dept Head' && $filter !== '') {
        $stmt = $koneksi->prepare("SELECT username FROM user WHERE level = ? AND dept = ? ORDER BY username");
        $stmt->bind_param('ss', $level, $filter);
    } elseif ($level === 'Head IMP' && $filter !== '') {
        $stmt = $koneksi->prepare("SELECT username FROM user WHERE level = ? AND REPLACE(plant, ' ', '') = REPLACE(?, ' ', '') ORDER BY username");
        $stmt->bind_param('ss', $level, $filter);
    } else {
        $stmt = $koneksi->prepare("SELECT username FROM user WHERE level = ? ORDER BY username");
        $stmt->bind_param('s', $level);
    }
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'username');
}

function emailSpHrdIsi(mysqli $koneksi, array $sp): string
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $tgl = fn($v) => date('d-m-Y', strtotime($v));
    $akun = emailSpHrdAkunPihak($koneksi, $sp['pihak']);

    $baris = '';
    foreach ($sp['fpm'] as $f) {
        $baris .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0">' . $e($f['no_form'] ?: ('ID ' . $f['id_fpm']))
            . '</td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . $e($f['tahap']) . '</td></tr>';
    }

    $info = [
        'Pihak'          => $sp['pihak'],
        'Akun terkait'   => $akun ? implode(', ', $akun) : '-',
        'Level'          => 'SP ' . $sp['level'],
        'Tanggal SP'     => $tgl($sp['tgl']),
        'Berlaku sampai' => $tgl($sp['berlaku_sampai']),
    ];
    $tabelInfo = '';
    foreach ($info as $label => $nilai) {
        $tabelInfo .= '<tr><td style="padding:4px 12px 4px 0;color:#64748b">' . $e($label) . '</td><td style="padding:4px 0"><b>' . $e($nilai) . '</b></td></tr>';
    }

    return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1e293b">'
        . '<p>Yth. HRD,</p>'
        . '<p>Sistem ' . $e(brandNama()) . ' mencatat <b>SP ' . $e($sp['level']) . '</b> untuk pihak di bawah ini karena tahap approval FPM '
        . 'melewati batas waktu (hari kerja) sesuai aturan Monitor Approval. Mohon ditindaklanjuti sesuai kebijakan perusahaan.</p>'
        . '<table style="border-collapse:collapse;margin:8px 0 16px">' . $tabelInfo . '</table>'
        . '<p style="margin-bottom:6px"><b>FPM yang terlambat:</b></p>'
        . '<table style="border-collapse:collapse"><tr>'
        . '<th style="padding:6px 10px;border:1px solid #e2e8f0;background:#f1f5f9;text-align:left">No. Form</th>'
        . '<th style="padding:6px 10px;border:1px solid #e2e8f0;background:#f1f5f9;text-align:left">Tahap terlambat</th></tr>'
        . $baris . '</table>'
        . '<p style="color:#64748b;font-size:12px;margin-top:20px">Email otomatis dari ' . $e(brandNama())
        . ', mohon tidak membalas email ini. Riwayat lengkap ada di menu Monitoring Approval.</p></div>';
}
