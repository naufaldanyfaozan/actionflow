<?php

function cariRiwayatCountermeasure($koneksi, array $keywords, string $machine = '', string $part = '', int $limit = 20): array
{
    $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords), function ($k) {
        return mb_strlen($k) >= 2;
    })));

    if (!$keywords) {
        return [];
    }

    $buildOr = function (array $columns) use ($koneksi, $keywords) {
        $parts = [];
        foreach ($columns as $col) {
            foreach ($keywords as $kw) {
                $esc = mysqli_real_escape_string($koneksi, $kw);
                $parts[] = "$col LIKE '%$esc%'";
            }
        }
        return '(' . implode(' OR ', $parts) . ')';
    };

    $mesinFilter = $machine !== '' ? " AND machine_no LIKE '%" . mysqli_real_escape_string($koneksi, $machine) . "%'" : '';
    $mesinFilterAsakai = $machine !== '' ? " AND ah.machine LIKE '%" . mysqli_real_escape_string($koneksi, $machine) . "%'" : '';
    $partFilter = $part !== '' ? " AND nama_part LIKE '%" . mysqli_real_escape_string($koneksi, $part) . "%'" : '';
    $partFilterAsakai = $part !== '' ? " AND ah.namapart LIKE '%" . mysqli_real_escape_string($koneksi, $part) . "%'" : '';

    $whereCorrective = $buildOr(['h.jenis_masalah', 'h.jenis_masalah_detail']);
    $whereDowntime = $buildOr(['ah.downtime_problem']);
    $whereNg = $buildOr(['ah.ng_problem', 'anp.deskripsi']);

    $limitSafe = max(1, min(50, $limit));

    $query = "
        (SELECT 'FPM - Corrective' AS sumber, h.id_fpm, NULL AS id_asakai, h.tgl_ditemukan AS tanggal, h.machine_no AS mesin, h.nama_part AS part,
            CONCAT(h.jenis_masalah, IF(h.jenis_masalah_detail IS NOT NULL AND h.jenis_masalah_detail <> '', CONCAT(' - ', h.jenis_masalah_detail), '')) AS masalah,
            ca.tindakan, ca.pic, ca.status, ca.due_date
         FROM fpm_header h
         JOIN fpm_corrective_action ca ON ca.id_fpm = h.id_fpm
         WHERE $whereCorrective
         $mesinFilter $partFilter)

        UNION ALL

        (SELECT 'FPM - Preventive' AS sumber, h.id_fpm, NULL AS id_asakai, h.tgl_ditemukan AS tanggal, h.machine_no AS mesin, h.nama_part AS part,
            CONCAT(h.jenis_masalah, IF(h.jenis_masalah_detail IS NOT NULL AND h.jenis_masalah_detail <> '', CONCAT(' - ', h.jenis_masalah_detail), '')) AS masalah,
            pa.tindakan, pa.pic, pa.status, pa.due_date
         FROM fpm_header h
         JOIN fpm_preventive_action pa ON pa.id_fpm = h.id_fpm
         WHERE $whereCorrective
         $mesinFilter $partFilter)

        UNION ALL

        (SELECT 'Asakai - Down Time' AS sumber, NULL AS id_fpm, ah.id_asakai, ah.tanggal, ah.machine AS mesin, ah.namapart AS part,
            ah.downtime_problem AS masalah,
            dt.deskripsi AS tindakan, dt.pic, dt.status, dt.due_date
         FROM asakai_header ah
         JOIN asakai_countermeasure_downtime dt ON dt.id_asakai = ah.id_asakai
         WHERE $whereDowntime
         $mesinFilterAsakai $partFilterAsakai)

        UNION ALL

        (SELECT 'Asakai - NG' AS sumber, NULL AS id_fpm, ah.id_asakai, ah.tanggal, ah.machine AS mesin, ah.namapart AS part,
            COALESCE(NULLIF(ah.ng_problem, ''), anp.deskripsi) AS masalah,
            cn.deskripsi AS tindakan, cn.pic, cn.status, cn.due_date
         FROM asakai_header ah
         JOIN asakai_countermeasure_ng cn ON cn.id_asakai = ah.id_asakai
         LEFT JOIN asakai_ng_problem anp ON anp.id_asakai = ah.id_asakai
         WHERE $whereNg
         $mesinFilterAsakai $partFilterAsakai)

        ORDER BY tanggal DESC
        LIMIT $limitSafe
    ";

    $result = mysqli_query($koneksi, $query);

    if (!$result) {
        return [];
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }

    return $data;
}
