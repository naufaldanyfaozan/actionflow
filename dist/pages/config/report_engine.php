<?php

/**
 * Rule-based insight report — no external API calls.
 * Scores and ranks risks from the same data fetchDashboardData() already
 * assembles, and fills Indonesian narrative templates with the real numbers.
 *
 * @param array $data shape returned by fetchDashboardData()
 * @param string $periode e.g. "Juli 2026"
 * @param string $plant e.g. "Plant 1" or "" for all plants
 */
function generateReportInsights(array $data, string $periode, string $plant): array
{
    $kpi = $data['kpi'];
    $fpmActionDept = $data['fpm_action_dept'];
    $asakaiDept = $data['asakai_dept'];
    $fpmOverdue = $data['fpm_overdue'];
    $asakaiOverdue = $data['asakai_overdue'];
    $partMasalah = $data['part_masalah'];
    $machineMasalah = $data['machine_masalah'];
    $delivery = $data['delivery'];

    $totalDt = $kpi['closed'] + $kpi['open'];
    $fpmRate = $totalDt > 0 ? round(($kpi['closed'] / $totalDt) * 100, 1) : 0;

    $worstFpm = findWorstDept($fpmActionDept);
    $worstAsakai = findWorstDept($asakaiDept);

    $priorityActions = [];
    addOverdueActions($priorityActions, $fpmOverdue, 'FPM');
    addOverdueActions($priorityActions, $asakaiOverdue, 'Asakai');
    addDeptActions($priorityActions, $fpmActionDept, 'FPM');
    addDeptActions($priorityActions, $asakaiDept, 'Asakai');
    addRecurringActions($priorityActions, $partMasalah, 'Part');
    addRecurringActions($priorityActions, $machineMasalah, 'Mesin');
    $stockRiskRows = addStockRiskActions($priorityActions, $delivery);

    usort($priorityActions, function ($a, $b) {
        $severityWeight = ['Tinggi' => 3, 'Sedang' => 2, 'Rendah' => 1];
        $diff = $severityWeight[$b['severity']] - $severityWeight[$a['severity']];
        return $diff !== 0 ? $diff : ($b['score'] <=> $a['score']);
    });

    $plantSuffix = !empty($plant) ? " untuk {$plant}" : '';

    $summaryParts = [];
    $summaryParts[] = "Pada periode {$periode}{$plantSuffix}, tercatat {$kpi['down_time']} kasus FPM dan {$kpi['asakai']} kasus Asakai.";
    $summaryParts[] = $totalDt > 0
        ? "Dari {$totalDt} countermeasure FPM Down Time yang harus ditindaklanjuti, tingkat penyelesaian mencapai {$fpmRate}% ({$kpi['closed']} closed)."
        : "Belum ada data countermeasure FPM Down Time pada periode ini.";

    if ($worstFpm || $worstAsakai) {
        $bits = [];
        if ($worstFpm) {
            $bits[] = "departemen {$worstFpm['dept']} ({$worstFpm['percent']}% FPM)";
        }
        if ($worstAsakai) {
            $bits[] = "departemen {$worstAsakai['dept']} ({$worstAsakai['percent']}% Asakai)";
        }
        $summaryParts[] = "Performa penyelesaian countermeasure terendah tercatat pada " . implode(' dan ', $bits) . '.';
    }

    $overdueCount = count($fpmOverdue) + count($asakaiOverdue);
    $stockRiskCount = count($stockRiskRows);
    if ($overdueCount > 0 || $stockRiskCount > 0) {
        $summaryParts[] = "Saat ini terdapat {$overdueCount} countermeasure yang telah melewati due date dan {$stockRiskCount} part berisiko kekurangan stock.";
    }

    return [
        'periode' => $periode,
        'plant' => $plant,
        'executive_summary' => implode(' ', $summaryParts),
        'priority_actions' => array_slice($priorityActions, 0, 10),
        'dept_ranking' => [
            'fpm' => sortDeptByPercentAsc($fpmActionDept),
            'asakai' => sortDeptByPercentAsc($asakaiDept),
        ],
        'overdue' => array_merge(
            array_map(fn($r) => $r + ['source' => 'FPM'], $fpmOverdue),
            array_map(fn($r) => $r + ['source' => 'Asakai'], $asakaiOverdue)
        ),
        'recurring_problems' => array_merge(
            filterRecurring($partMasalah, 'Part'),
            filterRecurring($machineMasalah, 'Mesin')
        ),
        'stock_risk' => $stockRiskRows,
    ];
}

function findWorstDept(array $deptRows): ?array
{
    $worst = null;
    foreach ($deptRows as $row) {
        $total = $row['open'] + $row['closed'];
        if ($total < 1) {
            continue;
        }
        if ($worst === null || $row['percent'] < $worst['percent']) {
            $worst = $row;
        }
    }
    return $worst;
}

function sortDeptByPercentAsc(array $deptRows): array
{
    usort($deptRows, fn($a, $b) => $a['percent'] <=> $b['percent']);
    return $deptRows;
}

function hariOverdue(?string $dueDate): int
{
    if (empty($dueDate) || $dueDate === '0000-00-00') {
        return 0;
    }
    $due = strtotime($dueDate);
    if ($due === false) {
        return 0;
    }
    $days = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $due))) / 86400);
    return max($days, 0);
}

function severityFromDays(int $days): string
{
    if ($days >= 14) {
        return 'Tinggi';
    }
    if ($days >= 7) {
        return 'Sedang';
    }
    return 'Rendah';
}

function addOverdueActions(array &$actions, array $overdueRows, string $source): void
{
    foreach ($overdueRows as $row) {
        $days = hariOverdue($row['due_date'] ?? null);
        $pic = $row['pic'] ?? '-';
        $deskripsi = $row['deskripsi'] ?? '-';
        $actions[] = [
            'category' => "Overdue {$source}",
            'severity' => severityFromDays($days),
            'score' => $days,
            'text' => "Tindak lanjuti segera countermeasure \"{$deskripsi}\" (PIC: {$pic}), sudah overdue {$days} hari.",
        ];
    }
}

function addDeptActions(array &$actions, array $deptRows, string $source): void
{
    foreach ($deptRows as $row) {
        $total = $row['open'] + $row['closed'];
        if ($total < 1 || $row['percent'] >= 80) {
            continue;
        }
        $actions[] = [
            'category' => "Performa Dept {$source}",
            'severity' => $row['percent'] < 50 ? 'Tinggi' : 'Sedang',
            'score' => 100 - $row['percent'],
            'text' => "Prioritaskan review countermeasure {$source} di departemen {$row['dept']}, tingkat penyelesaian baru {$row['percent']}% ({$row['closed']}/{$total}).",
        ];
    }
}

function filterRecurring(array $masalah, string $jenis): array
{
    $rows = [];
    foreach ($masalah['label'] as $i => $label) {
        $total = $masalah['total'][$i] ?? 0;
        if ($total >= 3) {
            $rows[] = ['jenis' => $jenis, 'label' => $label, 'total' => $total];
        }
    }
    return $rows;
}

function addRecurringActions(array &$actions, array $masalah, string $jenis): void
{
    foreach (filterRecurring($masalah, $jenis) as $row) {
        $actions[] = [
            'category' => "Masalah Berulang - {$jenis}",
            'severity' => $row['total'] >= 5 ? 'Tinggi' : 'Sedang',
            'score' => $row['total'],
            'text' => "{$jenis} \"{$row['label']}\" mengalami masalah berulang sebanyak {$row['total']} kali pada periode ini — pertimbangkan corrective action permanen / root cause analysis.",
        ];
    }
}

function addStockRiskActions(array &$actions, array $deliveryRows): array
{
    $riskRows = [];
    foreach ($deliveryRows as $row) {
        $actStock = (float) ($row['act_stock'] ?? 0);
        $deliveryPerHari = (float) ($row['delivery_per_hari'] ?? 0);

        if ($deliveryPerHari <= 0) {
            continue;
        }

        $isFgRisk = $actStock <= $deliveryPerHari;

        if (!$isFgRisk) {
            continue;
        }

        $riskRows[] = $row;

        $score = ($deliveryPerHari - $actStock) + 1000;

        $namaPart = $row['nama_part'] ?? '-';
        $actions[] = [
            'category' => 'Risiko Stock',
            'severity' => 'Tinggi',
            'score' => $score,
            'text' => "Part \"{$namaPart}\" berisiko kekurangan stock (Stock FG: {$actStock}, Delivery/hari: {$deliveryPerHari}) — percepat proses delivery.",
        ];
    }
    return $riskRows;
}
