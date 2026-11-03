-- Tambah step approval Dept Head QA SEBELUM Plant Head pada alur FPM Claim Machining
-- (flow_type='CLAIMMACH'). Alur v3: Staff QA -> Dept Head QA approve -> Plant Head
-- (terima + pilih dept) -> Dept Head (terima -> jawab) -> Plant Head approve ->
-- Directur approve -> Staff QA verifikasi.
ALTER TABLE fpm_header
  ADD COLUMN cm_deptheadqa_approved_at DATETIME NULL,
  ADD COLUMN cm_deptheadqa_approved_oleh VARCHAR(100) NULL;
