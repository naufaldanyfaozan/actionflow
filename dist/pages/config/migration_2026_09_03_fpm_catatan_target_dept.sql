-- Migration: catatan Dept Head QA ke dept/PIC countermeasure FPM QA (2026-09-03)
--
-- Reuse tabel fpm_catatan_revisi yang sudah ada (dipakai fitur "+ Catatan Revisi"
-- di update_countermeasure.php untuk FPM Down Time) untuk fitur serupa di FPM QA:
-- Dept Head QA bisa kirim catatan ke dept/PIC countermeasure tertentu di setiap
-- FPM QA (Customer/Internal/Claim Machining), dan dept tersebut (Dept Head/Staff-nya)
-- atau Plant Head bisa membalas. Tabel itu sebelumnya cuma satu arah (catatan dari
-- IMP ke dept), jadi ditambah kolom tipe supaya balasan juga bisa disimpan di tabel
-- yang sama dan tampil sebagai satu timeline percakapan. Tabel ini juga belum punya
-- primary key/auto_increment yang benar (kolom id NOT NULL tanpa default) - sekalian
-- dibetulkan di sini karena tabelnya masih kosong (aman, tidak ada data yang berubah).
ALTER TABLE fpm_catatan_revisi
  MODIFY id INT NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD COLUMN tipe ENUM('CATATAN','BALASAN') NOT NULL DEFAULT 'CATATAN' AFTER dept_tujuan,
  ADD COLUMN role VARCHAR(50) NULL AFTER dibuat_oleh,
  ADD KEY idx_id_fpm (id_fpm);

-- Dept/PIC mana yang sedang "dipegang" oleh sebuah catatan aktif (belum dibalas) -
-- dipakai untuk menahan approval FPM sampai dibalas (reuse status_reject yang sudah
-- ada) dan menentukan siapa yang berhak membalas (Dept Head/Staff dept tsb, atau
-- Plant Head). NULL berarti tidak ada catatan PIC yang aktif (perilaku lama tidak berubah).
ALTER TABLE fpm_header
  ADD COLUMN catatan_target_dept VARCHAR(100) NULL AFTER status_reject;
