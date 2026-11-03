-- Migration: riwayat revisi FPM (2026-08-24)
--
-- Sebelumnya alasan reject (fpm_header.note/tgl_reject/status_reject) cuma
-- disimpan di kolom tunggal yang ditimpa tiap kali - jadi kalau sebuah FPM
-- direvisi lebih dari sekali, alasan reject sebelumnya hilang, dan tidak ada
-- catatan sama sekali soal apa yang sudah diperbaiki oleh Staff/Dept Head.
-- Tabel ini jadi log lengkap (append-only) untuk setiap siklus
-- reject -> revisi, supaya yang meminta revisi bisa lihat balasannya dan
-- histori lengkapnya tidak hilang.
CREATE TABLE fpm_revisi_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  tipe ENUM('REJECT','REVISI') NOT NULL,
  catatan TEXT NOT NULL,
  oleh VARCHAR(100) NOT NULL,
  role VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_id_fpm (id_fpm)
);
