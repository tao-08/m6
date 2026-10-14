/**
 * =====================================================================
 *  assets/sheet_editor.js — 型紙の管理（sheet_templates.php）の「枠の配置エディタ」
 * =====================================================================
 *  会場の PDF を pdf.js で描いて、その上に SVG で型紙の枠（どこに何を書くか）を重ねる。
 *  枠をドラッグすると、下の JSON の欄の座標が書き換わる。JSON を直接書き換えても枠が動く（両方向）。
 *
 *  ・SVG の viewBox を「PDF のページの大きさ（pt）」にしてあるので、SVG の中の座標 = 型紙の座標（pt）そのまま。
 *    マウスの位置だけ「画面の px → pt」に直せばいい（toPt）
 *  ・枠の種類
 *      rect  … [左, 上, 右, 下] の四角（バンド名の欄・セット図の枠・○で囲む選択肢・自由記入欄 など）
 *              中をドラッグ = 移動 / 辺をドラッグ = その辺だけ動かす
 *      table … メンバー表・セットリスト。縦の線（列の境目）・横の線（行の境目）を1本ずつドラッグできる
 *              外枠の中をドラッグ = 表ごと移動
 *  ・保存するとき、PDF のセット図の枠の中を PNG に切り抜いて一緒に送る（セッティングシート作成の画面の背景になる）
 * =====================================================================
 */
(() => {
    'use strict';

    const root = document.querySelector('[data-sheet-editor]');
    if (!root) return;
    const DATA = JSON.parse(document.getElementById('sheet-editor-data').textContent);
    const form = root.closest('form');
    const fileInput = form.querySelector('input[name="pdf"]');
    const textarea = form.querySelector('[data-config]');
    const pngInput = form.querySelector('input[name="stage_png"]');
    const wrap = root.querySelector('[data-ed-wrap]');
    const canvas = root.querySelector('[data-ed-canvas]');
    const svg = root.querySelector('[data-ed-svg]');
    const note = root.querySelector('[data-ed-note]');
    const cursorNote = root.querySelector('[data-ed-cursor]');
    const list = root.querySelector('[data-ed-list]');
    const panel = root.querySelector('[data-ed-panel]');
    // 詳細設定（バーの ⚙ で開く）。中身は選んでいる枠ごとに drawPanel() が作る
    const advBtn = root.querySelector('[data-ed-adv]');
    const advBox = root.querySelector('[data-ed-adv-box]');
    let out = panel;       // numInput / textInput / panelButton を足す先（panel か advBox）
    const zoomSelect = root.querySelector('[data-ed-zoom]');
    const fontInput = root.querySelector('[data-ed-font]');
    const SVG_NS = 'http://www.w3.org/2000/svg';

    // 1行の欄。year / month / day は「年」「月」が印刷済みのシート用（出演日から数字だけ書く）
    const FIELD_LABELS = { band: 'バンド名', kana: 'フリガナ', date: '出演日', year: '年', month: '月', day: '日', order: '出演順', rep: '代表者氏名', count: '出演人数' };
    const COL_LABELS = { part: 'パート', name: '氏名', part2: 'パート', name2: '氏名', mic: 'マイク', no: '曲順', title: '曲名', sound: '音響', light: '照明', request: '備考（音響・照明）', time: '分数' };
    const PAIR_LABELS = { part: 'パート1', name: '名前1', part2: 'パート2', name2: '名前2' };

    let cfg = null;        // 今の型紙（JSON を読んだもの）。ドラッグはこの中の数字を直接書き換える
    let items = [];        // 画面に出す枠の一覧（buildItems で cfg から作る）
    let selectedId = null;
    let pw = 595, ph = 842; // ページの大きさ（pt）。PDF を読んだら置き換える
    let pdfScale = 0;      // canvas の 1pt が何 px か（セット図の切り抜きに使う）
    let zoom = 1;

    // 0.1pt 単位に丸める（JSON が 123.456789 だらけにならないように）
    const r1 = (v) => Math.round(v * 10) / 10;
    const isNum = (v) => typeof v === 'number' && Number.isFinite(v);
    const isBox = (v) => Array.isArray(v) && v.length === 4 && v.every(isNum);
    const isCol = (v) => Array.isArray(v) && v.length === 2 && v.every(isNum);

    /* -----------------------------------------------------------------
     *  cfg → 枠の一覧
     *  値を書き換えるときは、cfg の中の配列・オブジェクトを直接書き換える（参照を持っておく）
     * ----------------------------------------------------------------- */

    /** 1つの値の読み書き（get / set）。table の線は、同じ位置の値をまとめて1本として動かす */
    const ref = (obj, key) => ({ get: () => obj[key], set: (v) => { obj[key] = r1(v); } });

    /** 同じ位置（0.1pt 単位）の値をまとめて1本の線にする */
    function groupRefs(refs) {
        const map = new Map();
        for (const rf of refs) {
            const k = r1(rf.get()).toFixed(1);
            if (!map.has(k)) map.set(k, []);
            map.get(k).push(rf);
        }
        return [...map.values()].map((rs) => ({
            get: () => rs[0].get(),
            set: (v) => rs.forEach((rf) => rf.set(v)),
        })).sort((a, b) => a.get() - b.get());
    }

    function rectItem(id, label, kind, box, remove, extra = {}) {
        return { id, label, kind, type: 'rect', box, remove, ...extra };
    }

    /** 選択肢のグループの見出し（型紙ごとの choice_labels → 共通の名前 → キーそのまま） */
    const groupLabel = (g) => cfg?.choice_labels?.[g] || DATA.choiceLabels[g] || g;

    /**
     * 選択肢1つの設定を { box, mark, text, text_box } の形で読む。
     *   型紙には [箱]（○で囲む）か { box, mark: 'check' | 'none', text, text_box } で書いてある
     */
    const optSpec = (v) => (Array.isArray(v) ? { box: v } : { ...v });
    /** 選択肢1つの設定を書き戻す。○で囲むだけ（文字なし）なら [箱] の短い形にする */
    function writeOpt(g, name, o) {
        if (!o.text) {
            delete o.text;
            delete o.text_box;
            delete o.size;
        }
        if (!o.mark || o.mark === 'circle') delete o.mark;
        cfg.choices[g][name] = Object.keys(o).length === 1 ? o.box : o;
    }
    const MARKS = { circle: '○で囲む', check: '✓を書く', none: '印なし（文字だけ）' };
    /** 固定の文字を囲む図形（枠の大きさそのまま） */
    const SHAPES = { '': 'なし', rect: '四角', ellipse: '丸' };

    /** メンバー表の組 [[パート列, 名前列], …]（2組目があれば2つ）。行の通し番号は左の組の上から下 → 右の組 */
    const memberPairs = (t) => [[t.part, t.name], ...(isCol(t.part2) && isCol(t.name2) ? [[t.part2, t.name2]] : [])].filter(([p, n]) => isCol(p) && isCol(n));

    /** セットリストの曲ごとの追加の列（setlist.cols = [{ key, label, col: [左, 右] }]）。形がおかしいものは除く */
    const extraCols = (t) => (Array.isArray(t.cols) ? t.cols.filter((c) => c && typeof c.key === 'string' && isCol(c.col)) : []);

    /**
     * 分数（time）と追加の列のうち、左右がそろって（1pt 以内）同じ列に積んであるもの。
     * 作成画面（setting_sheet.js の setlistCells）と同じ決め方で、行を上下に等分する。[[{ key, label, col }, …], …]
     */
    function stackedCols(t) {
        const list = [...extraCols(t).map((c) => ({ key: c.key, label: c.label || c.key, col: c.col })), ...(isCol(t.time) ? [{ key: 'time', label: '分数', col: t.time }] : [])];
        const groups = [];
        for (const c of list) {
            const g = groups.find((x) => Math.abs(x[0].col[0] - c.col[0]) <= 1 && Math.abs(x[0].col[1] - c.col[1]) <= 1);
            if (g) g.push(c);
            else groups.push([c]);
        }
        return groups;
    }

    /** メンバー表・セットリスト → table */
    function tableItem(id, label, kind, t) {
        const colKeys = Object.keys(t).filter((k) => isCol(t[k]) && k !== 'lines');
        // 追加の列（cols）の線も、ほかの列と同じように1本ずつドラッグできる
        const xs = groupRefs([...colKeys.flatMap((k) => [ref(t[k], 0), ref(t[k], 1)]), ...extraCols(t).flatMap((c) => [ref(c.col, 0), ref(c.col, 1)])]);
        let ys;
        let shiftY;
        let inner = []; // 動かせない線（row_h で等分している行の境目）
        if (Array.isArray(t.lines)) {
            ys = groupRefs(t.lines.map((_, i) => ref(t.lines, i)));
            shiftY = null;
        } else if (Array.isArray(t.slots)) {
            ys = groupRefs(t.slots.filter((s) => isCol(s.box)).flatMap((s) => [ref(s.box, 0), ref(s.box, 1)]));
            shiftY = null;
        } else if (isNum(t.top) && isNum(t.row_h) && isNum(t.rows)) {
            const bottom = () => t.top + t.row_h * t.rows;
            ys = [
                // 上の線: 下の線は動かさずに、行の高さを計算し直す
                { get: () => t.top, set: (v) => { const b = bottom(); t.top = r1(v); t.row_h = +((b - t.top) / t.rows).toFixed(3); } },
                { get: bottom, set: (v) => { t.row_h = +((v - t.top) / t.rows).toFixed(3); } },
            ];
            for (let i = 1; i < t.rows; i++) inner.push(() => t.top + t.row_h * i);
            shiftY = (dy, start) => { t.top = r1(start + dy); };
        } else {
            return null;
        }
        return { id, label, kind, type: 'table', t, colKeys, xs, ys, inner, shiftY };
    }

    function buildItems() {
        const out = [];
        if (!cfg) return out;
        for (const [k, f] of Object.entries(cfg.fields || {})) {
            if (f && isBox(f.box)) out.push(rectItem(`fields.${k}`, f.label || FIELD_LABELS[k] || k, 'fields', f.box, () => delete cfg.fields[k]));
        }
        // メンバー表・セットリスト・セット図の枠も消せる（消したら「＋ 枠を足す」から標準の形で出し直す。無いままは保存できない）
        if (cfg.members) {
            const it = tableItem('members', 'メンバー表', 'members', cfg.members);
            if (it) out.push({ ...it, remove: () => delete cfg.members });
        }
        if (cfg.setlist) {
            const it = tableItem('setlist', 'セットリスト', 'setlist', cfg.setlist);
            if (it) out.push({ ...it, remove: () => delete cfg.setlist });
        }
        if (isBox(cfg.stage)) out.push(rectItem('stage', 'セット図の枠', 'stage', cfg.stage, () => delete cfg.stage));
        if (isBox(cfg.drum)) out.push(rectItem('drum', 'ドラム（自動配置で避ける）', 'drum', cfg.drum, () => delete cfg.drum));
        for (const [g, opts] of Object.entries(cfg.choices || {})) {
            for (const [name, v] of Object.entries(opts || {})) {
                const box = isBox(v) ? v : (v && isBox(v.box) ? v.box : null);
                if (!box) continue;
                const mark = v.mark === 'check' ? '（✓）' : v.mark === 'none' ? '（印なし）' : '';
                out.push(rectItem(`choices.${g}.${name}`, `${groupLabel(g)}: ${name}${mark}`, 'choices', box, () => {
                    delete cfg.choices[g][name];
                    if (Object.keys(cfg.choices[g]).length === 0) {
                        delete cfg.choices[g];
                        if (cfg.choice_labels) delete cfg.choice_labels[g];
                    }
                }, { choice: { g, name } }));
                // 選んだときに書く文字の枠（例: 2タム →「（2Tom）」）
                if (v.text && isBox(v.text_box)) {
                    out.push(rectItem(`ctext.${g}.${name}`, `${name} → ${v.text}`, 'ctext', v.text_box, () => {
                        writeOpt(g, name, { ...optSpec(v), text: '' });
                    }, { choice: { g, name } }));
                }
            }
        }
        (cfg.stamps || []).forEach((s, i) => {
            if (s && isBox(s.box)) out.push(rectItem(`stamps.${i}`, s.text ? `「${s.text}」` : SHAPES[s.shape] || '（空）', 'stamps', s.box, () => cfg.stamps.splice(i, 1), { stamp: s }));
        });
        (cfg.texts || []).forEach((t, i) => {
            if (t && isBox(t.box)) out.push(rectItem(`texts.${i}`, t.label || t.key || `欄${i + 1}`, 'texts', t.box, () => cfg.texts.splice(i, 1), { text: t }));
        });
        return out;
    }

    /* -----------------------------------------------------------------
     *  描く
     * ----------------------------------------------------------------- */

    const el = (name, attrs = {}) => {
        const e = document.createElementNS(SVG_NS, name);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, String(v));
        return e;
    };
    /** 画面の 1px が何 pt か（文字の大きさ・つかめる線の太さを画面に合わせる） */
    const ptPerPx = () => pw / (svg.getBoundingClientRect().width || pw);

    function draw() {
        svg.setAttribute('viewBox', `0 0 ${pw} ${ph}`);
        svg.textContent = '';
        const k = ptPerPx();
        // 選んでいる枠は最後に描く（上に重なって、つかみやすい）
        const ordered = [...items.filter((it) => it.id !== selectedId), ...items.filter((it) => it.id === selectedId)];
        for (const it of ordered) {
            const g = el('g', { class: `ed-item ed-k-${it.kind}${it.id === selectedId ? ' is-selected' : ''}`, 'data-id': it.id });
            if (it.type === 'rect') {
                const [x0, y0, x1, y1] = it.box;
                g.append(el('rect', { x: x0, y: y0, width: Math.max(x1 - x0, 0.1), height: Math.max(y1 - y0, 0.1), class: 'ed-body', 'data-drag': 'move' }));
                // 辺: 太い透明の線（vector-effect で、拡大しても画面上で同じ太さ = つかみやすい）
                const edge = (xa, ya, xb, yb, which) => el('line', { x1: xa, y1: ya, x2: xb, y2: yb, class: `ed-edge ed-edge--${which < 2 ? 'v' : 'h'}`, 'data-drag': `edge${which}`, 'vector-effect': 'non-scaling-stroke' });
                // which: 0=左 1=右 2=上 3=下
                g.append(edge(x0, y0, x0, y1, 0), edge(x1, y0, x1, y1, 1), edge(x0, y0, x1, y0, 2), edge(x0, y1, x1, y1, 3));
                if (it.id === selectedId || (x1 - x0) / k > 50) {
                    const t = el('text', { x: x0 + 2 * k, y: y0 + 11 * k, class: 'ed-label', 'font-size': 10 * k, 'stroke-width': 3 * k });
                    t.textContent = it.label;
                    g.append(t);
                }
            } else {
                const xs = it.xs.map((x) => x.get());
                const ys = it.ys.map((y) => y.get());
                const [x0, x1, y0, y1] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
                g.append(el('rect', { x: x0, y: y0, width: x1 - x0, height: y1 - y0, class: 'ed-body', 'data-drag': 'move' }));
                for (const f of it.inner) {
                    g.append(el('line', { x1: x0, y1: f(), x2: x1, y2: f(), class: 'ed-inner', 'vector-effect': 'non-scaling-stroke' }));
                }
                // 線は「見える細い線」と「つかむ用の太い透明の線」の2本（細い線だけだと、つかみ損ねて表ごと動いてしまう）
                const line = (xa, ya, xb, yb, dir, drag) => {
                    const vis = el('line', { x1: xa, y1: ya, x2: xb, y2: yb, class: 'ed-line', 'vector-effect': 'non-scaling-stroke' });
                    const hit = el('line', { x1: xa, y1: ya, x2: xb, y2: yb, class: `ed-edge ed-edge--${dir}`, 'data-drag': drag, 'vector-effect': 'non-scaling-stroke' });
                    g.append(vis, hit);
                };
                it.ys.forEach((y, i) => line(x0, y.get(), x1, y.get(), 'h', `y${i}`));
                it.xs.forEach((x, i) => line(x.get(), y0, x.get(), y1, 'v', `x${i}`));
                // 列の名前（パート・氏名…）を列の上に。同じ列に積んだもの（コーラス/CD と分数など）は「上 / 下」の順につなぐ
                const heads = new Map(); // 列の左端 → [名前, …]
                const addHead = (l, text) => {
                    const key = Math.round(Math.min(...l) * 10) / 10;
                    heads.set(key, [...(heads.get(key) || []), text]);
                };
                for (const key of it.colKeys) {
                    if (key === 'time') continue; // 分数は下で（積んだ列と一緒に）
                    // 2組ある表は「名前1 / パート1 / 名前2 / パート2」で見分ける
                    const pair = it.colKeys.includes('part2') ? PAIR_LABELS[key] : null;
                    addHead(it.t[key], pair || COL_LABELS[key] || key);
                }
                if (it.id === 'setlist') {
                    const rowYs = Array.isArray(it.t.lines) ? it.t.lines : Array.from({ length: it.t.rows + 1 }, (_, i) => it.t.top + it.t.row_h * i);
                    for (const grp of stackedCols(it.t)) {
                        addHead(grp[0].col, grp.map((c) => c.label).join(' / '));
                        // 積んだ列は、行の中の段の境目を点線で（どれが上の段か分かるように）
                        if (grp.length < 2) continue;
                        const [l, r] = [Math.min(...grp[0].col), Math.max(...grp[0].col)];
                        for (let i = 0; i + 1 < rowYs.length; i++) {
                            for (let n = 1; n < grp.length; n++) {
                                const y = rowYs[i] + ((rowYs[i + 1] - rowYs[i]) * n) / grp.length;
                                g.append(el('line', { x1: l, y1: y, x2: r, y2: y, class: 'ed-inner ed-stack', 'vector-effect': 'non-scaling-stroke' }));
                            }
                        }
                    }
                }
                for (const [l, names] of heads) {
                    const t = el('text', { x: l + 2 * k, y: y0 - 3 * k, class: 'ed-label', 'font-size': 10 * k, 'stroke-width': 3 * k });
                    t.textContent = names.join(' / ');
                    g.append(t);
                }
                const t = el('text', { x: x0 + 2 * k, y: y0 + 11 * k, class: 'ed-label', 'font-size': 10 * k, 'stroke-width': 3 * k });
                t.textContent = it.label;
                g.append(t);
                // パートが印刷済みの行（詳細設定）: パートのセルに薄くそのパートを出す
                if (it.id === 'members' && it.shiftY) {
                    const pairs = memberPairs(it.t);
                    for (const [slot, label] of Object.entries(it.t.fixed || {})) {
                        const pair = pairs[Math.floor(+slot / it.t.rows)];
                        if (!pair || !label) continue;
                        const [pl, pr] = [Math.min(...pair[0]), Math.max(...pair[0])];
                        const fy = it.t.top + it.t.row_h * ((+slot % it.t.rows) + 0.5);
                        const ft = el('text', { x: (pl + pr) / 2, y: fy, class: 'ed-fixed', 'font-size': Math.min(it.t.row_h * 0.6, 12 * k), 'text-anchor': 'middle', 'dominant-baseline': 'central' });
                        ft.textContent = label;
                        g.append(ft);
                    }
                }
            }
            svg.append(g);
        }
        drawList();
        drawPanel();
    }

    function drawList() {
        list.textContent = '';
        for (const it of items) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = `ed-chip ed-k-${it.kind}${it.id === selectedId ? ' is-selected' : ''}`;
            b.textContent = it.label;
            b.addEventListener('click', () => select(it.id));
            list.append(b);
        }
    }

    /**
     * 選んでいる枠のパネル（数字・名前・削除）と、詳細設定（列を足す・印刷済み・印 など）。
     * 詳細設定の中身は選んでいる枠ごとに変わる。何も無い枠では ⚙ を押せなくする
     */
    function drawPanel() {
        const it = items.find((x) => x.id === selectedId);
        panel.textContent = '';
        advBox.textContent = '';
        out = panel;
        if (!it) {
            panel.innerHTML = '<span class="muted small">Ctrlキーをホールドして正方形に編集。矢印キーで 0.5pt（Shift で 5pt）ずつ調整可。</span>';
            syncAdv();
            return;
        }
        const title = document.createElement('strong');
        title.textContent = it.label;
        panel.append(title);
        const num = numInput;
        drawBasic(it, num);
        out = advBox;
        drawAdvanced(it, num);
        out = panel;
        if (it.remove) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn--ghost btn--danger btn--sm';
            del.title = 'この枠を消す';
            del.setAttribute('aria-label', 'この枠を消す');
            del.innerHTML = '<span class="icon" aria-hidden="true">delete</span>';
            del.addEventListener('click', () => {
                it.remove();
                selectedId = null;
                changed(true);
            });
            panel.append(del);
        }
        // ⚙ 詳細設定は消すボタンの右（中身が無い枠では出さない）
        panel.append(advBtn);
        syncAdv();
    }

    /** 詳細設定の中身が無ければ ⚙ を隠して閉じる。開いていたら、別の枠を選んでも開いたまま */
    let advOpen = false;
    function syncAdv() {
        const has = advBox.childElementCount > 0;
        advBtn.hidden = !has;
        advBox.hidden = !(has && advOpen);
        advBtn.setAttribute('aria-expanded', String(has && advOpen));
    }
    advBtn.addEventListener('click', () => {
        advOpen = !advOpen;
        syncAdv();
    });

    /** パネル: 位置の数字・名前 */
    function drawBasic(it, num) {
        if (it.type === 'rect') {
            ['左', '上', '右', '下'].forEach((name, i) => num(name, () => it.box[i], (v) => { it.box[i] = r1(v); }));
        } else if (it.shiftY) {
            num('行数', () => it.t.rows, (v) => {
                // 下の線の位置は変えずに、行を増やす・減らす
                const n = Math.max(1, Math.round(v));
                const b = it.t.top + it.t.row_h * it.t.rows;
                it.t.rows = n;
                it.t.row_h = +((b - it.t.top) / n).toFixed(3);
            });
        } else {
            const s = document.createElement('span');
            s.className = 'muted small';
            s.textContent = '線を1本ずつドラッグして合わせます。行・列を増やすときは JSON を書き換えてください。';
            panel.append(s);
        }
        if (it.choice) {
            const { g, name } = it.choice;
            textInput('グループの見出し', groupLabel(g), (v) => {
                cfg.choice_labels = { ...(cfg.choice_labels || {}), [g]: v };
                changed(true);
            });
            textInput('選択肢の名前', name, (v) => {
                if (v === name) return;
                if (v in cfg.choices[g]) {
                    note.textContent = `「${v}」はこのグループにもうあります。`;
                    drawPanel();
                    return;
                }
                renameOption(g, name, v);
                selectedId = `choices.${g}.${v}`;
                changed(true);
            });
        }
        if (it.text) {
            textInput('欄の名前（画面に出る）', it.text.label || '', (v) => { it.text.label = v; changed(true); });
        }
        if (it.stamp) {
            // 空にすると図形だけ書く
            textInput('書く文字', it.stamp.text || '', (v) => { it.stamp.text = v; changed(true); }, true);
        }
    }

    /** 詳細設定: 枠ごとの、列を足す・入れ替える・印刷済みのもの・印や囲み など */
    function drawAdvanced(it, num) {
        if (it.id === 'members' && it.t.mode !== 'slots') {
            membersPairPanel(it.t);
            membersSwapPanel(it.t);
            membersFixedPanel(it.t);
        }
        if (it.id === 'fields.kana') {
            // 作成画面の欄の名前（シートの表記に合わせる）。省略 = フリガナ
            const f = cfg.fields.kana;
            panelButton(f.label === 'ふりがな' ? '欄の名前を「フリガナ」に' : '欄の名前を「ふりがな」に', () => {
                if (f.label === 'ふりがな') delete f.label;
                else f.label = 'ふりがな';
                changed(true);
            });
        }
        if (it.id === 'setlist') {
            setlistNotePanel(it.t);
            setlistColsPanel(it.t);
        }
        if (it.id === 'stage') {
            // 部品（アンプ・マイク・人）の大きさの倍率。枠が大きいシートで自動配置がすかすかになるときに上げる
            num('部品の大きさ（倍率）', () => cfg.stage_scale || 1, (v) => {
                const s = Math.min(5, Math.max(0.3, r1(v)));
                if (s === 1) delete cfg.stage_scale;
                else cfg.stage_scale = s;
            });
            // アンプが印刷済みのシート: 自動配置でギター・ベースのアンプの四角を置かない
            const ampLabel = document.createElement('label');
            ampLabel.className = 'check';
            const amp = Object.assign(document.createElement('input'), { type: 'checkbox', checked: !!cfg.amps_printed });
            amp.addEventListener('change', () => {
                if (amp.checked) cfg.amps_printed = true;
                else delete cfg.amps_printed;
                changed();
            });
            ampLabel.append(amp, 'アンプが印刷済み');
            out.append(ampLabel);
        }
        if (it.choice) choicePanel(it.choice);
        if (it.stamp) {
            if (it.stamp.text) {
                panelButton(it.stamp.align === 'center' ? '左寄せにする' : '中央寄せにする', () => {
                    it.stamp.align = it.stamp.align === 'center' ? 'left' : 'center';
                    changed(true);
                });
            }
            const l = document.createElement('label');
            l.className = 'ed-num ed-text';
            l.textContent = '囲み';
            const sel = document.createElement('select');
            for (const [k, v] of Object.entries(SHAPES)) sel.append(new Option(v, k, false, (it.stamp.shape || '') === k));
            sel.addEventListener('change', () => {
                if (sel.value) it.stamp.shape = sel.value;
                else delete it.stamp.shape;
                changed(true);
            });
            l.append(sel);
            out.append(l);
            if (it.stamp.shape) num('線の太さ', () => it.stamp.line || 0.9, (v) => { it.stamp.line = Math.max(0.1, r1(v)); });
        }
    }

    /** パネルに数字の入力欄を1つ足す */
    function numInput(label, get, set) {
        const l = document.createElement('label');
        l.className = 'ed-num';
        l.textContent = label;
        const inp = document.createElement('input');
        inp.type = 'number';
        inp.step = '0.1';
        inp.value = String(+get().toFixed(3));
        inp.addEventListener('change', () => {
            if (inp.value === '' || !Number.isFinite(+inp.value)) return;
            set(+inp.value);
            changed();
        });
        l.append(inp);
        out.append(l);
    }

    /** パネルに文字の入力欄を1つ足す。打ち終わって確定したとき（change）だけ onSet を呼ぶ */
    function textInput(label, value, onSet, allowEmpty = false) {
        const l = document.createElement('label');
        l.className = 'ed-num ed-text';
        l.textContent = label;
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.maxLength = 50;
        inp.value = value;
        inp.addEventListener('change', () => {
            const v = inp.value.trim();
            if (v !== '' || allowEmpty) onSet(v);
        });
        l.append(inp);
        out.append(l);
    }

    /**
     * メンバー表: 右にもう1組（パート・氏名）ある表（4列）にする・1組に戻す。
     * 足すときは、今の組と同じ幅で氏名列のすぐ右に置く（あとで線をドラッグして合わせる）
     */
    function membersPairPanel(t) {
        if (Array.isArray(t.part2) && Array.isArray(t.name2)) {
            panelButton('右の組（パート・氏名）を消す', () => {
                delete t.part2;
                delete t.name2;
                changed(true);
            });
            return;
        }
        if (!Array.isArray(t.part) || !Array.isArray(t.name)) return;
        panelButton('＋ 右にもう1組（パート・氏名）を足す', () => {
            // 1組目のいちばん右の線から、1組目と同じ並び（名前が先 / パートが先）・同じ幅で置く。
            // 1組目の列に重ねると線がくっついて（groupRefs）離せなくなる
            const pw2 = Math.abs(t.part[1] - t.part[0]);
            const nw = Math.abs(t.name[1] - t.name[0]);
            const x = Math.max(...t.part, ...t.name);
            const nameFirst = Math.min(...t.name) < Math.min(...t.part);
            const [w1, w2] = nameFirst ? [nw, pw2] : [pw2, nw];
            const first = [r1(x), r1(x + w1)];
            const second = [r1(x + w1), r1(x + w1 + w2)];
            [t.part2, t.name2] = nameFirst ? [second, first] : [first, second];
            note.textContent = '右にもう1組置きました。左の組が埋まったら、右の組の上から書きます。線をドラッグして合わせてください。';
            changed(true);
        });
    }

    /**
     * メンバー表: パートの列と名前の列の役割を入れ替える（線の位置はそのまま）。
     * 見本はパートが左なので、名前が左のシート（MEMBER → Position）はこれで合わせる。
     * 真ん中の線はパートと名前で共有していて、ドラッグでは入れ替えられない
     */
    function membersSwapPanel(t) {
        if (!Array.isArray(t.part) || !Array.isArray(t.name)) return;
        panelButton('⇄ パートと名前を入れ替える', () => {
            [t.part, t.name] = [t.name, t.part];
            if (Array.isArray(t.part2) && Array.isArray(t.name2)) [t.part2, t.name2] = [t.name2, t.part2];
            note.textContent = '線の位置はそのままです。真ん中の線をドラッグして幅を合わせてください。';
            changed(true);
        });
    }

    /**
     * セットリスト: 「音響」「照明」の2列 ⇔ 1つの「備考」列（request）を切り替える。
     * 備考列のシートには、音響と照明への要望を「音響 / 照明：…」とつないで書く（setting_sheet.js）
     */
    function setlistNotePanel(t) {
        // 表の左端・右端（任意の列を足す場所）
        const cols = Object.keys(t).filter((k) => isCol(t[k]) && k !== 'lines').map((k) => t[k]);
        const left = Math.min(...cols.flat());
        const right = Math.max(...cols.flat());
        // 曲順（印刷済みのシートは無くてよい）: 足すときは表の左に 30pt
        if (isCol(t.no)) {
            panelButton('曲順の列を消す', () => { delete t.no; changed(true); });
        } else {
            panelButton('＋ 曲順の列を足す', () => {
                t.no = [r1(left - 30), r1(left)];
                note.textContent = '表の左に曲順の列を足しました。線をドラッグして合わせてください。';
                changed(true);
            });
        }
        // 音響・照明（備考）の列が無いシート: 足すときは表の右に 60pt × 2
        if (!isCol(t.request) && !(isCol(t.sound) && isCol(t.light))) {
            panelButton('＋ 音響・照明の列を足す', () => {
                t.sound = [r1(right), r1(right + 60)];
                t.light = [r1(right + 60), r1(right + 120)];
                note.textContent = '表の右に音響・照明の列を足しました。線をドラッグして合わせてください。';
                changed(true);
            });
            return;
        }
        panelButton('音響・照明の列を消す', () => {
            delete t.sound;
            delete t.light;
            delete t.request;
            changed(true);
        });
        if (isCol(t.request)) {
            panelButton('音響と照明を別の列に分ける', () => {
                const [l, r] = t.request;
                const mid = r1((l + r) / 2);
                delete t.request;
                t.sound = [l, mid];
                t.light = [mid, r];
                changed(true);
            });
            return;
        }
        if (!isCol(t.sound) || !isCol(t.light)) return;
        panelButton('音響・照明を1つの「備考」列にする', () => {
            const l = Math.min(t.sound[0], t.light[0]);
            const r = Math.max(t.sound[1], t.light[1]);
            delete t.sound;
            delete t.light;
            t.request = [l, r];
            note.textContent = '音響と照明を1つの「備考」列にしました。両方の要望があるときは「音響 / 照明：…」とつないで書きます。';
            changed(true);
        });
    }

    /**
     * セットリストの分数（time）と、曲ごとの追加の列（cols: コーラス/CD・備考 など）
     *   新しい列は、選んだ列（曲名・音響・照明・備考・分数）の右端を縮めて、そこに置く（ほかの列の線は動かさない）
     *   「分数と同じ列」に足すと、その列の行を上下に等分して上に積む（クラブ・ドロシーの「コーラス/CD トラック」と「分数」）
     */
    function setlistColsPanel(t) {
        if (!isCol(t.title)) return;
        // 列（key）の右端から w pt（列の半分まで）を切り取って新しい列にする。狭すぎる列からは取らない。
        // 分数と同じ左右に積んである列も一緒に縮める（積んだ形をくずさない）
        const takeFrom = (key, w) => {
            const [l, r] = [Math.min(...t[key]), Math.max(...t[key])];
            if (r - l < 20) {
                note.textContent = `「${COL_LABELS[key]}」の列が狭すぎて足せません。先に広げてください。`;
                return null;
            }
            const cut = r1(r - Math.min(w, (r - l) / 2));
            const same = (c) => Math.abs(Math.min(...c) - l) <= 1 && Math.abs(Math.max(...c) - r) <= 1;
            for (const c of [t[key], ...(key === 'time' ? extraCols(t).map((x) => x.col).filter(same) : [])]) {
                c[c[1] >= c[0] ? 1 : 0] = cut;
            }
            return [cut, r];
        };
        // 消した列の幅を、隣の列（ふつうは切り取った元の曲名）に返す。線が残らないように。
        // 同じ左右に積んである列がまだあれば、その列が使うので返さない
        const giveBack = (col) => {
            const near = (a, b) => Math.abs(a - b) <= 1;
            const others = [...Object.keys(t).filter((k) => isCol(t[k]) && k !== 'lines').map((k) => t[k]), ...extraCols(t).map((c) => c.col)].filter((c) => c !== col);
            const [l, r] = [Math.min(...col), Math.max(...col)];
            if (others.some((c) => near(Math.min(...c), l) && near(Math.max(...c), r))) return;
            // 隣が積んである列（分数とその上の段）なら、積んだ列ぜんぶに返す
            const left = others.filter((c) => near(Math.max(...c), l));
            if (left.length) {
                left.forEach((c) => { c[c[1] >= c[0] ? 1 : 0] = r; });
                return;
            }
            others.filter((c) => near(Math.min(...c), r)).forEach((c) => { c[c[0] <= c[1] ? 0 : 1] = l; });
        };
        if (isCol(t.time)) {
            panelButton('分数の列を消す', () => {
                giveBack(t.time);
                delete t.time;
                delete t.time_colon;
                changed(true);
            });
            // 「:」が印刷されているシートは、分と秒を「:」の左右に書く。無いシートは「3:45」と書く
            panelButton(t.time_colon ? '「:」は印刷済み → 印刷なしにする' : '「:」は印刷なし → 印刷済みにする', () => {
                if (t.time_colon) delete t.time_colon;
                else t.time_colon = true;
                changed(true);
            });
        } else {
            panelButton('＋ 分数の列を足す', () => {
                const col = takeFrom('title', 40);
                if (!col) return;
                t.time = col;
                note.textContent = '曲名の右に分数の列を足しました。線をドラッグして合わせてください。';
                changed(true);
            });
        }

        // 追加の列の一覧: 名前を変える・消す
        for (const c of extraCols(t)) {
            textInput('列の名前', c.label || '', (v) => {
                c.label = v.slice(0, 30);
                changed(true);
            });
            panelButton(`「${c.label || c.key}」の列を消す`, () => {
                giveBack(c.col);
                t.cols = extraCols(t).filter((x) => x !== c);
                if (t.cols.length === 0) delete t.cols;
                changed(true);
            });
        }

        // 列を足す: 名前と置き方（○○の右 / 分数と同じ列）
        const wrap = document.createElement('span');
        wrap.className = 'ed-num ed-text';
        const name = Object.assign(document.createElement('input'), { type: 'text', maxLength: 30, placeholder: '列の名前（例: コーラス/CD）' });
        name.setAttribute('aria-label', '足す列の名前');
        const where = document.createElement('select');
        where.setAttribute('aria-label', '足す列の置き方');
        for (const k of ['title', 'sound', 'light', 'request', 'time']) {
            if (isCol(t[k])) where.append(new Option(`${COL_LABELS[k]}の右`, k));
        }
        if (isCol(t.time)) where.append(new Option('分数と同じ列（上下に分ける）', 'stack'));
        const add = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn--ghost btn--sm', textContent: '＋ 列を足す' });
        add.addEventListener('click', () => {
            const label = name.value.trim();
            if (label === '') {
                name.focus();
                return;
            }
            const stack = where.value === 'stack' && isCol(t.time);
            const col = stack ? [...t.time] : isCol(t[where.value]) ? takeFrom(where.value, 50) : null;
            if (!col) return;
            // キーは型紙の中で重ならない名前（c1, c2 …）。作成画面の入力はこのキーで保存する
            const used = new Set(extraCols(t).map((c) => c.key));
            let n = 1;
            while (used.has(`c${n}`)) n++;
            t.cols = [...extraCols(t), { key: `c${n}`, label, col }];
            note.textContent = stack ? `「${label}」を分数の上の段に足しました。` : `${COL_LABELS[where.value]}の右に「${label}」の列を足しました。線をドラッグして合わせてください。`;
            changed(true);
        });
        wrap.append(name, where, add);
        out.append(wrap);
    }

    /** パネルにボタンを1つ足す */
    function panelButton(text, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn--ghost btn--sm';
        b.textContent = text;
        b.addEventListener('click', onClick);
        out.append(b);
    }

    /** 選択肢の詳細設定: ○/✓・選んだときに書く文字・このグループに選択肢を足す（見出しと名前は drawBasic） */
    function choicePanel({ g, name }) {
        const spec = optSpec(cfg.choices[g][name]);
        // 選んだときの印（○ / ✓ / 印なし）
        const l = document.createElement('label');
        l.className = 'ed-num ed-text';
        l.textContent = '選んだときの印';
        const sel = document.createElement('select');
        for (const [k, v] of Object.entries(MARKS)) sel.append(new Option(v, k, false, (spec.mark || 'circle') === k));
        sel.addEventListener('change', () => {
            writeOpt(g, name, { ...spec, mark: sel.value });
            changed(true);
        });
        l.append(sel);
        out.append(l);
        // 選んだときに書く文字。初めて入れたら、選択肢の枠の右に文字の枠を置く（空にすると枠ごと消える）
        textInput('選んだときに書く文字', spec.text || '', (v) => {
            const [x0, y0, x1, y1] = spec.box;
            const textBox = isBox(spec.text_box) ? spec.text_box : [r1(x1 + 6), y0, r1(x1 + 6 + Math.max(x1 - x0, 40)), y1];
            writeOpt(g, name, { ...spec, text: v, text_box: textBox });
            if (v && !isBox(spec.text_box)) {
                selectedId = `ctext.${g}.${name}`;
                note.textContent = `「${v}」を書く枠を置きました。書きたい場所にドラッグしてください。`;
            }
            changed(true);
        }, true);
        panelButton('＋ このグループに選択肢を足す', () => {
            // 最後の選択肢の箱の右に、同じ大きさ・同じ印で置く（書く文字は人によって違うので写さない）
            const boxes = Object.values(cfg.choices[g]).map((v) => (Array.isArray(v) ? v : v.box));
            const [x0, y0, x1, y1] = boxes[boxes.length - 1];
            const w = x1 - x0;
            let n = boxes.length + 1;
            while (`選択肢${n}` in cfg.choices[g]) n++;
            const newName = `選択肢${n}`;
            const box = [r1(x1 + 6), y0, r1(x1 + 6 + w), y1];
            cfg.choices[g][newName] = box;
            writeOpt(g, newName, { box, mark: spec.mark });
            selectedId = `choices.${g}.${newName}`;
            changed(true);
        });
    }

    /**
     * 選択肢の名前を変える。並び順はそのまま（画面のボタンの順になるので）。
     * show_if（この選択のときだけ見せる）・choice_notes（選んだときの説明）で前の名前を使っている所も直す
     */
    function renameOption(g, from, to) {
        const swap = (list) => list.map((x) => (x === from ? to : x));
        cfg.choices[g] = Object.fromEntries(Object.entries(cfg.choices[g]).map(([k, v]) => [k === from ? to : k, v]));
        for (const cond of Object.values(cfg.show_if || {})) {
            if (Array.isArray(cond) && cond[0] === g && Array.isArray(cond[1])) cond[1] = swap(cond[1]);
        }
        for (const t of cfg.texts || []) {
            if (Array.isArray(t.show_if) && t.show_if[0] === g && Array.isArray(t.show_if[1])) t.show_if[1] = swap(t.show_if[1]);
        }
        const notes = cfg.choice_notes?.[g];
        if (notes && from in notes) {
            cfg.choice_notes[g] = Object.fromEntries(Object.entries(notes).map(([k, v]) => [k === from ? to : k, v]));
        }
    }

    function select(id) {
        selectedId = id;
        draw();
    }

    /** cfg を書き換えたあと: JSON の欄に書き戻して描き直す（rebuild: 枠の数が変わったとき） */
    function changed(rebuild = false) {
        textarea.value = JSON.stringify(cfg, null, 2);
        record();
        if (rebuild) items = buildItems();
        if (rebuild) fillAddMenu();
        draw();
        queuePreview(); // プレビューのタブが開いていれば送り直す
    }

    /* -----------------------------------------------------------------
     *  ドラッグ
     *  つかんだ瞬間の値（start）を覚えて、「start + マウスが動いた量」を入れる。
     *  （動くたびに差分を足していくと、0.1pt の丸めで少しずつズレる）
     * ----------------------------------------------------------------- */
    function toPt(e) {
        const r = svg.getBoundingClientRect();
        return [(e.clientX - r.left) / r.width * pw, (e.clientY - r.top) / r.height * ph];
    }

    /** つかんだ所（data-drag）→ 動かすものの一覧 [{start, set, axis}] */
    function dragOps(it, what) {
        const op = (get, set, axis) => ({ start: get(), set, axis });
        // 線・辺1本のドラッグは、隣の線を追い越さない（GAP pt 手前で止める）。
        //   追い越すと列の左右が逆になり、ぴったり重ねると groupRefs で1本にくっついて離せなくなる
        const GAP = 2;
        const limit = (set, lo, hi) => (v) => set(Math.min(hi, Math.max(lo, v)));
        if (it.type === 'rect') {
            const b = it.box;
            const side = (i, axis) => op(() => b[i], (v) => { b[i] = r1(v); }, axis);
            if (what === 'move') return [side(0, 'x'), side(2, 'x'), side(1, 'y'), side(3, 'y')];
            const i = [0, 2, 1, 3][+what.slice(4)]; // edge0〜3 = 左・右・上・下 → box の添字
            const o = side(i, i % 2 === 0 ? 'x' : 'y');
            // 左・上の辺は向かいの辺の GAP 手前まで、右・下の辺は向かいの辺の GAP 先から
            const other = b[i < 2 ? i + 2 : i - 2];
            o.set = limit(o.set, i < 2 ? -Infinity : other + GAP, i < 2 ? other - GAP : Infinity);
            return [o];
        }
        if (what === 'move') {
            const ops = it.xs.map((x) => op(x.get, x.set, 'x'));
            if (it.shiftY) {
                const st = it.t.top;
                ops.push({ start: 0, axis: 'y', set: (dy) => it.shiftY(dy, st) });
            } else {
                ops.push(...it.ys.map((y) => op(y.get, y.set, 'y')));
            }
            return ops;
        }
        // xs / ys は位置の順に並んでいる（groupRefs）ので、前後が隣の線
        const lines = what[0] === 'x' ? it.xs : it.ys;
        const n = +what.slice(1);
        const g = lines[n];
        const lo = n > 0 ? lines[n - 1].get() + GAP : -Infinity;
        const hi = n < lines.length - 1 ? lines[n + 1].get() - GAP : Infinity;
        return [op(g.get, limit(g.set, lo, hi), what[0])];
    }

    let drag = null;
    svg.addEventListener('pointerdown', (e) => {
        const target = e.target.closest('[data-drag]');
        const g = e.target.closest('[data-id]');
        if (!target || !g) return;
        const it = items.find((x) => x.id === g.dataset.id);
        if (!it) return;
        e.preventDefault();
        selectedId = it.id;
        const what = target.dataset.drag;
        // 四角の枠の辺をつかんだときは、Ctrl / Shift で正方形にできるように辺の番号を覚えておく
        const edge = it.type === 'rect' && what.startsWith('edge') ? +what.slice(4) : null;
        drag = { from: toPt(e), ops: dragOps(it, what), moved: false, box: it.box, edge };
        svg.setPointerCapture(e.pointerId); // 枠の外までマウスが出ても、離すまで追いかける
        draw();
    });
    svg.addEventListener('pointermove', (e) => {
        const [x, y] = toPt(e);
        cursorNote.textContent = `x ${x.toFixed(1)} / y ${y.toFixed(1)} pt`;
        if (!drag) return;
        const dx = x - drag.from[0];
        const dy = y - drag.from[1];
        for (const o of drag.ops) o.set(o.start + (o.axis === 'x' ? dx : dy));
        if (drag.edge !== null && (e.ctrlKey || e.shiftKey)) square(drag.box, drag.edge);
        drag.moved = true;
        draw();
    });

    /**
     * Ctrl / Shift を押しながら辺をドラッグ → 正方形にする（Office の図形と同じ。丸の図形なら円になる）。
     * 左右の辺なら今の幅に高さを合わせて下の辺を動かし、上下の辺なら今の高さに幅を合わせて右の辺を動かす
     */
    function square(b, edge) {
        if (edge < 2) {
            b[3] = r1(b[1] + (b[2] - b[0])); // edge0 / 1 = 左・右の辺
        } else {
            b[2] = r1(b[0] + (b[3] - b[1])); // edge2 / 3 = 上・下の辺
        }
    }
    const endDrag = () => {
        if (drag && drag.moved) changed(true);
        drag = null;
    };
    svg.addEventListener('pointerup', endDrag);
    svg.addEventListener('pointercancel', endDrag);
    // 何も無い所をクリック → 選ぶのをやめる
    svg.addEventListener('click', (e) => {
        if (!e.target.closest('[data-id]') && selectedId !== null) select(null);
    });

    // 矢印キーで選んでいる枠を動かす（入力欄に文字を打っているときは除く）
    document.addEventListener('keydown', (e) => {
        if (!selectedId || !e.key.startsWith('Arrow') || e.target.closest('input, textarea, select')) return;
        const it = items.find((x) => x.id === selectedId);
        if (!it) return;
        e.preventDefault();
        const step = e.shiftKey ? 5 : 0.5;
        const dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0;
        const dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0;
        for (const o of dragOps(it, 'move')) o.set(o.start + (o.axis === 'x' ? dx : dy));
        changed(true);
    });

    /* -----------------------------------------------------------------
     *  JSON の欄 → 枠（手で書き換えたとき・見本を入れたとき）
     * ----------------------------------------------------------------- */
    let timer = 0;
    function readJson() {
        const text = textarea.value.trim();
        if (text === '') {
            cfg = null;
            items = [];
            fillAddMenu();
            note.textContent = '「見本にする型紙」を選んで座標を入れると、枠が出ます。';
            record();
                draw();
            return;
        }
        try {
            const v = JSON.parse(text);
            if (!v || typeof v !== 'object' || Array.isArray(v)) throw new Error('{ } で囲んだ形にしてください');
            cfg = v;
            items = buildItems();
            if (!items.some((x) => x.id === selectedId)) selectedId = null;
            fillAddMenu();
            fontInput.value = String(cfg.font_scale || 1);
            note.textContent = '';
            record();
            } catch (err) {
            note.textContent = `JSON の書き方が間違っています（${err.message}）。直すまで枠は前のままです。`;
        }
        draw();
    }
    textarea.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(readJson, 400); // 打つたびに読むと重いので、手が止まってから
    });

    /* -----------------------------------------------------------------
     *  1つ戻す・やり直す（左下のボタン / Ctrl+Z・Ctrl+Y）
     *  変わるたびに JSON の文字をまるごと履歴に積み、何番目を出しているか（histPos）を前後に動かす。
     *  戻した所から新しく変えたら、それより先（やり直せた分）は捨てる
     * ----------------------------------------------------------------- */
    const undoBtn = root.querySelector('[data-ed-undo]');
    const redoBtn = root.querySelector('[data-ed-redo]');
    let hist = [];
    let histPos = -1;
    function record() {
        const v = textarea.value;
        if (hist[histPos] === v) return; // 戻した・やり直した直後に読み直したときなど、同じ中身は積まない
        hist = hist.slice(0, histPos + 1);
        hist.push(v);
        if (hist.length > 200) hist.shift(); // 古いものから捨てる
        histPos = hist.length - 1;
        syncHistBtns();
    }
    function syncHistBtns() {
        undoBtn.disabled = histPos <= 0;
        redoBtn.disabled = histPos >= hist.length - 1;
    }
    /** step = -1 で1つ戻す、+1 でやり直す */
    function moveHist(step) {
        const next = histPos + step;
        if (next < 0 || next >= hist.length) return;
        histPos = next;
        clearTimeout(timer); // 打ちかけの JSON を後から読み直して上書きしないように
        textarea.value = hist[histPos];
        readJson();
        syncHistBtns();
        form.dispatchEvent(new Event('input', { bubbles: true })); // プレビューを送り直す・「保存していない変更」の印
    }
    undoBtn.addEventListener('click', () => moveHist(-1));
    redoBtn.addEventListener('click', () => moveHist(1));
    // 入力欄の中では、その欄の文字の「元に戻す」を優先する（Mac は ⌘）
    document.addEventListener('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey) || e.altKey || e.target.closest('input, textarea, select')) return;
        const k = e.key.toLowerCase();
        if (k === 'z' && !e.shiftKey) moveHist(-1);
        else if (k === 'y' || (k === 'z' && e.shiftKey)) moveHist(1);
        else return;
        e.preventDefault();
    });

    /* -----------------------------------------------------------------
     *  「＋ 枠を足す…」: 選んだものをページの真ん中あたりに置く
     *    field:キー … 1行の欄（バンド名・年・月・日・代表者氏名 など。型紙にまだ無いものだけ選べる）
     *    text       … 自由記入欄
     *    choice     … 選択肢のグループ（「あり」「なし」の2つを横に並べて置く）
     * ----------------------------------------------------------------- */
    const addSelect = root.querySelector('[data-ed-add]');

    /** 型紙に必ず要る3つ（作成画面はこれがある前提で動く） */
    const MAIN_PARTS = { members: 'メンバー表', setlist: 'セットリスト', stage: 'セット図の枠' };
    /** その3つがちゃんとあるか（buildItems() で枠になる形か） */
    const hasMain = (k) => (k === 'stage' ? isBox(cfg?.stage) : !!(cfg?.[k] && tableItem(k, '', k, cfg[k])));

    /** プルダウンの中身を作り直す（もうある欄は出さない） */
    function fillAddMenu() {
        addSelect.textContent = '';
        const opt = (value, text) => addSelect.append(new Option(text, value));
        opt('', '＋ 枠を足す…');
        // 無いときだけ（消したとき・白紙から作るとき）
        for (const [k, label] of Object.entries(MAIN_PARTS)) {
            if (!hasMain(k)) opt(k, label);
        }
        for (const [k, label] of Object.entries(FIELD_LABELS)) {
            if (!cfg?.fields?.[k]) opt(`field:${k}`, label);
        }
        opt('text', '自由記入欄');
        opt('stamp', '固定の文字（いつも書く）');
        opt('shape', '図形（四角・丸）');
        opt('choice', '選択肢のグループ（○で囲む・✓を書く）');
    }

    addSelect.addEventListener('change', () => {
        const what = addSelect.value;
        if (!what) return;
        if (!cfg) cfg = { fields: {} }; // 白紙から: メンバー表などもこのプルダウンから出す
        const cx = pw / 2, cy = ph / 2;
        const x = (v) => r1(cx + v);
        const y = (v) => r1(cy + v);
        if (what === 'members') {
            // 標準の形: パート | 名前 の5行（名前が左のシートは「⇄ パートと名前を入れ替える」）
            cfg.members = { top: y(-50), row_h: 20, rows: 5, part: [x(-120), x(-60)], name: [x(-60), x(120)] };
            selectedId = 'members';
            note.textContent = 'メンバー表をページの真ん中に置きました。線をドラッグしてシートの罫線に合わせてください。行数は下で変えられます。';
        } else if (what === 'setlist') {
            // 標準の形: 曲順 | 曲名 | 音響 | 照明 の10行（幅 320pt）
            cfg.setlist = {
                top: y(-100), row_h: 20, rows: 10,
                no: [x(-160), x(-130)], title: [x(-130), x(40)], sound: [x(40), x(100)], light: [x(100), x(160)],
            };
            selectedId = 'setlist';
            note.textContent = 'セットリストをページの真ん中に置きました。要らない列は下のボタンで消せます。';
        } else if (what === 'stage') {
            cfg.stage = [x(-150), y(-100), x(150), y(100)];
            selectedId = 'stage';
            note.textContent = 'セット図の枠をページの真ん中に置きました。シートのセット図の四角に合わせてください。';
        } else if (what.startsWith('field:')) {
            const k = what.slice(6);
            const narrow = ['year', 'month', 'day', 'order'].includes(k); // 数字だけの欄は小さく
            const hw = narrow ? 15 : 80;
            cfg.fields = cfg.fields && typeof cfg.fields === 'object' ? cfg.fields : {};
            cfg.fields[k] = { box: [r1(cx - hw), r1(cy - 7), r1(cx + hw), r1(cy + 7)], size: 11, align: narrow ? 'center' : 'left' };
            selectedId = `fields.${k}`;
            note.textContent = `「${FIELD_LABELS[k]}」をページの真ん中に置きました。ドラッグで合わせてください。`;
        } else if (what === 'text') {
            cfg.texts = Array.isArray(cfg.texts) ? cfg.texts : [];
            let n = cfg.texts.length + 1;
            while (cfg.texts.some((t) => t.key === `text${n}`)) n++;
            cfg.texts.push({ key: `text${n}`, label: `新しい欄${n}`, box: [r1(cx - 60), r1(cy - 8), r1(cx + 60), r1(cy + 8)], size: 10 });
            selectedId = `texts.${cfg.texts.length - 1}`;
            note.textContent = `「新しい欄${n}」をページの真ん中に置きました。名前は下で直せます。`;
        } else if (what === 'stamp') {
            cfg.stamps = Array.isArray(cfg.stamps) ? cfg.stamps : [];
            cfg.stamps.push({ text: '文字', box: [r1(cx - 50), r1(cy - 7), r1(cx + 50), r1(cy + 7)], size: 10 });
            selectedId = `stamps.${cfg.stamps.length - 1}`;
            note.textContent = '固定の文字をページの真ん中に置きました。書く文字は下で直せます。';
        } else if (what === 'shape') {
            // 文字の無い固定の文字 = 図形だけ書く
            cfg.stamps = Array.isArray(cfg.stamps) ? cfg.stamps : [];
            cfg.stamps.push({ text: '', shape: 'rect', box: [r1(cx - 30), r1(cy - 20), r1(cx + 30), r1(cy + 20)] });
            selectedId = `stamps.${cfg.stamps.length - 1}`;
        } else if (what === 'choice') {
            cfg.choices = cfg.choices && typeof cfg.choices === 'object' && !Array.isArray(cfg.choices) ? cfg.choices : {};
            let n = 1;
            while (`c${n}` in cfg.choices) n++;
            const g = `c${n}`;
            cfg.choices[g] = {
                'あり': [r1(cx - 32), r1(cy - 7), r1(cx - 4), r1(cy + 7)],
                'なし': [r1(cx + 4), r1(cy - 7), r1(cx + 32), r1(cy + 7)],
            };
            cfg.choice_labels = { ...(cfg.choice_labels || {}), [g]: `新しい選択肢${n}` };
            selectedId = `choices.${g}.あり`;
            note.textContent = '選択肢のグループを置きました。見出し・選択肢の名前は下で直せます。印刷されている文字にそれぞれの枠を重ねてください。';
        }
        addSelect.value = '';
        changed(true);
    });

    /* -----------------------------------------------------------------
     *  PDF を描く（pdf.js。setting_sheet.js と同じ読み込み方）
     * ----------------------------------------------------------------- */
    let pdfjs = null;
    let pdfBytes = null;
    async function loadPdfjs() {
        if (!pdfjs) {
            pdfjs = await import(new URL(DATA.pdfjs.lib, location.href).href);
            pdfjs.GlobalWorkerOptions.workerSrc = new URL(DATA.pdfjs.worker, location.href).href;
        }
        return pdfjs;
    }

    async function renderPdf() {
        if (!pdfBytes) {
            note.textContent = '会場の PDF を選ぶと、ここに出ます。';
            return;
        }
        note.textContent = 'PDF を読み込み中…';
        try {
            const lib = await loadPdfjs();
            const task = lib.getDocument({
                data: pdfBytes.slice(),
                cMapUrl: new URL(DATA.pdfjs.cmaps, location.href).href,
                cMapPacked: true,
                standardFontDataUrl: new URL(DATA.pdfjs.fonts, location.href).href,
            });
            const doc = await task.promise;
            const page = await doc.getPage(1);
            const base = page.getViewport({ scale: 1 });
            pw = base.width;
            ph = base.height;
            // 画面の幅 × 拡大率 × 画面の細かさ。ただしセット図の切り抜きがぼやけないように 1pt = 2px 以上
            const ratio = Math.min(window.devicePixelRatio || 1, 3);
            let scale = Math.max((root.clientWidth * zoom * ratio) / pw, 2);
            const MAX_PIXELS = 16000000; // iPhone の canvas の上限くらい
            if (pw * ph * scale * scale > MAX_PIXELS) scale = Math.sqrt(MAX_PIXELS / (pw * ph));
            const viewport = page.getViewport({ scale });
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            await page.render({ canvas, viewport }).promise;
            await task.destroy();
            pdfScale = scale;
            wrap.style.aspectRatio = `${pw} / ${ph}`;
            note.textContent = cfg ? '' : '「見本にする型紙」を選んで座標を入れると、枠が出ます。';
        } catch (err) {
            pdfScale = 0;
            note.textContent = `PDF を表示できませんでした（${err.message}）`;
        }
        draw();
        queuePreview(); // セット図の背景の切り抜きが新しい PDF になった
    }

    fileInput.addEventListener('change', async () => {
        const f = fileInput.files[0];
        if (!f) return;
        pdfBytes = new Uint8Array(await f.arrayBuffer());
        renderPdf();
    });

    // 文字の大きさの倍率（メンバー表・セットリスト以外の欄すべて）。1 なら設定ごと消す
    fontInput.addEventListener('change', () => {
        if (!cfg || fontInput.value === '' || !Number.isFinite(+fontInput.value)) return;
        const s = Math.min(5, Math.max(0.3, r1(+fontInput.value)));
        fontInput.value = String(s);
        if (s === 1) delete cfg.font_scale;
        else cfg.font_scale = s;
        changed();
    });

    /**
     * メンバー表の詳細設定: パートが印刷済みの行（members.fixed = { 行の番号: 'Vo' }）。
     * そのパートの人をその行に入れ、パートは書かない（パートを自由に書く表だけ）
     */
    function membersFixedPanel(t) {
        if (!t || t.mode === 'slots' || !isNum(t.rows) || !isNum(t.top) || !isNum(t.row_h)) return;
        const pairs = memberPairs(t);
        const head = document.createElement('p');
        head.className = 'small ed-adv__head';
        head.textContent = 'パートが印刷済みの行';
        out.append(head);
        pairs.forEach((_, p) => {
            const row = document.createElement('div');
            row.className = 'ed-adv__rows';
            if (pairs.length > 1) row.append(Object.assign(document.createElement('span'), { className: 'muted small', textContent: `パート${p + 1}` }));
            for (let r = 0; r < t.rows; r++) {
                const slot = p * t.rows + r;
                const l = document.createElement('label');
                l.className = 'ed-num';
                l.textContent = String(r + 1);
                const inp = Object.assign(document.createElement('input'), { type: 'text', maxLength: 10, value: t.fixed?.[slot] ?? '', placeholder: '—' });
                inp.setAttribute('aria-label', `${pairs.length > 1 ? `パート${p + 1}の` : ''}${r + 1}行目に印刷されているパート`);
                inp.addEventListener('change', () => {
                    const v = inp.value.trim();
                    const fixed = { ...(t.fixed || {}) };
                    if (v) fixed[slot] = v;
                    else delete fixed[slot];
                    if (Object.keys(fixed).length) t.fixed = fixed;
                    else delete t.fixed;
                    changed();
                });
                l.append(inp);
                row.append(l);
            }
            out.append(row);
        });
    }

    zoomSelect.addEventListener('change', () => {
        zoom = +zoomSelect.value;
        wrap.style.width = `${zoom * 100}%`;
        renderPdf();
    });

    /** セット図の枠の中を PNG（data URL）に切り抜く。PDF がまだ描けていなければ '' */
    function stagePng() {
        if (!pdfScale || !cfg || !isBox(cfg.stage)) return '';
        const [x0, y0, x1, y1] = cfg.stage.map((v) => v * pdfScale);
        const w = Math.round(x1 - x0), h = Math.round(y1 - y0);
        if (w < 2 || h < 2) return '';
        const c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        c.getContext('2d').drawImage(canvas, x0, y0, w, h, 0, 0, w, h);
        return c.toDataURL('image/png');
    }

    // 保存するとき: セット図の枠の中を PNG に切り抜いて一緒に送る
    form.addEventListener('submit', (e) => {
        // メンバー表・セットリスト・セット図の枠が無い型紙は保存しない（作成画面が動かなくなる）
        const missing = Object.keys(MAIN_PARTS).filter((k) => !hasMain(k));
        if (cfg && missing.length) {
            e.preventDefault();
            note.textContent = `${missing.map((k) => MAIN_PARTS[k]).join(' / ')} がありません。「＋ 枠を足す」から出してください。`;
            note.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }
        pngInput.value = stagePng();
    });

    /* -----------------------------------------------------------------
     *  プレビュー: 右下のボタンで浮いた枠を開き、中に本番のセッティングシートの画面（setting_sheet?tpl_preview=1）を
     *  <iframe> で出す。保存前の型紙と PDF を postMessage で渡すと、仮の値（バンド名・メンバー・曲…）を入れた PDF が出る。
     *  PDF を書く処理は本番と同じなので、文字の大きさの自動調整も本物どおり。
     *  開いている間は、枠を動かすたびに送り直す（少し待ってから）
     * ----------------------------------------------------------------- */
    const previewBtn = root.querySelector('[data-ed-preview]');
    const previewBox = root.querySelector('[data-ed-preview-box]');
    let frame = null;
    let sendTimer = 0;
    const labelInput = form.querySelector('[data-template-label]');
    function sendPreview() {
        if (!frame || previewBox.hidden) return;
        frame.contentWindow.postMessage({
            type: 'sheet-tpl',
            cfg: cfg ? JSON.parse(JSON.stringify(cfg)) : null,
            label: labelInput.value.trim(),
            pdf: fileInput.files[0] && pdfBytes ? pdfBytes.slice() : null, // 新しく選んだ PDF（まだ保存していない）
            pdfPath: DATA.pdf,
            stageImage: stagePng() || null,
        }, location.origin);
    }
    function setPreview(open) {
        previewBox.hidden = !open;
        previewBtn.setAttribute('aria-expanded', String(open));
        previewBtn.querySelector('.icon').textContent = open ? 'close' : 'visibility';
        previewBtn.querySelector('[data-ed-preview-text]').textContent = open ? '閉じる' : 'プレビュー';
        if (!open) return;
        if (!frame) {
            // 初めて開いたときだけ作る（閉じても残すので、次に開くのが速い）
            frame = document.createElement('iframe');
            frame.src = 'setting_sheet?tpl_preview=1';
            frame.title = '型紙のプレビュー';
            previewBox.append(frame);
        } else {
            sendPreview();
        }
    }
    previewBtn.addEventListener('click', () => setPreview(previewBox.hidden));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !previewBox.hidden) setPreview(false);
    });
    // iframe の中の準備ができた → 型紙を送る
    window.addEventListener('message', (e) => {
        if (e.origin === location.origin && frame && e.source === frame.contentWindow && e.data?.type === 'sheet-tpl-ready') sendPreview();
    });
    function queuePreview() {
        clearTimeout(sendTimer);
        sendTimer = setTimeout(sendPreview, 800);
    }
    form.addEventListener('input', queuePreview);
    form.addEventListener('change', queuePreview); // PDF を選び直したとき

    // ---- 最初 ----
    readJson();
    if (DATA.pdf) {
        fetch(DATA.pdf).then((r) => r.arrayBuffer()).then((b) => {
            pdfBytes = new Uint8Array(b);
            renderPdf();
        }).catch(() => { note.textContent = '今の PDF を読み込めませんでした。'; });
    } else {
        renderPdf();
    }
    // 画面の幅が変わったら、文字の大きさ（ptPerPx）を合わせ直す
    window.addEventListener('resize', () => draw());
})();
