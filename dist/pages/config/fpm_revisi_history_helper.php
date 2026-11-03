<?php

/**
 * Catat satu entri riwayat revisi FPM (reject ATAU perbaikan/revisi balik).
 * Dipanggil dari setiap handler reject_submit (tipe REJECT) dan setiap
 * handler resubmit-setelah-revisi (tipe REVISI) di semua role.
 */
function catatFpmRevisiHistory($koneksi, int $idFpm, string $tipe, string $catatan, string $oleh, string $role): bool
{
    $stmt = mysqli_prepare($koneksi, "INSERT INTO fpm_revisi_history
        (id_fpm, tipe, catatan, oleh, role) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "issss", $idFpm, $tipe, $catatan, $oleh, $role);
    return mysqli_stmt_execute($stmt);
}

/**
 * Ambil seluruh riwayat revisi satu FPM, urut kronologis (lama ke baru)
 * supaya kebaca sebagai percakapan reject -> revisi -> reject -> revisi.
 */
function ambilFpmRevisiHistory($koneksi, int $idFpm): array
{
    $stmt = mysqli_prepare($koneksi, "SELECT tipe, catatan, oleh, role, created_at
        FROM fpm_revisi_history WHERE id_fpm = ? ORDER BY created_at ASC, id ASC");
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, "i", $idFpm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Ambil riwayat revisi untuk BANYAK FPM sekaligus (satu query, bukan N+1
 * per baris) - dipakai di halaman list (List FPM Internal / Down Time)
 * supaya tiap baris tabel bisa menampilkan ringkasan histori revisinya.
 * Hasil dikelompokkan per id_fpm, masing-masing sudah urut kronologis.
 */
function ambilFpmRevisiHistoryBanyak($koneksi, array $idFpmList): array
{
    $idFpmList = array_values(array_unique(array_filter(array_map('intval', $idFpmList))));
    if (!$idFpmList) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($idFpmList), '?'));
    $stmt = mysqli_prepare($koneksi, "SELECT id_fpm, tipe, catatan, oleh, role, created_at
        FROM fpm_revisi_history WHERE id_fpm IN ($placeholders) ORDER BY created_at ASC, id ASC");
    if (!$stmt) {
        return [];
    }

    $types = str_repeat('i', count($idFpmList));
    mysqli_stmt_bind_param($stmt, $types, ...$idFpmList);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $grouped = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $grouped[(int) $row['id_fpm']][] = $row;
    }
    return $grouped;
}

/**
 * Ringkasan histori revisi buat satu sel tabel di halaman list - badge yang
 * bisa diklik untuk buka mini-timeline-nya, supaya kolomnya tidak makan
 * banyak tempat tapi tetap gampang ditelusuri histori lengkapnya.
 */
function renderFpmRevisiRingkasan(array $rows): string
{
    if (!$rows) {
        return '<span style="color:#9ca3af; font-size:11px;">-</span>';
    }

    $jumlahRevisi = count(array_filter($rows, fn($r) => $r['tipe'] === 'REVISI'));
    $terakhir = end($rows);
    $sudahDirevisi = $terakhir['tipe'] === 'REVISI';
    $badgeColor = $sudahDirevisi ? '#16a34a' : '#dc2626';
    $badgeBg = $sudahDirevisi ? '#f0fdf4' : '#fef2f2';
    $badgeLabel = $sudahDirevisi ? ($jumlahRevisi . 'x direvisi') : 'Menunggu revisi';

    return '<details style="max-width:220px;">'
        . '<summary style="cursor:pointer; list-style:none; display:inline-block;">'
        . '<span style="background:' . $badgeBg . '; color:' . $badgeColor . '; '
        . 'font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:10px; white-space:nowrap;">'
        . htmlspecialchars($badgeLabel) . '</span>'
        . '</summary>'
        . '<div style="margin-top:6px; text-align:left;">' . renderFpmRevisiHistory($rows) . '</div>'
        . '</details>';
}

/**
 * Render riwayat revisi sebagai timeline HTML sederhana (dipakai di semua
 * form_fpm.php per role, supaya tampilannya konsisten di mana pun dibuka).
 * REJECT tampil merah (alasan diminta revisi), REVISI tampil hijau
 * (balasan/bukti bagian mana yang sudah diperbaiki).
 */
function renderFpmRevisiHistory(array $rows): string
{
    if (!$rows) {
        return '<p style="color:#6b7280; font-size:13px; margin:0;">Belum ada riwayat revisi untuk FPM ini.</p>';
    }

    $html = '<div class="fpm-revisi-timeline">';
    foreach ($rows as $r) {
        $isReject = $r['tipe'] === 'REJECT';
        $label = $isReject ? 'Diminta Revisi' : 'Sudah Direvisi';
        $color = $isReject ? '#dc2626' : '#16a34a';
        $bg = $isReject ? '#fef2f2' : '#f0fdf4';
        $tanggal = date('d-m-Y H:i', strtotime($r['created_at']));

        $html .= '<div style="border-left:3px solid ' . $color . '; background:' . $bg . '; '
            . 'border-radius:6px; padding:10px 12px; margin-bottom:8px;">'
            . '<div style="font-weight:700; color:' . $color . '; font-size:12px; text-transform:uppercase;">'
            . htmlspecialchars($label) . '</div>'
            . '<div style="font-size:13px; color:#1f2937; margin:4px 0; white-space:pre-wrap;">'
            . nl2br(htmlspecialchars($r['catatan'])) . '</div>'
            . '<div style="font-size:11px; color:#6b7280;">'
            . htmlspecialchars($r['oleh']) . ' (' . htmlspecialchars($r['role']) . ') &middot; ' . $tanggal
            . '</div>'
            . '</div>';
    }
    $html .= '</div>';

    return $html;
}
