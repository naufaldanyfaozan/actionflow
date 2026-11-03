-- Migration: riwayat pelanggaran SP (Surat Peringatan) Monitor Approval FPM (2026-11-02)
--
-- Setiap FPM yang tahap approval-nya melewati batas waktu dicatat 1 baris di sini
-- (lihat config/monitoring_approval_helper.php). Level SP 1/2/3 & masa berlaku 6 bulan
-- dihitung dari tabel ini, tidak disimpan.
--
-- Tabel ini juga dibuat otomatis oleh helper kalau belum ada, jadi file ini aman
-- dijalankan berulang (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS fpm_sp_pelanggaran (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pihak VARCHAR(100) NOT NULL,
  id_fpm INT NOT NULL,
  no_form VARCHAR(100) NULL,
  tahap VARCHAR(30) NOT NULL,
  tahap_label VARCHAR(100) NULL,
  tgl_mulai DATE NOT NULL,
  tgl_sp DATE NOT NULL,
  dicatat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pelanggaran (pihak, id_fpm, tahap, tgl_mulai),
  KEY idx_pihak (pihak)
);
