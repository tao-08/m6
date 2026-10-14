/**
 * =====================================================================
 *  assets/setting_sheet.js — セッティングシートの画面と PDF 作成
 * =====================================================================
 *  setting_sheet.php だけで読み込む。やっていることは大きく3つ:
 *    1. 入力欄（バンド名・メンバー・セットリスト・○囲み）と state（状態）を同期する
 *    2. セット図: マイク・丸・四角・文字をドラッグで置く
 *    3. PDF: 会場の PDF を読み込み、pdf-lib で上から書き込んでダウンロード
 *
 *  ---- 設計の考え方（業界でよく使う形）----
 *  「state（状態）が唯一の正解。画面は state から描き直すだけ」
 *    入力 → state を書き換える → render() で画面を描き直す → save() で保存
 *  React や Vue などのフレームワークも、根っこはこの考え方。
 *  画面（DOM）を直接いじって状態を覚えさせると「画面と中身がずれる」バグが起きやすい。
 *
 *  ---- 座標 ----
 *  セット図の部品の位置は「セット図の枠の左上からの pt（PDF の単位）」で持つ。
 *  画面に描くときだけ「枠の横幅(px) ÷ 枠の横幅(pt)」倍して px にする。
 *  → 画面の大きさ（スマホ / PC）が変わっても、PDF に書く位置は同じになる。
 * =====================================================================
 */
(() => {
    'use strict'; // うっかりミス（宣言していない変数への代入など）をエラーにしてくれるモード

    /* =================================================================
     *  0. 準備: PHP から渡されたデータ・よく使う要素
     * ================================================================= */
    const dataEl = document.getElementById('sheet-data');
    const root = document.querySelector('[data-sheet]');
    if (!dataEl || !root) {
        return; // このページではない（念のため）
    }
    /** PHP が json_encode したもの。templates（型紙）や initial（DB の初期値）が入っている */
    const DATA = JSON.parse(dataEl.textContent);

    // root.querySelector の短縮形。$('[data-stage]') のように使う
    const $ = (sel) => root.querySelector(sel);
    const $$ = (sel) => Array.from(root.querySelectorAll(sel));

    // localStorage の保存キー（バンドごと。白紙は1つだけ）
    //   型紙のお試し（sheet_templates の「プレビュー」）は保存しない（save() を見る）
    const STORE_KEY = DATA.blank ? 'settingSheet:blank' : 'settingSheet:band:' + DATA.bandId;
    // プレビューを開いているか（下の「6.5 プレビュー」で使う）。save() から先に参照されるので、ここで宣言しておく
    //   let で作った変数は、宣言の行より前で使うとエラーになる（TDZ: 一時的なデッドゾーン）
    let previewOpen = false;
    let previewTimer = 0;
    let pdfBytes = null;    // プレビュー用に最後に作った PDF。拡大・縮小だけのときは作り直さずにこれを描き直す
    let pdfDirty = true;    // 入力が変わって、PDF を作り直す必要があるか
    // 「1つ戻す」の履歴（下の「4.5」で使う）。save() から先に参照されるので、previewTimer と同じくここで宣言する
    let history = null; // null = まだ準備できていない（準備前の save() では積まない）
    let histPos = 0;
    let histTimer = 0;

    /** セット図の部品の「基準の大きさ」（pt）。大きさスライダー（scale）を掛けて使う */
    const BASE = {
        micLen: 30,   // マイクの長さ
        circle: 24,   // 丸の直径
        boxW: 42,     // 四角の横
        boxH: 20,     // 四角の縦
        text: 9,      // 文字の大きさ
        drawW: 1.5,   // 手で描いた線の太さ
    };
    const DEFAULT_LABEL = { mic: '', circle: '', box: 'Amp', text: '文字', draw: '' };

    /* =================================================================
     *  1. state（状態）
     * ================================================================= */

    /** DB の内容から作る「まっさらな状態」 */
    function defaultState() {
        const ini = DATA.initial;
        return {
            template: DATA.templateKey,
            band: ini.band,
            kana: ini.kana,
            date: ini.date,
            order: ini.order,
            rep: ini.rep || '',
            // [...配列] でコピーしてから map。元の DATA を書き換えないため
            members: ini.members.map((m) => ({ part: m.part, name: m.name })),
            // セットリスト。1曲 = { id, mc: false, title, sound, light } / MC = { id, mc: true }
            songs: ini.songs.map((title, i) => ({ id: i + 1, mc: false, title, sound: '', light: '' })),
            nextSongId: ini.songs.length + 1,
            choices: {},    // { se: 'なし', start: 'ハケ', ... }。空 = どれも囲まない
            texts: {},      // その他の自由記入欄 { other: '...', se_title: '...' }
            slots: null,    // パートが印刷済みの表の中身 { vo: { name, mic }, gt1: {...} }。null = まだ振り分けていない
            items: [],      // セット図の部品 { id, type, x, y, rot, scale, stretch, label }
            nextId: 1,      // 部品に振る通し番号
        };
    }

    /**
     * localStorage から前回の状態を読む。無い・壊れているときは null。
     *   localStorage はブラウザの「端末ごとの小さな保存場所」（文字列しか入らないので JSON にして入れる）。
     *   プライベートモードなどでは使えず例外が出ることがあるので try/catch で包む（業界の定石）
     */
    function loadState() {
        try {
            const raw = localStorage.getItem(STORE_KEY);
            if (!raw) {
                return null;
            }
            // { ...a, ...b } は「a に b を上書きしたコピー」（スプレッド構文）。
            // 古い保存データに新しい項目が無くても、defaultState の値で埋まる
            const saved = { ...defaultState(), ...JSON.parse(raw) };
            // 前の版（テキスト欄1つ）で保存したデータなら、1曲ずつの形に直す（データの移行＝マイグレーション）
            if (typeof saved.setlist === 'string') {
                saved.songs = parseSetlist(saved.setlist).map((e, i) => ({ id: i + 1, sound: '', light: '', title: '', ...e }));
                saved.nextSongId = saved.songs.length + 1;
                delete saved.setlist;
            }
            // SE の選択肢の名前を「有」→「あり」に変えたので、前の名前で保存したデータを直す
            if (saved.choices?.se === '有') {
                saved.choices.se = 'あり';
            }
            return saved;
        } catch (e) {
            return null;
        }
    }

    let saveTimer = 0;
    /** 保存。入力のたびに書くと重いので「最後の入力から 300ms 後に1回だけ」にする（デバウンスという定番の技） */
    function save() {
        schedulePreview(); // プレビューを開いていれば作り直す（下の「7. プレビュー」）
        scheduleHistory(); // セット図が変わっていれば「1つ戻す」の履歴に積む（下の「4.5 1つ戻す・やり直す」）
        clearTimeout(saveTimer);
        if (DATA.tplPreview) {
            return; // 型紙のお試しは毎回同じ仮の値なので保存しない
        }
        saveTimer = setTimeout(() => {
            try {
                localStorage.setItem(STORE_KEY, JSON.stringify(state));
            } catch (e) {
                // 保存できなくても画面はそのまま使えるので何もしない
            }
        }, 300);
    }

    const restored = DATA.tplPreview ? null : loadState();
    let state = restored || defaultState();
    if (!DATA.templates[state.template] || DATA.tplPreview) {
        state.template = DATA.templateKey; // 保存した型紙が消えていたら（設定から削除された等）初期値に戻す
    }

    /** いま選んでいる型紙 */
    const tpl = () => DATA.templates[state.template];
    /** セット図の部品の大きさの倍率（枠が大きいシートでは部品も大きく） */
    const stageScale = () => tpl().stage_scale || 1;
    /** BASE の値に倍率を掛けたもの。base('micLen') のように使う */
    const base = (key) => BASE[key] * stageScale();
    /** セット図の枠の大きさ（pt） */
    const stageSize = () => {
        const [x0, y0, x1, y1] = tpl().stage;
        return { w: x1 - x0, h: y1 - y0 };
    };

    /* =================================================================
     *  2. 入力欄 ⇔ state
     * ================================================================= */

    /** 1行の入力欄（data-f="band" など）。state の同じ名前の値とつなぐ */
    function bindFields() {
        $$('[data-f]').forEach((input) => {
            const key = input.dataset.f; // data-f="band" → dataset.f は "band"
            input.value = state[key] ?? '';
            // 'input' イベントは1文字打つたびに起きる（'change' は確定したときだけ）
            input.addEventListener('input', () => {
                state[key] = input.value;
                save();
            });
        });
    }

    /** パート文字列（"Vo/Gt"）→ 小文字の配列 ['vo', 'gt'] */
    const partList = (part) => part.toLowerCase().split(/[\/／・,、\s]+/).filter((x) => x !== '');

    /**
     * パートが印刷済みの表（slots）に、メンバーを振り分ける。
     *   ・Vo（Vo/Gt も）→ Vo (Gt.) の行
     *   ・Gt → Gt.1、埋まっていたら Gt.2、それも埋まっていたら「その他」
     *   ・Ba / Dr / Key → それぞれの行（2人目以降は名前をつなぐ）
     *   ・それ以外（Vn・Sax など）→「その他」に「名前(楽器)」で
     *   ・マイク〇: Vo か Cho を持っている人
     * 結果は state.slots に入れ、画面で手直しできる（自動はあくまで下書き）
     */
    function assignSlots() {
        const slots = {};
        for (const sl of tpl().members.slots) {
            slots[sl.key] = { name: '', mic: false };
        }
        // 1つの欄に2人目が来たら「・」でつなぐ
        const put = (key, name, mic) => {
            if (!slots[key]) {
                return;
            }
            slots[key].name = slots[key].name ? `${slots[key].name}・${name}` : name;
            slots[key].mic = slots[key].mic || mic;
        };
        for (const m of state.members) {
            const name = m.name.trim();
            if (name === '') {
                continue;
            }
            const parts = partList(m.part);
            const mic = parts.includes('vo') || parts.includes('cho');
            // どの楽器の行に入れるか（ボーカルは Vo 行が最優先。Vo/Ba の人は Ba 行にしてマイク〇）
            const has = (x) => parts.includes(x);
            if (has('vo') && !has('ba') && !has('dr') && !has('key')) {
                put('vo', name, true);
            } else if (has('gt')) {
                if (!slots.gt1 || slots.gt1.name === '') put('gt1', name, mic);
                else if (!slots.gt2 || slots.gt2.name === '') put('gt2', name, mic);
                else put('other', `${name}(Gt)`, mic);
            } else if (has('ba')) {
                put('ba', name, mic);
            } else if (has('dr')) {
                put('dr', name, mic);
            } else if (has('key')) {
                put('key', name, mic);
            } else {
                // 楽器名が無い・その他の楽器。楽器名をかっこで残す
                put('other', m.part.trim() ? `${name}(${m.part.trim()})` : name, mic);
            }
        }
        state.slots = slots;
    }

    /** パートが印刷済みの表の画面（パート名 / 名前 / マイク〇） */
    function renderSlots(box) {
        const mt = tpl().members;
        if (!state.slots) {
            assignSlots();
        }
        for (const sl of mt.slots) {
            const v = state.slots[sl.key] || (state.slots[sl.key] = { name: '', mic: false });
            const row = document.createElement('div');
            row.className = 'sheet-slots__row';
            const label = document.createElement('span');
            label.className = 'sheet-slots__label';
            label.textContent = sl.label;
            const name = document.createElement('input');
            name.type = 'text';
            name.maxLength = 60;
            name.placeholder = '複数人は・で区切る';
            name.value = v.name;
            name.addEventListener('input', () => { v.name = name.value; renderTexts(); save(); });
            // マイク〇のチェック。<label> で包むと文字を押してもチェックが切り替わる
            const micWrap = document.createElement('label');
            micWrap.className = 'check sheet-slots__mic';
            const mic = document.createElement('input');
            mic.type = 'checkbox';
            mic.checked = v.mic;
            mic.addEventListener('change', () => { v.mic = mic.checked; renderTexts(); save(); });
            micWrap.append(mic, 'マイク');
            row.append(label, name, micWrap);
            box.append(row);
        }
        // 振り分け直しボタン（「基本」のメンバーは DB の内容なので、それを元にやり直す）
        const redo = document.createElement('button');
        redo.type = 'button';
        redo.className = 'btn btn--sm btn--ghost';
        redo.textContent = 'DB のメンバーから振り分け直す';
        redo.addEventListener('click', () => {
            state.members = DATA.initial.members.map((m) => ({ part: m.part, name: m.name }));
            assignSlots();
            renderMembers();
            renderTexts();
            save();
        });
        if (!DATA.blank) { // 白紙のときは DB のメンバーが居ないので出さない
            box.append(redo);
        }
    }

    /**
     * メンバー表の「組」（パート列と氏名列の左右）。part2 / name2 があれば、表の右半分にもう1組ある（4列の表）。
     * 左の組を上から埋めて、入り切らなければ右の組に続ける。書ける人数 = 行数 × 組の数
     */
    const memberCols = (mt) => [[mt.part, mt.name], ...(Array.isArray(mt.part2) && Array.isArray(mt.name2) ? [[mt.part2, mt.name2]] : [])]
        .map((pair) => pair.map((c) => [Math.min(...c), Math.max(...c)])); // 型紙で線を追い越して左右が逆になった列も書けるように

    /** メンバー表の行を作る（型紙に書ける人数ぶん） */
    function renderMembers() {
        const box = $('[data-members]');
        box.textContent = ''; // 中身を空にする
        const hint = $('[data-members-hint]');
        if (tpl().members.mode === 'slots') {
            hint.textContent = 'マイクを使う人に✓';
            renderSlots(box);
            return;
        }
        hint.textContent = 'パートは Vo / Gt / Ba / Dr / Key などを入力';
        const rows = tpl().members.rows * memberCols(tpl().members).length; // 4列の表なら行数の2倍
        const [[partCol, nameCol]] = memberCols(tpl().members);
        const nameFirst = nameCol[0] < partCol[0];
        for (let i = 0; i < rows; i++) {
            if (!state.members[i]) {
                state.members[i] = { part: '', name: '' }; // 足りない行は空で埋める
            }
            const m = state.members[i];
            const row = document.createElement('div');
            row.className = 'sheet-members__row';
            // createElement + value 代入で作る。innerHTML に文字列で名前を埋め込むと、
            // 名前に <script> などが入っていたときに実行されてしまう（XSS）。value なら安全
            const part = document.createElement('input');
            part.type = 'text';
            part.placeholder = 'パート';
            part.maxLength = 10;
            part.value = m.part;
            part.addEventListener('input', () => { m.part = part.value; save(); });
            const name = document.createElement('input');
            name.type = 'text';
            name.placeholder = `${i + 1}人目の名前`;
            name.maxLength = 50;
            name.value = m.name;
            name.addEventListener('input', () => { m.name = name.value; save(); });
            // 入力欄の並びはシートの列と同じに（名前が左のシートは「名前 → パート」）
            if (nameFirst) {
                row.classList.add('is-name-first');
                row.append(name, part);
            } else {
                row.append(part, name);
            }
            box.append(row);
        }
        if (state.members.filter((m) => m.name.trim() !== '').length > rows) {
            setStatus(`メンバーが ${rows} 人を超えています。${rows + 1} 人目以降はシートに入りません`, true);
        }
    }

    /** マイク〇の数（「マイク計」の自動の値） */
    function micCount() {
        if (tpl().members.mode !== 'slots' || !state.slots) {
            return 0;
        }
        // 1つの欄に「A・B」と2人いてマイク〇なら2本と数える
        return Object.values(state.slots)
            .filter((v) => v.mic && v.name.trim() !== '')
            .reduce((sum, v) => sum + v.name.split('・').length, 0); // reduce: 配列を1つの値（合計）にまとめる
    }

    /**
     * PDF の1行の欄に書く値。year / month / day は出演日の欄（state.date）から数字だけ取り出す
     *   「2026/10/12」「10/12」「10月12日」→ 月 10・日 12。年は4桁の数字があるときだけ
     */
    function fieldValue(key) {
        if (key === 'year') {
            return (state.date || '').match(/(\d{4})/)?.[1] ?? '';
        }
        if (key === 'month' || key === 'day') {
            const m = (state.date || '').match(/(\d{1,2})\D+(\d{1,2})\D*$/);
            return m ? String(+m[key === 'month' ? 1 : 2]) : ''; // +: 「01」→ 1
        }
        return state[key] ?? '';
    }

    /** 自由記入欄の値。空で auto がある欄は自動の値を使う */
    function textValue(t) {
        const v = (state.texts[t.key] || '').trim();
        if (v === '' && t.auto === 'mic') {
            const n = micCount();
            return n > 0 ? String(n) : '';
        }
        return v;
    }

    /** その他の自由記入欄（型紙の texts から作る）。with_choice の欄は renderChoices() が作る */
    function renderTexts() {
        const box = $('[data-texts]');
        const list = (tpl().texts || []).filter((t) => !t.with_choice);
        // 入力中の欄を作り直すとカーソルが外れるので、欄の構成が同じなら placeholder だけ更新する
        if (box.dataset.tpl === state.template) {
            list.forEach((t) => {
                if (t.auto === 'mic') {
                    box.querySelector(`[data-text="${t.key}"]`).placeholder = `空欄なら自動: ${micCount()}`;
                }
            });
            return;
        }
        box.dataset.tpl = state.template;
        box.textContent = '';
        for (const t of list) {
            box.append(textField(t));
        }
    }

    /** 自由記入欄1つ（<label> の中に入力欄） */
    function textField(t) {
        const label = document.createElement('label');
        label.className = 'field';
        label.append(t.label);
        const input = document.createElement(t.multiline ? 'textarea' : 'input');
        if (!t.multiline) {
            input.type = 'text';
        } else {
            input.rows = 2;
        }
        input.dataset.text = t.key;
        input.maxLength = t.digits ? 3 : 200;
        input.value = state.texts[t.key] || '';
        if (t.placeholder) {
            input.placeholder = t.placeholder;
        }
        if (t.auto === 'mic' || t.digits) {
            input.inputMode = 'numeric'; // スマホで数字のキーボードを出す
        }
        if (t.auto === 'mic') {
            input.placeholder = `空欄なら自動: ${micCount()}`;
        }
        input.addEventListener('input', () => {
            if (t.digits) {
                // 数字だけ残す。全角の数字（３）は半角（3）に直す
                //   全角の ０〜９ は、半角の 0〜9 の文字コードに 0xFEE0 を足した位置にある
                input.value = input.value
                    .replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
                    .replace(/[^0-9]/g, '');
            }
            state.texts[t.key] = input.value;
            save();
        });
        label.append(input);
        return label;
    }

    /**
     * ○囲みのグループを見せるか。型紙の show_if に書いてあれば、別のグループの選択で決まる
     *   例: show_if.live_feel = ['mix', ['する', 'おまかせ']] → ミックス希望が「する」「おまかせ」のときだけ
     */
    function isShown(group) {
        const cond = tpl().show_if?.[group];
        return !cond || cond[1].includes(state.choices[cond[0]]);
    }

    /** ○囲みと一緒に出す自由記入欄を見せるか（一緒に出すグループが見えていて、その欄の show_if も満たすとき） */
    function isTextShown(t) {
        return (!t.with_choice || isShown(t.with_choice))
            && (!t.show_if || t.show_if[1].includes(state.choices[t.show_if[0]]));
    }

    /** 型紙に無い欄を隠す（出演日・その他の項目 など） */
    function renderTemplateUi() {
        const t = tpl();
        // data-field="date year month day" のように空白区切りで複数書ける。どれか1つでも型紙にあれば出す
        $$('[data-field]').forEach((el) => { el.hidden = !el.dataset.field.split(' ').some((k) => k in t.fields); });
        const hasExtras = (t.texts || []).length > 0 || Object.keys(t.choices || {}).length > 0;
        $('[data-extras-card]').hidden = !hasExtras;
        $('[data-extras-title]').textContent = t.extras_title || 'その他の項目';
    }

    let shownChoices = null; // 前に描いたときに見えていたグループ（新しく出たものだけアニメーションするため）

    /**
     * SE 有/なし などの○囲みボタン
     *   pop: 今押したボタン [グループ, 選択肢]。そのボタンだけ「ぽん」と弾ませる
     */
    function renderChoices(pop = null) {
        const box = $('[data-choices]');
        box.textContent = '';
        const before = shownChoices;
        shownChoices = new Set();
        // Object.entries({a: 1}) → [['a', 1]]。オブジェクトを for...of で回すときの定番
        for (const [group, options] of Object.entries(tpl().choices || {})) {
            if (!isShown(group)) {
                continue;
            }
            shownChoices.add(group);
            // 前は隠れていて、今回出てきたグループ（SE「あり」で出る項目など）はふわっと出す。最初に描くときはしない
            const entering = before !== null && !before.has(group);
            const wrap = document.createElement('div');
            wrap.className = 'sheet-choice' + (entering ? ' is-entering' : '');
            const title = document.createElement('span');
            title.className = 'sheet-choice__title';
            title.textContent = tpl().choice_labels?.[group] || DATA.choiceLabels[group] || group; // 型紙ごとの見出しが先
            wrap.append(title);
            // どのボタンも押されていない = どれも囲まない（未選択）。押されているボタンをもう一度押すと未選択に戻る
            for (const opt of Object.keys(options)) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'sheet-choice__btn';
                b.textContent = opt;
                const pressed = state.choices[group] === opt;
                // aria-pressed: 読み上げソフトに「押されている状態のボタン」と伝える。見た目の切り替えにも使う
                b.setAttribute('aria-pressed', String(pressed));
                if (pop && pop[0] === group && pop[1] === opt) {
                    b.classList.add('is-pop');
                }
                b.addEventListener('click', () => {
                    state.choices[group] = pressed ? '' : opt;
                    renderChoices([group, opt]);
                    save();
                });
                wrap.append(b);
            }
            box.append(wrap);
            // 選んでいる選択肢の説明（型紙の choice_notes）。ボタンの下に薄い文字で
            const note = tpl().choice_notes?.[group]?.[state.choices[group]];
            if (note) {
                const p = document.createElement('p');
                p.className = 'sheet-choice__note muted small';
                p.textContent = note; // textContent なので HTML として読まれない
                // 今押して出てきた説明はふわっと出す（ほかのボタンを押して描き直したときは動かさない）
                p.classList.toggle('is-entering', !!pop && pop[0] === group);
                box.append(p);
            }
            // このグループと一緒に出す自由記入欄（レコーディングの「その他要望」など）
            for (const t of (tpl().texts || []).filter((x) => x.with_choice === group)) {
                if (!isTextShown(t)) {
                    continue;
                }
                const id = 'text:' + t.key; // グループと同じ Set で「前から見えていたか」を覚える
                shownChoices.add(id);
                const field = textField(t);
                field.classList.toggle('is-entering', before !== null && !before.has(id));
                box.append(field);
            }
        }
    }

    /* =================================================================
     *  3. セットリストの読み取り
     * ================================================================= */

    /** MC の行か？（MC / ＭＣ / mc / MC1 など。大文字小文字・全角半角を問わない） */
    //   function 宣言にしているのは「巻き上げ」のため。ファイルの上の方（loadState）から先に呼ばれても動く
    //   （const で作った関数は、その行より前で呼ぶとエラーになる）
    function isMc(s) {
        return /^(mc|ｍｃ)[0-9０-９]*$/i.test(s.replace(/[\s　]/g, ''));
    }

    /**
     * （前の版の保存データの移行用）テキスト → [{ mc: true } | { title, sound, light }]
     *   split(/\r?\n/): 改行で分ける（Windows の改行 \r\n にも対応する正規表現）
     *   | と ｜（全角）で「曲名 | 音響 | 照明」に分ける
     */
    function parseSetlist(text) {
        return text.split(/\r?\n/)
            .map((s) => s.trim())
            .filter((s) => s !== '')
            .map((line) => {
                if (isMc(line)) {
                    return { mc: true };
                }
                const [title, sound = '', light = ''] = line.split(/[|｜]/).map((s) => s.trim());
                return { title, sound, light };
            });
    }

    /**
     * シートの行への割り当てを決める。MC は必ず1行使う（曲の間の線に書く書き方はやめた）
     *   rows : 表の行に書くもの（{ no: 曲順 or '', title, sound, light, mc }）
     */
    function layoutSetlist() {
        // 曲名が空の行は数えない（追加したけどまだ書いていない行）
        //   テキストの行（{ mc: true, text }）も、まだ何も書いていなければ数えない
        const entries = state.songs.filter((e) => (e.mc ? (e.text ?? 'MC').trim() !== '' : e.title.trim() !== ''));
        const st = tpl().setlist;
        const max = rowCount(st);
        const rows = [];
        let no = 0;
        for (const e of entries) {
            if (e.mc) {
                // text がある行は「テキストの行」。MC と同じく曲順を付けず、書いた文字をそのまま出す
                rows.push({ no: '', title: (e.text ?? 'MC').trim(), sound: '', light: '', mc: true });
            } else {
                no++;
                rows.push({ no: String(no), title: e.title, sound: e.sound, light: e.light });
            }
        }
        return { rows, max, songs: no };
    }

    /** 表の行数（lines で書いた表は「境目の数 − 1」） */
    const rowCount = (table) => (table.lines ? table.lines.length - 1 : table.rows);
    /** i 行目の [上, 下] */
    const rowRange = (table, i) => (table.lines
        ? [table.lines[i], table.lines[i + 1]]
        : [table.top + table.row_h * i, table.top + table.row_h * (i + 1)]);

    /** 「使う行: 7 / 10」の案内 */
    function renderRowsNote() {
        const { rows, max } = layoutSetlist();
        const note = $('[data-rows-note]');
        let msg = `シートの行: ${rows.length} / ${max}`;
        note.classList.remove('is-warn');
        if (rows.length > max) {
            msg += ` — 多すぎて ${rows.length - max} 行入りません（MC も1行使います）`;
            note.classList.add('is-warn');
        }
        note.textContent = msg;
    }


    /* -----------------------------------------------------------------
     *  セットリストの入力（1曲 = 1行、≡ をドラッグで並び替え）
     * ----------------------------------------------------------------- */
    const songList = $('[data-songs]');

    /** Material Symbols のアイコン（PHP の icon() と同じ形の HTML 要素を作る） */
    function iconEl(name) {
        const span = document.createElement('span');
        span.className = 'icon';
        span.setAttribute('aria-hidden', 'true');
        span.textContent = name;
        return span;
    }

    /** 小さな丸ボタン（アイコンだけ）。aria-label: 文字の無いボタンを読み上げソフトに説明する */
    function iconButton(name, label, className) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = className;
        b.setAttribute('aria-label', label);
        b.title = label;
        b.append(iconEl(name));
        return b;
    }

    /**
     * 曲を1つ足す（after を渡すとその曲のすぐ下に。無ければ一番下）
     *   kind: 'song' = 曲 / 'mc' = MC / 'text' = テキストの行（「転換」「SE」など、曲順を付けずに自由な文字を書く行）
     *   テキストの行は MC の仲間（mc: true）にして、曲順・曲の登録から外れるようにしている
     */
    function addSong(kind, after = null) {
        const id = state.nextSongId++;
        const song = kind === 'mc' ? { id, mc: true }
            : kind === 'text' ? { id, mc: true, text: '' }
            : { id, mc: false, title: '', sound: '', light: '' };
        const i = after ? state.songs.indexOf(after) + 1 : state.songs.length;
        state.songs.splice(i, 0, song); // splice(位置, 消す数, 足すもの): 配列の途中に差し込む
        renderSongs();
        renderRowsNote();
        save();
        if (kind !== 'mc') {
            songList.querySelector(`[data-id="${song.id}"] .setlist__title`)?.focus(); // すぐ打てるように
        }
    }

    /** セットリストの行を全部作り直す */
    function renderSongs() {
        songList.textContent = '';
        let no = 0;
        for (const song of state.songs) {
            const li = document.createElement('li');
            li.className = 'setlist__item' + (song.mc ? ' setlist__item--mc' : '');
            li.dataset.id = String(song.id);

            const handle = iconButton('drag_indicator', 'ドラッグで並び替え（↑↓キーでも動く）', 'setlist__handle');
            const num = document.createElement('span');
            num.className = 'setlist__no';
            num.textContent = song.mc ? '' : String(++no); // ++no: 先に1足してから使う
            li.append(handle, num);

            if (song.mc && song.text !== undefined) {
                // テキストの行: 曲名と同じ見た目の入力欄（要望の欄は無し）
                const text = document.createElement('input');
                text.type = 'text';
                text.className = 'setlist__title';
                text.placeholder = 'テキスト（例: 転換、SE）';
                text.maxLength = 100;
                text.value = song.text;
                text.addEventListener('input', () => { song.text = text.value; renderRowsNote(); save(); });
                li.append(text);
            } else if (song.mc) {
                const mc = document.createElement('span');
                mc.className = 'setlist__mc';
                mc.textContent = 'MC';
                li.append(mc);
            } else {
                const title = document.createElement('input');
                title.type = 'text';
                title.className = 'setlist__title';
                title.placeholder = '曲名';
                title.maxLength = 100;
                title.value = song.title;
                // enterKeyHint: スマホのキーボードの Enter キーの表示を「次へ」にする
                title.enterKeyHint = 'next';
                title.addEventListener('input', () => { song.title = title.value; renderRowsNote(); save(); });
                // Enter で「下に新しい曲を足して、そこへ移る」（続けて打ち込める）
                title.addEventListener('keydown', (e) => {
                    // isComposing: 日本語変換中の Enter（確定）は無視する。これを忘れると変換するたびに行が増える
                    if (e.key === 'Enter' && !e.isComposing) {
                        e.preventDefault();
                        addSong('song', song);
                    }
                });
                const tune = iconButton('tune', '音響・照明への要望', 'setlist__btn');
                const hasNote = song.sound !== '' || song.light !== '';
                tune.classList.toggle('is-on', hasNote);
                li.append(title, tune);

                // 要望の欄（最初は隠す。書いてあれば開いておく）
                const notes = document.createElement('div');
                notes.className = 'setlist__notes';
                notes.hidden = !hasNote;
                for (const [key, label] of [['sound', '音響への要望'], ['light', '照明への要望']]) {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.placeholder = label;
                    input.maxLength = 100;
                    input.value = song[key];
                    input.addEventListener('input', () => {
                        song[key] = input.value;
                        tune.classList.toggle('is-on', song.sound !== '' || song.light !== '');
                        save();
                    });
                    notes.append(input);
                }
                tune.addEventListener('click', () => {
                    notes.hidden = !notes.hidden;
                    notes.classList.toggle('is-entering', !notes.hidden); // 開いたときだけふわっと出す
                });
                li.append(notes);
            }

            const del = iconButton('delete', song.text !== undefined ? 'テキストを消す' : song.mc ? 'MC を消す' : '曲を消す', 'setlist__btn setlist__btn--del');
            del.addEventListener('click', () => {
                state.songs = state.songs.filter((x) => x !== song);
                renderSongs();
                renderRowsNote();
                save();
            });
            // 消すボタンは1行目の右端に置きたいので、要望欄より前に差し込む
            li.insertBefore(del, li.querySelector('.setlist__notes'));

            bindSongDrag(li, handle, song);
            songList.append(li);
        }
    }

    /**
     * ≡ を掴んで上下に動かす。
     *   動かしている間は、指の位置より下にある最初の行の「前」へ実際に DOM を移動させる（見たまま並ぶ）。
     *   指を離したら、画面の並び順を state.songs に写す。
     *   ライブラリ（SortableJS など）を使うのが業界では普通だが、仕組みを学ぶために自分で書いている
     *
     *   動き・離したことは window で受け取る。
     *     行を insertBefore で動かすと、ブラウザが setPointerCapture を解除してしまう。
     *     ≡ で受け取っていると、指が ≡ から外れた（横にずれた）とたんに何も届かなくなり、行が固まる
     */
    function bindSongDrag(li, handle, song) {
        handle.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            handle.setPointerCapture(e.pointerId);
            const grab = e.clientY - li.getBoundingClientRect().top; // 行の上端から指までの距離（ずれないように覚えておく）
            li.classList.add('is-dragging');

            const onMove = (ev) => {
                if (ev.pointerId !== e.pointerId) {
                    return; // ドラッグしている指以外の動きは無視
                }
                const y = ev.clientY;
                // 指の位置より「真ん中が下にある」最初の行を探す → その前に入れる
                const others = [...songList.children].filter((el) => el !== li);
                const target = others.find((el) => {
                    const r = el.getBoundingClientRect();
                    return y < r.top + r.height / 2;
                });
                // 位置が変わるときだけ動かす（毎回動かすと無駄に描き直しが起きる）
                if (li.nextElementSibling !== (target || null)) {
                    songList.insertBefore(li, target || null); // null なら一番最後へ
                }
                // 指に吸い付いて見えるように、本来の位置から指の位置までずらして描く
                li.style.transform = '';
                const top = li.getBoundingClientRect().top;
                li.style.transform = `translateY(${y - grab - top}px)`;
                // 画面の端まで来たら自動でスクロール（スマホで長いリストを動かすとき用）
                if (y < 90) window.scrollBy(0, -12);
                else if (y > window.innerHeight - 140) window.scrollBy(0, 12); // 下はタブバーと PDF ボタンの分だけ広く
            };
            const onUp = (ev) => {
                if (ev.pointerId !== e.pointerId) {
                    return; // 別の指が離れただけ
                }
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                window.removeEventListener('pointercancel', onUp);
                li.classList.remove('is-dragging');
                li.style.transform = '';
                commitOrder();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onUp);
        });

        // キーボードでも動かせるように（↑↓）。ドラッグしかできない UI はアクセシビリティ的に NG
        handle.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') {
                return;
            }
            e.preventDefault();
            const i = state.songs.indexOf(song);
            const j = e.key === 'ArrowUp' ? i - 1 : i + 1;
            if (j < 0 || j >= state.songs.length) {
                return;
            }
            // 分割代入で2つを入れ替える（[a, b] = [b, a] は値の交換の定番）
            [state.songs[i], state.songs[j]] = [state.songs[j], state.songs[i]];
            renderSongs();
            renderRowsNote();
            save();
            songList.querySelector(`[data-id="${song.id}"] .setlist__handle`).focus();
        });
    }

    /** 画面の並び順（data-id の順）を state.songs に写して描き直す */
    function commitOrder() {
        const byId = new Map(state.songs.map((x) => [x.id, x])); // Map: id → 曲 の対応表（探すのが速い）
        state.songs = [...songList.children].map((el) => byId.get(Number(el.dataset.id)));
        renderSongs();
        renderRowsNote();
        save();
    }

    $('[data-song-add]').addEventListener('click', () => addSong('song'));
    $('[data-mc-add]').addEventListener('click', () => addSong('mc'));
    $('[data-text-add]').addEventListener('click', () => addSong('text'));

    /* =================================================================
     *  4. セット図（ドラッグ編集）
     * ================================================================= */
    const stage = $('[data-stage]');
    const panel = $('[data-panel]');
    let selectedId = null;

    /** 1pt が画面で何 px か */
    const ptToPx = () => stage.clientWidth / stageSize().w;

    /** 値を min〜max に収める（はみ出し防止） */
    const clamp = (v, min, max) => Math.min(max, Math.max(min, v));

    /**
     * 部品の「回転前の」大きさ（pt）。PDF と画面で同じ計算を使う
     *   mic: 縦長の棒。w は細い
     */
    function itemSize(it) {
        switch (it.type) {
            case 'mic': return { w: base('micLen') * it.scale * 0.44, h: base('micLen') * it.scale };
            case 'circle': return { w: base('circle') * it.scale * it.stretch, h: base('circle') * it.scale };
            case 'box': return { w: base('boxW') * it.scale * it.stretch, h: base('boxH') * it.scale };
            case 'draw': { // 点の一番外側まで。点は中心からの位置なので、絶対値の最大 × 2
                const mx = Math.max(1, ...it.pts.map((p) => Math.abs(p[0])));
                const my = Math.max(1, ...it.pts.map((p) => Math.abs(p[1])));
                return { w: mx * 2 * it.scale, h: my * 2 * it.scale };
            }
            default: return { w: 0, h: 0 }; // text は文字の長さで決まる
        }
    }

    /**
     * 部品の中心を基準にした点 (lx, ly) を、回転させてセット図の座標（pt）にする。
     *   画面は y が下向きなので、この式で「時計回り」に回る。
     *   cos / sin は「角度 → 回したときの x, y の割合」。ラジアン（π = 180°）で渡す
     */
    function rotatePoint(it, lx, ly) {
        const r = (it.rot * Math.PI) / 180;
        return {
            x: it.x + lx * Math.cos(r) - ly * Math.sin(r),
            y: it.y + lx * Math.sin(r) + ly * Math.cos(r),
        };
    }

    /** マイクの下（持ち手側の端の少し先）に名前（Vo など）を書く位置 */
    function micLabelPoint(it) {
        return rotatePoint(it, 0, base('micLen') * it.scale / 2 + 6 * stageScale());
    }

    /* ---- 文字の大きさを箱に収める（画面・PDF 共通の考え方）----
     *   measure(文字, 大きさ) → 幅 を渡してもらい、
     *   「全部の行が幅に収まり、行数ぶんの高さが箱に収まる」いちばん大きいサイズを探す
     */
    function fitSize(lines, maxW, maxH, maxSize, measure) {
        let size = maxSize;
        while (size > 4) {
            const widest = Math.max(...lines.map((l) => measure(l, size)));
            if (widest <= maxW && lines.length * size * 1.2 <= maxH) {
                break;
            }
            size -= 0.25;
        }
        return size;
    }

    // 画面用の文字幅の測り方: <canvas> の measureText が速い（DOM に置かなくていい）
    const measureCanvas = document.createElement('canvas').getContext('2d');
    function measureScreen(text, size) {
        measureCanvas.font = `${size}px "Noto Sans JP", sans-serif`;
        return measureCanvas.measureText(text).width;
    }

    /** マイクの絵（SVG）。マイクの形は「中心が (0,0)、長さ 1」で書いて、viewBox で拡大する */
    //   形はシートの凡例（↑ の付いた ⌀）に合わせ、下の棒だけ少し短くしている
    const MIC_SVG =
        '<svg viewBox="-0.22 -0.5 0.44 1" preserveAspectRatio="none" aria-hidden="true">' +
        '<line x1="0" y1="-0.29" x2="0" y2="0.5" stroke="#000" stroke-width="0.045"/>' +
        '<circle cx="0" cy="0.053" r="0.199" fill="none" stroke="#000" stroke-width="0.035"/>' +
        '<polygon points="0,-0.5 -0.105,-0.285 0.105,-0.285" fill="#000"/>' +
        '</svg>';

    /** 部品を全部描き直す */
    function renderStage() {
        const { w, h } = stageSize();
        // aspect-ratio: 横:縦 の比率を CSS に任せる（横幅が変わっても縦が自動で合う）
        stage.style.aspectRatio = `${w} / ${h}`;
        // 背景の画像が無い型紙（エディタで PDF を読めずに保存した等）は、白いまま
        stage.style.backgroundImage = tpl().stage_image ? `url("${tpl().stage_image}")` : "none";
        stage.textContent = '';
        for (const it of state.items) {
            const el = document.createElement('div');
            el.className = `st-item st-item--${it.type}`;
            el.dataset.item = String(it.id);
            if (it.id === selectedId) {
                el.classList.add('is-selected');
            }
            if (it.type === 'mic') {
                el.innerHTML = MIC_SVG; // 固定の文字列（ユーザーの入力を含まない）なので innerHTML でも安全
            } else if (it.type === 'draw') {
                el.append(drawSvg(it));
            } else {
                // 文字は textContent で入れる（HTML として解釈されないので XSS にならない）
                const span = document.createElement('span');
                span.textContent = it.label;
                el.append(span);
            }
            stage.append(el);
            if (it.type === 'mic' && it.label.trim() !== '') {
                const lab = document.createElement('div');
                lab.className = 'st-label';
                lab.dataset.labelFor = String(it.id);
                lab.textContent = it.label;
                stage.append(lab);
            }
            place(it);
        }
    }

    /** 部品1つの位置・大きさ・回転を画面に反映（ドラッグ中はこれだけ呼ぶので軽い） */
    function place(it) {
        const k = ptToPx();
        const el = stage.querySelector(`[data-item="${it.id}"]`);
        if (!el) {
            return;
        }
        el.style.left = `${it.x * k}px`;
        el.style.top = `${it.y * k}px`;
        // translate(-50%, -50%): 要素の中心を left/top の点に合わせる。そのあと中心で回す
        el.style.transform = `translate(-50%, -50%) rotate(${it.rot}deg)`;
        if (it.type === 'text') {
            el.style.fontSize = `${base('text') * it.scale * k}px`;
        } else {
            const { w, h } = itemSize(it);
            el.style.width = `${w * k}px`;
            el.style.height = `${h * k}px`;
            if (it.type === 'draw') {
                // 線の太さは拡大しても同じにしたいので、vector-effect で倍率を無視させて px で指定
                el.querySelector('.st-draw-line').setAttribute('stroke-width', String(base('drawW') * k));
            } else if (it.type !== 'mic') {
                const lines = it.label.split('\n');
                const size = fitSize(lines, w - 4, h - 2, 10 * it.scale * stageScale(), measureScreen);
                el.style.fontSize = `${size * k}px`;
                el.style.borderWidth = `${Math.max(1, k)}px`;
            }
        }
        const lab = stage.querySelector(`[data-label-for="${it.id}"]`);
        if (lab) {
            const p = micLabelPoint(it);
            lab.style.left = `${p.x * k}px`;
            lab.style.top = `${p.y * k}px`;
            lab.style.fontSize = `${7 * stageScale() * k}px`;
        }
    }

    const findItem = (id) => state.items.find((it) => it.id === id);

    /** 部品を追加して選択する */
    function addItem(type, props = {}) {
        const { w, h } = stageSize();
        const it = {
            id: state.nextId++,
            type,
            x: w / 2,
            y: h / 2,
            rot: 0,
            scale: 1,
            stretch: 1,
            label: DEFAULT_LABEL[type],
            ...props, // 自動配置などで位置や文字を指定されたら上書き
        };
        state.items.push(it);
        return it;
    }

    function select(id) {
        selectedId = id;
        renderStage();
        renderPanel();
    }

    /** 選んだ部品の設定パネル */
    function renderPanel() {
        const it = findItem(selectedId);
        panel.hidden = !it;
        if (!it) {
            return;
        }
        panel.querySelector('[data-p="label"]').value = it.label;
        panel.querySelector('[data-p="scale"]').value = String(it.scale);
        panel.querySelector('[data-p="stretch"]').value = String(it.stretch);
        // data-only="box circle": その種類のときだけ見せる欄
        panel.querySelectorAll('[data-only]').forEach((el) => {
            el.hidden = !el.dataset.only.split(' ').includes(it.type);
        });
        renderPartButtons();
    }

    /** 四角を選んだときに出す候補（アンプなど）。型紙に box_labels があればそちらを使う */
    const BOX_LABELS = ['Marshall', 'JC', 'Ba Amp', 'Key', 'E.Pf'];

    /**
     * 丸・四角を選んだときの「名前ボタン」。押すとその名前が中の文字になる。
     *   丸: メンバー欄に入力されている人のパートだけ出す。
     *       「Vo/Gt」の人は Vo と Gt の2つに分ける。同じ楽器は1つにまとめる（大文字小文字は区別しない）
     *       Vo/Gt は誰もいなくても先頭に出す。「Vo/Ba」など Vo + 楽器の人がいれば、その組み合わせも出す
     *   四角: BOX_LABELS をそのまま出す
     */
    function renderPartButtons() {
        const box = panel.querySelector('[data-part-btns]');
        const it = findItem(selectedId);
        let names = [];
        if (it.type === 'box') {
            names = tpl().box_labels || BOX_LABELS;
        } else if (it.type === 'circle') {
            const seen = new Map([['vo/gt', 'Vo/Gt']]); // 小文字 => 表示する名前（最初に出てきた書き方を使う）
            for (const m of state.members) {
                if (m.name.trim() === '') {
                    continue;
                }
                const parts = m.part.split(/[\/／・,、\s]+/).filter((p) => p !== '');
                // Vo + 楽器（Vo/Ba など）: 自動配置と同じ「Vo/楽器」の形で候補に足す
                const inst = parts.find((p) => !['vo', 'cho', 'dr'].includes(p.toLowerCase()));
                if (inst && parts.some((p) => p.toLowerCase() === 'vo')) {
                    const combo = 'Vo/' + inst;
                    if (!seen.has(combo.toLowerCase())) {
                        seen.set(combo.toLowerCase(), combo);
                    }
                }
                for (const p of parts) {
                    if (p !== '' && !seen.has(p.toLowerCase())) {
                        seen.set(p.toLowerCase(), p);
                    }
                }
            }
            names = [...seen.values()];
        }
        box.replaceChildren(); // 中身を空にしてから作り直す
        for (const name of names) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn--sm';
            b.textContent = name; // textContent なので、パートに < などが入っていても HTML にならない（XSS 対策）
            b.addEventListener('click', () => {
                findItem(selectedId).label = name;
                panel.querySelector('[data-p="label"]').value = name;
                renderStage();
                save();
            });
            box.append(b);
        }
        if (it.type === 'circle' && names.length === 0) {
            box.innerHTML = '<span class="muted small">メンバー欄に名前とパートを入れると、ここに楽器名のボタンが出ます。</span>';
        }
    }

    let drawMode = false; // 描画モード中か（保存しない。ページを開き直すとオフ）

    /** 手で描いた線の SVG。点は「中心からの位置（pt）」なので、viewBox もその座標で書く */
    function drawSvg(it) {
        const NS = 'http://www.w3.org/2000/svg'; // SVG の要素は createElementNS で作る決まり
        const { w, h } = itemSize(it);
        const vw = w / it.scale, vh = h / it.scale; // 倍率を掛ける前の大きさ。拡大は要素の大きさで行う
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', `${-vw / 2} ${-vh / 2} ${vw} ${vh}`);
        svg.setAttribute('preserveAspectRatio', 'none');
        const line = document.createElementNS(NS, 'polyline');
        line.setAttribute('class', 'st-draw-line');
        line.setAttribute('points', it.pts.map((p) => p.join(',')).join(' '));
        line.setAttribute('fill', 'none');
        line.setAttribute('stroke', '#000');
        line.setAttribute('stroke-linecap', 'round');
        line.setAttribute('stroke-linejoin', 'round');
        line.setAttribute('vector-effect', 'non-scaling-stroke');
        // 細い線は指で掴めないので、見えない太い線を重ねて「当たり判定」にする
        const hit = line.cloneNode();
        hit.setAttribute('class', 'st-draw-hit');
        hit.setAttribute('stroke', 'transparent');
        hit.setAttribute('stroke-width', '16');
        svg.append(line, hit);
        return svg;
    }

    /**
     * 描画モードで指を置いた → 指の通った点を集めて、離したら1本の線の部品にする。
     *   描いている間は、セット図に重ねた SVG（st-draw-preview）にそのまま描いて見せる
     */
    function startStroke(e) {
        e.preventDefault();
        const k = ptToPx();
        const { w, h } = stageSize();
        const rect = stage.getBoundingClientRect();
        // 画面の px → セット図の pt。枠の外には出さない
        const toPt = (ev) => [
            clamp((ev.clientX - rect.left) / k, 0, w),
            clamp((ev.clientY - rect.top) / k, 0, h),
        ];
        const pts = [toPt(e)];
        const NS = 'http://www.w3.org/2000/svg';
        const preview = document.createElementNS(NS, 'svg');
        preview.setAttribute('class', 'st-draw-preview');
        preview.setAttribute('viewBox', `0 0 ${w} ${h}`);
        const line = document.createElementNS(NS, 'polyline');
        line.setAttribute('fill', 'none');
        line.setAttribute('stroke', '#000');
        line.setAttribute('stroke-width', String(base('drawW')));
        line.setAttribute('stroke-linecap', 'round');
        line.setAttribute('stroke-linejoin', 'round');
        preview.append(line);
        stage.append(preview);

        const onMove = (ev) => {
            if (ev.pointerId !== e.pointerId) {
                return;
            }
            const p = toPt(ev);
            const last = pts[pts.length - 1];
            // 前の点から 1pt 以上動いたときだけ足す（点が多すぎると保存データと PDF が重くなる）
            if (Math.hypot(p[0] - last[0], p[1] - last[1]) >= 1) {
                pts.push(p);
                line.setAttribute('points', pts.map((q) => q.join(',')).join(' '));
                if (!preview.isConnected) {
                    stage.append(preview); // 途中で描き直し（renderStage）が起きて消えたら付け直す
                }
            }
        };
        const onUp = (ev) => {
            if (ev.pointerId !== e.pointerId) {
                return;
            }
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onUp);
            preview.remove();
            if (pts.length < 2) {
                return; // 点を打っただけ（線になっていない）
            }
            // 部品の中心 = 点が収まる四角の真ん中。点は中心からの位置で持つ（移動・回転を他の部品と同じ仕組みでできる）
            const xs = pts.map((p) => p[0]), ys = pts.map((p) => p[1]);
            const cx = (Math.min(...xs) + Math.max(...xs)) / 2;
            const cy = (Math.min(...ys) + Math.max(...ys)) / 2;
            const r1 = (n) => Math.round(n * 10) / 10; // 小数第1位まで（保存データを小さく）
            addItem('draw', { x: cx, y: cy, pts: pts.map((p) => [r1(p[0] - cx), r1(p[1] - cy)]) });
            renderStage();
            save();
        };
        // 指が動いた・離れたは window で受け取る（セットリストのドラッグと同じ理由）
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onUp);
    }

    /* ---- 4.5 1つ戻す・やり直す（セット図の部品だけ）----
     *  変更のたびに state.items を JSON の文字列にして配列（history）に積んでおき、
     *  何番目を表示しているか（histPos）を前後に動かす。Word などの「元に戻す」と同じ考え方。
     *  文字の入力やスライダーは1文字・1目盛りごとに積むと多すぎるので、
     *  最後の変更から 400ms 止まったら1回だけ積む（save() と同じデバウンス）
     */
    const HISTORY_MAX = 50; // 覚えておく数（多すぎるとメモリを使う）
    history = [JSON.stringify(state.items)];
    const undoBtn = $('[data-undo]');
    const redoBtn = $('[data-redo]');

    function scheduleHistory() {
        if (!history) {
            return;
        }
        clearTimeout(histTimer);
        histTimer = setTimeout(recordHistory, 400);
    }

    /** 今のセット図が、表示中の履歴と違えば積む */
    function recordHistory() {
        clearTimeout(histTimer);
        const now = JSON.stringify(state.items);
        if (now === history[histPos]) {
            return;
        }
        // 戻した状態から新しく変更したら、それより先（やり直せた分）は捨てる
        history = history.slice(0, histPos + 1);
        history.push(now);
        if (history.length > HISTORY_MAX) {
            history.shift(); // 一番古いものを捨てる
        }
        histPos = history.length - 1;
        updateHistoryButtons();
    }

    function updateHistoryButtons() {
        undoBtn.disabled = histPos <= 0;
        redoBtn.disabled = histPos >= history.length - 1;
    }

    /** step = -1 で1つ戻す、+1 でやり直す */
    function moveHistory(step) {
        recordHistory(); // まだ積んでいない変更（400ms 待ちの分）を先に積む
        const next = histPos + step;
        if (next < 0 || next >= history.length) {
            return;
        }
        histPos = next;
        state.items = JSON.parse(history[histPos]);
        selectedId = null;
        renderStage();
        renderPanel();
        updateHistoryButtons();
        save(); // 履歴と同じ中身なので、ここで新しく積まれることはない
    }

    undoBtn.addEventListener('click', () => moveHistory(-1));
    redoBtn.addEventListener('click', () => moveHistory(1));
    // パソコンでは Ctrl+Z / Ctrl+Y（Mac は ⌘）でも。入力欄の中では、その欄の文字の「元に戻す」を優先する
    document.addEventListener('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey) || e.target.closest('input, textarea, select')) {
            return;
        }
        const key = e.key.toLowerCase();
        if (key === 'z' && !e.shiftKey) {
            e.preventDefault();
            moveHistory(-1);
        } else if (key === 'y' || (key === 'z' && e.shiftKey)) {
            e.preventDefault();
            moveHistory(1);
        }
    });

    function bindStage() {
        // ---- 追加ボタン ----
        $$('[data-add]').forEach((b) => b.addEventListener('click', () => {
            const it = addItem(b.dataset.add);
            select(it.id);
            save();
        }));

        // ---- 自動配置 ----
        $('[data-auto]').addEventListener('click', () => {
            if (state.items.length && !confirm('今のセット図を消して、メンバーのパートから並べ直しますか？')) {
                return;
            }
            autoLayout();
            select(null);
            save();
        });

        // ---- リセット: 部品を全部消す ----
        $('[data-stage-reset]').addEventListener('click', () => {
            if (state.items.length === 0 || !confirm('セット図の部品を全部消します。元に戻せません。よろしいですか？')) {
                return;
            }
            state.items = [];
            select(null); // 選択を外して描き直す（パネルも隠れる）
            save();
        });

        // ---- 描画モードのオン・オフ ----
        const drawBtn = $('[data-draw]');
        const hint = $('[data-stage-hint]');
        const hintText = hint.textContent;
        drawBtn.addEventListener('click', () => {
            drawMode = !drawMode;
            drawBtn.setAttribute('aria-pressed', String(drawMode));
            stage.classList.toggle('is-drawing', drawMode);
            hint.textContent = drawMode ? '指でなぞると線が描けます。終わったら「描画」をもう一度押してください。' : hintText;
            select(null);
        });

        // ---- ドラッグ ----
        // Pointer Events: マウス・指・ペンを1つの書き方で扱える（mouse/touch を別々に書かなくていい。今の標準）
        stage.addEventListener('pointerdown', (e) => {
            if (drawMode) {
                startStroke(e);
                return;
            }
            const el = e.target.closest('[data-item]'); // 押した場所から一番近い部品の要素を探す
            if (!el) {
                select(null); // 何もないところ → 選択を外す
                return;
            }
            const it = findItem(Number(el.dataset.item));
            if (it.id !== selectedId) {
                select(it.id);
            }
            const target = stage.querySelector(`[data-item="${it.id}"]`); // select() で描き直したので取り直す
            e.preventDefault();
            // setPointerCapture: 指が要素の外に出ても、この要素に pointermove が届き続けるようにする
            target.setPointerCapture(e.pointerId);
            const k = ptToPx();
            const { w, h } = stageSize();
            const start = { px: e.clientX, py: e.clientY, x: it.x, y: it.y };

            const onMove = (ev) => {
                // 動いた px を pt に直して足す。枠の外には出さない
                it.x = clamp(start.x + (ev.clientX - start.px) / k, 0, w);
                it.y = clamp(start.y + (ev.clientY - start.py) / k, 0, h);
                place(it);
            };
            const onUp = () => {
                target.removeEventListener('pointermove', onMove);
                target.removeEventListener('pointerup', onUp);
                target.removeEventListener('pointercancel', onUp);
                save();
            };
            target.addEventListener('pointermove', onMove);
            target.addEventListener('pointerup', onUp);
            target.addEventListener('pointercancel', onUp); // 電話が来た等で中断されたとき
        });

        // ---- 設定パネル ----
        panel.querySelector('[data-p="label"]').addEventListener('input', (e) => {
            const it = findItem(selectedId);
            it.label = e.target.value;
            renderStage();
            save();
        });
        ['scale', 'stretch'].forEach((key) => {
            panel.querySelector(`[data-p="${key}"]`).addEventListener('input', (e) => {
                const it = findItem(selectedId);
                it[key] = Number(e.target.value);
                place(it);
                save();
            });
        });
        panel.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => {
            const it = findItem(selectedId);
            if (!it) {
                return;
            }
            switch (b.dataset.act) {
                case 'rotL': it.rot = (it.rot - 45 + 360) % 360; break; // % 360: 0〜359 に収める
                case 'rotR': it.rot = (it.rot + 45) % 360; break;
                case 'dup': {
                    const { id, ...rest } = it; // id 以外をコピー（分割代入の「残り」構文）
                    const copy = addItem(it.type, { ...rest, x: it.x + 12, y: it.y + 12 });
                    selectedId = copy.id;
                    break;
                }
                case 'del':
                    state.items = state.items.filter((x) => x.id !== it.id);
                    selectedId = null;
                    break;
            }
            renderStage();
            renderPanel();
            save();
        }));

        // 画面の大きさが変わったら（スマホの縦横回転など）描き直す。
        // ResizeObserver: 要素の大きさの変化を見張る仕組み（window の resize より正確）
        new ResizeObserver(() => renderStage()).observe(stage);
    }

    /**
     * メンバーのパートから、ざっくり並べる。あとから手で直す前提の「下書き」。
     *   奥（上）: ベースアンプはドラムの左のすき間の真ん中、ギターアンプは右のすき間を等分（JC → Marshall）
     *   下: メンバー1人につき1つ、楽器名を書いた丸（立ち位置）。その下にマイク1本分のすき間を残す
     *   丸の真下: マイクを使う人のマイク。名前は入れない
     *     マイクを使う人 = パートに Vo / Cho がある人（Voice）、マイクの欄に✓がある人（共通シート）
     *   左: キーボードの四角（丸は置かない）。マイクを使うなら四角の真下にマイク。
     *       キーボードがいるときは、ベースの丸をキーボードの右へずらす（マイクと重ならないように）
     *   3人のバンド（Dr も数える）は上の通り。3人以外は、下の段を次のように並べ直す:
     *     - Vo/Ba・Vo/Key の人は真ん中（ボーカルの位置）
     *     - Gt がいなくて Key がいるときは、キーボードの四角を Gt の丸の位置に
     *     - 左 → 真ん中 → 右 を同じ間隔で並べ、真ん中のグループを横の真ん中に。左が空なら Gt.1 を左へ
     */
    function autoLayout() {
        const { w, h } = stageSize();
        state.items = [];
        // 印刷済みのドラムの箱（枠の中での位置）。アンプはこれの左右に置く
        const [sx, sy] = tpl().stage;
        const d = tpl().drum || [sx + w * 0.4, sy, sx + w * 0.6, sy + h * 0.2];
        const drumL = d[0] - sx, drumR = d[2] - sx, ampY = (d[1] + d[3]) / 2 - sy;
        const ampW = base('boxW');
        const micLen = base('micLen');
        const circle = base('circle');
        const people = autoPeople();
        const count = (key) => people.filter((pp) => pp.parts.includes(key)).length;
        const clampX = (x) => clamp(x, ampW / 2, w - ampW / 2);

        // ---- ギターアンプ: ドラムの右のすき間の真ん中に、すき間 gap で横に並べる ----
        //   ドラムに近い方から JC → Marshall（型紙の gt_amps があればその順。足りない分は Gt Amp）。
        //   （すき間を等分した点に置くと、点の間隔がアンプの幅より狭くなって重なるので、幅から並べる）
        //   入りきらないときは、アンプを小さくして（scale）入れる
        const gtN = count('gt');
        const gtX = [];
        if (gtN > 0) {
            const gap = 4 * stageScale();
            const room = w - drumR - gap * 2; // 右のすき間（ドラムと枠の端から gap ずつ空ける）
            const scale = Math.min(1, (room - gap * (gtN - 1)) / (ampW * gtN));
            const aw = ampW * scale;
            const rowW = aw * gtN + gap * (gtN - 1);           // 並べたときの全体の幅
            const left = drumR + (w - drumR) / 2 - rowW / 2;   // 全体の左端（右のすき間の真ん中に置く）
            for (let i = 0; i < gtN; i++) {
                const x = left + aw / 2 + i * (aw + gap);
                gtX.push(x);
                addItem('box', { x, y: ampY, scale, label: (tpl().gt_amps || ['JC', 'Marshall'])[i] || 'Gt Amp' });
            }
        }
        // ---- ベースアンプ: ドラムの左のすき間の真ん中 ----
        const baX = clampX(drumL / 2);
        if (count('ba') > 0) {
            addItem('box', { x: baX, y: ampY, label: 'Ba Amp' });
        }
        // ---- キーボード: 左側の真ん中の高さ ----
        //   3人以外のバンドで Gt がいないときは、左ではなく Gt の丸の位置（下の段の右）に置く（keyRight）
        const small = people.length === 3; // 3人のバンド（Dr も数える）は今まで通りの並べ方
        const keyX = ampW * 0.8;
        const keyY = h * 0.5;
        const keyW = ampW; // アンプの四角と同じ幅（前は 1.4 倍の横長で、長すぎた）
        const keyN = count('key');
        const keyRight = !small && keyN > 0 && gtN === 0;
        // キーボードの四角を置く場所（1台目, 2台目）。3台目からは四角を増やさず、左の四角を使う
        //   row   : 下の段の Gt の丸の位置（Gt がいないバンドの1台目）
        //   left  : 左の真ん中の高さ（いつもの位置）
        //   mirror: left と左右対称の位置（Gt がいるバンドの2台目）
        const keySlots = (keyRight ? ['row', 'left'] : ['left', 'mirror']).slice(0, keyN);
        const keyPos = { left: keyX, mirror: w - keyX };
        for (const slot of keySlots) {
            if (keyPos[slot] !== undefined) {
                addItem('box', { x: keyPos[slot], y: keyY, label: 'Key' });
            }
        }
        const hasKeyLeft = keySlots.includes('left');
        const keyMicY = keyY + base('boxH') / 2 + 2 + micLen / 2; // 四角の真下のマイクの中心

        // ---- メンバーの丸（立ち位置）----
        //   下の端から「マイク1本分 + すき間」上に置く。ドラムは印刷済みなので、Dr の人は丸を置かない
        const circleY = h - micLen - 4 - circle / 2;
        const micY = (y) => Math.min(y + circle / 2 + 2 + micLen / 2, h - micLen / 2); // 丸の真下のマイクの中心
        const sings = (pp) => pp.mic;
        // ベースの丸: キーボードが左にいるときは、四角の右端に丸が少しかかるくらいまで右へ（Ba Amp から離れすぎない）。
        //   ただし四角の真下に置くキーボードのマイク（幅は長さの 0.22 倍）とは重ならないように、その右にすき間を空ける
        const keyMicRight = keyX + micLen * 0.22 + 4 * stageScale();
        const baCircleX = hasKeyLeft ? Math.max(baX, keyX + keyW / 2 - circle * 0.3, keyMicRight + circle / 2) : baX;
        // 下の段に置く人を「左・真ん中・右」に分けて集め、最後にまとめて置く
        //   { label, mic, x: 今まで通りの位置（真ん中の人は無し）, key: キーボードの四角を置くか }
        const left = [], others = [], right = []; // others: 真ん中（Vo だけの人など）
        let keyMicLeft = false;   // 左のキーボードの下にマイクを置いたか（下の段の左端をそれより右にする）
        let keyMicMirror = false; // 右（左右対称）のキーボードの下にマイクを置いたか（下の段の右端をそれより左にする）
        let gtNo = 0, keyNo = 0;
        for (const pp of people) {
            // vo / cho / dr 以外の最初の楽器。歌いもするなら「Vo/楽器」（Vo/Gt・Vo/Ba）。楽器が無ければ Vo（Dr の人は null）
            const i = pp.parts.findIndex((x) => !['vo', 'cho', 'dr'].includes(x));
            const label = i >= 0
                ? (pp.parts.includes('vo') ? 'Vo/' + pp.raw[i] : pp.raw[i])
                : (pp.parts.includes('vo') ? 'Vo' : null);
            if (!label) {
                // ドラムで歌う人: マイクだけドラムの下に置く
                if (sings(pp)) {
                    addItem('mic', { x: (drumL + drumR) / 2, y: micY(d[3] - sy + circle / 2), label: '' });
                }
                continue;
            }
            const main = i >= 0 ? pp.parts[i] : 'vo';
            const vo = pp.parts.includes('vo');
            const mic = sings(pp);
            if (main === 'gt' && gtNo < gtX.length) {
                right.push({ label, mic, x: gtX[gtNo++] });
            } else if (main === 'ba' && !(vo && !small)) {
                left.push({ label, mic, x: baCircleX });
            } else if (main === 'key') {
                const slot = keySlots[keyNo++] || 'left'; // この人のキーボードの場所
                if (vo) {
                    // Vo/Key の人は、端のキーボードではなく、Vo だけの人と同じ真ん中に丸とマイクを置く（キーボードの四角はそのまま）
                    others.push({ label, mic });
                    if (slot === 'row') {
                        right.push({ label: 'Key', mic: false, x: drumR + (w - drumR) / 2, key: true });
                    }
                } else if (slot === 'row') {
                    // Gt がいないバンド: キーボードの四角を Gt の丸の位置に置く（丸は置かない）
                    right.push({ label: 'Key', mic, x: drumR + (w - drumR) / 2, key: true });
                } else if (mic) {
                    // キーボードの人は丸を置かない。マイクを使うなら四角の真下に
                    addItem('mic', { x: keyPos[slot], y: keyMicY, label: '' });
                    if (slot === 'mirror') {
                        keyMicMirror = true;
                    } else {
                        keyMicLeft = true;
                    }
                }
            } else {
                // Vo だけの人・その他の楽器・（3人以外のバンドの）Vo/Ba は真ん中
                others.push({ label, mic });
            }
        }

        /** 下の段に1人分を置く（丸、またはキーボードの四角）。マイクを使うならその真下に */
        const place = (o, x) => {
            if (o.key) {
                addItem('box', { x, y: circleY, label: 'Key' });
                if (o.mic) {
                    addItem('mic', { x, y: Math.min(circleY + base('boxH') / 2 + 2 + micLen / 2, h - micLen / 2), label: '' });
                }
                return;
            }
            addItem('circle', { x, y: circleY, label: o.label });
            if (o.mic) {
                addItem('mic', { x, y: micY(circleY), label: '' });
            }
        };

        if (small || others.length === 0) {
            // 3人のバンド・真ん中に誰もいないバンド: 今まで通り（左右はアンプの前、真ん中の人は枠の真ん中 4 割に等間隔）
            for (const o of [...left, ...right]) {
                place(o, o.x);
            }
            others.forEach((o, i) => place(o, w * 0.3 + (w * 0.4 * (i + 1)) / (others.length + 1)));
            return;
        }

        // 3人以外: 左 → 真ん中 → 右 の順に、同じ間隔 s で並べる。真ん中のグループの中央が枠の横の真ん中に来る
        //   左に誰もいない（Vo/Ba が真ん中に行ったなど）で右に2人以上いるときは、右の先頭（Gt.1）を左へ回す
        if (left.length === 0 && right.length >= 2) {
            left.push(right.shift()); // shift(): 配列の先頭を取り出す
        }
        const row = [...left, ...others, ...right];
        const cMid = left.length + (others.length - 1) / 2; // 真ん中のグループの中央の番号（0 始まり。半端もあり）
        const half = (o) => (o.key ? keyW / 2 : circle / 2); // 横幅の半分
        const gap = 4 * stageScale();
        // 端の丸が枠からはみ出さない（左にキーボードのマイクがあればそれにも重ならない）ように、間隔の上限を決める
        const minX = Math.max(half(row[0]) + gap, keyMicLeft ? keyMicRight + circle / 2 : 0);
        const maxX = Math.min(w - half(row[row.length - 1]) - gap, keyMicMirror ? w - keyMicRight - circle / 2 : Infinity);
        const stepsL = cMid, stepsR = row.length - 1 - cMid;
        const s = Math.min(
            row.length <= 3 ? w * 0.28 : w * 0.2, // 前に3人以下なら広めに離す
            stepsL > 0 ? (w / 2 - minX) / stepsL : Infinity,
            stepsR > 0 ? (maxX - w / 2) / stepsR : Infinity,
        );
        const xs = row.map((_, k) => w / 2 + (k - cMid) * s);
        // 左右で人数が違うとき（Ba・Vo・Gt・Gt など）は、少ない側を「枠の端〜真ん中のグループの端」の間に等分して置く
        //   （Ba は左端と Vo のちょうど中間になる）
        const L = left.length, C = others.length, R = right.length;
        if (L > 0 && L < R) {
            for (let j = 0; j < L; j++) {
                xs[j] = Math.max(minX, (xs[L] * (j + 1)) / (L + 1)); // 左のキーボードのマイクには重ねない
            }
        } else if (R > 0 && R < L) {
            const from = xs[L + C - 1];
            for (let j = 0; j < R; j++) {
                xs[L + C + j] = Math.min(maxX, from + ((w - from) * (j + 1)) / (R + 1));
            }
        }
        row.forEach((o, k) => place(o, xs[k]));
    }

    /**
     * 自動配置に使う「1人ずつの情報」{ parts: 小文字のパート一覧（判定用）, raw: 元の書き方（丸に書く用）, mic: マイクを使うか }
     *   Voice など（パート / 氏名を書く表）: メンバー欄のパートから。マイクはパートに Vo / Cho があれば
     *   共通シート（パートが印刷済みの表）: 欄（Vo / Gt.1 …）がパート。マイクは欄の✓
     */
    function autoPeople() {
        if (tpl().members.mode === 'slots') {
            if (!state.slots) {
                assignSlots();
            }
            // 欄のキー → [判定用のパート, 丸に書く名前]
            const SLOT_PART = { vo: ['vo', 'Vo'], gt1: ['gt', 'Gt'], gt2: ['gt', 'Gt'], ba: ['ba', 'Ba'], dr: ['dr', 'Dr'], key: ['key', 'Key'] };
            const people = [];
            for (const [key, v] of Object.entries(state.slots)) {
                if (!v || v.name.trim() === '') {
                    continue;
                }
                if (SLOT_PART[key]) {
                    const [part, label] = SLOT_PART[key];
                    people.push({ parts: [part], raw: [label], mic: v.mic });
                } else {
                    // 「その他」の欄: 「名前(Gt)」のかっこの中を楽器として使う。無ければ楽器なし
                    const m = v.name.match(/[（(]([^）)]+)[）)]\s*$/);
                    const raw = m ? m[1].split(/[\/／・,、\s]+/).filter((x) => x !== '') : [];
                    people.push({ parts: raw.map((x) => x.toLowerCase()), raw, mic: v.mic });
                }
            }
            return people;
        }
        return state.members
            .filter((m) => m.name.trim() !== '' || m.part.trim() !== '')
            .map((m) => {
                const parts = partList(m.part);
                return {
                    parts,
                    raw: m.part.split(/[\/／・,、\s]+/).filter((x) => x !== ''),
                    mic: parts.includes('vo') || parts.includes('cho'),
                };
            });
    }

    /* =================================================================
     *  5. PDF を作る
     * ================================================================= */

    /**
     * <script> を後から読み込む（遅延読み込み）。
     *   pdf-lib などは合わせて 1MB 以上あるので、ページを開いた瞬間ではなく
     *   「PDF を作る」を押したときに初めて読む。ページの表示が速くなる
     */
    const scriptCache = {};
    function loadScript(src) {
        if (!scriptCache[src]) {
            // Promise: 「あとで終わる処理」を表すもの。resolve で成功、reject で失敗を知らせる
            scriptCache[src] = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = src;
                s.onload = resolve;
                s.onerror = () => reject(new Error(`読み込めませんでした: ${src}`));
                document.head.append(s);
            });
        }
        return scriptCache[src];
    }

    /**
     * ファイルをバイト列（ArrayBuffer）で取ってくる。
     *   プレビューは入力のたびに PDF を作り直すので、取ってきたものは覚えておく（メモ化）。
     *   4MB のフォントを毎回ダウンロード・読み込みし直すと重いため
     */
    const bytesCache = new Map();
    function fetchBytes(url) {
        if (!bytesCache.has(url)) {
            const p = fetch(url).then((res) => {
                if (!res.ok) {
                    throw new Error(`ファイルを取得できませんでした (${res.status}): ${url}`);
                }
                return res.arrayBuffer();
            });
            p.catch(() => bytesCache.delete(url)); // 失敗したら覚えない（次に押したとき取り直す）
            bytesCache.set(url, p);
        }
        // pdf-lib に渡すと中身を使い回すことがあるので、毎回コピー（slice）を渡す
        return bytesCache.get(url).then((buf) => buf.slice(0));
    }

    /**
     * 文字の「見た目の縦の真ん中」が、ベースライン（文字の底の線）から上に文字の大きさの何倍の所にあるか。
     *   Noto Sans JP の字形を測ると、あ 0.372 / 藤 0.379 / ア 0.344 / H・1 0.366 / 〇 0.38 だったので 0.37。
     *   欄の真ん中にこの点を合わせると、文字が欄の縦の真ん中に見える
     */
    const GLYPH_MID = 0.37;

    /**
     * 本体: 書き込み済みの PDF のバイト列を返す。
     *   async 関数の中では await で「終わるまで待つ」が書ける（コールバック地獄にならない）
     */
    async function buildPdf() {
        // Promise.all: 複数の処理を「同時に」走らせて、全部終わるのを待つ（順番に待つより速い）
        await Promise.all(DATA.vendor.map(loadScript));
        const { PDFDocument, rgb, degrees } = window.PDFLib; // pdf-lib が window.PDFLib に入る
        const t = tpl();
        const [pdfBytes, fontBytes] = await Promise.all([fetchBytes(t.pdf), fetchBytes(DATA.fontUrl)]);

        const doc = await PDFDocument.load(pdfBytes);
        // 日本語を書くには、日本語の入ったフォントを PDF に埋め込む必要がある。
        // fontkit は TTF / OTF を読むためのライブラリ（pdf-lib に「差し込んで」使う）
        // ※ fontkit-shim.min.js は fontkit v2 版（v1 だと日本語の一部の文字が消えるため。assets/vendor/src/fontkit-shim.mjs 参照）
        doc.registerFontkit(window.fontkit);
        // subset: true → 使った文字だけを埋め込む（4MB のフォントでも PDF は数十 KB で済む）
        const font = await doc.embedFont(fontBytes, { subset: true });
        const page = doc.getPages()[0];
        const H = page.getHeight();
        const black = rgb(0, 0, 0);

        // ---- 座標の変換 ----
        // 型紙は「上から測った y」、PDF は「下から測った y」なので H − y にする
        const Y = (top) => H - top;
        const measure = (text, size) => font.widthOfTextAtSize(text, size);

        /**
         * 箱 [x0, top, x1, bottom] の中に文字を書く。
         *   大きい文字から順に試して、箱に収まったサイズで書く。
         *   wrap: true なら折り返して最大 maxLines 行まで使う。改行（\n）はそのまま改行になる
         *   型紙に text_scale があれば、いちばん大きい文字（size）をその倍率にする（ページが大きいシートでは文字も大きく）
         */
        function drawInBox(text, box, opt = {}) {
            const { size: baseSize = 10, min = 5, align = 'center', wrap = false, maxLines = 2, pad = 2 } = opt;
            const maxSize = baseSize * (t.text_scale || 1);
            text = String(text || '').trim();
            if (text === '') {
                return;
            }
            const [x0, top, x1, bottom] = box;
            const bw = x1 - x0 - pad * 2;
            const bh = bottom - top;
            const limit = wrap ? maxLines : text.split('\n').length;
            let size = maxSize;
            let lines = [];
            // 0.25pt ずつ小さくしながら「幅・行数・高さ」が全部収まるサイズを探す
            for (; size >= min; size -= 0.25) {
                lines = wrap ? wrapText(text, bw, size) : text.split('\n');
                const fits = lines.length <= limit
                    && lines.length * size * 1.15 <= bh
                    && lines.every((l) => measure(l, size) <= bw); // every: 全部が条件を満たすと true
                if (fits) {
                    break;
                }
            }
            if (size < min) {
                // いちばん小さくしても入らない → 最小サイズで、入る行数だけ書く（はみ出すより欠けた方がまし）
                size = min;
                lines = (wrap ? wrapText(text, bw, size) : text.split('\n')).slice(0, Math.max(1, Math.floor(bh / (size * 1.15))));
            }
            const lineH = size * 1.15;
            // 縦の真ん中に置く: 1行目の行の真ん中（行の上端 + lineH / 2）に、文字の見た目の真ん中（GLYPH_MID）を合わせる
            let baseline = top + (bh - lineH * lines.length) / 2 + lineH / 2 + size * GLYPH_MID;
            for (const line of lines) {
                const w = measure(line, size);
                const x = align === 'center' ? x0 + (x1 - x0 - w) / 2 : x0 + pad;
                page.drawText(line, { x, y: Y(baseline), size, font, color: black });
                baseline += lineH;
            }
        }

        /**
         * 文字を幅に合わせて折り返した行の配列にする。
         *   日本語は単語の区切り（スペース）が無いので、1文字ずつ足して幅を超えたら次の行へ
         */
        function wrapText(text, maxW, size) {
            const lines = [];
            for (const para of text.split('\n')) { // 自分で入れた改行は必ず改行
                let cur = '';
                for (const ch of para) { // for...of は文字単位で回る（絵文字なども1文字として扱える）
                    if (cur !== '' && measure(cur + ch, size) > maxW) {
                        lines.push(cur);
                        cur = ch;
                    } else {
                        cur += ch;
                    }
                }
                lines.push(cur);
            }
            return lines;
        }

        // 型紙の font_scale: メンバー表・セットリスト以外の欄（1行の欄・自由記入欄・固定の文字・選択肢の文字）の文字の倍率。
        //   大きくしても枠に入らなければ drawInBox が自動で小さくする
        const fs = t.font_scale || 1;

        // ---- 1. バンド名・フリガナ・出演日・出演順 ----
        for (const [key, f] of Object.entries(t.fields)) {
            drawInBox(fieldValue(key), f.box, { size: (f.size || 10) * fs, min: 5 });
        }
        // 型紙で決めた「いつも書く文字」（サークル名など。入力欄は無い）
        // shape があれば枠の大きさの四角・丸で囲む（文字が空なら図形だけ）
        for (const s of t.stamps || []) {
            const [x0, top, x1, bottom] = s.box;
            const line = { borderColor: black, borderWidth: s.line || 0.9 };
            if (s.shape === 'rect') {
                page.drawRectangle({ x: x0, y: Y(bottom), width: x1 - x0, height: bottom - top, ...line });
            } else if (s.shape === 'ellipse') {
                page.drawEllipse({ x: (x0 + x1) / 2, y: Y((top + bottom) / 2), xScale: (x1 - x0) / 2, yScale: (bottom - top) / 2, ...line });
            }
            if (s.text) drawInBox(s.text, s.box, { size: (s.size || 10) * fs, align: s.align || 'left', min: 5 });
        }

        // ---- 2. メンバー ----
        const mt = t.members;
        if (mt.mode === 'slots') {
            // パートが印刷済みの表: 欄ごとに名前、マイク列に〇
            for (const sl of mt.slots) {
                const v = state.slots?.[sl.key];
                if (!v) {
                    continue;
                }
                const [top, bottom] = sl.box;
                // 「A・B」の2人は2行に分けると読みやすい
                drawInBox(v.name.split('・').join('\n'), [mt.name[0] + 4, top, mt.name[1], bottom], { size: 14, min: 6 });
                if (v.mic && v.name.trim() !== '') {
                    drawInBox('〇', [mt.mic[0], top, mt.mic[1], bottom], { size: 16, align: 'center' });
                }
            }
        } else {
            state.members
                .filter((m) => m.name.trim() !== '' || m.part.trim() !== '')
                .slice(0, mt.rows * memberCols(mt).length) // 書ける人数を超えた人は書かない
                .forEach((m, i) => {
                    // 左の組を上から埋めて、続きは右の組の上から
                    const [partCol, nameCol] = memberCols(mt)[Math.floor(i / mt.rows)];
                    const top = mt.top + mt.row_h * (i % mt.rows);
                    const bottom = top + mt.row_h;
                    drawInBox(m.part, [partCol[0], top, partCol[1], bottom], { size: 9, align: 'center', pad: 1 });
                    drawInBox(m.name, [nameCol[0] + 3, top, nameCol[1], bottom], { size: 11 });
                });
        }

        // ---- 3. セットリスト ----
        const st = t.setlist;
        const lay = layoutSetlist();
        // 行の高さに合わせて文字の大きさを決める（Club Voice は 25pt、サークル共通は 44pt の行）
        const rowH = rowRange(st, 0)[1] - rowRange(st, 0)[0];
        const titleSize = Math.min(16, rowH * 0.48);
        const noteSize = Math.min(11, rowH * 0.32);
        lay.rows.slice(0, rowCount(st)).forEach((r, i) => {
            const [top, bottom] = rowRange(st, i);
            if (st.no) { // 曲順が印刷済みのシートには書かない
                drawInBox(r.no, [st.no[0], top, st.no[1], bottom], { size: 11, align: 'center' });
            }
            if (r.mc) {
                // MC・テキストの行（r.title に 'MC' か書いた文字が入っている）
                drawInBox(r.title, [st.title[0], top, st.title[1], bottom], { size: titleSize * 0.85, min: 7, align: 'center', pad: 4 });
                return; // forEach の中の return は「次の行へ」（continue と同じ働き）
            }
            drawInBox(r.title, [st.title[0], top, st.title[1], bottom], { size: titleSize, min: 7, wrap: true, pad: 4 });
            const note = { size: noteSize, min: 5, wrap: true, maxLines: 3, pad: 3, align: 'left' }; // 音響・照明への要望だけは左揃え
            if (st.request) {
                // 音響と照明が1つの欄: 両方あれば「音響 / 照明：…」の形でまとめる
                //   filter(Boolean): 空の文字（''）を取り除く定番の書き方
                const req = [r.sound, r.light && `照明：${r.light}`].filter(Boolean).join(' / ');
                drawInBox(req, [st.request[0], top, st.request[1], bottom], note);
            } else if (st.sound && st.light) { // 音響・照明の列が無いシートには書かない
                drawInBox(r.sound, [st.sound[0], top, st.sound[1], bottom], note);
                drawInBox(r.light, [st.light[0], top, st.light[1], bottom], note);
            }
        });

        // ---- 3.5 その他の自由記入欄 ----
        for (const tx of t.texts || []) {
            if (!DATA.tplPreview && !isTextShown(tx)) { // 型紙のお試しは全部の欄を書く
                continue; // 画面で隠れている欄は書かない
            }
            drawInBox(textValue(tx), tx.box, {
                size: (tx.size || 10) * fs, wrap: !!tx.multiline, maxLines: 4, pad: tx.pad ?? 3,
            });
        }

        // ---- 4. ○囲み（SE 有/なし など）----
        // 型紙のお試しは、全グループの全選択肢に印を付ける（本番ではあり得ないが、位置を一度に確かめられる）
        const picked = DATA.tplPreview
            ? Object.entries(t.choices || {}).flatMap(([g, opts]) => Object.keys(opts).map((o) => [g, o]))
            : Object.entries(state.choices);
        for (const [group, opt] of picked) {
            if (!DATA.tplPreview && !isShown(group)) {
                continue; // 画面で隠れている項目は書かない
            }
            const spec = t.choices?.[group]?.[opt]; // ?. : 途中が無くてもエラーにせず undefined を返す（オプショナルチェーン）
            if (!spec) {
                continue;
            }
            // 書き方は2通り: [箱] だけ（○で囲む）か { box: [箱], mark: 'check' | 'none', text, text_box }
            //   mark: 'check' = ✓ / 'none' = 印なし / 無し = ○。text があれば text_box に書く（例: 2タム →「（2Tom）」）
            const box = Array.isArray(spec) ? spec : spec.box;
            const [x0, top, x1, bottom] = box;
            if (!Array.isArray(spec) && spec.text && Array.isArray(spec.text_box)) {
                drawInBox(spec.text, spec.text_box, { size: (spec.size || 10) * fs, min: 5, pad: 1 });
            }
            if (!Array.isArray(spec) && spec.mark === 'none') {
                continue;
            }
            if (!Array.isArray(spec) && spec.mark === 'check') {
                // ✓ を2本の線で描く（フォントの ✓ は入っていないことがあるので、線で描くほうが確実）
                const w = x1 - x0, h = bottom - top;
                const p1 = { x: x0 + w * 0.18, y: Y(top + h * 0.52) };
                const p2 = { x: x0 + w * 0.42, y: Y(top + h * 0.8) };
                const p3 = { x: x0 + w * 0.85, y: Y(top + h * 0.18) };
                page.drawLine({ start: p1, end: p2, thickness: 2.5, color: black });
                page.drawLine({ start: p2, end: p3, thickness: 2.5, color: black });
                continue;
            }
            page.drawEllipse({
                x: (x0 + x1) / 2,
                y: Y((top + bottom) / 2),
                xScale: (x1 - x0) / 2 + 4,
                yScale: (bottom - top) / 2 + 2.5,
                borderColor: black,
                borderWidth: 0.9,
                // color を書かない → 中は塗らない（線だけ）
            });
        }

        // ---- 5. セット図 ----
        const [sx, sy] = t.stage;
        /** 部品の中心基準の点 → PDF の座標 */
        const toPdf = (it, lx, ly) => {
            const p = rotatePoint(it, lx, ly);
            return { x: sx + p.x, y: Y(sy + p.y) };
        };
        /**
         * PDF の座標の点の列 → drawSvgPath に渡す文字列。
         *   drawSvgPath は SVG と同じく「y が下向き」として描くので、x:0, y:0 で呼び、y の符号を反転して渡す
         *   M = そこへ移動、L = 線を引く、C = ベジェ曲線、Z = 閉じる
         */
        const svgNum = (n) => n.toFixed(2);
        for (const it of state.items) {
            const { w, h } = itemSize(it);
            if (it.type === 'mic') {
                const L = base('micLen') * it.scale;
                const p = (lx, ly) => toPdf(it, lx * L, ly * L); // マイクの形は「長さ1」で書いてあるので L 倍
                // 数字は画面の MIC_SVG と同じ
                const a = p(0, -0.29), b = p(0, 0.5);
                page.drawLine({ start: a, end: b, thickness: Math.max(0.8, 0.045 * L), color: black });
                const c = p(0, 0.053);
                page.drawCircle({ x: c.x, y: c.y, size: 0.199 * L, borderColor: black, borderWidth: Math.max(0.7, 0.035 * L) });
                const t1 = p(0, -0.5), t2 = p(-0.105, -0.285), t3 = p(0.105, -0.285);
                page.drawSvgPath(
                    `M ${svgNum(t1.x)} ${svgNum(-t1.y)} L ${svgNum(t2.x)} ${svgNum(-t2.y)} L ${svgNum(t3.x)} ${svgNum(-t3.y)} Z`,
                    { x: 0, y: 0, color: black },
                );
                if (it.label.trim() !== '') {
                    const lp = micLabelPoint(it);
                    const size = 7 * stageScale();
                    const tw = measure(it.label, size);
                    page.drawText(it.label, { x: sx + lp.x - tw / 2, y: Y(sy + lp.y + size * 0.35), size, font, color: black });
                }
                continue;
            }
            if (it.type === 'draw') {
                const path = it.pts
                    .map((q, i) => {
                        const r = toPdf(it, q[0] * it.scale, q[1] * it.scale);
                        return `${i === 0 ? 'M' : 'L'} ${svgNum(r.x)} ${svgNum(-r.y)}`;
                    })
                    .join(' ');
                page.drawSvgPath(path, { x: 0, y: 0, borderColor: black, borderWidth: base('drawW') });
                continue;
            }
            if (it.type === 'box' || it.type === 'circle') {
                let path;
                if (it.type === 'box') {
                    const pts = [[-w / 2, -h / 2], [w / 2, -h / 2], [w / 2, h / 2], [-w / 2, h / 2]].map(([lx, ly]) => toPdf(it, lx, ly));
                    path = `M ${pts.map((q) => `${svgNum(q.x)} ${svgNum(-q.y)}`).join(' L ')} Z`;
                } else {
                    // 楕円は4本のベジェ曲線で描く（0.5523 は円をベジェで近似するときの定番の係数）
                    const rx = w / 2, ry = h / 2, kx = rx * 0.5523, ky = ry * 0.5523;
                    const seg = [
                        [[rx, 0]],
                        [[rx, ky], [kx, ry], [0, ry]],
                        [[-kx, ry], [-rx, ky], [-rx, 0]],
                        [[-rx, -ky], [-kx, -ry], [0, -ry]],
                        [[kx, -ry], [rx, -ky], [rx, 0]],
                    ];
                    const s = (q) => { const r = toPdf(it, q[0], q[1]); return `${svgNum(r.x)} ${svgNum(-r.y)}`; };
                    path = `M ${s(seg[0][0])} ` + seg.slice(1).map((c3) => `C ${c3.map(s).join(' ')}`).join(' ') + ' Z';
                }
                page.drawSvgPath(path, { x: 0, y: 0, borderColor: black, borderWidth: 1 });
            }
            // 文字（box / circle / text 共通）。回転に合わせて文字も回す
            const lines = it.label.split('\n').filter((l, i, arr) => l !== '' || arr.length === 1);
            if (lines.join('') === '') {
                continue;
            }
            const size = it.type === 'text'
                ? base('text') * it.scale
                : fitSize(lines, w - 4, h - 2, 10 * it.scale * stageScale(), measure);
            const lineH = size * 1.2;
            lines.forEach((line, i) => {
                const tw = measure(line, size);
                // 中心から見た「この行の左端・ベースライン」の位置
                const ly = -(lineH * lines.length) / 2 + lineH * i + lineH / 2 + size * GLYPH_MID;
                const o = toPdf(it, -tw / 2, ly);
                // PDF の回転は反時計回りが +。画面は時計回りが + なので符号を反転
                page.drawText(line, { x: o.x, y: o.y, size, font, color: black, rotate: degrees(-it.rot) });
            });
        }

        return doc.save(); // Uint8Array（PDF のバイト列）
    }

    /* =================================================================
     *  6. ボタン・メッセージ
     * ================================================================= */
    const statusEl = $('[data-status]');
    function setStatus(msg, warn = false) {
        statusEl.textContent = msg;
        statusEl.classList.toggle('is-warn', warn);
    }

    /**
     * 出力する PDF のファイル名。例: 1030_2番目_バンド名.pdf
     *   画面の「出演日」「出演順」「バンド名」の欄から作る（欄を直して出すこともあるので DB ではなく欄の値）。
     *   空の欄はその部分を飛ばす。ファイル名に使えない文字（\ / : * ? " < > |）は _ にする
     */
    const fileName = () => {
        // 出演日 "2026/10/30" や "10/30" → 数字だけ取り出し、最後の2つを月・日として "1030" に
        //   padStart(2, '0'): 2桁に満たなければ前に 0 を足す（"9" → "09"）
        const nums = String(state.date || '').match(/\d+/g) || [];
        const mmdd = nums.length >= 2 ? nums.slice(-2).map((n) => n.padStart(2, '0')).join('') : '';
        const order = String(state.order || '').trim();
        const parts = [mmdd, order && `${order}番目`, String(state.band || '').trim()].filter(Boolean);
        const name = parts.length ? parts.join('_') : 'セッティングシート';
        return `${name}.pdf`.replace(/[\\/:*?"<>|]/g, '_');
    };

    let lastFile = null; // 共有ボタン用に、最後に作った PDF を覚えておく

    $('[data-make]').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true; // 二度押し防止
        setStatus('PDF を作っています…（初回はフォントの読み込みで少しかかります）');
        try {
            const bytes = await buildPdf();
            const blob = new Blob([bytes], { type: 'application/pdf' });
            lastFile = new File([blob], fileName(), { type: 'application/pdf' });
            // ダウンロード: 見えない <a download> を作ってクリックする（ブラウザで定番のやり方）
            const url = URL.createObjectURL(blob); // メモリ上のデータを指す一時的な URL
            const a = document.createElement('a');
            a.href = url;
            a.download = fileName();
            document.body.append(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60_000); // 一時 URL は使い終わったら捨てる（メモリ解放）
            const lay = layoutSetlist();
            setStatus(lay.rows.length > lay.max ? `できました（${lay.rows.length - lay.max} 曲は入りませんでした）` : 'できました！', lay.rows.length > lay.max);
            // Web Share API: スマホの「共有」シート（LINE などに送れる）。対応しているときだけボタンを出す
            const share = $('[data-share]');
            share.hidden = !(navigator.canShare && navigator.canShare({ files: [lastFile] }));
            askRegister(); // 続けて「DB に登録しますか？」
        } catch (err) {
            console.error(err); // 開発者ツールのコンソールに詳しいエラーを出す
            setStatus('PDF を作れませんでした: ' + err.message, true);
        } finally {
            btn.disabled = false; // 成功しても失敗しても最後に必ず実行される
        }
    });

    /**
     * PDF を作ったあと「このセットリストを DB に登録しますか？」と聞く。
     *   ・曲が1つも無い / DB と全く同じ → 聞かない（同じなら「登録済み ✓」と出すだけ）
     *   ・「はい」→ 隠しフォームに曲名を titles[] として入れ、songs_edit.php に POST で送る
     *     （GET の URL に曲名を並べると、長すぎたり履歴に残ったりするので POST。CSRF の合言葉も付く）
     * <dialog> の中の <form method="dialog"> は、押したボタンの value を dialog.returnValue に入れて閉じる（HTML の標準機能）
     */
    function askRegister() {
        if (DATA.blank) {
            return; // 白紙（バンドなし）は登録先が無い
        }
        const titles = state.songs.filter((x) => !x.mc && x.title.trim() !== '').map((x) => x.title.trim());
        if (titles.length === 0) {
            return;
        }
        const db = DATA.dbSongs.map((t) => t.trim());
        const same = titles.length === db.length && titles.every((t, i) => t === db[i]);
        if (same) {
            setStatus('できました！ セットリストは DB に登録済みです ✓');
            return;
        }
        // ダイアログは [data-sheet] の外にあるので document から探す
        const dialog = document.querySelector('[data-register-dialog]');
        const list = dialog.querySelector('[data-register-list]');
        list.textContent = '';
        titles.forEach((t) => {
            const li = document.createElement('li');
            li.textContent = t; // textContent なので曲名に < > が入っていても安全
            list.append(li);
        });
        if (titles.length > 15) {
            const li = document.createElement('li');
            li.textContent = '※ 登録できるのは15曲までです（16曲目以降は入りません）';
            list.append(li);
        }
        dialog.addEventListener('close', () => {
            if (dialog.returnValue !== 'yes') {
                return;
            }
            const form = document.querySelector('[data-register-form]');
            form.querySelectorAll('[name="titles[]"]').forEach((el) => el.remove()); // 前に入れた分は消す
            titles.forEach((t) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'titles[]'; // [] を付けると PHP 側で配列 $_POST['titles'] として受け取れる
                input.value = t;
                form.append(input);
            });
            form.submit();
        }, { once: true }); // once: 1回呼ばれたら自動で外れる（何回も PDF を作ったときに重ならない）
        dialog.showModal();
    }

    $('[data-share]').addEventListener('click', async () => {
        if (!lastFile) {
            return;
        }
        try {
            await navigator.share({ files: [lastFile], title: lastFile.name });
        } catch (err) {
            // 共有シートを閉じただけ（AbortError）のときは何もしない
        }
    });

    // 型紙の切り替え
    const tplSelect = $('[data-template]');
    tplSelect.value = state.template;
    tplSelect.addEventListener('change', () => {
        state.template = tplSelect.value;
        renderAll();
        save();
    });

    // 「DB の内容に戻す」
    $('[data-reset]').addEventListener('click', () => {
        if (!confirm(DATA.blank ? '入力した内容とセット図を消して、白紙に戻しますか？' : '入力した内容とセット図を消して、DB の内容に戻しますか？')) {
            return;
        }
        try { localStorage.removeItem(STORE_KEY); } catch (e) { /* 何もしない */ }
        state = defaultState();
        $('[data-saved-note]').hidden = true;
        // 入力欄の値も入れ直す
        $$('[data-f]').forEach((input) => { input.value = state[input.dataset.f] ?? ''; });
        tplSelect.value = state.template;
        selectedId = null;
        renderAll();
    });

    function renderAll() {
        renderTemplateUi();
        renderSongs();
        renderMembers();
        delete $('[data-texts]').dataset.tpl; // 自由記入欄は必ず作り直す（型紙が変わった・リセットした）
        renderTexts();
        renderChoices();
        renderRowsNote();
        renderStage();
        renderPanel();
    }


    /* =================================================================
     *  6.5 プレビュー（左下のフローティングボタンで開閉）
     * =================================================================
     *  完成形の PDF を実際に作り、pdf.js（Mozilla 製。Firefox の PDF ビューアの中身）で <canvas> に描く。
     *  なぜ <iframe> で PDF をそのまま見せないのか:
     *    Android の Chrome は <iframe> の中の PDF を表示できない（ダウンロードのボタンになる）。
     *    canvas に描けば、どのスマホでも同じに見える
     *  pdf.js は 1.7MB あるので、初めて開いたときに読み込む（遅延読み込み）
     */
    const fab = document.querySelector('[data-preview-fab]');
    const previewBox = document.querySelector('[data-sheet-preview]');
    const previewCanvas = previewBox.querySelector('canvas');
    const previewNote = previewBox.querySelector('[data-preview-note]');
    let zoom = 1;            // プレビューの拡大率（1 = 枠の幅ぴったり）
    const ZOOM_MAX = 5;
    let rendering = false;   // いま描いている途中か
    let renderAgain = false; // 描いている途中に入力があった → 終わったらもう一度
    let pdfjs = null;

    async function loadPdfjs() {
        if (!pdfjs) {
            // import(): JS のモジュールを後から読み込む。pdf.js は「モジュール形式」でしか配られていない
            //   拡張子を .mjs から .js に変えて置いている。レンタルサーバーによっては .mjs を JS として配らず、
            //   ブラウザが「これは JS じゃない」と読み込みを拒否するため
            pdfjs = await import(new URL(DATA.pdfjs.lib, location.href).href);
            pdfjs.GlobalWorkerOptions.workerSrc = new URL(DATA.pdfjs.worker, location.href).href;
        }
        return pdfjs;
    }

    async function renderPreview() {
        if (rendering) {
            renderAgain = true; // 今の描画が終わったら、最新の入力でもう一度
            return;
        }
        // 枠を出した直後（型紙のプレビューの iframe を作った直後など）はまだ幅が 0 のことがある。
        // 幅 0 の canvas は drawImage で失敗するので、幅が付くまで次のフレームを待つ
        if (fitWidth() <= 0) {
            requestAnimationFrame(() => { if (previewOpen) renderPreview(); });
            return;
        }
        rendering = true;
        // 入力が変わったときだけ「更新中」を出して薄くする。拡大・縮小の描き直しで出すと点滅して見える
        const rebuild = pdfDirty || !pdfBytes;
        if (rebuild) {
            previewBox.classList.add('is-busy');
            previewNote.textContent = '更新中…';
        }
        try {
            const lib = await loadPdfjs();
            if (rebuild) {
                pdfDirty = false;
                pdfBytes = await buildPdf();
            }
            // getDocument は「読み込みの仕事（loadingTask）」を返す。.promise で読み終わりを待つ
            const task = lib.getDocument({
                data: pdfBytes.slice(), // pdf.js に渡すと中身を持っていかれることがあるのでコピーを渡す
                // 元の PDF に埋め込まれていない日本語フォント（サークル共通シート）を読むための対応表
                cMapUrl: new URL(DATA.pdfjs.cmaps, location.href).href,
                cMapPacked: true,
                standardFontDataUrl: new URL(DATA.pdfjs.fonts, location.href).href,
            });
            const doc = await task.promise;
            const page = await doc.getPage(1);
            // 表示する幅 × 画面の細かさ（devicePixelRatio。iPhone は 3）で描くと、文字がにじまない。
            //   拡大しているときは、その大きさで描き直すので拡大してもくっきり
            const width = fitWidth() * zoom;
            const base = page.getViewport({ scale: 1 });
            const ratio = Math.min(window.devicePixelRatio || 1, 3);
            let scale = (width / base.width) * ratio;
            // canvas は大きすぎると描けない（iPhone は約 1600 万画素まで）。超えるなら細かさの方を下げる
            const MAX_PIXELS = 16000000;
            const pixels = base.width * base.height * scale * scale;
            if (pixels > MAX_PIXELS) {
                scale *= Math.sqrt(MAX_PIXELS / pixels);
            }
            const viewport = page.getViewport({ scale });
            // 見えない canvas に描いてから、出来上がりを一度に写す。
            //   見えている canvas の width を変えると、その瞬間に中身が消える（真っ白になる）ので、
            //   そこに直接描くと「消える → 描き終わる」が点滅に見える
            const off = document.createElement('canvas');
            off.width = viewport.width;
            off.height = viewport.height;
            await page.render({ canvas: off, viewport }).promise;
            await task.destroy(); // 使い終わった PDF をメモリから捨てる（裏で動く worker の分も）
            previewCanvas.width = off.width;   // ここで消えるが、
            previewCanvas.height = off.height;
            previewCanvas.getContext('2d').drawImage(off, 0, 0); // 同じ処理の中ですぐ描くので、画面には消えた瞬間が出ない
            // 幅は「今の」拡大率で（描いている間にピンチで変わっていることがある）
            previewCanvas.style.width = `${fitWidth() * zoom}px`;
            previewNote.textContent = '';
        } catch (err) {
            console.error(err);
            previewNote.textContent = 'プレビューを作れませんでした: ' + err.message;
        } finally {
            rendering = false;
            previewBox.classList.remove('is-busy');
            if (renderAgain && previewOpen) {
                renderAgain = false;
                renderPreview();
            }
        }
    }

    /** 拡大率 1 のときの表示の幅（枠の幅 − 内側の余白） */
    const fitWidth = () => previewBox.clientWidth - 16;

    /**
     * 拡大率を変える。指の真ん中（cx, cy: 枠の左上からの px）の場所が、拡大しても指の下に残るようにスクロールを合わせる。
     *   まず CSS の幅だけ変えて（速い。少しぼやける）、指を止めてから描き直す（くっきり）
     */
    function setZoom(next, cx, cy) {
        next = clamp(next, 1, ZOOM_MAX);
        const f = next / zoom; // 何倍になるか
        // 「枠の中の見えている位置 + スクロール量」= 絵の中の位置。それが f 倍の場所に動くので、その分スクロールする
        const x = previewBox.scrollLeft + cx;
        const y = previewBox.scrollTop + cy;
        zoom = next;
        previewCanvas.style.width = `${fitWidth() * zoom}px`;
        previewBox.scrollLeft = x * f - cx;
        previewBox.scrollTop = y * f - cy;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 300);
    }

    /* ---- ピンチで拡大・縮小、ダブルタップで拡大 ⇔ 元に戻す ----
     *  Touch Events の touches（今さわっている指の一覧）が2本のとき、「2本の指の距離」の変化を拡大率にする。
     *  Pointer Events ではなく Touch Events を使う理由:
     *    1本指でスクロールが始まると、ブラウザがその指の pointer イベントを止めて（pointercancel）しまい、
     *    あとから2本目を置いてもピンチを見つけられないことがある。touch イベントはスクロール中も届き続ける
     */
    let pinch = null; // ピンチ中の { 始めの距離, 始めの拡大率 }
    const touchDist = (t) => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); // hypot: 2点の距離
    previewBox.addEventListener('touchstart', (e) => {
        if (e.touches.length === 2) {
            pinch = { dist: touchDist(e.touches), zoom };
        }
    });
    // passive: false … preventDefault() を使うと宣言する（しないと、ブラウザは待たずにスクロールを始めてしまう）
    previewBox.addEventListener('touchmove', (e) => {
        if (!pinch || e.touches.length !== 2) {
            return;
        }
        e.preventDefault(); // ピンチ中はスクロールさせない
        const [a, b] = e.touches;
        const r = previewBox.getBoundingClientRect();
        setZoom(
            pinch.zoom * (touchDist(e.touches) / pinch.dist),
            (a.clientX + b.clientX) / 2 - r.left,
            (a.clientY + b.clientY) / 2 - r.top,
        );
    }, { passive: false });
    const pinchEnd = (e) => {
        if (e.touches.length < 2) {
            pinch = null;
        }
    };
    previewBox.addEventListener('touchend', pinchEnd);
    previewBox.addEventListener('touchcancel', pinchEnd);
    // ダブルタップ（PC はダブルクリック）: 拡大していなければ 2.5 倍、していれば元に戻す
    previewBox.addEventListener('dblclick', (e) => {
        const r = previewBox.getBoundingClientRect();
        setZoom(zoom > 1 ? 1 : 2.5, e.clientX - r.left, e.clientY - r.top);
    });

    /** 入力のたびに呼ばれる。最後の入力から 0.6 秒たったら1回だけ描き直す（デバウンス） */
    function schedulePreview() {
        pdfDirty = true; // 閉じている間の入力も、次に開いたとき作り直す
        if (!previewOpen) {
            return;
        }
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 600);
    }

    function setPreview(open) {
        previewOpen = open;
        previewBox.hidden = !open;
        fab.setAttribute('aria-expanded', String(open)); // 読み上げソフトに「開いている」を伝える
        fab.querySelector('.icon').textContent = open ? 'close' : 'visibility';
        fab.querySelector('[data-fab-text]').textContent = open ? '閉じる' : 'プレビュー';
        document.documentElement.classList.toggle('is-previewing', open); // 開いている間は後ろの画面をスクロールさせない
        if (open) {
            zoom = 1; // 開くたびに全体が見える大きさから
            previewCanvas.style.width = `${fitWidth()}px`;
            renderPreview();
        }
    }
    fab.addEventListener('click', () => setPreview(!previewOpen));
    // 入力中にプレビューのボタンを押しても、入力欄からフォーカスを外さない（キーボードを閉じない）。
    //   ボタンを押すとふつうはボタンにフォーカスが移ってキーボードが閉じ、ボタンの位置が飛んでしまう
    fab.addEventListener('pointerdown', (e) => {
        if (document.documentElement.classList.contains('is-typing')) {
            e.preventDefault();
        }
    });

    /* ---- スマホのキーボード ----
     *  キーボードが出ている間は html に is-typing を付け、キーボードの高さを --kb に入れる（CSS が使う）。
     *  visualViewport = 「実際に見えている範囲」。キーボードが出るとその分だけ高さ（height）が減る。
     *  window.innerHeight（ページの画面の高さ）から、見えている範囲の下の端（offsetTop + height）を引いた分がキーボード
     */
    const touchScreen = window.matchMedia('(hover: none) and (pointer: coarse)');
    const TYPING_INPUT = 'textarea, input:not([type="checkbox"], [type="radio"], [type="button"], [type="submit"], [type="reset"], [type="file"], [type="range"], [type="color"], [readonly])';
    function updateKeyboard() {
        const el = document.activeElement;
        const typing = touchScreen.matches && !!el && el.matches(TYPING_INPUT);
        const html = document.documentElement;
        if (typing && !html.classList.contains('is-typing')) {
            // 入力を始める直前（まだふつうの位置にある）のバーの左端を覚えておき、入力中も同じ横の位置に出す
            html.style.setProperty('--bar-left', `${Math.round($('.sheet-actions').getBoundingClientRect().left)}px`);
        }
        html.classList.toggle('is-typing', typing);
        const vv = window.visualViewport;
        const kb = typing && vv ? Math.max(0, window.innerHeight - (vv.offsetTop + vv.height)) : 0;
        document.documentElement.style.setProperty('--kb', `${Math.round(kb)}px`);
    }
    document.addEventListener('focusin', updateKeyboard);
    // focusout の瞬間は、まだ次の要素にフォーカスが移っていないので、少し待ってから調べる
    document.addEventListener('focusout', () => setTimeout(updateKeyboard, 0));
    if (window.visualViewport) {
        // キーボードが出る・引っ込む（resize）、見えている範囲が動く（scroll）たびに位置を合わせ直す
        window.visualViewport.addEventListener('resize', updateKeyboard);
        window.visualViewport.addEventListener('scroll', updateKeyboard);
    }
    // Esc キーでも閉じる（PC 用）
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && previewOpen) {
            setPreview(false);
        }
    });

    /* =================================================================
     *  7. 起動
     * ================================================================= */
    bindFields();
    bindStage();
    renderAll();
    if (restored) {
        $('[data-saved-note]').hidden = false;
    }

    /* ---- 型紙のお試し（?tpl_preview=1&embed=1）----
     *  型紙の編集画面（sheet_editor.js）が浮いた枠の中に <iframe> で開き、postMessage で「保存前の型紙」と PDF を送ってくる。
     *  受け取ったら __preview の型紙を差し替え、仮の値（sampleState）を入れて PDF のプレビューだけ見せる。DB には何も保存しない。
     *  送り主は「自分を埋め込んでいる画面（parent）」かつ同じサイト（origin）のときだけ信じる
     */

    /** 型紙のお試しに入れる仮の値（毎回同じ）。曲は型紙の行数ぶん、自由記入欄は欄の名前をそのまま書く */
    function sampleState() {
        const s = defaultState();
        Object.assign(s, { band: 'サンプルバンド', kana: 'サンプルバンド', date: '2026/10/12', order: '3', rep: '山田 太郎' });
        s.members = [
            ['Vo', '山田 太郎'], ['Gt', '佐藤 花子'], ['Gt', '鈴木 一郎'], ['Ba', '高橋 次郎'], ['Dr', '田中 三郎'], ['Key', '伊藤 四郎'],
        ].map(([part, name]) => ({ part, name }));
        // 書き込む表は、2組目まで全部の行を埋める（パートが印刷済みの表は上の6人を振り分けるだけ）
        const mt = tpl().members;
        if (mt.mode !== 'slots') {
            const sei = ['渡辺', '中村', '小林', '加藤', '吉田', '山口', '松本', '井上', '木村', '清水'];
            const mei = ['健太', '美咲', '翔', '結衣', '大輝', 'さくら', '蓮', '陽菜', '悠真', '凛'];
            const parts = ['Cho', 'Gt', 'Perc', 'Sax', 'Tp', 'Vn'];
            for (let k = 0; s.members.length < mt.rows * memberCols(mt).length; k++) {
                s.members.push({ part: parts[k % parts.length], name: `${sei[k % sei.length]} ${mei[k % mei.length]}` });
            }
        }
        const n = rowCount(tpl().setlist);
        // 音響・照明の要望: 1・3曲目は1行に収まる短いもの、2曲目は2行になる長めのもの
        //   （備考1列のシートは「音響 / 照明：…」とつなぐので、短い方は片方だけにして1行に収める）
        const notes = {
            1: ['リバーブ深め', ''],
            2: ['ギターソロで音量を上げてください。サビはコーラスを強めに', 'イントロは青一色、サビで全体を明るく。最後は暗転'],
            3: ['', '暗転から'],
        };
        s.songs = [];
        for (let i = 1; i < n; i++) {
            const [sound, light] = notes[i] || ['', ''];
            s.songs.push({ id: i, mc: false, title: `サンプル曲${i}`, sound, light });
            if (i === 1) s.songs.push({ id: 100, mc: true });
        }
        s.nextSongId = 101;
        for (const t of tpl().texts || []) {
            if (t.auto !== 'mic') s.texts[t.key] = t.digits ? '3' : t.label; // マイク計は空欄にして自動の値を出す
        }
        return s;
    }

    if (DATA.tplPreview) {
        let pdfUrl = null;
        let first = true;
        window.addEventListener('message', (e) => {
            if (e.origin !== location.origin || e.source !== window.parent || e.data?.type !== 'sheet-tpl') {
                return;
            }
            const { cfg, label, pdf, pdfPath, stageImage } = e.data;
            if (pdf) {
                if (pdfUrl) URL.revokeObjectURL(pdfUrl);
                pdfUrl = URL.createObjectURL(new Blob([pdf], { type: 'application/pdf' }));
            }
            const src = pdf ? pdfUrl : pdfPath;
            if (!src || !cfg?.setlist || !cfg?.members || !cfg?.stage) {
                previewBox.hidden = false;
                previewNote.textContent = '会場の PDF と座標を入れると、ここに出ます';
                return;
            }
            DATA.templates[DATA.templateKey] = { ...cfg, label: label || 'お試し', venue_words: [], pdf: src, stage_image: stageImage || cfg.stage_image };
            state = sampleState();
            if (tpl().members.mode === 'slots') {
                assignSlots(); // パートが印刷済みの表は、仮のメンバーを振り分ける
            }
            autoLayout(); // セット図も仮のメンバーから並べる
            delete $('[data-texts]').dataset.tpl;
            renderAll();
            if (first) {
                first = false;
                setPreview(true);
            } else {
                save(); // プレビューを作り直す（保存はしない）
            }
        });
        window.parent.postMessage({ type: 'sheet-tpl-ready' }, location.origin);
    }
})();
