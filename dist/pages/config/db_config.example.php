<?php
// CONTOH format config/db_config.php - kredensial database FPM yang berjalan mandiri.
//
// Cara pakai: salin file ini menjadi db_config.php (folder yang sama) lalu isi kredensial
// hosting. db_config.php di-.gitignore supaya password tidak ikut tersimpan di git.
//
// 'local'   dipakai di XAMPP (Windows), 'hosting' dipakai di server (Linux) - deteksinya
// ada di koneksi.php. Di dalam productionflow file ini tidak diperlukan: koneksi.php
// otomatis memakai config/productionflow_db.php milik productionflow.
return [
	'local' => [
		'host' => 'localhost',
		'user' => 'root',
		'pass' => '',
		'name' => 'fpm',
	],
	'hosting' => [
		'host' => 'localhost',
		'user' => '',
		'pass' => '',
		'name' => '',
	],
];
