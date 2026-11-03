<?php

require_once __DIR__ . '/fpm_claim_machining_helper.php';
require_once __DIR__ . '/fpm_claim_approval_helper.php';

/**
 * Render halaman "LOG TPDP" (rekap status FPM ala FOR/QA/02/04/04) untuk satu jenis FPM.
 * Dipanggil dari file tipis di tiap folder role (dept_head, plant_head, vice_directur,
 * directur, dept_head_qa) supaya logic query/render-nya tidak diduplikasi 5x4 kali.
 *
 * $jenisLog salah satu dari: 'INTERNAL', 'INTERNAL_QA', 'CUSTOMER', 'DOWNTIME', 'CLAIMMACH'
 */
// $detailPage: halaman tujuan tombol Detail (relatif ke folder role pemanggil).
function renderLogTpdpPage($koneksi, $jenisLog, $detailPage = 'form_fpm.php')
{
    $labelMap = [
        'INTERNAL'    => 'LOG TPDP Internal',
        'INTERNAL_QA' => 'LOG TPDP Internal QA',
        'CUSTOMER'    => 'LOG TPDP Customer',
        'DOWNTIME'    => 'LOG TPDP Down Time',
        'CLAIMMACH'   => 'LOG TPDP Claim Machining',
    ];
    $pageTitle = $labelMap[$jenisLog] ?? 'LOG TPDP';

    $tglAwalFilter  = $_GET['tgl_awal'] ?? '';
    $tglAkhirFilter = $_GET['tgl_akhir'] ?? '';
    $deptFilter   = $_GET['dept'] ?? '';
    $mesinFilter  = $_GET['mesin'] ?? '';
    $statusFilter = $_GET['status'] ?? '';
    $searchFilter = trim($_GET['search'] ?? '');

    switch ($jenisLog) {
        case 'INTERNAL':
            // FPM-N (NG Internal IMP) disimpan tanpa mengisi flow_type, jadi bisa kosong/NULL
            $where = "WHERE (h.flow_type = 'IMP' OR h.flow_type = '' OR h.flow_type IS NULL) AND h.jenis_fpm IN ('NG Casting','NG Machining')";
            break;
        case 'INTERNAL_QA':
            $where = "WHERE h.flow_type = 'QA' AND h.jenis_fpm = 'NG Machining'";
            break;
        case 'CUSTOMER':
            $where = "WHERE h.jenis_fpm = 'CUSTOMER'";
            break;
        case 'DOWNTIME':
            $where = "WHERE h.jenis_fpm = 'Down Time'";
            break;
        case 'CLAIMMACH':
            $where = "WHERE h.flow_type = 'CLAIMMACH'";
            break;
        default:
            $where = "WHERE 1=0";
    }

    if (!empty($tglAwalFilter) && !empty($tglAkhirFilter)) {
        $tglAwalEsc  = mysqli_real_escape_string($koneksi, $tglAwalFilter);
        $tglAkhirEsc = mysqli_real_escape_string($koneksi, $tglAkhirFilter);
        $where .= " AND h.tgl_pembuatan BETWEEN '$tglAwalEsc' AND '$tglAkhirEsc'";
    }

    if (!empty($deptFilter)) {
        $deptFilter = mysqli_real_escape_string($koneksi, $deptFilter);
        $where .= " AND (h.dept = '$deptFilter' OR h.internal_dept = '$deptFilter')";
    }

    if (!empty($mesinFilter)) {
        $mesinFilter = mysqli_real_escape_string($koneksi, $mesinFilter);
        $where .= " AND h.machine_no = '$mesinFilter'";
    }

    if (!empty($searchFilter)) {
        $search = mysqli_real_escape_string($koneksi, $searchFilter);
        $where .= " AND (h.no_form LIKE '%$search%' OR h.nama_part LIKE '%$search%' OR h.jenis_masalah LIKE '%$search%' OR h.customer_nama LIKE '%$search%')";
    }

    $statusHaving = '';
    if ($statusFilter === 'OPEN') {
        $statusHaving = " HAVING total_action = 0 OR total_not_closed > 0";
    } elseif ($statusFilter === 'CLOSED') {
        $statusHaving = " HAVING total_action > 0 AND total_not_closed = 0";
    }

    $qFpm = mysqli_query($koneksi, "SELECT
        h.id_fpm, h.no_form, h.jenis_fpm, h.nama_part, h.machine_no, h.customer_nama,
        h.dept, h.internal_dept, h.jenis_masalah, h.jenis_masalah_detail,
        h.tgl_ditemukan, h.tgl_pembuatan, h.status_reject, h.kejadian_berulang_ke,
        h.cm_dept_head_dijawab_at, h.cm_dept_head_diterima_at, h.cm_plant_head_diterima_at,
        h.cm_dept_head_approved_at, h.cm_plant_head_approved_at, h.cm_directur_approved_at,
        h.cm_deptheadqa_approved_at,

        (
            (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm)
            + (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm)
        ) AS total_action,

        (
            (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND (ca.status IS NULL OR ca.status != 'CLOSED'))
            + (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND (pa.status IS NULL OR pa.status != 'CLOSED'))
        ) AS total_not_closed

        FROM fpm_header h
        $where
        GROUP BY h.id_fpm
        $statusHaving
        ORDER BY h.id_fpm DESC
    ");

    $rows = mysqli_fetch_all($qFpm, MYSQLI_ASSOC) ?: [];

    $masterDept = [];
    $qDept = mysqli_query($koneksi, "SELECT DISTINCT nama_dept FROM master_dept ORDER BY nama_dept ASC");
    while ($rowDept = mysqli_fetch_assoc($qDept)) {
        $masterDept[] = $rowDept['nama_dept'];
    }

    $masterMesin = [];
    $qMesin = mysqli_query($koneksi, "SELECT DISTINCT machine_no FROM fpm_header WHERE machine_no IS NOT NULL AND machine_no != '' ORDER BY machine_no ASC");
    while ($rowMesin = mysqli_fetch_assoc($qMesin)) {
        $masterMesin[] = $rowMesin['machine_no'];
    }

    $jumlahKolom = 17;
    if ($jenisLog === 'CUSTOMER') $jumlahKolom += 1;
    if ($jenisLog === 'INTERNAL' || $jenisLog === 'INTERNAL_QA') $jumlahKolom += 2;
    if ($jenisLog === 'CLAIMMACH') $jumlahKolom += 4;

    $today = date('Y-m-d');
?>
    <div class="card log-tpdp-card">

        <div class="log-tpdp-header">
            <div class="log-tpdp-title"><?= htmlspecialchars($pageTitle) ?></div>
            <div class="log-tpdp-badges">
                <div class="lt-badge lt-badge-today">TODAY<br><b><?= date('d-M-y') ?></b></div>
                <div class="lt-legend">
                    <span class="lt-dot lt-dot-closed"></span> CLOSED
                    <span class="lt-dot lt-dot-open"></span> OPEN
                </div>
            </div>
        </div>

        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>Tanggal Awal</label>
                <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tglAwalFilter) ?>">
            </div>

            <div class="filter-group">
                <label>Tanggal Akhir</label>
                <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tglAkhirFilter) ?>">
            </div>

            <?php if ($jenisLog !== 'CUSTOMER'): ?>
                <div class="filter-group">
                    <label>Mesin</label>
                    <select name="mesin" class="form-control">
                        <option value="">-- Semua Mesin --</option>
                        <?php foreach ($masterMesin as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= $mesinFilter === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="filter-group">
                <label>Dept</label>
                <select name="dept" class="form-control">
                    <option value="">-- Semua Dept --</option>
                    <?php foreach ($masterDept as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $deptFilter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Status FPM</label>
                <select name="status" class="form-control">
                    <option value="">-- Semua Status --</option>
                    <option value="OPEN" <?= $statusFilter === 'OPEN' ? 'selected' : '' ?>>Open</option>
                    <option value="CLOSED" <?= $statusFilter === 'CLOSED' ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>

            <div class="filter-group grow">
                <label>Cari No Form / Nama Part / Jenis Masalah / Customer</label>
                <input type="text" name="search" class="form-control" placeholder="Search ..." value="<?= htmlspecialchars($searchFilter) ?>">
            </div>

            <div class="filter-group">
                <label>&nbsp;</label>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary">Cari</button>
                    <a href="?" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>

        <div class="log-tpdp-scroll">
            <table class="log-tpdp-table">
                <thead>
                    <tr>
                        <th style="width:36px;">No</th>
                        <th style="width:90px;">Sketch</th>
                        <th style="width:110px;">No. Reg FPM</th>
                        <th style="width:70px;">Jenis FPM</th>
                        <th style="width:150px;">Nama Part - Mesin</th>
                        <?php if ($jenisLog === 'CUSTOMER'): ?>
                            <th style="width:90px;">Customer</th>
                        <?php endif; ?>
                        <th style="width:160px;">Masalah</th>
                        <th style="width:260px;">Analisa Masalah</th>
                        <?php if ($jenisLog === 'INTERNAL' || $jenisLog === 'INTERNAL_QA'): ?>
                            <th style="width:60px;">NG Area (%)</th>
                            <th style="width:60px;">NG Total (%)</th>
                        <?php endif; ?>
                        <?php if ($jenisLog === 'CLAIMMACH'): ?>
                            <th style="width:80px;">Due Date Jawab</th>
                            <th style="width:80px;">Actual Jawab</th>
                            <th style="width:70px;">Status On Time</th>
                            <th style="width:140px;">Status Approve</th>
                        <?php endif; ?>
                        <th style="width:320px;">Counter Measure</th>
                        <th style="width:70px;">Status Action</th>
                        <th style="width:60px;">On Time</th>
                        <th style="width:60px;">Not On Time</th>
                        <th style="width:70px;">Status FPM</th>
                        <th style="width:120px;">Update Dokumen</th>
                        <th style="width:120px;">Action 4M</th>
                        <th style="width:60px;">Similar Part</th>
                        <th style="width:60px;">Verifikasi</th>
                        <th style="width:70px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= $jumlahKolom ?>" style="text-align:center; padding:24px; color:#999; font-style:italic;">Tidak ada data</td></tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($rows as $row): ?>
                            <?php
                            $qDetail = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '{$row['id_fpm']}' LIMIT 1");
                            $detail = mysqli_fetch_assoc($qDetail) ?: [];

                            $qAnalisa = mysqli_query($koneksi, "SELECT * FROM fpm_analisa WHERE id_fpm = '{$row['id_fpm']}' ORDER BY jenis ASC, id ASC");
                            $analisaRows = mysqli_fetch_all($qAnalisa, MYSQLI_ASSOC) ?: [];

                            $qCorrective = mysqli_query($koneksi, "SELECT * FROM fpm_corrective_action WHERE id_fpm = '{$row['id_fpm']}' ORDER BY id ASC");
                            $correctiveRows = mysqli_fetch_all($qCorrective, MYSQLI_ASSOC) ?: [];

                            $qPreventive = mysqli_query($koneksi, "SELECT * FROM fpm_preventive_action WHERE id_fpm = '{$row['id_fpm']}' ORDER BY id ASC");
                            $preventiveRows = mysqli_fetch_all($qPreventive, MYSQLI_ASSOC) ?: [];

                            $qDokumen = mysqli_query($koneksi, "SELECT DISTINCT dokumen FROM fpm_review_dokumen WHERE id_fpm = '{$row['id_fpm']}'");
                            $dokumenSet = [];
                            while ($d = mysqli_fetch_assoc($qDokumen)) { $dokumenSet[strtoupper(trim($d['dokumen']))] = true; }

                            $qSimilar = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM fpm_similar WHERE id_fpm = '{$row['id_fpm']}'");
                            $hasSimilar = (int) (mysqli_fetch_assoc($qSimilar)['total'] ?? 0) > 0;

                            $hasVerifikasi = !empty(trim($detail['verification_3_bulan'] ?? ''));

                            $totalAction = (int) $row['total_action'];
                            $totalNotClosed = (int) $row['total_not_closed'];
                            $statusFpmClosed = $totalAction > 0 && $totalNotClosed === 0;

                            $onTime = 0;
                            $notOnTime = 0;
                            $allActions = array_merge($correctiveRows, $preventiveRows);
                            foreach ($allActions as $a) {
                                $due = $a['due_date'] ?? null;
                                $actual = $a['actual_date'] ?? null;
                                if (empty($due) || $due === '0000-00-00') continue;
                                if (!empty($actual) && $actual !== '0000-00-00') {
                                    if ($actual <= $due) $onTime++; else $notOnTime++;
                                } elseif ($due < $today) {
                                    $notOnTime++;
                                }
                            }

                            $dueDateJawab = '';
                            $actualJawab = '';
                            $statusOnTimeJawab = '';
                            $statusApprove = ['approved' => '-', 'waiting' => '-'];
                            if ($jenisLog === 'CLAIMMACH') {
                                $dueDateJawab = hitungDueDateJawabClaimMachining(
                                    !empty($row['tgl_pembuatan']) ? $row['tgl_pembuatan'] : date('Y-m-d'),
                                    (int) ($row['kejadian_berulang_ke'] ?? 0)
                                );
                                $actualJawab = !empty($row['cm_dept_head_dijawab_at']) ? substr($row['cm_dept_head_dijawab_at'], 0, 10) : '';

                                if ($actualJawab !== '') {
                                    $statusOnTimeJawab = $actualJawab <= $dueDateJawab ? 'ON TIME' : 'NOT ON TIME';
                                } elseif ($dueDateJawab < $today) {
                                    $statusOnTimeJawab = 'NOT ON TIME';
                                } else {
                                    $statusOnTimeJawab = '-';
                                }

                                $statusApprove = hitungStatusApprovalClaimMach($row);
                                // Tahap "Dept Head ..." (selain Dept Head QA) ditunggu dari dept
                                // tujuan (h.dept) - tampilkan nama dept-nya supaya jelas siapa yang ditunggu.
                                $deptTujuan = trim($row['dept'] ?? '');
                                if ($deptTujuan !== '' && strpos($statusApprove['waiting'], 'Dept Head') === 0
                                    && strpos($statusApprove['waiting'], 'Dept Head QA') !== 0) {
                                    $statusApprove['waiting'] .= ' (' . $deptTujuan . ')';
                                }
                            }

                            $method4m = false; $man4m = false; $material4m = false; $machine4m = false;
                            foreach ($analisaRows as $a) {
                                $kat = strtoupper(trim($a['kategori'] ?? ''));
                                if ($kat === 'METHOD') $method4m = true;
                                if ($kat === 'MAN') $man4m = true;
                                if ($kat === 'MATERIAL') $material4m = true;
                                if ($kat === 'MACHINE') $machine4m = true;
                            }

                            $namaFile = trim($detail['file'] ?? '');
                            $filePath = $namaFile !== '' ? "../upload_fpm/$namaFile" : '';
                            ?>
                            <tr>
                                <td class="lt-center"><?= $no++ ?></td>
                                <td class="lt-center">
                                    <?php if ($filePath !== '' && file_exists(__DIR__ . '/../' . str_replace('../', '', $filePath))): ?>
                                        <img src="<?= htmlspecialchars($filePath) ?>" class="lt-sketch" title="Klik untuk memperbesar" onclick="ltZoomOpen(this.src)">
                                    <?php else: ?>
                                        <span class="lt-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['no_form']) ?></td>
                                <td class="lt-center"><?= htmlspecialchars($row['jenis_fpm']) ?></td>
                                <td><?= htmlspecialchars($row['nama_part']) ?><?= !empty($row['machine_no']) ? ' - ' . htmlspecialchars($row['machine_no']) : '' ?></td>
                                <?php if ($jenisLog === 'CUSTOMER'): ?>
                                    <td><?= htmlspecialchars($row['customer_nama'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td>
                                    <?= htmlspecialchars($row['jenis_masalah'] ?? '-') ?>
                                    <?php if (!empty($row['jenis_masalah_detail'])): ?>
                                        <br><span class="lt-muted"><?= htmlspecialchars($row['jenis_masalah_detail']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="lt-left">
                                    <?php if (empty($analisaRows)): ?>
                                        <span class="lt-muted">-</span>
                                    <?php else: ?>
                                        <?php
                                        $occurrence = array_filter($analisaRows, fn($a) => ($a['jenis'] ?? '') !== 'FLOW OUT');
                                        $flowout    = array_filter($analisaRows, fn($a) => ($a['jenis'] ?? '') === 'FLOW OUT');
                                        ?>
                                        <?php if (!empty($occurrence)): ?>
                                            <div class="lt-sub-title">Occurrence</div>
                                            <ul class="lt-list">
                                                <?php foreach ($occurrence as $a): ?>
                                                    <?php foreach (['why_1', 'why_2', 'why_3', 'why_4', 'why_5'] as $why): ?>
                                                        <?php if (!empty($a[$why])): ?><li><?= htmlspecialchars($a[$why]) ?></li><?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if (!empty($flowout)): ?>
                                            <div class="lt-sub-title">Flow Out</div>
                                            <ul class="lt-list">
                                                <?php foreach ($flowout as $a): ?>
                                                    <?php foreach (['why_1', 'why_2', 'why_3', 'why_4', 'why_5'] as $why): ?>
                                                        <?php if (!empty($a[$why])): ?><li><?= htmlspecialchars($a[$why]) ?></li><?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php if ($jenisLog === 'INTERNAL' || $jenisLog === 'INTERNAL_QA'): ?>
                                    <td class="lt-center"><?= isset($detail['ng_area_percent']) && $detail['ng_area_percent'] !== null ? number_format((float) $detail['ng_area_percent'], 2) : '-' ?></td>
                                    <td class="lt-center"><?= isset($detail['ng_total_percent']) && $detail['ng_total_percent'] !== null ? number_format((float) $detail['ng_total_percent'], 2) : '-' ?></td>
                                <?php endif; ?>
                                <?php if ($jenisLog === 'CLAIMMACH'): ?>
                                    <td class="lt-center"><?= $dueDateJawab !== '' ? date('d-M-y', strtotime($dueDateJawab)) : '-' ?></td>
                                    <td class="lt-center"><?= $actualJawab !== '' ? date('d-M-y', strtotime($actualJawab)) : '-' ?></td>
                                    <td class="lt-center">
                                        <?php if ($statusOnTimeJawab === 'ON TIME'): ?>
                                            <span class="lt-status lt-closed">ON TIME</span>
                                        <?php elseif ($statusOnTimeJawab === 'NOT ON TIME'): ?>
                                            <span class="lt-status lt-open">NOT ON TIME</span>
                                        <?php else: ?>
                                            <span class="lt-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="lt-center">
                                        <?= htmlspecialchars($statusApprove['approved']) ?>
                                        <?php if ($statusApprove['waiting'] !== '-'): ?>
                                            <br><span class="lt-muted">Menunggu: <?= htmlspecialchars($statusApprove['waiting']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="lt-left">
                                    <?php if (empty($correctiveRows) && empty($preventiveRows)): ?>
                                        <span class="lt-muted">-</span>
                                    <?php else: ?>
                                        <?php foreach ($correctiveRows as $i => $c): ?>
                                            <div class="lt-cm-line">
                                                # <?= htmlspecialchars($c['tindakan']) ?>
                                                <span class="lt-muted">
                                                    (Due: <?= !empty($c['due_date']) && $c['due_date'] != '0000-00-00' ? date('d-M-y', strtotime($c['due_date'])) : '-' ?>
                                                    | PIC: <?= htmlspecialchars($c['pic'] ?? '-') ?>
                                                    | <span class="lt-status-inline <?= strtoupper($c['status'] ?? '') === 'CLOSED' ? 'lt-closed' : 'lt-open' ?>"><?= htmlspecialchars($c['status'] ?? 'OPEN') ?></span>)
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php foreach ($preventiveRows as $p): ?>
                                            <div class="lt-cm-line">
                                                # <?= htmlspecialchars($p['tindakan']) ?>
                                                <span class="lt-muted">
                                                    (Due: <?= !empty($p['due_date']) && $p['due_date'] != '0000-00-00' ? date('d-M-y', strtotime($p['due_date'])) : '-' ?>
                                                    | PIC: <?= htmlspecialchars($p['pic'] ?? '-') ?>
                                                    | <span class="lt-status-inline <?= strtoupper($p['status'] ?? '') === 'CLOSED' ? 'lt-closed' : 'lt-open' ?>"><?= htmlspecialchars($p['status'] ?? 'OPEN') ?></span>)
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="lt-center">
                                    <?php if ($totalAction === 0): ?>
                                        <span class="lt-muted">-</span>
                                    <?php else: ?>
                                        <span class="lt-status <?= $statusFpmClosed ? 'lt-closed' : 'lt-open' ?>"><?= $statusFpmClosed ? 'CLOSED' : 'OPEN' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="lt-center"><?= $onTime > 0 ? $onTime : '-' ?></td>
                                <td class="lt-center"><?= $notOnTime > 0 ? '<span class="lt-notontime">' . $notOnTime . '</span>' : '-' ?></td>
                                <td class="lt-center">
                                    <?php if ($row['status_reject'] === 'REJECT'): ?>
                                        <span class="lt-status lt-revisi">REVISI</span>
                                    <?php else: ?>
                                        <span class="lt-status <?= $statusFpmClosed ? 'lt-closed' : 'lt-open' ?>"><?= $statusFpmClosed ? 'Closed' : 'Open' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="lt-center lt-doc-cell">
                                    <?php foreach (['FMEA', 'QCPC', 'PIS', 'IK'] as $doc): ?>
                                        <div><?= $doc ?>: <?= isset($dokumenSet[$doc]) ? '<span class="lt-check">&#10003;</span>' : '-' ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="lt-center lt-doc-cell">
                                    <div>Method: <?= $method4m ? '<span class="lt-check">&#10003;</span>' : '-' ?></div>
                                    <div>Man: <?= $man4m ? '<span class="lt-check">&#10003;</span>' : '-' ?></div>
                                    <div>Material: <?= $material4m ? '<span class="lt-check">&#10003;</span>' : '-' ?></div>
                                    <div>Machine: <?= $machine4m ? '<span class="lt-check">&#10003;</span>' : '-' ?></div>
                                </td>
                                <td class="lt-center"><?= $hasSimilar ? '<span class="lt-check">&#10003;</span>' : '-' ?></td>
                                <td class="lt-center"><?= $hasVerifikasi ? '<span class="lt-check">&#10003;</span>' : '-' ?></td>
                                <td class="lt-center"><a href="<?= htmlspecialchars($detailPage) ?>?id=<?= $row['id_fpm'] ?>" class="lt-btn-detail">Detail</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <style>
        .log-tpdp-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 10px 25px rgba(0,0,0,.05); font-family: 'Inter', sans-serif; }
        .log-tpdp-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .log-tpdp-title { font-size: 18px; font-weight: 700; }
        .log-tpdp-badges { display: flex; align-items: center; gap: 14px; }
        .lt-badge { border: 1px solid #ccc; border-radius: 6px; padding: 4px 10px; font-size: 11px; text-align: center; background: #d9edf7; }
        .lt-legend { font-size: 12px; display: flex; align-items: center; gap: 4px; }
        .lt-dot { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-left: 10px; margin-right: 3px; vertical-align: middle; }
        .lt-dot-closed { background: #92d050; }
        .lt-dot-open { background: #f4b183; }

        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 18px; }
        .filter-group { display: flex; flex-direction: column; min-width: 150px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
        .filter-group.grow { flex: 1; min-width: 220px; }

        /* Scroll vertikal di dalam kotak tabel supaya header (sticky) tetap terlihat saat scroll. */
        .log-tpdp-scroll { overflow: auto; max-height: calc(100vh - 160px); border: 1px solid #ccc; }
        .log-tpdp-table { border-collapse: collapse; width: 100%; min-width: 1900px; font-size: 10px; }
        .log-tpdp-table th, .log-tpdp-table td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; }
        .log-tpdp-table thead th { background: #4472c4; color: #fff; font-size: 10px; text-transform: uppercase; position: sticky; top: 0; z-index: 2; box-shadow: inset 0 -1px 0 #ccc; }
        .log-tpdp-table tbody tr:nth-child(even) { background: #f8f9fb; }
        .lt-center { text-align: center; }
        .lt-left { text-align: left; }
        .lt-muted { color: #9aa0a6; font-size: 9px; }
        .lt-sub-title { font-weight: 700; text-decoration: underline; font-size: 9.5px; margin-top: 4px; }
        .lt-list { margin: 2px 0 4px 14px; padding: 0; }
        .lt-list li { margin-bottom: 2px; }
        .lt-cm-line { margin-bottom: 5px; padding-bottom: 4px; border-bottom: 1px dashed #eee; }
        .lt-cm-line:last-child { border-bottom: none; }
        .lt-status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 9.5px; }
        .lt-status-inline { font-weight: 700; }
        .lt-status.lt-open, .lt-status-inline.lt-open { background: #f4b183; color: #5c2e00; }
        .lt-status.lt-closed, .lt-status-inline.lt-closed { background: #92d050; color: #1b4b00; }
        .lt-status.lt-revisi { background: #ffe699; color: #7a5c00; }
        .lt-notontime { color: #c00000; font-weight: 700; }
        .lt-check { color: #198754; font-weight: 700; }
        .lt-sketch { max-width: 80px; max-height: 70px; border-radius: 4px; border: 1px solid #ddd; cursor: zoom-in; }
        .lt-zoom { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.85); overflow: auto; }
        .lt-zoom.show { display: flex; }
        .lt-zoom img { margin: auto; max-width: 95vw; max-height: 92vh; cursor: zoom-in; background: #fff; }
        .lt-zoom.zoomed img { max-width: none; max-height: none; width: 200%; cursor: zoom-out; }
        .lt-zoom-close { position: fixed; top: 12px; right: 20px; color: #fff; font-size: 34px; line-height: 1; cursor: pointer; z-index: 2001; }
        .lt-zoom-hint { position: fixed; bottom: 12px; left: 50%; transform: translateX(-50%); color: #ddd; font-size: 12px; }
        .lt-doc-cell div { text-align: left; white-space: nowrap; }
        .lt-btn-detail { background: #2196f3; color: #fff; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 10px; }
    </style>

    <div class="lt-zoom" id="ltZoom" onclick="if (event.target === this) ltZoomClose()">
        <span class="lt-zoom-close" onclick="ltZoomClose()" title="Tutup">&times;</span>
        <img id="ltZoomImg" alt="Sketch" onclick="document.getElementById('ltZoom').classList.toggle('zoomed')">
        <div class="lt-zoom-hint">Klik foto untuk zoom in/out &middot; Esc atau klik area gelap untuk menutup</div>
    </div>

    <script>
        function ltZoomOpen(src) {
            const box = document.getElementById('ltZoom');
            document.getElementById('ltZoomImg').src = src;
            box.classList.remove('zoomed');
            box.classList.add('show');
        }

        function ltZoomClose() {
            document.getElementById('ltZoom').classList.remove('show', 'zoomed');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') ltZoomClose();
        });
    </script>
<?php
}
