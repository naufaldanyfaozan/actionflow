<?php
require_once __DIR__ . '/../config/branding_helper.php';
require_once __DIR__ . '/../config/auth_helper.php';
session_start();
wajibLogin();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/ng_detail_box.php';
require_once __DIR__ . '/../config/fpm_revisi_history_helper.php';
require_once __DIR__ . '/../config/fpm_catatan_revisi_helper.php';
require_once __DIR__ . '/../config/fpm_claim_approval_helper.php';
require_once __DIR__ . '/../config/fpm_lampiran_helper.php';

if (!$koneksi) {
  die(pesanErrorDb('Koneksi database gagal', mysqli_connect_error()));
}

date_default_timezone_set('Asia/Jakarta');
$date = date('Y-m-d');

$id_fpm = (int) ($_GET['id'] ?? 0);

$username = $_SESSION['username'];

$level    = $_SESSION['level'];

$qHeader  = mysqli_query($koneksi, "SELECT * FROM fpm_header WHERE id_fpm = '$id_fpm'");
$qDetail  = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm'");

$fpm      = mysqli_fetch_assoc($qHeader);

$isFlowQA = (($fpm['flow_type'] ?? 'IMP') === 'QA');
$isClaimMach = (($fpm['flow_type'] ?? 'IMP') === 'CLAIMMACH');

// Untuk FPM QA, disetujui_directure diisi username asli (bukan literal 'Dept Head'), jadi
// pengecekan "sudah diterima/approved"-nya beda dari FPM IMP - supaya tombolnya benar-benar
// berubah jadi "Sudah Approved" setelah diklik. Punya FPM IMP TIDAK diubah.
$sudahDiterima = $isFlowQA
  ? !empty(trim($fpm['disetujui_directure'] ?? ''))
  : (trim($fpm['disetujui_directure'] ?? '') === 'Dept Head');

$sudahDiapproveImp = $isFlowQA
  ? !empty(trim($fpm['diterima_qa'] ?? ''))
  : (trim($fpm['disetujui_oleh'] ?? '') === 'Imp Head');

if (!$fpm) {
  die('Data FPM tidak ditemukan');
}

// FPM QA baru boleh di-approve Dept Head setelah Staff QA selesai pembahasan (dept terkait
// & corrective action terisi lewat staff/jawab_fpm_qa.php) - sebelum itu dept terkait masih
// kosong, jadi notif WA ke Plant Head setelah approve tidak punya tujuan.
$sudahDibahasQa = $isFlowQA
  && trim($fpm['internal_dept'] ?? '') !== ''
  && mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM fpm_corrective_action WHERE id_fpm = '" . (int) $id_fpm . "' LIMIT 1")) > 0;

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

if (isset($_POST['approved']) && isset($_POST['id_fpm']) && !$isClaimMach) {

  $id_fpm_post = (int) $_POST['id_fpm'];

  $cekPengiriman = mysqli_query($koneksi, "SELECT dibuat FROM fpm_pengiriman WHERE id_fpm = '$id_fpm_post'");
  $dataCek = mysqli_fetch_assoc($cekPengiriman);

  if (($dataCek['dibuat'] ?? '') === 'Dept Head') {
    die('FPM sudah di-approve sebelumnya.');
  }

  if (!$isFlowQA && trim($fpm['disetujui_oleh'] ?? '') !== 'Imp Head') {
    die('FPM ini belum di-approve oleh IMP Head. Tidak bisa di-approve Dept Head dulu.');
  }

  $cekCorrective = mysqli_query($koneksi, "SELECT id FROM fpm_corrective_action WHERE id_fpm = '$id_fpm_post'");

  if (mysqli_num_rows($cekCorrective) == 0) {
    die('Tidak bisa approve karena corrective action belum diisi.');
  }

  $update = mysqli_query($koneksi, "UPDATE fpm_pengiriman SET  dibuat = '$level', user_dibuat = '$username' WHERE id_fpm = '$id_fpm_post'");

  $nama_dept = $fpm['internal_dept'];

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  // Data sudah tersimpan di atas - nomor WA kosong jangan bikin halaman berhenti dengan
  // error (user jadi mengira gagal & submit ulang), cukup lewati kirim WA + catat di log.
  $waTarget = implode(',', array_filter($waPlantHead));
  $pesan = "Terdapat FPM dari departemen *$nama_dept* ditujukan kepada Plant Head. Mohon segera ditindaklanjuti. No Form: " . $fpm['no_form'];

  if ($waTarget === '') {
    error_log("WA Plant Head kosong untuk dept '$nama_dept' (wa_map.php), notifikasi FPM $id_fpm_post dilewati");
  } elseif (!kirimWA($waTarget, $pesan)) {
    error_log("Gagal kirim WA FPM: $id_fpm_post");
  }

  if ($update) {
    header("Location: form_fpm.php?id=" . $id_fpm_post);
    exit;
  } else {
    die('Gagal update approval');
  }
}

if (isset($_POST['terima']) && isset($_POST['id_fpm'])) {
  $id_fpm_post = (int) $_POST['id_fpm'];

  if ($isFlowQA) {
    if (empty(trim($fpm['diterima_qa'] ?? ''))) {
      die('FPM ini belum diterima oleh Staff QA. Tidak bisa diteruskan ke Team Dept dulu.');
    }
    if (!$sudahDibahasQa) {
      die('FPM ini belum selesai dibahas Staff QA. Tidak bisa di-approve dulu.');
    }
  } elseif (trim($fpm['disetujui_oleh'] ?? '') !== 'Imp Head') {
    die('FPM ini belum di-approve oleh IMP Head. Tidak bisa diteruskan ke Team Dept dulu.');
  }

  $update = mysqli_query($koneksi, "UPDATE fpm_header SET diterima_pic = '$date', disetujui_directure = '$username' WHERE id_fpm = '$id_fpm_post'");

  if ($isFlowQA) {
    // Untuk FPM QA tombol ini sekaligus jadi approval Dept Head (bukan cuma "terima"),
    // jadi langsung catat di fpm_pengiriman.dibuat/user_dibuat supaya kolom DIBUAT pada
    // form cetak (sejajar Departemen/TGL) terisi nama Dept Head yang approve, dan status
    // "Approved: Dept Head" langsung muncul di list tanpa perlu isi jawab_fpm.php dulu.
    $deptTerkait = $koneksi->real_escape_string($fpm['dept'] ?? '');

    $cekPengiriman = mysqli_query($koneksi, "SELECT id FROM fpm_pengiriman WHERE id_fpm = '$id_fpm_post'");

    if (mysqli_num_rows($cekPengiriman) > 0) {
      mysqli_query($koneksi, "UPDATE fpm_pengiriman
          SET departement = '$deptTerkait', dibuat = 'Dept Head', user_dibuat = '$username'
          WHERE id_fpm = '$id_fpm_post'");
    } else {
      mysqli_query($koneksi, "INSERT INTO fpm_pengiriman (id_fpm, departement, tgl, dibuat, user_dibuat)
          VALUES ('$id_fpm_post', '$deptTerkait', '$date', 'Dept Head', '$username')");
    }
  }

  $nama_dept = $fpm['internal_dept'];

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  if ($isFlowQA) {
    // Dept terkait FPM QA bisa "All Dept" atau dept yang belum terdaftar di wa_map.php,
    // jadi jangan blokir approval-nya hanya karena nomor WA belum ada - cukup skip kirimnya.
    if (!empty($waPlantHead)) {
      $waTarget = implode(',', $waPlantHead);
      $pesan = "FPM QA departemen *$nama_dept* sudah diapprove Dept Head, ditujukan kepada Plant Head. No Form: " . $fpm['no_form'];
      $terkirim = kirimWA($waTarget, $pesan);

      if (!$terkirim) {
        error_log("Gagal kirim WA FPM: $id_fpm_post");
      }
    }
  } else {

    // Data sudah tersimpan di atas - nomor WA kosong jangan bikin halaman berhenti dengan
    // error (user jadi mengira gagal & submit ulang), cukup lewati kirim WA + catat di log.
    $waTarget = implode(',', array_filter($waStaff));
    $pesan = "Terdapat FPM untuk departemen *$nama_dept* ditujukan kepada Team *$nama_dept*. Mohon segera dijawab. No Form: " . $fpm['no_form'];

    if ($waTarget === '') {
      error_log("WA Staff kosong untuk dept '$nama_dept' (wa_map.php), notifikasi FPM $id_fpm_post dilewati");
    } elseif (!kirimWA($waTarget, $pesan)) {
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

if (isset($_POST['reject_submit']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $note = mysqli_real_escape_string($koneksi, $_POST['note']);

  $update = mysqli_query($koneksi, "UPDATE fpm_header SET tgl_reject = '$date', note = '$note', status_reject = 'REJECT' WHERE id_fpm = '$id_fpm_post'");

  catatFpmRevisiHistory($koneksi, $id_fpm_post, 'REJECT', $_POST['note'], $username, $level);

  // Flow FPM Claim Machining: Dept Terkait tidak approve -> kembali ke "Analisa & Jawab
  // FPM", jadi status jawab/approve Dept Head di-reset (sama seperti revisi Plant Head /
  // Directur) dan notif ke staff dept terkait (bukan internal_dept = QA).
  if ($isClaimMach) {
    mysqli_query($koneksi, "UPDATE fpm_header
        SET cm_dept_head_dijawab_at = NULL, cm_dept_head_dijawab_oleh = NULL,
            cm_dept_head_approved_at = NULL, cm_dept_head_approved_oleh = NULL
        WHERE id_fpm = '$id_fpm_post'");
  }

  $nama_dept = $isClaimMach ? ($fpm['dept'] ?? '') : $fpm['internal_dept'];

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  // FPM QA dibahas & direvisi oleh Staff QA, jadi Staff QA ikut dikabari.
  $waTujuan = $isFlowQA ? array_merge($waStaffQA ?? [], $waStaff) : array_merge($waStaff);
  $waTarget = implode(',', array_unique(array_filter($waTujuan)));

  $pesan = ($isFlowQA ? "FPM QA" : "FPM") . " departemen *$nama_dept* diminta revisi oleh Dept Head. Mohon segera ditindak lanjuti. No Form: " . $fpm['no_form'];

  if ($isClaimMach) {
    $pesan = "FPM Claim Machining departemen *$nama_dept* diminta revisi oleh Dept Head, mohon dianalisa & dijawab ulang. No Form: " . $fpm['no_form'];
  }

  if ($waTarget !== '') {
    $terkirim = kirimWA($waTarget, $pesan);
  }

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

// Dept Head melihat data dasar FPM (part, mesin, jenis masalah, dst) salah
// SEBELUM diterima - beda dari tombol "Revisi" di atas (itu untuk jawaban
// countermeasure, arahnya ke Staff via status_reject). Di sini kita reset
// approval Head IMP supaya FPM balik ke antrian approval mereka, dan catat
// alasannya ke fpm_revisi_history yang sudah tampil di halaman Head IMP/Staff IMP.
if (isset($_POST['minta_perbaikan_data_submit']) && isset($_POST['id_fpm'])) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $catatanData = trim($_POST['catatan_data'] ?? '');

  if ($catatanData === '') {
    die('Catatan wajib diisi - jelaskan bagian data mana yang salah.');
  }

  if (!$sudahDiapproveImp || $sudahDiterima) {
    die('FPM ini sudah tidak dalam status menunggu diterima Dept Head.');
  }

  $update = mysqli_query($koneksi, "UPDATE fpm_header SET
      disetujui_oleh = '', user_disetujui = '', tgl_terbit = '',
      minta_perbaikan_data_at = NOW()
      WHERE id_fpm = '$id_fpm_post'");

  catatFpmRevisiHistory($koneksi, $id_fpm_post, 'REJECT', $catatanData, $username, $level);

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

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

  $waTarget = implode(',', array_filter(array_merge($waStaffFinal, $waIMPHeadFinal)));

  $pesan = "Dept Head meminta perbaikan data FPM No Form: " . $fpm['no_form'] . ".\nAlasan: $catatanData\nMohon segera diperbaiki datanya lalu approve ulang.";

  if (!empty($waTarget)) {
    $terkirim = kirimWA($waTarget, $pesan);
    if (!$terkirim) {
      error_log("Gagal kirim WA minta perbaikan data FPM: $id_fpm_post");
    }
  }

  if ($update) {
    echo "<script>
            alert('Permintaan perbaikan data berhasil dikirim ke Head IMP / Staff IMP');
            window.location='form_fpm.php?id=$id_fpm_post';
          </script>";
    exit;
  } else {
    die('Gagal update data');
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

if (isset($_POST['terima_claim_mach']) && isset($_POST['id_fpm']) && $isClaimMach) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $sessionDept = $_SESSION['dept'] ?? '';

  // Cuma Dept Head dari dept yang dipilih Plant Head yang boleh klik Diterima, dan cuma
  // sekali (dept Terkait harus sudah ditentukan Plant Head lebih dulu).
  if (empty($fpm['dept'])) {
    die('FPM ini belum diteruskan Plant Head ke dept manapun.');
  }

  if ($sessionDept === '' || $sessionDept !== $fpm['dept']) {
    die('Dept Anda bukan dept tujuan FPM ini.');
  }

  if (!empty($fpm['cm_dept_head_diterima_at'])) {
    die('FPM ini sudah diterima sebelumnya.');
  }

  mysqli_query($koneksi, "UPDATE fpm_header
      SET cm_dept_head_diterima_at = NOW(), cm_dept_head_diterima_oleh = '$username'
      WHERE id_fpm = '$id_fpm_post'");

  header("Location: form_fpm.php?id=" . $id_fpm_post);
  exit;
}

// Sesuai SOP "Flow FPM Claim Machining": setelah dept terkait Analisa & Jawab FPM,
// ada proses approval tersendiri oleh Dept Terkait (Spv/Asmen/Dept Head) SEBELUM
// diteruskan ke Plant Head - bukan langsung dari "jawab" ke approval Plant Head.
if (isset($_POST['approve_dept_head_claim_mach']) && isset($_POST['id_fpm']) && $isClaimMach) {

  $id_fpm_post = (int) $_POST['id_fpm'];
  $sessionDept = $_SESSION['dept'] ?? '';

  if ($sessionDept === '' || $sessionDept !== $fpm['dept']) {
    die('Dept Anda bukan dept tujuan FPM ini.');
  }

  if (empty($fpm['cm_dept_head_dijawab_at'])) {
    die('FPM ini belum dijawab. Isi dulu perbaikannya sebelum approve.');
  }

  if (!empty($fpm['cm_dept_head_approved_at'])) {
    die('FPM ini sudah diapprove sebelumnya.');
  }

  mysqli_query($koneksi, "UPDATE fpm_header
      SET cm_dept_head_approved_at = NOW(), cm_dept_head_approved_oleh = '$username'
      WHERE id_fpm = '$id_fpm_post'");

  require_once __DIR__ . '/../config/wa_map.php';
  require_once __DIR__ . '/../services/whatsapp.php';

  $plantKeyApprove = trim(str_ireplace('Plant', '', $fpm['plant'] ?? ''));
  $waApprovalTujuan = array_unique(array_filter($waMapPlantHead[$plantKeyApprove] ?? []));

  if (!empty($waApprovalTujuan)) {
    $waTargetApproval = implode(',', $waApprovalTujuan);
    $pesanApproval = "FPM Claim Machining (dept terkait: *{$fpm['dept']}*) sudah diapprove Dept Head, mohon di-approve. No Form: " . $fpm['no_form'];
    $terkirimApproval = kirimWA($waTargetApproval, $pesanApproval);

    if (!$terkirimApproval) {
      error_log("Gagal kirim WA approval Plant Head FPM Claim Machining: $id_fpm_post");
    }
  }

  header("Location: form_fpm.php?id=" . $id_fpm_post);
  exit;
}

$qCorrective  = mysqli_query($koneksi, "SELECT * FROM fpm_corrective_action WHERE id_fpm = '$id_fpm'");
$qPreventive  = mysqli_query($koneksi, "SELECT * FROM fpm_preventive_action WHERE id_fpm = '$id_fpm'");
$qReview      = mysqli_query($koneksi, "SELECT * FROM fpm_review_dokumen WHERE id_fpm = '$id_fpm'");
$qSimilar     = mysqli_query($koneksi, "SELECT * FROM fpm_similar WHERE id_fpm = '$id_fpm'");
$qPengiriman  = mysqli_query($koneksi, "SELECT * FROM fpm_pengiriman WHERE id_fpm = '$id_fpm'");
$qEvaluasi    = mysqli_query($koneksi, "SELECT * FROM fpm_evaluasi WHERE id_fpm = '$id_fpm'");

$correctiveData  = mysqli_fetch_all($qCorrective ?? false, MYSQLI_ASSOC) ?: [];
$preventiveData  = mysqli_fetch_all($qPreventive ?? false, MYSQLI_ASSOC) ?: [];
$reviewData      = mysqli_fetch_all($qReview ?? false, MYSQLI_ASSOC) ?: [];
$pengirimanData  = mysqli_fetch_all($qPengiriman ?? false, MYSQLI_ASSOC) ?: [];
$similarData     = mysqli_fetch_all($qSimilar ?? false, MYSQLI_ASSOC) ?: [];
$evaluasi        = $qEvaluasi ? (mysqli_fetch_assoc($qEvaluasi) ?: []) : [];

$qAnalisaOcc = mysqli_query($koneksi, "SELECT * FROM fpm_analisa 
    WHERE id_fpm = '$id_fpm' 
    AND jenis = 'OCCURRENCE'
    ORDER BY id ASC
");

$qAnalisaFlow = mysqli_query($koneksi, "SELECT * FROM fpm_analisa 
    WHERE id_fpm = '$id_fpm' 
    AND jenis = 'FLOW OUT'
    ORDER BY id ASC
");

$analisaOccData  = mysqli_fetch_all($qAnalisaOcc ?? false, MYSQLI_ASSOC) ?: [];
$analisaFlowData = mysqli_fetch_all($qAnalisaFlow ?? false, MYSQLI_ASSOC) ?: [];

$peng = $pengirimanData[0] ?? [];

$sudahApproved = (trim($peng['dibuat'] ?? '') === 'Dept Head');

$adaCorrective = (count($correctiveData) > 0);

// FPM IMP (Internal / Down Time): approve Dept Head hanya setelah Head IMP approve.
$bolehApproved = (!$sudahApproved && $adaCorrective && ($isFlowQA || trim($fpm['disetujui_oleh'] ?? '') === 'Imp Head'));

$sessionDeptAdalahTujuan = $isClaimMach && ($_SESSION['dept'] ?? '') !== '' && ($_SESSION['dept'] ?? '') === ($fpm['dept'] ?? '');
$sudahDiterimaDeptHead   = $isClaimMach && !empty($fpm['cm_dept_head_diterima_at']);

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

      <?php if (!$isClaimMach): ?>
      <button
        type="submit"
        name="terima"
        class="btn btn-primary btn-sm"
        <?php $bolehTerima = !$sudahDiterima && $sudahDiapproveImp && (!$isFlowQA || $sudahDibahasQa); ?>
        <?= !$bolehTerima ? 'disabled style="background-color:gray;border-color:gray;"' : '' ?>
        <?= $bolehTerima ? 'onclick="return confirm(\'' . ($isFlowQA ? 'Approve' : 'Terima') . ' FPM ini?\')"' : '' ?>>
        <?php
        if ($sudahDiterima) {
          echo $isFlowQA ? 'Sudah Approved' : 'Sudah Diterima';
        } elseif (!$sudahDiapproveImp) {
          echo $isFlowQA ? 'Menunggu Terima Staff QA' : 'Menunggu Approval IMP Head';
        } elseif ($isFlowQA && !$sudahDibahasQa) {
          echo 'Menunggu Pembahasan Staff QA';
        } else {
          echo $isFlowQA ? 'Approved' : 'Terima';
        }
        ?>
      </button>
      <?php endif; ?>

      <?php if (!$isClaimMach): ?>
      <button
        type="submit"
        name="approved"
        class="btn btn-success btn-sm"
        <?= !$bolehApproved ? 'disabled style="background-color:gray;border-color:gray;"' : '' ?>
        <?= $bolehApproved ? 'onclick="return confirm(\'Approved FPM ini?\')"' : '' ?>>
        <?= $sudahApproved ? 'Sudah Approved' : 'Approved' ?>
      </button>
      <?php endif; ?>

      <button
        type="button"
        class="btn btn-warning btn-sm"
        onclick="openRejectModal()">
        Revisi
      </button>

      <?php if (!$isClaimMach && !$isFlowQA): ?>
        <?php $sudahMintaPerbaikanData = !empty($fpm['minta_perbaikan_data_at']); ?>
        <button
          type="button"
          class="btn btn-outline-danger btn-sm"
          <?= (!$sudahDiapproveImp || $sudahDiterima || $sudahMintaPerbaikanData) ? 'disabled' : '' ?>
          onclick="openMintaPerbaikanDataModal()">
          <?= $sudahMintaPerbaikanData ? 'Menunggu Perbaikan Data' : 'Minta Perbaikan Data' ?>
        </button>
      <?php endif; ?>

    </form>

    <?php if ($isClaimMach): ?>
      <?php if ($sessionDeptAdalahTujuan && !$sudahDiterimaDeptHead): ?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">
          <button type="submit"
            name="terima_claim_mach"
            class="btn btn-primary btn-sm"
            onclick="return confirm('Terima FPM Claim Machining ini?')">
            Diterima
          </button>
        </form>
      <?php endif; ?>

      <?php if ($sessionDeptAdalahTujuan): ?>
        <a href="jawab_fpm_claim_machining.php?id=<?= $id_fpm ?>" class="btn btn-outline-primary btn-sm">Isi / Update Perbaikan</a>
      <?php endif; ?>

      <?php if ($sessionDeptAdalahTujuan && !empty($fpm['cm_dept_head_dijawab_at']) && empty($fpm['cm_dept_head_approved_at'])): ?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">
          <button type="submit"
            name="approve_dept_head_claim_mach"
            class="btn btn-success btn-sm"
            onclick="return confirm('Approve jawaban FPM Claim Machining ini dan teruskan ke Plant Head?')">
            Approve Jawaban
          </button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($isClaimMach && !empty($fpm['dept'])): ?>
    <div class="no-print" style="max-width:900px; margin:12px 0; font-size:12px; color:#374151;">
      <strong>Status alur Claim Machining:</strong>
      <?php
      $stepsClaimMachDeptHead = [
        'Plant Head Terima'  => !empty($fpm['cm_plant_head_diterima_at']),
        'Dept Head Terima'   => !empty($fpm['cm_dept_head_diterima_at']),
        'Dept Head Jawab'    => !empty($fpm['cm_dept_head_dijawab_at']),
        'Dept Head Approve'  => !empty($fpm['cm_dept_head_approved_at']),
        'Plant Head Approve' => !empty($fpm['cm_plant_head_approved_at']),
        'Directur Approve'   => !empty($fpm['cm_directur_approved_at']),
      ];
      foreach ($stepsClaimMachDeptHead as $label => $done):
      ?>
        <span style="display:inline-block; margin:2px 6px 2px 0; padding:2px 8px; border-radius:10px; font-weight:600; background:<?= $done ? '#f0fdf4' : '#fef2f2' ?>; color:<?= $done ? '#16a34a' : '#dc2626' ?>;">
          <?= $done ? '&#10003;' : '&#9675;' ?> <?= htmlspecialchars($label) ?>
        </span>
      <?php endforeach; ?>
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
        <?php // Untuk Claim Machining, DEPT ikut dept/PIC yang ditentukan Plant Head (fpm_header.dept),
        // bukan internal_dept yang selalu 'QA' - biar cetakan mencerminkan dept tujuan sebenarnya. ?>
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
              <?php // Untuk Claim Machining, tanggal ini dipindah ke kolom TGL TERBIT (dekat NO.REG. FPM),
              // tidak lagi ditampilkan di sel TGL tabel PENGIRIMAN SETELAH PERBAIKAN. ?>
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
            <td style="border-right: none"><?= htmlspecialchars($peng['User_disetujui'] ?? '') ?></td>
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

  <div id="mintaPerbaikanDataModal" class="no-print" style="display:none; position:fixed; top:0; left:0;
     width:100%; height:100%; background:rgba(0,0,0,0.5);">

    <div style="background:#fff; width:400px; padding:20px;
       margin:100px auto; border-radius:8px;">

      <h4>Minta Perbaikan Data FPM</h4>
      <p style="font-size:12px; color:#6b7280; margin-top:-8px;">
        Dipakai kalau data dasar FPM ini (part, mesin, jenis masalah, dst) salah -
        bukan untuk menolak jawaban countermeasure. Head IMP &amp; Staff IMP akan
        dapat notifikasi dan FPM ini kembali menunggu approval Head IMP.
      </p>

      <form method="post">
        <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">

        <label>Bagian Data yang Salah <span style="color:#dc2626">*</span></label>
        <textarea name="catatan_data" class="form-control"
          placeholder="Contoh: No Part salah, seharusnya 213111-12710..."
          required style="width:100%; height:100px;"></textarea>

        <br>

        <button type="submit" name="minta_perbaikan_data_submit"
          class="btn btn-primary btn-sm">
          Kirim
        </button>

        <button type="button"
          class="btn btn-secondary btn-sm"
          onclick="closeMintaPerbaikanDataModal()">
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

    function openMintaPerbaikanDataModal() {
      document.getElementById('mintaPerbaikanDataModal').style.display = 'block';
    }

    function closeMintaPerbaikanDataModal() {
      document.getElementById('mintaPerbaikanDataModal').style.display = 'none';
    }
  </script>

</body>

</html>