<?php

/**
 * "Tugas Saya" - semua hal yang harus dikerjakan user login sesuai role-nya, dikumpulkan di
 * satu halaman supaya tidak perlu scroll List FPM satu per satu: FPM yang menunggu
 * terima / jawab / approve / periksa / verifikasi / setuju, countermeasure dept yang belum
 * upload bukti, permintaan revisi, verifikasi countermeasure, FPM dari Asakai, evaluasi
 * 3 bulan, dll.
 *
 * Hanya MEMBACA data - tidak mengubah status apa pun. Tahap approval diambil dari
 * ambilMonitoringApproval() (sama dengan Monitor Approval), jadi H+ & batas SP-nya sama.
 *
 * Dipakai halaman tugas_saya.php di setiap role (lihat tugas_saya_view.php) dan badge menu
 * "Tugas & Approval" di sidebar (renderMenuTugasSaya).
 *
 * Belum termasuk: FPM QA (flow_type 'QA') - alurnya belum ikut Monitor Approval.
 */

require_once __DIR__ . '/monitoring_approval_helper.php';
require_once __DIR__ . '/asakai_fpm_helper.php';
require_once __DIR__ . '/verifikasi_countermeasure_helper.php';
require_once __DIR__ . '/countermeasure_revisi_helper.php';
require_once __DIR__ . '/fpm_reschedule_helper.php';
require_once __DIR__ . '/app_setting_helper.php';

// Kelompok tugas: judul, ikon & urutan tampil.
const TUGAS_SAYA_GRUP = [
    'approval'      => ['judul' => 'FPM menunggu tindakan Anda',           'ikon' => 'bi-diagram-3-fill'],
    'cm_fpm'        => ['judul' => 'Countermeasure FPM belum upload bukti', 'ikon' => 'bi-wrench-adjustable'],
    'cm_asakai'     => ['judul' => 'Countermeasure Asakai belum upload bukti', 'ikon' => 'bi-tools'],
    'revisi'        => ['judul' => 'Permintaan revisi countermeasure',     'ikon' => 'bi-arrow-counterclockwise'],
    'verifikasi'    => ['judul' => 'Verifikasi countermeasure',            'ikon' => 'bi-patch-check-fill'],
    'revisi_cek'    => ['judul' => 'Revisi sudah diperbaiki PIC, perlu dicek', 'ikon' => 'bi-check2-square'],
    'reschedule'    => ['judul' => 'Reschedule countermeasure belum dibaca', 'ikon' => 'bi-calendar-event'],
    'asakai_fpm'    => ['judul' => 'FPM dari Asakai belum dibuat',         'ikon' => 'bi-journal-plus'],
    'evaluasi'      => ['judul' => 'Evaluasi / Verifikasi 3 bulan',        'ikon' => 'bi-clipboard2-check-fill'],
];

/**
 * Konteks user login: level, dept, plant ('1' / '3' / '' = semua plant), pihak yang
 * dianggap "saya" di Monitor Approval, dan apakah Staff dept QA (modul Claim Machining).
 */
function tugasSayaKonteks(array $sesi): array
{
    $level = $sesi['level'] ?? '';
    $dept  = trim($sesi['dept'] ?? '');
    $plant = trim(str_ireplace('Plant', '', (string) ($sesi['plant'] ?? '')));

    if ($level === 'Staff IMP' && $plant === '') {
        $plant = resolveVerifikasiPlant((string) ($sesi['username'] ?? ''), null);
    }

    $plantLbl = monitoringLabelPlant($plant);
    $pihak = [];
    if (in_array($level, ['Dept Head', 'Staff'], true) && $dept !== '') {
        $pihak[] = 'Dept Head ' . $dept;
    } elseif (in_array($level, ['Plant Head', 'Directure', 'Dept Head QA'], true)) {
        $pihak[] = $level;
    } elseif (in_array($level, ['Head IMP', 'Staff IMP'], true)) {
        $pihak = $plantLbl !== '' ? ['Head IMP ' . $plantLbl] : ['Head IMP Plant 1', 'Head IMP Plant 3', 'Head IMP'];
    }

    return [
        'level'    => $level,
        'dept'     => $dept,
        'plant'    => $plant,
        'pihak'    => $pihak,
        'staff_qa' => $level === 'Staff' && strcasecmp($dept, 'QA') === 0,
    ];
}

// Satu baris tugas. 'status': sp / warning / normal / info (warna sama dengan Monitor Approval).
function tugasSayaItem(string $grup, array $isi): array
{
    return $isi + [
        'grup'         => $grup,
        'no_form'      => '',
        'tugas'        => '',
        'detail'       => '',
        'sejak'        => null,
        'hari'         => null,
        'status'       => 'info',
        'status_label' => '',
        'link'         => '',
        'tombol'       => 'Buka',
        'jumlah'       => 1,
    ];
}

// Gabung keterangan dengan ' · ', bagian kosong dilewati.
function tugasSayaGabung(string ...$bagian): string
{
    return implode(' · ', array_filter(array_map('trim', $bagian), fn($b) => $b !== ''));
}

// Halaman detail FPM per role (Staff IMP punya halaman terpisah untuk FPM Internal).
function tugasSayaHalamanFpm(string $level, string $jenis): string
{
    if ($level === 'Staff IMP' && $jenis === 'Internal') {
        return 'form_fpm_internal.php';
    }
    return 'form_fpm.php';
}

/** FPM yang tahap approval-nya menunggu user ini (dari Monitor Approval). */
function tugasSayaApproval(mysqli $koneksi, array $k, string $hariIni): array
{
    if (!$k['pihak']) {
        return [];
    }

    $labelStatus = [
        'sp'      => 'Kena SP',
        'warning' => 'Mendekati SP',
        'normal'  => 'Dalam batas waktu',
        'info'    => 'Tanpa batas waktu',
    ];

    $hasil = [];
    foreach (ambilMonitoringApproval($koneksi, $hariIni) as $row) {
        if (!in_array($row['menunggu'], $k['pihak'], true)) {
            continue;
        }

        $id       = $row['id_fpm'];
        $isCm     = $row['jenis'] === 'Claim Machining';
        $ket      = $row['keterangan'];
        $halaman  = tugasSayaHalamanFpm($k['level'], $row['jenis']) . '?id=' . $id;
        $tombol   = 'Buka FPM';
        $tugas    = $row['tahap'];

        // Staff membantu Dept Head-nya menjawab; approve tetap tugas Dept Head.
        // Staff IMP membantu Head IMP hanya di tahap verifikasi.
        if ($k['level'] === 'Staff' && !in_array($row['aturan'], ['jawab', 'cm_jawab'], true)) {
            continue;
        }
        if ($k['level'] === 'Staff' && strpos($ket, 'menunggu approve') !== false) {
            continue;
        }
        if ($k['level'] === 'Staff IMP' && $row['aturan'] !== 'verifikasi') {
            continue;
        }

        if (in_array($k['level'], ['Dept Head', 'Staff'], true)) {
            if (strpos($ket, 'Belum diterima') === 0) {
                $tugas  = 'Terima FPM';
                $tombol = 'Terima';
                $halaman = 'form_fpm.php?id=' . $id;
            } elseif (in_array($row['aturan'], ['jawab', 'cm_jawab'], true) && strpos($ket, 'menunggu approve') === false) {
                $tugas  = $row['tahap'] === 'Revisi jawaban FPM' ? 'Revisi jawaban FPM' : 'Jawab FPM (isi countermeasure)';
                $tombol = 'Jawab';
                $halaman = ($isCm ? 'jawab_fpm_claim_machining.php' : 'jawab_fpm.php') . '?id=' . $id;
            } else {
                $tugas  = 'Approve jawaban FPM';
                $tombol = 'Approve';
            }
        } elseif ($k['level'] === 'Staff IMP') {
            $tugas = 'Bantu Head IMP verifikasi FPM';
        } else {
            $tombol = [
                'Approve Head IMP'           => 'Approve',
                'Verifikasi IMP'             => 'Verifikasi',
                'Pemeriksaan Plant Head'     => 'Periksa',
                'Persetujuan Directure'      => 'Setujui',
                'Approve Dept Head QA'       => 'Approve',
                'Terima & pilih dept tujuan' => 'Terima',
                'Approve Plant Head'         => 'Approve',
            ][$row['tahap']] ?? 'Buka FPM';
        }

        $statusLabel = $labelStatus[$row['status']];
        if ($row['status'] === 'sp' && $row['lewat_sp'] > 0) {
            $statusLabel .= ' (lewat ' . $row['lewat_sp'] . ' hari kerja)';
        } elseif (in_array($row['status'], ['normal', 'warning'], true) && $row['tgl_sp']) {
            $statusLabel .= ' · SP mulai ' . monitoringFormatTgl($row['tgl_sp']);
        }

        $hasil[] = tugasSayaItem('approval', [
            'no_form'      => $row['no_form'],
            'tugas'        => $tugas,
            'detail'       => tugasSayaGabung($row['jenis'], monitoringLabelPlant($row['plant']), $row['dept']) . ($ket !== '' ? ' — ' . $ket : ''),
            'sejak'        => $row['mulai'],
            'hari'         => $row['hari'],
            'status'       => $row['status'],
            'status_label' => $statusLabel,
            'link'         => $halaman,
            'tombol'       => $tombol,
        ]);
    }
    return $hasil;
}

// Status due date countermeasure (bukti belum diupload & belum CLOSED).
function tugasSayaStatusDue(?string $due, string $hariIni): array
{
    if ($due === null) {
        return ['info', 'Due date belum diisi'];
    }
    if ($due < $hariIni) {
        $lewat = monitoringHariKerja($due, $hariIni);
        return ['sp', 'Lewat due date' . ($lewat > 0 ? ' ' . $lewat . ' hari kerja' : '')];
    }
    if ($due === $hariIni) {
        return ['warning', 'Due hari ini'];
    }
    return ['normal', 'Due ' . monitoringFormatTgl($due)];
}

/**
 * Countermeasure FPM milik dept user (kolom pic) yang belum ada file bukti & belum CLOSED.
 * "Sudah upload" = kolom file terisi, sama dengan Verifikasi Countermeasure. Status tidak
 * diubah di sini (CLOSED hanya dari verifikasi Staff IMP / Staff QA).
 */
function tugasSayaCountermeasureFpm(mysqli $koneksi, array $k, string $hariIni): array
{
    if ($k['dept'] === '' || !in_array($k['level'], ['Dept Head', 'Staff'], true)) {
        return [];
    }

    $label = ['corrective' => 'Corrective', 'preventive' => 'Preventive', 'dokumen' => 'Update Dokumen'];
    $sql = "SELECT t.*, h.no_form, h.plant, h.jenis_fpm, h.flow_type FROM (
            SELECT 'corrective' AS jenis, id, id_fpm, tindakan AS isi, due_date, status, file, pic FROM fpm_corrective_action
            UNION ALL
            SELECT 'preventive', id, id_fpm, tindakan, due_date, status, file, pic FROM fpm_preventive_action
            UNION ALL
            SELECT 'dokumen', id, id_fpm, dokumen, due_date, status, file, pic FROM fpm_review_dokumen
        ) t
        JOIN fpm_header h ON h.id_fpm = t.id_fpm
        WHERE t.pic = ? AND IFNULL(t.file, '') = '' AND (t.status IS NULL OR t.status != 'CLOSED')
          AND IFNULL(h.flow_type, '') != 'QA'
        ORDER BY t.due_date IS NULL, t.due_date, h.no_form";

    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $k['dept']);
    $stmt->execute();
    $res = $stmt->get_result();

    $hasil = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $isCm = trim($r['flow_type'] ?? '') === 'CLAIMMACH' || strpos((string) $r['no_form'], 'FPM-Cast/') === 0;
        $due  = monitoringTgl($r['due_date']);
        [$status, $statusLabel] = tugasSayaStatusDue($due, $hariIni);

        $hasil[] = tugasSayaItem('cm_fpm', [
            'no_form'      => $r['no_form'],
            'tugas'        => 'Upload bukti ' . $label[$r['jenis']],
            'detail'       => tugasSayaGabung(mb_strimwidth(trim((string) $r['isi']), 0, 140, '…'), monitoringLabelPlant($r['plant'])),
            'sejak'        => $due,
            'status'       => $status,
            'status_label' => $statusLabel,
            'link'         => ($isCm ? 'jawab_fpm_claim_machining.php' : 'edit_fpm_downtime.php') . '?id=' . (int) $r['id_fpm'],
            'tombol'       => 'Upload bukti',
        ]);
    }
    $stmt->close();
    return $hasil;
}

/** Countermeasure Asakai (Down Time & NG) milik dept user yang belum ada file bukti & belum CLOSED. */
function tugasSayaCountermeasureAsakai(mysqli $koneksi, array $k, string $hariIni): array
{
    if ($k['dept'] === '' || !in_array($k['level'], ['Dept Head', 'Staff'], true)) {
        return [];
    }

    $sql = "SELECT t.*, h.tanggal, h.plant, h.machine, h.namapart FROM (
            SELECT 'Down Time' AS jenis, id_asakai, deskripsi, due_date, status, file1, file2, file3, pic FROM asakai_countermeasure_downtime
            UNION ALL
            SELECT 'NG', id_asakai, deskripsi, due_date, status, file1, file2, file3, pic FROM asakai_countermeasure_ng
        ) t
        JOIN asakai_header h ON h.id_asakai = t.id_asakai
        WHERE t.pic = ? AND IFNULL(t.file1, '') = '' AND IFNULL(t.file2, '') = '' AND IFNULL(t.file3, '') = ''
          AND (t.status IS NULL OR t.status != 'CLOSED')
        ORDER BY t.due_date IS NULL, t.due_date, h.tanggal";

    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $k['dept']);
    $stmt->execute();
    $res = $stmt->get_result();

    $hasil = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $due = monitoringTgl($r['due_date']);
        [$status, $statusLabel] = tugasSayaStatusDue($due, $hariIni);

        $hasil[] = tugasSayaItem('cm_asakai', [
            'no_form'      => 'Asakai ' . monitoringFormatTgl(monitoringTgl($r['tanggal'])),
            'tugas'        => 'Upload bukti countermeasure ' . $r['jenis'],
            'detail'       => tugasSayaGabung(mb_strimwidth(trim((string) $r['deskripsi']), 0, 140, '…'),
                $r['machine'] . ' ' . $r['namapart'], monitoringLabelPlant($r['plant'])),
            'sejak'        => $due,
            'status'       => $status,
            'status_label' => $statusLabel,
            'link'         => 'update_asakai.php?id=' . (int) $r['id_asakai'],
            'tombol'       => 'Upload bukti',
        ]);
    }
    $stmt->close();
    return $hasil;
}

/** Permintaan revisi countermeasure dari Staff IMP / Staff QA yang belum ditindaklanjuti dept. */
function tugasSayaRevisi(mysqli $koneksi, array $k): array
{
    if ($k['dept'] === '' || !in_array($k['level'], ['Dept Head', 'Staff'], true)) {
        return [];
    }

    $peminta = [CM_REVISI_ROLE_IMP];
    // Halaman Permintaan Revisi QA hanya ada di Dept Head (modul QA).
    if ($k['level'] === 'Dept Head') {
        $peminta[] = CM_REVISI_ROLE_QA;
    }

    $hasil = [];
    foreach ($peminta as $p) {
        $rows = array_filter(ambilPermintaanRevisiDept($koneksi, $k['dept'], $p), fn($r) => $r['status_revisi'] === 'MENUNGGU');
        foreach ($rows as $r) {
            $noForm = $r['sumber'] === 'ASAKAI'
                ? trim('Asakai ' . monitoringFormatTgl(monitoringTgl($r['tanggal'] ?? '')) . ' ' . ($r['machine'] ?? ''))
                : (string) ($r['no_form'] ?? '-');
            $item = trim((string) ($r['item_deskripsi'] ?? ''));
            $hasil[] = tugasSayaItem('revisi', [
                'no_form'      => $noForm,
                'tugas'        => 'Perbaiki countermeasure (diminta ' . $p . ')',
                'detail'       => trim(mb_strimwidth(trim((string) ($r['catatan'] ?? '')), 0, 160, '…'))
                    . ($item !== '' ? ' · Item: ' . mb_strimwidth($item, 0, 80, '…') : ''),
                'sejak'        => monitoringTgl($r['created_at'] ?? ''),
                'status'       => 'warning',
                'status_label' => 'Menunggu tindak lanjut',
                'link'         => cmRevisiHalamanPic($p) . ($p === CM_REVISI_ROLE_QA ? '?modul=qa' : ''),
                'tombol'       => 'Tindak lanjuti',
            ]);
        }
    }
    return $hasil;
}

/**
 * Staff IMP / Staff QA: countermeasure yang buktinya sudah diupload & perlu diverifikasi.
 * Daftar detailnya ditampilkan langsung di Tugas Saya (verifikasi_imp_view.php /
 * verifikasi_qa_view.php); baris di sini dipakai untuk hitungan badge & kartu ringkasan.
 */
function tugasSayaVerifikasi(mysqli $koneksi, array $k): array
{
    $hasil = [];

    if ($k['level'] === 'Staff IMP') {
        $jml = countPendingCountermeasureVerifikasi($koneksi, $k['plant']);
        $revisiCek = count(ambilRevisiPerluDicekImp($koneksi, $k['plant']));
    } elseif ($k['staff_qa']) {
        $jml = countPendingCountermeasureVerifikasi($koneksi, '', true);
        $jml['asakai'] = 0;
        $revisiCek = count(ambilRevisiPerluDicekQa($koneksi));
        $reschedule = hitungRescheduleBelumDibaca($koneksi, true);
    } else {
        return [];
    }

    // Daftar detail verifikasi tampil di halaman Tugas Saya sendiri (bagian #verifikasi).
    $link = '#verifikasi';

    foreach (['fpm' => 'Countermeasure FPM', 'asakai' => 'Countermeasure Asakai'] as $kunci => $label) {
        if ($jml[$kunci] > 0) {
            $hasil[] = tugasSayaItem('verifikasi', [
                'no_form'      => $jml[$kunci] . ' item',
                'tugas'        => 'Verifikasi ' . $label . ' (bukti sudah diupload dept)',
                'detail'       => 'Cek bukti, lalu CLOSED atau minta revisi.',
                'status'       => 'warning',
                'status_label' => 'Menunggu verifikasi',
                'link'         => $link,
                'tombol'       => 'Verifikasi',
                'jumlah'       => $jml[$kunci],
            ]);
        }
    }

    if ($revisiCek > 0) {
        $hasil[] = tugasSayaItem('revisi_cek', [
            'no_form'      => $revisiCek . ' item',
            'tugas'        => 'Cek revisi countermeasure yang sudah diperbaiki PIC',
            'status'       => 'warning',
            'status_label' => 'Perlu dicek',
            'link'         => $link,
            'tombol'       => 'Cek revisi',
            'jumlah'       => $revisiCek,
        ]);
    }

    if (!empty($reschedule)) {
        $hasil[] = tugasSayaItem('reschedule', [
            'no_form'      => $reschedule . ' item',
            'tugas'        => 'Baca reschedule due date countermeasure Claim Machining',
            'status'       => 'info',
            'status_label' => 'Belum dibaca',
            'link'         => $link,
            'tombol'       => 'Lihat',
            'jumlah'       => $reschedule,
        ]);
    }

    return $hasil;
}

/** Staff IMP: FPM dari pembahasan Asakai yang belum dibuat (H+1 peringatan, H+2 SP). */
function tugasSayaAsakaiFpm(mysqli $koneksi, array $k): array
{
    if ($k['level'] !== 'Staff IMP') {
        return [];
    }

    $label = ['sp' => 'Kena SP', 'warning' => 'Mendekati SP', 'normal' => 'Dalam batas waktu'];
    $hasil = [];
    foreach (ambilTugasAsakaiFpmTertunda($koneksi, $k['plant']) as $t) {
        $jenis = ($t['jenis'] ?? '') === 'NG' ? 'NG Internal' : 'Down Time';
        $hasil[] = tugasSayaItem('asakai_fpm', [
            'no_form'      => asakaiFpmLabelAsakai($t),
            'tugas'        => 'Buat FPM ' . $jenis,
            'detail'       => tugasSayaGabung($t['namapart'] ?? '', monitoringLabelPlant($t['plant'] ?? '')),
            'sejak'        => $t['mulai'],
            'hari'         => $t['hari'],
            'status'       => $t['status'],
            'status_label' => $label[$t['status']] . ($t['status'] !== 'sp' ? ' · SP mulai ' . monitoringFormatTgl($t['tgl_sp']) : ''),
            'link'         => 'fpm_asakai.php',
            'tombol'       => 'Buat FPM',
        ]);
    }
    return $hasil;
}

/**
 * Evaluasi 3 bulan: Staff IMP membuat (setelah N bulan sejak actual date terakhir, sama dengan
 * cron_verifikasi_3_bulan.php), Head IMP memeriksa (FPM IMP), Dept Head QA memeriksa
 * (Claim Machining), Directure menyetujui.
 */
function tugasSayaEvaluasi(mysqli $koneksi, array $k): array
{
    $isCm = "(COALESCE(h.flow_type,'') = 'CLAIMMACH' OR COALESCE(h.no_form,'') LIKE 'FPM-Cast/%')";
    $plantSql = $k['plant'] !== '' ? " AND h.plant = '" . $koneksi->real_escape_string($k['plant']) . "'" : '';
    $hasil = [];

    if ($k['level'] === 'Staff IMP') {
        $bulan = max(1, appSettingInt('reminder_verifikasi_bulan'));
        $q = $koneksi->query("SELECT h.id_fpm, h.no_form, h.plant, h.jenis_fpm, a.last_date
            FROM fpm_header h
            JOIN (
                SELECT id_fpm, MAX(actual_date) AS last_date FROM (
                    SELECT id_fpm, actual_date FROM fpm_corrective_action
                    UNION ALL SELECT id_fpm, actual_date FROM fpm_preventive_action
                    UNION ALL SELECT id_fpm, actual_date FROM fpm_review_dokumen
                ) u
                WHERE actual_date IS NOT NULL AND actual_date != '' AND actual_date != '0000-00-00'
                GROUP BY id_fpm
            ) a ON a.id_fpm = h.id_fpm
            WHERE EXISTS (SELECT 1 FROM fpm_detail_masalah d WHERE d.id_fpm = h.id_fpm)
              AND NOT EXISTS (SELECT 1 FROM fpm_detail_masalah d WHERE d.id_fpm = h.id_fpm
                    AND (IFNULL(d.verification_3_bulan,'') != '' OR IFNULL(d.verification_3_bulan2,'') != '' OR IFNULL(d.verification_3_bulan3,'') != ''))
              AND a.last_date <= DATE_SUB(CURDATE(), INTERVAL $bulan MONTH)
              AND IFNULL(h.flow_type, '') != 'QA' AND NOT $isCm $plantSql
            ORDER BY a.last_date");

        while ($q && ($r = $q->fetch_assoc())) {
            $jenis = stripos($r['jenis_fpm'] ?? '', 'Down') !== false ? 'Down Time' : 'Internal';
            $hasil[] = tugasSayaItem('evaluasi', [
                'no_form'      => $r['no_form'],
                'tugas'        => 'Isi verifikasi ' . $bulan . ' bulan',
                'detail'       => tugasSayaGabung($jenis, monitoringLabelPlant($r['plant']), 'actual date terakhir ' . monitoringFormatTgl(monitoringTgl($r['last_date']))),
                'sejak'        => monitoringTgl($r['last_date']),
                'status'       => 'warning',
                'status_label' => 'Sudah lewat ' . $bulan . ' bulan',
                'link'         => tugasSayaHalamanFpm('Staff IMP', $jenis) . '?id=' . (int) $r['id_fpm'],
                'tombol'       => 'Verifikasi',
            ]);
        }
        return $hasil;
    }

    if ($k['level'] === 'Head IMP') {
        $where = "IFNULL(e.dibuat,'') != '' AND IFNULL(e.diperiksa,'') = '' AND NOT $isCm $plantSql";
        $tugas = 'Periksa evaluasi 3 bulan';
    } elseif ($k['level'] === 'Dept Head QA') {
        $where = "IFNULL(e.dibuat,'') != '' AND IFNULL(e.diperiksa,'') = '' AND $isCm";
        $tugas = 'Approve status evaluasi Claim Machining';
    } elseif ($k['level'] === 'Directure') {
        $where = "IFNULL(e.diperiksa,'') != '' AND IFNULL(e.disetujui,'') = ''";
        $tugas = 'Setujui evaluasi 3 bulan';
    } else {
        return [];
    }

    $q = $koneksi->query("SELECT h.id_fpm, h.no_form, h.plant, h.jenis_fpm, e.tgl_dibuat
        FROM fpm_evaluasi e JOIN fpm_header h ON h.id_fpm = e.id_fpm
        WHERE $where AND IFNULL(h.flow_type, '') != 'QA'
        ORDER BY h.id_fpm");
    while ($q && ($r = $q->fetch_assoc())) {
        $hasil[] = tugasSayaItem('evaluasi', [
            'no_form'      => $r['no_form'],
            'tugas'        => $tugas,
            'detail'       => tugasSayaGabung($r['jenis_fpm'] ?? '', monitoringLabelPlant($r['plant'])),
            'sejak'        => monitoringTgl($r['tgl_dibuat'] ?? ''),
            'status'       => 'info',
            'status_label' => 'Menunggu Anda',
            'link'         => 'form_fpm.php?id=' . (int) $r['id_fpm'],
            'tombol'       => 'Buka FPM',
        ]);
    }
    return $hasil;
}

/**
 * Semua tugas user login, dikelompokkan per grup (urutan TUGAS_SAYA_GRUP), tiap grup urut
 * dari yang paling mendesak. Hasil di-cache per request (sidebar & halaman memakai yang sama).
 *
 * @return array{grup: array<string, list<array>>, total: int, mendesak: int, konteks: array}
 */
function ambilTugasSaya(mysqli $koneksi, array $sesi, ?string $hariIni = null): array
{
    static $cache = [];
    $hariIni = $hariIni ?? date('Y-m-d');
    $kunci = md5(serialize([$sesi['username'] ?? '', $sesi['level'] ?? '', $sesi['dept'] ?? '', $sesi['plant'] ?? '', $hariIni]));
    if (isset($cache[$kunci])) {
        return $cache[$kunci];
    }

    $k = tugasSayaKonteks($sesi);

    $semua = array_merge(
        tugasSayaApproval($koneksi, $k, $hariIni),
        tugasSayaCountermeasureFpm($koneksi, $k, $hariIni),
        tugasSayaCountermeasureAsakai($koneksi, $k, $hariIni),
        tugasSayaRevisi($koneksi, $k),
        tugasSayaVerifikasi($koneksi, $k),
        tugasSayaAsakaiFpm($koneksi, $k),
        tugasSayaEvaluasi($koneksi, $k)
    );

    $urutan = ['sp' => 0, 'warning' => 1, 'normal' => 2, 'info' => 3];
    $grup = array_fill_keys(array_keys(TUGAS_SAYA_GRUP), []);
    $total = 0;
    $mendesak = 0;
    foreach ($semua as $item) {
        $grup[$item['grup']][] = $item;
        $total += $item['jumlah'];
        if ($item['status'] === 'sp') {
            $mendesak += $item['jumlah'];
        }
    }
    foreach ($grup as &$daftar) {
        usort($daftar, fn($a, $b) => [$urutan[$a['status']], -($a['hari'] ?? 0), $a['sejak'] ?? '9999'] <=> [$urutan[$b['status']], -($b['hari'] ?? 0), $b['sejak'] ?? '9999']);
    }
    unset($daftar);

    return $cache[$kunci] = [
        'grup'     => array_filter($grup),
        'total'    => $total,
        'mendesak' => $mendesak,
        'konteks'  => $k,
    ];
}

/**
 * Menu "Tugas & Approval" di sidebar (gabungan Tugas Saya + Monitor Approval) + badge jumlah
 * tugas milik user (merah kalau ada yang kena SP / lewat due date). Dipanggil dari
 * sidebar.php / sidebar_qa.php tiap role. Pencatatan SP (hitungSpMonitoringApproval) tetap
 * dipanggil terpisah di sidebar.
 */
function renderMenuTugasSaya($koneksi, bool $modulQa = false): void
{
    $total = 0;
    $mendesak = 0;
    if ($koneksi instanceof mysqli && isset($_SESSION['username'])) {
        $data = ambilTugasSaya($koneksi, $_SESSION);
        $total = $data['total'];
        $mendesak = $data['mendesak'];
    }
    $aktif = in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['tugas_saya.php', 'monitoring_approval.php'], true) ? ' active' : '';
    ?>
    <li class="nav-item">
        <a href="tugas_saya.php<?= $modulQa ? '?modul=qa' : '' ?>" class="nav-link<?= $aktif ?>">
            <i class="nav-icon bi bi-list-check"></i>
            <p>
                Tugas &amp; Approval
                <?php if ($total > 0): ?>
                    <span class="badge <?= $mendesak > 0 ? 'bg-danger' : 'bg-warning text-dark' ?> ms-1"
                        title="<?= $total ?> tugas menunggu Anda<?= $mendesak > 0 ? ', ' . $mendesak . ' sudah kena SP / lewat due date' : '' ?>"><?= $total ?></span>
                <?php endif; ?>
            </p>
        </a>
    </li>
    <?php
}

/**
 * Tab di atas halaman Tugas Saya & Monitor Approval (satu menu di sidebar).
 * $aktif: 'tugas' | 'monitor'.
 */
function renderTabTugasApproval(mysqli $koneksi, string $aktif): void
{
    $qa = ($_GET['modul'] ?? '') === 'qa' ? '?modul=qa' : '';
    $tugas = ambilTugasSaya($koneksi, $_SESSION);
    $jumlahSp = count(array_filter(ambilMonitoringApproval($koneksi), fn($r) => $r['status'] === 'sp'));
    ?>
    <ul class="nav nav-tabs mt-3 mb-0 px-3" style="font-weight:600">
        <li class="nav-item">
            <a class="nav-link<?= $aktif === 'tugas' ? ' active' : '' ?>" href="tugas_saya.php<?= $qa ?>">
                <i class="bi bi-list-check"></i> Tugas Saya
                <?php if ($tugas['total'] > 0): ?>
                    <span class="badge <?= $tugas['mendesak'] > 0 ? 'bg-danger' : 'bg-warning text-dark' ?> ms-1"><?= $tugas['total'] ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $aktif === 'monitor' ? ' active' : '' ?>" href="monitoring_approval.php<?= $qa ?>">
                <i class="bi bi-diagram-3-fill"></i> Monitor Approval <span class="fw-normal text-muted">(semua FPM)</span>
                <?php if ($jumlahSp > 0): ?>
                    <span class="badge bg-danger ms-1" title="Jumlah FPM yang sudah kena SP (Surat Peringatan), semua pihak"><?= $jumlahSp ?> SP</span>
                <?php endif; ?>
            </a>
        </li>
    </ul>
    <?php
}
