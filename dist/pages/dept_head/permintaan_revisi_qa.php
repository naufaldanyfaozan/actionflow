<?php
/**
 * Permintaan Revisi kini tampil di menu "Tugas & Approval" › Tugas Saya (lihat
 * config/tugas_saya_view.php). Halaman ini tinggal memproses "Upload Perbaikan" /
 * "Tandai Sudah Ditindaklanjuti" lalu kembali ke sana; link lama (mis. dari notifikasi WA)
 * otomatis diarahkan ke Tugas Saya.
 */
require_once __DIR__ . '/../config/auth_helper.php';
mulaiSessionAman();
wajibLogin();

include __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/permintaan_revisi_view.php';

handlePermintaanRevisiPost($koneksi, CM_REVISI_ROLE_QA);

$paramTab = permintaanRevisiParamTab(CM_REVISI_ROLE_QA);
header('Location: ' . permintaanRevisiUrlTugasSaya(CM_REVISI_ROLE_QA, ($_GET['tab'] ?? '') === 'riwayat' ? [$paramTab => 'riwayat'] : []));
exit;
