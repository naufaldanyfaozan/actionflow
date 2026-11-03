<?php

// Renderer isian pengaturan, dipakai halaman-halaman Admin IT (index.php, notifikasi.php,
// identitas.php). Butuh _cek_admin.php sudah di-include.

function inputSettingAdmin(string $key, $nilai): string
{
    $def  = APP_SETTING_DEFINISI[$key];
    $nama = 'setting[' . $key . ']';

    if ($def['tipe'] === 'bool') {
        return '<input type="hidden" name="' . $nama . '" value="0">'
            . '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="' . $nama . '" value="1"'
            . ((string) $nilai === '1' ? ' checked' : '') . '><label class="form-check-label small">' . ((string) $nilai === '1' ? 'Aktif' : 'Nonaktif') . '</label></div>';
    }
    if ($def['tipe'] === 'time') {
        return '<input type="time" class="form-control form-control-sm adm-input" name="' . $nama . '" value="' . htmlspecialchars((string) $nilai) . '" required>';
    }
    if ($def['tipe'] === 'text' || $def['tipe'] === 'email') {
        return '<input type="' . $def['tipe'] . '" class="form-control form-control-sm adm-input-teks" name="' . $nama . '" value="' . htmlspecialchars((string) $nilai) . '"'
            . (isset($def['maks']) ? ' maxlength="' . $def['maks'] . '"' : '') . (!empty($def['wajib']) ? ' required' : '')
            . ($def['tipe'] === 'email' ? ' placeholder="nama@perusahaan.com"' : '') . '>';
    }
    return '<div class="input-group input-group-sm adm-input-group"><input type="number" class="form-control adm-input" name="' . $nama . '" value="' . htmlspecialchars((string) $nilai)
        . '" min="' . $def['min'] . '" max="' . $def['max'] . '" required>'
        . '<span class="input-group-text">' . htmlspecialchars($def['satuan'] ?? '') . '</span></div>';
}

function infoSettingAdmin(string $key, array $meta): string
{
    $def = APP_SETTING_DEFINISI[$key];
    if ($def['tipe'] === 'bool') {
        $default = $def['default'] ? 'Aktif' : 'Nonaktif';
    } elseif ($def['tipe'] === 'logo') {
        $default = 'logo bawaan';
    } else {
        $default = (string) $def['default'] !== '' ? $def['default'] : '(kosong)';
    }
    $html = 'Default: ' . htmlspecialchars((string) $default);
    if (!empty($meta[$key]['diubah_at'])) {
        $html .= ' &middot; diubah ' . htmlspecialchars($meta[$key]['diubah_oleh'] ?? '-') . ', ' . date('d-m-Y H:i', strtotime($meta[$key]['diubah_at']));
    }
    return $html;
}

// Waktu & pengubah terakhir per setting, untuk infoSettingAdmin().
function metaSettingAdmin(mysqli $koneksi): array
{
    $meta = [];
    $q = $koneksi->query("SELECT setting_key, diubah_oleh, diubah_at FROM app_setting");
    while ($q && ($r = $q->fetch_assoc())) {
        $meta[$r['setting_key']] = $r;
    }
    return $meta;
}
