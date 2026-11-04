<?php

/**
 * Export Excel untuk semua halaman list FPM (Internal, Down Time, Claim Machining,
 * QA) di semua role. Tidak menulis ulang query per halaman - halaman list dirender
 * seperti biasa (filter GET yang sama), lalu tabel hasil render-nya diubah jadi .xlsx.
 * Dengan begitu isi kolom, status, label approval, dan filter di Excel otomatis sama
 * persis dengan yang terlihat di web untuk role tersebut.
 *
 * Pemakaian di halaman list (sebelum ada output HTML apa pun):
 *   require_once __DIR__ . '/../config/list_fpm_excel_export.php';
 *   exportListFpmExcelJikaDiminta('List FPM Down Time');
 * dan tombolnya di samping tombol Reset:
 *   <?= tombolExportExcelListFpm() ?>
 */

function exportListFpmExcelJikaDiminta(string $judul): void
{
    if (($_GET['export'] ?? '') !== 'excel' || empty($_SESSION['username'])) {
        return;
    }

    $levelAwal = ob_get_level();
    ob_start();

    // Shutdown function jalan SEBELUM output buffer di-flush PHP, jadi seluruh HTML
    // halaman masih bisa diambil di sini dan diganti dengan file xlsx.
    register_shutdown_function(function () use ($judul, $levelAwal) {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return; // biarkan pesan error halaman tampil apa adanya
        }

        while (ob_get_level() > $levelAwal + 1) {
            ob_end_flush();
        }
        $html = ob_get_level() > $levelAwal ? ob_get_clean() : '';

        try {
            kirimExcelDariHtmlListFpm($html, $judul);
        } catch (Throwable $e) {
            error_log('Export Excel list FPM gagal: ' . $e->getMessage());
            echo 'Export Excel gagal. Silakan coba lagi atau hubungi Admin IT.';
        }
    });
}

function tombolExportExcelListFpm(): string
{
    $query = $_GET;
    $query['export'] = 'excel';

    return '<a href="?' . htmlspecialchars(http_build_query($query)) . '" class="btn btn-success">'
        . '<i class="bi bi-file-earmark-excel"></i> Export Excel</a>';
}

/**
 * Ambil teks sel seperti yang terlihat di layar: <br>/div jadi baris baru, <details>
 * cuma diambil <summary>-nya (riwayat revisi yang tertutup tidak ikut), tombol dibuang.
 */
function teksSelListFpm(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return preg_replace('/\s+/u', ' ', $node->nodeValue);
    }

    if (!$node instanceof DOMElement) {
        return '';
    }

    $tag = strtolower($node->nodeName);

    if (in_array($tag, ['script', 'style', 'button', 'form', 'input', 'select', 'textarea'], true)) {
        return '';
    }

    if ($tag === 'br') {
        return "\n";
    }

    if ($tag === 'details') {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'summary') {
                return "\n" . teksSelListFpm($child) . "\n";
            }
        }
        return '';
    }

    $teks = '';
    foreach ($node->childNodes as $child) {
        $teks .= teksSelListFpm($child);
    }

    if (in_array($tag, ['div', 'p', 'li', 'ul', 'ol'], true)) {
        $teks = "\n" . $teks . "\n";
    }

    return $teks;
}

function rapikanTeksSelListFpm(string $teks): string
{
    $baris = array_values(array_filter(array_map('trim', explode("\n", $teks)), 'strlen'));

    // Note + ringkasan revisi kosong tampil "teks" lalu "-" - strip "-" kalau sudah ada isi lain.
    if (count($baris) > 1) {
        $baris = array_values(array_filter($baris, fn($b) => $b !== '-'));
    }

    return $baris ? implode("\n", $baris) : '-';
}

/** Warna sel ikut warna badge di web (hijau/kuning/merah). */
function warnaBadgeSelListFpm(DOMElement $td): ?string
{
    $peta = ['bg-success' => 'D1FAE5', 'bg-warning' => 'FEF3C7', 'bg-danger' => 'FEE2E2'];

    foreach ($td->getElementsByTagName('span') as $span) {
        $kelas = ' ' . $span->getAttribute('class') . ' ';
        foreach ($peta as $kelasBadge => $warna) {
            if (strpos($kelas, " $kelasBadge ") !== false) {
                return $warna;
            }
        }
    }

    return null;
}

/** Ringkasan filter yang sedang aktif, dibaca dari form GET di halaman. */
function ringkasanFilterListFpm(DOMXPath $xpath): string
{
    $bagian = [];

    foreach ($xpath->query('//main//form[translate(@method,"get","GET")="GET"]//*[@name]') as $el) {
        $nama = $el->getAttribute('name');
        $tag = strtolower($el->nodeName);
        $nilai = '';

        if ($tag === 'select') {
            foreach ($el->getElementsByTagName('option') as $opt) {
                if ($opt->hasAttribute('selected') && $opt->getAttribute('value') !== '') {
                    $nilai = trim($opt->textContent);
                }
            }
        } elseif ($tag === 'input' && !in_array(strtolower($el->getAttribute('type')), ['submit', 'button', 'hidden'], true)) {
            $nilai = trim($el->getAttribute('value'));
        }

        if ($nilai === '') {
            continue;
        }

        $label = $nama;
        $labelNode = $xpath->query('label', $el->parentNode)->item(0);
        if ($labelNode) {
            $label = trim(preg_replace('/\s+/u', ' ', $labelNode->textContent));
        }

        $bagian[] = "$label: $nilai";
    }

    return $bagian ? implode('; ', $bagian) : 'Semua data';
}

function kirimExcelDariHtmlListFpm(string $html, string $judul): void
{
    require_once __DIR__ . '/../../../vendor/autoload.php';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $table = $xpath->query('//main//table[thead]')->item(0) ?: $xpath->query('//table')->item(0);
    if (!$table) {
        throw new RuntimeException('Tabel list tidak ditemukan.');
    }

    // Header + index kolom yang dibuang (Action) / diringkas (Lampiran).
    $header = [];
    $kolomDipakai = [];
    $kolomLampiran = [];
    $thList = $xpath->query('.//thead//th', $table);
    foreach ($thList as $i => $th) {
        $teks = rapikanTeksSelListFpm(str_replace("\n", ' ', teksSelListFpm($th)));
        $kunci = strtolower($teks);
        if (in_array($kunci, ['action', 'aksi'], true)) {
            continue;
        }
        if ($kunci === 'lampiran') {
            $kolomLampiran[$i] = true;
        }
        $kolomDipakai[] = $i;
        $header[] = $teks;
    }

    $dataRows = [];
    foreach ($xpath->query('.//tbody/tr', $table) as $tr) {
        if (strpos(' ' . $tr->getAttribute('class') . ' ', ' empty-row ') !== false) {
            continue;
        }

        $tdList = [];
        foreach ($tr->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'td') {
                $tdList[] = $child;
            }
        }

        $baris = [];
        foreach ($kolomDipakai as $i) {
            $td = $tdList[$i] ?? null;
            if (!$td) {
                $baris[] = ['-', null];
                continue;
            }
            if (isset($kolomLampiran[$i])) {
                $baris[] = [$td->getElementsByTagName('a')->length > 0 ? 'Ada' : '-', null];
                continue;
            }
            $baris[] = [rapikanTeksSelListFpm(teksSelListFpm($td)), warnaBadgeSelListFpm($td)];
        }
        $dataRows[] = $baris;
    }

    $jumlahKolom = count($header);
    $kolomAkhir = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($jumlahKolom);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $judul), 0, 31));

    $sheet->setCellValue('A1', $judul);
    $sheet->mergeCells("A1:{$kolomAkhir}1");
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $sheet->setCellValue('A2', 'Filter: ' . ringkasanFilterListFpm($xpath));
    $sheet->mergeCells("A2:{$kolomAkhir}2");
    $sheet->setCellValue('A3', 'Diekspor: ' . date('d-m-Y H:i') . ' oleh ' . ($_SESSION['username'] ?? '-')
        . ' | Total data: ' . count($dataRows));
    $sheet->mergeCells("A3:{$kolomAkhir}3");
    $sheet->getStyle('A2:A3')->getFont()->setItalic(true)->getColor()->setRGB('4B5563');

    $barisHeader = 5;
    foreach ($header as $c => $teks) {
        $sheet->setCellValueExplicit([$c + 1, $barisHeader], $teks, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $rangeHeader = "A{$barisHeader}:{$kolomAkhir}{$barisHeader}";
    $sheet->getStyle($rangeHeader)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1F4E78']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
    ]);
    $sheet->getRowDimension($barisHeader)->setRowHeight(32);

    $lebar = array_map(fn($h) => mb_strlen($h), $header);
    $r = $barisHeader;

    foreach ($dataRows as $baris) {
        $r++;
        foreach ($baris as $c => [$nilai, $warna]) {
            if ($c === 0 && ctype_digit($nilai)) {
                $sheet->setCellValue([1, $r], (int) $nilai);
            } else {
                $sheet->setCellValueExplicit([$c + 1, $r], $nilai, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }

            if ($warna) {
                $sheet->getStyle([$c + 1, $r])->getFill()->setFillType('solid')->getStartColor()->setRGB($warna);
                $sheet->getStyle([$c + 1, $r])->getFont()->setBold(true);
                $sheet->getStyle([$c + 1, $r])->getAlignment()->setHorizontal('center');
            }

            foreach (explode("\n", $nilai) as $potongan) {
                $lebar[$c] = max($lebar[$c], mb_strlen($potongan));
            }
        }
    }

    if (!$dataRows) {
        $r++;
        $sheet->setCellValue("A{$r}", 'Belum ada data');
        $sheet->mergeCells("A{$r}:{$kolomAkhir}{$r}");
        $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
    }

    $rangeTabel = "A{$barisHeader}:{$kolomAkhir}{$r}";
    $sheet->getStyle($rangeTabel)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '9CA3AF']]],
    ]);
    if ($r > $barisHeader) {
        $sheet->getStyle("A" . ($barisHeader + 1) . ":{$kolomAkhir}{$r}")->getAlignment()
            ->setVertical('top')->setWrapText(true);
        $sheet->getStyle("A" . ($barisHeader + 1) . ":A{$r}")->getAlignment()->setHorizontal('center');
    }

    foreach ($lebar as $c => $w) {
        $sheet->getColumnDimensionByColumn($c + 1)->setWidth(min(max($w + 2, 8), 45));
    }

    if ($dataRows) {
        $sheet->setAutoFilter($rangeTabel);
    }
    $sheet->freezePane('A' . ($barisHeader + 1));

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)->setFitToHeight(0)
        ->setRowsToRepeatAtTopByStartAndEnd($barisHeader, $barisHeader);

    $namaFile = preg_replace('/[^A-Za-z0-9]+/', '_', trim($judul)) . '_' . date('Ymd_His') . '.xlsx';

    header_remove('Location');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Cache-Control: max-age=0');

    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
}
