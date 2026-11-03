<?php
session_start();
include '../koneksi.php';

if (isset($_POST['submit_update_delivery']) && isset($_POST['data'])) {
    $tanggal_filter = $_POST['tanggal_filter'] ?? date('Y-m-d');

    foreach ($_POST['data'] as $row) {
        $id_monitor     = mysqli_real_escape_string($koneksi, $row['id_monitor']);
        $countermeasure = mysqli_real_escape_string($koneksi, $row['countermeasure']);
        $due_date       = !empty($row['due_date']) ? "'" . mysqli_real_escape_string($koneksi, $row['due_date']) . "'" : (!empty($row['old_due_date']) ? "'" . mysqli_real_escape_string($koneksi, $row['old_due_date']) . "'" : "NULL");
        $pic            = mysqli_real_escape_string($koneksi, $row['pic']);

        $check = mysqli_query($koneksi, "SELECT id_countermeasure FROM fpm_delivery_monitor_countermeasure WHERE id_monitor = '$id_monitor'");

        if (mysqli_num_rows($check) > 0) {
            $query_save = "UPDATE fpm_delivery_monitor_countermeasure SET countermeasure = '$countermeasure', 
                            due_date = $due_date, pic = '$pic' WHERE id_monitor = '$id_monitor'";
        } else {
            $query_save = "INSERT INTO fpm_delivery_monitor_countermeasure (id_monitor, countermeasure, due_date, pic, status) 
                           VALUES ('$id_monitor', '$countermeasure', $due_date, '$pic', 'Open')";
        }

        mysqli_query($koneksi, $query_save);
    }

    echo "<script>
            alert('Data Delivery Countermeasure berhasil diperbarui!');
            window.location.href = 'list_asakai.php?tanggal=" . $tanggal_filter . "';
          </script>";
} else {
    header("Location: list_asakai.php");
}
