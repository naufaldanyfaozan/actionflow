-- Perbaikan bug: semua kolom cm_* di fpm_header (alur approval Claim Machining v2/v3)
-- ternyata tersimpan sebagai NOT NULL dengan default zero-date ('0000-00-00 00:00:00')
-- alih-alih NULL seperti yang dimaksud di migration_2026_09_04 dan migration_2026_09_07.
-- Akibatnya empty($fpm['cm_..._at']) di PHP selalu bernilai false (string zero-date
-- dianggap "terisi"), jadi semua gate approval (Dept Head QA / Plant Head / Directur)
-- salah membaca status sebagai "sudah disetujui" walau belum ada yang approve apa-apa.
--
-- Migration ini: (1) pastikan semua kolom cm_* benar-benar NULL-able, (2) bersihkan
-- data lama yang sudah kadung berisi zero-date/string kosong supaya jadi NULL lagi.

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
