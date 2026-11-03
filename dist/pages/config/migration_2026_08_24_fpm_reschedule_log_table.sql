-- Migration: buat tabel fpm_reschedule_log kalau belum ada (2026-08-24)
--
-- dist/pages/staff/edit_fpm_downtime.php dan dist/pages/dept_head/edit_fpm_downtime.php
-- sudah lama membaca & menulis ke tabel ini (dan migration_2026_08_21_fpm_feature_requests.sql
-- bahkan sudah ALTER TABLE kolomnya), tapi CREATE TABLE aslinya ternyata tidak pernah
-- tersimpan sebagai migration - jadi di environment mana pun tabel ini belum pernah
-- benar-benar dibuat, halaman edit_fpm_downtime.php akan Fatal Error (mysqli_prepare()
-- gagal karena tabelnya tidak ada, lalu bind_param() dipanggil di atas hasil false).
-- Pakai IF NOT EXISTS supaya aman dijalankan di environment yang tabelnya sudah ada.
CREATE TABLE IF NOT EXISTS fpm_reschedule_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fpm INT NOT NULL,
  id_action INT NULL,
  jenis_action ENUM('CORRECTIVE','PREVENTIVE') NOT NULL,
  tindakan TEXT NOT NULL,
  due_date_lama DATE NOT NULL,
  due_date_baru DATE NOT NULL,
  alasan TEXT NOT NULL,
  pic VARCHAR(100) NULL,
  direschedule_oleh VARCHAR(100) NOT NULL,
  direschedule_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_id_fpm (id_fpm),
  KEY idx_id_action (id_action)
);
