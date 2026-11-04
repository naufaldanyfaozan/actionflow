<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../config/asakai_data_fetcher.php';
require_once __DIR__ . '/../config/asakai_excel_builder.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

[$mainRows, $deliveryRows, $castingRows, $tanggal] = fetchAsakaiExportData($koneksi, $_SESSION['username']);

$spreadsheet = buildAsakaiExcel($mainRows, $deliveryRows, $castingRows);
$writer = new Xlsx($spreadsheet);

$filename = 'Summary_Asakai_' . str_replace('-', '', $tanggal) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
