<?php

if (!function_exists('renderDetailMasalahBox')) {
    /**
     * Render isi kolom "DETAIL MASALAH" pada form_fpm.php.
     * Baris FPM Internal (NG Casting/Machining, foto di fpm_detail_masalah.file)
     * menampilkan foto + kotak NG Area/NG Total (kalau diisi).
     * Baris FPM Down Time lama tetap menampilkan Shift/Total Lost Time/PIC.
     */
    function renderDetailMasalahBox($d)
    {
        if (!$d) {
            echo '<em>Tidak ada detail masalah</em>';
            return;
        }

        // Dokumen / Foto NG bisa sampai 3 file: `file` (file pertama), file2, file3.
        $namaFiles = array_values(array_filter([
            trim($d['file'] ?? ''),
            trim($d['file2'] ?? ''),
            trim($d['file3'] ?? ''),
        ], fn($f) => $f !== ''));

        if (!empty($namaFiles)) {
            // Lebar tiap gambar menyesuaikan jumlah file supaya 1-3 file tetap muat sebaris.
            $maxWidth = count($namaFiles) === 1 ? '50%' : (count($namaFiles) === 2 ? '48%' : '32%');

            echo '<div style="display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start;">';

            foreach ($namaFiles as $namaFile) {
                $filePath = "../upload_fpm/$namaFile";

                if (!file_exists($filePath)) {
                    echo '<em>File tidak ditemukan</em>';
                    continue;
                }

                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    echo '<a href="' . $filePath . '" target="_blank" style="max-width:' . $maxWidth . ';">';
                    echo '<img src="' . $filePath . '" style="max-width:100%; max-height:120px;">';
                    echo '</a>';
                } elseif ($ext === 'pdf') {
                    echo '<a href="' . $filePath . '" target="_blank" class="btn btn-primary btn-sm">Lihat File PDF</a>';
                } else {
                    echo '<a href="' . $filePath . '" target="_blank">' . htmlspecialchars(basename($filePath)) . '</a>';
                }
            }

            echo '</div>';

            if (isset($d['ng_area_percent']) || isset($d['ng_total_percent'])) {
                if ($d['ng_area_percent'] !== null || $d['ng_total_percent'] !== null) {
                    echo '<div style="display:flex; gap:8px; margin-top:8px;">';
                    echo '<div style="border:2px solid #dc2626; border-radius:6px; padding:4px 10px; color:#dc2626; font-weight:bold; display:inline-block;">';
                    echo 'NG AREA: ' . number_format((float)($d['ng_area_percent'] ?? 0), 2) . '%';
                    echo '</div>';
                    echo '<div style="border:2px solid #2563eb; border-radius:6px; padding:4px 10px; color:#2563eb; font-weight:bold; display:inline-block;">';
                    echo 'NG TOTAL: ' . number_format((float)($d['ng_total_percent'] ?? 0), 2) . '%';
                    echo '</div>';
                    echo '</div>';
                }
            }
        }

        // FPM Down Time: Shift/Total Lost Time/PIC tetap tampil (di bawah foto kalau ada foto).
        if (trim($d['shift'] ?? '') !== '' || trim((string)($d['total_lost_time'] ?? '')) !== '') {
            // Font kecil & rapat supaya foto masalah (kalau ada) tetap punya ruang di kotak.
            echo '<div style="font-size: 10px; line-height:1.5;' . (!empty($namaFiles) ? ' margin-top:6px;' : '') . '">';
            echo '<strong>Shift</strong> : ' . htmlspecialchars($d['shift'] ?? '') . '<br>';
            echo '<strong>Total Lost Time</strong> : ' . htmlspecialchars((string)($d['total_lost_time'] ?? '')) . ' Minutes<br>';
            echo '<strong>PIC</strong> : ' . htmlspecialchars($d['pic'] ?? '');
            echo '</div>';
            return;
        }

        if (empty($namaFiles)) {
            echo '<em>Tidak ada detail masalah</em>';
        }
    }
}
