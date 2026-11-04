<?php
require_once __DIR__ . '/../config/auth_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';
mulaiSessionAman();
wajibLogin();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/ng_detail_box.php';

$id_fpm = (int) ($_GET['id'] ?? 0);

$qHeader  = mysqli_query($koneksi, "SELECT * FROM fpm_header WHERE id_fpm = '$id_fpm'");

$qDetail  = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm'");

$fpm      = mysqli_fetch_assoc($qHeader);

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

// =============================
// VERIFIKASI 3 BULAN (BERTAHAP, 1 BULAN SEKALI)
// =============================
// Tiap klik mengisi 1 baris bulan berikutnya yang masih kosong (Bulan 1 -> 2 -> 3).
// Bulan ke-i = bulan kalender ke-i setelah bulan Tgl Ditemukan Masalah, dan baru bisa
// diverifikasi mulai bulan tersebut. Begitu ditemukan problem yang sama (bulan 1/2/3)
// status evaluasi langsung OPEN; kalau sampai bulan 3 tidak ditemukan -> CLOSED.
// Saat status evaluasi terisi, kolom DIBUAT diisi user Staff IMP, lalu menunggu
// approval Head IMP (DIPERIKSA) dan Directure (DISETUJUI).
$kolomVerifikasi = [1 => 'verification_3_bulan', 2 => 'verification_3_bulan2', 3 => 'verification_3_bulan3'];

function bulanVerifikasiBerikutnya($detail, $kolomVerifikasi)
{
    foreach ($kolomVerifikasi as $i => $kolom) {
        if (trim($detail[$kolom] ?? '') === '') {
            return $i;
        }
    }
    return null;
}

function periodeBulanVerifikasi($tglDitemukan, $bulanKe)
{
    $d = new DateTime($tglDitemukan);
    $d->modify('first day of this month')->modify("+$bulanKe month");
    return $d;
}

if (isset($_POST['verifikasi_3_bulan']) && isset($_POST['id_fpm'])) {

    $id_fpm_post = (int) $_POST['id_fpm'];

    $qFpm = mysqli_query($koneksi, "SELECT * FROM fpm_header WHERE id_fpm = '$id_fpm_post'");
    $fpmNow = mysqli_fetch_assoc($qFpm);

    if (!$fpmNow) {
        die('Data tidak ditemukan');
    }

    if (empty($fpmNow['tgl_ditemukan'])) {
        die('Tanggal ditemukan kosong');
    }

    if (trim($fpmNow['status_evaluasi'] ?? '') !== '') {
        die('Verifikasi 3 bulan sudah selesai (status evaluasi: ' . htmlspecialchars($fpmNow['status_evaluasi']) . ')');
    }

    $qDet = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm_post'");
    $detNow = mysqli_fetch_assoc($qDet);

    if (!$detNow) {
        mysqli_query($koneksi, "INSERT INTO fpm_detail_masalah (id_fpm, pic, file) VALUES ('$id_fpm_post', '', '')");
        $detNow = [];
    }

    $bulanKe = bulanVerifikasiBerikutnya($detNow, $kolomVerifikasi);

    if ($bulanKe === null) {
        die('Verifikasi 3 bulan sudah lengkap');
    }

    $periode = periodeBulanVerifikasi($fpmNow['tgl_ditemukan'], $bulanKe);

    if (date('Y-m') < $periode->format('Y-m')) {
        echo "<script>
            alert('Verifikasi Bulan $bulanKe baru bisa dilakukan mulai bulan " . $periode->format('m-Y') . "');
            window.location='form_fpm_internal.php?id=$id_fpm_post';
          </script>";
        exit;
    }

    $bulan = $periode->format('m');
    $tahun = $periode->format('Y');

    $jenis_fpm     = mysqli_real_escape_string($koneksi, $fpmNow['jenis_fpm']);
    $nama_part     = mysqli_real_escape_string($koneksi, $fpmNow['nama_part']);
    $model         = mysqli_real_escape_string($koneksi, $fpmNow['model']);
    $machine_no    = mysqli_real_escape_string($koneksi, $fpmNow['machine_no']);
    $jenis_masalah = mysqli_real_escape_string($koneksi, $fpmNow['jenis_masalah']);

    $queryCek = "SELECT no_form
      FROM fpm_header
      WHERE id_fpm != '$id_fpm_post'
      AND MONTH(tgl_ditemukan) = '$bulan'
      AND YEAR(tgl_ditemukan) = '$tahun'
      AND jenis_fpm = '$jenis_fpm'
      AND nama_part = '$nama_part'
      AND model = '$model'
      AND machine_no = '$machine_no'
      AND jenis_masalah = '$jenis_masalah'
    ";

    $qCek = mysqli_query($koneksi, $queryCek);

    $noForms = [];
    while ($row = mysqli_fetch_assoc($qCek)) {
        $noForms[] = $row['no_form'];
    }

    $adaProblemSama = !empty($noForms);
    $hasil = $adaProblemSama
        ? "Terdapat Problem Yang Sama pada : " . implode(", ", $noForms)
        : "Tidak ada problem yang sama";

    $kolom  = $kolomVerifikasi[$bulanKe];
    $update = mysqli_query($koneksi, "UPDATE fpm_detail_masalah
        SET $kolom = '" . mysqli_real_escape_string($koneksi, $hasil) . "'
        WHERE id_fpm = '$id_fpm_post'
    ");

    if (!$update) {
        die('Gagal update verifikasi');
    }

    $statusEvaluasi = $adaProblemSama ? 'OPEN' : ($bulanKe === 3 ? 'CLOSED' : '');

    if ($statusEvaluasi !== '') {
        $tanggalHariIni = date('Y-m-d');
        $userStaff      = mysqli_real_escape_string($koneksi, $_SESSION['username']);

        mysqli_query($koneksi, "UPDATE fpm_header SET status_evaluasi = '$statusEvaluasi' WHERE id_fpm = '$id_fpm_post'");

        $cekEvaluasi = mysqli_query($koneksi, "SELECT id FROM fpm_evaluasi WHERE id_fpm = '$id_fpm_post'");

        if (mysqli_num_rows($cekEvaluasi) > 0) {
            mysqli_query($koneksi, "UPDATE fpm_evaluasi
                SET tgl = '$tanggalHariIni', dibuat = 'Staff IMP', user_dibuat = '$userStaff', tgl_dibuat = '$tanggalHariIni'
                WHERE id_fpm = '$id_fpm_post'");
        } else {
            mysqli_query($koneksi, "INSERT INTO fpm_evaluasi
                (id_fpm, tgl, dibuat, user_dibuat, tgl_dibuat,
                 diperiksa, user_diperiksa, diperiksa2, user_diperiksa2, disetujui, user_disetujui, tgl_disetujui)
                VALUES
                ('$id_fpm_post', '$tanggalHariIni', 'Staff IMP', '$userStaff', '$tanggalHariIni',
                 '', '', '', '', '', '', '')");
        }
    }

    $pesanAlert = "Verifikasi Bulan $bulanKe berhasil disimpan";
    if ($statusEvaluasi === 'OPEN') {
        $pesanAlert .= '. Ditemukan problem yang sama, status evaluasi OPEN';
    } elseif ($statusEvaluasi === 'CLOSED') {
        $pesanAlert .= '. Tidak ditemukan problem yang sama selama 3 bulan, status evaluasi CLOSED';
    }

    echo "<script>
            alert('$pesanAlert');
            window.location='form_fpm_internal.php?id=$id_fpm_post';
          </script>";
    exit;
}

$qCorrective  = mysqli_query($koneksi, "SELECT * FROM fpm_corrective_action WHERE id_fpm = '$id_fpm'");
$qPreventive  = mysqli_query($koneksi, "SELECT * FROM fpm_preventive_action WHERE id_fpm = '$id_fpm'");
$qReview      = mysqli_query($koneksi, "SELECT * FROM fpm_review_dokumen WHERE id_fpm = '$id_fpm'");
$qPengiriman  = mysqli_query($koneksi, "SELECT * FROM fpm_pengiriman WHERE id_fpm = '$id_fpm'");
$qSimilar     = mysqli_query($koneksi, "SELECT * FROM fpm_similar WHERE id_fpm = '$id_fpm'");
$qEvaluasi    = mysqli_query($koneksi, "SELECT * FROM fpm_evaluasi WHERE id_fpm = '$id_fpm'");
$qAnalisaOcc  = mysqli_query($koneksi, "SELECT * FROM fpm_analisa WHERE id_fpm = '$id_fpm' AND jenis = 'OCCURRENCE' ORDER BY id ASC");
$qAnalisaFlow = mysqli_query($koneksi, "SELECT * FROM fpm_analisa WHERE id_fpm = '$id_fpm' AND jenis = 'FLOW OUT' ORDER BY id ASC");

$correctiveData  = mysqli_fetch_all($qCorrective ?? false, MYSQLI_ASSOC) ?: [];
$preventiveData  = mysqli_fetch_all($qPreventive ?? false, MYSQLI_ASSOC) ?: [];
$reviewData      = mysqli_fetch_all($qReview ?? false, MYSQLI_ASSOC) ?: [];
$pengirimanData  = mysqli_fetch_all($qPengiriman ?? false, MYSQLI_ASSOC) ?: [];
$similarData     = mysqli_fetch_all($qSimilar ?? false, MYSQLI_ASSOC) ?: [];
$evaluasi        = $qEvaluasi ? (mysqli_fetch_assoc($qEvaluasi) ?: []) : [];
$analisaOccData  = mysqli_fetch_all($qAnalisaOcc ?? false, MYSQLI_ASSOC) ?: [];
$analisaFlowData = mysqli_fetch_all($qAnalisaFlow ?? false, MYSQLI_ASSOC) ?: [];

$peng = $pengirimanData[0] ?? [];
$btnDisabledVerif3Bulan = '';
$titleVerif3Bulan       = '';
$bulanVerifBerikutnya   = null;

$qDetailBtn = mysqli_query($koneksi, "SELECT * FROM fpm_detail_masalah WHERE id_fpm = '$id_fpm'");
$detailBtn  = $qDetailBtn ? (mysqli_fetch_assoc($qDetailBtn) ?: []) : [];

if (!(isset($peng['disetujui']) && trim($peng['disetujui']) === 'Directure')) {
    $btnDisabledVerif3Bulan = 'disabled';
    $titleVerif3Bulan       = 'Belum Approved Directure';
} elseif (trim($fpm['status_evaluasi'] ?? '') !== '') {
    $btnDisabledVerif3Bulan = 'disabled';
    $titleVerif3Bulan       = 'Verifikasi 3 bulan sudah selesai (' . $fpm['status_evaluasi'] . ')';
} elseif (empty($fpm['tgl_ditemukan'])) {
    $btnDisabledVerif3Bulan = 'disabled';
    $titleVerif3Bulan       = 'Tanggal ditemukan masalah kosong';
} else {
    $bulanVerifBerikutnya = bulanVerifikasiBerikutnya($detailBtn, $kolomVerifikasi);
    if ($bulanVerifBerikutnya !== null) {
        $periodeBtn = periodeBulanVerifikasi($fpm['tgl_ditemukan'], $bulanVerifBerikutnya);
        if (date('Y-m') < $periodeBtn->format('Y-m')) {
            $btnDisabledVerif3Bulan = 'disabled';
            $titleVerif3Bulan       = 'Verifikasi Bulan ' . $bulanVerifBerikutnya . ' baru bisa dilakukan mulai bulan ' . $periodeBtn->format('m-Y');
        }
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
    </style>
</head>

<body>

    <button type="button" onclick="history.back()" class="btn btn-secondary btn-sm">
        ⬅ Back
    </button>

    <div style="float:right">

        <form method="POST" style="display:inline;">
            <input type="hidden" name="id_fpm" value="<?= $id_fpm ?>">

            <button type="submit"
                name="verifikasi_3_bulan"
                class="btn btn-warning btn-sm"
                <?= $btnDisabledVerif3Bulan ?>
                title="<?= htmlspecialchars($titleVerif3Bulan) ?>">
                Verifikasi 3 Bulan<?= $bulanVerifBerikutnya !== null ? ' (Bulan ' . $bulanVerifBerikutnya . ')' : '' ?>
            </button>
        </form>

    </div>

    <br><br>

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
                        <td class="center small-text"
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
                        <td><?= v('user_disetujui', $fpm) ?></td>
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
                <input type="checkbox" <?= checkedIfNotEmpty($fpm['internal_dept'] ?? '') ?> /> INTERNAL <br />
                DEPT : <?= v('internal_dept', $fpm) ?>
            </td>
        </tr>

        <tr>
            <td colspan="3">NO. PART : <?= v('no_part', $fpm) ?></td>
            <td colspan="4">MODEL : <?= v('model', $fpm) ?></td>
            <td colspan="4">KEJADIAN BERULANG KE : <?= v('kejadian_berulang_ke', $fpm) ?></td>
            <td colspan="5">TGL DITEMUKAN MASALAH : <?= formatTanggal($fpm['tgl_ditemukan'] ?? '') ?></td>
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
            <td colspan="3" style="height:150px">
                <strong>DETAIL MASALAH :</strong><br><br>

                <?php
                $d = mysqli_fetch_assoc($qDetail);
                ?>

                <?php renderDetailMasalahBox($d); ?>
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
                            (!empty($peng['tgl']) && $peng['tgl'] != '0000-00-00')
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
                        <?= htmlspecialchars($c['due_date']) ?><br>
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
    </p><br>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>