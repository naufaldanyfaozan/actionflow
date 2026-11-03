<?php

// Section tambahan "Sasaran Mutu" untuk FPM Down Time, FPM Internal, dan Asakai -
// dipakai di tab "Ringkasan Sasaran Mutu" yang sudah ada di semua dashboard role
// (staff_imp, head_imp, staff, dept_head, plant_head, directur, vice_directur).
//
// PENTING: dashboard-dashboard itu SUDAH punya KPI (Total Closed/Open, Problem
// Berulang), tabel overdue, tabel "Top Problem Berulang", dan chart bar "Top Masalah
// Terbanyak" (klasifikasi per jenis_masalah) - jadi fungsi di file ini SENGAJA hanya
// menambahkan 2 hal yang belum ada di mana pun supaya tidak dobel informasi:
//   1. Chart tren bulanan "Total vs Sudah Dijawab/Dibahas" (belum pernah di-chart,
//      cuma ada sebagai angka KPI sesaat, bukan tren per bulan).
//   2. Tabel detail "Tindakan Perbaikan" LENGKAP (semua status open+closed - tabel
//      yang sudah ada di tiap dashboard cuma overdue-only).
// Pie chart Status FPM/Countermeasure/Problem Berulang/Klasifikasi Problem SENGAJA
// tidak dibuat di sini karena datanya sudah tersaji (dalam bentuk lain) di dashboard
// yang sudah ada - beda dengan FPM Claim Machining yang tadinya benar-benar kosong.

/**
 * Chart tren bulanan Total FPM vs Sudah Dijawab, untuk kategori FPM tertentu
 * (Down Time atau Internal).
 *
 * @param array  $jenisFpmList  nilai jenis_fpm yang termasuk kategori ini,
 *                              mis. ['Down Time'] atau ['NG Casting','NG Machining'].
 * @param bool   $excludeFlowQa kalau true, exclude flow_type='QA' (dipakai utk FPM
 *                              Internal karena jenis_fpm='NG Machining' juga dipakai
 *                              sub-alur FPM Internal QA yang sedang dinonaktifkan).
 * @param string $canvasSuffix id canvas dibuat unik supaya aman dipanggil >1 kali
 *                              di halaman yang sama (mis. Down Time + Internal).
 */
function renderTrenSasaranMutuFpm($koneksi, string $namaPeriode, array $jenisFpmList, string $judul, bool $excludeFlowQa, string $canvasSuffix)
{
    $jenisEsc = array_map(fn($j) => "'" . mysqli_real_escape_string($koneksi, $j) . "'", $jenisFpmList);
    $inClause = implode(',', $jenisEsc);
    $flowQaClause = $excludeFlowQa ? "AND (h.flow_type IS NULL OR h.flow_type != 'QA')" : '';

    $query = mysqli_query($koneksi, "SELECT DATE_FORMAT(h.tgl_terbit, '%Y-%m') AS bulan, COUNT(*) AS total,
        SUM(CASE WHEN (
            (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
            (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
        ) > 0 THEN 1 ELSE 0 END) AS sudah_dijawab
        FROM fpm_header h
        WHERE h.jenis_fpm IN ($inClause) $flowQaClause
          AND h.tgl_terbit IS NOT NULL AND h.tgl_terbit != '0000-00-00'
        GROUP BY bulan ORDER BY bulan ASC");

    $totalPerBulan = [];
    $dijawabPerBulan = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $totalPerBulan[$row['bulan']] = (int) $row['total'];
        $dijawabPerBulan[$row['bulan']] = (int) $row['sudah_dijawab'];
    }
    $labels = array_keys($totalPerBulan);
    $canvasId = 'trenSasaranMutuChart_' . $canvasSuffix;
?>
    <div class="row section g-3">
        <div class="col-md-12">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    Tren <?= htmlspecialchars($judul) ?> per Bulan
                </div>
                <div class="card-body">
                    <?php if (empty($labels)): ?>
                        <div class="text-center text-muted py-4">Belum ada data <?= htmlspecialchars($judul) ?>.</div>
                    <?php else: ?>
                        <div class="chart-large">
                            <canvas id="<?= $canvasId ?>"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if (!empty($labels)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Jaga-jaga kalau halaman pemanggil tidak memuat dashboard-widgets.js
            // (yang biasanya mendaftarkan plugin ini) - tanpa baris ini, opsi
            // "datalabels" di bawah tidak akan pernah menampilkan angka.
            if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
                Chart.register(ChartDataLabels);
            }

            new Chart(document.getElementById('<?= $canvasId ?>'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labels) ?>,
                    datasets: [
                        { label: 'Total FPM', data: <?= json_encode(array_values($totalPerBulan)) ?>, backgroundColor: '#f98909', borderRadius: 6 },
                        { label: 'Sudah Dijawab', data: <?= json_encode(array_values($dijawabPerBulan)) ?>, backgroundColor: '#0d6efd', borderRadius: 6 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
                    plugins: {
                        datalabels: { display: true, anchor: 'end', align: 'top', offset: 4, color: '#1e293b', font: { weight: 'bold', size: 13 } }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
<?php
}

/**
 * Tabel detail "Tindakan Perbaikan" lengkap (semua status) untuk kategori FPM
 * tertentu (Down Time atau Internal), dibatasi 1 bulan sesuai filter dashboard.
 */
function renderTindakanPerbaikanFpm($koneksi, string $filterBulan, string $namaPeriode, array $jenisFpmList, string $judul, bool $excludeFlowQa, string $tableIdSuffix)
{
    $jenisEsc = array_map(fn($j) => "'" . mysqli_real_escape_string($koneksi, $j) . "'", $jenisFpmList);
    $inClause = implode(',', $jenisEsc);
    $flowQaClause = $excludeFlowQa ? "AND (h2.flow_type IS NULL OR h2.flow_type != 'QA')" : '';
    $filterBulanEsc = mysqli_real_escape_string($koneksi, $filterBulan);

    $query = mysqli_query($koneksi, "SELECT h.no_form, h.nama_part, gabungan.pic,
            gabungan.tindakan, gabungan.due_date, gabungan.actual_date, gabungan.status
        FROM (
            SELECT ca.id_fpm, ca.pic, ca.tindakan, ca.due_date, ca.actual_date, ca.status
            FROM fpm_corrective_action ca
            JOIN fpm_header h2 ON h2.id_fpm = ca.id_fpm
            WHERE h2.jenis_fpm IN ($inClause) $flowQaClause
              AND DATE_FORMAT(h2.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
            UNION ALL
            SELECT pa.id_fpm, pa.pic, pa.tindakan, pa.due_date, pa.actual_date, pa.status
            FROM fpm_preventive_action pa
            JOIN fpm_header h2 ON h2.id_fpm = pa.id_fpm
            WHERE h2.jenis_fpm IN ($inClause) $flowQaClause
              AND DATE_FORMAT(h2.tgl_terbit, '%Y-%m') = '$filterBulanEsc'
        ) AS gabungan
        JOIN fpm_header h ON h.id_fpm = gabungan.id_fpm
        ORDER BY gabungan.due_date ASC");
    $rows = mysqli_fetch_all($query, MYSQLI_ASSOC) ?: [];
    $tableId = 'tindakanFpmTable_' . $tableIdSuffix;
?>
    <div class="row section g-3">
        <div class="col-md-12">
            <div class="card h-100">
                <div class="card-header bg-dark text-white">
                    Tindakan Perbaikan &mdash; <?= htmlspecialchars($judul) ?> (<?= htmlspecialchars($namaPeriode) ?>)
                </div>
                <div class="card-body table-responsive">
                    <table id="<?= $tableId ?>" class="table table-bordered table-sm text-center align-middle">
                        <thead>
                            <tr>
                                <th>No Form</th>
                                <th>Part</th>
                                <th>PIC</th>
                                <th>Tindakan Perbaikan</th>
                                <th>Due Date</th>
                                <th>Actual</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7">Belum ada tindakan perbaikan pada periode ini</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $i => $t): ?>
                                    <tr class="<?= $i >= 5 ? 'table-extra-row' : '' ?>">
                                        <td><?= htmlspecialchars($t['no_form']) ?></td>
                                        <td><?= htmlspecialchars($t['nama_part'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($t['pic'] ?? '') ?></td>
                                        <td class="text-start"><?= htmlspecialchars($t['tindakan'] ?? '') ?></td>
                                        <td><?= !empty($t['due_date']) ? htmlspecialchars($t['due_date']) : '-' ?></td>
                                        <td><?= !empty($t['actual_date']) ? htmlspecialchars($t['actual_date']) : '-' ?></td>
                                        <td>
                                            <?= strtoupper($t['status'] ?? '') === 'CLOSED' ? '<span class="badge bg-success">CLOSED</span>' : '<span class="badge bg-warning">OPEN</span>' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($rows) > 5): ?>
                                    <tr>
                                        <td colspan="7">
                                            <button type="button" class="table-expand-toggle" data-expand-toggle="<?= $tableId ?>"
                                                data-label-expand="Lihat <?= count($rows) - 5 ?> lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($rows) - 5 ?> lainnya &#9662;</button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php
}

/**
 * Chart tren bulanan Total Asakai vs Sudah Dibahas ("sudah dibahas" = pola yang
 * sama dengan bahan_asakai.php: analisa1 sudah diisi).
 */
function renderTrenSasaranMutuAsakai($koneksi, string $namaPeriode)
{
    $query = mysqli_query($koneksi, "SELECT LEFT(ah.tanggal, 7) AS bulan, COUNT(*) AS total,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM asakai_analisa aa
            WHERE aa.id_asakai = ah.id_asakai AND aa.analisa1 IS NOT NULL AND TRIM(aa.analisa1) != ''
        ) THEN 1 ELSE 0 END) AS sudah_dibahas
        FROM asakai_header ah
        GROUP BY bulan ORDER BY bulan ASC");

    $totalPerBulan = [];
    $dibahasPerBulan = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $totalPerBulan[$row['bulan']] = (int) $row['total'];
        $dibahasPerBulan[$row['bulan']] = (int) $row['sudah_dibahas'];
    }
    $labels = array_keys($totalPerBulan);
?>
    <div class="row section g-3">
        <div class="col-md-12">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    Tren Asakai per Bulan
                </div>
                <div class="card-body">
                    <?php if (empty($labels)): ?>
                        <div class="text-center text-muted py-4">Belum ada data Asakai.</div>
                    <?php else: ?>
                        <div class="chart-large">
                            <canvas id="trenAsakaiChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if (!empty($labels)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Jaga-jaga kalau halaman pemanggil tidak memuat dashboard-widgets.js.
            if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
                Chart.register(ChartDataLabels);
            }

            new Chart(document.getElementById('trenAsakaiChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labels) ?>,
                    datasets: [
                        { label: 'Total Asakai', data: <?= json_encode(array_values($totalPerBulan)) ?>, backgroundColor: '#f98909', borderRadius: 6 },
                        { label: 'Sudah Dibahas', data: <?= json_encode(array_values($dibahasPerBulan)) ?>, backgroundColor: '#0d6efd', borderRadius: 6 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
                    plugins: {
                        datalabels: { display: true, anchor: 'end', align: 'top', offset: 4, color: '#1e293b', font: { weight: 'bold', size: 13 } }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
<?php
}

/**
 * Tabel detail "Tindakan Perbaikan Asakai" lengkap (semua status), 1 bulan sesuai
 * filter dashboard. Union asakai_countermeasure_downtime + asakai_countermeasure_ng.
 */
function renderTindakanPerbaikanAsakai($koneksi, string $filterBulan, string $namaPeriode)
{
    $filterBulanEsc = mysqli_real_escape_string($koneksi, $filterBulan);

    $query = mysqli_query($koneksi, "SELECT ah.namapart, gabungan.pic, gabungan.deskripsi,
            gabungan.due_date, gabungan.actual_date, gabungan.status
        FROM (
            SELECT cd.id_asakai, cd.pic, cd.deskripsi, cd.due_date, cd.actual_date, cd.status
            FROM asakai_countermeasure_downtime cd
            JOIN asakai_header ah2 ON ah2.id_asakai = cd.id_asakai
            WHERE LEFT(ah2.tanggal, 7) = '$filterBulanEsc'
            UNION ALL
            SELECT cn.id_asakai, cn.pic, cn.deskripsi, cn.due_date, cn.actual_date, cn.status
            FROM asakai_countermeasure_ng cn
            JOIN asakai_header ah2 ON ah2.id_asakai = cn.id_asakai
            WHERE LEFT(ah2.tanggal, 7) = '$filterBulanEsc'
        ) AS gabungan
        JOIN asakai_header ah ON ah.id_asakai = gabungan.id_asakai
        ORDER BY gabungan.due_date ASC");
    $rows = mysqli_fetch_all($query, MYSQLI_ASSOC) ?: [];
?>
    <div class="row section g-3">
        <div class="col-md-12">
            <div class="card h-100">
                <div class="card-header bg-dark text-white">
                    Tindakan Perbaikan &mdash; Asakai (<?= htmlspecialchars($namaPeriode) ?>)
                </div>
                <div class="card-body table-responsive">
                    <table id="tindakanAsakaiTable" class="table table-bordered table-sm text-center align-middle">
                        <thead>
                            <tr>
                                <th>Part</th>
                                <th>PIC</th>
                                <th>Tindakan Perbaikan</th>
                                <th>Due Date</th>
                                <th>Actual</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="6">Belum ada tindakan perbaikan Asakai pada periode ini</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $i => $t): ?>
                                    <tr class="<?= $i >= 5 ? 'table-extra-row' : '' ?>">
                                        <td><?= htmlspecialchars($t['namapart'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($t['pic'] ?? '') ?></td>
                                        <td class="text-start"><?= htmlspecialchars($t['deskripsi'] ?? '') ?></td>
                                        <td><?= !empty($t['due_date']) ? htmlspecialchars($t['due_date']) : '-' ?></td>
                                        <td><?= !empty($t['actual_date']) ? htmlspecialchars($t['actual_date']) : '-' ?></td>
                                        <td>
                                            <?= strtoupper($t['status'] ?? '') === 'CLOSED' ? '<span class="badge bg-success">CLOSED</span>' : '<span class="badge bg-warning">OPEN</span>' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($rows) > 5): ?>
                                    <tr>
                                        <td colspan="6">
                                            <button type="button" class="table-expand-toggle" data-expand-toggle="tindakanAsakaiTable"
                                                data-label-expand="Lihat <?= count($rows) - 5 ?> lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($rows) - 5 ?> lainnya &#9662;</button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php
}
