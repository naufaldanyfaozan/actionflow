-- Migration: FPM dari pembahasan Asakai (2026-11-03)
--
-- Tabel ini JUGA dibuat otomatis oleh config/asakai_fpm_helper.php saat pertama dipakai,
-- jadi file ini hanya untuk dokumentasi / dijalankan manual.
--
-- Satu baris = satu problem Asakai yang ditandai perlu FPM (per jenis: DOWN_TIME / NG).
-- Selesai saat FPM-nya disimpan (id_fpm terisi). Belum selesai sampai batas hari kerja
-- (Admin IT: monitor_asakai_fpm_warning / monitor_asakai_fpm_sp) -> peringatan lalu SP
-- untuk "Staff IMP Plant X" di fpm_sp_pelanggaran (tahap asakai_fpm_dt / asakai_fpm_ng,
-- kolom id_fpm berisi id_asakai).
CREATE TABLE IF NOT EXISTS asakai_fpm_tugas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_asakai INT NOT NULL,
  jenis VARCHAR(20) NOT NULL,          -- DOWN_TIME | NG
  plant VARCHAR(5) NOT NULL DEFAULT '',
  cara VARCHAR(10) NOT NULL,           -- langsung | nanti
  dibuat_oleh VARCHAR(50) NULL,
  dibuat_at DATETIME NOT NULL,         -- mulai hitungan H+
  id_fpm INT NULL,
  no_form VARCHAR(100) NULL,
  selesai_at DATETIME NULL,
  dibatalkan_oleh VARCHAR(50) NULL,
  dibatalkan_at DATETIME NULL,         -- hanya bisa di hari yang sama saat ditandai
  alasan_batal VARCHAR(255) NULL,
  UNIQUE KEY uq_asakai_jenis (id_asakai, jenis),
  KEY idx_plant (plant)
);

-- Jenis Masalah dari problem Asakai (bisa > 50 karakter) otomatis masuk master saat FPM dari
-- Asakai disimpan (kategori 'Asakai'). Kolom dilebarkan sama dengan fpm_header.jenis_masalah.
-- Juga dilakukan otomatis oleh siapkanKolomJenisMasalah() saat form FPM dari Asakai dibuka.
ALTER TABLE jenis_masalah MODIFY masalah VARCHAR(255) NOT NULL;
