<?php
require_once __DIR__ . '/../config/branding_helper.php';
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/ng_detail_box.php';
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';
require_once __DIR__ . '/../config/fpm_catatan_revisi_helper.php';
require_once __DIR__ . '/../config/fpm_claim_approval_helper.php';
require_once __DIR__ . '/../config/fpm_lampiran_helper.php';

date_default_timezone_set('Asia/Jakarta');
$date = date('Y-m-d');

$id_fpm = $_GET['id'] ?? 0;

$username = $_SESSION['username'];

$level = $_SESSION['level'];

$qHeader = mysqli_query($koneksi, "SELECT * FROM fpm_header WHERE id_fpm = '$id_fpm'");

$qDetail = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm'");

$fpm = mysqli_fetch_assoc($qHeader);

if (!$fpm) {
  die('Data FPM tidak ditemukan');
}

$isClaimMach = (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH');

// Dept tujuan Claim Machining harus SATU dept spesifik - semua langkah berikutnya
// (Dept Head Terima/Jawab/Approve) dicocokkan ke dept itu. "All Dept" tidak punya
// Dept Head, jadi FPM yang terlanjur diteruskan ke "All Dept" diperlakukan sama
// dengan belum diteruskan supaya Plant Head bisa memilih ulang dept tujuannya.
$deptClaimMachBelumDitentukan = $isClaimMach && (trim($fpm['dept'] ?? '') === '' || strcasecmp(trim($fpm['dept']), 'All Dept') === 0);

// Alur FPM QA & FPM IMP (Internal / Down Time): Plant Head approve setelah Dept Head
// terkait approve (fpm_pengiriman.dibuat = 'Dept Head'), supaya urutan approval & notif
// WA tidak terlompati (IMP: Head IMP -> Dept Head -> Plant Head -> Head IMP -> Directure;
// QA: Dept Head -> Plant Head -> Dept Head QA -> Directure).
$isFlowQaPlant = (($fpm['flow_type'] ?? 'IMP') === 'QA');
$qDibuatGate = mysqli_query($koneksi, "SELECT dibuat FROM fpm_pengiriman WHERE id_fpm = '" . (int) $id_fpm . "'");
$sudahDiapproveDeptHeadPengiriman = trim((mysqli_fetch_assoc($qDibuatGate) ?: [])['dibuat'] ?? '') === 'Dept Head';

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

if (isset($_POST['terima_pilih_dept_claim_mach']) && isset($_POST['id_fpm']) && $isClaimMach) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $deptTujuan  = trim($_POST['dept_tujuan'] ?? '');

  if (empty($fpm['cm_deptheadqa_approved_at'])) {
    die('FPM ini belum diapprove Dept Head QA.');
  }

  if (!$deptClaimMachBelumDitentukan) {
    die('FPM ini sudah diteruskan ke dept sebelumnya.');
  }

  if ($deptTujuan === '') {
    die('Dept tujuan wajib dipilih.');
  }

  if (strcasecmp($deptTujuan, 'All Dept') === 0) {
    die('Dept tujuan harus satu dept spesifik, tidak bisa "All Dept".');
  }

  $deptTujuanEsc = mysqli_real_escape_string($koneksi, $deptTujuan);

  mysqli_query($koneksi, "UPDATE fpm_header
      SET dept = '$deptTujuanEsc', cm_plant_head_diterima_at = NOW(), cm_plant_head_diterima_oleh = '$username'
      WHERE id_fpm = '$id_fpm_post'");

  $cekPengirimanDept = mysqli_query($koneksi, "SELECT id FROM fpm_pengiriman WHERE id_fpm = '$id_fpm_post'");

  if (mysqli_num_rows($cekPengirimanDept) > 0) {
    mysqli_query($koneksi, "UPDATE fpm_pengiriman SET departement = '$deptTujuanEsc', tgl = '$date' WHERE id_fpm = '$id_fpm_post'");
  } else {
    mysqli_query($koneksi, "INSERT INTO fpm_pengiriman (id_fpm, departement, tgl) VALUES ('$id_fpm_post', '$deptTujuanEsc', '$date')");
  }

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  $waTarget = implode(',', array_filter($waMap[$deptTujuan]['depthead'] ?? []));

  if (!empty($waTarget)) {
    require_once __DIR__ . '/../config/fpm_claim_machining_helper.php';
    $dueDateJawab = hitungDueDateJawabClaimMachining($fpm['tgl_pembuatan'] ?: $date, (int) ($fpm['kejadian_berulang_ke'] ?? 0));

    $pesan = "FPM Claim Machining diteruskan Plant Head kepada Dept Head *$deptTujuan*. Mohon klik Diterima lalu segera dijawab"
      . " paling lambat *" . date('d-m-Y', strtotime($dueDateJawab)) . "*. No Form: " . $fpm['no_form'];
    $terkirim = kirimWA($waTarget, $pesan);

    if (!$terkirim) {
      error_log("Gagal kirim WA FPM Claim Machining terima_pilih_dept: $id_fpm_post");
    }
  }

  header("Location: form_fpm.php?id=" . $id_fpm_post);
  exit;
}

if (isset($_POST['approved']) && isset($_POST['id_fpm'])) {
  $id_fpm_post = (int) $_POST['id_fpm'];

  // Untuk FPM Claim Machining, Plant Head cuma boleh approve setelah Dept Head tujuan
  // sudah menjawab DAN approve sendiri jawabannya (alur linear sesuai SOP: Plant Head
  // pilih dept -> Dept Head terima -> Dept Head jawab -> Dept Head approve -> Plant
  // Head approve -> Directur approve), bukan lagi approval paralel banyak dept PIC
  // seperti sebelumnya.
  if ($isClaimMach && empty($fpm['cm_dept_head_approved_at'])) {
    die('FPM ini belum diapprove oleh Dept Head tujuan.');
  }

  if (!$isClaimMach && !$sudahDiapproveDeptHeadPengiriman) {
    die('FPM ini belum diapprove oleh Dept Head.');
  }

  $update = mysqli_query($koneksi, "UPDATE fpm_pengiriman SET diperiksa = '$level', user_diperiksa = '$username', tgl_diperiksa = '$date' WHERE id_fpm = '$id_fpm_post'");

  if ($isClaimMach) {
    mysqli_query($koneksi, "UPDATE fpm_header SET cm_plant_head_approved_at = NOW(), cm_plant_head_approved_oleh = '$username' WHERE id_fpm = '$id_fpm_post'");
  }

  $nama_dept = $fpm['internal_dept'];

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  if (($fpm['flow_type'] ?? 'IMP') === 'QA') {

    $waTarget = implode(',', $waDeptHeadQA ?? []);
    $pesan = "FPM QA dari departemen *$nama_dept* sudah diapprove Plant Head, ditujukan kepada Dept Head QA. Mohon segera diverifikasi. No Form: " . $fpm['no_form'];

    $terkirim = kirimWA($waTarget, $pesan);

    if (!$terkirim) {
      error_log("Gagal kirim WA FPM: $id_fpm_post");
    }
  } elseif (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH') {

    // Dept Head QA sudah approve di AWAL alur (sebelum FPM ini sampai ke Plant Head) -
    // di tahap ini (setelah Plant Head approve jawaban Dept Head), langsung diteruskan
    // ke Direktur, tidak lewat Dept Head QA lagi (beda dengan FPM QA internal).
    $waTarget = implode(',', $waDirecture ?? []);
    $pesan = "FPM Claim Machining dari departemen *$nama_dept* sudah diapprove Plant Head, ditujukan kepada Direktur. No Form: " . $fpm['no_form'];

    $terkirim = kirimWA($waTarget, $pesan);

    if (!$terkirim) {
      error_log("Gagal kirim WA FPM: $id_fpm_post");
    }
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

    $waTujuan = array_merge(
      $waStaffFinal,
      $waIMPHeadFinal
    );

    $waTarget = implode(',', $waTujuan);

    $pesan = "Terdapat FPM dari departemen *$nama_dept* ditujukan kepada Team IMP. Mohon segera diverifikasi. No Form: " . $fpm['no_form'];

    $terkirim = kirimWA($waTarget, $pesan);

    if (!$terkirim) {
      error_log("Gagal kirim WA FPM: $id_fpm_post");
    }
  }

  if ($update) {
    header("Location: form_fpm.php?id=" . $id_fpm_post);
    exit;
  } else {
    die('Gagal update approval');
  }
}

if (isset($_POST['batal_approved']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];

  $update = mysqli_query($koneksi, "UPDATE fpm_pengiriman 
      SET diperiksa = '', user_diperiksa = '' WHERE id_fpm = '$id_fpm_post'
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

  $update = mysqli_query($koneksi, "UPDATE fpm_header SET tgl_reject = '$date', note = '$note', status_reject = 'REJECT'
        WHERE id_fpm = '$id_fpm_post'");

  // Kalau Plant Head menolak, alur kembali ke Dept Head untuk direvisi - dibuat &
  // user_dibuat (tanda "sudah dijawab Dept Head") direset juga di sini, bukan cuma
  // diperiksa, supaya status "Approved" di List FPM tidak tetap nyangkut di
  // "Dept Head" padahal FPM sudah diminta revisi dan sedang menunggu Dept Head.
  $reset_pengiriman = mysqli_query($koneksi, "UPDATE fpm_pengiriman
        SET diperiksa = '', user_diperiksa = '',
            dibuat = '', user_dibuat = ''
        WHERE id_fpm = '$id_fpm_post'");

  // Sesuai SOP "Flow FPM Claim Machining": kalau Plant Head menolak, alur kembali ke
  // "Analisa & Jawab FPM" oleh Dept Terkait - jadi status Dept Head Jawab & Dept Head
  // Approve di-reset supaya dept terkait harus menjawab ulang, bukan cuma ditandai
  // revisi tanpa mengubah tahapan.
  if ($isClaimMach) {
    mysqli_query($koneksi, "UPDATE fpm_header
        SET cm_dept_head_dijawab_at = NULL, cm_dept_head_dijawab_oleh = NULL,
            cm_dept_head_approved_at = NULL, cm_dept_head_approved_oleh = NULL
        WHERE id_fpm = '$id_fpm_post'");
  }

  catatFpmRevisiHistory($koneksi, $id_fpm_post, 'REJECT', $_POST['note'], $username, $level);

  $nama_dept = $isClaimMach ? ($fpm['dept'] ?? '') : $fpm['internal_dept'];

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  // FPM QA dibahas & direvisi oleh Staff QA, jadi Staff QA ikut dikabari.
  $waTujuan = array_merge($isFlowQaPlant ? ($waStaffQA ?? []) : [], $waStaff, $waDeptHead);
  $waTarget = implode(',', array_unique(array_filter($waTujuan)));

  $pesan = $isClaimMach
    ? "FPM Claim Machining departemen *$nama_dept* diminta revisi oleh Plant Head, mohon dianalisa & dijawab ulang. No Form: " . $fpm['no_form']
    : "FPM departemen *$nama_dept* diminta revisi oleh Plant Head. Mohon segera ditindak lanjuti. No Form: " . $fpm['no_form'];

  $terkirim = kirimWA($waTarget, $pesan);

  if ($update) {
    echo "<script>
            alert('FPM berhasil diajukan revisi');
            window.location='form_fpm.php?id=$id_fpm_post';
          </script>";
    exit;
  } else {
    die('Gagal update note');
  }
}

if (isset($_POST['balas_catatan_pic']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $balasan     = trim($_POST['balasan'] ?? '');

  if ($balasan === '') {
    die('Balasan wajib diisi');
  }

  $targetDept   = trim($fpm['catatan_target_dept'] ?? '');
  $sessionDept  = $_SESSION['dept'] ?? '';
  $sessionLevel = $_SESSION['level'] ?? '';
  $berhakBalas  = (($fpm['status_reject'] ?? '') === 'REJECT') && $targetDept !== ''
    && (($sessionDept !== '' && $sessionDept === $targetDept) || $sessionLevel === 'Plant Head');

  if (!$berhakBalas) {
    die('Tidak berhak membalas catatan ini');
  }

  mysqli_query($koneksi, "UPDATE fpm_header SET status_reject = '', catatan_target_dept = NULL WHERE id_fpm = '$id_fpm_post'");

  catatFpmCatatanRevisi($koneksi, $id_fpm_post, $targetDept, $balasan, $username, 'BALASAN', $sessionLevel);

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  $waTarget = implode(',', array_filter($waMap['QA']['depthead'] ?? []));

  if (!empty($waTarget)) {
    $pesan = "Balasan catatan FPM QA dari dept *$targetDept* (No Form: " . $fpm['no_form'] . ") sudah masuk. Mohon dicek.";
    $terkirim = kirimWA($waTarget, $pesan);

    if (!$terkirim) {
      error_log("Gagal kirim WA balasan catatan PIC FPM QA: $id_fpm_post");
    }
  }

  echo "<script>
          alert('Balasan berhasil dikirim');
          window.location='form_fpm.php?id=$id_fpm_post';
        </script>";
  exit;
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
    WHERE id_fpm = '$id_fpm' AND jenis = 'OCCURRENCE' ORDER BY id ASC");

$qAnalisaFlow = mysqli_query($koneksi, "SELECT * FROM fpm_analisa
    WHERE id_fpm = '$id_fpm' AND jenis = 'FLOW OUT' ORDER BY id ASC");

$analisaOccData  = mysqli_fetch_all($qAnalisaOcc ?? false, MYSQLI_ASSOC) ?: [];
$analisaFlowData = mysqli_fetch_all($qAnalisaFlow ?? false, MYSQLI_ASSOC) ?: [];

$sudahApproved = false;

if (!empty($pengirimanData)) {
  $peng = $pengirimanData[0];
  if (!empty($peng['diperiksa']) && $peng['diperiksa'] == 'Plant Head') {
    $sudahApproved = true;
  }
}

$similarData = mysqli_fetch_all($qSimilar ?? false, MYSQLI_ASSOC) ?: [];

$sudahDiapproveDeptHead = $isClaimMach && !empty($fpm['cm_dept_head_approved_at']);

$masterDeptClaimMach = [];
if ($deptClaimMachBelumDitentukan) {
  $qMasterDeptClaimMach = mysqli_query($koneksi, "SELECT nama_dept FROM master_dept WHERE nama_dept <> 'All Dept' ORDER BY nama_dept ASC");
  while ($rowDept = mysqli_fetch_assoc($qMasterDeptClaimMach)) {
    $masterDeptClaimMach[] = $rowDept['nama_dept'];
  }
}

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
        type="submit" name="approved"
        class="btn btn-primary btn-sm"
        <?= ($sudahApproved || ($isClaimMach && !$sudahDiapproveDeptHead) || (!$isClaimMach && !$sudahDiapproveDeptHeadPengiriman)) ? 'disabled style="cursor:not-allowed;opacity:0.6;"' : '' ?>
        onclick="return confirm('Approve FPM ini?')">

        <?php
        if ($sudahApproved) {
          echo 'Sudah Diapproved';
        } elseif (($isClaimMach && !$sudahDiapproveDeptHead) || (!$isClaimMach && !$sudahDiapproveDeptHeadPengiriman)) {
          echo 'Menunggu Approve Dept Head';
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
        type="button" class="btn btn-warning btn-sm"
        onclick="openRejectModal()">
        Revisi
      </button>
    </form>

    <?php if ($isClaimMach): ?>
      <?php if (!$deptClaimMachBelumDitentukan): ?>
        <a href="jawab_fpm_claim_machining.php?id=<?= $id_fpm ?>" class="btn btn-outline-primary btn-sm">Isi / Update Perbaikan</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($isClaimMach && empty($fpm['cm_deptheadqa_approved_at'])): ?>
    <div class="no-print" style="max-width:900px; margin:12px 0; padding:12px; border:1px solid #fca5a5; background:#fef2f2; border-radius:8px; color:#991b1b; font-size:13px;">
      Menunggu approval Dept Head QA. FPM ini baru bisa diterima &amp; diteruskan ke dept tujuan setelah Dept Head QA approve.
    </div>
  <?php elseif ($deptClaimMachBelumDitentukan): ?>
    <div class="no-print" style="max-width:900px; margin:12px 0; padding:12px; border:1px solid #fbbf24; background:#fffbeb; border-radius:8px;">
      <form method="POST" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
        <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">
        <div>
          <label style="display:block; font-size:12px; font-weight:600; color:#92400e; margin-bottom:4px;">Terima &amp; Pilih Dept Tujuan</label>
          <select name="dept_tujuan" class="form-control" required>
            <option value="">-- Pilih Dept --</option>
            <?php foreach ($masterDeptClaimMach as $d): ?>
              <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="terima_pilih_dept_claim_mach" class="btn btn-success btn-sm"
          onclick="return confirm('Terima FPM ini dan teruskan ke dept terpilih?')">
          Terima &amp; Teruskan
        </button>
      </form>
    </div>
  <?php elseif ($isClaimMach): ?>
    <div class="no-print" style="max-width:900px; margin:12px 0; font-size:12px; color:#374151;">
      <strong>Status alur Claim Machining:</strong>
      <?php
      $stepsClaimMach = [
        'Dept Head QA Approve' => !empty($fpm['cm_deptheadqa_approved_at']),
        'Plant Head Terima'   => !empty($fpm['cm_plant_head_diterima_at']),
        'Dept Head Terima'    => !empty($fpm['cm_dept_head_diterima_at']),
        'Dept Head Jawab'     => !empty($fpm['cm_dept_head_dijawab_at']),
        'Dept Head Approve'   => !empty($fpm['cm_dept_head_approved_at']),
        'Plant Head Approve'  => !empty($fpm['cm_plant_head_approved_at']),
        'Directur Approve'    => !empty($fpm['cm_directur_approved_at']),
      ];
      foreach ($stepsClaimMach as $label => $done):
      ?>
        <span style="display:inline-block; margin:2px 6px 2px 0; padding:2px 8px; border-radius:10px; font-weight:600; background:<?= $done ? '#f0fdf4' : '#fef2f2' ?>; color:<?= $done ? '#16a34a' : '#dc2626' ?>;">
          <?= $done ? '&#10003;' : '&#9675;' ?> <?= htmlspecialchars($label) ?>
        </span>
      <?php endforeach; ?>
      (Dept Tujuan: <?= htmlspecialchars($fpm['dept']) ?>)
    </div>
  <?php endif; ?>

  <br><br>

  <div class="no-print" style="max-width:900px; margin-bottom:16px;">
    <?php $riwayatRevisi = ambilFpmRevisiHistory($koneksi, (int) $id_fpm); ?>
    <?php if ($riwayatRevisi): ?>
      <details open>
        <summary style="cursor:pointer; font-weight:600; font-size:13px; color:#374151;">Riwayat Revisi (<?= count($riwayatRevisi) ?>)</summary>
        <div style="margin-top:8px;"><?= renderFpmRevisiHistory($riwayatRevisi) ?></div>
      </details>
    <?php endif; ?>

    <?php $riwayatCatatanPic = ambilFpmCatatanRevisi($koneksi, (int) $id_fpm); ?>
    <?php if ($riwayatCatatanPic): ?>
      <details open style="margin-top:8px;">
        <summary style="cursor:pointer; font-weight:600; font-size:13px; color:#374151;">Catatan ke PIC (<?= count($riwayatCatatanPic) ?>)</summary>
        <div style="margin-top:8px;"><?= renderFpmCatatanRevisiHistory($riwayatCatatanPic) ?></div>
      </details>
    <?php endif; ?>

    <?= renderBalasCatatanPicWidget($fpm, $_SESSION['dept'] ?? '', $_SESSION['level'] ?? '') ?>
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
            <td><?= $isClaimMach ? v('cm_deptheadqa_approved_oleh', $fpm) : v('user_disetujui', $fpm) ?></td>
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