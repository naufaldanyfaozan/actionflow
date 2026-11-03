<?php

use PhpOffice\PhpWord\PhpWord;

/**
 * Renders the same sections generateReportInsights() produces into a .docx.
 */
function buildReportWord(array $insights, string $title = 'Laporan Insight Dashboard'): PhpWord
{
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    $section->addText($title, ['bold' => true, 'size' => 18]);
    $plantLabel = !empty($insights['plant']) ? $insights['plant'] : 'Semua Plant';
    $section->addText("Periode: {$insights['periode']}   |   Plant: {$plantLabel}", ['size' => 10, 'italic' => true, 'color' => '6c757d']);
    $section->addTextBreak(1);

    $section->addText('Ringkasan Eksekutif', ['bold' => true, 'size' => 14]);
    $section->addText($insights['executive_summary'], ['size' => 11]);
    $section->addTextBreak(1);

    $section->addText('Rekomendasi Tindakan Prioritas', ['bold' => true, 'size' => 14]);
    if (empty($insights['priority_actions'])) {
        $section->addText('Tidak ada tindakan prioritas pada periode ini.', ['italic' => true]);
    } else {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        addHeaderCell($table, 'Prioritas', 1500);
        addHeaderCell($table, 'Kategori', 2500);
        addHeaderCell($table, 'Rekomendasi', 6000);
        foreach ($insights['priority_actions'] as $action) {
            $table->addRow();
            $table->addCell(1500)->addText($action['severity'], ['bold' => true, 'color' => severityColor($action['severity'])]);
            $table->addCell(2500)->addText($action['category']);
            $table->addCell(6000)->addText($action['text']);
        }
    }
    $section->addTextBreak(1);

    $section->addText('Departemen Perlu Perhatian (% Countermeasure Closed)', ['bold' => true, 'size' => 14]);
    addDeptTable($section, 'FPM', $insights['dept_ranking']['fpm']);
    addDeptTable($section, 'Asakai', $insights['dept_ranking']['asakai']);
    $section->addTextBreak(1);

    $section->addText('Countermeasure Overdue', ['bold' => true, 'size' => 14]);
    if (empty($insights['overdue'])) {
        $section->addText('Tidak ada countermeasure overdue.', ['italic' => true]);
    } else {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        addHeaderCell($table, 'Sumber', 1200);
        addHeaderCell($table, 'PIC', 1800);
        addHeaderCell($table, 'Deskripsi', 4500);
        addHeaderCell($table, 'Due Date', 1500);
        foreach ($insights['overdue'] as $row) {
            $table->addRow();
            $table->addCell(1200)->addText($row['source']);
            $table->addCell(1800)->addText($row['pic'] ?? '-');
            $table->addCell(4500)->addText($row['deskripsi'] ?? '-');
            $table->addCell(1500)->addText($row['due_date'] ?? '-');
        }
    }
    $section->addTextBreak(1);

    $section->addText('Part / Mesin Bermasalah Berulang', ['bold' => true, 'size' => 14]);
    if (empty($insights['recurring_problems'])) {
        $section->addText('Tidak ada masalah berulang pada periode ini.', ['italic' => true]);
    } else {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        addHeaderCell($table, 'Jenis', 1500);
        addHeaderCell($table, 'Nama', 6000);
        addHeaderCell($table, 'Jumlah Kejadian', 1500);
        foreach ($insights['recurring_problems'] as $row) {
            $table->addRow();
            $table->addCell(1500)->addText($row['jenis']);
            $table->addCell(6000)->addText($row['label']);
            $table->addCell(1500)->addText((string) $row['total']);
        }
    }
    $section->addTextBreak(1);

    $section->addText('Risiko Stock Part', ['bold' => true, 'size' => 14]);
    if (empty($insights['stock_risk'])) {
        $section->addText('Tidak ada part berisiko kekurangan stock.', ['italic' => true]);
    } else {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        addHeaderCell($table, 'Nama Part', 3500);
        addHeaderCell($table, 'Stock FG', 1500);
        addHeaderCell($table, 'Delivery/Hari', 1500);
        foreach ($insights['stock_risk'] as $row) {
            $table->addRow();
            $table->addCell(3500)->addText($row['nama_part'] ?? '-');
            $table->addCell(1500)->addText((string) ($row['act_stock'] ?? '-'));
            $table->addCell(1500)->addText((string) ($row['delivery_per_hari'] ?? '-'));
        }
    }

    return $phpWord;
}

function addHeaderCell($table, string $text, int $width): void
{
    $cell = $table->addCell($width, ['bgColor' => 'eef2ff']);
    $cell->addText($text, ['bold' => true]);
}

function addDeptTable($section, string $label, array $rows): void
{
    $section->addText($label, ['bold' => true, 'size' => 12]);
    if (empty($rows)) {
        $section->addText('Tidak ada data.', ['italic' => true]);
        return;
    }
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
    $table->addRow();
    addHeaderCell($table, 'Departemen', 3000);
    addHeaderCell($table, 'Open', 1500);
    addHeaderCell($table, 'Closed', 1500);
    addHeaderCell($table, '% Closed', 1500);
    foreach ($rows as $row) {
        $table->addRow();
        $table->addCell(3000)->addText($row['dept']);
        $table->addCell(1500)->addText((string) $row['open']);
        $table->addCell(1500)->addText((string) $row['closed']);
        $table->addCell(1500)->addText($row['percent'] . '%', ['color' => percentColor($row['percent'])]);
    }
}

function severityColor(string $severity): string
{
    return match ($severity) {
        'Tinggi' => 'dc3545',
        'Sedang' => 'f59e0b',
        default => '198754',
    };
}

function percentColor(float $percent): string
{
    if ($percent >= 80) {
        return '198754';
    }
    if ($percent >= 50) {
        return 'f59e0b';
    }
    return 'dc3545';
}
