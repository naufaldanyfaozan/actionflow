-- Penanda "Dept Head minta Head IMP/Staff IMP perbaiki data FPM sebelum diterima".
-- Diisi NOW() saat Dept Head klik "Minta Perbaikan Data", dikosongkan lagi (NULL)
-- saat Head IMP approve ulang setelah data diperbaiki. Alasannya sendiri dicatat
-- di tabel fpm_revisi_history yang sudah ada (tidak perlu kolom terpisah untuk itu).
ALTER TABLE fpm_header
  ADD COLUMN minta_perbaikan_data_at DATETIME NULL;
