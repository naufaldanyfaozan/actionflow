<?php

/**
 * FPM dari pembahasan Asakai.
 *
 * Saat membahas Asakai (staff_imp/form_pembahasan_asakai.php), Staff IMP menandai problem
 * yang perlu dibuatkan FPM: "Buat sekarang" (langsung ke form FPM dengan header terisi dari
 * Asakai) atau "Buat nanti". Keduanya dicatat sebagai tugas di asakai_fpm_tugas, jadi
 * form yang dibuka lalu ditinggal tetap terpantau.
 *
 * Tugas selesai saat FPM-nya disimpan (proses_down_time.php / proses_fpm_internal.php ->
 * tautkanFpmKeAsakai()). Selama belum selesai, dihitung hari kerja sejak ditandai:
 *   H+0 normal, H+warning -> peringatan ke Staff IMP (dashboard + WA), H+sp -> SP untuk
 *   "Staff IMP Plant X" di fpm_sp_pelanggaran (riwayat & level SP sama dengan Monitor Approval).
 * Batasnya diatur Admin IT (monitor_asakai_fpm_warning / monitor_asakai_fpm_sp).
 */

require_once __DIR__ . '/monitoring_approval_helper.php';

// jenis => label, form pembuatan FPM (folder staff_imp), kode tahap di fpm_sp_pelanggaran.
const ASAKAI_FPM_JENIS = [
    'DOWN_TIME' => ['label' => 'Down Time',   'form' => 'buat_fpm_down_time.php',   'tahap' => 'asakai_fpm_dt'],
    'NG'        => ['label' => 'NG Internal', 'form' => 'buat_fpm_ng_internal.php', 'tahap' => 'asakai_fpm_ng'],
];

// Tabel dibuat otomatis kalau belum ada (lihat juga migration_2026_11_03b_asakai_fpm_tugas.sql).
function siapkanTabelAsakaiFpm(mysqli $koneksi): bool
{
    static $siap = null;
    if ($siap === null) {
        $siap = (bool) $koneksi->query("CREATE TABLE IF NOT EXISTS asakai_fpm_tugas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_asakai INT NOT NULL,
            jenis VARCHAR(20) NOT NULL,
            plant VARCHAR(5) NOT NULL DEFAULT '',
            cara VARCHAR(10) NOT NULL,
            dibuat_oleh VARCHAR(50) NULL,
            dibuat_at DATETIME NOT NULL,
            id_fpm INT NULL,
            no_form VARCHAR(100) NULL,
            selesai_at DATETIME NULL,
            dibatalkan_oleh VARCHAR(50) NULL,
            dibatalkan_at DATETIME NULL,
            alasan_batal VARCHAR(255) NULL,
            UNIQUE KEY uq_asakai_jenis (id_asakai, jenis),
            KEY idx_plant (plant)
        )");
    }
    return $siap;
}

function asakaiFpmPlant($plant): string
{
    return trim(str_ireplace('Plant', '', (string) $plant));
}

/**
 * Status tugas pada $hariIni: 'selesai' | 'batal' | 'sp' | 'warning' | 'normal', plus
 * H+ (hari kerja sejak ditandai) dan tanggal mulai warning / SP.
 */
function asakaiFpmStatus(array $t, ?string $hariIni = null): array
{
    $hariIni = $hariIni ?? date('Y-m-d');
    $mulai   = date('Y-m-d', strtotime($t['dibuat_at']));
    $batasW  = appSettingInt('monitor_asakai_fpm_warning');
    $batasSp = appSettingInt('monitor_asakai_fpm_sp');
    $hari    = monitoringHariKerja($mulai, $hariIni);

    if (!empty($t['dibatalkan_at'])) {
        $status = 'batal';
    } elseif (!empty($t['id_fpm'])) {
        $status = 'selesai';
    } elseif ($hari >= $batasSp) {
        $status = 'sp';
    } elseif ($hari >= $batasW) {
        $status = 'warning';
    } else {
        $status = 'normal';
    }

    return [
        'status'      => $status,
        'hari'        => $hari,
        'mulai'       => $mulai,
        'tgl_warning' => monitoringTambahHariKerja($mulai, $batasW),
        'tgl_sp'      => monitoringTambahHariKerja($mulai, $batasSp),
        // Hari terakhir boleh dibuat tanpa SP (sehari sebelum tanggal SP, hari kerja).
        'batas_akhir' => monitoringTambahHariKerja($mulai, $batasSp - 1),
    ];
}

/** Tugas FPM satu Asakai, per jenis (DOWN_TIME / NG). */
function ambilTugasAsakaiFpm(mysqli $koneksi, int $idAsakai): array
{
    $hasil = [];
    if (!siapkanTabelAsakaiFpm($koneksi)) {
        return $hasil;
    }
    $stmt = $koneksi->prepare("SELECT * FROM asakai_fpm_tugas WHERE id_asakai = ?");
    $stmt->bind_param('i', $idAsakai);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $hasil[$t['jenis']] = $t + asakaiFpmStatus($t);
    }
    return $hasil;
}

/**
 * Tandai problem Asakai perlu dibuatkan FPM ($cara 'langsung' / 'nanti').
 * Tugas yang sudah ada (belum selesai / sudah selesai) dibiarkan - hitungan H+ tidak
 * diulang dari nol. Tugas yang pernah dibatalkan dibuka lagi mulai hari ini.
 * @return string|null pesan error
 */
function tandaiAsakaiPerluFpm(mysqli $koneksi, int $idAsakai, string $jenis, string $cara, string $user): ?string
{
    if (!isset(ASAKAI_FPM_JENIS[$jenis]) || !in_array($cara, ['langsung', 'nanti'], true)) {
        return 'Pilihan tidak valid.';
    }
    if (!siapkanTabelAsakaiFpm($koneksi)) {
        return 'Tabel tugas FPM Asakai tidak bisa dibuat.';
    }

    $stmt = $koneksi->prepare("SELECT plant FROM asakai_header WHERE id_asakai = ?");
    $stmt->bind_param('i', $idAsakai);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();
    if (!$header) {
        return 'Data Asakai tidak ditemukan.';
    }
    $plant = asakaiFpmPlant($header['plant']);

    $stmt = $koneksi->prepare("INSERT INTO asakai_fpm_tugas (id_asakai, jenis, plant, cara, dibuat_oleh, dibuat_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            cara = IF(dibatalkan_at IS NULL, cara, VALUES(cara)),
            dibuat_oleh = IF(dibatalkan_at IS NULL, dibuat_oleh, VALUES(dibuat_oleh)),
            dibuat_at = IF(dibatalkan_at IS NULL, dibuat_at, NOW()),
            dibatalkan_oleh = NULL, alasan_batal = IF(dibatalkan_at IS NULL, alasan_batal, NULL),
            dibatalkan_at = NULL");
    $stmt->bind_param('issss', $idAsakai, $jenis, $plant, $cara, $user);
    return $stmt->execute() ? null : 'Gagal menyimpan tugas FPM Asakai.';
}

/**
 * Batalkan tugas (salah tandai). Hanya boleh di hari yang sama dengan saat ditandai -
 * setelah itu tidak bisa dibatalkan supaya peringatan & SP tidak bisa dihindari.
 * @return string|null pesan error
 */
function batalkanAsakaiFpm(mysqli $koneksi, int $idAsakai, string $jenis, string $user, string $alasan): ?string
{
    $tugas = ambilTugasAsakaiFpm($koneksi, $idAsakai)[$jenis] ?? null;
    $alasan = trim($alasan);
    if (!$tugas || $tugas['status'] === 'batal') {
        return 'Tugas tidak ditemukan.';
    }
    if ($tugas['status'] === 'selesai') {
        return 'FPM sudah dibuat, tugas tidak bisa dibatalkan.';
    }
    if ($tugas['mulai'] !== date('Y-m-d')) {
        return 'Pembatalan hanya bisa di hari yang sama saat ditandai. Silakan buat FPM-nya.';
    }
    if ($alasan === '') {
        return 'Alasan pembatalan wajib diisi.';
    }

    $alasan = mb_substr($alasan, 0, 255);
    $stmt = $koneksi->prepare("UPDATE asakai_fpm_tugas SET dibatalkan_at = NOW(), dibatalkan_oleh = ?, alasan_batal = ?
        WHERE id = ? AND id_fpm IS NULL");
    $stmt->bind_param('ssi', $user, $alasan, $tugas['id']);
    return $stmt->execute() ? null : 'Gagal membatalkan tugas.';
}

/**
 * Tautkan FPM yang baru disimpan ke tugas Asakai-nya. Dari tombol "Buat sekarang" form
 * membawa id_asakai; FPM yang dibuat lewat menu biasa dicocokkan otomatis ke tugas yang
 * belum selesai dengan jenis, plant, mesin & nama part yang sama (paling lama dulu).
 */
function tautkanFpmKeAsakai(mysqli $koneksi, int $idFpm, string $noForm, string $jenis, int $idAsakai, $plant, string $machine, string $namaPart): void
{
    if (!isset(ASAKAI_FPM_JENIS[$jenis]) || !siapkanTabelAsakaiFpm($koneksi)) {
        return;
    }

    if ($idAsakai > 0) {
        // Form dibuka langsung (tanpa lewat tombol) tetap dicatat sebagai tugas yang selesai.
        if (tandaiAsakaiPerluFpm($koneksi, $idAsakai, $jenis, 'langsung', '') !== null) {
            return;
        }
        $stmt = $koneksi->prepare("SELECT id FROM asakai_fpm_tugas WHERE id_asakai = ? AND jenis = ? AND id_fpm IS NULL");
        $stmt->bind_param('is', $idAsakai, $jenis);
    } else {
        $plant = asakaiFpmPlant($plant);
        $stmt = $koneksi->prepare("SELECT t.id FROM asakai_fpm_tugas t
            JOIN asakai_header h ON h.id_asakai = t.id_asakai
            WHERE t.jenis = ? AND t.plant = ? AND t.id_fpm IS NULL AND t.dibatalkan_at IS NULL
                AND h.machine = ? AND h.namapart = ?
            ORDER BY t.dibuat_at LIMIT 1");
        $stmt->bind_param('ssss', $jenis, $plant, $machine, $namaPart);
    }
    $stmt->execute();
    $tugas = $stmt->get_result()->fetch_assoc();
    if (!$tugas) {
        return;
    }

    $stmt = $koneksi->prepare("UPDATE asakai_fpm_tugas SET id_fpm = ?, no_form = ?, selesai_at = NOW() WHERE id = ?");
    $stmt->bind_param('isi', $idFpm, $noForm, $tugas['id']);
    $stmt->execute();
}

/**
 * Tugas yang belum selesai (untuk peringatan Staff IMP & reminder WA), urut paling kritis.
 * $plant '' = semua plant.
 */
function ambilTugasAsakaiFpmTertunda(mysqli $koneksi, string $plant = ''): array
{
    if (!siapkanTabelAsakaiFpm($koneksi)) {
        return [];
    }
    $plant = asakaiFpmPlant($plant);
    $q = $koneksi->query("SELECT t.*, h.tanggal, h.machine, h.namapart, h.type
        FROM asakai_fpm_tugas t
        JOIN asakai_header h ON h.id_asakai = t.id_asakai
        WHERE t.id_fpm IS NULL AND t.dibatalkan_at IS NULL"
        . ($plant !== '' ? " AND t.plant = '" . $koneksi->real_escape_string($plant) . "'" : '')
        . " ORDER BY t.dibuat_at");

    $hasil = [];
    while ($q && ($t = $q->fetch_assoc())) {
        $hasil[] = $t + asakaiFpmStatus($t);
    }
    $urutan = ['sp' => 0, 'warning' => 1, 'normal' => 2];
    usort($hasil, fn($a, $b) => [$urutan[$a['status']], -$a['hari']] <=> [$urutan[$b['status']], -$b['hari']]);
    return $hasil;
}

/**
 * Semua tugas (belum / sudah dibuat / dibatalkan) untuk menu "FPM dari Asakai", terbaru dulu.
 * $plant '' = semua plant.
 */
function ambilTugasAsakaiFpmSemua(mysqli $koneksi, string $plant = ''): array
{
    if (!siapkanTabelAsakaiFpm($koneksi)) {
        return [];
    }
    $plant = asakaiFpmPlant($plant);
    $q = $koneksi->query("SELECT t.*, h.tanggal, h.machine, h.namapart, h.type
        FROM asakai_fpm_tugas t
        JOIN asakai_header h ON h.id_asakai = t.id_asakai"
        . ($plant !== '' ? " WHERE t.plant = '" . $koneksi->real_escape_string($plant) . "'" : '')
        . " ORDER BY t.dibuat_at DESC, t.id DESC");

    $hasil = [];
    while ($q && ($t = $q->fetch_assoc())) {
        $hasil[] = $t + asakaiFpmStatus($t);
    }
    return $hasil;
}

/**
 * Badge menu sidebar "FPM dari Asakai": jumlah yang belum dibuat & warnanya
 * (merah kalau ada yang sudah SP, kuning kalau ada yang H+warning, biru kalau masih aman).
 */
function badgeAsakaiFpm(mysqli $koneksi, string $plant = ''): array
{
    $status = array_column(ambilTugasAsakaiFpmTertunda($koneksi, $plant), 'status');
    $warna = in_array('sp', $status, true) ? 'bg-danger' : (in_array('warning', $status, true) ? 'bg-warning text-dark' : 'bg-info text-dark');
    return ['jumlah' => count($status), 'warna' => $warna];
}

function asakaiFpmLabelAsakai(array $t): string
{
    return 'Asakai ' . trim($t['machine'] ?? '') . ' ' . monitoringFormatTgl(monitoringTgl($t['tanggal'] ?? ''));
}

/**
 * Catat SP untuk tugas yang terlambat (belum dibuat sampai H+sp, atau baru dibuat setelah
 * lewat batas) ke fpm_sp_pelanggaran - satu baris per tugas, pihak "Staff IMP Plant X".
 * id_fpm berisi id_asakai; tahap 'asakai_fpm_*' membedakannya dari SP FPM biasa.
 * Dipanggil dari hitungSpMonitoringApproval() (setiap sidebar & cron reminder_fpm.php).
 */
function catatSpAsakaiFpm(mysqli $koneksi): void
{
    static $sudah = false;
    if ($sudah || !siapkanTabelAsakaiFpm($koneksi) || !siapkanTabelSpFpm($koneksi)) {
        return;
    }
    $sudah = true;

    $q = $koneksi->query("SELECT t.*, h.tanggal, h.machine
        FROM asakai_fpm_tugas t
        JOIN asakai_header h ON h.id_asakai = t.id_asakai
        WHERE t.dibatalkan_at IS NULL
            AND (t.id_fpm IS NULL OR DATE(t.selesai_at) > DATE(t.dibuat_at))");

    $nilai = [];
    $e = fn($v) => "'" . $koneksi->real_escape_string((string) $v) . "'";
    while ($q && ($t = $q->fetch_assoc())) {
        // Yang sudah selesai dinilai pada tanggal FPM-nya dibuat, bukan hari ini.
        $s = asakaiFpmStatus(['id_fpm' => null] + $t, $t['selesai_at'] ? date('Y-m-d', strtotime($t['selesai_at'])) : null);
        if ($s['status'] !== 'sp') {
            continue;
        }
        $jenis = ASAKAI_FPM_JENIS[$t['jenis']] ?? null;
        if (!$jenis) {
            continue;
        }
        $nilai[] = '(' . implode(',', [
            $e(trim('Staff IMP ' . monitoringLabelPlant($t['plant']))), (int) $t['id_asakai'], $e(asakaiFpmLabelAsakai($t)),
            $e($jenis['tahap']), $e('Belum buat FPM ' . $jenis['label'] . ' dari Asakai'), $e($s['mulai']), $e($s['tgl_sp']),
        ]) . ')';
    }

    if ($nilai) {
        $koneksi->query("INSERT IGNORE INTO fpm_sp_pelanggaran
            (pihak, id_fpm, no_form, tahap, tahap_label, tgl_mulai, tgl_sp) VALUES " . implode(',', $nilai));
    }
}

/**
 * Data header Asakai untuk mengisi otomatis form pembuatan FPM ($jenis DOWN_TIME / NG).
 * null kalau Asakai tidak ditemukan.
 */
function asakaiFpmPrefill(mysqli $koneksi, int $idAsakai, string $jenis): ?array
{
    if ($idAsakai <= 0 || !isset(ASAKAI_FPM_JENIS[$jenis])) {
        return null;
    }
    $stmt = $koneksi->prepare("SELECT * FROM asakai_header WHERE id_asakai = ?");
    $stmt->bind_param('i', $idAsakai);
    $stmt->execute();
    $h = $stmt->get_result()->fetch_assoc();
    if (!$h) {
        return null;
    }

    $problem = asakaiFpmDaftarProblem($koneksi, $idAsakai, $jenis, $h);

    // Jenis Masalah: problem yang sudah ada di master memakai nama master (tidak dobel),
    // yang belum ada jadi opsi "dari Asakai" & masuk master saat FPM disimpan.
    siapkanKolomJenisMasalah($koneksi);
    $opsiMasalah = [];
    foreach ($problem as $p) {
        $master = jenisMasalahMasterSama($koneksi, $p);
        $opsiMasalah[$master ?? $p] = $master === null;
    }

    $tglProblem = monitoringTgl($h['tanggal_problem'] ?? '');
    return [
        'masalah_opsi'  => $opsiMasalah,
        'jenis_masalah' => (string) (array_key_first($opsiMasalah) ?? ''),
        'id_asakai'     => $idAsakai,
        'label'         => asakaiFpmLabelAsakai($h),
        'namapart'      => (string) $h['namapart'],
        'type'          => (string) $h['type'],
        'partnumber'    => (string) $h['partnumber'],
        'machine'       => (string) $h['machine'],
        'mold_no'       => (string) $h['mold_no'],
        'tgl_ditemukan' => $tglProblem ? date('d-m-Y', strtotime($tglProblem)) : '',
        'detail'        => mb_substr(implode('; ', $problem), 0, 255),
    ];
}

/**
 * Problem Asakai per jenis, sama dengan kolom Problem di form pembahasan Asakai: yang sudah
 * dibahas (asakai_analisa) kalau ada, kalau belum dari header Asakai. Maks 255 karakter.
 */
function asakaiFpmDaftarProblem(mysqli $koneksi, int $idAsakai, string $jenis, ?array $header = null): array
{
    $stmt = $koneksi->prepare("SELECT problem FROM asakai_analisa WHERE id_asakai = ? AND kategori = ? ORDER BY id_analisa");
    $stmt->bind_param('is', $idAsakai, $jenis);
    $stmt->execute();
    $problem = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'problem');

    if (!array_filter(array_map('trim', $problem))) {
        if ($header === null) {
            $stmt = $koneksi->prepare("SELECT downtime_problem, ng_problem FROM asakai_header WHERE id_asakai = ?");
            $stmt->bind_param('i', $idAsakai);
            $stmt->execute();
            $header = $stmt->get_result()->fetch_assoc() ?: [];
        }
        if ($jenis === 'DOWN_TIME') {
            $problem = explode("\n", (string) ($header['downtime_problem'] ?? ''));
        } else {
            $problem = [(string) ($header['ng_problem'] ?? '')];
            $stmt = $koneksi->prepare("SELECT deskripsi FROM asakai_ng_problem WHERE id_asakai = ? ORDER BY id_ng_problem");
            $stmt->bind_param('i', $idAsakai);
            $stmt->execute();
            $problem = array_merge($problem, array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'deskripsi'));
        }
    }

    $problem = array_map(fn($p) => mb_substr(trim(preg_replace('/\s+/', ' ', $p)), 0, 255), $problem);
    return array_values(array_unique(array_filter($problem, fn($p) => $p !== '')));
}

/**
 * Master jenis_masalah.masalah dulu VARCHAR(50) - problem Asakai bisa lebih panjang, jadi
 * dilebarkan ke 255 (sama dengan fpm_header.jenis_masalah). Dipanggil saat form FPM dari
 * Asakai dibuka (DDL jangan di dalam transaksi simpan FPM: memicu commit implisit).
 */
function siapkanKolomJenisMasalah(mysqli $koneksi): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }
    $sudah = true;
    $kolom = $koneksi->query("SHOW COLUMNS FROM jenis_masalah LIKE 'masalah'");
    $tipe = $kolom ? strtolower((string) ($kolom->fetch_assoc()['Type'] ?? '')) : '';
    if ($tipe !== '' && $tipe !== 'varchar(255)') {
        $koneksi->query("ALTER TABLE jenis_masalah MODIFY masalah VARCHAR(255) NOT NULL");
    }
}

/** Nama di master yang sama dengan $masalah (abaikan huruf besar/kecil & spasi), atau null. */
function jenisMasalahMasterSama(mysqli $koneksi, string $masalah): ?string
{
    $stmt = $koneksi->prepare("SELECT masalah FROM jenis_masalah WHERE LOWER(TRIM(masalah)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->bind_param('s', $masalah);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['masalah'] : null;
}

/**
 * Saat FPM dari Asakai disimpan: Jenis Masalah yang dipilih dari problem Asakai itu dan
 * belum ada di master ditambahkan ke master (kategori 'Asakai'), supaya lolos validasi
 * jenisMasalahTerdaftar(). Nilai lain (bukan problem Asakai tsb) tidak pernah ditambahkan.
 */
function daftarkanJenisMasalahDariAsakai(mysqli $koneksi, int $idAsakai, string $jenis, string $masalah): void
{
    $masalah = trim($masalah);
    if ($idAsakai <= 0 || $masalah === '' || jenisMasalahMasterSama($koneksi, $masalah) !== null
        || !in_array($masalah, asakaiFpmDaftarProblem($koneksi, $idAsakai, $jenis), true)) {
        return;
    }
    // Dipanggil di dalam transaksi simpan FPM, jadi kolom tidak dilebarkan di sini (DDL).
    // Kolom masih sempit -> jangan simpan terpotong; validasi master akan menolak dengan pesan.
    $kolom = $koneksi->query("SHOW COLUMNS FROM jenis_masalah LIKE 'masalah'");
    if ($kolom && preg_match('/\((\d+)\)/', (string) ($kolom->fetch_assoc()['Type'] ?? ''), $m) && mb_strlen($masalah) > (int) $m[1]) {
        return;
    }
    $stmt = $koneksi->prepare("INSERT INTO jenis_masalah (masalah, kategori) VALUES (?, 'Asakai')");
    $stmt->bind_param('s', $masalah);
    $stmt->execute();
}

/** Opsi tambahan di <select name="jenis_masalah">: problem Asakai yang belum ada di master. */
function renderOpsiJenisMasalahAsakai(?array $pf): void
{
    $baru = $pf ? array_keys(array_filter($pf['masalah_opsi'])) : [];
    if (!$baru) {
        return;
    }
    echo '<optgroup label="Dari pembahasan Asakai (otomatis masuk master saat disimpan)">';
    foreach ($baru as $m) {
        echo '<option value="' . htmlspecialchars($m) . '"' . asakaiFpmMasalahTerpilih($pf, $m) . '>' . htmlspecialchars($m) . '</option>';
    }
    echo '</optgroup>';
}

function asakaiFpmMasalahTerpilih(?array $pf, string $masalah): string
{
    return ($pf && $pf['jenis_masalah'] !== '' && $masalah === $pf['jenis_masalah']) ? ' selected' : '';
}

/**
 * Banner + script pengisi tanggal di form pembuatan FPM yang dibuka dari Asakai. Field lain
 * diisi langsung di HTML form (selected / value). Panggil di dalam <form>.
 */
function renderBannerAsakaiFpm(?array $pf): void
{
    if (!$pf) {
        return;
    }
    ?>
    <input type="hidden" name="id_asakai" value="<?= (int) $pf['id_asakai'] ?>">
    <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; border-radius:10px; padding:10px 14px; margin-bottom:18px; font-size:13.5px;">
        <i class="bi bi-link-45deg"></i> FPM ini dibuat dari <b><?= htmlspecialchars($pf['label']) ?></b>.
        Nama part, mesin, mold, jenis &amp; detail masalah dan tanggal masalah sudah diisi dari Asakai - cek lagi, lalu pilih Dept dan klik <b>Cek</b>.
        <?php if (array_filter($pf['masalah_opsi'])): ?>
            <div style="margin-top:4px; font-size:12.5px;">Jenis Masalah dari Asakai yang belum ada di master otomatis ditambahkan ke Master Problem saat FPM disimpan.</div>
        <?php endif; ?>
    </div>
    <?php if ($pf['tgl_ditemukan'] !== ''): ?>
        <script>
            // Diisi setelah flatpickr siap (diinisialisasi saat DOMContentLoaded).
            window.addEventListener('load', function() {
                var el = document.querySelector("input[name='tgl_ditemukan']");
                if (el && el._flatpickr) el._flatpickr.setDate(<?= json_encode($pf['tgl_ditemukan']) ?>, true, 'd-m-Y');
            });
        </script>
    <?php endif;
}

/**
 * Kotak peringatan tugas FPM Asakai yang belum dibuat (dashboard Staff IMP).
 * $plant '' = semua plant. Tidak menampilkan apa pun kalau tidak ada tugas tertunda.
 */
function renderPengingatAsakaiFpm(mysqli $koneksi, string $plant = '', string $halamanAsakai = 'form_pembahasan_asakai.php'): void
{
    $tugas = ambilTugasAsakaiFpmTertunda($koneksi, $plant);
    if (!$tugas) {
        return;
    }
    $adaSp = in_array('sp', array_column($tugas, 'status'), true);
    $adaWarning = in_array('warning', array_column($tugas, 'status'), true);
    $warna = $adaSp ? 'danger' : ($adaWarning ? 'warning' : 'info');
    ?>
    <div class="alert alert-<?= $warna ?>" style="font-size:14px">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill"></i>
            <?= count($tugas) ?> FPM dari pembahasan Asakai belum dibuat
            <a href="tugas_saya.php#asakai-fpm" class="ms-2 fw-normal small">Lihat di Tugas &amp; Approval &rsaquo;</a>
        </div>
        <div class="small mb-2">Buat FPM-nya paling lambat <?= appSettingInt('monitor_asakai_fpm_sp') - 1 ?> hari kerja setelah ditandai di pembahasan Asakai. Lewat dari itu tercatat <b>SP</b> untuk Staff IMP.</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($tugas as $t):
                $jenis = ASAKAI_FPM_JENIS[$t['jenis']] ?? ['label' => $t['jenis'], 'form' => ''];
                $ket = [
                    'sp'      => '<span class="badge bg-danger">SP</span> terlambat, tercatat SP sejak ' . monitoringFormatTgl($t['tgl_sp']),
                    'warning' => '<span class="badge bg-warning text-dark">H+' . $t['hari'] . '</span> batas akhir ' . monitoringFormatTgl($t['batas_akhir']) . ', setelah itu SP',
                    'normal'  => '<span class="badge bg-info text-dark">H+' . $t['hari'] . '</span> batas akhir ' . monitoringFormatTgl($t['batas_akhir']),
                ][$t['status']];
            ?>
                <li class="mb-1">
                    FPM <b><?= htmlspecialchars($jenis['label']) ?></b> -
                    <?= htmlspecialchars($t['machine'] . ' / ' . $t['namapart']) ?>
                    (Asakai <?= monitoringFormatTgl(monitoringTgl($t['tanggal'])) ?>) - <?= $ket ?>
                    <a href="<?= htmlspecialchars($jenis['form']) ?>?asakai=<?= (int) $t['id_asakai'] ?>" class="ms-1 fw-semibold">Buat FPM</a>
                    &middot; <a href="<?= htmlspecialchars($halamanAsakai) ?>?id=<?= (int) $t['id_asakai'] ?>">Lihat Asakai</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}
