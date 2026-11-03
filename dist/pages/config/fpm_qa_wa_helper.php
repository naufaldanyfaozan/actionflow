<?php

/**
 * Notifikasi WA alur FPM QA (flow_type 'QA': FPM Internal QA & Customer QA).
 *
 * Alur approval: Staff QA (buat + pembahasan) -> Dept Head dept terkait -> Plant Head
 * -> Dept Head QA -> Directure. Tahap yang sedang menunggu dibaca dari fpm_pengiriman
 * (dibuat / diperiksa / disetujui1 / disetujui), jadi setelah revisi notifikasi
 * diteruskan ke tahap yang memang di-reset oleh pihak yang meminta revisi - bukan
 * selalu ke awal atau selalu ke Dept Head QA.
 */

if (!function_exists('approverBerikutnyaFpmQa')) {
    /**
     * @return array{tahap:string, wa:string[]}|null  null kalau semua tahap sudah approve.
     */
    function approverBerikutnyaFpmQa(mysqli $koneksi, int $id_fpm): ?array
    {
        $stmt = $koneksi->prepare("SELECT h.dept, h.internal_dept, p.dibuat, p.diperiksa, p.disetujui1, p.disetujui
            FROM fpm_header h
            LEFT JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
            WHERE h.id_fpm = ?");
        $stmt->bind_param("i", $id_fpm);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return null;
        }

        // wa_map.php hanya berisi variabel, di-require ulang di scope fungsi ini supaya
        // $waMap dkk tersedia walaupun file itu sudah pernah di-require_once di luar.
        $nama_dept = $row['internal_dept'] ?: $row['dept'];
        require __DIR__ . '/wa_map.php';

        if (trim($row['dibuat'] ?? '') !== 'Dept Head') {
            // Dept terkait + semua dept PIC countermeasure (sama seperti notif pembahasan
            // di staff/jawab_fpm_qa.php).
            $picDepts = [$nama_dept];
            foreach (['fpm_corrective_action', 'fpm_preventive_action'] as $tabel) {
                $q = $koneksi->query("SELECT pic FROM $tabel WHERE id_fpm = " . (int) $id_fpm);
                while ($q && ($r = $q->fetch_assoc())) {
                    $picDepts[] = $r['pic'];
                }
            }

            $wa = [];
            foreach (array_unique(array_filter($picDepts)) as $picDept) {
                $wa = array_merge($wa, $waMap[$picDept]['depthead'] ?? [], $waMap[$picDept]['staffdept'] ?? []);
            }

            return ['tahap' => 'Dept Head', 'wa' => array_values(array_unique(array_filter($wa)))];
        }

        if (trim($row['diperiksa'] ?? '') !== 'Plant Head') {
            return ['tahap' => 'Plant Head', 'wa' => array_values(array_unique(array_filter($waPlantHead)))];
        }

        if (trim($row['disetujui1'] ?? '') !== 'Dept Head QA') {
            return ['tahap' => 'Dept Head QA', 'wa' => array_values(array_unique(array_filter($waDeptHeadQA)))];
        }

        if (trim($row['disetujui'] ?? '') !== 'Directure') {
            return ['tahap' => 'Directure', 'wa' => array_values(array_unique(array_filter($waDirecture)))];
        }

        return null;
    }
}
