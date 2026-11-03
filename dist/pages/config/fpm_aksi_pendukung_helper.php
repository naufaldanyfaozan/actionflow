<?php

/**
 * Dokumen pendukung per baris Corrective/Preventive/Update Dokumen FPM (tabel fpm_aksi_pendukung,
 * lihat migration_2026_11_03_fpm_aksi_pendukung.sql).
 *
 * BUKAN bukti perbaikan: tidak dihitung kuota FPM_BUKTI_AKSI_MAX_FILE, tidak mengisi actual_date,
 * tidak mengubah status OPEN/CLOSED dan tidak masuk Verifikasi Staff IMP. Boleh ditambah walau
 * barisnya sudah CLOSED. Dokumen pendukung untuk FPM secara umum tetap di fpm_lampiran.
 *
 * Input form: name="pendukung[<jenis>][<id_aksi>][]" (multiple), hapus: name="hapus_pendukung[]".
 */

require_once __DIR__ . '/fpm_bukti_aksi_helper.php';

const FPM_PENDUKUNG_MAX_FILE = 5;

/** Semua dokumen pendukung satu FPM, dikelompokkan [jenis][id_aksi] => [baris, ...]. */
function ambilPendukungAksiFpm($koneksi, int $idFpm): array
{
    $data = [];
    if ($idFpm <= 0) return $data;

    $stmt = $koneksi->prepare("SELECT id, jenis, id_aksi, file, original_name FROM fpm_aksi_pendukung WHERE id_fpm = ? ORDER BY id ASC");
    $stmt->bind_param("i", $idFpm);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $data[$row['jenis']][(int) $row['id_aksi']][] = $row;
    }

    return $data;
}

/** Pastikan baris aksi memang milik FPM ini (id_aksi dari form bisa dimanipulasi client). */
function aksiMilikFpm($koneksi, string $jenis, int $idAksi, int $idFpm): bool
{
    $tabel = FPM_BUKTI_AKSI_TABEL[$jenis] ?? null;
    if ($tabel === null) return false;

    $stmt = $koneksi->prepare("SELECT 1 FROM $tabel WHERE id = ? AND id_fpm = ?");
    $stmt->bind_param("ii", $idAksi, $idFpm);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

/** Simpan upload dokumen pendukung baru dari $_FILES['pendukung'] (dipanggil di dalam transaksi). */
function simpanPendukungAksiBaru($koneksi, int $idFpm, string $uploadDir, string $oleh): void
{
    if (empty($_FILES['pendukung']['name']) || !is_array($_FILES['pendukung']['name'])) return;

    $label = ['corrective' => 'Corrective Action', 'preventive' => 'Preventive Action', 'dokumen' => 'Update Dokumen'];

    foreach ($_FILES['pendukung']['name'] as $jenis => $perAksi) {

        if (!isset(FPM_BUKTI_AKSI_TABEL[$jenis]) || !is_array($perAksi)) continue;

        foreach ($perAksi as $idAksi => $namaFiles) {

            $idAksi = (int) $idAksi;
            $namaFiles = array_filter((array) $namaFiles, fn($n) => $n !== '');
            if (empty($namaFiles)) continue;

            $jenisKegiatan = "Dokumen Pendukung {$label[$jenis]}";

            if (!aksiMilikFpm($koneksi, $jenis, $idAksi, $idFpm)) {
                throw new Exception("$jenisKegiatan: baris tidak ditemukan di FPM ini. Muat ulang halaman lalu coba lagi.");
            }

            $stmt = $koneksi->prepare("SELECT COUNT(*) FROM fpm_aksi_pendukung WHERE jenis = ? AND id_aksi = ?");
            $stmt->bind_param("si", $jenis, $idAksi);
            $stmt->execute();
            $jumlahLama = (int) $stmt->get_result()->fetch_row()[0];

            if ($jumlahLama + count($namaFiles) > FPM_PENDUKUNG_MAX_FILE) {
                throw new Exception("$jenisKegiatan maksimal " . FPM_PENDUKUNG_MAX_FILE . " file per baris (sudah ada $jumlahLama file).");
            }

            $insert = $koneksi->prepare("INSERT INTO fpm_aksi_pendukung (id_fpm, jenis, id_aksi, file, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($namaFiles as $j => $originalName) {

                $errUpload = pesanErrorUpload($_FILES['pendukung']['error'][$jenis][$idAksi][$j] ?? UPLOAD_ERR_OK, "$jenisKegiatan ($originalName)");
                if ($errUpload !== null) {
                    throw new Exception($errUpload);
                }

                $ext = pastikanFormatFileDiizinkan($originalName, $jenisKegiatan);
                $fileName = namaFileUploadUnik('SUPP', $ext, $uploadDir);

                if (!move_uploaded_file($_FILES['pendukung']['tmp_name'][$jenis][$idAksi][$j], $uploadDir . $fileName)) {
                    throw new Exception("Gagal menyimpan $jenisKegiatan ($originalName) di server. Silakan upload ulang.");
                }

                $insert->bind_param("isisss", $idFpm, $jenis, $idAksi, $fileName, $originalName, $oleh);
                $insert->execute();
            }
        }
    }
}

/** Hapus dokumen pendukung yang dicentang (name="hapus_pendukung[]"), hanya milik FPM ini. */
function hapusPendukungTerpilih($koneksi, int $idFpm, string $uploadDir): void
{
    if (empty($_POST['hapus_pendukung']) || !is_array($_POST['hapus_pendukung'])) return;

    foreach ($_POST['hapus_pendukung'] as $idPendukung) {

        $idPendukung = (int) $idPendukung;

        $stmt = $koneksi->prepare("SELECT file FROM fpm_aksi_pendukung WHERE id = ? AND id_fpm = ?");
        $stmt->bind_param("ii", $idPendukung, $idFpm);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $path = $uploadDir . $row['file'];
            if (is_file($path)) @unlink($path);

            $del = $koneksi->prepare("DELETE FROM fpm_aksi_pendukung WHERE id = ? AND id_fpm = ?");
            $del->bind_param("ii", $idPendukung, $idFpm);
            $del->execute();
        }
    }
}

/**
 * Kotak "Dokumen Pendukung" di bawah kolom File satu baris aksi - dibedakan tampilannya dari
 * bukti perbaikan. $bisaUbah = false untuk halaman read-only (tanpa input & centang hapus).
 */
function renderPendukungAksi(string $jenis, int $idAksi, array $files, bool $bisaUbah = true, string $baseUrl = '../uploads/fpm_dt/'): string
{
    if (!$bisaUbah && empty($files)) return '';

    $html = '<div class="pendukung-aksi" style="margin-top:8px;padding:6px;border:1px dashed #cbd5e1;border-radius:6px;background:#f8fafc;text-align:left;">'
        . '<div style="font-size:11px;font-weight:600;color:#64748b;">📎 Dokumen Pendukung</div>'
        . '<div style="font-size:11px;color:#64748b;margin-bottom:4px;">Info tambahan saja (drawing, hasil ukur, email, dll). Tidak dicek sebagai bukti &amp; tidak mengubah status.</div>';

    if (!empty($files)) {
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
        foreach ($files as $f) {
            $path = htmlspecialchars($baseUrl . $f['file']);
            $html .= '<div style="font-size:11px;max-width:120px;word-break:break-all;">'
                . '<a href="' . $path . '" target="_blank">' . htmlspecialchars($f['original_name']) . '</a>';
            if ($bisaUbah) {
                $html .= '<label style="display:block;color:#dc2626;cursor:pointer;">'
                    . '<input type="checkbox" name="hapus_pendukung[]" value="' . (int) $f['id'] . '"> Hapus</label>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    if ($bisaUbah) {
        $sisa = max(0, FPM_PENDUKUNG_MAX_FILE - count($files));
        $html .= '<input type="file" name="pendukung[' . htmlspecialchars($jenis) . '][' . $idAksi . '][]" multiple'
            . ' accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"'
            . ' data-bukti-ui data-bu-label="+ Dokumen Pendukung" data-bu-catatan="PDF/JPG/PNG saja" data-sisa="' . $sisa . '"' . ($sisa === 0 ? ' disabled' : '') . '>'
            . '<small class="upload-video-hint bu-hint" style="display:block;">'
            . ($sisa === 0 ? 'Sudah ' . FPM_PENDUKUNG_MAX_FILE . ' file (maksimal)' : 'PDF/JPG/PNG, maks ' . $sisa . ' file lagi')
            . '</small>';
    }

    return $html . '</div>';
}

/** Pembuka kotak "Bukti Perbaikan" di kolom File (tutup dengan '</div>'), pasangan kotak Dokumen Pendukung. */
function renderJudulBuktiAksi(): string
{
    return '<div class="bukti-aksi-box" style="padding:6px;border:1px solid #93c5fd;border-radius:6px;background:#eff6ff;text-align:left;">'
        . '<div style="font-size:11px;font-weight:600;color:#1d4ed8;">✅ Bukti Perbaikan</div>'
        . '<div style="font-size:11px;color:#1e40af;margin-bottom:4px;">Foto/video hasil perbaikan - dicek Staff IMP untuk menutup (CLOSED) perbaikan ini.</div>';
}
