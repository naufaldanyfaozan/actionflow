<?php

/**
 * Status ganda di list FPM (Down Time, Internal, Claim Machining) semua role:
 *  - Status Approval      : CLOSED kalau sudah di-approve sampai Directure
 *                           (fpm_pengiriman.disetujui = 'Directure'), REJECT kalau
 *                           FPM di-reject, selain itu OPEN.
 *  - Status Countermeasure: dihitung dari Corrective Action + Preventive Action +
 *                           Review Dokumen (Review Dokumen yang kosong otomatis tidak
 *                           ikut terhitung). CLOSED kalau ada countermeasure dan semuanya
 *                           CLOSED, selain itu OPEN (termasuk kalau belum diisi sama sekali).
 *
 * Query list harus JOIN fpm_pengiriman sebagai alias p dan fpm_header sebagai h.
 */

// Jumlah semua countermeasure FPM ($hanyaOpen = true: yang belum CLOSED saja).
function sqlJumlahCountermeasureFpm(bool $hanyaOpen): string
{
    $sql = [];
    foreach (['fpm_corrective_action' => 'ca', 'fpm_preventive_action' => 'pa', 'fpm_review_dokumen' => 'rd'] as $tabel => $a) {
        $sql[] = "(SELECT COUNT(*) FROM $tabel $a WHERE $a.id_fpm = h.id_fpm"
            . ($hanyaOpen ? " AND ($a.status IS NULL OR $a.status != 'CLOSED')" : '') . ")";
    }
    return '(' . implode(' + ', $sql) . ')';
}

// Kolom SELECT tambahan: total_cm_action & total_cm_open.
function sqlKolomStatusCountermeasureFpm(): string
{
    return sqlJumlahCountermeasureFpm(false) . ' AS total_cm_action, '
        . sqlJumlahCountermeasureFpm(true) . ' AS total_cm_open';
}

// Potongan WHERE untuk filter Status Approval (OPEN / CLOSED / REJECT).
function sqlFilterStatusApprovalFpm(string $status): string
{
    $bukanReject = "(h.status_reject IS NULL OR h.status_reject != 'REJECT')";

    switch ($status) {
        case 'OPEN':
            return " AND $bukanReject AND (p.disetujui IS NULL OR p.disetujui != 'Directure')";
        case 'CLOSED':
            return " AND $bukanReject AND p.disetujui = 'Directure'";
        case 'REJECT':
            return " AND h.status_reject = 'REJECT'";
    }
    return '';
}

// Pihak yang sedang ditunggu approval-nya - sama dengan kolom "Waiting" di List FPM
// (FPM QA punya urutan sendiri: Staff QA -> Dept Head -> Plant Head -> Dept Head QA -> Directure).
function sqlWaitingApprovalFpm(): string
{
    return "(CASE
        WHEN IFNULL(h.flow_type, '') = 'QA' THEN (CASE
            WHEN p.disetujui = 'Directure' THEN '-'
            WHEN p.disetujui1 = 'Dept Head QA' THEN 'Directure'
            WHEN p.diperiksa = 'Plant Head' THEN 'Dept Head QA'
            WHEN IFNULL(p.dibuat, '') != '' THEN 'Plant Head'
            WHEN IFNULL(h.diterima_qa, '') != '' THEN 'Dept Head'
            ELSE 'Staff QA' END)
        WHEN p.disetujui = 'Directure' THEN '-'
        WHEN p.disetujui1 = 'Vice Directure' THEN 'Directure'
        WHEN IFNULL(h.diterima_imp, '') != '' THEN 'Directure'
        WHEN p.diperiksa = 'Plant Head' THEN 'Imp Head'
        WHEN IFNULL(p.dibuat, '') != '' THEN 'Plant Head'
        WHEN IFNULL(h.diperiksa_oleh, '') != '' AND IFNULL(h.disetujui_oleh, '') != '' THEN 'Dept Head'
        ELSE 'Imp Head' END)";
}

/**
 * Potongan WHERE untuk filter Status "Perlu Approval Saya" (PERLU_SAYA) / "Sudah Saya
 * Approve" (SUDAH_SAYA). Dulu disaring di PHP saat menampilkan baris, jadi jumlah per bulan
 * di paginasi tidak ikut tersaring dan bulan yang dipilih bisa tampil kosong.
 * $role: 'Dept Head' | 'Plant Head' | 'Imp Head' | 'Directure' | 'Vice Directure'.
 */
function sqlFilterApprovalSayaFpm(string $status, string $role): string
{
    if ($status === 'PERLU_SAYA') {
        return " AND " . sqlWaitingApprovalFpm() . " = '" . addslashes($role) . "'";
    }

    if ($status !== 'SUDAH_SAYA') {
        return '';
    }

    switch ($role) {
        case 'Dept Head':
            return " AND IFNULL(p.dibuat, '') != ''";
        case 'Plant Head':
            return " AND p.diperiksa = 'Plant Head'";
        case 'Imp Head':
            return " AND ((IFNULL(h.diperiksa_oleh, '') != '' AND IFNULL(h.disetujui_oleh, '') != '') OR IFNULL(h.diterima_imp, '') != '')";
        case 'Directure':
            return " AND p.disetujui = 'Directure'";
        case 'Vice Directure':
            return " AND p.disetujui1 = 'Vice Directure'";
    }
    return '';
}

// Potongan WHERE untuk filter Status Countermeasure (OPEN / CLOSED).
function sqlFilterStatusCountermeasureFpm(string $status): string
{
    $total = sqlJumlahCountermeasureFpm(false);
    $open  = sqlJumlahCountermeasureFpm(true);

    if ($status === 'CLOSED') {
        return " AND $total > 0 AND $open = 0";
    }
    if ($status === 'OPEN') {
        return " AND ($total = 0 OR $open > 0)";
    }
    return '';
}

// ['text' => ..., 'badge' => ...] dari baris hasil query. Claim Machining menyebut
// FPM yang di-reject sebagai REVISI, jadi labelnya bisa diganti.
function statusApprovalFpm(array $row, string $labelReject = 'REJECT'): array
{
    if (($row['status_reject'] ?? '') === 'REJECT') {
        return ['text' => $labelReject, 'badge' => 'danger'];
    }
    if (($row['disetujui'] ?? '') === 'Directure') {
        return ['text' => 'CLOSED', 'badge' => 'success'];
    }
    return ['text' => 'OPEN', 'badge' => 'warning'];
}

function statusCountermeasureFpm(array $row): array
{
    if ((int) ($row['total_cm_action'] ?? 0) > 0 && (int) ($row['total_cm_open'] ?? 0) === 0) {
        return ['text' => 'CLOSED', 'badge' => 'success'];
    }
    return ['text' => 'OPEN', 'badge' => 'warning'];
}

function badgeStatusFpm(array $status): string
{
    return '<span class="badge bg-' . $status['badge'] . '">' . $status['text'] . '</span>';
}

// Dropdown filter Status Countermeasure (parameter GET: status_cm).
function dropdownFilterStatusCountermeasureFpm(string $terpilih, string $classWrapper = ''): string
{
    $opsi = ['' => '-- Semua Status --', 'OPEN' => 'Open', 'CLOSED' => 'Closed'];
    $html = '<div' . ($classWrapper !== '' ? ' class="' . $classWrapper . '"' : '') . '><label>Status Countermeasure</label>' . ($classWrapper !== '' ? '' : '<br>') . '<select name="status_cm" class="form-control">';
    foreach ($opsi as $nilai => $label) {
        $html .= '<option value="' . $nilai . '"' . ($terpilih === $nilai ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select></div>';
}

// Potongan WHERE untuk filter Dept (dept yang dituju menjawab FPM Down Time / Internal,
// sama dengan kolom "Dept Dituju").
function sqlFilterDeptDitujuFpm($koneksi, string $dept): string
{
    if ($dept === '') {
        return '';
    }
    return " AND h.internal_dept = '" . mysqli_real_escape_string($koneksi, $dept) . "'";
}

// Dropdown filter Dept (parameter GET: dept). Opsi dari master_dept + dept yang sudah
// pernah dituju di fpm_header (mis. QA yang tidak ada di master_dept).
function dropdownFilterDeptDitujuFpm($koneksi, string $terpilih): string
{
    $q = mysqli_query($koneksi, "SELECT nama_dept AS dept FROM master_dept WHERE nama_dept != 'All Dept'
        UNION SELECT DISTINCT internal_dept FROM fpm_header WHERE internal_dept IS NOT NULL AND internal_dept != ''
        ORDER BY dept");

    $html = '<div><label>Dept</label><br><select name="dept" class="form-control">'
        . '<option value="">-- Semua Dept --</option>';
    while ($q && ($d = mysqli_fetch_assoc($q))) {
        $nilai = htmlspecialchars($d['dept']);
        $html .= '<option value="' . $nilai . '"' . ($terpilih === $d['dept'] ? ' selected' : '') . '>' . $nilai . '</option>';
    }
    return $html . '</select></div>';
}

// Kolom SELECT untuk kolom "Dept Dituju" di list FPM (dept yang harus menjawab FPM).
// - Down Time / Internal: fpm_header.internal_dept (dept yang dipilih saat buat FPM,
//   sama dengan filter list Dept Head).
// - Claim Machining: fpm_header.dept (dept terkait; internal_dept di alur ini selalu QA
//   sebagai pembuat).
function sqlKolomDijawabOlehFpm(): string
{
    return "h.flow_type AS jawab_flow, h.dept AS jawab_dept_cm, h.internal_dept AS jawab_dept_tujuan";
}

function renderDijawabOlehFpm(array $row): string
{
    $dept = ($row['jawab_flow'] ?? '') === 'CLAIMMACH'
        ? trim($row['jawab_dept_cm'] ?? '')
        : trim($row['jawab_dept_tujuan'] ?? '');

    return $dept !== '' ? htmlspecialchars($dept) : '-';
}
