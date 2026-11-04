<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();
include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$filterBulan = inputBulan('bulan');

$userPlant = $_SESSION['plant'] ?? '';
$userPlant = trim(str_ireplace('Plant', '', $userPlant));
$filterPlant = inputPlant($_GET['plant'] ?? $userPlant);

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
require_once __DIR__ . '/../config/report_engine.php';
require_once __DIR__ . '/../config/report_word_builder.php';

use PhpOffice\PhpWord\IOFactory;

$plantLabel = !empty($filterPlant) ? 'Plant ' . $filterPlant : '';

$data = fetchDashboardData($koneksi, $filterBulan, $filterPlant);
$insights = generateReportInsights($data, $namaPeriode, $plantLabel);

$phpWord = buildReportWord($insights);
$writer = IOFactory::createWriter($phpWord, 'Word2007');

$filename = 'Laporan_Insight_' . str_replace(' ', '_', $namaPeriode) . ($plantLabel ? '_' . str_replace(' ', '_', $plantLabel) : '') . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
