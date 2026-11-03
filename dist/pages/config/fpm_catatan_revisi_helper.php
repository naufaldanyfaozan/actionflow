<?php

/**
 * Catat satu entri catatan/balasan PIC untuk FPM QA (Customer/Internal/Claim Machining).
 * $tipe 'CATATAN' dipakai saat Dept Head QA mengirim catatan ke dept/PIC countermeasure
 * tertentu, 'BALASAN' saat dept/PIC (atau Plant Head) itu membalas. Ditaruh di tabel
 * fpm_catatan_revisi yang sama dengan fitur "+ Catatan Revisi" FPM Down Time, supaya
 * histori catatan tidak hilang meski fpm_header.note/status_reject cuma satu slot aktif.
 */
function catatFpmCatatanRevisi(
    $koneksi,
    int $idFpm,
    string $deptTujuan,
    string $catatan,
    string $dibuatOleh,
    string $tipe = 'CATATAN',
    ?string $role = null
): bool {
    $stmt = mysqli_prepare($koneksi, "INSERT INTO fpm_catatan_revisi
        (id_fpm, dept_tujuan, tipe, catatan, dibuat_oleh, role) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "isssss", $idFpm, $deptTujuan, $tipe, $catatan, $dibuatOleh, $role);
    return mysqli_stmt_execute($stmt);
}

/**
 * Ambil timeline catatan/balasan PIC satu FPM, urut kronologis (lama ke baru)
 * supaya kebaca sebagai percakapan catatan -> balasan -> catatan -> balasan.
 * Hanya catatan umum (bukan yang menempel ke baris corrective/preventive/dokumen
 * tertentu di fitur Down Time) yang diambil di sini.
 */
function ambilFpmCatatanRevisi($koneksi, int $idFpm): array
{
    $stmt = mysqli_prepare($koneksi, "SELECT dept_tujuan, tipe, catatan, dibuat_oleh, role, created_at
        FROM fpm_catatan_revisi
        WHERE id_fpm = ? AND (ref_table IS NULL OR ref_table = '')
        ORDER BY created_at ASC, id ASC");
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
 * Render timeline catatan/balasan PIC sebagai HTML sederhana (dipakai di form_fpm.php
 * Dept Head QA, Dept Head, Staff, dan Plant Head supaya tampilannya konsisten).
 * CATATAN tampil kuning (permintaan dari Dept Head QA ke suatu dept), BALASAN tampil
 * hijau (tanggapan dari dept/PIC yang dituju atau Plant Head).
 */
function renderFpmCatatanRevisiHistory(array $rows): string
{
    if (!$rows) {
        return '<p style="color:#6b7280; font-size:13px; margin:0;">Belum ada catatan untuk FPM ini.</p>';
    }

    $html = '<div class="fpm-catatan-timeline">';
    foreach ($rows as $r) {
        $isCatatan = $r['tipe'] === 'CATATAN';
        $label = $isCatatan ? ('Catatan ke ' . $r['dept_tujuan']) : 'Balasan';
        $color = $isCatatan ? '#d97706' : '#16a34a';
        $bg = $isCatatan ? '#fffbeb' : '#f0fdf4';
        $tanggal = date('d-m-Y H:i', strtotime($r['created_at']));
        $roleLabel = !empty($r['role']) ? ' (' . htmlspecialchars($r['role']) . ')' : '';

        $html .= '<div style="border-left:3px solid ' . $color . '; background:' . $bg . '; '
            . 'border-radius:6px; padding:10px 12px; margin-bottom:8px;">'
            . '<div style="font-weight:700; color:' . $color . '; font-size:12px; text-transform:uppercase;">'
            . htmlspecialchars($label) . '</div>'
            . '<div style="font-size:13px; color:#1f2937; margin:4px 0; white-space:pre-wrap;">'
            . nl2br(htmlspecialchars($r['catatan'])) . '</div>'
            . '<div style="font-size:11px; color:#6b7280;">'
            . htmlspecialchars($r['dibuat_oleh']) . $roleLabel . ' &middot; ' . $tanggal
            . '</div>'
            . '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Kumpulkan daftar dept/PIC yang relevan buat sebuah FPM QA - dept utama FPM plus
 * semua dept yang jadi PIC di corrective/preventive action-nya - supaya Dept Head QA
 * memilih dari daftar yang benar-benar terlibat, bukan mengetik bebas.
 */
function ambilPicDeptFpm(array $fpm, array $correctiveData, array $preventiveData): array
{
    $depts = [];

    $deptUtama = trim($fpm['dept'] ?? '') ?: trim($fpm['internal_dept'] ?? '');
    if ($deptUtama !== '') {
        $depts[] = $deptUtama;
    }

    foreach ($correctiveData as $c) {
        $pic = trim($c['pic'] ?? '');
        if ($pic !== '') {
            $depts[] = $pic;
        }
    }

    foreach ($preventiveData as $p) {
        $pic = trim($p['pic'] ?? '');
        if ($pic !== '') {
            $depts[] = $pic;
        }
    }

    return array_values(array_unique($depts));
}

/**
 * Widget balasan catatan PIC - dirender di form_fpm.php Dept Head/Staff/Plant Head.
 * Cuma muncul kalau ada catatan aktif (status_reject=REJECT + catatan_target_dept
 * terisi) dan yang login berhak membalas: Dept Head/Staff dari dept yang dituju,
 * atau Plant Head (mengawasi semua dept).
 */
function renderBalasCatatanPicWidget(array $fpm, string $sessionDept, string $sessionLevel): string
{
    $targetDept = trim($fpm['catatan_target_dept'] ?? '');
    $isAktif = ($fpm['status_reject'] ?? '') === 'REJECT' && $targetDept !== '';

    if (!$isAktif) {
        return '';
    }

    $berhakBalas = ($sessionDept !== '' && $sessionDept === $targetDept) || $sessionLevel === 'Plant Head';

    if (!$berhakBalas) {
        return '';
    }

    return '<div class="no-print" style="max-width:900px; margin-bottom:16px; border:1px solid #fde68a; '
        . 'background:#fffbeb; border-radius:8px; padding:14px;">'
        . '<div style="font-weight:700; color:#92400e; font-size:13px; margin-bottom:8px;">'
        . 'Ada catatan dari Dept Head QA ditujukan ke ' . htmlspecialchars($targetDept) . ' - mohon ditindaklanjuti / dibalas</div>'
        . '<form method="post">'
        . '<input type="hidden" name="id_fpm" value="' . (int) $fpm['id_fpm'] . '">'
        . '<textarea name="balasan" class="form-control" placeholder="Tulis balasan / perbaikan yang sudah dilakukan..." '
        . 'required style="width:100%; height:80px;"></textarea><br>'
        . '<button type="submit" name="balas_catatan_pic" class="btn btn-success btn-sm">Kirim Balasan</button>'
        . '</form></div>';
}
