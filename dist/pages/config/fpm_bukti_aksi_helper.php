<?php

/**
 * Upload bukti countermeasure FPM (Corrective/Preventive/Update Dokumen) lebih dari 1
 * file per baris - maksimal FPM_BUKTI_AKSI_MAX_FILE file per baris.
 *
 * File pertama tetap disimpan di kolom `file` baris (dipakai di semua halaman lama),
 * file ke-2 dst. di tabel fpm_corrective_files / fpm_preventive_files / fpm_dokumen_files
 * (sama dengan file tambahan dari Jawab FPM). Upload baru DITAMBAHKAN ke file yang sudah
 * ada, ditolak kalau totalnya melebihi batas.
 *
 * Dipakai: dept_head & staff edit_fpm_downtime.php, staff_imp/update_countermeasure.php dan
 * staff/update_countermeasure_qa.php (upload, juga saat baris sudah CLOSED). File yang salah
 * upload bisa dihapus per file lewat config/ajax_hapus_bukti_aksi_fpm.php.
 */

require_once __DIR__ . '/fpm_lampiran_helper.php';
require_once __DIR__ . '/upload_bukti_ui_helper.php';
require_once __DIR__ . '/app_setting_helper.php';

// Diatur Admin IT (app_setting bukti_fpm_maks_file), default 3.
define('FPM_BUKTI_AKSI_MAX_FILE', appSettingInt('bukti_fpm_maks_file'));
const FPM_BUKTI_AKSI_EXT = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv'];

/**
 * Daftar file yang dipilih untuk satu baris dari input name="{$field}[$key][]" multiple.
 * Form lama (masih terbuka sebelum fitur ini, name="{$field}[$key]" tunggal) tetap terbaca.
 */
function ambilUploadBuktiAksi(string $field, $key): array
{
    if (!isset($_FILES[$field]['name'][$key])) return [];

    $f = $_FILES[$field];
    $names = $f['name'][$key];
    $tunggal = !is_array($names);

    $hasil = [];
    foreach ((array) $names as $j => $name) {
        $error = $tunggal ? $f['error'][$key] : $f['error'][$key][$j];
        if ($name === '' || (int) $error === UPLOAD_ERR_NO_FILE) continue;

        $hasil[] = [
            'name'  => $name,
            'tmp'   => $tunggal ? $f['tmp_name'][$key] : $f['tmp_name'][$key][$j],
            'size'  => $tunggal ? $f['size'][$key] : $f['size'][$key][$j],
            'error' => (int) $error,
        ];
    }
    return $hasil;
}

/** Upload tunggal input name="{$field}[]" untuk baris ke-$i, null kalau tidak ada file dipilih. */
function ambilUploadUtamaBaris(string $field, $i): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'][$i]) || is_array($f['name'][$i]) || $f['name'][$i] === '') {
        return null;
    }
    if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return [
        'name'  => $f['name'][$i],
        'tmp'   => $f['tmp_name'][$i],
        'size'  => $f['size'][$i],
        'error' => (int) $f['error'][$i],
    ];
}

/**
 * Slot file utama baris countermeasure di form Jawab FPM: file yang sudah ada TIDAK PERNAH
 * diganti upload baru. Aturannya: kalau mau mengganti, user menghapus file lama dulu.
 *  - File lama diambil dari baris DB saat ini, bukan dari hidden input old_file (bisa basi
 *    kalau sejak form dibuka ada yang upload / hapus file).
 *  - Baris belum punya file -> upload jadi file utama. Sudah punya -> upload jadi file
 *    tambahan, ditolak kalau total melebihi FPM_BUKTI_AKSI_MAX_FILE.
 *
 * @param ?array $barisDb baris countermeasure di DB (null = baris baru dari tombol "+")
 * @return array{0: string, 1: string[]} [isi kolom `file`, file yang harus disimpan sebagai
 *         file tambahan lewat simpanFileTambahanAksi setelah id barisnya pasti]
 */
function fileUtamaJawabTanpaTimpa($koneksi, string $jenis, ?array $barisDb, string $field, $i, string $uploadDir, string $prefix, string $label): array
{
    // Baris tidak ketemu di DB: baris baru (hidden old_file kosong) atau form lama yang belum
    // mengirim id_lama - pakai old_file supaya file lamanya tidak dianggap kosong & hilang.
    $fileUtama = $barisDb !== null
        ? (string) ($barisDb['file'] ?? '')
        : (string) ($_POST[$jenis]['old_file'][$i] ?? '');
    $upload = ambilUploadUtamaBaris($field, $i);
    if ($upload === null) {
        return [$fileUtama, []];
    }

    $jumlahLama = ($fileUtama !== '' ? 1 : 0)
        + ($barisDb !== null ? count(ambilFileTambahanAksi($koneksi, $jenis, (int) $barisDb['id'])) : 0);

    return prosesUploadBuktiAksi([$upload], $fileUtama, $jumlahLama, $uploadDir, $prefix, $label);
}

/** Semua file bukti satu baris: file utama (kolom `file`) + file tambahan. */
function semuaFileBuktiAksi($koneksi, string $jenis, array $baris): array
{
    $files = [];
    if (!empty($baris['file'])) $files[] = $baris['file'];
    if (!empty($baris['id'])) {
        $files = array_merge($files, ambilFileTambahanAksi($koneksi, $jenis, (int) $baris['id']));
    }
    return $files;
}

/**
 * Validasi lalu pindahkan file yang baru diupload untuk satu baris.
 * $jumlahFileLama = jumlah file bukti yang sudah ada di baris itu (lihat semuaFileBuktiAksi).
 *
 * @return array{0: string, 1: string[]} [isi kolom `file` yang baru, file tambahan yang
 *         harus disimpan lewat simpanFileTambahanAksi setelah id barisnya pasti]
 */
function prosesUploadBuktiAksi(array $uploads, string $fileUtama, int $jumlahFileLama, string $uploadDir, string $prefix, string $label): array
{
    if (!$uploads) return [$fileUtama, []];

    // Semua divalidasi dulu sebelum ada file yang dipindahkan.
    foreach ($uploads as $u) {
        $err = pesanErrorUpload($u['error'], "$label ({$u['name']})");
        if ($err !== null) throw new Exception($err);

        $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, FPM_BUKTI_AKSI_EXT, true)) {
            throw new Exception("Tipe file {$u['name']} pada $label tidak diizinkan.");
        }
        if (isVideoUploadTooLarge($u['name'], $u['size'])) {
            throw new Exception("Ukuran video {$u['name']} pada $label melebihi 10MB");
        }
    }

    if ($jumlahFileLama + count($uploads) > FPM_BUKTI_AKSI_MAX_FILE) {
        throw new Exception("$label maksimal " . FPM_BUKTI_AKSI_MAX_FILE . " file per baris (sudah ada $jumlahFileLama file, dipilih " . count($uploads) . " file)."
            . " File lama tidak ditimpa - kalau mau mengganti, hapus dulu file lamanya.");
    }

    $tambahan = [];
    foreach ($uploads as $u) {
        $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
        $nama = namaFileUploadUnik($prefix, $ext, $uploadDir);

        if (!pindahkanUploadAman($u['tmp'], $uploadDir . $nama)) {
            throw new Exception("Gagal menyimpan file {$u['name']} pada $label di server. Silakan upload ulang.");
        }

        if ($fileUtama === '') {
            $fileUtama = $nama;
        } else {
            $tambahan[] = $nama;
        }
    }

    return [$fileUtama, $tambahan];
}

/** Input file multiple untuk satu baris + keterangan sisa kuota (tampil sebagai tombol + / ✕, lihat upload_bukti_ui_helper.php). */
function renderInputBuktiAksi(string $name, int $jumlahFileLama): string
{
    $sisa = max(0, FPM_BUKTI_AKSI_MAX_FILE - $jumlahFileLama);
    $html = '<input type="file" name="' . htmlspecialchars($name) . '[]" multiple accept="image/*,.pdf,video/*,.doc,.docx,.xls,.xlsx"'
        . ' class="input-bukti-aksi" data-bukti-ui data-sisa="' . $sisa . '"' . ($sisa === 0 ? ' disabled' : '') . '>';
    $html .= '<small class="upload-video-hint bu-hint" style="display:block;">'
        . ($sisa === 0
            ? 'Sudah ' . FPM_BUKTI_AKSI_MAX_FILE . ' file (maksimal)'
            : 'Bisa pilih maks ' . $sisa . ' file lagi (total maks ' . FPM_BUKTI_AKSI_MAX_FILE . ' file)')
        . '</small>';
    return $html;
}

/** Preview satu file bukti (gambar / pdf / video / tautan). */
function renderPreviewBuktiAksi(string $file, string $baseUrl = '../uploads/fpm_dt/'): string
{
    $path = htmlspecialchars($baseUrl . $file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $isi = '<a href="' . $path . '" target="_blank"><img src="' . $path . '" style="width:80px;"></a>';
    } elseif ($ext === 'pdf') {
        $isi = '<a href="' . $path . '" target="_blank"><iframe src="' . $path . '" width="80" height="100"></iframe></a>';
    } elseif (in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'], true)) {
        $isi = '<video src="' . $path . '" style="width:120px;" controls></video>';
    } else {
        $isi = '<a href="' . $path . '" target="_blank">📎 ' . htmlspecialchars($file) . '</a>';
    }
    return '<div class="file-preview">' . $isi . '</div>';
}

const FPM_BUKTI_AKSI_TABEL = [
    'corrective' => 'fpm_corrective_action',
    'preventive' => 'fpm_preventive_action',
    'dokumen'    => 'fpm_review_dokumen',
];

/**
 * Kolom `file` baris countermeasure langsung dari DB, null kalau baris tidak ada di FPM ini.
 * Dipakai saat simpan supaya tidak menimpa file dengan hidden input old_file yang sudah basi
 * (mis. file baru saja dihapus lewat tombol hapus / diupload dept lain).
 */
function fileUtamaBuktiAksi($koneksi, string $jenis, int $idAksi, int $idFpm): ?string
{
    $tabel = FPM_BUKTI_AKSI_TABEL[$jenis] ?? null;
    if ($tabel === null) return null;

    $stmt = $koneksi->prepare("SELECT file FROM $tabel WHERE id = ? AND id_fpm = ?");
    $stmt->bind_param("ii", $idAksi, $idFpm);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (string) ($row['file'] ?? '') : null;
}

/**
 * Hapus satu file bukti baris countermeasure (salah upload). Kalau yang dihapus file utama
 * (kolom `file`), file tambahan pertama naik jadi file utama supaya halaman lama yang hanya
 * membaca kolom `file` tetap menampilkan bukti. Status & actual_date baris tidak disentuh.
 *
 * @return array{ok: bool, pesan: string, file_utama?: string, jumlah?: int}
 */
function hapusFileBuktiAksi($koneksi, int $idFpm, string $jenis, int $idAksi, string $file, string $uploadDir): array
{
    $tabel = FPM_BUKTI_AKSI_TABEL[$jenis] ?? null;
    if ($tabel === null || $idAksi <= 0 || $file === '') {
        return ['ok' => false, 'pesan' => 'Data file tidak lengkap.'];
    }
    $meta = metaFileTambahanAksi($jenis);

    $koneksi->begin_transaction();
    try {
        $stmt = $koneksi->prepare("SELECT id, file FROM $tabel WHERE id = ? AND id_fpm = ? FOR UPDATE");
        $stmt->bind_param("ii", $idAksi, $idFpm);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        if (!$baris) {
            throw new Exception('Baris countermeasure tidak ditemukan.');
        }

        $fileUtama = (string) ($baris['file'] ?? '');

        if ($file === $fileUtama) {
            $stmt = $koneksi->prepare("SELECT id, file FROM {$meta['table']} WHERE {$meta['kolom']} = ? ORDER BY id ASC LIMIT 1");
            $stmt->bind_param("i", $idAksi);
            $stmt->execute();
            $pengganti = $stmt->get_result()->fetch_assoc();

            $fileUtama = $pengganti ? (string) $pengganti['file'] : '';
            $stmt = $koneksi->prepare("UPDATE $tabel SET file = ? WHERE id = ?");
            $stmt->bind_param("si", $fileUtama, $idAksi);
            $stmt->execute();

            if ($pengganti) {
                $idPengganti = (int) $pengganti['id'];
                $stmt = $koneksi->prepare("DELETE FROM {$meta['table']} WHERE id = ?");
                $stmt->bind_param("i", $idPengganti);
                $stmt->execute();
            }
        } else {
            $stmt = $koneksi->prepare("DELETE FROM {$meta['table']} WHERE {$meta['kolom']} = ? AND file = ? LIMIT 1");
            $stmt->bind_param("is", $idAksi, $file);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new Exception('File sudah tidak ada di baris ini. Muat ulang halaman.');
            }
        }

        $koneksi->commit();
    } catch (Exception $e) {
        $koneksi->rollback();
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }

    $path = $uploadDir . basename($file);
    if (is_file($path)) @unlink($path);

    $jumlah = ($fileUtama !== '' ? 1 : 0) + count(ambilFileTambahanAksi($koneksi, $jenis, $idAksi));
    return ['ok' => true, 'pesan' => 'File berhasil dihapus.', 'file_utama' => $fileUtama, 'jumlah' => $jumlah];
}

/** Preview file bukti + tombol hapus (lihat renderScriptHapusBuktiAksi). */
function renderPreviewBuktiAksiHapus(string $jenis, int $idAksi, string $file): string
{
    return '<div class="bukti-aksi-item" style="position:relative; display:inline-block; margin-right:4px;">'
        . renderPreviewBuktiAksi($file)
        . '<button type="button" class="bu-hapus-lama" title="Hapus file ini" onclick="hapusBuktiAksi(this)"'
        . ' data-jenis="' . htmlspecialchars($jenis) . '" data-id="' . $idAksi . '" data-file="' . htmlspecialchars($file, ENT_QUOTES) . '"'
        . ' style="position:absolute; top:0; right:-6px; width:20px; height:20px; padding:0; line-height:18px; border:none;'
        . ' border-radius:50%; background:#dc2626; color:#fff; font-size:12px; font-weight:700; cursor:pointer;">&times;</button>'
        . '</div>';
}

/**
 * JS sekali per halaman untuk tombol hapus file bukti: hapus lewat AJAX, lalu kuota input
 * file di sel yang sama dibuka lagi tanpa reload (isian form lain tidak hilang).
 */
function renderScriptHapusBuktiAksi(int $idFpm, string $endpoint = '../config/ajax_hapus_bukti_aksi_fpm.php'): string
{
    $idFpmJs = json_encode($idFpm);
    $endpointJs = json_encode($endpoint);
    $max = FPM_BUKTI_AKSI_MAX_FILE;

    return <<<HTML
<script>
    function hapusBuktiAksi(btn) {
        if (!confirm('Hapus file ini? File yang dihapus tidak bisa dikembalikan.')) return;

        const fd = new FormData();
        fd.append('id_fpm', {$idFpmJs});
        fd.append('jenis', btn.dataset.jenis);
        fd.append('id_aksi', btn.dataset.id);
        fd.append('file', btn.dataset.file);
        btn.disabled = true;

        fetch({$endpointJs}, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json().catch(() => { throw new Error('Respon server tidak valid (HTTP ' + r.status + ')'); }))
            .then(res => {
                if (!res.ok) throw new Error(res.pesan || 'Gagal menghapus file');

                const sel = btn.closest('td');
                btn.closest('.bukti-aksi-item').remove();
                if (!sel) return;

                const oldFile = sel.querySelector('input[type="hidden"][name*="old_file"]');
                if (oldFile) oldFile.value = res.file_utama;

                const input = sel.querySelector('input.input-bukti-aksi');
                if (input) {
                    const sisa = Math.max(0, {$max} - res.jumlah);
                    input.dataset.sisa = sisa;
                    input.disabled = sisa === 0;
                    const hint = input.nextElementSibling;
                    if (hint && hint.classList.contains('upload-video-hint')) {
                        hint.textContent = sisa === 0
                            ? 'Sudah {$max} file (maksimal)'
                            : 'Bisa pilih maks ' + sisa + ' file lagi (total maks {$max} file)';
                    }
                }
            })
            .catch(err => {
                btn.disabled = false;
                alert(err.message);
            });
    }
</script>
HTML;
}

/** JS sekali per halaman: tolak pilihan file yang melebihi sisa kuota baris + tampilan tombol + / ✕. */
function renderScriptBuktiAksi(): string
{
    $max = FPM_BUKTI_AKSI_MAX_FILE;
    return renderUploadBuktiUI() . <<<HTML
<script>
    document.addEventListener('change', function(e) {
        if (!e.target.matches || !e.target.matches('input.input-bukti-aksi')) return;
        const sisa = parseInt(e.target.dataset.sisa || '0', 10);
        if (e.target.files.length > sisa) {
            alert('Maksimal ' + sisa + ' file lagi untuk baris ini (total maks {$max} file per baris). File lama tidak ditimpa - hapus dulu kalau mau mengganti.');
            e.target.value = '';
        }
    });
</script>
HTML;
}
