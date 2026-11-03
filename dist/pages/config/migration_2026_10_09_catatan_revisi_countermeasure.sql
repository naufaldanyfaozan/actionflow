-- =====================================================================
-- Migration: Catatan Revisi Countermeasure dari Staff IMP (2026-10-09)
--
-- Staff IMP bisa mengirim catatan revisi per baris countermeasure (FPM:
-- corrective/preventive/dokumen, ASAKAI: countermeasure down time/NG) saat
-- verifikasi - mis. PIC salah upload foto. Dept Head/Staff dept tujuan
-- melihatnya di menu "Permintaan Revisi" dan bisa langsung upload ulang.
--
-- Jalankan SEKALI lewat phpMyAdmin > database > tab SQL. Aman diulang
-- (idempotent): setiap ADD COLUMN dicek dulu apakah kolomnya sudah ada.
-- =====================================================================

DROP PROCEDURE IF EXISTS _tambah_kolom_revisi_cm;
DELIMITER $$
CREATE PROCEDURE _tambah_kolom_revisi_cm(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_definisi TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabel AND COLUMN_NAME = p_kolom
  ) THEN
    SET @sql_tambah = CONCAT('ALTER TABLE ', p_tabel, ' ADD COLUMN ', p_kolom, ' ', p_definisi);
    PREPARE stmt_tambah FROM @sql_tambah;
    EXECUTE stmt_tambah;
    DEALLOCATE PREPARE stmt_tambah;
  END IF;
END$$
DELIMITER ;

-- FPM: reuse tabel fpm_catatan_revisi (sudah dipakai tombol "+ Catatan Revisi").
-- file_saat_catatan = snapshot file baris saat catatan dikirim; kalau file baris
-- sudah berbeda berarti PIC sudah upload ulang (lewat halaman mana pun).
CALL _tambah_kolom_revisi_cm('fpm_catatan_revisi', 'file_saat_catatan', 'VARCHAR(255) NULL AFTER catatan');
CALL _tambah_kolom_revisi_cm('fpm_catatan_revisi', 'selesai_at', 'DATETIME NULL AFTER created_at');
CALL _tambah_kolom_revisi_cm('fpm_catatan_revisi', 'selesai_oleh', 'VARCHAR(100) NULL AFTER selesai_at');
CALL _tambah_kolom_revisi_cm('fpm_catatan_revisi', 'tanggapan_pic', 'TEXT NULL AFTER selesai_oleh');

-- Kolom role NOT NULL tanpa default bikin INSERT lama gagal di MySQL strict mode.
ALTER TABLE fpm_catatan_revisi MODIFY role VARCHAR(50) NULL DEFAULT NULL;

-- ASAKAI: tabel baru dengan bentuk yang sama.
CREATE TABLE IF NOT EXISTS asakai_catatan_revisi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_asakai INT NOT NULL,
  ref_table VARCHAR(40) NULL,
  ref_id INT NULL,
  dept_tujuan VARCHAR(100) NOT NULL,
  catatan TEXT NOT NULL,
  file_saat_catatan VARCHAR(255) NULL,
  dibuat_oleh VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  selesai_at DATETIME NULL,
  selesai_oleh VARCHAR(100) NULL,
  tanggapan_pic TEXT NULL,
  KEY idx_id_asakai (id_asakai),
  KEY idx_dept_tujuan (dept_tujuan)
);

DROP PROCEDURE IF EXISTS _tambah_kolom_revisi_cm;
