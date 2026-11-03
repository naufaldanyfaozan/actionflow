<?php

require_once __DIR__ . '/fpm_claim_machining_helper.php';

// Tahap alur Claim Machining yang sedang ditunggu untuk FPM yang BELUM dijawab
// (cm_dept_head_dijawab_at kosong). Dipakai di tabel "belum dijawab" supaya jelas
// siapa yang sebenarnya menahan FPM - tidak semua FPM di tabel itu tertahan di Dept
// Head (bisa masih di Dept Head QA / Plant Head, atau sedang diminta revisi).
function tahapBelumJawabClaimMach(array $row): string
{
    $dept = trim($row['dept'] ?? '');
    $deptBelumDitentukan = ($dept === '' || strcasecmp($dept, 'All Dept') === 0);

    if (empty($row['cm_deptheadqa_approved_at'])) {
        return 'Menunggu Dept Head QA Approve';
    }
    if ($deptBelumDitentukan) {
        return $dept === ''
            ? 'Menunggu Plant Head Terima & Pilih Dept'
            : 'Menunggu Plant Head Pilih Ulang Dept (tidak bisa "All Dept")';
    }
    if (($row['status_reject'] ?? '') === 'REJECT') {
        return 'Revisi - Menunggu ' . $dept . ' Jawab Ulang';
    }
    if (empty($row['cm_dept_head_diterima_at'])) {
        return 'Menunggu Dept Head ' . $dept . ' Terima';
    }
    return 'Menunggu Dept Head ' . $dept . ' Jawab';
}

// Widget "Progress Perbaikan - FPM Claim Machining" dipakai bareng di semua
// dashboard role (dept_head, plant_head, directur, head_imp, dept_head_qa,
// staff, staff_imp, vice_directur) supaya query & tampilannya konsisten dan
// tidak perlu diduplikasi di 8 file index.php.
//
// $username: kalau diisi, widget di-scope ke dept user itu (sama seperti
// scoping "List FPM" - dept/internal_dept dia sendiri, atau dept manapun yang
// jadi PIC corrective/preventive/review). Kalau null, tampilkan semua dept
// (dipakai role yang company-wide seperti Plant Head/Directure/Dept Head QA).
function renderClaimMachiningDashboardWidget($koneksi, $username = null)
{
    $scopeWhere = '';

    if (!empty($username)) {
        $usernameEsc = mysqli_real_escape_string($koneksi, $username);
        $qUser = mysqli_query($koneksi, "SELECT dept FROM user WHERE username = '$usernameEsc'");
        $dataUser = mysqli_fetch_assoc($qUser);
        $userDept = mysqli_real_escape_string($koneksi, $dataUser['dept'] ?? '');

        if ($userDept !== '') {
            $scopeWhere = " AND (
                h.dept = '$userDept'
                OR h.internal_dept = '$userDept'
                OR EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND ca.pic LIKE '%$userDept%')
                OR EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND pa.pic LIKE '%$userDept%')
                OR EXISTS (SELECT 1 FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm AND rd.pic LIKE '%$userDept%')
            )";
        }
    }

    $where = "WHERE h.flow_type = 'CLAIMMACH'" . $scopeWhere;

    $qDept = mysqli_query($koneksi, "SELECT h.dept, COUNT(*) AS total,

        SUM(CASE WHEN
            (
                (SELECT COUNT(*) FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm) +
                (SELECT COUNT(*) FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm)
            ) > 0
            AND NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND (ca.status IS NULL OR ca.status != 'CLOSED'))
            AND NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND (pa.status IS NULL OR pa.status != 'CLOSED'))
            AND NOT EXISTS (SELECT 1 FROM fpm_review_dokumen rd WHERE rd.id_fpm = h.id_fpm AND (rd.status IS NULL OR rd.status != 'CLOSED'))
        THEN 1 ELSE 0 END) AS closed

        FROM fpm_header h
        $where
        GROUP BY h.dept ORDER BY total DESC");

    // Query di atas/bawah bisa gagal (mis. kolom cm_* dari migration terbaru belum
    // dijalankan di database ini) - jangan biarkan itu men-fatal-error-kan seluruh
    // halaman dashboard (mysqli_fetch_assoc menolak argumen bool di PHP 8.1+), cukup
    // sembunyikan widget ini dan tetap render sisa dashboard.
    if (!$qDept) {
        echo "<!-- Widget FPM Claim Machining dilewati: " . htmlspecialchars(mysqli_error($koneksi)) . " -->";
        return;
    }

    $rows = [];
    $grandTotal = 0;
    $grandClosed = 0;

    while ($row = mysqli_fetch_assoc($qDept)) {
        $rows[] = $row;
        $grandTotal += (int) $row['total'];
        $grandClosed += (int) $row['closed'];
    }

    // Overdue = belum dijawab Dept Head (cm_dept_head_dijawab_at masih kosong) dan
    // due date jawabnya sudah lewat - dulu dicek lewat fpm_pengiriman.dibuat='Dept Head'
    // yang tidak pernah cocok untuk CLAIMMACH (kolom itu dulu dipakai buat daftar PIC
    // dept, bukan literal 'Dept Head'), sekarang pakai kolom cm_* yang jelas maknanya.
    $qOverdue = mysqli_query($koneksi, "SELECT h.id_fpm, h.no_form, h.dept, h.nama_part,
            h.tgl_pembuatan, h.kejadian_berulang_ke, h.status_reject,
            h.cm_deptheadqa_approved_at, h.cm_dept_head_diterima_at
        FROM fpm_header h
        $where
        AND (h.status_evaluasi IS NULL OR h.status_evaluasi != 'CLOSED')
        AND h.cm_dept_head_dijawab_at IS NULL
        ORDER BY h.tgl_pembuatan ASC");

    $overdueRows = [];
    if ($qOverdue) {
        while ($row = mysqli_fetch_assoc($qOverdue)) {
            $dueDate = hitungDueDateJawabClaimMachining($row['tgl_pembuatan'] ?: date('Y-m-d'), (int) $row['kejadian_berulang_ke']);
            if ($dueDate < date('Y-m-d')) {
                $row['due_date'] = $dueDate;
                $overdueRows[] = $row;
            }
        }
    } else {
        echo "<!-- Query overdue FPM Claim Machining dilewati: " . htmlspecialchars(mysqli_error($koneksi)) . " -->";
    }
?>
    <div class="row section g-3">
        <div class="col-md-12">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    Progress Perbaikan &mdash; FPM Claim Machining
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered text-center align-middle">
                        <thead>
                            <tr>
                                <th>Dept Terkait</th>
                                <th>Total</th>
                                <th>Open</th>
                                <th>Closed</th>
                                <th>% Closed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="5">Belum ada data FPM Claim Machining</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $total = (int) $r['total'];
                                    $closed = (int) $r['closed'];
                                    $open = $total - $closed;
                                    $percent = $total > 0 ? round(($closed / $total) * 100, 2) : 0;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['dept'] ?: '-') ?></td>
                                        <td><?= $total ?></td>
                                        <td class="text-danger fw-semibold"><?= $open ?></td>
                                        <td class="text-success fw-semibold"><?= $closed ?></td>
                                        <td>
                                            <span class="fw-bold <?= $percent >= 80 ? 'text-success' : ($percent >= 50 ? 'text-warning' : 'text-danger') ?>">
                                                <?= $percent ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>TOTAL</td>
                                    <td><?= $grandTotal ?></td>
                                    <td class="text-danger"><?= $grandTotal - $grandClosed ?></td>
                                    <td class="text-success"><?= $grandClosed ?></td>
                                    <td><?= $grandTotal > 0 ? round(($grandClosed / $grandTotal) * 100, 2) : 0 ?>%</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($overdueRows)): ?>
    <div class="row section g-3">
        <div class="col-md-12">
            <div class="card h-100">
                <div class="card-header bg-danger text-white">
                    FPM Claim Machining Melewati Due Date Jawab
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-sm text-center align-middle">
                        <thead>
                            <tr>
                                <th>No Form</th>
                                <th>Dept Terkait</th>
                                <th>Part</th>
                                <th>Due Date Jawab</th>
                                <th>Tahap Saat Ini</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueRows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['no_form']) ?></td>
                                    <td><?= htmlspecialchars($row['dept']) ?></td>
                                    <td><?= htmlspecialchars($row['nama_part'] ?? '') ?></td>
                                    <td class="text-danger fw-bold"><?= htmlspecialchars($row['due_date']) ?></td>
                                    <td><?= htmlspecialchars(tahapBelumJawabClaimMach($row)) ?></td>
                                    <td>
                                        <a href="form_fpm.php?id=<?= $row['id_fpm'] ?>" class="btn btn-outline-primary btn-sm">Detail</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php
}

// Section "Sasaran Mutu - FPM Claim Machining" (combo chart Total vs Sudah Dibahas,
// 4 pie chart status, daftar problem berulang, tabel Tindakan Perbaikan) ala laporan
// "Pencapaian Sasaran Mutu Manajemen". SATU fungsi dipakai di semua role (dept_head_qa,
// staff, dept_head, plant_head, vice_directur, directur - modul FPM QA) supaya kalau ada
// perbaikan/bug, cukup diperbaiki di sini, tidak perlu di-copy-paste ulang ke banyak file.
// Butuh Chart.js + chartjs-plugin-datalabels + dashboard-widgets.js sudah dimuat di
// <head> halaman pemanggil (lihat dept_head_qa/index.php sebagai contoh referensi).
function renderSasaranMutuClaimMachining($koneksi, string $filterBulan, string $namaPeriode)
{
    // ================= COMBO CHART: TOTAL vs SUDAH DIBAHAS PER BULAN =================
    $queryJenis = mysqli_query($koneksi, "SELECT DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') AS bulan,
        h.jenis_fpm, COUNT(*) AS total,
        SUM(CASE WHEN h.cm_dept_head_dijawab_at IS NOT NULL THEN 1 ELSE 0 END) AS sudah_dibahas
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
        GROUP BY bulan, h.jenis_fpm ORDER BY bulan ASC");

    $jenisData = [];
    $dibahasPerBulan = [];
    while ($row = mysqli_fetch_assoc($queryJenis)) {
        $jenisData[$row['bulan']][$row['jenis_fpm']] = (int) $row['total'];
        $dibahasPerBulan[$row['bulan']] = (int) $row['sudah_dibahas'];
    }

    // ================= STATUS FPM (pie 1) =================
    $qStatusClaimMach = mysqli_query($koneksi, "SELECT
            h.status_reject, h.cm_dept_head_dijawab_at, h.cm_directur_approved_at
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
    ");
    $statusFpmClaimMach = ['Belum Bahas' => 0, 'Bahas Sebagian' => 0, 'Sudah Approve' => 0, 'Revisi Belum ACC' => 0];
    while ($row = mysqli_fetch_assoc($qStatusClaimMach)) {
        if (($row['status_reject'] ?? '') === 'REJECT') {
            $statusFpmClaimMach['Revisi Belum ACC']++;
        } elseif (!empty($row['cm_directur_approved_at'])) {
            $statusFpmClaimMach['Sudah Approve']++;
        } elseif (!empty($row['cm_dept_head_dijawab_at'])) {
            $statusFpmClaimMach['Bahas Sebagian']++;
        } else {
            $statusFpmClaimMach['Belum Bahas']++;
        }
    }

    // ================= STATUS COUNTERMEASURE (pie 2) =================
    // ca dan pa di-UNION ALL dulu SEBELUM di-JOIN ke fpm_header - LEFT JOIN langsung
    // bareng-bareng ke h menghasilkan cartesian product dan jumlahnya jadi salah.
    $qCmStatusClaimMach = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
        SUM(CASE WHEN gabungan.status IS NULL OR gabungan.status != 'CLOSED' THEN 1 ELSE 0 END) AS total_open,
        SUM(CASE WHEN gabungan.status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
        FROM (
            SELECT ca.id_fpm, ca.status FROM fpm_corrective_action ca
            JOIN fpm_header h ON h.id_fpm = ca.id_fpm WHERE h.flow_type = 'CLAIMMACH'
            UNION ALL
            SELECT pa.id_fpm, pa.status FROM fpm_preventive_action pa
            JOIN fpm_header h ON h.id_fpm = pa.id_fpm WHERE h.flow_type = 'CLAIMMACH'
        ) AS gabungan
    ")) ?: [];
    $cmOpenClaimMach = (int) ($qCmStatusClaimMach['total_open'] ?? 0);
    $cmClosedClaimMach = (int) ($qCmStatusClaimMach['total_closed'] ?? 0);

    // ================= PROBLEM BARU VS BERULANG (pie 3) =================
    $qProblemBaruBerulang = mysqli_query($koneksi, "SELECT
            SUM(CASE WHEN kejadian_berulang_ke IS NULL OR kejadian_berulang_ke = 0 THEN 1 ELSE 0 END) AS baru,
            SUM(CASE WHEN kejadian_berulang_ke >= 1 THEN 1 ELSE 0 END) AS berulang
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
    ");
    $rowBaruBerulang = mysqli_fetch_assoc($qProblemBaruBerulang) ?: [];
    $totalProblemBaru = (int) ($rowBaruBerulang['baru'] ?? 0);
    $totalProblemBerulang = (int) ($rowBaruBerulang['berulang'] ?? 0);

    // ================= KLASIFIKASI PROBLEM (pie 4) =================
    $qKlasifikasiClaimMach = mysqli_query($koneksi, "SELECT jenis_masalah, COUNT(*) AS total
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
          AND jenis_masalah IS NOT NULL AND jenis_masalah != ''
        GROUP BY jenis_masalah ORDER BY total DESC");
    $klasifikasiLabelClaimMach = [];
    $klasifikasiTotalClaimMach = [];
    while ($row = mysqli_fetch_assoc($qKlasifikasiClaimMach)) {
        $klasifikasiLabelClaimMach[] = $row['jenis_masalah'];
        $klasifikasiTotalClaimMach[] = (int) $row['total'];
    }

    // ================= DAFTAR FPM PROBLEM BERULANG =================
    $qDaftarBerulang = mysqli_query($koneksi, "SELECT no_form, nama_part, jenis_masalah, machine_no
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND DATE_FORMAT(h.tgl_pembuatan, '%Y-%m') = '$filterBulan'
          AND h.kejadian_berulang_ke >= 1
        ORDER BY h.tgl_pembuatan ASC");
    $daftarProblemBerulang = mysqli_fetch_all($qDaftarBerulang, MYSQLI_ASSOC) ?: [];

    // ================= TINDAKAN PERBAIKAN (detail corrective/preventive) =================
    $qTindakanClaimMach = mysqli_query($koneksi, "SELECT h.no_form, h.nama_part, gabungan.pic,
            gabungan.tindakan, gabungan.due_date, gabungan.actual_date, gabungan.status, gabungan.id_fpm
        FROM (
            SELECT ca.id_fpm, ca.pic, ca.tindakan, ca.due_date, ca.actual_date, ca.status
            FROM fpm_corrective_action ca
            JOIN fpm_header h2 ON h2.id_fpm = ca.id_fpm
            WHERE h2.flow_type = 'CLAIMMACH' AND DATE_FORMAT(h2.tgl_pembuatan, '%Y-%m') = '$filterBulan'
            UNION ALL
            SELECT pa.id_fpm, pa.pic, pa.tindakan, pa.due_date, pa.actual_date, pa.status
            FROM fpm_preventive_action pa
            JOIN fpm_header h2 ON h2.id_fpm = pa.id_fpm
            WHERE h2.flow_type = 'CLAIMMACH' AND DATE_FORMAT(h2.tgl_pembuatan, '%Y-%m') = '$filterBulan'
        ) AS gabungan
        JOIN fpm_header h ON h.id_fpm = gabungan.id_fpm
        ORDER BY gabungan.due_date ASC");
    $tindakanClaimMach = mysqli_fetch_all($qTindakanClaimMach, MYSQLI_ASSOC) ?: [];
?>
                <div class="dashboard-title" style="font-size:16px; margin-bottom:12px;">
                    <i class="bi bi-award-fill"></i> Sasaran Mutu &mdash; FPM Claim Machining (<?= htmlspecialchars($namaPeriode) ?>)
                </div>

                <div class="row section g-3">
                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white">
                                Total FPM Claim Machining per Bulan
                            </div>
                            <div class="card-body">
                                <div class="chart-large">
                                    <canvas id="jenisFpmChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($daftarProblemBerulang)): ?>
                <div class="row section g-3">
                    <div class="col-md-12">
                        <div class="alert alert-warning" style="margin-bottom:0;">
                            <strong><i class="bi bi-arrow-repeat"></i> Terdapat <?= count($daftarProblemBerulang) ?> FPM status problem berulang (<?= htmlspecialchars($namaPeriode) ?>):</strong>
                            <ul style="margin:8px 0 0 0;">
                                <?php foreach ($daftarProblemBerulang as $b): ?>
                                    <li>
                                        <?= htmlspecialchars($b['no_form']) ?> &mdash;
                                        <?= htmlspecialchars($b['nama_part'] ?: '-') ?>
                                        <?= !empty($b['machine_no']) ? ' (' . htmlspecialchars($b['machine_no']) . ')' : '' ?>
                                        <?= !empty($b['jenis_masalah']) ? ' - ' . htmlspecialchars($b['jenis_masalah']) : '' ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row section g-3">

                    <div class="col-md-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-header bg-secondary text-white text-center">Status FPM</div>
                            <div class="card-body">
                                <canvas id="statusFpmChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-header bg-secondary text-white text-center">Status Countermeasure FPM</div>
                            <div class="card-body">
                                <canvas id="cmStatusChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-header bg-secondary text-white text-center">Status Problem Berulang</div>
                            <div class="card-body">
                                <canvas id="problemBerulangChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-header bg-secondary text-white text-center">Klasifikasi Problem</div>
                            <div class="card-body">
                                <?php if (empty($klasifikasiLabelClaimMach)): ?>
                                    <div class="text-center text-muted py-4" style="font-size:13px;">Tidak ada data pada periode ini.</div>
                                <?php else: ?>
                                    <canvas id="klasifikasiChart"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row section g-3">
                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-dark text-white">
                                Tindakan Perbaikan &mdash; FPM Claim Machining (<?= htmlspecialchars($namaPeriode) ?>)
                            </div>
                            <div class="card-body table-responsive">
                                <table id="tindakanClaimMachTable" class="table table-bordered table-sm text-center align-middle">
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
                                        <?php if (empty($tindakanClaimMach)): ?>
                                            <tr><td colspan="7">Belum ada tindakan perbaikan pada periode ini</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($tindakanClaimMach as $i => $t): ?>
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
                                            <?php if (count($tindakanClaimMach) > 5): ?>
                                                <tr>
                                                    <td colspan="7">
                                                        <button type="button" class="table-expand-toggle" data-expand-toggle="tindakanClaimMachTable"
                                                            data-label-expand="Lihat <?= count($tindakanClaimMach) - 5 ?> lainnya &#9662;" data-label-collapse="Sembunyikan &#9652;">Lihat <?= count($tindakanClaimMach) - 5 ?> lainnya &#9662;</button>
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
        // Jaga-jaga kalau halaman pemanggil tidak memuat dashboard-widgets.js.
        if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
            Chart.register(ChartDataLabels);
        }

        const jenisRaw = <?= json_encode($jenisData) ?>;
        const jenisLabels = Object.keys(jenisRaw);
        const dibahasRaw = <?= json_encode($dibahasPerBulan) ?>;

        new Chart(document.getElementById('jenisFpmChart'), {
            type: 'bar',
            data: {
                labels: jenisLabels,
                datasets: [
                    {
                        label: 'Total FPM',
                        data: jenisLabels.map(b => jenisRaw[b]['Claim Machining'] ?? 0),
                        backgroundColor: '#f98909',
                        borderRadius: 6
                    },
                    {
                        label: 'Sudah Dibahas',
                        data: jenisLabels.map(b => dibahasRaw[b] ?? 0),
                        backgroundColor: '#0d6efd',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true },
                    x: { grid: { display: false } }
                },
                plugins: {
                    datalabels: {
                        display: true,
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        color: '#1e293b',
                        font: { weight: 'bold', size: 13 }
                    }
                }
            }
        });

        const pieOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                datalabels: {
                    display: true,
                    color: '#fff',
                    font: { weight: 'bold', size: 11 },
                    formatter: (value) => value > 0 ? value : ''
                }
            }
        };

        new Chart(document.getElementById('statusFpmChart'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($statusFpmClaimMach)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($statusFpmClaimMach)) ?>,
                    backgroundColor: ['#9ca3af', '#facc15', '#22c55e', '#ef4444']
                }]
            },
            options: pieOptions
        });

        new Chart(document.getElementById('cmStatusChart'), {
            type: 'pie',
            data: {
                labels: ['CM Open', 'CM Closed'],
                datasets: [{
                    data: [<?= (int) $cmOpenClaimMach ?>, <?= (int) $cmClosedClaimMach ?>],
                    backgroundColor: ['#f97316', '#3b82f6']
                }]
            },
            options: pieOptions
        });

        new Chart(document.getElementById('problemBerulangChart'), {
            type: 'pie',
            data: {
                labels: ['New Problem', 'Problem Berulang'],
                datasets: [{
                    data: [<?= (int) $totalProblemBaru ?>, <?= (int) $totalProblemBerulang ?>],
                    backgroundColor: ['#a78bfa', '#f97316']
                }]
            },
            options: pieOptions
        });

        <?php if (!empty($klasifikasiLabelClaimMach)): ?>
        new Chart(document.getElementById('klasifikasiChart'), {
            type: 'pie',
            data: {
                labels: <?= json_encode($klasifikasiLabelClaimMach) ?>,
                datasets: [{
                    data: <?= json_encode($klasifikasiTotalClaimMach) ?>,
                    backgroundColor: ['#0d6efd', '#f97316', '#22c55e', '#facc15', '#ef4444', '#a78bfa', '#14b8a6', '#ec4899', '#64748b', '#84cc16']
                }]
            },
            options: pieOptions
        });
        <?php endif; ?>
        });
    </script>
<?php
}

// Section "Perlu Perhatian - FPM Claim Machining": KPI + tabel countermeasure overdue
// dan FPM yang belum dijawab padahal sudah lewat due date jawab. Dipakai terutama di
// dashboard Staff QA karena merekalah yang follow up proyek perbaikan supaya termonitor -
// tapi fungsinya reusable, bisa dipanggil dari role lain juga kalau perlu nanti.
// $formFpmPage: nama file detail FPM di folder pemanggil (form_fpm.php ada di hampir
// semua role, tapi disediakan sebagai parameter untuk jaga-jaga kalau beda).
function renderPerhatianClaimMachining($koneksi, string $formFpmPage = 'form_fpm.php')
{
    // ================= COUNTERMEASURE OVERDUE =================
    $queryOverdueCm = mysqli_query($koneksi, "SELECT gabungan.pic, gabungan.tindakan AS deskripsi,
            gabungan.due_date, gabungan.status, h.no_form, h.id_fpm, h.machine_no, h.nama_part
        FROM (
            SELECT ca.pic, ca.tindakan, ca.due_date, ca.status, ca.actual_date, ca.id_fpm
            FROM fpm_corrective_action ca
            JOIN fpm_header h2 ON ca.id_fpm = h2.id_fpm WHERE h2.flow_type = 'CLAIMMACH'
            UNION ALL
            SELECT pa.pic, pa.tindakan, pa.due_date, pa.status, pa.actual_date, pa.id_fpm
            FROM fpm_preventive_action pa
            JOIN fpm_header h2 ON pa.id_fpm = h2.id_fpm WHERE h2.flow_type = 'CLAIMMACH'
            UNION ALL
            SELECT rd.pic, CONCAT('Update Dokumen: ', rd.dokumen), rd.due_date, rd.status, rd.actual_date, rd.id_fpm
            FROM fpm_review_dokumen rd
            JOIN fpm_header h2 ON rd.id_fpm = h2.id_fpm WHERE h2.flow_type = 'CLAIMMACH'
        ) AS gabungan
        JOIN fpm_header h ON h.id_fpm = gabungan.id_fpm
        WHERE (gabungan.status IS NULL OR gabungan.status != 'CLOSED')
          AND (gabungan.actual_date IS NULL OR gabungan.actual_date = '')
          AND gabungan.due_date IS NOT NULL
          AND gabungan.due_date < CURDATE()
        ORDER BY gabungan.due_date ASC");
    // Tanpa LIMIT - angka KPI dihitung dari count() hasil query ini, jadi kalau dibatasi
    // angkanya mentok di batas itu dan tidak sesuai data aktual (tabel sudah pakai pagination).
    $overdueCmData = $queryOverdueCm ? (mysqli_fetch_all($queryOverdueCm, MYSQLI_ASSOC) ?: []) : [];

    // ================= FPM BELUM DIJAWAB, LEWAT DUE DATE JAWAB =================
    $queryBelumJawab = mysqli_query($koneksi, "SELECT h.id_fpm, h.no_form, h.dept, h.nama_part,
            h.tgl_pembuatan, h.kejadian_berulang_ke, h.status_reject,
            h.cm_deptheadqa_approved_at, h.cm_dept_head_diterima_at
        FROM fpm_header h
        WHERE h.flow_type = 'CLAIMMACH'
          AND (h.status_evaluasi IS NULL OR h.status_evaluasi != 'CLOSED')
          AND h.cm_dept_head_dijawab_at IS NULL
        ORDER BY h.tgl_pembuatan ASC");
    $belumJawabRows = [];
    while ($queryBelumJawab && $row = mysqli_fetch_assoc($queryBelumJawab)) {
        $dueDate = hitungDueDateJawabClaimMachining($row['tgl_pembuatan'] ?: date('Y-m-d'), (int) $row['kejadian_berulang_ke']);
        if ($dueDate < date('Y-m-d')) {
            $row['due_date'] = $dueDate;
            $belumJawabRows[] = $row;
        }
    }
?>
                <h5 style="font-weight:600; margin: 8px 0 16px; border-left: 5px solid #dc2626; padding-left: 10px; color:#b02a37;">
                    <i class="bi bi-exclamation-octagon-fill"></i> Perlu Perhatian &mdash; FPM Claim Machining
                </h5>

                <div class="row g-3">

                    <div class="col-md-6">
                        <div class="kpi-card kpi-danger">
                            <div class="kpi-icon"><i class="bi bi-alarm-fill"></i></div>
                            <div>
                                <div class="kpi-title">Countermeasure Claim Machining Overdue</div>
                                <div class="kpi-value"><?= count($overdueCmData) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="kpi-card kpi-danger">
                            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="kpi-title">FPM Belum Dijawab, Lewat Due Date</div>
                                <div class="kpi-value"><?= count($belumJawabRows) ?></div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row section g-3">
                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-danger text-white">
                                Countermeasure Claim Machining Over Due Date
                            </div>
                            <div class="card-body table-responsive">
                                <?php if (!empty($overdueCmData)): ?>
                                    <div class="list-pagination-toolbar">
                                        Tampilkan
                                        <select class="list-pagination-select" data-paginate-size-for="cmOverdueClaimMachTable">
                                            <option value="10">10</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                            <option value="200">200</option>
                                        </select>
                                        baris
                                    </div>
                                <?php endif; ?>
                                <table id="cmOverdueClaimMachTable" class="table table-bordered table-sm text-center align-middle" <?= !empty($overdueCmData) ? 'data-paginate="10"' : '' ?>>
                                    <thead>
                                        <tr>
                                            <th>No Form</th>
                                            <th>PIC</th>
                                            <th>Tindakan</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th>Mesin</th>
                                            <th>Part</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($overdueCmData)): ?>
                                            <tr><td colspan="8">Tidak ada countermeasure over due date</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($overdueCmData as $i => $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['no_form']) ?></td>
                                                    <td><?= htmlspecialchars($row['pic']) ?></td>
                                                    <td><?= htmlspecialchars($row['deskripsi']) ?></td>
                                                    <td class="text-danger fw-bold"><?= htmlspecialchars($row['due_date']) ?></td>
                                                    <td><span class="badge bg-danger"><?= htmlspecialchars($row['status'] ?: 'OPEN') ?></span></td>
                                                    <td><?= htmlspecialchars($row['machine_no'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($row['nama_part'] ?? '') ?></td>
                                                    <td>
                                                        <a href="<?= htmlspecialchars($formFpmPage) ?>?id=<?= $row['id_fpm'] ?>" class="btn btn-outline-primary btn-sm">Detail</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <?php if (!empty($overdueCmData)): ?>
                                    <div class="list-pagination-nav">
                                        <button type="button" class="list-pagination-btn" data-paginate-prev="cmOverdueClaimMachTable">&laquo; Sebelumnya</button>
                                        <span class="list-pagination-info" data-paginate-info="cmOverdueClaimMachTable"></span>
                                        <button type="button" class="list-pagination-btn" data-paginate-next="cmOverdueClaimMachTable">Berikutnya &raquo;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($belumJawabRows)): ?>
                <div class="row section g-3">
                    <div class="col-md-12">
                        <div class="card h-100">
                            <div class="card-header bg-danger text-white">
                                FPM Claim Machining Belum Dijawab, Melewati Due Date
                            </div>
                            <div class="card-body table-responsive">
                                <table class="table table-bordered table-sm text-center align-middle">
                                    <thead>
                                        <tr>
                                            <th>No Form</th>
                                            <th>Dept Terkait</th>
                                            <th>Part</th>
                                            <th>Due Date Jawab</th>
                                            <th>Tahap Saat Ini</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($belumJawabRows as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['no_form']) ?></td>
                                                <td><?= htmlspecialchars($row['dept'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($row['nama_part'] ?? '') ?></td>
                                                <td class="text-danger fw-bold"><?= htmlspecialchars($row['due_date']) ?></td>
                                                <td><?= htmlspecialchars(tahapBelumJawabClaimMach($row)) ?></td>
                                                <td>
                                                    <a href="<?= htmlspecialchars($formFpmPage) ?>?id=<?= $row['id_fpm'] ?>" class="btn btn-outline-primary btn-sm">Detail</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
<?php
}
