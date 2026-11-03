<?php

/**
 * Hapus satu FPM (fpm_header) beserta semua data anak & file upload terkait, dipakai
 * oleh hapus_fpm.php di staff/ dan staff_imp/ - satu-satunya role yang boleh menghapus
 * FPM. Menghapus lintas semua flow_type (IMP/QA/CLAIMMACH) dan jenis_fpm (termasuk
 * Down Time), karena struktur tabel anaknya sama untuk semua flow.
 */
function hapusFpmLengkap($koneksi, int $idFpm): bool
{
    $folderUploadFpm = __DIR__ . '/../upload_fpm/';
    $folderUploadsFpmDt = __DIR__ . '/../uploads/fpm_dt/';

    $hapusFileKolom = function (string $tabel, string $kolomFile, string $folder) use ($koneksi, $idFpm) {
        $stmt = mysqli_prepare($koneksi, "SELECT $kolomFile FROM $tabel WHERE id_fpm = ?");
        mysqli_stmt_bind_param($stmt, "i", $idFpm);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $namaFile = trim($row[$kolomFile] ?? '');
            if ($namaFile !== '') {
                $path = $folder . $namaFile;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    };

    $hapusFileKolom('fpm_detail_masalah', 'file', $folderUploadFpm);
    $hapusFileKolom('fpm_detail_masalah', 'file2', $folderUploadFpm);
    $hapusFileKolom('fpm_detail_masalah', 'file3', $folderUploadFpm);
    $hapusFileKolom('fpm_corrective_action', 'file', $folderUploadsFpmDt);
    $hapusFileKolom('fpm_preventive_action', 'file', $folderUploadsFpmDt);
    $hapusFileKolom('fpm_review_dokumen', 'file', $folderUploadsFpmDt);

    $tabelAnak = [
        'fpm_detail_masalah',
        'fpm_analisa',
        'fpm_corrective_action',
        'fpm_preventive_action',
        'fpm_review_dokumen',
        'fpm_similar',
        'fpm_evaluasi',
        'fpm_pengiriman',
        'fpm_claim_pic_approval',
        'fpm_catatan_revisi',
        'fpm_reschedule_log',
    ];

    mysqli_begin_transaction($koneksi);

    try {
        foreach ($tabelAnak as $tabel) {
            $stmt = mysqli_prepare($koneksi, "DELETE FROM $tabel WHERE id_fpm = ?");
            mysqli_stmt_bind_param($stmt, "i", $idFpm);
            mysqli_stmt_execute($stmt);
        }

        $stmtHeader = mysqli_prepare($koneksi, "DELETE FROM fpm_header WHERE id_fpm = ?");
        mysqli_stmt_bind_param($stmtHeader, "i", $idFpm);
        mysqli_stmt_execute($stmtHeader);

        mysqli_commit($koneksi);
        return true;
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        return false;
    }
}
