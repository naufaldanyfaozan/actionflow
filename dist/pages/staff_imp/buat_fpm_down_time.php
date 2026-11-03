<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include __DIR__ . '/../koneksi.php';

$queryNamaPart = mysqli_query(
    $koneksi,
    "SELECT namapart, type, partnumber FROM master_part ORDER BY namapart ASC"
);

$username = $_SESSION['username'] ?? '';
$plantUser = 0;

if ($username === 'doni') {
    $plantUser = 1;
} elseif ($username === 'aji') {
    $plantUser = 3;
}

$queryMachine = mysqli_query(
    $koneksi,
    "SELECT nama_mesin FROM master_machine WHERE plant = '$plantUser'
     ORDER BY nama_mesin ASC"
);

$queryDept = mysqli_query(
    $koneksi,
    "SELECT nama_dept 
     FROM master_dept 
     WHERE 
        plant = '$plantUser'
        OR plant IS NULL
        OR plant = ''
     ORDER BY nama_dept ASC"
);

$queryJenisMasalah = mysqli_query(
    $koneksi,
    "SELECT masalah FROM jenis_masalah ORDER BY masalah ASC"
);

$bulan = date('n');
$tahun = date('Y');

function bulanRomawi($bulan)
{
    $romawi = [
        1 => 'I',
        2 => 'II',
        3 => 'III',
        4 => 'IV',
        5 => 'V',
        6 => 'VI',
        7 => 'VII',
        8 => 'VIII',
        9 => 'IX',
        10 => 'X',
        11 => 'XI',
        12 => 'XII'
    ];
    return $romawi[$bulan];
}

$bulanRomawi = bulanRomawi($bulan);

$qNo = mysqli_query($koneksi, "SELECT no_form 
    FROM fpm_header WHERE no_form LIKE 'FPM-D/%/$bulanRomawi/$tahun'
    ORDER BY no_form DESC LIMIT 1
");

$urut = 1;

if ($row = mysqli_fetch_assoc($qNo)) {
    $lastNo = explode('/', $row['no_form']);
    $urut = (int)$lastNo[1] + 1;
}

$urutFormat = str_pad($urut, 2, '0', STR_PAD_LEFT);

$noFormAuto = "FPM-D/$urutFormat/$bulanRomawi/$tahun";
?>

<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Create FPM Down Time</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="Create FPM Down Time" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: #f4f6f9;
        }

        .form-container {
            max-width: 980px;
            margin: 30px auto;
        }

        .form-card {
            background: #fff;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .form-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .form-header h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: #1f2937;
        }

        .form-header span {
            font-size: 13px;
            color: #6b7280;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select {
            height: 40px;
            padding: 8px 12px;
            font-size: 14px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #fff;
            transition: all .2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
            outline: none;
        }

        .readonly {
            background: #f3f4f6;
            color: #374151;
            font-weight: 500;
        }

        .form-footer {
            margin-top: 30px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn-primary {
            background: #2563eb;
            border: none;
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        @media(max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main">

            <form method="post" action="proses_down_time.php" enctype="multipart/form-data">
                <div class="form-container">
                    <div class="form-card">

                        <div class="form-header">
                            <h2>Create Blank FPM</h2>
                        </div>

                        <div class="form-grid">

                            <div class="form-group">
                                <label>No Reg FPM</label>
                                <input type="text"
                                    class="readonly"
                                    name="no_form"
                                    id="no_form"
                                    value="<?= htmlspecialchars($noFormAuto); ?>"
                                    readonly>
                            </div>

                            <div class="form-group">
                                <label>Jenis FPM</label>
                                <input type="text"
                                    class="readonly"
                                    name="jenis_fpm"
                                    id="jenis_fpm"
                                    value="Down Time"
                                    readonly>
                            </div>

                            <div class="form-group">
                                <label>Nama Part <span style="color:#dc2626">*</span></label>
                                <select name="nama_part" id="nama_part" class="form-control" required>
                                    <option value="">-- Pilih --</option>

                                    <?php while ($row = mysqli_fetch_assoc($queryNamaPart)) { ?>
                                        <option
                                            value="<?= htmlspecialchars($row['namapart']); ?>"
                                            data-type="<?= htmlspecialchars($row['type']); ?>"
                                            data-partnumber="<?= htmlspecialchars($row['partnumber']); ?>">
                                            <?= htmlspecialchars($row['namapart'] . ' - ' . $row['type']); ?>
                                        </option>
                                    <?php } ?>

                                </select>
                            </div>

                            <div class="form-group">
                                <label>No Part</label>
                                <input type="text" class="readonly" name="no_part" id="no_part" readonly placeholder="Auto">
                            </div>

                            <div class="form-group">
                                <label>Model / Type</label>
                                <input type="text" class="readonly" name="model" id="model_type" readonly placeholder="Auto">
                            </div>

                            <div class="form-group">
                                <label>Mesin <span style="color:#dc2626">*</span></label>
                                <select name="machine" class="form-control" required>
                                    <option value="">-- Pilih --</option>

                                    <?php while ($m = mysqli_fetch_assoc($queryMachine)) { ?>
                                        <option value="<?= htmlspecialchars($m['nama_mesin']); ?>">
                                            <?= htmlspecialchars($m['nama_mesin']); ?>
                                        </option>
                                    <?php } ?>

                                </select>
                            </div>

                            <div class="form-group">
                                <label>Mold #</label>
                                <input type="text" name="mold_no">
                            </div>

                            <div class="form-group">
                                <label>Jenis Masalah <span style="color:#dc2626">*</span></label>
                                <select name="jenis_masalah" class="form-control" required>
                                    <option value="">-- Pilih --</option>

                                    <?php while ($jm = mysqli_fetch_assoc($queryJenisMasalah)) { ?>
                                        <option value="<?= htmlspecialchars($jm['masalah']); ?>">
                                            <?= htmlspecialchars($jm['masalah']); ?>
                                        </option>
                                    <?php } ?>

                                </select>
                            </div>

                            <div class="form-group">
                                <label>Detail Jenis Masalah</label>
                                <input type="text" name="jenis_masalah_detail" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>Kejadian Berulang Ke</label>
                                <div style="display:flex; gap:8px;">
                                    <input type="number"
                                        name="kejadian_berulang_ke"
                                        id="kejadian_berulang_ke"
                                        class="readonly" placeholder="Auto"
                                        readonly>
                                    <button type="button"
                                        id="btnCekBerulang"
                                        class="btn btn-warning"
                                        style="height:40px;">
                                        Cek
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Shift</label>
                                <select name="shift">
                                    <option value="1">Shift 1</option>
                                    <option value="2">Shift 2</option>
                                    <option value="3">Shift 3</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Total Lost Time</label>
                                <input type="text" name="total_lost_time">
                            </div>

                            <div class="form-group">
                                <label>Dept <span style="color:#dc2626">*</span></label>
                                <select name="dept" class="form-control" required>
                                    <option value="">-- Pilih --</option>

                                    <?php while ($d = mysqli_fetch_assoc($queryDept)) { ?>
                                        <option value="<?= htmlspecialchars($d['nama_dept']); ?>">
                                            <?= htmlspecialchars($d['nama_dept']); ?>
                                        </option>
                                    <?php } ?>

                                </select>
                            </div>

                            <div class="form-group">
                                <label>Tanggal Masalah</label>
                                <input type="date"
                                    name="tgl_ditemukan"
                                    class="datepicker-manual"
                                    placeholder="dd-mm-yyyy">
                            </div>

                            <div class="form-group">
                                <label>Tanggal Pembuatan FPM</label>
                                <input type="date"
                                    name="tgl_pembuatan"
                                    class="datepicker-auto"
                                    placeholder="dd-mm-yyyy">
                            </div>

                            <div class="form-group">
                                <label>Dokumen / Foto</label>
                                <input type="file"
                                    name="dokumen_ng[]"
                                    id="dokumen_ng"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                    multiple>

                                <small style="color:#6b7280; margin-top:4px;">
                                    Format: JPG, JPEG, PNG (maksimal 3 file)
                                </small>

                                <div id="preview_dokumen"
                                    style="display:none; margin-top:10px; gap:8px; flex-wrap:wrap;"></div>
                            </div>

                        </div>

                        <div class="form-footer">
                            <button class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Dibuat
                            </button>
                        </div>

                    </div>
                </div>
            </form>

        </main>

        <?php include '../footer.php'; ?>

    </div>

    <script>
        let lastTanggalSebelumnya = null;
        let sudahCek = false;

        document.getElementById("btnCekBerulang").addEventListener("click", function() {

            const jenis_fpm = document.getElementById("jenis_fpm").value;
            const nama_part = document.getElementById("nama_part").value;
            const machine = document.querySelector("select[name='machine']").value;
            const mold_no = document.querySelector("input[name='mold_no']").value;
            const jenis_masalah = document.querySelector("select[name='jenis_masalah']").value;

            if (!nama_part || !machine || !jenis_masalah) {
                alert("Lengkapi data terlebih dahulu");
                return;
            }

            fetch("cek_kejadian_berulang.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: new URLSearchParams({
                        jenis_fpm,
                        nama_part,
                        machine,
                        mold_no,
                        jenis_masalah
                    })
                })
                .then(response => response.json())
                .then(data => {

                    document.getElementById("kejadian_berulang_ke").value = data.next;
                    lastTanggalSebelumnya = data.last_date;
                    sudahCek = true;

                    if (data.last_no_reg_fpm) {
                        alert("Kejadian sebelumnya pada No Reg FPM: " + data.last_no_reg_fpm);
                    } else {
                        alert("Ini kejadian pertama");
                    }
                });

        });

        // VALIDASI SEBELUM SUBMIT
        document.querySelector("form").addEventListener("submit", function(e) {

            const tglInput = document.querySelector("input[name='tgl_ditemukan']").value;

            if (!sudahCek) {
                alert("Silakan klik tombol CEK terlebih dahulu!");
                e.preventDefault();
                return false;
            }

            // ==============================
            // JIKA KEJADIAN PERTAMA
            // ==============================
            if (!lastTanggalSebelumnya) {
                // Tidak perlu cek tanggal sebelumnya
                return true;
            }

            // ==============================
            // JIKA KEJADIAN KE-2 DAN SETERUSNYA
            // ==============================

            if (!tglInput) {
                alert("Tanggal masalah wajib diisi!");
                e.preventDefault();
                return false;
            }

            // format flatpickr dd-mm-yyyy
            const parts = tglInput.split("-");
            const tglBaru = new Date(parts[2], parts[1] - 1, parts[0]);

            const tglLama = new Date(lastTanggalSebelumnya);

            if (tglBaru <= tglLama) {
                alert("❌ Tanggal masalah tidak boleh lebih kecil atau sama dengan kejadian sebelumnya!");
                e.preventDefault();
                return false;
            }

        });
    </script>

    <script>
        document.getElementById('nama_part').addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];

            const type = selected.getAttribute('data-type') || '';
            const partnumber = selected.getAttribute('data-partnumber') || '';

            document.getElementById('model_type').value = type;
            document.getElementById('no_part').value = partnumber;
        });
    </script>

    <script>
        document.getElementById('dokumen_ng').addEventListener('change', function() {
            const MAX_FILE_NG = 3;
            const preview = document.getElementById('preview_dokumen');

            if (this.files.length > MAX_FILE_NG) {
                alert('Dokumen / Foto maksimal ' + MAX_FILE_NG + ' file.');
                this.value = '';
            }

            preview.innerHTML = '';

            Array.from(this.files).forEach(function(file) {
                const img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.style.cssText = 'max-width:200px; border-radius:8px;';
                preview.appendChild(img);
            });

            preview.style.display = this.files.length ? 'flex' : 'none';
        });
    </script>

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

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        const connectedSortables =
            document.querySelectorAll(".connectedSortable");
        connectedSortables.forEach((connectedSortable) => {
            let sortable = new Sortable(connectedSortable, {
                group: "shared",
                handle: ".card-header",
            });
        });

        const cardHeaders = document.querySelectorAll(
            ".connectedSortable .card-header",
        );
        cardHeaders.forEach((cardHeader) => {
            cardHeader.style.cursor = "move";
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js"></script>

    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function() {

            // Tanggal Masalah → TIDAK auto isi
            flatpickr(".datepicker-manual", {
                dateFormat: "d-m-Y",
                allowInput: true
            });

            // Tanggal Pembuatan FPM → Auto hari ini
            flatpickr(".datepicker-auto", {
                dateFormat: "d-m-Y",
                defaultDate: "today",
                allowInput: true
            });

        });
    </script>

</body>

</html>