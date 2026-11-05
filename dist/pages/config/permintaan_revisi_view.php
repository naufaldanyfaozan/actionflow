<?php

require_once __DIR__ . '/countermeasure_revisi_helper.php';
require_once __DIR__ . '/upload_bukti_ui_helper.php';
require_once __DIR__ . '/fpm_bukti_aksi_helper.php';
require_once __DIR__ . '/asakai_bukti_helper.php';

/** Boleh hapus file bukti - sama dengan aturan endpoint ajax_hapus_bukti_aksi_fpm / ajax_hapus_bukti_asakai. */
function revisiBolehHapusFile(bool $isAsakai): bool
{
    if ($isAsakai) {
        return asakaiBolehHapusBukti();
    }
    $level = (string) ($_SESSION['level'] ?? '');
    return in_array($level, ['Dept Head', 'Staff IMP'], true)
        || ($level === 'Staff' && strtoupper(trim((string) ($_SESSION['dept'] ?? ''))) === 'QA');
}

/** Thumbnail file baris + tombol ✕ (hapus lewat AJAX, lihat hapusFileRevisi di bawah). */
function renderFileRevisiHapus(array $r, bool $bisaHapus): string
{
    $isAsakai = $r['sumber'] === 'ASAKAI';
    $files = $r['files_semua'] ?? $r['files'];
    if (!$files) {
        return '<span class="pr-kosong" style="color:#9ca3af; font-size:12px;">Belum ada file</span>';
    }
    $html = '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
    foreach ($files as $f) {
        $html .= '<span class="pr-file" style="position:relative; display:inline-block;">' . cmRevisiRenderFiles($r['sumber'], [$f], 72);
        if ($bisaHapus) {
            $html .= '<button type="button" title="Hapus file ini" onclick="hapusFileRevisi(this)"'
                . ' data-sumber="' . ($isAsakai ? 'ASAKAI' : 'FPM') . '"'
                . ' data-jenis="' . htmlspecialchars(($isAsakai ? CM_REVISI_JENIS_ASAKAI : CM_REVISI_JENIS_FPM)[$r['ref_table']] ?? '') . '"'
                . ' data-id="' . (int) $r['ref_id'] . '" data-id-fpm="' . (int) ($r['id_fpm'] ?? 0) . '"'
                . ' data-file="' . htmlspecialchars($f, ENT_QUOTES) . '"'
                . ' style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; padding:0; line-height:18px; border:none;'
                . ' border-radius:50%; background:#dc2626; color:#fff; font-size:12px; font-weight:700; cursor:pointer;">&times;</button>';
        }
        $html .= '</span>';
    }
    return $html . '</div>';
}

/**
 * Halaman "Permintaan Revisi" untuk PIC (Dept Head & Staff): daftar catatan revisi
 * countermeasure untuk dept yang login, lengkap dengan file yang sekarang terupload dan
 * form upload ulang langsung di tiap kartu. Dipisah per peminta:
 *  - Staff IMP (FPM + ASAKAI): dept_head/permintaan_revisi.php & staff/permintaan_revisi.php
 *  - Staff QA (FPM Claim Machining): dept_head/permintaan_revisi_qa.php (Modul FPM QA, Dept Head saja).
 */

/** Proses POST (upload ulang / tandai ditindaklanjuti). Panggil sebelum HTML dikirim. */
function handlePermintaanRevisiPost($koneksi, string $peminta = CM_REVISI_ROLE_IMP): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    // Upload melebihi post_max_size: PHP mengosongkan $_POST & $_FILES sama sekali.
    $postTerlaluBesar = empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    if (!$postTerlaluBesar && !isset($_POST['tindak_lanjut_revisi'])) {
        return;
    }

    if ($postTerlaluBesar) {
        $hasil = ['ok' => false, 'pesan' => 'Upload gagal: ukuran file terlalu besar untuk server. Kecilkan/kompres file lalu coba lagi.'];
    } else {
        $hasil = prosesTindakLanjutRevisiPic(
            $koneksi,
            $_SESSION['dept'] ?? '',
            $_SESSION['username'] ?? '',
            $_POST,
            $_FILES
        );
    }

    echo "<script>alert(" . json_encode($hasil['pesan']) . "); window.location=" . json_encode(permintaanRevisiUrlTugasSaya($peminta)) . ";</script>";
    exit;
}

/**
 * Permintaan Revisi kini tampil di menu "Tugas & Approval" › Tugas Saya (lihat
 * tugas_saya_view.php) - alamat bagiannya di sana, per peminta.
 */
function permintaanRevisiUrlTugasSaya(string $peminta, array $query = []): string
{
    if ($peminta === CM_REVISI_ROLE_QA) {
        $query = ['modul' => 'qa'] + $query;
    }
    return 'tugas_saya.php' . ($query ? '?' . http_build_query($query) : '') . '#' . permintaanRevisiAnchor($peminta);
}

function permintaanRevisiAnchor(string $peminta): string
{
    return $peminta === CM_REVISI_ROLE_QA ? 'revisi-qa' : 'revisi-imp';
}

// Parameter GET tab (Perlu Diperbaiki / Sudah Ditindaklanjuti) per peminta - Dept Head bisa
// punya dua bagian (IMP & QA) di halaman Tugas Saya yang sama.
function permintaanRevisiParamTab(string $peminta): string
{
    return $peminta === CM_REVISI_ROLE_QA ? 'revisi_qa_tab' : 'revisi_tab';
}

function permintaanRevisiLinkDetail(array $r): string
{
    if ($r['sumber'] === 'ASAKAI') {
        return 'update_asakai.php?id=' . (int) $r['id_asakai'];
    }
    if (strcasecmp((string) ($r['jenis_fpm'] ?? ''), 'Down Time') === 0) {
        return 'edit_fpm_downtime.php?id=' . (int) $r['id_fpm'];
    }
    return 'form_fpm.php?id=' . (int) $r['id_fpm'];
}

function renderPermintaanRevisiContent($koneksi, string $peminta = CM_REVISI_ROLE_IMP): void
{
    $dept = $_SESSION['dept'] ?? '';
    $semua = ambilPermintaanRevisiDept($koneksi, $dept, $peminta);
    $halaman = cmRevisiHalamanPic($peminta);
    $menunggu = array_values(array_filter($semua, fn($r) => $r['status_revisi'] === 'MENUNGGU'));
    $riwayat = array_values(array_filter($semua, fn($r) => $r['status_revisi'] !== 'MENUNGGU'));

    $paramTab = permintaanRevisiParamTab($peminta);
    $tab = ($_GET[$paramTab] ?? '') === 'riwayat' ? 'riwayat' : 'menunggu';
    $list = $tab === 'riwayat' ? $riwayat : $menunggu;
    // Link tab tetap di halaman Tugas Saya (parameter GET lain, mis. modul=qa, dipertahankan).
    $urlTab = function (string $nilai) use ($paramTab, $peminta) {
        $q = $_GET;
        unset($q[$paramTab]);
        if ($nilai !== 'menunggu') {
            $q[$paramTab] = $nilai;
        }
        return '?' . http_build_query($q) . '#' . permintaanRevisiAnchor($peminta);
    };
    ?>
    <style>
        .pr-wrap { max-width: 1100px; margin: 20px auto; padding: 0 16px; font-family: 'Inter', 'Segoe UI', sans-serif; }
        .pr-title { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
        .pr-sub { color: #6b7280; font-size: 13px; margin: 0 0 18px; }
        .pr-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .pr-tab { padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid #e5e7eb; color: #374151; background: #fff; }
        .pr-tab.active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
        .pr-tab .cnt { display: inline-block; min-width: 20px; padding: 0 6px; margin-left: 6px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; text-align: center; }
        .pr-tab.active .cnt { background: #fff; color: #1e3a8a; }
        .pr-card { background: #fff; border: 1px solid #e5e7eb; border-left: 5px solid #f59e0b; border-radius: 10px; padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 2px 6px rgba(0,0,0,.04); }
        .pr-card.done { border-left-color: #3b82f6; }
        .pr-card.closed { border-left-color: #22c55e; }
        .pr-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: flex-start; margin-bottom: 12px; }
        .pr-src { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: #eef2ff; color: #3730a3; margin-right: 6px; }
        .pr-src.asakai { background: #ecfeff; color: #155e75; }
        .pr-ident { font-weight: 700; font-size: 14px; }
        .pr-meta { color: #6b7280; font-size: 12px; margin-top: 2px; }
        .pr-body { display: grid; grid-template-columns: 1.3fr 1fr; gap: 16px; }
        .pr-lbl { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .pr-item { font-size: 13px; white-space: pre-wrap; margin-bottom: 10px; }
        .pr-note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 12px; font-size: 13.5px; color: #78350f; white-space: pre-wrap; }
        .pr-note small { display: block; color: #92400e; opacity: .8; margin-top: 4px; font-size: 11px; }
        .pr-form { margin-top: 14px; padding-top: 14px; border-top: 1px dashed #e5e7eb; display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
        .pr-form input[type=file] { width: 100%; font-size: 12.5px; padding: 6px; border: 1px dashed #94a3b8; border-radius: 8px; background: #f8fafc; }
        .pr-form textarea { width: 100%; font-size: 13px; padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 8px; min-height: 38px; resize: vertical; }
        .pr-btn { padding: 9px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; white-space: nowrap; color: #fff; background: #16a34a; }
        .pr-btn:hover { opacity: .9; }
        .pr-hint { font-size: 11.5px; color: #6b7280; margin-top: 4px; }
        .pr-link { font-size: 12px; text-decoration: none; color: #1d4ed8; white-space: nowrap; }
        .pr-tanggapan { margin-top: 10px; font-size: 12.5px; color: #1e3a8a; background: #eff6ff; border-radius: 8px; padding: 8px 10px; }
        .pr-empty { text-align: center; padding: 50px 20px; color: #6b7280; background: #fff; border: 1px dashed #d1d5db; border-radius: 10px; }
        @media (max-width: 768px) {
            .pr-body, .pr-form { grid-template-columns: 1fr; }
        }
    </style>

    <div class="pr-wrap" id="<?= permintaanRevisiAnchor($peminta) ?>">
        <h2 class="pr-title"><i class="bi bi-arrow-counterclockwise"></i> Permintaan Revisi dari <?= htmlspecialchars($peminta) ?></h2>
        <p class="pr-sub">
            Countermeasure dept <b><?= htmlspecialchars($dept ?: '-') ?></b> yang perlu diperbaiki - mis. salah upload foto.
            Pilih file yang benar lalu klik <b>Upload Perbaikan</b>; <?= htmlspecialchars($peminta) ?> akan memverifikasi ulang.
        </p>

        <div class="pr-tabs">
            <a href="<?= htmlspecialchars($urlTab('menunggu')) ?>" class="pr-tab <?= $tab === 'menunggu' ? 'active' : '' ?>">
                Perlu Diperbaiki<?php if (count($menunggu) > 0): ?><span class="cnt"><?= count($menunggu) ?></span><?php endif; ?>
            </a>
            <a href="<?= htmlspecialchars($urlTab('riwayat')) ?>" class="pr-tab <?= $tab === 'riwayat' ? 'active' : '' ?>">
                Sudah Ditindaklanjuti (<?= count($riwayat) ?>)
            </a>
        </div>

        <?php if (!$list): ?>
            <div class="pr-empty">
                <?php if ($tab === 'menunggu'): ?>
                    <i class="bi bi-check-circle" style="font-size:32px; color:#16a34a;"></i><br>
                    Tidak ada permintaan revisi. Semua countermeasure dept Anda aman.
                <?php else: ?>
                    Belum ada riwayat permintaan revisi.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($list as $r): ?>
            <?php
            $cfg = cmRevisiConfig($r['sumber']);
            $isAsakai = $r['sumber'] === 'ASAKAI';
            $punyaBaris = !empty($r['ref_table']) && isset($cfg['refs'][$r['ref_table']]);
            $labelItem = $punyaBaris ? $cfg['refs'][$r['ref_table']]['label'] : 'Catatan umum';
            $cardClass = ['MENUNGGU' => '', 'DIPERBAIKI' => 'done', 'SELESAI' => 'closed'][$r['status_revisi']] ?? '';
            $formId = 'rev_' . $r['sumber'] . '_' . (int) $r['id'];
            // Kuota upload = batas file per baris dikurangi file yang sudah ada (file lama tidak ditimpa).
            $maxFileBaris = $isAsakai ? ASAKAI_BUKTI_MAX_FILE : FPM_BUKTI_AKSI_MAX_FILE;
            $sisaFile = max(0, $maxFileBaris - count($r['files_semua'] ?? $r['files']));
            $bisaHapus = $punyaBaris && $r['status_revisi'] === 'MENUNGGU' && revisiBolehHapusFile($isAsakai);
            ?>
            <div class="pr-card <?= $cardClass ?>">
                <div class="pr-head">
                    <div>
                        <?php if ($isAsakai): ?>
                            <span class="pr-src asakai">ASAKAI</span>
                            <span class="pr-ident"><?= htmlspecialchars($r['machine'] ?? '') ?> &middot; <?= htmlspecialchars($r['namapart'] ?? '') ?></span>
                            <div class="pr-meta">Tanggal Asakai: <?= htmlspecialchars($r['tanggal'] ?? '-') ?> &middot; Plant <?= htmlspecialchars($r['plant'] ?? '-') ?></div>
                        <?php else: ?>
                            <span class="pr-src">FPM <?= htmlspecialchars(strtoupper($r['jenis_fpm'] ?? '')) ?></span>
                            <span class="pr-ident"><?= htmlspecialchars(($r['no_reg_fpm'] ?? '') ?: ($r['no_form'] ?? '-')) ?></span>
                            <div class="pr-meta"><?= htmlspecialchars($r['nama_part'] ?? '') ?> &middot; Mesin <?= htmlspecialchars($r['machine_no'] ?? '-') ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;">
                        <?= cmRevisiBadge($r['status_revisi']) ?>
                        <div style="margin-top:6px;"><a class="pr-link" href="<?= permintaanRevisiLinkDetail($r) ?>"><i class="bi bi-box-arrow-up-right"></i> Lihat <?= $isAsakai ? 'Asakai' : 'FPM' ?></a></div>
                    </div>
                </div>

                <div class="pr-body">
                    <div>
                        <div class="pr-lbl"><?= htmlspecialchars($labelItem) ?></div>
                        <div class="pr-item"><?= $punyaBaris ? nl2br(htmlspecialchars($r['item_deskripsi'] ?? '')) : '<i style="color:#6b7280;">Tidak terkait baris tertentu</i>' ?></div>

                        <div class="pr-lbl">Catatan <?= htmlspecialchars(cmRevisiPeminta($r)) ?></div>
                        <div class="pr-note"><?= nl2br(htmlspecialchars($r['catatan'])) ?><small><?= htmlspecialchars($r['dibuat_oleh'] ?? '') ?> &middot; <?= date('d-m-Y H:i', strtotime($r['created_at'])) ?></small></div>
                    </div>
                    <?php if ($punyaBaris): ?>
                        <div>
                            <div class="pr-lbl"><?= $r['status_revisi'] === 'MENUNGGU' ? 'File saat ini (yang diminta diganti)' : 'File saat ini' ?></div>
                            <?= renderFileRevisiHapus($r, $bisaHapus) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($r['tanggapan_pic']) || !empty($r['selesai_at'])): ?>
                    <div class="pr-tanggapan">
                        <b>Tanggapan:</b> <?= !empty($r['tanggapan_pic']) ? nl2br(htmlspecialchars($r['tanggapan_pic'])) : '<i>upload ulang file</i>' ?>
                        <?php if (!empty($r['selesai_at'])): ?>
                            <span style="color:#6b7280; font-size:11px;"> &middot; <?= htmlspecialchars($r['selesai_oleh'] ?? '') ?>, <?= date('d-m-Y H:i', strtotime($r['selesai_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($r['status_revisi'] === 'MENUNGGU'): ?>
                    <form method="POST" action="<?= $halaman ?>" enctype="multipart/form-data" class="pr-form" id="<?= $formId ?>"
                        onsubmit="return cekFormRevisi(this, <?= $punyaBaris ? 'true' : 'false' ?>);">
                        <input type="hidden" name="sumber" value="<?= $r['sumber'] ?>">
                        <input type="hidden" name="id_revisi" value="<?= (int) $r['id'] ?>">
                        <?php if ($punyaBaris): ?>
                            <div>
                                <div class="pr-lbl">Upload file perbaikan (maks <?= $maxFileBaris ?> file per baris)</div>
                                <input type="file" name="file_revisi[]" multiple
                                    accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx"
                                    data-bukti-ui data-sisa="<?= $sisaFile ?>" data-max="<?= $maxFileBaris ?>"<?= $sisaFile === 0 ? ' disabled' : '' ?>>
                                <div class="pr-hint">
                                    File baru <b>ditambahkan</b>, file lama tidak ditimpa.
                                    <?= $bisaHapus
                                        ? 'Untuk mengganti file yang salah, hapus dulu lewat tombol ✕ pada file di atas.'
                                        : 'Untuk mengganti file yang salah, minta Dept Head menghapus file lamanya dulu.' ?>
                                    Video maks 10MB.
                                </div>
                            </div>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                        <div>
                            <div class="pr-lbl">Keterangan <?= $punyaBaris ? '(opsional)' : '' ?></div>
                            <textarea name="tanggapan" placeholder="Contoh: foto sudah diganti dengan foto perbaikan yang benar"></textarea>
                        </div>
                        <button type="submit" name="tindak_lanjut_revisi" value="1" class="pr-btn">
                            <?= $punyaBaris ? '<i class="bi bi-upload"></i> Upload Perbaikan' : '<i class="bi bi-check2"></i> Tandai Sudah Ditindaklanjuti' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?= renderUploadBuktiUI() ?>
    <script>
        function cekFormRevisi(form, wajibFile) {
            var input = form.querySelector('input[type=file]');
            if (wajibFile && (!input || input.files.length === 0)) {
                alert('Pilih file pengganti terlebih dahulu.');
                return false;
            }
            if (!wajibFile && form.querySelector('textarea').value.trim() === '') {
                return confirm('Tandai sudah ditindaklanjuti tanpa keterangan?');
            }
            var btn = form.querySelector('button[type=submit]');
            setTimeout(function () { btn.disabled = true; btn.textContent = 'Mengupload...'; }, 0);
            return true;
        }

        // Hapus file yang salah upload (file lama tidak pernah ditimpa upload baru), lalu kuota
        // upload di kartu yang sama dibuka lagi tanpa reload.
        function hapusFileRevisi(btn) {
            if (!confirm('Hapus file ini? File yang dihapus tidak bisa dikembalikan.')) return;

            var asakai = btn.dataset.sumber === 'ASAKAI';
            var fd = new FormData();
            fd.append('jenis', btn.dataset.jenis);
            fd.append('file', btn.dataset.file);
            if (asakai) {
                fd.append('id_cm', btn.dataset.id);
            } else {
                fd.append('id_fpm', btn.dataset.idFpm);
                fd.append('id_aksi', btn.dataset.id);
            }
            btn.disabled = true;

            fetch(asakai ? '../config/ajax_hapus_bukti_asakai.php' : '../config/ajax_hapus_bukti_aksi_fpm.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json().catch(function () { throw new Error('Respon server tidak valid (HTTP ' + r.status + ')'); }); })
                .then(function (res) {
                    if (!res.ok) throw new Error(res.pesan || 'Gagal menghapus file');
                    var card = btn.closest('.pr-card');
                    var wadah = btn.closest('.pr-file').parentNode;
                    btn.closest('.pr-file').remove();
                    if (!wadah.querySelector('.pr-file')) {
                        wadah.outerHTML = '<span style="color:#9ca3af; font-size:12px;">Belum ada file</span>';
                    }
                    var input = card && card.querySelector('input[name="file_revisi[]"]');
                    if (input) {
                        var sisa = Math.min(parseInt(input.dataset.max, 10), parseInt(input.dataset.sisa || '0', 10) + 1);
                        input.dataset.sisa = sisa;
                        input.disabled = sisa === 0;
                    }
                })
                .catch(function (err) {
                    btn.disabled = false;
                    alert(err.message);
                });
        }
    </script>
    <?php
}
