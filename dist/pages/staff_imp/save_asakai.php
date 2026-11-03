<?php
session_start();
include '../koneksi.php';

if (isset($_POST['save'])) {

    $total = count($_POST['tanggal']);

    // Tanggal problem harus diisi dan sebelum tanggal asakai. Kalau salah ketik
    // (mis. tanggal di depan), data tidak akan muncul di Pembahasan Asakai.
    for ($i = 0; $i < $total; $i++) {
        $tanggal = $_POST['tanggal'][$i];
        if ($tanggal == '') continue;

        $tanggal_problem = $_POST['tanggal_problem'][$i] ?? '';
        if ($tanggal_problem == '' || $tanggal_problem >= $tanggal) {
            $baris = $i + 1;
            echo "<script>
                alert('Baris ke-$baris: Tanggal Problem wajib diisi dan harus sebelum Tanggal Asakai.');
                history.back();
            </script>";
            exit;
        }
    }

    for ($i = 0; $i < $total; $i++) {

        $tanggal = $_POST['tanggal'][$i];
        if ($tanggal == '') continue;

        $tanggal_problem    = $_POST['tanggal_problem'][$i];
        $plant              = $_POST['plant'][$i];
        $machine            = $_POST['machine'][$i];
        $namapart           = $_POST['namapart'][$i];
        $partnumber         = $_POST['partnumber'][$i];
        $type               = $_POST['type'][$i];
        $mold_no            = $_POST['mold_no'][$i];
        $target             = $_POST['target'][$i];
        $ok                 = $_POST['ok_qty'][$i];
        $ng                 = $_POST['ng_qty'][$i];
        $downtime           = $_POST['downtime'][$i];
        $std                = $_POST['standard'][$i];
        $act                = $_POST['actual'][$i];
        $eff                = $_POST['efficiency'][$i];
        $ct_ng              = $_POST['ct_ng'][$i];

        $rowUid             = $_POST['row_uid'][$i] ?? $i;

        $dtList             = array_values(array_filter(array_map('trim', $_POST['downtime_problem'][$rowUid] ?? []), fn($v) => $v !== ''));
        $dt_problem         = mysqli_real_escape_string($koneksi, implode("\n", $dtList));

        $ngList             = array_values(array_filter(array_map('trim', $_POST['ng_problem'][$rowUid] ?? []), fn($v) => $v !== ''));
        $ng_problem         = $ngList[0] ?? '';

        $urutan = (int) preg_replace('/[^0-9]/', '', $machine);

        mysqli_query($koneksi, "INSERT INTO asakai_header
        (tanggal,tanggal_problem,plant,machine,namapart,partnumber,type,mold_no,target,ok_qty,ng_qty,downtime,standard,actual,efficiency,persen_ng,downtime_problem,ng_problem,urutan)
        VALUES
        ('$tanggal','$tanggal_problem','$plant','$machine','$namapart','$partnumber','$type','$mold_no','$target','$ok','$ng','$downtime','$std','$act','$eff','$ct_ng','$dt_problem','$ng_problem','$urutan')
        ");

        $id_asakai = mysqli_insert_id($koneksi);

        mysqli_query($koneksi, "INSERT INTO asakai_analisa (id_asakai,kategori)
        VALUES ('$id_asakai','DOWN_TIME'),
               ('$id_asakai','NG')");

        for ($j = 1; $j < count($ngList); $j++) {
            $extraNg = mysqli_real_escape_string($koneksi, $ngList[$j]);
            mysqli_query($koneksi, "INSERT INTO asakai_ng_problem (id_asakai, deskripsi) VALUES ('$id_asakai', '$extraNg')");
        }
    }

    echo "<script>
        alert('Data berhasil disimpan');
        window.location='bahan_asakai.php';
    </script>";
}
