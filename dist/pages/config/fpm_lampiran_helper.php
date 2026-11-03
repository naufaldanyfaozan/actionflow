<?php

require_once __DIR__ . '/upload_bukti_ui_helper.php';

// Berlaku untuk Lampiran FPM & file tambahan Corrective/Preventive/Dokumen -
// bukan untuk kolom file utama (masih boleh video, lihat isVideoUploadTooLarge).
const FPM_MULTI_FILE_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
const FPM_MULTI_FILE_MAX_COUNT = 5;

function pastikanFormatFileDiizinkan($originalName, $jenisKegiatan)
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, FPM_MULTI_FILE_ALLOWED_EXT, true)) {
        throw new Exception("$jenisKegiatan hanya boleh format PDF, JPG, JPEG, atau PNG");
    }
    return $ext;
}

// Render tombol "+ Tambah File" (maks 5 file) dengan tombol ✕ per file - tampilan
// bersama upload bukti (config/upload_bukti_ui_helper.php). Satu input multiple dengan
// name yang sama, jadi struktur $_FILES di server tetap sama dengan slot-slot input dulu.
// Dipakai untuk Lampiran FPM maupun file tambahan Corrective/Preventive/Dokumen.
function renderMultiFileInput($fieldName, $hint = 'Format PDF/JPG/PNG, maksimal 5 file', $labelTombol = '+ Tambah File')
{
    $accept = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
    $nameAttr = htmlspecialchars($fieldName, ENT_QUOTES);
?>
    <div class="multi-file" data-name="<?= $nameAttr ?>" data-accept="<?= htmlspecialchars($accept, ENT_QUOTES) ?>">
        <?php if ($hint): ?>
            <small class="upload-video-hint" style="margin:4px 0 2px;"><?= htmlspecialchars($hint) ?></small>
        <?php endif; ?>
        <input type="file" class="multi-file-input" name="<?= $nameAttr ?>" multiple
            accept="<?= htmlspecialchars($accept, ENT_QUOTES) ?>" data-bukti-ui data-sisa="<?= FPM_MULTI_FILE_MAX_COUNT ?>"
            data-bu-label="<?= htmlspecialchars($labelTombol, ENT_QUOTES) ?>">
    </div>
<?php
}

// ================== LAMPIRAN UMUM FPM (per id_fpm, bisa lebih dari 1 file) ==================

function ambilLampiranFpm($koneksi, $id_fpm)
{
    $data = [];
    if (empty($id_fpm)) return $data;

    $stmt = $koneksi->prepare("SELECT id, file, original_name FROM fpm_lampiran WHERE id_fpm = ? ORDER BY id ASC");
    $stmt->bind_param("i", $id_fpm);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    return $data;
}

function hapusLampiranTerpilih($koneksi, $id_fpm, $uploadDir)
{
    if (empty($_POST['hapus_lampiran']) || !is_array($_POST['hapus_lampiran'])) return;

    foreach ($_POST['hapus_lampiran'] as $idLampiran) {

        $idLampiran = (int) $idLampiran;

        $stmt = $koneksi->prepare("SELECT file FROM fpm_lampiran WHERE id = ? AND id_fpm = ?");
        $stmt->bind_param("ii", $idLampiran, $id_fpm);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $path = $uploadDir . $row['file'];
            if (is_file($path)) @unlink($path);

            $del = $koneksi->prepare("DELETE FROM fpm_lampiran WHERE id = ? AND id_fpm = ?");
            $del->bind_param("ii", $idLampiran, $id_fpm);
            $del->execute();
        }
    }
}

function simpanLampiranBaru($koneksi, $id_fpm, $uploadDir)
{
    if (empty($_FILES['lampiran_file']['name'])) return;

    $namaFiles = array_filter($_FILES['lampiran_file']['name'], fn($n) => $n !== '');
    if (count($namaFiles) > FPM_MULTI_FILE_MAX_COUNT) {
        throw new Exception("Lampiran FPM maksimal " . FPM_MULTI_FILE_MAX_COUNT . " file per submit");
    }

    foreach ($_FILES['lampiran_file']['name'] as $i => $originalName) {

        if ($originalName === '') continue;

        $errUpload = pesanErrorUpload($_FILES['lampiran_file']['error'][$i] ?? UPLOAD_ERR_OK, "Lampiran FPM ($originalName)");
        if ($errUpload !== null) {
            throw new Exception($errUpload);
        }

        $ext = pastikanFormatFileDiizinkan($originalName, "Lampiran FPM");

        $tmpName = $_FILES['lampiran_file']['tmp_name'][$i];
        $fileName = namaFileUploadUnik('LAMP', $ext, $uploadDir);

        if (!move_uploaded_file($tmpName, $uploadDir . $fileName)) {
            throw new Exception("Gagal menyimpan Lampiran FPM ($originalName) di server. Silakan upload ulang.");
        }

        $stmt = $koneksi->prepare("INSERT INTO fpm_lampiran (id_fpm, file, original_name) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $id_fpm, $fileName, $originalName);
        $stmt->execute();
    }
}

// ========= FILE TAMBAHAN COUNTERMEASURE (lebih dari 1 file per baris Corrective/Preventive/Dokumen) =========
// $jenis: 'corrective' | 'preventive' | 'dokumen'
// Kolom `file` yang lama di tabel induk tetap dipakai sebagai file utama/pertama (tidak diubah),
// file ke-2 dan seterusnya disimpan di tabel *_files ini.

function metaFileTambahanAksi($jenis)
{
    switch ($jenis) {
        case 'corrective':
            return ['table' => 'fpm_corrective_files', 'kolom' => 'corrective_id'];
        case 'preventive':
            return ['table' => 'fpm_preventive_files', 'kolom' => 'preventive_id'];
        case 'dokumen':
            return ['table' => 'fpm_dokumen_files', 'kolom' => 'dokumen_id'];
    }

    throw new Exception("Jenis file tambahan tidak dikenal: $jenis");
}

function ambilFileTambahanAksi($koneksi, $jenis, $parentId)
{
    $data = [];
    if (empty($parentId)) return $data;

    $meta = metaFileTambahanAksi($jenis);

    $sql = "SELECT file FROM {$meta['table']} WHERE {$meta['kolom']} = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $parentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $data[] = $row['file'];
    }

    return $data;
}

// Ambil daftar file yang baru diupload untuk satu baris (dikelompokkan lewat $rowUid),
// belum dipindahkan ke folder upload - dipisah dari proses simpan supaya validasi ukuran
// video bisa dilempar sebagai Exception sebelum ada file yang keburu dipindahkan.
function ambilFileTambahanBaruUpload($fieldName, $rowUid)
{
    $files = [];

    if (
        $rowUid === null || $rowUid === '' ||
        empty($_FILES[$fieldName]['name'][$rowUid]) ||
        !is_array($_FILES[$fieldName]['name'][$rowUid])
    ) {
        return $files;
    }

    foreach ($_FILES[$fieldName]['name'][$rowUid] as $j => $originalName) {

        if ($originalName === '') continue;

        $files[] = [
            'original_name' => $originalName,
            'tmp_name'      => $_FILES[$fieldName]['tmp_name'][$rowUid][$j],
            'size'          => $_FILES[$fieldName]['size'][$rowUid][$j],
            'error'         => $_FILES[$fieldName]['error'][$rowUid][$j] ?? UPLOAD_ERR_OK,
        ];
    }

    return $files;
}

// Pindahkan file yang baru diupload ke folder upload, kembalikan daftar nama file tersimpan.
function simpanFileTambahanBaruUpload($uploadedFiles, $uploadDir, $prefix, $rowUid, $jenisKegiatan)
{
    if (count($uploadedFiles) > FPM_MULTI_FILE_MAX_COUNT) {
        throw new Exception("File tambahan pada $jenisKegiatan maksimal " . FPM_MULTI_FILE_MAX_COUNT . " file per submit");
    }

    $fileNames = [];

    foreach ($uploadedFiles as $idx => $up) {

        $errUpload = pesanErrorUpload($up['error'] ?? UPLOAD_ERR_OK, "file tambahan $jenisKegiatan ({$up['original_name']})");
        if ($errUpload !== null) {
            throw new Exception($errUpload);
        }

        $ext = pastikanFormatFileDiizinkan($up['original_name'], "File tambahan pada $jenisKegiatan");

        $fileName = namaFileUploadUnik($prefix, $ext, $uploadDir);

        if (!move_uploaded_file($up['tmp_name'], $uploadDir . $fileName)) {
            throw new Exception("Gagal menyimpan file tambahan $jenisKegiatan ({$up['original_name']}) di server. Silakan upload ulang.");
        }

        $fileNames[] = $fileName;
    }

    return $fileNames;
}

function simpanFileTambahanAksi($koneksi, $jenis, $parentId, $fileNames)
{
    if (empty($fileNames)) return;

    $meta = metaFileTambahanAksi($jenis);

    $sql = "INSERT INTO {$meta['table']} ({$meta['kolom']}, file) VALUES (?, ?)";
    $stmt = $koneksi->prepare($sql);

    foreach ($fileNames as $fileName) {
        if ($fileName === '') continue;
        $stmt->bind_param("is", $parentId, $fileName);
        $stmt->execute();
    }
}
