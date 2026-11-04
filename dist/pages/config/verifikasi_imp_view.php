<?php
/**
 * Daftar Verifikasi Countermeasure Staff IMP (revisi diperbaiki PIC, ASAKAI, FPM) -
 * ditampilkan di dalam halaman Tugas Saya Staff IMP (lihat tugas_saya_view.php), supaya
 * verifikasi cukup di satu menu. Isi & aksinya sama dengan halaman Verifikasi
 * Countermeasure sebelumnya; proses "Tandai sudah dicek" tetap di
 * staff_imp/verifikasi_countermeasure.php (yang lalu kembali ke Tugas Saya).
 *
 * CSS dibatasi di .verif-imp supaya tidak mengubah tampilan tabel Tugas Saya.
 *
 * @var mysqli $koneksi
 */

require_once __DIR__ . '/verifikasi_countermeasure_helper.php';
require_once __DIR__ . '/countermeasure_revisi_helper.php';

$plantFilter = resolveVerifikasiPlant((string) ($_SESSION['username'] ?? ''), $_GET['plant'] ?? null);

// Reschedule due date dari Staff / Dept Head / Plant Head tidak masuk daftar ini - cukup
// tercatat di History Reschedule halaman countermeasure, tidak perlu diverifikasi Staff IMP.
$revisiDiperbaikiRows = ambilRevisiPerluDicekImp($koneksi, $plantFilter);
$asakaiRows = fetchPendingAsakaiVerifikasi($koneksi, $plantFilter);
$fpmRows = fetchPendingFpmVerifikasi($koneksi, $plantFilter);

if (!function_exists('formatTglVerifikasi')) {
    function formatTglVerifikasi($tgl)
    {
        if (empty($tgl) || $tgl === '0000-00-00') return '';
        return date('d-m-Y', strtotime($tgl));
    }
}
?>
<style>
    .verif-imp .page-subtitle {
        color: #6b7280;
        font-size: 13px;
        margin-bottom: 14px;
    }

    .verif-imp .filter-section {
        margin-bottom: 10px;
        display: flex;
        align-items: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .verif-imp .section-heading {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 24px 0 12px;
    }

    .verif-imp .section-heading h3 {
        font-size: 15px;
        font-weight: 700;
        margin: 0;
        text-transform: uppercase;
    }

    .verif-imp .section-count {
        background: #fee2e2;
        color: #b91c1c;
        font-weight: 700;
        font-size: 12px;
        padding: 3px 10px;
        border-radius: 999px;
    }

    .verif-imp .section-count.zero {
        background: #dcfce7;
        color: #166534;
    }

    .verif-imp .table-wrapper {
        max-height: 550px;
        overflow-y: auto;
        overflow-x: auto;
        border: 1px solid #cbd5e1;
        margin-bottom: 10px;
    }

    .verif-imp table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        font-size: 13px;
        border: 1px solid #cbd5e1;
    }

    .verif-imp th,
    .verif-imp td {
        padding: 10px;
        border: 1px solid #e2e8f0;
    }

    .verif-imp thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background-color: #2c6ed7;
        color: white;
        font-weight: 600;
        text-align: center;
    }

    .verif-imp tbody tr:nth-child(even) {
        background-color: #f8fafc;
    }

    .verif-imp tbody tr:nth-child(odd) {
        background-color: #ffffff;
    }

    .verif-imp tr:hover {
        background-color: #e2e8f0;
        transition: 0.2s;
    }

    .verif-imp .status-badge {
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-transform: uppercase;
    }

    .verif-imp .status-open {
        background-color: #fef3c7;
        color: #967e08;
    }

    .verif-imp .status-on-progress {
        background-color: #dbeafe;
        color: #1d4ed8;
    }

    .verif-imp .btn-v {
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

    .verif-imp .btn-verify {
        background-color: #eff6ff;
        color: #2563eb;
    }

    .verif-imp .btn-verify:hover {
        background-color: #2563eb;
        color: white;
    }

    .verif-imp .empty-hint {
        text-align: center;
        padding: 24px;
        color: #6b7280;
    }
</style>

<div class="card mb-3 verif-imp" id="verifikasi">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-patch-check-fill"></i>
        <strong>Verifikasi countermeasure</strong>
        <span class="badge bg-secondary"><?= count($revisiDiperbaikiRows) + count($asakaiRows) + count($fpmRows) ?></span>
    </div>
    <div class="card-body">

        <p class="page-subtitle">
            Daftar perbaikan (ASAKAI &amp; FPM) yang sudah diupload buktinya oleh dept terkait,
            namun statusnya belum diubah menjadi CLOSED.
        </p>

        <form method="GET" action="tugas_saya.php#verifikasi" class="filter-section">
            <div>
                <label>Plant:</label>
                <select name="plant" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="">-- Semua Plant --</option>
                    <option value="1" <?= ($plantFilter == '1') ? 'selected' : '' ?>>Plant 1</option>
                    <option value="2" <?= ($plantFilter == '2') ? 'selected' : '' ?>>Plant 2</option>
                    <option value="3" <?= ($plantFilter == '3') ? 'selected' : '' ?>>Plant 3</option>
                </select>
            </div>
            <button type="submit" class="btn-v btn-verify">Filter</button>
            <a href="tugas_saya.php#verifikasi" class="btn-v" style="background:#f1f5f9; color:inherit;">Reset</a>
        </form>

        <div class="section-heading">
            <h3>Revisi Sudah Diperbaiki PIC</h3>
            <span class="section-count <?= count($revisiDiperbaikiRows) === 0 ? 'zero' : '' ?>" style="<?= count($revisiDiperbaikiRows) > 0 ? 'background:#dbeafe; color:#1d4ed8;' : '' ?>">
                <?= count($revisiDiperbaikiRows) ?> perlu dicek ulang
            </span>
        </div>
        <p style="color:#6b7280; font-size:12.5px; margin:-6px 0 10px;">
            Catatan revisi yang sudah ditindaklanjuti Dept Head / Staff PIC. Cek hasilnya, lalu CLOSED-kan countermeasure di halaman verifikasi
            atau klik <b>Tandai sudah dicek</b> (mis. untuk catatan umum).
        </p>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Sumber</th>
                        <th>FPM / Asakai</th>
                        <th>Countermeasure</th>
                        <th>Catatan IMP</th>
                        <th>Tindak Lanjut PIC</th>
                        <th>File Sekarang</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($revisiDiperbaikiRows): ?>
                        <?php foreach ($revisiDiperbaikiRows as $r): ?>
                            <?php
                            $isAsakai = $r['sumber'] === 'ASAKAI';
                            $cfgRev = cmRevisiConfig($r['sumber']);
                            $labelRev = !empty($r['ref_table']) ? ($cfgRev['refs'][$r['ref_table']]['label'] ?? '') : 'Catatan umum';
                            $linkRev = $isAsakai ? 'update_asakai.php?id=' . (int) $r['id_asakai'] : 'update_countermeasure.php?id=' . (int) $r['id_fpm'];
                            ?>
                            <tr>
                                <td style="text-align:center;"><?= $isAsakai ? 'ASAKAI' : 'FPM ' . htmlspecialchars($r['jenis_fpm'] ?? '') ?></td>
                                <td>
                                    <?php if ($isAsakai): ?>
                                        <?= htmlspecialchars($r['tanggal'] ?? '') ?><br><small><?= htmlspecialchars(($r['machine'] ?? '') . ' - ' . ($r['namapart'] ?? '')) ?></small>
                                    <?php else: ?>
                                        <?= htmlspecialchars(($r['no_reg_fpm'] ?? '') ?: ($r['no_form'] ?? '')) ?><br><small><?= htmlspecialchars($r['nama_part'] ?? '') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><b><?= htmlspecialchars($labelRev) ?></b><br><?= nl2br(htmlspecialchars($r['item_deskripsi'] ?? '')) ?><br><small>PIC: <?= htmlspecialchars($r['dept_tujuan']) ?></small></td>
                                <td><?= nl2br(htmlspecialchars($r['catatan'])) ?></td>
                                <td>
                                    <?= !empty($r['tanggapan_pic']) ? nl2br(htmlspecialchars($r['tanggapan_pic'])) : '<i>Upload ulang file</i>' ?>
                                    <?php if (!empty($r['selesai_at'])): ?>
                                        <br><small style="color:#6b7280;"><?= htmlspecialchars($r['selesai_oleh'] ?? '') ?> &middot; <?= date('d-m-Y H:i', strtotime($r['selesai_at'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($r['ref_table']) ? cmRevisiRenderFiles($r['sumber'], $r['files'], 48) : '-' ?></td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <a href="<?= $linkRev ?>" class="btn-v btn-verify">Verifikasi</a>
                                    <form method="POST" action="verifikasi_countermeasure.php?plant=<?= urlencode($plantFilter) ?>" style="margin-top:6px;" onsubmit="return confirm('Tandai revisi ini sudah dicek?');">
                                        <input type="hidden" name="sumber" value="<?= $r['sumber'] ?>">
                                        <input type="hidden" name="id_revisi" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" name="tandai_revisi_dicek" value="1" class="btn-v" style="background:#dcfce7; color:#166534;">Tandai sudah dicek</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-hint">Tidak ada revisi yang menunggu dicek.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section-heading">
            <h3>ASAKAI</h3>
            <span class="section-count <?= count($asakaiRows) === 0 ? 'zero' : '' ?>">
                <?= count($asakaiRows) ?> perlu diverifikasi
            </span>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Plant</th>
                        <th>Mesin</th>
                        <th>Nama Part</th>
                        <th>Kategori</th>
                        <th>Countermeasure</th>
                        <th>PIC</th>
                        <th>Due Date</th>
                        <th>Actual</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($asakaiRows) > 0): ?>
                        <?php foreach ($asakaiRows as $r): ?>
                            <tr>
                                <td><?= formatTglVerifikasi($r['tanggal']) ?></td>
                                <td><?= htmlspecialchars($r['plant']) ?></td>
                                <td><?= htmlspecialchars($r['machine']) ?></td>
                                <td><?= htmlspecialchars($r['namapart']) ?></td>
                                <td><?= $r['kategori'] === 'DOWN_TIME' ? 'DOWN TIME' : 'NG' ?></td>
                                <td><?= nl2br(htmlspecialchars($r['deskripsi'])) ?></td>
                                <td><?= htmlspecialchars($r['pic'] ?? '') ?></td>
                                <td><?= formatTglVerifikasi($r['due_date']) ?></td>
                                <td><?= formatTglVerifikasi($r['actual_date']) ?></td>
                                <td>
                                    <span class="status-badge status-open"><?= htmlspecialchars($r['status'] ?? 'OPEN') ?></span>
                                </td>
                                <td>
                                    <a href="update_asakai.php?id=<?= (int) $r['id_asakai'] ?>" class="btn-v btn-verify">Verifikasi</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="empty-hint">Tidak ada countermeasure ASAKAI yang perlu diverifikasi.</td>
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
                            <?php $statusClass = strtoupper((string) $r['status']) === 'ON PROGRESS' ? 'status-on-progress' : 'status-open'; ?>
                            <tr>
                                <td><?= htmlspecialchars($r['no_form'] ?? $r['no_reg_fpm'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['plant']) ?></td>
                                <td><?= htmlspecialchars($r['jenis_fpm']) ?></td>
                                <td><?= htmlspecialchars($r['nama_part']) ?></td>
                                <td><?= htmlspecialchars($r['machine_no']) ?></td>
                                <td><?= htmlspecialchars($r['jenis_aksi']) ?></td>
                                <td><?= nl2br(htmlspecialchars($r['deskripsi'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($r['pic'] ?? '') ?></td>
                                <td><?= formatTglVerifikasi($r['due_date']) ?></td>
                                <td><?= formatTglVerifikasi($r['actual_date']) ?></td>
                                <td>
                                    <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($r['status'] ?? 'OPEN') ?></span>
                                </td>
                                <td>
                                    <a href="update_countermeasure.php?id=<?= (int) $r['id_fpm'] ?>" class="btn-v btn-verify">Verifikasi</a>
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
