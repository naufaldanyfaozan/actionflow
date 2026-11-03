-- Dokumen pendukung per baris Corrective/Preventive/Update Dokumen FPM (2026-11-03)
--
-- Sengaja tabel terpisah dari fpm_corrective_files / fpm_preventive_files / fpm_dokumen_files:
-- tabel-tabel itu adalah BUKTI perbaikan (dihitung kuota bukti, mengisi actual_date, masuk
-- Verifikasi Staff IMP). Dokumen pendukung hanya informasi - tidak mengubah status/actual_date.
-- Diisi & dihapus lewat config/fpm_aksi_pendukung_helper.php.
CREATE TABLE IF NOT EXISTS fpm_aksi_pendukung (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_fpm INT(11) NOT NULL,
  jenis VARCHAR(20) NOT NULL,
  id_aksi INT(11) NOT NULL,
  file VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  uploaded_by VARCHAR(100) NULL DEFAULT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fpm_aksi_pendukung_fpm (id_fpm),
  KEY idx_fpm_aksi_pendukung_aksi (jenis, id_aksi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
