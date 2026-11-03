<?php

/**
 * Ringkasan KPI satu bulan (FPM + Asakai digabung) untuk dashboard "Ringkasan Sasaran
 * Mutu" - satu fungsi ini jadi acuan tunggal dipakai untuk bulan berjalan MAUPUN bulan
 * lalu (buat hitung tren), supaya definisi angka konsisten dan tidak dihitung ulang
 * beda-beda logic di tiap pemanggilan.
 */
function hitungRingkasanBulan($koneksi, string $whereFPM, string $whereAsakai): array
{
    $row = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
        COUNT(*) AS total_fpm,
        SUM(CASE WHEN
            NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND ca.status != 'CLOSED')
            AND NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND pa.status != 'CLOSED')
        THEN 1 ELSE 0 END) AS total_closed
        FROM fpm_header h $whereFPM
    "));

    $totalFpm = (int) ($row['total_fpm'] ?? 0);
    $totalClosed = (int) ($row['total_closed'] ?? 0);

    $rowOverdue = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM (
        SELECT ca.due_date, ca.status, ca.actual_date FROM fpm_corrective_action ca JOIN fpm_header h ON ca.id_fpm = h.id_fpm $whereFPM
        UNION ALL
        SELECT pa.due_date, pa.status, pa.actual_date FROM fpm_preventive_action pa JOIN fpm_header h ON pa.id_fpm = h.id_fpm $whereFPM
        UNION ALL
        SELECT ng.due_date, ng.status, ng.actual_date FROM asakai_countermeasure_ng ng JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai $whereAsakai
        UNION ALL
        SELECT dt.due_date, dt.status, dt.actual_date FROM asakai_countermeasure_downtime dt JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai $whereAsakai
    ) gabungan WHERE (status IS NULL OR status != 'CLOSED') AND due_date < CURDATE()"));
    $totalOverdue = (int) ($rowOverdue['total'] ?? 0);

    $severity = hitungSeverityKpi($koneksi, $whereAsakai, $whereFPM);

    return [
        'total_fpm' => $totalFpm,
        'total_closed' => $totalClosed,
        'total_open' => $totalFpm - $totalClosed,
        'completion_rate' => $totalFpm > 0 ? round($totalClosed / $totalFpm * 100, 1) : 0.0,
        'total_overdue' => $totalOverdue,
        'problem_berulang' => $severity['problem_berulang'],
        'efisiensi_rata' => $severity['efisiensi_rata'],
        'downtime_menit' => $severity['downtime_menit'],
        'ng_rate' => $severity['ng_rate'],
    ];
}

/**
 * Target sasaran mutu perusahaan - belum ada tabel target di database, jadi
 * dipakai angka default yang wajar dulu supaya KPI completion rate/NG rate/
 * efisiensi punya pembanding "vs target", bukan cuma tren vs bulan lalu.
 * TODO: pindah ke tabel konfigurasi kalau nanti target resmi per-plant/dept
 * sudah ditentukan dan perlu diubah-ubah tanpa edit kode.
 */
function sasaranMutuTarget(): array
{
    return [
        'completion_rate' => 85.0,
        'ng_rate' => 2.0,
        'efisiensi_rata' => 85.0,
    ];
}

/**
 * Badge "vs target" - hijau kalau tercapai, merah kalau belum. $arahBaik sama
 * makna dengan renderTrenBadge: 'up' kalau makin tinggi makin bagus (completion
 * rate, efisiensi), 'down' kalau makin rendah makin bagus (NG rate).
 */
function renderTargetBadge(float $aktual, float $target, string $arahBaik = 'up'): string
{
    $tercapai = $arahBaik === 'up' ? $aktual >= $target : $aktual <= $target;
    $kelas = $tercapai ? 'trend-good' : 'trend-bad';
    $ikon = $tercapai ? '&#10003;' : '&#10007;';

    return '<span class="kpi-trend ' . $kelas . '">' . $ikon . ' Target ' . $target . '%</span>';
}

/**
 * Arah & besar perubahan satu angka dibanding bulan lalu, dipakai buat panah tren
 * di KPI card. Null kalau bulan lalu maupun sekarang sama-sama 0 (tidak ada dasar
 * untuk dihitung persennya, daripada tampil "0% naik" yang menyesatkan).
 */
function hitungTrenKpi($sekarang, $lalu): ?array
{
    if ($lalu == 0 && $sekarang == 0) {
        return null;
    }
    if ($lalu == 0) {
        return ['arah' => 'up', 'persen' => null];
    }
    $delta = $sekarang - $lalu;
    $persen = round(($delta / $lalu) * 100, 1);
    return ['arah' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'), 'persen' => abs($persen)];
}

/**
 * Render badge tren (panah + persen) buat KPI card. $arahBaik menentukan warna:
 * 'down' kalau turun itu bagus (overdue, open, problem berulang), 'up' kalau naik
 * itu bagus (completion rate, efisiensi).
 */
function renderTrenBadge(?array $tren, string $arahBaik = 'down'): string
{
    if ($tren === null) {
        return '<span class="kpi-trend trend-flat">&#8213; vs bulan lalu</span>';
    }

    $arah = $tren['arah'];
    $panah = $arah === 'up' ? '&#9650;' : ($arah === 'down' ? '&#9660;' : '&#8213;');
    $persenText = $tren['persen'] === null ? 'baru' : $tren['persen'] . '%';

    // 'netral' dipakai untuk angka volume (mis. Total Asakai) yang naik/turun bukan
    // berarti bagus/jelek - cukup info tren tanpa dihakimi warna.
    if ($arahBaik === 'netral') {
        return '<span class="kpi-trend trend-flat">' . $panah . ' ' . $persenText . ' vs bulan lalu</span>';
    }

    $bagus = ($arah === $arahBaik) || $arah === 'flat';
    $kelas = $arah === 'flat' ? 'trend-flat' : ($bagus ? 'trend-good' : 'trend-bad');

    return '<span class="kpi-trend ' . $kelas . '">' . $panah . ' ' . $persenText . ' vs bulan lalu</span>';
}

/**
 * Tren jumlah FPM dibuat vs closed per bulan, trailing N bulan (termasuk bulan
 * berjalan) - dipakai buat 1 grafik "bagaimana perkembangannya" di dashboard,
 * menggantikan beberapa chart/tabel breakdown yang terlalu detail untuk level
 * manajemen ke atas.
 */
function trenBulananFpm($koneksi, string $filterBulan, string $filterPlant, int $bulanTerakhir = 6): array
{
    $mulai = date('Y-m-01', strtotime($filterBulan . '-01 -' . ($bulanTerakhir - 1) . ' months'));
    $where = "WHERE tgl_terbit >= '$mulai'";
    if (!empty($filterPlant)) {
        $where .= " AND plant = '" . mysqli_real_escape_string($koneksi, $filterPlant) . "'";
    }

    $result = mysqli_query($koneksi, "SELECT DATE_FORMAT(tgl_terbit, '%Y-%m') AS bulan,
        COUNT(*) AS total,
        SUM(CASE WHEN
            NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND ca.status != 'CLOSED')
            AND NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND pa.status != 'CLOSED')
        THEN 1 ELSE 0 END) AS closed
        FROM fpm_header h $where
        GROUP BY bulan ORDER BY bulan ASC");

    $label = [];
    $total = [];
    $closed = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $label[] = $row['bulan'];
        $total[] = (int) $row['total'];
        $closed[] = (int) $row['closed'];
    }

    return ['label' => $label, 'total' => $total, 'closed' => $closed];
}

/**
 * Gabungan item overdue FPM (corrective/preventive) + Asakai (NG/downtime) jadi
 * satu list "Item Kritis" terurut dari yang paling lama telat, dilengkapi link
 * langsung ke halaman perbaikannya. Menggantikan 2 tabel overdue terpisah yang
 * sebelumnya berdampingan tapi isinya sejenis (sama-sama "perlu ditindaklanjuti").
 */
function itemKritisGabungan(array $fpmOverdue, array $asakaiOverdue): array
{
    $items = [];

    foreach ($fpmOverdue as $row) {
        $items[] = [
            'tipe' => 'FPM',
            'pic' => $row['pic'] ?? '-',
            'deskripsi' => $row['deskripsi'] ?? '-',
            'konteks' => trim(($row['machine_no'] ?? '') . ' - ' . ($row['nama_part'] ?? ''), ' -'),
            'due_date' => $row['due_date'] ?? null,
            'link' => 'edit_fpm_downtime.php?id=' . ($row['id_fpm'] ?? ''),
        ];
    }

    foreach ($asakaiOverdue as $row) {
        $items[] = [
            'tipe' => 'Asakai',
            'pic' => $row['pic'] ?? '-',
            'deskripsi' => $row['deskripsi'] ?? '-',
            'konteks' => trim(($row['machine'] ?? '') . ' - ' . ($row['namapart'] ?? ''), ' -'),
            'due_date' => $row['due_date'] ?? null,
            'link' => 'update_asakai.php?id=' . ($row['id_asakai'] ?? ''),
        ];
    }

    foreach ($items as &$item) {
        $item['hari_telat'] = !empty($item['due_date'])
            ? (int) floor((strtotime(date('Y-m-d')) - strtotime($item['due_date'])) / 86400)
            : 0;
    }
    unset($item);

    usort($items, fn($a, $b) => $b['hari_telat'] <=> $a['hari_telat']);

    return $items;
}

/**
 * % completion (closed/total countermeasure FPM+Asakai) per departemen (PIC),
 * per bulan, trailing N bulan - dipakai buat 1 tabel "progres tiap departemen
 * dari bulan ke bulan" yang director butuh, menggantikan ranking 1-bulan-saja
 * yang tidak menunjukkan arah perkembangan.
 *
 * Dikelompokkan berdasarkan bulan FPM/Asakai-nya DIBUAT (tgl_terbit / tanggal),
 * bukan due_date countermeasure-nya, supaya konsisten dengan filter periode
 * yang dipakai di seluruh dashboard ini.
 */
function trenDepartemenBulanan($koneksi, string $filterBulan, string $filterPlant, int $bulanTerakhir = 6): array
{
    $mulai = date('Y-m-01', strtotime($filterBulan . '-01 -' . ($bulanTerakhir - 1) . ' months'));
    $plantEsc = mysqli_real_escape_string($koneksi, $filterPlant);

    $filterFpm = "h.tgl_terbit >= '$mulai'" . (!empty($filterPlant) ? " AND h.plant = '$plantEsc'" : '');
    $filterAsakai = "ah.tanggal >= '$mulai'" . (!empty($filterPlant) ? " AND ah.plant = '$plantEsc'" : '');

    $query = "
        SELECT pic, bulan,
            SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS closed,
            COUNT(*) AS total
        FROM (
            SELECT ca.pic, ca.status, DATE_FORMAT(h.tgl_terbit, '%Y-%m') AS bulan
            FROM fpm_corrective_action ca JOIN fpm_header h ON ca.id_fpm = h.id_fpm
            WHERE $filterFpm

            UNION ALL

            SELECT pa.pic, pa.status, DATE_FORMAT(h.tgl_terbit, '%Y-%m') AS bulan
            FROM fpm_preventive_action pa JOIN fpm_header h ON pa.id_fpm = h.id_fpm
            WHERE $filterFpm

            UNION ALL

            SELECT ng.pic, ng.status, DATE_FORMAT(ah.tanggal, '%Y-%m') AS bulan
            FROM asakai_countermeasure_ng ng JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai
            WHERE $filterAsakai

            UNION ALL

            SELECT dt.pic, dt.status, DATE_FORMAT(ah.tanggal, '%Y-%m') AS bulan
            FROM asakai_countermeasure_downtime dt JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai
            WHERE $filterAsakai
        ) gabungan
        WHERE pic IS NOT NULL AND pic <> '' AND pic <> 'All Dept'
        GROUP BY pic, bulan
        ORDER BY pic ASC, bulan ASC
    ";

    $result = mysqli_query($koneksi, $query);

    $bulanList = [];
    for ($i = $bulanTerakhir - 1; $i >= 0; $i--) {
        $bulanList[] = date('Y-m', strtotime($filterBulan . '-01 -' . $i . ' months'));
    }

    $matrix = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $total = (int) $row['total'];
        $closed = (int) $row['closed'];
        $matrix[$row['pic']][$row['bulan']] = [
            'total' => $total,
            'closed' => $closed,
            'percent' => $total > 0 ? round($closed / $total * 100, 1) : null,
            'items' => [],
        ];
    }

    // Daftar countermeasure yang BELUM selesai per dept+bulan, supaya saat hover
    // ke angka % di tabel, dept bisa langsung tahu tindakan apa saja yang masih
    // perlu dikerjakan (bukan cuma lihat angka agregatnya).
    $queryOpen = "
        SELECT pic, bulan, tindakan, konteks FROM (
            SELECT ca.pic, ca.tindakan, DATE_FORMAT(h.tgl_terbit, '%Y-%m') AS bulan, ca.status,
                TRIM(BOTH ' - ' FROM CONCAT(COALESCE(h.machine_no, ''), ' - ', COALESCE(h.nama_part, ''))) AS konteks
            FROM fpm_corrective_action ca JOIN fpm_header h ON ca.id_fpm = h.id_fpm
            WHERE $filterFpm

            UNION ALL

            SELECT pa.pic, pa.tindakan, DATE_FORMAT(h.tgl_terbit, '%Y-%m') AS bulan, pa.status,
                TRIM(BOTH ' - ' FROM CONCAT(COALESCE(h.machine_no, ''), ' - ', COALESCE(h.nama_part, ''))) AS konteks
            FROM fpm_preventive_action pa JOIN fpm_header h ON pa.id_fpm = h.id_fpm
            WHERE $filterFpm

            UNION ALL

            SELECT ng.pic, ng.deskripsi AS tindakan, DATE_FORMAT(ah.tanggal, '%Y-%m') AS bulan, ng.status,
                TRIM(BOTH ' - ' FROM CONCAT(COALESCE(ah.machine, ''), ' - ', COALESCE(ah.namapart, ''))) AS konteks
            FROM asakai_countermeasure_ng ng JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai
            WHERE $filterAsakai

            UNION ALL

            SELECT dt.pic, dt.deskripsi AS tindakan, DATE_FORMAT(ah.tanggal, '%Y-%m') AS bulan, dt.status,
                TRIM(BOTH ' - ' FROM CONCAT(COALESCE(ah.machine, ''), ' - ', COALESCE(ah.namapart, ''))) AS konteks
            FROM asakai_countermeasure_downtime dt JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai
            WHERE $filterAsakai
        ) gabungan
        WHERE pic IS NOT NULL AND pic <> '' AND pic <> 'All Dept' AND (status IS NULL OR status != 'CLOSED')
        ORDER BY pic ASC, bulan ASC
    ";
    $resultOpen = mysqli_query($koneksi, $queryOpen);
    while ($resultOpen && ($row = mysqli_fetch_assoc($resultOpen))) {
        if (isset($matrix[$row['pic']][$row['bulan']])) {
            $tindakan = $row['tindakan'] ?: '(tanpa keterangan)';
            $konteks = trim((string) $row['konteks']);
            $matrix[$row['pic']][$row['bulan']]['items'][] = $konteks !== '' ? "[$konteks] $tindakan" : $tindakan;
        }
    }

    ksort($matrix);

    return ['bulan' => $bulanList, 'dept' => $matrix];
}
