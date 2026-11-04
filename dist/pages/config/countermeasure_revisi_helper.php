<?php
require_once __DIR__ . '/branding_helper.php';

/**
 * Catatan revisi countermeasure dari Staff IMP ke PIC (Dept Head/Staff dept tujuan).
 *
 * Saat verifikasi countermeasure (FPM: update_countermeasure.php, ASAKAI: update_asakai.php)
 * Staff IMP bisa menandai satu baris countermeasure "perlu revisi" - mis. PIC salah upload
 * foto. Catatan disimpan di fpm_catatan_revisi / asakai_catatan_revisi (migration
 * 2026_10_09), PIC dikabari lewat WA + badge menu "Permintaan Revisi", dan PIC bisa
 * langsung upload ulang dari halaman itu.
 *
 * Status sebuah catatan (dihitung, bukan disimpan):
 *  - MENUNGGU   : PIC belum menindaklanjuti.
 *  - DIPERBAIKI : PIC sudah upload ulang (file baris berbeda dari snapshot saat catatan
 *                 dikirim - jadi upload lewat form lama pun ikut terhitung) atau PIC
 *                 menandai sudah ditindaklanjuti. Menunggu dicek ulang Staff IMP.
 *  - SELESAI    : baris countermeasure sudah CLOSED oleh Staff IMP (atau barisnya dihapus).
 * Status baris countermeasure sendiri tidak diubah PIC - CLOSED tetap hanya oleh Staff IMP.
 *
 * Staff QA memakai alur yang sama untuk FPM Claim Machining (FPM-Cast) lewat
 * staff/update_countermeasure_qa.php: catatannya disimpan dengan role 'Staff QA', notif
 * "sudah diperbaiki" dikirim ke Staff QA, dan hanya muncul di Verifikasi Countermeasure QA.
 */

const CM_REVISI_ROLE_IMP = 'Staff IMP';
const CM_REVISI_ROLE_QA = 'Staff QA';

/** Peminta revisi sebuah catatan: 'Staff QA' atau 'Staff IMP' (termasuk catatan lama tanpa role). */
function cmRevisiPeminta(array $row): string
{
    return ($row['role'] ?? '') === CM_REVISI_ROLE_QA ? CM_REVISI_ROLE_QA : CM_REVISI_ROLE_IMP;
}

/** Gabungan peminta beberapa catatan, mis. "Staff IMP / Staff QA". */
function cmRevisiPemintaDari(array $rows): string
{
    $peminta = array_unique(array_map('cmRevisiPeminta', $rows));
    return $peminta ? implode(' / ', $peminta) : CM_REVISI_ROLE_IMP;
}

const CM_REVISI_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];

/**
 * Konfigurasi per sumber: tabel catatan, kolom induk, tabel baris yang boleh dirujuk
 * beserta primary key, kolom deskripsi dan kolom file-nya.
 */
function cmRevisiConfig(string $sumber): array
{
    if ($sumber === 'ASAKAI') {
        return [
            'tabel'     => 'asakai_catatan_revisi',
            'kolomInduk' => 'id_asakai',
            'uploadDir' => __DIR__ . '/../uploads/asakai/',
            'uploadUrl' => '../uploads/asakai/',
            'refs' => [
                'asakai_countermeasure_downtime' => ['pk' => 'id_cm_dt', 'desk' => 'deskripsi', 'label' => 'Countermeasure Down Time', 'prefix' => 'DT'],
                'asakai_countermeasure_ng'       => ['pk' => 'id_cm_ng', 'desk' => 'deskripsi', 'label' => 'Countermeasure NG', 'prefix' => 'NG'],
            ],
        ];
    }

    return [
        'tabel'     => 'fpm_catatan_revisi',
        'kolomInduk' => 'id_fpm',
        'uploadDir' => __DIR__ . '/../uploads/fpm_dt/',
        'uploadUrl' => '../uploads/fpm_dt/',
        'refs' => [
            'fpm_corrective_action' => ['pk' => 'id', 'desk' => 'tindakan', 'label' => 'Corrective Action', 'prefix' => 'CORR'],
            'fpm_preventive_action' => ['pk' => 'id', 'desk' => 'tindakan', 'label' => 'Preventive Action', 'prefix' => 'PREV'],
            'fpm_review_dokumen'    => ['pk' => 'id', 'desk' => 'dokumen', 'label' => 'Update Dokumen', 'prefix' => 'DOC'],
        ],
    ];
}

// Nama jenis yang dipakai helper bukti FPM (fpm_bukti_aksi_helper) / Asakai (asakai_bukti_helper).
const CM_REVISI_JENIS_FPM = [
    'fpm_corrective_action' => 'corrective',
    'fpm_preventive_action' => 'preventive',
    'fpm_review_dokumen'    => 'dokumen',
];
const CM_REVISI_JENIS_ASAKAI = [
    'asakai_countermeasure_downtime' => 'dt',
    'asakai_countermeasure_ng'       => 'ng',
];

/**
 * Semua file baris untuk ditampilkan: FPM = file utama + file tambahan (*_files), ASAKAI =
 * file1..file3. Beda dengan cmRevisiFileBaris (hanya kolom utama, dipakai untuk snapshot).
 */
function cmRevisiSemuaFile($koneksi, string $sumber, array $row): array
{
    $files = cmRevisiFileBaris($sumber, $row);
    $jenis = CM_REVISI_JENIS_FPM[$row['ref_table'] ?? ''] ?? null;
    if ($sumber === 'FPM' && $jenis !== null && !empty($row['ref_id'])) {
        require_once __DIR__ . '/fpm_lampiran_helper.php';
        $files = array_merge($files, ambilFileTambahanAksi($koneksi, $jenis, (int) $row['ref_id']));
    }
    return $files;
}

/** Daftar file sebuah baris countermeasure (FPM 1 kolom `file`, ASAKAI file1..file3). */
function cmRevisiFileBaris(string $sumber, array $baris): array
{
    $kolom = $sumber === 'ASAKAI' ? ['file1', 'file2', 'file3'] : ['file'];
    $files = [];
    foreach ($kolom as $k) {
        if (!empty($baris[$k])) {
            $files[] = $baris[$k];
        }
    }
    return $files;
}

function cmRevisiAmbilBaris($koneksi, string $sumber, string $refTable, int $refId): ?array
{
    $cfg = cmRevisiConfig($sumber);
    if (!isset($cfg['refs'][$refTable])) {
        return null;
    }
    $pk = $cfg['refs'][$refTable]['pk'];
    $stmt = $koneksi->prepare("SELECT * FROM $refTable WHERE $pk = ? LIMIT 1");
    $stmt->bind_param("i", $refId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Simpan catatan revisi dari Staff IMP / Staff QA ($role, QA hanya untuk FPM) + kirim WA
 * ke Staff & Dept Head dept tujuan. Kalau catatan menempel ke baris tertentu, baris itu
 * dipaksa kembali OPEN.
 *
 * @return array{ok: bool, pesan: string}
 */
function kirimCatatanRevisiCm($koneksi, string $sumber, int $idInduk, ?string $refTable, ?int $refId, string $deptTujuan, string $catatan, string $oleh, string $role = CM_REVISI_ROLE_IMP): array
{
    $role = ($sumber !== 'ASAKAI' && $role === CM_REVISI_ROLE_QA) ? CM_REVISI_ROLE_QA : CM_REVISI_ROLE_IMP;
    $cfg = cmRevisiConfig($sumber);
    $deptTujuan = trim($deptTujuan);
    $catatan = trim($catatan);

    if ($idInduk <= 0 || $deptTujuan === '' || $catatan === '') {
        return ['ok' => false, 'pesan' => 'Dept tujuan dan catatan wajib diisi.'];
    }

    $baris = null;
    if ($refTable !== null && $refTable !== '' && $refId) {
        $baris = cmRevisiAmbilBaris($koneksi, $sumber, $refTable, $refId);
        if (!$baris || (int) $baris[$cfg['kolomInduk']] !== $idInduk) {
            return ['ok' => false, 'pesan' => 'Baris countermeasure tidak ditemukan.'];
        }
    } else {
        $refTable = null;
        $refId = null;
    }

    $snapshot = $baris ? implode('|', cmRevisiFileBaris($sumber, $baris)) : null;

    if ($sumber === 'ASAKAI') {
        $stmt = $koneksi->prepare("INSERT INTO asakai_catatan_revisi
            (id_asakai, ref_table, ref_id, dept_tujuan, catatan, file_saat_catatan, dibuat_oleh)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isissss", $idInduk, $refTable, $refId, $deptTujuan, $catatan, $snapshot, $oleh);
    } else {
        $tipe = 'CATATAN';
        $stmt = $koneksi->prepare("INSERT INTO fpm_catatan_revisi
            (id_fpm, ref_table, ref_id, dept_tujuan, tipe, catatan, file_saat_catatan, dibuat_oleh, role)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isissssss", $idInduk, $refTable, $refId, $deptTujuan, $tipe, $catatan, $snapshot, $oleh, $role);
    }

    if (!$stmt->execute()) {
        return ['ok' => false, 'pesan' => pesanErrorDb('Gagal menyimpan catatan', $stmt)];
    }

    if ($baris) {
        $pk = $cfg['refs'][$refTable]['pk'];
        $stmtStatus = $koneksi->prepare("UPDATE $refTable SET status = 'OPEN' WHERE $pk = ?");
        $stmtStatus->bind_param("i", $refId);
        $stmtStatus->execute();
    }

    cmRevisiKirimWa($koneksi, $sumber, $idInduk, $refTable, $baris, $deptTujuan, $catatan, $role);

    return ['ok' => true, 'pesan' => 'Catatan revisi berhasil dikirim ke ' . $deptTujuan . '.'];
}

function cmRevisiKirimWa($koneksi, string $sumber, int $idInduk, ?string $refTable, ?array $baris, string $deptTujuan, string $catatan, string $role = CM_REVISI_ROLE_IMP): void
{
    $nama_dept = $deptTujuan;
    // include (bukan require_once) supaya $waStaff/$waDeptHead terisi di scope fungsi ini
    // meskipun wa_map.php sudah pernah di-load halaman pemanggil.
    include __DIR__ . '/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    // Revisi dari Staff QA hanya ditindaklanjuti Dept Head (menu Permintaan Revisi Modul FPM QA).
    $waTujuan = $role === CM_REVISI_ROLE_QA
        ? array_filter($waDeptHead ?? [])
        : array_filter(array_merge($waStaff ?? [], $waDeptHead ?? []));
    if (!$waTujuan) {
        error_log("WA catatan revisi $sumber #$idInduk: dept '$deptTujuan' tidak terdaftar di wa_map.php");
        return;
    }

    $cfg = cmRevisiConfig($sumber);
    $item = '';
    if ($baris && $refTable) {
        $item = $cfg['refs'][$refTable]['label'] . ': ' . trim((string) ($baris[$cfg['refs'][$refTable]['desk']] ?? '')) . "\n";
    }

    if ($sumber === 'ASAKAI') {
        $stmt = $koneksi->prepare("SELECT tanggal, machine, namapart FROM asakai_header WHERE id_asakai = ?");
        $stmt->bind_param("i", $idInduk);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc() ?: [];
        $judul = "*ASAKAI - PERMINTAAN REVISI COUNTERMEASURE*\n"
            . "Tanggal Asakai: " . ($h['tanggal'] ?? '-') . "\n"
            . "Mesin: " . ($h['machine'] ?? '-') . "\n"
            . "Nama Part: " . ($h['namapart'] ?? '-') . "\n";
    } else {
        $stmt = $koneksi->prepare("SELECT no_reg_fpm, no_form, jenis_fpm, nama_part FROM fpm_header WHERE id_fpm = ?");
        $stmt->bind_param("i", $idInduk);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc() ?: [];
        $judul = "*FPM " . strtoupper($h['jenis_fpm'] ?? '') . " - PERMINTAAN REVISI COUNTERMEASURE*\n"
            . "No Reg FPM: " . ($h['no_reg_fpm'] ?: ($h['no_form'] ?? '-')) . "\n"
            . "Nama Part: " . ($h['nama_part'] ?? '-') . "\n";
    }

    $pesan = $judul . $item . "\n"
        . "Catatan dari $role:\n$catatan\n\n"
        . "Mohon buka menu *Permintaan Revisi*" . ($role === CM_REVISI_ROLE_QA ? ' di *Modul FPM QA*' : '')
        . " " . brandNama() . " lalu upload ulang foto/dokumen yang sesuai.";

    if (!kirimWA(implode(',', $waTujuan), $pesan)) {
        error_log("Gagal kirim WA catatan revisi $sumber #$idInduk ke dept $deptTujuan");
    }
}

/**
 * Tentukan status catatan dari data catatan + baris countermeasure yang dirujuk.
 * $row wajib punya: ref_table, selesai_at, file_saat_catatan, ada_baris, status_baris, file_sekarang.
 */
function cmRevisiStatus(array $row): string
{
    // Staff IMP sudah mengecek hasil perbaikan PIC (tombol "Tandai sudah dicek").
    if (!empty($row['dicek_imp_at'])) {
        return 'SELESAI';
    }
    if (!empty($row['ref_table'])) {
        if (empty($row['ada_baris']) || strtoupper((string) $row['status_baris']) === 'CLOSED') {
            return 'SELESAI';
        }
        // file_saat_catatan NULL = catatan lama sebelum ada snapshot, tidak bisa dibandingkan.
        $fileBerubah = $row['file_saat_catatan'] !== null && (string) $row['file_sekarang'] !== (string) $row['file_saat_catatan'];
        if (!empty($row['selesai_at']) || $fileBerubah) {
            return 'DIPERBAIKI';
        }
        return 'MENUNGGU';
    }
    return !empty($row['selesai_at']) ? 'DIPERBAIKI' : 'MENUNGGU';
}

/**
 * Ambil catatan revisi IMP lengkap dengan info baris & status-nya.
 * Filter: $idInduk (satu FPM/ASAKAI) dan/atau $dept (dept tujuan, buat halaman PIC).
 */
function ambilCatatanRevisiCm($koneksi, string $sumber, ?int $idInduk = null, ?string $dept = null): array
{
    $where = [];
    $types = '';
    $params = [];

    if ($sumber === 'ASAKAI') {
        $sql = "SELECT r.*, h.tanggal, h.machine, h.namapart, h.plant,
                COALESCE(cd.deskripsi, cn.deskripsi) AS item_deskripsi,
                COALESCE(cd.pic, cn.pic) AS pic_baris,
                COALESCE(cd.status, cn.status) AS status_baris,
                COALESCE(cd.due_date, cn.due_date) AS due_date_baris,
                IF(cd.id_cm_dt IS NULL AND cn.id_cm_ng IS NULL, 0, 1) AS ada_baris,
                COALESCE(cd.file1, cn.file1) AS file1,
                COALESCE(cd.file2, cn.file2) AS file2,
                COALESCE(cd.file3, cn.file3) AS file3
            FROM asakai_catatan_revisi r
            JOIN asakai_header h ON h.id_asakai = r.id_asakai
            LEFT JOIN asakai_countermeasure_downtime cd ON r.ref_table = 'asakai_countermeasure_downtime' AND cd.id_cm_dt = r.ref_id
            LEFT JOIN asakai_countermeasure_ng cn ON r.ref_table = 'asakai_countermeasure_ng' AND cn.id_cm_ng = r.ref_id";
        if ($idInduk !== null) {
            $where[] = 'r.id_asakai = ?';
        }
    } else {
        $sql = "SELECT r.*, h.no_reg_fpm, h.no_form, h.jenis_fpm, h.nama_part, h.machine_no, h.plant,
                COALESCE(ca.tindakan, pa.tindakan, rd.dokumen) AS item_deskripsi,
                COALESCE(ca.pic, pa.pic, rd.pic) AS pic_baris,
                COALESCE(ca.status, pa.status, rd.status) AS status_baris,
                COALESCE(ca.due_date, pa.due_date, rd.due_date) AS due_date_baris,
                IF(ca.id IS NULL AND pa.id IS NULL AND rd.id IS NULL, 0, 1) AS ada_baris,
                COALESCE(ca.file, pa.file, rd.file) AS file
            FROM fpm_catatan_revisi r
            JOIN fpm_header h ON h.id_fpm = r.id_fpm
            LEFT JOIN fpm_corrective_action ca ON r.ref_table = 'fpm_corrective_action' AND ca.id = r.ref_id
            LEFT JOIN fpm_preventive_action pa ON r.ref_table = 'fpm_preventive_action' AND pa.id = r.ref_id
            LEFT JOIN fpm_review_dokumen rd ON r.ref_table = 'fpm_review_dokumen' AND rd.id = r.ref_id";
        // Tabel ini juga dipakai catatan Dept Head QA + balasannya - itu bukan permintaan revisi IMP.
        $where[] = "r.tipe IN ('CATATAN', '') AND COALESCE(r.role, '') <> 'Dept Head QA'";
        if ($idInduk !== null) {
            $where[] = 'r.id_fpm = ?';
        }
    }

    if ($idInduk !== null) {
        $types .= 'i';
        $params[] = $idInduk;
    }
    if ($dept !== null) {
        $where[] = 'UPPER(TRIM(r.dept_tujuan)) = UPPER(TRIM(?))';
        $types .= 's';
        $params[] = $dept;
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC';

    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        // Migration 2026_10_09 belum dijalankan - fitur dianggap kosong, halaman tetap jalan.
        error_log('ambilCatatanRevisiCm: ' . $koneksi->error);
        return [];
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['sumber'] = $sumber;
        $row['files'] = cmRevisiFileBaris($sumber, $row);
        $row['files_semua'] = cmRevisiSemuaFile($koneksi, $sumber, $row);
        $row['file_sekarang'] = implode('|', $row['files']);
        $row['status_revisi'] = cmRevisiStatus($row);
        $rows[] = $row;
    }
    return $rows;
}

/** Kelompokkan catatan per baris (key "ref_table_ref_id", catatan umum = "general"). */
function kelompokkanCatatanRevisiCm(array $rows): array
{
    $grouped = [];
    foreach ($rows as $r) {
        $key = (!empty($r['ref_table']) && !empty($r['ref_id'])) ? $r['ref_table'] . '_' . $r['ref_id'] : 'general';
        $grouped[$key][] = $r;
    }
    return $grouped;
}

/**
 * Permintaan revisi untuk satu dept, terbaru di atas. $peminta memisahkan menu:
 * Staff IMP (FPM + ASAKAI) di modul utama, Staff QA (FPM Claim Machining) di Modul FPM QA.
 */
function ambilPermintaanRevisiDept($koneksi, string $dept, string $peminta = CM_REVISI_ROLE_IMP): array
{
    if (trim($dept) === '') {
        return [];
    }
    $rows = ambilCatatanRevisiCm($koneksi, 'FPM', null, $dept);
    if ($peminta !== CM_REVISI_ROLE_QA) {
        $rows = array_merge($rows, ambilCatatanRevisiCm($koneksi, 'ASAKAI', null, $dept));
    }
    $rows = array_values(array_filter($rows, fn($r) => cmRevisiPeminta($r) === $peminta));
    usort($rows, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return $rows;
}

function hitungPermintaanRevisiMenunggu($koneksi, string $dept, string $peminta = CM_REVISI_ROLE_IMP): int
{
    return count(array_filter(ambilPermintaanRevisiDept($koneksi, $dept, $peminta), fn($r) => $r['status_revisi'] === 'MENUNGGU'));
}

/** Halaman Permintaan Revisi PIC sesuai peminta (QA ada di Modul FPM QA). */
function cmRevisiHalamanPic(string $peminta): string
{
    return $peminta === CM_REVISI_ROLE_QA ? 'permintaan_revisi_qa.php' : 'permintaan_revisi.php';
}

function cmRevisiAmbilSatu($koneksi, string $sumber, int $idRevisi): ?array
{
    $cfg = cmRevisiConfig($sumber);
    $stmt = $koneksi->prepare("SELECT * FROM {$cfg['tabel']} WHERE id = ?");
    $stmt->bind_param("i", $idRevisi);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Tandai catatan (dan catatan lain yang masih terbuka untuk baris yang sama) sudah
 * ditindaklanjuti PIC.
 */
function cmRevisiTandaiDiperbaiki($koneksi, string $sumber, array $revisi, string $oleh, string $tanggapan): int
{
    $cfg = cmRevisiConfig($sumber);
    $tanggapan = trim($tanggapan) !== '' ? trim($tanggapan) : null;

    if (!empty($revisi['ref_table']) && !empty($revisi['ref_id'])) {
        $stmt = $koneksi->prepare("UPDATE {$cfg['tabel']}
            SET selesai_at = NOW(), selesai_oleh = ?, tanggapan_pic = COALESCE(?, tanggapan_pic)
            WHERE {$cfg['kolomInduk']} = ? AND ref_table = ? AND ref_id = ? AND selesai_at IS NULL");
        $idInduk = (int) $revisi[$cfg['kolomInduk']];
        $refId = (int) $revisi['ref_id'];
        $stmt->bind_param("ssisi", $oleh, $tanggapan, $idInduk, $revisi['ref_table'], $refId);
    } else {
        $stmt = $koneksi->prepare("UPDATE {$cfg['tabel']}
            SET selesai_at = NOW(), selesai_oleh = ?, tanggapan_pic = COALESCE(?, tanggapan_pic)
            WHERE id = ? AND selesai_at IS NULL");
        $idRevisi = (int) $revisi['id'];
        $stmt->bind_param("ssi", $oleh, $tanggapan, $idRevisi);
    }
    $stmt->execute();
    return $stmt->affected_rows;
}

/**
 * WA ke peminta revisi bahwa PIC sudah menindaklanjuti: Staff IMP (sesuai plant FPM/ASAKAI)
 * atau Staff QA. Item dikelompokkan per peminta supaya tiap pihak hanya menerima catatannya.
 * $items: list ['label' => ..., 'deskripsi' => ..., 'catatan' => ..., 'tanggapan' => ..., 'role' => ...].
 */
function cmRevisiNotifImp($koneksi, string $sumber, int $idInduk, array $items, string $oleh, string $dept): void
{
    $perRole = [];
    foreach ($items as $it) {
        $perRole[($it['role'] ?? '') === CM_REVISI_ROLE_QA ? CM_REVISI_ROLE_QA : CM_REVISI_ROLE_IMP][] = $it;
    }
    foreach ($perRole as $role => $itemsRole) {
        cmRevisiNotifPeminta($koneksi, $sumber, $idInduk, $itemsRole, $oleh, $dept, $role);
    }
}

function cmRevisiNotifPeminta($koneksi, string $sumber, int $idInduk, array $items, string $oleh, string $dept, string $role): void
{
    include __DIR__ . '/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    if ($sumber === 'ASAKAI') {
        $stmt = $koneksi->prepare("SELECT tanggal, machine, namapart, plant FROM asakai_header WHERE id_asakai = ?");
        $stmt->bind_param("i", $idInduk);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc() ?: [];
        $judul = "*ASAKAI - REVISI SUDAH DIPERBAIKI PIC*\n"
            . "Tanggal Asakai: " . ($h['tanggal'] ?? '-') . "\n"
            . "Mesin: " . ($h['machine'] ?? '-') . "\n"
            . "Nama Part: " . ($h['namapart'] ?? '-') . "\n";
    } else {
        $stmt = $koneksi->prepare("SELECT no_reg_fpm, no_form, jenis_fpm, nama_part, plant FROM fpm_header WHERE id_fpm = ?");
        $stmt->bind_param("i", $idInduk);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc() ?: [];
        $judul = "*FPM " . strtoupper($h['jenis_fpm'] ?? '') . " - REVISI SUDAH DIPERBAIKI PIC*\n"
            . "No Reg FPM: " . (($h['no_reg_fpm'] ?? '') ?: ($h['no_form'] ?? '-')) . "\n"
            . "Nama Part: " . ($h['nama_part'] ?? '-') . "\n";
    }

    $plant = trim(str_ireplace('Plant', '', (string) ($h['plant'] ?? '')));
    if ($role === CM_REVISI_ROLE_QA) {
        $waTujuan = $waStaffQA ?? [];
    } elseif ($plant === '1') {
        $waTujuan = $waStaffIMP1 ?? [];
    } elseif ($plant === '3') {
        $waTujuan = $waStaffIMP3 ?? [];
    } else {
        $waTujuan = array_merge($waStaffIMP1 ?? [], $waStaffIMP3 ?? []);
    }
    $waTujuan = array_unique(array_filter($waTujuan));
    if (!$waTujuan) {
        return;
    }

    $detail = '';
    foreach ($items as $it) {
        $detail .= "\n- " . $it['label'] . ($it['deskripsi'] !== '' ? ': ' . $it['deskripsi'] : '') . "\n"
            . "  Catatan " . ($role === CM_REVISI_ROLE_QA ? 'QA' : 'IMP') . ": " . $it['catatan'] . "\n"
            . "  Tindak lanjut: " . ($it['tanggapan'] !== '' ? $it['tanggapan'] : 'upload ulang file') . "\n";
    }

    $pesan = $judul . "Diperbaiki oleh: $oleh ($dept)\n" . $detail . "\n"
        . "Mohon cek ulang di menu *Verifikasi Countermeasure* " . brandNama() . ".";

    if (!kirimWA(implode(',', $waTujuan), $pesan)) {
        error_log("Gagal kirim WA revisi diperbaiki $sumber #$idInduk ke $role");
    }
}

function cmRevisiItemNotif(string $sumber, array $r, string $tanggapan): array
{
    $cfg = cmRevisiConfig($sumber);
    $refTable = $r['ref_table'] ?? '';
    return [
        'label'     => ($refTable && isset($cfg['refs'][$refTable])) ? $cfg['refs'][$refTable]['label'] : 'Catatan umum',
        'deskripsi' => trim((string) ($r['item_deskripsi'] ?? '')),
        'catatan'   => trim((string) $r['catatan']),
        'tanggapan' => trim($tanggapan),
        'role'      => cmRevisiPeminta($r),
    ];
}

/** Notif ke peminta (Staff IMP / Staff QA) untuk satu catatan revisi (diambil ulang supaya deskripsi barisnya ikut). */
function cmRevisiNotifImpUntuk($koneksi, string $sumber, array $revisi, string $oleh, string $dept, string $tanggapan): void
{
    $cfg = cmRevisiConfig($sumber);
    $idInduk = (int) $revisi[$cfg['kolomInduk']];
    foreach (ambilCatatanRevisiCm($koneksi, $sumber, $idInduk) as $r) {
        if ((int) $r['id'] === (int) $revisi['id']) {
            cmRevisiNotifImp($koneksi, $sumber, $idInduk, [cmRevisiItemNotif($sumber, $r, $tanggapan)], $oleh, $dept);
            return;
        }
    }
}

/**
 * Dipanggil setelah PIC menyimpan form lama (edit_fpm_downtime / proses_update_asakai):
 * catatan revisi yang file barisnya sudah berganti ditandai ditindaklanjuti, lalu
 * peminta (Staff IMP / Staff QA) dikabari lewat WA (satu pesan per peminta).
 */
function sinkronRevisiSetelahSimpanPic($koneksi, string $sumber, int $idInduk, string $oleh, string $dept): void
{
    $cfg = cmRevisiConfig($sumber);
    $items = [];

    foreach (ambilCatatanRevisiCm($koneksi, $sumber, $idInduk) as $r) {
        if ($r['status_revisi'] !== 'DIPERBAIKI' || !empty($r['selesai_at']) || empty($r['ref_table'])) {
            continue;
        }
        $stmt = $koneksi->prepare("UPDATE {$cfg['tabel']} SET selesai_at = NOW(), selesai_oleh = ? WHERE id = ? AND selesai_at IS NULL");
        $idRevisi = (int) $r['id'];
        $stmt->bind_param("si", $oleh, $idRevisi);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $items[] = cmRevisiItemNotif($sumber, $r, '');
        }
    }

    cmRevisiNotifImp($koneksi, $sumber, $idInduk, $items, $oleh, $dept);
}

/**
 * Revisi yang sudah ditindaklanjuti PIC tapi belum dicek Staff IMP (FPM + ASAKAI),
 * difilter plant ('' = semua). Dipakai badge sidebar & daftar di Verifikasi Countermeasure.
 */
function ambilRevisiPerluDicekImp($koneksi, string $plant = ''): array
{
    $rows = array_merge(ambilCatatanRevisiCm($koneksi, 'FPM'), ambilCatatanRevisiCm($koneksi, 'ASAKAI'));
    $rows = array_filter($rows, function ($r) use ($plant) {
        if ($r['status_revisi'] !== 'DIPERBAIKI' || cmRevisiPeminta($r) !== CM_REVISI_ROLE_IMP) {
            return false;
        }
        return $plant === '' || trim(str_ireplace('Plant', '', (string) $r['plant'])) === $plant;
    });
    usort($rows, fn($a, $b) => strcmp((string) ($b['selesai_at'] ?? $b['created_at']), (string) ($a['selesai_at'] ?? $a['created_at'])));
    return array_values($rows);
}

/**
 * Revisi FPM dari Staff QA yang sudah ditindaklanjuti PIC tapi belum dicek Staff QA
 * (lintas plant). Dipakai badge sidebar QA & daftar di Verifikasi Countermeasure QA.
 */
function ambilRevisiPerluDicekQa($koneksi): array
{
    $rows = array_filter(
        ambilCatatanRevisiCm($koneksi, 'FPM'),
        fn($r) => $r['status_revisi'] === 'DIPERBAIKI' && cmRevisiPeminta($r) === CM_REVISI_ROLE_QA
    );
    usort($rows, fn($a, $b) => strcmp((string) ($b['selesai_at'] ?? $b['created_at']), (string) ($a['selesai_at'] ?? $a['created_at'])));
    return array_values($rows);
}

/**
 * Tandai sudah dicek peminta. Kolom dicek_imp_* juga dipakai untuk catatan Staff QA;
 * $role memastikan Staff QA hanya menandai catatannya sendiri (dan sebaliknya).
 *
 * @return array{ok: bool, pesan: string}
 */
function tandaiRevisiDicekImp($koneksi, string $sumber, int $idRevisi, string $oleh, string $role = CM_REVISI_ROLE_IMP): array
{
    $cfg = cmRevisiConfig($sumber);
    $revisi = cmRevisiAmbilSatu($koneksi, $sumber, $idRevisi);
    if (!$revisi || cmRevisiPeminta($revisi) !== $role) {
        return ['ok' => false, 'pesan' => 'Permintaan revisi tidak ditemukan.'];
    }
    $stmt = $koneksi->prepare("UPDATE {$cfg['tabel']} SET dicek_imp_at = NOW(), dicek_imp_oleh = ? WHERE id = ? AND dicek_imp_at IS NULL");
    if (!$stmt) {
        return ['ok' => false, 'pesan' => 'Database belum diupdate (jalankan migration_2026_10_09b).'];
    }
    $stmt->bind_param("si", $oleh, $idRevisi);
    $stmt->execute();
    return ['ok' => true, 'pesan' => 'Revisi ditandai sudah dicek.'];
}

/**
 * Proses aksi PIC dari halaman Permintaan Revisi: upload ulang file baris countermeasure
 * (mengganti file lama) dan/atau tandai sudah ditindaklanjuti.
 *
 * @return array{ok: bool, pesan: string}
 */
function prosesTindakLanjutRevisiPic($koneksi, string $sessionDept, string $oleh, array $post, array $files): array
{
    $sumber = ($post['sumber'] ?? '') === 'ASAKAI' ? 'ASAKAI' : 'FPM';
    $cfg = cmRevisiConfig($sumber);
    $revisi = cmRevisiAmbilSatu($koneksi, $sumber, (int) ($post['id_revisi'] ?? 0));

    if (!$revisi) {
        return ['ok' => false, 'pesan' => 'Permintaan revisi tidak ditemukan.'];
    }
    if (strtoupper(trim($revisi['dept_tujuan'])) !== strtoupper(trim($sessionDept))) {
        return ['ok' => false, 'pesan' => 'Permintaan revisi ini bukan untuk dept Anda.'];
    }
    if (!empty($revisi['selesai_at'])) {
        return ['ok' => false, 'pesan' => 'Permintaan revisi ini sudah ditindaklanjuti sebelumnya.'];
    }

    $tanggapan = (string) ($post['tanggapan'] ?? '');
    $peminta = cmRevisiPeminta($revisi);
    $punyaBaris = !empty($revisi['ref_table']) && isset($cfg['refs'][$revisi['ref_table']]);

    // Kumpulkan file yang benar-benar diupload (input multiple: file_revisi[]).
    $uploads = [];
    if (isset($files['file_revisi']['name']) && is_array($files['file_revisi']['name'])) {
        foreach ($files['file_revisi']['name'] as $i => $name) {
            if ($name === '' || ($files['file_revisi']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $err = pesanErrorUpload($files['file_revisi']['error'][$i], $name);
            if ($err !== null) {
                return ['ok' => false, 'pesan' => $err];
            }
            $uploads[] = [
                'name' => $name,
                'tmp'  => $files['file_revisi']['tmp_name'][$i],
                'size' => $files['file_revisi']['size'][$i],
            ];
        }
    }

    if (!$punyaBaris) {
        cmRevisiTandaiDiperbaiki($koneksi, $sumber, $revisi, $oleh, $tanggapan);
        cmRevisiNotifImpUntuk($koneksi, $sumber, $revisi, $oleh, $sessionDept, $tanggapan);
        return ['ok' => true, 'pesan' => 'Permintaan revisi ditandai sudah ditindaklanjuti. ' . $peminta . ' sudah dikabari.'];
    }

    if (!$uploads) {
        return ['ok' => false, 'pesan' => 'Pilih file pengganti terlebih dahulu.'];
    }

    $refCfg = $cfg['refs'][$revisi['ref_table']];
    if (!is_dir($cfg['uploadDir'])) {
        mkdir($cfg['uploadDir'], 0755, true);
    }

    foreach ($uploads as $u) {
        $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, CM_REVISI_ALLOWED_EXT, true)) {
            return ['ok' => false, 'pesan' => "Tipe file {$u['name']} tidak diizinkan."];
        }
        if (isVideoUploadTooLarge($u['name'], $u['size'])) {
            return ['ok' => false, 'pesan' => "Video {$u['name']} melebihi 10MB."];
        }
    }

    $hariIni = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    $refId = (int) $revisi['ref_id'];
    $idInduk = (int) $revisi[$cfg['kolomInduk']];
    $pk = $refCfg['pk'];
    $refTable = $revisi['ref_table'];

    $baris = cmRevisiAmbilBaris($koneksi, $sumber, $refTable, $refId);
    if (!$baris) {
        return ['ok' => false, 'pesan' => 'Baris countermeasure tidak ditemukan.'];
    }

    // File lama TIDAK PERNAH ditimpa: file perbaikan ditambahkan ke file yang sudah ada.
    // Kalau slot penuh ditolak - PIC menghapus dulu file yang salah (tombol ✕), baru upload.
    // Status baris tidak disentuh - CLOSED tetap keputusan Staff IMP / Staff QA saat verifikasi ulang.
    if ($sumber === 'ASAKAI') {
        require_once __DIR__ . '/asakai_bukti_helper.php';
        $lama = fileBuktiAsakai($baris);
        if (count($lama) + count($uploads) > ASAKAI_BUKTI_MAX_FILE) {
            return ['ok' => false, 'pesan' => 'Maksimal ' . ASAKAI_BUKTI_MAX_FILE . ' file per baris (sudah ada ' . count($lama) . ' file). '
                . 'File lama tidak ditimpa - hapus dulu file yang salah (tombol ✕), lalu upload lagi.'];
        }
        $semua = $lama;
        foreach ($uploads as $u) {
            $nama = namaFileUploadUnik($refCfg['prefix'] . '_REV', pathinfo($u['name'], PATHINFO_EXTENSION), $cfg['uploadDir']);
            if (!pindahkanUploadAman($u['tmp'], $cfg['uploadDir'] . $nama)) {
                return ['ok' => false, 'pesan' => "Server gagal menyimpan file {$u['name']}. Silakan coba lagi."];
            }
            $semua[] = $nama;
        }
        $f = array_pad($semua, 3, null);
        $stmt = $koneksi->prepare("UPDATE $refTable SET file1 = ?, file2 = ?, file3 = ?, actual_date = ?
            WHERE $pk = ? AND id_asakai = ?");
        $stmt->bind_param("ssssii", $f[0], $f[1], $f[2], $hariIni, $refId, $idInduk);
        $tambahanFpm = [];
    } else {
        require_once __DIR__ . '/fpm_bukti_aksi_helper.php';
        $jenis = CM_REVISI_JENIS_FPM[$refTable];
        try {
            [$fileUtama, $tambahanFpm] = prosesUploadBuktiAksi(
                array_map(fn($u) => $u + ['error' => UPLOAD_ERR_OK], $uploads),
                (string) ($baris['file'] ?? ''),
                count(semuaFileBuktiAksi($koneksi, $jenis, $baris)),
                $cfg['uploadDir'],
                $refCfg['prefix'] . '_REV',
                $refCfg['label']
            );
        } catch (Exception $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }
        $stmt = $koneksi->prepare("UPDATE $refTable SET file = ?, actual_date = ? WHERE $pk = ? AND id_fpm = ?");
        $stmt->bind_param("ssii", $fileUtama, $hariIni, $refId, $idInduk);
    }
    if (!$stmt->execute()) {
        return ['ok' => false, 'pesan' => pesanErrorDb('Gagal menyimpan file', $stmt)];
    }
    if ($tambahanFpm) {
        simpanFileTambahanAksi($koneksi, $jenis, $refId, $tambahanFpm);
    }

    cmRevisiTandaiDiperbaiki($koneksi, $sumber, $revisi, $oleh, $tanggapan);
    cmRevisiNotifImpUntuk($koneksi, $sumber, $revisi, $oleh, $sessionDept, $tanggapan);

    return ['ok' => true, 'pesan' => 'File perbaikan berhasil diupload. ' . $peminta . ' sudah dikabari untuk verifikasi ulang.'];
}

/* =========================================================================
   RENDER
   ========================================================================= */

function cmRevisiBadge(string $status): string
{
    $map = [
        'MENUNGGU'   => ['Menunggu perbaikan PIC', '#b45309', '#fef3c7'],
        'DIPERBAIKI' => ['Sudah diperbaiki PIC - cek ulang', '#1d4ed8', '#dbeafe'],
        'SELESAI'    => ['Selesai', '#166534', '#dcfce7'],
    ];
    [$label, $color, $bg] = $map[$status] ?? $map['MENUNGGU'];
    return '<span style="display:inline-block; background:' . $bg . '; color:' . $color . '; font-size:10.5px; '
        . 'font-weight:700; padding:2px 8px; border-radius:10px; white-space:nowrap;">' . $label . '</span>';
}

/** Tautan/thumbnail file baris countermeasure. */
function cmRevisiRenderFiles(string $sumber, array $files, int $size = 56): string
{
    if (!$files) {
        return '<span style="color:#9ca3af; font-size:12px;">Belum ada file</span>';
    }
    $cfg = cmRevisiConfig($sumber);
    $html = '<div style="display:flex; gap:6px; flex-wrap:wrap;">';
    foreach ($files as $f) {
        $url = htmlspecialchars($cfg['uploadUrl'] . $f);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $html .= '<a href="' . $url . '" target="_blank"><img src="' . $url . '" style="width:' . $size . 'px; height:' . $size . 'px; '
                . 'object-fit:cover; border-radius:6px; border:1px solid #e5e7eb;"></a>';
        } else {
            $html .= '<a href="' . $url . '" target="_blank" style="display:flex; align-items:center; justify-content:center; '
                . 'width:' . $size . 'px; height:' . $size . 'px; background:#f1f5f9; border:1px solid #e5e7eb; border-radius:6px; '
                . 'font-size:11px; font-weight:700; color:#374151; text-decoration:none;">' . strtoupper(htmlspecialchars($ext)) . '</a>';
        }
    }
    return $html . '</div>';
}

/** Riwayat catatan revisi untuk satu baris (dipakai di halaman Staff IMP / Staff QA). */
function renderRiwayatCatatanRevisiCm(array $rows): string
{
    if (!$rows) {
        return '';
    }
    $html = '<div style="margin-top:8px; text-align:left;">';
    foreach ($rows as $r) {
        $html .= '<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:6px 8px; font-size:12px; margin-bottom:6px;">'
            . '<div style="display:flex; justify-content:space-between; gap:6px; flex-wrap:wrap; margin-bottom:3px;">'
            . '<b>Revisi ke ' . htmlspecialchars($r['dept_tujuan']) . '</b>' . cmRevisiBadge($r['status_revisi']) . '</div>'
            . nl2br(htmlspecialchars($r['catatan']))
            . '<div style="color:#6b7280; font-size:11px; margin-top:3px;">' . htmlspecialchars($r['dibuat_oleh'] ?? '')
            . ' &middot; ' . date('d-m-Y H:i', strtotime($r['created_at'])) . '</div>';
        if (!empty($r['tanggapan_pic']) || !empty($r['selesai_at'])) {
            $html .= '<div style="margin-top:4px; padding-top:4px; border-top:1px dashed #fcd34d; color:#1e3a8a;">'
                . '<b>Tanggapan PIC:</b> ' . (!empty($r['tanggapan_pic']) ? nl2br(htmlspecialchars($r['tanggapan_pic'])) : '<i>upload ulang file</i>')
                . (!empty($r['selesai_at']) ? '<div style="color:#6b7280; font-size:11px;">' . htmlspecialchars($r['selesai_oleh'] ?? '')
                    . ' &middot; ' . date('d-m-Y H:i', strtotime($r['selesai_at'])) . '</div>' : '')
                . '</div>';
        }
        if (!empty($r['dicek_imp_at'])) {
            $html .= '<div style="margin-top:4px; color:#166534; font-size:11px;"><i class="bi bi-check2-all"></i> Dicek '
                . htmlspecialchars($r['dicek_imp_oleh'] ?? '') . ' &middot; ' . date('d-m-Y H:i', strtotime($r['dicek_imp_at'])) . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/** Tombol "Minta Revisi" per baris di halaman Staff IMP / Staff QA (memanggil openCatatanModal di JS halaman). */
function renderTombolMintaRevisiCm(string $refTable, int $refId, string $pic, string $deskripsi): string
{
    $args = htmlspecialchars(json_encode([$refTable, $refId, $pic, $deskripsi]), ENT_QUOTES);
    return '<button type="button" class="btn-minta-revisi" data-revisi="' . $args . '" onclick="openCatatanModalDari(this)"'
        . ' style="margin-top:6px; background:#f59e0b; color:#fff; border:none; border-radius:6px; padding:4px 10px; '
        . 'font-size:11.5px; font-weight:600; cursor:pointer; white-space:nowrap;">'
        . '<i class="bi bi-arrow-counterclockwise"></i> Minta Revisi</button>';
}

/**
 * Modal + JS "Catatan Revisi" untuk halaman Staff IMP / Staff QA. $action = URL form (halaman itu sendiri).
 * Form mengirim: catatan_revisi_submit, ref_table, ref_id, dept_tujuan, catatan.
 */
function renderModalCatatanRevisiCm(array $deptList, string $hiddenIndukName, int $idInduk): string
{
    $opsi = '<option value="">-- Pilih Dept --</option>';
    foreach ($deptList as $d) {
        $opsi .= '<option value="' . htmlspecialchars($d) . '">' . htmlspecialchars($d) . '</option>';
    }

    return <<<HTML
<div id="catatanModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2000; overflow-y:auto;">
    <div style="background:#fff; width:460px; max-width:92%; margin:80px auto; padding:20px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.2);">
        <h5 style="margin:0 0 4px; font-weight:700;"><i class="bi bi-chat-left-text"></i> Catatan Revisi ke PIC</h5>
        <p style="margin:0 0 12px; color:#6b7280; font-size:12px;">PIC (Dept Head &amp; Staff dept tujuan) akan menerima notif WA dan melihatnya di menu <b>Permintaan Revisi</b>.</p>
        <form method="POST">
            <input type="hidden" name="{$hiddenIndukName}" value="{$idInduk}">
            <input type="hidden" name="ref_table" id="catatanRefTable" value="">
            <input type="hidden" name="ref_id" id="catatanRefId" value="">

            <div id="catatanItemBox" style="display:none; background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; font-size:12px; margin-bottom:10px;">
                <div style="color:#6b7280; font-weight:600; font-size:11px; text-transform:uppercase;">Countermeasure yang direvisi</div>
                <div id="catatanItemText" style="white-space:pre-wrap;"></div>
            </div>

            <label for="catatanDeptSelect" style="font-size:13px; font-weight:600; display:block; margin-bottom:4px;">Tujukan ke Dept <span style="color:#dc2626">*</span></label>
            <select name="dept_tujuan" id="catatanDeptSelect" required style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; margin-bottom:10px;">{$opsi}</select>

            <label style="font-size:13px; font-weight:600; display:block; margin-bottom:4px;">Alasan cepat</label>
            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
                <button type="button" class="chip-alasan" onclick="isiAlasanRevisi(this)">Foto salah / bukan foto perbaikan ini</button>
                <button type="button" class="chip-alasan" onclick="isiAlasanRevisi(this)">Foto buram / tidak jelas</button>
                <button type="button" class="chip-alasan" onclick="isiAlasanRevisi(this)">Foto before-after belum lengkap</button>
                <button type="button" class="chip-alasan" onclick="isiAlasanRevisi(this)">Perbaikan belum sesuai countermeasure</button>
                <button type="button" class="chip-alasan" onclick="isiAlasanRevisi(this)">Dokumen belum direvisi / belum ditandatangani</button>
            </div>

            <label for="catatanText" style="font-size:13px; font-weight:600; display:block; margin-bottom:4px;">Catatan <span style="color:#dc2626">*</span></label>
            <textarea name="catatan" id="catatanText" required style="width:100%; height:100px; padding:8px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"
                placeholder="Contoh: foto yang diupload foto mesin lain, mohon upload foto perbaikan DCM 06 (before & after)."></textarea>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                <button type="button" onclick="closeCatatanModal()" style="padding:8px 16px; border:none; border-radius:6px; background:#e5e7eb; cursor:pointer;">Batal</button>
                <button type="submit" name="catatan_revisi_submit" value="1" style="padding:8px 16px; border:none; border-radius:6px; background:#f59e0b; color:#fff; font-weight:600; cursor:pointer;">Kirim &amp; Notif WA</button>
            </div>
        </form>
    </div>
</div>
<style>
    .chip-alasan { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; border-radius:999px; padding:3px 10px; font-size:11.5px; cursor:pointer; }
    .chip-alasan:hover { background:#ffedd5; }
</style>
<script>
    function openCatatanModal(refTable, refId, dept, deskripsi) {
        document.getElementById('catatanRefTable').value = refTable || '';
        document.getElementById('catatanRefId').value = refId || '';
        document.getElementById('catatanDeptSelect').value = dept || '';
        var box = document.getElementById('catatanItemBox');
        if (deskripsi) {
            document.getElementById('catatanItemText').textContent = deskripsi;
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }
        document.getElementById('catatanText').value = '';
        document.getElementById('catatanModal').style.display = 'block';
        document.getElementById('catatanText').focus();
    }

    function openCatatanModalDari(btn) {
        var a = JSON.parse(btn.getAttribute('data-revisi'));
        openCatatanModal(a[0], a[1], a[2], a[3]);
    }

    function closeCatatanModal() {
        document.getElementById('catatanModal').style.display = 'none';
    }

    function isiAlasanRevisi(chip) {
        var t = document.getElementById('catatanText');
        t.value = t.value.trim() === '' ? chip.textContent : t.value.replace(/\s+$/, '') + '. ' + chip.textContent;
        t.focus();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeCatatanModal();
    });
</script>
HTML;
}

/**
 * Banner ringkas di halaman edit/upload milik PIC (edit_fpm_downtime / update_asakai):
 * daftar permintaan revisi yang masih MENUNGGU untuk FPM/ASAKAI ini + tombol ke halaman
 * Permintaan Revisi, supaya PIC yang membuka form lama tetap tahu ada yang harus diperbaiki.
 */
function renderBannerRevisiPic($koneksi, string $sumber, int $idInduk, string $dept): string
{
    $rows = array_filter(
        ambilCatatanRevisiCm($koneksi, $sumber, $idInduk, $dept),
        fn($r) => $r['status_revisi'] === 'MENUNGGU'
    );
    if (!$rows) {
        return '';
    }

    $cfg = cmRevisiConfig($sumber);
    $html = '<div style="border:1px solid #fcd34d; background:#fffbeb; border-left:5px solid #f59e0b; border-radius:8px; padding:14px 16px; margin:0 0 18px;">'
        . '<div style="font-weight:700; color:#92400e; margin-bottom:8px;"><i class="bi bi-exclamation-triangle-fill"></i> '
        . htmlspecialchars(cmRevisiPemintaDari($rows)) . ' meminta revisi (' . count($rows) . ') untuk ' . ($sumber === 'ASAKAI' ? 'Asakai' : 'FPM') . ' ini</div>';
    foreach ($rows as $r) {
        $label = !empty($r['ref_table']) ? ($cfg['refs'][$r['ref_table']]['label'] ?? '') : 'Catatan umum';
        $html .= '<div style="font-size:13px; margin-bottom:6px;"><b>' . htmlspecialchars($label) . '</b>'
            . (!empty($r['item_deskripsi']) ? ' - ' . htmlspecialchars(mb_strimwidth($r['item_deskripsi'], 0, 90, '...')) : '')
            . '<br><span style="color:#92400e;">"' . nl2br(htmlspecialchars($r['catatan'])) . '"</span></div>';
    }
    $html .= '<a href="' . cmRevisiHalamanPic(cmRevisiPemintaDari($rows)) . '" style="display:inline-block; margin-top:4px; background:#f59e0b; color:#fff; '
        . 'padding:6px 14px; border-radius:6px; font-size:13px; font-weight:600; text-decoration:none;">'
        . '<i class="bi bi-upload"></i> Upload perbaikan di halaman Permintaan Revisi</a>'
        . '</div>';
    return $html;
}
