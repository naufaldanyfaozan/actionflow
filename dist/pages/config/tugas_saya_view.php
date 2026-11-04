<?php
/**
 * Konten halaman "Tugas Saya" - di-include dari dalam <main class="app-main"> oleh
 * tugas_saya.php setiap role. Menampilkan semua yang harus dikerjakan user login
 * (lihat tugas_saya_helper.php), dikelompokkan & diurutkan dari yang paling mendesak.
 *
 * @var mysqli $koneksi
 */

require_once __DIR__ . '/tugas_saya_helper.php';

$modulQa   = ($_GET['modul'] ?? '') === 'qa';
$dataTugas = ambilTugasSaya($koneksi, $_SESSION);
$konteks   = $dataTugas['konteks'];

// Staff IMP & Staff QA: verifikasi countermeasure ditampilkan sebagai daftar detail
// (verifikasi_imp_view.php / verifikasi_qa_view.php), bukan baris ringkasan - grup lain
// tetap tabel Tugas Saya biasa.
$grupVerifDetail = [];
$viewVerifDetail = null;
if ($konteks['level'] === 'Staff IMP') {
    $grupVerifDetail = ['verifikasi', 'revisi_cek'];
    $viewVerifDetail = __DIR__ . '/verifikasi_imp_view.php';
} elseif ($konteks['staff_qa']) {
    $grupVerifDetail = ['verifikasi', 'revisi_cek', 'reschedule'];
    $viewVerifDetail = __DIR__ . '/verifikasi_qa_view.php';
}
$grupBiasa = array_diff_key($dataTugas['grup'], array_flip($grupVerifDetail));

// SP (Surat Peringatan) yang masih berlaku untuk pihak user ini.
$spSaya = null;
$pihakSp = $konteks['pihak'];
if ($konteks['level'] === 'Staff IMP') {
    $pihakSp[] = trim('Staff IMP ' . monitoringLabelPlant($konteks['plant']));
}
if (!in_array($konteks['level'], ['Staff'], true)) {
    $riwayatSp = ambilRiwayatSpFpm($koneksi);
    foreach ($pihakSp as $pihak) {
        if (isset($riwayatSp['aktif'][$pihak]) && (!$spSaya || $riwayatSp['aktif'][$pihak]['level'] > $spSaya['level'])) {
            $spSaya = $riwayatSp['aktif'][$pihak];
        }
    }
}

$warnaStatus = [
    'sp'      => 'ts-badge-sp',
    'warning' => 'ts-badge-warning',
    'normal'  => 'ts-badge-normal',
    'info'    => 'ts-badge-info',
];

$namaUser = trim((string) ($_SESSION['username'] ?? ''));
$peran = trim($konteks['level'] . ($konteks['dept'] !== '' ? ' ' . $konteks['dept'] : '') . ($konteks['plant'] !== '' ? ' · ' . monitoringLabelPlant($konteks['plant']) : ''));
?>
<style>
    .ts-ringkas {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .ts-ringkas a {
        text-decoration: none;
        color: inherit;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 14px;
        min-width: 180px;
        flex: 1 1 180px;
        max-width: 280px;
        box-shadow: 0 1px 4px rgba(15, 23, 42, 0.05);
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .ts-ringkas a.ada-sp {
        border-color: #fca5a5;
        background: #fef2f2;
    }

    .ts-ringkas .ikon {
        font-size: 20px;
        color: #3b82f6;
    }

    .ts-ringkas a.ada-sp .ikon {
        color: #dc2626;
    }

    .ts-ringkas .angka {
        font-size: 20px;
        font-weight: 800;
        line-height: 1;
    }

    .ts-ringkas .nama {
        font-size: 12px;
        color: #475569;
    }

    .ts-badge-sp,
    .ts-badge-warning,
    .ts-badge-normal,
    .ts-badge-info {
        font-weight: 700;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-block;
    }

    .ts-badge-sp {
        background: #fee2e2;
        color: #b91c1c;
    }

    .ts-badge-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .ts-badge-normal {
        background: #d1fae5;
        color: #047857;
    }

    .ts-badge-info {
        background: #e2e8f0;
        color: #475569;
    }

    .ts-table {
        font-size: 13px;
        margin-bottom: 0;
    }

    .ts-table th {
        background: #f8fafc;
        font-size: 12px;
        color: #475569;
        white-space: nowrap;
    }

    .ts-table td {
        vertical-align: top;
    }

    .ts-table tr.ts-row-sp td:first-child {
        box-shadow: inset 3px 0 0 #ef4444;
    }

    .ts-table tr.ts-row-warning td:first-child {
        box-shadow: inset 3px 0 0 #f59e0b;
    }

    .ts-sub {
        font-size: 11.5px;
        color: #64748b;
    }

    .ts-tugas {
        font-weight: 700;
        color: #0f172a;
    }

    .ts-kosong {
        text-align: center;
        padding: 48px 16px;
        color: #475569;
    }

    .ts-kosong i {
        font-size: 48px;
        color: #10b981;
    }
</style>

<?php renderTabTugasApproval($koneksi, 'tugas'); ?>

<div class="app-content-header">
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div>
                <h3 class="mb-0"><i class="bi bi-list-check"></i> Tugas Saya</h3>
                <div class="text-muted small">
                    <?= htmlspecialchars($namaUser) ?><?= $peran !== '' ? ' — ' . htmlspecialchars($peran) : '' ?>
                    · Semua yang perlu Anda kerjakan, urut dari yang paling mendesak.
                </div>
            </div>
            <div class="small text-muted">Per <?= date('d-m-Y H:i') ?></div>
        </div>
    </div>
</div>

<div class="app-content">
    <div class="container-fluid">

        <?php if ($spSaya): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <strong><?= htmlspecialchars($spSaya['pihak']) ?></strong> sedang memiliki
                <strong>SP <?= (int) $spSaya['level'] ?></strong> (berlaku sampai <?= monitoringFormatTgl($spSaya['berlaku_sampai']) ?>).
                Terlambat lagi selama SP berlaku akan menaikkan level SP.
            </div>
        <?php endif; ?>

        <?php if ($dataTugas['total'] === 0): ?>
            <div class="card">
                <div class="ts-kosong">
                    <i class="bi bi-check-circle-fill"></i>
                    <h5 class="mt-2 mb-1">Tidak ada tugas yang menunggu Anda</h5>
                    <div class="small">Semua FPM, countermeasure & verifikasi untuk Anda sudah beres. Halaman ini diperbarui setiap dibuka.</div>
                </div>
            </div>
        <?php else: ?>

            <div class="mb-3">
                <?php if ($dataTugas['mendesak'] > 0): ?>
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Ada <strong><?= $dataTugas['mendesak'] ?></strong> tugas yang sudah <strong>kena SP / lewat due date</strong> — kerjakan lebih dulu (garis merah).
                    </div>
                <?php endif; ?>

                <div class="ts-ringkas">
                    <?php foreach ($dataTugas['grup'] as $kode => $daftar):
                        $jml = array_sum(array_column($daftar, 'jumlah'));
                        $adaSp = in_array('sp', array_column($daftar, 'status'), true);
                    ?>
                        <a href="<?= in_array($kode, $grupVerifDetail, true) ? '#verifikasi' : '#grup-' . $kode ?>" class="<?= $adaSp ? 'ada-sp' : '' ?>">
                            <i class="bi <?= TUGAS_SAYA_GRUP[$kode]['ikon'] ?> ikon"></i>
                            <div>
                                <div class="angka"><?= $jml ?></div>
                                <div class="nama"><?= htmlspecialchars(TUGAS_SAYA_GRUP[$kode]['judul']) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($grupBiasa): ?>
                <div class="mb-3" style="max-width:360px">
                    <input type="search" id="tsCari" class="form-control form-control-sm" placeholder="Cari no. FPM / mesin / dept ...">
                </div>
            <?php endif; ?>

            <?php foreach ($grupBiasa as $kode => $daftar): ?>
                <div class="card mb-3 ts-grup" id="grup-<?= $kode ?>">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="bi <?= TUGAS_SAYA_GRUP[$kode]['ikon'] ?>"></i>
                        <strong><?= htmlspecialchars(TUGAS_SAYA_GRUP[$kode]['judul']) ?></strong>
                        <span class="badge bg-secondary"><?= array_sum(array_column($daftar, 'jumlah')) ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover ts-table">
                                <thead>
                                    <tr>
                                        <th style="width:190px">Status</th>
                                        <th>No. FPM</th>
                                        <th>Yang harus dikerjakan</th>
                                        <th style="width:130px"><?= in_array($kode, ['cm_fpm', 'cm_asakai'], true) ? 'Due date' : 'Sejak' ?></th>
                                        <th style="width:120px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daftar as $t): ?>
                                        <tr class="ts-row-<?= $t['status'] ?> ts-baris">
                                            <td>
                                                <span class="<?= $warnaStatus[$t['status']] ?>"><?= htmlspecialchars($t['status_label']) ?></span>
                                            </td>
                                            <td style="white-space:nowrap" class="fw-semibold"><?= htmlspecialchars($t['no_form']) ?></td>
                                            <td>
                                                <div class="ts-tugas"><?= htmlspecialchars($t['tugas']) ?></div>
                                                <?php if ($t['detail'] !== ''): ?>
                                                    <div class="ts-sub"><?= htmlspecialchars($t['detail']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="white-space:nowrap">
                                                <?= monitoringFormatTgl($t['sejak']) ?>
                                                <?php if ($t['hari'] !== null): ?>
                                                    <div class="ts-sub">H+<?= (int) $t['hari'] ?> hari kerja</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($t['link'] !== ''): ?>
                                                    <a href="<?= htmlspecialchars($t['link']) ?>" class="btn btn-sm <?= $t['status'] === 'sp' ? 'btn-danger' : 'btn-primary' ?>" style="white-space:nowrap">
                                                        <?= htmlspecialchars($t['tombol']) ?> <i class="bi bi-arrow-right-short"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // Staff IMP / Staff QA: daftar verifikasi lengkap (sebelumnya menu Verifikasi Countermeasure).
            if ($viewVerifDetail && array_intersect_key($dataTugas['grup'], array_flip($grupVerifDetail))) {
                require $viewVerifDetail;
            }
            ?>

            <div class="small text-muted mb-4">
                H+ dihitung hari kerja (Sabtu & Minggu tidak dihitung), batas SP sama dengan tab
                <a href="monitoring_approval.php<?= $modulQa ? '?modul=qa' : '' ?>">Monitor Approval</a>.
                Countermeasure dianggap selesai dari sisi dept setelah bukti diupload; status CLOSED tetap lewat verifikasi.
            </div>

            <script>
                document.getElementById('tsCari')?.addEventListener('input', function() {
                    const kata = this.value.trim().toLowerCase();
                    document.querySelectorAll('.ts-grup').forEach(function(grup) {
                        let tampil = 0;
                        grup.querySelectorAll('.ts-baris').forEach(function(tr) {
                            const cocok = kata === '' || tr.textContent.toLowerCase().includes(kata);
                            tr.style.display = cocok ? '' : 'none';
                            if (cocok) tampil++;
                        });
                        grup.style.display = tampil > 0 ? '' : 'none';
                    });
                });
            </script>

        <?php endif; ?>

    </div>
</div>
