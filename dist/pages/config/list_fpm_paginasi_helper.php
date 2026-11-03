<?php

/**
 * Tampilan tabel list FPM (Down Time, Internal, Claim Machining) semua role:
 *  - Paginasi per bulan masalah terjadi (fpm_header.tgl_ditemukan; kalau kosong pakai
 *    tgl_pembuatan). Parameter GET: bulan=YYYY-MM, atau bulan=semua untuk semua data.
 *    Default bulan terbaru yang punya data (sesuai filter lain yang sedang dipakai), atau
 *    semua bulan kalau rentang Tanggal Awal/Akhir dipakai. Bulan yang dipilih ikut terbawa
 *    saat filter lain diganti (list_fpm_filter_ui.php); kosong untuk filter baru -> dialihkan.
 *  - Hanya isi tabel yang bisa di-scroll, header tabel di-freeze (CSS .fpm-table-scroll).
 *
 * Pemakaian (sesudah $where selesai disusun, sebelum query list):
 *   $paginasiBulan = siapkanPaginasiBulanFpm($koneksi, $where);
 *   $where .= $paginasiBulan['sql'];
 * dan di atas tabel:
 *   <?= renderPaginasiBulanFpm($paginasiBulan) ?>
 *   <div class="fpm-table-scroll"> <table>...</table> </div>
 */

// Tanggal masalah terjadi. Data lama ada yang berformat dd-mm-YYYY, jadi dua format dicoba.
function sqlTanggalMasalahFpm(): string
{
    $tgl = "COALESCE(NULLIF(h.tgl_ditemukan, ''), h.tgl_pembuatan)";
    return "COALESCE(STR_TO_DATE($tgl, '%Y-%m-%d'), STR_TO_DATE($tgl, '%d-%m-%Y'))";
}

// Tanggal pembuatan FPM untuk filter Tanggal Awal / Akhir. Data tersimpan YYYY-MM-DD
// (data lama dd-mm-YYYY) - dulu hanya dd-mm-YYYY yang dicoba, jadi filter tanggal selalu kosong.
function sqlTanggalPembuatanFpm(): string
{
    return "COALESCE(STR_TO_DATE(h.tgl_pembuatan, '%Y-%m-%d'), STR_TO_DATE(h.tgl_pembuatan, '%d-%m-%Y'))";
}

function siapkanPaginasiBulanFpm($koneksi, string $where): array
{
    $tglMasalah = sqlTanggalMasalahFpm();

    $daftar = [];
    $q = mysqli_query($koneksi, "SELECT DATE_FORMAT($tglMasalah, '%Y-%m') AS bulan, COUNT(*) AS jumlah
        FROM fpm_header h
        LEFT JOIN fpm_pengiriman p ON p.id_fpm = h.id_fpm
        $where
        GROUP BY bulan
        ORDER BY bulan DESC");
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        if (!empty($row['bulan'])) {
            $daftar[$row['bulan']] = (int) $row['jumlah'];
        }
    }

    // Rentang Tanggal Awal/Akhir sudah menentukan periode, jadi defaultnya semua bulan
    // dalam rentang itu (bukan cuma bulan terbaru).
    $rentangTanggal = !empty($_GET['tgl_awal']) && !empty($_GET['tgl_akhir']);
    $diminta = $_GET['bulan'] ?? ($rentangTanggal ? 'semua' : '');

    // Bulan yang dipilih tetap dipakai saat filter lain diganti. Kalau bulan itu tidak punya
    // data untuk filter yang baru, pindah ke bulan terbaru yang ada datanya + beri tahu user,
    // supaya tabel tidak kosong tanpa penjelasan.
    $bulan = $diminta;
    if ($bulan !== 'semua' && !isset($daftar[$bulan])) {
        $bulan = $daftar ? array_key_first($daftar) : 'semua';
    }
    $dialihkanDari = ($diminta !== '' && $diminta !== 'semua' && $diminta !== $bulan) ? $diminta : '';

    $sql = '';
    if ($bulan !== 'semua') {
        $bulanEsc = mysqli_real_escape_string($koneksi, $bulan);
        $sql = " AND DATE_FORMAT($tglMasalah, '%Y-%m') = '$bulanEsc'";
    }

    return ['bulan' => $bulan, 'daftar' => $daftar, 'sql' => $sql, 'dialihkan_dari' => $dialihkanDari];
}

function labelBulanFpm(string $bulan): string
{
    $nama = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun',
        '07' => 'Jul', '08' => 'Agu', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];
    [$tahun, $bln] = explode('-', $bulan) + [1 => ''];
    return ($nama[$bln] ?? $bln) . ' ' . $tahun;
}

function urlBulanFpm(string $bulan): string
{
    $query = $_GET;
    unset($query['export']);
    $query['bulan'] = $bulan;
    return '?' . htmlspecialchars(http_build_query($query));
}

function renderPaginasiBulanFpm(array $paginasi): string
{
    $daftar = $paginasi['daftar'];
    $aktif  = $paginasi['bulan'];
    $kunci  = array_keys($daftar); // terbaru dulu
    $posisi = array_search($aktif, $kunci, true);

    $html = '<style>
        .fpm-table-scroll { overflow: auto; max-height: calc(100vh - 330px); min-height: 320px; border: 1px solid #eee; border-radius: 8px; }
        .fpm-table-scroll thead th { position: sticky; top: 0; z-index: 3; background: #f1f3f5; box-shadow: inset 0 -1px 0 #ddd; }
        .fpm-paginasi-bulan { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .fpm-paginasi-bulan .judul { font-size: 13px; font-weight: 600; color: #555; margin-right: 4px; }
        .fpm-paginasi-bulan a, .fpm-paginasi-bulan span.nonaktif { padding: 5px 11px; border: 1px solid #d0d7de; border-radius: 6px; font-size: 13px; text-decoration: none; color: #0d6efd; background: #fff; white-space: nowrap; }
        .fpm-paginasi-bulan a.aktif { background: #0d6efd; border-color: #0d6efd; color: #fff; }
        .fpm-paginasi-bulan span.nonaktif { color: #aaa; }
        .fpm-paginasi-bulan small { opacity: .75; }
        .fpm-paginasi-bulan select { width: auto; padding: 4px 8px; font-size: 13px; border: 1px solid #d0d7de; border-radius: 6px; margin-left: 6px; }
        .fpm-paginasi-info { font-size: 13px; padding: 7px 12px; margin-bottom: 10px; border-radius: 6px; background: #fff8e1; border: 1px solid #ffe08a; color: #7a5b00; }
    </style>';

    if (!empty($paginasi['dialihkan_dari'])) {
        $html .= '<div class="fpm-paginasi-info"><i class="bi bi-info-circle"></i> Tidak ada data di <b>'
            . htmlspecialchars(labelBulanFpm($paginasi['dialihkan_dari'])) . '</b> untuk filter yang dipilih, jadi ditampilkan <b>'
            . ($aktif === 'semua' ? 'Semua Bulan' : htmlspecialchars(labelBulanFpm($aktif))) . '</b>.</div>';
    }

    $html .= '<div class="fpm-paginasi-bulan"><span class="judul">Bulan Masalah:</span>';

    // Tombol Sebelumnya = bulan lebih lama, Berikutnya = bulan lebih baru.
    $lebihLama = ($posisi !== false && isset($kunci[$posisi + 1])) ? $kunci[$posisi + 1] : null;
    $lebihBaru = ($posisi !== false && $posisi > 0) ? $kunci[$posisi - 1] : null;

    $html .= $lebihLama ? '<a href="' . urlBulanFpm($lebihLama) . '">&laquo; Sebelumnya</a>' : '<span class="nonaktif">&laquo; Sebelumnya</span>';
    // Tampilkan maksimal 6 bulan di kiri-kanan bulan aktif supaya baris tombol tidak kepanjangan.
    $mulai = max(0, ($posisi === false ? 0 : $posisi) - 6);
    foreach (array_slice($daftar, $mulai, 13, true) as $bulan => $jumlah) {
        $html .= '<a href="' . urlBulanFpm($bulan) . '"' . ($bulan === $aktif ? ' class="aktif"' : '') . '>'
            . labelBulanFpm($bulan) . ' <small>(' . $jumlah . ')</small></a>';
    }
    $html .= $lebihBaru ? '<a href="' . urlBulanFpm($lebihBaru) . '">Berikutnya &raquo;</a>' : '<span class="nonaktif">Berikutnya &raquo;</span>';
    $html .= '<a href="' . urlBulanFpm('semua') . '"' . ($aktif === 'semua' ? ' class="aktif"' : '') . '>Semua Bulan</a>';

    // Lompat langsung ke bulan mana pun (tombol di atas hanya menampilkan bulan di sekitar bulan aktif).
    if (count($daftar) > 1) {
        $html .= '<select onchange="if (this.value) location.href = this.value" title="Lompat ke bulan">'
            . '<option value="">Lompat ke bulan&hellip;</option>';
        foreach ($daftar as $bulan => $jumlah) {
            $html .= '<option value="' . urlBulanFpm($bulan) . '"' . ($bulan === $aktif ? ' selected' : '') . '>'
                . labelBulanFpm($bulan) . ' (' . $jumlah . ')</option>';
        }
        $html .= '</select>';
    }

    return $html . '</div>';
}
