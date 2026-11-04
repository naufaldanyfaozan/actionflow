-- Keamanan login (audit keamanan 2026-11-04) - lihat config/login_helper.php
--
-- Dijalankan otomatis oleh aplikasi (cek_login.php & portal_sso.php), file ini sebagai dokumentasi
-- dan untuk dijalankan manual bila user database hosting tidak punya hak ALTER/CREATE.
--
-- PENTING untuk productionflow: jalankan ALTER kolom password di database FPM productionflow
-- SEBELUM sync dari FPM standalone berikutnya, supaya hash password (60 karakter) tidak terpotong
-- oleh kolom lama VARCHAR(20).

-- 1) Kolom password muat hash password_hash() (bcrypt 60 karakter, disiapkan sampai 255)
ALTER TABLE `user` MODIFY `password` VARCHAR(255) NOT NULL;

-- 2) Catatan percobaan login gagal untuk pembatasan percobaan (lockout sementara)
CREATE TABLE IF NOT EXISTS login_gagal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    waktu DATETIME NOT NULL,
    KEY idx_username_waktu (username, waktu),
    KEY idx_ip_waktu (ip, waktu)
);

-- Password lama (teks biasa) TIDAK perlu diubah manual: otomatis diganti hash saat user
-- berhasil login berikutnya. Cek progres migrasi:
--   SELECT COUNT(*) AS total, SUM(password LIKE '$2y$%') AS sudah_hash FROM `user`;
