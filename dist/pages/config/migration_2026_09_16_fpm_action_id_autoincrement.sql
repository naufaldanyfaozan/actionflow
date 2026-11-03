-- Kolom `id` di 3 tabel ini sebelumnya bukan AUTO_INCREMENT/PRIMARY KEY.
-- Setiap kali form Jawab FPM disimpan, baris-baris di tabel ini dihapus lalu
-- di-insert ulang tanpa mengisi `id`, sehingga id selalu menjadi 0 - membuat
-- fitur "file tambahan per baris" (fpm_corrective_files, dst) salah kaitan
-- antar baris/antar FPM. Perbaikan ini menjadikan `id` PRIMARY KEY AUTO_INCREMENT
-- yang sesungguhnya, sekaligus memperbaiki bug lama tersebut.
ALTER TABLE fpm_corrective_action
  MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY;

ALTER TABLE fpm_preventive_action
  MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY;

ALTER TABLE fpm_review_dokumen
  MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY;
