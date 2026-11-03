-- =====================================================================
-- Migration: tanda "sudah dicek Staff IMP" untuk catatan revisi (2026-10-09)
--
-- Lanjutan migration_2026_10_09_catatan_revisi_countermeasure.sql. Setelah PIC
-- menindaklanjuti revisi, Staff IMP dapat notif (WA + daftar di Verifikasi
-- Countermeasure). dicek_imp_at diisi saat Staff IMP klik "Tandai sudah dicek"
-- supaya revisi itu hilang dari daftar notif.
--
-- Jalankan SEKALI lewat phpMyAdmin > database > tab SQL. Aman diulang.
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

CALL _tambah_kolom_revisi_cm('fpm_catatan_revisi', 'dicek_imp_at', 'DATETIME NULL AFTER tanggapan_pic');
CALL _tambah_kolom_revisi_cm('fpm_catatan_revisi', 'dicek_imp_oleh', 'VARCHAR(100) NULL AFTER dicek_imp_at');
CALL _tambah_kolom_revisi_cm('asakai_catatan_revisi', 'dicek_imp_at', 'DATETIME NULL AFTER tanggapan_pic');
CALL _tambah_kolom_revisi_cm('asakai_catatan_revisi', 'dicek_imp_oleh', 'VARCHAR(100) NULL AFTER dicek_imp_at');

DROP PROCEDURE IF EXISTS _tambah_kolom_revisi_cm;
