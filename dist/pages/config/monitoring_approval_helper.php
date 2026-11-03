<?php

/**
 * Monitoring Flow Approval FPM - setiap FPM yang approval-nya belum selesai: sedang di
 * tahap apa, datang dari mana, menunggu siapa, sejak kapan dan sudah H+ berapa (hari
 * kerja, Sabtu & Minggu tidak dihitung). Lewat batas di monitoringAturanApproval() ->
 * Warning lalu SP untuk pihak yang ditunggu.
 *
 * Dipakai halaman monitoring_approval.php di semua role (lihat monitoring_approval_view.php)
 * dan badge jumlah SP di sidebar.
 *
 * Cakupan: FPM IMP (Down Time & Internal) dan FPM Claim Machining. FPM QA (flow_type 'QA')
 * belum ikut karena batas H+-nya belum ditentukan.
 */

require_once __DIR__ . '/fpm_claim_machining_helper.php';
require_once __DIR__ . '/app_setting_helper.php';
require_once __DIR__ . '/sp_email_hrd_helper.php';

// Batas hari kerja per tahap: 'warning' = mulai "Mendekati SP", 'sp' = pihak yang ditunggu
// mendapat SP (Surat Peringatan). Tahap tanpa aturan ditampilkan tanpa batas waktu.
// Angkanya diatur Admin IT (app_setting monitor_<tahap>_warning / monitor_<tahap>_sp).
function monitoringAturanApproval(): array
{
    $aturan = [
        'jawab'      => ['label' => 'Menjawab FPM',                 'pihak' => 'Dept Head'],
        'periksa'    => ['label' => 'Memeriksa FPM',                'pihak' => 'Plant Head'],
        'verifikasi' => ['label' => 'Verifikasi FPM',               'pihak' => 'Head IMP'],
        'setuju'     => ['label' => 'Menyetujui FPM',               'pihak' => 'Directure'],
        'cm_jawab'   => ['label' => 'Menjawab FPM Claim Machining', 'pihak' => 'Dept Head'],
        'cm_dh'      => ['label' => 'Claim Machining: approve jawaban', 'pihak' => 'Dept Head'],
        'cm_ph'      => ['label' => 'Claim Machining: approve',         'pihak' => 'Plant Head'],
        'cm_dir'     => ['label' => 'Claim Machining: approve',         'pihak' => 'Directure'],
    ];
    foreach ($aturan as $tahap => &$a) {
        $a['warning'] = appSettingInt("monitor_{$tahap}_warning");
        $a['sp']      = appSettingInt("monitor_{$tahap}_sp");
    }
    return $aturan;
}

// '' / '0000-00-00' / NULL -> null, selain itu 'Y-m-d' (kolom DATETIME ikut dipotong).
function monitoringTgl($nilai): ?string
{
    $nilai = trim((string) $nilai);
    if ($nilai === '' || strpos($nilai, '0000-00-00') === 0) {
        return null;
    }
    $ts = strtotime($nilai);
    return $ts ? date('Y-m-d', $ts) : null;
}

// Tanggal paling akhir dari beberapa kandidat (yang kosong dilewati).
function monitoringTglTerakhir(...$kandidat): ?string
{
    $hasil = null;
    foreach ($kandidat as $tgl) {
        $tgl = monitoringTgl($tgl);
        if ($tgl !== null && ($hasil === null || $tgl > $hasil)) {
            $hasil = $tgl;
        }
    }
    return $hasil;
}

// Jumlah hari kerja yang sudah lewat sejak $mulai sampai $hariIni (H+n). 0 kalau $mulai
// hari ini / di masa depan.
function monitoringHariKerja(?string $mulai, string $hariIni): int
{
    if ($mulai === null || $mulai >= $hariIni) {
        return 0;
    }

    $tgl  = new DateTime($mulai);
    $stop = new DateTime($hariIni);
    $hari = 0;

    while ($tgl < $stop) {
        $tgl->modify('+1 day');
        if ($tgl->format('N') < 6) {
            $hari++;
        }
    }

    return $hari;
}

// Tanggal setelah $n hari kerja dari $mulai - dipakai untuk tanggal jatuhnya SP.
function monitoringTambahHariKerja(string $mulai, int $n): string
{
    $tgl = new DateTime($mulai);
    while ($n > 0) {
        $tgl->modify('+1 day');
        if ($tgl->format('N') < 6) {
            $n--;
        }
    }
    return $tgl->format('Y-m-d');
}

function monitoringLabelPlant($plant): string
{
    $plant = trim(str_ireplace('Plant', '', (string) $plant));
    return $plant !== '' ? 'Plant ' . $plant : '';
}

function monitoringFormatTgl(?string $tgl): string
{
    return $tgl ? date('d-m-Y', strtotime($tgl)) : '-';
}

/**
 * Tahap approval FPM IMP (Down Time / Internal) yang sedang berjalan.
 * Urutan: Head IMP approve -> Dept Head jawab & approve -> Plant Head periksa ->
 * Head IMP verifikasi -> Directure setuju. Sama dengan kolom "Waiting" di List FPM.
 */
function monitoringTahapImp(array $r): ?array
{
    if (trim($r['disetujui'] ?? '') === 'Directure') {
        return null;
    }

    $dept     = trim($r['dept'] ?? '') !== '' ? trim($r['dept']) : trim($r['internal_dept'] ?? '');
    $plantLbl = monitoringLabelPlant($r['plant'] ?? '');

    if (($r['status_reject'] ?? '') === 'REJECT') {
        return [
            'tahap'      => 'Revisi jawaban FPM',
            'dari'       => 'Diminta revisi ' . monitoringFormatTgl(monitoringTgl($r['tgl_reject'] ?? '')),
            'menunggu'   => 'Dept Head ' . $dept,
            'keterangan' => trim($r['note'] ?? '') !== '' ? 'Catatan: ' . trim($r['note']) : '',
            'mulai'      => monitoringTglTerakhir($r['tgl_reject'] ?? '', $r['tgl_disetujui_imp'] ?? ''),
            'aturan'     => 'jawab',
        ];
    }

    if (monitoringTgl($r['diterima_imp'] ?? '') !== null) {
        return [
            'tahap'      => 'Persetujuan Directure',
            'dari'       => 'Head IMP verifikasi ' . monitoringFormatTgl(monitoringTgl($r['diterima_imp'])),
            'menunggu'   => 'Directure',
            'keterangan' => '',
            'mulai'      => monitoringTgl($r['diterima_imp']),
            'aturan'     => 'setuju',
        ];
    }

    if (trim($r['diperiksa'] ?? '') !== '') {
        $mulai = monitoringTglTerakhir($r['tgl_diperiksa'] ?? '', $r['tgl_dibuat'] ?? '', $r['tgl_jawab'] ?? '');
        return [
            'tahap'      => 'Verifikasi IMP',
            'dari'       => 'Plant Head periksa ' . monitoringFormatTgl($mulai),
            'menunggu'   => trim('Head IMP ' . $plantLbl),
            'keterangan' => 'Dibantu Staff IMP ' . $plantLbl,
            'mulai'      => $mulai,
            'aturan'     => 'verifikasi',
        ];
    }

    if (trim($r['dibuat'] ?? '') !== '') {
        $mulai = monitoringTglTerakhir($r['tgl_dibuat'] ?? '', $r['tgl_jawab'] ?? '', $r['tgl_disetujui_imp'] ?? '');
        return [
            'tahap'      => 'Pemeriksaan Plant Head',
            'dari'       => 'Dept Head ' . $dept . ' approve ' . monitoringFormatTgl($mulai),
            'menunggu'   => 'Plant Head',
            'keterangan' => '',
            'mulai'      => $mulai,
            'aturan'     => 'periksa',
        ];
    }

    if (trim($r['disetujui_oleh'] ?? '') === 'Imp Head') {
        if (monitoringTgl($r['diterima_pic'] ?? '') === null) {
            $ket = 'Belum diterima Dept Head';
        } elseif ((int) ($r['jml_countermeasure'] ?? 0) === 0) {
            $ket = 'Sudah diterima, countermeasure belum diisi';
        } else {
            $ket = 'Sudah dijawab, menunggu approve Dept Head';
        }

        return [
            'tahap'      => 'Jawab & approve Dept terkait',
            'dari'       => 'Head IMP approve ' . monitoringFormatTgl(monitoringTgl($r['tgl_disetujui_imp'] ?? '')),
            'menunggu'   => 'Dept Head ' . $dept,
            'keterangan' => $ket,
            'mulai'      => monitoringTgl($r['tgl_disetujui_imp'] ?? ''),
            'aturan'     => 'jawab',
        ];
    }

    return [
        'tahap'      => 'Approve Head IMP',
        'dari'       => 'Staff IMP buat FPM ' . monitoringFormatTgl(monitoringTgl($r['tgl_pembuatan'] ?? '')),
        'menunggu'   => trim('Head IMP ' . $plantLbl),
        'keterangan' => !empty($r['minta_perbaikan_data_at']) ? 'Diminta perbaikan data oleh Dept Head' : '',
        'mulai'      => monitoringTglTerakhir($r['tgl_pembuatan'] ?? '', $r['minta_perbaikan_data_at'] ?? ''),
        'aturan'     => null,
    ];
}

/**
 * Tahap approval FPM Claim Machining yang sedang berjalan.
 * Urutan: Dept Head QA approve -> Plant Head terima & pilih dept -> Dept tujuan terima,
 * jawab & approve (batas 5 / 3 hari kerja) -> Plant Head approve -> Directure approve.
 */
function monitoringTahapClaimMach(array $r, string $hariIni): ?array
{
    if (!empty($r['cm_directur_approved_at']) || strtoupper(trim($r['status_evaluasi'] ?? '')) === 'CLOSED') {
        return null;
    }

    $dept = trim($r['dept'] ?? '');

    if (empty($r['cm_deptheadqa_approved_at'])) {
        return [
            'tahap'      => 'Approve Dept Head QA',
            'dari'       => 'Staff QA buat FPM ' . monitoringFormatTgl(monitoringTgl($r['tgl_pembuatan'] ?? '')),
            'menunggu'   => 'Dept Head QA',
            'keterangan' => '',
            'mulai'      => monitoringTgl($r['tgl_pembuatan'] ?? ''),
            'aturan'     => null,
        ];
    }

    if (empty($r['cm_plant_head_diterima_at']) || $dept === '' || strcasecmp($dept, 'All Dept') === 0) {
        return [
            'tahap'      => 'Terima & pilih dept tujuan',
            'dari'       => 'Dept Head QA approve ' . monitoringFormatTgl(monitoringTgl($r['cm_deptheadqa_approved_at'])),
            'menunggu'   => 'Plant Head',
            'keterangan' => '',
            'mulai'      => monitoringTgl($r['cm_deptheadqa_approved_at']),
            'aturan'     => null,
        ];
    }

    if (empty($r['cm_dept_head_dijawab_at'])) {
        $dueJawab = hitungDueDateJawabClaimMachining(
            monitoringTgl($r['tgl_pembuatan'] ?? '') ?? $hariIni,
            (int) ($r['kejadian_berulang_ke'] ?? 0)
        );

        $ket = empty($r['cm_dept_head_diterima_at']) ? 'Belum diterima Dept Head' : 'Sudah diterima, belum dijawab';

        // Revisi dari Plant Head / Directure: hitungan ulang dari tanggal revisi.
        $revisi = ($r['status_reject'] ?? '') === 'REJECT' && monitoringTgl($r['tgl_reject'] ?? '') !== null;

        return [
            'tahap'      => $revisi ? 'Revisi jawaban FPM' : 'Jawab FPM Dept tujuan',
            'dari'       => $revisi
                ? 'Diminta revisi ' . monitoringFormatTgl(monitoringTgl($r['tgl_reject']))
                : 'Plant Head teruskan ' . monitoringFormatTgl(monitoringTgl($r['cm_plant_head_diterima_at'])),
            'menunggu'   => 'Dept Head ' . $dept,
            'keterangan' => $ket . ($revisi ? '' : ' · Due jawab ' . monitoringFormatTgl($dueJawab)),
            'mulai'      => $revisi ? monitoringTgl($r['tgl_reject']) : $dueJawab,
            'aturan'     => 'cm_jawab',
        ];
    }

    // Jenjang approval Claim Machining (Dept Head -> Plant Head -> Directure) punya batas
    // waktu sendiri (cm_dh / cm_ph / cm_dir), terpisah dari FPM IMP.
    if (empty($r['cm_dept_head_approved_at'])) {
        return [
            'tahap'      => 'Approve jawaban Dept Head',
            'dari'       => 'Dept ' . $dept . ' jawab ' . monitoringFormatTgl(monitoringTgl($r['cm_dept_head_dijawab_at'])),
            'menunggu'   => 'Dept Head ' . $dept,
            'keterangan' => '',
            'mulai'      => monitoringTgl($r['cm_dept_head_dijawab_at']),
            'aturan'     => 'cm_dh',
        ];
    }

    if (empty($r['cm_plant_head_approved_at'])) {
        return [
            'tahap'      => 'Approve Plant Head',
            'dari'       => 'Dept Head ' . $dept . ' approve ' . monitoringFormatTgl(monitoringTgl($r['cm_dept_head_approved_at'])),
            'menunggu'   => 'Plant Head',
            'keterangan' => '',
            'mulai'      => monitoringTgl($r['cm_dept_head_approved_at']),
            'aturan'     => 'cm_ph',
        ];
    }

    return [
        'tahap'      => 'Persetujuan Directure',
        'dari'       => 'Plant Head approve ' . monitoringFormatTgl(monitoringTgl($r['cm_plant_head_approved_at'])),
        'menunggu'   => 'Directure',
        'keterangan' => '',
        'mulai'      => monitoringTgl($r['cm_plant_head_approved_at']),
        'aturan'     => 'cm_dir',
    ];
}

/**
 * Semua FPM yang approval-nya belum selesai, sudah dilengkapi H+ dan status
 * ('sp' / 'warning' / 'normal' / 'info'), urut dari yang paling kritis.
 */
function ambilMonitoringApproval(mysqli $koneksi, ?string $hariIni = null): array
{
    $hariIni = $hariIni ?? date('Y-m-d');

    $q = $koneksi->query("SELECT h.id_fpm, h.no_form, h.jenis_fpm, h.flow_type, h.plant, h.dept,
            h.internal_dept, h.tgl_pembuatan, h.disetujui_oleh, h.tgl_disetujui_imp,
            h.minta_perbaikan_data_at, h.diterima_pic, h.diterima_imp, h.status_reject,
            h.tgl_reject, h.note, h.status_evaluasi, h.kejadian_berulang_ke,
            h.cm_deptheadqa_approved_at, h.cm_plant_head_diterima_at, h.cm_dept_head_diterima_at,
            h.cm_dept_head_dijawab_at, h.cm_dept_head_approved_at, h.cm_plant_head_approved_at,
            h.cm_directur_approved_at,
            p.tgl AS tgl_jawab, p.dibuat, p.tgl_dibuat, p.diperiksa, p.tgl_diperiksa, p.disetujui,
            (SELECT COUNT(*) FROM fpm_corrective_action WHERE id_fpm = h.id_fpm)
                + (SELECT COUNT(*) FROM fpm_preventive_action WHERE id_fpm = h.id_fpm) AS jml_countermeasure
        FROM fpm_header h
        LEFT JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
        WHERE IFNULL(h.flow_type, '') != 'QA'");

    $hasil = [];
    $daftarAturan = monitoringAturanApproval();

    while ($q && ($r = $q->fetch_assoc())) {
        $isClaimMach = trim($r['flow_type'] ?? '') === 'CLAIMMACH';
        $tahap = $isClaimMach ? monitoringTahapClaimMach($r, $hariIni) : monitoringTahapImp($r);

        if ($tahap === null) {
            continue;
        }

        $aturan = $tahap['aturan'] !== null ? $daftarAturan[$tahap['aturan']] : null;
        $hari   = monitoringHariKerja($tahap['mulai'], $hariIni);

        if ($aturan === null) {
            $status = 'info';
        } elseif ($hari >= $aturan['sp']) {
            $status = 'sp';
        } elseif ($hari >= $aturan['warning']) {
            $status = 'warning';
        } else {
            $status = 'normal';
        }

        if ($isClaimMach) {
            $jenis = 'Claim Machining';
        } elseif (stripos($r['jenis_fpm'] ?? '', 'Down') !== false) {
            $jenis = 'Down Time';
        } else {
            $jenis = 'Internal';
        }

        $hasil[] = $tahap + [
            'id_fpm'  => (int) $r['id_fpm'],
            'no_form' => $r['no_form'],
            'jenis'   => $jenis,
            'plant'   => trim(str_ireplace('Plant', '', (string) $r['plant'])),
            'dept'    => trim($r['dept'] ?? '') !== '' ? trim($r['dept']) : trim($r['internal_dept'] ?? ''),
            'hari'    => $hari,
            'status'  => $status,
            // Tanggal pihak yang ditunggu mulai kena SP (Surat Peringatan) & sudah lewat berapa hari kerja.
            'tgl_sp'  => ($aturan && $tahap['mulai']) ? monitoringTambahHariKerja($tahap['mulai'], $aturan['sp']) : null,
            'batas_sp' => $aturan['sp'] ?? null,
            'lewat_sp' => $aturan ? max(0, $hari - $aturan['sp']) : 0,
        ];
    }

    $urutan = ['sp' => 0, 'warning' => 1, 'normal' => 2, 'info' => 3];
    usort($hasil, function ($a, $b) use ($urutan) {
        return [$urutan[$a['status']], -$a['hari'], $a['no_form']] <=> [$urutan[$b['status']], -$b['hari'], $b['no_form']];
    });

    return $hasil;
}

// Jumlah FPM yang sedang berstatus SP - untuk badge menu Monitoring Approval di sidebar.
// Sekaligus mencatat pelanggaran SP baru (sidebar dimuat di setiap halaman, jadi FPM yang
// lewat batas tetap tercatat walau halaman Monitor Approval tidak dibuka).
function hitungSpMonitoringApproval(mysqli $koneksi): int
{
    $data = ambilMonitoringApproval($koneksi);
    // SP Staff IMP yang lupa membuat FPM dari pembahasan Asakai - dicatat lebih dulu supaya
    // ikut terkirim di email SP ke HRD (prosesEmailSpHrd di catatPelanggaranSpFpm).
    require_once __DIR__ . '/asakai_fpm_helper.php';
    catatSpAsakaiFpm($koneksi);
    catatPelanggaranSpFpm($koneksi, $data);
    return count(array_filter($data, fn($row) => $row['status'] === 'sp'));
}

/*
 * ===================== SP (Surat Peringatan) bertingkat =====================
 * Aturan HR: SP berlaku 6 bulan. Terlambat lagi selama SP masih berlaku -> naik level
 * (SP 1 -> SP 2 -> SP 3; SP 3 tertinggi, pelanggaran berikutnya tetap SP 3 & masa
 * berlakunya diperpanjang). Beberapa FPM yang jatuh SP di hari yang sama = 1 SP.
 *
 * Setiap FPM yang lewat batas dicatat permanen di fpm_sp_pelanggaran (riwayat tetap ada
 * walau FPM-nya sudah di-approve). Level SP tidak disimpan - selalu dihitung ulang dari
 * riwayat, jadi tidak bisa melenceng. Keterlambatan sebelum fitur ini aktif (backlog)
 * dihitung pada tanggal pertama kali tercatat, bukan mundur ke tanggal aslinya.
 */
// Diatur Admin IT (app_setting sp_masa_berlaku_bulan / sp_level_maks).
define('MONITORING_SP_MASA_BERLAKU_BULAN', appSettingInt('sp_masa_berlaku_bulan'));
define('MONITORING_SP_LEVEL_MAKS', appSettingInt('sp_level_maks'));

// Tabel dibuat otomatis kalau belum ada (lihat juga migration_2026_11_02_fpm_sp_pelanggaran.sql).
function siapkanTabelSpFpm(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap === null) {
        $siap = (bool) $koneksi->query("CREATE TABLE IF NOT EXISTS fpm_sp_pelanggaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pihak VARCHAR(100) NOT NULL,
            id_fpm INT NOT NULL,
            no_form VARCHAR(100) NULL,
            tahap VARCHAR(30) NOT NULL,
            tahap_label VARCHAR(100) NULL,
            tgl_mulai DATE NOT NULL,
            tgl_sp DATE NOT NULL,
            dicatat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email_hrd_status VARCHAR(20) NULL,
            email_hrd_dicoba_at DATETIME NULL,
            UNIQUE KEY uq_pelanggaran (pihak, id_fpm, tahap, tgl_mulai),
            KEY idx_pihak (pihak)
        )");

        // Tabel lama (sebelum fitur email SP ke HRD): tambah kolom status email. Baris yang
        // sudah ada ditandai 'lama' supaya tidak terkirim massal saat fitur diaktifkan.
        $adaKolom = $siap ? $koneksi->query("SHOW COLUMNS FROM fpm_sp_pelanggaran LIKE 'email_hrd_status'") : false;
        if ($adaKolom && $adaKolom->num_rows === 0) {
            $siap = $koneksi->query("ALTER TABLE fpm_sp_pelanggaran
                    ADD COLUMN email_hrd_status VARCHAR(20) NULL,
                    ADD COLUMN email_hrd_dicoba_at DATETIME NULL")
                && $koneksi->query("UPDATE fpm_sp_pelanggaran SET email_hrd_status = 'lama'");
        }
    }
    return $siap;
}

// Catat FPM berstatus SP yang belum tercatat (1 baris per FPM per tahap yang terlambat).
function catatPelanggaranSpFpm(mysqli $koneksi, array $data): void
{
    static $sudah = false;
    if ($sudah || !siapkanTabelSpFpm($koneksi)) {
        return;
    }
    $sudah = true;

    $nilai = [];
    foreach ($data as $row) {
        if ($row['status'] !== 'sp' || !$row['tgl_sp'] || !$row['mulai'] || !$row['aturan']) {
            continue;
        }
        $e = fn($v) => "'" . $koneksi->real_escape_string((string) $v) . "'";
        $nilai[] = '(' . implode(',', [
            $e($row['menunggu']), (int) $row['id_fpm'], $e($row['no_form']), $e($row['aturan']),
            $e($row['tahap']), $e($row['mulai']), $e($row['tgl_sp']),
        ]) . ')';
    }

    if ($nilai) {
        $koneksi->query("INSERT IGNORE INTO fpm_sp_pelanggaran
            (pihak, id_fpm, no_form, tahap, tahap_label, tgl_mulai, tgl_sp) VALUES " . implode(',', $nilai));
    }

    // SP yang baru tercatat -> email ke HRD (kalau diaktifkan Admin IT).
    prosesEmailSpHrd($koneksi);
}

/**
 * Riwayat SP per pihak, dihitung dari fpm_sp_pelanggaran.
 * @return array{riwayat: array, aktif: array<string, array>}
 *   riwayat: SP yang pernah keluar, terbaru dulu - [pihak, tgl, level, berlaku_sampai, fpm[], aktif]
 *   aktif  : SP yang masih berlaku per pihak (level terakhir).
 */
function ambilRiwayatSpFpm(mysqli $koneksi, ?string $hariIni = null): array
{
    $hariIni = $hariIni ?? date('Y-m-d');
    $kosong  = ['riwayat' => [], 'aktif' => []];

    if (!siapkanTabelSpFpm($koneksi)) {
        return $kosong;
    }

    // Tanggal fitur mulai mencatat - keterlambatan sebelum itu dihitung di tanggal ini.
    $qMulai = $koneksi->query("SELECT MIN(DATE(dicatat_at)) AS mulai FROM fpm_sp_pelanggaran");
    $mulaiBerlaku = $qMulai ? ($qMulai->fetch_assoc()['mulai'] ?? null) : null;
    if (!$mulaiBerlaku) {
        return $kosong;
    }

    // pihak => tanggal pelanggaran => daftar FPM
    $perPihak = [];
    $q = $koneksi->query("SELECT id, pihak, id_fpm, no_form, tahap, tahap_label, tgl_sp FROM fpm_sp_pelanggaran ORDER BY tgl_sp, id");
    while ($q && ($r = $q->fetch_assoc())) {
        $tgl = max($r['tgl_sp'], $mulaiBerlaku);
        $perPihak[$r['pihak']][$tgl][] = ['id' => (int) $r['id'], 'id_fpm' => (int) $r['id_fpm'], 'no_form' => $r['no_form'], 'tahap' => $r['tahap_label'],
            // SP lupa buat FPM dari Asakai: id_fpm berisi id_asakai (lihat asakai_fpm_helper.php).
            'asakai' => strpos($r['tahap'], 'asakai_fpm') === 0];
    }

    $riwayat = [];
    $aktif   = [];
    foreach ($perPihak as $pihak => $perTanggal) {
        ksort($perTanggal);
        $level = 0;
        $berlakuSampai = null;

        foreach ($perTanggal as $tgl => $fpm) {
            $level = ($level > 0 && $tgl <= $berlakuSampai) ? min($level + 1, MONITORING_SP_LEVEL_MAKS) : 1;
            $berlakuSampai = (new DateTime($tgl))
                ->modify('+' . MONITORING_SP_MASA_BERLAKU_BULAN . ' months')->modify('-1 day')->format('Y-m-d');

            $riwayat[] = [
                'pihak'          => $pihak,
                'tgl'            => $tgl,
                'level'          => $level,
                'berlaku_sampai' => $berlakuSampai,
                'fpm'            => $fpm,
                'aktif'          => false,
            ];
        }

        if ($berlakuSampai >= $hariIni) {
            $riwayat[count($riwayat) - 1]['aktif'] = true;
            $aktif[$pihak] = $riwayat[count($riwayat) - 1];
        }
    }

    usort($riwayat, fn($a, $b) => [$b['tgl'], $b['level']] <=> [$a['tgl'], $a['level']]);
    uasort($aktif, fn($a, $b) => [$b['level'], $a['berlaku_sampai']] <=> [$a['level'], $b['berlaku_sampai']]);

    return ['riwayat' => $riwayat, 'aktif' => $aktif];
}
