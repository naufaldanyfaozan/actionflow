<?php
/**
 * Daftar Verifikasi Countermeasure Staff QA (FPM Claim Machining): reschedule belum dibaca,
 * revisi diperbaiki PIC, countermeasure FPM - ditampilkan di dalam halaman Tugas Saya Staff QA
 * (lihat tugas_saya_view.php), supaya verifikasi cukup di satu menu. Isi & aksinya sama dengan
 * halaman Verifikasi Countermeasure sebelumnya; proses "Tandai sudah dicek/dibaca" tetap di
 * staff/verifikasi_countermeasure.php (yang lalu kembali ke Tugas Saya).
 *
 * CSS dibatasi di .verif-qa supaya tidak mengubah tampilan tabel Tugas Saya.
 *
 * @var mysqli $koneksi
 */

require_once __DIR__ . '/verifikasi_countermeasure_helper.php';
require_once __DIR__ . '/countermeasure_revisi_helper.php';
require_once __DIR__ . '/fpm_reschedule_helper.php';

// Reschedule due date FPM-Cast (Claim Machining) dari Staff / Dept Head / Plant Head yang belum dibaca.
$rescheduleRows = ambilRescheduleBelumDibaca($koneksi, true);
$revisiDiperbaikiRows = ambilRevisiPerluDicekQa($koneksi);

// Staff QA memverifikasi FPM lintas plant, jadi tidak difilter per-plant seperti Staff IMP.
$fpmRows = fetchPendingFpmVerifikasi($koneksi, '', true);

if (!function_exists('formatTglVerifikasiQA')) {
    function formatTglVerifikasiQA($tgl)
    {
        if (empty($tgl) || $tgl === '0000-00-00') return '';
        return date('d-m-Y', strtotime($tgl));
    }
}

// Form "Tandai sudah dibaca" dari helper reschedule tidak punya action - arahkan ke halaman prosesnya.
$seksiReschedule = str_replace(
    '<form method="POST"',
    '<form method="POST" action="verifikasi_countermeasure.php"',
    renderSeksiRescheduleBelumDibaca($rescheduleRows, 'update_countermeasure_qa.php?id=')
);
?>
<style>
    .verif-qa .page-subtitle {
        color: #6b7280;
        font-size: 13px;
        margin-bottom: 14px;
    }

    .verif-qa .section-heading {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 20px 0 12px;
    }

    .verif-qa .section-heading h3 {
        font-size: 15px;
        font-weight: 700;
        margin: 0;
        text-transform: uppercase;
    }

    .verif-qa .section-count {
        background: #fee2e2;
        color: #b91c1c;
        font-weight: 700;
        font-size: 12px;
        padding: 3px 10px;
        border-radius: 999px;
    }

    .verif-qa .section-count.zero {
        background: #dcfce7;
        color: #166534;
    }

    .verif-qa .table-wrapper {
        max-height: 650px;
        overflow-y: auto;
        overflow-x: auto;
        border: 1px solid #cbd5e1;
        margin-bottom: 10px;
    }

    .verif-qa table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        font-size: 13px;
        border: 1px solid #cbd5e1;
    }

    .verif-qa th,
    .verif-qa td {
        padding: 10px;
        border: 1px solid #e2e8f0;
    }

    .verif-qa thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background-color: #2c6ed7;
        color: white;
        font-weight: 600;
        text-align: center;
    }

    .verif-qa tbody tr:nth-child(even) {
        background-color: #f8fafc;
    }

    .verif-qa tbody tr:nth-child(odd) {
        background-color: #ffffff;
    }

    .verif-qa tr:hover {
        background-color: #e2e8f0;
        transition: 0.2s;
    }

    .verif-qa .status-badge {
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-transform: uppercase;
    }

    .verif-qa .status-open {
        background-color: #fef3c7;
        color: #967e08;
    }

    .verif-qa .btn {
        padding: 6px 12px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        font-size: 12px;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-block;
    }

    .verif-qa .btn-verify {
        background-color: #eff6ff;
        color: #2563eb;
    }

    .verif-qa .btn-verify:hover {
        background-color: #2563eb;
        color: white;
    }

    .verif-qa .empty-hint {
        text-align: center;
        padding: 24px;
        color: #6b7280;
    }
</style>

<div class="card mb-3 verif-qa" id="verifikasi">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-patch-check-fill"></i>
        <strong>Verifikasi countermeasure Claim Machining</strong>
        <span class="badge bg-secondary"><?= count($rescheduleRows) + count($revisiDiperbaikiRows) + count($fpmRows) ?></span>
    </div>
    <div class="card-body">

        <p class="page-subtitle">
            Daftar Corrective/Preventive Action &amp; Update Dokumen FPM Claim Machining (FPM-Cast)
            yang sudah diupload buktinya oleh dept terkait, namun statusnya belum
            diubah menjadi CLOSED. Penutupan status hanya bisa dilakukan dari sini oleh Staff QA.
        </p>

        <?= $seksiReschedule ?>

        <div class="section-heading">
            <h3>Revisi Sudah Diperbaiki PIC</h3>
            <span class="section-count <?= count($revisiDiperbaikiRows) === 0 ? 'zero' : '' ?>" style="<?= count($revisiDiperbaikiRows) > 0 ? 'background:#dbeafe; color:#1d4ed8;' : '' ?>">
                <?= count($revisiDiperbaikiRows) ?> perlu dicek ulang
            </span>
        </div>
        <p style="color:#6b7280; font-size:12.5px; margin:-6px 0 10px;">
            Catatan revisi Staff QA yang sudah ditindaklanjuti Dept Head / Staff PIC. Cek hasilnya, lalu CLOSED-kan countermeasure di halaman verifikasi
            atau klik <b>Tandai sudah dicek</b> (mis. untuk catatan umum).
        </p>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>No Form</th>
                        <th>Countermeasure</th>
                        <th>Catatan QA</th>
                        <th>Tindak Lanjut PIC</th>
                        <th>File Sekarang</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($revisiDiperbaikiRows): ?>
                        <?php foreach ($revisiDiperbaikiRows as $r): ?>
                            <?php
                            $cfgRev = cmRevisiConfig('FPM');
                            $labelRev = !empty($r['ref_table']) ? ($cfgRev['refs'][$r['ref_table']]['label'] ?? '') : 'Catatan umum';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(($r['no_form'] ?? '') ?: ($r['no_reg_fpm'] ?? '')) ?><br><small><?= htmlspecialchars($r['nama_part'] ?? '') ?></small></td>
                                <td><b><?= htmlspecialchars($labelRev) ?></b><br><?= nl2br(htmlspecialchars($r['item_deskripsi'] ?? '')) ?><br><small>PIC: <?= htmlspecialchars($r['dept_tujuan']) ?></small></td>
                                <td><?= nl2br(htmlspecialchars($r['catatan'])) ?></td>
                                <td>
                                    <?= !empty($r['tanggapan_pic']) ? nl2br(htmlspecialchars($r['tanggapan_pic'])) : '<i>Upload ulang file</i>' ?>
                                    <?php if (!empty($r['selesai_at'])): ?>
                                        <br><small style="color:#6b7280;"><?= htmlspecialchars($r['selesai_oleh'] ?? '') ?> &middot; <?= date('d-m-Y H:i', strtotime($r['selesai_at'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($r['ref_table']) ? cmRevisiRenderFiles('FPM', $r['files'], 48) : '-' ?></td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <a href="update_countermeasure_qa.php?id=<?= (int) $r['id_fpm'] ?>" class="btn btn-verify">Verifikasi</a>
                                    <form method="POST" action="verifikasi_countermeasure.php" style="margin-top:6px;" onsubmit="return confirm('Tandai revisi ini sudah dicek?');">
                                        <input type="hidden" name="id_revisi" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" name="tandai_revisi_dicek" value="1" class="btn" style="background:#dcfce7; color:#166534;">Tandai sudah dicek</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-hint">Tidak ada revisi yang menunggu dicek.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section-heading">
            <h3>FPM</h3>
            <span class="section-count <?= count($fpmRows) === 0 ? 'zero' : '' ?>">
                <?= count($fpmRows) ?> perlu diverifikasi
            </span>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>No Form</th>
                        <th>Plant</th>
                        <th>Jenis FPM</th>
                        <th>Nama Part</th>
                        <th>Mesin</th>
                        <th>Jenis Aksi</th>
                        <th>Tindakan / Dokumen</th>
                        <th>PIC</th>
                        <th>Due Date</th>
                        <th>Actual</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($fpmRows) > 0): ?>
                        <?php foreach ($fpmRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['no_form'] ?? $r['no_reg_fpm'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['plant']) ?></td>
                                <td><?= htmlspecialchars($r['jenis_fpm']) ?></td>
                                <td><?= htmlspecialchars($r['nama_part']) ?></td>
                                <td><?= htmlspecialchars($r['machine_no']) ?></td>
                                <td><?= htmlspecialchars($r['jenis_aksi']) ?></td>
                                <td><?= nl2br(htmlspecialchars($r['deskripsi'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($r['pic'] ?? '') ?></td>
                                <td><?= formatTglVerifikasiQA($r['due_date']) ?></td>
                                <td><?= formatTglVerifikasiQA($r['actual_date']) ?></td>
                                <td>
                                    <span class="status-badge status-open"><?= htmlspecialchars($r['status'] ?? 'OPEN') ?></span>
                                </td>
                                <td>
                                    <a href="update_countermeasure_qa.php?id=<?= (int) $r['id_fpm'] ?>" class="btn btn-verify">Verifikasi</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" class="empty-hint">Tidak ada countermeasure FPM yang perlu diverifikasi.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>
