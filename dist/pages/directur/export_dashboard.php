<?php
require_once __DIR__ . '/../config/auth_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';
mulaiSessionAman();
wajibLogin();
include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$filterBulan = inputBulan('bulan');
$filterPlant = inputPlant($_GET['plant'] ?? '');

$bulanIndo = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maret',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Desember'
];

if (!empty($filterBulan) && strpos($filterBulan, '-') !== false) {
    $exp = explode('-', $filterBulan);
    $tahun = $exp[0] ?? date('Y');
    $bulan = $exp[1] ?? date('m');
} else {
    $tahun = date('Y');
    $bulan = date('m');
}

$namaPeriode = ($bulanIndo[$bulan] ?? '-') . ' ' . $tahun;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../config/dashboard_data_fetcher.php';
require_once __DIR__ . '/../config/dashboard_excel_builder.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$data = fetchDashboardData($koneksi, $filterBulan, $filterPlant);
$data['title'] = brandNama() . ' Dashboard';
$data['periode'] = $namaPeriode;
$data['plant'] = $filterPlant;

$spreadsheet = buildDashboardExcel($data);
$writer = new Xlsx($spreadsheet);

$filename = 'Dashboard_' . str_replace(' ', '_', $namaPeriode) . ($filterPlant ? '_' . str_replace(' ', '_', $filterPlant) : '') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
