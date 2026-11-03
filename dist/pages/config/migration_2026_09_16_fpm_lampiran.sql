-- Lampiran umum FPM (terpisah dari bukti countermeasure), bisa lebih dari 1 file per FPM.
CREATE TABLE IF NOT EXISTS fpm_lampiran (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_fpm INT(11) NOT NULL,
  file VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fpm_lampiran_id_fpm (id_fpm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- File tambahan per baris Corrective/Preventive/Dokumen (mendukung > 1 file per baris,
-- kolom `file` yang lama tetap dipakai sebagai file utama/pertama).
CREATE TABLE IF NOT EXISTS fpm_corrective_files (
  id INT(11) NOT NULL AUTO_INCREMENT,
  corrective_id INT(11) NOT NULL,
  file VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_corrective_files_corrective_id (corrective_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fpm_preventive_files (
  id INT(11) NOT NULL AUTO_INCREMENT,
  preventive_id INT(11) NOT NULL,
  file VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_preventive_files_preventive_id (preventive_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fpm_dokumen_files (
  id INT(11) NOT NULL AUTO_INCREMENT,
  dokumen_id INT(11) NOT NULL,
  file VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_dokumen_files_dokumen_id (dokumen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
