<?php
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include '../koneksi.php';
require_once '../config/verifikasi_countermeasure_helper.php';
require_once '../config/countermeasure_revisi_helper.php';
require_once '../config/fpm_reschedule_helper.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

$username = $_SESSION['username'];
$plantFilter = resolveVerifikasiPlant($username, $_GET['plant'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tandai_revisi_dicek'])) {
    $hasil = tandaiRevisiDicekImp(
        $koneksi,
        ($_POST['sumber'] ?? '') === 'ASAKAI' ? 'ASAKAI' : 'FPM',
        (int) ($_POST['id_revisi'] ?? 0),
        $username
    );
    echo "<script>alert(" . json_encode($hasil['pesan']) . "); window.location='verifikasi_countermeasure.php?plant=" . urlencode($plantFilter) . "';</script>";
    exit;
}

// Reschedule due date dari Staff / Dept Head / Plant Head tidak masuk menu ini - cukup
// tercatat di History Reschedule halaman countermeasure, tidak perlu diverifikasi Staff IMP.
$revisiDiperbaikiRows = ambilRevisiPerluDicekImp($koneksi, $plantFilter);
$asakaiRows = fetchPendingAsakaiVerifikasi($koneksi, $plantFilter);
$fpmRows = fetchPendingFpmVerifikasi($koneksi, $plantFilter);

function formatTglVerifikasi($tgl)
{
    if (empty($tgl) || $tgl === '0000-00-00') return '';
    return date('d-m-Y', strtotime($tgl));
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Verifikasi Countermeasure</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Verifikasi Countermeasure" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --bg-color: #f8fafc;
            --text-color: #1e293b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
        }

        .container {
            width: 98%;
            max-width: 98vw;
            margin: 20px auto;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        h2 {
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .page-subtitle {
            text-align: center;
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 24px;
        }

        .filter-section {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 30px 0 14px;
        }

        .section-heading h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
            text-transform: uppercase;
        }

        .section-count {
            background: #fee2e2;
            color: #b91c1c;
            font-weight: 700;
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 999px;
        }

        .section-count.zero {
            background: #dcfce7;
            color: #166534;
        }

        .table-wrapper {
            max-height: 550px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #cbd5e1;
            margin-bottom: 20px;
        }

        table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            font-size: 13px;
            border: 1px solid #cbd5e1;
        }

        th, td {
            padding: 10px;
            border: 1px solid #e2e8f0;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background-color: #2c6ed7;
            color: white;
            font-weight: 600;
            text-align: center;
        }

        tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        tr:hover {
            background-color: #e2e8f0;
            transition: 0.2s;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
        }

        .status-open {
            background-color: #fef3c7;
            color: #967e08;
        }

        .status-on-progress {
            background-color: #dbeafe;
            color: #1d4ed8;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-verify {
            background-color: #eff6ff;
            color: var(--primary-color);
        }

        .btn-verify:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .empty-hint {
            text-align: center;
            padding: 24px;
            color: #6b7280;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <div class="container">

                <h2>Verifikasi Countermeasure</h2>
                <p class="page-subtitle">
                    Daftar perbaikan (ASAKAI &amp; FPM) yang sudah diupload buktinya oleh dept terkait,
                    namun statusnya belum diubah menjadi CLOSED.
                </p>

                <form method="GET" class="filter-section">
                    <div>
                        <label>Plant:</label>
                        <select name="plant" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <option value="">-- Semua Plant --</option>
                            <option value="1" <?= ($plantFilter == '1') ? 'selected' : '' ?>>Plant 1</option>
                            <option value="2" <?= ($plantFilter == '2') ? 'selected' : '' ?>>Plant 2</option>
                            <option value="3" <?= ($plantFilter == '3') ? 'selected' : '' ?>>Plant 3</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-verify" style="margin-top: 20px;">Filter</button>
                    <a href="verifikasi_countermeasure.php" class="btn" style="margin-top: 20px; background:#f1f5f9;">Reset</a>
                </form>

                <div class="section-heading">
                    <h3>Revisi Sudah Diperbaiki PIC</h3>
                    <span class="section-count <?= count($revisiDiperbaikiRows) === 0 ? 'zero' : '' ?>" style="<?= count($revisiDiperbaikiRows) > 0 ? 'background:#dbeafe; color:#1d4ed8;' : '' ?>">
                        <?= count($revisiDiperbaikiRows) ?> perlu dicek ulang
                    </span>
                </div>
                <p style="color:#6b7280; font-size:12.5px; margin:-6px 0 10px;">
                    Catatan revisi yang sudah ditindaklanjuti Dept Head / Staff PIC. Cek hasilnya, lalu CLOSED-kan countermeasure di halaman verifikasi
                    atau klik <b>Tandai sudah dicek</b> (mis. untuk catatan umum).
                </p>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Sumber</th>
                                <th>FPM / Asakai</th>
                                <th>Countermeasure</th>
                                <th>Catatan IMP</th>
                                <th>Tindak Lanjut PIC</th>
                                <th>File Sekarang</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($revisiDiperbaikiRows): ?>
                                <?php foreach ($revisiDiperbaikiRows as $r): ?>
                                    <?php
                                    $isAsakai = $r['sumber'] === 'ASAKAI';
                                    $cfgRev = cmRevisiConfig($r['sumber']);
                                    $labelRev = !empty($r['ref_table']) ? ($cfgRev['refs'][$r['ref_table']]['label'] ?? '') : 'Catatan umum';
                                    $linkRev = $isAsakai ? 'update_asakai.php?id=' . (int) $r['id_asakai'] : 'update_countermeasure.php?id=' . (int) $r['id_fpm'];
                                    ?>
                                    <tr>
                                        <td style="text-align:center;"><?= $isAsakai ? 'ASAKAI' : 'FPM ' . htmlspecialchars($r['jenis_fpm'] ?? '') ?></td>
                                        <td>
                                            <?php if ($isAsakai): ?>
                                                <?= htmlspecialchars($r['tanggal'] ?? '') ?><br><small><?= htmlspecialchars(($r['machine'] ?? '') . ' - ' . ($r['namapart'] ?? '')) ?></small>
                                            <?php else: ?>
                                                <?= htmlspecialchars(($r['no_reg_fpm'] ?? '') ?: ($r['no_form'] ?? '')) ?><br><small><?= htmlspecialchars($r['nama_part'] ?? '') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><b><?= htmlspecialchars($labelRev) ?></b><br><?= nl2br(htmlspecialchars($r['item_deskripsi'] ?? '')) ?><br><small>PIC: <?= htmlspecialchars($r['dept_tujuan']) ?></small></td>
                                        <td><?= nl2br(htmlspecialchars($r['catatan'])) ?></td>
                                        <td>
                                            <?= !empty($r['tanggapan_pic']) ? nl2br(htmlspecialchars($r['tanggapan_pic'])) : '<i>Upload ulang file</i>' ?>
                                            <?php if (!empty($r['selesai_at'])): ?>
                                                <br><small style="color:#6b7280;"><?= htmlspecialchars($r['selesai_oleh'] ?? '') ?> &middot; <?= date('d-m-Y H:i', strtotime($r['selesai_at'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= !empty($r['ref_table']) ? cmRevisiRenderFiles($r['sumber'], $r['files'], 48) : '-' ?></td>
                                        <td style="text-align:center; white-space:nowrap;">
                                            <a href="<?= $linkRev ?>" class="btn btn-verify">Verifikasi</a>
                                            <form method="POST" style="margin-top:6px;" onsubmit="return confirm('Tandai revisi ini sudah dicek?');">
                                                <input type="hidden" name="sumber" value="<?= $r['sumber'] ?>">
                                                <input type="hidden" name="id_revisi" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" name="tandai_revisi_dicek" value="1" class="btn" style="background:#dcfce7; color:#166534;">Tandai sudah dicek</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-hint">Tidak ada revisi yang menunggu dicek.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section-heading">
                    <h3>ASAKAI</h3>
                    <span class="section-count <?= count($asakaiRows) === 0 ? 'zero' : '' ?>">
                        <?= count($asakaiRows) ?> perlu diverifikasi
                    </span>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Plant</th>
                                <th>Mesin</th>
                                <th>Nama Part</th>
                                <th>Kategori</th>
                                <th>Countermeasure</th>
                                <th>PIC</th>
                                <th>Due Date</th>
                                <th>Actual</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($asakaiRows) > 0): ?>
                                <?php foreach ($asakaiRows as $r): ?>
                                    <tr>
                                        <td><?= formatTglVerifikasi($r['tanggal']) ?></td>
                                        <td><?= htmlspecialchars($r['plant']) ?></td>
                                        <td><?= htmlspecialchars($r['machine']) ?></td>
                                        <td><?= htmlspecialchars($r['namapart']) ?></td>
                                        <td><?= $r['kategori'] === 'DOWN_TIME' ? 'DOWN TIME' : 'NG' ?></td>
                                        <td><?= nl2br(htmlspecialchars($r['deskripsi'])) ?></td>
                                        <td><?= htmlspecialchars($r['pic'] ?? '') ?></td>
                                        <td><?= formatTglVerifikasi($r['due_date']) ?></td>
                                        <td><?= formatTglVerifikasi($r['actual_date']) ?></td>
                                        <td>
                                            <span class="status-badge status-open"><?= htmlspecialchars($r['status'] ?? 'OPEN') ?></span>
                                        </td>
                                        <td>
                                            <a href="update_asakai.php?id=<?= (int) $r['id_asakai'] ?>" class="btn btn-verify">Verifikasi</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="empty-hint">Tidak ada countermeasure ASAKAI yang perlu diverifikasi.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section-heading">
                    <h3>FPM</h3>
                    <span class="section-count <?= count($fpmRows) === 0 ? 'zero' : '' ?>">
                        <?= count($fpmRows) ?> perlu diverifikasi
                    </span>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>No Form</th>
                                <th>Plant</th>
                                <th>Jenis FPM</th>
                                <th>Nama Part</th>
                                <th>Mesin</th>
                                <th>Jenis Aksi</th>
                                <th>Tindakan / Dokumen</th>
                                <th>PIC</th>
                                <th>Due Date</th>
                                <th>Actual</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($fpmRows) > 0): ?>
                                <?php foreach ($fpmRows as $r): ?>
                                    <?php $statusClass = strtoupper((string) $r['status']) === 'ON PROGRESS' ? 'status-on-progress' : 'status-open'; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['no_form'] ?? $r['no_reg_fpm'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['plant']) ?></td>
                                        <td><?= htmlspecialchars($r['jenis_fpm']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_part']) ?></td>
                                        <td><?= htmlspecialchars($r['machine_no']) ?></td>
                                        <td><?= htmlspecialchars($r['jenis_aksi']) ?></td>
                                        <td><?= nl2br(htmlspecialchars($r['deskripsi'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($r['pic'] ?? '') ?></td>
                                        <td><?= formatTglVerifikasi($r['due_date']) ?></td>
                                        <td><?= formatTglVerifikasi($r['actual_date']) ?></td>
                                        <td>
                                            <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($r['status'] ?? 'OPEN') ?></span>
                                        </td>
                                        <td>
                                            <a href="update_countermeasure.php?id=<?= (int) $r['id_fpm'] ?>" class="btn btn-verify">Verifikasi</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="empty-hint">Tidak ada countermeasure FPM yang perlu diverifikasi.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../../dist/js/adminlte.js"></script>

    <script>
        const SELECTOR_SIDEBAR_WRAPPER = ".sidebar-wrapper";
        const Default = {
            scrollbarTheme: "os-theme-light",
            scrollbarAutoHide: "leave",
            scrollbarClickScroll: true,
        };
        document.addEventListener("DOMContentLoaded", function() {
            const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
            if (
                sidebarWrapper &&
                typeof OverlayScrollbarsGlobal?.OverlayScrollbars !== "undefined"
            ) {
                OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                    scrollbars: {
                        theme: Default.scrollbarTheme,
                        autoHide: Default.scrollbarAutoHide,
                        clickScroll: Default.scrollbarClickScroll,
                    },
                });
            }
        });
    </script>

</body>

</html>
