<?php
/**
 * Keamanan halaman role: session aman, guard login + level, dan CSRF.
 *
 * Pemakaian di halaman role (satu tingkat di bawah dist/pages):
 *   require_once __DIR__ . '/../config/auth_helper.php';
 *   wajibLogin();               // login + level sesuai folder role (AUTH_LEVEL_FOLDER)
 *   wajibLogin(['Staff IMP']);  // login + level tertentu
 *
 * wajibLogin():
 *  - memulai session dengan cookie HttpOnly + SameSite=Lax (+ Secure di HTTPS)
 *  - belum login -> redirect ke form login lalu exit (isi halaman/proses POST tidak dijalankan)
 *  - level tidak sesuai folder -> 403
 *  - request POST (dan link hapus via GET) wajib membawa token CSRF yang valid
 *  - token disisipkan otomatis ke semua <form method="post">, link hapus, fetch() & XHR
 *    di halaman yang dirender, jadi form/JS lama tidak perlu diubah satu per satu.
 */

require_once __DIR__ . '/keamanan_helper.php';

// Level yang boleh membuka halaman di tiap folder role. Folder yang tidak tercantum
// (mis. config/) tidak dicek level-nya - endpoint di sana memeriksa level sendiri.
const AUTH_LEVEL_FOLDER = [
    'admin_it'      => ['Admin IT'],
    'dept_head'     => ['Dept Head'],
    'dept_head_qa'  => ['Dept Head QA'],
    'directur'      => ['Directure'],
    'head_imp'      => ['Head IMP'],
    'plant_head'    => ['Plant Head'],
    'staff'         => ['Staff'],
    'staff_imp'     => ['Staff IMP'],
    'vice_directur' => ['Vice Directure'],
];

if (!function_exists('wajibLogin')) {

    function koneksiHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** session_start() dengan pengaturan cookie aman. Aman dipanggil berulang. */
    function mulaiSessionAman(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        if (!headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            if (koneksiHttps()) {
                ini_set('session.cookie_secure', '1');
            }
        }
        session_start();
    }

    /** Folder role dari script yang sedang dijalankan (mis. "staff_imp"). */
    function authFolderScript(): string
    {
        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        return $script === '' ? '' : basename(dirname(str_replace('\\', '/', $script)));
    }

    function wajibLogin(?array $levelBoleh = null): void
    {
        mulaiSessionAman();

        if (!isset($_SESSION['username'])) {
            header('Location: ../index.html');
            exit;
        }

        if ($levelBoleh === null) {
            $levelBoleh = AUTH_LEVEL_FOLDER[authFolderScript()] ?? [];
        }
        if ($levelBoleh && !in_array($_SESSION['level'] ?? '', $levelBoleh, true)) {
            http_response_code(403);
            exit('Akses ditolak: halaman ini bukan untuk level akun Anda. <a href="../index.html">Login ulang</a>');
        }

        csrfWajibValid();
        csrfMulaiSuntik();
    }

    // ---------- CSRF ----------

    function csrfToken(): string
    {
        mulaiSessionAman();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** true kalau request ini mengubah data: POST/PUT/DELETE, atau GET ke link hapus. */
    function csrfPerluDicek(): bool
    {
        $metode = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($metode, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        if (strpos(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), 'hapus') === 0) {
            return true;
        }
        foreach (array_keys($_GET) as $key) {
            if (strpos((string) $key, 'hapus') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hentikan request yang mengubah data tanpa token CSRF valid. Dipanggil otomatis oleh
     * wajibLogin(); endpoint yang punya guard sendiri (config/ajax_*.php) memanggilnya langsung.
     */
    function csrfWajibValid(): void
    {
        if (PHP_SAPI === 'cli' || !csrfPerluDicek()) {
            return;
        }

        // Asal request dari situs lain -> tolak (lapisan tambahan selain token)
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && $origin !== 'null') {
            $hostOrigin = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $hostIni = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            if ($hostOrigin !== '' && $hostIni !== '' && $hostOrigin !== $hostIni) {
                csrfTolak('Permintaan dari situs lain ditolak.');
            }
        }

        $kirim = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sesi = $_SESSION['csrf_token'] ?? '';
        if (is_string($kirim) && is_string($sesi) && $sesi !== '' && hash_equals($sesi, $kirim)) {
            return;
        }

        // POST melebihi post_max_size -> $_POST kosong, token ikut hilang. Beri pesan yang tepat.
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            csrfTolak('Upload gagal: total ukuran file terlalu besar untuk server (maks ' . ini_get('post_max_size') . '). Kurangi ukuran/jumlah file lalu coba lagi.');
        }
        csrfTolak('Sesi formulir sudah kedaluwarsa atau tidak valid. Muat ulang halaman lalu coba lagi.');
    }

    function csrfTolak(string $pesan): void
    {
        http_response_code(403);
        $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || isset($_SERVER['HTTP_X_CSRF_TOKEN']);
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'json') !== false) {
                $ajax = true; // endpoint yang sudah menyatakan responsnya JSON
            }
        }
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'success' => false, 'pesan' => $pesan, 'message' => $pesan]);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Akses ditolak</title>'
                . '<div style="font-family:sans-serif;max-width:520px;margin:60px auto;padding:20px;border:1px solid #ddd;border-radius:8px">'
                . '<h3 style="margin-top:0">Permintaan ditolak</h3><p>' . htmlspecialchars($pesan) . '</p>'
                . '<p><a href="javascript:history.back()">&larr; Kembali</a></p></div>';
        }
        exit;
    }

    /** Mulai output buffer yang menyisipkan token CSRF ke HTML halaman (sekali per request). */
    function csrfMulaiSuntik(): void
    {
        static $mulai = false;
        if ($mulai || PHP_SAPI === 'cli') {
            return;
        }
        $mulai = true;
        csrfToken();
        ob_start('csrfSuntikHtml');
    }

    /** Callback output buffer: hanya mengubah respons HTML. */
    function csrfSuntikHtml(string $html): string
    {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                return $html; // JSON, Excel, PDF, file download - tidak disentuh
            }
            if (stripos($h, 'content-disposition:') === 0) {
                return $html;
            }
        }
        $token = $_SESSION['csrf_token'] ?? '';
        $awal = ltrim(substr($html, 0, 200));
        if ($token === '' || $html === '' || ($awal !== '' && ($awal[0] === '{' || $awal[0] === '['))) {
            return $html; // JSON yang dikirim tanpa header Content-Type
        }
        $t = htmlspecialchars($token, ENT_QUOTES);

        // 1) hidden input di semua form POST
        $html = preg_replace_callback('~<form\b[^>]*>~i', function ($m) use ($t) {
            if (!preg_match('~\bmethod\s*=\s*["\']?post\b~i', $m[0])) {
                return $m[0];
            }
            return $m[0] . '<input type="hidden" name="csrf_token" value="' . $t . '">';
        }, $html) ?? $html;

        // 2) token di link hapus (hapus_xxx.php?... atau ?hapus=...)
        $html = preg_replace_callback('~\bhref\s*=\s*(["\'])([^"\']*?)\1~i', function ($m) use ($t) {
            $url = $m[2];
            if (!preg_match('~(^|/)hapus\w*\.php|[?&](amp;)?hapus\w*=~i', $url) || stripos($url, 'csrf_token=') !== false) {
                return $m[0];
            }
            $pisah = strpos($url, '?') === false ? '?' : '&';
            return 'href=' . $m[1] . $url . $pisah . 'csrf_token=' . $t . $m[1];
        }, $html) ?? $html;

        // 3) skrip yang menambahkan token ke form dinamis, fetch() & XMLHttpRequest (same-origin)
        $js = '<meta name="csrf-token" content="' . $t . '"><script>(function(){var t=' . json_encode($token) . ';'
            . 'function s(u){try{return new URL(u,location.href).origin===location.origin}catch(e){return true}}'
            . 'function f(x){if(!x||!x.getAttribute||(x.getAttribute("method")||"").toLowerCase()!=="post")return;'
            . 'var i=x.querySelector("input[name=csrf_token]");if(!i){i=document.createElement("input");i.type="hidden";i.name="csrf_token";x.appendChild(i)}i.value=t}'
            . 'document.addEventListener("submit",function(e){f(e.target)},true);'
            . 'var ps=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){f(this);return ps.apply(this,arguments)};'
            . 'var o=XMLHttpRequest.prototype.open,se=XMLHttpRequest.prototype.send;'
            . 'XMLHttpRequest.prototype.open=function(m,u){this.__c=s(u);return o.apply(this,arguments)};'
            . 'XMLHttpRequest.prototype.send=function(){if(this.__c){try{this.setRequestHeader("X-CSRF-Token",t)}catch(e){}}return se.apply(this,arguments)};'
            . 'if(window.fetch){var wf=window.fetch;window.fetch=function(r,c){var u=(r&&r.url)?r.url:String(r);'
            . 'if(s(u)){c=c||{};var h=new Headers(c.headers||(r&&r.headers)||{});h.set("X-CSRF-Token",t);c.headers=h}return wf.call(this,r,c)}}'
            . '})();</script>';

        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $js . substr($html, $pos);
        }
        if (stripos($html, '<form') !== false || stripos($html, '<script') !== false) {
            return $js . $html; // potongan HTML tanpa <head>
        }
        return $html;
    }
}
