<?php

/**
 * Helper tabel Summary Asakai (list_asakai.php di semua level).
 *
 * Query summary men-join countermeasure ke problem/analisa lewat id_analisa. Data
 * lama yang id_analisa-nya kosong/0 tidak tahu milik problem yang mana, jadi tetap
 * ter-join ke SETIAP problem dengan kategori yang sama - helper ini membuang
 * duplikat tersebut supaya countermeasure yang sama tidak muncul lagi sebagai
 * baris kosong di bawah problem berikutnya.
 */

if (!function_exists('buangCountermeasureDuplikat')) {
    /**
     * @param array $groupProblem [key problem => baris-baris hasil query]
     * @param array $shownCm      penanda countermeasure yang sudah tampil (by reference)
     * @return array $groupProblem tanpa baris duplikat. Problem yang semua
     *               countermeasure-nya sudah tampil di problem lain tetap disisakan
     *               1 baris bertanda `_cm_kosong` supaya problem & analisanya tetap tampil.
     */
    function buangCountermeasureDuplikat(array $groupProblem, array &$shownCm): array
    {
        foreach ($groupProblem as $key => $rows) {
            $kept = [];

            foreach ($rows as $r) {
                $kategori = $r['kategori'] ?? '';
                $cmId = null;
                if ($kategori === 'DOWN_TIME') {
                    $cmId = $r['id_cm_dt'] ?? null;
                } elseif ($kategori === 'NG') {
                    $cmId = $r['id_cm_ng'] ?? null;
                }

                if ($cmId !== null) {
                    $cmKey = $kategori . '#' . $r['id_asakai'] . '#' . $cmId;
                    if (isset($shownCm[$cmKey])) {
                        continue;
                    }
                    $shownCm[$cmKey] = true;
                }

                $kept[] = $r;
            }

            if (empty($kept)) {
                $r = $rows[0];
                $r['_cm_kosong'] = true;
                $kept[] = $r;
            }

            $groupProblem[$key] = $kept;
        }

        return $groupProblem;
    }
}

if (!function_exists('labelProblemAsakai')) {
    /**
     * Label kategori problem. Kalau dalam satu asakai ada lebih dari 1 problem dengan
     * kategori sama, diberi nomor ("DOWN TIME #1", "DOWN TIME #2") supaya pembaca
     * tahu countermeasure mana milik problem yang mana.
     */
    function labelProblemAsakai(string $kategori, int $nomor, int $jumlahSejenis): string
    {
        if ($kategori === 'DOWN_TIME') {
            $label = 'DOWN TIME';
        } elseif ($kategori === 'NG') {
            $label = 'NG';
        } else {
            return '-';
        }

        return $jumlahSejenis > 1 ? $label . ' #' . $nomor : $label;
    }
}

if (!function_exists('kondisiFilterTambahanAsakai')) {
    /**
     * Kondisi WHERE untuk filter tambahan Summary Asakai (Nama Part, Type, Kategori,
     * Status). Dipakai list_asakai.php semua role dan export Excel-nya supaya hasil
     * export sama dengan yang tampil di layar.
     *
     * Status OVERDUE = countermeasure belum CLOSED dan due date sudah lewat.
     */
    function kondisiFilterTambahanAsakai(mysqli $koneksi, array $get): array
    {
        $conditions = [];

        if (!empty($get['nama_part'])) {
            $part = mysqli_real_escape_string($koneksi, trim($get['nama_part']));
            $conditions[] = "(h.namapart LIKE '%$part%' OR h.partnumber LIKE '%$part%')";
        }

        if (!empty($get['type'])) {
            $type = mysqli_real_escape_string($koneksi, $get['type']);
            $conditions[] = "h.type = '$type'";
        }

        if (!empty($get['kategori']) && in_array($get['kategori'], ['DOWN_TIME', 'NG'], true)) {
            $conditions[] = "a.kategori = '{$get['kategori']}'";
        }

        $status = strtoupper($get['status'] ?? '');
        if ($status === 'OPEN' || $status === 'CLOSED') {
            $conditions[] = "UPPER(COALESCE(cd.status, cn.status)) = '$status'";
        } elseif ($status === 'OVERDUE') {
            $conditions[] = "(
                COALESCE(cd.id_cm_dt, cn.id_cm_ng) IS NOT NULL
                AND UPPER(IFNULL(COALESCE(cd.status, cn.status), '')) <> 'CLOSED'
                AND COALESCE(cd.due_date, cn.due_date) <> '0000-00-00'
                AND COALESCE(cd.due_date, cn.due_date) < CURDATE()
            )";
        }

        return $conditions;
    }
}

if (!function_exists('kondisiFilterPartStock')) {
    /**
     * Filter Nama Part juga diterapkan ke tabel Delivery & Stock Casting.
     * Mengembalikan potongan "AND ..." (string kosong kalau filter tidak diisi).
     */
    function kondisiFilterPartStock(mysqli $koneksi, array $get, string $kolomNama, string $kolomPartNumber): string
    {
        if (empty($get['nama_part'])) {
            return '';
        }

        $part = mysqli_real_escape_string($koneksi, trim($get['nama_part']));
        return " AND ($kolomNama LIKE '%$part%' OR $kolomPartNumber LIKE '%$part%')";
    }
}

if (!function_exists('formatAnalisaAsakai')) {
    /**
     * Analisa 1-4 sebagai daftar bernomor, hanya yang terisi (sebelumnya digabung
     * dengan pemisah "." sehingga analisa kosong tampil sebagai titik-titik).
     */
    function formatAnalisaAsakai(array $r): string
    {
        $items = [];
        foreach (['analisa1', 'analisa2', 'analisa3', 'analisa4'] as $kolom) {
            $isi = trim((string)($r[$kolom] ?? ''));
            if ($isi !== '' && $isi !== '-') {
                $items[] = $isi;
            }
        }

        if (empty($items)) {
            return '-';
        }

        if (count($items) === 1) {
            return nl2br(htmlspecialchars($items[0]));
        }

        $html = '<ol style="margin:0; padding-left:16px;">';
        foreach ($items as $isi) {
            $html .= '<li>' . nl2br(htmlspecialchars($isi)) . '</li>';
        }
        return $html . '</ol>';
    }
}
