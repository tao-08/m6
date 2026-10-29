/*
 * =====================================================================
 *  studio.js — スタジオ割（studio.php?live=…）の画面
 * =====================================================================
 *  画面は3つ（URL の #need / #grid / #rows で切り替え）
 *    要否 … バンドごとの「練習が必要か」。部員の回答を見る・代わりに入れる・回答リンクの共有と締切
 *    割当 … 表（時間 × 部屋）にバンドを置く。自動配置 / タップで入れ替え / 重複・移動の警告 / 画像の書き出し
 *    時間 … 部屋の数と名前・1枠の分数・行（日付 / 練習 / 自由記入 / ライブ）の編集
 *
 *  ファイルの作り:
 *    前半 StudioCore … 画面に関係ない「計算だけ」の関数（時刻の計算・警告の判定・自動配置）。
 *                      DOM を触らないので Node.js からも読み込んでテストできる（下の module.exports）
 *    後半 画面       … StudioCore を使って画面を作る。ブラウザでだけ動く
 *    このように「計算（ロジック）」と「見た目（UI）」を分けるのは、テストしやすく直しやすくするための業界の定番（関心の分離）
 *
 *  保存: 変更すると 0.8 秒後に studio_api.php へ state をまるごと送る（保存ボタンは無い）。
 *        20 秒おきに回答と他の人の変更を受け取る。
 *  画面の文字は全部 textContent で入れる（バンド名に <script> などがあっても HTML として解釈させない = XSS 対策）
 *  state の形は lib/studio.php の先頭のコメントを見ること（直すときは studio_sanitize も一緒に）
 * =====================================================================
 */

/* ============================================================
 *  StudioCore — 計算だけの部品
 * ============================================================ */
const StudioCore = (() => {
  'use strict';

  // "15:30" → 930（0時からの分）。おかしければ null
  const toMin = (hm) => {
    const m = /^(\d{1,2}):(\d{2})$/.exec(hm || '');
    return m ? Number(m[1]) * 60 + Number(m[2]) : null;
  };
  // 930 → "15:30"。24時を超えたら「25:00」のように続ける（深夜練習の表記。日付は日付の行でわかる）
  const fmt = (min) => (min == null ? '' : `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`);
  // [0, 1, …, n-1] を作る
  const range = (n) => Array.from({ length: n }, (_, i) => i);
  // セルのキー「行id:何枠目:何番目の部屋」
  const cellKey = (row, slot, room) => `${row}:${slot}:${room}`;

  /**
   * 行ごとの時刻を計算する。
   *   start がある行はその時刻から、無い行は1つ上の行の終わりから。日付の行で一旦リセット（次の行に start が無ければ時刻は空）
   * @returns 行と同じ並びの配列 [{start, end, slots: [{start, end}]（練習の行だけ）}]（時刻は 0時からの分。不明は null）
   */
  function timeline(s) {
    let cur = null;
    return s.rows.map((r) => {
      if (r.t === 'day') { cur = null; return { start: null, end: null }; }
      const start = r.start ? toMin(r.start) : cur;
      if (r.t === 'block') {
        const len = r.min ?? s.slotMin; // ?? = 左が null / undefined のときだけ右を使う（0 は 0 のまま。|| との違い）
        const slots = range(r.n).map((i) => ({
          start: start == null ? null : start + len * i,
          end: start == null ? null : start + len * (i + 1),
        }));
        cur = start == null ? null : start + len * r.n;
        return { start, end: cur, slots };
      }
      const end = start == null ? null : start + (r.min ?? 0);
      cur = end;
      return { start, end };
    });
  }

  /**
   * 練習の「枠」を上から順に並べる。1枠 = 1つの時間（そこに部屋の数だけバンドが入る）
   *   group  … 続いている練習の固まりの番号。間に日付・自由記入・ライブの行があると別の固まり（＝連続していない）
   *   passed … この枠より上にある「ライブ枠」の日（Set）。passed に 1 があれば「1日目のライブの後」の枠
   */
  function slotList(s) {
    const out = [];
    const passed = new Set();
    let group = 0;
    let prevBlock = false;
    s.rows.forEach((r) => {
      if (r.t === 'block') {
        if (!prevBlock) group++;
        range(r.n).forEach((i) => out.push({ row: r.id, s: i, group, passed: new Set(passed), idx: out.length }));
        prevBlock = true;
      } else {
        prevBlock = false;
        if (r.t === 'live') passed.add(r.d);
      }
    });
    return out;
  }

  /** state.cells から「バンド → どの枠・部屋か」を作る（Map: band_id => {slot: 枠の番号, room}） */
  function placements(s, slots) {
    const at = new Map(slots.map((x) => [`${x.row}:${x.s}`, x.idx]));
    const out = new Map();
    Object.entries(s.cells).forEach(([k, id]) => {
      const [row, slot, room] = k.split(':');
      const idx = at.get(`${row}:${slot}`);
      if (idx != null && Number(room) < s.rooms.length) out.set(id, { slot: idx, room: Number(room) });
    });
    return out;
  }

  /** ライブ枠がある日（Set）。「その日のライブより前に練習を終える」判定は、ライブ枠がある日だけ行う */
  const liveDays = (s) => new Set(s.rows.filter((r) => r.t === 'live').map((r) => r.d));
  // 置いてはいけない枠: 自分のライブより後
  const isLate = (band, slot, lives) => lives.has(band.day) && slot.passed.has(band.day);
  // 寄せたい枠: 2日目以降のバンドは「前の日のライブの後」
  const isPreferred = (band, slot, lives) => band.day > 1 && lives.has(band.day - 1) && slot.passed.has(band.day - 1);
  const wantsPreferred = (band, lives) => band.day > 1 && lives.has(band.day - 1);

  /**
   * 警告を調べる。
   * @param bands Map(band_id => {members: [{id, name}], day})
   * @returns {cells: Map(セルのキー => {clash: [名前], move: [名前], late, early}), clash, move, late}
   *   clash … 同じ時間に同じ人が2か所（赤）
   *   move  … 続けて練習する人の部屋が変わる（黄）
   *   late  … 自分のライブより後に練習（赤）
   *   early … 2日目以降のバンドが前の日のライブより前（薄い印。空きが無ければ仕方ない）
   */
  function analyze(s, bands) {
    const slots = slotList(s);
    const pos = placements(s, slots);
    const lives = liveDays(s);
    const bySlot = slots.map(() => []); // 枠ごとの [{id, room}]
    pos.forEach((p, id) => { if (bands.has(id)) bySlot[p.slot].push({ id, room: p.room }); });
    const cells = new Map();
    const flag = (slotIdx, room) => {
      const k = cellKey(slots[slotIdx].row, slots[slotIdx].s, room);
      if (!cells.has(k)) cells.set(k, { clash: [], move: [], late: false, early: false });
      return cells.get(k);
    };
    let clash = 0, move = 0, late = 0;
    bySlot.forEach((list, i) => {
      // 同じ枠の中で、同じ人が2つのバンドにいないか（Map: 人 => その人が出るバンドのリスト）
      const who = new Map();
      list.forEach((x) => bands.get(x.id).members.forEach((m) => {
        if (!who.has(m.id)) who.set(m.id, { name: m.name, at: [] });
        who.get(m.id).at.push(x);
      }));
      who.forEach((w) => {
        if (w.at.length < 2) return;
        clash++;
        w.at.forEach((x) => flag(i, x.room).clash.push(w.name));
      });
      // 1つ前の枠（同じ固まり）にもいる人の部屋が変わっていないか
      if (i > 0 && slots[i - 1].group === slots[i].group) {
        const prevRoom = new Map();
        bySlot[i - 1].forEach((x) => bands.get(x.id).members.forEach((m) => prevRoom.set(m.id, x.room)));
        list.forEach((x) => bands.get(x.id).members.forEach((m) => {
          const pr = prevRoom.get(m.id);
          if (pr == null || pr === x.room) return;
          move++;
          flag(i, x.room).move.push(m.name);
          flag(i - 1, pr).move.push(m.name);
        }));
      }
      list.forEach((x) => {
        const b = bands.get(x.id);
        if (isLate(b, slots[i], lives)) { late++; flag(i, x.room).late = true; }
        else if (wantsPreferred(b, lives) && !isPreferred(b, slots[i], lives)) flag(i, x.room).early = true;
      });
    });
    return { cells, clash, move, late };
  }

  /**
   * 自動配置。
   *   ① 枠決め（どのバンドをどの時間にするか）: 「同じ時間に同じ人がいない」を守りながら、置きにくいバンドから順に置く。
   *      これはグラフ彩色問題（隣り合う点＝メンバーが重なるバンド同士 に違う色＝時間 を塗る問題）の一種で、
   *      完璧な答えを出すのは難しい（NP 困難）。なので「置きにくい順に貪欲に置く」を、順番を少しずつランダムに変えて何百回も試し、
   *      いちばん良かったものを採用する（ランダム化貪欲法 + リスタート。実務でもよく使う割り切り）
   *   ② 部屋決め: 1つ前の枠にいた人が同じ部屋に残れるように、枠ごとに部屋を選ぶ
   * 良さの点数（小さいほど良い）: 置けなかったバンド × 10000 + 2日目なのに前の日のライブ後に置けなかった × 100 + 部屋を移る人数
   *
   * @param targets 置きたいバンドの id の配列
   * @param fixed   動かさないバンド Map(band_id => {slot, room})（「未配置だけ」のとき今の配置）
   * @param tries   試す回数
   * @param rand    乱数の関数（テストで同じ結果を出せるように差し替えられる）
   * @returns {cells, unplaced: [band_id], score}
   */
  function autoAssign(s, bands, targets, fixed = new Map(), tries = 300, rand = Math.random) {
    const slots = slotList(s);
    const R = s.rooms.length;
    const lives = liveDays(s);
    const mem = new Map([...bands].map(([id, b]) => [id, new Set(b.members.map((m) => m.id))]));
    const overlap = (a, b) => { for (const m of mem.get(a)) if (mem.get(b).has(m)) return true; return false; };
    const list = targets.filter((id) => bands.has(id) && !fixed.has(id));
    // 前もって数えておく: 置ける枠の数 / メンバーが重なるバンドの数（多いほど置きにくい）
    const allowedCount = new Map(list.map((id) => [id, slots.filter((sl) => !isLate(bands.get(id), sl, lives)).length]));
    const all = [...list, ...fixed.keys()];
    const degree = new Map(list.map((id) => [id, all.filter((o) => o !== id && overlap(id, o)).length]));

    let best = null;
    for (let t = 0; t < tries; t++) {
      const bySlot = slots.map(() => []);
      fixed.forEach((p, id) => bySlot[p.slot]?.push(id));
      // 置きにくい順（置ける枠が少ない → 重なる相手が多い）。1回目はそのまま、2回目からは少しランダムに崩す
      const jitter = new Map(list.map((id) => [id, t === 0 ? 0 : rand() * 3]));
      const order = [...list].sort((a, b) =>
        (allowedCount.get(a) - allowedCount.get(b)) * 4 - (degree.get(a) - degree.get(b)) + jitter.get(a) - jitter.get(b));
      const unplaced = [];
      let missPref = 0;
      order.forEach((id) => {
        const band = bands.get(id);
        let pick = -1, pickScore = -Infinity;
        slots.forEach((sl, i) => {
          if (bySlot[i].length >= R || isLate(band, sl, lives) || bySlot[i].some((o) => overlap(id, o))) return;
          // 点数: 寄せたい枠なら大きく加点。その中では早い枠。寄せられない2日目のバンドは、なるべく遅い枠（自分のライブに近い方）
          const pref = isPreferred(band, sl, lives);
          let sc = pref ? 1000 - i : (wantsPreferred(band, lives) ? i : -i);
          sc += t === 0 ? 0 : rand() * 2; // 同点に近いものはランダムに選んで、試すたびに違う形を探す
          if (sc > pickScore) { pickScore = sc; pick = i; }
        });
        if (pick < 0) { unplaced.push(id); return; }
        if (wantsPreferred(band, lives) && !isPreferred(band, slots[pick], lives)) missPref++;
        bySlot[pick].push(id);
      });
      const rooms = assignRooms(slots, bySlot, fixed, R, mem);
      const score = unplaced.length * 10000 + missPref * 100 + rooms.moves;
      if (!best || score < best.score) best = { score, bySlot, roomOf: rooms.roomOf, unplaced };
      if (score === 0) break; // 文句なしの答えが出たら終わり
    }

    const cells = {};
    best.bySlot.forEach((ids, i) => ids.forEach((id) => {
      cells[cellKey(slots[i].row, slots[i].s, best.roomOf.get(id))] = id;
    }));
    return { cells, unplaced: best.unplaced, score: best.score };
  }

  /**
   * 部屋決め。上の枠から順に、1つ前の枠（同じ固まり）でその人がいた部屋に、なるべく同じバンドを入れる。
   *   gain（得点）= そのバンドのメンバーのうち、1つ前の枠でその部屋にいた人数
   *   得点の高い「バンド × 部屋」の組から順に決める（貪欲法）。残りは空いている部屋に左から
   * @returns {roomOf: Map(band_id => 部屋の番号), moves: 部屋を移る人の延べ人数}
   */
  function assignRooms(slots, bySlot, fixed, R, mem) {
    const roomOf = new Map();
    let moves = 0;
    slots.forEach((sl, i) => {
      const prevRoom = new Map(); // 人 => 1つ前の枠での部屋
      if (i > 0 && slots[i - 1].group === sl.group) {
        bySlot[i - 1].forEach((id) => mem.get(id).forEach((m) => prevRoom.set(m, roomOf.get(id))));
      }
      const taken = new Set();
      const free = [];
      bySlot[i].forEach((id) => {
        const f = fixed.get(id);
        if (f && f.slot === i && f.room < R && !taken.has(f.room)) { roomOf.set(id, f.room); taken.add(f.room); } else free.push(id);
      });
      // 得点のある組を全部並べて、高い順に決める
      const pairs = [];
      free.forEach((id) => {
        const cnt = new Map();
        mem.get(id).forEach((m) => { const r = prevRoom.get(m); if (r != null) cnt.set(r, (cnt.get(r) || 0) + 1); });
        cnt.forEach((g, r) => pairs.push({ id, r, g }));
      });
      pairs.sort((a, b) => b.g - a.g || a.r - b.r);
      const done = new Set();
      pairs.forEach((p) => {
        if (done.has(p.id) || taken.has(p.r)) return;
        roomOf.set(p.id, p.r); taken.add(p.r); done.add(p.id);
      });
      free.filter((id) => !done.has(id)).forEach((id) => {
        const r = range(R).find((x) => !taken.has(x));
        roomOf.set(id, r); taken.add(r);
      });
      // 部屋を移る人を数える（点数に使う）
      bySlot[i].forEach((id) => mem.get(id).forEach((m) => {
        const pr = prevRoom.get(m);
        if (pr != null && pr !== roomOf.get(id)) moves++;
      }));
    });
    return { roomOf, moves };
  }

  return { toMin, fmt, range, cellKey, timeline, slotList, placements, analyze, autoAssign, liveDays };
})();

// Node.js（テスト）から require されたときは、計算の部品だけを渡して終わる
if (typeof module !== 'undefined' && module.exports) module.exports = StudioCore;

/* ============================================================
 *  画面
 * ============================================================ */
(() => {
  'use strict';
  if (typeof document === 'undefined') return;
  const root = document.querySelector('[data-studio]');
  const dataEl = document.getElementById('std-data');
  if (!root || !dataEl) return;
  const { toMin, fmt, range, cellKey, timeline, slotList, placements, analyze, autoAssign } = StudioCore;

  const boot = JSON.parse(dataEl.textContent);
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const DAYS = boot.days;
  const MULTI = DAYS.length > 1;
  // Map にしておくと「id からバンド」をすぐ引ける（配列の find より速い）
  const BANDS = new Map(boot.bands.map((b) => [b.id, b]));
  const TIME_RE = /^([01]?\d|2\d):[0-5]\d$/;
  const MAX_ROWS = 60;

  let state = norm(boot.state);
  let v = boot.v;
  let needs = boot.needs || {};
  let open = boot.open;
  let shareUrl = boot.shareUrl;
  let view = ['#need', '#grid', '#rows'].includes(location.hash) ? location.hash.slice(1)
    : (Object.keys(state.cells).length ? 'grid' : 'need');
  let selected = null;           // 選択中: {cell: キー} か {band: id}
  let dragLock = false;          // ドラッグ中は render() で画面を作り直さない（掴んでいる要素が消えるため）
  let renderPending = false;     // ドラッグ中に描き直しが要求された。離したあとにまとめて1回やる
  let dirty = false;             // まだ保存していない変更がある
  let saving = false;
  let status = 'saved';          // saved | saving | error
  let saveTimer = 0;
  const undoStack = [];          // 元に戻す用（変更前の state の JSON 文字列）

  function norm(s) {
    if (Array.isArray(s.cells)) s.cells = {}; // PHP の空配列は [] で来る
    return s;
  }
  const clone = (x) => JSON.parse(JSON.stringify(x));
  const needOf = (id) => (needs[id] ? needs[id].need : null); // 1 / 0 / null（未回答）
  const isNeeded = (id) => needOf(id) !== 0;                     // 未回答は「必要」として扱う（後から不要と言われる方が、部屋が足りないより困らない）
  const dayLabel = (n) => DAYS[n - 1]?.label || `${n}日目`;

  /* ---------- 小さな部品 ---------- */
  // el('div', {class: 'x', text: '文字', onclick: fn}, 子要素…) で要素を作る。text は textContent に入るので HTML として解釈されない
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
  const btn = (label, ic, onclick, cls = 'btn btn--sm', extra = {}) =>
    el('button', { type: 'button', class: cls, onclick, ...extra }, ic ? icon(ic) : null, label ? el('span', { text: label }) : null);

  let toastEl = null;
  function toast(msg, action) {
    toastEl?.remove();
    toastEl = el('div', { class: 'ttb-toast', role: 'status' }, el('span', { text: msg }),
      action ? el('button', { type: 'button', class: 'btn btn--ghost btn--sm', text: action.label, onclick: () => { action.run(); toastEl?.remove(); } }) : null);
    document.body.append(toastEl);
    const mine = toastEl;
    setTimeout(() => mine.remove(), action ? 8000 : 3500);
  }

  // サーバーに JSON を送る。fetch = ブラウザから HTTP リクエストを送る標準の関数（Promise を返すので await で待てる）
  async function api(body) {
    const res = await fetch('studio_api', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ live: boot.live, ...body }),
    });
    const json = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, json };
  }

  async function copy(text, label) {
    try {
      await navigator.clipboard.writeText(text); // クリップボードにコピー（https のページでだけ使える）
      toast(`${label}をコピーしました`);
    } catch {
      window.prompt(label, text); // 使えない環境では、選択してコピーしてもらう
    }
  }

  /* ---------- 変更と保存 ---------- */
  // state を変える処理はすべてここを通す: 元に戻す用に前の state を取っておく → 変更 → 描き直し → 保存の予約
  function change(fn, { undo = true } = {}) {
    const before = JSON.stringify(state);
    fn(state);
    if (JSON.stringify(state) === before) return;
    if (undo) { undoStack.push(before); if (undoStack.length > 50) undoStack.shift(); }
    dirty = true;
    render();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 800); // 0.8 秒まとめてから送る（連続で変えたとき毎回送らない = デバウンス）
  }
  function undo() {
    const prev = undoStack.pop();
    if (!prev) return;
    state = norm(JSON.parse(prev));
    selected = null;
    dirty = true;
    render();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 800);
  }

  async function save() {
    if (saving) { saveTimer = setTimeout(save, 500); return; }
    saving = true; status = 'saving'; renderStatus();
    const sent = JSON.stringify(state);
    try {
      const r = await api({ action: 'save', v, state });
      if (r.ok) {
        v = r.json.v;
        if (JSON.stringify(state) === sent) dirty = false; // 送っている間に変更が無ければ保存済み
        status = 'saved';
      } else if (r.status === 409 && r.json.conflict) {
        // 他の人が先に保存していた → その内容を読み込む（自分の直前の変更は捨てる。元に戻すで取り戻せる）
        undoStack.push(sent);
        state = norm(r.json.state); v = r.json.v; dirty = false; status = 'saved';
        toast('他の人の変更を読み込みました', { label: '元に戻す', run: undo });
        render();
      } else {
        status = 'error';
        toast(r.json.error || '保存できませんでした');
      }
    } catch {
      status = 'error';
    }
    saving = false;
    renderStatus();
    if (dirty && status !== 'error') { clearTimeout(saveTimer); saveTimer = setTimeout(save, 800); }
  }

  // 20 秒おきに回答と他の人の変更を受け取る（タブが裏にあるときは休む）
  async function poll() {
    if (document.hidden || saving) return;
    try {
      const r = await api({ action: 'poll', v });
      if (!r.ok) return;
      const changedNeeds = JSON.stringify(r.json.needs) !== JSON.stringify(needs);
      needs = r.json.needs || {};
      open = r.json.open;
      if (r.json.state && !dirty) { state = norm(r.json.state); v = r.json.v; selected = null; render(); return; }
      if (changedNeeds) render();
    } catch { /* 通信できないときは次の回に */ }
  }
  setInterval(poll, 20000);
  // 保存前にページを閉じようとしたら確認（beforeunload）
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '')) {
      e.preventDefault(); undo();
    }
  });

  /* ---------- 描画 ---------- */
  let statusEl = null;
  function renderStatus() {
    if (!statusEl) return;
    const [ic, label] = status === 'saving' ? ['sync', '保存中'] : status === 'error' ? ['cloud_off', '保存失敗'] : ['cloud_done', '保存済み'];
    statusEl.replaceChildren(icon(ic));
    statusEl.title = label;
    statusEl.className = `std-status is-${status}`;
  }

  function render() {
    if (dragLock) { renderPending = true; return; }
    const scrollX = root.querySelector('.std-grid__scroll')?.scrollLeft || 0; // 表の横スクロール位置を描き直しても保つ
    statusEl = el('span', { class: 'std-status', role: 'status' });
    const head = el('div', { class: 'std-head' },
      el('h1', { class: 'display display--sm std-head__title', text: boot.liveName }),
      statusEl,
      btn('', 'delete', () => document.getElementById('std-delete').requestSubmit(), 'icon-btn', { title: '削除', 'aria-label': '削除' }));
    // タブの並び順: 要否 → 時間（真ん中）→ 割当
    const tabs = el('div', { class: 'ttb-seg ttb-views', role: 'tablist' },
      [['need', 'fact_check', '要否'], ['rows', 'schedule', '時間'], ['grid', 'grid_view', '割当']].map(([k, ic, label]) =>
        el('button', { type: 'button', role: 'tab', class: view === k ? 'is-on' : '', 'aria-selected': String(view === k),
          onclick: () => { view = k; selected = null; history.replaceState(null, '', `#${k}`); render(); } }, icon(ic), el('span', { text: label }))));
    const body = view === 'need' ? renderNeed() : view === 'rows' ? renderRows() : renderGrid();
    root.replaceChildren(head, tabs, body);
    renderStatus();
    const sc = root.querySelector('.std-grid__scroll');
    if (sc) sc.scrollLeft = scrollX;
  }

  /* ---------- 要否 ---------- */
  function renderNeed() {
    const ids = [...BANDS.keys()];
    const cnt = { 1: 0, 0: 0, null: 0 };
    ids.forEach((id) => { cnt[needOf(id)]++; });

    // 回答リンクの共有と、受付・締切
    const share = el('div', { class: 'card std-share' },
      el('div', { class: 'ttb-seg', role: 'group', 'aria-label': '回答' },
        [[true, '受付中'], [false, '締切']].map(([val, label]) => el('button', {
          type: 'button', class: open === val ? 'is-on' : '', 'aria-pressed': String(open === val), text: label,
          onclick: async () => {
            const r = await api({ action: 'open', open: val });
            if (r.ok) { open = r.json.open; render(); } else toast(r.json.error || '変更できませんでした');
          },
        }))),
      btn('共有リンク', 'link', () => copy(shareUrl, '共有リンク'), 'btn btn--sm', { title: 'ログイン不要。全バンドが回答可能' }),
      btn('部員用', 'person', () => copy(boot.memberUrl, '部員用リンク'), 'btn btn--sm', { title: 'ログインした部員が自分のバンドだけ回答' }),
      btn('', 'refresh', async () => {
        if (!confirm('共有リンクを作り直します。今のリンクは使えなくなります。')) return;
        const r = await api({ action: 'token' });
        if (r.ok) { shareUrl = shareUrl.replace(/t=[0-9a-f]+/, `t=${r.json.token}`); toast('作り直しました'); } else toast(r.json.error || '作り直せませんでした');
      }, 'icon-btn', { title: '共有リンクを作り直す', 'aria-label': '共有リンクを作り直す' }));

    const sum = el('div', { class: 'std-sum' },
      el('span', { class: 'std-chip is-need' }, icon('check'), `必要 ${cnt[1]}`),
      el('span', { class: 'std-chip is-no' }, icon('close'), `不要 ${cnt[0]}`),
      el('span', { class: 'std-chip' }, icon('help'), `未回答 ${cnt.null}`));

    const list = el('div', { class: 'std-need' });
    let curDay = 0;
    ids.forEach((id) => {
      const b = BANDS.get(id);
      if (MULTI && b.day !== curDay) { curDay = b.day; list.append(el('h2', { class: 'std-need__day', text: dayLabel(b.day) })); }
      const n = needs[id];
      const set = async (val) => {
        const r = await api({ action: 'need', band: id, need: val });
        if (r.ok) { needs = r.json.needs || {}; render(); } else toast(r.json.error || '保存できませんでした');
      };
      list.append(el('div', { class: `std-need__row${n ? (n.need ? ' is-need' : ' is-no') : ''}` },
        el('div', { class: 'std-need__main' },
          el('span', { class: 'std-need__name', text: b.name }),
          el('span', { class: 'muted small std-need__members', text: b.members.map((m) => m.name).join('・') })),
        el('span', { class: 'muted small std-need__who', text: n ? `${n.by || 'リンク'} ${n.at}` : '' }),
        el('div', { class: 'ttb-seg std-need__seg', role: 'group', 'aria-label': '練習' },
          [[1, 'check', '必要'], [0, 'close', '不要'], [null, 'remove', '未回答']].map(([val, ic, label]) => el('button', {
            type: 'button', class: needOf(id) === val ? 'is-on' : '', title: label, 'aria-label': label, 'aria-pressed': String(needOf(id) === val),
            onclick: () => set(val),
          }, icon(ic))))));
    });
    return el('div', {}, share, sum, list);
  }

  /* ---------- 割当 ---------- */
  function renderGrid() {
    const tl = timeline(state);
    const slots = slotList(state);
    const pos = placements(state, slots);
    const warn = analyze(state, BANDS);
    const R = state.rooms.length;
    const unplaced = [...BANDS.keys()].filter((id) => !pos.has(id));
    const unNeeded = unplaced.filter(isNeeded);

    // 操作ボタン
    const bar = el('div', { class: 'std-bar' },
      btn('自動配置', 'auto_awesome', () => runAuto(false), 'btn btn--primary btn--sm'),
      unNeeded.length && Object.keys(state.cells).length ? btn('未配置のみ', 'playlist_add', () => runAuto(true)) : null,
      btn('', 'undo', undo, 'icon-btn', { title: '元に戻す', 'aria-label': '元に戻す', disabled: !undoStack.length }),
      btn('画像', 'image', exportImage));

    // 警告の数
    const chips = el('div', { class: 'std-sum' },
      warn.clash ? el('span', { class: 'std-chip is-clash', title: '同じ時間に同じメンバー' }, icon('error'), `重複 ${warn.clash}`) : null,
      warn.late ? el('span', { class: 'std-chip is-clash', title: '出演日のライブより後' }, icon('event_busy'), `ライブ後 ${warn.late}`) : null,
      warn.move ? el('span', { class: 'std-chip is-move', title: '連続で練習するメンバーの部屋が変更' }, icon('directions_walk'), `移動 ${warn.move}`) : null,
      unNeeded.length ? el('span', { class: 'std-chip' }, icon('pending'), `未配置 ${unNeeded.length}`) : null,
      !warn.clash && !warn.late && !warn.move && !unNeeded.length && pos.size ? el('span', { class: 'std-chip is-need' }, icon('check_circle'), 'OK') : null);

    // 表
    const thead = el('tr', {}, el('th', { class: 'std-grid__time', text: '' }), state.rooms.map((r) => el('th', { text: r })));
    const trs = [];
    state.rows.forEach((r, ri) => {
      const t = tl[ri];
      if (r.t === 'day') {
        trs.push(el('tr', { class: 'std-grid__day' }, el('td', { colspan: R + 1, text: r.x })));
      } else if (r.t === 'block') {
        range(r.n).forEach((si) => {
          const sl = t.slots[si];
          trs.push(el('tr', { class: 'std-grid__slot' },
            el('td', { class: 'std-grid__time' }, el('span', { text: fmt(sl.start) }), el('span', { class: 'muted', text: fmt(sl.end) })),
            range(R).map((room) => renderCell(cellKey(r.id, si, room), warn))));
        });
      } else {
        trs.push(el('tr', { class: `std-grid__note${r.t === 'live' ? ' is-live' : ''}` },
          el('td', { class: 'std-grid__time' }, el('span', { text: fmt(t.start) }), r.min ? el('span', { class: 'muted', text: fmt(t.end) }) : null),
          el('td', { colspan: R, text: r.x })));
      }
    });
    const table = el('div', { class: 'std-grid__scroll card' },
      el('table', { class: 'std-grid' }, el('thead', {}, thead), el('tbody', {}, trs)));
    if (!slots.length) table.append(el('p', { class: 'muted small std-grid__empty', text: '「時間」で練習の行を追加' }));

    // 未配置のバンド（必要なバンドを先に。不要のバンドは折りたたみ）
    const chip = (id) => {
      const b = BANDS.get(id);
      const on = selected?.band === id;
      return el('button', {
        type: 'button', class: `std-band${on ? ' is-sel' : ''}${needOf(id) === null ? ' is-unknown' : ''}`,
        title: b.members.map((m) => m.name).join('・'),
        onclick: () => onBand(id), 'data-drag': `band|${id}`,
      }, el('span', { text: b.name }), MULTI ? el('span', { class: 'std-band__day', text: dayLabel(b.day) }) : null);
    };
    const pool = el('div', { class: 'std-pool', 'data-drop': 'pool|' }, // 表のバンドをここへ落とすと未配置に戻る
      unNeeded.length ? el('div', { class: 'std-pool__list' }, unNeeded.map(chip)) : null,
      unplaced.length > unNeeded.length ? el('details', { class: 'std-pool__more' },
        el('summary', { class: 'muted small', text: `不要 ${unplaced.length - unNeeded.length}` }),
        el('div', { class: 'std-pool__list' }, unplaced.filter((id) => !isNeeded(id)).map(chip))) : null);

    // 選択中のバンドの情報と「外す」
    const selId = selected?.band ?? (selected?.cell ? state.cells[selected.cell] : null);
    const float = selId != null ? el('div', { class: 'std-float card' },
      el('div', { class: 'std-float__body' },
        el('strong', { text: BANDS.get(selId)?.name || '' }),
        el('span', { class: 'muted small', text: (BANDS.get(selId)?.members || []).map((m) => m.name).join('・') })),
      selected.cell ? btn('', 'remove_circle', () => { const k = selected.cell; selected = null; change((s) => { delete s.cells[k]; }); },
        'icon-btn', { title: '外す', 'aria-label': '外す' }) : null,
      btn('', 'close', () => { selected = null; render(); }, 'icon-btn', { title: '選択解除', 'aria-label': '選択解除' })) : null;

    return el('div', {}, bar, chips, table, pool, float);
  }

  function renderCell(key, warn) {
    const id = state.cells[key];
    const b = id != null ? BANDS.get(id) : null;
    const w = warn.cells.get(key);
    const cls = ['std-cell'];
    if (selected?.cell === key) cls.push('is-sel');
    if (w?.clash.length || w?.late) cls.push('is-clash');
    else if (w?.move.length) cls.push('is-move');
    if (b && needOf(id) === 0) cls.push('is-no');
    // ツールチップ（長押し・マウスを乗せると出る）に警告の理由を出す
    const tips = [];
    if (w?.clash.length) tips.push(`重複: ${[...new Set(w.clash)].join('・')}`);
    if (w?.late) tips.push('出演日のライブより後');
    if (w?.move.length) tips.push(`移動: ${[...new Set(w.move)].join('・')}`);
    if (w?.early) tips.push('前日のライブより前');
    // data-drop = ここに落とせる / data-drag = これを掴める（バンドがいるセルだけ）。詳しくは「ドラッグ移動」の節
    return el('td', { class: cls.join(' '), title: tips.join('\n') || (b ? b.members.map((m) => m.name).join('・') : null), onclick: () => onCell(key),
      'data-drop': `cell|${key}`, 'data-drag': b ? `cell|${key}` : null },
      b ? el('span', { class: 'std-cell__name', text: b.name }) : el('span', { class: 'std-cell__empty', 'aria-hidden': 'true', text: '+' }),
      b && MULTI ? el('span', { class: `std-cell__day${w?.early ? ' is-early' : ''}`, text: dayLabel(b.day) }) : null);
  }

  // セルを押したとき: 何も選んでいなければ選ぶ / 選んでいれば入れ替え・移動・配置
  function onCell(key) {
    if (selected?.cell === key) { selected = null; render(); return; }
    if (selected?.cell) {
      const from = selected.cell;
      selected = null;
      moveCell(from, key);
      return;
    }
    if (selected?.band != null) {
      const id = selected.band;
      selected = null;
      change((s) => { s.cells[key] = id; }); // 行き先にいたバンドは未配置に戻る
      return;
    }
    if (state.cells[key] != null) { selected = { cell: key }; render(); }
  }
  function onBand(id) {
    selected = selected?.band === id ? null : { band: id };
    render();
  }

  /* ---------- ドラッグ移動 ----------
   * 動かせるもの:
   *   割当の表のバンド（セル） → 別のセルへ。通った先のバンドはその場で元の位置へ滑って入れ替わる / 未配置の置き場へ（外す）
   *   未配置のバンド（チップ） → セルへ
   *   時間タブの行（左端の取っ手） → 好きな位置へ。通った行が道を空けるように滑る
   *
   * 動きの作り方は、アルバムの並び替え（app.js）と同じ考え方:
   *   ① 掴んだ「本物の要素」に transform（translate）を付けて指に付いてこさせる（複製の分身は作らない）
   *   ② 相手の要素は「本来の位置」で当たり判定する
   *      見た目の位置（滑っている途中）で判定すると、滑って避けた相手が指の下から消えて、
   *      戻る → また避ける…と行ったり来たりしてブルブル震える
   *   ③ 離したときに、離した場所から着地点へスッと滑らせる
   *   ④ 状態（state）の変更は「離したとき」に1回だけ。ドラッグ中は見た目（transform / DOM の並び）だけ動かす
   *      → ドラッグ中に render() で画面を作り直すと掴んだ要素が消えるので dragLock で止める
   *
   * 仕組み: Pointer Events（マウス・タッチ・ペンを1つにまとめた入力イベント）。
   *   HTML5 の draggable はスマホのタッチでほぼ動かないので使わない。
   *   描き直し（render）のたびに要素が作り直されるので、root に1回だけイベントを付ける（イベント委譲）。
   *   data-drag="種類|id" … 掴める要素   data-drag-now … 取っ手（タッチでも長押し不要）
   *   開始の条件: マウスは 6px 動かしたら / タッチは 0.35 秒の長押し（それまでは普通にスクロール）
   */

  // セル同士の移動・入れ替え（タップ操作の onCell とドラッグで共通）
  function moveCell(from, to) {
    if (from === to) return;
    change((s) => {
      const a = s.cells[from], b = s.cells[to];
      delete s.cells[from]; delete s.cells[to];
      if (a != null) s.cells[to] = a;
      if (b != null) s.cells[from] = b; // 行き先にバンドがいたら入れ替え
    });
  }
  // 行の並び順を、行の id の配列（画面の上から順）に合わせる。
  // 配置（state.cells）は「行のid」で持っているので、行を動かしても配置はそのまま付いてくる
  function applyRowOrder(ids) {
    change((s) => {
      const by = new Map(s.rows.map((r) => [r.id, r]));
      const next = ids.map((id) => by.get(id)).filter(Boolean);
      if (next.length === s.rows.length) s.rows = next; // 数が合わないとき（他の人の変更が混ざった等）は何もしない
    });
  }

  function initDrag() {
    const HOLD_MS = 350;   // タッチ: 長押しの時間
    const MOVE_PX = 6;     // マウス・取っ手: 動いたらドラッグとみなす距離
    const EDGE = 70;       // 画面の端からこの距離に入ると自動スクロール
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches; // 「動きを減らす」設定の人にはアニメーションを出さない
    let d = null;          // ドラッグ中の情報（無いときは null）
    let suppressClick = false; // ドラッグ直後に出る「クリック」を捨てるための旗

    // 「種類|id」を2つに分ける（id の中にも : があるので、最初の | だけで切る）
    const cut = (s) => { const i = s.indexOf('|'); return [s.slice(0, i), s.slice(i + 1)]; };
    // data 属性の文字列から、実際のバンドの id（数値かもしれない）を引き当てる
    const bandKey = (str) => [...BANDS.keys()].find((k) => String(k) === str);

    // いま付いている translate の量。getComputedStyle の transform は 'none' か matrix(...) の文字列で、
    // DOMMatrixReadOnly に渡すと m41 = 横、m42 = 縦のずれが取れる（'none' は「ずれ 0」として読まれる）
    const shift = (el) => { const m = new DOMMatrixReadOnly(getComputedStyle(el).transform); return [m.m41, m.m42]; };
    // 「本来の位置」= 今の見た目の位置から translate のずれを引いたもの（②の当たり判定とずれの計算に使う）
    const home = (el) => {
      const r = el.getBoundingClientRect();
      const [x, y] = shift(el);
      return { left: r.left - x, top: r.top - y, width: r.width, height: r.height };
    };
    const inside = (r, x, y) => x >= r.left && x < r.left + r.width && y >= r.top && y < r.top + r.height;

    // 掴んだ要素を指に付いてこさせる。「指の位置 − 掴んだ点 − 本来の位置」だけ translate でずらす
    function follow() {
      const h = home(d.box);
      const dx = d.kind === 'row' ? 0 : d.x - d.gx - h.left; // 行は縦にだけ動く
      const dy = d.y - d.gy - h.top;
      d.box.style.transform = `translate(${dx}px, ${dy}px)`;
    }

    // セル・チップ: 指の下の「落とせる場所」を探して印を付ける
    function aim() {
      let drop = null;
      const sc = root.querySelector('.std-grid__scroll');
      if (sc && inside(sc.getBoundingClientRect(), d.x, d.y)) {
        // 表の中: 本来の位置で判定する（掴んでいるセル自身は除く）
        const t = [...sc.querySelectorAll('td[data-drop]')].find((td) => td !== d.box && inside(home(td), d.x, d.y));
        if (t) drop = { el: t, kind: 'cell', id: cut(t.dataset.drop)[1] };
      }
      const pool = root.querySelector('.std-pool');
      if (!drop && d.kind === 'cell' && pool && inside(pool.getBoundingClientRect(), d.x, d.y)) drop = { el: pool, kind: 'pool', id: '' };

      if (d.mark !== (drop?.el ?? null)) {
        if (d.mark) { d.mark.classList.remove('is-drop'); d.mark.style.transform = ''; } // 前の相手は元の位置へ滑って戻る
        d.mark = drop?.el ?? null;
        if (drop) {
          if (d.kind === 'cell' && drop.kind === 'cell') {
            // 相手のバンドを、掴んでいるセルの元の位置へ滑らせる（これが「その場で入れ替わる」見た目）
            const from = home(d.box), to = home(drop.el);
            drop.el.style.transition = reduce ? 'none' : 'transform .16s ease-out';
            drop.el.style.transform = `translate(${from.left - to.left}px, ${from.top - to.top}px)`;
            d.touched.add(drop.el);
          } else {
            drop.el.classList.add('is-drop'); // チップをセルへ / セルを未配置へ: 落とし先を枠で強調
          }
        }
      }
      d.drop = drop;
    }

    // 行: 指が上下の行の真ん中を越えたら、DOM の並びを入れ替える（state はまだ触らない）。
    // 入れ替えのアニメーションは FLIP: 動かす前の位置を測る → DOM を入れ替える → 前の位置から今の位置へ滑らせる
    function reorder() {
      const list = d.box.parentElement;
      const all = () => [...list.querySelectorAll(':scope > .std-row')];
      const rows = all();
      const i = rows.indexOf(d.box);
      const mid = (el) => home(el).top + el.offsetHeight / 2;
      let change = null;
      if (rows[i - 1] && d.y < mid(rows[i - 1])) change = () => list.insertBefore(d.box, rows[i - 1]);
      else if (rows[i + 1] && d.y > mid(rows[i + 1])) change = () => list.insertBefore(d.box, rows[i + 1].nextElementSibling);
      if (!change) return;
      const before = new Map(rows.map((el) => [el, el.getBoundingClientRect().top]));
      change();
      if (reduce) return;
      all().forEach((el) => {
        if (el === d.box) return; // 掴んでいる行は指に付いているので滑らせない
        el.getAnimations().forEach((a) => a.cancel()); // 滑っている途中なら止めてから測る
        const dy = before.get(el) - el.getBoundingClientRect().top;
        if (Math.abs(dy) < 1) return;
        el.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 200, easing: 'ease-out' });
      });
    }

    function step() {
      if (d.kind === 'row') reorder(); else aim();
      follow(); // 入れ替えで本来の位置が変わるので、最後にずらす量を計算し直す
    }

    function start() {
      if (!d || d.active) return;
      d.active = true;
      clearTimeout(d.timer);
      selected = null;
      dragLock = true;
      d.box.getAnimations().forEach((a) => a.cancel());
      const r = d.box.getBoundingClientRect();
      d.gx = d.x0 - r.left; d.gy = d.y0 - r.top; // 掴んだ点（要素の左上からのずれ）。これを保ったまま付いてこさせる
      d.box.classList.add('is-dragging');
      document.body.classList.add('std-dragging');
      navigator.vibrate?.(15); // 掴んだことを振動で知らせる（対応端末のみ）
      step();
      tick();
    }

    // 画面の端に寄せている間、ページ（縦）と表（横）を自動でスクロールする。
    // requestAnimationFrame = 次の画面更新の直前に呼ばれる仕組み（滑らかな動きの定番）
    function tick() {
      if (!d?.active) return;
      const speed = 14;
      if (d.y < EDGE) window.scrollBy(0, -speed);
      else if (d.y > window.innerHeight - EDGE) window.scrollBy(0, speed);
      const sc = d.kind === 'row' ? null : root.querySelector('.std-grid__scroll');
      if (sc) {
        const r = sc.getBoundingClientRect();
        if (d.y >= r.top && d.y <= r.bottom) {
          if (d.x < r.left + EDGE) sc.scrollLeft -= speed;
          else if (d.x > r.right - EDGE) sc.scrollLeft += speed;
        }
      }
      step(); // スクロールで指の下の要素が変わるので毎回やり直す
      d.raf = requestAnimationFrame(tick);
    }

    // 後始末（transform・強調・旗を全部元に戻す）
    function cleanup() {
      if (!d) return;
      clearTimeout(d.timer);
      cancelAnimationFrame(d.raf);
      d.mark?.classList.remove('is-drop');
      d.touched.forEach((el) => { el.style.transform = ''; setTimeout(() => { el.style.transition = ''; }, 250); }); // 避けていた相手は滑って戻る
      d.box.style.transform = '';
      d.box.classList.remove('is-dragging');
      document.body.classList.remove('std-dragging');
      d = null;
      dragLock = false;
    }

    // 着地: 離した場所（from = 離した瞬間の見た目の矩形）から、本来の場所へ滑らせる
    function settle(sel, from) {
      const el = root.querySelector(sel);
      if (!el || !from || reduce) return;
      const r = el.getBoundingClientRect();
      const dx = from.left - r.left, dy = from.top - r.top;
      if (Math.abs(dx) + Math.abs(dy) < 1) return;
      el.classList.add('is-settling'); // 滑っている間だけ、ほかの要素より手前に出す
      el.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: 160, easing: 'ease-out' })
        .onfinish = () => el.classList.remove('is-settling');
    }

    root.addEventListener('pointerdown', (e) => {
      if (d || e.button > 0) return; // すでにドラッグ中 / 右・中ボタンは無視（タッチと左クリックは button が 0）
      const src = e.target.closest('[data-drag]');
      if (!src || !root.contains(src)) return;
      const [kind, id] = cut(src.dataset.drag);
      d = { kind, id, src, box: kind === 'row' ? src.closest('.std-row') : src, pid: e.pointerId, x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY,
        active: false, touch: e.pointerType !== 'mouse', now: src.hasAttribute('data-drag-now'), timer: 0, raf: 0, mark: null, drop: null, touched: new Set() };
      if (d.touch && !d.now) d.timer = setTimeout(start, HOLD_MS); // タッチは長押しで開始
    });

    // pointermove / up は指やマウスが要素の外に出ても受け取りたいので window に付ける
    window.addEventListener('pointermove', (e) => {
      if (!d || e.pointerId !== d.pid) return;
      d.x = e.clientX; d.y = e.clientY;
      if (!d.active) {
        const dist = Math.hypot(d.x - d.x0, d.y - d.y0);
        if (d.touch && !d.now) { if (dist > 10) cleanup(); return; } // 長押しの前に動いた = ただのスクロール
        if (dist < MOVE_PX) return;
        start();
        return;
      }
      step();
    });
    window.addEventListener('pointerup', (e) => {
      if (!d || e.pointerId !== d.pid) return;
      const was = d.active, { kind, id, box } = d, drop = d.drop;
      const from = was ? box.getBoundingClientRect() : null; // 離した瞬間の見た目の位置（着地アニメーションの出発点）
      // 行は DOM の並びがそのまま新しい並び順。cleanup の前に読む
      const order = was && kind === 'row' ? [...box.parentElement.querySelectorAll(':scope > .std-row')].map((r) => r.dataset.rowId) : null;
      const back = was ? `[data-drag="${box.dataset.drag ?? ''}"]` : ''; // 落とせない場所で離したときに戻る先
      cleanup();
      if (!was) return;                       // 動かさずに離した = ふつうのタップ。何もしない（click が処理する）
      suppressClick = true;                   // ドラッグの直後に出る click を捨てる
      setTimeout(() => { suppressClick = false; }, 0);

      let landing = back;                     // 着地する要素（落とせない場所で離したら元の場所へ戻る）
      if (kind === 'row') {
        applyRowOrder(order);
        landing = `.std-row[data-row-id="${id}"]`;
      } else if (drop?.kind === 'pool') {
        change((s) => { delete s.cells[id]; });
        landing = null;
      } else if (drop?.kind === 'cell') {
        if (kind === 'cell') { moveCell(id, drop.id); landing = `td[data-drop="cell|${drop.id}"]`; }
        else { const bid = bandKey(id); if (bid !== undefined) change((s) => { s.cells[drop.id] = bid; }); landing = null; } // チップ: 行き先にいたバンドは未配置に戻る
      }
      if (renderPending) { renderPending = false; render(); } // ドラッグ中に止めておいた描き直し（他の人の変更の受け取り等）
      if (landing) settle(landing, from);
    });
    window.addEventListener('pointercancel', (e) => {
      if (!d || e.pointerId !== d.pid) return;
      const was = d.active;
      cleanup();
      if (was) render(); // 行の DOM の並びを入れ替えていたら、state の並びに描き直して戻す
    });

    // ① ドラッグ直後の click を止める（止めないと、離した場所のセルが「選択」されてしまう）。capture = 子より先に受け取る
    root.addEventListener('click', (e) => { if (suppressClick) { e.stopPropagation(); e.preventDefault(); } }, true);
    // ② ドラッグ中はタッチでページが動かないようにする。passive: false にしないと preventDefault できない
    root.addEventListener('touchmove', (e) => { if (d?.active) e.preventDefault(); }, { passive: false });
    // ③ 長押しで出る「コピー・共有」のメニューを、掴める要素の上では出さない
    root.addEventListener('contextmenu', (e) => { if (e.target.closest('[data-drag]')) e.preventDefault(); });
  }

  function runAuto(keep) {
    if (!keep && Object.keys(state.cells).length && !confirm('現在の割り当てを上書きします')) return;
    const slots = slotList(state);
    const fixed = keep ? placements(state, slots) : new Map();
    const targets = [...BANDS.keys()].filter(isNeeded);
    const res = autoAssign(state, BANDS, targets, fixed);
    selected = null;
    change((s) => { s.cells = res.cells; });
    const warn = analyze(state, BANDS);
    const msgs = [];
    if (res.unplaced.length) msgs.push(`未配置 ${res.unplaced.length}（枠が不足）`);
    if (warn.move) msgs.push(`移動 ${warn.move}`);
    toast(msgs.length ? msgs.join(' / ') : '配置しました', { label: '元に戻す', run: undo });
  }

  /* ---------- 時間 ---------- */
  function renderRows() {
    const tl = timeline(state);
    // 入力欄の部品。change イベント（入力を確定したとき）で保存する（input イベントだと1文字ごとに描き直されて入力しにくい）
    const input = (value, onset, attrs = {}) => el('input', { class: 'std-in', value: value ?? '', autocomplete: 'off', ...attrs,
      onchange: (e) => onset(e.target.value.trim()) });
    const num = (value, onset, min, max, attrs = {}) => input(value, (x) => {
      if (x === '') { onset(null); return; }
      const n = Number(x);
      if (Number.isInteger(n) && n >= min && n <= max) onset(n); else { toast(`${min}〜${max} で入力`); render(); }
    }, { type: 'number', inputmode: 'numeric', min, max, ...attrs });
    const time = (value, onset) => input(value, (x) => {
      const z = x.replace(/[：．.]/g, ':').replace(/^(\d{1,2})(\d{2})$/, '$1:$2'); // 「1530」「15.30」も 15:30 にする
      if (z === '') { onset(null); return; }
      if (TIME_RE.test(z)) onset(z.padStart(5, '0')); else { toast('時刻は 15:30 の形で入力'); render(); }
    }, { inputmode: 'numeric', placeholder: '続き', maxlength: 5, class: 'std-in std-in--time', 'aria-label': '開始' });
    const setRow = (id, patch) => change((s) => { Object.assign(s.rows.find((r) => r.id === id), patch); });

    // ---- 部屋・枠の分数・見出し ----
    const R = state.rooms.length;
    const settings = el('div', { class: 'card std-set' },
      // 全消去: 設定カードの右上。枠だけ赤（btn--danger）。行が無いときは押せない（disabled）
      btn('', 'delete_sweep', clearRows, 'btn btn--sm btn--danger std-set__clear',
        { title: '全消去', 'aria-label': '全消去', disabled: !state.rows.length }),
      el('label', { class: 'field' }, el('span', { text: '部屋' }),
        el('select', { class: 'std-in', onchange: (e) => setRooms(Number(e.target.value)) },
          range(10).map((i) => el('option', { value: i + 1, selected: i + 1 === R, text: `${i + 1}部屋` })))),
      el('label', { class: 'field' }, el('span', { text: '1枠（分）' }),
        num(state.slotMin, (n) => n != null && change((s) => { s.slotMin = n; }), 5, 600)),
      el('label', { class: 'field std-set__title' }, el('span', { text: '画像の見出し' }),
        input(state.title, (x) => change((s) => { s.title = x.slice(0, 60); }), { maxlength: 60, placeholder: boot.liveName })),
      el('div', { class: 'std-set__rooms' }, state.rooms.map((name, i) =>
        input(name, (x) => change((s) => { s.rooms[i] = x.slice(0, 12); }), { maxlength: 12, 'aria-label': `部屋${i + 1}`, class: 'std-in std-in--room' }))));

    // ---- 行 ----
    const TYPES = { day: ['calendar_today', '日付'], block: ['meeting_room', '練習'], text: ['edit_note', '自由記入'], live: ['mic', 'ライブ'] };
    const list = el('div', { class: 'std-rows' });
    state.rows.forEach((r, i) => {
      const t = tl[i];
      const fields = [];
      if (r.t === 'day') {
        fields.push(input(r.x, (x) => setRow(r.id, { x: x.slice(0, 30) }), { maxlength: 30, placeholder: '8月6日', 'aria-label': '日付' }));
      } else {
        fields.push(time(r.start, (x) => setRow(r.id, { start: x })));
        if (r.t === 'block') {
          fields.push(el('label', { class: 'std-row__unit' }, num(r.n, (n) => setBlockN(r.id, n ?? 1), 1, 12, { 'aria-label': '枠数' }), '枠'));
          fields.push(el('label', { class: 'std-row__unit' }, num(r.min, (n) => setRow(r.id, { min: n }), 5, 600, { placeholder: String(state.slotMin), 'aria-label': '1枠の分数' }), '分'));
        } else {
          fields.push(input(r.x, (x) => setRow(r.id, { x: x.slice(0, 40) }), { maxlength: 40, placeholder: r.t === 'live' ? 'Live 1日目' : '昼食', 'aria-label': '内容', class: 'std-in std-in--grow' }));
          if (r.t === 'live' && MULTI) {
            fields.push(el('select', { class: 'std-in', 'aria-label': '何日目', onchange: (e) => setRow(r.id, { d: Number(e.target.value) }) },
              DAYS.map((d) => el('option', { value: d.n, selected: d.n === r.d, text: d.label }))));
          }
          fields.push(el('label', { class: 'std-row__unit' }, num(r.min, (n) => setRow(r.id, { min: n }), 0, 1440, { 'aria-label': '分数' }), '分'));
        }
      }
      list.append(el('div', { class: `std-row is-${r.t}`, 'data-row-id': r.id },
        // 左端の取っ手: ここを掴んで上下にドラッグすると行を並べ替えられる（data-drag-now = タッチでも長押し不要）
        el('span', { class: 'std-row__grip', 'data-drag': `row|${r.id}`, 'data-drag-now': true, title: '移動', 'aria-label': '移動' }, icon('drag_indicator')),
        el('span', { class: 'std-row__type', title: TYPES[r.t][1] }, icon(TYPES[r.t][0])),
        el('div', { class: 'std-row__fields' }, fields,
          r.t !== 'day' && t.start != null ? el('span', { class: 'muted small std-row__range', text: `${fmt(t.start)}〜${t.end !== t.start ? fmt(t.end) : ''}` }) : null),
        el('div', { class: 'std-row__ops' },
          btn('', 'arrow_upward', () => moveRow(i, -1), 'icon-btn', { 'aria-label': '上へ', disabled: i === 0 }),
          btn('', 'arrow_downward', () => moveRow(i, 1), 'icon-btn', { 'aria-label': '下へ', disabled: i === state.rows.length - 1 }),
          btn('', 'delete', () => delRow(r.id), 'icon-btn', { 'aria-label': '削除' }))));
    });
    const add = el('div', { class: 'std-add' }, Object.entries(TYPES).map(([t, [ic, label]]) => btn(label, ic, () => addRow(t))));
    return el('div', {}, settings, list, add);
  }

  function newId() {
    const used = new Set(state.rows.map((r) => r.id));
    let i = state.rows.length + 1;
    while (used.has(`r${i}`)) i++;
    return `r${i}`;
  }
  function addRow(t) {
    if (state.rows.length >= MAX_ROWS) { toast('行が多すぎます'); return; }
    const row = t === 'day' ? { id: newId(), t, x: '' }
      : t === 'block' ? { id: newId(), t, start: null, n: 2, min: null }
        : t === 'live' ? { id: newId(), t, x: 'Live', d: 1, start: null, min: 540 }
          : { id: newId(), t, x: '', start: null, min: 60 };
    change((s) => { s.rows.push(row); });
  }
  function moveRow(i, dir) {
    change((s) => { const [r] = s.rows.splice(i, 1); s.rows.splice(i + dir, 0, r); });
  }
  // 行に置いてあるバンドの数（消す・枠を減らす前の確認に使う）
  const countIn = (id, fromSlot = 0) => Object.keys(state.cells).filter((k) => { const [r, sl] = k.split(':'); return r === id && Number(sl) >= fromSlot; }).length;
  function delRow(id) {
    const n = countIn(id);
    change((s) => {
      s.rows = s.rows.filter((r) => r.id !== id);
      Object.keys(s.cells).forEach((k) => { if (k.split(':')[0] === id) delete s.cells[k]; });
    });
    if (n) toast(`${n}バンドを未配置に変更`, { label: '元に戻す', run: undo });
  }
  // 全行を削除（部屋数・部屋名・1枠の分数・見出しは残す）。
  // 行が消えると、その行に置いていたバンドの配置（state.cells）も意味を失うので、セルも空にする。
  // change() が変更前の state を undoStack に積むので、トーストの「元に戻す」で全部復元できる。
  function clearRows() {
    if (!state.rows.length) return;
    const n = Object.keys(state.cells).length; // 配置済みバンド数（トーストの表示用）
    change((s) => { s.rows = []; s.cells = {}; });
    toast(n ? `全行を削除・${n}バンドを未配置に変更` : '全行を削除', { label: '元に戻す', run: undo });
  }
  function setBlockN(id, n) {
    const lost = countIn(id, n);
    change((s) => {
      s.rows.find((r) => r.id === id).n = n;
      Object.keys(s.cells).forEach((k) => { const [r, sl] = k.split(':'); if (r === id && Number(sl) >= n) delete s.cells[k]; });
    });
    if (lost) toast(`${lost}バンドを未配置に変更`, { label: '元に戻す', run: undo });
  }
  function setRooms(n) {
    const lost = Object.keys(state.cells).filter((k) => Number(k.split(':')[2]) >= n).length;
    change((s) => {
      while (s.rooms.length < n) s.rooms.push(String.fromCharCode(65 + s.rooms.length)); // 65 = 'A'。増えた部屋は A, B, C… の続き
      s.rooms.length = n; // 配列の length を小さくすると後ろが消える
      Object.keys(s.cells).forEach((k) => { if (Number(k.split(':')[2]) >= n) delete s.cells[k]; });
    });
    if (lost) toast(`${lost}バンドを未配置に変更`, { label: '元に戻す', run: undo });
  }

  /* ---------- 画像（PNG）の書き出し ----------
   * <canvas>（ブラウザの中のお絵かき用の紙）に表を描いて PNG にする。タイムテーブル作成（tt_builder.js）と同じ仕組み。
   * サーバー（PHP）で描かないのは、レンタルサーバーに日本語フォントが無いことが多いため。
   * 添付の画像の構成（時間 × 部屋）をもとに、見やすさのために:
   *   見出し行は濃い色 / 日付の行は帯 / ライブ・自由記入の行は色付きで部屋をまたいで1マス / 練習の行は1行おきに薄い色
   *   2日以上のライブは、バンド名の下に出演日を小さく出す
   */
  async function exportImage() {
    const tl = timeline(state);
    const R = state.rooms.length;
    const SCALE = 2;                         // 2倍の細かさで描く（スマホで拡大してもぼやけない）
    const PAD = 24;
    const TIME_W = 150;                      // 時間の列の幅
    const ROOM_W = Math.max(170, Math.floor((900 - TIME_W) / R)); // 部屋の列の幅（部屋が少なくても横幅 900 は確保）
    const W = PAD * 2 + TIME_W + ROOM_W * R;
    const H_TITLE = 48, H_HEAD = 40, H_DAY = 36, H_NOTE = 40, H_SLOT = MULTI ? 66 : 56;
    const C = { line: '#cfcfcf', head: '#2b2b2b', headText: '#ffffff', day: '#f3dede', dayText: '#8a1c1c',
      live: '#e3ebfb', liveText: '#1d3f8f', note: '#f1f1f1', stripe: '#fafafa', text: '#111111', muted: '#777777' };
    const FONT = '"Noto Sans JP", "std-noto", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif';
    const title = state.title || boot.liveName;

    // 描く行を先に作る（高さを決めるため）
    const rows = [];
    state.rows.forEach((r, ri) => {
      const t = tl[ri];
      if (r.t === 'day') rows.push({ kind: 'day', h: H_DAY, text: r.x });
      else if (r.t === 'block') range(r.n).forEach((si) => rows.push({ kind: 'slot', h: H_SLOT, time: span(t.slots[si].start, t.slots[si].end), cells: range(R).map((room) => state.cells[cellKey(r.id, si, room)]) }));
      else rows.push({ kind: r.t === 'live' ? 'live' : 'note', h: H_NOTE, time: span(t.start, r.min ? t.end : null), text: r.x });
    });
    function span(a, b) { return a == null ? '' : b == null ? fmt(a) : `${fmt(a)}〜${fmt(b)}`; }

    // フォントを読み込んでから描く（読み込み前に描くと別のフォントになる）
    try {
      if (![...document.fonts].some((x) => x.family.replace(/"/g, '') === 'std-noto')) {
        const f = new FontFace('std-noto', 'url(assets/fonts/NotoSansJP-Regular.ttf)');
        document.fonts.add(await f.load());
      }
      const used = [title, ...state.rooms, ...rows.flatMap((r) => [r.text, r.time, ...(r.cells || []).map((id) => BANDS.get(id)?.name)]), ...DAYS.map((d) => d.label)].join('');
      await Promise.all(['400', '700'].map((w) => document.fonts.load(`${w} 16px ${FONT}`, used)));
    } catch { /* 読み込めなくても端末のフォントで描ける */ }

    const top = PAD + H_TITLE;
    const H = top + H_HEAD + rows.reduce((a, r) => a + r.h, 0) + PAD;
    const canvas = document.createElement('canvas');
    canvas.width = W * SCALE;
    canvas.height = H * SCALE;
    const g = canvas.getContext('2d');
    g.scale(SCALE, SCALE);

    // 文字を x1〜x2 の真ん中に描く。入らなければ小さくする（長いバンド名の対策）
    const text = (s, x1, x2, cy, { bold = false, size = 16, color = C.text } = {}) => {
      if (!s) return;
      let px = size;
      const set = () => { g.font = `${bold ? '700' : '400'} ${px}px ${FONT}`; };
      set();
      while (px > 9 && g.measureText(s).width > x2 - x1 - 12) { px--; set(); }
      g.fillStyle = color; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.fillText(s, (x1 + x2) / 2, cy);
    };
    // 1行に入らない文字を2行に分ける（入るなら1行のまま）。空白があれば真ん中に近い空白で、無ければ（日本語）文字数の半分で分ける
    const wrap = (s, maxW, size) => {
      g.font = `700 ${size}px ${FONT}`;
      if (g.measureText(s).width <= maxW) return [s];
      const chars = [...s]; // [...文字列] = 1文字ずつの配列（絵文字などの2つで1文字の文字も壊さない）
      const spaces = chars.map((c, i) => (c === ' ' ? i : -1)).filter((i) => i > 0);
      const half = chars.length / 2;
      const at = spaces.length ? spaces.reduce((a, b) => (Math.abs(b - half) < Math.abs(a - half) ? b : a)) : Math.ceil(half);
      return [chars.slice(0, at).join('').trim(), chars.slice(at).join('').trim()];
    };
    const xRoom = (i) => PAD + TIME_W + ROOM_W * i;
    const right = PAD + TIME_W + ROOM_W * R;

    g.fillStyle = '#fff';
    g.fillRect(0, 0, W, H);
    text(title, PAD, right, PAD + H_TITLE / 2 - 4, { bold: true, size: 24 });

    // 見出し行
    g.fillStyle = C.head;
    g.fillRect(PAD, top, right - PAD, H_HEAD);
    text('Studio', PAD, PAD + TIME_W, top + H_HEAD / 2, { bold: true, color: C.headText });
    state.rooms.forEach((name, i) => text(name, xRoom(i), xRoom(i + 1), top + H_HEAD / 2, { bold: true, size: 18, color: C.headText }));

    // 本文
    let y = top + H_HEAD;
    let stripe = false;
    g.strokeStyle = C.line;
    g.lineWidth = 1;
    rows.forEach((r) => {
      const mid = y + r.h / 2;
      if (r.kind === 'day') {
        g.fillStyle = C.day; g.fillRect(PAD, y, right - PAD, r.h);
        text(r.text, PAD, right, mid, { bold: true, size: 17, color: C.dayText });
        stripe = false;
      } else if (r.kind === 'slot') {
        if (stripe) { g.fillStyle = C.stripe; g.fillRect(PAD, y, right - PAD, r.h); }
        stripe = !stripe;
        text(r.time, PAD, PAD + TIME_W, mid, { bold: true, size: 15 });
        r.cells.forEach((id, i) => {
          const b = id != null ? BANDS.get(id) : null;
          if (!b) return;
          const nameY = MULTI ? mid - 8 : mid;
          // 長いバンド名は、小さくしすぎる前に2行に折り返す
          const lines = wrap(b.name, ROOM_W - 12, 17);
          if (lines.length === 2) {
            text(lines[0], xRoom(i), xRoom(i + 1), nameY - 9, { bold: true, size: 15 });
            text(lines[1], xRoom(i), xRoom(i + 1), nameY + 9, { bold: true, size: 15 });
          } else {
            text(b.name, xRoom(i), xRoom(i + 1), nameY, { bold: true, size: 17 });
          }
          if (MULTI) text(dayLabel(b.day), xRoom(i), xRoom(i + 1), mid + (lines.length === 2 ? 20 : 15), { size: 11, color: C.muted });
        });
        for (let i = 0; i <= R; i++) { g.beginPath(); g.moveTo(xRoom(i), y); g.lineTo(xRoom(i), y + r.h); g.stroke(); }
      } else {
        const live = r.kind === 'live';
        g.fillStyle = live ? C.live : C.note; g.fillRect(PAD, y, right - PAD, r.h);
        text(r.time, PAD, PAD + TIME_W, mid, { bold: true, size: 15, color: live ? C.liveText : C.text });
        text(r.text, PAD + TIME_W, right, mid, { bold: true, size: 17, color: live ? C.liveText : C.text });
        g.beginPath(); g.moveTo(xRoom(0), y); g.lineTo(xRoom(0), y + r.h); g.stroke();
        stripe = false;
      }
      g.beginPath(); g.moveTo(PAD, y + r.h); g.lineTo(right, y + r.h); g.stroke(); // 行の下の横線
      y += r.h;
    });
    g.strokeStyle = '#555';
    g.strokeRect(PAD, top, right - PAD, y - top); // 外枠

    canvas.toBlob((blob) => {
      if (!blob) { toast('画像を作れませんでした'); return; }
      const url = URL.createObjectURL(blob);
      const a = el('a', { href: url, download: `${title}.png` });
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }, 'image/png');
  }

  initDrag(); // ドラッグのイベントは root に1回だけ付ける（render のたびに付け直さない）
  render();
})();
