<footer class="app-footer">
    <div class="float-end d-none d-sm-inline"></div>
    <strong>
        <a href="https://sheitai.co.id" class="text-decoration-none">PT. SHEI TAI INDUSTRIAL</a>
    </strong>
</footer>

<?php if (isset($_SESSION['username'])):
    // Halaman detail FPM ada di semua role (form_fpm.php?id=), tapi halaman detail
    // Asakai (detail_fpm_asakai.php?id=) tidak ada di role dept_head_qa (mereka tidak
    // menangani Asakai) - dicek langsung dari direktori halaman yang sedang aktif
    // supaya link kartu riwayat di widget "Tanya Sistem" tidak pernah 404.
    $tsCurrentDir = dirname($_SERVER['SCRIPT_FILENAME']);
    $tsHasAsakaiDetail = file_exists($tsCurrentDir . '/detail_fpm_asakai.php');
?>
<div id="tsWidget">
    <button type="button" id="tsFab" title="Tanya Sistem">
        <i class="bi bi-chat-dots-fill"></i>
        <span>Chat</span>
    </button>

    <div id="tsPanel">
        <div id="tsPanelHeader">
            <div>
                <div id="tsPanelTitle"><i class="bi bi-question-circle-fill"></i> Tanya Sistem</div>
            </div>
            <button type="button" id="tsClose" title="Tutup"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="tsMessages">
            <div class="ts-row bot">
                <div class="ts-bubble bot">Halo! Ceritakan masalah yang Anda hadapi, misalnya "NG dimensi pada part bracket" atau "downtime mesin karena mold macet". Saya akan carikan tindakan yang pernah terbukti berdasarkan riwayat FPM &amp; Asakai.</div>
            </div>
        </div>

        <div id="tsOptionalRow">
            <input type="text" id="tsMesin" placeholder="Mesin (opsional)">
            <input type="text" id="tsPart" placeholder="Part (opsional)">
        </div>

        <div id="tsInputRow">
            <textarea id="tsInput" rows="1" placeholder="Tulis masalah Anda di sini..."></textarea>
            <button type="button" id="tsSend" title="Kirim"><i class="bi bi-send-fill"></i></button>
        </div>
    </div>
</div>

<style>
    #tsWidget, #tsWidget * {
        box-sizing: border-box;
        font-family: 'Inter', 'Source Sans 3', sans-serif;
    }

    #tsFab {
        position: fixed;
        right: 24px;
        bottom: 24px;
        height: 52px;
        padding: 0 20px 0 18px;
        border-radius: 26px;
        border: none;
        background: #1d4ed8;
        color: #fff;
        font-size: 15px;
        font-weight: 600;
        box-shadow: 0 8px 24px rgba(29, 78, 216, 0.4);
        cursor: pointer;
        z-index: 1045;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: transform .15s ease, background .15s ease;
    }

    #tsFab i {
        font-size: 20px;
    }

    #tsFab:hover {
        background: #1e40af;
        transform: scale(1.06);
    }

    #tsPanel {
        position: fixed;
        right: 24px;
        bottom: 92px;
        width: 380px;
        max-width: calc(100vw - 32px);
        height: 560px;
        max-height: calc(100vh - 140px);
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
        border: 1px solid #e2e8f0;
        display: none;
        flex-direction: column;
        overflow: hidden;
        z-index: 1046;
    }

    #tsWidget.ts-open #tsPanel {
        display: flex;
    }

    #tsPanelHeader {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 16px;
        background: #1d4ed8;
        color: #fff;
    }

    #tsPanelTitle {
        font-size: 15px;
        font-weight: 600;
    }

    #tsClose {
        border: none;
        background: transparent;
        color: #fff;
        font-size: 14px;
        cursor: pointer;
        opacity: .85;
        flex-shrink: 0;
    }

    #tsClose:hover {
        opacity: 1;
    }

    #tsMessages {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: #f8fafc;
    }

    .ts-row {
        display: flex;
    }

    .ts-row.user {
        justify-content: flex-end;
    }

    .ts-bubble {
        max-width: 85%;
        padding: 10px 12px;
        border-radius: 12px;
        font-size: 13px;
        line-height: 1.5;
        white-space: pre-wrap;
    }

    .ts-bubble.bot {
        background: #fff;
        color: #1e293b;
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
    }

    .ts-bubble.user {
        background: #1d4ed8;
        color: #fff;
        border-bottom-right-radius: 4px;
    }

    .ts-bubble.error {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .ts-bubble.typing {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 14px 16px;
    }

    .ts-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #94a3b8;
        animation: tsBounce 1.2s infinite ease-in-out;
    }

    .ts-dot:nth-child(2) { animation-delay: .15s; }
    .ts-dot:nth-child(3) { animation-delay: .3s; }

    @keyframes tsBounce {
        0%, 60%, 100% { transform: translateY(0); opacity: .5; }
        30% { transform: translateY(-4px); opacity: 1; }
    }

    .ts-sources-toggle {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 10px;
        padding: 6px 0 0;
        border: none;
        border-top: 1px dashed #cbd5e1;
        background: none;
        color: #64748b;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        white-space: normal;
        width: 100%;
        text-align: left;
    }

    .ts-sources-toggle:hover {
        color: #1d4ed8;
    }

    .ts-sources-toggle i {
        transition: transform .15s ease;
    }

    .ts-sources-toggle.open i {
        transform: rotate(180deg);
    }

    .ts-sources {
        margin-top: 8px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        white-space: normal;
    }

    .ts-source-item {
        display: block;
        font-size: 11px;
        color: #475569;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 8px 10px;
        text-decoration: none;
    }

    .ts-source-item.clickable {
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
    }

    .ts-source-item.clickable:hover {
        border-color: #1d4ed8;
        background: #eff6ff;
    }

    .ts-source-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 4px;
    }

    .ts-source-sumber {
        font-weight: 600;
        color: #1d4ed8;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .ts-source-status {
        font-size: 10px;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 10px;
    }

    .ts-source-status.closed {
        background: #dcfce7;
        color: #15803d;
    }

    .ts-source-status.open {
        background: #ffedd5;
        color: #c2410c;
    }

    .ts-source-masalah {
        color: #1e293b;
        font-weight: 600;
        margin-bottom: 2px;
    }

    .ts-source-tindakan {
        color: #334155;
        margin-bottom: 4px;
    }

    .ts-source-meta {
        color: #94a3b8;
        font-size: 10.5px;
    }

    .ts-source-link {
        margin-top: 6px;
        color: #1d4ed8;
        font-weight: 600;
        font-size: 10.5px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    #tsOptionalRow {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        padding: 8px 12px;
        border-top: 1px solid #e2e8f0;
        background: #fff;
    }

    #tsOptionalRow input {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 6px 8px;
        font-size: 12px;
        font-family: inherit;
    }

    #tsInputRow {
        display: flex;
        gap: 8px;
        padding: 10px 12px 12px;
        border-top: 1px solid #e2e8f0;
        align-items: flex-end;
        background: #fff;
    }

    #tsInput {
        flex: 1;
        resize: none;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 8px 10px;
        font-size: 13px;
        font-family: inherit;
        max-height: 100px;
        min-height: 38px;
    }

    #tsSend {
        background: #1d4ed8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        flex-shrink: 0;
        font-size: 14px;
        cursor: pointer;
    }

    #tsSend:hover {
        background: #1e40af;
    }

    #tsSend:disabled {
        background: #94a3b8;
        cursor: not-allowed;
    }

    @media (max-width: 420px) {
        #tsPanel {
            right: 16px;
            bottom: 84px;
        }
        #tsFab {
            right: 16px;
            bottom: 16px;
        }
    }
</style>

<script>
(function() {
    const widget = document.getElementById('tsWidget');
    const fab = document.getElementById('tsFab');
    const closeBtn = document.getElementById('tsClose');
    const messages = document.getElementById('tsMessages');
    const input = document.getElementById('tsInput');
    const sendBtn = document.getElementById('tsSend');
    const mesinInput = document.getElementById('tsMesin');
    const partInput = document.getElementById('tsPart');
    const hasAsakaiDetail = <?= $tsHasAsakaiDetail ? 'true' : 'false' ?>;

    let isSending = false;
    // Konteks pencarian terakhir (dari backend, lihat ajax_tanya_sistem_chat.php)
    // supaya balasan "itu sudah saya coba tapi gagal" bisa nyambung ke pencarian
    // sebelumnya, bukan dianggap pertanyaan baru yang tidak ketemu apa-apa.
    let tsContextMessage = null;
    let tsUsedKeys = [];

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function formatTanggal(tgl) {
        if (!tgl) return '-';
        const d = new Date(tgl);
        if (isNaN(d)) return tgl;
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function scrollToBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function addUserMessage(text) {
        const row = document.createElement('div');
        row.className = 'ts-row user';
        row.innerHTML = `<div class="ts-bubble user">${escapeHtml(text)}</div>`;
        messages.appendChild(row);
        scrollToBottom();
    }

    function sourceDetailUrl(s) {
        if (s.id_fpm) return 'form_fpm.php?id=' + encodeURIComponent(s.id_fpm);
        if (s.id_asakai && hasAsakaiDetail) return 'detail_fpm_asakai.php?id=' + encodeURIComponent(s.id_asakai);
        return null;
    }

    let tsSourceGroupId = 0;

    // Jawaban utama sudah lengkap di teks balasan (lihat bangunJawabanRiwayat di
    // backend), jadi "sources" di sini cuma referensi tambahan yang sifatnya
    // opsional - disembunyikan di balik toggle supaya user tidak perlu membuka
    // / men-scroll banyak kartu satu per satu untuk dapat jawabannya.
    function addBotMessage(text, sources, isError) {
        const row = document.createElement('div');
        row.className = 'ts-row bot';

        let sourcesHtml = '';
        if (sources && sources.length) {
            const groupId = 'tsSrc' + (tsSourceGroupId++);
            const cards = sources.map(function(s) {
                const isClosed = (s.status || '').toUpperCase() === 'CLOSED';
                const url = sourceDetailUrl(s);
                const masalah = escapeHtml(s.masalah || 'Masalah tidak dijelaskan');
                const tindakan = escapeHtml(s.tindakan || 'Tindakan belum dicatat');
                const inner = `
                    <div class="ts-source-top">
                        <span class="ts-source-sumber">${escapeHtml(s.sumber)}</span>
                        <span class="ts-source-status ${isClosed ? 'closed' : 'open'}">${isClosed ? 'CLOSED' : 'OPEN'}</span>
                    </div>
                    <div class="ts-source-masalah">${masalah}</div>
                    <div class="ts-source-tindakan">→ ${tindakan}</div>
                    <div class="ts-source-meta">${formatTanggal(s.tanggal)} · ${escapeHtml(s.mesin || '-')} / ${escapeHtml(s.part || '-')} · PIC: ${escapeHtml(s.pic || '-')}</div>
                    ${url ? '<div class="ts-source-link">Lihat detail <i class="bi bi-box-arrow-up-right"></i></div>' : ''}
                `;
                return url
                    ? `<a class="ts-source-item clickable" href="${url}" target="_blank" rel="noopener">${inner}</a>`
                    : `<div class="ts-source-item">${inner}</div>`;
            }).join('');

            sourcesHtml = `
                <button type="button" class="ts-sources-toggle" data-target="${groupId}">
                    <i class="bi bi-chevron-down"></i> ${sources.length} riwayat lain (referensi tambahan)
                </button>
                <div class="ts-sources" id="${groupId}" hidden>${cards}</div>
            `;
        }

        row.innerHTML = `<div class="ts-bubble bot${isError ? ' error' : ''}">${escapeHtml(text)}${sourcesHtml}</div>`;

        const toggle = row.querySelector('.ts-sources-toggle');
        if (toggle) {
            toggle.addEventListener('click', function() {
                const panel = document.getElementById(toggle.dataset.target);
                const willShow = panel.hasAttribute('hidden');
                panel.toggleAttribute('hidden');
                toggle.classList.toggle('open', willShow);
                scrollToBottom();
            });
        }

        messages.appendChild(row);
        scrollToBottom();
    }

    function addTyping() {
        const row = document.createElement('div');
        row.className = 'ts-row bot';
        row.id = 'tsTypingRow';
        row.innerHTML = '<div class="ts-bubble bot typing"><span class="ts-dot"></span><span class="ts-dot"></span><span class="ts-dot"></span></div>';
        messages.appendChild(row);
        scrollToBottom();
    }

    function removeTyping() {
        const row = document.getElementById('tsTypingRow');
        if (row) row.remove();
    }

    function autoResize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 100) + 'px';
    }

    function kirimPesan() {
        if (isSending) return;

        const message = input.value.trim();
        if (message.length < 3) return;

        const mesin = mesinInput.value.trim();
        const part = partInput.value.trim();

        addUserMessage(message);
        input.value = '';
        autoResize();

        isSending = true;
        sendBtn.disabled = true;
        addTyping();

        fetch('../config/ajax_tanya_sistem_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message, mesin, part,
                    prevMessage: tsContextMessage,
                    prevExcluded: tsUsedKeys,
                })
            })
            .then(res => res.json())
            .then(data => {
                removeTyping();
                if (data && data.error) {
                    addBotMessage(data.error, null, true);
                    return;
                }
                addBotMessage(data.reply, data.sources, false);
                tsContextMessage = data.contextMessage || null;
                tsUsedKeys = Array.isArray(data.usedKeys) ? data.usedKeys : [];
            })
            .catch(() => {
                removeTyping();
                addBotMessage('Terjadi kesalahan saat menghubungi sistem. Coba lagi.', null, true);
            })
            .finally(() => {
                isSending = false;
                sendBtn.disabled = false;
                input.focus();
            });
    }

    function openPanel() {
        widget.classList.add('ts-open');
        input.focus();
    }

    function closePanel() {
        widget.classList.remove('ts-open');
    }

    fab.addEventListener('click', function() {
        widget.classList.contains('ts-open') ? closePanel() : openPanel();
    });
    closeBtn.addEventListener('click', closePanel);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && widget.classList.contains('ts-open')) closePanel();
    });

    sendBtn.addEventListener('click', kirimPesan);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            kirimPesan();
        }
    });
    input.addEventListener('input', autoResize);
})();
</script>
<?php endif; ?>
