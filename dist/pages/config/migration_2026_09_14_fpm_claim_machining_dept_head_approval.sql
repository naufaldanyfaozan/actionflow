-- Tambah step approval Dept Head (dept terkait/tujuan) SETELAH menjawab dan SEBELUM
-- diteruskan ke Plant Head, pada alur FPM Claim Machining (flow_type='CLAIMMACH'),
-- supaya sesuai SOP "Flow FPM Claim Machining": Analisa & Jawab FPM -> Proses Approval
-- FPM Oleh Dept Terkait -> Proses Approval FPM Oleh Plant Head -> Proses Approval FPM
-- Oleh Direktur. Reject di tahap Plant Head / Direktur akan mengembalikan status ke
-- sebelum step ini (lihat perubahan PHP terkait) supaya sesuai alur "kembali ke Analisa
-- & Jawab FPM" pada diagram SOP.
ALTER TABLE fpm_header
  ADD COLUMN cm_dept_head_approved_at DATETIME NULL,
  ADD COLUMN cm_dept_head_approved_oleh VARCHAR(40) NULL;
