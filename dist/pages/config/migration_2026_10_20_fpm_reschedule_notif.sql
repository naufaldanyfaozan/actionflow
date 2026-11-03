-- Migration: notifikasi reschedule countermeasure FPM ke Staff IMP / Staff QA (2026-10-20)
--
-- Reschedule due date (maks 3x per baris) sekarang bisa dilakukan Staff / Dept Head /
-- Plant Head dari semua halaman upload perbaikan FPM Internal, Down Time & Claim Machining
-- (lihat config/fpm_reschedule_helper.php). Setiap reschedule dikirim WA + muncul di
-- menu Verifikasi Countermeasure Staff IMP (FPM-D/FPM-N) atau Staff QA (FPM-Cast) sampai
-- ditandai "sudah dibaca".
--
-- AMAN DIJALANKAN BERULANG & di kondisi tabel apa pun (tabel belum ada, kolom id_action/pic
-- belum ada, kolom dibaca_* sudah ada) - di hosting tabel ini pernah tidak ada / tidak
-- lengkap, yang membuat edit_fpm_downtime.php HTTP 500.
--
--  - jenis_action ditambah 'DOKUMEN' supaya baris Update Dokumen juga bisa di-reschedule.
--  - dibaca_at / dibaca_oleh: kapan & siapa Staff IMP/QA menandai reschedule sudah dibaca.

CREATE TABLE IF NOT EXISTS fpm_reschedule_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  id_action INT NULL,
  jenis_action ENUM('CORRECTIVE','PREVENTIVE','DOKUMEN') NOT NULL,
  tindakan TEXT NOT NULL,
  due_date_lama DATE NOT NULL,
  due_date_baru DATE NOT NULL,
  alasan TEXT NOT NULL,
  pic VARCHAR(100) NULL,
  direschedule_oleh VARCHAR(100) NOT NULL,
  direschedule_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dibaca_at DATETIME NULL,
  dibaca_oleh VARCHAR(100) NULL,
  KEY idx_id_fpm (id_fpm),
  KEY idx_id_action (id_action)
);

DROP PROCEDURE IF EXISTS _migrasi_fpm_reschedule_2026_10_20;

DELIMITER $$
CREATE PROCEDURE _migrasi_fpm_reschedule_2026_10_20()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fpm_reschedule_log' AND COLUMN_NAME = 'id_action') THEN
    ALTER TABLE fpm_reschedule_log ADD COLUMN id_action INT NULL AFTER id_fpm;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fpm_reschedule_log' AND COLUMN_NAME = 'pic') THEN
    ALTER TABLE fpm_reschedule_log ADD COLUMN pic VARCHAR(100) NULL AFTER alasan;
  END IF;

  ALTER TABLE fpm_reschedule_log
    MODIFY jenis_action ENUM('CORRECTIVE','PREVENTIVE','DOKUMEN') NOT NULL,
    MODIFY pic VARCHAR(100) NULL;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fpm_reschedule_log' AND COLUMN_NAME = 'dibaca_at') THEN
    ALTER TABLE fpm_reschedule_log
      ADD COLUMN dibaca_at DATETIME NULL AFTER direschedule_at,
      ADD COLUMN dibaca_oleh VARCHAR(100) NULL AFTER dibaca_at;

    -- Hanya saat kolom baru dibuat: reschedule lama (sebelum fitur notifikasi) dianggap
    -- sudah dibaca supaya badge Staff IMP/QA tidak langsung penuh data lama.
    UPDATE fpm_reschedule_log SET dibaca_at = direschedule_at, dibaca_oleh = 'migrasi' WHERE dibaca_at IS NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fpm_reschedule_log' AND INDEX_NAME = 'idx_dibaca_at') THEN
    ALTER TABLE fpm_reschedule_log ADD KEY idx_dibaca_at (dibaca_at);
  END IF;
END$$
DELIMITER ;

CALL _migrasi_fpm_reschedule_2026_10_20();
DROP PROCEDURE IF EXISTS _migrasi_fpm_reschedule_2026_10_20;
