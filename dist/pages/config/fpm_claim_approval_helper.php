<?php

/**
 * Helper alur approval FPM Claim Machining (flow_type='CLAIMMACH'):
 * Dept Head QA approve -> Plant Head Terima (pilih dept) -> Dept Head Terima ->
 * Dept Head Jawab -> Plant Head Approve -> Directur Approve.
 * Dipakai bareng-bareng oleh form_fpm.php dan list_fpm_claim_machining.php di role
 * dept_head, plant_head, dept_head_qa, directur supaya urutan/status-nya konsisten
 * di semua halaman, tidak masing-masing role menghitung sendiri-sendiri.
 */

/**
 * Catat approval satu dept PIC untuk satu FPM Claim Machining. Idempotent - approve
 * ulang oleh dept yang sama cuma memperbarui waktu/usernya, bukan bikin baris baru.
 */
function catatFpmClaimPicApproval($koneksi, int $idFpm, string $dept, string $approvedBy): bool
{
    $stmt = mysqli_prepare($koneksi, "INSERT INTO fpm_claim_pic_approval (id_fpm, dept, approved_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by), approved_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "iss", $idFpm, $dept, $approvedBy);
    return mysqli_stmt_execute($stmt);
}

/**
 * Ambil approval PIC dept yang sudah masuk untuk satu FPM, dikembalikan sebagai
 * peta dept => [approved_by, approved_at] supaya gampang dicek per dept.
 */
function ambilFpmClaimPicApprovals($koneksi, int $idFpm): array
{
    $stmt = mysqli_prepare($koneksi, "SELECT dept, approved_by, approved_at
        FROM fpm_claim_pic_approval WHERE id_fpm = ?");
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, "i", $idFpm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $map[$row['dept']] = $row;
    }
    return $map;
}

/**
 * True kalau semua dept PIC (dept utama + PIC corrective/preventive, lihat
 * ambilPicDeptFpm di fpm_catatan_revisi_helper.php) sudah approve.
 */
function semuaPicSudahApprove(array $picDeptList, array $picApprovals): bool
{
    if (empty($picDeptList)) {
        return false;
    }
    foreach ($picDeptList as $dept) {
        if (!isset($picApprovals[$dept])) {
            return false;
        }
    }
    return true;
}

/**
 * Hitung tahap alur linear FPM Claim Machining v3 saat ini dari kolom cm_* di baris
 * fpm_header ($fpm) - satu fungsi ini jadi acuan tunggal dipakai di semua halaman list
 * supaya label "Approved"/"Waiting" konsisten, tidak menghitung ulang beda-beda logic
 * di tiap role.
 *
 * Urutan alur (sesuai SOP "Flow FPM Claim Machining"): Dept Head QA Approve ->
 * Plant Head Terima (pilih dept) -> Dept Head Terima -> Dept Head Jawab -> Dept
 * Head Approve (approval internal dept terkait, sebelum diteruskan) -> Plant Head
 * Approve -> Directur Approve.
 *
 * Return ['approved' => label tahap yang sudah lolos, 'waiting' => label tahap
 * berikutnya yang ditunggu ('-' kalau sudah selesai semua)].
 */
function hitungStatusApprovalClaimMach(array $fpm): array
{
    if (!empty($fpm['cm_directur_approved_at'])) {
        return ['approved' => 'Directur', 'waiting' => '-'];
    }

    if (!empty($fpm['cm_plant_head_approved_at'])) {
        return ['approved' => 'Plant Head Approve', 'waiting' => 'Directur'];
    }

    if (!empty($fpm['cm_dept_head_approved_at'])) {
        return ['approved' => 'Dept Head Approve', 'waiting' => 'Plant Head Approve'];
    }

    if (!empty($fpm['cm_dept_head_dijawab_at'])) {
        return ['approved' => 'Dept Head Jawab', 'waiting' => 'Dept Head Approve'];
    }

    if (!empty($fpm['cm_dept_head_diterima_at'])) {
        return ['approved' => 'Dept Head Terima', 'waiting' => 'Dept Head Jawab'];
    }

    if (!empty($fpm['cm_plant_head_diterima_at'])) {
        return ['approved' => 'Plant Head Terima', 'waiting' => 'Dept Head Terima'];
    }

    if (!empty($fpm['cm_deptheadqa_approved_at'])) {
        return ['approved' => 'Dept Head QA Approve', 'waiting' => 'Plant Head Terima'];
    }

    return ['approved' => '-', 'waiting' => 'Dept Head QA Approve'];
}
