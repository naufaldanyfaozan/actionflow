<?php

// Kelompok sinonim istilah masalah produksi/maintenance yang umum dipakai
// bergantian di lapangan tapi beda kata dasar (jadi tidak akan ketemu lewat
// LIKE substring biasa). Kalau salah satu kata di kalimat user cocok dengan
// satu kelompok, kata-kata lain di kelompok itu ikut ditambahkan ke pencarian
// supaya riwayat yang pakai istilah berbeda tetap ketemu.
// Didefinisikan di atas (bukan dekat fungsi yang memakainya) karena `const`
// di top-level PHP dieksekusi berurutan, bukan di-hoist seperti fungsi -
// kalau ditaruh di bawah titik pemanggilan pertama, akan error "Undefined
// constant" walau textually berada di file yang sama.
const TS_SINONIM = [
    ['bocor', 'rembes', 'netes', 'menetes', 'kebocoran'],
    ['macet', 'nyangkut', 'seret', 'ngadat', 'jam'],
    ['patah', 'pecah', 'retak', 'gompal', 'sompel', 'crack'],
    ['rusak', 'error', 'trouble', 'ngadat', 'rewel'],
    ['aus', 'tipis', 'terkikis', 'kikis'],
    ['kendor', 'longgar', 'oblak', 'goyang'],
    ['kotor', 'korosi', 'karat', 'kerak'],
    ['panas', 'overheat', 'kepanasan'],
    ['bunyi', 'berisik', 'kasar', 'suara'],
    ['getar', 'vibrasi', 'oleng', 'goyang'],
    ['lambat', 'lemot', 'lelet', 'lama'],
    ['dimensi', 'ukuran', 'oversize', 'undersize'],
    ['gores', 'baret', 'lecet', 'scratch'],
    ['penyok', 'dented', 'peyang'],
    ['alarm', 'trip', 'stop'],
];

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesi Anda telah berakhir. Silakan login ulang.']);
    exit;
}

include __DIR__ . '/../koneksi.php';
include __DIR__ . '/riwayat_countermeasure_helper.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$message = trim((string)($input['message'] ?? ''));
$machine = trim((string)($input['mesin'] ?? ''));
$part = trim((string)($input['part'] ?? ''));

// Konteks percakapan sebelumnya (dikirim balik oleh frontend, lihat footer.php) -
// dipakai supaya balasan "itu sudah saya coba tapi gagal" bisa nyambung ke
// pencarian sebelumnya, bukan dianggap pertanyaan baru yang tidak ketemu apa-apa.
$prevMessage = trim((string)($input['prevMessage'] ?? ''));
$prevExcluded = [];
if (is_array($input['prevExcluded'] ?? null)) {
    $prevExcluded = array_values(array_filter(array_map('strval', $input['prevExcluded'])));
}

if (mb_strlen($message) < 3) {
    echo json_encode(['error' => 'Deskripsi masalah minimal 3 karakter.']);
    exit;
}

$isFollowUpKegagalan = $prevMessage !== '' && mengandungSinyalKegagalan($message);

// Kalau ini balasan "belum berhasil" atas jawaban sebelumnya, cari ulang pakai
// masalah yang sama (bukan kalimat follow-up-nya, yang biasanya tidak punya
// kata kunci relevan sama sekali).
$searchMessage = $isFollowUpKegagalan ? $prevMessage : $message;
$keywords = ekstrakKataKunci($searchMessage);

// Ambil kandidat dalam pool yang jauh lebih besar dari yang ditampilkan
// (masih diurutkan tanggal terbaru dulu oleh SQL). Kalau limitnya kecil
// (mis. 8) dan riwayatnya sudah banyak, riwayat lama yang justru paling
// relevan bisa keburu tersingkir oleh riwayat baru yang cuma nyambung
// kata kunci secara kebetulan - sebelum sempat dinilai relevansinya sama
// sekali. Makanya penilaian & pengurutan relevansi (skorRelevansi) di
// bawah ini dilakukan di atas pool besar ini dulu, baru dipotong ke
// jumlah yang ditampilkan.
const TS_POOL_SIZE = 40;
const TS_DISPLAY_LIMIT = 8;

$riwayat = cariRiwayatCountermeasure($koneksi, $keywords['search'], $machine, $part, TS_POOL_SIZE);
foreach ($riwayat as &$r) {
    $r['_key'] = riwayatKey($r);
}
unset($r);

usort($riwayat, function ($a, $b) use ($keywords) {
    $skorA = skorRelevansi($a, $keywords['original']);
    $skorB = skorRelevansi($b, $keywords['original']);
    if ($skorA !== $skorB) {
        return $skorB <=> $skorA;
    }
    $closedA = strtoupper((string)($a['status'] ?? '')) === 'CLOSED';
    $closedB = strtoupper((string)($b['status'] ?? '')) === 'CLOSED';
    if ($closedA !== $closedB) {
        return $closedB <=> $closedA;
    }
    return strtotime((string)($b['tanggal'] ?? '')) <=> strtotime((string)($a['tanggal'] ?? ''));
});
$riwayat = array_slice($riwayat, 0, TS_DISPLAY_LIMIT);

$kandidat = $isFollowUpKegagalan
    ? array_values(array_filter($riwayat, function ($r) use ($prevExcluded) {
        return !in_array($r['_key'], $prevExcluded, true);
    }))
    : $riwayat;

$terbaikKey = pilihRiwayatTerbaikKey($kandidat, $keywords['original']);
$terbaik = $terbaikKey !== null ? $kandidat[$terbaikKey] : null;

$reply = $isFollowUpKegagalan
    ? bangunJawabanFollowUp($kandidat, $terbaik)
    : bangunJawabanRiwayat($riwayat, $terbaik);

$usedKeys = $prevExcluded;
if ($terbaik) {
    $usedKeys[] = $terbaik['_key'];
}

$sources = array_values(array_filter($kandidat, function ($r) use ($terbaik) {
    return !$terbaik || $r['_key'] !== $terbaik['_key'];
}));
// Riwayat lain juga diurutkan dari yang paling relevan dulu, bukan cuma
// tanggal, dengan alasan yang sama seperti pemilihan jawaban utama.
usort($sources, function ($a, $b) use ($keywords) {
    return skorRelevansi($b, $keywords['original']) <=> skorRelevansi($a, $keywords['original']);
});
foreach ($sources as &$s) {
    unset($s['_key']);
}
unset($s);

echo json_encode([
    'reply' => $reply,
    'sources' => $sources,
    // Dikirim balik oleh frontend di request berikutnya supaya server bisa
    // mendeteksi follow-up "itu sudah saya coba" tanpa perlu session/DB state.
    'contextMessage' => $searchMessage,
    'usedKeys' => $usedKeys,
]);

/**
 * Kunci unik untuk satu baris riwayat countermeasure, dipakai untuk melacak
 * "sudah pernah disarankan yang mana" lewat request (server tidak menyimpan
 * state percakapan sama sekali).
 */
function riwayatKey(array $r): string
{
    return md5(
        ($r['sumber'] ?? '') . '|' . ($r['id_fpm'] ?? '') . '|' . ($r['id_asakai'] ?? '')
        . '|' . ($r['tindakan'] ?? '') . '|' . ($r['due_date'] ?? '')
    );
}

/**
 * Deteksi sederhana (tanpa AI) apakah pesan user menyatakan bahwa
 * tindakan/saran sebelumnya sudah dicoba tapi belum menyelesaikan masalah.
 */
function mengandungSinyalKegagalan(string $message): bool
{
    $m = mb_strtolower($message);
    $pola = [
        'tidak berhasil', 'blm berhasil', 'belum berhasil', 'ga berhasil', 'gak berhasil', 'nggak berhasil',
        'enggak berhasil', 'gagal', 'masih terjadi', 'masih sama', 'masih muncul', 'masih ada',
        'sudah saya coba', 'sudah dicoba', 'sudah saya lakukan', 'sudah dilakukan', 'udah dicoba',
        'udah saya coba', 'tetap terjadi', 'tetap muncul', 'tidak selesai', 'belum selesai',
        'belum tuntas', 'tidak menyelesaikan', 'terulang lagi', 'kambuh lagi',
    ];
    foreach ($pola as $p) {
        if (mb_strpos($m, $p) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Ambil kata kunci signifikan dari kalimat bebas pengguna. Mengembalikan dua
 * daftar terpisah:
 * - 'search'   : kata kunci asli + sinonimnya (lihat TS_SINONIM), dipakai
 *                untuk query SQL supaya lebih banyak riwayat ketemu walau
 *                istilahnya beda.
 * - 'original' : cuma kata kunci asli dari kalimat user (TANPA sinonim),
 *                dipakai untuk MERANKING hasil pencarian di skorRelevansi().
 *                Ini penting - kalau sinonim ikut dipakai untuk ranking,
 *                riwayat yang cuma nyambung lewat sinonim (mis. "gompal"
 *                -> "pecah") bisa mengalahkan riwayat yang justru match
 *                persis dengan kata asli user, cuma karena kebetulan lebih
 *                baru tanggalnya.
 */
function ekstrakKataKunci(string $message): array
{
    $stopwords = [
        'yang', 'untuk', 'dengan', 'pada', 'dan', 'atau', 'ini', 'itu', 'ada', 'saya', 'kami',
        'tolong', 'bagaimana', 'kenapa', 'mengapa', 'apa', 'apakah', 'adalah', 'dari', 'ke',
        'di', 'karena', 'akan', 'sudah', 'belum', 'bisa', 'tidak', 'tapi', 'juga', 'saat',
        'ketika', 'part', 'mesin', 'masalah', 'terjadi', 'sedang', 'nih', 'dong', 'kok',
    ];
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY);
    $original = [];
    foreach ($words as $w) {
        if (mb_strlen($w) >= 3 && !in_array($w, $stopwords, true)) {
            $original[] = $w;
        }
    }
    $original = array_values(array_unique($original));
    if (!$original) {
        $original = [$message];
    }

    // Tambahkan sinonim dari kata kunci yang sudah ketemu, khusus untuk
    // memperluas cakupan pencarian SQL (bukan untuk ranking).
    $tambahan = [];
    foreach ($original as $kw) {
        foreach (TS_SINONIM as $grup) {
            if (in_array($kw, $grup, true)) {
                foreach ($grup as $sin) {
                    if ($sin !== $kw) {
                        $tambahan[] = $sin;
                    }
                }
            }
        }
    }
    $search = array_values(array_unique(array_merge($original, $tambahan)));
    $search = array_slice($search, 0, 10);

    return ['search' => $search, 'original' => $original];
}

/**
 * Berapa banyak kata kunci ASLI (bukan hasil ekspansi sinonim) yang muncul
 * di masalah/tindakan riwayat ini. Dipakai supaya riwayat yang match persis
 * dengan yang ditanyakan user selalu menang dibanding riwayat yang cuma
 * nyambung lewat sinonim (yang bisa jadi topik lain sama sekali).
 */
function skorRelevansi(array $r, array $originalKeywords): int
{
    $teks = mb_strtolower(($r['masalah'] ?? '') . ' ' . ($r['tindakan'] ?? ''));
    $skor = 0;
    foreach ($originalKeywords as $kw) {
        if (mb_strpos($teks, mb_strtolower($kw)) !== false) {
            $skor++;
        }
    }
    return $skor;
}

/**
 * Riwayat paling RELEVAN (paling banyak cocok dengan kata kunci asli user)
 * dijadikan jawaban utama; di antara yang sama relevannya, yang CLOSED
 * menang (sudah terbukti menyelesaikan masalah serupa), lalu yang paling
 * baru tanggalnya. Relevansi diutamakan di atas tanggal supaya riwayat yang
 * cuma nyambung lewat sinonim tidak mengalahkan riwayat yang benar-benar
 * cocok dengan yang ditanyakan user.
 */
function pilihRiwayatTerbaikKey(array $riwayat, array $originalKeywords): ?int
{
    if (!$riwayat) {
        return null;
    }

    $terbaikKey = null;
    $terbaikSkor = -1;
    $terbaikClosed = false;

    foreach ($riwayat as $key => $r) {
        $skor = skorRelevansi($r, $originalKeywords);
        $isClosed = strtoupper((string)($r['status'] ?? '')) === 'CLOSED';

        // $riwayat sudah di-pre-sort (skor lalu closed lalu tanggal, lihat
        // pemanggil), jadi kalau skor & status sama, kandidat pertama yang
        // ditemukan (lebih dulu di urutan itu) menang.
        if (
            $terbaikKey === null
            || $skor > $terbaikSkor
            || ($skor === $terbaikSkor && $isClosed && !$terbaikClosed)
        ) {
            $terbaikKey = $key;
            $terbaikSkor = $skor;
            $terbaikClosed = $isClosed;
        }
    }

    return $terbaikKey;
}

function formatTanggalId(?string $tgl): string
{
    if (!$tgl) {
        return '-';
    }
    $ts = strtotime($tgl);
    return $ts ? date('d-m-Y', $ts) : $tgl;
}

/**
 * Pilih satu kalimat pembuka secara acak dari beberapa varian supaya balasan
 * tidak selalu berbunyi persis sama (tanpa memanggil layanan AI eksternal).
 */
function acakVarian(array $varian): string
{
    return $varian[array_rand($varian)];
}

function ringkasDetail(array $r): string
{
    $pic = (string)($r['pic'] ?? '');
    $mesin = (string)($r['mesin'] ?? '');
    $part = (string)($r['part'] ?? '');
    $tanggal = formatTanggalId($r['tanggal'] ?? null);

    return 'PIC: ' . ($pic !== '' ? $pic : '-')
        . ' · Mesin/Part: ' . ($mesin !== '' ? $mesin : '-') . ' / ' . ($part !== '' ? $part : '-')
        . ' · ' . $tanggal . ' · Sumber: ' . $r['sumber'];
}

/**
 * Menyusun jawaban lengkap & percakapan langsung dari riwayat countermeasure
 * yang paling relevan (tanpa memanggil layanan AI eksternal apa pun). Jawaban
 * ini SUDAH memuat detail tindakan lengkap supaya user tidak perlu membuka
 * kartu riwayat satu per satu untuk tahu jawabannya - kartu riwayat lain
 * (kalau ada) cuma jadi referensi tambahan yang sifatnya opsional.
 */
function bangunJawabanRiwayat(array $riwayat, ?array $terbaik): string
{
    if (!$riwayat || !$terbaik) {
        return acakVarian([
            'Belum ketemu riwayat FPM/Asakai yang mirip dengan deskripsi itu. Coba pakai kata kunci lain (nama part, jenis masalah, atau nama mesin), atau langsung tindak lanjuti ini sebagai temuan baru ke tim terkait.',
            'Hmm, saya belum menemukan riwayat serupa untuk itu di data FPM/Asakai. Coba jelaskan dengan istilah lain (nama part/mesin, atau jenis masalahnya), atau catat sebagai temuan baru kalau memang belum pernah terjadi sebelumnya.',
            'Belum ada riwayat yang cocok dengan deskripsi tersebut. Boleh coba kata kunci yang lebih spesifik (part, mesin, atau jenis masalah), atau ini kemungkinan memang kejadian baru yang perlu dianalisis dari awal.',
        ]);
    }

    $isClosed = strtoupper((string)($terbaik['status'] ?? '')) === 'CLOSED';
    $masalah = (string)($terbaik['masalah'] ?? '');
    $masalah = $masalah !== '' ? $masalah : 'masalah serupa';
    $tindakan = (string)($terbaik['tindakan'] ?? '');
    $tindakan = $tindakan !== '' ? $tindakan : 'tindakan belum dicatat lengkap di riwayatnya';

    $closedCount = count(array_filter($riwayat, function ($r) {
        return strtoupper((string)($r['status'] ?? '')) === 'CLOSED';
    }));
    $totalLain = count($riwayat) - 1;

    $lines = [];

    if ($isClosed) {
        $lines[] = acakVarian([
            'Untuk masalah "' . $masalah . '", ini tindakan yang pernah terbukti tuntas menyelesaikannya:',
            'Saya cek riwayatnya - masalah "' . $masalah . '" pernah terjadi sebelumnya, dan ini yang berhasil mengatasinya:',
            'Ketemu! Untuk "' . $masalah . '", tindakan berikut sudah terbukti CLOSED sebelumnya:',
        ]);
    } else {
        $lines[] = acakVarian([
            'Untuk masalah "' . $masalah . '", belum ada riwayat yang tuntas, tapi ini tindakan yang pernah dicoba (masih OPEN):',
            'Saya belum menemukan riwayat "' . $masalah . '" yang sudah tuntas - yang ada baru sebatas ini, dan masih berjalan:',
        ]);
    }

    $lines[] = '» ' . $tindakan;
    $lines[] = ringkasDetail($terbaik);

    if (!$isClosed) {
        $lines[] = acakVarian([
            'Karena belum ada yang terbukti tuntas, anggap ini referensi awal saja - pastikan hasilnya diverifikasi lagi setelah dicoba.',
            'Belum terbukti 100% menyelesaikan masalah, jadi tetap perlu diverifikasi ulang setelah diterapkan.',
        ]);
    }

    if ($totalLain > 0) {
        $lines[] = $totalLain . ' riwayat serupa lainnya (' . $closedCount . ' CLOSED) tersedia sebagai referensi tambahan di bawah kalau diperlukan.';
    }

    return implode("\n", $lines);
}

/**
 * Balasan untuk follow-up "itu sudah saya coba tapi belum berhasil" - coba
 * tawarkan alternatif lain yang belum pernah disarankan di percakapan ini,
 * atau kalau riwayatnya sudah habis, arahkan untuk eskalasi.
 */
function bangunJawabanFollowUp(array $kandidat, ?array $terbaik): string
{
    if (!$terbaik) {
        return acakVarian([
            'Ternyata semua riwayat yang saya temukan untuk masalah ini sudah pernah saya sarankan tadi. Sepertinya perlu penanganan lebih lanjut - coba diskusikan langsung dengan dept terkait, atau catat sebagai temuan baru supaya akar masalahnya bisa dianalisis lebih dalam (kemungkinan ada faktor lain yang beda dari kejadian-kejadian sebelumnya).',
            'Kelihatannya sudah tidak ada alternatif lain dari riwayat yang tercatat - semua yang saya temukan sudah pernah saya tawarkan. Sebaiknya ini dieskalasi ke dept terkait atau dianalisis ulang sebagai kasus baru, karena kondisinya mungkin sedikit berbeda dari kejadian sebelumnya.',
        ]);
    }

    $isClosed = strtoupper((string)($terbaik['status'] ?? '')) === 'CLOSED';
    $tindakan = (string)($terbaik['tindakan'] ?? '');
    $tindakan = $tindakan !== '' ? $tindakan : 'tindakan belum dicatat lengkap di riwayatnya';

    $lines = [];
    $lines[] = acakVarian([
        'Oke, kalau itu belum berhasil, ada satu lagi riwayat yang belum saya sebutkan:',
        'Baik, berarti coba alternatif lain yang juga pernah tercatat:',
        'Mengerti - ini pilihan lain dari riwayat yang belum saya tawarkan sebelumnya:',
    ]);
    $lines[] = '» ' . $tindakan;
    $lines[] = ringkasDetail($terbaik);

    if (!$isClosed) {
        $lines[] = 'Catatan: yang ini juga belum ada bukti tuntas (masih OPEN), jadi kalau tetap tidak berhasil, kemungkinan besar memang perlu analisis akar masalah baru.';
    }

    $sisa = count($kandidat) - 1;
    if ($sisa > 0) {
        $lines[] = 'Masih ada ' . $sisa . ' riwayat lain kalau ini juga belum cocok.';
    }

    return implode("\n", $lines);
}
