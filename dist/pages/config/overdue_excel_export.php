<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export tabel "Countermeasure FPM/Asakai Over Due Date" di dashboard ke XLSX.
 * Dipanggil dari {role}/index.php setelah $fpmOverdue/$asakaiOverdue dihitung,
 * supaya isi file sama persis dengan tabel yang tampil di web.
 *
 * @param string $jenis 'fpm' atau 'asakai'
 */
function exportOverdueCountermeasureExcel(string $jenis, array $fpmOverdue, array $asakaiOverdue, string $plant = ''): void
{
    require_once __DIR__ . '/../../../vendor/autoload.php';

    if ($jenis === 'asakai') {
        $judul = 'Countermeasure Asakai Over Due Date';
        $kolomTindakan = 'Tindakan';
        $rows = $asakaiOverdue;
        $keyMesin = 'machine';
        $keyPart = 'namapart';
        $prefixFile = 'Countermeasure_Asakai_Over_Due';
    } else {
        $judul = 'Countermeasure FPM Over Due Date';
        $kolomTindakan = 'Tindakan / Dokumen';
        $rows = $fpmOverdue;
        $keyMesin = 'machine_no';
        $keyPart = 'nama_part';
        $prefixFile = 'Countermeasure_FPM_Over_Due';
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($jenis === 'asakai' ? 'Asakai Over Due' : 'FPM Over Due');
    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

    // Judul: bar merah seperti card-header bg-danger di web
    $sheet->setCellValue('A1', $judul);
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC3545');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $info = 'Plant: ' . ($plant !== '' ? 'Plant ' . $plant : 'Semua Plant')
        . '   |   Diexport: ' . date('d-m-Y H:i')
        . '   |   Total: ' . count($rows) . ' item';
    $sheet->setCellValue('A2', $info);
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('6C757D');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $headerRow = 4;
    $headers = ['PIC', 'Mesin', 'Part', $kolomTindakan, 'Due Date', 'Status'];
    $col = 'A';
    foreach ($headers as $label) {
        $sheet->setCellValue("{$col}{$headerRow}", $label);
        $col++;
    }
    $headerStyle = $sheet->getStyle("A{$headerRow}:F{$headerRow}");
    $headerStyle->getFont()->setBold(true);
    $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8F9FA');
    $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getRowDimension($headerRow)->setRowHeight(22);

    $row = $headerRow + 1;

    if (empty($rows)) {
        $sheet->setCellValue("A{$row}", 'Tidak ada countermeasure over due date');
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    } else {
        foreach ($rows as $r) {
            $sheet->setCellValueExplicit("A{$row}", (string) ($r['pic'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", (string) ($r[$keyMesin] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", (string) ($r[$keyPart] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", (string) ($r['deskripsi'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // Due date ditulis sebagai teks agar format sama dengan web (YYYY-MM-DD, termasuk 0000-00-00)
            $sheet->setCellValueExplicit("E{$row}", (string) ($r['due_date'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$row}", (string) ($r['status'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $sheet->getStyle("E{$row}")->getFont()->setBold(true)->getColor()->setRGB('DC3545');

            // Status tampil sebagai badge merah di web
            if (trim((string) ($r['status'] ?? '')) !== '') {
                $sheet->getStyle("F{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle("F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC3545');
            }
            $row++;
        }
    }

    $lastRow = $row - 1;
    $tableRange = "A{$headerRow}:F{$lastRow}";
    $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');
    $sheet->getStyle("A" . ($headerRow + 1) . ":F{$lastRow}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);

    $widths = ['A' => 14, 'B' => 12, 'C' => 38, 'D' => 45, 'E' => 14, 'F' => 11];
    foreach ($widths as $c => $w) {
        $sheet->getColumnDimension($c)->setWidth($w);
    }

    if (!empty($rows)) {
        $sheet->setAutoFilter("A{$headerRow}:F{$lastRow}");
    }
    $sheet->freezePane('A' . ($headerRow + 1));

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

    $filename = $prefixFile . ($plant !== '' ? '_Plant_' . $plant : '') . '_' . date('Ymd') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}
