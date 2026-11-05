<?php
/**
 * Menu "FPM dari Asakai" Staff IMP kini tampil di "Tugas & Approval" › Tugas Saya
 * (config/asakai_fpm_view.php di-include dari tugas_saya_view.php). Link lama diarahkan ke sana.
 * Head IMP tetap memakai head_imp/fpm_asakai.php untuk memantau.
 */
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

$status = preg_replace('/[^a-z]/', '', (string) ($_GET['status'] ?? ''));
header('Location: tugas_saya.php' . ($status !== '' ? '?status=' . $status : '') . '#asakai-fpm');
exit;
