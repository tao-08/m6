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
   * 曲の一覧ページ: 新規作成フォーム（form.tab-new）
   *   1. 楽器のタイル（ラジオ）を選び替えたら、パート名の欄に「その楽器の候補」（Gt1 / Ba / Dr …。PHP が data-part に入れてある）を入れる
   *      ただし、ユーザーが自分でパート名を書き換えたあとは上書きしない（せっかく書いた名前が消えるとイライラするので）
   *   2. 「部分」のチェックが外れている間は、開始小節の欄を使えなくする（disabled にした入力欄はフォームで送られない）
   *   JS が動かなくても、フォームは PHP が出した初期値のまま送れる（プログレッシブ・エンハンスメント = JS は「あれば便利」の位置づけ）
   * --------------------------------------------------------------- */
  const newForm = document.querySelector('form.tab-new');
  if (newForm) {
    const nameInput = newForm.querySelector('input[name="part"]');
    const presetRadios = [...newForm.querySelectorAll('input[name="preset"]')];
    const partialCheck = newForm.querySelector('input[name="partial"]');
    const firstBarInput = newForm.querySelector('input[name="first_bar"]');
    // 手で書き換えたか。最初は「いま入っている名前が、選択中の楽器の候補と違う」かどうかで決める
    //   （ブラウザが再読み込みのときに前回の入力を復元することがあるため。復元された名前は、ユーザーが書いたものとして守る）
    const checkedRadio = presetRadios.find((r) => r.checked);
    let typed = !!checkedRadio && nameInput.value !== checkedRadio.dataset.part;
    // input イベント = ユーザーが入力したとき（プログラムが value を入れたときは起きない）。空に戻したら、また候補を入れてよい状態に戻す
    nameInput.addEventListener('input', () => { typed = nameInput.value.trim() !== ''; });
    presetRadios.forEach((radio) => radio.addEventListener('change', () => {
      if (radio.checked && !typed) nameInput.value = radio.dataset.part || '';
    }));
    const syncFirstBar = () => { firstBarInput.disabled = !partialCheck.checked; };
    partialCheck.addEventListener('change', syncFirstBar);
    syncFirstBar();
  }

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
  function openImage(attachKind, id, pdf) {
    const modal = document.querySelector('[data-live-modal]');
    if (!modal) return;
    const img = modal.querySelector('[data-live-modal-img]');
    const frame = modal.querySelector('[data-live-modal-pdf]');
    img.hidden = !!pdf;
    frame.hidden = !pdf;
    if (pdf) img.removeAttribute('src'); else frame.removeAttribute('src');
    (pdf ? frame : img).src = `attach_image?k=${attachKind}&id=${id}`;
    modal.querySelector('[data-live-modal-dl]').href = `attach_image?k=${attachKind}&id=${id}&dl=1`;
    modal.querySelector('[data-live-modal-taken]').textContent = '';
    modal.showModal();
  }

  /**
   * 画像の並び（サムネイル + 追加ボタン + 削除）。kind = 'tab'（id = tab_id）/ 'song'（id = 曲キー）
   *   戻り値の setEditable(true) で追加・削除のボタンが出る。ids は今ある画像の ID
   */
  function imageBox({ kind, id, ids, pdfs, files, list, addLabel, max }) {
    const attachKind = kind === 'tab' ? 'song_tab' : 'song_meta';
    const input = addLabel.querySelector('[data-img-input]');
    let editable = false;
    const box = { ids: ids.slice(), pdfs: (pdfs || []).slice(), files: (files || []).slice(), onChange: null }; // pdfs = ids のうち PDF のもの
    const send = async (fd) => {
      fd.append('kind', kind);
      fd.append('id', String(id));
      const res = await fetch('api_tab_image', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': token }, body: fd });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.error || '画像を保存できませんでした');
      box.ids = json.images;
      box.pdfs = json.pdfs || [];
      box.files = json.files || [];
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
        open.addEventListener('click', () => {
          if (box.files.includes(imgId)) location.href = `attach_image?k=${attachKind}&id=${imgId}&dl=1`; // プレビューできないファイルはダウンロード
          else openImage(attachKind, imgId, box.pdfs.includes(imgId));
        });
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
    imageBox({ kind: 'song', id: S.key, ids: S.images, pdfs: S.pdfs, files: S.files, list: area.querySelector('[data-song-images]'), addLabel: area.querySelector('[data-img-add]'), max: 10 }).setEditable(true);
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

  /* ---- 使い方（？ボタン）----
   *   ？で使い方のモーダルを開く。中の「ボタン名を表示」で、root に is-help を付け外しする。
   *   is-help のときは tab.css が各ボタンの aria-label / title をボタンの下に文字で出す
   *   （スマホは title のポップアップがタップでは出ないので、初めての人向け）。
   *   文字は CSS の attr() で出すので、あとから JS が作るボタン（グリッド・キーボード）にも自動で付く。
   *   ボタン名は初めて開いたときは ON。切り替えた結果は端末に覚えておく */
  (function setupHelp() {
    const dlg = $('[data-guide]'); // data-help は入力欄の説明の文字（.tab-help-field）で使っているので別の名前
    const box = $('[data-help-labels]');
    if (!dlg || !box) return;
    let on = true;
    try { on = localStorage.getItem('tabHelp') !== '0'; } catch (e) { /* 保存できなくても動く（毎回 ON） */ }
    const apply = () => {
      root.classList.toggle('is-help', on);
      box.checked = on;
    };
    apply();
    box.addEventListener('change', () => {
      on = box.checked;
      apply();
      try { localStorage.setItem('tabHelp', on ? '1' : '0'); } catch (e) { /* 無視 */ }
      window.dispatchEvent(new Event('resize')); // ボタンの高さが変わるので canvas の大きさを測り直させる
    });
    $('[data-help-open]').addEventListener('click', () => dlg.showModal());
    $('[data-help-close]').addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); }); // 外側（背景）を押しても閉じる
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
   * BPM 自動検知: 音声（チャンネルごとの Float32Array の配列）から BPM を推定する。10秒未満・無音に近いときは null
   *   モノラルに混ぜ、曲の中ほど（先頭 30秒 か 10% のどちらか短いほうから最大90秒）だけを使う
   *   512サンプルごとのエネルギーの対数の増え方（半波整流）= 音の立ち上がりの強さ → 0.5秒の移動平均を引いて 0 未満は 0
   *   その自己相関を取り、60〜200 BPM を 0.1 刻みで、拍の周期の 1〜4 倍のずれの相関を足して採点（1/k の重み）
   *   倍・半分の取り違えを減らすため、120 BPM 付近を少しだけ優遇する（対数ガウス）
   * @returns {number|null} 小数1桁に丸めた BPM
   */
  function detectBpm(channels, sampleRate) {
    const HOP = 512;
    const total = channels[0] ? channels[0].length : 0;
    const start = Math.floor(Math.min(30 * sampleRate, total * 0.1));
    const end = Math.min(total, start + 90 * sampleRate);
    if ((end - start) < 10 * sampleRate) return null;
    const nFrames = Math.floor((end - start) / HOP);
    const fps = sampleRate / HOP;
    const energy = new Float64Array(nFrames);
    let peak = 0;
    for (let f = 0; f < nFrames; f++) {
      let s = 0;
      const from = start + f * HOP;
      for (let i = from; i < from + HOP; i++) {
        let v = 0;
        for (let c = 0; c < channels.length; c++) v += channels[c][i];
        v /= channels.length;
        s += v * v;
      }
      energy[f] = Math.sqrt(s / HOP);
      if (energy[f] > peak) peak = energy[f];
    }
    if (peak < 1e-3) return null; // ほぼ無音
    const onset = new Float64Array(nFrames);
    for (let i = 1; i < nFrames; i++) onset[i] = Math.max(0, Math.log(energy[i] + 1e-9) - Math.log(energy[i - 1] + 1e-9));
    // 0.5秒の移動平均を引く（累積和で計算）
    const half = Math.max(1, Math.round(fps * 0.25));
    const cum = new Float64Array(nFrames + 1);
    for (let i = 0; i < nFrames; i++) cum[i + 1] = cum[i] + onset[i];
    const env = new Float64Array(nFrames);
    for (let i = 0; i < nFrames; i++) {
      const a = Math.max(0, i - half), b = Math.min(nFrames, i + half + 1);
      env[i] = Math.max(0, onset[i] - (cum[b] - cum[a]) / (b - a));
    }
    // 自己相関（ずれが大きいほど重なる長さが減るので、重なりの数で割る）
    const maxLag = Math.min(Math.ceil(fps * 4) + 2, nFrames - 2);
    const ac = new Float64Array(maxLag + 1);
    for (let lag = 0; lag <= maxLag; lag++) {
      let s = 0;
      for (let i = lag; i < nFrames; i++) s += env[i] * env[i - lag];
      ac[lag] = s / (nFrames - lag);
    }
    if (ac[0] <= 0) return null;
    const at = (x) => { // 線形補間
      const i = Math.floor(x);
      return i + 1 > maxLag ? 0 : ac[i] + (ac[i + 1] - ac[i]) * (x - i);
    };
    let best = null, bestScore = 0;
    for (let t = 600; t <= 2000; t++) {
      const bpm = t / 10;
      const p = 60 / bpm * fps;
      let score = 0;
      for (let k = 1; k <= 4; k++) score += at(k * p) / k;
      const oct = Math.log2(bpm / 120) / 0.6;
      score *= Math.exp(-0.5 * oct * oct);
      if (score > bestScore) { bestScore = score; best = bpm; }
    }
    return best === null ? null : Math.round(best * 10) / 10;
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
  let srcFile = null;
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

  /*
   * 動画の位置・大きさ（この端末の localStorage に覚える）
   *   つまみをドラッグ = 画面に浮かせて(fixed)好きな位置へ / 右下の角をドラッグ = 大きさ / 復元 = 元の（ヘッダー下に固定の）位置へ
   *   iframe の上ではクリックを YouTube に奪われるので、操作は動画の上に重ねたつまみ・角だけで行う（pointer capture でドラッグ中も追従）
   */
  (function setupVideoBox() {
    const KEY = 'tabVideoBox';
    const MIN_W = 160;
    const dragEl = $('[data-video-drag]', videoBox);
    const sizeEl = $('[data-video-size]', videoBox);
    const resetEl = $('[data-video-reset]', videoBox);
    let box = null; // {x, y, w}（px）。null = 元の位置
    try {
      const v = JSON.parse(localStorage.getItem(KEY));
      if (v && [v.x, v.y, v.w].every(Number.isFinite)) box = { x: v.x, y: v.y, w: v.w };
    } catch (e) { /* 保存できなくても動く */ }

    /** 画面の外に出ないように収める（高さは 16:9 なので幅から決まる） */
    function fit(b) {
      const w = clamp(b.w, MIN_W, window.innerWidth);
      const h = w * 9 / 16;
      return { w, x: clamp(b.x, 0, Math.max(0, window.innerWidth - w)), y: clamp(b.y, 0, Math.max(0, window.innerHeight - h)) };
    }
    function apply() {
      if (box) box = fit(box);
      videoBox.classList.toggle('is-float', !!box);
      resetEl.hidden = !box;
      videoBox.style.left = box ? box.x + 'px' : '';
      videoBox.style.top = box ? box.y + 'px' : '';
      videoBox.style.width = box ? box.w + 'px' : '';
    }
    function save() {
      try { if (box) localStorage.setItem(KEY, JSON.stringify(box)); else localStorage.removeItem(KEY); } catch (e) { /* 無視 */ }
    }
    /** ドラッグの共通処理: 押した位置からの移動量を move に渡す */
    function drag(handle, onStart, onMove) {
      handle.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        handle.setPointerCapture(e.pointerId);
        const r = videoBox.getBoundingClientRect();
        const start = onStart(r);
        const x0 = e.clientX, y0 = e.clientY;
        const move = (ev) => { onMove(start, ev.clientX - x0, ev.clientY - y0); apply(); };
        const end = () => {
          handle.removeEventListener('pointermove', move);
          handle.removeEventListener('pointerup', end);
          handle.removeEventListener('pointercancel', end);
          save();
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
      });
    }
    drag(dragEl, (r) => { box = box || { x: r.left, y: r.top, w: r.width }; return { x: box.x, y: box.y }; },
      (s, dx, dy) => { box = { ...box, x: s.x + dx, y: s.y + dy }; });
    drag(sizeEl, (r) => { box = box || { x: r.left, y: r.top, w: r.width }; return { w: box.w }; },
      (s, dx) => { box = { ...box, w: s.w + dx }; });
    resetEl.addEventListener('click', () => { box = null; apply(); save(); });
    window.addEventListener('resize', apply); // 画面の向きを変えたとき、はみ出さないように
    apply();
  })();
  const fileInput = $('[data-file]');

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
    srcFile = kind === 'file' ? file : null; // BPM 自動検知に使う手元のファイル
    loadWave(srcFile);                   // 開始位置の波形も同じファイルから
    const detectBtn = $('[data-bpm-detect]');
    if (detectBtn) detectBtn.disabled = !srcFile;
    videoBox.hidden = kind !== 'yt';
    clock.setRate(currentRate());
    if (prevTime > 0) clock.seek(prevTime);
    $$('[data-src]').forEach((b) => b.classList.toggle('is-active', b.dataset.src === kind));
    $('[data-src="yt"]').disabled = !ytId;
    metroReset();
    updateUnsyncBtn();
    onClockChange();
    updateSetup(); // 音源が変わると、準備ステップの ①（音源）③（開始位置が要るか）の判定が変わる
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
    // 再生ボタンは2つある（操作バーと、準備ステップ ③ のミニ操作）。$('[data-play]') は DOM で先に出てくる1つ目しか返さないので、
    // $$（= querySelectorAll）で全部に反映する。片方だけ「再生」のまま、のような食い違いを防ぐ
    const label = on ? '一時停止' : '再生';
    $$('[data-play]').forEach((btn) => {
      $('.icon', btn).textContent = on ? 'pause' : 'play_arrow';
      btn.setAttribute('aria-label', label);
      btn.title = label + '（Space）';
    });
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
    if (wave) drawWave();
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
    else if (d.restart !== undefined) { // 最初から: 止めて 0 秒に戻し、そのまま再生（カウントインがあれば鳴らしてから）
      ensureAudio();
      if (counting) cancelCountIn();
      clock.pause();
      seekTo(0);
      if (metro.count > 0 && audioCtx) startCountIn(); else clock.play();
    }
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
    document.body.classList.toggle('has-tab-pad', !pad.hidden); // 画面の下・横にキーボードが出ている間、保存ボタンなどを避ける（tab.css）
    syncPadHeight(); // 出した・隠した直後の高さを、すぐ CSS 変数に反映する
    updateSecPick();
  }

  /* ---- キーボード（.tab-pad）の高さを CSS 変数 --tab-pad-h に入れる ----
   *   スマホではキーボードが画面の一番下に出て、後ろのマスや保存ボタンを隠してしまう。
   *   CSS（tab.css）が --tab-pad-h を使って「保存ボタンをキーボードの真上に出す」「選んだマスがキーボードに隠れないようにスクロールする」を計算する。
   *   ResizeObserver = 「要素の大きさが変わったら教えてくれる」ブラウザの標準の仕組み。
   *     パネルの切り替え（音 ↔ コード）・画面の回転・文字サイズの変更で、キーボードの高さは変わる。そのたびに測り直す
   *   隠れているとき（display: none）の高さは 0。広い画面（900px 以上）は右の横に出る（下を隠さない）ので 0 のまま */
  const padNarrow = window.matchMedia('(max-width: 899px)');
  function syncPadHeight() {
    const h = !pad.hidden && padNarrow.matches ? Math.ceil(pad.getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty('--tab-pad-h', h + 'px');
  }
  if (typeof ResizeObserver === 'function') new ResizeObserver(syncPadHeight).observe(pad);
  padNarrow.addEventListener('change', syncPadHeight); // 画面幅が 900px をまたいだ（スマホ ↔ PC の幅）とき
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

  /**
   * タップしたマスを、画面内キーボード・保存ボタンに隠れない位置までスクロールする。
   *   キーボードが出たあと（次の描画フレーム = requestAnimationFrame）に実行する: 出る前の画面の大きさで計算すると、出たあとに隠れてしまうため。
   *   block / inline: 'nearest' = すでに見えていれば動かさない。見えていないときだけ、いちばん近い位置まで最小限スクロールする
   *   隠れる分の余白は CSS の scroll-margin-bottom（tab.css。--tab-pad-h = キーボードの高さ）が確保している */
  function keepVisible(elem) {
    requestAnimationFrame(() => {
      syncPadHeight(); // パネルを切り替えた（音 ↔ コード）直後は、ResizeObserver がまだ高さを測り直していないことがあるので、ここでも測る
      elem.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    });
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
      keepVisible(chordCell);
      return;
    }
    const cell = e.target.closest('.tg-cell');
    if (cell) {
      select(Number(cell.closest('.tg-bar').dataset.bar), Number(cell.dataset.s), Number(cell.dataset.r), false);
      keepVisible(cell);
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
    if (title) {
      b.title = title; // title = パソコンでマウスを乗せたときのポップアップ。ショートカット（Ctrl+Z など）も書いておく
      // aria-label = スクリーンリーダー（読み上げ）用の名前。末尾の「（Ctrl+Z）」はスマホには関係なく、読み上げが長くなるだけなので外す
      //   正規表現 /（[^）]*）$/ = 「末尾の全角かっこ1組（かっこの中は「）」以外の文字）」
      b.setAttribute('aria-label', title.replace(/（[^）]*）$/, ''));
    }
    return b;
  }
  /** アイコン（Material Symbols）のボタン。↶ ↷ ◀ ▶ ▲ ▼ のような「文字の記号」は、端末（フォント）によって形がバラバラなので、アイコンにそろえる */
  function kbIcon(iconName, kbData, cls, title) {
    const b = kbBtn('', kbData, cls, title);
    const i = el('span', 'icon', iconName);
    i.setAttribute('aria-hidden', 'true');
    b.appendChild(i);
    return b;
  }
  function kbRow(...children) {
    const r = el('div', 'tab-pad__row');
    children.flat().forEach((c) => r.appendChild(c));
    return r;
  }
  /** 折り返さず横スクロールする行（ボタンの数が多い行用） */
  function kbScrollRow(...children) {
    const r = kbRow(...children);
    r.classList.add('tab-pad__row--scroll');
    return r;
  }
  function renderKeyboard() {
    pad.replaceChildren();
    // 上のタブ: 音 / コード（ドラム譜はコードなし）+ 元に戻す・やり直す + 閉じる（×）
    //   閉じる: スマホには Esc キーが無く、キーボードを閉じる方法が無かったので足した（マスの選択を外すと、キーボードも消える）
    const tabs = kbRow(kbBtn('音', 'panel:note', 'tab-pad__tab', '音を入力'), ...(isDrum ? [] : [kbBtn('コード', 'panel:chord', 'tab-pad__tab', 'コードを入力')]),
      kbIcon('undo', 'undo', 'tab-pad__sm', '元に戻す（Ctrl+Z）'), kbIcon('redo', 'redo', 'tab-pad__sm', 'やり直す（Ctrl+Y）'),
      kbIcon('close', 'close', 'tab-pad__sm tab-pad__close', '閉じる（Esc）'));
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
      // 数が多い行（フレット 10〜24・音価）は、折り返さず 1 行のまま横スクロール（tab-pad__row--scroll）: 折り返すとキーボードが高くなって、譜面を隠してしまう
      note.appendChild(kbScrollRow(...Array.from({ length: 15 }, (_, i) => kbBtn(String(i + 10), `fret:${i + 10}`, 'tab-pad__sm'))));
      note.appendChild(kbScrollRow(...BASES.map(([label, base]) => kbBtn(label, `base:${base}`, 'tab-pad__sm', `音価 ${label}`)),
        kbBtn('付点', 'dot', 'tab-pad__sm', '付点（.）'), kbBtn('3連', 'trip', 'tab-pad__sm', '3連（,）')));
    }
    // 移動（◀ ▶ ▲ ▼）と削除。弦の譜面は「x・休符・和音」と同じ行にまとめる（スマホの幅で 1 行に収まる。行が減るぶん、キーボードが低くなって譜面が隠れにくい）
    const moveBtns = [kbIcon('chevron_left', 'move:-1:0', 'tab-pad__sm', '左（←）'), kbIcon('chevron_right', 'move:1:0', 'tab-pad__sm', '右（→）'),
      kbIcon('keyboard_arrow_up', 'move:0:-1', 'tab-pad__sm', '上の弦（↑）'), kbIcon('keyboard_arrow_down', 'move:0:1', 'tab-pad__sm', '下の弦（↓）'),
      kbBtn('削除', 'del', 'tab-pad__sm', '削除（Backspace）')];
    if (isDrum) note.appendChild(kbRow(...moveBtns));
    else {
      note.appendChild(kbRow(kbBtn('x', 'fret:-1', 'tab-pad__sm', 'ミュート（x）'), kbBtn('休符', 'rest', 'tab-pad__sm', '休符（r）'),
        kbBtn('和音', 'chordmode', 'tab-pad__sm', '和音: 同じ位置に重ねる（c）'), ...moveBtns));
    }
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
    else if (kind === 'close') { sel = null; markSel(); } // 閉じる（×）: マスの選択を外す = キーボードが消える（PC の Esc と同じ）
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
    if (v >= 0 && v <= 36000) { st.offsetMs = Math.round(v * 1000); recompute(); metroReset(); requestFrame(); refreshDirty(); drawWave(); }
  });
  inputs.offset.addEventListener('change', () => { inputs.offset.value = (st.offsetMs / 1000).toFixed(2); });
  /** 開始位置（ミリ秒）を変える。保存はしない */
  function setOffset(ms) {
    st.offsetMs = clamp(Math.round(ms), 0, 36000000);
    inputs.offset.value = (st.offsetMs / 1000).toFixed(2);
    recompute(); metroReset(); requestFrame(); refreshDirty(); drawWave();
  }
  $('[data-offset-now]').addEventListener('click', () => setOffset(clock.time() * 1000));
  /*
   * 開始位置から確認: 開始位置ちょうどから、メトロノームを付けて鳴らす（1拍前の助走は無し。押したらすぐ1拍目が鳴る）。1小節目の頭のクリックと曲の頭が揃えば OK。
   *   以前の ±0.1秒（再生位置を動かすだけ）は、止まっていると音も表示も変わらず、効いていないように見えた。
   *   いまの ± は開始位置そのものを動かして、再生中ならすぐ鳴らし直す（ずれを耳で詰められる）
   */
  function playFromOffset() {
    ensureAudio();
    if (!metro.on) { metro.on = true; metroChanged(); }
    if (counting) cancelCountIn();
    seekTo(st.offsetMs / 1000);
    metroReset(st.offsetMs / 1000);        // 開始位置ちょうどの1拍目を飛ばさない（再生開始の数ミリ秒の遅れで schedPos が追い越されないように）
    if (!clock.playing) clock.play();      // カウントインは要らない（すぐ1拍目が鳴る）
  }
  $('[data-offset-play]').addEventListener('click', playFromOffset);
  $$('[data-offset-nudge]').forEach((b) => b.addEventListener('click', () => {
    setOffset(st.offsetMs + Number(b.dataset.offsetNudge) * 1000);
    if (clock.playing) playFromOffset();
  }));

  /* ---- 波形（手元のファイルだけ）: 端末の中で読み込む（送信なし）。1ミリ秒ごとの最大振幅だけ持つ ---- */
  const WAVE_RATE = 1000;
  const WAVE_SPANS = [1, 2, 4, 8, 16, 32]; // 画面に出す秒数
  const waveBox = $('[data-wave]');
  const waveCanvas = $('[data-wave-canvas]');
  let wave = null;                       // { peaks, max }
  let waveSpan = 2;                      // WAVE_SPANS の番号
  let waveSeq = 0;                       // 古いファイルの読み込みが、新しいファイルの波形を上書きしないように
  async function loadWave(file) {
    const seq = ++waveSeq;
    wave = null;
    waveBox.hidden = true;
    if (!file) return;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    let ctx = null;
    try {
      ctx = new AC();
      const buf = await ctx.decodeAudioData(await file.arrayBuffer());
      if (seq !== waveSeq) return;
      const peaks = new Float32Array(Math.ceil(buf.duration * WAVE_RATE) + 1);
      const k = WAVE_RATE / buf.sampleRate;
      for (let c = 0; c < buf.numberOfChannels; c++) {
        const d = buf.getChannelData(c);
        for (let i = 0; i < d.length; i++) {
          const v = Math.abs(d[i]);
          const j = (i * k) | 0;
          if (v > peaks[j]) peaks[j] = v;
        }
      }
      let max = 0;
      for (let i = 0; i < peaks.length; i++) if (peaks[i] > max) max = peaks[i];
      wave = { peaks, max: max || 1 };
      waveBox.hidden = false;
      drawWave();
    } catch (err) {
      /* 読めない形式: 波形は出さない（再生はできることがある） */
    } finally {
      if (ctx) ctx.close().catch(() => {});
    }
  }
  /** 音の出始め（秒）。最大音量から -30dB を超えて、そのあと 30 ミリ秒のうち半分以上が鳴っている最初の位置。見つからなければ null */
  function detectHead() {
    const { peaks, max } = wave;
    const th = Math.max(max * 0.0316, 0.003);
    for (let i = 0; i < peaks.length; i++) {
      if (peaks[i] < th) continue;
      let n = 0;
      for (let j = i; j < Math.min(peaks.length, i + 30); j++) if (peaks[j] >= th * 0.5) n++;
      if (n >= 15) return i / WAVE_RATE;
    }
    return null;
  }
  /** 開始位置を左から 1/4 に固定して描く。拍の線は TAB の時間軸から（タップで補正した小節もそのまま） */
  function drawWave() {
    if (!wave || waveBox.hidden || !waveCanvas.clientWidth) return;
    const dpr = window.devicePixelRatio || 1;
    const w = waveCanvas.clientWidth, h = waveCanvas.clientHeight;
    if (waveCanvas.width !== Math.round(w * dpr) || waveCanvas.height !== Math.round(h * dpr)) {
      waveCanvas.width = Math.round(w * dpr);
      waveCanvas.height = Math.round(h * dpr);
    }
    const g = waveCanvas.getContext('2d');
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, w, h);
    const span = WAVE_SPANS[waveSpan];
    const pxPerSec = w / span;
    const t0 = st.offsetMs / 1000 - span / 4;
    const xOf = (t) => (t - t0) * pxPerSec;
    // 拍の線（小節の頭は濃く）
    const t1 = t0 + span;
    for (let i = 0; i < starts.length; i++) {
      if (starts[i] > t1) break;
      if (starts[i] + durs[i] < t0) continue;
      for (let b = 0; b < st.beats; b++) {
        const x = xOf(starts[i] + b * durs[i] / st.beats);
        if (x < 0 || x > w) continue;
        g.fillStyle = b === 0 ? colors.muted : colors.border;
        g.fillRect(Math.round(x), 0, 1, h);
      }
    }
    // 波形（1px ごとの最大振幅。小さい音も見えるよう平方根で持ち上げる）
    g.fillStyle = colors.text;
    const mid = h / 2;
    for (let x = 0; x < w; x++) {
      const a = Math.max(0, Math.floor((t0 + x / pxPerSec) * WAVE_RATE));
      const b = Math.min(wave.peaks.length, Math.max(a + 1, Math.floor((t0 + (x + 1) / pxPerSec) * WAVE_RATE)));
      let v = 0;
      for (let j = a; j < b; j++) if (wave.peaks[j] > v) v = wave.peaks[j];
      const y = Math.sqrt(v / wave.max) * (mid - 2);
      if (y > 0.3) g.fillRect(x, mid - y, 1, y * 2);
    }
    // 開始位置
    g.fillStyle = colors.accent;
    g.fillRect(Math.round(xOf(st.offsetMs / 1000)) - 1, 0, 2, h);
    // 再生位置
    const now = clock ? clock.time() : 0;
    if (now >= t0 && now <= t1) { g.fillStyle = colors.accent2; g.fillRect(Math.round(xOf(now)), 0, 1, h); }
  }
  // ドラッグ: 波形を左右に動かす（右へ動かす = 開始位置を前へ）。動かさずに離したら、その場所を開始位置にする
  let waveDrag = null;
  waveCanvas.addEventListener('pointerdown', (e) => {
    if (!wave) return;
    waveCanvas.setPointerCapture(e.pointerId);
    waveDrag = { x: e.clientX, ms: st.offsetMs, moved: false };
  });
  waveCanvas.addEventListener('pointermove', (e) => {
    if (!waveDrag) return;
    const dx = e.clientX - waveDrag.x;
    if (!waveDrag.moved && Math.abs(dx) < 4) return;
    waveDrag.moved = true;
    waveCanvas.classList.add('is-dragging');
    setOffset(waveDrag.ms - dx / waveCanvas.clientWidth * WAVE_SPANS[waveSpan] * 1000);
  });
  waveCanvas.addEventListener('pointerup', (e) => {
    if (!waveDrag) return;
    if (!waveDrag.moved) {
      const rect = waveCanvas.getBoundingClientRect();
      const span = WAVE_SPANS[waveSpan];
      setOffset((st.offsetMs / 1000 - span / 4 + (e.clientX - rect.left) / rect.width * span) * 1000);
    }
    waveDrag = null;
    waveCanvas.classList.remove('is-dragging');
  });
  waveCanvas.addEventListener('pointercancel', () => { waveDrag = null; waveCanvas.classList.remove('is-dragging'); });
  $$('[data-wave-zoom]').forEach((b) => b.addEventListener('click', () => {
    waveSpan = clamp(waveSpan - Number(b.dataset.waveZoom), 0, WAVE_SPANS.length - 1);
    drawWave();
  }));
  $('[data-wave-detect]').addEventListener('click', () => {
    if (!wave) return;
    const t = detectHead();
    if (t === null) { notify('音を検知できませんでした', 'error'); return; }
    setOffset(t * 1000);
  });
  if (window.ResizeObserver) new ResizeObserver(() => drawWave()).observe(waveCanvas); // 畳んだステップを開いたとき・画面の幅が変わったとき
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
  const tabImages = imageBox({ kind: 'tab', id: D.tab_id, ids: D.images || [], pdfs: D.pdfs, files: D.files, list: $('[data-tab-images]', notes), addLabel: $('[data-img-add]', notes), max: 10 });
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
    if (bpm !== null) setBpm(bpm);
  });
  /** BPM を設定する（タップ計測・検索・自動検知で共通）。保存はしない */
  function setBpm(bpm) {
    st.bpm = bpm;
    inputs.bpm.value = String(bpm);
    recompute();
    clock.setRate(currentRate());
    syncPbpmField();
    requestFrame();
    refreshDirty();
  }

  /* ---- BPM 微調整（−1 / −0.1 / +0.1 / +1）: 押し続けると連続で変わる。1回ごとに 20〜400 に収め、小数2桁に丸める ---- */
  $$('[data-bpm-step]').forEach((btn) => {
    const step = Number(btn.dataset.bpmStep);
    const nudge = () => setBpm(clamp(Math.round((st.bpm + step) * 100) / 100, 20, 400));
    let delay = null, timer = null;
    const stop = () => { clearTimeout(delay); clearInterval(timer); };
    btn.addEventListener('pointerdown', (e) => {
      if (e.button) return; // 右クリックなどは無視
      nudge();
      delay = setTimeout(() => { timer = setInterval(nudge, 90); }, 450); // 0.45 秒押し続けたら連続
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => btn.addEventListener(ev, stop));
    btn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); nudge(); } }); // キーボード操作
    btn.addEventListener('contextmenu', (e) => e.preventDefault()); // スマホの長押しメニューを出さない
  });

  /* ---- BPM 自動検知: 手元のファイルを端末の中で解析する（送信なし）---- */
  const detectBtn = $('[data-bpm-detect]');
  detectBtn?.addEventListener('click', async () => {
    const file = srcFile;
    if (!file) return;
    detectBtn.disabled = true;
    const AC = window.AudioContext || window.webkitAudioContext;
    let ctx = null;
    try {
      ctx = new AC();
      const buf = await ctx.decodeAudioData(await file.arrayBuffer());
      const chans = [];
      for (let c = 0; c < buf.numberOfChannels; c++) chans.push(buf.getChannelData(c));
      const bpm = detectBpm(chans, buf.sampleRate);
      if (bpm === null) throw new Error('BPM を検知できませんでした');
      setBpm(bpm);
      notify(`BPM ${bpm}`);
    } catch (err) {
      notify('BPM を検知できませんでした', 'error');
    } finally {
      if (ctx) ctx.close().catch(() => {});
      detectBtn.disabled = !srcFile;
    }
  });

  /* ---- 検索（動画 / BPM）: 結果は textContent だけで組み立てる。選ぶと欄に入れるだけで、保存は別 ---- */
  const searchDlg = $('[data-search]');
  if (searchDlg) {
    const searchTitle = $('[data-search-title]', searchDlg);
    const searchStatus = $('[data-search-status]', searchDlg);
    const searchList = $('[data-search-list]', searchDlg);
    const searchCredit = $('[data-search-credit]', searchDlg);
    let searchSeq = 0; // 古い応答が新しい検索の結果を上書きしないように
    async function openSearch(action) {
      const seq = ++searchSeq;
      searchTitle.textContent = action === 'yt_search' ? '動画検索' : 'BPM検索';
      searchCredit.hidden = action !== 'bpm_search'; // BPM のデータ提供元の表記（利用条件）
      searchStatus.textContent = '検索中…';
      searchList.replaceChildren();
      if (!searchDlg.open) searchDlg.showModal();
      try {
        const json = await post({ action, tab_id: D.tab_id });
        if (seq !== searchSeq) return;
        const items = json.items || [];
        searchStatus.textContent = items.length ? '' : '見つかりませんでした';
        items.forEach((it) => {
          const li = el('li');
          const b = el('button', 'tab-search__btn');
          b.type = 'button';
          const text = el('span', 'tab-search__text');
          if (action === 'yt_search') {
            const img = el('img');
            img.src = `https://i.ytimg.com/vi/${encodeURIComponent(it.video_id)}/mqdefault.jpg`;
            img.alt = '';
            img.loading = 'lazy';
            text.append(el('span', 'tab-search__title', it.title), el('span', 'muted small', it.channel));
            b.append(img, text);
            b.addEventListener('click', () => {
              inputs.youtube.value = 'https://www.youtube.com/watch?v=' + encodeURIComponent(it.video_id);
              inputs.youtube.dispatchEvent(new Event('input', { bubbles: true }));
              inputs.youtube.dispatchEvent(new Event('change', { bubbles: true }));
              searchDlg.close();
              notify('反映しました（保存はまだです）');
            });
          } else {
            text.append(el('span', 'tab-search__bpm', 'BPM ' + it.bpm), el('span', '', `${it.title} · ${it.artist}`), el('span', 'muted small', it.time_sig || ''));
            b.append(text);
            b.addEventListener('click', () => {
              setBpm(Number(it.bpm));
              searchDlg.close();
              notify('反映しました（保存はまだです）');
            });
          }
          li.appendChild(b);
          searchList.appendChild(li);
        });
      } catch (err) {
        if (seq === searchSeq) searchStatus.textContent = err.message || '検索できませんでした';
      }
    }
    $$('[data-search-open]').forEach((b) => b.addEventListener('click', () => openSearch(b.dataset.searchOpen)));
    $('[data-search-close]', searchDlg).addEventListener('click', () => searchDlg.close());
  }

  /* ---- 練習 BPM の欄・ループごとの BPM 増加 ---- */
  pbpmEl.addEventListener('change', () => {
    const v = parseFloat(pbpmEl.value);
    if (v >= 20 && v <= 400) setPracticeBpm(v);
    else syncPbpmField();
  });
  pbpmEl.addEventListener('blur', syncPbpmField); // 入力が終わったら、実際に効いた BPM（YouTube で丸められたとき）に直す

  /* ---------------------------------------------------------------
   * 準備ステップ（編集モードの上に出る ① 音源 → ② テンポ → ③ 開始位置 → ④ 入力）
   *   初めての人が「何から手を付けるか」で迷わないための手順。HTML は tab.php の <details data-setup>。
   *   ・完了の判定は updateSetup() がまとめて行う。「いまの設定の値」から決めるので、設定を変えれば自動で ✓ が付く
   *     （値から決められないもの = 「次へ」「音源なし」を押した、は「フラグ」として、この端末の localStorage に覚えておく。
   *       その人の準備の進み具合なので、DB には入れない = 他の端末・他の人には共有しない）
   *   ・開くかどうかは、編集に入った最初の 1 回だけ決める（initSetupView）。入力中に全部終わっても自動では閉じない:
   *     上の要素が縮むと、いま入力しているグリッドの位置がずれてしまうため（Safari は、そのずれを直す機能 = スクロールアンカリングに非対応）
   *   ・見出しのボタンを押すとステップが開く。1つ開くと他は閉じる（アコーディオン）
   * --------------------------------------------------------------- */
  const setupEl = $('[data-setup]');
  const SETUP_ORDER = ['src', 'tempo', 'start', 'input'];
  const stepEls = {};
  SETUP_ORDER.forEach((n) => { stepEls[n] = setupEl ? $(`[data-step="${n}"]`, setupEl) : null; });
  const dotsEl = setupEl ? $('[data-setup-dots]', setupEl) : null;

  // フラグ（「次へ」「音源なし」を押したか）。localStorage は使えない環境（プライベートモード等）もあるので、必ず try/catch で包む
  const setupKey = `tabSetup:${D.tab_id}`;
  const setupFlags = { src: false, tempo: false, start: false };
  try {
    const saved = JSON.parse(localStorage.getItem(setupKey) || '{}');
    Object.keys(setupFlags).forEach((k) => { setupFlags[k] = !!(saved && saved[k]); }); // 知っている名前だけ取り出す（壊れた値は無視）
  } catch (e) { /* 読めなくても、フラグ無しで始めるだけ */ }
  function setSetupFlag(name) {
    setupFlags[name] = true;
    try { localStorage.setItem(setupKey, JSON.stringify(setupFlags)); } catch (e) { /* 保存できなくても、このページを開いている間は有効 */ }
  }

  const barHasContent = (b) => Object.keys(b.n).length > 0 || b.r.length > 0 || Object.keys(b.c).length > 0; // 音符・休符・コードのどれかがある小節
  // 読み込んだ時点で音符などがある = 作り終わった（または作りかけの）TAB。①〜③ も完了扱いにして、開くたびに準備を出さない
  const loadedFilled = st.bars.some(barHasContent);

  /** 4 つのステップの「完了か」と「要約の文字」を、いまの設定から作る（画面は触らない） */
  function setupState() {
    const hasYt = youtubeIdOf(st.youtubeUrl) !== null;
    // 開始位置が要るのは、音源がある（YouTube / 手元のファイル）とき。音源なしは BPM だけで流れるので、開始位置を合わせる必要が無い
    const needStart = hasYt || srcKind === 'file';
    const noteTotal = st.bars.reduce((n, b) => n + Object.keys(b.n).length, 0);
    const chordTotal = st.bars.reduce((n, b) => n + Object.keys(b.c).length, 0);
    const tapped = st.bars.some((b) => b.t !== null);        // タップで位置を合わせた小節がある
    const stepName = inputs.steps.selectedOptions[0]?.textContent ?? ''; // 「16分」など（select の表示の文字をそのまま使う）
    return {
      hasYt,
      needStart,
      src: {
        done: setupFlags.src || hasYt || srcKind === 'file' || loadedFilled,
        sum: hasYt ? 'YouTube' : srcKind === 'file' ? 'ファイル' : setupFlags.src ? 'なし' : '',
      },
      tempo: {
        // 120 / 4 / 4 は新規作成時の初期値。どれかが変わっていれば、触った = 完了
        done: setupFlags.tempo || st.bpm !== 120 || st.beats !== 4 || st.steps !== 4 || loadedFilled,
        sum: `${Math.round(st.bpm * 100) / 100} · ${st.beats}拍 · ${stepName}`,
      },
      start: {
        done: setupFlags.start || st.offsetMs > 0 || tapped || !needStart || loadedFilled,
        sum: st.offsetMs > 0 ? `${Number((st.offsetMs / 1000).toFixed(2))}秒` : !needStart ? '—' : tapped ? 'タップ' : '',
      },
      input: {
        done: st.bars.some(barHasContent),
        sum: noteTotal > 0 ? `${noteTotal}音` : chordTotal > 0 ? `${chordTotal}コード` : '',
      },
    };
  }

  let lastSetup = null; // 直近の判定結果（initSetupView が「全部完了か」を見るのに使う）
  /** 準備ステップの表示を、いまの設定に合わせる。refreshDirty() の最後と、音源を切り替えたとき（setSource）から呼ばれる */
  function updateSetup() {
    if (!setupEl || !dotsEl) return; // 準備ステップの HTML が無い、または DOM の準備前: 何もしない（落ちないためのガード）
    const state = setupState();
    lastSetup = state;
    const next = SETUP_ORDER.find((n) => !state[n].done) ?? null; // 「次にやるステップ」= 最初の未完了
    let doneCount = 0;
    SETUP_ORDER.forEach((n, i) => {
      const li = stepEls[n];
      if (!li) return;
      const status = state[n].done ? 'done' : n === next ? 'next' : 'todo';
      if (state[n].done) doneCount++;
      if (li.dataset.state !== status) { // 状態が変わったときだけ DOM を触る（updateSetup は 1 文字入力するたびに呼ばれるので、無駄な書き換えを避ける）
        li.dataset.state = status;
        li.classList.toggle('is-done', status === 'done');
        li.classList.toggle('is-next', status === 'next');
        const mark = $('[data-step-mark]', li);
        if (status === 'done') { // 完了 = ✓ のアイコン（読み上げ用に「完了」の文字も隠して入れる）
          const ic = el('span', 'icon', 'check');
          ic.setAttribute('aria-hidden', 'true');
          mark.replaceChildren(ic, el('span', 'tab-sr', '完了'));
        } else {
          mark.textContent = String(i + 1);
        }
      }
      const sum = $('[data-step-sum]', li);
      if (sum.textContent !== state[n].sum) sum.textContent = state[n].sum; // 要約は textContent で入れる（ユーザーの文字を HTML として解釈させない）
    });
    // 「準備」の見出しの右の 4 つの丸（畳んだ 1 行でも、進み具合が分かる）。丸は最初の 1 回だけ作る
    if (!dotsEl.childElementCount) SETUP_ORDER.forEach(() => dotsEl.appendChild(el('span', 'tab-setup__dot')));
    [...dotsEl.children].forEach((d, i) => d.classList.toggle('is-done', state[SETUP_ORDER[i]].done));
    dotsEl.setAttribute('aria-label', `${doneCount}/${SETUP_ORDER.length}`);
    // ① の「次へ」は、YouTube の URL が正しいときだけ押せる（音源を使わないなら、隣の「音源なし」）
    const srcNext = $('[data-setup-next]', stepEls.src);
    if (srcNext) srcNext.disabled = !state.hasYt;
  }

  /**
   * name のステップだけ開く（null なら全部閉じる）。aria-expanded は、スクリーンリーダーに「いま開いている」と伝える属性
   * scroll = true（ボタンを押して開いたとき）: 開いたステップが画面に見える位置までスクロールする。
   *   ③ 開始位置だけは、ステップを画面の上端に寄せる（block: 'start'）: すぐ下にある動画も一緒に見えて、動画を見ながら位置を合わせられるため。
   *   ほかは 'nearest'（すでに見えていれば動かさない） */
  function openStep(name, scroll) {
    SETUP_ORDER.forEach((n) => {
      const li = stepEls[n];
      if (!li) return;
      const open = n === name;
      li.classList.toggle('is-open', open);
      $('.tab-step__head', li).setAttribute('aria-expanded', String(open));
      $('.tab-step__body', li).hidden = !open;
    });
    if (scroll && name && stepEls[name]) {
      const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches; // 動きを減らす設定の人には、滑らかなスクロールにしない
      // 他のステップが閉じて高さが変わったあとの位置で動かすため、次のフレームに回す
      requestAnimationFrame(() => stepEls[name].scrollIntoView({ block: name === 'start' ? 'start' : 'nearest', behavior: calm ? 'auto' : 'smooth' }));
    }
  }
  /** 「次へ」で次に開くステップ。③ 開始位置は、音源が無いと要らない（自動で完了）ので飛ばす。最後まで来たら null（全部閉じる） */
  function nextAfter(name) {
    const state = setupState();
    for (let i = SETUP_ORDER.indexOf(name) + 1; i < SETUP_ORDER.length; i++) {
      if (SETUP_ORDER[i] === 'start' && !state.needStart) continue;
      return SETUP_ORDER[i];
    }
    return null;
  }

  /** 編集に入った最初の 1 回だけ: 全部完了なら 1 行に畳む / 未完了なら開いて、最初の未完了ステップを開く */
  let setupViewInit = false;
  function initSetupView() {
    if (setupViewInit || !setupEl) return;
    setupViewInit = true;
    updateSetup();
    const first = SETUP_ORDER.find((n) => !lastSetup[n].done) ?? null;
    setupEl.open = first !== null; // <details> の open 属性（true = 開く）
    openStep(first);
  }

  /** ④「入力へ」: 1 小節目の最初のマス（b=0, s=0, r=0）を選ぶ → 画面内キーボードが開く。1 小節目が見える位置までスクロールもする */
  function goInput() {
    if (!gridBuilt) renderGrid();
    kb.panel = 'note';
    select(0, 0, 0, false);
    updateKeyboard();
    const firstBar = grid.children[0];
    if (firstBar) {
      // キーボードが出たあと（次のフレーム）に動かす。動きを減らす設定（prefers-reduced-motion）の人には、滑らかなスクロールにしない
      const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      requestAnimationFrame(() => firstBar.scrollIntoView({ block: 'start', behavior: calm ? 'auto' : 'smooth' }));
    }
  }

  if (setupEl) {
    // クリックは setupEl 1 か所で受ける（イベント委譲 = 子のボタンのクリックは親まで泡のように上がってくる）
    setupEl.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn || btn.disabled) return;
      const li = btn.closest('.tab-step');
      const name = li ? li.dataset.step : '';
      if (btn.classList.contains('tab-step__head')) { // 見出し: 開いていれば閉じる / 閉じていれば開く（ほかは閉じる）
        openStep(li.classList.contains('is-open') ? null : name, true);
        return;
      }
      const d = btn.dataset;
      if (d.setupSkipSrc !== undefined) { // ① 音源なし: ① を完了にして ② へ
        setSetupFlag('src');
        updateSetup();
        openStep(nextAfter('src'), true);
      } else if (d.setupNext !== undefined) { // 次へ: いまのステップを完了にして、次のステップを開く
        setSetupFlag(name);
        updateSetup();
        openStep(nextAfter(name), true);
      } else if (d.setupGoInput !== undefined) {
        goInput();
      }
    });
  }

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
    if (!noteEl.dataset.error) {         // エラー表示中はそのまま
      noteEl.hidden = !dirty;
      noteEl.textContent = dirty ? '未保存' : '';
    }
    // 設定の変更・音符の入力・元に戻す / やり直す・インポートは、どれもここを通る。だから準備ステップの更新は、ここ 1 か所から呼べばよい
    updateSetup();
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
      tabImages.ids.filter((id) => !tabImages.pdfs.includes(id) && !tabImages.files.includes(id)).forEach((id, k) => { // PDF は AI に読ませられない
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
  $$('[data-import-open]').forEach((b) => b.addEventListener('click', importOpen)); // ボタンは2つ（準備ステップ ④ と、編集の道具の行）。$ は先頭の1つしか返さないので $$ で全部に付ける
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
    // is-editing = 編集中の印。tab.css が data-practice-only（速度・A/B・マーカー…）を隠し、data-edit-only（準備・詳細設定）を出す
    root.classList.toggle('is-editing', on);
    editBox.hidden = !on;
    tabImages.setEditable(on);
    renderNotes();
    modeBtn.setAttribute('aria-pressed', String(on));
    modeBtn.title = on ? '練習' : '編集';
    modeBtn.setAttribute('aria-label', modeBtn.title);
    $('.icon', modeBtn).textContent = on ? 'school' : 'edit';
    if (on && !gridBuilt) renderGrid();
    if (on) initSetupView(); // 準備ステップを開くか閉じるかは、編集に入った最初の 1 回だけ決める
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
