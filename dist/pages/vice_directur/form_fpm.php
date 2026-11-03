<?php
require_once __DIR__ . '/../config/branding_helper.php';
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

$username = $_SESSION["username"];

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/ng_detail_box.php';
require_once __DIR__ . '/../config/fpm_lampiran_helper.php';

date_default_timezone_set('Asia/Jakarta');
$date = date('Y-m-d');

$id_fpm = $_GET['id'] ?? 0;

$qHeader = mysqli_query($koneksi, "SELECT * FROM fpm_header WHERE id_fpm = '$id_fpm'");

$qDetail = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm'");

$fpm     = mysqli_fetch_assoc($qHeader);

if (!$fpm) {
  die('Data FPM tidak ditemukan');
}

function v($key, $data)
{
  return htmlspecialchars($data[$key] ?? '');
}

function formatTanggal($date)
{
  if (!empty($date) && $date != '0000-00-00') {
    return date('d-m-Y', strtotime($date));
  }
  return '';
}

function checkedIfNotEmpty($value)
{
  return (!empty(trim($value))) ? 'checked' : '';
}

// Vice Directure sudah tidak ada di alur FPM mana pun (approval, revisi, maupun
// Verifikasi 3 Bulan) - halaman ini hanya untuk melihat, semua aksi POST ditolak.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  die('Vice Directure tidak lagi menjadi bagian alur FPM - halaman ini hanya untuk melihat.');
}

$qCorrective  = mysqli_query($koneksi, "SELECT * FROM fpm_corrective_action WHERE id_fpm = '$id_fpm'");
$qPreventive  = mysqli_query($koneksi, "SELECT * FROM fpm_preventive_action WHERE id_fpm = '$id_fpm'");
$qReview      = mysqli_query($koneksi, "SELECT * FROM fpm_review_dokumen WHERE id_fpm = '$id_fpm'");
$qPengiriman  = mysqli_query($koneksi, "SELECT * FROM fpm_pengiriman WHERE id_fpm = '$id_fpm'");
$qSimilar     = mysqli_query($koneksi, "SELECT * FROM fpm_similar WHERE id_fpm = '$id_fpm'");
$qEvaluasi    = mysqli_query($koneksi, "SELECT * FROM fpm_evaluasi WHERE id_fpm = '$id_fpm'");

$correctiveData  = mysqli_fetch_all($qCorrective ?? false, MYSQLI_ASSOC) ?: [];
$preventiveData  = mysqli_fetch_all($qPreventive ?? false, MYSQLI_ASSOC) ?: [];
$reviewData      = mysqli_fetch_all($qReview ?? false, MYSQLI_ASSOC) ?: [];
$pengirimanData  = mysqli_fetch_all($qPengiriman ?? false, MYSQLI_ASSOC) ?: [];
$evaluasi        = $qEvaluasi ? (mysqli_fetch_assoc($qEvaluasi) ?: []) : [];

$qAnalisaOcc = mysqli_query($koneksi, "SELECT * FROM fpm_analisa 
    WHERE id_fpm = '$id_fpm' AND jenis = 'OCCURRENCE'
    ORDER BY id ASC");

$qAnalisaFlow = mysqli_query($koneksi, "SELECT * FROM fpm_analisa 
    WHERE id_fpm = '$id_fpm' AND jenis = 'FLOW OUT'
    ORDER BY id ASC");

$analisaOccData  = mysqli_fetch_all($qAnalisaOcc ?? false, MYSQLI_ASSOC) ?: [];
$analisaFlowData = mysqli_fetch_all($qAnalisaFlow ?? false, MYSQLI_ASSOC) ?: [];

$peng = $pengirimanData[0] ?? [];

$similarData = mysqli_fetch_all($qSimilar ?? false, MYSQLI_ASSOC) ?: [];

?>

<!doctype html>
<html lang="id">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>Form FPM</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="title" content="Form FPM" />
  <meta name="author" content="ColorlibHQ" />

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

  <style>
    body {
      font-family: Arial, sans-serif;
      font-size: 10px;
      margin: 20px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    th,
    td {
      border: 1px solid black;
      padding: 4px;
      vertical-align: top;
    }

    .header-title {
      font-size: 18px;
      font-weight: bold;
      text-align: center;
      border-bottom: 1px solid black;
    }

    .logo-box {
      font-weight: bold;
      border-right: none;
    }

    .bg-orange {
      background-color: #ff9900;
      color: white;
      display: inline-block;
      padding: 2px 10px;
    }

    .no-border {
      border: none;
    }

    .center {
      text-align: center;
    }

    .left {
      text-align: left;
    }

    .small-text {
      font-size: 8px;
    }

    .italic-bold {
      font-style: italic;
      font-weight: bold;
    }

    input[type="checkbox"] {
      margin-right: 5px;
    }

    .inner-table td {
      border: 1px solid black;
    }

    .inner-table {
      border-collapse: collapse;
      width: 100%;
      height: 100%;
    }

    .print-toolbar {
      display: inline-flex;
      gap: 6px;
    }

    @media print {
      body * {
        visibility: hidden;
      }

      #printArea,
      #printArea * {
        visibility: visible;
      }

      #printArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
      }

      .no-print {
        display: none !important;
      }
    }
  </style>
</head>

<body>

  <button type="button" onclick="history.back()" class="btn btn-secondary btn-sm no-print">
    ⬅ Back
  </button>

  <span class="print-toolbar no-print">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printTarget()">
      🖨️ Print
    </button>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportTargetAsJPEG('#printArea', 'FPM_<?= $id_fpm ?>')">
      🖼️ JPEG
    </button>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportTargetAsPDF('#printArea', 'FPM_<?= $id_fpm ?>')">
      📄 PDF
    </button>
  </span>


  <br><br>

  <div class="no-print" style="max-width:900px; margin-bottom:16px;">
    <?php $riwayatRevisi = ambilFpmRevisiHistory($koneksi, (int) $id_fpm); ?>
    <?php if ($riwayatRevisi): ?>
      <details open>
        <summary style="cursor:pointer; font-weight:600; font-size:13px; color:#374151;">Riwayat Revisi (<?= count($riwayatRevisi) ?>)</summary>
        <div style="margin-top:8px;"><?= renderFpmRevisiHistory($riwayatRevisi) ?></div>
      </details>
    <?php endif; ?>
  </div>

  <div id="printArea">
  <table>
    <tr>
      <td colspan="4" class="logo-box" style="border-right: none; text-align: center;">
        <img src="<?= htmlspecialchars(brandLogo('dokumen')) ?>" style="max-height: 70px;">
      </td>
      <td colspan="10" class="header-title" style="border-left: none">
        FORM PENANGANAN MASALAH
      </td>
      <td colspan="5" style="padding: 0">
        <table class="inner-table" style="border: none">
          <tr>
            <td colspan="3" style="border-top: none; border-left: none"></td>
            <td
              class="center small-text"
              style="border-top: none; border-right: none; border-left: none">
              FOR/QA/01/08/04
            </td>
          </tr>
          <tr style="height: 30px">
            <td style="border-left: none">DIBUAT</td>
            <td>DIPERIKSA</td>
            <td>DISETUJUI</td>
            <td rowspan="2" style="border-right: none; text-align: center"><?= v('disetujui_directure', $fpm) ?><br><?= formatTanggal($fpm['diterima_pic'] ?? '') ?></td>
          </tr>
          <tr style="height: 30px">
            <td style="border-left: none"><?= v('dibuat_oleh', $fpm) ?></td>
            <td><?= v('user_diperiksa', $fpm) ?></td>
            <td><?= (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH') ? v('cm_deptheadqa_approved_oleh', $fpm) : v('user_disetujui', $fpm) ?></td>
          </tr>
          <tr>
            <td colspan="3" class="small-text" style="border-bottom: none">
              DEPT :
            </td>
            <td style="border-right: none; border-bottom: none"></td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td colspan="3">
        <input type="checkbox" <?= $fpm['jenis_fpm'] == 'CUSTOMER' ? 'checked' : '' ?> /> CUSTOMER <br />
        NAMA : <?= v('customer_nama', $fpm) ?>
      </td>
      <td colspan="4">
        <input type="checkbox" <?= $fpm['jenis_fpm'] == 'SUPPLIER' ? 'checked' : '' ?> /> SUPPLIER <br />
        NAMA : <?= v('supplier_nama', $fpm) ?>
      </td>
      <td colspan="12">
        <input type="checkbox" <?= checkedIfNotEmpty((($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH') ? ($fpm['dept'] ?? '') : ($fpm['internal_dept'] ?? '')) ?> /> INTERNAL <br />
        DEPT : <?= htmlspecialchars((($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH') ? ($fpm['dept'] ?? '') : ($fpm['internal_dept'] ?? '')) ?>
      </td>
    </tr>

    <tr>
      <td colspan="3">NO. PART : <?= v('no_part', $fpm) ?></td>
      <td colspan="4">MODEL : <?= v('model', $fpm) ?></td>
      <td colspan="4">KEJADIAN BERULANG KE : <?= v('kejadian_berulang_ke', $fpm) ?></td>
      <td colspan="5">TGL DITEMUKAN MASALAH :<?= formatTanggal($fpm['tgl_ditemukan'] ?? '') ?></td>
      <td colspan="3">NO.REG. FPM : <?= v('no_reg_fpm', $fpm) ?></td>
    </tr>

    <tr>
      <td colspan="3">NAMA PART : <?= v('nama_part', $fpm) ?></td>
      <td colspan="4">
        MACHINE NO : <?= v('machine_no', $fpm) ?>
        <span style="float: right">MOLD # : <?= v('mold_no', $fpm) ?></span><br />
        <span class="small-text">*Jika problem casting</span>
      </td>
      <td colspan="4">JENIS MASALAH : <?= v('jenis_masalah', $fpm) ?><br />DETAIL : <?= v('jenis_masalah_detail', $fpm) ?></td>
      <td colspan="5">
        TGL PEMBUATAN : <?= formatTanggal($fpm['tgl_pembuatan'] ?? '') ?><br />
        QTY : <?= v('qty', $fpm) ?>
      </td>
      <td colspan="3">
        TGL TERBIT : <?= formatTanggal($fpm['tgl_terbit'] ?? '') ?><br />
        QTY. DEL / QTY. PROD : <?= v('qty_del_qty_prod', $fpm) ?>
      </td>
    </tr>

    <tr>
      <td colspan="3" style="height: 150px">
        <strong>DETAIL MASALAH :</strong><br><br>

        <?php
        $d = mysqli_fetch_assoc($qDetail);
        renderDetailMasalahBox($d);
        ?>
      </td>

      <td colspan="6">
        <strong>VERIFICATION 3 BULAN</strong><br><br>

        Bulan 1:<br>
        <?= htmlspecialchars($d['verification_3_bulan'] ?? '') ?><br><br>

        Bulan 2:<br>
        <?= htmlspecialchars($d['verification_3_bulan2'] ?? '') ?><br><br>

        Bulan 3:<br>
        <?= htmlspecialchars($d['verification_3_bulan3'] ?? '') ?>
      </td>
      <td colspan="6" style="padding: 0">
        <table class="inner-table" style="border: none">
          <tr>
            <td
              colspan="5"
              class="center"
              style="border-top: none; border-left: none">
              REVIEW / UPDATE DOKUMEN
            </td>
            <td
              rowspan="2"
              class="small-text center"
              style="border-top: none; border-right: none">
              SIMILAR<br />PART/MACHINE
            </td>
          </tr>
          <tr class="center small-text">
            <td style="border-left: none">DOKUMEN</td>
            <td>DUE DATE</td>
            <td>ACTUAL</td>
            <td>PIC</td>
            <td>STATUS</td>
          </tr>
          <?php
          $reviewMap = [];
          foreach ($reviewData as $r) {
            $reviewMap[$r['dokumen']] = $r;
          }

          function reviewVal($doc, $field, $map)
          {
            return isset($map[$doc][$field]) ? htmlspecialchars($map[$doc][$field]) : '';
          }
          ?>

          <tr>
            <td style="border-left:none">IK</td>
            <td><?= reviewVal('IK', 'due_date', $reviewMap) ?></td>
            <td><?= reviewVal('IK', 'actual_date', $reviewMap) ?></td>
            <td><?= reviewVal('IK', 'pic', $reviewMap) ?></td>
            <td><?= reviewVal('IK', 'status', $reviewMap) ?></td>
            <td rowspan="5" class="small-text" style="border-right:none;">
              <?php foreach ($similarData as $s): ?>
                <?= htmlspecialchars($s['part_or_machine']) ?><br>
              <?php endforeach; ?>
            </td>
          </tr>

          <tr>
            <td style="border-left:none">FMEA</td>
            <td><?= reviewVal('FMEA', 'due_date', $reviewMap) ?></td>
            <td><?= reviewVal('FMEA', 'actual_date', $reviewMap) ?></td>
            <td><?= reviewVal('FMEA', 'pic', $reviewMap) ?></td>
            <td><?= reviewVal('FMEA', 'status', $reviewMap) ?></td>
          </tr>

          <tr>
            <td style="border-left:none">QCPC</td>
            <td><?= reviewVal('QCPC', 'due_date', $reviewMap) ?></td>
            <td><?= reviewVal('QCPC', 'actual_date', $reviewMap) ?></td>
            <td><?= reviewVal('QCPC', 'pic', $reviewMap) ?></td>
            <td><?= reviewVal('QCPC', 'status', $reviewMap) ?></td>
          </tr>

          <tr>
            <td style="border-left:none">CHECK SHEET</td>
            <td><?= reviewVal('CHECK SHEET', 'due_date', $reviewMap) ?></td>
            <td><?= reviewVal('CHECK SHEET', 'actual_date', $reviewMap) ?></td>
            <td><?= reviewVal('CHECK SHEET', 'pic', $reviewMap) ?></td>
            <td><?= reviewVal('CHECK SHEET', 'status', $reviewMap) ?></td>
          </tr>

          <tr>
            <td style="border-left:none">LAIN-LAIN</td>
            <td><?= reviewVal('LAIN-LAIN', 'due_date', $reviewMap) ?></td>
            <td><?= reviewVal('LAIN-LAIN', 'actual_date', $reviewMap) ?></td>
            <td><?= reviewVal('LAIN-LAIN', 'pic', $reviewMap) ?></td>
            <td><?= reviewVal('LAIN-LAIN', 'status', $reviewMap) ?></td>
          </tr>

        </table>
      </td>
      <td colspan="4" style="padding: 0">
        <table class="inner-table" style="border: none">
          <tr>
            <td
              colspan="2"
              class="small-text"
              style="border-left: none; border-top: none">
              PENGIRIMAN SETELAH PERBAIKAN
            </td>
            <td
              class="small-text"
              style="border-right: none; border-top: none">
              TGL:
            </td>
          </tr>
          <tr class="small-text center">
            <td style="border-left: none">Departemen</td>
            <td style="border-left: none">TGL</td>
            <td style="border-right: none">DIBUAT</td>
            <td style="border-right: none">DIPERIKSA</td>
            <td style="border-right: none">DISETUJUI</td>
          </tr>

          <?php $peng = $pengirimanData[0] ?? []; ?>

          <tr style="height: 30px">
            <td style="border-left: none"><?= v('dept', $fpm) ?></td>
            <td style="border-left: none">
              <?=
              (!empty($peng['tgl']) && $peng['tgl'] != '0000-00-00' && ($fpm['flow_type'] ?? 'IMP') !== 'CLAIMMACH')
                ? date('d-m-Y', strtotime($peng['tgl']))
                : ''
              ?>
            </td>

            <td style="border-left: none"><?= htmlspecialchars($peng['user_dibuat'] ?? '') ?></td>
            <td style="border-right: none">
              <div><?= htmlspecialchars($peng['user_diperiksa'] ?? '') ?></div>
              <div><?= htmlspecialchars($peng['user_diperiksa2'] ?? '') ?></div>
              <?php if (($fpm['flow_type'] ?? 'IMP') !== 'QA'): ?>
                <div><?= htmlspecialchars($peng['user_disetujui1'] ?? '') ?></div>
              <?php endif; ?>
            </td>
            <td style="border-right: none"><?= htmlspecialchars($peng['user_disetujui'] ?? '') ?></td>
          </tr>

          <tr>
            <td class="small-text" style="border-left: none">
              STATUS EVALUASI
            </td>
            <td class="small-text" style="border-right: none">TGL:</td>
            <td class="small-text" style="border-right: none">DIBUAT</td>
            <td class="small-text" style="border-right: none">DIPERIKSA</td>
            <td class="small-text" style="border-right: none">DISETUJUI</td>
          </tr>

          <tr>
            <td class="small-text" style="border-bottom: none; border-left: none">
              <input type="checkbox"
                <?= $fpm['status_evaluasi'] == 'OPEN' ? 'checked' : '' ?> />
              OPEN
              <br>

              <input type="checkbox"
                <?= $fpm['status_evaluasi'] == 'CLOSED' ? 'checked' : '' ?> />
              CLOSED
            </td>

            <td style="border-bottom: none">
              <?= !empty($evaluasi['tgl'])
                ? date('d-m-Y', strtotime($evaluasi['tgl']))
                : '' ?>
            </td>

            <td style="border-bottom: none">
              <?= htmlspecialchars($evaluasi['user_dibuat'] ?? '') ?>
            </td>

            <td style="border-bottom: none">
              <?= htmlspecialchars($evaluasi['user_diperiksa'] ?? '') ?><br>
              <?= htmlspecialchars($evaluasi['user_diperiksa2'] ?? '') ?>
            </td>

            <td style="border-bottom: none">
              <?= htmlspecialchars($evaluasi['user_disetujui'] ?? '') ?>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <tr style="background-color: #f2f2f2">
      <td colspan="19"><strong>ANALISA MASALAH :</strong></td>
    </tr>

    <tr class="center small-text" style="font-weight: bold">
      <td>POINT</td>
      <td>ANALISA</td>
      <td>WHY-1</td>
      <td>WHY-2</td>
      <td>WHY-3</td>
      <td>WHY-4</td>
      <td>WHY-5</td>
      <td>POINT</td>
      <td colspan="8">TINDAKAN PERBAIKAN / CORRECTIVE ACTION</td>
      <td>DUE DATE</td>
      <td>PIC</td>
      <td>STATUS</td>
    </tr>

    <tr>
      <td colspan="7" class="left italic-bold">OCCURANCE</td>
      <td rowspan="7" colspan="1"></td>
      <td colspan="8" rowspan="5" style="vertical-align: top;">
        <?php if (!empty($correctiveData)): ?>
          <?php foreach ($correctiveData as $i => $c): ?>
            <?= ($i + 1) ?>. <?= htmlspecialchars($c['tindakan']) ?><br>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>

      <td rowspan="5" style="vertical-align: top;">
        <?php if (!empty($correctiveData)): ?>
          <?php foreach ($correctiveData as $c): ?>
            <?= !empty($c['due_date']) && $c['due_date'] != '0000-00-00'
              ? date('d-m-Y', strtotime($c['due_date']))
              : ''
            ?><br>

          <?php endforeach; ?>
        <?php endif; ?>
      </td>

      <td rowspan="5" style="vertical-align: top;">
        <?php if (!empty($correctiveData)): ?>
          <?php foreach ($correctiveData as $c): ?>
            <?= htmlspecialchars($c['pic']) ?><br>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>

      <td rowspan="5" style="vertical-align: top;">
        <?php if (!empty($correctiveData)): ?>
          <?php foreach ($correctiveData as $c): ?>
            <?= htmlspecialchars($c['status']) ?><br>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>

    </tr>

    <?php
    $maxRow = 4;
    $no = 1;

    $totalData = count($analisaOccData);

    for ($i = 0; $i < $maxRow; $i++):
      $a = $analisaOccData[$i] ?? null;
    ?>
      <tr style="height: 40px">
        <td class="center"><?= $no++ ?></td>
        <td class="center"><?= $a ? htmlspecialchars($a['kategori']) : '' ?></td>
        <td><?= $a ? htmlspecialchars($a['why_1']) : '' ?></td>
        <td><?= $a ? htmlspecialchars($a['why_2']) : '' ?></td>
        <td><?= $a ? htmlspecialchars($a['why_3']) : '' ?></td>
        <td><?= $a ? htmlspecialchars($a['why_4']) : '' ?></td>
        <td><?= $a ? htmlspecialchars($a['why_5']) : '' ?></td>
      </tr>
    <?php endfor; ?>

    <tr>
      <td colspan="7" class="left italic-bold">FLOWOUT</td>

      <td colspan="8" class="center" style="border-top: none">
        <strong>TINDAKAN PENCEGAHAN / PREVENTIVE ACTION</strong>
      </td>
      <td>DUE DATE</td>
      <td>PIC</td>
      <td>STATUS</td>
    </tr>

    <tr style="height: 50px">
      <td class="center">5</td>
      <?php
      $flowout = $analisaFlowData[0] ?? null;
      ?>
      <td class="center"><?= $flowout ? htmlspecialchars($flowout['kategori']) : '' ?></td>
      <td><?= $flowout ? htmlspecialchars($flowout['why_1']) : '' ?></td>
      <td><?= $flowout ? htmlspecialchars($flowout['why_2']) : '' ?></td>
      <td><?= $flowout ? htmlspecialchars($flowout['why_3']) : '' ?></td>
      <td><?= $flowout ? htmlspecialchars($flowout['why_4']) : '' ?></td>
      <td><?= $flowout ? htmlspecialchars($flowout['why_5']) : '' ?></td>

      <td colspan="8"><?php foreach ($preventiveData as $i => $p): ?>
          <?= ($i + 1) ?>. <?= htmlspecialchars($p['tindakan']) ?><br>
        <?php endforeach; ?></td>
      <td><?php foreach ($preventiveData as $p): ?>
          <?= !empty($p['due_date']) && $p['due_date'] != '0000-00-00'
            ? date('d-m-Y', strtotime($p['due_date']))
            : ''
          ?><br>
        <?php endforeach; ?></td>
      <td><?php foreach ($preventiveData as $p): ?>
          <?= htmlspecialchars($p['pic']) ?><br>
        <?php endforeach; ?></td>
      <td><?php foreach ($preventiveData as $p): ?>
          <?= htmlspecialchars($p['status']) ?><br>
        <?php endforeach; ?></td>
    </tr>
  </table>

  <p class="small-text">
    # ) FORM INI HARUS DI TINDAKLANJUTI OLEH PENANGGUNG JAWAB DAN PENGEMBALIAN
    JAWABAN FORM INI PALING LAMBAT 7 HARI KERJA SETELAH DITERBITKAN<br />
    APABILA ADA KEKURANGAN LEMBAR JAWABAN BISA DILAMPIRKAN
  </p>
  </div>
  <br>

  <?php $lampiranFpmView = ambilLampiranFpm($koneksi, (int) $id_fpm); ?>
  <?php if (!empty($lampiranFpmView)): ?>
    <div class="no-print" style="max-width:900px; margin:0 auto 16px;">
      <strong>Lampiran Pendukung FPM</strong>
      <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:8px;">
        <?php foreach ($lampiranFpmView as $lp): ?>
          <?php
          $filePathLp = "../uploads/fpm_dt/" . $lp['file'];
          $extLp = strtolower(pathinfo($lp['file'], PATHINFO_EXTENSION));
          ?>
          <div style="border:1px solid #e5e7eb; border-radius:6px; padding:8px; text-align:center; font-size:11px; width:110px;">
            <?php if (in_array($extLp, ['jpg', 'jpeg', 'png'])): ?>
              <a href="<?= $filePathLp ?>" target="_blank">
                <img src="<?= $filePathLp ?>" style="width:80px; height:auto; border:1px solid #ddd; border-radius:4px;">
              </a>
            <?php elseif ($extLp == 'pdf'): ?>
              <a href="<?= $filePathLp ?>" target="_blank">
                <iframe src="<?= $filePathLp ?>" style="width:90px; height:110px; border:1px solid #ddd; border-radius:4px;"></iframe>
              </a>
            <?php else: ?>
              <a href="<?= $filePathLp ?>" target="_blank" style="display:block; word-break:break-all;">
                📎 <?= htmlspecialchars($lp['original_name']) ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <script src="../../../dist/js/print-export.js?v=<?= @filemtime(__DIR__ . '/../../js/print-export.js') ?>"></script>


</body>

</html>