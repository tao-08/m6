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
    const COL_LABELS = { part: 'パート', name: '氏名', part2: 'パート', name2: '氏名', mic: 'マイク', no: '曲順', title: '曲名', sound: '音響', light: '照明', request: '備考（音響・照明）', time: '分数', mc: 'MC' };
    // 備え付けギターアンプの標準（assets/setting_sheet.js の gtAmps() と同じ。型紙に gt_amps が無いとき）
    const GT_AMPS = ['JC', 'Marshall'];
    const PAIR_LABELS ={ part: 'パート1', name: '名前1', part2: 'パート2', name2: '名前2' };

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
     * 選択肢1つの設定を { box, mark, text } の形で読む。
     *   型紙には [箱]（○で囲む）か { box, mark: 'check' | 'none', text } で書いてある。text は選択肢の箱の中に書く
     *   前の版の text_box（文字を書く別の枠）は使わないので落とす（保存し直すと消える）
     */
    const optSpec = (v) => {
        if (Array.isArray(v)) return { box: v };
        const { text_box: _, ...o } = v;
        return o;
    };
    /** 選択肢1つの設定を書き戻す。○で囲むだけ（文字なし）なら [箱] の短い形にする */
    function writeOpt(g, name, o) {
        delete o.text_box;
        if (!o.text) {
            delete o.text;
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
        // メンバー表・セットリスト・セット図の枠も消せる（消したら「＋ 枠を足す」から標準の形で出し直す。セットリストとセット図は無いままだと保存できない）
        if (cfg.members) {
            const it = tableItem('members', 'メンバー表', 'members', cfg.members);
            if (it) out.push({ ...it, remove: () => delete cfg.members });
        }
        if (cfg.setlist) {
            const it = tableItem('setlist', 'セットリスト', 'setlist', cfg.setlist);
            if (it) out.push({ ...it, remove: () => delete cfg.setlist });
        }
        if (isBox(cfg.stage)) out.push(rectItem('stage', 'セット図の枠', 'stage', cfg.stage, () => delete cfg.stage));
        // 自動配置で部品を並べる範囲（セット図の枠の内側。はみ出さないように fitAuto() で収める）
        if (isBox(cfg.stage_auto)) out.push(rectItem('stage_auto', '自動配置の範囲', 'stage_auto', cfg.stage_auto, () => delete cfg.stage_auto));
        if (isBox(cfg.drum)) out.push(rectItem('drum', 'ドラム（自動配置で避ける）', 'drum', cfg.drum, () => delete cfg.drum));
        for (const [g, opts] of Object.entries(cfg.choices || {})) {
            for (const [name, v] of Object.entries(opts || {})) {
                const box = isBox(v) ? v : (v && isBox(v.box) ? v.box : null);
                if (!box) continue;
                const mark = v.mark === 'check' ? '（✓）' : v.mark === 'none' ? '（印なし）' : '';
                const text = v.text ? ` →「${v.text}」` : ''; // 選んだときに書く文字（例: 2タム →「（2Tom）」）
                out.push(rectItem(`choices.${g}.${name}`, `${groupLabel(g)}: ${name}${mark}${text}`, 'choices', box, () => {
                    delete cfg.choices[g][name];
                    if (Object.keys(cfg.choices[g]).length === 0) {
                        delete cfg.choices[g];
                        if (cfg.choice_labels) delete cfg.choice_labels[g];
                        if (cfg.choice_multi) delete cfg.choice_multi[g];
                    }
                }, { choice: { g, name } }));
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
        // 大きい枠から先に描く（小さい枠が上に来て、セット図の中の選択肢なども押せる）。
        // 選んでいる枠は、つかむ線だけを最後にもう1回描く（中身は下のままなので、中の小さい枠をクリックできる）
        const area = (it) => {
            if (it.type === 'rect') return Math.abs((it.box[2] - it.box[0]) * (it.box[3] - it.box[1]));
            const xs = it.xs.map((x) => x.get());
            const ys = it.ys.map((y) => y.get());
            return (Math.max(...xs) - Math.min(...xs)) * (Math.max(...ys) - Math.min(...ys));
        };
        const ordered = [...items].sort((a, b) => area(b) - area(a));
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
        const sel = svg.querySelector(`g.is-selected`);
        if (sel) {
            const top = el('g', { class: sel.getAttribute('class'), 'data-id': selectedId });
            sel.querySelectorAll('.ed-edge').forEach((e) => top.append(e.cloneNode()));
            svg.append(top);
        }
        drawList();
        drawPanel();
    }

    /* -----------------------------------------------------------------
     *  要素の一覧（ツールバーの ☰ で開くポップアップ）
     *  ・その他の項目（選択肢グループ・自由記入欄）: 作成画面に出る順の木。≡ をドラッグで並べ替え・入れ子
     *      グループ → その選択肢（あり / なし）→ その選択肢を選んだときに出す子。子の ☑ で出す選択肢を足す
     *      自由記入欄をグループの行の真ん中に落とすと「グループの下にいつも出す」（with_choice だけ）
     *  ・ほかの枠: 押すと選ぶだけ
     *  並びは cfg.extras_order、親子は show_if / with_choice に書く（作成画面 setting_sheet.js の extrasTree と同じ決め方）
     * ----------------------------------------------------------------- */
    const listBtn = root.querySelector('[data-ed-list-btn]');
    listBtn.addEventListener('click', () => {
        list.hidden = !list.hidden;
        listBtn.setAttribute('aria-expanded', String(!list.hidden));
        drawList();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !list.hidden) listBtn.click();
    });

    /** その他の項目の木。{ nodes: [{ kind: 'c' | 't', key, id: 'c:キー', parent, opts: [選択肢] | null }], children(g) } */
    function extrasTree() {
        const choices = cfg?.choices || {};
        const nodes = [
            ...(cfg?.texts || []).filter((x) => x && typeof x.key === 'string').map((x) => {
                const p = x.with_choice ?? x.show_if?.[0];
                const ok = p in choices;
                return { kind: 't', key: x.key, text: x, parent: ok ? p : null, opts: ok && Array.isArray(x.show_if?.[1]) ? x.show_if[1] : null };
            }),
            ...Object.keys(choices).map((g) => {
                const c = cfg.show_if?.[g];
                const ok = Array.isArray(c) && c[0] in choices && c[0] !== g;
                return { kind: 'c', key: g, parent: ok ? c[0] : null, opts: ok && Array.isArray(c[1]) ? c[1] : null };
            }),
            // 見出し（作成画面ではここからカードを分ける）。いつも一番上の段
            ...(Array.isArray(cfg?.headings) ? cfg.headings : []).filter((x) => x && typeof x.key === 'string')
                .map((x) => ({ kind: 'h', key: x.key, heading: x, parent: null, opts: null })),
        ];
        nodes.forEach((n) => { n.id = `${n.kind}:${n.key}`; });
        const groupNode = new Map(nodes.filter((n) => n.kind === 'c').map((n) => [n.key, n]));
        for (const n of nodes) { // 循環していたら一番上へ
            const seen = new Set(n.kind === 'c' ? [n.key] : []);
            for (let p = n.parent; p !== null; p = groupNode.get(p)?.parent ?? null) {
                if (seen.has(p)) { n.parent = null; n.opts = null; break; }
                seen.add(p);
            }
        }
        const order = new Map((Array.isArray(cfg?.extras_order) ? cfg.extras_order : []).map((id, i) => [id, i]));
        const rank = (n) => order.get(n.id) ?? order.size + nodes.indexOf(n);
        nodes.sort((a, b) => rank(a) - rank(b));
        return { nodes, groupNode, children: (g) => nodes.filter((n) => n.parent === g) };
    }

    /** グループ g が n の子孫か（n 自身も含む。グループを自分の下には入れられない） */
    const isUnder = (tree, g, n) => {
        if (n.kind !== 'c') return false;
        for (let p = g, i = 0; p !== null && i < 50; p = tree.groupNode.get(p)?.parent ?? null, i++) {
            if (p === n.key) return true;
        }
        return false;
    };

    /**
     * 消えたグループ・選択肢を指している親子の設定を片付ける（changed() のたびに）。
     *   親が無くなった子は一番上へ、出す選択肢が全部消えた子も一番上へ。extras_order から無いものを外す
     */
    function cleanExtras() {
        if (!cfg) return;
        const choices = cfg.choices || {};
        const optsOk = (c) => Array.isArray(c) && c[0] in choices && Array.isArray(c[1]) && (c[1] = c[1].filter((o) => o in choices[c[0]])).length > 0;
        if (cfg.show_if && typeof cfg.show_if === 'object') {
            for (const [g, c] of Object.entries(cfg.show_if)) {
                if (!(g in choices) || !optsOk(c)) delete cfg.show_if[g];
            }
            if (Object.keys(cfg.show_if).length === 0) delete cfg.show_if;
        }
        for (const t of Array.isArray(cfg.texts) ? cfg.texts : []) {
            if (!t || typeof t !== 'object') continue;
            if (t.with_choice !== undefined && !(t.with_choice in choices)) delete t.with_choice;
            if (t.show_if !== undefined && !optsOk(t.show_if)) delete t.show_if;
        }
        if (cfg.headings !== undefined) {
            cfg.headings = Array.isArray(cfg.headings) ? cfg.headings.filter((h) => h && typeof h.key === 'string') : [];
            if (cfg.headings.length === 0) delete cfg.headings;
        }
        if (Array.isArray(cfg.extras_order)) {
            const ids = new Set(extrasTree().nodes.map((n) => n.id));
            cfg.extras_order = cfg.extras_order.filter((id) => ids.has(id));
            if (cfg.extras_order.length === 0) delete cfg.extras_order;
        }
    }

    /** 子 n の親子を書き換える（parent = null で一番上。opts = null は「グループの下にいつも出す」= 自由記入欄だけ） */
    function setParent(n, parent, opts) {
        if (n.kind === 'c') {
            cfg.show_if = cfg.show_if && typeof cfg.show_if === 'object' ? cfg.show_if : {};
            if (parent === null) delete cfg.show_if[n.key];
            else cfg.show_if[n.key] = [parent, opts || [Object.keys(cfg.choices[parent])[0]]];
            if (Object.keys(cfg.show_if).length === 0) delete cfg.show_if;
            return;
        }
        delete n.text.with_choice;
        delete n.text.show_if;
        if (parent !== null) {
            n.text.with_choice = parent;
            if (opts) n.text.show_if = [parent, [...opts]];
        }
    }

    function drawList() {
        if (list.hidden) return;
        list.textContent = '';
        const tree = extrasTree();
        const rows = document.createElement('div');
        rows.className = 'ed-tree';
        const shown = []; // 一覧に出した順の id（並びを保存するとき、これをそのまま extras_order にする）
        const row = (cls, depth, label, kind, onPick) => {
            const r = document.createElement('div');
            r.className = `ed-row ed-k-${kind} ${cls}`;
            r.style.setProperty('--depth', depth);
            const name = document.createElement('button');
            name.type = 'button';
            name.className = 'ed-row__name';
            name.textContent = label;
            name.addEventListener('click', onPick);
            r.append(name);
            rows.append(r);
            return r;
        };
        const handle = (r, n) => {
            const h = document.createElement('span');
            h.className = 'ed-row__handle';
            h.dataset.dragNode = n.id;
            h.innerHTML = '<span class="icon" aria-hidden="true">drag_indicator</span>';
            h.title = 'ドラッグで並べ替え・入れ子';
            r.prepend(h);
            r.dataset.node = n.id;
        };
        // 子の行に、親の選択肢の ☑（どの選択肢を選んだときに出すか。少なくとも1つ）
        const optChecks = (r, n) => {
            if (n.parent === null || !n.opts) return;
            const box = document.createElement('span');
            box.className = 'ed-row__opts';
            for (const o of Object.keys(cfg.choices[n.parent])) {
                const l = document.createElement('label');
                l.className = 'check';
                const c = Object.assign(document.createElement('input'), { type: 'checkbox', checked: n.opts.includes(o) });
                c.addEventListener('change', () => {
                    const next = Object.keys(cfg.choices[n.parent]).filter((x) => (x === o ? c.checked : n.opts.includes(x)));
                    if (next.length === 0) { c.checked = true; return; }
                    setParent(n, n.parent, next);
                    changed(true);
                });
                l.append(c, o);
                box.append(l);
            }
            r.append(box);
        };
        const pickId = (id) => () => { if (items.some((x) => x.id === id)) select(id); };
        const textIndex = (n) => cfg.texts.indexOf(n.text);
        const addNode = (n, depth) => {
            shown.push(n.id);
            if (n.kind === 'h') {
                // 見出しの行: 名前をその場で直す・🗑 で消す（作成画面ではここから新しいカード）
                const r = document.createElement('div');
                r.className = 'ed-row ed-row--heading';
                r.style.setProperty('--depth', 0);
                const name = Object.assign(document.createElement('input'), { type: 'text', maxLength: 30, value: n.heading.label || '' });
                name.setAttribute('aria-label', '見出し');
                name.addEventListener('change', () => { n.heading.label = name.value.trim().slice(0, 30); changed(true); });
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'ed-row__del';
                del.setAttribute('aria-label', '見出しを消す');
                del.innerHTML = '<span class="icon" aria-hidden="true">delete</span>';
                del.addEventListener('click', () => { cfg.headings = cfg.headings.filter((x) => x !== n.heading); changed(true); });
                r.append(name, del);
                rows.append(r);
                handle(r, n);
                return;
            }
            if (n.kind === 't') {
                const id = `texts.${textIndex(n)}`;
                const r = row(id === selectedId ? 'is-selected' : '', depth, n.text.label || n.key, 'texts', pickId(id));
                handle(r, n);
                optChecks(r, n);
                return;
            }
            const opts = Object.keys(cfg.choices[n.key] || {});
            const first = `choices.${n.key}.${opts[0]}`;
            const r = row('ed-row--group', depth, groupLabel(n.key), 'choices', pickId(first));
            handle(r, n);
            optChecks(r, n);
            r.dataset.group = n.key;
            const kids = tree.children(n.key);
            // 「いつも出す」子（自由記入欄の with_choice だけ）→ 選択肢ごとに、その選択肢で出す子
            kids.filter((k) => !k.opts).forEach((k) => addNode(k, depth + 1));
            for (const o of opts) {
                const id = `choices.${n.key}.${o}`;
                const orow = row(`ed-row--opt${id === selectedId ? ' is-selected' : ''}`, depth + 1, o, 'choices', pickId(id));
                orow.dataset.optGroup = n.key;
                orow.dataset.opt = o;
                kids.filter((k) => k.opts && (k.opts.find((x) => opts.includes(x)) ?? opts[0]) === o).forEach((k) => addNode(k, depth + 2));
            }
        };
        tree.nodes.filter((n) => n.parent === null).forEach((n) => addNode(n, 0));
        if (cfg) {
            // 一番上のカードの見出し（extras_title）もここで直す
            const h = Object.assign(document.createElement('input'), { type: 'text', maxLength: 30, value: cfg.extras_title || '', placeholder: 'その他の項目' });
            h.className = 'ed-pop__head';
            h.setAttribute('aria-label', '最初のカードの見出し');
            h.addEventListener('change', () => {
                const v = h.value.trim().slice(0, 30);
                if (v) cfg.extras_title = v; else delete cfg.extras_title;
                changed(true);
            });
            const addH = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn--ghost btn--sm', textContent: '＋ 見出し' });
            addH.title = 'ここから下を別のカードにする';
            addH.addEventListener('click', () => {
                cfg.headings = Array.isArray(cfg.headings) ? cfg.headings : [];
                let k = 1;
                while (cfg.headings.some((x) => x.key === `h${k}`)) k++;
                cfg.headings.push({ key: `h${k}`, label: `見出し${k}` });
                changed(true);
            });
            list.append(h, rows, addH);
        }
        // ほかの枠（押すと選ぶ）
        const others = document.createElement('div');
        others.className = 'ed-pop__chips';
        for (const it of items) {
            if (it.kind === 'choices' || it.kind === 'texts') continue;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = `ed-chip ed-k-${it.kind}${it.id === selectedId ? ' is-selected' : ''}`;
            b.textContent = it.label;
            b.addEventListener('click', () => select(it.id));
            others.append(b);
        }
        list.append(others);
        setupTreeDrag(rows, tree, shown);
    }

    /**
     * 一覧の木のドラッグ。指の下の行と、行の中の高さで落とす先を決める
     *   選択肢の行 → その選択肢の子の先頭 / グループの行の真ん中（自由記入欄だけ）→ グループの下にいつも出す /
     *   それ以外 → 行の上半分ならその前、下半分ならその後（その行と同じ親・同じ選択肢）
     */
    function setupTreeDrag(rows, tree, shown) {
        let drag = null; // { n, row, drop: { row, pos: 'before' | 'after' | 'into' } }
        const byId = new Map(tree.nodes.map((n) => [n.id, n]));
        const clearMark = () => rows.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-into').forEach((r) => r.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-into'));
        // 落とす先 → { parent, opts, after: この id の直後に入れる（null = 先頭）, before: この id の直前 } / 落とせなければ null
        const target = (r, pos) => {
            const n = drag.n;
            if (r.dataset.opt !== undefined) {
                if (n.kind === 'h') return null;
                const g = r.dataset.optGroup;
                return isUnder(tree, g, n) ? null : { parent: g, opts: [r.dataset.opt], after: `c:${g}` };
            }
            const t = byId.get(r.dataset.node);
            if (!t || t === n) return null;
            if (n.kind === 'h' && (pos === 'into' || t.parent !== null)) return null; // 見出しは一番上の段だけ
            if (pos === 'into') {
                return n.kind === 't' ? { parent: t.key, opts: null, after: t.id } : null;
            }
            if (t.parent !== null && isUnder(tree, t.parent, n)) return null;
            const opts = t.parent === null ? null : (t.opts ? [...t.opts] : null);
            if (n.kind === 'c' && t.parent !== null && !opts) return null; // グループは「いつも出す」には入れない
            return pos === 'before' ? { parent: t.parent, opts, before: t.id } : { parent: t.parent, opts, after: t.id };
        };
        rows.addEventListener('pointerdown', (e) => {
            const h = e.target.closest('[data-drag-node]');
            if (!h || (e.pointerType === 'mouse' && e.button !== 0)) return;
            e.preventDefault();
            drag = { n: byId.get(h.dataset.dragNode), row: h.closest('.ed-row'), drop: null };
            drag.row.classList.add('is-dragging');
            h.setPointerCapture(e.pointerId);
        });
        rows.addEventListener('pointermove', (e) => {
            if (!drag) return;
            clearMark();
            drag.drop = null;
            const r = document.elementFromPoint(e.clientX, e.clientY)?.closest('.ed-row');
            if (!r || !rows.contains(r) || r === drag.row) return;
            const b = r.getBoundingClientRect();
            const f = (e.clientY - b.top) / b.height;
            const pos = r.dataset.opt !== undefined ? 'into'
                : r.dataset.group !== undefined && drag.n.kind === 't' && f > 0.3 && f < 0.7 ? 'into'
                    : f < 0.5 ? 'before' : 'after';
            const to = target(r, pos);
            if (!to) return;
            drag.drop = to;
            r.classList.add(`is-drop-${pos}`);
        });
        const end = () => {
            if (!drag) return;
            const { n, drop } = drag;
            drag.row.classList.remove('is-dragging');
            clearMark();
            drag = null;
            if (!drop) return;
            if (n.kind !== 'h') setParent(n, drop.parent, drop.opts);
            const order = shown.filter((id) => id !== n.id);
            const at = drop.before ? order.indexOf(drop.before) : drop.after ? order.indexOf(drop.after) + 1 : 0;
            order.splice(Math.max(0, at), 0, n.id);
            cfg.extras_order = order;
            changed(true);
        };
        rows.addEventListener('pointerup', end);
        rows.addEventListener('pointercancel', end);
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
        gtAmpsPanel();
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
        // 文字の大きさ（pt。全体の倍率 font_scale をさらに掛ける）。10 なら設定ごと消す
        //   選択肢は詳細設定の「選んだときに書く文字」の横（choicePanel）
        const target = it.kind === 'fields' ? cfg.fields[it.id.slice(7)] : it.text || (it.stamp?.text ? it.stamp : null);
        if (target) sizeInput(target);
    }

    /** 文字の大きさの欄（pt。10 なら設定ごと消す）。after: 変えたあとに書き戻す処理（選択肢） */
    function sizeInput(target, after = () => {}) {
        numInput('文字の大きさ', () => target.size || 10, (v) => {
            const s = Math.min(72, Math.max(4, r1(v)));
            if (s === 10) delete target.size;
            else target.size = s;
            after();
        });
    }

    /** 詳細設定: 枠ごとの、列を足す・入れ替える・印刷済みのもの・印や囲み など */
    function drawAdvanced(it, num) {
        if (it.id === 'members' && it.t.mode !== 'slots') {
            section('列');
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
            toggle('セット図にアンプが配置済み', !!cfg.amps_printed, (on) => {
                if (on) cfg.amps_printed = true;
                else delete cfg.amps_printed;
            });
            // 自動配置の範囲: セット図の枠の各辺から 10pt 内側に置く（狭い枠ならそのまま同じ大きさ）
            if (!isBox(cfg.stage_auto)) {
                panelButton('＋ 自動配置の枠', () => {
                    const [x0, y0, x1, y1] = cfg.stage;
                    const m = Math.min(10, (x1 - x0) / 4, (y1 - y0) / 4);
                    cfg.stage_auto = [r1(x0 + m), r1(y0 + m), r1(x1 - m), r1(y1 - m)];
                    selectedId = 'stage_auto';
                    changed(true);
                });
            }
        }
        if (it.text) {
            // 複数行: 作成画面の入力欄が改行できるボックスになり、シートには枠の中で折り返して書く（4行まで）
            toggle('改行できるテキストボックス', !!it.text.multiline, (on) => {
                if (on) it.text.multiline = true;
                else delete it.text.multiline;
            });
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

    /** 備え付けギターアンプ（gt_amps）: 作成画面の四角の候補・自動配置で並んでいる順に使う。フォームの「登録済テンプレートから編集する」の下に出す */
    const ampChips = document.querySelector('[data-gt-amps]');
    const ampInput = document.querySelector('[data-gt-amps-input]');
    const nowAmps = () => (Array.isArray(cfg?.gt_amps) ? cfg.gt_amps : GT_AMPS);
    const setAmps = (next) => {
        if (next.length === 0 || next.join('\n') === GT_AMPS.join('\n')) delete cfg.gt_amps;
        else cfg.gt_amps = next;
        changed(true);
    };
    ampInput?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault(); // フォームを送信しない
        const v = ampInput.value.trim();
        if (!cfg || !v) return;
        if (!nowAmps().includes(v)) setAmps([...nowAmps(), v]);
        ampInput.value = '';
    });
    function gtAmpsPanel() {
        if (!ampChips) return;
        ampChips.textContent = '';
        if (!cfg) return;
        for (const name of nowAmps()) {
            const chip = document.createElement('span');
            chip.className = 'chip';
            chip.textContent = name;
            const x = Object.assign(document.createElement('button'), { type: 'button', className: 'chip__x', textContent: '×' });
            x.setAttribute('aria-label', '外す');
            x.addEventListener('click', () => setAmps(nowAmps().filter((v) => v !== name)));
            chip.append(x);
            ampChips.append(chip);
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
        if (!Array.isArray(t.part) || !Array.isArray(t.name)) return;
        toggle('右にもう1組（パート・氏名）', Array.isArray(t.part2) && Array.isArray(t.name2), (on) => {
            if (!on) {
                delete t.part2;
                delete t.name2;
                return;
            }
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
        section('列の設定');
        // 表の左端・右端（任意の列を足す場所）。MC の印の列は表の外なので数えない
        const cols = Object.keys(t).filter((k) => isCol(t[k]) && k !== 'lines' && k !== 'mc').map((k) => t[k]);
        const left = Math.min(...cols.flat());
        const right = Math.max(...cols.flat());
        // 曲順（印刷済みのシートは無くてよい）: 足すときは表の左に 30pt
        toggle('曲順', isCol(t.no), (on) => {
            if (!on) { delete t.no; return; }
            t.no = [r1(left - 30), r1(left)];
            note.textContent = '表の左に曲順の列を足しました。線をドラッグして合わせてください。';
        });
        // 音響・照明（備考）: 足すときは表の右に 60pt × 2。別々の2列 ⇔ 1つの「備考」列（request）は横の選択で
        const hasNote = isCol(t.request) || (isCol(t.sound) && isCol(t.light));
        const noteRow = toggle('音響・照明', hasNote, (on) => {
            if (!on) { delete t.sound; delete t.light; delete t.request; return; }
            t.sound = [r1(right), r1(right + 60)];
            t.light = [r1(right + 60), r1(right + 120)];
            note.textContent = '表の右に音響・照明の列を足しました。線をドラッグして合わせてください。';
        });
        if (hasNote) {
            const sel = document.createElement('select');
            sel.setAttribute('aria-label', '音響・照明の列の分け方');
            sel.append(new Option('別々の列', 'split', false, !isCol(t.request)), new Option('1つの備考列', 'request', false, isCol(t.request)));
            sel.addEventListener('change', () => {
                if (sel.value === 'request') {
                    const l = Math.min(t.sound[0], t.light[0]);
                    const r = Math.max(t.sound[1], t.light[1]);
                    delete t.sound;
                    delete t.light;
                    t.request = [l, r];
                    note.textContent = '両方の要望があるときは「音響 / 照明：…」とつないで書きます。';
                } else {
                    const [l, r] = t.request;
                    const mid = r1((l + r) / 2);
                    delete t.request;
                    t.sound = [l, mid];
                    t.light = [mid, r];
                }
                changed(true);
            });
            noteRow.append(sel);
        }
        // MC を行に書かず、表の外（曲と曲の境目）に「MC＞」と書くシート。足すときは表の左外に 30pt
        const mcRow = toggle('MC を枠の外に書く', isCol(t.mc), (on) => {
            if (!on) { delete t.mc; delete t.mc_text; return; }
            t.mc = [r1(left - 32), r1(left - 2)];
        });
        if (isCol(t.mc)) {
            const inp = Object.assign(document.createElement('input'), { type: 'text', maxLength: 10, value: t.mc_text || 'MC＞' });
            inp.setAttribute('aria-label', 'MC の印');
            inp.className = 'ed-short';
            inp.addEventListener('change', () => {
                const v = inp.value.trim();
                if (v === '' || v === 'MC＞') delete t.mc_text;
                else t.mc_text = v;
                changed();
            });
            mcRow.append(inp);
        }
    }

    /**
     * セットリストの分数（time）と、曲ごとの追加の列（cols: コーラス/CD・備考 など）
     *   新しい列は、選んだ列（曲名・音響・照明・備考・分数）の右端を縮めて、そこに置く（ほかの列の線は動かさない）
     *   「分数と同じ列」に足すと、その列の行を上下に等分して上に積む（クラブ・ドロシーの「コーラス/CD トラック」と「分数」）
     */
    function setlistColsPanel(t) {
        if (!isCol(t.title)) return;
        // 列のキー（title・time・追加の列の c1 など）→ 列の左右 / 名前
        const colOf = (key) => (isCol(t[key]) ? t[key] : extraCols(t).find((c) => c.key === key)?.col);
        const labelOf = (key) => COL_LABELS[key] ?? (extraCols(t).find((c) => c.key === key)?.label || key);
        // 新しい列を「○○の右」に置くときの候補（曲名・音響・照明・備考・分数・追加の列）
        const anchors = () => [...['title', 'sound', 'light', 'request', 'time'].filter((k) => isCol(t[k])), ...extraCols(t).map((c) => c.key)];
        // 列（key）の右端から w pt（列の半分まで）を切り取って新しい列にする。狭すぎる列からは取らない。
        // 分数・追加の列で、同じ左右に積んである列も一緒に縮める（積んだ形をくずさない）
        const takeFrom = (key, w) => {
            const src = colOf(key);
            const [l, r] = [Math.min(...src), Math.max(...src)];
            if (r - l < 20) {
                note.textContent = `「${labelOf(key)}」の列が狭すぎて足せません。先に広げてください。`;
                return null;
            }
            const cut = r1(r - Math.min(w, (r - l) / 2));
            const same = (c) => c !== src && Math.abs(Math.min(...c) - l) <= 1 && Math.abs(Math.max(...c) - r) <= 1;
            const stacked = ['title', 'sound', 'light', 'request'].includes(key) ? [] : [...(isCol(t.time) ? [t.time] : []), ...extraCols(t).map((x) => x.col)].filter(same);
            for (const c of [src, ...stacked]) {
                c[c[1] >= c[0] ? 1 : 0] = cut;
            }
            return [cut, r];
        };
        // 「○○の右」の選択欄
        const anchorSelect = (label) => {
            const s = document.createElement('select');
            s.setAttribute('aria-label', label);
            for (const k of anchors()) s.append(new Option(`${labelOf(k)}の右`, k));
            return s;
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
        // 分数: 足すときはどの列の右に置くか、横の選択で決めてからチェック
        const whereT = isCol(t.time) ? null : anchorSelect('分数の列の置き場所');
        const timeRow = toggle('演奏時間', isCol(t.time), (on) => {
            if (!on) {
                giveBack(t.time);
                delete t.time;
                delete t.time_colon;
                return;
            }
            const col = takeFrom(whereT.value, 40);
            if (!col) return;
            t.time = col;
            note.textContent = `${labelOf(whereT.value)}の右に分数の列を足しました。線をドラッグして合わせてください。`;
        });
        if (whereT) {
            timeRow.append(whereT);
        } else {
            // 「:」が印刷されているシートは、分と秒を「:」の左右に書く。無いシートは「3:45」と書く
            timeRow.append(toggle('「:」がプリント済み', !!t.time_colon, (on) => {
                if (on) t.time_colon = true;
                else delete t.time_colon;
            }, false));
        }

        // 追加の列の一覧: 名前を変える・消す
        section('追加の列');
        for (const c of extraCols(t)) {
            const row = document.createElement('div');
            row.className = 'ed-set';
            const inp = Object.assign(document.createElement('input'), { type: 'text', maxLength: 30, value: c.label || '' });
            inp.setAttribute('aria-label', '列の名前');
            inp.addEventListener('change', () => {
                const v = inp.value.trim();
                if (v === '') return;
                c.label = v;
                changed(true);
            });
            row.append(inp, iconBtn('delete', `「${c.label || c.key}」の列を消す`, () => {
                giveBack(c.col);
                t.cols = extraCols(t).filter((x) => x !== c);
                if (t.cols.length === 0) delete t.cols;
                changed(true);
            }));
            out.append(row);
        }

        // 列を足す: 名前と置き方（○○の右 / 分数と同じ列）
        const wrap = document.createElement('div');
        wrap.className = 'ed-set';
        const name = Object.assign(document.createElement('input'), { type: 'text', maxLength: 30, placeholder: '列の名前（例: コーラス/CD）' });
        name.setAttribute('aria-label', '足す列の名前');
        const where = anchorSelect('足す列の置き方');
        if (isCol(t.time)) where.append(new Option('分数と同じ列（上下に分ける）', 'stack'));
        const add = iconBtn('add', '列を足す', () => {
            const label = name.value.trim();
            if (label === '') {
                name.focus();
                return;
            }
            const stack = where.value === 'stack' && isCol(t.time);
            const col = stack ? [...t.time] : colOf(where.value) ? takeFrom(where.value, 50) : null;
            if (!col) return;
            // キーは型紙の中で重ならない名前（c1, c2 …）。作成画面の入力はこのキーで保存する
            const used = new Set(extraCols(t).map((c) => c.key));
            let n = 1;
            while (used.has(`c${n}`)) n++;
            t.cols = [...extraCols(t), { key: `c${n}`, label, col }];
            note.textContent = stack ? `「${label}」を分数の上の段に足しました。` : `${labelOf(where.value)}の右に「${label}」の列を足しました。線をドラッグして合わせてください。`;
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

    /** 詳細設定に小見出し付きの区切りを作り、ここから先の部品をその中に入れる */
    function section(title) {
        const sec = document.createElement('div');
        sec.className = 'ed-sec';
        const h = document.createElement('span');
        h.className = 'ed-sec__head';
        h.textContent = title;
        sec.append(h);
        advBox.append(sec);
        out = sec;
    }

    /**
     * ON / OFF のチェック。変えたら onChange(on) のあと描き直す。
     * row = true: 1行（.ed-set）にして足し、その行を返す（横に選択欄などを足せる）/ false: ラベルだけ返す（足さない）
     */
    function toggle(label, checked, onChange, row = true) {
        const l = document.createElement('label');
        l.className = 'check';
        const c = Object.assign(document.createElement('input'), { type: 'checkbox', checked });
        c.addEventListener('change', () => {
            onChange(c.checked);
            changed(true);
        });
        l.append(c, label);
        if (!row) return l;
        const r = document.createElement('div');
        r.className = 'ed-set';
        r.append(l);
        out.append(r);
        return r;
    }

    /** アイコンだけのボタン（説明は title のツールチップ）。足さずに返す */
    function iconBtn(icon, label, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn--ghost btn--sm';
        b.title = label;
        b.setAttribute('aria-label', label);
        b.innerHTML = `<span class="icon" aria-hidden="true">${icon}</span>`;
        b.addEventListener('click', onClick);
        return b;
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
        // このグループは複数選べる（cfg.choice_multi[g] = true）
        const ml = document.createElement('label');
        ml.className = 'ed-check';
        const mc = document.createElement('input');
        mc.type = 'checkbox';
        mc.checked = !!cfg.choice_multi?.[g];
        mc.addEventListener('change', () => {
            cfg.choice_multi = { ...(cfg.choice_multi || {}) };
            if (mc.checked) cfg.choice_multi[g] = true;
            else delete cfg.choice_multi[g];
            if (Object.keys(cfg.choice_multi).length === 0) delete cfg.choice_multi;
            changed(true);
        });
        ml.append(mc, ' 複数選択可');
        out.append(ml);
        // 選んだときに書く文字。選択肢の枠の中に書く（別の枠は作らない）
        textInput('選んだときに書く文字', spec.text || '', (v) => {
            writeOpt(g, name, { ...spec, text: v });
            changed(true);
        }, true);
        // 書く文字の大きさ（文字が無いときは保存しない: writeOpt が消す）
        if (spec.text) sizeInput(spec, () => writeOpt(g, name, spec));
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
        fitAuto(); // 枠を出し直したときなども、自動配置の範囲をセット図の枠の中に収めておく
        cleanExtras(); // 選択肢・欄を消したら、それを親にしていた子の設定を片付ける
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
            if (what === 'move') {
                const ops = [side(0, 'x'), side(2, 'x'), side(1, 'y'), side(3, 'y')];
                // セット図の枠を動かすときは、自動配置の範囲も一緒に動かす（中に入ったまま付いてくる）
                if (it.id === 'stage' && isBox(cfg.stage_auto)) {
                    const a = cfg.stage_auto;
                    ops.push(...[0, 2, 1, 3].map((i) => op(() => a[i], (v) => { a[i] = r1(v); }, i % 2 === 0 ? 'x' : 'y')));
                }
                return ops;
            }
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
        fitAuto();
        drag.moved = true;
        draw();
    });

    /**
     * Ctrl / Shift を押しながら辺をドラッグ → 正方形にする（Office の図形と同じ。丸の図形なら円になる）。
     * 左右の辺なら今の幅に高さを合わせて下の辺を動かし、上下の辺なら今の高さに幅を合わせて右の辺を動かす
     */
    /**
     * 自動配置の範囲（stage_auto）を、セット図の枠（stage）の内側に収める。
     *   はみ出した辺は枠の辺まで戻す。枠を縮めたら一緒に縮む（左右・上下が 2pt より近づかないように）
     */
    function fitAuto() {
        const a = cfg?.stage_auto;
        if (!isBox(a) || !isBox(cfg.stage)) return;
        const [l, t, r, b] = [Math.min(cfg.stage[0], cfg.stage[2]), Math.min(cfg.stage[1], cfg.stage[3]), Math.max(cfg.stage[0], cfg.stage[2]), Math.max(cfg.stage[1], cfg.stage[3])];
        const GAP = 2;
        a[0] = r1(Math.min(Math.max(a[0], l), r - GAP));
        a[1] = r1(Math.min(Math.max(a[1], t), b - GAP));
        a[2] = r1(Math.max(Math.min(a[2], r), a[0] + GAP));
        a[3] = r1(Math.max(Math.min(a[3], b), a[1] + GAP));
    }

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
        fitAuto();
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
     *  「＋ 枠を足す…」: 選んだものを見えている所の真ん中に置く（visibleCenter）
     *    field:キー … 1行の欄（バンド名・年・月・日・代表者氏名 など。型紙にまだ無いものだけ選べる）
     *    text       … 自由記入欄
     *    choice     … 選択肢のグループ（「あり」「なし」の2つを横に並べて置く）
     * ----------------------------------------------------------------- */
    const addSelect = root.querySelector('[data-ed-add]');

    /** 型紙の大きな枠。セットリストとセット図は必ず要る（作成画面はこれがある前提で動く）。メンバー表は無いシートもある */
    const MAIN_PARTS = { members: 'メンバー表', setlist: 'セットリスト', stage: 'セット図の枠' };
    const REQUIRED_PARTS = ['setlist', 'stage'];
    /** その枠がちゃんとあるか（buildItems() で枠になる形か） */
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

    /**
     * 今見えている所の真ん中（pt）。拡大してスクロールしているときは、スクロールの枠と画面の両方に見えている範囲の真ん中。
     *   ページが1つも見えていなければ、ページの真ん中
     */
    function visibleCenter() {
        const r = wrap.getBoundingClientRect();
        const s = wrap.parentElement.getBoundingClientRect(); // .sheet-ed__scroll（拡大したときにスクロールする枠）
        const l = Math.max(r.left, s.left, 0), t = Math.max(r.top, s.top, 0);
        const rr = Math.min(r.right, s.right, innerWidth), b = Math.min(r.bottom, s.bottom, innerHeight);
        if (rr <= l || b <= t || r.width === 0) return [pw / 2, ph / 2];
        return [((l + rr) / 2 - r.left) / r.width * pw, ((t + b) / 2 - r.top) / r.height * ph];
    }

    addSelect.addEventListener('change', () => {
        const what = addSelect.value;
        if (!what) return;
        if (!cfg) cfg = { fields: {} }; // 白紙から: メンバー表などもこのプルダウンから出す
        const [cx, cy] = visibleCenter();
        const x = (v) => r1(cx + v);
        const y = (v) => r1(cy + v);
        if (what === 'members') {
            // 標準の形: パート | 名前 の5行（名前が左のシートは「⇄ パートと名前を入れ替える」）
            cfg.members = { top: y(-50), row_h: 20, rows: 5, part: [x(-120), x(-60)], name: [x(-60), x(120)] };
            selectedId = 'members';
            note.textContent = 'メンバー表を見えている所に置きました。線をドラッグしてシートの罫線に合わせてください。行数は下で変えられます。';
        } else if (what === 'setlist') {
            // 標準の形: 曲順 | 曲名 | 音響 | 照明 の10行（幅 320pt）
            cfg.setlist = {
                top: y(-100), row_h: 20, rows: 10,
                no: [x(-160), x(-130)], title: [x(-130), x(40)], sound: [x(40), x(100)], light: [x(100), x(160)],
            };
            selectedId = 'setlist';
            note.textContent = 'セットリストを見えている所に置きました。要らない列は下のボタンで消せます。';
        } else if (what === 'stage') {
            cfg.stage = [x(-150), y(-100), x(150), y(100)];
            selectedId = 'stage';
            note.textContent = 'セット図の枠を見えている所に置きました。シートのセット図の四角に合わせてください。';
        } else if (what.startsWith('field:')) {
            const k = what.slice(6);
            const narrow = ['year', 'month', 'day', 'order'].includes(k); // 数字だけの欄は小さく
            const hw = narrow ? 15 : 80;
            cfg.fields = cfg.fields && typeof cfg.fields === 'object' ? cfg.fields : {};
            cfg.fields[k] = { box: [r1(cx - hw), r1(cy - 7), r1(cx + hw), r1(cy + 7)], size: 11, align: narrow ? 'center' : 'left' };
            selectedId = `fields.${k}`;
            note.textContent = `「${FIELD_LABELS[k]}」を見えている所に置きました。ドラッグで合わせてください。`;
        } else if (what === 'text') {
            cfg.texts = Array.isArray(cfg.texts) ? cfg.texts : [];
            let n = cfg.texts.length + 1;
            while (cfg.texts.some((t) => t.key === `text${n}`)) n++;
            cfg.texts.push({ key: `text${n}`, label: `新しい欄${n}`, box: [r1(cx - 60), r1(cy - 8), r1(cx + 60), r1(cy + 8)], size: 10 });
            selectedId = `texts.${cfg.texts.length - 1}`;
            note.textContent = `「新しい欄${n}」を見えている所に置きました。名前は下で直せます。`;
        } else if (what === 'stamp') {
            cfg.stamps = Array.isArray(cfg.stamps) ? cfg.stamps : [];
            cfg.stamps.push({ text: '文字', box: [r1(cx - 50), r1(cy - 7), r1(cx + 50), r1(cy + 7)], size: 10 });
            selectedId = `stamps.${cfg.stamps.length - 1}`;
            note.textContent = '固定の文字を見えている所に置きました。書く文字は下で直せます。';
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
        section('パートが印刷済みの行');
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
        // セットリスト・セット図の枠が無い型紙は保存しない（作成画面が動かなくなる）
        const missing = REQUIRED_PARTS.filter((k) => !hasMain(k));
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
    // PC では枠を動かせる・大きさを変えられる（app.js の floatWin）
    const placePreview = typeof floatWin === 'function' ? floatWin(previewBox, 'tpl-preview-win') : () => {};
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
        if (open) placePreview();
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
