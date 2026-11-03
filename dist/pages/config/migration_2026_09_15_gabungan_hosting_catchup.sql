-- =====================================================================
-- MIGRATION GABUNGAN UNTUK HOSTING - jalankan file ini SEKALI lewat
-- phpMyAdmin > pilih database > tab SQL > paste semua isi file ini > Go.
--
-- Aman dijalankan berkali-kali (idempotent) - setiap ADD COLUMN dicek
-- dulu apakah kolomnya sudah ada, jadi tidak akan error "Duplicate
-- column" walau file ini ke-run lebih dari sekali.
--
-- Ini gabungan dari 11 file migration_*.sql di dist/pages/config/
-- (21 Agu - 14 Sep 2026) yang belum pernah dijalankan di hosting.
-- =====================================================================

-- ---------------------------------------------------------------
-- 1. BUAT TABEL BARU (aman - CREATE TABLE IF NOT EXISTS)
-- ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fpm_reschedule_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  id_action INT NULL,
  jenis_action ENUM('CORRECTIVE','PREVENTIVE') NOT NULL,
  tindakan TEXT NOT NULL,
  due_date_lama DATE NOT NULL,
  due_date_baru DATE NOT NULL,
  alasan TEXT NOT NULL,
  pic VARCHAR(100) NULL,
  direschedule_oleh VARCHAR(100) NOT NULL,
  direschedule_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_id_fpm (id_fpm),
  KEY idx_id_action (id_action)
);

CREATE TABLE IF NOT EXISTS asakai_reschedule_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_asakai INT NOT NULL,
  id_action INT NOT NULL,
  jenis_action ENUM('DOWNTIME','NG') NOT NULL,
  deskripsi TEXT NOT NULL,
  pic VARCHAR(100) NULL,
  due_date_lama DATE NOT NULL,
  due_date_baru DATE NOT NULL,
  alasan TEXT NOT NULL,
  direschedule_oleh VARCHAR(100) NOT NULL,
  direschedule_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_id_asakai (id_asakai),
  KEY idx_id_action (id_action, jenis_action)
);

CREATE TABLE IF NOT EXISTS fpm_revisi_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  tipe ENUM('REJECT','REVISI') NOT NULL,
  catatan TEXT NOT NULL,
  oleh VARCHAR(100) NOT NULL,
  role VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_id_fpm (id_fpm)
);

CREATE TABLE IF NOT EXISTS fpm_claim_pic_approval (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  dept VARCHAR(100) NOT NULL,
  approved_by VARCHAR(100) NOT NULL,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fpm_dept (id_fpm, dept)
);

-- ---------------------------------------------------------------
-- 2. TAMBAH KOLOM BARU - tiap blok cek dulu apakah kolom sudah ada
-- ---------------------------------------------------------------

DROP PROCEDURE IF EXISTS _tambah_kolom_jika_belum_ada;

DELIMITER $$
CREATE PROCEDURE _tambah_kolom_jika_belum_ada(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_definition);
    PREPARE stmt FROM @ddl;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

-- fpm_reschedule_log: jaga-jaga kalau tabel sudah ada duluan dari sumber lain
CALL _tambah_kolom_jika_belum_ada('fpm_reschedule_log', 'id_action', 'INT NULL AFTER id_fpm');
CALL _tambah_kolom_jika_belum_ada('fpm_reschedule_log', 'pic', 'VARCHAR(100) NULL AFTER alasan');

-- asakai_header
CALL _tambah_kolom_jika_belum_ada('asakai_header', 'urutan', 'INT NULL AFTER id_asakai');

-- fpm_catatan_revisi
CALL _tambah_kolom_jika_belum_ada('fpm_catatan_revisi', 'tipe', "ENUM('CATATAN','BALASAN') NOT NULL DEFAULT 'CATATAN' AFTER dept_tujuan");
CALL _tambah_kolom_jika_belum_ada('fpm_catatan_revisi', 'role', 'VARCHAR(50) NULL AFTER dibuat_oleh');

-- fpm_header - fitur QA
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'user_diterima_qa', 'VARCHAR(100) NULL');

-- fpm_header - catatan target dept
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'catatan_target_dept', 'VARCHAR(100) NULL AFTER status_reject');

-- fpm_header - alur Claim Machining v2 (2026-09-04)
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_plant_head_diterima_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_plant_head_diterima_oleh', 'VARCHAR(100) NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_dept_head_diterima_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_dept_head_diterima_oleh', 'VARCHAR(100) NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_dept_head_dijawab_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_dept_head_dijawab_oleh', 'VARCHAR(100) NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_plant_head_approved_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_plant_head_approved_oleh', 'VARCHAR(100) NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_directur_approved_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_directur_approved_oleh', 'VARCHAR(100) NULL');

-- fpm_header - alur Claim Machining v3, step Dept Head QA (2026-09-07)
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_deptheadqa_approved_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_deptheadqa_approved_oleh', 'VARCHAR(100) NULL');

-- fpm_header - alur Claim Machining, step Dept Head tujuan (2026-09-14)
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_dept_head_approved_at', 'DATETIME NULL');
CALL _tambah_kolom_jika_belum_ada('fpm_header', 'cm_dept_head_approved_oleh', 'VARCHAR(40) NULL');

DROP PROCEDURE IF EXISTS _tambah_kolom_jika_belum_ada;

-- ---------------------------------------------------------------
-- 3. PRIMARY KEY / AUTO_INCREMENT fpm_catatan_revisi.id (aman diulang -
--    dicek dulu apakah primary key sudah ada)
-- ---------------------------------------------------------------

SET @sudah_ada_pk = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fpm_catatan_revisi'
    AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @ddl_pk = IF(@sudah_ada_pk = 0,
  'ALTER TABLE fpm_catatan_revisi MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)',
  'SELECT 1'
);
PREPARE stmt_pk FROM @ddl_pk;
EXECUTE stmt_pk;
DEALLOCATE PREPARE stmt_pk;

-- Index tambahan fpm_catatan_revisi.id_fpm (dicek dulu supaya aman diulang)
SET @sudah_ada_index = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fpm_catatan_revisi'
    AND INDEX_NAME = 'idx_id_fpm'
);
SET @ddl_idx = IF(@sudah_ada_index = 0,
  'ALTER TABLE fpm_catatan_revisi ADD KEY idx_id_fpm (id_fpm)',
  'SELECT 1'
);
PREPARE stmt_idx FROM @ddl_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

-- ---------------------------------------------------------------
-- 4. MODIFY COLUMN (aman diulang - MODIFY ke tipe yang sama tidak error)
-- ---------------------------------------------------------------

ALTER TABLE fpm_pengiriman MODIFY user_dibuat VARCHAR(255) NOT NULL;

ALTER TABLE fpm_header
  MODIFY COLUMN cm_plant_head_diterima_at DATETIME NULL,
  MODIFY COLUMN cm_plant_head_diterima_oleh VARCHAR(40) NULL,
  MODIFY COLUMN cm_dept_head_diterima_at DATETIME NULL,
  MODIFY COLUMN cm_dept_head_diterima_oleh VARCHAR(40) NULL,
  MODIFY COLUMN cm_dept_head_dijawab_at DATETIME NULL,
  MODIFY COLUMN cm_dept_head_dijawab_oleh VARCHAR(40) NULL,
  MODIFY COLUMN cm_plant_head_approved_at DATETIME NULL,
  MODIFY COLUMN cm_plant_head_approved_oleh VARCHAR(40) NULL,
  MODIFY COLUMN cm_directur_approved_at DATETIME NULL,
  MODIFY COLUMN cm_directur_approved_oleh VARCHAR(40) NULL,
  MODIFY COLUMN cm_deptheadqa_approved_at DATETIME NULL,
  MODIFY COLUMN cm_deptheadqa_approved_oleh VARCHAR(100) NULL;

-- ---------------------------------------------------------------
-- 5. BERSIHKAN DATA LAMA (aman diulang - cuma UPDATE ... WHERE)
-- ---------------------------------------------------------------

UPDATE asakai_header SET urutan = CAST(machine AS UNSIGNED) WHERE urutan IS NULL;

UPDATE fpm_header SET cm_plant_head_diterima_at = NULL WHERE cm_plant_head_diterima_at = '0000-00-00 00:00:00';
UPDATE fpm_header SET cm_plant_head_diterima_oleh = NULL WHERE cm_plant_head_diterima_oleh = '';
UPDATE fpm_header SET cm_dept_head_diterima_at = NULL WHERE cm_dept_head_diterima_at = '0000-00-00 00:00:00';
UPDATE fpm_header SET cm_dept_head_diterima_oleh = NULL WHERE cm_dept_head_diterima_oleh = '';
UPDATE fpm_header SET cm_dept_head_dijawab_at = NULL WHERE cm_dept_head_dijawab_at = '0000-00-00 00:00:00';
UPDATE fpm_header SET cm_dept_head_dijawab_oleh = NULL WHERE cm_dept_head_dijawab_oleh = '';
UPDATE fpm_header SET cm_plant_head_approved_at = NULL WHERE cm_plant_head_approved_at = '0000-00-00 00:00:00';
UPDATE fpm_header SET cm_plant_head_approved_oleh = NULL WHERE cm_plant_head_approved_oleh = '';
UPDATE fpm_header SET cm_directur_approved_at = NULL WHERE cm_directur_approved_at = '0000-00-00 00:00:00';
UPDATE fpm_header SET cm_directur_approved_oleh = NULL WHERE cm_directur_approved_oleh = '';
UPDATE fpm_header SET cm_deptheadqa_approved_at = NULL WHERE cm_deptheadqa_approved_at = '0000-00-00 00:00:00';
UPDATE fpm_header SET cm_deptheadqa_approved_oleh = NULL WHERE cm_deptheadqa_approved_oleh = '';

-- =====================================================================
-- SELESAI. Sudah dites 2x berturut-turut di database lokal - bersih
-- tanpa error di kedua run, aman dijalankan berkali-kali di hosting.
-- =====================================================================
