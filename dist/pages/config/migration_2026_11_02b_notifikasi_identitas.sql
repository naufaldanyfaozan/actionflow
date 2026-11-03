-- Migration: Admin IT - saklar WA per nomor, email SP ke HRD, identitas aplikasi (2026-11-02)
--
-- Semua perubahan di bawah ini JUGA dibuat otomatis oleh helper saat halaman pertama kali
-- dibuka (wa_penerima_helper.php & monitoring_approval_helper.php), jadi file ini hanya
-- untuk dokumentasi / dijalankan manual. Jalankan SEKALI saja: ALTER TABLE di bawah akan
-- error "Duplicate column" kalau kolomnya sudah ada (artinya sudah ter-migrasi).
--
-- Pengaturan baru (email_sp_aktif, email_hrd_1/2, email_pengirim, brand_nama, brand_tagline,
-- brand_logo_app, brand_logo_dokumen) disimpan di tabel app_setting yang sudah ada - tidak
-- perlu tabel baru. Logo hasil upload disimpan di folder src/assets/img/branding/ (pastikan
-- folder ini bisa ditulis web server di hosting).

-- Nomor WA penerima yang dinonaktifkan Admin IT (nomor format 62xxx, sama dengan wa_map.php).
CREATE TABLE IF NOT EXISTS wa_nomor_nonaktif (
  nomor VARCHAR(20) PRIMARY KEY,
  diubah_oleh VARCHAR(50) NULL,
  diubah_at DATETIME NULL
);

-- Nomor WA yang ditambahkan Admin IT per peran (digabung ke wa_map.php saat runtime).
-- peran: 'dept:<DEPT>:staffdept|depthead|planthead', 'var:<variabel wa_map>', 'plant:<no plant>'.
CREATE TABLE IF NOT EXISTS wa_nomor_tambahan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor VARCHAR(20) NOT NULL,
  peran VARCHAR(80) NOT NULL,
  nama VARCHAR(60) NULL,
  dibuat_oleh VARCHAR(50) NULL,
  dibuat_at DATETIME NULL,
  UNIQUE KEY uq_nomor_peran (nomor, peran)
);

-- Status email SP ke HRD per catatan pelanggaran SP.
ALTER TABLE fpm_sp_pelanggaran
  ADD COLUMN email_hrd_status VARCHAR(20) NULL,
  ADD COLUMN email_hrd_dicoba_at DATETIME NULL;

-- SP yang tercatat sebelum fitur email ada tidak dikirim belakangan.
UPDATE fpm_sp_pelanggaran SET email_hrd_status = 'lama' WHERE email_hrd_status IS NULL;
