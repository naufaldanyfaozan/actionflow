<?php
/**
 * Shared content for "Rekap Perbaikan Mingguan" (weekly repair recap).
 * Included from inside <main class="app-main"> by every role's
 * rekap_perbaikan.php after $recap has been built with
 * fetchWeeklyRepairRecap(). Expects the following variables in scope:
 *
 * @var mysqli $koneksi
 * @var array  $recap        Result of fetchWeeklyRepairRecap().
 * @var string $filterBulan  'Y-m'
 * @var string $filterPlant
 * @var string $filterDept
 * @var string $filterStatus
 * @var array  $masterDept   List of department names for the filter dropdown.
 * @var string $namaPeriode  Human readable "Agustus 2026" label.
 * @var string $fpmTargetPage Filename of this role's FPM update page (e.g. 'edit_fpm_downtime.php').
 */

$fpmTargetPage = $fpmTargetPage ?? 'edit_fpm_downtime.php';

$statusLabel = [
    'open'     => 'Belum Dikerjakan',
    'progress' => 'Dalam Proses',
    'overdue'  => 'Terlambat',
    'closed'   => 'Selesai',
];

$statusBadgeClass = [
    'open'     => 'wrr-badge-open',
    'progress' => 'wrr-badge-progress',
    'overdue'  => 'wrr-badge-overdue',
    'closed'   => 'wrr-badge-closed',
];

$todayDay        = (int) date('j');
$isCurrentMonth  = ($filterBulan === date('Y-m'));
$defaultOpenWeek = null;

foreach ($recap['weeks'] as $no => $week) {
    if ($isCurrentMonth && $todayDay >= $week['start'] && $todayDay <= $week['end']) {
        $defaultOpenWeek = $no;
        break;
    }
}
if ($defaultOpenWeek === null) {
    foreach ($recap['weeks'] as $no => $week) {
        if ($week['total'] > 0) {
            $defaultOpenWeek = $no;
            break;
        }
    }
}
if ($defaultOpenWeek === null) {
    $defaultOpenWeek = array_key_first($recap['weeks']);
}
?>
<style>
    .wrr-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 18px;
        font-size: 12.5px;
        color: #475569;
    }

    .wrr-legend span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .wrr-legend .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
    }

    .wrr-badge-open {
        background: #fef3c7;
        color: #92400e;
    }

    .wrr-badge-progress {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .wrr-badge-overdue {
        background: #fee2e2;
        color: #b91c1c;
    }

    .wrr-badge-closed {
        background: #d1fae5;
        color: #047857;
    }

    .wrr-badge-open,
    .wrr-badge-progress,
    .wrr-badge-overdue,
    .wrr-badge-closed {
        font-weight: 700;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-block;
        white-space: nowrap;
    }

    .wrr-accordion .accordion-item {
        border: none;
        border-radius: 14px !important;
        overflow: hidden;
        margin-bottom: 14px;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
    }

    .wrr-accordion .accordion-button {
        font-weight: 700;
        font-size: 14.5px;
        color: #1e293b;
        background: #fff;
    }

    .wrr-accordion .accordion-button:not(.collapsed) {
        background: #eef2ff;
        color: #1e293b;
        box-shadow: none;
    }

    .wrr-accordion .accordion-button:focus {
        box-shadow: none;
    }

    .wrr-week-counts {
        display: inline-flex;
        gap: 6px;
        margin-left: auto;
        margin-right: 14px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .wrr-dept-block {
        margin-bottom: 18px;
    }

    .wrr-dept-block:last-child {
        margin-bottom: 0;
    }

    .wrr-dept-header {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #1e293b;
        color: #fff;
        font-weight: 700;
        font-size: 13px;
        padding: 8px 14px;
        border-radius: 8px 8px 0 0;
    }

    .wrr-dept-header .count {
        background: rgba(255, 255, 255, .18);
        border-radius: 999px;
        padding: 2px 10px;
        font-size: 11.5px;
    }

    .wrr-dept-table {
        margin-bottom: 0;
        border: 1px solid #e2e8f0;
        border-top: none;
    }

    .wrr-dept-table th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .3px;
        color: #64748b;
    }

    .wrr-dept-table td {
        vertical-align: middle;
        font-size: 12.5px;
    }

    .wrr-empty-week {
        text-align: center;
        color: #94a3b8;
        padding: 22px 0;
        font-size: 13px;
    }

    .wrr-dept-table tbody tr.wrr-clickable-row {
        cursor: pointer;
    }

    .wrr-dept-table tbody tr.wrr-clickable-row:hover {
        background-color: #f1f5f9;
    }
</style>

<div class="container-fluid dashboard-wrapper">

    <div class="dashboard-title">
        Rekap Perbaikan Mingguan
        <div style="font-size:14px; font-weight:500; margin-top:4px; color:#6c757d;">
            Ringkasan perbaikan (countermeasure FPM &amp; Asakai) per minggu untuk setiap departemen &mdash; <?= htmlspecialchars($namaPeriode) ?>
        </div>
    </div>

    <div class="filter-box">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto fw-semibold">Periode:</div>
            <div class="col-auto">
                <input type="month" name="bulan" class="form-control form-control-sm" value="<?= htmlspecialchars($filterBulan) ?>">
            </div>

            <div class="col-auto fw-semibold">Plant:</div>
            <div class="col-auto">
                <select name="plant" class="form-control form-control-sm">
                    <option value="">-- All Plant --</option>
                    <option value="1" <?= ($filterPlant == '1') ? 'selected' : '' ?>>Plant 1</option>
                    <option value="3" <?= ($filterPlant == '3') ? 'selected' : '' ?>>Plant 3</option>
                </select>
            </div>

            <div class="col-auto fw-semibold">Departemen:</div>
            <div class="col-auto">
                <select name="dept" class="form-control form-control-sm">
                    <option value="">-- Semua Departemen --</option>
                    <?php foreach ($masterDept as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= ($filterDept === $dept) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto fw-semibold">Status:</div>
            <div class="col-auto">
                <select name="status" class="form-control form-control-sm">
                    <option value="">-- Semua Status --</option>
                    <?php foreach ($statusLabel as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($filterStatus === $key) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <button class="btn btn-primary btn-sm">Filter</button>
                <a href="?" class="btn btn-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon"><i class="bi bi-tools"></i></div>
                <div>
                    <div class="kpi-title">Total Perbaikan</div>
                    <div class="kpi-value"><?= $recap['summary']['total'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="kpi-title">Belum / Proses</div>
                    <div class="kpi-value"><?= $recap['summary']['open'] + $recap['summary']['progress'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="kpi-title">Terlambat</div>
                    <div class="kpi-value"><?= $recap['summary']['overdue'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="kpi-title">Selesai</div>
                    <div class="kpi-value"><?= $recap['summary']['closed'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="wrr-legend section">
        <span><span class="dot" style="background:#fbbf24"></span> Belum Dikerjakan</span>
        <span><span class="dot" style="background:#3b82f6"></span> Dalam Proses</span>
        <span><span class="dot" style="background:#ef4444"></span> Terlambat</span>
        <span><span class="dot" style="background:#10b981"></span> Selesai</span>
    </div>

    <?php if ($recap['summary']['total'] === 0): ?>
        <div class="card section">
            <div class="card-body text-center text-muted py-5">
                Tidak ada data perbaikan (countermeasure) dengan due date pada <?= htmlspecialchars($namaPeriode) ?> sesuai filter yang dipilih.
            </div>
        </div>
    <?php else: ?>
        <div class="accordion wrr-accordion section" id="weeklyRecapAccordion">
            <?php foreach ($recap['weeks'] as $no => $week): ?>
                <?php
                $collapseId  = "wrrWeek{$no}";
                $isOpen      = ($no === $defaultOpenWeek);
                $buttonClass = $isOpen ? 'accordion-button' : 'accordion-button collapsed';
                $showClass   = $isOpen ? 'accordion-collapse collapse show' : 'accordion-collapse collapse';
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="<?= $buttonClass ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
                            <span><?= htmlspecialchars($week['label']) ?></span>
                            <span class="wrr-week-counts">
                                <?php if ($week['total'] === 0): ?>
                                    <span class="badge bg-light text-muted">Tidak ada perbaikan</span>
                                <?php else: ?>
                                    <?php if ($week['overdue'] > 0): ?><span class="wrr-badge-overdue"><?= $week['overdue'] ?> Terlambat</span><?php endif; ?>
                                    <?php if (($week['open'] + $week['progress']) > 0): ?><span class="wrr-badge-open"><?= $week['open'] + $week['progress'] ?> Proses</span><?php endif; ?>
                                    <?php if ($week['closed'] > 0): ?><span class="wrr-badge-closed"><?= $week['closed'] ?> Selesai</span><?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </button>
                    </h2>
                    <div id="<?= $collapseId ?>" class="<?= $showClass ?>" data-bs-parent="#weeklyRecapAccordion">
                        <div class="accordion-body">
                            <?php if (empty($week['depts'])): ?>
                                <div class="wrr-empty-week">Tidak ada perbaikan yang harus diselesaikan pada minggu ini.</div>
                            <?php else: ?>
                                <?php foreach ($week['depts'] as $deptName => $items): ?>
                                    <div class="wrr-dept-block">
                                        <div class="wrr-dept-header">
                                            <i class="bi bi-building"></i>
                                            <?= htmlspecialchars($deptName) ?>
                                            <span class="count"><?= count($items) ?> perbaikan</span>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm wrr-dept-table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th style="width:30px;">No</th>
                                                        <th>Perbaikan yang Harus Dilakukan</th>
                                                        <th style="width:150px;">Sumber</th>
                                                        <th style="width:160px;">Referensi</th>
                                                        <th style="width:100px;">Due Date</th>
                                                        <th style="width:120px;">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $i = 1; foreach ($items as $item): ?>
                                                        <?php
                                                        $ref = trim(implode(' / ', array_filter([
                                                            $item['ref_no'] ?? '',
                                                            $item['ref_part'] ?? '',
                                                            $item['ref_machine'] ?? '',
                                                        ])));

                                                        $itemId = $item['id'] ?? null;
                                                        $isAsakai = (strpos($item['sumber'], 'Asakai') === 0);
                                                        $targetUrl = null;
                                                        if ($itemId) {
                                                            $targetUrl = $isAsakai
                                                                ? 'update_asakai.php?id=' . urlencode($itemId)
                                                                : $fpmTargetPage . '?id=' . urlencode($itemId);
                                                        }
                                                        $rowClass = $targetUrl ? ' class="wrr-clickable-row"' : '';
                                                        $rowClick = $targetUrl ? ' onclick="window.location=\'' . htmlspecialchars($targetUrl, ENT_QUOTES) . '\'"' : '';
                                                        $rowTitle = $targetUrl ? ' title="Klik untuk update countermeasure ini"' : '';
                                                        ?>
                                                        <tr<?= $rowClass . $rowClick . $rowTitle ?>>
                                                            <td class="text-center"><?= $i++ ?></td>
                                                            <td><?= nl2br(htmlspecialchars($item['deskripsi'] ?? '-')) ?></td>
                                                            <td><?= htmlspecialchars($item['sumber']) ?></td>
                                                            <td><?= $ref !== '' ? htmlspecialchars($ref) : '-' ?></td>
                                                            <td class="text-center"><?= !empty($item['due_date']) ? date('d-m-Y', strtotime($item['due_date'])) : '-' ?></td>
                                                            <td class="text-center">
                                                                <span class="<?= $statusBadgeClass[$item['state']] ?>"><?= $statusLabel[$item['state']] ?></span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
