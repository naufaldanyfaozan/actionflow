-- Flow FPM Claim Machining v2 (linear): Staff QA -> Plant Head (terima + pilih dept) ->
-- Dept Head (terima -> jawab) -> Plant Head approve -> Directur approve -> Staff QA verifikasi.
-- Kolom baru berprefix cm_ supaya tidak menambah lagi ambiguitas reuse kolom fpm_pengiriman
-- yang sudah dipakai flow IMP/QA dengan makna berbeda-beda.
ALTER TABLE fpm_header
  ADD COLUMN cm_plant_head_diterima_at DATETIME NULL,
  ADD COLUMN cm_plant_head_diterima_oleh VARCHAR(100) NULL,
  ADD COLUMN cm_dept_head_diterima_at DATETIME NULL,
  ADD COLUMN cm_dept_head_diterima_oleh VARCHAR(100) NULL,
  ADD COLUMN cm_dept_head_dijawab_at DATETIME NULL,
  ADD COLUMN cm_dept_head_dijawab_oleh VARCHAR(100) NULL,
  ADD COLUMN cm_plant_head_approved_at DATETIME NULL,
  ADD COLUMN cm_plant_head_approved_oleh VARCHAR(100) NULL,
  ADD COLUMN cm_directur_approved_at DATETIME NULL,
  ADD COLUMN cm_directur_approved_oleh VARCHAR(100) NULL;

-- fpm_header.dept sudah varchar(100) NULL sejak awal, jadi diisi belakangan oleh
-- Plant Head (bukan lagi wajib saat Staff QA membuat FPM Claim Machining) tanpa
-- perlu ubah tipe kolom.
