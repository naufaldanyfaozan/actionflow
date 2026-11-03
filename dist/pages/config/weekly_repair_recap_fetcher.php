<?php

require_once __DIR__ . '/verifikasi_countermeasure_helper.php';

/**
 * Aggregates every repair/countermeasure item (FPM corrective, preventive,
 * update dokumen + Asakai NG & Down Time countermeasures) whose due_date
 * falls inside $filterBulan, and buckets them into calendar weeks
 * (W1: 1-7, W2: 8-14, W3: 15-21, W4: 22-28, W5: 29-end when the month has
 * 29+ days) grouped per department (pic), for the "Rekap Perbaikan
 * Mingguan" sidebar menu shared by every role.
 *
 * $onlyClaimMach: null = semua; true = Staff QA, hanya FPM-Cast (tanpa FPM-D/FPM-N & Asakai);
 * false = Staff IMP, semua kecuali FPM-Cast.
 *
 * @return array{weeks: array, summary: array, bulan: string}
 */
function fetchWeeklyRepairRecap(mysqli $koneksi, string $filterBulan, string $filterPlant = '', string $filterDept = '', string $filterStatus = '', ?bool $onlyClaimMach = null): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $filterBulan)) {
        $filterBulan = date('Y-m');
    }

    $plantFpm    = '';
    $plantAsakai = '';
    if ($filterPlant !== '') {
        $plantEsc    = mysqli_real_escape_string($koneksi, $filterPlant);
        $plantFpm    = " AND h.plant = '$plantEsc'";
        $plantAsakai = " AND ah.plant = '$plantEsc'";
    }

    $bulanEsc = mysqli_real_escape_string($koneksi, $filterBulan);

    if ($onlyClaimMach !== null) {
        $plantFpm .= ' ' . buildVerifikasiFpmFlowClause($onlyClaimMach);
    }
    if ($onlyClaimMach === true) {
        $plantAsakai .= ' AND 1 = 0';
    }

    $query = mysqli_query($koneksi, "
        SELECT * FROM (
            SELECT ca.pic AS pic, ca.tindakan AS deskripsi, ca.due_date AS due_date, ca.status AS status,
                   'FPM - Corrective Action' AS sumber, h.nama_part AS ref_part, h.machine_no AS ref_machine, h.no_reg_fpm AS ref_no,
                   h.id_fpm AS id
            FROM fpm_corrective_action ca
            JOIN fpm_header h ON ca.id_fpm = h.id_fpm
            WHERE ca.due_date IS NOT NULL AND ca.due_date != '0000-00-00'
              AND DATE_FORMAT(ca.due_date, '%Y-%m') = '$bulanEsc' $plantFpm

            UNION ALL

            SELECT pa.pic, pa.tindakan, pa.due_date, pa.status,
                   'FPM - Preventive Action', h.nama_part, h.machine_no, h.no_reg_fpm,
                   h.id_fpm
            FROM fpm_preventive_action pa
            JOIN fpm_header h ON pa.id_fpm = h.id_fpm
            WHERE pa.due_date IS NOT NULL AND pa.due_date != '0000-00-00'
              AND DATE_FORMAT(pa.due_date, '%Y-%m') = '$bulanEsc' $plantFpm

            UNION ALL

            SELECT rd.pic, rd.dokumen, rd.due_date, rd.status,
                   'FPM - Update Dokumen', h.nama_part, h.machine_no, h.no_reg_fpm,
                   h.id_fpm
            FROM fpm_review_dokumen rd
            JOIN fpm_header h ON rd.id_fpm = h.id_fpm
            WHERE rd.due_date IS NOT NULL AND rd.due_date != '0000-00-00'
              AND DATE_FORMAT(rd.due_date, '%Y-%m') = '$bulanEsc' $plantFpm

            UNION ALL

            SELECT ng.pic, ng.deskripsi, ng.due_date, ng.status,
                   'Asakai - NG', ah.namapart, ah.machine, NULL,
                   ah.id_asakai
            FROM asakai_countermeasure_ng ng
            JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai
            WHERE ng.due_date IS NOT NULL AND ng.due_date != '0000-00-00'
              AND DATE_FORMAT(ng.due_date, '%Y-%m') = '$bulanEsc' $plantAsakai

            UNION ALL

            SELECT dt.pic, dt.deskripsi, dt.due_date, dt.status,
                   'Asakai - Down Time', ah.namapart, ah.machine, NULL,
                   ah.id_asakai
            FROM asakai_countermeasure_downtime dt
            JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai
            WHERE dt.due_date IS NOT NULL AND dt.due_date != '0000-00-00'
              AND DATE_FORMAT(dt.due_date, '%Y-%m') = '$bulanEsc' $plantAsakai
        ) AS gabungan
        WHERE pic IS NOT NULL AND pic != ''
    ");

    $rows = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = $row;
    }

    if ($filterDept !== '') {
        $rows = array_values(array_filter($rows, fn($r) => $r['pic'] === $filterDept));
    }

    if ($filterStatus !== '') {
        $rows = array_values(array_filter(
            $rows,
            fn($r) => classifyRepairStatus($r['status'], $r['due_date']) === $filterStatus
        ));
    }

    // ================= BANGUN RENTANG MINGGU (W1..W5) UNTUK BULAN TERPILIH =================
    $daysInMonth = (int) date('t', strtotime($filterBulan . '-01'));

    $weekBounds = [];
    $start = 1;
    $weekNo = 1;
    while ($start <= $daysInMonth) {
        $end = min($start + 6, $daysInMonth);
        $weekBounds[$weekNo] = ['start' => $start, 'end' => $end];
        $start = $end + 1;
        $weekNo++;
    }

    $weeks = [];
    foreach ($weekBounds as $no => $bound) {
        $weeks[$no] = [
            'label'   => "Minggu ke-$no (Tgl {$bound['start']} - {$bound['end']})",
            'start'   => $bound['start'],
            'end'     => $bound['end'],
            'depts'   => [],
            'total'   => 0,
            'open'    => 0,
            'progress' => 0,
            'closed'  => 0,
            'overdue' => 0,
        ];
    }

    $summary = ['total' => 0, 'open' => 0, 'progress' => 0, 'closed' => 0, 'overdue' => 0];

    foreach ($rows as $row) {
        $day = (int) date('j', strtotime($row['due_date']));

        $weekNo = null;
        foreach ($weekBounds as $no => $bound) {
            if ($day >= $bound['start'] && $day <= $bound['end']) {
                $weekNo = $no;
                break;
            }
        }
        if ($weekNo === null) {
            continue;
        }

        $state = classifyRepairStatus($row['status'], $row['due_date']);
        $row['state'] = $state;

        $dept = $row['pic'];
        $weeks[$weekNo]['depts'][$dept][] = $row;
        $weeks[$weekNo]['total']++;
        $weeks[$weekNo][$state]++;

        $summary['total']++;
        $summary[$state]++;
    }

    foreach ($weeks as $no => $week) {
        ksort($weeks[$no]['depts']);
        foreach ($weeks[$no]['depts'] as $dept => $items) {
            usort($weeks[$no]['depts'][$dept], fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
        }
    }

    return [
        'weeks'   => $weeks,
        'summary' => $summary,
        'bulan'   => $filterBulan,
    ];
}

/**
 * Classifies one countermeasure row into 'closed' | 'progress' | 'overdue' | 'open',
 * used both to color its badge and to bucket the week/summary counters.
 */
function classifyRepairStatus(?string $status, ?string $dueDate): string
{
    $status = strtoupper(trim((string) $status));

    if ($status === 'CLOSED') {
        return 'closed';
    }

    if ($status === 'ON PROGRESS') {
        return 'progress';
    }

    if ($dueDate && $dueDate !== '0000-00-00' && strtotime($dueDate) < strtotime(date('Y-m-d'))) {
        return 'overdue';
    }

    return 'open';
}
