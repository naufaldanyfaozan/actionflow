<?php

/**
 * File bukti countermeasure Asakai (kolom file1-file3 di asakai_countermeasure_downtime /
 * asakai_countermeasure_ng): tampil dengan tombol ✕ untuk hapus, upload baru mengisi slot
 * yang masih kosong (dulu file baru ke-1 menimpa file1, ke-2 menimpa file2, dst.).
 *
 * Dipakai: update_asakai.php & proses_update_asakai.php di semua role, hapus lewat
 * config/ajax_hapus_bukti_asakai.php. Tampilan input upload: config/upload_bukti_ui_helper.php.
 */

require_once __DIR__ . '/upload_bukti_ui_helper.php';
require_once __DIR__ . '/app_setting_helper.php';

// Diatur Admin IT (app_setting bukti_asakai_maks_file), default 3 - maksimal 3 karena slot
// file di tabel countermeasure Asakai hanya file1-file3.
define('ASAKAI_BUKTI_MAX_FILE', appSettingInt('bukti_asakai_maks_file'));
// Inline supaya tampil sama walau halaman tidak punya CSS .file-preview (mis. Head IMP).
const ASAKAI_BUKTI_IKON_STYLE = 'width:55px;height:55px;display:flex;align-items:center;justify-content:center;border-radius:6px;background:#fee2e2;color:#b91c1c;font-size:12px;font-weight:700;';
const ASAKAI_BUKTI_TABEL = [
    'dt' => ['tabel' => 'asakai_countermeasure_downtime', 'id' => 'id_cm_dt'],
    'ng' => ['tabel' => 'asakai_countermeasure_ng', 'id' => 'id_cm_ng'],
];

/** Sama dengan aturan hapus bukti FPM: hanya Dept Head & Staff IMP. */
function asakaiBolehHapusBukti(): bool
{
    return in_array((string) ($_SESSION['level'] ?? ''), ['Dept Head', 'Staff IMP'], true);
}

function fileBuktiAsakai(array $cm): array
{
    return array_values(array_filter([$cm['file1'] ?? '', $cm['file2'] ?? '', $cm['file3'] ?? ''], fn($f) => (string) $f !== ''));
}

/**
 * File yang sudah tersimpan (✕ untuk hapus) + input upload (tombol + / ✕) untuk satu baris countermeasure.
 * $jenis 'dt' / 'ng', $i = index baris di form.
 */
function renderUploadBuktiAsakai(array $cm, string $jenis, $i, string $accept = 'image/*,.pdf,video/*'): string
{
    $files = fileBuktiAsakai($cm);
    $sisa = max(0, ASAKAI_BUKTI_MAX_FILE - count($files));
    $idCm = (int) ($cm[ASAKAI_BUKTI_TABEL[$jenis]['id']] ?? 0);

    $html = '<div class="file-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">';
    foreach ($files as $file) {
        $html .= renderFileBuktiAsakai($jenis, $idCm, $file, asakaiBolehHapusBukti());
    }
    $html .= '</div>';

    $html .= '<input type="file" name="upload_' . $jenis . '[' . htmlspecialchars((string) $i) . '][]" accept="' . htmlspecialchars($accept) . '" multiple'
        . ' class="input-bukti-asakai" data-bukti-ui data-sisa="' . $sisa . '"' . ($sisa === 0 ? ' disabled' : '') . '>';
    $html .= '<small class="bu-hint" style="display:block;font-size:11px;color:#6c757d;margin-top:2px;">Maks. upload ' . ASAKAI_BUKTI_MAX_FILE . ' file</small>';
    return $html;
}

function renderFileBuktiAsakai(string $jenis, int $idCm, string $file, bool $bisaHapus): string
{
    $url = htmlspecialchars('../uploads/asakai/' . $file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $isi = '<img src="' . $url . '" style="width:55px;height:55px;object-fit:cover;border-radius:6px;">';
    } elseif ($ext === 'pdf') {
        $isi = '<div class="pdf-icon" style="' . ASAKAI_BUKTI_IKON_STYLE . '">PDF</div>';
    } elseif (in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'], true)) {
        $isi = '<video src="' . $url . '" style="width:55px;height:55px;object-fit:cover;border-radius:6px;" muted></video>';
    } else {
        $isi = '<div class="pdf-icon" style="' . ASAKAI_BUKTI_IKON_STYLE . '">FILE</div>';
    }

    $html = '<span class="bukti-asakai-item" style="position:relative;display:inline-block;margin-right:4px;">'
        . '<a href="' . $url . '" target="_blank" title="' . htmlspecialchars($file) . '">' . $isi . '</a>';
    if ($bisaHapus) {
        $html .= '<button type="button" class="bu-hapus-lama" title="Hapus file ini" onclick="hapusBuktiAsakai(this)"'
            . ' data-jenis="' . $jenis . '" data-id="' . $idCm . '" data-file="' . htmlspecialchars($file, ENT_QUOTES) . '"'
            . ' style="position:absolute;top:-4px;right:-6px;width:20px;height:20px;padding:0;line-height:18px;border:none;'
            . 'border-radius:50%;background:#dc2626;color:#fff;font-size:12px;font-weight:700;cursor:pointer;">&times;</button>';
    }
    return $html . '</span>';
}

/**
 * SQL "file1 = .., file2 = .., file3 = .." untuk UPDATE: file lama tetap, file baru mengisi
 * slot kosong berikutnya. File baru yang tidak kebagian slot dihapus dari disk.
 */
function sqlSlotFileAsakai($koneksi, string $jenis, int $idCm, array $filesBaru, string $uploadDir): string
{
    $filesBaru = array_values(array_filter($filesBaru, fn($f) => (string) $f !== ''));
    if (!$filesBaru) {
        return 'file1 = file1, file2 = file2, file3 = file3';
    }

    $cfg = ASAKAI_BUKTI_TABEL[$jenis];
    $stmt = $koneksi->prepare("SELECT file1, file2, file3 FROM {$cfg['tabel']} WHERE {$cfg['id']} = ?");
    $stmt->bind_param("i", $idCm);
    $stmt->execute();
    $lama = fileBuktiAsakai($stmt->get_result()->fetch_assoc() ?? []);

    $semua = array_merge($lama, $filesBaru);
    foreach (array_slice($semua, ASAKAI_BUKTI_MAX_FILE) as $lebih) {
        if (in_array($lebih, $filesBaru, true) && is_file($uploadDir . $lebih)) @unlink($uploadDir . $lebih);
    }
    return sqlNilaiSlotAsakai($koneksi, array_slice($semua, 0, ASAKAI_BUKTI_MAX_FILE));
}

function sqlNilaiSlotAsakai($koneksi, array $files): string
{
    $set = [];
    for ($k = 0; $k < ASAKAI_BUKTI_MAX_FILE; $k++) {
        $set[] = 'file' . ($k + 1) . ' = ' . (isset($files[$k]) ? "'" . mysqli_real_escape_string($koneksi, $files[$k]) . "'" : 'NULL');
    }
    return implode(', ', $set);
}

/**
 * Hapus satu file bukti (salah upload); file sesudahnya naik mengisi slot yang kosong.
 * Status & actual_date baris tidak disentuh (sama dengan hapus bukti FPM).
 *
 * @return array{ok: bool, pesan: string, jumlah?: int}
 */
function hapusFileBuktiAsakai($koneksi, string $jenis, int $idCm, string $file, string $uploadDir): array
{
    $cfg = ASAKAI_BUKTI_TABEL[$jenis] ?? null;
    if ($cfg === null || $idCm <= 0 || $file === '') {
        return ['ok' => false, 'pesan' => 'Data file tidak lengkap.'];
    }

    $koneksi->begin_transaction();
    try {
        $stmt = $koneksi->prepare("SELECT file1, file2, file3 FROM {$cfg['tabel']} WHERE {$cfg['id']} = ? FOR UPDATE");
        $stmt->bind_param("i", $idCm);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        if (!$baris) {
            throw new Exception('Baris countermeasure tidak ditemukan.');
        }

        $files = fileBuktiAsakai($baris);
        $idx = array_search($file, $files, true);
        if ($idx === false) {
            throw new Exception('File sudah tidak ada di baris ini. Muat ulang halaman.');
        }
        array_splice($files, $idx, 1);

        $koneksi->query("UPDATE {$cfg['tabel']} SET " . sqlNilaiSlotAsakai($koneksi, $files) . " WHERE {$cfg['id']} = $idCm");
        $koneksi->commit();
    } catch (Exception $e) {
        $koneksi->rollback();
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }

    $path = $uploadDir . basename($file);
    if (is_file($path)) @unlink($path);

    return ['ok' => true, 'pesan' => 'File berhasil dihapus.', 'jumlah' => count($files)];
}

/** JS sekali per halaman: tombol + / ✕ upload & hapus file bukti tersimpan lewat AJAX. */
function renderScriptBuktiAsakai(string $endpoint = '../config/ajax_hapus_bukti_asakai.php'): string
{
    $endpointJs = json_encode($endpoint);
    $max = ASAKAI_BUKTI_MAX_FILE;

    return renderUploadBuktiUI() . <<<HTML
<script>
    function hapusBuktiAsakai(btn) {
        if (!confirm('Hapus file ini? File yang dihapus tidak bisa dikembalikan.')) return;

        const fd = new FormData();
        fd.append('jenis', btn.dataset.jenis);
        fd.append('id_cm', btn.dataset.id);
        fd.append('file', btn.dataset.file);
        btn.disabled = true;

        fetch({$endpointJs}, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json().catch(() => { throw new Error('Respon server tidak valid (HTTP ' + r.status + ')'); }))
            .then(res => {
                if (!res.ok) throw new Error(res.pesan || 'Gagal menghapus file');

                const sel = btn.closest('td');
                btn.closest('.bukti-asakai-item').remove();
                const input = sel ? sel.querySelector('input.input-bukti-asakai') : null;
                if (input) {
                    const sisa = Math.max(0, {$max} - res.jumlah);
                    input.disabled = sisa === 0;
                    input.dataset.sisa = sisa;
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
