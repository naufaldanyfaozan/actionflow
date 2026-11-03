<?php
// Jenis Masalah di form pembuatan FPM: dropdown yang bisa diketik untuk mencari (Tom Select),
// tapi HANYA bisa memilih yang ada di master `jenis_masalah`. Masalah baru wajib ditambah
// dulu lewat Master Database > Master Problem - tidak bisa diketik bebas dari form FPM.
// Dipakai semua form pembuatan FPM (Down Time, NG Internal, QA Internal, Claim Machining).

/**
 * Cek di server bahwa jenis masalah yang dikirim form ada di master (form bisa saja
 * dikirim tanpa lewat dropdown). Kosong dianggap tidak valid karena field ini wajib.
 */
function jenisMasalahTerdaftar(mysqli $koneksi, string $masalah): bool
{
    if (trim($masalah) === '') {
        return false;
    }
    $stmt = mysqli_prepare($koneksi, "SELECT 1 FROM jenis_masalah WHERE masalah = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $masalah);
    mysqli_stmt_execute($stmt);
    return (bool) mysqli_fetch_row(mysqli_stmt_get_result($stmt));
}

/** Pesan error kalau jenis masalah tidak ada di master (dipakai handler proses_*.php). */
function pesanJenisMasalahTidakTerdaftar(string $masalah): string
{
    return trim($masalah) === ''
        ? 'Jenis Masalah wajib dipilih.'
        : "Jenis Masalah \"$masalah\" belum ada di master. Tambahkan dulu lewat menu Master Database > Master Problem.";
}

/**
 * CSS + JS Tom Select untuk semua <select name="jenis_masalah"> di halaman. Panggil SEKALI
 * setelah form (mis. sebelum </body>). Nilai <select> aslinya tetap sinkron, jadi script
 * lain (tombol Cek kejadian berulang) tetap membaca select[name='jenis_masalah'].value.
 */
function pilihJenisMasalahAset(): void
{
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
    <style>
        /* Samakan dengan input lain di form FPM (tinggi 40px, sudut 8px). */
        .form-group .ts-wrapper.single .ts-control {
            min-height: 40px;
            padding: 8px 12px;
            font-size: 14px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            box-shadow: none;
        }

        .form-group .ts-wrapper.focus .ts-control {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
        }

        .form-group .ts-wrapper.single .ts-control input {
            height: auto;
            padding: 0;
            border: 0;
            font-size: 14px;
        }

        .form-group .ts-dropdown {
            font-size: 14px;
            border-radius: 8px;
        }

        .form-group .ts-dropdown .no-results {
            padding: 10px 12px;
            color: #b91c1c;
            white-space: normal;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script>
        document.querySelectorAll("select[name='jenis_masalah']").forEach(function(el) {
            new TomSelect(el, {
                create: false,
                maxOptions: null,
                placeholder: "Ketik untuk mencari jenis masalah...",
                render: {
                    no_results: function(data, escape) {
                        return '<div class="no-results">Jenis masalah "<b>' + escape(data.input) + '</b>" belum ada di master.<br>' +
                            'Tambahkan dulu lewat menu <b>Master Database &rsaquo; Master Problem</b>.</div>';
                    }
                }
            });
        });
    </script>
    <?php
}
