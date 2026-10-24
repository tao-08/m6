/*
 * =====================================================================
 *  tab.js — 流れる TAB 譜（tab.php）の練習・編集
 * =====================================================================
 *  PHP（tab.php）が <script type="application/json" id="tab-data"> に入れたデータを読んで動く。
 *  大きく4つの部品でできている:
 *    1. 時計（clock）  … 音源が何秒目かを返すもの。YouTube / 手元のファイル / 音源なし の3種類を同じ形に揃えてある
 *    2. タイミング     … BPM と開始位置から「各小節が何秒に始まるか」を計算（タップで補正した小節はそれを優先）
 *    3. canvas         … 時計の時刻に合わせて TAB を描く（再生位置の線は左から25%に固定、譜面が左へ流れる）
 *    4. グリッド編集   … 弦 × マスのボタンを選んでフレットを打つ。保存は api_tab_save.php
 *
 *  XSS 対策: セクション名・パート名などユーザーが書いた文字は、textContent / value / canvas.fillText にだけ入れる。
 *            innerHTML には入れない（<script> と書かれても、ただの文字として表示される）。
 *  音源のファイルはサーバーに送らない（URL.createObjectURL でブラウザの中で再生するだけ）。
 * =====================================================================
 */
(() => {
  'use strict';

  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

  // 曲の一覧ページ: 削除した TAB の「復元」ボタン（post() / notify() は下にある関数宣言なので、ここから呼べる）
  document.querySelectorAll('[data-restore]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!window.confirm('復元しますか？')) return;
      btn.disabled = true;
      try {
        const json = await post({ action: 'restore', rev_id: Number(btn.dataset.restore) });
        window.location.href = json.redirect;
      } catch (err) {
        btn.disabled = false;
        notify(err.message || '復元できませんでした', 'error');
      }
    });
  });

  /* ---------------------------------------------------------------
   * メモの表示・画像の添付（TAB のメモ / 曲のメモ）。曲の一覧ページとプレイヤーの両方で使う
   *   画像は api_tab_image.php に fetch() で送る（保存・縮小・検査はサーバー）。見るときは attach_image?k=song_tab|song_meta&id=
   *   XSS 対策: メモの文字は textContent で入れ、URL の部分だけ <a> にする（innerHTML は使わない）
   * --------------------------------------------------------------- */
  /** text を el に入れる。http(s):// の URL はリンクにする（末尾の句読点・閉じ括弧はリンクに含めない） */
  function linkifyInto(el, text) {
    el.replaceChildren();
    let last = 0;
    for (const m of text.matchAll(/https?:\/\/[^\s<>　]+/g)) {
      const url = m[0].replace(/[.,;:!?)\]」』）、。]+$/u, '');
      let ok = true;
      try { ok = ['http:', 'https:'].includes(new URL(url).protocol); } catch (e) { ok = false; }
      if (!ok) continue;
      el.append(text.slice(last, m.index));
      const a = document.createElement('a');
      a.href = url;
      a.textContent = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer nofollow';
      el.appendChild(a);
      last = m.index + url.length;
    }
    el.append(text.slice(last));
  }

  /** 画像の大きいほうを、ページにある共通のモーダル（lib/attach_image.php の attach_modal_html）で開く */
  function openImage(attachKind, id) {
    const modal = document.querySelector('[data-live-modal]');
    if (!modal) return;
    modal.querySelector('[data-live-modal-img]').src = `attach_image?k=${attachKind}&id=${id}`;
    modal.querySelector('[data-live-modal-dl]').href = `attach_image?k=${attachKind}&id=${id}&dl=1`;
    modal.querySelector('[data-live-modal-taken]').textContent = '';
    modal.showModal();
  }

  /**
   * 画像の並び（サムネイル + 追加ボタン + 削除）。kind = 'tab'（id = tab_id）/ 'song'（id = 曲キー）
   *   戻り値の setEditable(true) で追加・削除のボタンが出る。ids は今ある画像の ID
   */
  function imageBox({ kind, id, ids, list, addLabel, max }) {
    const attachKind = kind === 'tab' ? 'song_tab' : 'song_meta';
    const input = addLabel.querySelector('[data-img-input]');
    let editable = false;
    const box = { ids: ids.slice(), onChange: null };
    const send = async (fd) => {
      fd.append('kind', kind);
      fd.append('id', String(id));
      const res = await fetch('api_tab_image', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': token }, body: fd });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.error || '画像を保存できませんでした');
      box.ids = json.images;
      render();
      if (box.onChange) box.onChange();
    };
    function render() {
      list.replaceChildren();
      box.ids.forEach((imgId) => {
        const li = document.createElement('li');
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'live-thumbs__btn';
        open.setAttribute('aria-label', '画像を表示');
        const img = document.createElement('img');
        img.src = `attach_image?k=${attachKind}&id=${imgId}&t=1`;
        img.alt = '';
        img.loading = 'lazy';
        open.appendChild(img);
        open.addEventListener('click', () => openImage(attachKind, imgId));
        li.appendChild(open);
        if (editable) {
          const del = document.createElement('button');
          del.type = 'button';
          del.className = 'tab-imgs__del';
          del.title = '削除';
          del.setAttribute('aria-label', '削除');
          del.textContent = '×';
          del.addEventListener('click', async () => {
            if (!window.confirm('削除しますか？')) return;
            const fd = new FormData();
            fd.append('action', 'remove');
            fd.append('image_id', String(imgId));
            try { await send(fd); } catch (err) { notify(err.message, 'error'); }
          });
          li.appendChild(del);
        }
        list.appendChild(li);
      });
      addLabel.hidden = !editable || box.ids.length >= max;
    }
    input.addEventListener('change', async () => {
      if (!input.files.length) return;
      const fd = new FormData();
      fd.append('action', 'add');
      [...input.files].forEach((f) => fd.append('images[]', f));
      input.value = '';
      try { await send(fd); } catch (err) { notify(err.message, 'error'); }
    });
    box.setEditable = (on) => { editable = on; render(); };
    render();
    return box;
  }

  // 曲の一覧ページ: 曲のメモ（全パート共通）と画像。メモは「保存」で、画像は選んだらすぐ送る
  const songDataEl = document.getElementById('song-data');
  if (songDataEl) {
    const S = JSON.parse(songDataEl.textContent);
    const area = document.querySelector('[data-song-memo]');
    const text = area.querySelector('[data-song-memo-text]');
    imageBox({ kind: 'song', id: S.key, ids: S.images, list: area.querySelector('[data-song-images]'), addLabel: area.querySelector('[data-img-add]'), max: 10 }).setEditable(true);
    area.querySelector('[data-song-memo-save]').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await post({ action: 'song_memo', song_key: S.key, memo: text.value });
        notify('保存しました');
      } catch (err) {
        notify(err.message || '保存できませんでした', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  }

  const dataEl = document.getElementById('tab-data');
  const root = document.querySelector('[data-tab]');
  if (!dataEl || !root) return;
  const D = JSON.parse(dataEl.textContent);
  const $ = (sel, el = root) => el.querySelector(sel);
  const $$ = (sel, el = root) => [...el.querySelectorAll(sel)];
  const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

  /* ---- 説明の表示（？ボタン）----
   *   アイコンだけのボタンは、スマホだと初めての人には意味が分からない（title のポップアップはタップでは出ない）。
   *   ？を押すと root に is-help が付き、tab.css が各ボタンの aria-label / title をボタンの下に文字で出す。
   *   文字は CSS の attr() で出すので、あとから JS が作るボタン（グリッド・キーボード）にも自動で付く。
   *   初めて開いたときは最初から ON。押した結果は端末に覚えておく */
  (function setupHelp() {
    const btn = $('[data-help-toggle]');
    if (!btn) return;
    let on = true;
    try { on = localStorage.getItem('tabHelp') !== '0'; } catch (e) { /* 保存できなくても動く（毎回 ON） */ }
    const apply = () => {
      root.classList.toggle('is-help', on);
      btn.setAttribute('aria-pressed', String(on));
      btn.classList.toggle('is-active', on);
    };
    apply();
    btn.addEventListener('click', () => {
      on = !on;
      apply();
      try { localStorage.setItem('tabHelp', on ? '1' : '0'); } catch (e) { /* 無視 */ }
      window.dispatchEvent(new Event('resize')); // ボタンの高さが変わるので canvas の大きさを測り直させる
    });
  })();

  const MAX_BARS = 400;
  const MAX_FRET = 24;
  const SECTION_MAX = 20;
  const NOTE_RE = /^[A-G][#b]?$/;

  /* <pure>
   * ---------------------------------------------------------------
   * 時刻の計算（DOM も時計も使わない純粋な関数）。
   * tests 用の node スクリプトが、この <pure> 〜 </pure> の間を取り出して、そのまま動かして確かめる
   *   時刻は全部「元のテンポの TAB の時間軸（秒）」。練習 BPM で遅くしても、この時間軸の数字は変わらず、進む速さ（rate）だけが変わる
   * ---------------------------------------------------------------
   */
  /** 各小節の頭の時刻 starts（最後に曲の終わりも入る）と、小節の長さ durs。bars[i].t（タップ・ミリ秒）があればそれを優先 */
  function computeTimeline(bars, beats, bpm, offsetMs) {
    const n = bars.length;
    const bd = beats * 60 / bpm;
    const starts = new Array(n + 1);
    let lastTap = -1;
    for (let i = 0; i < n; i++) {
      if (bars[i].t !== null) { starts[i] = bars[i].t / 1000; lastTap = i; }
      else if (lastTap >= 0) starts[i] = starts[lastTap] + (i - lastTap) * bd;
      else starts[i] = offsetMs / 1000 + i * bd;
    }
    starts[n] = starts[n - 1] + bd;
    const durs = [];
    for (let i = 0; i < n; i++) {
      const d = starts[i + 1] - starts[i];
      durs.push(d > 0.05 ? d : bd);      // タップの順番が逆などで長さが負になったら、BPM の長さで数える
    }
    return { starts, durs };
  }
  /** time（秒）がある小節の番号。最初の小節より前なら -1 */
  function barIndexAt(starts, n, time) {
    if (time < starts[0]) return -1;
    let lo = 0, hi = n - 1;
    while (lo < hi) {
      const mid = (lo + hi + 1) >> 1;
      if (starts[mid] <= time) lo = mid; else hi = mid - 1;
    }
    return lo;
  }
  /**
   * メトロノームのクリックの時刻（from より後、to まで）。[{t, kind}]  kind = accent（1拍目）/ beat / sub（細分）
   *   拍の位置は小節の長さを拍数で割ったもの（タップで補正した小節はその長さで）。sub = 1拍を何個に割るか（1 = 細分なし）
   */
  function beatSchedule(tl, beats, sub, from, to) {
    const out = [];
    const n = tl.durs.length;
    for (let i = Math.max(0, barIndexAt(tl.starts, n, from)); i < n && tl.starts[i] <= to; i++) {
      const bd = tl.durs[i] / beats;
      for (let b = 0; b < beats; b++) {
        for (let j = 0; j < sub; j++) {
          const t = tl.starts[i] + b * bd + j * bd / sub;
          if (t > from && t <= to) out.push({ t, kind: j > 0 ? 'sub' : b === 0 ? 'accent' : 'beat' });
        }
      }
    }
    return out;
  }
  /** TAB の時刻 musicTime を、AudioContext の時刻にする（now = 今の TAB の時刻、rate = 再生の速さ。遅いほどクリックの間隔が空く） */
  function audioTimeFor(ctxNow, musicTime, nowMusic, rate) { return ctxNow + Math.max(0, (musicTime - nowMusic) / rate); }
  /** 内部時計: 基準の時刻から、実際に経った秒 × 速さ だけ進んだ TAB の時刻（同期解除中の位置） */
  function internalTime(base, elapsedSec, rate) { return base + elapsedSec * rate; }
  /** BPM 計測: タップした時刻（ミリ秒）から BPM。4回未満は null。中央値から ±25% を外れた間隔（打ち間違い）は捨てて平均する */
  function tapTempoBpm(stamps) {
    if (stamps.length < 4) return null;
    const iv = [];
    for (let i = 1; i < stamps.length; i++) iv.push(stamps[i] - stamps[i - 1]);
    const sorted = iv.slice().sort((a, b) => a - b);
    const med = sorted[Math.floor(sorted.length / 2)];
    const good = iv.filter((x) => Math.abs(x - med) <= med * 0.25);
    const use = good.length ? good : iv;
    const bpm = 60000 / (use.reduce((a, b) => a + b, 0) / use.length);
    return bpm >= 20 && bpm <= 400 ? Math.round(bpm * 100) / 100 : null;
  }
  /**
   * ASCII の TAB（`e|--3--5-|` の形）を、小節と音符にする。弦の数が合わない・TAB の行が無いときは error に理由。
   *   上の行 = 1弦（一番高い弦）。`|` で小節を区切る。2桁のフレット・x（ミュート）を読む。h p s b / \ ~ ^ などの奏法の記号は無視して数字だけ使う
   *   音符の位置は、小節の中の文字の位置を stepsPerBar マスに丸めて決める
   * @returns {{bars: {n: number[][]}[], warnings: string[], error: string|null}}
   */
  function parseAsciiTab(text, strings, stepsPerBar) {
    const lines = text.replace(/\r\n?/g, '\n').split('\n');
    const isTabLine = (l) => /^\s*[A-Ga-g]?[#b]?\s*\|/.test(l) && /[-\d]/.test(l.slice(l.indexOf('|') + 1));
    const runs = [];
    let run = [];
    for (const l of lines) {
      if (isTabLine(l)) run.push(l);
      else { if (run.length) runs.push(run); run = []; }
    }
    if (run.length) runs.push(run);
    if (!runs.length) return { bars: [], warnings: [], error: 'TAB の行（e|--3--5-| の形）が見つかりません' };
    const bars = [];
    const warnings = [];
    for (const r of runs) {
      if (r.length % strings !== 0) return { bars: [], warnings, error: `${strings}弦の TAB ではありません（${r.length}行）` };
      for (let g = 0; g < r.length; g += strings) {
        const rows = r.slice(g, g + strings).map((l) => l.slice(l.indexOf('|') + 1));
        const width = Math.max(...rows.map((x) => x.length));
        // 小節線: 半分以上の弦の同じ位置にある |
        const barCols = [];
        for (let x = 0; x < width; x++) if (rows.filter((row) => row[x] === '|').length * 2 >= strings) barCols.push(x);
        const edges = [-1, ...barCols, width];
        for (let e = 0; e + 1 < edges.length; e++) {
          const a = edges[e] + 1, b = edges[e + 1];
          if (b - a < 1) continue; // || の間など
          const notes = {};
          rows.forEach((row, str) => {
            for (let x = a; x < b && x < row.length; x++) {
              const ch = row[x];
              let fret = null;
              let at = x;
              if (/\d/.test(ch)) {
                let num = ch;
                if (/\d/.test(row[x + 1] || '') && x + 1 < b) { num += row[x + 1]; x++; }
                fret = Number(num);
                if (fret > 24) { warnings.push(`${fret} フレットは読めませんでした`); fret = null; }
              } else if (ch === 'x' || ch === 'X') fret = -1;
              if (fret !== null) notes[Math.min(stepsPerBar - 1, Math.round((at - a) / (b - a) * stepsPerBar)) * 100 + str] = fret;
            }
          });
          const n = Object.keys(notes).map(Number).sort((p, q) => p - q).map((k) => [Math.floor(k / 100), k % 100, notes[k]]);
          if (n.length || b - a >= 4) bars.push({ n });
        }
      }
    }
    return { bars, warnings: [...new Set(warnings)], error: bars.length ? null : '音符が見つかりませんでした' };
  }

  /**
   * コード名を semis 半音上げる（カポの音: カポ2なら2半音上が実際に鳴るコード）。N.C. はそのまま
   *   元の書き方（ルートに b が付いていれば フラット、それ以外はシャープ）に合わせて綴る。オンコード（/ のあと）も同じ
   */
  function transposeChord(name, semis) {
    if (name === 'N.C.' || semis % 12 === 0) return name;
    const SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    const FLAT = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];
    const m = name.match(/^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/);
    if (!m) return name;
    const flats = m[1].includes('b');
    const move = (note) => {
      const i = Math.max(SHARP.indexOf(note), FLAT.indexOf(note));
      return (flats ? FLAT : SHARP)[(((i + semis) % 12) + 12) % 12];
    };
    return move(m[1]) + m[2] + (m[3] ? '/' + move(m[3]) : '');
  }
  /* </pure> */

  /* ---------------------------------------------------------------
   * 状態
   *   bars[i] = {s: セクション名, t: タップした位置(ms) か null, n: {step*8+string: fret}}
   *   音符のキーを step*8+string にしているのは、弦が7本までなので1つの数字にできるから（同じ位置は上書きになる）
   *   string は 0 = 1弦 = 一番高い弦 = 画面の一番上
   * --------------------------------------------------------------- */
  const st = {
    part: D.part,
    tuning: D.tuning.slice(),            // 低い弦 → 高い弦
    bpm: D.bpm,
    beats: D.beats_per_bar,
    steps: D.steps_per_beat,
    offsetMs: D.offset_ms,
    youtubeUrl: D.youtube_url || '',
    memo: D.memo || '',                  // この TAB のメモ
    firstBar: D.first_bar || 1,          // 小節番号の最初（部分 TAB は途中の小節から）
    partial: !!D.is_partial,
    capo: D.capo || 0,                   // フレットはカポを基準に書く（カポ2なら、カポの位置が0）
    links: (D.links || []).map((l) => ({ u: l.u, l: l.l || '' })), // 参考リンク [{u: URL, l: 表示名}]
    bars: [],
  };
  const strings = st.tuning.length;
  const isDrum = D.kind === 'drum';        // ドラム譜: string = レーン（上から。tuning にレーンの略号）、fret = 奏法 1 通常 / 2 アクセント / 3 ゴースト / 4 オープン（HH のみ）
  const DRUM_HH = 2;                       // レーンの中の HH の位置（lib/tabs.php の TAB_DRUM_HH）
  // r = 0 が1弦（一番高い弦）= 画面の一番上。ドラムは tuning がそのまま上から下。弦名・レーン名の表示はすべてこれを通す
  const stringNote = (r) => (isDrum ? st.tuning[r] : st.tuning[strings - 1 - r]);
  const chordRe = new RegExp(D.chord_re);  // コード名の形（PHP の TAB_CHORD_RE と同じ）
  let updatedAt = D.updated_at;
  let editMode = false;
  let sel = null;                        // 選択中のマス {b, s, r}

  /** 小節 {s: セクション名, t: タップ(ms), n: {step*8+弦: フレット}, d: {同じキー: 長さ（マス。1 は省略）}, r: [[step, 長さ]] 休符, c: {step: コード名}} */
  const newBar = () => ({ s: '', t: null, n: {}, d: {}, r: [], c: {} });
  const barFromJson = (b) => {
    const n = {}, d = {}, c = {};
    (b.n || []).forEach(([step, string, fret, dur]) => { n[step * 8 + string] = fret; if (dur > 1) d[step * 8 + string] = dur; });
    (b.c || []).forEach(([step, name]) => { c[step] = name; });
    return { s: b.s || '', t: typeof b.t === 'number' ? b.t : null, n, d, r: (b.r || []).map(([a, l]) => [a, l]), c };
  };
  st.bars = (D.data.bars || []).map(barFromJson);
  if (st.bars.length === 0) st.bars.push(newBar());

  const serializeBars = () => st.bars.map((b) => {
    const o = {};
    if (b.s) o.s = b.s;
    if (b.t !== null) o.t = b.t;
    o.n = Object.keys(b.n).map(Number).sort((x, y) => x - y).map((k) => (b.d[k] > 1 ? [k >> 3, k & 7, b.n[k], b.d[k]] : [k >> 3, k & 7, b.n[k]]));
    if (b.r.length) o.r = b.r.slice().sort((x, y) => x[0] - y[0]).map(([a, l]) => [a, l]);
    const cs = Object.keys(b.c).map(Number).sort((x, y) => x - y);
    if (cs.length && !isDrum) o.c = cs.map((a) => [a, b.c[a]]); // コード譜（ドラム譜には付けない）
    return o;
  });
  const stepsPerBar = () => st.beats * st.steps;

  /** 保存する中身（api_tab_save.php に送る形） */
  const payload = () => ({
    part: st.part,
    tuning: st.tuning.join(' '),
    bpm: st.bpm,
    beats_per_bar: st.beats,
    steps_per_beat: st.steps,
    offset_ms: st.offsetMs,
    memo: st.memo,
    first_bar: st.firstBar,
    is_partial: st.partial,
    capo: st.capo,
    links: st.links.filter((l) => l.u.trim() !== '').map((l) => (l.l.trim() !== '' ? { u: l.u.trim(), l: l.l.trim() } : { u: l.u.trim() })),
    youtube_url: st.youtubeUrl.trim(),
    data: { bars: serializeBars() },
  });
  let savedStr = JSON.stringify(payload());
  let leaving = false;                   // 保存して / 削除して移動するときは警告しない

  /* ---------------------------------------------------------------
   * 1. 時計
   *   どれも同じ形: time()（秒）/ playing / play() / pause() / seek(秒) / setRate(倍速) / rate / destroy()
   *   time() は描画のたびに呼ばれる。音源が返す時刻は粗い（YouTube は 0.25 秒おきなど）ので、
   *   前回の時刻 + 経過時間 で補間し、新しい実測値が 0.15 秒以上ずれたときだけ合わせ直す（smoother）
   * --------------------------------------------------------------- */
  function smoother(raw, isPlaying, getRate, tol) {
    let base = 0, baseAt = performance.now(), lastRaw = NaN, was = false;
    return {
      time() {
        const now = performance.now();
        const r = raw();
        if (!isPlaying()) { base = r; baseAt = now; lastRaw = r; was = false; return r; }
        if (!was) { was = true; base = r; baseAt = now; lastRaw = r; return r; } // 再生を始めた瞬間
        const pred = base + (now - baseAt) / 1000 * getRate();
        if (r !== lastRaw) {             // 新しい実測値が届いたときだけ見比べる
          lastRaw = r;
          if (Math.abs(r - pred) > tol) { base = r; baseAt = now; return r; }
        }
        return pred;
      },
      reset() { lastRaw = NaN; was = false; },
    };
  }

  /** 音源なし: performance.now() だけで進む内部時計（BPM だけで練習） */
  function internalClock(onChange) {
    let base = 0, at = performance.now(), playing = false, rate = 1;
    const time = () => (playing ? base + (performance.now() - at) / 1000 * rate : base);
    return {
      kind: 'none',
      time,
      get playing() { return playing; },
      get rate() { return rate; },
      play() { if (!playing) { at = performance.now(); playing = true; onChange(); } },
      pause() { if (playing) { base = time(); playing = false; onChange(); } },
      seek(s) { base = Math.max(0, s); at = performance.now(); },
      setRate(r) { base = time(); at = performance.now(); rate = r; },
      destroy() { playing = false; },
    };
  }

  /** 手元の音声 / 動画ファイル: <audio> で再生する。サーバーには送らない */
  function fileClock(file, onChange) {
    const url = URL.createObjectURL(file);
    const a = new Audio();
    a.preload = 'auto';
    a.src = url;
    a.preservesPitch = true;             // 速度を落としても音程は変えない（古いブラウザ用に接頭辞付きも）
    a.mozPreservesPitch = true;
    a.webkitPreservesPitch = true;
    const isPlaying = () => !a.paused && !a.ended;
    const sm = smoother(() => a.currentTime, isPlaying, () => a.playbackRate, 0.08);
    ['play', 'pause', 'ended', 'seeked', 'ratechange'].forEach((ev) => a.addEventListener(ev, () => { sm.reset(); onChange(); }));
    return {
      kind: 'file',
      time: () => sm.time(),
      get playing() { return isPlaying(); },
      get rate() { return a.playbackRate; },
      play() { a.play().catch(() => {}); },
      pause() { a.pause(); },
      seek(s) { a.currentTime = Math.max(0, s); sm.reset(); },
      setRate(r) { a.playbackRate = r; },
      destroy() { a.pause(); a.removeAttribute('src'); a.load(); URL.revokeObjectURL(url); },
    };
  }

  /** YouTube の IFrame API を1回だけ読み込む */
  let ytApi = null;
  function loadYouTubeApi() {
    if (ytApi) return ytApi;
    ytApi = new Promise((resolve) => {
      if (window.YT && window.YT.Player) { resolve(); return; }
      const prev = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = () => { if (prev) prev(); resolve(); };
      const s = document.createElement('script');
      s.src = 'https://www.youtube.com/iframe_api';
      document.head.appendChild(s);
    });
    return ytApi;
  }

  /** YouTube 埋め込み。プレイヤーの準備ができる前の操作は、準備できてから実行する */
  function youtubeClock(videoId, mount, onChange) {
    let player = null, ready = false, state = -1, rate = 1, dead = false;
    const queued = [];
    const run = (fn) => (ready ? fn() : queued.push(fn));
    const sm = smoother(() => (ready ? player.getCurrentTime() || 0 : 0), () => state === 1, () => rate, 0.15);
    loadYouTubeApi().then(() => {
      if (dead) return;
      player = new window.YT.Player(mount, {
        videoId,
        width: '100%',
        height: '100%',
        playerVars: { playsinline: 1, rel: 0 },
        events: {
          onReady: () => { ready = true; queued.splice(0).forEach((f) => f()); onChange(); },
          onStateChange: (e) => { state = e.data; sm.reset(); onChange(); },
          // YouTube が使える速度は決まっている（0.9 は無い）ので、実際に効いた速度を覚える
          onPlaybackRateChange: () => { rate = player.getPlaybackRate() || 1; onChange(); },
        },
      });
    });
    return {
      kind: 'yt',
      time: () => sm.time(),
      get playing() { return state === 1 || state === 3; }, // 3 = 読み込み中（再生待ち）
      get rate() { return rate; },
      play() { run(() => player.playVideo()); },
      pause() { run(() => player.pauseVideo()); },
      seek(s) { run(() => player.seekTo(Math.max(0, s), true)); sm.reset(); },
      // YouTube が使える速度は決まっている（0.25 / 0.5 / 0.75 / 1 …）。いちばん近いものに丸めて、実際に効いた速度を覚える
      setRate(r) {
        run(() => {
          const avail = player.getAvailablePlaybackRates ? player.getAvailablePlaybackRates() : [r];
          const snap = avail.reduce((a, b) => (Math.abs(b - r) < Math.abs(a - r) ? b : a), avail[0]);
          player.setPlaybackRate(snap);
          rate = player.getPlaybackRate() || snap;
          onChange();
        });
      },
      destroy() { dead = true; try { if (player && player.destroy) player.destroy(); } catch (e) { /* 閉じるだけなので無視 */ } },
    };
  }

  /** YouTube の URL → 動画 ID（api_tab_save.php の youtube_video_id() と同じ条件。不正なら null） */
  function youtubeIdOf(url) {
    try {
      const u = new URL(url.trim());
      if (u.protocol !== 'https:') return null;
      const host = u.hostname.toLowerCase();
      let id = '';
      if (host === 'youtu.be') id = u.pathname.slice(1);
      else if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'].includes(host)) id = u.searchParams.get('v') || '';
      return /^[A-Za-z0-9_-]{11}$/.test(id) ? id : null;
    } catch (e) {
      return null;
    }
  }

  let clock = null;
  let srcKind = 'none';
  let ytId = youtubeIdOf(st.youtubeUrl);
  /*
   * 練習 BPM（自分だけの設定。song_tab_practice に自動保存）
   *   pbpm = null … 元の BPM のまま。再生速度 = 練習 BPM ÷ 元の BPM（0.25〜2 倍）
   *   音源は速度を変えても音程は変えない（audio.preservesPitch）。メトロノームと流れる譜面は音源の時刻（clock.time()）で動くので、
   *   速度が変わっても音とずれない
   */
  let practice = D.practice || { m: [], ab: null, bpm: null };
  let pbpm = practice.bpm;
  const currentRate = () => (pbpm === null ? 1 : clamp(pbpm / st.bpm, 0.25, 2));
  const videoBox = $('[data-video]');
  const videoMount = $('[data-video-mount]');
  const fileInput = $('[data-file]');
  const playBtn = $('[data-play]');

  /** 音源を切り替える。再生位置と速度は引き継ぐ */
  function setSource(kind, file) {
    const prevTime = clock ? clock.time() : 0;
    if (unsynced) { mediaClock.destroy(); mediaClock = null; unsynced = false; } // 同期解除中に別の音源を選んだ: 前のメディアは捨てる
    if (clock) clock.destroy();
    videoMount.replaceChildren();
    if (kind === 'yt' && ytId) {
      const m = document.createElement('div'); // YouTube がこの要素を <iframe> に置き換える
      videoMount.appendChild(m);
      clock = youtubeClock(ytId, m, onClockChange);
    } else if (kind === 'file' && file) {
      clock = fileClock(file, onClockChange);
    } else {
      kind = 'none';
      clock = internalClock(onClockChange);
    }
    srcKind = kind;
    videoBox.hidden = kind !== 'yt';
    clock.setRate(currentRate());
    if (prevTime > 0) clock.seek(prevTime);
    $$('[data-src]').forEach((b) => b.classList.toggle('is-active', b.dataset.src === kind));
    $('[data-src="yt"]').disabled = !ytId;
    metroReset();
    updateUnsyncBtn();
    onClockChange();
  }

  /*
   * 同期解除: YouTube / ファイルの音は止めて（動画は隠して）、内部時計に切り替える。TAB は今の位置からそのまま進むので、
   * 音源に縛られずに好きな練習 BPM（YouTube の速度の刻みに丸められない）で練習できる。
   *   時刻はどちらも「元のテンポの TAB の時間軸（秒）」なので、切り替えのときに換算は要らない。
   *   同期に戻すときは、内部時計の今の時刻へメディアを seek する（遅くして練習した分も、時間軸の上では進んだ分だけ進んでいる）
   */
  let unsynced = false;
  let mediaClock = null;
  const unsyncBtn = $('[data-unsync]');
  function updateUnsyncBtn() {
    unsyncBtn.disabled = srcKind === 'none';
    unsyncBtn.classList.toggle('is-active', unsynced);
    unsyncBtn.setAttribute('aria-pressed', String(unsynced));
    const label = unsynced ? '同期' : '同期解除';
    unsyncBtn.setAttribute('aria-label', label);
    unsyncBtn.title = label;
    $('.icon', unsyncBtn).textContent = unsynced ? 'link_off' : 'link';
  }
  function setUnsync(on) {
    if (on === unsynced || srcKind === 'none') return;
    if (counting) cancelCountIn();
    const was = clock.playing;
    const t = clock.time();
    if (on) {
      mediaClock = clock;
      mediaClock.pause();
      videoBox.hidden = true;
      clock = internalClock(onClockChange);
      clock.setRate(currentRate());
      clock.seek(t);
      unsynced = true;
    } else {
      clock.pause();
      clock.destroy();
      clock = mediaClock;
      mediaClock = null;
      unsynced = false;
      videoBox.hidden = srcKind !== 'yt';
      clock.setRate(currentRate());
      clock.seek(t);
    }
    if (was) clock.play();
    metroReset();
    updateUnsyncBtn();
    onClockChange();
  }

  function onClockChange() {
    const on = clock.playing || counting;
    const icon = $('.icon', playBtn);
    icon.textContent = on ? 'pause' : 'play_arrow';
    playBtn.setAttribute('aria-label', on ? '一時停止' : '再生');
    playBtn.title = (on ? '一時停止' : '再生') + '（Space）';
    $$('[data-rate]').forEach((b) => b.classList.toggle('is-active', Math.abs(Number(b.dataset.rate) - clock.rate) < 0.01));
    syncPbpmField();
    requestFrame();
  }

  /** 練習 BPM の欄: 実際に効いている速度から出す（YouTube で丸められたらその BPM）。入力中は上書きしない */
  const pbpmEl = $('[data-pbpm]');
  function syncPbpmField() {
    if (document.activeElement === pbpmEl) return;
    const r = clock.kind === 'yt' ? clock.rate : currentRate();
    pbpmEl.value = String(Math.round(st.bpm * r * 10) / 10);
  }
  /** 練習 BPM を変えた: 音源の速度に反映して、自動保存の予約 */
  function applyRate() {
    clock.setRate(currentRate());
    metroReset();
    onClockChange();
    schedulePracticeSave();
  }
  function setPracticeBpm(v) {
    pbpm = v === null || Math.abs(v - st.bpm) < 0.05 ? null : clamp(v, Math.max(20, st.bpm * 0.25), Math.min(400, st.bpm * 2));
    applyRate();
  }
  function togglePlay() {
    ensureAudio();                       // メトロノームの音は、最初の操作（クリック）のあとでないと鳴らせない
    if (counting) { cancelCountIn(); return; }
    if (clock.playing) { clock.pause(); return; }
    if (metro.count > 0 && audioCtx) startCountIn(); else clock.play(); // 止まっているところから始めるときだけカウントイン
  }

  /* ---------------------------------------------------------------
   * 2. タイミング
   *   starts[i] … i 小節目が始まる秒。bars[i].t（タップ）があればそれ。無ければ直前のタップ済みの小節から BPM で数える
   *   durs[i]   … i 小節目の長さ。次の小節の頭までの長さ（タップで補正した分だけ伸び縮みする）
   * --------------------------------------------------------------- */
  let starts = [], durs = [];
  let chordList = [];                    // [{t, name}] 時刻の順
  /** t（秒）に鳴っているコードの番号（まだ最初のコードの前なら -1） */
  function chordNowIndex(t) {
    let lo = -1;
    for (let k = 0; k < chordList.length && chordList[k].t <= t + 1e-6; k++) lo = k;
    return lo;
  }
  function recompute() {
    ({ starts, durs } = computeTimeline(st.bars, st.beats, st.bpm, st.offsetMs));
    // コードの時刻の一覧（いまのコードを探すため）。コードがあるときだけ、弦の位置を下げて、コードの行を空ける
    chordList = [];
    st.bars.forEach((bar, i) => Object.keys(bar.c).forEach((k) => {
      chordList.push({ t: starts[i] + Number(k) / stepsPerBar() * durs[i], name: bar.c[k] });
    }));
    chordList.sort((a, b) => a.t - b.t);
    const nextY0 = !isDrum && chordList.length ? 64 : 46;
    if (nextY0 !== Y0) { Y0 = nextY0; if (typeof resize === 'function' && cssW) resize(); }
    computeSections();
    renderSectionChips();
  }

  /** time（秒）がある小節の番号。最初の小節より前なら -1 */
  function barAt(time) { return barIndexAt(starts, st.bars.length, time); }
  const noteTime = (i, step) => starts[i] + step / stepsPerBar() * durs[i];

  /* ---------------------------------------------------------------
   * 曲のマーカー（セクション。全員で共有）
   *   小節のセクション名（bars[i].s）が付いている小節から、次にセクション名が付いている小節の手前までが1つのセクション。
   *   色: よく使う名前は固定の色。それ以外の自由な名前は、名前から決まる色（同じ名前なら必ず同じ色）。
   *   明るさ・彩度は中間にして、ライト・ダークどちらの背景でも見えるようにしてある（帯は透かして塗る）
   * --------------------------------------------------------------- */
  const SECTION_PRESETS = ['イントロ', 'Aメロ', 'Bメロ', 'サビ', 'Cメロ', '間奏', 'ソロ', 'アウトロ'];
  const SECTION_HUES = { 'イントロ': 205, 'Aメロ': 145, 'Bメロ': 48, 'サビ': 350, 'Cメロ': 285, '間奏': 25, 'ソロ': 265, 'アウトロ': 180 };
  const HASH_HUES = [10, 70, 100, 170, 230, 300, 330, 120];
  function sectionHue(name) {
    if (Object.prototype.hasOwnProperty.call(SECTION_HUES, name)) return SECTION_HUES[name];
    let h = 0;
    for (const ch of name) h = (h * 31 + ch.codePointAt(0)) >>> 0;
    return HASH_HUES[h % HASH_HUES.length];
  }
  const secColor = (name, alpha) => `hsl(${sectionHue(name)} 60% 48%${alpha === undefined ? '' : ' / ' + alpha})`;

  let sections = []; // [{name, from, to}] to は含まない（最後のセクションは小節数）
  let secOf = [];    // 小節の番号 → sections の番号（セクションの前なら -1）
  function computeSections() {
    const n = st.bars.length;
    sections = [];
    secOf = new Array(n).fill(-1);
    st.bars.forEach((b, i) => {
      const name = b.s.trim();
      if (name === '') return;
      if (sections.length) sections[sections.length - 1].to = i;
      sections.push({ name, from: i, to: n });
    });
    sections.forEach((sec, k) => { for (let i = sec.from; i < sec.to; i++) secOf[i] = k; });
  }

  /** 画面上のセクションの一覧（押すとその位置へ。ループのアイコンはそのセクションだけを繰り返す） */
  const secBox = $('[data-sections]');
  let secSig = '';
  function renderSectionChips() {
    const sig = JSON.stringify(sections);
    if (sig === secSig) return;
    secSig = sig;
    secBox.replaceChildren();
    secBox.hidden = sections.length === 0;
    sections.forEach((sec) => {
      const wrap = document.createElement('span');
      wrap.className = 'tab-sec';
      wrap.style.setProperty('--sec', secColor(sec.name));
      const go = document.createElement('button');
      go.type = 'button';
      go.className = 'tab-sec__go';
      go.textContent = sec.name;
      go.title = '移動';
      go.addEventListener('click', () => seekTo(starts[sec.from]));
      const lp = iconBtn('loop', 'repeat', 'ループ');
      lp.className = 'tab-sec__loop';
      lp.addEventListener('click', () => { setLoop(starts[sec.from], starts[sec.to]); seekTo(starts[sec.from]); });
      wrap.append(go, lp);
      secBox.appendChild(wrap);
    });
  }

  /** 編集グリッドの小節見出しに、セクションの色を付け直す */
  function tintBars() {
    [...grid.children].forEach((box, i) => {
      if (secOf[i] >= 0) box.style.setProperty('--sec', secColor(sections[secOf[i]].name));
      else box.style.removeProperty('--sec');
    });
  }

  /** 編集画面のセクション候補（選んだ小節 / 再生中の小節に付ける。もう一度押すと外す） */
  const secPick = $('[data-secpick]');
  SECTION_PRESETS.forEach((name) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'tab-chip';
    b.dataset.sec = name;
    b.textContent = name;
    b.style.setProperty('--sec', secColor(name));
    secPick.appendChild(b);
  });
  const targetBar = () => (sel ? sel.b : Math.max(0, clock ? barAt(clock.time()) : 0));
  function updateSecPick() {
    const cur = st.bars[targetBar()]?.s.trim();
    $$('[data-sec]', secPick).forEach((b) => b.classList.toggle('is-active', b.dataset.sec === cur));
  }
  secPick.addEventListener('click', (e) => {
    const b = e.target.closest('[data-sec]');
    if (!b) return;
    const i = targetBar();
    st.bars[i].s = st.bars[i].s.trim() === b.dataset.sec ? '' : b.dataset.sec;
    const input = grid.children[i]?.querySelector('.tg-bar__sec');
    if (input) input.value = st.bars[i].s;
    recompute();
    tintBars();
    updateSecPick();
    commit();
    requestFrame();
  });

  /* ---------------------------------------------------------------
   * A-B リピートと練習用マーカー（自分だけ。song_tab_practice に約1秒後に自動保存。TAB の編集履歴には入れない）
   * --------------------------------------------------------------- */
  function updateAbButtons() {
    $$('[data-ab]').forEach((b) => b.classList.toggle('is-active', (b.dataset.ab === 'a' && loop.a !== null) || (b.dataset.ab === 'b' && loop.b !== null)));
  }
  function setLoop(a, b) {
    loop = { a, b };
    updateAbButtons();
    schedulePracticeSave();
    requestFrame();
  }
  /** which = 'a' | 'b' | null（解除）。今の位置を A / B にする */
  function setLoopPoint(which) {
    const t = clock.time();
    if (which === 'a') setLoop(t, loop.b !== null && loop.b > t ? loop.b : null);
    else if (which === 'b') setLoop(loop.a, t);
    else setLoop(null, null);
  }

  let practiceTimer = 0;
  const practiceData = () => ({
    m: practice.m.map(({ t, e, l }) => ({ t, e, l })),
    ab: loop.a !== null && loop.b !== null && loop.b > loop.a ? [Math.round(loop.a * 1000), Math.round(loop.b * 1000)] : null,
    bpm: pbpm === null ? null : Math.round(pbpm * 100) / 100,
    metro: { on: metro.on, vol: Math.round(metro.vol * 100) / 100, sub: metro.sub, count: metro.count },
  });
  function schedulePracticeSave() {
    clearTimeout(practiceTimer);
    practiceTimer = setTimeout(flushPractice, 1000);
  }
  async function flushPractice() {
    clearTimeout(practiceTimer);
    practiceTimer = 0;
    try {
      await post({ action: 'practice', tab_id: D.tab_id, data: practiceData() }, true);
    } catch (err) {
      notify(err.message || '練習の設定を保存できませんでした', 'error');
    }
  }
  // タブを閉じる・切り替えるときに、予約したままの保存を先に送る
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden' && practiceTimer) flushPractice(); });
  window.addEventListener('pagehide', () => { if (practiceTimer) flushPractice(); });

  const markersEl = $('[data-markers]');
  function addMarker() {
    if (practice.m.length >= 100) { notify('マーカーは最大100個です', 'error'); return; }
    practice.m.push({ t: Math.max(0, Math.round(clock.time() * 1000)), e: null, l: '' });
    practice.m.sort((x, y) => x.t - y.t);
    renderMarkers();
    schedulePracticeSave();
    requestFrame();
  }
  function renderMarkers() {
    markersEl.replaceChildren();
    markersEl.hidden = practice.m.length === 0;
    practice.m.forEach((mk, idx) => {
      const li = document.createElement('li');
      li.className = 'tab-markers__item';
      const flag = document.createElement('span');
      flag.className = 'icon tab-markers__flag';
      flag.setAttribute('aria-hidden', 'true');
      flag.textContent = 'flag';
      const time = document.createElement('span');
      time.className = 'tab-markers__time small';
      time.textContent = fmtMs(mk.t) + (mk.e !== null ? ' - ' + fmtMs(mk.e) : '');
      const label = document.createElement('input');
      label.type = 'text';
      label.className = 'tab-markers__label';
      label.maxLength = 20;
      label.placeholder = 'マーカー';
      label.setAttribute('aria-label', 'マーカー名');
      label.value = mk.l; // value に入れるだけ（HTML としては解釈されない）
      label.addEventListener('input', () => { mk.l = label.value; schedulePracticeSave(); requestFrame(); });
      const jump = iconBtn('jump', 'my_location', '移動');
      jump.addEventListener('click', () => seekTo(mk.t / 1000));
      // ループ: このマーカーから、終点があればそこまで・なければ次のマーカーまで（最後なら曲の終わりまで）
      const lp = iconBtn('loop', 'repeat', 'ループ');
      lp.addEventListener('click', () => {
        const next = practice.m[idx + 1];
        let b = mk.e !== null ? mk.e / 1000 : next ? next.t / 1000 : starts[st.bars.length];
        if (b <= mk.t / 1000) b = mk.t / 1000 + 2;
        setLoop(mk.t / 1000, b);
        seekTo(mk.t / 1000);
      });
      const end = iconBtn('end', 'sports_score', mk.e !== null ? '終点解除' : '終点');
      end.classList.toggle('is-active', mk.e !== null);
      end.addEventListener('click', () => {
        mk.e = mk.e !== null ? null : Math.max(mk.t + 1, Math.round(clock.time() * 1000));
        renderMarkers();
        schedulePracticeSave();
        requestFrame();
      });
      const del = iconBtn('del', 'delete', '削除');
      del.addEventListener('click', () => { practice.m.splice(idx, 1); renderMarkers(); schedulePracticeSave(); requestFrame(); });
      li.append(flag, time, label, jump, lp, end, del);
      markersEl.appendChild(li);
    });
  }

  /* ---------------------------------------------------------------
   * 3. canvas — 流れる TAB
   * --------------------------------------------------------------- */
  const stage = $('[data-stage]');
  const canvas = $('[data-canvas]');
  const ctx = canvas.getContext('2d');
  const LABEL_W = 30, GAP = 24;
  let Y0 = 46;                           // 弦の一番上の y。コードがある TAB は、コードの行のぶん下げる（recompute で決める）
  const ZOOMS = [50, 80, 120, 180, 260, 380];  // 1秒あたりのピクセル数
  let zoom = 2;
  try { zoom = clamp(Number(localStorage.getItem('tabZoom') ?? 2) | 0, 0, ZOOMS.length - 1); } catch (e) { /* 保存できなくても動く */ }
  let cssW = 0, cssH = 0;
  let loop = { a: null, b: null };       // A-B リピート（秒）
  let colors = {};

  /** 色は app.css の変数から読む（ダークモードに追従） */
  function readColors() {
    const cs = getComputedStyle(document.documentElement);
    const v = (n, fb) => cs.getPropertyValue(n).trim() || fb;
    colors = {
      text: v('--text', '#1b1a1f'), muted: v('--muted', '#6c6a75'), accent: v('--accent', '#d90f0f'),
      soft: v('--accent-soft', 'rgba(217,15,15,.08)'), surface: v('--surface', '#fff'), border: v('--border', '#e5e2da'),
      onAccent: v('--on-accent', '#fff'), accent2: v('--accent-2', '#f39a3d'),
    };
    requestFrame();
  }

  function resize() {
    const dpr = window.devicePixelRatio || 1;
    cssW = Math.max(200, stage.clientWidth);
    cssH = Y0 + (strings - 1) * GAP + 26;
    canvas.width = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    canvas.style.width = cssW + 'px';
    canvas.style.height = cssH + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    requestFrame();
  }

  /** マスに出す文字。弦: フレット数字（-1 は x）。ドラム: シンバルは x・タム/スネア/バスは ●、アクセントは >、ゴーストは ( )、オープンは o */
  function fretText(f, r) {
    if (!isDrum) return f < 0 ? 'x' : String(f);
    const cym = st.tuning[r] === 'CR' || st.tuning[r] === 'RD' || st.tuning[r] === 'HH';
    const base = f === 4 ? 'o' : cym ? 'x' : '●';
    return f === 2 ? '>' + base : f === 3 ? '(' + base + ')' : base;
  }

  function draw() {
    const t = clock.time();
    const pps = ZOOMS[zoom];
    const playX = Math.max(LABEL_W + 24, cssW * 0.25);
    const xAt = (time) => playX + (time - t) * pps;
    const yAt = (r) => Y0 + r * GAP;
    const yEnd = yAt(strings - 1);
    ctx.setTransform(window.devicePixelRatio || 1, 0, 0, window.devicePixelRatio || 1, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    // 曲のマーカー（セクション）の帯: 弦の上に、セクションの範囲ぜんぶに色を付けて名前を書く
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'left';
    ctx.font = '700 11px system-ui, "Noto Sans JP", sans-serif';
    sections.forEach((sec) => {
      const sx0 = xAt(starts[sec.from]), sx1 = xAt(starts[sec.to]);
      if (sx1 < LABEL_W || sx0 > cssW) return;
      const bx = Math.max(sx0, LABEL_W);
      ctx.fillStyle = secColor(sec.name, 0.3);
      ctx.fillRect(bx, 2, Math.max(0, sx1 - bx - 1), 17);
      if (sx0 >= LABEL_W) { ctx.fillStyle = secColor(sec.name); ctx.fillRect(sx0, 2, 3, 17); }
      ctx.save();
      ctx.beginPath(); ctx.rect(bx, 2, Math.max(0, sx1 - bx - 1), 17); ctx.clip();
      ctx.fillStyle = colors.text;
      ctx.fillText(sec.name, bx + 7, 11);
      ctx.restore();
    });

    // A-B リピートの範囲（塗って、A・B の線を引く）
    if (loop.a !== null) {
      const xa = xAt(loop.a);
      const xb = loop.b !== null ? xAt(loop.b) : null;
      if (xb !== null && xb > xa) {
        ctx.globalAlpha = 0.14;
        ctx.fillStyle = colors.accent;
        ctx.fillRect(xa, 20, xb - xa, yEnd - 20 + 22);
        ctx.globalAlpha = 1;
      }
      ctx.strokeStyle = colors.accent;
      ctx.fillStyle = colors.accent;
      ctx.lineWidth = 2;
      ctx.font = '800 10px system-ui, sans-serif';
      [['A', loop.a], ['B', loop.b]].forEach(([name, p]) => {
        if (p === null) return;
        ctx.beginPath(); ctx.moveTo(xAt(p), 20); ctx.lineTo(xAt(p), yEnd + 20); ctx.stroke();
        ctx.fillText(name, xAt(p) + 3, yEnd + 20);
      });
      ctx.lineWidth = 1;
    }

    // 弦の線
    ctx.strokeStyle = colors.border;
    ctx.lineWidth = 1;
    for (let r = 0; r < strings; r++) {
      ctx.beginPath(); ctx.moveTo(LABEL_W, yAt(r) + 0.5); ctx.lineTo(cssW, yAt(r) + 0.5); ctx.stroke();
    }

    // 見えている範囲の小節
    const n = st.bars.length;
    const first = Math.max(0, barAt(t + (LABEL_W - playX) / pps));
    const last = Math.min(n - 1, barAt(t + (cssW - playX) / pps));
    const spb = stepsPerBar();
    ctx.textBaseline = 'middle';
    for (let i = first; i <= last && last >= 0; i++) {
      const x0 = xAt(starts[i]);
      const x1 = xAt(starts[i] + durs[i]);
      // 拍の薄い目盛り
      ctx.strokeStyle = colors.border;
      for (let b = 1; b < st.beats; b++) {
        const bx = x0 + (x1 - x0) * b / st.beats;
        ctx.beginPath(); ctx.moveTo(bx + 0.5, Y0 - 6); ctx.lineTo(bx + 0.5, yEnd + 6); ctx.stroke();
      }
      // 小節線・番号・セクション名
      ctx.strokeStyle = colors.muted;
      ctx.beginPath(); ctx.moveTo(x0 + 0.5, Y0 - 8); ctx.lineTo(x0 + 0.5, yEnd + 8); ctx.stroke();
      if (i === n - 1) { ctx.beginPath(); ctx.moveTo(x1 + 0.5, Y0 - 8); ctx.lineTo(x1 + 0.5, yEnd + 8); ctx.stroke(); }
      ctx.textAlign = 'left';
      ctx.font = '600 11px system-ui, sans-serif';
      ctx.fillStyle = colors.muted;
      ctx.font = '600 10px system-ui, sans-serif';
      ctx.fillText(String(st.firstBar + i), x0 + 4, Y0 - 16); // 小節番号（部分 TAB は firstBar から）
      const bar = st.bars[i];
      if (bar.t !== null) { // タップで合わせた小節の印
        ctx.fillStyle = colors.accent;
        ctx.beginPath(); ctx.arc(x0 + 0.5, Y0 - 14, 2.5, 0, Math.PI * 2); ctx.fill();
      }
      // 音符
      ctx.font = '700 13px ui-monospace, Menlo, Consolas, monospace';
      ctx.textAlign = 'center';
      for (const key of Object.keys(bar.n)) {
        const k = Number(key);
        const step = k >> 3, r = k & 7;
        const nt = starts[i] + step / spb * durs[i];
        const x = xAt(nt), y = yAt(r);
        if (x < LABEL_W - 20 || x > cssW + 20) continue;
        const text = fretText(bar.n[key], r);
        const w = Math.max(18, ctx.measureText(text).width + 8);
        const active = Math.abs(nt - t) <= 0.08;
        ctx.globalAlpha = nt < t - 0.08 ? 0.35 : 1; // 通過済みは薄く
        const len = bar.d[key] || 1;
        if (len > 1) { // 音の長さ: フレットの箱のあとに伸ばす線
          const xe = xAt(starts[i] + (step + len) / spb * durs[i]);
          ctx.fillStyle = active ? colors.accent : colors.muted;
          ctx.fillRect(x + w / 2, y - 1, Math.max(0, xe - x - w / 2 - 2), 3);
        }
        ctx.fillStyle = active ? colors.accent : colors.surface;
        ctx.strokeStyle = active ? colors.accent : colors.muted;
        roundRect(x - w / 2, y - 9, w, 18, 5);
        ctx.fill(); ctx.stroke();
        ctx.fillStyle = active ? colors.onAccent : colors.text;
        ctx.fillText(text, x, y + 1);
      }
      // 休符: 弦の真ん中に小さな印
      ctx.font = '700 11px system-ui, "Noto Sans JP", sans-serif';
      ctx.fillStyle = colors.muted;
      bar.r.forEach(([step]) => {
        const rx = xAt(starts[i] + step / spb * durs[i]);
        if (rx > LABEL_W - 10 && rx < cssW + 10) ctx.fillText('休', rx, (Y0 + yEnd) / 2);
      });
      // コード名（弦の上）。いま鳴っているコードは色を変える。カポがあれば、実際に鳴るコードを小さく下に
      if (!isDrum) {
        bar.c && Object.keys(bar.c).forEach((key) => {
          const step = Number(key);
          const ct = starts[i] + step / spb * durs[i];
          const cx = xAt(ct);
          if (cx < LABEL_W - 30 || cx > cssW + 30) return;
          const now = chordList.length && chordNowIndex(t) >= 0 && chordList[chordNowIndex(t)].t === ct;
          ctx.font = '800 15px system-ui, sans-serif';
          ctx.textAlign = 'left';
          ctx.fillStyle = now ? colors.accent : colors.text;
          ctx.fillText(bar.c[key], cx + 2, 34);
          if (st.capo > 0 && bar.c[key] !== 'N.C.') {
            ctx.font = '600 10px system-ui, sans-serif';
            ctx.fillStyle = colors.muted;
            ctx.fillText(transposeChord(bar.c[key], st.capo), cx + 2, 46);
          }
          ctx.textAlign = 'center';
        });
      }
      ctx.globalAlpha = 1;
    }

    // 部分 TAB: 最初の小節が始まる前は、始まるまでの秒数（カウントダウン）を弦の上に出す
    if (starts[0] > 0 && t < starts[0]) {
      ctx.font = '800 18px system-ui, sans-serif';
      ctx.fillStyle = colors.muted;
      ctx.textAlign = 'left';
      ctx.fillText(`▶ ${(starts[0] - t).toFixed(1)}`, Math.max(LABEL_W + 8, xAt(starts[0]) - 90), (Y0 + yEnd) / 2);
    }

    // 練習用マーカー: 点線 + 旗（自分だけのしるし。曲のマーカーの帯とは違う見た目）
    ctx.strokeStyle = colors.accent2;
    ctx.fillStyle = colors.accent2;
    ctx.font = '700 11px system-ui, "Noto Sans JP", sans-serif';
    ctx.textAlign = 'left';
    practice.m.forEach((mk) => {
      const mx = xAt(mk.t / 1000);
      const me = mk.e !== null ? xAt(mk.e / 1000) : null;
      if ((mx < LABEL_W || mx > cssW) && (me === null || me < LABEL_W || mx > cssW)) return;
      ctx.setLineDash([4, 3]);
      ctx.beginPath(); ctx.moveTo(mx, 22); ctx.lineTo(mx, yEnd + 12); ctx.stroke();
      if (me !== null) {
        ctx.beginPath(); ctx.moveTo(me, 22); ctx.lineTo(me, yEnd + 12); ctx.stroke();
        ctx.setLineDash([]);
        ctx.beginPath(); ctx.moveTo(mx, yEnd + 22); ctx.lineTo(me, yEnd + 22); ctx.stroke(); // 区間
      }
      ctx.setLineDash([]);
      ctx.beginPath(); ctx.moveTo(mx, yEnd + 8); ctx.lineTo(mx + 8, yEnd + 12); ctx.lineTo(mx, yEnd + 16); ctx.fill();
      if (mk.l) ctx.fillText(mk.l, mx + 11, yEnd + 13);
    });

    // 左端の弦名（流れてくる譜面を隠す）
    ctx.fillStyle = colors.surface;
    ctx.fillRect(0, 0, LABEL_W, cssH);
    ctx.fillStyle = colors.muted;
    ctx.font = '700 12px system-ui, sans-serif';
    ctx.textAlign = 'center';
    for (let r = 0; r < strings; r++) ctx.fillText(stringNote(r), LABEL_W / 2, yAt(r) + 1);

    // 再生位置の線
    ctx.strokeStyle = colors.accent;
    ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(playX, Y0 - 18); ctx.lineTo(playX, yEnd + 18); ctx.stroke();
    ctx.lineWidth = 1;
    ctx.fillStyle = colors.accent;
    ctx.beginPath(); ctx.moveTo(playX - 5, Y0 - 22); ctx.lineTo(playX + 5, Y0 - 22); ctx.lineTo(playX, Y0 - 15); ctx.fill();

    drawPos(t);
  }

  function roundRect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  /* ---- 描画ループ: 再生中だけ回り続ける。止まっているときは変更があったときに1回だけ描く ---- */
  let raf = 0;
  function requestFrame() { if (!raf) raf = requestAnimationFrame(frame); }
  function frame() {
    raf = 0;
    tick();
    draw();
    if (clock.playing) requestFrame();
  }

  let posText = '';
  let nowBar = -2;
  const posEl = $('[data-pos]');
  const chordNowEl = $('[data-chord-now]');
  function drawPos(t) {
    const i = barAt(t);
    const sec = Math.max(0, t);
    const text = `${i >= 0 ? st.firstBar + i : '-'}/${st.firstBar + st.bars.length - 1}${i >= 0 && st.bars[i].s ? ' ' + st.bars[i].s : ''} ${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2, '0')}`;
    if (text !== posText) { posText = text; posEl.textContent = text; }
    const ci = chordNowIndex(t);
    const cname = ci >= 0 ? chordList[ci].name : '';
    if (chordNowEl.textContent !== cname) chordNowEl.textContent = cname; // いまのコードを大きく（ストロークだけの練習用）
    if (i !== nowBar) { // グリッドの現在の小節を強調
      nowBar = i;
      $$('.tg-bar.is-now').forEach((el) => el.classList.remove('is-now'));
      if (i >= 0 && gridBuilt) grid.children[i]?.classList.add('is-now');
    }
  }

  /** 毎フレームの処理: A-B リピート・メトロノーム・音源なしの終了 */
  function tick() {
    if (!clock.playing) return;
    const t = clock.time();
    if (loop.a !== null && loop.b !== null && loop.b > loop.a && t >= loop.b) {
      clock.seek(loop.a);
      metroReset(loop.a); // ループの頭の拍から鳴らし直す
      const stepUp = Number($('[data-loop-step]').value);
      if (stepUp > 0 && pbpm !== null) setPracticeBpm(Math.min(st.bpm, pbpm + stepUp)); // ループのたびに少しずつ速く（元の BPM まで）
      return;
    }
    if (clock.kind === 'none' && t > starts[st.bars.length] + 1) { clock.pause(); return; }
    beatFlashTick(t);
  }

  /* ---------------------------------------------------------------
   * メトロノーム（WebAudio）
   *   setTimeout で1回ずつ鳴らすとずれていくので、先読みでまとめて予約する:
   *   25ms ごとに「これから 0.2 秒ぶん（TAB の時間軸）」の拍を beatSchedule で出して、AudioContext の正確な時刻（osc.start(時刻)）で予約する。
   *   拍の位置は TAB の時間軸（小節の頭・タップで補正した小節・拍数）から出して、速さ（rate）で AudioContext の時刻に直す
   *   → 練習 BPM で遅くしても、同期解除していても、譜面・音源と同じ位置で鳴る
   *   設定（オン・音量・細分・カウントイン）は practice.metro に入れて、練習用マーカーと一緒に自動保存する
   * --------------------------------------------------------------- */
  const metro = Object.assign({ on: false, vol: 0.6, sub: 1, count: 0 }, practice.metro || {});
  let audioCtx = null;
  const LOOKAHEAD = 0.2;                 // 先読みする秒（TAB の時間軸ではなく実時間。rate を掛けて時間軸にする）
  let schedPos = null;                   // ここまで（TAB の時刻）は予約した
  let flashKey = -1;
  function ensureAudio() {
    if (audioCtx) return;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (AC) audioCtx = new AC();
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
  }
  /** at（AudioContext の時刻）にクリックを1つ予約する。止めたいとき用に発振器を返す */
  function click(at, kind) {
    if (!audioCtx) return null;
    const o = audioCtx.createOscillator();
    const g = audioCtx.createGain();
    o.frequency.value = kind === 'accent' ? 2000 : kind === 'beat' ? 1300 : 900;
    const level = metro.vol * 0.6 * (kind === 'accent' ? 1 : kind === 'beat' ? 0.75 : 0.4);
    g.gain.setValueAtTime(0.0001, at);
    g.gain.exponentialRampToValueAtTime(Math.max(0.0002, level), at + 0.002);
    g.gain.exponentialRampToValueAtTime(0.0001, at + (kind === 'sub' ? 0.03 : 0.05));
    o.connect(g); g.connect(audioCtx.destination);
    o.start(at); o.stop(at + 0.07);
    return o;
  }
  /** シーク・ループ・速さの変更のあと: 予約をやり直す。at を渡すと、その位置（を含む）の拍から鳴らす */
  function metroReset(at) {
    schedPos = at === undefined ? null : at - 0.001;
    flashKey = -1;
  }
  function metroSchedule() {
    if (!metro.on || !audioCtx || !clock || !clock.playing) { schedPos = null; return; }
    const now = clock.time();
    const rate = clock.rate || 1;
    if (schedPos === null || schedPos > now + LOOKAHEAD * rate * 2 || schedPos < now - 0.5) schedPos = now - 0.001;
    let horizon = now + LOOKAHEAD * rate;
    if (loop.a !== null && loop.b !== null && loop.b > loop.a && schedPos < loop.b) horizon = Math.min(horizon, loop.b - 1e-6); // ループの終わりを越えた拍は予約しない
    if (horizon <= schedPos) return;
    for (const c of beatSchedule({ starts, durs }, st.beats, metro.sub, schedPos, horizon)) {
      click(audioTimeFor(audioCtx.currentTime, c.t, now, rate), c.kind);
    }
    schedPos = horizon;
  }
  setInterval(metroSchedule, 25);

  /* ---- カウントイン: 止まっているところから再生するとき、先に1〜2小節ぶんクリックを鳴らす（練習 BPM の速さで）---- */
  let counting = false;
  let countTimer = 0;
  let countNodes = [];
  let countFlashes = [];
  function startCountIn() {
    const effRate = clock.kind === 'yt' ? clock.rate : currentRate();
    const beatSec = 60 / st.bpm / effRate;
    const total = metro.count * st.beats;
    const t0 = audioCtx.currentTime + 0.06;
    for (let k = 0; k < total; k++) {
      const accent = k % st.beats === 0;
      countNodes.push(click(t0 + k * beatSec, accent ? 'accent' : 'beat'));
      countFlashes.push(setTimeout(() => flashBeat(accent), 60 + k * beatSec * 1000)); // 光る方は目安でよい（音は上で正確に予約している）
    }
    counting = true;
    countTimer = setTimeout(() => { counting = false; countTimer = 0; countNodes = []; countFlashes = []; clock.play(); onClockChange(); }, 60 + total * beatSec * 1000 - 8);
    onClockChange();
  }
  function cancelCountIn() {
    clearTimeout(countTimer);
    countFlashes.forEach(clearTimeout);
    countNodes.forEach((o) => { try { o.stop(); } catch (e) { /* もう鳴り終わっている */ } });
    counting = false;
    countNodes = [];
    countFlashes = [];
    onClockChange();
  }

  /* ---- 拍の表示（位置の横の丸。拍ごとに光り、1拍目は色が違う。動きを減らす設定のときは CSS が光らせずに色だけ変える）---- */
  const beatEl = $('[data-beat]');
  let beatTimer = 0;
  function flashBeat(accent) {
    beatEl.classList.toggle('is-accent', accent);
    beatEl.classList.add('is-on');
    clearTimeout(beatTimer);
    beatTimer = setTimeout(() => beatEl.classList.remove('is-on'), 110);
  }
  function beatFlashTick(t) {
    const i = barAt(t);
    if (i < 0 || t >= starts[i] + durs[i]) { flashKey = -1; return; }
    const bd = durs[i] / st.beats;
    const b = Math.min(st.beats - 1, Math.floor((t - starts[i]) / bd));
    const key = i * 32 + b;
    if (key === flashKey) return;
    const first = flashKey === -1;
    flashKey = key;
    if (!first || t - (starts[i] + b * bd) < 0.1) flashBeat(b === 0); // シークした直後など、拍の途中からは光らせない
  }

  /* ---- メトロノームの設定（ボタンの横の ▾ か、長押しで開くポップアップ）---- */
  const metroBtn = $('[data-metro]');
  const metroMenu = $('[data-metro-menu]');
  const metroSub = $('[data-metro-sub]');
  const metroVol = $('[data-metro-vol]');
  const metroCount = $('[data-metro-count]');
  let metroLongPress = false;
  function syncMetroUi() {
    metroBtn.setAttribute('aria-pressed', String(metro.on));
    metroBtn.classList.toggle('is-active', metro.on);
    metroSub.value = String(metro.sub);
    metroVol.value = String(Math.round(metro.vol * 100));
    metroCount.value = String(metro.count);
  }
  function metroChanged() {
    syncMetroUi();
    ensureAudio();
    metroReset();
    schedulePracticeSave();
  }
  function toggleMetroMenu(open) {
    metroMenu.hidden = open === undefined ? !metroMenu.hidden : !open;
    $('[data-metro-menu-toggle]').setAttribute('aria-expanded', String(!metroMenu.hidden));
  }
  metroSub.addEventListener('change', () => { metro.sub = Number(metroSub.value); metroChanged(); });
  metroVol.addEventListener('input', () => { metro.vol = clamp(Number(metroVol.value) / 100, 0, 1); metroChanged(); });
  metroCount.addEventListener('change', () => { metro.count = Number(metroCount.value); metroChanged(); });
  let pressTimer = 0;
  metroBtn.addEventListener('pointerdown', () => {
    metroLongPress = false;
    pressTimer = setTimeout(() => { metroLongPress = true; toggleMetroMenu(true); }, 500);
  });
  ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => metroBtn.addEventListener(ev, () => clearTimeout(pressTimer)));
  document.addEventListener('pointerdown', (e) => {
    if (!metroMenu.hidden && !e.target.closest('.tab-metro')) toggleMetroMenu(false);
  });
  /* ---------------------------------------------------------------
   * 練習用の操作
   * --------------------------------------------------------------- */
  function seekTo(sec) {
    clock.seek(Math.max(0, sec));
    metroReset();
    requestFrame();
  }

  stage.addEventListener('pointerdown', (e) => {
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    if (x < LABEL_W) return;
    const playX = Math.max(LABEL_W + 24, cssW * 0.25);
    seekTo(clock.time() + (x - playX) / ZOOMS[zoom]); // クリックした場所の時刻へ
  });

  root.addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if (!btn || !root.contains(btn)) return;
    const d = btn.dataset;
    if (d.play !== undefined) togglePlay();
    else if (d.seek !== undefined) seekTo(clock.time() + Number(d.seek));
    else if (d.rate !== undefined) setPracticeBpm(Number(d.rate) === 1 ? null : Math.round(st.bpm * Number(d.rate) * 10) / 10);
    else if (d.pbpmStep !== undefined) setPracticeBpm((pbpm ?? st.bpm) + Number(d.pbpmStep));
    else if (d.zoom !== undefined) {
      zoom = clamp(zoom + Number(d.zoom), 0, ZOOMS.length - 1);
      try { localStorage.setItem('tabZoom', String(zoom)); } catch (err) { /* 無視 */ }
      requestFrame();
    } else if (d.ab !== undefined) {
      setLoopPoint(d.ab === 'clear' ? null : d.ab);
    } else if (d.markerAdd !== undefined) {
      addMarker();
    } else if (d.metro !== undefined) {
      if (metroLongPress) { metroLongPress = false; return; } // 長押しで設定を開いたときは、オン・オフを切り替えない
      metro.on = !metro.on;
      metroChanged();
    } else if (d.metroMenuToggle !== undefined) {
      toggleMetroMenu();
    } else if (d.unsync !== undefined) {
      setUnsync(!unsynced);
    } else if (d.src !== undefined) {
      if (d.src === 'file') fileInput.click(); // 選んだあと change で切り替わる
      else setSource(d.src);
    } else if (d.modeToggle !== undefined) setMode(!editMode);
  });

  fileInput.addEventListener('change', () => {
    const f = fileInput.files[0];
    if (f) setSource('file', f);
    fileInput.value = '';
  });

  /* ---------------------------------------------------------------
   * 4. 編集 — グリッド
   * --------------------------------------------------------------- */
  const grid = $('[data-grid]');
  const editBox = $('[data-edit]');
  const pad = $('[data-pad]');
  let gridBuilt = false;

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }
  function iconBtn(act, icon, label) {
    const b = el('button', 'icon-btn icon-btn--sm');
    b.type = 'button';
    b.dataset.act = act;
    b.title = label;
    b.setAttribute('aria-label', label);
    const i = el('span', 'icon', icon);
    i.setAttribute('aria-hidden', 'true');
    b.appendChild(i);
    return b;
  }
  const fmtMs = (ms) => `${Math.floor(ms / 60000)}:${String(Math.floor(ms / 1000) % 60).padStart(2, '0')}.${Math.floor(ms % 1000 / 100)}`;

  /** 1小節ぶんのブロック（見出し + 弦 × マスのボタン） */
  function buildBar(i) {
    const bar = st.bars[i];
    const N = stepsPerBar();
    const box = el('div', 'tg-bar');
    box.dataset.bar = String(i);
    if (secOf[i] >= 0) box.style.setProperty('--sec', secColor(sections[secOf[i]].name)); // セクションの色（見出しに薄く付ける）
    if (tapMode && tapIdx === i) box.classList.add('is-tap-next');

    const head = el('div', 'tg-bar__head');
    head.appendChild(el('span', 'tg-bar__no', String(st.firstBar + i)));
    const sec = el('input', 'tg-bar__sec');
    sec.type = 'text';
    sec.maxLength = SECTION_MAX;
    sec.placeholder = 'セクション';
    sec.value = bar.s;               // value に入れるだけ（HTML としては解釈されない）
    head.appendChild(sec);
    if (bar.t !== null) {
      const tp = iconBtn('untap', 'touch_app', 'タップ解除');
      tp.classList.add('is-active');
      tp.appendChild(el('span', 'tg-bar__ms', fmtMs(bar.t)));
      head.appendChild(tp);
    }
    head.appendChild(iconBtn('add', 'add', '後ろに追加'));
    head.appendChild(iconBtn('dup', 'content_copy', '複製'));
    head.appendChild(iconBtn('del', 'delete', '削除'));
    box.appendChild(head);

    const cells = el('div', 'tg-bar__cells');
    cells.style.setProperty('--cols', String(N));
    if (!isDrum) { // コードの行（弦の上）。マスを押すと、画面内キーボードのコード入力でそのステップにコードを付ける
      const cn = el('span', 'tg-name tg-name--chord', '♪');
      cn.title = 'コード';
      cells.appendChild(cn);
      for (let s = 0; s < N; s++) {
        const cc = el('button', 'tg-chord' + (s % st.steps === 0 ? ' is-beat' : ''), bar.c[s] || '');
        cc.type = 'button';
        cc.tabIndex = -1;
        cc.dataset.s = String(s);
        cc.dataset.chord = '1';
        if (bar.c[s]) cc.classList.add('has-chord');
        cells.appendChild(cc);
      }
    }
    for (let r = 0; r < strings; r++) {
      const nm = el('span', 'tg-name', stringNote(r));
      nm.dataset.r = String(r); // 弦の番号を持たせておく（チューニングを変えたとき relabelStrings がこれで書き直す）
      cells.appendChild(nm);
      for (let s = 0; s < N; s++) {
        const c = el('button', 'tg-cell' + (s % st.steps === 0 ? ' is-beat' : '') + (isDrum && r === DRUM_HH ? ' is-hh' : ''));
        c.type = 'button';
        c.tabIndex = -1;             // 数千個のボタンを Tab で辿らせない（移動は矢印キー）
        c.dataset.s = String(s);
        c.dataset.r = String(r);
        const f = bar.n[s * 8 + r];
        if (f !== undefined) { c.textContent = fretText(f, r); c.classList.add('has-note'); }
        // 音の長さ: 音符の次のマスから長さぶんに、伸ばす線の印を付ける
        for (let q = 1; q < N; q++) {
          const lenKey = (s - q) * 8 + r;
          if (s - q >= 0 && bar.n[lenKey] !== undefined) { if ((bar.d[lenKey] || 1) > q) c.classList.add('is-sus'); break; }
        }
        if (r === 0 && f === undefined && bar.r.some(([rs]) => rs === s)) { c.textContent = '休'; c.classList.add('has-rest'); } // 休符
        cells.appendChild(c);
      }
    }
    box.appendChild(cells);
    return box;
  }

  function renderGrid() {
    const frag = document.createDocumentFragment();
    st.bars.forEach((_, i) => frag.appendChild(buildBar(i)));
    grid.replaceChildren(frag);
    gridBuilt = true;
    nowBar = -2;
    markSel();
    requestFrame();
  }
  function replaceBar(i) {
    const old = grid.children[i];
    if (old) old.replaceWith(buildBar(i));
    markSel();
  }
  const cellEl = (b, s, r) => grid.children[b]?.querySelector(`.tg-cell[data-s="${s}"][data-r="${r}"]`);

  function markSel() {
    $$('.tg-cell.is-sel', grid).forEach((c) => c.classList.remove('is-sel'));
    if (sel && cellEl(sel.b, sel.s, sel.r)) cellEl(sel.b, sel.s, sel.r).classList.add('is-sel');
    pad.hidden = !(editMode && sel);
    document.body.classList.toggle('has-tab-pad', !pad.hidden); // 画面の下・横にキーボードが出ている間、保存ボタンなどを避ける
    updateSecPick();
  }
  function select(b, s, r, scroll) {
    sel = { b, s, r };
    markSel();
    if (scroll) cellEl(b, s, r)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  function moveSel(dx, dy) {
    let { b, s, r } = sel;
    const N = stepsPerBar();
    if (dx) {
      s += dx;
      if (s >= N) { if (b < st.bars.length - 1) { b++; s = 0; } else s = N - 1; }
      else if (s < 0) { if (b > 0) { b--; s = N - 1; } else s = 0; }
    }
    if (dy) r = clamp(r + dy, 0, strings - 1);
    select(b, s, r, true);
  }

  /* ---------------------------------------------------------------
   * 入力（画面内キーボードと、パソコンのキーボードで同じ動き）
   *   カーソル = 選んでいるマス（sel）。フレットを打つと、今の音価の長さの音符を置いて、カーソルがその長さぶん進む（和音モードなら進まない）
   *   音価（kb.base = 1拍を1とした長さ、dot = 付点、trip = 3連）は、今の分割で整数のマス数になるものだけ使える
   *   パソコンのキー: 数字 = フレット / r = 休符 / x = ミュート / c = 和音 / - + = 音価を短く・長く / . = 付点 / , = 3連 / Backspace = 削除 / 矢印 = カーソル
   * --------------------------------------------------------------- */
  const kb = { base: 1, dot: false, trip: false, chord: false, panel: 'note', chordBuf: '' };
  const BASES = [['全', 4], ['2分', 2], ['4分', 1], ['8分', 0.5], ['16分', 0.25], ['32分', 0.125]];
  /** 音価 → マス数。今の分割で割り切れなければ null */
  function durFor(base, dot, trip) {
    const v = st.steps * base * (dot ? 1.5 : 1) * (trip ? 2 / 3 : 1);
    return Math.abs(v - Math.round(v)) < 1e-9 && Math.round(v) >= 1 ? Math.round(v) : null;
  }
  /** 今の音価のマス数（使えない組み合わせなら 1） */
  const curDur = () => durFor(kb.base, kb.dot, kb.trip) ?? 1;

  /** 小節の中の、そのマスより前の音符で、長さがそのマスまで届いているもの（上書きされる音の長さを切るため） */
  function trimSustain(bar, s, r) {
    for (let q = 1; q <= s; q++) {
      const k = (s - q) * 8 + r;
      if (bar.n[k] !== undefined) { if ((bar.d[k] || 1) > q) { if (q > 1) bar.d[k] = q; else delete bar.d[k]; } break; }
    }
  }

  /** フレット（奏法）を打つ（null で削除）。dur = 音の長さ（マス）。ドラムはオープン（4）は HH だけ */
  function setFret(b, s, r, fret, coalesce, dur) {
    const bar = st.bars[b];
    const key = s * 8 + r;
    if (isDrum && fret === 4 && r !== DRUM_HH) return;
    if (fret === null) { delete bar.n[key]; delete bar.d[key]; }
    else {
      bar.n[key] = fret;
      const len = Math.min(dur || 1, stepsPerBar() - s);
      if (len > 1) bar.d[key] = len; else delete bar.d[key];
      bar.r = bar.r.filter(([rs]) => rs !== s); // 音を置いたマスの休符は外す
    }
    trimSustain(bar, s, r);
    commit(coalesce);
    replaceBar(b); // 長さの線・休符の表示も含めて、この小節を描き直す
    requestFrame();
  }

  /** カーソルを今の音価ぶん進める（小節の終わりを越えたら次の小節の頭） */
  function advance(len) {
    if (!sel || kb.chord) return;
    let { b, s, r } = sel;
    s += len;
    if (s >= stepsPerBar()) { if (b < st.bars.length - 1) { b++; s = 0; } else s = stepsPerBar() - 1; }
    select(b, s, r, true);
  }

  /** フレット（奏法）を打ってカーソルを進める。ドラムは 1〜4 の奏法 */
  function placeNote(fret) {
    if (!sel) return;
    const f = isDrum ? clamp(fret, 1, 4) : fret;
    const len = isDrum ? 1 : curDur();
    setFret(sel.b, sel.s, sel.r, f, null, len);
    advance(len);
  }
  /** 休符: 今の音価の長さの休符を置いて、そのマスの音符は消す。カーソルが進む */
  function placeRest() {
    if (!sel) return;
    const { b, s } = sel;
    const bar = st.bars[b];
    const len = Math.min(curDur(), stepsPerBar() - s);
    for (let r = 0; r < strings; r++) { delete bar.n[s * 8 + r]; delete bar.d[s * 8 + r]; trimSustain(bar, s, r); }
    bar.r = bar.r.filter(([rs]) => rs !== s);
    bar.r.push([s, len]);
    commit(null);
    replaceBar(b);
    requestFrame();
    advance(len);
  }

  let lastDigit = null;                   // 直前に数字で置いた音の場所と時刻（2桁目の結合用）
  function typeDigit(d) {
    if (!sel) return;
    if (isDrum) { lastDigit = null; placeNote(d); return; } // ドラムは奏法 1〜4
    const now = Date.now();
    // 600ms 以内に続けて打った数字は、さっき置いた音の2桁目（1 → 2 で 12）。24 を超えるなら新しい音として置く。カーソルは進めたまま
    if (lastDigit && now - lastDigit.at < 600) {
      const bar = st.bars[lastDigit.b];
      const key = lastDigit.s * 8 + lastDigit.r;
      const cur = bar.n[key];
      if (cur !== undefined && cur >= 0 && cur < 10 && cur * 10 + d <= MAX_FRET) {
        lastDigit.at = now;
        setFret(lastDigit.b, lastDigit.s, lastDigit.r, cur * 10 + d, `digit:${key}`, bar.d[key] || 1);
        return;
      }
    }
    const at = { b: sel.b, s: sel.s, r: sel.r };
    placeNote(d);
    lastDigit = { ...at, at: now };
  }

  /** コードを付ける・外す（null）。コードの形は D.chord_re（PHP の TAB_CHORD_RE と同じ） */
  function setChord(b, s, name) {
    const bar = st.bars[b];
    if (name === null || name === '') delete bar.c[s];
    else if (chordRe.test(name)) bar.c[s] = name;
    else { notify('コード名がおかしいです', 'error'); return false; }
    recompute();
    commit(null);
    replaceBar(b);
    requestFrame();
    return true;
  }
  const recentChords = () => {
    const seen = [];
    st.bars.forEach((bar) => Object.keys(bar.c).sort((a, b) => a - b).forEach((k) => { if (!seen.includes(bar.c[k])) seen.push(bar.c[k]); }));
    return seen.slice(-8);
  };
  /* ---- 元に戻す / やり直す（JSON のスナップショットを最大100個） ---- */
  const HIST_MAX = 100;
  const snapshot = () => JSON.stringify({ beats: st.beats, steps: st.steps, bars: serializeBars() });
  let hist = [snapshot()];
  let hi = 0;
  let lastKey = null, lastAt = 0;
  const undoBtn = $('[data-undo]');
  const redoBtn = $('[data-redo]');
  undoBtn.addEventListener('click', undo);   // （画面のボタン。キーボードは Ctrl+Z / Ctrl+Y）
  redoBtn.addEventListener('click', redo);

  function commit(coalesce) {
    const snap = snapshot();
    if (snap !== hist[hi]) {
      // 同じマスへの連続入力（2桁など）は1回の操作として戻せるように、直前のスナップショットを置き換える
      if (coalesce && coalesce === lastKey && Date.now() - lastAt < 1200 && hi > 0) {
        hist[hi] = snap;
      } else {
        hist.length = hi + 1;
        hist.push(snap);
        if (hist.length > HIST_MAX + 1) hist.shift();
        hi = hist.length - 1;
      }
      lastKey = coalesce || null;
      lastAt = Date.now();
    }
    updateHistoryButtons();
    refreshDirty();
    updatePartialUi();   // 小節の数が変わると「部分 49〜64」も変わる
    updateKeyboard();    // コードの候補（このTABのコード）も変わる
  }
  function applySnapshot(str) {
    const s = JSON.parse(str);
    st.beats = s.beats;
    st.steps = s.steps;
    st.bars = s.bars.map(barFromJson);
    $('[data-set="beats"]').value = String(st.beats);
    $('[data-set="steps"]').value = String(st.steps);
    if (sel && (sel.b >= st.bars.length || sel.s >= stepsPerBar())) sel = null;
    tapIdx = Math.min(tapIdx, st.bars.length - 1);
    recompute();
    renderGrid();
    updateHistoryButtons();
    refreshDirty();
    updatePartialUi();
    updateKeyboard();
  }
  function undo() { if (hi > 0) { hi--; lastKey = null; applySnapshot(hist[hi]); } }
  function redo() { if (hi < hist.length - 1) { hi++; lastKey = null; applySnapshot(hist[hi]); } }
  function updateHistoryButtons() {
    undoBtn.disabled = hi <= 0;
    redoBtn.disabled = hi >= hist.length - 1;
  }

  /* ---- 小節の追加・複製・削除、見出しの入力 ---- */
  grid.addEventListener('click', (e) => {
    const chordCell = e.target.closest('.tg-chord');
    if (chordCell) { // コードの行: そのステップを選んで、キーボードをコードのパネルにする
      select(Number(chordCell.closest('.tg-bar').dataset.bar), Number(chordCell.dataset.s), sel ? sel.r : 0, false);
      kb.panel = 'chord';
      kb.chordBuf = chordCell.textContent;
      updateKeyboard();
      $('[data-chord-input]', pad)?.focus();
      return;
    }
    const cell = e.target.closest('.tg-cell');
    if (cell) {
      select(Number(cell.closest('.tg-bar').dataset.bar), Number(cell.dataset.s), Number(cell.dataset.r), false);
      return;
    }
    const act = e.target.closest('[data-act]');
    if (!act) return;
    const i = Number(act.closest('.tg-bar').dataset.bar);
    if (act.dataset.act === 'untap') {
      st.bars[i].t = null;
      recompute(); replaceBar(i); commit(); requestFrame();
    } else if (act.dataset.act === 'add') insertBar(i + 1, newBar());
    else if (act.dataset.act === 'dup') insertBar(i + 1, { ...newBar(), s: st.bars[i].s, n: { ...st.bars[i].n }, d: { ...st.bars[i].d }, r: st.bars[i].r.map((x) => [...x]), c: { ...st.bars[i].c } });
    else if (act.dataset.act === 'del') {
      if (st.bars.length <= 1) return;
      st.bars.splice(i, 1);
      sel = null;
      recompute(); renderGrid(); commit();
    }
  });
  grid.addEventListener('input', (e) => {
    if (!e.target.classList.contains('tg-bar__sec')) return;
    st.bars[Number(e.target.closest('.tg-bar').dataset.bar)].s = e.target.value;
    recompute();        // セクションの範囲が変わる
    tintBars();
    updateSecPick();
    refreshDirty();
    requestFrame();
  });
  grid.addEventListener('change', (e) => {
    if (e.target.classList.contains('tg-bar__sec')) commit();
  });
  // ボタンを押してもマスの選択（フォーカス）を外さない
  pad.addEventListener('mousedown', (e) => e.preventDefault());

  function insertBar(at, bar) {
    if (st.bars.length >= MAX_BARS) { notify('小節は最大' + MAX_BARS + 'です', 'error'); return; }
    st.bars.splice(at, 0, bar);
    sel = null;
    recompute(); renderGrid(); commit();
    grid.children[at]?.scrollIntoView({ block: 'nearest' });
  }
  $('[data-bar-add]').addEventListener('click', () => insertBar(st.bars.length, newBar()));

  /* ---------------------------------------------------------------
   * 画面内キーボード（編集モードでマスを選んでいる間だけ。スマホは画面の下、広い画面は右の横に出る）
   *   ボタンは data-kb="種類:値"。押しても入力欄のフォーカス・マスの選択を外さない（mousedown を止める）
   * --------------------------------------------------------------- */
  function kbBtn(label, kbData, cls, title) {
    const b = el('button', cls || '', label);
    b.type = 'button';
    b.dataset.kb = kbData;
    if (title) { b.title = title; b.setAttribute('aria-label', title); }
    return b;
  }
  function kbRow(...children) {
    const r = el('div', 'tab-pad__row');
    children.flat().forEach((c) => r.appendChild(c));
    return r;
  }
  function renderKeyboard() {
    pad.replaceChildren();
    // 上のタブ: 音 / コード（ドラム譜はコードなし）
    const tabs = kbRow(kbBtn('音', 'panel:note', 'tab-pad__tab', '音を入力'), ...(isDrum ? [] : [kbBtn('コード', 'panel:chord', 'tab-pad__tab', 'コードを入力')]),
      kbBtn('↶', 'undo', 'tab-pad__sm', '元に戻す（Ctrl+Z）'), kbBtn('↷', 'redo', 'tab-pad__sm', 'やり直す（Ctrl+Y）'));
    pad.appendChild(tabs);

    // ---- 音のパネル ----
    const note = el('div', 'tab-pad__panel');
    note.dataset.panel = 'note';
    if (isDrum) {
      note.appendChild(kbRow(kbBtn('通常', 'fret:1', 'tab-pad__big', '通常（1）'), kbBtn('アクセント', 'fret:2', 'tab-pad__big', 'アクセント（2）'),
        kbBtn('ゴースト', 'fret:3', 'tab-pad__big', 'ゴースト（3）'), kbBtn('オープン', 'fret:4', 'tab-pad__big', 'オープン（4・HH のみ）')));
      note.appendChild(kbRow(...st.tuning.map((lane, r) => kbBtn(lane, `lane:${r}`, 'tab-pad__sm', 'レーン ' + lane))));
    } else {
      note.appendChild(kbRow(...Array.from({ length: 10 }, (_, f) => kbBtn(String(f), `fret:${f}`, 'tab-pad__big'))));
      note.appendChild(kbRow(...Array.from({ length: 15 }, (_, i) => kbBtn(String(i + 10), `fret:${i + 10}`, 'tab-pad__sm'))));
      note.appendChild(kbRow(...BASES.map(([label, base]) => kbBtn(label, `base:${base}`, 'tab-pad__sm', `音価 ${label}`)),
        kbBtn('付点', 'dot', 'tab-pad__sm', '付点（.）'), kbBtn('3連', 'trip', 'tab-pad__sm', '3連（,）')));
      note.appendChild(kbRow(kbBtn('x', 'fret:-1', 'tab-pad__sm', 'ミュート（x）'), kbBtn('休符', 'rest', 'tab-pad__sm', '休符（r）'),
        kbBtn('和音', 'chordmode', 'tab-pad__sm', '和音: 同じ位置に重ねる（c）')));
    }
    note.appendChild(kbRow(kbBtn('◀', 'move:-1:0', 'tab-pad__sm', '左（←）'), kbBtn('▶', 'move:1:0', 'tab-pad__sm', '右（→）'),
      kbBtn('▲', 'move:0:-1', 'tab-pad__sm', '上の弦（↑）'), kbBtn('▼', 'move:0:1', 'tab-pad__sm', '下の弦（↓）'),
      kbBtn('削除', 'del', 'tab-pad__sm', '削除（Backspace）')));
    pad.appendChild(note);

    // ---- コードのパネル ----
    if (!isDrum) {
      const chord = el('div', 'tab-pad__panel');
      chord.dataset.panel = 'chord';
      const input = el('input', 'tab-pad__chord');
      input.type = 'text';
      input.maxLength = 16;
      input.placeholder = 'Am7';
      input.dataset.chordInput = '1';
      input.setAttribute('aria-label', 'コード名');
      input.addEventListener('input', () => { kb.chordBuf = input.value; updateKeyboard(); });
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyChord(); } });
      chord.appendChild(kbRow(input, kbBtn('OK', 'chord-ok', 'tab-pad__sm', 'このステップに付ける（Enter）'), kbBtn('外す', 'chord-del', 'tab-pad__sm', 'このステップのコードを外す')));
      chord.appendChild(kbRow(...['A', 'B', 'C', 'D', 'E', 'F', 'G'].map((n) => kbBtn(n, `root:${n}`, 'tab-pad__big')), kbBtn('#', 'acc:#', 'tab-pad__sm'), kbBtn('b', 'acc:b', 'tab-pad__sm')));
      chord.appendChild(kbRow(...['m', '7', 'M7', 'm7', 'sus4', 'add9', '6', 'dim', 'aug', '9'].map((q) => kbBtn(q, `q:${q}`, 'tab-pad__sm')),
        kbBtn('/', 'slash', 'tab-pad__sm', 'オンコード'), kbBtn('N.C.', 'nc', 'tab-pad__sm'), kbBtn('⌫', 'chord-back', 'tab-pad__sm', '1文字消す')));
      const recent = el('div', 'tab-pad__row tab-pad__recent');
      recent.dataset.recent = '1';
      chord.appendChild(recent);
      pad.appendChild(chord);
    }
    updateKeyboard();
  }
  /** ボタンの押せる・押せない・押している状態を、今の状態に合わせる */
  function updateKeyboard() {
    if (!pad.firstChild) return;
    if (kb.panel === 'chord' && isDrum) kb.panel = 'note';
    $$('[data-panel]', pad).forEach((p) => { p.hidden = p.dataset.panel !== kb.panel; });
    $$('[data-kb^="panel:"]', pad).forEach((b) => b.classList.toggle('is-active', b.dataset.kb === 'panel:' + kb.panel));
    if (!isDrum) {
      // 音価・付点・3連: 今の分割で整数のマス数にならないものは押せない
      $$('[data-kb^="base:"]', pad).forEach((b) => {
        const base = Number(b.dataset.kb.slice(5));
        b.disabled = durFor(base, false, false) === null;
        b.classList.toggle('is-active', base === kb.base);
      });
      if (durFor(kb.base, false, false) === null) { // 分割を変えて今の音価が使えなくなった: 使える一番長い音価に
        const ok = BASES.find(([, base]) => durFor(base, false, false) !== null);
        kb.base = ok ? ok[1] : 1;
      }
      const dotBtn = $('[data-kb="dot"]', pad), tripBtn = $('[data-kb="trip"]', pad);
      dotBtn.disabled = durFor(kb.base, true, false) === null && !kb.dot;
      tripBtn.disabled = durFor(kb.base, false, true) === null && !kb.trip;
      dotBtn.classList.toggle('is-active', kb.dot);
      tripBtn.classList.toggle('is-active', kb.trip);
      $('[data-kb="chordmode"]', pad).classList.toggle('is-active', kb.chord);
      const chordInput = $('[data-chord-input]', pad);
      if (chordInput && chordInput.value !== kb.chordBuf) chordInput.value = kb.chordBuf;
      $('[data-kb="chord-ok"]', pad).disabled = !chordRe.test(kb.chordBuf);
      const recent = $('[data-recent]', pad);
      recent.replaceChildren(...recentChords().map((c) => kbBtn(c, 'recent:' + c, 'tab-pad__sm')));
    }
  }
  /** 選んでいるステップにコードを付ける */
  function applyChord() {
    if (!sel || !chordRe.test(kb.chordBuf)) return;
    if (setChord(sel.b, sel.s, kb.chordBuf)) { moveSel(1, 0); updateKeyboard(); }
  }
  pad.addEventListener('click', (e) => {
    const b = e.target.closest('[data-kb]');
    if (!b || b.disabled) return;
    const [kind, v1, v2] = b.dataset.kb.split(':');
    const val = b.dataset.kb.slice(kind.length + 1);
    if (kind === 'panel') { kb.panel = val; }
    else if (kind === 'undo') undo();
    else if (kind === 'redo') redo();
    else if (kind === 'base') { kb.base = Number(val); if (durFor(kb.base, kb.dot, kb.trip) === null) { kb.dot = false; kb.trip = false; } }
    else if (kind === 'dot') { kb.dot = !kb.dot; if (kb.dot) kb.trip = false; }
    else if (kind === 'trip') { kb.trip = !kb.trip; if (kb.trip) kb.dot = false; }
    else if (kind === 'chordmode') kb.chord = !kb.chord;
    else if (sel) {
      lastDigit = null;
      if (kind === 'fret') placeNote(Number(val));
      else if (kind === 'lane') select(sel.b, sel.s, Number(val), true); // ドラム: レーンを選ぶ
      else if (kind === 'rest') placeRest();
      else if (kind === 'move') moveSel(Number(v1), Number(v2));
      else if (kind === 'del') {
        const bar = st.bars[sel.b];
        if (bar.n[sel.s * 8 + sel.r] === undefined && bar.r.some(([rs]) => rs === sel.s)) { bar.r = bar.r.filter(([rs]) => rs !== sel.s); commit(null); replaceBar(sel.b); requestFrame(); }
        else setFret(sel.b, sel.s, sel.r, null, null);
      }
      else if (kind === 'root') { kb.chordBuf = kb.chordBuf.includes('/') && !kb.chordBuf.endsWith('/') ? kb.chordBuf : (kb.chordBuf.endsWith('/') ? kb.chordBuf + val : val); }
      else if (kind === 'acc') kb.chordBuf += val;
      else if (kind === 'q') kb.chordBuf += val;
      else if (kind === 'slash') kb.chordBuf += '/';
      else if (kind === 'nc') kb.chordBuf = 'N.C.';
      else if (kind === 'chord-back') kb.chordBuf = kb.chordBuf.slice(0, -1);
      else if (kind === 'chord-ok') applyChord();
      else if (kind === 'chord-del') setChord(sel.b, sel.s, null);
      else if (kind === 'recent') { kb.chordBuf = val; applyChord(); }
    }
    updateKeyboard();
  });
  /* ---- タップ補正: 再生しながら T キー（またはボタン）を押すと、選んだ小節から順に「今の位置」を小節の頭にする ---- */
  let tapMode = false;
  let tapIdx = 0;
  const tapBtn = $('[data-tap]');
  function setTapMode(on) {
    tapMode = on;
    const b = $('[data-tap-mode]');
    b.setAttribute('aria-pressed', String(on));
    b.classList.toggle('is-active', on);
    tapBtn.hidden = !on;
    if (on) tapIdx = sel ? sel.b : 0;
    markTapNext();
  }
  function markTapNext() {
    $$('.tg-bar.is-tap-next', grid).forEach((x) => x.classList.remove('is-tap-next'));
    if (tapMode) grid.children[tapIdx]?.classList.add('is-tap-next');
  }
  function tapNow() {
    if (!tapMode) return;
    st.bars[tapIdx].t = Math.max(0, Math.round(clock.time() * 1000));
    recompute();
    replaceBar(tapIdx);
    if (tapIdx < st.bars.length - 1) tapIdx++;
    markTapNext();
    grid.children[tapIdx]?.scrollIntoView({ block: 'nearest' });
    commit();
    requestFrame();
  }
  $('[data-tap-mode]').addEventListener('click', () => setTapMode(!tapMode));
  tapBtn.addEventListener('click', tapNow);

  /* ---- 設定欄 ---- */
  const setEl = (n) => $(`[data-set="${n}"]`);
  const inputs = {
    part: setEl('part'), bpm: setEl('bpm'), beats: setEl('beats'), steps: setEl('steps'),
    offset: setEl('offset'), youtube: setEl('youtube'), tuning: setEl('tuning'),
  };
  inputs.part.value = st.part;
  inputs.bpm.value = String(st.bpm);
  inputs.beats.value = String(st.beats);
  inputs.steps.value = String(st.steps);
  inputs.offset.value = (st.offsetMs / 1000).toFixed(2);
  inputs.youtube.value = st.youtubeUrl;
  inputs.tuning.value = st.tuning.join(' ');

  const firstBarEl = setEl('first_bar');
  const partialEl = setEl('partial');
  const partialLabel = $('[data-partial-label]');
  /** 見出しの「部分 49〜64」と、小節番号の表示を今の値に合わせる */
  function updatePartialUi() {
    partialLabel.hidden = !st.partial;
    partialLabel.textContent = `部分 ${st.firstBar}〜${st.firstBar + st.bars.length - 1}`;
  }
  firstBarEl.value = String(st.firstBar);
  partialEl.checked = st.partial;
  firstBarEl.addEventListener('change', () => {
    st.firstBar = clamp(parseInt(firstBarEl.value, 10) || 1, 1, 999);
    firstBarEl.value = String(st.firstBar);
    if (gridBuilt) renderGrid();
    updatePartialUi();
    refreshDirty();
    requestFrame();
  });
  partialEl.addEventListener('change', () => { st.partial = partialEl.checked; updatePartialUi(); refreshDirty(); });
  // ドラム譜: カポ・チューニング・リンクの弦の設定は出さない
  $$('[data-string-only]').forEach((x) => { x.hidden = isDrum; });

  inputs.part.addEventListener('input', () => {
    st.part = inputs.part.value;
    $('[data-part-label]').textContent = st.part;
    refreshDirty();
  });
  inputs.bpm.addEventListener('input', () => {
    const v = parseFloat(inputs.bpm.value);
    if (v >= 20 && v <= 400) { st.bpm = v; recompute(); clock.setRate(currentRate()); syncPbpmField(); requestFrame(); refreshDirty(); } // 元の BPM が変わると、練習 BPM との比（速度）も変わる
  });
  inputs.bpm.addEventListener('change', () => { inputs.bpm.value = String(st.bpm); }); // 範囲外の入力は直前の値に戻す
  inputs.offset.addEventListener('input', () => {
    const v = parseFloat(inputs.offset.value);
    if (v >= 0 && v <= 36000) { st.offsetMs = Math.round(v * 1000); recompute(); requestFrame(); refreshDirty(); }
  });
  inputs.offset.addEventListener('change', () => { inputs.offset.value = (st.offsetMs / 1000).toFixed(2); });
  $('[data-offset-now]').addEventListener('click', () => {
    st.offsetMs = Math.max(0, Math.round(clock.time() * 1000));
    inputs.offset.value = (st.offsetMs / 1000).toFixed(2);
    recompute(); requestFrame(); refreshDirty();
  });
  inputs.beats.addEventListener('change', () => {
    const v = clamp(parseInt(inputs.beats.value, 10) || st.beats, 1, 16);
    inputs.beats.value = String(v);
    if (v === st.beats) return;
    st.beats = v;
    // 小節が短くなったら、はみ出した音符は消える（元に戻せる）
    const N = stepsPerBar();
    st.bars.forEach((bar) => {
      Object.keys(bar.n).forEach((k) => { if ((Number(k) >> 3) >= N) { delete bar.n[k]; delete bar.d[k]; } });
      bar.r = bar.r.filter(([rs]) => rs < N).map(([rs, l]) => [rs, Math.min(l, N - rs)]);
      Object.keys(bar.c).forEach((k) => { if (Number(k) >= N) delete bar.c[k]; });
    });
    sel = null;
    recompute(); renderGrid(); commit();
  });
  inputs.steps.addEventListener('change', () => {
    const next = Number(inputs.steps.value);
    const prev = st.steps;
    if (next === prev) return;
    // 分割を変えたら、既存の音符の位置を比例で寄せる（同じ位置に重なったら後ろの音符が勝つ）
    const N = st.beats * next;
    st.bars.forEach((bar) => {
      const moved = {}, movedD = {};
      Object.keys(bar.n).map(Number).sort((a, b) => a - b).forEach((k) => {
        const ns = Math.min(N - 1, Math.round((k >> 3) * next / prev));
        moved[ns * 8 + (k & 7)] = bar.n[k];
        if (bar.d[k] > 1) movedD[ns * 8 + (k & 7)] = Math.min(N - ns, Math.max(1, Math.round(bar.d[k] * next / prev)));
      });
      bar.n = moved;
      bar.d = Object.fromEntries(Object.entries(movedD).filter(([k, l]) => l > 1 && moved[k] !== undefined));
      bar.r = bar.r.map(([rs, l]) => { const ns = Math.min(N - 1, Math.round(rs * next / prev)); return [ns, Math.min(N - ns, Math.max(1, Math.round(l * next / prev)))]; });
      const movedC = {};
      Object.keys(bar.c).forEach((k) => { movedC[Math.min(N - 1, Math.round(Number(k) * next / prev))] = bar.c[k]; });
      bar.c = movedC;
    });
    st.steps = next;
    sel = null;
    recompute(); renderGrid(); commit();
    updateKeyboard();
  });
  inputs.youtube.addEventListener('input', () => {
    st.youtubeUrl = inputs.youtube.value;
    const id = youtubeIdOf(st.youtubeUrl);
    inputs.youtube.setAttribute('aria-invalid', String(st.youtubeUrl.trim() !== '' && !id));
    refreshDirty();
  });
  inputs.youtube.addEventListener('change', () => {
    const id = youtubeIdOf(st.youtubeUrl);
    if (id === ytId) return;
    ytId = id;
    // 動画が変わった / 無くなった: YouTube を使っていたなら作り直す。まだ音源なしなら YouTube に切り替える
    if (srcKind === 'yt' || (srcKind === 'none' && ytId)) setSource(ytId ? 'yt' : 'none');
    else $('[data-src="yt"]').disabled = !ytId;
  });
  /* ---- メモ・画像（この TAB の。プレイヤーの「メモ」の枠に出す。編集は編集モードで）---- */
  const notes = $('[data-notes]');
  const memoView = $('[data-memo-view]', notes);
  const memoInput = setEl('memo');
  const tabImages = imageBox({ kind: 'tab', id: D.tab_id, ids: D.images || [], list: $('[data-tab-images]', notes), addLabel: $('[data-img-add]', notes), max: 10 });
  const hasSongNotes = !!notes.querySelector('.tab-notes__song, [data-live-img]'); // 曲のメモ・画像（PHP が出したもの）
  function renderNotes() {
    linkifyInto(memoView, st.memo);
    memoView.hidden = st.memo.trim() === '';
    notes.hidden = !(editMode || hasSongNotes || st.memo.trim() !== '' || tabImages.ids.length > 0);
  }
  tabImages.onChange = renderNotes;
  memoInput.value = st.memo;
  memoInput.addEventListener('input', () => { st.memo = memoInput.value; renderNotes(); refreshDirty(); });

  /* ---- カポ・チューニング・参考リンク ---- */
  const capoLabel = $('[data-capo-label]');
  const tuningLabel = $('[data-tuning-label]');
  const capoSel = setEl('capo');
  const tuningSel = setEl('tuning-preset');
  const presets = D.tuning_presets || {}; // 名前 => 音名（弦の数ごとの候補。lib/tabs.php の TAB_TUNINGS）
  const presetNameOf = (notes) => Object.keys(presets).find((n) => presets[n] === notes) ?? null;
  let customTuning = false;               // 「カスタム」を選んでいる（音名を直接入力）

  /** プレイヤーの見出し: 「Capo 2 · ドロップD」（候補に無いチューニングは音名のまま） */
  function updateHeaderLabels() {
    const notes = st.tuning.join(' ');
    capoLabel.textContent = st.capo > 0 ? `Capo ${st.capo} · ` : '';
    tuningLabel.textContent = isDrum ? '' : (presetNameOf(notes) ?? notes);
  }
  /** 弦名（グリッド・canvas）を今のチューニングで書き直す。各 .tg-name が自分の弦の番号（data-r）を持っている */
  function relabelStrings() {
    $$('.tg-name', grid).forEach((n) => { n.textContent = stringNote(Number(n.dataset.r)); });
    requestFrame();
  }
  function syncTuningUi() {
    const notes = st.tuning.join(' ');
    customTuning = customTuning || presetNameOf(notes) === null;
    tuningSel.value = customTuning ? '__custom' : notes;
    inputs.tuning.hidden = !customTuning;
    inputs.tuning.value = notes;
    updateHeaderLabels();
  }
  function setTuning(notes) {
    st.tuning = notes;
    relabelStrings();
    syncTuningUi();
    refreshDirty();
  }
  Object.keys(presets).forEach((name) => tuningSel.appendChild(new Option(`${name}  ${presets[name]}`, presets[name])));
  tuningSel.appendChild(new Option('カスタム', '__custom'));
  tuningSel.addEventListener('change', () => {
    customTuning = tuningSel.value === '__custom';
    if (customTuning) { syncTuningUi(); inputs.tuning.focus(); return; }
    setTuning(tuningSel.value.split(' '));
  });
  inputs.tuning.addEventListener('change', () => {
    const notes = inputs.tuning.value.trim().split(/ +/);
    // 弦の数は変えられない（音符の「何弦か」が意味を失う）。音名が正しいときだけ受け付ける
    if (notes.length === strings && notes.every((n) => NOTE_RE.test(n))) setTuning(notes);
    else inputs.tuning.value = st.tuning.join(' ');
  });
  capoSel.value = String(st.capo);
  capoSel.addEventListener('change', () => {
    st.capo = clamp(parseInt(capoSel.value, 10) || 0, 0, 12);
    updateHeaderLabels();
    refreshDirty();
  });

  /** 参考リンク（TAB サイトなど。リンクを張るだけ）。https の URL だけ、a 要素にする */
  const linksBox = $('[data-links]');
  const linksEdit = $('[data-links-edit]');
  const linkAddBtn = $('[data-link-add]');
  const ytTemplate = document.querySelector('template[data-yt-icon]');
  function renderLinks() {
    linksBox.replaceChildren();
    st.links.forEach((lk) => {
      let url;
      try { url = new URL(lk.u.trim()); } catch (e) { return; }
      if (url.protocol !== 'https:') return;
      const host = url.hostname.replace(/^www\./, '');
      const a = document.createElement('a');
      a.className = 'tab-chip tab-link';
      a.href = url.href;
      a.target = '_blank';
      a.rel = 'noopener noreferrer nofollow';
      if (/(^|\.)(youtube\.com|youtu\.be)$/.test(host) && ytTemplate) {
        a.appendChild(ytTemplate.content.cloneNode(true));
      } else {
        const ic = document.createElement('span');
        ic.className = 'icon';
        ic.setAttribute('aria-hidden', 'true');
        ic.textContent = 'link';
        a.appendChild(ic);
      }
      a.append(' ', lk.l.trim() !== '' ? lk.l.trim() : host);
      linksBox.appendChild(a);
    });
    linksBox.hidden = linksBox.childElementCount === 0;
  }
  function renderLinksEdit() {
    linksEdit.replaceChildren();
    st.links.forEach((lk, idx) => {
      const row = document.createElement('div');
      row.className = 'tab-links-edit__row';
      const u = document.createElement('input');
      u.type = 'url';
      u.maxLength = 500;
      u.placeholder = 'https://…';
      u.setAttribute('aria-label', 'URL');
      u.value = lk.u;
      u.addEventListener('input', () => { lk.u = u.value; renderLinks(); refreshDirty(); });
      const l = document.createElement('input');
      l.type = 'text';
      l.maxLength = 30;
      l.placeholder = '表示名';
      l.setAttribute('aria-label', '表示名');
      l.value = lk.l;
      l.addEventListener('input', () => { lk.l = l.value; renderLinks(); refreshDirty(); });
      const del = iconBtn('del', 'delete', '削除');
      del.addEventListener('click', () => { st.links.splice(idx, 1); renderLinksEdit(); renderLinks(); refreshDirty(); });
      row.append(u, l, del);
      linksEdit.appendChild(row);
    });
    linkAddBtn.disabled = st.links.length >= 10;
  }
  linkAddBtn.addEventListener('click', () => {
    if (st.links.length >= 10) return;
    st.links.push({ u: '', l: '' });
    renderLinksEdit();
    linksEdit.querySelector('.tab-links-edit__row:last-child input')?.focus();
  });

  /* ---- BPM 計測: 曲に合わせて4回以上タップすると BPM が決まる（小節の頭を合わせる「タップ」とは別）---- */
  let bpmTaps = [];
  $('[data-bpm-tap]').addEventListener('click', () => {
    const now = performance.now();
    if (bpmTaps.length && now - bpmTaps[bpmTaps.length - 1] > 2000) bpmTaps = []; // 2秒あいたら最初から
    bpmTaps = [...bpmTaps, now].slice(-8);
    const bpm = tapTempoBpm(bpmTaps);
    if (bpm === null) return;
    st.bpm = bpm;
    inputs.bpm.value = String(bpm);
    recompute();
    clock.setRate(currentRate());
    syncPbpmField();
    requestFrame();
    refreshDirty();
  });

  /* ---- 練習 BPM の欄・ループごとの BPM 増加 ---- */
  pbpmEl.addEventListener('change', () => {
    const v = parseFloat(pbpmEl.value);
    if (v >= 20 && v <= 400) setPracticeBpm(v);
    else syncPbpmField();
  });
  pbpmEl.addEventListener('blur', syncPbpmField); // 入力が終わったら、実際に効いた BPM（YouTube で丸められたとき）に直す

  /* ---------------------------------------------------------------
   * 保存・削除・未保存の警告
   * --------------------------------------------------------------- */
  const saveBtn = $('[data-save]');
  const noteEl = $('[data-note]');
  let saving = false;
  let dirty = false;

  function isDirty() { return JSON.stringify(payload()) !== savedStr; }
  function refreshDirty() {
    dirty = isDirty();
    if (noteEl.dataset.error) return;    // エラー表示中はそのまま
    noteEl.hidden = !dirty;
    noteEl.textContent = dirty ? '未保存' : '';
  }
  /** 画面上部のお知らせ（app.js の showToast）で知らせる */
  function notify(message, type) {
    const t = document.createElement('div');
    t.className = 'flash flash--' + (type || 'success');
    t.setAttribute('role', 'status');
    t.textContent = message;
    if (typeof window.showToast === 'function') window.showToast(t); else window.alert(message);
  }

  async function post(body, keepalive, url = 'api_tab_save') {
    const res = await fetch(url, {
      method: 'POST',
      keepalive: !!keepalive, // ページを閉じる直前の送信用
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || '保存できませんでした');
    return json;
  }

  async function save() {
    if (saving) return;
    saving = true;
    saveBtn.disabled = true;
    const body = payload();
    try {
      const json = await post({ action: 'save', tab_id: D.tab_id, updated_at: updatedAt, ...body });
      updatedAt = json.updated_at;
      savedStr = JSON.stringify(body);
      refreshDirty();
      notify('保存しました');
    } catch (err) {
      notify(err.message || '通信に失敗しました', 'error'); // 409（他の人が更新）もここに来る
    } finally {
      saving = false;
      saveBtn.disabled = false;
    }
  }
  saveBtn.addEventListener('click', save);

  // 誰でも削除できる。履歴に残るので、曲の一覧の「削除」から復元できる
  const delBtn = $('[data-delete]');
  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      if (!window.confirm('削除しますか？')) return;
      try {
        const json = await post({ action: 'delete', tab_id: D.tab_id });
        leaving = true;
        window.location.href = json.redirect;
      } catch (err) {
        notify(err.message || '削除できませんでした', 'error');
      }
    });
  }

  /* ---------------------------------------------------------------
   * インポート（ASCII の TAB・画像から）
   *   文字: parseAsciiTab（ブラウザの中。<pure> の中の関数）で読む。画像・読めない文字: api_tab_ai.php（AI）。
   *   どちらも結果は「プレビュー」に出すだけ。置換 / 末尾に追加 を押すまで譜面は変わらず、押したあとも保存はしない
   *   （元に戻す・やり直すで戻せて、保存ボタンで初めて保存される）
   * --------------------------------------------------------------- */
  const importDlg = $('[data-import]');
  const importText = $('[data-import-text]', importDlg);
  const importStatus = $('[data-import-status]', importDlg);
  const importImages = $('[data-import-images]', importDlg);
  const importApply = $$('[data-import-apply]', importDlg);
  let imported = null; // {bars: [...], bpm, tuning} プレビュー中の結果

  function showImport(r, label) {
    imported = r;
    const notes = r.bars.reduce((n, b) => n + (b.n || []).length, 0);
    importStatus.textContent = `${label}: ${r.bars.length}小節 / ${notes}音${(r.notes || r.warnings || []).length ? ' · ' + (r.notes || r.warnings).join(' / ') : ''}`;
    importApply.forEach((b) => { b.disabled = r.bars.length === 0; });
  }
  function importOpen() {
    imported = null;
    importApply.forEach((b) => { b.disabled = true; });
    importStatus.textContent = '';
    // AI に読ませる画像は、この TAB に添付した画像から選ぶ（サーバーが自分でファイルを読む）
    importImages.replaceChildren();
    if (D.ai) {
      tabImages.ids.forEach((id, k) => {
        const label = document.createElement('label');
        label.className = 'tab-import__img';
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'import-image';
        radio.value = String(id);
        const img = document.createElement('img');
        img.src = `attach_image?k=song_tab&id=${id}&t=1`;
        img.alt = '';
        label.append(radio, img);
        importImages.appendChild(label);
        if (k === 0) radio.checked = false;
      });
    }
    $('[data-import-ai]', importDlg).hidden = !D.ai;
    importDlg.showModal();
  }
  $('[data-import-open]').addEventListener('click', importOpen);
  $('[data-import-close]', importDlg).addEventListener('click', () => importDlg.close());
  $('[data-import-parse]', importDlg).addEventListener('click', () => {
    const r = parseAsciiTab(importText.value, strings, stepsPerBar());
    if (r.error) { imported = null; importApply.forEach((b) => { b.disabled = true; }); importStatus.textContent = r.error; return; }
    showImport(r, '読込');
  });
  $('[data-import-ai]', importDlg).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const radio = importImages.querySelector('input:checked');
    const body = { tab_id: D.tab_id, beats_per_bar: st.beats, steps_per_beat: st.steps };
    if (radio) body.image_id = Number(radio.value);
    else if (importText.value.trim() !== '') body.text = importText.value;
    else { importStatus.textContent = '画像を選ぶか、テキストを貼り付けてください'; return; }
    btn.disabled = true;
    importStatus.textContent = '…';
    try {
      const r = await post(body, false, 'api_tab_ai');
      showImport({ bars: r.data.bars, bpm: r.bpm, tuning: r.tuning, notes: r.notes }, 'AI');
    } catch (err) {
      importStatus.textContent = err.message || 'AI で読み取れませんでした';
    } finally {
      btn.disabled = false;
    }
  });
  importApply.forEach((btn) => btn.addEventListener('click', () => {
    if (!imported) return;
    const add = imported.bars.map(barFromJson);
    if (btn.dataset.importApply === 'replace') {
      st.bars = add.slice(0, MAX_BARS);
      if (imported.bpm) { st.bpm = imported.bpm; inputs.bpm.value = String(st.bpm); }
      if (imported.tuning) setTuning(imported.tuning.split(' '));
    } else {
      st.bars = st.bars.concat(add).slice(0, MAX_BARS);
    }
    if (st.bars.length === 0) st.bars.push(newBar());
    sel = null;
    recompute();
    renderGrid();
    commit();
    updatePartialUi();
    importDlg.close();
    notify('反映しました（保存はまだです）');
  }));

  /* ---- 履歴: 開いたときに一覧を取りに行く。先頭 = 今の状態なので、復元ボタンは2行目から ---- */
  const histDlg = $('[data-history]');
  const histList = $('[data-history-list]');
  async function openHistory() {
    histList.replaceChildren();
    histDlg.showModal();
    try {
      const json = await post({ action: 'history', tab_id: D.tab_id });
      json.items.forEach((it, idx) => {
        const li = el('li', 'tab-history__item');
        li.appendChild(el('span', 'tab-history__label tab-history__label--' + it.action, it.label));
        li.appendChild(el('span', 'tab-history__who muted small', it.at + ' · ' + it.user)); // 名前は textContent で入れる
        if (idx > 0) {
          const b = el('button', 'btn btn--sm');
          b.type = 'button';
          b.dataset.rev = String(it.rev_id);
          const ic = el('span', 'icon', 'restore');
          ic.setAttribute('aria-hidden', 'true');
          b.append(ic, ' 復元');
          li.appendChild(b);
        }
        histList.appendChild(li);
      });
    } catch (err) {
      histDlg.close();
      notify(err.message || '履歴を取得できませんでした', 'error');
    }
  }
  $('[data-history-open]').addEventListener('click', openHistory);
  $('[data-history-close]').addEventListener('click', () => histDlg.close());
  histList.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rev]');
    if (!b) return;
    const msg = isDirty() ? '未保存の変更は失われます。復元しますか？' : '復元しますか？';
    if (!window.confirm(msg)) return;
    b.disabled = true;
    try {
      const json = await post({ action: 'restore', rev_id: Number(b.dataset.rev) });
      leaving = true; // 復元後の再読み込みで「未保存」の警告を出さない
      window.location.href = json.redirect;
    } catch (err) {
      b.disabled = false;
      notify(err.message || '復元できませんでした', 'error');
    }
  });

  window.addEventListener('beforeunload', (e) => {
    if (leaving || !isDirty()) return;
    e.preventDefault();
    e.returnValue = ''; // 古いブラウザ（Safari など）はこれが無いと警告を出さない
  });

  /* ---------------------------------------------------------------
   * 練習 / 編集 の切り替えとキーボード
   * --------------------------------------------------------------- */
  const modeBtn = $('[data-mode-toggle]');
  function setMode(on) {
    editMode = on;
    editBox.hidden = !on;
    tabImages.setEditable(on);
    renderNotes();
    modeBtn.setAttribute('aria-pressed', String(on));
    modeBtn.title = on ? '練習' : '編集';
    modeBtn.setAttribute('aria-label', modeBtn.title);
    $('.icon', modeBtn).textContent = on ? 'school' : 'edit';
    if (on && !gridBuilt) renderGrid();
    if (!on) setTapMode(false);
    markSel();
    requestFrame();
  }

  document.addEventListener('keydown', (e) => {
    const mod = e.ctrlKey || e.metaKey;
    const key = e.key;
    if (mod && key.toLowerCase() === 's') { // ブラウザの「ページを保存」ではなく、TAB の保存
      e.preventDefault();
      if (editMode) save();
      return;
    }
    // 入力欄の中では、ふつうの文字入力・Ctrl+Z（入力欄の中の取り消し）を邪魔しない
    if (e.target.closest && e.target.closest('input, textarea, select, [contenteditable]')) return;
    if (mod && editMode) {
      if (key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) redo(); else undo(); }
      else if (key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
      return;
    }
    if (e.altKey || mod) return;
    if (key === 'Escape' && !metroMenu.hidden) { toggleMetroMenu(false); return; }
    if (key === ' ') { e.preventDefault(); togglePlay(); return; }
    if (key === '[') { setLoopPoint('a'); return; }   // A 地点
    if (key === ']') { setLoopPoint('b'); return; }   // B 地点
    if (key === '\\') { setLoopPoint(null); return; } // リピート解除
    if (key === 'm' || key === 'M') { addMarker(); return; } // 練習用マーカー
    const editing = editMode && sel;
    if (key === 'ArrowLeft' || key === 'ArrowRight') {
      e.preventDefault();
      const dir = key === 'ArrowLeft' ? -1 : 1;
      if (editing) moveSel(dir, 0); else seekTo(clock.time() + 5 * dir);
    } else if (key === 'ArrowUp' || key === 'ArrowDown') {
      if (editing) { e.preventDefault(); moveSel(0, key === 'ArrowUp' ? -1 : 1); }
    } else if (editMode && (key === 't' || key === 'T')) {
      tapNow();
    } else if (editing) {
      if (/^[0-9]$/.test(key)) typeDigit(Number(key));
      else if (!isDrum && (key === 'x' || key === 'X')) { lastDigit = null; placeNote(-1); }
      else if (key === 'r' || key === 'R') { lastDigit = null; if (!isDrum) placeRest(); }
      else if (key === 'c' || key === 'C') { if (!isDrum) { kb.chord = !kb.chord; updateKeyboard(); } }
      else if (key === '-') { const i = BASES.findIndex(([, b]) => b === kb.base); const nb = BASES.slice(i + 1).find(([, b]) => durFor(b, false, false) !== null); if (nb) kb.base = nb[1]; kb.dot = kb.trip = false; updateKeyboard(); }
      else if (key === '+' || key === '=') { const i = BASES.findIndex(([, b]) => b === kb.base); const nb = BASES.slice(0, Math.max(0, i)).reverse().find(([, b]) => durFor(b, false, false) !== null); if (nb) kb.base = nb[1]; kb.dot = kb.trip = false; updateKeyboard(); }
      else if (key === '.') { if (durFor(kb.base, !kb.dot, false) !== null) { kb.dot = !kb.dot; kb.trip = false; updateKeyboard(); } }
      else if (key === ',') { if (durFor(kb.base, false, !kb.trip) !== null) { kb.trip = !kb.trip; kb.dot = false; updateKeyboard(); } }
      else if (key === 'Backspace' || key === 'Delete') { e.preventDefault(); lastDigit = null; setFret(sel.b, sel.s, sel.r, null, null); }
      else if (key === 'Escape') { sel = null; markSel(); }
    }
  });
  // ボタンにフォーカスがあるとき、Space は離したときにクリックになる。再生の切り替えだけにしたいので止める
  document.addEventListener('keyup', (e) => {
    if (e.key === ' ' && e.target.closest && e.target.closest('button')) e.preventDefault();
  });

  /* ---------------------------------------------------------------
   * 起動
   * --------------------------------------------------------------- */
  readColors();
  // ライト / ダークの切り替え（<html data-theme>）に追従
  new MutationObserver(readColors).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  new ResizeObserver(resize).observe(stage);
  recompute();
  if (practice.ab) loop = { a: practice.ab[0] / 1000, b: practice.ab[1] / 1000 }; // 前回の A-B リピート
  updateAbButtons();
  syncMetroUi();
  renderKeyboard();
  updatePartialUi();
  renderNotes();
  renderMarkers();
  renderLinks();
  renderLinksEdit();
  syncTuningUi();
  updateSecPick();
  setSource(ytId ? 'yt' : 'none');
  resize();
  if (D.edit) setMode(true);
  updateHistoryButtons();
  refreshDirty();
})();
