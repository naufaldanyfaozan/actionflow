-- Migration for 6 FPM feature requests (2026-08-21)

-- Feature 2: persist staff_imp drag-reorder of "List Pembahasan Asakai"
ALTER TABLE asakai_header ADD COLUMN urutan INT NULL AFTER id_asakai;
UPDATE asakai_header SET urutan = CAST(machine AS UNSIGNED) WHERE urutan IS NULL;

-- Feature 5 (FPM side): scope reschedule count/history to the specific
-- corrective/preventive action row instead of the whole FPM record, and
-- keep track of who the countermeasure was for (PIC) at reschedule time.
ALTER TABLE fpm_reschedule_log ADD COLUMN id_action INT NULL AFTER id_fpm;
ALTER TABLE fpm_reschedule_log ADD COLUMN pic VARCHAR(100) NULL AFTER alasan;

-- Feature 5 (Asakai side): reschedule history did not exist yet for Asakai
-- NG/Down Time countermeasures.
CREATE TABLE asakai_reschedule_log (
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
