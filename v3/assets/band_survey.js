/**
 * =====================================================================
 *  band_survey.js — バンド希望調査の「プレビュー」タブ（band_survey.php?id=）
 * =====================================================================
 *  表の中身（#survey-pdf-data の JSON）から、ブラウザの中で PDF を作って見せる。
 *    1. pdf-lib で PDF を作る（日本語は Noto Sans JP を埋め込む。使った文字だけなので数十 KB）
 *    2. pdf.js で canvas に描く
 *    3. ピンチで拡大・縮小、ダブルタップで拡大 ⇔ 元に戻す（セッティングシートのプレビューと同じ）
 *  ライブラリとフォント（合わせて約 7MB）は、プレビューを初めて開いたときに読む。表だけ使う人は読まない
 * =====================================================================
 */
(() => {
    const DATA_EL = document.getElementById('survey-pdf-data');
    const box = document.querySelector('[data-pdf-box]');
    if (!DATA_EL || !box) return;
    const DATA = JSON.parse(DATA_EL.textContent);
    const canvas = box.querySelector('[data-pdf-canvas]');
    const note = document.querySelector('[data-pdf-note]');
    const saveBtn = document.querySelector('[data-pdf-save]');
    const VENDOR = ['assets/vendor/pdf-lib.min.js', 'assets/vendor/fontkit-shim.min.js'];
    const FONT = 'assets/fonts/NotoSansJP-Regular.ttf';
    const PDFJS = { lib: 'assets/vendor/pdfjs/pdf.min.js', worker: 'assets/vendor/pdfjs/pdf.worker.min.js' };
    const ZOOM_MAX = 4;

    /* ---------- 表 ⇔ プレビュー ---------- */
    const views = [...document.querySelectorAll('[data-survey-view]')];
    const show = (view) => {
        views.forEach((b) => {
            const on = b.dataset.surveyView === view;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', String(on));
        });
        document.querySelectorAll('[data-survey-pane]').forEach((p) => { p.hidden = p.dataset.surveyPane !== view; });
        // 並び順のタブで読み直しても、同じ方を開く（#pdf）
        document.querySelectorAll('a.tab[href*="band_survey?id="]').forEach((a) => {
            a.href = a.href.split('#')[0] + (view === 'pdf' ? '#pdf' : '');
        });
        history.replaceState(null, '', location.pathname + location.search + (view === 'pdf' ? '#pdf' : ''));
        if (view === 'pdf') open();
    };
    views.forEach((b) => b.addEventListener('click', () => show(b.dataset.surveyView)));

    /* ---------- 読み込み ---------- */
    const loaded = {};
    const loadScript = (src) => (loaded[src] ??= new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = () => { delete loaded[src]; reject(new Error('読み込めませんでした')); };
        document.head.append(s);
    }));
    let fontBytes = null;
    let pdfjs = null;

    /* ---------- PDF を作る ---------- */
    const FS = 7;          // 名前の文字の大きさ（pt）
    const PAD = 3;         // 名前の枠の左右の余白
    const GAP = 3;         // 名前どうしのすき間
    const ROW_H = FS + 10; // 1行の高さ
    const CELL_PAD = 6;    // 列の左右の余白
    const M = 24;          // ページの余白

    async function buildPdf() {
        await Promise.all(VENDOR.map(loadScript));
        fontBytes ??= await fetch(FONT).then((r) => {
            if (!r.ok) throw new Error('フォントを取得できませんでした');
            return r.arrayBuffer();
        });
        const { PDFDocument, rgb } = window.PDFLib;
        const doc = await PDFDocument.create();
        doc.registerFontkit(window.fontkit);
        const font = await doc.embedFont(fontBytes.slice(0), { subset: true });
        const w = (t, size = FS) => font.widthOfTextAtSize(t, size);

        // 色（app.css の --accent / --surface-2 / --muted / --text / --border と同じ）
        const accent = [217, 15, 15];
        const c = (r, g, b) => rgb(r / 255, g / 255, b / 255);
        const text = c(27, 26, 31);
        const muted = c(108, 106, 117);
        const surface2 = c(241, 239, 234);
        const border = c(225, 222, 215);
        // 優先度（1位 = 1、最下位 = 0）→ 白と赤を混ぜた色（表の .survey-chip と同じ割合）
        const heatColor = (h) => {
            const a = h * 0.55 + 0.08;
            return c(...accent.map((v) => 255 * (1 - a) + v * a));
        };
        const chipLabel = (ch) => (ch.rank ? `${ch.name} ${ch.rank}` : ch.name);
        const chipW = (ch) => w(chipLabel(ch)) + PAD * 2;

        // 列の幅: その列でいちばん長い中身に合わせる
        const head = ['バンド', '優先度', ...DATA.columns];
        const widths = head.map((t) => w(t, FS - 1) + CELL_PAD * 2);
        DATA.rows.forEach((row) => {
            widths[0] = Math.max(widths[0], w(row.band + (row.low ? ' ↓' : '')) + CELL_PAD * 2);
            widths[1] = Math.max(widths[1], w(row.heat) + CELL_PAD * 2);
            row.cells.forEach((cell, i) => {
                const sum = cell.reduce((s, ch) => s + chipW(ch), 0) + GAP * Math.max(0, cell.length - 1);
                widths[i + 2] = Math.max(widths[i + 2], sum + CELL_PAD * 2);
            });
        });
        const tableW = widths.reduce((s, x) => s + x, 0);
        const TITLE_H = 30;
        const pageW = Math.max(tableW + M * 2, 420);
        const pageH = M * 2 + TITLE_H + ROW_H * (DATA.rows.length + 1);
        const page = doc.addPage([pageW, pageH]);
        const Y = (top) => pageH - top; // 上から測った位置 → PDF の座標（下から）
        const mid = (top) => Y(top + ROW_H / 2) - FS * 0.37; // 行の縦の真ん中に文字を置く（setting_sheet.js の GLYPH_MID と同じ）

        // 見出し: 調査名と色の見本
        page.drawText(DATA.title, { x: M, y: Y(M + 12), size: 12, font, color: text });
        const legend = [
            { name: '1位', kind: 'ok', heat: 1 }, { name: '最下位', kind: 'ok', heat: 0 },
            { name: '未回答', kind: 'none' }, { name: '対象外', kind: 'out' },
        ];
        let lx = M + w(DATA.title, 12) + 16;
        const drawChip = (ch, x, top) => {
            const cw = chipW(ch);
            const h = FS + 5;
            const y = Y(top + ROW_H / 2 + h / 2);
            if (ch.kind === 'ok') page.drawRectangle({ x, y, width: cw, height: h, color: heatColor(ch.heat) });
            if (ch.kind === 'none') page.drawRectangle({ x, y, width: cw, height: h, color: surface2 });
            if (ch.kind === 'out' || ch.kind === 'tbd') {
                page.drawRectangle({ x, y, width: cw, height: h, borderColor: border, borderWidth: 0.6, borderDashArray: [1.5, 1.5] });
            }
            page.drawText(chipLabel(ch), { x: x + PAD, y: mid(top), size: FS, font, color: ch.kind === 'ok' ? text : muted });
            return cw;
        };
        legend.forEach((ch) => { lx += drawChip(ch, lx, M) + GAP; });

        // 表
        const top0 = M + TITLE_H;
        page.drawRectangle({ x: M, y: Y(top0 + ROW_H), width: tableW, height: ROW_H, color: surface2 });
        let x = M;
        head.forEach((t, i) => {
            page.drawText(t, { x: x + CELL_PAD, y: mid(top0), size: FS - 1, font, color: muted });
            x += widths[i];
        });
        DATA.rows.forEach((row, ri) => {
            const top = top0 + ROW_H * (ri + 1);
            page.drawLine({ start: { x: M, y: Y(top) }, end: { x: M + tableW, y: Y(top) }, thickness: 0.5, color: border });
            page.drawText(row.band, { x: M + CELL_PAD, y: mid(top), size: FS, font, color: text });
            if (row.low) {
                page.drawText('↓', { x: M + CELL_PAD + w(row.band) + 2, y: mid(top), size: FS, font, color: muted });
            }
            page.drawText(row.heat, { x: M + widths[0] + widths[1] - CELL_PAD - w(row.heat), y: mid(top), size: FS, font, color: text });
            let cx = M + widths[0] + widths[1];
            row.cells.forEach((cell, i) => {
                let px = cx + CELL_PAD;
                cell.forEach((ch) => { px += drawChip(ch, px, top) + GAP; });
                cx += widths[i + 2];
            });
        });
        return doc.save();
    }

    /* ---------- 描く・拡大縮小 ---------- */
    let pdfBytes = null;
    let zoom = 1;
    let rendering = false;
    let again = false;
    let timer = null;
    const fitWidth = () => box.clientWidth - 16;

    async function render() {
        if (rendering) { again = true; return; }
        if (fitWidth() <= 0) { requestAnimationFrame(render); return; }
        rendering = true;
        try {
            if (!pdfjs) {
                pdfjs = await import(new URL(PDFJS.lib, location.href).href);
                pdfjs.GlobalWorkerOptions.workerSrc = new URL(PDFJS.worker, location.href).href;
            }
            const task = pdfjs.getDocument({ data: pdfBytes.slice() });
            const pdf = await task.promise;
            const page = await pdf.getPage(1);
            const base = page.getViewport({ scale: 1 });
            const ratio = Math.min(window.devicePixelRatio || 1, 3);
            let scale = (fitWidth() * zoom / base.width) * ratio;
            const MAX_PIXELS = 16000000; // iPhone の canvas の上限（約 1600 万画素）
            const pixels = base.width * base.height * scale * scale;
            if (pixels > MAX_PIXELS) scale *= Math.sqrt(MAX_PIXELS / pixels);
            const viewport = page.getViewport({ scale });
            const off = document.createElement('canvas'); // 見えない canvas に描いてから写す（点滅しない）
            off.width = viewport.width;
            off.height = viewport.height;
            await page.render({ canvas: off, viewport }).promise;
            await task.destroy();
            canvas.width = off.width;
            canvas.height = off.height;
            canvas.getContext('2d').drawImage(off, 0, 0);
            canvas.style.width = `${fitWidth() * zoom}px`;
            note.textContent = '';
        } catch (err) {
            console.error(err);
            note.textContent = 'プレビューを作れませんでした';
        } finally {
            rendering = false;
            if (again) { again = false; render(); }
        }
    }

    let opened = false;
    async function open() {
        if (opened) return;
        opened = true;
        note.textContent = '作成中…';
        try {
            pdfBytes = await buildPdf();
            saveBtn.disabled = false;
            await render();
        } catch (err) {
            console.error(err);
            opened = false;
            note.textContent = 'プレビューを作れませんでした';
        }
    }

    /** 拡大率を変える。指の真ん中（cx, cy: 枠の左上から）の場所が指の下に残るようにスクロールを合わせる。描き直しは指を止めてから */
    function setZoom(next, cx, cy) {
        next = Math.min(Math.max(next, 1), ZOOM_MAX);
        const f = next / zoom;
        const x = box.scrollLeft + cx;
        const y = box.scrollTop + cy;
        zoom = next;
        canvas.style.width = `${fitWidth() * zoom}px`;
        box.scrollLeft = x * f - cx;
        box.scrollTop = y * f - cy;
        clearTimeout(timer);
        timer = setTimeout(render, 300);
    }
    // ピンチ（Touch Events を使う理由は setting_sheet.js のプレビューと同じ）
    let pinch = null;
    const dist = (t) => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    box.addEventListener('touchstart', (e) => { if (e.touches.length === 2) pinch = { d: dist(e.touches), zoom }; });
    box.addEventListener('touchmove', (e) => {
        if (!pinch || e.touches.length !== 2) return;
        e.preventDefault();
        const [a, b] = e.touches;
        const r = box.getBoundingClientRect();
        setZoom(pinch.zoom * (dist(e.touches) / pinch.d), (a.clientX + b.clientX) / 2 - r.left, (a.clientY + b.clientY) / 2 - r.top);
    }, { passive: false });
    const end = (e) => { if (e.touches.length < 2) pinch = null; };
    box.addEventListener('touchend', end);
    box.addEventListener('touchcancel', end);
    // ダブルタップ（PC はダブルクリック）: 拡大していなければ 2.5 倍、していれば元に戻す
    box.addEventListener('dblclick', (e) => {
        const r = box.getBoundingClientRect();
        setZoom(zoom > 1 ? 1 : 2.5, e.clientX - r.left, e.clientY - r.top);
    });
    // 画面の幅が変わったら描き直す
    window.addEventListener('resize', () => {
        if (!pdfBytes) return;
        canvas.style.width = `${fitWidth() * zoom}px`;
        clearTimeout(timer);
        timer = setTimeout(render, 300);
    });

    /* ---------- 保存 ---------- */
    saveBtn.addEventListener('click', () => {
        if (!pdfBytes) return;
        const url = URL.createObjectURL(new Blob([pdfBytes], { type: 'application/pdf' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `${DATA.title.replace(/[\\/:*?"<>|]/g, '_')}.pdf`;
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    if (location.hash === '#pdf') show('pdf');
})();
