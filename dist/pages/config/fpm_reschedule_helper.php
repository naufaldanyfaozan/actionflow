<?php
require_once __DIR__ . '/branding_helper.php';

/**
 * Reschedule due date countermeasure FPM (Corrective / Preventive / Update Dokumen).
 *
 * Kalau perbaikan tidak bisa selesai sesuai due date, Staff / Dept Head / Plant Head bisa
 * menunda due date baris itu maksimal FPM_RESCHEDULE_MAX kali (wajib isi alasan). Dipakai
 * di semua halaman upload perbaikan: edit_fpm_downtime.php (Staff & Dept Head, FPM Internal
 * & Down Time), plant_head/jawab_fpm.php, dan jawab_fpm_claim_machining.php (Staff, Dept
 * Head, Plant Head).
 *
 * Reschedule langsung disimpan lewat config/ajax_reschedule_fpm.php (tidak menunggu form
 * di-submit), supaya tidak bergantung cara simpan tiap halaman. Setiap reschedule:
 *  - dicatat di fpm_reschedule_log (+ due_date baris diganti),
 *  - dikirim WA ke Staff QA (FPM-Cast / Claim Machining) atau Staff IMP sesuai plant
 *    (FPM-D Down Time / FPM-N NG Internal),
 *  - muncul di menu Verifikasi Countermeasure Staff QA / Staff IMP (badge sidebar) sampai
 *    ditandai "sudah dibaca" (kolom dibaca_at, migration 2026_10_20).
 *
 * Di modal reschedule user juga bisa sekalian upload bukti perbaikan (opsional) - mis.
 * sudah overdue lalu di hari yang sama di-reschedule sekaligus diupload. File ditambahkan
 * ke bukti baris itu (maks FPM_BUKTI_AKSI_MAX_FILE), actual_date otomatis = tanggal upload,
 * status tetap OPEN, sehingga baris langsung muncul di Verifikasi Countermeasure Staff IMP
 * (FPM-D/FPM-N) / Staff QA (FPM-Cast) untuk diverifikasi.
 */

require_once __DIR__ . '/verifikasi_countermeasure_helper.php';
require_once __DIR__ . '/fpm_bukti_aksi_helper.php';
require_once __DIR__ . '/app_setting_helper.php';

// Diatur Admin IT (app_setting reschedule_maks), default 3.
define('FPM_RESCHEDULE_MAX', appSettingInt('reschedule_maks'));

function fpmRescheduleCfg(string $jenis): ?array
{
    $cfg = [
        'CORRECTIVE' => ['tabel' => 'fpm_corrective_action', 'kolomTeks' => 'tindakan', 'label' => 'Corrective Action', 'jenisFile' => 'corrective', 'prefix' => 'CORR'],
        'PREVENTIVE' => ['tabel' => 'fpm_preventive_action', 'kolomTeks' => 'tindakan', 'label' => 'Preventive Action', 'jenisFile' => 'preventive', 'prefix' => 'PREV'],
        'DOKUMEN'    => ['tabel' => 'fpm_review_dokumen', 'kolomTeks' => 'dokumen', 'label' => 'Update Dokumen', 'jenisFile' => 'dokumen', 'prefix' => 'DOC'],
    ];
    return $cfg[strtoupper($jenis)] ?? null;
}

/** FPM-Cast (Claim Machining) - sama dengan buildVerifikasiFpmFlowClause(). */
function fpmRescheduleIsClaimMach(array $header): bool
{
    return ($header['flow_type'] ?? '') === 'CLAIMMACH'
        || ($header['jenis_fpm'] ?? '') === 'Claim Machining'
        || strpos((string) ($header['no_form'] ?? ''), 'FPM-Cast/') === 0;
}

function fpmRescheduleTglValid(string $tgl): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $tgl);
    return $d && $d->format('Y-m-d') === $tgl;
}

function fpmRescheduleFormatTgl(?string $tgl): string
{
    if (empty($tgl) || $tgl === '0000-00-00') return '-';
    return date('d-m-Y', strtotime($tgl));
}

/**
 * prepare + bind + execute. Kalau gagal (mis. tabel/kolom fpm_reschedule_log di hosting belum
 * sesuai migration) melempar RuntimeException, bukan Fatal error "bind_param() on bool" yang
 * membuat seluruh halaman HTTP 500.
 */
function fpmRsStmt($koneksi, string $sql, string $types = '', ...$params): mysqli_stmt
{
    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Query reschedule gagal: ' . $koneksi->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Query reschedule gagal: ' . $stmt->error);
    }
    return $stmt;
}

/**
 * Log yang id_action-nya tidak lagi menunjuk baris FPM ini (dulu beberapa halaman menghapus
 * lalu meng-insert ulang baris, jadi id berganti) dicocokkan lewat teks countermeasure.
 */
function fpmRescheduleSqlCocok(string $tabel): string
{
    return "(r.id_action = ? OR (r.tindakan = ? AND (r.id_action IS NULL OR NOT EXISTS
        (SELECT 1 FROM $tabel t WHERE t.id = r.id_action AND t.id_fpm = r.id_fpm))))";
}

/**
 * Jumlah reschedule per baris FPM: key "JENIS:id" untuk log yang barisnya masih ada,
 * "JENIS|teks" untuk log yatim (lihat fpmRescheduleSqlCocok). Baca lewat fpmRescheduleJumlah().
 */
function fpmRescheduleCounts($koneksi, int $idFpm): array
{
    $counts = [];
    try {
        foreach (['CORRECTIVE', 'PREVENTIVE', 'DOKUMEN'] as $jenis) {
            $tabel = fpmRescheduleCfg($jenis)['tabel'];
            $res = fpmRsStmt($koneksi, "SELECT r.id_action, r.tindakan, (t.id IS NOT NULL) AS ada
                FROM fpm_reschedule_log r
                LEFT JOIN $tabel t ON t.id = r.id_action AND t.id_fpm = r.id_fpm
                WHERE r.id_fpm = ? AND r.jenis_action = ?", "is", $idFpm, $jenis)->get_result();
            while ($r = $res->fetch_assoc()) {
                $key = $r['ada'] ? $jenis . ':' . (int) $r['id_action'] : $jenis . '|' . $r['tindakan'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {
        error_log('fpmRescheduleCounts: ' . $e->getMessage());
    }
    return $counts;
}

function fpmRescheduleJumlah(array $counts, string $jenis, array $baris): int
{
    $jenis = strtoupper($jenis);
    $teks = (string) ($baris[fpmRescheduleCfg($jenis)['kolomTeks']] ?? '');
    return ($counts[$jenis . ':' . (int) ($baris['id'] ?? 0)] ?? 0) + ($counts[$jenis . '|' . $teks] ?? 0);
}

function fpmRescheduleLogs($koneksi, int $idFpm): array
{
    try {
        return fpmRsStmt($koneksi, "SELECT * FROM fpm_reschedule_log WHERE id_fpm = ? ORDER BY direschedule_at DESC, id DESC", "i", $idFpm)
            ->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        error_log('fpmRescheduleLogs: ' . $e->getMessage());
        return [];
    }
}

/**
 * null kalau user boleh me-reschedule baris ini, selain itu alasan tidak boleh.
 * Plant Head boleh semua baris; Staff / Dept Head hanya baris PIC dept-nya atau FPM
 * milik dept-nya; Staff QA boleh semua baris FPM Claim Machining (full-control); Staff IMP
 * boleh semua baris FPM-D / FPM-N (sering membantu Dept Head mengelola FPM-nya).
 */
function fpmRescheduleAlasanTidakBoleh(array $baris, array $header, string $level, string $dept): ?string
{
    if (strtoupper(trim((string) ($baris['status'] ?? ''))) === 'CLOSED') {
        return 'Countermeasure sudah CLOSED';
    }
    if (empty($baris['due_date']) || $baris['due_date'] === '0000-00-00') {
        return 'Due date belum diisi';
    }

    if ($level === 'Plant Head') {
        return null;
    }
    if ($level === 'Staff IMP') {
        return fpmRescheduleIsClaimMach($header) ? 'FPM Claim Machining dikelola Staff QA' : null;
    }
    if (!in_array($level, ['Staff', 'Dept Head'], true)) {
        return 'Reschedule hanya untuk Staff, Dept Head, Plant Head, dan Staff IMP';
    }

    $dept = strtoupper(trim($dept));
    if ($dept === 'QA' && fpmRescheduleIsClaimMach($header)) {
        return null;
    }

    $deptBoleh = array_map(fn($d) => strtoupper(trim((string) $d)), [
        $baris['pic'] ?? '',
        $header['dept'] ?? '',
        $header['internal_dept'] ?? '',
    ]);
    if ($dept !== '' && in_array($dept, $deptBoleh, true)) {
        return null;
    }

    return 'Hanya PIC (' . ($baris['pic'] ?? '-') . ') yang bisa reschedule';
}

/**
 * Simpan satu reschedule: catat log, ganti due_date baris, lalu kirim WA ke Staff QA/IMP.
 * $uploads (format ambilUploadBuktiAksi): bukti perbaikan yang diupload dari modal reschedule -
 * ditambahkan ke file baris & actual_date = hari ini. Opsional, kecuali due date baru = hari ini
 * (perbaikan selesai hari ini -> wajib bukti).
 *
 * @return array{ok: bool, pesan: string, due_date_baru?: string, jumlah?: int, file?: string, actual_date?: string, jumlah_upload?: int}
 */
function rescheduleCountermeasureFpm($koneksi, int $idFpm, string $jenis, int $idAction, string $dueBaru, string $alasan, string $oleh, string $level, string $dept, array $uploads = []): array
{
    $jenis = strtoupper(trim($jenis));
    $cfg = fpmRescheduleCfg($jenis);
    $dueBaru = trim($dueBaru);
    $alasan = trim($alasan);

    if (!$cfg || $idFpm <= 0 || $idAction <= 0) {
        return ['ok' => false, 'pesan' => 'Data countermeasure tidak valid.'];
    }
    if (!fpmRescheduleTglValid($dueBaru)) {
        return ['ok' => false, 'pesan' => 'Due date baru wajib diisi.'];
    }
    if ($alasan === '') {
        return ['ok' => false, 'pesan' => 'Alasan reschedule wajib diisi.'];
    }

    if ($dueBaru === date('Y-m-d') && !$uploads) {
        return ['ok' => false, 'pesan' => 'Due date baru hari ini: upload bukti perbaikan wajib diisi.'];
    }

    try {
        $header = fpmRsStmt($koneksi, "SELECT * FROM fpm_header WHERE id_fpm = ?", "i", $idFpm)->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }
    if (!$header) {
        return ['ok' => false, 'pesan' => 'FPM tidak ditemukan.'];
    }

    $koneksi->begin_transaction();
    try {
        // FOR UPDATE: dua orang reschedule baris yang sama bersamaan tidak bisa lewat batas maks.
        $baris = fpmRsStmt($koneksi, "SELECT * FROM {$cfg['tabel']} WHERE id = ? AND id_fpm = ? FOR UPDATE", "ii", $idAction, $idFpm)
            ->get_result()->fetch_assoc();
        if (!$baris) {
            throw new RuntimeException('Baris countermeasure tidak ditemukan. Muat ulang halaman lalu coba lagi.');
        }

        $tidakBoleh = fpmRescheduleAlasanTidakBoleh($baris, $header, $level, $dept);
        if ($tidakBoleh !== null) {
            throw new RuntimeException($tidakBoleh . '.');
        }

        $dueLama = $baris['due_date'];
        if ($dueBaru <= $dueLama) {
            throw new RuntimeException('Due date baru harus setelah due date sekarang (' . fpmRescheduleFormatTgl($dueLama) . ').');
        }

        $teks = (string) ($baris[$cfg['kolomTeks']] ?? '');

        $jumlah = (int) fpmRsStmt($koneksi, "SELECT COUNT(*) AS jumlah FROM fpm_reschedule_log r
            WHERE r.id_fpm = ? AND r.jenis_action = ? AND " . fpmRescheduleSqlCocok($cfg['tabel']), "isis", $idFpm, $jenis, $idAction, $teks)
            ->get_result()->fetch_assoc()['jumlah'];
        if ($jumlah >= FPM_RESCHEDULE_MAX) {
            throw new RuntimeException('Batas maksimal ' . FPM_RESCHEDULE_MAX . 'x reschedule untuk countermeasure ini sudah tercapai.');
        }

        // Bukti perbaikan dari modal: divalidasi & dipindahkan sebelum ada data yang ditulis,
        // supaya file yang ditolak (tipe/ukuran/kuota) tidak ikut menyimpan reschedule-nya.
        $fileUtama = (string) ($baris['file'] ?? '');
        $actualDate = (string) ($baris['actual_date'] ?? '');
        $fileTambahan = [];
        if ($uploads) {
            $jumlahFileLama = count(semuaFileBuktiAksi($koneksi, $cfg['jenisFile'], $baris));
            [$fileUtama, $fileTambahan] = prosesUploadBuktiAksi($uploads, $fileUtama, $jumlahFileLama, __DIR__ . '/../uploads/fpm_dt/', $cfg['prefix'], $cfg['label']);
            $actualDate = date('Y-m-d');
        }

        $pic = $baris['pic'] ?? null;
        fpmRsStmt($koneksi, "INSERT INTO fpm_reschedule_log
            (id_fpm, id_action, jenis_action, tindakan, due_date_lama, due_date_baru, alasan, pic, direschedule_oleh)
            VALUES (?,?,?,?,?,?,?,?,?)", "iisssssss", $idFpm, $idAction, $jenis, $teks, $dueLama, $dueBaru, $alasan, $pic, $oleh);

        $idLog = (int) $koneksi->insert_id;

        fpmRsStmt($koneksi, "UPDATE {$cfg['tabel']} SET due_date = ? WHERE id = ? AND id_fpm = ?", "sii", $dueBaru, $idAction, $idFpm);

        if ($uploads) {
            // Status tidak disentuh (tetap OPEN) - yang menutup hanya Staff IMP / QA lewat verifikasi.
            fpmRsStmt($koneksi, "UPDATE {$cfg['tabel']} SET file = ?, actual_date = ? WHERE id = ? AND id_fpm = ?", "ssii", $fileUtama, $actualDate, $idAction, $idFpm);
            simpanFileTambahanAksi($koneksi, $cfg['jenisFile'], $idAction, $fileTambahan);
        }

        // Staff IMP adalah penerima notifikasi FPM-D/FPM-N - reschedule oleh Staff IMP sendiri
        // langsung dianggap sudah dibaca (tidak perlu badge / WA ke dirinya).
        $olehStaffImp = ($level === 'Staff IMP');
        if ($olehStaffImp) {
            try {
                fpmRsStmt($koneksi, "UPDATE fpm_reschedule_log SET dibaca_at = NOW(), dibaca_oleh = ? WHERE id = ?", "si", $oleh, $idLog);
            } catch (Throwable $e) {
                error_log('reschedule Staff IMP tandai dibaca: ' . $e->getMessage());
            }
        }

        $koneksi->commit();
    } catch (Throwable $e) {
        $koneksi->rollback();
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }

    $jumlah++;
    if (!$olehStaffImp) notifRescheduleFpm($header, [
        'label'    => $cfg['label'],
        'teks'     => $teks,
        'pic'      => (string) $pic,
        'due_lama' => $dueLama,
        'due_baru' => $dueBaru,
        'alasan'   => $alasan,
        'jumlah'   => $jumlah,
        'oleh'     => $oleh,
        'level'    => $level,
        'dept'     => $dept,
        'upload'   => count($uploads),
        'actual'   => $actualDate,
    ]);

    $pesan = 'Due date berhasil di-reschedule ke ' . fpmRescheduleFormatTgl($dueBaru) . " ($jumlah/" . FPM_RESCHEDULE_MAX . ').';
    if ($uploads) {
        $pesan .= ' ' . count($uploads) . ' file bukti tersimpan (actual ' . fpmRescheduleFormatTgl($actualDate) . ') dan menunggu verifikasi '
            . (fpmRescheduleIsClaimMach($header) ? 'Staff QA' : 'Staff IMP') . '.';
    }

    return [
        'ok'            => true,
        'pesan'         => $pesan,
        'due_date_baru' => $dueBaru,
        'jumlah'        => $jumlah,
        'file'          => $fileUtama,
        'actual_date'   => $actualDate,
        'jumlah_upload' => count($uploads),
    ];
}

/** WA ke Staff QA (FPM-Cast) atau Staff IMP sesuai plant (FPM-D / FPM-N). */
function notifRescheduleFpm(array $header, array $it): void
{
    include __DIR__ . '/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    $plant = trim(str_ireplace('Plant', '', (string) ($header['plant'] ?? '')));
    if (fpmRescheduleIsClaimMach($header)) {
        $waTujuan = $waStaffQA ?? [];
        $penerima = 'Staff QA';
    } elseif ($plant === '1') {
        $waTujuan = $waStaffIMP1 ?? [];
        $penerima = 'Staff IMP';
    } elseif ($plant === '3') {
        $waTujuan = $waStaffIMP3 ?? [];
        $penerima = 'Staff IMP';
    } else {
        $waTujuan = array_merge($waStaffIMP1 ?? [], $waStaffIMP3 ?? []);
        $penerima = 'Staff IMP';
    }
    $waTujuan = array_unique(array_filter($waTujuan));
    if (!$waTujuan) {
        return;
    }

    $pesan = "*FPM " . strtoupper($header['jenis_fpm'] ?? '') . " - COUNTERMEASURE DI-RESCHEDULE*\n"
        . "No FPM: " . (($header['no_form'] ?? '') ?: ($header['no_reg_fpm'] ?? '-')) . "\n"
        . "Nama Part: " . ($header['nama_part'] ?? '-') . "\n\n"
        . $it['label'] . ": " . $it['teks'] . "\n"
        . "PIC: " . ($it['pic'] !== '' ? $it['pic'] : '-') . "\n"
        . "Due date lama: " . fpmRescheduleFormatTgl($it['due_lama']) . "\n"
        . "Due date baru: *" . fpmRescheduleFormatTgl($it['due_baru']) . "*\n"
        . "Reschedule ke: " . $it['jumlah'] . "/" . FPM_RESCHEDULE_MAX . "\n"
        . "Alasan: " . $it['alasan'] . "\n"
        . (!empty($it['upload'])
            ? "Bukti perbaikan: *" . $it['upload'] . " file diupload* (actual " . fpmRescheduleFormatTgl($it['actual']) . ") - mohon diverifikasi\n"
            : "")
        . "Oleh: " . $it['oleh'] . " (" . trim($it['level'] . ' ' . $it['dept']) . ")\n\n"
        // Reschedule FPM-D / FPM-N tidak masuk menu Verifikasi Staff IMP - hanya bukti upload-nya.
        . ($penerima === 'Staff QA' || !empty($it['upload'])
            ? "Detail ada di menu *Verifikasi Countermeasure* " . brandNama() . "."
            : "Detail ada di *History Reschedule* halaman countermeasure FPM ini di " . brandNama() . ".");

    if (!kirimWA(implode(',', $waTujuan), $pesan)) {
        error_log("Gagal kirim WA reschedule FPM #" . ($header['id_fpm'] ?? '?') . " ke $penerima");
    }
}

/**
 * Halaman jawab Claim Machining menghapus lalu meng-insert ulang semua baris saat simpan
 * (id baris berganti) - log reschedule dipindah ke id baru supaya hitungan maks tidak reset.
 * Catatan revisi baris itu dan file bukti ke-2 dst. (bisa dari upload di modal reschedule)
 * ikut dipindah supaya tidak yatim.
 */
function pindahkanRescheduleLogFpm($koneksi, int $idFpm, string $jenis, int $idLama, int $idBaru): void
{
    if ($idLama <= 0 || $idBaru <= 0 || $idLama === $idBaru) {
        return;
    }
    try {
        fpmRsStmt($koneksi, "UPDATE fpm_reschedule_log SET id_action = ? WHERE id_fpm = ? AND jenis_action = ? AND id_action = ?",
            "iisi", $idBaru, $idFpm, strtoupper($jenis), $idLama);
    } catch (Throwable $e) {
        // Jangan gagalkan simpan jawaban FPM hanya karena log reschedule tidak bisa dipindah.
        error_log('pindahkanRescheduleLogFpm: ' . $e->getMessage());
    }

    $cfg = fpmRescheduleCfg($jenis);
    if (!$cfg) {
        return;
    }
    // Catatan revisi (Minta Revisi Staff IMP / Staff QA) juga menunjuk id baris - tanpa ini
    // riwayat revisinya hilang begitu Staff QA menyimpan status CLOSED.
    try {
        fpmRsStmt($koneksi, "UPDATE fpm_catatan_revisi SET ref_id = ? WHERE id_fpm = ? AND ref_table = ? AND ref_id = ?",
            "iisi", $idBaru, $idFpm, $cfg['tabel'], $idLama);
    } catch (Throwable $e) {
        error_log('pindahkanRescheduleLogFpm catatan revisi: ' . $e->getMessage());
    }
    try {
        $meta = metaFileTambahanAksi($cfg['jenisFile']);
        fpmRsStmt($koneksi, "UPDATE {$meta['table']} SET {$meta['kolom']} = ? WHERE {$meta['kolom']} = ?", "ii", $idBaru, $idLama);
    } catch (Throwable $e) {
        error_log('pindahkanRescheduleLogFpm file tambahan: ' . $e->getMessage());
    }
}

/** Reschedule yang belum ditandai dibaca, untuk Staff QA ($claimMach) atau Staff IMP ($plant). */
function ambilRescheduleBelumDibaca($koneksi, bool $claimMach, string $plant = ''): array
{
    $plantClause = buildVerifikasiPlantClause($koneksi, $plant, 'h.plant');
    $flowClause = buildVerifikasiFpmFlowClause($claimMach);

    $q = mysqli_query($koneksi, "SELECT r.*, h.no_form, h.no_reg_fpm, h.plant, h.jenis_fpm, h.nama_part
        FROM fpm_reschedule_log r
        JOIN fpm_header h ON h.id_fpm = r.id_fpm
        WHERE r.dibaca_at IS NULL $plantClause $flowClause
        ORDER BY r.direschedule_at DESC, r.id DESC");

    return $q ? mysqli_fetch_all($q, MYSQLI_ASSOC) : [];
}

function hitungRescheduleBelumDibaca($koneksi, bool $claimMach, string $plant = ''): int
{
    return count(ambilRescheduleBelumDibaca($koneksi, $claimMach, $plant));
}

/** $idLog 0 = tandai semua yang tampil untuk peran itu. */
function tandaiRescheduleDibaca($koneksi, int $idLog, string $oleh, bool $claimMach, string $plant = ''): array
{
    $ids = [];
    foreach (ambilRescheduleBelumDibaca($koneksi, $claimMach, $plant) as $r) {
        if ($idLog === 0 || (int) $r['id'] === $idLog) {
            $ids[] = (int) $r['id'];
        }
    }
    if (!$ids) {
        return ['ok' => false, 'pesan' => 'Data reschedule tidak ditemukan atau sudah ditandai dibaca.'];
    }

    try {
        $stmt = fpmRsStmt($koneksi, "UPDATE fpm_reschedule_log SET dibaca_at = NOW(), dibaca_oleh = ?
            WHERE dibaca_at IS NULL AND id IN (" . implode(',', $ids) . ")", "s", $oleh);
    } catch (Throwable $e) {
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }

    return ['ok' => true, 'pesan' => $stmt->affected_rows . ' reschedule ditandai sudah dibaca.'];
}

/**
 * Seksi "Countermeasure Di-reschedule" di halaman Verifikasi Countermeasure Staff IMP / QA.
 * Form-nya POST tandai_reschedule_dibaca + id_reschedule (0 = semua) ke halaman itu sendiri.
 * Pakai class section-heading / table-wrapper / btn milik halaman verifikasi.
 */
function renderSeksiRescheduleBelumDibaca(array $rows, string $linkDetail): string
{
    ob_start();
    ?>
    <div class="section-heading">
        <h3>Countermeasure Di-reschedule</h3>
        <span class="section-count <?= $rows ? '' : 'zero' ?>" style="<?= $rows ? 'background:#ffedd5; color:#c2410c;' : '' ?>">
            <?= count($rows) ?> belum dibaca
        </span>
        <?php if ($rows): ?>
            <form method="POST" style="margin-left:auto;" onsubmit="return confirm('Tandai semua reschedule sudah dibaca?');">
                <input type="hidden" name="id_reschedule" value="0">
                <button type="submit" name="tandai_reschedule_dibaca" value="1" class="btn" style="background:#f1f5f9;">Tandai semua sudah dibaca</button>
            </form>
        <?php endif; ?>
    </div>
    <p style="color:#6b7280; font-size:12.5px; margin:-6px 0 10px;">
        Due date countermeasure yang ditunda Staff / Dept Head / Plant Head (maks <?= FPM_RESCHEDULE_MAX ?>x per countermeasure).
        Tandai sudah dibaca setelah dicek.
    </p>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No Form</th>
                    <th>Countermeasure</th>
                    <th>Due Date Lama</th>
                    <th>Due Date Baru</th>
                    <th>Alasan</th>
                    <th>Oleh</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php $cfg = fpmRescheduleCfg($r['jenis_action']); ?>
                        <tr>
                            <td><?= htmlspecialchars(($r['no_form'] ?? '') ?: ($r['no_reg_fpm'] ?? '')) ?><br><small><?= htmlspecialchars(trim(($r['jenis_fpm'] ?? '') . ' - ' . ($r['nama_part'] ?? ''), ' -')) ?></small></td>
                            <td><b><?= htmlspecialchars($cfg['label'] ?? $r['jenis_action']) ?></b><br><?= nl2br(htmlspecialchars($r['tindakan'])) ?><br><small>PIC: <?= htmlspecialchars($r['pic'] ?? '-') ?></small></td>
                            <td style="text-align:center;"><?= fpmRescheduleFormatTgl($r['due_date_lama']) ?></td>
                            <td style="text-align:center;"><b><?= fpmRescheduleFormatTgl($r['due_date_baru']) ?></b></td>
                            <td><?= nl2br(htmlspecialchars($r['alasan'])) ?></td>
                            <td><?= htmlspecialchars($r['direschedule_oleh']) ?><br><small style="color:#6b7280;"><?= date('d-m-Y H:i', strtotime($r['direschedule_at'])) ?></small></td>
                            <td style="text-align:center; white-space:nowrap;">
                                <a href="<?= htmlspecialchars($linkDetail) . (int) $r['id_fpm'] ?>" class="btn btn-verify">Lihat FPM</a>
                                <form method="POST" style="margin-top:6px;">
                                    <input type="hidden" name="id_reschedule" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" name="tandai_reschedule_dibaca" value="1" class="btn" style="background:#dcfce7; color:#166534;">Tandai sudah dibaca</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-hint">Tidak ada reschedule baru.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Tombol "Reschedule (n/3)" untuk satu baris - taruh di sel Due Date (JS mencari
 * input[type=date] di sel yang sama). Baris baru (belum tersimpan) tidak diberi tombol.
 */
function renderTombolRescheduleFpm(string $jenis, array $baris, array $header, array $counts, string $level, string $dept): string
{
    $jenis = strtoupper($jenis);
    $id = (int) ($baris['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }

    $jumlah = fpmRescheduleJumlah($counts, $jenis, $baris);
    $tidakBoleh = fpmRescheduleAlasanTidakBoleh($baris, $header, $level, $dept);
    if ($tidakBoleh === null && $jumlah >= FPM_RESCHEDULE_MAX) {
        $tidakBoleh = 'Maksimal ' . FPM_RESCHEDULE_MAX . 'x reschedule tercapai';
    }

    return '<div class="fpm-rs-box" data-jenis="' . $jenis . '" data-id="' . $id . '" data-jumlah="' . $jumlah . '"'
        . ' data-due="' . htmlspecialchars((string) ($baris['due_date'] ?? '')) . '">'
        . '<button type="button" class="fpm-rs-btn" onclick="fpmRsBuka(this)"'
        . ($tidakBoleh !== null ? ' disabled title="' . htmlspecialchars($tidakBoleh) . '"' : '') . '>'
        . '&#128260; Reschedule (<span class="fpm-rs-jumlah">' . $jumlah . '</span>/' . FPM_RESCHEDULE_MAX . ')</button>'
        . '<div class="fpm-rs-info"></div>'
        . '</div>';
}

function renderHistoryRescheduleFpm(array $logs, string $judulClass = 'section-title'): string
{
    if (!$logs) {
        return '';
    }

    $html = '<div class="' . htmlspecialchars($judulClass) . '">History Reschedule</div>'
        . '<table class="fpm-rs-history"><tr>'
        . '<th>Jenis</th><th>Countermeasure</th><th>Due Date Lama</th><th>Due Date Baru</th>'
        . '<th>Alasan</th><th>PIC</th><th>Oleh</th><th>Tanggal</th></tr>';

    foreach ($logs as $log) {
        $cfg = fpmRescheduleCfg($log['jenis_action']);
        $html .= '<tr>'
            . '<td>' . htmlspecialchars($cfg['label'] ?? $log['jenis_action']) . '</td>'
            . '<td style="text-align:left;">' . nl2br(htmlspecialchars($log['tindakan'])) . '</td>'
            . '<td>' . fpmRescheduleFormatTgl($log['due_date_lama']) . '</td>'
            . '<td>' . fpmRescheduleFormatTgl($log['due_date_baru']) . '</td>'
            . '<td style="text-align:left;">' . nl2br(htmlspecialchars($log['alasan'])) . '</td>'
            . '<td>' . htmlspecialchars($log['pic'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($log['direschedule_oleh']) . '</td>'
            . '<td>' . date('d-m-Y H:i', strtotime($log['direschedule_at'])) . '</td>'
            . '</tr>';
    }

    return $html . '</table>';
}

/**
 * CSS + JS tombol reschedule (SweetAlert2). Panggil sekali per halaman, setelah markup.
 * $endpoint relatif dari halaman, mis. '../config/ajax_reschedule_fpm.php'.
 */
function renderAsetRescheduleFpm(int $idFpm, string $endpoint = '../config/ajax_reschedule_fpm.php', bool $muatSwal = true): string
{
    ob_start();
    ?>
    <style>
        .fpm-rs-btn {
            margin-top: 6px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            border-radius: 6px;
            background: #f59e0b;
            color: #fff;
            cursor: pointer;
            white-space: nowrap;
        }
        .fpm-rs-btn:hover { background: #d97706; }
        .fpm-rs-btn:disabled { background: #d1d5db; color: #6b7280; cursor: not-allowed; }
        .fpm-rs-info {
            display: none;
            margin-top: 4px;
            padding: 4px 6px;
            font-size: 11px;
            text-align: left;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            border-radius: 6px;
        }
        .fpm-rs-history { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
        .fpm-rs-history th, .fpm-rs-history td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; }
        .fpm-rs-history th { background: #fff7ed; color: #9a3412; }
    </style>
    <?php if ($muatSwal): ?>
        <script>
            if (typeof Swal === 'undefined') {
                document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"><\/script>');
            }
        </script>
    <?php endif; ?>
    <script>
        const FPM_RS_ID_FPM = <?= (int) $idFpm ?>;
        const FPM_RS_ENDPOINT = <?= json_encode($endpoint) ?>;
        const FPM_RS_MAX = <?= FPM_RESCHEDULE_MAX ?>;

        function fpmRsFormatTgl(tgl) {
            const p = (tgl || '').split('-');
            return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0] : tgl;
        }

        function fpmRsEsc(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function fpmRsHariIni() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // Setelah bukti diupload dari modal, input form halaman ini (old_file / actual_date /
        // old_actual_date) disamakan dengan DB supaya klik Simpan/Submit berikutnya tidak
        // menimpa file & actual date tersebut dengan nilai saat halaman dibuka. Dicari lewat
        // urutan index (sama seperti server membaca $_POST[..][$i]) karena di beberapa halaman
        // hidden input-nya tidak berada di dalam <td>.
        function fpmRsSinkronForm(box, res) {
            const row = box.closest('tr');
            const oldFile = row ? row.querySelector('input[name$="[old_file][]"]') : null;
            if (!oldFile || !oldFile.form) return;

            const form = oldFile.form;
            const idx = Array.from(form.querySelectorAll('[name="' + oldFile.name + '"]')).indexOf(oldFile);
            oldFile.value = res.file;
            ['actual_date', 'old_actual_date'].forEach(kolom => {
                const el = form.querySelectorAll('[name="' + oldFile.name.replace('[old_file]', '[' + kolom + ']') + '"]')[idx];
                if (el) el.value = res.actual_date;
            });

            const inputBukti = row.querySelector('input.input-bukti-aksi');
            if (inputBukti) {
                const sisa = Math.max(0, parseInt(inputBukti.dataset.sisa || '0', 10) - res.jumlah_upload);
                inputBukti.dataset.sisa = sisa;
                if (sisa === 0) inputBukti.disabled = true;
            }
        }

        function fpmRsBuka(btn) {
            const box = btn.closest('.fpm-rs-box');
            const cell = box.closest('td');
            const dateInput = cell ? cell.querySelector('input[type="date"]') : null;
            const dueSekarang = box.dataset.due;
            const jumlah = parseInt(box.dataset.jumlah || '0', 10);
            const hariIni = fpmRsHariIni();

            Swal.fire({
                title: 'Reschedule Due Date',
                html: '<div style="text-align:left;font-size:13px;">' +
                    '<p style="margin:0 0 10px;color:#6b7280;">Due date sekarang: <b>' + fpmRsFormatTgl(dueSekarang) + '</b><br>' +
                    'Sisa reschedule: <b>' + (FPM_RS_MAX - jumlah) + 'x</b> dari maks ' + FPM_RS_MAX + 'x. Reschedule langsung tersimpan &amp; dikabarkan ke Staff IMP/QA.</p>' +
                    '<label style="font-weight:600;display:block;margin-bottom:4px;">Due Date Baru</label>' +
                    '<input type="date" id="fpmRsTgl" class="swal2-input" style="margin:0 0 12px;width:100%;" min="' + dueSekarang + '">' +
                    '<label style="font-weight:600;display:block;margin-bottom:4px;">Alasan Reschedule</label>' +
                    '<textarea id="fpmRsAlasan" class="swal2-textarea" style="margin:0 0 12px;width:100%;" placeholder="Jelaskan kenapa perbaikan tidak bisa selesai sesuai due date..."></textarea>' +
                    '<label style="font-weight:600;display:block;margin-bottom:4px;">Upload Bukti Perbaikan <span class="fpm-rs-sifat" style="font-weight:400;color:#6b7280;">(opsional)</span></label>' +
                    '<input type="file" id="fpmRsFile" multiple accept="image/*,.pdf,video/*,.doc,.docx,.xls,.xlsx" data-bukti-ui data-sisa="3" style="width:100%;font-size:13px;">' +
                    '<small style="display:block;color:#6b7280;margin-top:2px;">Maks 3 file per countermeasure (termasuk file yang sudah ada), video maks 10MB. Actual date otomatis = tanggal upload, lalu bukti masuk Verifikasi Countermeasure Staff IMP/QA.</small>' +
                    '<small id="fpmRsInfoWajib" style="display:none;color:#b45309;margin-top:8px;">Due date baru = hari ini, jadi bukti perbaikan wajib diupload.</small>' +
                    '</div>',
                didOpen: () => {
                    // Kuota file modal = sisa kuota baris ini; tampil sebagai tombol + / ✕
                    // (config/upload_bukti_ui_helper.php) kalau halaman memuatnya.
                    const fileEl = document.getElementById('fpmRsFile');
                    const inputBaris = box.closest('tr') ? box.closest('tr').querySelector('input.input-bukti-aksi') : null;
                    if (inputBaris) fileEl.dataset.sisa = inputBaris.dataset.sisa || '0';
                    if (window.BuktiUpload) window.BuktiUpload.init(fileEl);

                    const tglEl = document.getElementById('fpmRsTgl');
                    // Due date baru = hari ini berarti perbaikan selesai hari ini -> bukti wajib.
                    tglEl.addEventListener('change', () => {
                        const wajib = tglEl.value === hariIni;
                        const sifat = document.querySelector('.fpm-rs-sifat');
                        sifat.textContent = wajib ? '(wajib)' : '(opsional)';
                        sifat.style.color = wajib ? '#dc2626' : '#6b7280';
                        document.getElementById('fpmRsInfoWajib').style.display = wajib ? 'block' : 'none';
                    });
                },
                confirmButtonText: 'Simpan Reschedule',
                confirmButtonColor: '#f59e0b',
                showCancelButton: true,
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                allowOutsideClick: () => !Swal.isLoading(),
                preConfirm: () => {
                    const tgl = document.getElementById('fpmRsTgl').value;
                    const alasan = document.getElementById('fpmRsAlasan').value.trim();
                    if (!tgl) {
                        Swal.showValidationMessage('Due date baru wajib diisi');
                        return false;
                    }
                    if (tgl <= dueSekarang) {
                        Swal.showValidationMessage('Due date baru harus setelah ' + fpmRsFormatTgl(dueSekarang));
                        return false;
                    }
                    if (!alasan) {
                        Swal.showValidationMessage('Alasan reschedule wajib diisi');
                        return false;
                    }
                    const files = Array.from(document.getElementById('fpmRsFile').files);
                    if (files.length > 3) {
                        Swal.showValidationMessage('Maksimal 3 file bukti per countermeasure');
                        return false;
                    }
                    if (tgl === hariIni && !files.length) {
                        Swal.showValidationMessage('Due date baru hari ini: upload bukti perbaikan wajib diisi');
                        return false;
                    }

                    const fd = new FormData();
                    fd.append('id_fpm', FPM_RS_ID_FPM);
                    fd.append('jenis', box.dataset.jenis);
                    fd.append('id_action', box.dataset.id);
                    fd.append('due_date_baru', tgl);
                    fd.append('alasan', alasan);
                    files.forEach(f => fd.append('bukti[0][]', f));

                    return fetch(FPM_RS_ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(r => r.json().catch(() => { throw new Error('Respon server tidak valid (HTTP ' + r.status + ')'); }))
                        .then(res => {
                            if (!res.ok) throw new Error(res.pesan || 'Gagal menyimpan reschedule');
                            return { res, tgl, alasan };
                        })
                        .catch(err => {
                            Swal.showValidationMessage(err.message || 'Gagal menghubungi server');
                            return false;
                        });
                }
            }).then(result => {
                if (!result.isConfirmed || !result.value) return;
                const { res, tgl, alasan } = result.value;

                box.dataset.due = res.due_date_baru;
                box.dataset.jumlah = res.jumlah;
                if (dateInput) dateInput.value = res.due_date_baru;
                box.querySelector('.fpm-rs-jumlah').textContent = res.jumlah;
                if (res.jumlah >= FPM_RS_MAX) {
                    btn.disabled = true;
                    btn.title = 'Maksimal ' + FPM_RS_MAX + 'x reschedule tercapai';
                }

                const info = box.querySelector('.fpm-rs-info');
                info.style.display = 'block';
                info.innerHTML = '&#128260; Direschedule: ' + fpmRsFormatTgl(dueSekarang) + ' &rarr; <b>' + fpmRsFormatTgl(tgl) + '</b><br>Alasan: ' + fpmRsEsc(alasan);

                if (res.jumlah_upload > 0) {
                    fpmRsSinkronForm(box, res);
                    info.innerHTML += '<br>&#128206; ' + res.jumlah_upload + ' file bukti diupload, actual <b>' + fpmRsFormatTgl(res.actual_date) + '</b> - menunggu verifikasi';
                }

                Swal.fire({ icon: 'success', title: 'Reschedule tersimpan', text: res.pesan, timer: res.jumlah_upload > 0 ? 5000 : 2500, showConfirmButton: false });
            });
        }
    </script>
    <?php
    return ob_get_clean();
}
