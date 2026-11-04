<?php

/**
 * Simpan baris Corrective Action / Preventive Action / Update Dokumen dari form
 * Jawab/Edit FPM (dept_head, staff, plant_head jawab_fpm.php).
 *
 * Dulu tiap simpan menghapus SEMUA baris FPM lalu meng-insert ulang yang ada di form.
 * Akibatnya id baris berganti tiap simpan (catatan revisi Staff IMP, log reschedule &
 * file tambahan yang menempel ke id lama jadi yatim), dan form yang dibuka sebelum
 * orang lain upload bukti bisa menimpa bukti orang itu. Sekarang:
 *  - baris lama (row_uid = id baris) di-UPDATE per id, id tidak berubah;
 *  - baris baru dari tombol "+" di-INSERT;
 *  - baris yang dihapus di form (ada saat form dibuka, tidak dikirim lagi) dihapus per id.
 *    Baris yang ditambahkan orang lain setelah form dibuka tidak ikut terhapus;
 *  - file utama diambil dari DB saat simpan, bukan dari hidden input old_file;
 *  - file yang sudah ada TIDAK PERNAH ditimpa upload baru: upload di baris yang sudah punya
 *    file disimpan sebagai file tambahan (lihat fileUtamaJawabTanpaTimpa). Untuk mengganti
 *    file, user harus menghapus file lama dulu.
 *
 * Butuh koneksi.php (pesanErrorUpload, isVideoUploadTooLarge) sudah di-include.
 */

require_once __DIR__ . '/fpm_bukti_aksi_helper.php';

function cfgAksiJawabFpm(string $jenis): array
{
    $cfg = [
        'corrective' => [
            'tabel' => 'fpm_corrective_action', 'kolomTeks' => 'tindakan', 'prefix' => 'CORR',
            'label' => 'Corrective Action', 'pakaiNote' => true,
        ],
        'preventive' => [
            'tabel' => 'fpm_preventive_action', 'kolomTeks' => 'tindakan', 'prefix' => 'PREV',
            'label' => 'Preventive Action', 'pakaiNote' => true,
        ],
        'dokumen' => [
            'tabel' => 'fpm_review_dokumen', 'kolomTeks' => 'dokumen', 'prefix' => 'DOC',
            'label' => 'Update Dokumen', 'pakaiNote' => false,
        ],
    ];

    if (!isset($cfg[$jenis])) {
        throw new Exception("Jenis aksi tidak dikenal: $jenis");
    }
    return $cfg[$jenis];
}

/**
 * Hidden input daftar id baris yang tampil saat form dibuka - dipakai saat simpan untuk
 * membedakan "baris dihapus user di form" dari "baris baru milik orang lain".
 * Letakkan di dalam <form>, di luar <table>.
 */
function inputIdDimuatAksiJawabFpm(string $jenis, array $baris): string
{
    $ids = implode(',', array_map(fn($b) => (int) $b['id'], $baris));
    return '<input type="hidden" name="aksi_id_dimuat[' . htmlspecialchars($jenis) . ']" value="' . $ids . '">';
}

function simpanAksiJawabFpm($koneksi, int $id_fpm, string $jenis, array $snapshot, string $uploadDir): void
{
    $cfg  = cfgAksiJawabFpm($jenis);
    $post = $_POST[$jenis] ?? [];

    $barisById = [];
    foreach ($snapshot as $b) {
        $barisById[(int) $b['id']] = $b;
    }

    // Form lama yang dibuka sebelum perubahan ini belum mengirim daftar id - anggap
    // semua baris DB saat ini tampil di form (sama dengan perilaku hapus-semua dulu).
    if (isset($_POST['aksi_id_dimuat'][$jenis])) {
        $idDimuat = array_filter(array_map('intval', explode(',', (string) $_POST['aksi_id_dimuat'][$jenis])));
    } else {
        $idDimuat = array_keys($barisById);
    }

    $idDisimpan = [];

    foreach ($post[$cfg['kolomTeks']] ?? [] as $i => $teks) {

        if ($teks == '') continue;

        // row_uid = id baris lama, atau "new..." untuk baris dari tombol "+".
        $rowUid = $post['row_uid'][$i] ?? $i;
        $idLama = isset($post['row_uid'][$i]) && ctype_digit((string) $rowUid) ? (int) $rowUid : 0;
        $barisLama = ($idLama > 0 && !in_array($idLama, $idDisimpan, true)) ? ($barisById[$idLama] ?? null) : null;

        $fileForm   = (string) ($post['old_file'][$i] ?? '');
        // actual_date adalah varchar NOT NULL, jadi string kosong bukan null.
        $actualForm = (string) ($post['actual_date'][$i] ?? '');

        if ($barisLama !== null) {
            $fileName = (string) ($barisLama['file'] ?? '');
            // File di DB sudah berubah sejak form dibuka (orang lain upload) -> actual_date
            // milik upload itu ikut dipertahankan.
            $actual_date = ($fileName !== $fileForm) ? (string) ($barisLama['actual_date'] ?? '') : $actualForm;
        } else {
            $fileName = $fileForm;
            $actual_date = $actualForm;
        }

        $fileField = $jenis . '_file';
        $errUpload = pesanErrorUpload($_FILES[$fileField]['error'][$i] ?? UPLOAD_ERR_NO_FILE, $cfg['label'] . ' baris ' . ($i + 1));
        if ($errUpload !== null) {
            throw new Exception($errUpload);
        }

        // Upload baru tidak pernah mengganti file yang sudah ada - kalau baris sudah punya
        // file, upload ini jadi file tambahan (dulu file lama tertimpa & hilang dari daftar).
        $barisFile = $barisLama ?? ($fileName !== '' ? ['id' => 0, 'file' => $fileName] : null);
        [$fileName, $utamaJadiTambahan] = fileUtamaJawabTanpaTimpa(
            $koneksi, $jenis, $barisFile, $fileField, $i, $uploadDir, $cfg['prefix'], $cfg['label'] . ' baris ' . ($i + 1)
        );

        $uploadedExtra = ambilFileTambahanBaruUpload($jenis . '_extra_file', $rowUid);
        $newExtraFiles = simpanFileTambahanBaruUpload($uploadedExtra, $uploadDir, $cfg['prefix'], $rowUid, $cfg['label']);

        // Status CLOSED cuma boleh diset lewat Verifikasi Countermeasure - diambil dari DB
        // per id baris, bukan dari hidden input / urutan baris.
        $statusLama = strtoupper(trim($barisLama['status'] ?? 'OPEN'));
        $status = ($statusLama === 'CLOSED') ? 'CLOSED' : 'OPEN';

        $data = [
            $cfg['kolomTeks'] => $teks,
            'due_date'        => !empty($post['due_date'][$i]) ? $post['due_date'][$i] : null,
            'actual_date'     => $actual_date,
            'pic'             => $post['pic'][$i] ?? '',
            'status'          => $status,
            'file'            => $fileName,
        ];
        if ($cfg['pakaiNote']) {
            $data['note'] = $post['note'][$i] ?? '';
        }

        $kolom = array_keys($data);
        $nilai = array_values($data);

        if ($barisLama !== null) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", $kolom));
            $stmt = $koneksi->prepare("UPDATE {$cfg['tabel']} SET $set WHERE id = ? AND id_fpm = ?");
            $nilai[] = $idLama;
            $nilai[] = $id_fpm;
            $stmt->bind_param(str_repeat('s', count($kolom)) . 'ii', ...$nilai);
        } else {
            $placeholder = implode(', ', array_fill(0, count($kolom) + 1, '?'));
            $stmt = $koneksi->prepare("INSERT INTO {$cfg['tabel']} (id_fpm, " . implode(', ', $kolom) . ") VALUES ($placeholder)");
            array_unshift($nilai, $id_fpm);
            $stmt->bind_param('i' . str_repeat('s', count($kolom)), ...$nilai);
        }

        if (!$stmt->execute()) {
            throw new Exception(pesanErrorDb("Gagal menyimpan {$cfg['label']} baris " . ($i + 1), $stmt));
        }

        if ($barisLama !== null) {
            $parentId = $idLama;
            // File tambahan lama tetap di DB (tidak dihapus-tulis ulang), cukup tambah yang baru.
            $extraDisimpan = array_merge($utamaJadiTambahan, $newExtraFiles);
        } else {
            $parentId = (int) $koneksi->insert_id;
            $oldExtraFiles = $post['old_extra_file'][$rowUid] ?? [];
            if (!is_array($oldExtraFiles)) $oldExtraFiles = [$oldExtraFiles];
            $extraDisimpan = array_merge($oldExtraFiles, $utamaJadiTambahan, $newExtraFiles);
        }

        simpanFileTambahanAksi($koneksi, $jenis, $parentId, $extraDisimpan);
        $idDisimpan[] = $parentId;
    }

    // Baris yang tampil saat form dibuka tapi tidak dikirim lagi = dihapus user di form.
    $meta = metaFileTambahanAksi($jenis);
    foreach ($idDimuat as $idHapus) {
        if (in_array($idHapus, $idDisimpan, true) || !isset($barisById[$idHapus])) continue;

        $stmt = $koneksi->prepare("DELETE FROM {$meta['table']} WHERE {$meta['kolom']} = ?");
        $stmt->bind_param("i", $idHapus);
        $stmt->execute();

        $stmt = $koneksi->prepare("DELETE FROM {$cfg['tabel']} WHERE id = ? AND id_fpm = ?");
        $stmt->bind_param("ii", $idHapus, $id_fpm);
        $stmt->execute();
    }
}
