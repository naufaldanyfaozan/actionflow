<?php
require_once __DIR__ . '/branding_helper.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Builds one XLSX sheet mirroring the ACTIONFLOW dashboard page top-to-bottom:
 * header info, KPI summary, per-department tables, overdue countermeasures,
 * top-problem lists, and the Delivery & Sisa Stock Part table.
 *
 * @param array $data see dist/pages/{role}/export_dashboard.php for the shape.
 */
function buildDashboardExcel(array $data): Spreadsheet
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Dashboard');

    $row = 1;

    $sheet->setCellValue("A{$row}", $data['title'] ?? brandNama() . ' Dashboard');
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
    $row++;

    $sheet->setCellValue("A{$row}", 'Corrective Action, Monitoring & Improvement System');
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('6c757d');
    $row++;

    $sheet->setCellValue("A{$row}", 'Periode: ' . ($data['periode'] ?? '-') . '   |   Plant: ' . (!empty($data['plant']) ? $data['plant'] : 'Semua Plant'));
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);
    $row += 2;

    // ================= KPI SUMMARY =================
    $row = writeSectionTitle($sheet, $row, 'Ringkasan KPI', '2563eb');
    $kpiHeader = ['Total FPM Down Time', 'Total FPM Closed', 'Total FPM Open', 'Total Asakai'];
    $kpiValues = [
        $data['kpi']['down_time'] ?? 0,
        $data['kpi']['closed'] ?? 0,
        $data['kpi']['open'] ?? 0,
        $data['kpi']['asakai'] ?? 0,
    ];
    $col = 'A';
    foreach ($kpiHeader as $i => $label) {
        $sheet->setCellValue("{$col}{$row}", $label);
        $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("{$col}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('eef2ff');
        $col++;
    }
    $row++;
    $col = 'A';
    foreach ($kpiValues as $value) {
        $sheet->setCellValue("{$col}{$row}", $value);
        $sheet->getStyle("{$col}{$row}")->getFont()->setSize(14)->setBold(true);
        $col++;
    }
    $row += 2;

    // ================= % COUNTERMEASURE FPM PER DEPT =================
    $row = writeSectionTitle($sheet, $row, '% Countermeasure FPM per Departemen', '2563eb');
    $row = writeDeptTable($sheet, $row, $data['fpm_action_dept'] ?? []);

    // ================= % COUNTERMEASURE ASAKAI PER DEPT =================
    $row = writeSectionTitle($sheet, $row, '% Countermeasure Asakai per Departemen', '198754');
    $row = writeDeptTable($sheet, $row, $data['asakai_dept'] ?? []);

    // ================= FPM OVERDUE =================
    $row = writeSectionTitle($sheet, $row, 'Countermeasure FPM Over Due Date', 'dc3545');
    $row = writeOverdueTable($sheet, $row, $data['fpm_overdue'] ?? []);

    // ================= ASAKAI OVERDUE =================
    $row = writeSectionTitle($sheet, $row, 'Countermeasure Asakai Over Due Date', 'dc3545');
    $row = writeOverdueTable($sheet, $row, $data['asakai_overdue'] ?? []);

    // ================= TOP PROBLEM LISTS =================
    $row = writeSectionTitle($sheet, $row, 'Jenis Masalah Terbanyak', 'dc3545');
    $row = writeTopList($sheet, $row, 'Jenis Masalah', $data['jenis_masalah']['label'] ?? [], $data['jenis_masalah']['total'] ?? []);

    $row = writeSectionTitle($sheet, $row, 'Part Dengan Masalah Terbanyak', 'f59e0b');
    $row = writeTopList($sheet, $row, 'Part - Masalah', $data['part_masalah']['label'] ?? [], $data['part_masalah']['total'] ?? []);

    $row = writeSectionTitle($sheet, $row, 'Mesin Dengan Masalah Terbanyak', '0dcaf0');
    $row = writeTopList($sheet, $row, 'Mesin - Masalah', $data['machine_masalah']['label'] ?? [], $data['machine_masalah']['total'] ?? []);

    // ================= DELIVERY & SISA STOCK PART =================
    $row = writeSectionTitle($sheet, $row, 'Delivery & Sisa Stock Part', 'f9dcc8', '000000');
    $row = writeDeliveryTable($sheet, $row, $data['delivery'] ?? []);

    foreach (range('A', 'I') as $colLetter) {
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    return $spreadsheet;
}

function writeSectionTitle($sheet, int $row, string $title, string $rgb, string $fontRgb = 'FFFFFF'): int
{
    $sheet->setCellValue("A{$row}", $title);
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($fontRgb);
    $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    return $row + 1;
}

function writeTableHeader($sheet, int $row, array $headers): int
{
    $col = 'A';
    foreach ($headers as $label) {
        $sheet->setCellValue("{$col}{$row}", $label);
        $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("{$col}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('e9f7ef');
        $sheet->getStyle("{$col}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $col++;
    }
    return $row + 1;
}

function writeDeptTable($sheet, int $row, array $rows): int
{
    $row = writeTableHeader($sheet, $row, ['Department', 'Total', 'Open', 'Closed', '% Closed']);

    if (empty($rows)) {
        $sheet->setCellValue("A{$row}", 'Tidak ada data pada periode ini');
        $sheet->mergeCells("A{$row}:E{$row}");
        return $row + 2;
    }

    foreach ($rows as $d) {
        $total = $d['open'] + $d['closed'];
        $sheet->setCellValue("A{$row}", $d['dept']);
        $sheet->setCellValue("B{$row}", $total);
        $sheet->setCellValue("C{$row}", $d['open']);
        $sheet->setCellValue("D{$row}", $d['closed']);
        $sheet->setCellValue("E{$row}", $d['percent'] . '%');
        $sheet->getStyle("A{$row}:E{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        if ($d['percent'] >= 80) {
            $sheet->getStyle("E{$row}")->getFont()->getColor()->setRGB('198754');
        } elseif ($d['percent'] >= 50) {
            $sheet->getStyle("E{$row}")->getFont()->getColor()->setRGB('f59e0b');
        } else {
            $sheet->getStyle("E{$row}")->getFont()->getColor()->setRGB('dc3545');
        }
        $sheet->getStyle("E{$row}")->getFont()->setBold(true);
        $row++;
    }

    return $row + 1;
}

function writeOverdueTable($sheet, int $row, array $rows): int
{
    $row = writeTableHeader($sheet, $row, ['PIC', 'Mesin', 'Part', 'Tindakan / Dokumen', 'Due Date', 'Status']);

    if (empty($rows)) {
        $sheet->setCellValue("A{$row}", 'Tidak ada countermeasure over due date');
        $sheet->mergeCells("A{$row}:F{$row}");
        return $row + 2;
    }

    foreach ($rows as $r) {
        $sheet->setCellValue("A{$row}", $r['pic']);
        $sheet->setCellValue("B{$row}", $r['machine_no'] ?? $r['machine'] ?? '');
        $sheet->setCellValue("C{$row}", $r['nama_part'] ?? $r['namapart'] ?? '');
        $sheet->setCellValue("D{$row}", $r['deskripsi']);
        $sheet->setCellValue("E{$row}", $r['due_date']);
        $sheet->setCellValue("F{$row}", $r['status']);
        $sheet->getStyle("A{$row}:F{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("E{$row}")->getFont()->setBold(true)->getColor()->setRGB('dc3545');
        $sheet->getStyle("F{$row}")->getFont()->getColor()->setRGB('dc3545');
        $row++;
    }

    return $row + 1;
}

function writeTopList($sheet, int $row, string $labelHeader, array $labels, array $totals): int
{
    $row = writeTableHeader($sheet, $row, [$labelHeader, 'Jumlah']);

    if (empty($labels)) {
        $sheet->setCellValue("A{$row}", 'Tidak ada data pada periode ini');
        $sheet->mergeCells("A{$row}:B{$row}");
        return $row + 2;
    }

    foreach ($labels as $i => $label) {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $totals[$i] ?? 0);
        $sheet->getStyle("A{$row}:B{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }

    return $row + 1;
}

function writeDeliveryTable($sheet, int $row, array $rows): int
{
    $headers = ['No.', 'Nama Part', 'Act Stock', 'Delivery / hari', 'Kemampuan Delivery', 'News Delivery', 'Countermeasure', 'Due Date', 'PIC'];
    $col = 'A';
    foreach ($headers as $label) {
        $sheet->setCellValue("{$col}{$row}", $label);
        $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("{$col}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('f9dcc8');
        $sheet->getStyle("{$col}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $col++;
    }
    $row++;

    if (empty($rows)) {
        $sheet->setCellValue("A{$row}", 'Data stock part tidak ditemukan untuk tanggal/plant terpilih.');
        $sheet->mergeCells("A{$row}:I{$row}");
        return $row + 1;
    }

    $no = 1;
    foreach ($rows as $r) {
        $actStock = (float) $r['act_stock'];
        $deliveryPerHari = (float) $r['delivery_per_hari'];

        $sheet->setCellValue("A{$row}", $no++);
        $sheet->setCellValue("B{$row}", $r['nama_part']);
        $sheet->setCellValue("C{$row}", $actStock);
        $sheet->setCellValue("D{$row}", $deliveryPerHari);
        $sheet->setCellValue("E{$row}", $r['sisa_stock']);
        $sheet->setCellValue("F{$row}", $r['news_delivery']);
        $sheet->setCellValue("G{$row}", $r['countermeasure']);
        $sheet->setCellValue("H{$row}", $r['due_date']);
        $sheet->setCellValue("I{$row}", $r['pic']);

        $sheet->getStyle("A{$row}:I{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        if ($actStock <= $deliveryPerHari) {
            $sheet->getStyle("C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('f8d7da');
            $sheet->getStyle("C{$row}")->getFont()->setBold(true)->getColor()->setRGB('dc3545');
        }

        $sheet->getStyle("E{$row}")->getFont()->setBold(true);

        $row++;
    }

    return $row + 1;
}
