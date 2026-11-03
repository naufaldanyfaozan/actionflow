# ACTIONFLOW — Sistem FPM

**Corrective Action, Monitoring & Improvement System.** Aplikasi web untuk mencatat masalah produksi
dalam bentuk **FPM (Form Penanganan Masalah)** dan **Asakai** (rapat pagi), lalu memantau jawaban,
countermeasure, approval berjenjang, sampai verifikasi. Ada reminder WhatsApp otomatis dan Surat
Peringatan (SP) bagi pihak yang terlambat.

> Untuk Claude / AI assistant: aturan kerja & konteks teknis ada di [CLAUDE.md](CLAUDE.md).

## Fitur utama

- **Jenis FPM:** Down Time (`FPM-D`), NG Internal (`FPM-N`), Claim Machining (`FPM-Cast`, modul QA).
- **Approval berjenjang per role:** Staff IMP → Head IMP → Dept terkait (jawab & approve) → Plant Head
  → Head IMP verifikasi → Direktur. Claim Machining: Staff QA → Dept Head QA → Plant Head → Dept
  tujuan → Plant Head → Direktur.
- **Countermeasure** (corrective, preventive, update dokumen) dengan PIC, due date, upload bukti
  (maks 3 file), permintaan revisi, reschedule (maks 3x), dan verifikasi CLOSED oleh Staff IMP / QA.
- **Asakai:** input problem harian Down Time & NG, pembahasan analisa & countermeasure, summary,
  dan **FPM dari Asakai** (buat sekarang / nanti, header terisi otomatis, peringatan & SP kalau lupa).
- **Monitor Approval & SP:** setiap tahap punya batas hari kerja. Lewat batas → Mendekati SP → SP
  bertingkat (SP 1–3, berlaku 6 bulan), opsional email otomatis ke HRD.
- **Reminder WhatsApp** (Fonnte) untuk jawaban, due date, overdue, dan FPM dari Asakai.
- **Dashboard** per role, grafik, LOG TPDP, Rekap Perbaikan Mingguan, Sasaran Mutu, export Excel /
  Word / PDF, cetak form FPM.
- **Admin IT:** atur batas H+/SP/reminder tanpa ubah kode, nyalakan/matikan WA per nomor, tambah
  nomor WA, email SP ke HRD, ganti nama & logo aplikasi, riwayat perubahan.
- **Jenis Masalah** dari master, dropdown bisa diketik untuk mencari.

## Teknologi

PHP native 8.2–8.4 · MariaDB/MySQL (mysqli) · AdminLTE 4 + Bootstrap 5.3 · Composer
(PhpSpreadsheet, PhpWord) · Node.js (hanya untuk build CSS/JS AdminLTE) · WhatsApp API Fonnte ·
cron PHP CLI.

## Instalasi lokal (XAMPP)

1. **Clone** ke folder XAMPP:
   ```bash
   cd C:\xampp\htdocs
   git clone https://github.com/naufaldanyfaozan/actionflow.git fpm
   cd fpm
   ```
2. **Dependency PHP:** `composer install`
3. **Build CSS/JS AdminLTE** (folder `dist/css`, `dist/js`, `dist/assets` tidak ada di git):
   ```bash
   npm install
   npm run css
   npm run js
   npm run assets
   ```
4. **File konfigurasi rahasia** — salin dari contoh di `dist/pages/config/`:
   | Buat file | Dari | Isi |
   |---|---|---|
   | `db_config.php` | `db_config.example.php` | lokal cukup `root` tanpa password, database `fpm` |
   | `env.php` | `env.example.php` | token Fonnte (boleh kosong di lokal) |
   | `wa_nomor.php` | `wa_nomor.example.php` | nomor WA penerima (boleh nomor contoh di lokal) |
5. **Database:** buat database `fpm` lalu import file SQL struktur dari pemilik project
   (minta *struktur saja*, tanpa data asli). Tabel tambahan dibuat otomatis saat halaman pertama kali
   dibuka; riwayatnya ada di `dist/pages/config/migration_*.sql`.
6. **Folder upload:** buat `dist/pages/uploads/fpm_dt`, `dist/pages/uploads/asakai`,
   `dist/pages/uploads/asakai_tambahan` (folder `dist/pages/upload_fpm` sudah ada).
7. Buka `http://localhost/fpm/dist/pages/index.html` dan login dengan akun dari tabel `user`.

Di lokal (Windows) WhatsApp & email **otomatis tidak dikirim**, jadi aman untuk uji coba.

## Hosting

- Upload isi project + file rahasia di atas (berisi kredensial hosting) ke server Linux, PHP ≥ 8.2.
- Pastikan folder upload dan `src/assets/img/branding/` bisa ditulis web server.
- Cron (hari kerja, jam diatur dari Admin IT):
  ```
  php /path/fpm/dist/pages/config/reminder_fpm.php
  php /path/fpm/dist/pages/config/reminder_asakai.php
  php /path/fpm/dist/pages/config/cron_verifikasi_3_bulan.php
  ```
- Halaman **Admin IT › Notifikasi WA & Email** menampilkan peringatan kalau file token / nomor WA
  belum ada di server.

## Cara berkontribusi

- Branch utama: `main`. Buat branch sendiri untuk setiap perubahan, push, lalu buka **Pull Request**
  — pemilik project memeriksa & merge.
- Sebelum mulai kerja: `git pull`.
- Jangan commit file rahasia (`db_config.php`, `env.php`, `wa_nomor.php`), file upload user, atau
  data database asli.

## Riwayat fitur

Riwayat commit sebelum repository ini dibuat tidak ikut di GitHub; ringkasannya:

**Agustus 2026**
- Baseline sistem FPM; fitur permintaan user 1–5; **Tanya Sistem** (cari countermeasure serupa).

**September 2026**
- Modul **QA** & alur **FPM Claim Machining**; guard reschedule Asakai; lampiran FPM multi-file.
- Lampiran pendukung, Actual Date otomatis, alur **permintaan perbaikan data**.
- **SSO portal productionflow** + tombol Ganti Modul.
- Definisi overdue H+1 + paginasi list overdue; LOG TPDP Claim Machining.
- Staff IMP bisa edit tindakan, due date, actual date & PIC countermeasure.
- Kompres gambar sebelum upload & cegah double submit; gagal kirim WA tidak menghentikan proses.
- Kredensial database dipusatkan di `config/db_config.php`.

**Oktober 2026**
- Dashboard: overdue berdasarkan status (bukan actual date); dashboard Dept Head QA; Sasaran Mutu
  format QMR; upload Dokumen/Foto NG sampai 3 file.
- Staff QA: Master Database (Produk & Problem); penyesuaian form & LOG TPDP Claim Machining.
- Login: tombol tampilkan password.
- Notif WA & alur approval Claim Machining sesuai flowchart; batas jawab 5/3 hari kerja + reminder.
- **Export Excel** semua list FPM & tabel overdue.
- **Permintaan revisi countermeasure** dari Staff IMP / Staff QA ke PIC (FPM & Asakai).
- List FPM: status approval & countermeasure terpisah, kolom Dijawab Oleh / Dept Dituju, filter,
  header freeze, paginasi per bulan.
- Edit FPM tidak lagi mengubah status approval; gate urutan approval FPM IMP.
- Reminder WA harian Dept Head sampai FPM dijawab & countermeasure CLOSED.
- Cek kejadian berulang: Nama Part + Mold # + Jenis Masalah.
- Upload bukti countermeasure maks 3 file + preview; Tanggal Problem Asakai wajib.
- Pemisahan verifikasi: FPM-Cast di Staff QA, FPM-D/FPM-N di Staff IMP.
- **Reschedule** due date countermeasure (maks 3x) + notifikasi, upload bukti di modal reschedule.
- Cetak/JPEG/PDF form FPM identik tampilan; grafik Total Jenis FPM.
- Riwayat revisi & reschedule tetap ada setelah CLOSED; filter Summary Asakai; lampiran partial &
  hapus file salah upload.
- **Vice Direktur dihapus** dari semua alur (7 Okt 2026).

**November 2026**
- **Admin IT:** saklar WA per nomor & tambah nomor WA, email SP ke HRD, ganti nama & logo aplikasi.
- **Monitor Approval & SP** bertingkat dengan batas yang diatur Admin IT.
- File rahasia keluar dari git (`*.example.php`); repository GitHub dibuat.
- **Jenis Masalah** bisa diketik untuk mencari, hanya dari master (divalidasi server).
- **FPM dari Asakai:** buat sekarang / nanti, header & jenis masalah terisi otomatis, peringatan H+1,
  SP H+2 untuk Staff IMP, reminder WA, menu & badge "FPM dari Asakai" (Staff IMP & Head IMP).

Perubahan berikutnya: lihat riwayat commit di GitHub.

## Lisensi

Tampilan dibangun di atas template [AdminLTE](https://adminlte.io) (lisensi MIT, lihat `LICENSE`).
