<?php

/**
 * Tampilan upload bukti countermeasure yang seragam di semua halaman FPM & Asakai:
 * tombol "+ Tambah File" untuk menambah file, tombol ✕ per file untuk menghapus /
 * mengganti file yang belum disimpan. Input file aslinya tetap ada (disembunyikan) dan
 * diisi lewat DataTransfer, jadi nama field & proses simpan di server tidak berubah.
 *
 * Aktif untuk <input type="file" data-bukti-ui>:
 *  - multiple       -> beberapa file, maksimal data-sisa (default 3)
 *  - tanpa multiple -> 1 file
 *  - disabled       -> kuota penuh (file lama dihapus dulu lewat tombol ✕ class bu-hapus-lama)
 *  - data-bu-label  -> teks tombol tambah (default "+ Tambah File")
 *  - data-bu-catatan -> catatan di keterangan sisa kuota (default "video maks 10MB")
 * Baris tabel hasil clone / modal SweetAlert yang muncul belakangan ikut aktif otomatis.
 * Browser tanpa DataTransfer tetap memakai input file biasa.
 *
 * Panggil sekali per halaman (sebelum </body>): <?= renderUploadBuktiUI() ?>
 */

/**
 * Nama file fisik yang dijamin belum dipakai di $uploadDir. Dulu nama dibuat dari time() +
 * nomor baris saja, jadi dua upload di detik yang sama bisa menimpa file orang lain di disk.
 */
if (!function_exists('namaFileUploadUnik')) {
    function namaFileUploadUnik(string $prefix, string $ext, string $uploadDir): string
    {
        $ext = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $ext));
        do {
            $nama = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . ($ext !== '' ? '.' . $ext : '');
        } while (file_exists($uploadDir . $nama));
        return $nama;
    }
}
/**
 * Upload untuk kolom file TUNGGAL (mis. asakai_perbaikan_tambahan.file): file lama TIDAK
 * PERNAH ditimpa. Upload baru hanya diterima kalau belum ada file, atau user mencentang
 * "Hapus file lama" ($hapusLama) - file lama dihapus dulu baru file baru disimpan.
 *
 * @param string $fileLama isi kolom file saat ini, diambil dari DB (bukan hidden input)
 * @param ?array $upload   $_FILES['nama_input']
 * @return string isi kolom file yang baru
 * @throws Exception pesan untuk user
 */
function simpanFileTunggalTanpaTimpa(string $fileLama, ?array $upload, bool $hapusLama, string $uploadDir, string $prefix): string
{
    $adaUpload = $upload && ($upload['name'] ?? '') !== '' && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($adaUpload) {
        $err = pesanErrorUpload($upload['error'], 'file ' . $upload['name']);
        if ($err !== null) {
            throw new Exception($err);
        }
        if ($fileLama !== '' && !$hapusLama) {
            throw new Exception('Sudah ada file tersimpan. File lama tidak ditimpa - centang "Hapus file lama" dulu kalau mau menggantinya.');
        }
        if (isVideoUploadTooLarge($upload['name'], $upload['size'])) {
            throw new Exception('Ukuran video melebihi 10MB. Silakan pilih file yang lebih kecil.');
        }
        $nama = namaFileUploadUnik($prefix, pathinfo($upload['name'], PATHINFO_EXTENSION), $uploadDir);
        if (!move_uploaded_file($upload['tmp_name'], $uploadDir . $nama)) {
            throw new Exception('Server gagal menyimpan file ' . $upload['name'] . '. Silakan upload ulang.');
        }
    } else {
        $nama = $hapusLama ? '' : $fileLama;
    }

    // File lama baru dihapus setelah file pengganti (kalau ada) berhasil disimpan.
    if ($hapusLama && $fileLama !== '' && $nama !== $fileLama && is_file($uploadDir . basename($fileLama))) {
        @unlink($uploadDir . basename($fileLama));
    }
    return $nama;
}

/** Checkbox "Hapus file lama" + petunjuk, untuk kolom file tunggal (lihat simpanFileTunggalTanpaTimpa). */
function renderHapusFileLamaTunggal(string $inputFileName = 'file'): string
{
    $sel = htmlspecialchars('input[type=file][name="' . $inputFileName . '"]', ENT_QUOTES);
    return '<label style="display:flex; align-items:center; gap:6px; margin-top:6px; font-size:13px; color:#b91c1c; cursor:pointer;">'
        . '<input type="checkbox" name="hapus_file_lama" value="1"'
        . ' onchange="var f=this.form.querySelector(\'' . $sel . '\'); if (f) { f.disabled = !this.checked; if (!this.checked) f.value = \'\'; }">'
        . ' Hapus file lama</label>'
        . '<small style="display:block; color:#6c757d; font-size:11.5px;">File lama tidak ditimpa. Untuk mengganti, centang "Hapus file lama" lalu pilih file baru.</small>';
}

function renderUploadBuktiUI(): string
{
    static $sudah = false;
    if ($sudah) return '';
    $sudah = true;

    return <<<'HTML'
<style>
    .bu-native { display: none !important; }
    .bu-hint-tersembunyi { display: none !important; }
    .bu-ui { display: flex; flex-direction: column; gap: 6px; min-width: 170px; margin-top: 2px; text-align: left; }
    .bu-list { display: flex; flex-direction: column; gap: 4px; }
    .bu-list:empty { display: none; }
    .bu-item { display: flex; align-items: center; gap: 8px; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; }
    .bu-thumb { width: 34px; height: 34px; flex: 0 0 34px; border-radius: 6px; object-fit: cover; background: #e2e8f0; color: #334155; font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .bu-name { flex: 1; min-width: 0; line-height: 1.25; font-size: 12px; }
    .bu-name span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: #1e293b; }
    .bu-name small { display: block; color: #b45309; font-size: 10.5px; }
    .bu-del { flex: 0 0 24px; width: 24px; height: 24px; padding: 0; border: none; border-radius: 50%; background: #fee2e2; color: #dc2626; font-size: 12px; font-weight: 700; line-height: 24px; cursor: pointer; }
    .bu-del:hover { background: #dc2626; color: #fff; }
    .bu-add { align-self: flex-start; padding: 6px 12px; border: 1.5px dashed #2563eb; border-radius: 8px; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 600; cursor: pointer; }
    .bu-add:hover { background: #dbeafe; }
    .bu-info { display: block; font-size: 11px; color: #64748b; }
    .bu-info:empty { display: none; }
</style>
<script>
    (function() {
        if (window.BuktiUpload) return;

        const MAX_VIDEO = 10 * 1024 * 1024;
        const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'flv', 'wmv', 'm4v'];
        const IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        const SELECTOR = 'input[type="file"][data-bukti-ui]';
        const bisaDataTransfer = (function() {
            try { new DataTransfer(); return true; } catch (e) { return false; }
        })();

        function ekstensi(nama) {
            const i = nama.lastIndexOf('.');
            return i < 0 ? '' : nama.slice(i + 1).toLowerCase();
        }

        function isVideo(f) {
            return (f.type || '').indexOf('video/') === 0 || VIDEO_EXT.includes(ekstensi(f.name));
        }

        function ukuran(b) {
            return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
        }

        function cocokAccept(input, f) {
            const daftar = (input.getAttribute('accept') || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
            if (!daftar.length) return true;
            const ext = '.' + ekstensi(f.name);
            const tipe = (f.type || '').toLowerCase();
            return daftar.some(a => a[0] === '.' ? a === ext : (a.endsWith('/*') ? tipe.indexOf(a.slice(0, -1)) === 0 : a === tipe));
        }

        // Jumlah file baru yang masih boleh dipilih untuk input ini.
        function batas(input) {
            if (input.disabled) return 0;
            if (!input.multiple) return 1;
            const sisa = parseInt(input.dataset.sisa, 10);
            return isNaN(sisa) ? 3 : Math.max(0, sisa);
        }

        function setFiles(input, files) {
            const dt = new DataTransfer();
            files.forEach(f => dt.items.add(f));
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function tambah(input, dipilih) {
            const ada = Array.from(input.files || []);
            const maks = batas(input);
            const ditolak = [];
            const baru = [];

            dipilih.forEach(f => {
                if (!cocokAccept(input, f)) {
                    ditolak.push(f.name + ' (format tidak diizinkan)');
                } else if (isVideo(f) && f.size > MAX_VIDEO) {
                    ditolak.push(f.name + ' (video lebih dari 10MB)');
                } else if (!ada.some(a => a.name === f.name && a.size === f.size)) {
                    baru.push(f);
                }
            });

            let hasil = input.multiple ? ada.concat(baru) : (baru.length ? [baru[0]] : ada);
            if (hasil.length > maks) {
                ditolak.push((hasil.length - maks) + ' file tidak ditambahkan karena melebihi batas ' + maks + ' file');
                hasil = hasil.slice(0, maks);
            }
            if (ditolak.length) alert('Sebagian file tidak bisa ditambahkan:\n- ' + ditolak.join('\n- '));
            setFiles(input, hasil);
        }

        function render(input) {
            const ui = input._buUi;
            if (!ui) return;

            const files = Array.from(input.files || []);
            const maks = batas(input);
            const list = ui.querySelector('.bu-list');
            list.querySelectorAll('img').forEach(img => URL.revokeObjectURL(img.src));
            list.innerHTML = '';

            files.forEach((f, idx) => {
                const item = document.createElement('div');
                item.className = 'bu-item';

                const ext = ekstensi(f.name);
                let thumb;
                if (IMG_EXT.includes(ext)) {
                    thumb = document.createElement('img');
                    thumb.src = URL.createObjectURL(f);
                } else {
                    thumb = document.createElement('span');
                    thumb.textContent = ext === 'pdf' ? 'PDF' : (isVideo(f) ? 'VIDEO' : (ext ? ext.toUpperCase() : 'FILE'));
                }
                thumb.className = 'bu-thumb';

                const info = document.createElement('div');
                info.className = 'bu-name';
                const nama = document.createElement('span');
                nama.textContent = f.name;
                nama.title = f.name;
                const ket = document.createElement('small');
                ket.textContent = ukuran(f.size) + ' · belum tersimpan';
                info.append(nama, ket);

                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'bu-del';
                del.title = 'Hapus file ini';
                del.textContent = '✕';
                del.addEventListener('click', () => setFiles(input, files.filter((_, i) => i !== idx)));

                item.append(thumb, info, del);
                list.appendChild(item);
            });

            const sisa = maks - files.length;
            ui.querySelector('.bu-add').style.display = sisa > 0 ? '' : 'none';

            const sel = input.closest('td') || input.parentNode;
            const adaHapusLama = !!(sel && sel.querySelector('.bu-hapus-lama'));
            let teks = '';
            if (maks === 0) {
                teks = 'Jumlah file sudah maksimal.' + (adaHapusLama ? ' Klik ✕ pada file lama untuk mengganti.' : '');
            } else if (sisa <= 0) {
                teks = input.multiple ? 'Batas ' + maks + ' file tercapai. Klik ✕ untuk mengganti.' : 'Klik ✕ untuk mengganti file.';
            } else if (input.multiple) {
                teks = 'Bisa tambah ' + sisa + ' file lagi (' + (input.dataset.buCatatan || 'video maks 10MB') + ').';
            }
            ui.querySelector('.bu-info').textContent = teks;
        }

        function init(input) {
            if (input._buUi || !bisaDataTransfer) return;

            // Baris tabel hasil clone ikut membawa tampilan lama - buang, bangun ulang.
            const next = input.nextElementSibling;
            if (next && next.classList.contains('bu-ui')) next.remove();

            const ui = document.createElement('div');
            ui.className = 'bu-ui';
            ui.innerHTML = '<div class="bu-list"></div><button type="button" class="bu-add"></button><small class="bu-info"></small>';
            ui.querySelector('.bu-add').textContent = input.dataset.buLabel || '+ Tambah File';
            input.insertAdjacentElement('afterend', ui);
            input.classList.add('bu-native');
            input._buUi = ui;

            // Keterangan lama di bawah input (class bu-hint) diganti keterangan widget.
            let sib = ui.nextElementSibling;
            while (sib && sib.tagName === 'SMALL') {
                if (sib.classList.contains('bu-hint')) sib.classList.add('bu-hint-tersembunyi');
                sib = sib.nextElementSibling;
            }

            ui.querySelector('.bu-add').addEventListener('click', () => {
                const pick = document.createElement('input');
                pick.type = 'file';
                pick.multiple = input.multiple;
                if (input.accept) pick.accept = input.accept;
                pick.addEventListener('change', () => {
                    if (pick.files.length) tambah(input, Array.from(pick.files));
                });
                pick.click();
            });

            // Render ulang juga setelah listener halaman lain sempat mengosongkan input.
            input.addEventListener('change', () => {
                render(input);
                setTimeout(() => render(input), 0);
            });
            new MutationObserver(() => render(input)).observe(input, {
                attributes: true,
                attributeFilter: ['disabled', 'data-sisa']
            });
            render(input);
        }

        function scan(root) {
            if (root.matches && root.matches(SELECTOR)) init(root);
            if (root.querySelectorAll) root.querySelectorAll(SELECTOR).forEach(init);
        }

        window.BuktiUpload = { init: init, refresh: render };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => scan(document));
        } else {
            scan(document);
        }
        new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(n => {
            if (n.nodeType === 1) scan(n);
        }))).observe(document.documentElement, { childList: true, subtree: true });
    })();
</script>
HTML;
}
