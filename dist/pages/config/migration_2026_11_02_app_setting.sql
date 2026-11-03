-- Migration: pengaturan aturan aplikasi oleh Admin IT (2026-11-02)
--
-- Nilai aturan yang diubah lewat halaman admin_it/index.php disimpan di app_setting;
-- setiap perubahan dicatat di app_setting_log. Aturan yang belum pernah diubah memakai
-- nilai default di config/app_setting_helper.php (APP_SETTING_DEFINISI).
--
-- Tabel juga dibuat otomatis oleh helper kalau belum ada - file ini aman dijalankan berulang.
--
-- Akun Admin IT: tambahkan user dengan level 'Admin IT', mis.
--   INSERT INTO user (username, password, dept, level, plant) VALUES ('<username>', '<password>', 'IT', 'Admin IT', '');
-- Untuk login lewat portal productionflow, akun dengan username yang sama juga perlu diberi akses modul FPM di portal.

CREATE TABLE IF NOT EXISTS app_setting (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  diubah_oleh VARCHAR(50) NULL,
  diubah_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS app_setting_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(60) NOT NULL,
  nilai_lama VARCHAR(255) NULL,
  nilai_baru VARCHAR(255) NOT NULL,
  diubah_oleh VARCHAR(50) NULL,
  diubah_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_key (setting_key)
);
