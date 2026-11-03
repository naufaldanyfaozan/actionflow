<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

include '../koneksi.php';

if (!isset($koneksi)) {
    die("Variabel koneksi tidak ada");
}

if (isset($_POST['nama_part'])) {

    $tanggal = $_POST['tanggal'];

    $nama_part          = $_POST['nama_part'];
    $part_number        = $_POST['part_number'];
    $act_stock          = $_POST['act_stock'];
    $delivery_per_hari  = $_POST['delivery_per_hari'];
    $sisa_stock         = $_POST['sisa_stock'];
    $news_delivery      = $_POST['news_delivery'];

    $berhasil = true;

    for ($i = 0; $i < count($nama_part); $i++) {

        $nama = mysqli_real_escape_string($koneksi, $nama_part[$i]);
        $part = mysqli_real_escape_string($koneksi, $part_number[$i]);
        $stock = (int)$act_stock[$i];
        $delivery = (int)$delivery_per_hari[$i];
        $sisa = (int)$sisa_stock[$i];
        $news = mysqli_real_escape_string($koneksi, $news_delivery[$i]);

        $sql = "INSERT INTO fpm_delivery_monitor
                (
                    tanggal,
                    part_number,
                    nama_part,
                    stock_casting,
                    act_stock,
                    delivery_per_hari,
                    sisa_stock,
                    news_delivery,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    '$tanggal',
                    '$part',
                    '$nama',
                    '0',
                    '$stock',
                    '$delivery',
                    '$sisa',
                    '$news',
                    NOW(),
                    NOW()
                )";

        if (!mysqli_query($koneksi, $sql)) {
            $berhasil = false;
        }
    }

    if ($berhasil) {
        echo "
        <script>
            alert('Data berhasil disimpan');
            window.location='';
        </script>";
    } else {
        echo "
        <script>
            alert('Gagal menyimpan data');
        </script>";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Info</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="title" content="AdminLTE v4 | Dashboard" />
    <meta name="author" content="ColorlibHQ" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" />

    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <!-- bootstrap.min.css terpisah ini dihapus dari sini - adminlte.css sudah membundel
         Bootstrap 5 lengkap (grid, card, dst), jadi file ini cuma duplikat dari versi
         bootstrap yang beda (5.3.0 vs 5.3.2) dan menimpa beberapa style dasar AdminLTE
         (mis. .nav-link) karena urutannya dimuat belakangan - salah satu dugaan penyebab
         submenu sidebar (.nav-treeview) di halaman dashboard kadang tidak merespon. -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        .card {
            border-radius: 16px;
        }

        .form-control,
        .input-group-text {
            border-radius: 10px;
        }

        .form-control {
            height: 45px;
        }

        textarea.form-control {
            height: auto;
        }

        .form-label {
            font-weight: 600;
            color: #495057;
        }

        .btn-primary {
            border-radius: 10px;
            font-weight: 600;
        }

        hr {
            opacity: .1;
        }

        .autocomplete-box {
            position: sticky;
            overflow: visible !important;
            background: #fff;
            border: 1px solid #ddd;

            width: 1000px;
            max-height: 600px;

            overflow-y: auto;
            overflow-x: auto;

            z-index: 9999;
            display: none;

            box-shadow: 0 5px 20px rgba(0, 0, 0, .20);
        }

        .autocomplete-table {
            width: 100%;
            margin: 0;
        }

        .autocomplete-table th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8f9fa;
        }

        .autocomplete-table tr {
            cursor: pointer;
        }

        .autocomplete-table tr:hover {
            background: #e9f3ff;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div class="app-wrapper">

        <?php include '../navbar.php'; ?>
        <?php include 'sidebar.php'; ?>

        <main class="app-main p-4">

            <form id="formDelivery" method="POST">

                <div class="card shadow-sm border-0">

                    <div class="card-header bg-white">

                        <div class="row">

                            <div class="col-md-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date"
                                    name="tanggal"
                                    class="form-control"
                                    value="<?= date('Y-m-d') ?>">
                            </div>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="table-responsive">

                            <table class="table table-hover align-middle" id="tblPart">

                                <thead class="table-light">

                                    <tr>
                                        <th width="50">No</th>
                                        <th width="250">Nama Part</th>
                                        <th width="180">Part Number</th>
                                        <th width="120">Stock FG</th>
                                        <th width="120">Delivery/Hari</th>
                                        <th width="120">Sisa Stock (Delivery)</th>
                                        <th>News Delivery</th>
                                        <th width="70"></th>
                                    </tr>

                                </thead>

                                <tbody id="bodyPart"></tbody>

                            </table>

                            <div class="d-flex justify-content-between mt-3">

                                <button type="button"
                                    class="btn btn-success"
                                    id="btnTambah">

                                    <i class="bi bi-plus-lg"></i>
                                    Tambah Part

                                </button>

                                <button type="submit"
                                    class="btn btn-primary">

                                    <i class="bi bi-save"></i>
                                    Simpan Semua

                                </button>

                            </div>

                        </div>
                    </div>
                </div>

            </form>

        </main>

    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let no = 1;

        function tambahBaris() {

            $('#bodyPart').append(`
<tr>

<td class="nomor">${no++}</td>

<td style="position:relative">

    <input type="text"
           name="nama_part[]"
           class="form-control namaPart">

    <div class="autocomplete-box">

        <table class="table table-sm autocomplete-table">

            <thead>
                <tr>
                    <th>Part Number</th>
                    <th>Nama Part</th>
                    <th>Type</th>
                </tr>
            </thead>

            <tbody></tbody>

        </table>

    </div>

</td>

<td>
    <input type="text"
           name="part_number[]"
           class="form-control partNumber"
           readonly>
</td>

<td><input type="number" name="act_stock[]" class="form-control stock"></td>
<td><input type="number" name="delivery_per_hari[]" class="form-control delivery"></td>
<td><input type="text" name="sisa_stock[]" class="form-control sisa" readonly></td>
<td><input type="text" name="news_delivery[]" class="form-control"></td>

<td>
<button type="button"
        class="btn btn-danger btn-sm btnHapus">
        <i class="bi bi-trash"></i>
</button>
</td>

</tr>
`);
        }

        $(document).ready(function() {

            tambahBaris();

            $(document).on('click', '#btnTambah', function() {
                tambahBaris();
            });

            $(document).on('click', '.btnHapus', function() {
                $(this).closest('tr').remove();
            });

        });
    </script>

    <script>
        $(document).on('keyup change', '.stock, .delivery', function() {

            let row = $(this).closest('tr');

            let stock = parseFloat(row.find('.stock').val()) || 0;
            let delivery = parseFloat(row.find('.delivery').val()) || 0;

            let sisa = 0;

            if (delivery > 0) {
                sisa = (stock / delivery).toFixed(1);
            }

            row.find('.sisa').val(sisa);
        });
    </script>

    <script>
        $(document).on('keyup', '.namaPart', function() {

            let keyword = $(this).val();

            let td = $(this).closest('td');

            let box = td.find('.autocomplete-box');

            let tbody = box.find('tbody');

            if (keyword.length < 2) {
                box.hide();
                return;
            }

            $.ajax({
                url: 'ajax_part.php',
                type: 'GET',
                data: {
                    keyword: keyword
                },
                dataType: 'json',

                success: function(res) {

                    let html = '';

                    $.each(res, function(i, row) {

                        html += `
                <tr class="pilihPart"
                    data-partnumber="${row.partnumber}"
                    data-namapart="${row.namapart}">
                    
                    <td>${row.partnumber}</td>
                    <td>${row.namapart}</td>
                    <td>${row.type}</td>

                </tr>`;
                    });

                    tbody.html(html);

                    if (res.length > 0) {
                        box.show();
                    } else {
                        box.hide();
                    }
                }
            });

        });



        $(document).on('click', '.pilihPart', function() {

            let partnumber = $(this).data('partnumber');

            let namapart = $(this).data('namapart');

            let td = $(this).closest('td');

            td.find('.namaPart').val(namapart);

            td.closest('tr')
                .find('.partNumber')
                .val(partnumber);

            td.find('.autocomplete-box').hide();

        });



        $(document).click(function(e) {

            if (!$(e.target).closest('.namaPart, .autocomplete-box').length) {
                $('.autocomplete-box').hide();
            }

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

</body>

</html>