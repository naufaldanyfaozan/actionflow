<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Builds the "Summary Asakai" workbook: one sheet mirroring the main
 * asakai table (list_asakai.php), one for Delivery & Sisa Stock Part,
 * one for Stock Casting - Machining.
 *
 * @param array $mainRows     Raw rows from list_asakai.php's main $query, fetched with mysqli_fetch_assoc.
 * @param array $deliveryRows Raw rows from $query_delivery.
 * @param array $castingRows  Raw rows from $query_casting.
 */
function buildAsakaiExcel(array $mainRows, array $deliveryRows, array $castingRows): Spreadsheet
{
    $spreadsheet = new Spreadsheet();

    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Summary Asakai');
    writeAsakaiMainTable($sheet1, $mainRows);

    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Delivery & Stock Part');
    writeAsakaiDeliveryTable($sheet2, $deliveryRows);

    $sheet3 = $spreadsheet->createSheet();
    $sheet3->setTitle('Stock Casting');
    writeAsakaiCastingTable($sheet3, $castingRows);

    $spreadsheet->setActiveSheetIndex(0);

    return $spreadsheet;
}

function styleAsakaiHeaderRow($sheet, string $range, string $fillRgb, string $fontRgb): void
{
    $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB($fontRgb);
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fillRgb);
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// Mirrors the grouping done in list_asakai.php's HTML template: rows are
// grouped by tanggal+machine (outer merge), then by problem+analisa (inner
// merge), so the resulting sheet has the same merged-cell shape as the page.
function writeAsakaiMainTable($sheet, array $rows): void
{
    $headers = [
        'ASAKAI DATE', 'PLANT', 'MESIN', 'NAMA PART', 'TYPE', 'PART NUMBER',
        'ACHIEVEMENT', 'CYCLE TIME & EFFICIENCY', 'PROBLEM', 'ANALISIS',
        'COUNTERMEASURE', 'PIC', 'DUE DATE', 'LAMPIRAN', 'STATUS',
    ];
    $lastCol = 'O';

    $col = 'A';
    foreach ($headers as $label) {
        $sheet->setCellValue("{$col}1", $label);
        $col++;
    }
    styleAsakaiHeaderRow($sheet, "A1:{$lastCol}1", '2C6ED7', 'FFFFFF');

    if (empty($rows)) {
        $sheet->setCellValue('A2', 'Data tidak ditemukan');
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle("A2:{$lastCol}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        applyAsakaiColumnWidths($sheet, $lastCol);
        return;
    }

    // id_asakai disertakan di key supaya 2 submission berbeda untuk
    // mesin+tanggal yang sama tidak tergabung jadi satu blok gabungan.
    $grouped = [];
    foreach ($rows as $row) {
        $key = $row['tanggal'] . '|' . $row['machine'] . '|' . $row['id_asakai'];
        $grouped[$key][] = $row;
    }

    // Countermeasure rows are joined against every analisa row of the same
    // id_asakai+kategori (see list_asakai.php), so a single countermeasure
    // can appear in more than one $problemRows group here. Track which
    // countermeasure ids were already written so repeats render blank
    // instead of duplicating the same countermeasure text.
    $shownCm = [];

    $r = 2;
    foreach ($grouped as $machineRows) {
        $machineStartRow = $r;

        $groupProblem = [];
        foreach ($machineRows as $mr) {
            $key = $mr['problem'] . '|' . $mr['analisa'];
            $groupProblem[$key][] = $mr;
        }

        foreach ($groupProblem as $problemRows) {
            $problemStartRow = $r;

            foreach ($problemRows as $pr) {
                if ($pr['kategori'] === 'DOWN_TIME') {
                    $label = 'DOWN TIME';
                    $cm = $pr['cm_downtime'];
                    $pic = $pr['pic_downtime'];
                    $rawDue = $pr['due_downtime'];
                    $status = $pr['status_downtime'];
                    $files = [$pr['file1_downtime'], $pr['file2_downtime'], $pr['file3_downtime']];
                    $cmId = $pr['id_cm_dt'] ?? null;
                } else {
                    $label = 'NG';
                    $cm = $pr['cm_ng'];
                    $pic = $pr['pic_ng'];
                    $rawDue = $pr['due_ng'];
                    $status = $pr['status_ng'];
                    $files = [$pr['file1_ng'], $pr['file2_ng'], $pr['file3_ng']];
                    $cmId = $pr['id_cm_ng'] ?? null;
                }

                if ($cmId !== null) {
                    $cmKey = $pr['kategori'] . '#' . $pr['id_asakai'] . '#' . $cmId;
                    if (isset($shownCm[$cmKey])) {
                        $cm = null;
                        $pic = null;
                        $rawDue = null;
                        $status = '';
                        $files = [null, null, null];
                    } else {
                        $shownCm[$cmKey] = true;
                    }
                }

                $due = (!empty($rawDue) && $rawDue !== '0000-00-00') ? date('d-m-Y', strtotime($rawDue)) : '';

                $hasFile = false;
                foreach ($files as $f) {
                    if (!empty($f)) {
                        $hasFile = true;
                        break;
                    }
                }

                $sheet->setCellValue("I{$r}", $label . "\n" . $pr['problem']);
                $sheet->setCellValue("J{$r}", str_replace('<br>', "\n", (string) $pr['analisa']));
                $sheet->setCellValue("K{$r}", $cm);
                $sheet->setCellValue("L{$r}", $pic);
                $sheet->setCellValue("M{$r}", $due);
                $sheet->setCellValue("N{$r}", $hasFile ? 'Ada' : '-');
                $sheet->setCellValue("O{$r}", $status);

                $r++;
            }

            $problemEndRow = $r - 1;
            if ($problemEndRow > $problemStartRow) {
                $sheet->mergeCells("I{$problemStartRow}:I{$problemEndRow}");
                $sheet->mergeCells("J{$problemStartRow}:J{$problemEndRow}");
            }
        }

        $machineEndRow = $r - 1;
        $first = $machineRows[0];

        $sheet->setCellValue("A{$machineStartRow}", date('d-m-Y', strtotime($first['tanggal'])));
        $sheet->setCellValue("B{$machineStartRow}", $first['plant']);
        $sheet->setCellValue("C{$machineStartRow}", $first['machine']);
        $sheet->setCellValue("D{$machineStartRow}", $first['namapart']);
        $sheet->setCellValue("E{$machineStartRow}", $first['type']);
        $sheet->setCellValue("F{$machineStartRow}", $first['partnumber']);
        $sheet->setCellValue("G{$machineStartRow}", "T : {$first['target']}\nOK : {$first['ok_qty']}\nNG : {$first['ng_qty']}\nDT : {$first['downtime']}");
        $sheet->setCellValue("H{$machineStartRow}", "Std : {$first['standard']}\nAct : {$first['actual']}\nEff : {$first['efficiency']} %\nNG : {$first['persen_ng']} %");

        if ($machineEndRow > $machineStartRow) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $c) {
                $sheet->mergeCells("{$c}{$machineStartRow}:{$c}{$machineEndRow}");
            }
        }
    }

    $lastRow = $r - 1;
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->freezePane('A2');

    applyAsakaiColumnWidths($sheet, $lastCol);
}

function applyAsakaiColumnWidths($sheet, string $lastCol): void
{
    foreach (range('A', $lastCol) as $c) {
        $sheet->getColumnDimension($c)->setWidth(20);
    }
    foreach (['I', 'J', 'K'] as $c) {
        $sheet->getColumnDimension($c)->setWidth(32);
    }
}

function writeAsakaiDeliveryTable($sheet, array $rows): void
{
    $headers = ['No.', 'Nama Part', 'Type', 'Part Number', 'Stock FG', 'Delivery / hari', 'Kemampuan Delivery', 'News Delivery', 'Countermeasure', 'Due Date', 'PIC'];
    $lastCol = 'K';

    $col = 'A';
    foreach ($headers as $label) {
        $sheet->setCellValue("{$col}1", $label);
        $col++;
    }
    styleAsakaiHeaderRow($sheet, "A1:{$lastCol}1", 'FCE4D6', '000000');

    if (empty($rows)) {
        $sheet->setCellValue('A2', 'Data stock part tidak ditemukan untuk tanggal/plant terpilih.');
        $sheet->mergeCells("A2:{$lastCol}2");
        foreach (range('A', $lastCol) as $c) {
            $sheet->getColumnDimension($c)->setWidth(20);
        }
        return;
    }

    $r = 2;
    $no = 1;
    foreach ($rows as $row) {
        $actStock = (float) $row['act_stock'];
        $deliveryPerHari = (float) $row['delivery_per_hari'];
        $due = (!empty($row['due_date']) && $row['due_date'] !== '0000-00-00') ? date('d-m-Y', strtotime($row['due_date'])) : '';

        $sheet->setCellValue("A{$r}", $no++);
        $sheet->setCellValue("B{$r}", $row['nama_part']);
        $sheet->setCellValue("C{$r}", $row['part_type']);
        $sheet->setCellValue("D{$r}", $row['part_number']);
        $sheet->setCellValue("E{$r}", $actStock);
        $sheet->setCellValue("F{$r}", $deliveryPerHari);
        $sheet->setCellValue("G{$r}", (float) $row['sisa_stock']);
        $sheet->setCellValue("H{$r}", $row['news_delivery']);
        $sheet->setCellValue("I{$r}", $row['countermeasure']);
        $sheet->setCellValue("J{$r}", $due);
        $sheet->setCellValue("K{$r}", $row['pic']);

        if ($actStock < $deliveryPerHari) {
            $sheet->getStyle("E{$r}")->getFont()->setBold(true)->getColor()->setRGB('DC3545');
            $sheet->getStyle("E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
        }
        if ((float) $row['sisa_stock'] < 0) {
            $sheet->getStyle("G{$r}")->getFont()->setBold(true)->getColor()->setRGB('DC3545');
            $sheet->getStyle("G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
        }

        $r++;
    }

    $lastRow = $r - 1;
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->freezePane('A2');

    foreach (range('A', $lastCol) as $c) {
        $sheet->getColumnDimension($c)->setWidth(20);
    }
    $sheet->getColumnDimension('I')->setWidth(30);
}

function writeAsakaiCastingTable($sheet, array $rows): void
{
    $headers = ['No.', 'Part ( Stock Casting )', 'Type', 'Part Number', 'DCM', 'Min Stock (3x Deliv)', 'Stock PPC'];
    $lastCol = 'G';

    $col = 'A';
    foreach ($headers as $label) {
        $sheet->setCellValue("{$col}1", $label);
        $col++;
    }
    styleAsakaiHeaderRow($sheet, "A1:{$lastCol}1", 'FCE4D6', '000000');

    if (empty($rows)) {
        $sheet->setCellValue('A2', 'Data stock casting tidak ditemukan untuk tanggal terpilih.');
        $sheet->mergeCells("A2:{$lastCol}2");
        foreach (range('A', $lastCol) as $c) {
            $sheet->getColumnDimension($c)->setWidth(20);
        }
        return;
    }

    $r = 2;
    $no = 1;
    foreach ($rows as $row) {
        $ppc = (int) $row['stock_ppc'];

        $sheet->setCellValue("A{$r}", $no++);
        $sheet->setCellValue("B{$r}", $row['nama_part']);
        $sheet->setCellValue("C{$r}", $row['part_type']);
        $sheet->setCellValue("D{$r}", $row['part_number']);
        $sheet->setCellValue("E{$r}", (float) $row['dcm']);
        $sheet->setCellValue("F{$r}", (float) $row['min_stock']);
        $sheet->setCellValue("G{$r}", $ppc);

        if ($ppc < 0) {
            $sheet->getStyle("G{$r}")->getFont()->setBold(true)->getColor()->setRGB('FF0000');
        }

        $r++;
    }

    $lastRow = $r - 1;
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->freezePane('A2');

    foreach (range('A', $lastCol) as $c) {
        $sheet->getColumnDimension($c)->setWidth(20);
    }
}
