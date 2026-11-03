ALTER TABLE fpm_header
  ADD COLUMN flow_type VARCHAR(10) NOT NULL DEFAULT 'IMP' AFTER jenis_fpm,
  ADD COLUMN diterima_qa DATE NULL,
  ADD COLUMN user_diterima_qa VARCHAR(100) NULL,
  ADD COLUMN catatan_pembahasan_qa TEXT NULL;

ALTER TABLE fpm_detail_masalah
  ADD COLUMN ng_area_percent DECIMAL(5,2) NULL,
  ADD COLUMN ng_total_percent DECIMAL(5,2) NULL;
