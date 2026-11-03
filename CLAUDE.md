# CLAUDE.md — ACTIONFLOW (FPM)

Panduan untuk Claude yang bekerja di repo ini. Baca seluruhnya sebelum mengubah kode.
Gambaran produk, instalasi & riwayat fitur ada di [README.md](README.md).

## Aturan kerja (wajib)

- **Balas user dalam Bahasa Indonesia.** Komentar kode juga Bahasa Indonesia, mengikuti gaya yang ada.
- **Ubah hanya yang diminta.** Banyak halaman punya salinan hampir identik per role
  (mis. `dept_head/jawab_fpm.php`, `staff/jawab_fpm.php`, `plant_head/jawab_fpm.php`). Kalau bug
  yang sama ada di file/role lain, **sebutkan & tanya dulu** — jangan ikut diperbaiki diam-diam.
  User pernah mengalami fitur yang tadinya jalan jadi rusak karena perubahan yang tidak diminta.
  Pengecualian: user bilang "semua" / "sekalian" / "berlaku untuk semua FPM".
- **Uji lalu bersihkan.** Setelah menguji dengan data di database (insert FPM uji, tugas, SP, dll),
  hapus lagi semua data uji sampai kondisi DB sama seperti sebelumnya.
- **Commit / push hanya kalau diminta.** Akhiri pesan commit dengan baris Co-Authored-By sesuai
  instruksi sistem Claude Code.

## Aturan bisnis yang TIDAK boleh diubah

- **Status countermeasure:** upload bukti (FPM corrective/preventive/dokumen, Asakai DT/NG) hanya
  menyimpan file & mengisi `actual_date` — status **tetap OPEN**. Hanya **Staff IMP** (menu Verifikasi
  Countermeasure) yang mengubah ke CLOSED (FPM Claim Machining: Staff QA). Jangan pernah set CLOSED
  otomatis dari upload/actual_date. "Overdue"/"open" selalu dihitung dari `status` (NULL = belum
  closed), **bukan** dari `actual_date`.
- **Tidak ada Vice Direktur** di alur mana pun sejak 2026-10-07. Alur IMP: Staff IMP → Head IMP →
  Dept terkait → Plant Head → Head IMP verifikasi → Direktur. Evaluasi 3 bulan: dibuat Staff IMP,
  diperiksa Head IMP, disetujui Direktur. Folder `vice_directur/` hanya untuk data historis
  (read-only) — jangan tambah gate/tombol/notif yang bergantung pada Vice Direktur.
- **Hari kerja:** semua batas H+ (Monitor Approval, SP, reminder, FPM dari Asakai) dihitung hari
  kerja (Sabtu & Minggu tidak dihitung) lewat `monitoringHariKerja()` /
  `monitoringTambahHariKerja()` di `config/monitoring_approval_helper.php`.
- **Jenis Masalah hanya dari master** (`jenis_masalah`) — dropdown bisa diketik (Tom Select) tapi
  tidak bisa isi bebas; server memvalidasi (`config/jenis_masalah_helper.php`). Satu-satunya
  pengecualian: FPM yang dibuat dari pembahasan Asakai — problem Asakai itu otomatis masuk master
  saat FPM disimpan (`daftarkanJenisMasalahDariAsakai()`).

## Teknologi

PHP native (kompatibel 8.2–8.4, XAMPP lokal 8.2) · MariaDB 10.4 via **mysqli** · AdminLTE 4 /
Bootstrap 5.3 · Composer: PhpSpreadsheet, PhpWord · WhatsApp via API **Fonnte** (cURL) · email via
`mail()` · cron PHP CLI. Tidak ada framework, tidak ada test suite otomatis.

## Struktur

```
dist/pages/
  index.html, cek_login.php      login → redirect per level ke folder role
  koneksi.php                    koneksi DB ($koneksi) + helper upload; dipakai semua halaman
  navbar.php, footer.php         bagian layout bersama
  <role>/                        halaman per role (lihat tabel), masing-masing punya sidebar.php
  config/                        helper bersama, view bersama, cron, migration_*.sql
  services/whatsapp.php          kirimWA() — SEMUA WA lewat fungsi ini
  services/email.php             kirimEmail()
  upload_fpm/, uploads/          file upload user (di-.gitignore)
dist/css/dashboard-modern.css, dist/js/{dashboard-widgets,print-export}.js   aset buatan sendiri
src/                             sumber SCSS/TS AdminLTE (build ke dist/css & dist/js)
```

| Folder role | Level user | Catatan |
|---|---|---|
| `staff_imp` | Staff IMP | membuat FPM Down Time & NG Internal, Asakai, verifikasi countermeasure |
| `head_imp` | Head IMP | approve & verifikasi FPM IMP |
| `staff` | Staff | jawab FPM dept; Staff dept QA juga membuat FPM Claim Machining (`sidebar_qa.php`) |
| `dept_head` | Dept Head | jawab & approve FPM dept-nya |
| `dept_head_qa` | Dept Head QA | approve FPM Claim Machining |
| `plant_head` | Plant Head | memeriksa FPM |
| `directur` | Directure | persetujuan akhir |
| `vice_directur` | Vice Directure | **historis saja** (lihat aturan bisnis) |
| `admin_it` | Admin IT | pengaturan aturan (app_setting), notifikasi WA/email, identitas aplikasi, riwayat |

Plant user ada di `$_SESSION['plant']` dengan format `"Plant 1"`; di tabel FPM/Asakai format-nya
`'1'` — normalisasi dengan `trim(str_ireplace('Plant', '', ...))`.

## Konvensi kode

- **Helper bersama di `config/*_helper.php`**, halaman bersama di `config/*_view.php` yang
  di-include dari halaman tipis per role (contoh: `monitoring_approval.php` →
  `config/monitoring_approval_view.php`; `fpm_asakai.php` → `config/asakai_fpm_view.php`).
  Fitur baru yang dipakai beberapa role: buat helper/view di `config/`, bukan copy-paste per role.
- **Tabel baru dibuat otomatis** oleh fungsi `siapkanTabel...()` (CREATE TABLE IF NOT EXISTS) dan
  didokumentasikan di `config/migration_YYYY_MM_DD_*.sql`. **Jangan jalankan DDL di dalam transaksi**
  simpan data — DDL MySQL memicu commit implisit (panggil setelah `mysqli_commit` atau saat halaman
  dibuka).
- **Aturan yang bisa diatur** (batas H+, jam reminder, dll) ada di `APP_SETTING_DEFINISI`
  (`config/app_setting_helper.php`), dibaca dengan `appSettingInt()` / `appSettingBool()` /
  `appSetting()`, diubah dari menu Admin IT. Jangan hardcode angka batas baru — tambahkan setting.
- Query baru pakai **prepared statement**. Kode lama banyak memakai string SQL langsung — jangan
  ubah kode lama yang tidak diminta.
- `WA_NOTIF_ENABLED` / `EMAIL_NOTIF_ENABLED` otomatis **mati di Windows (XAMPP)** dan hidup di
  hosting (Linux) — aman menguji lokal tanpa WA terkirim ke nomor asli.
- Kegagalan kirim WA tidak boleh menghentikan proses yang datanya sudah tersimpan — cukup `error_log`.
- Nama brand jangan di-hardcode: pakai `brandNama()` / `brandLogo()` (`config/branding_helper.php`).

## File rahasia (TIDAK ada di git)

| File | Isi | Contoh |
|---|---|---|
| `dist/pages/config/db_config.php` | kredensial DB local & hosting | `db_config.example.php` |
| `dist/pages/config/env.php` | token Fonnte | `env.example.php` |
| `dist/pages/config/wa_nomor.php` | nomor WA penerima per role/dept | `wa_nomor.example.php` |

Jangan pernah menulis password, token, atau nomor WA asli ke file yang ter-track git. Logika WA ada di
`config/wa_map.php` (ter-track) yang membaca `wa_nomor.php`. Nomor tambahan juga bisa dikelola dari
Admin IT (tabel `wa_nomor_tambahan`).

## Alur & modul penting

- **FPM IMP** (Down Time `FPM-D/..`, NG Internal `FPM-N/..`): tahap & H+ di
  `monitoringTahapImp()`. **FPM Claim Machining** (`FPM-Cast/..`, modul QA, `flow_type='CLAIMMACH'`):
  `config/fpm_claim_machining_helper.php`, `monitoringTahapClaimMach()`.
- **Monitor Approval & SP**: `config/monitoring_approval_helper.php`. Pelanggaran dicatat permanen di
  `fpm_sp_pelanggaran` per pihak (mis. `Dept Head PPC`, `Head IMP Plant 1`, `Staff IMP Plant 1`);
  level SP dihitung ulang dari riwayat (berlaku 6 bulan, naik level kalau terulang). Pencatatan
  dipanggil dari sidebar (`hitungSpMonitoringApproval`) & cron. Email SP ke HRD:
  `config/sp_email_hrd_helper.php` (saklar di Admin IT, default nonaktif).
- **Asakai**: pembahasan di `staff_imp/form_pembahasan_asakai.php`. **FPM dari Asakai**
  (`config/asakai_fpm_helper.php`, tabel `asakai_fpm_tugas`): Staff IMP menandai "Buat sekarang /
  nanti"; form FPM terisi dari Asakai (`?asakai=<id>`); FPM yang disimpan ditautkan
  (`tautkanFpmKeAsakai`, juga cocok otomatis lewat mesin+part); belum dibuat → H+1 peringatan,
  H+2 SP `Staff IMP Plant X`. Menu "FPM dari Asakai" di Staff IMP (buat) & Head IMP (pantau saja).
- **Countermeasure**: revisi (`countermeasure_revisi_helper.php`), reschedule maks 3x
  (`fpm_reschedule_helper.php`), lampiran maks 3 file (`fpm_lampiran_helper.php`).
- **Cron** (jalan di hosting): `config/reminder_fpm.php`, `config/reminder_asakai.php`,
  `config/cron_verifikasi_3_bulan.php` — hanya hari kerja & dalam jam yang diatur Admin IT.
  Jangan jalankan cron reminder di lokal untuk uji: walau WA tidak terkirim, log `wa_reminder_log`
  tetap tercatat untuk data asli.

## Menguji perubahan

- Lint: `C:/xampp/php/php.exe -l <file>`.
- Render halaman dari CLI dengan session palsu (set `$_SESSION` username/level/dept/plant, `chdir` ke
  folder role, `include` halamannya) lalu cari `Warning|Notice|Fatal` di output — bandingkan dengan
  versi sebelum perubahan (`git stash`) kalau ada warning.
- Cek tampilan: buka `http://localhost/fpm/dist/pages/...` (login dengan akun sesuai role).
- Setelah uji, hapus semua data uji (FPM, tugas, SP, master) dan kembalikan AUTO_INCREMENT bila perlu.

## Git & deploy

- Branch kerja: **`main`** (GitHub `actionflow`). Rekan kerja: branch sendiri + Pull Request.
- Di laptop pemilik ada branch lokal **`master`** = arsip riwayat lama yang masih berisi kredensial —
  **jangan pernah di-push** (`git push --all` / `git push origin master` dilarang).
- **productionflow** (`C:\xampp\htdocs\productionflow`, monorepo portal) menarik FPM lewat
  `git subtree`. Ubah kode **di repo ini dulu**, commit, lalu di productionflow:
  `git subtree pull --prefix=fpm fpm-src main --squash -m "Sync fpm: <ringkasan>"`. Jangan edit
  `productionflow/fpm/` langsung (kecuali fix khusus productionflow yang disetujui user). Di
  productionflow file rahasia FPM ada di `productionflow/config/fpm/` dan DB dari config pusat;
  folder upload FPM berupa junction ke folder repo ini.
- Hosting FPM mandiri di-upload manual. Saat upload `config/wa_map.php` / `services/whatsapp.php`
  baru, pastikan `config/wa_nomor.php`, `config/env.php` & `config/file_rahasia.php` juga ada
  (halaman Admin IT › Notifikasi menampilkan peringatan merah kalau belum).
- Jaga perubahan pada kode koneksi DB tetap minimal — FPM mandiri tetap dipakai & di-deploy sendiri.

## Catatan teknis yang diketahui

- Tidak semua halaman role memeriksa `$_SESSION['level']` (hanya cek login).
- Password user disimpan apa adanya (plain text) di tabel `user`.
- `head_imp/proses_down_time.php` tidak menyimpan Jenis Masalah (bug lama, belum diperbaiki).
- Form edit `staff/edit_fpm_qa_internal.php`, `staff_imp/edit_fpm_down_time.php`,
  `staff_imp/edit_fpm_internal.php` masih menerima Jenis Masalah ketik bebas.
- Belum ada dump struktur database di repo — minta file SQL struktur ke pemilik project.
