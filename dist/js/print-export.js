/*
 * Shared print / JPEG / PDF export helpers (form FPM semua role).
 * Usage: include this file, then call
 *   printTarget()                                  -> print #printArea
 *   exportTargetAsJPEG('#printArea', 'filename')
 *   exportTargetAsPDF('#printArea', 'filename')
 *
 * Hasil Print / JPEG / PDF dibuat dari SATU gambar yang sama, diambil langsung dari
 * tampilan HTML (html-to-image - dirender oleh browser sendiri lewat SVG foreignObject),
 * jadi tata letaknya identik dengan yang terlihat di layar:
 *  - tidak lagi meratakan rowspan dengan menduplikasi sel (dulu isi jadi dobel/bergeser),
 *  - difoto dari salinan tersembunyi selebar CAPTURE_WIDTH: hasil sama di semua layar
 *    dan huruf di kertas lebih kecil (form FPM memakai CSS global, jadi gayanya tetap sama),
 *  - Print tidak lagi memakai CSS print browser yang memeras tabel ke lebar kertas,
 *  - PDF/Print memilih orientasi (portrait/landscape) yang paling besar dan memuat
 *    seluruh form di satu halaman A4; hanya konten yang sangat panjang dibagi ke
 *    beberapa halaman.
 *  - konten yang terlalu panjang untuk 1 halaman dipotong di batas baris tabel (tidak
 *    pernah membelah satu baris/sel), dan teks panjang selalu dibungkus di dalam selnya,
 *  - Ctrl+P / menu Print browser juga diarahkan ke hasil yang sama (1 halaman landscape).
 * html2canvas tetap dipakai sebagai cadangan kalau html-to-image gagal.
 */
(function () {
    var PIXEL_RATIO = 2.5;
    var MIN_CAPTURE_WIDTH = 1100;   // print bawaan browser (cadangan): lebar minimum form
    // Print/JPEG/PDF selalu difoto pada lebar ini (salinan tersembunyi, bukan form di layar):
    // hasil sama di semua ukuran layar, dan karena lebih lebar dari tampilan biasa, huruf
    // pada kertas jadi lebih kecil serta teks panjang lebih jarang turun baris.
    var CAPTURE_WIDTH = 1700;
    var PAGE_MARGIN_MM = 6;
    var A4 = { w: 210, h: 297 };
    // Konten lebih panjang dari ini (x tinggi halaman pada lebar penuh) dibagi ke beberapa halaman.
    var MAX_SHRINK_ONE_PAGE = 1.6;

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = function () { reject(new Error('Gagal memuat ' + src)); };
            document.head.appendChild(s);
        });
    }

    function ensureLibs(needPdf) {
        var pending = [];
        if (!window.htmlToImage) {
            pending.push(loadScript('https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js'));
        }
        if (needPdf && !window.jspdf) {
            pending.push(loadScript('https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js'));
        }
        return Promise.all(pending);
    }

    function ensureHtml2Canvas() {
        return window.html2canvas ? Promise.resolve()
            : loadScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
    }

    // ---------- indikator proses ----------
    var busyEl = null;
    function setBusy(on, text) {
        if (on) {
            if (!busyEl) {
                busyEl = document.createElement('div');
                busyEl.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:99999;' +
                    'background:#1f2937;color:#fff;padding:8px 16px;border-radius:8px;font:13px Arial,sans-serif;' +
                    'box-shadow:0 4px 12px rgba(0,0,0,.25)';
                busyEl.className = 'no-print';
            }
            busyEl.textContent = text || 'Menyiapkan dokumen...';
            document.body.appendChild(busyEl);
            document.body.style.cursor = 'progress';
        } else {
            if (busyEl && busyEl.parentNode) busyEl.parentNode.removeChild(busyEl);
            document.body.style.cursor = '';
        }
    }

    // ---------- salinan form untuk difoto ----------
    // Salinan diletakkan di luar layar langsung di <body> (gaya form FPM berlaku global),
    // dengan lebar CAPTURE_WIDTH. Rowspan TIDAK diubah sama sekali - versi lama meratakan
    // rowspan dengan menduplikasi sel, itu yang membuat data tampak dobel.
    function buildCaptureClone(target) {
        var wrapper = document.createElement('div');
        wrapper.setAttribute('aria-hidden', 'true');
        wrapper.style.cssText = 'position:absolute;left:-100000px;top:0;background:#fff;width:' +
            Math.max(CAPTURE_WIDTH, target.offsetWidth) + 'px';

        var clone = target.cloneNode(true);
        clone.style.width = '100%';
        clone.style.maxWidth = 'none';
        clone.style.margin = '0';

        Array.prototype.forEach.call(clone.querySelectorAll('.no-print'), function (el) { el.remove(); });

        // Nilai input/textarea/checkbox saat ini ikut tersalin.
        var asli = target.querySelectorAll('input,textarea,select');
        var salin = clone.querySelectorAll('input,textarea,select');
        Array.prototype.forEach.call(salin, function (el, i) {
            var src = asli[i];
            if (!src) return;
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (src.checked) el.setAttribute('checked', 'checked'); else el.removeAttribute('checked');
            } else if (el.tagName === 'TEXTAREA') {
                el.textContent = src.value;
            } else if (el.tagName === 'SELECT') {
                Array.prototype.forEach.call(el.options, function (o, j) {
                    if (src.options[j] && src.options[j].selected) o.setAttribute('selected', 'selected');
                });
            } else {
                el.setAttribute('value', src.value);
            }
        });

        wrapper.appendChild(clone);
        document.body.appendChild(wrapper);

        // Area scroll di dalam form dibuka penuh supaya tidak terpotong.
        Array.prototype.forEach.call(clone.querySelectorAll('*'), function (el) {
            if (el.tagName === 'TEXTAREA') return;
            var cs = getComputedStyle(el);
            if (/(auto|scroll)/.test(cs.overflow + cs.overflowX + cs.overflowY)) {
                el.style.overflow = 'visible';
                el.style.maxHeight = 'none';
                el.style.height = 'auto';
            }
        });

        var imgs = Array.prototype.slice.call(clone.querySelectorAll('img'));
        var siap = Promise.all(imgs.map(function (img) {
            if (img.complete && img.naturalWidth) return Promise.resolve();
            return new Promise(function (r) { img.onload = img.onerror = r; setTimeout(r, 5000); });
        }));

        return { wrapper: wrapper, clone: clone, siap: siap };
    }

    // Posisi (px CSS dari atas target) yang aman untuk memotong halaman: batas bawah sel yang
    // tidak dilintasi sel lain (termasuk sel rowspan & tabel di dalam sel).
    function safeCuts(target) {
        var top = target.getBoundingClientRect().top;
        var spans = [];
        var ys = {};
        Array.prototype.forEach.call(target.querySelectorAll('td,th'), function (c) {
            var r = c.getBoundingClientRect();
            if (!r.height) return;
            spans.push([r.top - top, r.bottom - top]);
            ys[Math.round(r.bottom - top)] = true;
        });
        return Object.keys(ys).map(Number).sort(function (a, b) { return a - b; }).filter(function (y) {
            return !spans.some(function (sp) { return sp[0] < y - 2 && sp[1] > y + 2; });
        });
    }

    function isNoPrint(node) {
        return !(node.classList && node.classList.contains('no-print'));
    }

    function captureCanvas(targetSelector, needPdf) {
        var target = document.querySelector(targetSelector);
        if (!target) {
            return Promise.reject(new Error('Target "' + targetSelector + '" tidak ditemukan'));
        }

        return ensureLibs(needPdf).then(function () {
            var built = buildCaptureClone(target);
            var clone = built.clone;

            return built.siap.then(function () {
                var width = Math.ceil(Math.max(clone.scrollWidth, clone.offsetWidth));
                var height = Math.ceil(Math.max(clone.scrollHeight, clone.offsetHeight));
                var cuts = safeCuts(clone);

                return window.htmlToImage.toCanvas(clone, {
                    pixelRatio: PIXEL_RATIO,
                    backgroundColor: '#ffffff',
                    width: width,
                    height: height,
                    cacheBust: true,
                    skipFonts: true,          // form memakai Arial; hindari CORS font Google
                    filter: isNoPrint,
                    style: { margin: '0', position: 'static', left: '0', top: '0', transform: 'none' }
                }).then(function (canvas) {
                    if (!canvas || !canvas.width || !canvas.height) throw new Error('Canvas kosong');
                    return canvas;
                }).catch(function (err) {
                    // Cadangan: html2canvas pada salinan yang sama (tanpa meratakan rowspan).
                    console.warn('html-to-image gagal, pakai html2canvas:', err);
                    return ensureHtml2Canvas().then(function () {
                        return window.html2canvas(clone, {
                            scale: PIXEL_RATIO,
                            useCORS: true,
                            backgroundColor: '#ffffff',
                            width: width,
                            height: height,
                            ignoreElements: function (el) { return !isNoPrint(el); }
                        });
                    });
                }).then(function (canvas) {
                    canvas._cutsCss = cuts;
                    canvas._cssHeight = height;
                    return canvas;
                });
            }).then(function (canvas) {
                built.wrapper.remove();
                return canvas;
            }, function (err) {
                built.wrapper.remove();
                throw err;
            });
        });
    }

    // ---------- tata letak halaman A4 ----------
    // Pilih orientasi yang menghasilkan gambar paling besar saat seluruh form dimuat di 1 halaman.
    // Konten yang sangat panjang: pakai lebar penuh portrait lalu dipotong per halaman.
    function planPages(canvas) {
        var ratio = canvas.height / canvas.width;
        var options = [
            { orientation: 'p', pw: A4.w, ph: A4.h },
            { orientation: 'l', pw: A4.h, ph: A4.w }
        ].map(function (o) {
            var aw = o.pw - 2 * PAGE_MARGIN_MM;
            var ah = o.ph - 2 * PAGE_MARGIN_MM;
            var w = Math.min(aw, ah / ratio);
            return { orientation: o.orientation, pw: o.pw, ph: o.ph, aw: aw, ah: ah, w: w, h: w * ratio };
        });
        var best = options[0].w >= options[1].w ? options[0] : options[1];

        // Seluruh form muat di 1 halaman tanpa menyusut terlalu kecil.
        var fullWidthHeight = best.aw * ratio;
        if (fullWidthHeight <= best.ah * MAX_SHRINK_ONE_PAGE) {
            return {
                orientation: best.orientation, pw: best.pw, ph: best.ph,
                slices: [{ canvas: canvas, x: (best.pw - best.w) / 2, y: PAGE_MARGIN_MM, w: best.w, h: best.h }]
            };
        }

        // Multi-halaman: landscape lebar penuh (huruf tetap terbaca), dipotong per halaman di
        // batas baris terdekat supaya tidak ada baris/sel yang terbelah.
        var p = options[1];
        var pxPerMm = canvas.width / p.aw;
        var pageHeightPx = Math.floor(p.ah * pxPerMm);
        var cssToPx = canvas.height / (canvas._cssHeight || canvas.height);
        var cutsPx = (canvas._cutsCss || []).map(function (y) { return Math.round(y * cssToPx); });
        var slices = [];
        var y = 0;
        while (y < canvas.height - 1) {
            var end = Math.min(y + pageHeightPx, canvas.height);
            if (end < canvas.height) {
                var aman = cutsPx.filter(function (c) { return c > y + pageHeightPx * 0.3 && c <= end; });
                if (aman.length) end = aman[aman.length - 1];
            }
            var hPx = end - y;
            var part = document.createElement('canvas');
            part.width = canvas.width;
            part.height = hPx;
            var ctx = part.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, part.width, part.height);
            ctx.drawImage(canvas, 0, y, canvas.width, hPx, 0, 0, canvas.width, hPx);
            slices.push({ canvas: part, x: PAGE_MARGIN_MM, y: PAGE_MARGIN_MM, w: p.aw, h: hPx / pxPerMm });
            y = end;
        }
        return { orientation: p.orientation, pw: p.pw, ph: p.ph, slices: slices };
    }

    function runWithBusy(text, fn) {
        setBusy(true, text);
        // beri waktu indikator tampil sebelum proses berat
        return new Promise(function (r) { setTimeout(r, 30); })
            .then(fn)
            .catch(function (err) {
                alert('Gagal: ' + (err && err.message ? err.message : err));
            })
            .then(function () { setBusy(false); });
    }

    // ---------- Print: cetak gambar yang identik dengan tampilan HTML ----------
    window.printTarget = function (targetSelector) {
        targetSelector = targetSelector || '#printArea';
        if (!document.querySelector(targetSelector)) {
            window.print();
            return Promise.resolve();
        }

        return runWithBusy('Menyiapkan print...', function () {
            return captureCanvas(targetSelector, false).then(function (canvas) {
                var plan = planPages(canvas);
                var size = plan.orientation === 'l' ? 'A4 landscape' : 'A4 portrait';

                var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + document.title + '</title><style>' +
                    '@page{size:' + size + ';margin:0}' +
                    'html,body{margin:0;padding:0;background:#fff}' +
                    '.page{width:' + plan.pw + 'mm;height:' + plan.ph + 'mm;position:relative;overflow:hidden;page-break-after:always;break-after:page}' +
                    '.page:last-child{page-break-after:auto;break-after:auto}' +
                    '.page img{position:absolute;display:block}' +
                    '</style></head><body>' +
                    plan.slices.map(function (s) {
                        return '<div class="page"><img src="' + s.canvas.toDataURL('image/png') + '" style="left:' + s.x + 'mm;top:' + s.y +
                            'mm;width:' + s.w + 'mm;height:' + s.h + 'mm"></div>';
                    }).join('') +
                    '</body></html>';

                var frame = document.createElement('iframe');
                frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
                document.body.appendChild(frame);

                return new Promise(function (resolve) {
                    var doc = frame.contentWindow.document;
                    doc.open();
                    doc.write(html);
                    doc.close();

                    var imgs = Array.prototype.slice.call(doc.images);
                    Promise.all(imgs.map(function (img) {
                        return img.complete ? Promise.resolve() : new Promise(function (r) { img.onload = img.onerror = r; });
                    })).then(function () {
                        setBusy(false);
                        frame.contentWindow.focus();
                        frame.contentWindow.print();
                        // hapus iframe setelah dialog print ditutup
                        setTimeout(function () { frame.remove(); resolve(); }, 1000);
                    });
                });
            });
        });
    };

    window.exportTargetAsJPEG = function (targetSelector, filename) {
        return runWithBusy('Menyiapkan JPEG...', function () {
            return captureCanvas(targetSelector, false).then(function (canvas) {
                var link = document.createElement('a');
                link.download = (filename || 'export') + '.jpg';
                link.href = canvas.toDataURL('image/jpeg', 0.95);
                document.body.appendChild(link);
                link.click();
                link.remove();
            });
        });
    };

    window.exportTargetAsPDF = function (targetSelector, filename) {
        return runWithBusy('Menyiapkan PDF...', function () {
            return captureCanvas(targetSelector, true).then(function (canvas) {
                var plan = planPages(canvas);
                var pdf = new window.jspdf.jsPDF(plan.orientation, 'mm', 'a4');

                plan.slices.forEach(function (s, i) {
                    if (i > 0) pdf.addPage('a4', plan.orientation);
                    pdf.addImage(s.canvas.toDataURL('image/jpeg', 0.95), 'JPEG', s.x, s.y, s.w, s.h, undefined, 'FAST');
                });

                pdf.save((filename || 'export') + '.pdf');
            });
        });
    };

    // ---------- teks panjang & print bawaan browser ----------
    var PRINT_W_PX = 1084;  // A4 landscape 297mm - margin 2x5mm, pada 96 dpi
    var PRINT_H_PX = 756;   // 210mm - 2x5mm

    function injectStyle(id, css) {
        var el = document.getElementById(id);
        if (!el) {
            el = document.createElement('style');
            el.id = id;
            document.head.appendChild(el);
        }
        el.textContent = css;
    }

    // Teks panjang (termasuk kata/kode tanpa spasi) selalu dibungkus di dalam selnya,
    // tidak meluber menimpa kolom sebelah - di layar maupun di hasil Print/JPEG/PDF.
    injectStyle('pe-wrap-style',
        '#printArea td,#printArea th{overflow-wrap:anywhere;word-break:break-word}');

    // Ctrl+P diarahkan ke printTarget() (hasil sama dengan tombol Print).
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'p' || e.key === 'P') && document.querySelector('#printArea')) {
            e.preventDefault();
            window.printTarget('#printArea');
        }
    });

    // ---------- print dari menu browser (bukan tombol Print / Ctrl+P) ----------
    // Event beforeprint tidak bisa menunggu proses async, jadi gambar form disiapkan lebih
    // dulu di latar belakang setelah halaman terbuka. Saat print, hanya gambar itu yang
    // dicetak - utuh di 1 halaman, identik dengan tombol Print.
    var siapPrint = null; // { url, orientation, w, h } dalam mm

    function fitSatuHalaman(canvas) {
        var ratio = canvas.height / canvas.width;
        var best = null;
        [{ o: 'portrait', pw: A4.w, ph: A4.h }, { o: 'landscape', pw: A4.h, ph: A4.w }].forEach(function (x) {
            var aw = x.pw - 2 * PAGE_MARGIN_MM, ah = x.ph - 2 * PAGE_MARGIN_MM;
            var w = Math.min(aw, ah / ratio);
            if (!best || w > best.w) best = { orientation: x.o, w: w, h: w * ratio, pw: x.pw };
        });
        return best;
    }

    function siapkanPrintLatar() {
        var target = document.querySelector('#printArea');
        if (!target) return;
        captureCanvas('#printArea', false).then(function (canvas) {
            var fit = fitSatuHalaman(canvas);
            canvas.toBlob(function (blob) {
                if (!blob) return;
                if (siapPrint) URL.revokeObjectURL(siapPrint.url);
                siapPrint = { url: URL.createObjectURL(blob), orientation: fit.orientation, w: fit.w, h: fit.h, pw: fit.pw };
                var holder = document.getElementById('pe-print-holder');
                if (!holder) {
                    holder = document.createElement('div');
                    holder.id = 'pe-print-holder';
                    holder.style.display = 'none';
                    document.body.appendChild(holder);
                }
                holder.innerHTML = '<img alt="" src="' + siapPrint.url + '" style="display:block;margin:0 auto;width:' +
                    siapPrint.w + 'mm;height:' + siapPrint.h + 'mm">';
            }, 'image/png');
        }).catch(function (err) {
            console.warn('Siapkan print latar gagal:', err);
        });
    }

    function jadwalkanPrintLatar() {
        var jalan = function () {
            (window.requestIdleCallback || function (fn) { setTimeout(fn, 200); })(siapkanPrintLatar);
        };
        if (document.readyState === 'complete') setTimeout(jalan, 800);
        else window.addEventListener('load', function () { setTimeout(jalan, 800); });
    }
    jadwalkanPrintLatar();

    window.addEventListener('beforeprint', function () {
        var target = document.querySelector('#printArea');
        if (!target) return;

        if (siapPrint) {
            injectStyle('pe-native-print-style',
                '@media print{@page{size:A4 ' + siapPrint.orientation + ';margin:' + PAGE_MARGIN_MM + 'mm 0}' +
                'html,body{margin:0 !important;padding:0 !important;height:auto !important;overflow:visible !important}' +
                'body *{visibility:visible !important}' +
                'body>*:not(#pe-print-holder){display:none !important}' +
                '#pe-print-holder{display:block !important}}');
            return;
        }

        // Cadangan (gambar belum siap): form selebar layar lalu diperkecil agar muat 1 halaman.
        var w = Math.max(target.scrollWidth, target.offsetWidth, MIN_CAPTURE_WIDTH);
        var h = Math.max(target.scrollHeight, target.offsetHeight);
        var zoom = Math.min(1, PRINT_W_PX / w, PRINT_H_PX / h);
        injectStyle('pe-native-print-style',
            '@media print{@page{size:A4 landscape;margin:5mm}' +
            'html,body{margin:0 !important;padding:0 !important}' +
            '#printArea{width:' + w + 'px !important;max-width:none !important;zoom:' + zoom.toFixed(4) + '}}');
    });
    window.addEventListener('afterprint', function () {
        var el = document.getElementById('pe-native-print-style');
        if (el) el.remove();
    });
})();
