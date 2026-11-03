<?php

/**
 * Field "Foto Masalah" (maks 3 foto: kolom file, file2, file3 di fpm_detail_masalah)
 * untuk halaman Edit Blank FPM Staff IMP - dipakai bersama oleh edit_fpm_internal.php
 * dan edit_fpm_down_time.php supaya tampilan & perilakunya sama.
 * Form pemanggil wajib enctype="multipart/form-data".
 */

if (!function_exists('renderFotoEditFields')) {
    function renderFotoEditFields($detail)
    {
        $detail = $detail ?: [];
?>
        <style>
            .foto-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 8px; }
            .foto-item { display: flex; flex-direction: column; gap: 8px; }
            .foto-item .label { font-weight: 600; color: #6b7280; }
            .foto-preview {
                height: 140px; border: 1px dashed #e5e7eb; border-radius: 6px; background: #fff;
                display: flex; align-items: center; justify-content: center; overflow: hidden;
                color: #6b7280; font-size: 13px;
            }
            .foto-preview img { max-width: 100%; max-height: 138px; }
            .foto-item input[type="file"] { font-size: 13px; }
            .hapus-foto { font-size: 13px; color: #dc2626; cursor: pointer; }
        </style>

        <div class="section-title">Foto Masalah</div>
        <div class="info-box">
            <div class="foto-grid">
                <?php foreach (['file', 'file2', 'file3'] as $i => $k): ?>
                    <?php $namaFile = trim($detail[$k] ?? ''); ?>
                    <div class="foto-item">
                        <div class="label">Foto <?= $i + 1 ?></div>
                        <div class="foto-preview" id="preview_<?= $k ?>">
                            <?php if ($namaFile !== '' && file_exists(__DIR__ . '/../upload_fpm/' . $namaFile)): ?>
                                <a href="../upload_fpm/<?= htmlspecialchars($namaFile) ?>" target="_blank">
                                    <img src="../upload_fpm/<?= htmlspecialchars($namaFile) ?>">
                                </a>
                            <?php elseif ($namaFile !== ''): ?>
                                <em>File tidak ditemukan</em>
                            <?php else: ?>
                                <em>Belum ada foto</em>
                            <?php endif; ?>
                        </div>
                        <input type="file" name="foto_ng[<?= $k ?>]"
                            accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                            onchange="previewFotoEdit(this, 'preview_<?= $k ?>')">
                        <?php if ($namaFile !== ''): ?>
                            <label class="hapus-foto">
                                <input type="checkbox" name="hapus_file[<?= $k ?>]" value="1"> Hapus foto ini
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <small style="color:#6b7280;">Format: JPG, JPEG, PNG. Pilih file baru untuk mengganti foto lama.</small>
        </div>

        <script>
            function previewFotoEdit(input, previewId) {
                const box = document.getElementById(previewId);
                if (!input.files || !input.files[0]) return;
                const img = document.createElement('img');
                img.src = URL.createObjectURL(input.files[0]);
                box.innerHTML = '';
                box.appendChild(img);
            }
        </script>
<?php
    }
}

if (!function_exists('simpanFotoEdit')) {
    /**
     * Proses upload/ganti/hapus foto dari renderFotoEditFields(). Melempar Exception
     * kalau upload gagal (dipanggil di dalam transaksi halaman edit).
     */
    function simpanFotoEdit($koneksi, $id_fpm)
    {
        $stmtDet = $koneksi->prepare("SELECT file, file2, file3 FROM fpm_detail_masalah WHERE id_fpm = ?");
        $stmtDet->bind_param("i", $id_fpm);
        $stmtDet->execute();
        $detailLama = $stmtDet->get_result()->fetch_assoc();

        $kolomFile = ['file', 'file2', 'file3'];
        $namaFiles = [];
        foreach ($kolomFile as $k) {
            $namaFiles[$k] = trim($detailLama[$k] ?? '') !== '' ? $detailLama[$k] : null;
        }
        $sebelum = $namaFiles;

        $allow  = ['jpg', 'jpeg', 'png'];
        $folder = __DIR__ . '/../upload_fpm/';

        foreach ($kolomFile as $slot => $k) {
            if (!empty($_POST['hapus_file'][$k])) {
                $namaFiles[$k] = null;
            }

            $err = $_FILES['foto_ng']['error'][$k] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;

            $namaAsli = $_FILES['foto_ng']['name'][$k];
            if ($err !== UPLOAD_ERR_OK) {
                throw new Exception("Gagal upload file " . htmlspecialchars($namaAsli) . ".");
            }

            $ext = strtolower(pathinfo($namaAsli, PATHINFO_EXTENSION));
            if (!in_array($ext, $allow)) {
                throw new Exception("Format file hanya boleh JPG, JPEG, atau PNG.");
            }

            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            $namaFile = 'FPM_' . $id_fpm . '_' . date('YmdHis') . '_' . ($slot + 1) . '.' . $ext;
            if (!move_uploaded_file($_FILES['foto_ng']['tmp_name'][$k], $folder . $namaFile)) {
                throw new Exception("Gagal upload file.");
            }

            $namaFiles[$k] = $namaFile;
        }

        if ($namaFiles === $sebelum) {
            return;
        }

        // Rapatkan urutan supaya foto pertama selalu di kolom `file` (dibaca duluan
        // oleh renderDetailMasalahBox). Kolom `file` NOT NULL -> kosong = ''.
        $urut = array_pad(array_values(array_filter($namaFiles)), 3, null);
        $urut[0] = $urut[0] ?? '';

        if ($detailLama) {
            $stmt = $koneksi->prepare("UPDATE fpm_detail_masalah SET file=?, file2=?, file3=? WHERE id_fpm=?");
            $stmt->bind_param("sssi", $urut[0], $urut[1], $urut[2], $id_fpm);
        } else {
            $stmt = $koneksi->prepare("INSERT INTO fpm_detail_masalah (id_fpm, pic, file, file2, file3) VALUES (?, '', ?, ?, ?)");
            $stmt->bind_param("isss", $id_fpm, $urut[0], $urut[1], $urut[2]);
        }
        $stmt->execute();
    }
}
