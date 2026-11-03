-- Migration: alur approval FPM Claim Machining yang benar (2026-09-03)
--
-- Sebelumnya FPM Claim Machining (flow_type='CLAIMMACH') tidak punya alur approval
-- sendiri sama sekali - halaman list (list_fpm_internal.php dkk) malah menghitung
-- status approval-nya pakai logic alur IMP biasa (Imp Head -> Dept Head -> Plant Head
-- -> Vice Directure -> Directure), jadi selalu nyangkut di "Menunggu Imp Head" karena
-- Imp Head memang tidak pernah memproses FPM Claim Machining.
--
-- Alur yang benar: setiap dept yang jadi PIC di corrective/preventive action-nya
-- approve dulu -> baru Plant Head approve -> baru Dept Head QA approve -> baru
-- Directure approve (final). Karena PIC bisa lebih dari satu dept untuk satu FPM
-- (fpm_pengiriman cuma 1 baris per FPM), approval per-PIC-dept butuh tabel sendiri
-- yang bisa menyimpan banyak baris per FPM.
CREATE TABLE fpm_claim_pic_approval (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  dept VARCHAR(100) NOT NULL,
  approved_by VARCHAR(100) NOT NULL,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fpm_dept (id_fpm, dept)
);

-- Plant Head dan Directure approve FPM Claim Machining lewat kolom fpm_pengiriman
-- yang sama seperti alur lain, tapi dengan arti berbeda khusus untuk baris CLAIMMACH
-- (tidak konflik karena tiap baris fpm_pengiriman cuma dibaca lewat satu flow_type):
--   disetujui1 / user_disetujui1 = Plant Head approve (setelah semua PIC approve)
--   diperiksa  / user_diperiksa  = Dept Head QA approve (sudah ada, ditambah gate
--                                   supaya cuma bisa diklik setelah Plant Head approve)
--   disetujui  / user_disetujui  = Directure approve, final (sudah dipakai
--                                   list_fpm_claim_machining.php Dept Head QA)
-- Tidak ada perubahan skema untuk tiga kolom itu, cuma dicatat di sini biar jelas.
