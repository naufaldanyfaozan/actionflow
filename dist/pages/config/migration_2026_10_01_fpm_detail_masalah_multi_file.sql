-- Migration: Dokumen / Foto NG FPM Internal bisa sampai 3 file (2026-10-01)
--
-- Kolom `file` yang lama tetap dipakai sebagai file pertama (data lama tidak perlu
-- dipindah), file kedua & ketiga disimpan di file2 / file3. Diisi oleh
-- staff_imp/proses_fpm_internal.php, ditampilkan lewat config/ng_detail_box.php.
ALTER TABLE fpm_detail_masalah
  ADD COLUMN file2 VARCHAR(50) NULL DEFAULT NULL AFTER file,
  ADD COLUMN file3 VARCHAR(50) NULL DEFAULT NULL AFTER file2;
