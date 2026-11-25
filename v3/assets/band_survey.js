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
    /* ---------- 編集: 年度のプルダウン（複数選べる） ---------- */
    document.querySelectorAll('[data-survey-years]').forEach((dd) => {
        const text = dd.querySelector('[data-survey-years-text]');
        dd.addEventListener('change', () => {
            const ys = [...dd.querySelectorAll('input:checked')].map((i) => `${i.value}年入部`);
            text.textContent = ys.length ? ys.join('、') : '選ばない';
        });
        document.addEventListener('click', (e) => { if (!dd.contains(e.target)) dd.open = false; }); // 外を押したら閉じる
    });

    // 編集: 自分を個別の閲覧可能者から外すときは確かめる（年度の条件に入らなければ、自分も見られなくなる）
    document.querySelector('[data-survey-access]')?.addEventListener('submit', (e) => {
        const self = e.target.querySelector('[data-survey-self]');
        if (self && !self.checked && !confirm('自分を閲覧可能者から外します。年度の条件に入らなければ、この調査を見られなくなります。よろしいですか？')) {
            e.preventDefault();
        }
    });

    /* ---------- 表の絞り込み: 「◯位まで入れた人が 0〜4人」＋「以上 / 以下 / のみ」（どれもスロット）（どちらもスロット）バンドだけ出す ---------- */
    const filter = { rank: '1', mode: '', last: '1', more: 'eq' }; // mode: 人数（'' = 閉じている・絞り込まない）/ last: 開き直したときの人数
    const applyFilter = () => {
        const zero = filter.mode === '0'; // 0人には「以上 / 以下」がない（隠して「のみ」として扱う）
        const op = zero ? 'eq' : filter.more;
        const moreSlot = document.querySelector('[data-survey-filter-more]');
        if (moreSlot) moreSlot.hidden = zero;
        document.querySelectorAll('[data-survey-pane="table"] tr[data-ranks]').forEach((tr) => {
            const hits = tr.dataset.ranks.split(',').filter((r) => r !== '' && Number(r) <= Number(filter.rank)).length;
            tr.hidden = filter.mode !== '' && !({ ge: hits >= Number(filter.mode), le: hits <= Number(filter.mode), eq: hits === Number(filter.mode) })[op];
        });
        // バンドの見出しの（出ている数/全部）: 一時削除・絞り込みで隠したバンドは数えない
        const rows = [...document.querySelectorAll('[data-survey-pane="table"] tr[data-ranks]')];
        const count = document.querySelector('[data-survey-band-count]');
        if (count) count.textContent = `（${rows.filter((tr) => !tr.hidden && !tr.classList.contains('is-off')).length}/${rows.length}）`;
        const open = filter.mode !== '';
        const fbox = document.querySelector('[data-survey-filter]');
        const body = fbox?.querySelector('.survey-filter__body');
        if (body && open) { // 広がる先の大きさを中身から測る。漏斗の横に入らなければ下の段に出す
            body.style.setProperty('--w', `${body.scrollWidth}px`);
            // 高さは scrollHeight だと測れない（上下中央そろえで上にはみ出した分が入らず、開くたびに少しずつ伸びる）→ 中身の高さ＋上下の padding
            body.style.setProperty('--h', `${Math.max(...[...body.children].map((el) => el.offsetHeight)) + 6}px`);
            const room = fbox.clientWidth - parseFloat(getComputedStyle(fbox).paddingLeft) * 2 - fbox.querySelector('[data-survey-filter-toggle]').offsetWidth - 8;
            fbox.classList.toggle('is-stacked', body.scrollWidth - 6 > room);
        }
        fbox?.classList.toggle('is-open', open);
        const toggle = document.querySelector('[data-survey-filter-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? '絞り込みを解除' : '絞り込み');
            toggle.title = toggle.getAttribute('aria-label');
            toggle.querySelector('.icon').textContent = open ? 'filter_alt_off' : 'filter_alt';
        }
        document.querySelectorAll('[data-survey-filter-slot], [data-survey-filter-count], [data-survey-filter-more]').forEach((s) => s.classList.toggle('is-filtered', open));
        const text = document.querySelector('[data-survey-filter-text]');
        if (text) text.textContent = filter.rank === '1' ? 'に入れた人が' : 'まで入れた人が'; // 「1位まで」は言わない
    };
    const setupFilter = () => {
        const box = document.querySelector('[data-survey-filter]');
        if (!box || box.dataset.ready) return;
        box.dataset.ready = '1';
        makeSlot(box.querySelector('[data-survey-filter-slot]'), (value) => { filter.rank = value; applyFilter(); });
        makeSlot(box.querySelector('[data-survey-filter-count]'), (value, changed) => { // 最初の呼び出し（changed = false）では開かない
            if (!changed) return;
            filter.mode = filter.last = value;
            applyFilter();
        });
        makeSlot(box.querySelector('[data-survey-filter-more]'), (value) => { filter.more = value; applyFilter(); });
        box.querySelector('[data-survey-filter-toggle]').addEventListener('click', () => { filter.mode = filter.mode ? '' : filter.last; applyFilter(); });
    };
    if (!document.querySelector('[data-survey-pane="table"]')?.hidden) setupFilter();

    const DATA_EL = document.getElementById('survey-pdf-data');
    const box = document.querySelector('[data-pdf-box]');
    if (!DATA_EL || !box) return;
    let DATA = JSON.parse(DATA_EL.textContent); // 一時削除のあとに入れ替える
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
        // 並び順のタブで読み直しても、同じ方を開く（#pdf / #answers）
        document.querySelectorAll('a.tab[href*="band_survey?id="]').forEach((a) => {
            a.href = a.href.split('#')[0] + (view === 'table' ? '' : '#' + view);
        });
        history.replaceState(null, '', location.pathname + location.search + (view === 'table' ? '' : '#' + view));
        if (view === 'pdf') open();
        if (view === 'table') setupFilter(); // 隠れていると段の高さを測れないので、表を開いたときに作る
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
        let op = 1; // 描く濃さ（opacity）
        const drawChip = (ch, x, top) => {
            const cw = chipW(ch);
            const h = FS + 5;
            const y = Y(top + ROW_H / 2 + h / 2);
            if (ch.kind === 'ok') page.drawRectangle({ x, y, width: cw, height: h, color: heatColor(ch.heat), opacity: op });
            if (ch.kind === 'none') page.drawRectangle({ x, y, width: cw, height: h, color: surface2, opacity: op });
            if (ch.kind === 'out' || ch.kind === 'tbd') {
                page.drawRectangle({ x, y, width: cw, height: h, borderColor: border, borderWidth: 0.6, borderDashArray: [1.5, 1.5], borderOpacity: op });
            }
            page.drawText(chipLabel(ch), { x: x + PAD, y: mid(top), size: FS, font, color: ch.kind === 'ok' ? text : muted, opacity: op });
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
            op = row.off ? 0.35 : 1; // 一時削除したバンドは薄く（表の .is-off と同じ）
            page.drawLine({ start: { x: M, y: Y(top) }, end: { x: M + tableW, y: Y(top) }, thickness: 0.5, color: border });
            page.drawText(row.band, { x: M + CELL_PAD, y: mid(top), size: FS, font, color: text, opacity: op });
            if (row.low) {
                page.drawText('↓', { x: M + CELL_PAD + w(row.band) + 2, y: mid(top), size: FS, font, color: muted, opacity: op });
            }
            page.drawText(row.heat, { x: M + widths[0] + widths[1] - CELL_PAD - w(row.heat), y: mid(top), size: FS, font, color: text, opacity: op });
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
    // 拡大していないときの幅（枠の中 - 左右の余白 8px × 2。縦のスクロールバーの分も引かれる）。拡大中は枠が広がるので、広げる前に測った値を使う
    let fitCache = 0;
    const fitWidth = () => (box.classList.contains('is-wide') ? fitCache : box.clientWidth - 16);
    /** 拡大中は、枠を画面の左右いっぱいに広げる（カードとページの余白を使わない） */
    function wide(on) {
        box.classList.remove('is-wide');
        box.style.marginLeft = box.style.width = '';
        if (!on) return;
        fitCache = box.clientWidth - 16;
        box.classList.add('is-wide');
        box.style.marginLeft = `${-box.getBoundingClientRect().left}px`;
        box.style.width = `${document.documentElement.clientWidth}px`;
    }

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

    /** 拡大率を変える。指の真ん中（画面の座標 x, y）の場所が指の下に残るようにスクロールを合わせる。描き直しは指を止めてから */
    function setZoom(next, x, y) {
        next = Math.min(Math.max(next, 1), ZOOM_MAX);
        const f = next / zoom;
        const before = canvas.getBoundingClientRect();
        zoom = next;
        wide(zoom > 1);
        canvas.style.width = `${fitWidth() * zoom}px`;
        const after = canvas.getBoundingClientRect(); // 枠が広がると canvas の位置も動くので、測り直して合わせる
        box.scrollLeft += after.left + (x - before.left) * f - x;
        box.scrollTop += after.top + (y - before.top) * f - y;
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
        setZoom(pinch.zoom * (dist(e.touches) / pinch.d), (a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2);
    }, { passive: false });
    const end = (e) => { if (e.touches.length < 2) pinch = null; };
    box.addEventListener('touchend', end);
    box.addEventListener('touchcancel', end);
    // ダブルタップ（PC はダブルクリック）: 拡大していなければ 2.5 倍、していれば元に戻す
    box.addEventListener('dblclick', (e) => setZoom(zoom > 1 ? 1 : 2.5, e.clientX, e.clientY));
    // 画面の幅が変わったら描き直す
    window.addEventListener('resize', () => {
        if (!pdfBytes) return;
        wide(zoom > 1);
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

    /* ---------- 一時削除・回答タブの保存: ページを移らずに保存して、表・回答・プレビューの中身を入れ替える ---------- */
    const refresh = async (body) => {
        try {
            const res = await fetch(location.href, { method: 'POST', body }); // 保存のあとの転送先（同じ調査のページ）の HTML が返る
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const json = doc.getElementById('survey-pdf-data');
            if (!res.ok || !json) throw new Error('読み込めませんでした');
            doc.querySelectorAll('[data-toasts] .flash').forEach((f) => showToast(document.importNode(f, true)));
            if (doc.querySelector('[data-toasts] .flash--error')) { // 保存しなかった: 入れた順位を消さないように、入れ替えない
                document.querySelectorAll('[data-survey-ranks] button').forEach((b) => { b.disabled = false; });
                return;
            }
            const swap = (sel, keep) => {
                const old = document.querySelector(sel);
                const fresh = doc.querySelector(sel);
                if (!old || !fresh) throw new Error('読み込めませんでした');
                if (old.hasAttribute('data-survey-pane')) fresh.hidden = old.hidden;
                keep?.(old, fresh);
                old.replaceWith(fresh);
            };
            swap('[data-survey-pane="table"]', (old, fresh) => {
                const scrollers = (el) => [el, el.querySelector('.table-scroll')].filter(Boolean); // 横にスクロールする入れ物（カードと中の枠）
                const pos = scrollers(old).map((el) => el.scrollLeft);
                requestAnimationFrame(() => scrollers(fresh).forEach((el, i) => { el.scrollLeft = pos[i] ?? 0; }));
                // 絞り込みは入れ替えずに持っていく（選んだ順位・タブのまま）
                // shortcut: スロットの段（最大の順位）は読み直すまで前のまま。順位の数が変わるのは名簿を変えたときくらいなので
                const box = old.querySelector('[data-survey-filter]');
                const freshBox = fresh.querySelector('[data-survey-filter]');
                if (box && freshBox) freshBox.replaceWith(box);
            });
            setupFilter(); // 回答が初めて入って絞り込みが出たとき
            applyFilter();
            swap('[data-survey-pane="answers"]', (old, fresh) => {
                const open = new Set([...old.querySelectorAll('details[open]')].map((d) => d.dataset.member)); // 開いていた人は開いたまま
                fresh.querySelectorAll('details').forEach((d) => { d.open = open.has(d.dataset.member); });
            });
            swap('[data-survey-count]');
            DATA = JSON.parse(json.textContent);
            DATA_EL.textContent = json.textContent;
            // プレビューは作り直す（開いていれば今すぐ、閉じていれば次に開いたとき）
            pdfBytes = null;
            opened = false;
            saveBtn.disabled = true;
            if (!box.closest('[data-survey-pane]').hidden) open();
        } catch (err) {
            console.error(err);
            location.reload(); // うまく入れ替えられなければ、読み直して今の状態を出す
        }
    };
    document.addEventListener('change', (e) => {
        const cb = e.target.closest('[data-survey-off]');
        if (!cb) return;
        const body = new FormData(cb.form); // disabled にする前に読む（disabled の欄は送られない）
        cb.disabled = true;
        refresh(body);
    });
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-survey-ranks]');
        if (!form) return;
        e.preventDefault();
        const op = e.submitter?.name === 'op' ? e.submitter.value : '';
        if (op === 'clear' && !confirm(`${form.elements.member.value} の回答を消します。よろしいですか？`)) return;
        const body = new FormData(form);
        if (op) body.set('op', op);
        form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
        refresh(body);
    });

    if (location.hash === '#pdf' || location.hash === '#answers') show(location.hash.slice(1));
})();
