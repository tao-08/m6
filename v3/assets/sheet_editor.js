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
    const zoomSelect = root.querySelector('[data-ed-zoom]');
    const SVG_NS = 'http://www.w3.org/2000/svg';

    // 1行の欄。year / month / day は「年」「月」が印刷済みのシート用（出演日から数字だけ書く）
    const FIELD_LABELS = { band: 'バンド名', kana: 'フリガナ', date: '出演日', year: '年', month: '月', day: '日', order: '出演順', rep: '代表者氏名' };
    const COL_LABELS = { part: 'パート', name: '氏名', part2: 'パート', name2: '氏名', mic: 'マイク', no: '曲順', title: '曲名', sound: '音響', light: '照明', request: '備考（音響・照明）' };

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

    /** メンバー表・セットリスト → table */
    function tableItem(id, label, kind, t) {
        const colKeys = Object.keys(t).filter((k) => isCol(t[k]) && k !== 'lines');
        const xs = groupRefs(colKeys.flatMap((k) => [ref(t[k], 0), ref(t[k], 1)]));
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
            if (f && isBox(f.box)) out.push(rectItem(`fields.${k}`, FIELD_LABELS[k] || k, 'fields', f.box, () => delete cfg.fields[k]));
        }
        if (cfg.members) {
            const it = tableItem('members', 'メンバー表', 'members', cfg.members);
            if (it) out.push(it);
        }
        if (cfg.setlist) {
            const it = tableItem('setlist', 'セットリスト', 'setlist', cfg.setlist);
            if (it) out.push(it);
        }
        if (isBox(cfg.stage)) out.push(rectItem('stage', 'セット図の枠', 'stage', cfg.stage));
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
                    const t = el('text', { x: x0 + 2 * k, y: y0 + 11 * k, class: 'ed-label', 'font-size': 10 * k });
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
                // 列の名前（パート・氏名…）を列の上に
                for (const key of it.colKeys) {
                    const [l] = it.t[key];
                    const t = el('text', { x: l + 2 * k, y: y0 - 3 * k, class: 'ed-label', 'font-size': 10 * k });
                    t.textContent = COL_LABELS[key] || key;
                    g.append(t);
                }
                const t = el('text', { x: x0 + 2 * k, y: y0 + 11 * k, class: 'ed-label', 'font-size': 10 * k });
                t.textContent = it.label;
                g.append(t);
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

    /** 選んでいる枠の数字（細かく合わせたいとき用）と削除ボタン */
    function drawPanel() {
        const it = items.find((x) => x.id === selectedId);
        panel.textContent = '';
        if (!it) {
            panel.innerHTML = '<span class="muted small">枠をクリックすると、ここで数字を直せます。矢印キーで 0.5pt（Shift で 5pt）ずつ動きます。</span>';
            return;
        }
        const title = document.createElement('strong');
        title.textContent = it.label;
        panel.append(title);
        const num = numInput;
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
            if (it.id === 'members' && it.t.mode !== 'slots') membersPairPanel(it.t);
        } else {
            const s = document.createElement('span');
            s.className = 'muted small';
            s.textContent = '線を1本ずつドラッグして合わせます。行・列を増やすときは JSON を書き換えてください。';
            panel.append(s);
        }
        if (it.id === 'setlist') setlistNotePanel(it.t);
        if (it.choice) choicePanel(it.choice);
        if (it.text) {
            textInput('欄の名前（画面に出る）', it.text.label || '', (v) => { it.text.label = v; changed(true); });
        }
        if (it.stamp) {
            // 空にすると図形だけ書く
            textInput('書く文字', it.stamp.text || '', (v) => { it.stamp.text = v; changed(true); }, true);
            if (it.stamp.text) {
                num('文字の大きさ', () => it.stamp.size || 10, (v) => { it.stamp.size = Math.max(4, r1(v)); });
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
            panel.append(l);
            if (it.stamp.shape) num('線の太さ', () => it.stamp.line || 0.9, (v) => { it.stamp.line = Math.max(0.1, r1(v)); });
        }
        if (it.remove) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn--ghost btn--sm';
            del.textContent = 'この枠を消す';
            del.addEventListener('click', () => {
                it.remove();
                selectedId = null;
                changed(true);
            });
            panel.append(del);
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
        panel.append(l);
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
        panel.append(l);
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
            const pw2 = t.part[1] - t.part[0];
            const nw = t.name[1] - t.name[0];
            const x = t.name[1];
            t.part2 = [r1(x), r1(x + pw2)];
            t.name2 = [r1(x + pw2), r1(x + pw2 + nw)];
            note.textContent = '右にもう1組置きました。左の組が埋まったら、右の組の上から書きます。線をドラッグして合わせてください。';
            changed(true);
        });
    }

    /**
     * セットリスト: 「音響」「照明」の2列 ⇔ 1つの「備考」列（request）を切り替える。
     * 備考列のシートには、音響と照明への要望を「音響 / 照明：…」とつないで書く（setting_sheet.js）
     */
    function setlistNotePanel(t) {
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

    /** パネルにボタンを1つ足す */
    function panelButton(text, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn--ghost btn--sm';
        b.textContent = text;
        b.addEventListener('click', onClick);
        panel.append(b);
    }

    /** 選択肢の設定: グループの見出し・選択肢の名前・○/✓・このグループに選択肢を足す */
    function choicePanel({ g, name }) {
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
        panel.append(l);
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
        if (spec.text) numInput('文字の大きさ', () => spec.size || 10, (v) => writeOpt(g, name, { ...spec, size: Math.max(4, r1(v)) }));
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
        if (it.type === 'rect') {
            const b = it.box;
            const side = (i, axis) => op(() => b[i], (v) => { b[i] = r1(v); }, axis);
            if (what === 'move') return [side(0, 'x'), side(2, 'x'), side(1, 'y'), side(3, 'y')];
            return [[side(0, 'x')], [side(2, 'x')], [side(1, 'y')], [side(3, 'y')]][+what.slice(4)];
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
        const g = (what[0] === 'x' ? it.xs : it.ys)[+what.slice(1)];
        return [op(g.get, g.set, what[0])];
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
        drag = { from: toPt(e), ops: dragOps(it, target.dataset.drag), moved: false };
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
        drag.moved = true;
        draw();
    });
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
            note.textContent = '';
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
     *  「＋ 枠を足す…」: 選んだものをページの真ん中あたりに置く
     *    field:キー … 1行の欄（バンド名・年・月・日・代表者氏名 など。型紙にまだ無いものだけ選べる）
     *    text       … 自由記入欄
     *    choice     … 選択肢のグループ（「あり」「なし」の2つを横に並べて置く）
     * ----------------------------------------------------------------- */
    const addSelect = root.querySelector('[data-ed-add]');

    /** プルダウンの中身を作り直す（もうある欄は出さない） */
    function fillAddMenu() {
        addSelect.textContent = '';
        const opt = (value, text) => addSelect.append(new Option(text, value));
        opt('', '＋ 枠を足す…');
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
        if (!cfg) cfg = { fields: {}, members: {}, stage: [0, 0, 100, 100], setlist: {} };
        const cx = pw / 2, cy = ph / 2;
        if (what.startsWith('field:')) {
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
    form.addEventListener('submit', () => {
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
