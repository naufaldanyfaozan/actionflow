<?php
// File rahasia FPM (token Fonnte di env.php, nomor WA karyawan di wa_nomor.php) TIDAK masuk
// git - tiap instalasi punya salinannya sendiri, dibuat dari file *.example.php di folder ini.
//
// Kalau FPM berjalan di dalam productionflow, subtree fpm/ tidak membawa file ini (karena
// tidak ada di git), jadi salinannya disimpan di config/fpm/ di root productionflow
// (di-.gitignore juga di sana, diupload manual ke hosting seperti config/db_secret.php).

if (!function_exists('fileRahasiaFpm')) {
    /** Path file rahasia $nama, atau null kalau belum dibuat di instalasi ini. */
    function fileRahasiaFpm(string $nama): ?string
    {
        $kandidat = [
            __DIR__ . '/' . $nama,
            __DIR__ . '/../../../../config/fpm/' . $nama,
        ];
        foreach ($kandidat as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }
}
