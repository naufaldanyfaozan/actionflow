<?php
require_once __DIR__ . '/../config/branding_helper.php';
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

$username = $_SESSION["username"];

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/ng_detail_box.php';
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';
require_once __DIR__ . '/../config/fpm_lampiran_helper.php';

date_default_timezone_set('Asia/Jakarta');
$date = date('Y-m-d');

$id_fpm = $_GET['id'] ?? 0;

$qHeader = mysqli_query($koneksi, "SELECT * FROM fpm_header
    WHERE id_fpm = '$id_fpm'");

$qDetail = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm'");

$fpm = mysqli_fetch_assoc($qHeader);

$qPengirimanGate = mysqli_query($koneksi, "SELECT disetujui1, diperiksa FROM fpm_pengiriman WHERE id_fpm = '$id_fpm'");
$pengGate = mysqli_fetch_assoc($qPengirimanGate) ?: [];

$flowTypeGate = $fpm['flow_type'] ?? 'IMP';

if ($flowTypeGate === 'QA') {
  $isApprovedDisabled = empty(trim($pengGate['disetujui1'] ?? ''));
} elseif ($flowTypeGate === 'CLAIMMACH') {
  // Alur FPM Claim Machining v3 (linear): Staff QA -> Dept Head QA approve -> Plant Head
  // (pilih dept) -> Dept Head (terima -> jawab) -> Plant Head approve -> Directure approve.
  // Dept Head QA cuma approve di awal (sebelum Plant Head), jadi gate Directur di sini
  // cukup cek Plant Head sudah approve.
  $isApprovedDisabled = empty(trim($fpm['cm_plant_head_approved_at'] ?? ''));
} else {
  $isApprovedDisabled = empty(trim($fpm['diterima_imp'] ?? ''));
}

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

if (isset($_POST['approve']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $isClaimMachApprove = (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH');

  if ($isClaimMachApprove && empty(trim($fpm['cm_plant_head_approved_at'] ?? ''))) {
    die('FPM ini belum di-approve Plant Head. Tidak bisa diapprove Directure dulu.');
  }

  // FPM IMP (Internal / Down Time): Directure approve setelah Head IMP menerima
  // (diterima_imp) - sama dengan gate tombol di UI ($isApprovedDisabled).
  if ($flowTypeGate !== 'QA' && !$isClaimMachApprove && $isApprovedDisabled) {
    die('FPM ini belum diterima Head IMP. Tidak bisa diapprove Directure dulu.');
  }

  $updatePengiriman = mysqli_query($koneksi, "UPDATE fpm_pengiriman
        SET disetujui = 'Directure', user_disetujui = '$username', tgl_disetujui = '$date'
        WHERE id_fpm = '$id_fpm_post'");

  if ($isClaimMachApprove) {
    mysqli_query($koneksi, "UPDATE fpm_header
        SET cm_directur_approved_at = NOW(), cm_directur_approved_oleh = '$username'
        WHERE id_fpm = '$id_fpm_post'");

    require_once __DIR__ . '/../config/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    $waStaffQaTarget = implode(',', array_filter($waStaffQA ?? []));

    if (!empty($waStaffQaTarget)) {
      $pesanStaffQa = "FPM Claim Machining sudah di-approve Directure (final). No Form: " . ($fpm['no_form'] ?? $id_fpm_post);
      $terkirimStaffQa = kirimWA($waStaffQaTarget, $pesanStaffQa);

      if (!$terkirimStaffQa) {
        error_log("Gagal kirim WA final approval FPM Claim Machining ke Staff QA: $id_fpm_post");
      }
    }
  } elseif (($fpm['flow_type'] ?? 'IMP') === 'QA') {
    // Approval terakhir alur FPM QA - kabari Staff QA (pembuat) bahwa FPM sudah final.
    require_once __DIR__ . '/../config/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    $waStaffQaTarget = implode(',', array_filter($waStaffQA ?? []));

    if (!empty($waStaffQaTarget)) {
      $pesanStaffQa = "FPM QA sudah di-approve Directure (final). No Form: " . ($fpm['no_form'] ?? $id_fpm_post);

      if (!kirimWA($waStaffQaTarget, $pesanStaffQa)) {
        error_log("Gagal kirim WA final approval FPM QA ke Staff QA: $id_fpm_post");
      }
    }
  }

  if ($updatePengiriman) {
    echo "<script>
              alert('FPM berhasil di-approve oleh Directure');
              window.location='form_fpm.php?id=$id_fpm_post';
            </script>";
    exit;
  } else {
    die('Gagal update approval Directure');
  }
}

if (isset($_POST['batal_approved']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];

  $update = mysqli_query($koneksi, "UPDATE fpm_pengiriman 
      SET disetujui = '', user_disetujui = '' WHERE id_fpm = '$id_fpm_post'
  ");

  if ($update) {
    header("Location: form_fpm.php?id=" . $id_fpm_post);
    exit;
  } else {
    die('Gagal membatalkan approval');
  }
}

if (isset($_POST['reject_submit']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $note = mysqli_real_escape_string($koneksi, $_POST['note']);
  $isClaimMachReject = (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH');

  $update = mysqli_query($koneksi, "UPDATE fpm_header
        SET note = '$note', tgl_reject = '$date', diterima_imp = '', status_reject = 'REJECT'
        WHERE id_fpm = '$id_fpm_post'");

  $reset_pengiriman = mysqli_query($koneksi, "UPDATE fpm_pengiriman
        SET disetujui1 = '', user_disetujui1 = '',
            diperiksa2 = '', user_diperiksa2 = '',
            diperiksa = '', user_diperiksa = '',
            dibuat = '', user_dibuat = ''
        WHERE id_fpm = '$id_fpm_post'");

  // Sesuai SOP "Flow FPM Claim Machining": kalau Directur menolak, alur kembali ke
  // "Analisa & Jawab FPM" oleh Dept Terkait - jadi status Dept Head Jawab, Dept Head
  // Approve, DAN Plant Head Approve semua di-reset (dept terkait harus menjawab ulang,
  // lalu diapprove ulang oleh Dept Head dan Plant Head). Flow selain Claim Machining
  // TIDAK terpengaruh.
  if ($isClaimMachReject) {
    mysqli_query($koneksi, "UPDATE fpm_header
        SET cm_dept_head_dijawab_at = NULL, cm_dept_head_dijawab_oleh = NULL,
            cm_dept_head_approved_at = NULL, cm_dept_head_approved_oleh = NULL,
            cm_plant_head_approved_at = NULL, cm_plant_head_approved_oleh = NULL
        WHERE id_fpm = '$id_fpm_post'");
  }

  catatFpmRevisiHistory($koneksi, $id_fpm_post, 'REJECT', $_POST['note'], $username, 'Directure');

  $nama_dept = $isClaimMachReject ? ($fpm['dept'] ?? '') : $fpm['internal_dept'];

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  if ($isClaimMachReject) {
    $plantKeyReject = trim(str_ireplace('Plant', '', $fpm['plant'] ?? ''));

    $waTujuan = array_merge(
      $waMap[$nama_dept]['depthead'] ?? [],
      $waMap[$nama_dept]['staffdept'] ?? [],
      $waMapPlantHead[$plantKeyReject] ?? []
    );

    $waTarget = implode(',', array_unique(array_filter($waTujuan)));

    $pesan = "FPM Claim Machining departemen *$nama_dept* diminta revisi oleh Directure, mohon dianalisa & dijawab ulang. No Form: " . $fpm['no_form'];

    $terkirim = kirimWA($waTarget, $pesan);
  } elseif (($fpm['flow_type'] ?? 'IMP') === 'QA') {

    // Alur FPM QA (Staff QA -> Dept Head -> Plant Head -> Dept Head QA ->
    // Directure) TIDAK melalui Imp Head sama sekali - jadi notif revisi dari
    // Directure ditujukan ke semua pihak QA sebelum Directure, bukan ke
    // kontak Imp Head/Staff IMP generik.
    $waTujuan = array_merge(
      $waStaffQA ?? [],
      $waStaff ?? [],
      $waDeptHead ?? [],
      $waPlantHead ?? [],
      $waDeptHeadQA ?? []
    );

    $waTarget = implode(',', array_unique(array_filter($waTujuan)));

    $pesan = "FPM QA departemen *$nama_dept* diminta revisi oleh Directure. Mohon segera ditindak lanjuti. No Form: " . $fpm['no_form'];

    $terkirim = kirimWA($waTarget, $pesan);
  } else {
    $nilaiPlant = $fpm['plant'] ?? '';

    if ($nilaiPlant == '1') {
      $waStaffFinal = $waStaffIMP1 ?? [];
      $waIMPHeadFinal = $waIMPHead1 ?? [];
    } elseif ($nilaiPlant == '3') {
      $waStaffFinal = $waStaffIMP3 ?? [];
      $waIMPHeadFinal = $waIMPHead3 ?? [];
    } else {
      $waStaffFinal = [];
      $waIMPHeadFinal = [];
    }

    // Notif revisi harus ke semua pihak SEBELUM Directure di alur approval
    // (Staff dept, Staff IMP, Dept Head, Plant Head, Imp Head).
    $waTujuan = array_merge(
      $waStaff ?? [],
      $waStaffFinal,
      $waIMPHeadFinal,
      $waDeptHead ?? [],
      $waPlantHead ?? []
    );

    $waTarget = implode(',', $waTujuan);

    $pesan = "FPM departemen *$nama_dept* diminta revisi oleh Directure. Mohon segera ditindak lanjuti. No Form: " . $fpm['no_form'];

    $terkirim = kirimWA($waTarget, $pesan);
  }

  if ($update && $reset_pengiriman) {
    echo "<script>
            alert('FPM berhasil diajukan revisi');
            window.location='form_fpm.php?id=$id_fpm_post';
          </script>";
    exit;
  } else {
    die('Gagal update data reject');
  }
}

if (isset($_POST['verifikasi_3_bulan']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $tanggal_hari_ini = date('Y-m-d');
  $isClaimMachEvaluasi = (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH');

  // Flow FPM Claim Machining langkah 12: Direktur approve status evaluasi setelah
  // Dept Head QA approve (fpm_evaluasi.diperiksa).
  if ($isClaimMachEvaluasi) {
    $evaluasiNow = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT diperiksa, disetujui FROM fpm_evaluasi WHERE id_fpm = '$id_fpm_post'")) ?: [];

    if (empty(trim($evaluasiNow['diperiksa'] ?? ''))) {
      die('Status evaluasi belum diapprove Dept Head QA.');
    }

    if (!empty(trim($evaluasiNow['disetujui'] ?? ''))) {
      die('Status evaluasi sudah diapprove sebelumnya.');
    }
  }

  $update = mysqli_query($koneksi, "UPDATE fpm_evaluasi SET disetujui = 'Directure', user_disetujui = '$username', tgl_disetujui = '$tanggal_hari_ini' WHERE id_fpm = '$id_fpm_post'");

  if ($update && $isClaimMachEvaluasi) {
    require_once __DIR__ . '/../config/wa_map.php';
    require_once __DIR__ . '/../services/whatsapp.php';

    $waTarget = implode(',', array_unique(array_filter(array_merge($waStaffQA ?? [], $waDeptHeadQA ?? []))));

    if (!empty($waTarget)) {
      $pesan = "Status evaluasi FPM Claim Machining (*" . ($fpm['status_evaluasi'] ?: '-') . "*) sudah di-approve Direktur (final). No Form: " . $fpm['no_form'];

      if (!kirimWA($waTarget, $pesan)) {
        error_log("Gagal kirim WA final status evaluasi FPM Claim Machining: $id_fpm_post");
      }
    }
  }

  if ($update) {
    header("Location: form_fpm.php?id=" . $id_fpm_post);
    exit;
  }
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

$isQaFlow = (($fpm['flow_type'] ?? 'IMP') === 'QA');
$isClaimMach = (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH');

$btnDisabledVerif3Bulan = '';

// Vice Directure sudah tidak ada di alur mana pun: FPM QA & FPM Claim Machining (flow
// langkah 12: Staff QA buat -> Dept Head QA approve -> Direktur) menunggu Dept Head QA,
// FPM IMP (Internal & Down Time) menunggu Head IMP - semuanya di field 'diperiksa'.
if (empty(trim($evaluasi['diperiksa'] ?? ''))) {
  $btnDisabledVerif3Bulan = 'disabled';
}

if (!empty(trim($evaluasi['disetujui'] ?? ''))) {
  $btnDisabledVerif3Bulan = 'disabled';
}

$qAnalisaOcc = mysqli_query($koneksi, "SELECT * FROM fpm_analisa WHERE id_fpm = '$id_fpm' 
    AND jenis = 'OCCURRENCE' ORDER BY id ASC");

$qAnalisaFlow = mysqli_query($koneksi, "SELECT * FROM fpm_analisa WHERE id_fpm = '$id_fpm' 
    AND jenis = 'FLOW OUT' ORDER BY id ASC");

$analisaOccData  = mysqli_fetch_all($qAnalisaOcc ?? false, MYSQLI_ASSOC) ?: [];
$analisaFlowData = mysqli_fetch_all($qAnalisaFlow ?? false, MYSQLI_ASSOC) ?: [];

$peng = $pengirimanData[0] ?? [];

$isSudahApprovedPengiriman = !empty(trim($peng['disetujui'] ?? ''));

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

  <div style="float:right" class="no-print">
    <form method="post" style="display:inline">
      <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">

      <button
        type="submit"
        name="approve"
        class="btn btn-primary btn-sm"
        onclick="return confirm('Approve FPM ini?')"
        <?= ($isApprovedDisabled || $isSudahApprovedPengiriman) ? 'disabled' : '' ?>>

        <?php
        if ($isSudahApprovedPengiriman) {
          echo 'Sudah Diapproved';
        } elseif ($isApprovedDisabled) {
          if (($fpm['flow_type'] ?? 'IMP') === 'QA') {
            echo 'Menunggu Approval Dept Head QA';
          } elseif ($isClaimMach) {
            echo 'Menunggu Approval Plant Head';
          } else {
            echo 'Belum Diverifikasi IMP';
          }
        } else {
          echo 'Approved';
        }
        ?>

      </button>

      <button
        type="submit"
        name="batal_approved"
        class="btn btn-warning btn-sm"
        onclick="return confirm('Yakin ingin membatalkan approval?')">
        Batal Approved
      </button>

      <button
        type="button"
        class="btn btn-warning btn-sm"
        onclick="openRejectModal()">
        Revisi
      </button>

      <form method="POST" style="display:inline;">
        <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">

        <button type="submit"
          name="verifikasi_3_bulan"
          class="btn btn-success btn-sm"
          <?= $btnDisabledVerif3Bulan ?>
          title="<?= $btnDisabledVerif3Bulan ? ($isClaimMach ? (!empty(trim($evaluasi['disetujui'] ?? '')) ? 'Status evaluasi sudah diapprove' : (empty(trim($evaluasi['dibuat'] ?? '')) ? 'Menunggu Staff QA menjalankan Verifikasi 3 Bulan' : 'Menunggu approval status evaluasi Dept Head QA')) : ($isQaFlow ? 'Belum Approved Dept Head QA' : 'Belum Approved Head IMP')) : '' ?>">
          Verifikasi 3 Bulan
        </button>
      </form>

    </form>

  </div>

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
        <img src="<?= htmlspecialchars(brandLogo('dokumen')) ?>"
          style="max-height: 70px;">
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
        <input type="checkbox" <?= checkedIfNotEmpty($isClaimMach ? ($fpm['dept'] ?? '') : ($fpm['internal_dept'] ?? '')) ?> /> INTERNAL <br />
        DEPT : <?= htmlspecialchars($isClaimMach ? ($fpm['dept'] ?? '') : ($fpm['internal_dept'] ?? '')) ?>
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
              (!empty($peng['tgl']) && $peng['tgl'] != '0000-00-00' && !$isClaimMach)
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

  <!-- Modal Reject -->
  <div id="rejectModal" class="no-print" style="display:none; position:fixed; top:0; left:0;
     width:100%; height:100%; background:rgba(0,0,0,0.5);">

    <div style="background:#fff; width:400px; padding:20px; 
       margin:100px auto; border-radius:8px;">

      <h4>Alasan Revisi</h4>

      <form method="post">
        <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">

        <label>Alasan Revisi <span style="color:#dc2626">*</span></label>
        <textarea name="note" class="form-control"
          placeholder="Masukkan alasan revisi..."
          required style="width:100%; height:100px;"></textarea>

        <br>

        <button type="submit" name="reject_submit"
          class="btn btn-primary btn-sm">
          Submit
        </button>

        <button type="button"
          class="btn btn-secondary btn-sm"
          onclick="closeRejectModal()">
          Cancel
        </button>
      </form>

    </div>
  </div>

  <script src="../../../dist/js/print-export.js?v=<?= @filemtime(__DIR__ . '/../../js/print-export.js') ?>"></script>

  <script>
    function openRejectModal() {
      document.getElementById('rejectModal').style.display = 'block';
    }

    function closeRejectModal() {
      document.getElementById('rejectModal').style.display = 'none';
    }
  </script>


</body>

</html>