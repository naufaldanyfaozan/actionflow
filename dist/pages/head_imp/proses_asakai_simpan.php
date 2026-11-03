<?php
session_start();
if (!isset($_SESSION["username"])) {
    header("location:cek_login.php");
    exit;
}

include '../koneksi.php';

$username = $_SESSION['username'] ?? '';

$plantMap = [
    'mustika' => 1,
    'safri'   => 3
];

$plant = $plantMap[$username] ?? 0; // default 0 jika tidak ditemukan

if (!isset($_POST['submit_asakai'])) {
    header("location:create_asakai.php");
    exit;
}

mysqli_begin_transaction($koneksi);

try {

    $username = $_SESSION['username'] ?? '';

    $plantMap = [
        'mustika' => 1,
        'safri'   => 3
    ];

    $plant = $plantMap[$username] ?? 0;

    $tanggal            = $_POST['tanggal'];
    $machine            = $_POST['machine'];
    $namapart           = $_POST['nama_part'];
    $mold_no            = $_POST['mold_no'];
    $target             = (int) $_POST['target'];
    $ok_qty             = (int) $_POST['ok_qty'];
    $ng_qty             = (int) $_POST['ng_qty'];
    $downtime           = (int) $_POST['downtime'];
    $standard           = $_POST['standard'];
    $actual             = $_POST['actual'];
    $efficiency         = (float) $_POST['efficiency'];
    $persen_ng          = (float) $_POST['persen_ng'];
    $downtime_problem   = $_POST['downtime_problem'];

    $ngList = array_values(array_filter(array_map('trim', $_POST['ng_problem'] ?? []), fn($v) => $v !== ''));
    $ng_problem = $ngList[0] ?? '';

    $sqlHeader = "INSERT INTO asakai_header (
        plant, tanggal, machine, namapart, mold_no,
        target, ok_qty, ng_qty, downtime,
        standard, actual, efficiency, persen_ng,
        downtime_problem, ng_problem
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $stmt = mysqli_prepare($koneksi, $sqlHeader);
    mysqli_stmt_bind_param(
        $stmt,
        "sssssiiiissddss",
        $plant,
        $tanggal,
        $machine,
        $namapart,
        $mold_no,
        $target,
        $ok_qty,
        $ng_qty,
        $downtime,
        $standard,
        $actual,
        $efficiency,
        $persen_ng,
        $downtime_problem,
        $ng_problem
    );
    mysqli_stmt_execute($stmt);

    $id_asakai = mysqli_insert_id($koneksi);

    $sqlNgExtra = "INSERT INTO asakai_ng_problem (id_asakai, deskripsi) VALUES (?,?)";
    $stmtNgExtra = mysqli_prepare($koneksi, $sqlNgExtra);
    for ($j = 1; $j < count($ngList); $j++) {
        $extraNg = $ngList[$j];
        mysqli_stmt_bind_param($stmtNgExtra, "is", $id_asakai, $extraNg);
        mysqli_stmt_execute($stmtNgExtra);
    }

    $sqlAnalisa = "INSERT INTO asakai_analisa
        (id_asakai, kategori, analisa1, analisa2, analisa3, analisa4)
        VALUES (?,?,?,?,?,?)";

    $stmtA = mysqli_prepare($koneksi, $sqlAnalisa);

    $kat = 'DOWN_TIME';
    $a1 = $_POST['analisa_down'][1] ?? '';
    $a2 = $_POST['analisa_down'][2] ?? '';
    $a3 = $_POST['analisa_down'][3] ?? '';
    $a4 = $_POST['analisa_down'][4] ?? '';

    mysqli_stmt_bind_param(
        $stmtA,
        "isssss",
        $id_asakai,
        $kat,
        $a1,
        $a2,
        $a3,
        $a4
    );
    mysqli_stmt_execute($stmtA);

    $kat = 'NG';
    $a1 = $_POST['analisa_ng'][1] ?? '';
    $a2 = $_POST['analisa_ng'][2] ?? '';
    $a3 = $_POST['analisa_ng'][3] ?? '';
    $a4 = $_POST['analisa_ng'][4] ?? '';

    mysqli_stmt_bind_param(
        $stmtA,
        "isssss",
        $id_asakai,
        $kat,
        $a1,
        $a2,
        $a3,
        $a4
    );
    mysqli_stmt_execute($stmtA);

    $sqlCMD = "INSERT INTO asakai_countermeasure_downtime
        (id_asakai, deskripsi, pic, due_date, status)
        VALUES (?,?,?,?,?)";

    $stmtCMD = mysqli_prepare($koneksi, $sqlCMD);

    if (!empty($_POST['cm_down_desc'])) {
        foreach ($_POST['cm_down_desc'] as $i => $desc) {
            if ($desc == '') continue;

            $pic    = $_POST['cm_down_pic'][$i] ?? '';
            $due    = !empty($_POST['cm_down_due'][$i]) ? $_POST['cm_down_due'][$i] : null;
            $status = $_POST['cm_down_status'][$i] ?? '';

            mysqli_stmt_bind_param(
                $stmtCMD,
                "issss",
                $id_asakai,
                $desc,
                $pic,
                $due,
                $status
            );
            mysqli_stmt_execute($stmtCMD);
        }
    }

    $sqlCMN = "INSERT INTO asakai_countermeasure_ng
        (id_asakai, deskripsi, pic, due_date, status)
        VALUES (?,?,?,?,?)";

    $stmtCMN = mysqli_prepare($koneksi, $sqlCMN);

    if (!empty($_POST['cm_ng_desc'])) {
        foreach ($_POST['cm_ng_desc'] as $i => $desc) {
            if ($desc == '') continue;

            $pic    = $_POST['cm_ng_pic'][$i] ?? '';
            $due    = !empty($_POST['cm_ng_due'][$i]) ? $_POST['cm_ng_due'][$i] : null;
            $status = $_POST['cm_ng_status'][$i] ?? '';

            mysqli_stmt_bind_param(
                $stmtCMN,
                "issss",
                $id_asakai,
                $desc,
                $pic,
                $due,
                $status
            );
            mysqli_stmt_execute($stmtCMN);
        }
    }

    mysqli_commit($koneksi);
    header("location:list_asakai.php");
    exit;
} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo "Gagal menyimpan data : " . $e->getMessage();
}
