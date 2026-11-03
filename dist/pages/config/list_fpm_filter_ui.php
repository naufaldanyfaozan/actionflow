<?php

/**
 * Tampilan filter list FPM yang lebih mudah dipahami (semua halaman list FPM, semua role):
 *  - Dropdown & tanggal langsung diterapkan begitu dipilih (tidak perlu klik tombol).
 *    Tanggal baru diterapkan kalau Tanggal Awal & Tanggal Akhir dua-duanya terisi.
 *  - Kolom filter yang sedang dipakai diberi warna biru.
 *  - Baris "Filter aktif" di bawah form, tiap filter bisa dilepas dengan klik tanda x.
 *
 * Pemakaian: panggil tepat setelah </form> filter (script mengambil form sebelumnya):
 *   <?= renderFilterUiListFpm() ?>
 */
function renderFilterUiListFpm(): string
{
    return <<<'HTML'
<style>
    .fpm-filter-aktif { margin: -8px 0 14px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 13px; }
    .fpm-filter-aktif .judul { font-weight: 600; color: #495057; }
    .fpm-filter-aktif .kosong { color: #6c757d; }
    .fpm-filter-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 6px 3px 10px; border-radius: 999px; background: #e7f1ff; color: #0a58ca; border: 1px solid #b6d4fe; }
    .fpm-filter-chip button { border: none; background: #0a58ca; color: #fff; width: 18px; height: 18px; border-radius: 50%; font-size: 11px; line-height: 1; padding: 0; cursor: pointer; }
    .fpm-filter-hint { font-size: 12px; color: #6c757d; margin: -10px 0 8px; }
    form .fpm-filter-terisi { border-color: #0d6efd !important; background-color: #f0f6ff !important; }
    form .fpm-filter-perlu { border-color: #dc3545 !important; }
    form.fpm-filter-memuat { opacity: .6; pointer-events: none; }
</style>
<script>
    (function() {
        // Form filter = elemen <form> terdekat sebelum script ini (lewati <style> milik helper)
        let form = document.currentScript.previousElementSibling;
        while (form && form.tagName !== 'FORM') {
            form = form.previousElementSibling;
        }
        if (!form) return;

        const NAMA_TANGGAL = ['tgl_awal', 'tgl_akhir'];
        const kontrol = Array.from(form.querySelectorAll('select[name], input[name]'))
            .filter(el => !['submit', 'button', 'hidden'].includes((el.type || '').toLowerCase()));
        const ambil = nama => form.querySelector('[name="' + nama + '"]');

        function labelDari(el) {
            const label = el.parentNode.querySelector('label');
            return label ? label.textContent.replace(/\s+/g, ' ').trim() : el.name;
        }

        function teksNilai(el) {
            if (el.tagName === 'SELECT') {
                const opt = el.options[el.selectedIndex];
                return opt ? opt.textContent.trim() : el.value;
            }
            return el.value.trim();
        }

        function formatTanggal(v) {
            const p = v.split('-');
            return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0] : v;
        }

        // Bulan yang sedang dipilih di paginasi ikut terbawa saat filter lain diganti / Cari,
        // supaya user tidak terlempar ke bulan terbaru. Kalau bulan itu kosong untuk filter
        // baru, server memindahkan ke bulan yang ada datanya (list_fpm_paginasi_helper.php).
        const bulanAktif = new URLSearchParams(location.search).get('bulan');
        let inputBulan = null;
        if (bulanAktif && !form.querySelector('[name="bulan"]')) {
            inputBulan = document.createElement('input');
            inputBulan.type = 'hidden';
            inputBulan.name = 'bulan';
            inputBulan.value = bulanAktif;
            form.appendChild(inputBulan);
        }

        // Rentang tanggal menentukan periode sendiri - bulan paginasi dilepas.
        function lepasBulan() {
            if (inputBulan) {
                inputBulan.remove();
                inputBulan = null;
            }
        }

        function kirim() {
            form.classList.add('fpm-filter-memuat');
            form.submit();
        }

        // Petunjuk singkat di atas form
        const hint = document.createElement('div');
        hint.className = 'fpm-filter-hint';
        hint.textContent = 'Filter langsung diterapkan saat dipilih, bulan yang sedang dibuka tetap dipertahankan. Untuk mencari kata, ketik di kotak pencarian lalu tekan Enter atau klik Cari.';
        form.insertAdjacentElement('beforebegin', hint);

        // Tandai kolom yang sedang dipakai (nilai dari server, sebelum diubah user)
        const tglAwal = ambil('tgl_awal');
        const tglAkhir = ambil('tgl_akhir');
        const rentangTanggalAktif = tglAwal && tglAkhir && tglAwal.value && tglAkhir.value;

        kontrol.forEach(el => {
            const isTanggal = NAMA_TANGGAL.includes(el.name);
            if (el.value.trim() !== '' && (!isTanggal || rentangTanggalAktif)) {
                el.classList.add('fpm-filter-terisi');
            }
        });

        // Baris "Filter aktif"
        const bar = document.createElement('div');
        bar.className = 'fpm-filter-aktif';
        const judul = document.createElement('span');
        judul.className = 'judul';
        judul.textContent = 'Filter aktif:';
        bar.appendChild(judul);

        function tambahChip(teks, lepas) {
            const chip = document.createElement('span');
            chip.className = 'fpm-filter-chip';
            chip.appendChild(document.createTextNode(teks));
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.title = 'Lepas filter ini';
            btn.textContent = '✕';
            btn.addEventListener('click', function() {
                lepas();
                kirim();
            });
            chip.appendChild(btn);
            bar.appendChild(chip);
        }

        if (rentangTanggalAktif) {
            tambahChip('Tanggal: ' + formatTanggal(tglAwal.value) + ' s/d ' + formatTanggal(tglAkhir.value), function() {
                tglAwal.value = '';
                tglAkhir.value = '';
                lepasBulan();
            });
        }
        kontrol.forEach(el => {
            if (NAMA_TANGGAL.includes(el.name) || el.value.trim() === '') return;
            tambahChip(labelDari(el) + ': ' + teksNilai(el), function() {
                el.value = '';
            });
        });

        if (bar.children.length === 1) {
            const kosong = document.createElement('span');
            kosong.className = 'kosong';
            kosong.textContent = 'tidak ada - menampilkan semua data';
            bar.appendChild(kosong);
        }
        form.insertAdjacentElement('afterend', bar);

        // Dropdown langsung diterapkan
        form.querySelectorAll('select[name]').forEach(el => {
            el.addEventListener('change', kirim);
        });

        // Tanggal diterapkan kalau dua-duanya terisi (atau dua-duanya dikosongkan)
        if (tglAwal && tglAkhir) {
            [tglAwal, tglAkhir].forEach(el => {
                el.addEventListener('change', function() {
                    const awal = tglAwal.value;
                    const akhir = tglAkhir.value;
                    tglAwal.classList.toggle('fpm-filter-perlu', !awal && !!akhir);
                    tglAkhir.classList.toggle('fpm-filter-perlu', !!awal && !akhir);
                    if ((awal && akhir) || (!awal && !akhir && rentangTanggalAktif)) {
                        lepasBulan();
                        kirim();
                    }
                });
            });
        }
    })();
</script>
HTML;
}
