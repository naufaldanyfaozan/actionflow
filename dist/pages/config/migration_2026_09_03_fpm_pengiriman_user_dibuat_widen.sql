-- Migration: lebarkan fpm_pengiriman.user_dibuat (2026-09-03)
--
-- Kolom ini tadinya varchar(15) - cukup untuk satu username, tapi fitur "DIBUAT
-- menampilkan dept/PIC yang sudah approve" di FPM Claim Machining (lihat
-- approve_pic_claim_mach di dept_head/form_fpm.php) mengisinya dengan daftar
-- beberapa nama dept dipisah koma (mis. "PRO-CAST, MTC-MACH, QC-CAST"), yang
-- bisa lebih dari 15 karakter dan akan terpotong tanpa perubahan ini.
ALTER TABLE fpm_pengiriman
  MODIFY user_dibuat VARCHAR(255) NOT NULL;
