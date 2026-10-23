/*
 * =====================================================================
 *  tt_builder.js — タイムテーブル作成ツール（tt_builder.php?id=…）の画面
 * =====================================================================
 *  画面は3つ（URL の #days / #preview で切り替え。見ている画面は人ごと）
 *    出演日     … バンドごとに出られる日をまとめて選ぶ
 *    プレビュー … 書き出す CSV と同じ表。日付・会場・ライブ名・持ち時間・バンド名・key を編集
 *    出順     … ≡ ドラッグで並べ替え・日程の移動・自動配置・持ち時間と時刻
 *
 *  保存の仕組み（保存ボタンは無い）
 *    変更は「操作」(op) にして、すぐ自分の画面に当てる（applyOp）→ 300ms まとめて tt_builder_api に送る。
 *    数秒おきに API を呼び、他の人の変更があればサーバーの state を受け取り、まだ届いていない自分の操作を当て直す。
 *    applyOp は lib/tt_builder.php の tt_apply_op と同じ動き（直すときは両方直すこと）。
 *
 *  画面の文字は全部 textContent で入れる（バンド名・名前に <script> などがあっても HTML として解釈させない = XSS 対策）
 * =====================================================================
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-tt-builder]');
  const dataEl = document.getElementById('ttb-data');
  if (!root || !dataEl) return;

  const boot = JSON.parse(dataEl.textContent);
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const LABELS = boot.labels;
  const INFO = Array.isArray(boot.info) ? {} : boot.info; // PHP の空配列は [] で来る
  const SIMILAR = boot.similar || {}; // DBにいない人の「もしかして」候補 キー => [{n, k}]
  const MAX_ROWS = 80;
  const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

  // 学年の重み（4年がやや上。OB は 4 年扱い）
  const WEIGHT = { 1: 1, 2: 2, 3: 3.6, 4: 4 };

  let server = norm(boot.state); // サーバーで確定している state
  let state = clone(server);     // server + まだ届いていない自分の操作
  let v = boot.v;
  let pending = [];              // まだ送っていない操作
  let inflight = null;           // 送信中の操作
  let timer = 0;
  let status = 'saved';          // saved | saving | error
  let people = [];
  let focus = null;              // 自分が操作中の行
  let dragging = false;
  let needRender = false;
  let view = location.hash === '#days' ? 'days' : location.hash === '#preview' ? 'preview' : 'order';
  let curDay = 1;
  let enter = true; // 画面・日の切り替え直後だけ登場アニメーション（編集のたびの再描画では動かさない）
  let timeMode = false;
  const HISTORY_OPS = ['move', 'arrange', 'add', 'del', 'text', 'min', 'call', 'restore'];
  let undoStack = [];            // 元に戻す用: 操作の直前の「全日程の集合時間 + 行」
  let redoStack = [];
  let lastSig = '';              // 最後に描いたときの state + 開いている人（変わっていなければ描き直さない）

  /* ===================== state の操作 ===================== */
  function clone(x) { return JSON.parse(JSON.stringify(x)); }
  function norm(s) {
    if (Array.isArray(s.grades)) s.grades = {};
    if (Array.isArray(s.bands)) s.bands = {};
    return s;
  }
  const isInt = (x, min, max) => Number.isInteger(x) && x >= min && x <= max;
  const isStr = (x, max) => typeof x === 'string' && [...x].length <= max;
  function findRow(s, id) {
    for (let d = 0; d < s.days.length; d++) {
      const i = s.days[d].rows.findIndex((r) => r.id === id);
      if (i >= 0) return [d, i];
    }
    return null;
  }
  const rowOf = (s, id) => { const at = findRow(s, id); return at && s.days[at[0]].rows[at[1]]; };

  // lib/tt_builder.php の tt_apply_op と同じ
  function applyOp(s, op) {
    const dc = s.dayCount;
    switch (op.op) {
      case 'title':
        if (!isStr(op.x, 100) || op.x.trim() === '') return false;
        s.title = op.x.trim();
        return true;
      case 'dayCount': {
        if (!isInt(op.n, 1, 3)) return false;
        const n = op.n;
        for (let d = n; d < 3; d++) {
          s.days[n - 1].rows.push(...s.days[d].rows);
          s.days[d].rows = [];
        }
        Object.values(s.bands).forEach((b) => {
          b.avail = b.avail.filter((x) => x <= n);
          if (!b.avail.length) b.avail = range(n);
          if (n > dc && b.avail.length === dc) b.avail = range(n);
        });
        s.dayCount = n;
        return true;
      }
      case 'avail': {
        const b = s.bands[op.b];
        if (!b || !Array.isArray(op.days) || !op.days.length) return false;
        const days = [...new Set(op.days)];
        if (!days.every((x) => isInt(x, 1, dc))) return false;
        days.sort((a, c) => a - c);
        b.avail = days;
        const at = findRow(s, op.b);
        if (at && !days.includes(at[0] + 1)) {
          const [row] = s.days[at[0]].rows.splice(at[1], 1);
          s.days[days[0] - 1].rows.push(row);
        }
        return true;
      }
      case 'move': {
        if (!isInt(op.day, 1, dc) || !isInt(op.index, 0, MAX_ROWS)) return false;
        const at = findRow(s, op.id);
        if (!at || (s.bands[op.id] && !s.bands[op.id].avail.includes(op.day))) return false;
        if (at[0] !== op.day - 1 && s.days[op.day - 1].rows.length >= MAX_ROWS) return false;
        const [row] = s.days[at[0]].rows.splice(at[1], 1);
        const rows = s.days[op.day - 1].rows;
        rows.splice(Math.min(op.index, rows.length), 0, row);
        return true;
      }
      case 'arrange': {
        if (!Array.isArray(op.days) || op.days.length !== dc) return false;
        const byId = {};
        s.days.forEach((d) => d.rows.forEach((r) => { byId[r.id] = r; }));
        const seen = new Set();
        const next = [];
        for (let d = 0; d < dc; d++) {
          const ids = op.days[d];
          if (!Array.isArray(ids) || ids.length > MAX_ROWS) return false;
          next[d] = [];
          for (const id of ids) {
            if (!byId[id] || seen.has(id) || (s.bands[id] && !s.bands[id].avail.includes(d + 1))) return false;
            seen.add(id);
            next[d].push(byId[id]);
          }
        }
        if (seen.size !== Object.keys(byId).length) return false;
        for (let d = 0; d < 3; d++) s.days[d].rows = next[d] || [];
        return true;
      }
      case 'restore': { // 元に戻す / やり直す。lib/tt_builder.php の restore と同じ
        if (!Array.isArray(op.days) || op.days.length !== 3) return false;
        const seen = new Set();
        const next = [];
        for (let d = 0; d < 3; d++) {
          const day = op.days[d];
          if (!day || !Array.isArray(day.rows) || day.rows.length > MAX_ROWS || typeof day.call !== 'string' || (day.call !== '' && !TIME_RE.test(day.call))) return false;
          if (d >= dc && day.rows.length) return false;
          const rows = [];
          for (const r of day.rows) {
            if (!r || typeof r.id !== 'string' || seen.has(r.id) || !(r.min == null || isInt(r.min, 0, 600))) return false;
            if (s.bands[r.id]?.off) continue; // 非表示のバンドの行は無かったことにする
            seen.add(r.id);
            if (s.bands[r.id]) {
              if (!s.bands[r.id].avail.includes(d + 1)) return false;
              rows.push({ id: r.id, min: r.min ?? null });
            } else {
              if (!/^x[a-z0-9]{0,23}$/.test(r.id) || !['break', 'text'].includes(r.t) || !isStr(r.x, 50)) return false;
              rows.push({ id: r.id, t: r.t, x: r.x.trim(), min: r.min ?? null });
            }
          }
          next[d] = { call: day.call, rows };
        }
        if (!Object.keys(s.bands).every((id) => s.bands[id].off || seen.has(id))) return false;
        next.forEach((day, d) => { s.days[d].call = day.call; s.days[d].rows = day.rows; });
        return true;
      }
      case 'member': { // 名簿の人を候補の人に置きかえる。lib/tt_builder.php の member と同じ（k は画面が持っている候補のキーを使う）
        const b = s.bands[op.b];
        const at = b ? b.members.findIndex((m) => m.k === op.k) : -1;
        if (at < 0 || !isStr(op.n, 60) || op.n.trim() === '' || typeof op.nk !== 'string' || op.nk === ''
          || (op.nk !== op.k && b.members.some((m) => m.k === op.nk))) return false;
        b.members[at] = { n: op.n.trim(), k: op.nk, p: b.members[at].p ?? '' };
        return true;
      }
      case 'hide': {
        const b = s.bands[op.b];
        if (!b || typeof op.on !== 'boolean') return false;
        const at = findRow(s, op.b);
        if (op.on) {
          if (at) s.days[at[0]].rows.splice(at[1], 1);
          b.off = true;
        } else {
          const day = Math.min(...(b.avail.length ? b.avail : [1]));
          if (!at) {
            if (day > dc || s.days[day - 1].rows.length >= MAX_ROWS) return false;
            s.days[day - 1].rows.push({ id: op.b, min: null });
          }
          delete b.off;
        }
        return true;
      }
      case 'pin':
        if (!s.bands[op.b] || typeof op.on !== 'boolean') return false;
        s.bands[op.b].pin = op.on;
        return true;
      case 'mix':
        if (!isInt(op.v, 0, 100)) return false;
        s.mix = op.v;
        return true;
      case 'gap':
        if (typeof op.on !== 'boolean') return false;
        s.gap = op.on;
        return true;
      case 'key': // バンドの Key（機材メモ）。空文字で消す
        if (!s.bands[op.b] || !isStr(op.x, 60)) return false;
        s.bands[op.b].key = op.x.trim();
        return true;
      case 'grade':
        if (!isStr(op.k, 60) || op.k === '' || !(op.g === null || isInt(op.g, 1, 9))) return false;
        if (op.g === null) delete s.grades[op.k]; else s.grades[op.k] = op.g;
        return true;
      case 'date':
        if (!isInt(op.day, 1, 3) || typeof op.x !== 'string' || (op.x !== '' && !validDate(op.x))) return false;
        s.days[op.day - 1].date = op.x;
        return true;
      case 'venue':
        if (!isInt(op.day, 1, 3) || !isStr(op.x, 100)) return false;
        s.days[op.day - 1].venue = op.x.trim();
        return true;
      case 'name':
        if (!s.bands[op.b] || !isStr(op.x, 100) || op.x.trim() === '') return false;
        s.bands[op.b].name = op.x.trim();
        return true;
      case 'songs':
      case 'cnt':
        if (!s.bands[op.b] || !(op.v === null || isInt(op.v, 0, 99))) return false;
        s.bands[op.b][op.op] = op.v;
        return true;
      case 'dur':
        if (!(op.v === null || isInt(op.v, 0, 600))) return false;
        s.dur = op.v;
        return true;
      case 'bdur':
        if (!s.bands[op.b] || !(op.v === null || isInt(op.v, 0, 600))) return false;
        s.bands[op.b].dur = op.v;
        return true;
      case 'min': {
        const r = rowOf(s, op.id);
        if (!r || !(op.min === null || isInt(op.min, 0, 600))) return false;
        r.min = op.min;
        return true;
      }
      case 'call':
        if (!isInt(op.day, 1, 3) || typeof op.t !== 'string' || (op.t !== '' && !TIME_RE.test(op.t))) return false;
        s.days[op.day - 1].call = op.t;
        return true;
      case 'add': {
        if (typeof op.id !== 'string' || !/^x[a-z0-9]{0,23}$/.test(op.id) || findRow(s, op.id)
          || !isInt(op.day, 1, dc) || !isInt(op.index, 0, MAX_ROWS) || !['break', 'text'].includes(op.t)
          || !isStr(op.x, 50) || s.days[op.day - 1].rows.length >= MAX_ROWS
          || !(op.min == null || isInt(op.min, 0, 600))) return false;
        const rows = s.days[op.day - 1].rows;
        rows.splice(Math.min(op.index, rows.length), 0, { id: op.id, t: op.t, x: op.x.trim(), min: op.min ?? null });
        return true;
      }
      case 'text': {
        const r = rowOf(s, op.id);
        if (!r || !r.t || !isStr(op.x, 50)) return false;
        r.x = op.x.trim();
        return true;
      }
      case 'del': {
        const at = findRow(s, op.id);
        if (!at || !s.days[at[0]].rows[at[1]].t) return false;
        s.days[at[0]].rows.splice(at[1], 1);
        return true;
      }
    }
    return false;
  }
  /** 'YYYY-MM-DD' の実在する日付か（lib/tt_builder.php の tt_valid_date と同じ） */
  function validDate(x) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(x);
    if (!m) return false;
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3];
  }
  const range = (n) => Array.from({ length: n }, (_, i) => i + 1);
  const snapshot = () => clone(state.days.map((d) => ({ call: d.call, rows: d.rows })));
  /** 元に戻す（from = undoStack）/ やり直す（from = redoStack） */
  function stepHistory(from, to) {
    const target = from.pop();
    if (!target) return;
    const now = snapshot();
    if (commit({ op: 'restore', days: target }, { fromHistory: true })) to.push(now);
    else { from.push(target); toast('他の人の編集と重なって戻せませんでした'); }
  }

  /** 操作を自分の画面に当てて、送信待ちに積む */
  function commit(op, { fromHistory = false } = {}) {
    const before = snapshot();
    if (!applyOp(state, op)) return false;
    if (!fromHistory && HISTORY_OPS.includes(op.op)) { // 出順まわりの操作だけ、元に戻す / やり直すの対象にする
      undoStack.push(before);
      if (undoStack.length > 50) undoStack.shift();
      redoStack = [];
    }
    if (op.op === 'hide') { undoStack = []; redoStack = []; } // 行の増減を伴うので、これより前の「元に戻す」は使えない
    pending.push(op);
    status = 'saving';
    render();
    schedule(300);
    return true;
  }

  /* ===================== 自動保存・同期 ===================== */
  function schedule(ms) {
    clearTimeout(timer);
    timer = setTimeout(sync, ms);
  }
  async function sync() {
    if (inflight) return;
    const ops = pending;
    pending = [];
    inflight = ops;
    let next = document.hidden ? 10000 : 2500;
    try {
      const res = await fetch('tt_builder_api', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ id: boot.id, v, ops, focus }),
      });
      const j = await res.json().catch(() => ({ error: '通信エラー' }));
      if (!res.ok) {
        if (res.status === 404) { toast(j.error); next = 60000; }
        throw new Error(j.error || String(res.status));
      }
      v = j.v;
      people = j.people || [];
      if (j.state) server = norm(j.state);
      if (j.rejected) toast('他の人の編集と重なった変更は反映されませんでした');
      state = clone(server);
      pending.forEach((op) => applyOp(state, op)); // 送っている間に増えた自分の操作を当て直す
      status = pending.length ? 'saving' : 'saved';
    } catch (e) {
      pending = ops.concat(pending); // 送れなかった操作は次に送り直す
      status = 'error';
      next = 5000;
    }
    inflight = null;
    const sig = signature();
    if (sig !== lastSig) {
      // 入力中・ドラッグ中に描き直すと打っている文字が消えるので、終わってから
      if (dragging || typing()) needRender = true; else render();
    } else {
      root.querySelector('.ttb-status')?.replaceWith(statusIcon());
    }
    schedule(pending.length ? 300 : next);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(0); });
  window.addEventListener('beforeunload', (e) => {
    if (pending.length || inflight) { e.preventDefault(); e.returnValue = ''; }
  });
  function setFocus(id) {
    if (focus === id) return;
    focus = id;
    schedule(150);
  }

  /* ===================== 学年・おすすめ度・連続出演 ===================== */
  function gradeOf(k) {
    const g = INFO[k]?.g ?? state.grades[k] ?? null;
    return g;
  }
  const gradeText = (g) => (g == null ? '?' : g <= 4 ? String(g) : `OB${g - 4}`);

  // 学年の色（下 → 上）: 楽器ラベルの色を借りる（青緑 / 黄緑 / 黄土 / 橙）。ライト・ダークの切り替えも楽器ラベルと同じ
  const GRADE_COLORS = ['--prog', '--cho', '--gt', '--vn'];
  let gradeColor = {}; // 学年 => CSS の色。描画のたびに作り直す
  /** このライブにいる学年を下から並べ、数に合わせて離れた色を選ぶ（2つなら両端…）。5つ以上は4色の間を混ぜる */
  function gradeColorMap() {
    const gs = [...new Set(Object.values(state.bands).filter((b) => !b.off).flatMap((b) => b.members.map((m) => gradeOf(m.k))))]
      .filter((g) => g != null).sort((a, c) => a - c);
    const n = gs.length;
    const pick = [[3], [0, 3], [0, 2, 3], [0, 1, 2, 3]][n - 1];
    const at = (t) => {
      const s = t * 3;
      const i = Math.min(2, Math.floor(s));
      return `color-mix(in srgb, var(${GRADE_COLORS[i + 1]}) ${Math.round((s - i) * 100)}%, var(${GRADE_COLORS[i]}))`;
    };
    return Object.fromEntries(gs.map((g, i) => [g, pick ? `var(${GRADE_COLORS[pick[i]]})` : at(i / (n - 1))]));
  }
  const gradeBadge = (g) => el('span', { class: 'ttb-g' + (gradeColor[g] ? ' has-c' : ''), style: gradeColor[g] ? `--gc:${gradeColor[g]}` : null, text: gradeText(g) });
  const weightOf = (g) => (g == null ? null : WEIGHT[g] ?? 4);

  /** 配分（0〜100）から、学年とトリ支援それぞれの係数を出す。50 = 従来の比（1 : 1）。0 = 学年だけ / 100 = トリ支援だけ */
  function mixOf() {
    const m = (state.mix ?? 50) / 100;
    return { grade: Math.min(1, 2 * (1 - m)), help: m <= 0.5 ? 2 * m : 1 + (m - 0.5) * 8 };
  }

  /** 全日程を出順に見て、メンバーごとに出るバンドの並び（キー => [バンドID…]） */
  function appearances() {
    const map = {};
    state.days.slice(0, state.dayCount).forEach((day) => day.rows.forEach((r) => {
      state.bands[r.id]?.members.forEach((m) => { (map[m.k] ||= []).push(r.id); });
    }));
    return map;
  }

  /** バンドの「おすすめ度」= 学年の重みの平均 + トリ支援（トリ・後ろの経験が少ない人が多いほど加点）。係数は mixOf */
  function scoreOf(b) {
    const ws = [];
    let miss = 1; // 全員の (1 - need) の積。該当する人が多いほど小さくなる
    b.members.forEach((m) => {
      const g = gradeOf(m.k);
      const w = weightOf(g);
      if (w != null) ws.push(w);
      // 5バンド以上出ている人は学年に関係なく、出演が多い割にトリ・後ろが少ないほど加点（上限 0.7。配分のスライダーで効き具合を変える）
      //   経験 = 出演数 / 20（20回で上限）× トリ不足 = 1 / (1 + トリ回数) × 前寄り = 0.5 + 0.5 × 前寄りの度合い
      //   前寄りの度合い: 逆順 ÷ バンド数の平均 q が 0.5（真ん中）→ 0 / 0.8 以上 → 1（実データでは中央値が約 0.5、0.65 以上は上位 1 割）
      const h = INFO[m.k];
      if (h && h.n >= 5 && h.q != null) {
        const front = Math.max(0, Math.min(1, (h.q - 0.5) / 0.3));
        const need = Math.min(1, h.n / 20) * (1 / (1 + h.t)) * (0.5 + 0.5 * front);
        miss *= 1 - need;
      }
    });
    // 該当する人が多いほど加点（1人なら need × 0.7。2人目以降は残りの幅を埋めていくので 0.7 は超えない）
    const bonus = (1 - miss) * 0.7;
    const base = ws.length ? ws.reduce((a, c) => a + c, 0) / ws.length : 1;
    // 学年の最大 4・トリ支援の最大 0.7 を配分で混ぜ、いちばん高くなり得る値が 4 になるようにそろえる
    const k = mixOf();
    const total = ((base * k.grade + bonus * k.help) / (4 * k.grade + 0.7 * k.help)) * 4;
    return { base, bonus, total };
  }

  const keysOf = (id) => new Set(state.bands[id].members.map((m) => m.k));
  /** 1日分のバンドの並びから、行ごとの警告（2 = 隣で連続 / 1 = 1つ挟み）と、メンバーごとの警告を出す */
  function conflicts(ids) {
    const bandIds = ids.filter((id) => state.bands[id]);
    const sets = bandIds.map(keysOf);
    const row = {};
    const member = {}; // `${id}:${k}` => 2 | 1
    bandIds.forEach((id, i) => {
      let lvl = 0;
      sets[i].forEach((k) => {
        let ml = 0;
        if (sets[i - 1]?.has(k) || sets[i + 1]?.has(k)) ml = 2;
        else if (sets[i - 2]?.has(k) || sets[i + 2]?.has(k)) ml = 1;
        if (ml) member[`${id}:${k}`] = ml;
        lvl = Math.max(lvl, ml);
      });
      row[id] = lvl;
    });
    return { row, member };
  }
  function dayWarnings(d) {
    const c = conflicts(state.days[d - 1].rows.map((r) => r.id));
    const vals = Object.values(c.row);
    return { red: vals.filter((x) => x === 2).length, yellow: vals.filter((x) => x === 1).length };
  }

  /* ===================== 自動配置 ===================== */
  /** 1バンド挟みの重み。設定「2バンド以上間隔を開けて自動配置」が外れていれば 0（隣だけ避け、あとはおすすめ度の順） */
  const gapCost = () => (state.gap ?? true ? 10 : 0);

  function pairCost(seqSets) {
    let cost = 0;
    const gap = gapCost();
    for (let i = 0; i < seqSets.length; i++) {
      for (const k of seqSets[i]) {
        if (seqSets[i + 1]?.has(k)) { cost += 100; break; }
      }
      for (const k of seqSets[i]) {
        if (seqSets[i + 2]?.has(k)) { cost += gap; break; }
      }
    }
    return cost;
  }

  function autoArrange() {
    const dc = state.dayCount;
    const score = {};
    Object.keys(state.bands).forEach((id) => { score[id] = scoreOf(state.bands[id]).total; });

    // ---- 1. 日程の割り当て（ピン留めは今の日のまま。出られる日が1つならその日） ----
    const dayOfBand = {};
    const load = Array(dc).fill(0);
    const onDay = Array.from({ length: dc }, () => new Map()); // 日ごとの メンバー => 出る回数
    const addTo = (id, d) => {
      dayOfBand[id] = d;
      load[d]++;
      keysOf(id).forEach((k) => onDay[d].set(k, (onDay[d].get(k) || 0) + 1));
    };
    const current = {};
    state.days.slice(0, dc).forEach((day, d) => day.rows.forEach((r) => { current[r.id] = d; }));
    const free = [];
    Object.entries(state.bands).forEach(([id, b]) => {
      if (b.off) return;
      if (b.pin) addTo(id, current[id]);
      else if (b.avail.length === 1) addTo(id, b.avail[0] - 1);
      else free.push(id);
    });
    free.sort((a, c) => state.bands[a].avail.length - state.bands[c].avail.length || score[c] - score[a]);
    free.forEach((id) => {
      let best = null;
      let bestCost = Infinity;
      state.bands[id].avail.forEach((day) => {
        const d = day - 1;
        let shared = 0;
        keysOf(id).forEach((k) => { shared += onDay[d].get(k) || 0; });
        const cost = load[d] + shared * 1.5 - (current[id] === d ? 0.3 : 0);
        if (cost < bestCost) { bestCost = cost; best = d; }
      });
      addTo(id, best);
    });

    // ---- 2. 日ごとの並び ----
    const result = [];
    for (let d = 0; d < dc; d++) {
      const oldRows = state.days[d].rows.map((r) => r.id);
      const oldBands = oldRows.filter((id) => state.bands[id]);
      const bands = Object.keys(dayOfBand).filter((id) => dayOfBand[id] === d);
      const M = bands.length;
      const slots = Array(M).fill(null);
      // ピン留めのバンドは今の位置（バンドの中で何番目か）に置く
      bands.filter((id) => state.bands[id].pin).forEach((id) => {
        let p = Math.min(Math.max(oldBands.indexOf(id), 0), M - 1);
        while (slots[p] !== null) p = (p + 1) % M;
        slots[p] = id;
      });
      const pool = bands.filter((id) => !state.bands[id].pin);
      // 後ろから、おすすめ度が高い順に。連続（隣=赤 / 1つ挟み=黄）にならない候補を優先
      const setAt = (p) => (slots[p] ? keysOf(slots[p]) : null);
      const clash = (id, p) => {
        const ks = keysOf(id);
        let c = 0;
        [[p - 1, 100], [p + 1, 100], [p - 2, gapCost()], [p + 2, gapCost()]].forEach(([q, w]) => {
          const s = q >= 0 && q < M ? setAt(q) : null;
          if (s && [...ks].some((k) => s.has(k))) c += w;
        });
        return c;
      };
      for (let p = M - 1; p >= 0; p--) {
        if (slots[p]) continue;
        let best = -1;
        let bestVal = -Infinity;
        pool.forEach((id, i) => {
          const val = score[id] - clash(id, p);
          if (val > bestVal) { bestVal = val; best = i; }
        });
        slots[p] = pool.splice(best, 1)[0];
      }
      // 入れ替えで仕上げ（連続の数 + おすすめ度の順からのずれ を小さく）
      const movable = slots.map((id, i) => (state.bands[id].pin ? -1 : i)).filter((i) => i >= 0);
      const ideal = [...slots].sort((a, c) => score[a] - score[c]);
      const cost = (seq) => pairCost(seq.map(keysOf)) + seq.reduce((sum, id, i) => sum + Math.abs(i - ideal.indexOf(id)) * 0.6, 0);
      let cur = cost(slots);
      for (let pass = 0; pass < 30; pass++) {
        let improved = false;
        for (let a = 0; a < movable.length; a++) {
          for (let c = a + 1; c < movable.length; c++) {
            const i = movable[a];
            const j = movable[c];
            [slots[i], slots[j]] = [slots[j], slots[i]];
            const nc = cost(slots);
            if (nc < cur - 1e-9) { cur = nc; improved = true; } else [slots[i], slots[j]] = [slots[j], slots[i]];
          }
        }
        if (!improved) break;
      }
      // 休憩・テキストの行は、元の「何バンド目の後か」の割合の位置に戻す（先頭・最後の行はそのまま先頭・最後）
      const N = oldBands.length;
      const extras = [];
      let before = 0;
      oldRows.forEach((id) => {
        if (state.bands[id]) before++;
        else extras.push({ id, at: N ? Math.round((before / N) * M) : 0 });
      });
      const out = [];
      for (let p = 0; p <= M; p++) {
        extras.filter((x) => x.at === p).forEach((x) => out.push(x.id));
        if (p < M) out.push(slots[p]);
      }
      result.push(out);
    }
    animateRows(() => commit({ op: 'arrange', days: result }));
  }

  /** 再描画の前後で行の位置を比べ、動いた行は元の位置から滑らせ、新しく現れた行（別の日から来た）はふわっと出す */
  function animateRows(change) {
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const before = new Map();
    if (!reduce) root.querySelectorAll('.ttb-row').forEach((x) => before.set(x.dataset.id, x.getBoundingClientRect().top));
    change();
    if (reduce) return;
    root.querySelectorAll('.ttb-row').forEach((x) => {
      const old = before.get(x.dataset.id);
      if (old == null) { x.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 180, easing: 'ease-out' }); return; }
      const dy = old - x.getBoundingClientRect().top;
      if (Math.abs(dy) > 1) x.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 220, easing: 'ease-out' });
    });
  }

  /* ===================== 小さい部品 ===================== */
  function el(tag, attrs = {}, ...children) {
    const e = document.createElement(tag);
    Object.entries(attrs).forEach(([k, val]) => {
      if (val == null || val === false) return;
      if (k === 'class') e.className = val;
      else if (k === 'text') e.textContent = val;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), val);
      else e.setAttribute(k, val === true ? '' : val);
    });
    children.flat().forEach((c) => { if (c != null && c !== false) e.append(c instanceof Node ? c : String(c)); });
    return e;
  }
  const icon = (name) => el('span', { class: 'icon', 'aria-hidden': 'true', text: name });
  const hueOf = (n) => (n * 67) % 360;
  const addMin = (hm, m) => {
    const [h, mi] = hm.split(':').map(Number);
    const t = (h * 60 + mi + m) % 1440;
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
  };
  const newId = () => 'x' + Math.random().toString(36).slice(2, 10);
  /*
   * Key（機材メモ）を、画像の key 列に出す短い表記にする（lib/tt_builder.php の tt_key_short と同じ動き。直すときは両方直すこと）
   *   1つだけ … 名簿の書き方のまま（括弧は全角にそろえる）  「貸出(エレピ)」→「貸出（エレピ）」
   *   2つ以上 … 「私/貸 + 楽器」にして「/」でつなぐ        「私物(シンセ), 貸出(エレピ)」→「私シンセ/貸エレピ」
   *   「私物（楽器）」「貸出（楽器）」の形でないものは、そのまま残す（読み替えて意味を変えないため）
   */
  function keyShort(key) {
    // split の正規表現: カンマ・読点・スラッシュ（前後の空白ごと）で区切る。filter(Boolean) で空の要素を捨てる
    const items = String(key || '').trim().split(/\s*[,，、/／]\s*/).filter(Boolean);
    // match の () で取り出した [1] = 私物/貸出などの頭、[2] = 括弧の中（楽器）。全角・半角どちらの括弧も受ける
    const parsed = items.map((it) => {
      const m = it.match(/^(私物|私|貸出し?|貸し?出し?|貸)\s*[（(]\s*(.+?)\s*[）)]$/);
      return m ? { head: m[1], inst: m[2], raw: it } : { head: null, inst: null, raw: it };
    });
    if (parsed.length === 1) return parsed[0].head === null ? parsed[0].raw : `${parsed[0].head}（${parsed[0].inst}）`;
    return parsed.map((p) => (p.head === null ? p.raw : [...p.head][0] + p.inst)).join('/');
  }

  let toastEl = null;
  function toast(msg, action) {
    toastEl?.remove();
    toastEl = el('div', { class: 'ttb-toast', role: 'status' }, el('span', { text: msg }),
      action ? el('button', { type: 'button', class: 'btn btn--ghost btn--sm', text: action.label, onclick: () => { action.run(); toastEl?.remove(); } }) : null);
    document.body.append(toastEl);
    const mine = toastEl;
    setTimeout(() => mine.remove(), action ? 8000 : 4000);
  }

  // ポップアップ（メニュー・学年の選択）。外を押すと閉じる
  let popEl = null;
  function closePop() { popEl?.remove(); popEl = null; }
  function openPop(anchor, content) {
    closePop();
    popEl = el('div', { class: 'ttb-pop card', role: 'dialog' }, content);
    document.body.append(popEl);
    const r = anchor.getBoundingClientRect();
    const w = popEl.offsetWidth;
    popEl.style.left = `${Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - w - 8))}px`;
    popEl.style.top = `${window.scrollY + r.bottom + 4}px`;
  }
  document.addEventListener('pointerdown', (e) => {
    if (popEl && !popEl.contains(e.target) && !e.target.closest('[data-pop-anchor]')) closePop();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePop(); });

  /* ===================== 描画 ===================== */
  const signature = () => JSON.stringify([state, people]);
  const typing = () => root.contains(document.activeElement) && document.activeElement.matches('input:not([type=checkbox])');
  root.addEventListener('focusout', () => setTimeout(() => { if (needRender && !dragging && !typing()) render(); }, 0));

  function render() {
    needRender = false;
    lastSig = signature();
    if (curDay > state.dayCount) curDay = state.dayCount;
    gradeColor = gradeColorMap();
    document.title = `${state.title} — タイムテーブル作成`;
    root.classList.toggle('is-enter', enter);
    enter = false;
    root.replaceChildren(renderHead(), viewSwitch(), view === 'days' ? renderDays() : view === 'preview' ? renderPreview() : renderOrder());
  }

  function statusIcon() {
    const map = { saved: ['cloud_done', '保存済み'], saving: ['sync', '保存中'], error: ['cloud_off', '保存できません（再送します）'] };
    const [ic, label] = map[status];
    return el('span', { class: `ttb-status is-${status}`, title: label, 'aria-label': label }, icon(ic));
  }

  function renderHead() {
    const others = people.filter((p) => !p.me);
    const menuBtn = el('button', { type: 'button', class: 'btn btn--ghost btn--sm ttb-iconbtn', 'aria-label': 'メニュー', 'data-pop-anchor': true, onclick: (e) => openPop(e.currentTarget, mainMenu()) }, icon('more_vert'));
    return el('div', { class: 'ttb-head' },
      el('a', { href: 'tt_builder', class: 'ttb-iconbtn btn btn--ghost btn--sm', 'aria-label': '一覧' }, icon('arrow_back')),
      el('h1', { class: 'ttb-title', text: state.title }),      boot.published ? el('a', { class: 'tag', href: `live?id=${boot.published}`, title: '公開済み' }, icon('task_alt')) : null,
      el('span', { class: 'ttb-people' }, others.map((p) => el('span', { class: 'ttb-person', title: `${p.name} が編集中`, style: `--h:${hueOf(p.id)}`, text: [...p.name][0] || '?' }))),
      statusIcon(),
      menuBtn);
  }

  // 画面の切り替え（出演日 / 出演順 / プレビュー）。メニューを開かずに行き来できるよう見出しの下に常に出す
  function viewSwitch() {
    const views = [['days', 'event_available', '出演日'], ['order', 'format_list_numbered', '出演順'], ['preview', 'table_view', 'プレビュー']];
    return el('div', { class: 'ttb-seg ttb-views', role: 'group', 'aria-label': '画面' }, views.map(([v, ic, label]) => el('button', {
      type: 'button', class: view === v ? 'is-on' : '', 'aria-pressed': String(view === v),
      onclick: () => { if (view !== v) goView(v); },
    }, icon(ic), label)));
  }

  function mainMenu() {
    const item = (ic, label, onclick, attrs = {}) => el('button', { type: 'button', class: 'ttb-menu__item', onclick: () => { closePop(); onclick(); }, ...attrs }, icon(ic), label);
    return el('div', { class: 'ttb-menu' },
      item('edit', 'ライブ名を変更', () => {
        const x = prompt('ライブ名', state.title);
        if (x != null && x.trim() !== '') commit({ op: 'title', x: x.trim().slice(0, 100) });
      }),
      range(state.dayCount).map((d) => el('a', { class: 'ttb-menu__item', href: `tt_builder?id=${boot.id}&csv=${d}` }, icon('download'), `CSV出力（${LABELS[d - 1]}）`)),
      // 画像は <a> ではなくボタン: サーバーへ取りに行かず、ブラウザで描いて保存するため
      range(state.dayCount).map((d) => item('download', `PNG出力（${LABELS[d - 1]}）`, () => exportImage(d))),
      item('publish', '公開（ライブとして登録）', () => {
        if (pending.length || inflight) { toast('保存中です。少し待ってからもう一度'); return; }
        document.getElementById('ttb-publish').requestSubmit();
      }),
      item('delete', '削除', () => document.getElementById('ttb-delete').requestSubmit(), { 'data-danger': true }));
  }

  function goView(v) {
    enter = true;
    root.style.setProperty('--ttb-dir', 0);
    view = v;
    history.replaceState(null, '', v === 'order' ? location.pathname + location.search : `#${v}`);
    render();
    window.scrollTo(0, 0);
  }

  /* ---------- プレビュー（書き出す CSV・完成形の表と同じ形。日付・会場・ライブ名・持ち時間・バンド名・key をその場で編集） ---------- */
  function renderPreview() {
    const dc = state.dayCount;
    const d = curDay;
    const day = state.days[d - 1];
    const tabs = dc > 1 ? el('div', { class: 'ttb-tabs', role: 'tablist' }, range(dc).map((x) => el('button', {
      type: 'button', role: 'tab', class: 'ttb-tab' + (x === d ? ' is-on' : ''), 'aria-selected': String(x === d),
      onclick: () => { if (x === d) return; root.style.setProperty('--ttb-dir', x > d ? 1 : -1); curDay = x; enter = true; render(); },
    }, LABELS[x - 1]))) : null;

    // 持ち時間の出し方: 1枠の時間そのまま / バンドだけ表記上の値を一括（休憩・テキストは1枠の時間のまま）
    const bulk = state.dur ?? null;
    const bar = el('div', { class: 'ttb-bar' },
      el('span', { class: 'small muted', text: '持ち時間の編集' }),
      el('div', { class: 'ttb-seg', role: 'group', 'aria-label': '持ち時間の編集' },
        el('button', { type: 'button', class: bulk === null ? 'is-on' : '', 'aria-pressed': String(bulk === null), text: '1枠ずつ', onclick: () => { if (bulk !== null) commit({ op: 'dur', v: null }); } }),
        el('button', { type: 'button', class: bulk !== null ? 'is-on' : '', 'aria-pressed': String(bulk !== null), text: '一括', onclick: () => { if (bulk === null) commit({ op: 'dur', v: commonMin() ?? 15 }); } })),
      bulk !== null ? el('input', {
        type: 'number', class: 'ttb-min', min: 0, max: 600, inputmode: 'numeric', value: bulk, 'aria-label': 'バンドの持ち時間（分）',
        onchange: (e) => { const n = parseInt(e.target.value, 10); if (n >= 0 && n <= 600) commit({ op: 'dur', v: n }); else e.target.value = bulk; },
      }) : null,
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => exportImage(d) }, icon('download'), '出力'));

    const td = (attrs, ...kids) => el('td', attrs, ...kids);
    // セルの入力欄。変更したら保存。保存できない値（空のバンド名など）は元に戻す
    const field = (value, save, attrs = {}) => el('input', {
      class: 'ttb-pv__in', value: value ?? '', ...attrs,
      onchange: (e) => { if (!save(e.target.value.trim())) e.target.value = value ?? ''; },
    });
    const minField = (value, save) => field(value, (x) => {
      if (x === '') return save(null);
      const n = parseInt(x, 10);
      return n >= 0 && n <= 600 && save(n);
    }, { type: 'number', min: 0, max: 600, inputmode: 'numeric', class: 'ttb-pv__in ttb-pv__num', 'aria-label': '持ち時間（分）' });

    // 会場はプルダウン（登録済みの会場。下書きに別の名前が入っていればそれも選べるように足す）
    const venueSelect = (cur, save) => {
      const names = [...(boot.venues || [])];
      if (cur && !names.includes(cur)) names.unshift(cur);
      return el('select', {
        class: 'ttb-pv__in', 'aria-label': '会場',
        onchange: (e) => {
          if (e.target.value !== '＋') { save(e.target.value); return; }
          // 新しい会場: 名前を入れると下書きに入り、公開（取り込み）のとき会場として登録される
          const x = prompt('新しい会場名', '');
          if (x != null && x.trim() !== '') save(x.trim().slice(0, 50)); else e.target.value = cur;
        },
      },
        el('option', { value: '', text: '' }),
        names.map((n) => el('option', { value: n, text: n, selected: n === cur })),
        el('option', { value: '＋', text: '＋ 新しい会場' }));
    };
    const countField = (value, save, label) => field(value, (x) => {
      if (x === '') return save(null);
      const n = parseInt(x, 10);
      return n >= 0 && n <= 99 && save(n);
    }, { type: 'number', min: 0, max: 99, inputmode: 'numeric', class: 'ttb-pv__in ttb-pv__num', 'aria-label': label });
    const top = el('tr', { class: 'ttb-pv__top' },
      td({ colspan: 2 }, field(day.date, (x) => commit({ op: 'date', day: d, x }), { type: 'date', 'aria-label': '開催日' })),
      td({}),
      td({ class: 'ttb-pv__title' }, field(state.title, (x) => x !== '' && commit({ op: 'title', x: x.slice(0, 100) }), { maxlength: 100, 'aria-label': 'ライブ名' }), el('span', { text: LABELS[d - 1] })),
      td({ class: 'ttb-pv__b', text: '会場' }),
      td({ colspan: 2 }, venueSelect(day.venue || '', (x) => commit({ op: 'venue', day: d, x }))));
    const head = el('tr', { class: 'ttb-pv__head' }, ['時間', '持ち時間', 'バンド名', '曲数', '人数', 'key'].map((t, i) => el('th', { colspan: i === 0 ? 2 : null, text: t })));

    let cur = day.call || null;
    const rows = day.rows.map((r, i) => {
      const b = state.bands[r.id];
      const start = cur;
      if (cur && r.min != null) cur = addMin(cur, r.min);
      const edge = i === 0 || i === day.rows.length - 1;
      if (!b && edge) {
        // 先頭（集合など）と末尾（撤収など）の休憩・テキストは開始時刻だけ。途中のものはバンドと同じく終了時刻も出す
        return el('tr', { class: 'ttb-pv__extra' },
          td({ colspan: 2, class: 'mono', text: start || '' }),
          td({}, minField(r.min, (n) => commit({ op: 'min', id: r.id, min: n }))),
          td({ class: 'ttb-pv__b' }, field(r.x, (x) => commit({ op: 'text', id: r.id, x: x.slice(0, 50) }), { maxlength: 50, 'aria-label': '内容' })),
          td({}), td({}), td({}));
      }
      if (!b) {
        return el('tr', { class: 'ttb-pv__extra' },
          td({ class: 'mono', text: start || '' }), td({ class: 'mono', text: start && r.min != null ? cur : '' }),
          td({}, minField(r.min, (n) => commit({ op: 'min', id: r.id, min: n }))),
          td({ class: 'ttb-pv__b' }, field(r.x, (x) => commit({ op: 'text', id: r.id, x: x.slice(0, 50) }), { maxlength: 50, 'aria-label': '内容' })),
          td({}), td({}), td({}));
      }
      return el('tr', {},
        td({ class: 'mono', text: start || '' }), td({ class: 'mono', text: start && r.min != null ? cur : '' }),
        td({}, bulk === null
          ? minField(r.min, (n) => commit({ op: 'min', id: r.id, min: n }))
          : minField(b.dur ?? bulk, (n) => commit({ op: 'bdur', b: r.id, v: n === bulk ? null : n }))),
        td({ class: 'ttb-pv__b' }, field(b.name, (x) => x !== '' && commit({ op: 'name', b: r.id, x: x.slice(0, 100) }), { maxlength: 100, 'aria-label': 'バンド名' })),
        td({}, countField(b.songs, (n) => commit({ op: 'songs', b: r.id, v: n }), '曲数')),
        td({}, countField(b.cnt, (n) => commit({ op: 'cnt', b: r.id, v: n }), '人数')),
        td({}, field(b.key, (x) => commit({ op: 'key', b: r.id, x: x.slice(0, 60) }), { maxlength: 60, 'aria-label': 'key' })));
    });
    return el('div', { class: 'ttb-pv' }, tabs, bar,
      el('div', { class: 'ttb-pv__scroll card' }, el('table', { class: 'ttb-pv__table' }, el('tbody', {}, top, head, rows))));
  }

  /* ---------- 画像（PNG）の書き出し ----------
   * 仕組み: <canvas>（ブラウザの中の「お絵かき用の白い紙」）に、表を線と文字で描いて PNG にする。
   * なぜ PHP（サーバー）ではなくブラウザで描くのか？
   *   ・サーバーで日本語を描くには「日本語フォント」が必要で、レンタルサーバーには入っていないことが多い
   *   ・今画面に出ている state（保存前の最新の変更を含む）をそのまま使えるので、サーバーに送り直す必要がない
   * 見た目は、運営が使っているタイムテーブル（Excel）の画像そっくりにしてある:
   *   灰色の見出し行 / 「時間」は開始・終了を1つに結合した見出し / 太字のバンド名 / 右端に空欄の key 列
   * 列: 時間（開始・終了）/ 持ち時間 / バンド名 / 曲数 / 人数 / key（名簿の Key 列。2つ以上は略称。keyShort を見る）
   * 日付・会場は下書きに無い（取り込みのプレビューで入れる）ので載せない。
   * 時刻の計算は renderOrder / tt_day_rows（PHP）と同じ: 集合時間から上の行の持ち時間を順に足す。
   */
  async function exportImage(d) {
    const day = state.days[d - 1];
    if (!day) return;

    // --- 1. 見た目の設定（数字を変えれば画像の雰囲気が変わる。単位は論理ピクセル） ---
    const SCALE = 2;                       // 2倍の細かさで描く（スマホで拡大しても文字がぼやけない）
    const W = 1024;                        // 画像の横幅（実際の画像は W × SCALE）
    const PAD = 20;                        // 画像のまわりの余白
    const TITLE_H = 36;                    // 一番上のタイトル行（枠なし）の高さ
    const HEAD_H = 28;                     // 見出し行の高さ
    const ROW_H = 29;                      // 1行の高さ
    const SIZE = 21;                       // 文字の大きさ（見出し・本文共通。タイトルだけ下で +2）
    const HEAD_BG = '#808080';             // 見出し行の灰色
    const LINE = '#4d4d4d';                // 罫線の色
    // フォントの優先順: ① サイトが読み込んでいる Noto Sans JP（太字もある）→ ② assets/fonts の同じフォント（下で登録）→ ③ 端末のフォント
    // 先に書いたものが使われ、その中に無い文字だけ次のフォントで描かれる（CSS の font-family と同じ考え方）
    const FONT = '"Noto Sans JP", "ttb-noto", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif';
    // 列の幅の比率: 開始 / 終了 / 持ち時間 / バンド名 / 曲数 / 人数 / key（合計 10）
    const UNITS = [1, 1, 1, 3, 1, 1, 2];
    const TW = W - PAD * 2;                // 表の幅
    const xs = [PAD];                      // 各列の左端の x 座標（最後は表の右端）。xs[i] が i 番目の列の左端
    UNITS.forEach((u) => xs.push(xs[xs.length - 1] + (TW * u) / 10));

    // --- 2. 描く行を作る（時刻を足し込む） ---
    let cur = day.call || null;            // 今の時刻。集合時間が空なら null（時刻は空欄）
    const rows = [];
    if (day.call) rows.push({ band: false, start: day.call, end: '', min: '', name: '集合', songs: '', cnt: '' });
    day.rows.forEach((r) => {
      const start = cur;
      const end = cur && r.min != null ? addMin(cur, r.min) : null;
      if (end) cur = end;
      const b = state.bands[r.id];         // バンドなら中身がある。休憩・テキスト行は undefined
      rows.push({
        band: !!b, start: start || '', end: r.min ? end || '' : '',
        min: r.min != null ? String(r.min) : '', name: b ? b.name : r.x,
        songs: b && b.songs != null ? String(b.songs) : '', cnt: b ? String(b.cnt) : '',
        key: b ? keyShort(b.key) : '',     // Key（機材メモ）は、2つ以上のとき略称にして狭い列に収める
      });
    });
    // 日付・会場: 公開済みなら登録した値（tt_builder.php が boot.meta に入れる）。無ければ空
    // 公開前は、プレビューで入れた開催日・会場（state.days）を使う
    const pub = (boot.meta || {})[d];
    const m = day.date && /^(\d{4})-(\d{2})-(\d{2})$/.test(day.date) ? day.date.split('-').map(Number) : null;
    const meta = pub || { date: m ? `${m[0]}/${m[1]}/${m[2]}` : '', venue: day.venue || '' };
    const hasMeta = !!(meta.date || meta.venue);
    const title = state.dayCount > 1 ? `${state.title}${LABELS[d - 1]}` : state.title;
    const HEADS = ['時間', '持ち時間', 'バンド名', '曲数', '人数', 'key'];

    // --- 3. フォントを読み込んでから描く（読み込み前に描くと、別のフォントで描かれてしまう） ---
    // 1) assets/fonts のフォントを「ttb-noto」という名前で登録する（FontFace = JS からフォントを追加する部品）。1回だけ
    // 2) document.fonts.load(フォント指定, 実際に描く文字): 「この文字を描くフォント」を確実に読み込ませる。
    //    Google Fonts は文字の範囲ごとに分割して配るので、文字を渡さないと、まだ画面に出ていない文字の分が間に合わないことがある
    try {
      // ※ document.fonts.check は「その名前のフォントが無い」ときも true を返す仕様なので、登録済みかの判定には使えない
      if (![...document.fonts].some((x) => x.family.replace(/"/g, '') === 'ttb-noto')) {
        const f = new FontFace('ttb-noto', 'url(assets/fonts/NotoSansJP-Regular.ttf)');
        document.fonts.add(await f.load());
      }
      const used = [title, meta.date, meta.venue, '会場', ...HEADS, ...rows.flatMap((r) => [r.name, r.start, r.end, r.min, r.songs, r.cnt, r.key])].join('');
      await Promise.all(['400', '700'].map((w) => document.fonts.load(`${w} ${SIZE}px ${FONT}`, used)));
    } catch { /* 読み込めなくても端末のフォントで描ける */ }

    // --- 4. 紙（canvas）を用意する ---
    const top = PAD + TITLE_H;             // 見出し行の上端
    const bottom = top + HEAD_H + ROW_H * rows.length; // 表の下端
    const H = bottom + PAD;
    const canvas = document.createElement('canvas');
    canvas.width = W * SCALE;              // canvas.width は「本当のピクセル数」
    canvas.height = H * SCALE;
    const g = canvas.getContext('2d');     // g = 絵を描くための「ペン」（2D コンテキスト）
    g.scale(SCALE, SCALE);                 // 以降は論理ピクセル（W × H）のつもりで描ける

    // 文字を描く部品。x1〜x2 の真ん中に描く。幅が足りないときはフォントを小さくして収める（長いバンド名の対策）
    const text = (s, x1, x2, cy, { bold = false, size = SIZE } = {}) => {
      if (!s) return;
      const maxW = x2 - x1 - 10;
      let px = size;
      g.font = `${bold ? '700' : '400'} ${px}px ${FONT}`;
      while (px > 10 && g.measureText(s).width > maxW) {   // measureText = 描いたときの幅を測る
        px -= 1;
        g.font = `${bold ? '700' : '400'} ${px}px ${FONT}`;
      }
      g.fillStyle = '#000';
      g.textAlign = 'center';
      g.textBaseline = 'middle';           // y 座標を文字の縦の真ん中に合わせる
      g.fillText(s, (x1 + x2) / 2, cy);
    };
    const line = (x1, y1, x2, y2) => { g.beginPath(); g.moveTo(x1, y1); g.lineTo(x2, y2); g.stroke(); };

    // --- 5. 描く ---
    g.fillStyle = '#fff';                  // 背景を白で塗る（塗らないと PNG の背景が透明になり、黒く見える場合がある）
    g.fillRect(0, 0, W, H);

    // タイトル（枠なし）。元の画像と同じく「バンド名」の列の真ん中に置く
    // 日付・会場があるときは元の画像と同じ並び（左に日付 / 真ん中にタイトル / 右に「会場 〇〇」）。無いときはタイトルだけを広く使う
    const cx = (xs[3] + xs[4]) / 2;
    const half = hasMeta ? (xs[4] - xs[2]) / 2 : Math.min(cx - PAD, PAD + TW - cx);
    text(title, cx - half, cx + half, PAD + TITLE_H / 2, { bold: true, size: SIZE + 2 });
    if (hasMeta) {
      text(meta.date, xs[0], xs[2], PAD + TITLE_H / 2);
      if (meta.venue) {
        text('会場', xs[4], xs[5], PAD + TITLE_H / 2, { bold: true });
        text(meta.venue, xs[5], xs[7], PAD + TITLE_H / 2, { bold: true });
      }
    }

    // 見出し行（灰色の背景 + 太字）。「時間」だけ開始・終了の2列を結合する。[見出し, 左の列番号, 右の列番号]
    g.fillStyle = HEAD_BG;
    g.fillRect(PAD, top, TW, HEAD_H);
    [[0, 0, 2], [1, 2, 3], [2, 3, 4], [3, 4, 5], [4, 5, 6], [5, 6, 7]].forEach(([i, a, b]) =>
      text(HEADS[i], xs[a], xs[b], top + HEAD_H / 2, { bold: HEADS[i] !== 'key' }));

    // 本文の行
    rows.forEach((r, i) => {
      const mid = top + HEAD_H + ROW_H * i + ROW_H / 2;
      // バンドの行は 開始 | 終了 に分ける。休憩などは終了があるときだけ分け、無いとき（集合・撤収）は1つのセルに結合して真ん中に出す
      if (r.band || r.end) { text(r.start, xs[0], xs[1], mid); text(r.end, xs[1], xs[2], mid); } else { text(r.start, xs[0], xs[2], mid); }
      text(r.min, xs[2], xs[3], mid);
      text(r.name, xs[3], xs[4], mid, { bold: true });
      text(r.songs, xs[4], xs[5], mid);
      text(r.cnt, xs[5], xs[6], mid);
      text(r.key, xs[6], xs[7], mid, { bold: true });
    });

    // 罫線: 外枠 → 見出しと各行の下の横線 → 縦線（開始|終了 の線だけ、分けた行にだけ引く）
    g.strokeStyle = LINE;
    g.lineWidth = 1;
    g.strokeRect(PAD, top, TW, bottom - top);
    for (let y = top + HEAD_H, i = 0; i <= rows.length; i++, y += ROW_H) line(PAD, y, PAD + TW, y);
    for (let b = 2; b <= 6; b++) line(xs[b], top, xs[b], bottom);       // 持ち時間 / バンド名 / 曲数 / 人数 / key の境目は全部の行を通す
    rows.forEach((r, i) => {
      if (!(r.band || r.end)) return;
      const y = top + HEAD_H + ROW_H * i;
      line(xs[1], y, xs[1], y + ROW_H);
    });

    // --- 6. PNG にしてダウンロードさせる ---
    // toBlob = canvas を画像ファイル（Blob = 中身がバイナリのデータのかたまり）に変換する
    canvas.toBlob((blob) => {
      if (!blob) { toast('画像を作れませんでした'); return; }
      const url = URL.createObjectURL(blob);   // Blob を指す一時的な URL（このタブの中だけで使える）
      const a = el('a', { href: url, download: `${state.title}${LABELS[d - 1]}.png` });
      document.body.append(a);
      a.click();                                // 見えないリンクをクリックしてダウンロードを始める（定番のやり方）
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000); // 一時 URL を片付ける（メモリの解放）
    }, 'image/png');
  }

  /* ---------- 日程希望 ---------- */
  function renderDays() {
    const dc = state.dayCount;
    const ids = allBandIds();
    const seg = el('div', { class: 'ttb-seg', role: 'group', 'aria-label': '日数' },
      [1, 2, 3].map((n) => el('button', { type: 'button', class: n === dc ? 'is-on' : '', 'aria-pressed': String(n === dc), text: `${n}日`, onclick: () => commit({ op: 'dayCount', n }) })));
    const head = el('div', { class: 'ttb-avail__row ttb-avail__headrow' },
      el('span', { class: 'ttb-avail__name muted small', text: `${ids.length}バンド` }),
      range(dc).map((d) => el('button', {
        type: 'button', class: 'ttb-avail__col', title: `${LABELS[d - 1]}を一括で切り替え`, text: LABELS[d - 1],
        onclick: () => {
          // 全員OKなら外す（その日しか出られないバンドはそのまま）、そうでなければ全員OKにする
          const allOn = ids.every((id) => state.bands[id].avail.includes(d));
          ids.forEach((id) => {
            const a = state.bands[id].avail;
            if (allOn && a.length > 1) commit({ op: 'avail', b: id, days: a.filter((x) => x !== d) });
            if (!allOn && !a.includes(d)) commit({ op: 'avail', b: id, days: [...a, d] });
          });
        },
      })),
      el('span'));
    const rows = ids.map((id) => {
      const b = state.bands[id];
      return el('div', { class: 'ttb-avail__row' + (b.avail.length < dc ? ' is-limited' : '') },
        el('span', { class: 'ttb-avail__name' }, el('b', { text: b.name }), el('span', { class: 'ttb-avail__members' }, memberSpans(id, {}))),
        range(dc).map((d) => {
          const on = b.avail.includes(d);
          return el('label', { class: 'ttb-avail__check' }, el('input', {
            type: 'checkbox', checked: on, 'aria-label': `${b.name} ${LABELS[d - 1]}`,
            onchange: (e) => {
              const days = e.target.checked ? [...b.avail, d] : b.avail.filter((x) => x !== d);
              if (!days.length) { e.target.checked = true; toast('1日以上選んでください'); return; }
              commit({ op: 'avail', b: id, days });
            },
          }));
        }),
        el('button', { type: 'button', class: 'btn btn--ghost btn--sm ttb-iconbtn', 'aria-label': '非表示', title: '非表示', onclick: () => commit({ op: 'hide', b: id, on: true }) }, icon('visibility_off')));
    });
    const hidden = Object.entries(state.bands).filter(([, b]) => b.off);
    const hiddenBox = hidden.length ? el('div', { class: 'ttb-avail__table card ttb-hidden' },
      el('p', { class: 'small muted', text: `非表示 ${hidden.length}` }),
      hidden.map(([id, b]) => el('div', { class: 'ttb-hidden__row' },
        el('span', { class: 'ttb-hidden__name', text: b.name }),
        el('button', { type: 'button', class: 'btn btn--ghost btn--sm ttb-iconbtn', 'aria-label': '表示に戻す', title: '表示に戻す', onclick: () => commit({ op: 'hide', b: id, on: false }) }, icon('visibility'))))) : null;
    return el('div', { class: 'ttb-avail' },
      el('h2', { class: 'ttb-avail__title', text: '出演日を選択' }),
      el('div', { class: 'ttb-bar' }, el('span', { class: 'small muted', text: 'ライブ開催日数' }), seg),
      el('div', { class: 'ttb-avail__table card', style: `--dc:${dc}` }, head, rows),
      hiddenBox,
      el('button', { type: 'button', class: 'btn btn--primary btn--block', onclick: () => goView('order') }, '出演順へ', icon('arrow_forward')));
  }
  function allBandIds() {
    // 出順の並び（1日目から）で
    return state.days.flatMap((d) => d.rows.map((r) => r.id)).filter((id) => state.bands[id]);
  }

  /** メンバーの「4山田 3佐藤 ?鈴木」。学年不明は押すと学年を選べる */
  function memberSpans(id, memberWarn, appear = {}) {
    return state.bands[id].members.map((m) => {
      const g = gradeOf(m.k);
      const w = memberWarn[`${id}:${m.k}`];
      const cls = 'ttb-m' + (w === 2 ? ' is-red' : w === 1 ? ' is-yellow' : '');
      // 名前の下に、その人の出演数ぶんの点（このバンドまでを塗る = 何個目か）。最後の出演なら赤（1バンドだけの人も赤い点1つ）
      const list = appear[m.k] || [];
      const at = list.indexOf(id);
      const dots = list.length ? el('span', { class: 'ttb-dots', 'aria-hidden': 'true' }, list.map((_, i) => el('i', { class: (i <= at ? 'is-on' : '') + (i === at && at === list.length - 1 ? ' is-last' : '') }))) : null;
      const content = [gradeBadge(g), el('span', { text: m.n }), dots]; // 丸は名前の真下に中央揃え（学年バッジの分は含めない）
      if (INFO[m.k]?.g != null) return el('span', { class: cls, title: m.p }, content);
      return el('button', {
        type: 'button', class: cls + ' is-unknown', title: '学年を入力', 'data-pop-anchor': true,
        onclick: (e) => { e.stopPropagation(); openPop(e.currentTarget, gradePicker(m, id)); },
      }, content);
    });
  }
  function gradePicker(m, bandId) {
    const cur = state.grades[m.k] ?? null;
    const grades = el('div', { class: 'ttb-grades' },
      el('span', { class: 'small', text: m.n }),
      [1, 2, 3, 4, 5].map((g) => el('button', { type: 'button', class: 'btn btn--sm' + (cur === g ? ' btn--primary' : ''), text: gradeText(g), onclick: () => { closePop(); commit({ op: 'grade', k: m.k, g }); } })),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm', 'aria-label': '消去', onclick: () => { closePop(); commit({ op: 'grade', k: m.k, g: null }); } }, icon('close')));
    // DBにいない人: 「もしかして ◯◯？」。押すとその人の名前に置きかえる（取り込み画面の名前欄と同じ）
    const cands = (SIMILAR[m.k] || []).filter((c) => !state.bands[bandId].members.some((x) => x.k === c.k));
    if (!cands.length) return grades;
    const sug = el('p', { class: 'ttb-suggest small' }, 'もしかして ');
    cands.forEach((c, i) => {
      if (i > 0) sug.append(' / ');
      sug.append(el('button', {
        type: 'button', class: 'ttb-suggest__pick', text: c.n,
        onclick: () => { closePop(); commit({ op: 'member', b: bandId, k: m.k, n: c.n, nk: c.k }); },
      }));
    });
    sug.append('？');
    return el('div', {}, sug, grades);
  }

  /* ---------- 出順 ---------- */
  function renderOrder() {
    const dc = state.dayCount;
    const tabs = dc > 1 ? el('div', { class: 'ttb-tabs', role: 'tablist' }, range(dc).map((d) => {
      const w = dayWarnings(d);
      const n = state.days[d - 1].rows.filter((r) => state.bands[r.id]).length;
      return el('button', {
        type: 'button', role: 'tab', class: 'ttb-tab' + (d === curDay ? ' is-on' : ''), 'aria-selected': String(d === curDay), 'data-day-tab': d,
        onclick: () => { if (d === curDay) return; root.style.setProperty('--ttb-dir', d > curDay ? 1 : -1); curDay = d; enter = true; render(); },
      }, LABELS[d - 1], el('span', { class: 'ttb-tab__n', text: n }),
      w.red ? el('span', { class: 'ttb-dot is-red', title: `隣で連続 ${w.red}` }) : null,
      !w.red && w.yellow ? el('span', { class: 'ttb-dot is-yellow', title: `1つ挟み ${w.yellow}` }) : null);
    })) : null;

    const tools = el('div', { class: 'ttb-bar' },
      el('button', { type: 'button', class: 'btn btn--sm', onclick: () => autoArrange(), title: '日程希望・連続出演・学年から自動で並べる' }, icon('auto_fix_high'), '自動配置'),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => addRow('break') }, icon('add'), '休憩'),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => addRow('text') }, icon('add'), 'テキスト'),
      el('button', { type: 'button', class: 'btn btn--sm' + (timeMode ? ' btn--primary' : ''), 'aria-pressed': String(timeMode), onclick: () => { animateRows(() => { timeMode = !timeMode; render(); }); root.classList.add('is-tswitch'); setTimeout(() => root.classList.remove('is-tswitch'), 300); } }, icon('schedule'), '時刻'),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm ttb-iconbtn', 'aria-label': '設定', title: '設定', 'data-pop-anchor': true, onclick: (e) => openPop(e.currentTarget, settingsMenu()) }, icon('settings')),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm ttb-iconbtn', 'aria-label': '凡例', 'data-pop-anchor': true, onclick: (e) => openPop(e.currentTarget, legend()) }, icon('help')));

    const day = state.days[curDay - 1];
    const timeBar = timeMode ? el('div', { class: 'ttb-bar ttb-timebar' },
      el('label', { class: 'ttb-call' }, '集合', el('input', {
        type: 'time', value: day.call, onchange: (e) => commit({ op: 'call', day: curDay, t: TIME_RE.test(e.target.value) ? e.target.value : '' }),
      })),
      // 全バンドの持ち時間をまとめて入れる（個別の値は上書きされる。あとから各行で調整できる）
      el('label', { class: 'ttb-call' }, '持ち時間', el('input', {
        type: 'number', class: 'ttb-min', min: 0, max: 600, inputmode: 'numeric', value: commonMin() ?? '', placeholder: '分', 'aria-label': '全バンドの持ち時間（分）',
        onchange: (e) => {
          const val = e.target.value.trim();
          if (val === '') return;
          setAllMin(Math.max(0, Math.min(600, parseInt(val, 10) || 0)));
        },
      }))) : null;

    const ids = day.rows.map((r) => r.id);
    const c = { ...conflicts(ids), appear: appearances() };
    let no = 0;
    let cur = day.call || null;
    const list = el('div', { class: 'ttb-list' + (timeMode ? ' is-time' : ''), 'data-list': true }, day.rows.map((r) => {
      const start = cur;
      if (cur && r.min != null) cur = addMin(cur, r.min);
      return state.bands[r.id] ? bandRow(r, ++no, c, start) : extraRow(r, start);
    }));
    const dangling = day.rows.length === 0 ? el('p', { class: 'muted small ttb-empty', text: 'バンドがありません' }) : null;
    const endTime = timeMode && cur && day.rows.length ? el('p', { class: 'muted small ttb-end', text: `終了 ${cur}` }) : null;
    // 画面の下に浮かせる 元に戻す / やり直す
    const floating = el('div', { class: 'ttb-float' },
      el('button', { type: 'button', class: 'btn btn--sm', 'aria-label': '元に戻す', title: '元に戻す', disabled: !undoStack.length, onclick: () => stepHistory(undoStack, redoStack) }, icon('undo')),
      el('button', { type: 'button', class: 'btn btn--sm', 'aria-label': 'やり直す', title: 'やり直す', disabled: !redoStack.length, onclick: () => stepHistory(redoStack, undoStack) }, icon('redo')));
    return el('div', { class: 'ttb-order' }, tabs, tools, timeBar, list, dangling, endTime, floating);
  }

  /** 設定のポップアップ。離したときに保存（下書きに入るので全員・自動配置に同じ値が効く） */
  function settingsMenu() {
    const val = el('b', { class: 'mono', text: state.mix ?? 50 });
    return el('div', { class: 'ttb-settings' },
      el('p', { class: 'small' }, el('b', { text: 'おすすめ度の配分' })),
      el('div', { class: 'ttb-mix' },
        el('span', { class: 'small', text: '学年' }),
        el('input', {
          type: 'range', min: 0, max: 100, step: 5, value: state.mix ?? 50, 'aria-label': 'おすすめ度の配分',
          oninput: (e) => { val.textContent = e.target.value; },
          onchange: (e) => commit({ op: 'mix', v: parseInt(e.target.value, 10) }),
        }),
        el('span', { class: 'small', text: 'トリ・後半未経験' })),
      el('p', { class: 'small muted' }, val, '　50 = 標準'),
      el('label', { class: 'check small' }, el('input', {
        type: 'checkbox', checked: state.gap ?? true, onchange: (e) => commit({ op: 'gap', on: e.target.checked }),
      }), '2バンド以上間隔を開けて自動配置'));
  }

  function legend() {
    return el('div', { class: 'ttb-legend small' },
      el('p', {}, el('span', { class: 'ttb-m is-red', text: '名前' }), ' 隣のバンドにも出演'),
      el('p', {}, el('span', { class: 'ttb-m is-yellow', text: '名前' }), ' 1バンド挟んで出演'),
      el('p', {}, gradeBadge(Object.keys(gradeColor).map(Number).pop() ?? 4), '学年　', el('span', { class: 'ttb-g', text: '?' }), '不明（押して入力）'),
      el('p', {}, el('span', { class: 'ttb-score', text: '3.6' }), ' おすすめ度（後ろの出演順に向くほど高い）'),
      el('p', {}, icon('push_pin'), ' 自動配置で動かさない'));
  }

  function othersOn(id) {
    return people.find((p) => !p.me && p.focus === id);
  }

  function bandRow(r, no, c, start) {
    const id = r.id;
    const b = state.bands[id];
    const sc = scoreOf(b);
    const lvl = c.row[id] || 0;
    const other = othersOn(id);
    const dc = state.dayCount;
    const days = dc > 1 && !timeMode ? el('div', { class: 'ttb-days' }, range(dc).map((d) => {
      const ok = b.avail.includes(d);
      return el('button', {
        type: 'button', class: 'ttb-day' + (d === curDay ? ' is-cur' : ''), disabled: !ok || d === curDay, text: d,
        title: ok ? `${LABELS[d - 1]}へ移動` : `${LABELS[d - 1]}は出演不可`,
        onclick: (e) => {
          const rowEl = e.currentTarget.closest('.ttb-row');
          flyToTab(rowEl, d); // 再描画で行が消える前に複製を飛ばす
          if (commit({ op: 'move', id, day: d, index: state.days[d - 1].rows.length })) toast(`「${b.name}」を${LABELS[d - 1]}へ移動`);
        },
      });
    })) : null;
    return el('div', {
      class: 'ttb-row' + (lvl === 2 ? ' is-red' : lvl === 1 ? ' is-yellow' : '') + (other ? ' is-other' : ''),
      'data-id': id, style: other ? `--h:${hueOf(other.id)}` : null, title: other ? `${other.name} が操作中` : null,
    },
    handle(),
    el('span', { class: 'ttb-no', text: no }),
    el('div', { class: 'ttb-main' },
      el('div', { class: 'ttb-l1row' },
        el('button', { type: 'button', class: 'ttb-l1', 'data-pop-anchor': true, onclick: (e) => {
          if (popEl && popEl.dataset.band === id) { closePop(); return; }
          setFocus(id); openPop(e.currentTarget, bandMenu(id)); popEl.dataset.band = id;
        } },
          el('span', { class: 'ttb-name', text: b.name }),
          b.pin ? icon('push_pin') : null,
          el('span', { class: 'ttb-score', title: 'おすすめ度', text: sc.total.toFixed(1) })),
        timeMode ? timeCells(r, start) : days),
      el('div', { class: 'ttb-l2' }, memberSpans(id, c.member, c.appear))));
  }

  function extraRow(r, start) {
    const other = othersOn(r.id);
    return el('div', { class: 'ttb-row ttb-row--extra' + (other ? ' is-other' : ''), 'data-id': r.id, style: other ? `--h:${hueOf(other.id)}` : null },
      handle(),
      el('span', { class: 'ttb-no' }, icon(r.t === 'break' ? 'coffee' : 'notes')),
      el('input', {
        class: 'ttb-extra-text', value: r.x, maxlength: 50, 'aria-label': '内容', placeholder: r.t === 'break' ? '休憩' : 'テキスト',
        onfocus: () => setFocus(r.id),
        onchange: (e) => commit({ op: 'text', id: r.id, x: e.target.value.slice(0, 50) }),
      }),
      timeMode ? timeCells(r, start) : (r.min != null ? el('span', { class: 'muted small', text: `${r.min}分` }) : null),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm ttb-iconbtn', 'aria-label': '削除', onclick: () => animateRows(() => commit({ op: 'del', id: r.id })) }, icon('close')));
  }

  function timeCells(r, start) {
    return el('div', { class: 'ttb-time' },
      el('span', { class: 'ttb-start mono', text: start || '--:--' }),
      el('input', {
        type: 'number', class: 'ttb-min', min: 0, max: 600, inputmode: 'numeric', value: r.min ?? '', 'aria-label': '持ち時間（分）', placeholder: '分',
        onfocus: () => setFocus(r.id),
        onchange: (e) => {
          const val = e.target.value.trim();
          const n = val === '' ? null : Math.max(0, Math.min(600, parseInt(val, 10) || 0));
          commit({ op: 'min', id: r.id, min: n });
        },
      }));
  }

  function bandMenu(id) {
    const b = state.bands[id];
    const dc = state.dayCount;
    const sc = scoreOf(b);
    // メンバーごとの学年と、後ろの出順の履歴
    const hist = b.members.map((m) => {
      const g = gradeOf(m.k);
      const h = INFO[m.k];
      const past = h ? `　トリ${h.t}・平均逆順${h.r ?? '-'}／${h.n}回` : '　履歴なし';
      return el('li', {}, `${gradeText(g)}${m.n}`, past);
    });
    return el('div', { class: 'ttb-bandmenu' },
      el('p', { class: 'ttb-bandmenu__name' }, el('b', { text: b.name }), el('span', { class: 'muted small', text: ` ${b.songs ?? '?'}曲 · おすすめ度 ${sc.total.toFixed(1)}` })),
      dc > 1 ? el('div', { class: 'ttb-bandmenu__days' }, el('span', { class: 'small muted', text: '出演日' }), range(dc).map((d) => el('label', { class: 'check' },
        el('input', {
          type: 'checkbox', checked: b.avail.includes(d),
          onchange: (e) => {
            const days = e.target.checked ? [...b.avail, d] : b.avail.filter((x) => x !== d);
            if (!days.length) { e.target.checked = true; return; }
            commit({ op: 'avail', b: id, days });
          },
        }), LABELS[d - 1]))) : null,
      el('label', { class: 'check' }, el('input', { type: 'checkbox', checked: b.pin, onchange: (e) => commit({ op: 'pin', b: id, on: e.target.checked }) }), icon('push_pin'), '自動配置で動かさない'),
      el('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => { closePop(); commit({ op: 'hide', b: id, on: true }); } }, icon('visibility_off'), '非表示'),
      // Key（機材メモ）の手直し。名簿から取り込んだ値が入っている。画像・CSV の key 列に出る（例: 私物(シンセ), 貸出(エレピ)）
      el('label', { class: 'check', style: 'display:flex;gap:6px;align-items:center' }, icon('piano'),
        el('input', {
          type: 'text', value: b.key || '', maxlength: 60, placeholder: 'key（例: 貸出(シンセ)）', 'aria-label': 'Key',
          style: 'flex:1;min-width:0',
          onchange: (e) => commit({ op: 'key', b: id, x: e.target.value.slice(0, 60) }),
        })),
      hist.length ? el('ul', { class: 'ttb-bandmenu__hist small muted' }, hist) : null);
  }

  /** 全日程のバンドの持ち時間でいちばん多い値（全体の入力欄の初期値）。1つも入っていなければ null */
  function commonMin() {
    const count = new Map();
    state.days.forEach((d) => d.rows.forEach((r) => { if (state.bands[r.id] && r.min != null) count.set(r.min, (count.get(r.min) || 0) + 1); }));
    return [...count].sort((a, b) => b[1] - a[1])[0]?.[0] ?? null;
  }
  /** 全日程のバンドの持ち時間を同じ値にする（休憩・テキストはそのまま）。1操作なので「元に戻す」で戻る */
  function setAllMin(n) {
    const days = snapshot();
    days.forEach((d) => d.rows.forEach((r) => { if (state.bands[r.id]) r.min = n; }));
    commit({ op: 'restore', days });
  }

  function addRow(t) {
    // 一番上に足す
    animateRows(() => commit({ op: 'add', id: newId(), day: curDay, index: 0, t, x: t === 'break' ? '休憩' : '', min: t === 'break' ? 10 : null }));
    if (t === 'text') setTimeout(() => root.querySelector('.ttb-list > .ttb-row--extra .ttb-extra-text')?.focus(), 0);
  }

  /* ---------- ≡ ドラッグで並べ替え（日程タブの上で離すとその日へ移動） ---------- */
  function handle() {
    return el('button', { type: 'button', class: 'ttb-handle', 'aria-label': 'ドラッグで並べ替え', onpointerdown: startDrag }, icon('drag_indicator'));
  }
  /** 別の日へ移すとき、行の複製が移動先の日タブの方向（左右）へスライドして消える */
  function flyToTab(rowEl, day) {
    const tab = root.querySelector(`[data-day-tab="${day}"]`);
    const cur = root.querySelector('[data-day-tab].is-on');
    if (!tab || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const a = rowEl.getBoundingClientRect();
    const dir = cur && tab.getBoundingClientRect().left < cur.getBoundingClientRect().left ? -1 : 1;
    const ghost = rowEl.cloneNode(true);
    ghost.classList.remove('is-dragging');
    Object.assign(ghost.style, { position: 'fixed', left: `${a.left}px`, top: `${a.top}px`, width: `${a.width}px`, margin: 0, zIndex: 40, pointerEvents: 'none', transform: '' });
    document.body.append(ghost);
    const anim = ghost.animate([{ transform: 'none', opacity: 1 }, { transform: `translateX(${dir * a.width * 0.4}px)`, opacity: 0 }], { duration: 180, easing: 'ease-in' });
    anim.onfinish = () => {
      ghost.remove();
      root.querySelector(`[data-day-tab="${day}"]`)?.animate([{ transform: 'scale(1.1)' }, { transform: 'none' }], { duration: 150, easing: 'ease-out' });
    };
  }
  function startDrag(e) {
    if (e.button !== 0) return;
    const rowEl = e.currentTarget.closest('.ttb-row');
    const list = rowEl.parentElement;
    const rows = [...list.children];
    const from = rows.indexOf(rowEl);
    const others = rows.filter((x) => x !== rowEl);
    const mids = others.map((x) => { const b = x.getBoundingClientRect(); return b.top + window.scrollY + b.height / 2; });
    const h = rowEl.getBoundingClientRect().height;
    const id = rowEl.dataset.id;
    const startY = e.clientY + window.scrollY;
    let y = e.clientY;
    let target = from;
    let tabDay = null;
    let raf = 0;
    e.preventDefault();
    e.currentTarget.setPointerCapture(e.pointerId);
    dragging = true;
    setFocus(id);
    closePop();
    rowEl.classList.add('is-dragging');

    const update = () => {
      const py = y + window.scrollY;
      rowEl.style.transform = `translateY(${py - startY}px)`;
      target = mids.filter((m) => m < py).length;
      others.forEach((x, i) => {
        const shift = target < from && i >= target && i < from ? h : target > from && i >= from && i < target ? -h : 0;
        x.style.transform = shift ? `translateY(${shift}px)` : '';
      });
    };
    const scroll = () => {
      const edge = 60;
      const dy = y < edge ? -8 : y > window.innerHeight - edge ? 8 : 0;
      if (dy) { window.scrollBy(0, dy); update(); }
      raf = requestAnimationFrame(scroll);
    };
    raf = requestAnimationFrame(scroll);
    const move = (ev) => {
      y = ev.clientY;
      update();
      const tab = document.elementFromPoint(ev.clientX, ev.clientY)?.closest('[data-day-tab]');
      const d = tab ? Number(tab.dataset.dayTab) : null;
      const okDay = d && d !== curDay && (!state.bands[id] || state.bands[id].avail.includes(d));
      root.querySelectorAll('[data-day-tab]').forEach((t) => t.classList.toggle('is-drop', okDay && t === tab));
      tabDay = okDay ? d : null;
    };
    const up = () => {
      cancelAnimationFrame(raf);
      rowEl.removeEventListener('pointermove', move);
      rowEl.removeEventListener('pointerup', up);
      rowEl.removeEventListener('pointercancel', up);
      dragging = false;
      const dropTop = rowEl.getBoundingClientRect().top;
      setFocus(null);
      if (tabDay) {
        flyToTab(rowEl, tabDay);
        commit({ op: 'move', id, day: tabDay, index: state.days[tabDay - 1].rows.length });
        toast(`${LABELS[tabDay - 1]}へ移動`);
      } else if (target !== from) {
        commit({ op: 'move', id, day: curDay, index: target });
      } else {
        render();
      }
      if (needRender) render();
      // 離した位置から新しい位置へすっと収まる（他の行は動かしながら既に新しい位置にいる）
      const landed = [...root.querySelectorAll('.ttb-row')].find((x) => x.dataset.id === id);
      if (landed && !tabDay && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const dy = dropTop - landed.getBoundingClientRect().top;
        if (Math.abs(dy) > 1) landed.animate([{ transform: `translateY(${dy}px)`, boxShadow: 'var(--shadow)' }, { transform: 'none', boxShadow: '0 0 0 transparent' }], { duration: 150, easing: 'ease-out' });
      }
    };
    rowEl.addEventListener('pointermove', move);
    rowEl.addEventListener('pointerup', up);
    rowEl.addEventListener('pointercancel', up);
  }

  render();
  schedule(0);
})();
