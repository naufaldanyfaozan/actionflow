<?php
session_start();
if (!isset($_SESSION["username"])) header("location:cek_login.php");

// FPM Claim Machining: role ini bukan bagian alur yang menjawab (cuma Dept Head &
// Plant Head yang boleh isi/update perbaikan) - redirect ke halaman detail/approval.
header("location:form_fpm.php?id=" . ($_GET['id'] ?? ''));
exit;
