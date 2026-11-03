<?php
// Pengaman: halaman login asli ada di dist/pages/index.html. Kalau halaman login
// terbuka dari root (bookmark lama / cache browser), form akan POST ke sini dan
// sebelumnya berujung 404. Teruskan ke cek_login.php yang benar - 307 menjaga
// method POST beserta username/password.
header('Location: dist/pages/cek_login.php', true, 307);
exit;
