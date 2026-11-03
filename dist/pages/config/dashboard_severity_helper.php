<?php

/**
 * KPI severity utama untuk satu periode/plant: total downtime (jam), NG rate (%),
 * efisiensi rata-rata (%), dan jumlah problem berulang.
 *
 * $whereAsakai / $whereFPM harus berupa string "WHERE ..." lengkap yang sudah
 * dibangun oleh halaman pemanggil (mengikuti filter Periode & Plant yang aktif).
 *
 * Catatan data: kolom `downtime` pada asakai_header adalah menit stop line -
 * dijumlahkan apa adanya (termasuk nilai negatif) karena itu tetap representasi
 * menit stop line sesungguhnya, bukan cuma selisih dari target yang perlu difilter.
 */
function hitungSeverityKpi($koneksi, string $whereAsakai, string $whereFPM): array
{
    $downtimeMenit = 0;
    $ngRate = 0.0;
    $efisiensiRata = 0.0;

    $query = "
        SELECT
            SUM(downtime) AS total_downtime_menit,
            SUM(ng_qty) AS total_ng,
            SUM(ok_qty) AS total_ok,
            AVG(CASE WHEN target > 0 THEN efficiency END) AS avg_efisiensi
        FROM asakai_header
        $whereAsakai
    ";
    $result = mysqli_query($koneksi, $query);
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $downtimeMenit = (int)($row['total_downtime_menit'] ?? 0);
        $totalOk = (int)($row['total_ok'] ?? 0);
        $totalNg = (int)($row['total_ng'] ?? 0);
        $ngRate = ($totalOk + $totalNg) > 0 ? round($totalNg / ($totalOk + $totalNg) * 100, 1) : 0.0;
        $efisiensiRata = round((float)($row['avg_efisiensi'] ?? 0), 1);
    }

    $problemBerulang = 0;
    $queryBerulang = "SELECT COUNT(*) AS total FROM fpm_header h $whereFPM AND kejadian_berulang_ke > 0";
    $resultBerulang = mysqli_query($koneksi, $queryBerulang);
    if ($resultBerulang && ($rowBerulang = mysqli_fetch_assoc($resultBerulang))) {
        $problemBerulang = (int)($rowBerulang['total'] ?? 0);
    }

    return [
        'downtime_menit' => $downtimeMenit,
        // downtime_jam dipertahankan (dulu field utama) supaya dashboard role lain yang
        // belum dirombak ke versi "Ringkasan Sasaran Mutu" baru tidak ikut berubah.
        'downtime_jam' => round($downtimeMenit / 60, 1),
        'ng_rate' => $ngRate,
        'efisiensi_rata' => $efisiensiRata,
        'problem_berulang' => $problemBerulang,
    ];
}

/**
 * Jumlah FPM dengan problem berulang (kejadian_berulang_ke > 0) pada satu
 * periode/scope $whereFPM. Dipakai oleh dashboard yang tidak punya data
 * Asakai (mis. dept_head_qa) sehingga tidak memakai hitungSeverityKpi().
 */
function hitungProblemBerulangFpm($koneksi, string $whereFPM): int
{
    $result = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM fpm_header h $whereFPM AND kejadian_berulang_ke > 0");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

/**
 * Daftar part/mesin dengan problem berulang (kejadian_berulang_ke > 0),
 * diurutkan dari yang paling sering terulang.
 */
function topProblemBerulang($koneksi, string $whereFPM, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $query = "
        SELECT nama_part, machine_no, jenis_masalah, kejadian_berulang_ke, tgl_ditemukan, internal_dept, dept
        FROM fpm_header h
        $whereFPM
        AND kejadian_berulang_ke > 0
        ORDER BY kejadian_berulang_ke DESC, tgl_ditemukan DESC
        LIMIT $limit
    ";
    $result = mysqli_query($koneksi, $query);
    $data = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
    }
    return $data;
}

/**
 * Perbandingan metrik severity antar plant untuk satu periode (bulan),
 * mengabaikan filter plant yang sedang aktif di halaman (khusus untuk
 * dashboard level management yang perlu melihat semua plant sekaligus).
 */
function perbandinganAntarPlant($koneksi, string $filterBulan): array
{
    $bulanEsc = mysqli_real_escape_string($koneksi, $filterBulan);
    $rows = [];

    $queryAsakai = "
        SELECT plant,
            SUM(downtime) AS total_downtime_menit,
            SUM(ng_qty) AS total_ng,
            SUM(ok_qty) AS total_ok,
            AVG(CASE WHEN target > 0 THEN efficiency END) AS avg_efisiensi,
            COUNT(*) AS total_asakai
        FROM asakai_header
        WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$bulanEsc'
        GROUP BY plant
    ";
    $resultAsakai = mysqli_query($koneksi, $queryAsakai);
    while ($resultAsakai && ($row = mysqli_fetch_assoc($resultAsakai))) {
        $plant = trim((string)$row['plant']);
        if ($plant === '') {
            continue;
        }
        $totalOk = (int)($row['total_ok'] ?? 0);
        $totalNg = (int)($row['total_ng'] ?? 0);
        $rows[$plant]['plant'] = $plant;
        $rows[$plant]['downtime_menit'] = (int)($row['total_downtime_menit'] ?? 0);
        $rows[$plant]['downtime_jam'] = round($rows[$plant]['downtime_menit'] / 60, 1);
        $rows[$plant]['ng_rate'] = ($totalOk + $totalNg) > 0 ? round($totalNg / ($totalOk + $totalNg) * 100, 1) : 0.0;
        $rows[$plant]['efisiensi_rata'] = round((float)($row['avg_efisiensi'] ?? 0), 1);
        $rows[$plant]['total_asakai'] = (int)($row['total_asakai'] ?? 0);
    }

    // Countermeasure Asakai (NG + downtime) open/closed per plant.
    $queryAsakaiCm = "
        SELECT ah.plant,
            SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS closed,
            SUM(CASE WHEN status != 'CLOSED' THEN 1 ELSE 0 END) AS open_count
        FROM (
            SELECT ng.status, ng.id_asakai FROM asakai_countermeasure_ng ng
            UNION ALL
            SELECT dt.status, dt.id_asakai FROM asakai_countermeasure_downtime dt
        ) cm
        JOIN asakai_header ah ON ah.id_asakai = cm.id_asakai
        WHERE DATE_FORMAT(ah.tanggal, '%Y-%m') = '$bulanEsc'
        GROUP BY ah.plant
    ";
    $resultAsakaiCm = mysqli_query($koneksi, $queryAsakaiCm);
    while ($resultAsakaiCm && ($row = mysqli_fetch_assoc($resultAsakaiCm))) {
        $plant = trim((string)$row['plant']);
        if ($plant === '' || !isset($rows[$plant])) {
            continue;
        }
        $rows[$plant]['asakai_cm_closed'] = (int)($row['closed'] ?? 0);
        $rows[$plant]['asakai_cm_open'] = (int)($row['open_count'] ?? 0);
    }

    $queryFpm = "
        SELECT h.plant,
            COUNT(*) AS total_fpm,
            SUM(CASE WHEN kejadian_berulang_ke > 0 THEN 1 ELSE 0 END) AS total_berulang,
            SUM(CASE WHEN
                NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND ca.status != 'CLOSED')
                AND NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND pa.status != 'CLOSED')
            THEN 1 ELSE 0 END) AS total_closed
        FROM fpm_header h
        WHERE DATE_FORMAT(h.tgl_terbit, '%Y-%m') = '$bulanEsc'
        GROUP BY h.plant
    ";
    $resultFpm = mysqli_query($koneksi, $queryFpm);
    while ($resultFpm && ($row = mysqli_fetch_assoc($resultFpm))) {
        $plant = trim((string)$row['plant']);
        if ($plant === '') {
            continue;
        }
        if (!isset($rows[$plant])) {
            $rows[$plant] = [
                'plant' => $plant,
                'downtime_menit' => 0,
                'downtime_jam' => 0.0,
                'ng_rate' => 0.0,
                'efisiensi_rata' => 0.0,
                'total_asakai' => 0,
                'asakai_cm_closed' => 0,
                'asakai_cm_open' => 0,
            ];
        }
        $rows[$plant]['asakai_cm_closed'] = $rows[$plant]['asakai_cm_closed'] ?? 0;
        $rows[$plant]['asakai_cm_open'] = $rows[$plant]['asakai_cm_open'] ?? 0;
        $rows[$plant]['total_fpm'] = (int)($row['total_fpm'] ?? 0);
        $rows[$plant]['total_fpm_closed'] = (int)($row['total_closed'] ?? 0);
        $rows[$plant]['total_berulang'] = (int)($row['total_berulang'] ?? 0);
    }

    foreach ($rows as $plant => $row) {
        $rows[$plant]['total_fpm'] = $row['total_fpm'] ?? 0;
        $rows[$plant]['total_fpm_closed'] = $row['total_fpm_closed'] ?? 0;
        $rows[$plant]['total_berulang'] = $row['total_berulang'] ?? 0;
        $rows[$plant]['asakai_cm_closed'] = $row['asakai_cm_closed'] ?? 0;
        $rows[$plant]['asakai_cm_open'] = $row['asakai_cm_open'] ?? 0;
    }

    ksort($rows);
    return array_values($rows);
}

/**
 * Ranking departemen/PIC berdasarkan proporsi countermeasure yang masih OPEN
 * (gabungan FPM corrective+preventive dan Asakai NG+downtime), diurutkan dari
 * yang paling banyak tunggakan agar management bisa langsung melihat siapa
 * yang perlu didorong.
 */
function rankingDepartemenPenanganan($koneksi, string $whereFPM, ?string $whereAsakai = null, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));

    $subqueries = [
        "SELECT ca.pic, ca.status FROM fpm_corrective_action ca JOIN fpm_header h ON ca.id_fpm = h.id_fpm $whereFPM",
        "SELECT pa.pic, pa.status FROM fpm_preventive_action pa JOIN fpm_header h ON pa.id_fpm = h.id_fpm $whereFPM",
    ];
    if ($whereAsakai !== null) {
        $subqueries[] = "SELECT ng.pic, ng.status FROM asakai_countermeasure_ng ng JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai $whereAsakai";
        $subqueries[] = "SELECT dt.pic, dt.status FROM asakai_countermeasure_downtime dt JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai $whereAsakai";
    }

    $query = "
        SELECT pic,
            SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed,
            SUM(CASE WHEN status != 'CLOSED' THEN 1 ELSE 0 END) AS total_open,
            COUNT(*) AS total
        FROM (" . implode("\n            UNION ALL\n            ", $subqueries) . ") gabungan
        WHERE pic IS NOT NULL AND pic <> ''
        GROUP BY pic
        ORDER BY (SUM(CASE WHEN status != 'CLOSED' THEN 1 ELSE 0 END) / COUNT(*)) DESC,
            SUM(CASE WHEN status != 'CLOSED' THEN 1 ELSE 0 END) DESC
        LIMIT $limit
    ";
    $result = mysqli_query($koneksi, $query);
    $data = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $total = (int)$row['total'];
            $row['persen_open'] = $total > 0 ? round(((int)$row['total_open'] / $total) * 100, 1) : 0.0;
            $data[] = $row;
        }
    }
    return $data;
}
