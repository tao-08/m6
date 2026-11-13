/* =====================================================================
 *  assets/block.js — ブロックパズル（block.php）
 * =====================================================================
 *  上半分「ゲームのルール」は画面（DOM）を一切使わない。node でもそのまま動くので、テストしやすい。
 *    盤面 10 × 40（下から 20 行が見える範囲、上の 20 行は隠れバッファ）。
 *    座標は「x は右が正、y は上が正」（y = 0 が一番下の行）。壁蹴りの表もこの向きのまま使える。
 *    回転は SRS（スーパーローテーションシステム）、ランダムは 7 種 1 巡（7-bag）、
 *    接地猶予 0.5 秒・リセット 15 回まで、T スピン判定、B2B、REN、パーフェクトクリアは公式ガイドライン準拠。
 *  下半分「画面」は canvas の描画と、スマホのジェスチャー・PC のキーボード操作。
 *  点数は ゲームオーバーで api_block_score.php へ送り、ランキングを受け取る。
 *  ユーザーに見える文字に別名のゲーム名は使わない（「ブロックパズル」で統一）。
 * ===================================================================== */
(function (root) {
  'use strict';

  /* ===================================================================
   *  1. ゲームのルール（DOM なし）
   * =================================================================== */
  const COLS = 10;
  const ROWS = 40;          // 盤面の高さ（隠れバッファ込み）
  const VISIBLE = 20;       // 見える行数（y = 0〜19）
  const LOCK_DELAY = 0.5;   // 接地猶予（秒）
  const LOCK_RESETS = 15;   // 接地猶予をリセットできる回数（1 ミノにつき）
  const SOFT_DROP_FACTOR = 20; // ソフトドロップは通常の 20 倍速
  const TYPES = ['I', 'O', 'T', 'S', 'Z', 'J', 'L'];

  // 形。4×4（I・O）または 3×3 の枠の中の、上から下・左から右のマス（1 = ミノ）
  const BASE = {
    I: [[0, 0, 0, 0], [1, 1, 1, 1], [0, 0, 0, 0], [0, 0, 0, 0]],
    O: [[0, 1, 1, 0], [0, 1, 1, 0], [0, 0, 0, 0], [0, 0, 0, 0]],
    T: [[0, 1, 0], [1, 1, 1], [0, 0, 0]],
    S: [[0, 1, 1], [1, 1, 0], [0, 0, 0]],
    Z: [[1, 1, 0], [0, 1, 1], [0, 0, 0]],
    J: [[1, 0, 0], [1, 1, 1], [0, 0, 0]],
    L: [[0, 0, 1], [1, 1, 1], [0, 0, 0]],
  };

  /**
   * 回転状態ごとのマス: SHAPES[種類][回転 0,1,2,3] = [[列, 行], …]（枠の左上が [0,0]、行は下向きに増える）
   *   回転状態 0 = 出現時、1 = R（右に 1 回）、2 = 2 回、3 = L（左に 1 回）
   *   枠の中心まわりに時計回りに回す: 枠の大きさ N で (列 c, 行 r) → (列 N-1-r, 行 c)
   *   これで I は 4×4 の中心、他は 3×3 の中心まわりの SRS の回転になる。O は回しても同じ。
   */
  function buildShapes() {
    const out = {};
    for (const t of TYPES) {
      let cells = [];
      BASE[t].forEach((row, r) => row.forEach((v, c) => { if (v) cells.push([c, r]); }));
      const N = BASE[t].length;
      out[t] = [cells];
      for (let k = 1; k < 4; k++) {
        cells = t === 'O' ? cells : cells.map(([c, r]) => [N - 1 - r, c]);
        out[t].push(cells);
      }
    }
    return out;
  }
  const SHAPES = buildShapes();

  // 壁蹴り（SRS）。キーは「今の回転状態>回転後の状態」。[dx, dy] の順に試す（x は右が正、y は上が正）
  const KICKS_JLSTZ = {
    '0>1': [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
    '1>0': [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
    '1>2': [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
    '2>1': [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
    '2>3': [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
    '3>2': [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
    '3>0': [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
    '0>3': [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
  };
  const KICKS_I = {
    '0>1': [[0, 0], [-2, 0], [1, 0], [-2, -1], [1, 2]],
    '1>0': [[0, 0], [2, 0], [-1, 0], [2, 1], [-1, -2]],
    '1>2': [[0, 0], [-1, 0], [2, 0], [-1, 2], [2, -1]],
    '2>1': [[0, 0], [1, 0], [-2, 0], [1, -2], [-2, 1]],
    '2>3': [[0, 0], [2, 0], [-1, 0], [2, 1], [-1, -2]],
    '3>2': [[0, 0], [-2, 0], [1, 0], [-2, -1], [1, 2]],
    '3>0': [[0, 0], [1, 0], [-2, 0], [1, -2], [-2, 1]],
    '0>3': [[0, 0], [-1, 0], [2, 0], [-1, 2], [2, -1]],
  };

  /** 1 行落ちるのにかかる秒数。level 1 で 1 秒、level 20 でほぼ 0 */
  function gravityInterval(level) {
    return Math.pow(0.8 - (level - 1) * 0.007, level - 1);
  }

  /** ライン数からレベル（10 ライン毎に +1、上限 20）。サーバー（lib/block.php）と同じ式 */
  function levelForLines(lines) {
    return Math.min(20, 1 + Math.floor(lines / 10));
  }

  /** ライン消去の基本点（× レベルする前）。tspin = 'none' | 'mini' | 'full' */
  function clearPoints(lines, tspin) {
    if (tspin === 'full') return [400, 800, 1200, 1600][Math.min(lines, 3)];
    if (tspin === 'mini') return [100, 200, 400][Math.min(lines, 2)];
    return [0, 100, 300, 500, 800][lines];
  }

  /** パーフェクトクリアのボーナス（× レベルする前）。b2b4 = B2B の 4 ライン */
  function perfectPoints(lines, b2b4) {
    if (lines === 4 && b2b4) return 3200;
    return [0, 800, 1200, 1800, 2000][lines];
  }

  /** ライン消去の表示用の文字（盤面の上に出す）。先頭が主役 */
  function describeClear(info) {
    const names = ['', 'シングル', 'ダブル', 'トリプル', 'テ◯リス'];
    const out = [];
    let head = info.tspin === 'full' ? 'Tスピン' : info.tspin === 'mini' ? 'Tスピン Mini' : '';
    if (info.lines > 0) head = head ? head + ' ' + names[info.lines] : names[info.lines];
    if (head) out.push(head);
    if (info.b2b) out.push('B2B');
    if (info.combo >= 1) out.push('REN ' + info.combo);
    if (info.perfect) out.push('パーフェクトクリア');
    return out;
  }

  class Game {
    /** rng = 0 以上 1 未満の乱数を返す関数（テストでは固定の乱数を渡せる） */
    constructor(rng) {
      this.rng = rng || Math.random;
      this.board = Array.from({ length: ROWS }, () => new Array(COLS).fill(0)); // 0 = 空、1〜7 = TYPES の番号 + 1
      this.queue = [];
      this.hold = null;
      this.holdUsed = false;
      this.score = 0;
      this.lines = 0;
      this.level = 1;
      this.combo = -1;       // REN。-1 = 連続していない、0 = 1 回目の消去、1 = 2 回目（= REN 1）…
      this.b2b = false;      // 直前の「難しい消し」から B2B が続いているか
      this.over = false;
      this.overReason = '';  // 'blockout'（出現位置が埋まっていた）| 'lockout'（見える範囲の上で固定した）
      this.pieceId = 0;      // ミノが出るたびに増える（落下中の処理で「ミノが入れ替わったか」を見る用）
      this.onClear = null;   // ライン消去・T スピンのたびに呼ばれる関数 (info) => void
      this.lastClear = null;
      this.piece = null;
      this._spawn(this._draw());
    }

    // ---- 7 種 1 巡のランダム ----
    _fill() {
      while (this.queue.length < 7) {
        const bag = TYPES.slice();
        for (let i = bag.length - 1; i > 0; i--) { // フィッシャー–イェーツのシャッフル
          const j = Math.floor(this.rng() * (i + 1));
          [bag[i], bag[j]] = [bag[j], bag[i]];
        }
        this.queue.push(...bag);
      }
    }
    _draw() {
      this._fill();
      return this.queue.shift();
    }
    /** 次に出るミノ n 個 */
    next(n) {
      this._fill();
      return this.queue.slice(0, n === undefined ? 5 : n);
    }

    // ---- 盤面の判定 ----
    /** そのミノがその位置・向きで盤面に収まるか（壁・床・ブロックに当たらないか）。x, y は枠の左上 */
    _fits(type, rot, x, y) {
      for (const [c, r] of SHAPES[type][rot]) {
        const bx = x + c;
        const by = y - r;
        if (bx < 0 || bx >= COLS || by < 0 || by >= ROWS) return false;
        if (this.board[by][bx]) return false;
      }
      return true;
    }
    /** 今のミノのマスの盤面座標 [[x, y], …] */
    cells(p) {
      p = p || this.piece;
      return SHAPES[p.type][p.rot].map(([c, r]) => [p.x + c, p.y - r]);
    }
    isGrounded() {
      const p = this.piece;
      return !this._fits(p.type, p.rot, p.x, p.y - 1);
    }
    /** ゴースト（落下予定位置）の y */
    ghostY() {
      const p = this.piece;
      let y = p.y;
      while (this._fits(p.type, p.rot, p.x, y - 1)) y--;
      return y;
    }

    // ---- 出現 ----
    /**
     * ミノを出す。見える最上段の上（枠の上端が y = 21）に置き、下に空きがあれば 1 行下げる。
     * I・O は列 3〜6 の 4 列の中央、他は列 3〜5。出現位置が埋まっていたらゲームオーバー（block out）
     */
    _spawn(type) {
      this.pieceId++;
      this.piece = { type, rot: 0, x: 3, y: 21 };
      this.gravAcc = 0;
      this.lockTimer = 0;
      this.resets = 0;
      this.landed = false;
      this.lastRotate = false;
      this.lastKick = -1;
      if (!this._fits(type, 0, 3, 21)) {
        this.over = true;
        this.overReason = 'blockout';
        return;
      }
      if (this._fits(type, 0, 3, 20)) this.piece.y = 20;
      this.lowest = this.piece.y; // このミノがこれまでに降りた一番下の行
    }

    // ---- 操作 ----
    /** 1 マス動かす。pts = 成功したときに足す点（ソフトドロップの 1 点）。動けたら true */
    _tryMove(dx, dy, pts) {
      const p = this.piece;
      if (!this._fits(p.type, p.rot, p.x + dx, p.y + dy)) return false;
      const wasGrounded = this.isGrounded();
      p.x += dx;
      p.y += dy;
      this.lastRotate = false; // T スピンは「最後の成功操作が回転」のときだけ。移動・落下が入ったら外れる
      if (pts) this.score += pts;
      this._moved(wasGrounded);
      return true;
    }
    /** 移動・回転が成功したあとの接地猶予の処理 */
    _moved(wasGrounded) {
      const p = this.piece;
      if (p.y < this.lowest) { // これまでより下に降りた → リセット回数を戻す
        this.lowest = p.y;
        this.resets = 0;
      }
      if (wasGrounded) { // 接地中の操作 → タイマーをリセット（15 回まで）
        this.resets++;
        if (this.resets <= LOCK_RESETS) this.lockTimer = 0;
      }
      this._checkLock(0);
    }
    /**
     * 接地猶予の判定。接地していなければタイマーを 0 に戻す。
     * ・リセットを使い切った（15 回）あとに接地した → すぐ固定
     * ・16 回目以降の操作で接地したまま → すぐ固定
     * ・それ以外は接地してから 0.5 秒で固定
     */
    _checkLock(dt) {
      if (this.over) return;
      if (!this.isGrounded()) {
        this.lockTimer = 0;
        this.landed = false;
        return;
      }
      if (!this.landed) { // 今、接地した
        this.landed = true;
        if (this.resets >= LOCK_RESETS) { this._lock(); return; }
      }
      if (this.resets > LOCK_RESETS) { this._lock(); return; }
      this.lockTimer += dt;
      if (this.lockTimer >= LOCK_DELAY) this._lock();
    }
    move(dir) {
      if (this.over) return false;
      return this._tryMove(dir, 0, 0);
    }
    /** ソフトドロップを 1 マス（1 点）。スマホの下ドラッグ用 */
    softStep() {
      if (this.over) return false;
      return this._tryMove(0, -1, 1);
    }
    /** 回転。dir = +1（右）/ -1（左）。壁蹴りを順に試し、入れたら true */
    rotate(dir) {
      if (this.over) return false;
      const p = this.piece;
      if (p.type === 'O') return false; // O は回しても同じ
      const to = (p.rot + dir + 4) % 4;
      const kicks = (p.type === 'I' ? KICKS_I : KICKS_JLSTZ)[p.rot + '>' + to];
      for (let i = 0; i < kicks.length; i++) {
        const [dx, dy] = kicks[i];
        if (!this._fits(p.type, to, p.x + dx, p.y + dy)) continue;
        const wasGrounded = this.isGrounded();
        p.rot = to;
        p.x += dx;
        p.y += dy;
        this.lastRotate = true;
        this.lastKick = i; // 何番目の壁蹴りで入ったか（T スピンの Mini 判定に使う）
        this._moved(wasGrounded);
        return true;
      }
      return false;
    }
    /** ハードドロップ: 一気に落として固定（1 マス 2 点） */
    hardDrop() {
      if (this.over) return false;
      const p = this.piece;
      let n = 0;
      while (this._fits(p.type, p.rot, p.x, p.y - 1)) { p.y--; n++; }
      if (n > 0) this.lastRotate = false;
      this.score += 2 * n;
      this._lock();
      return true;
    }
    /** HOLD。1 回の落下につき 1 回だけ */
    holdPiece() {
      if (this.over || this.holdUsed) return false;
      const cur = this.piece.type;
      let next;
      if (this.hold) {
        next = this.hold;
      } else {
        next = this._draw();
      }
      this.hold = cur;
      this.holdUsed = true;
      this._spawn(next);
      return true;
    }

    // ---- 時間の経過 ----
    /** dt 秒進める。soft = ソフトドロップ中（キーを押している間） */
    update(dt, soft) {
      if (this.over) return;
      const g = gravityInterval(this.level);
      const interval = soft ? g / SOFT_DROP_FACTOR : g;
      if (this.isGrounded()) {
        this.gravAcc = 0; // 接地中は重力をためない（ためると、浮いた瞬間にまとめて落ちる）
      } else {
        this.gravAcc += dt;
        const id = this.pieceId;
        // level が高いと 1 フレームで何行も落ちる。ためた時間で割って、その行数だけ落とす
        while (this.gravAcc >= interval) {
          if (!this._tryMove(0, -1, soft ? 1 : 0)) { this.gravAcc = 0; break; }
          this.gravAcc -= interval;
          if (this.over || this.pieceId !== id) return; // 落ちた先で固定された
        }
        if (this.isGrounded()) this.gravAcc = 0;
      }
      this._checkLock(dt);
    }

    // ---- 固定・ライン消去・得点 ----
    /** T スピンの判定。'none' | 'mini' | 'full'（固定する直前、盤面にまだ自分が入っていない状態で呼ぶ） */
    _tspin() {
      const p = this.piece;
      if (p.type !== 'T' || !this.lastRotate) return 'none';
      const cx = p.x + 1;
      const cy = p.y - 1; // T の回転の中心
      // 範囲外（壁・床）は埋まっている扱い。上の外は空き
      const filled = (x, y) => x < 0 || x >= COLS || y < 0 || (y < ROWS && this.board[y][x] !== 0);
      const tl = filled(cx - 1, cy + 1);
      const tr = filled(cx + 1, cy + 1);
      const bl = filled(cx - 1, cy - 1);
      const br = filled(cx + 1, cy - 1);
      if (tl + tr + bl + br < 3) return 'none';
      // 凸側（T の出っ張りが向いている側）の 2 隅
      const front = [[tl, tr], [tr, br], [bl, br], [tl, bl]][p.rot];
      // 凸側が両方埋まっていれば Tスピン。片方だけなら Mini。ただし 5 番目の壁蹴りで入ったら Mini にしない
      if ((front[0] && front[1]) || this.lastKick === 4) return 'full';
      return 'mini';
    }

    _lock() {
      const p = this.piece;
      const level = this.level; // 点数はライン消去前のレベルで計算する
      const tspin = this._tspin();
      const cells = this.cells();
      const tNum = TYPES.indexOf(p.type) + 1;
      for (const [x, y] of cells) this.board[y][x] = tNum;
      const lockOut = cells.every(([, y]) => y >= VISIBLE); // 完全に見える範囲より上で固定した

      // ライン消去
      const keep = this.board.filter((row) => !row.every((v) => v !== 0));
      const lines = ROWS - keep.length;
      if (lines > 0) {
        while (keep.length < ROWS) keep.push(new Array(COLS).fill(0));
        this.board = keep;
      }
      const perfect = lines > 0 && this.board.every((row) => row.every((v) => v === 0));

      // 得点
      let pts = clearPoints(lines, tspin) * level;
      let b2bApplied = false;
      if (lines > 0) {
        const difficult = lines === 4 || tspin !== 'none'; // 難しい消し
        if (difficult) {
          if (this.b2b) { pts = Math.floor(pts * 1.5); b2bApplied = true; }
          this.b2b = true;
        } else {
          this.b2b = false; // 普通の 1〜3 ライン消しで途切れる（ラインを消さない T スピンでは変えない）
        }
        this.combo++;
        if (this.combo >= 1) pts += 50 * this.combo * level;
      } else {
        this.combo = -1;
      }
      if (perfect) pts += perfectPoints(lines, b2bApplied) * level;
      this.score += pts;
      this.lines += lines;
      this.level = levelForLines(this.lines);

      const info = { lines, tspin, b2b: b2bApplied, combo: lines > 0 ? this.combo : -1, perfect, points: pts, level };
      this.lastClear = info;
      if ((lines > 0 || tspin !== 'none') && this.onClear) this.onClear(info);

      if (lockOut) {
        this.over = true;
        this.overReason = 'lockout';
        return;
      }
      this.holdUsed = false;
      this._spawn(this._draw());
    }
  }

  const Engine = {
    COLS, ROWS, VISIBLE, TYPES, SHAPES, KICKS_JLSTZ, KICKS_I, LOCK_DELAY, LOCK_RESETS,
    Game, gravityInterval, levelForLines, clearPoints, perfectPoints, describeClear,
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = Engine;

  /* ===================================================================
   *  2. 画面（canvas・操作・ランキング）
   * =================================================================== */
  if (typeof document === 'undefined') return;

  // ミノの色（ガイドライン: I 水色, O 黄, T 紫, S 緑, Z 赤, J 青, L 橙）。盤面は暗い地なので、青と紫は少し明るくしている
  const COLORS = { I: '#2fd9f2', O: '#f5d90a', T: '#b657f0', S: '#3fd651', Z: '#ee4a3f', J: '#4b7bf5', L: '#fa9a1c' };
  const DAS = 0.167;   // キーを押してから連続移動が始まるまで（秒）
  const ARR = 0.033;   // 連続移動の間隔（秒）
  const TAP_MS = 250;  // タップ・フリックの時間
  const SLOP = 10;     // タップとみなす指のぶれ（px）

  /* ---------- 操作設定（localStorage に保存。読めない・壊れている値は初期値に戻す） ---------- */
  const SETTINGS_KEY = 'block.controls.v1';
  const GESTURE_ACTS = ['cw', 'ccw', 'hard', 'hold', 'none'];
  const BUTTON_ACTS = ['ccw', 'cw', 'hold', 'hard', 'pause', 'none'];
  const GESTURE_KEYS = ['tapL', 'tapR', 'flickDown', 'flickUp'];
  const MOVE_RANGE = [0.4, 1.5];   // 横移動: 1 マス動くのに必要な指の移動量（マス幅の倍率）。小さいほど敏感
  const FLICK_RANGE = [0.15, 0.8]; // フリック判定の速さ（px/ms）。小さいほど敏感
  const SETTINGS_DEFAULT = Object.freeze({
    gesture: Object.freeze({ tapL: 'cw', tapR: 'cw', flickDown: 'hard', flickUp: 'hold' }),
    move: 0.8,
    flick: 0.35,
    buttons: Object.freeze(['ccw', 'hold', 'pause', 'cw']),
  });

  function cloneDefault() {
    return { gesture: { ...SETTINGS_DEFAULT.gesture }, move: SETTINGS_DEFAULT.move, flick: SETTINGS_DEFAULT.flick, buttons: SETTINGS_DEFAULT.buttons.slice() };
  }
  /** 範囲に収めて 0.05 刻みにそろえる。数でなければ初期値 */
  function clampStep(v, range, fallback) {
    if (typeof v !== 'number' || !Number.isFinite(v)) return fallback;
    const n = Math.round(Math.min(range[1], Math.max(range[0], v)) / 0.05) * 0.05;
    return Math.round(n * 100) / 100;
  }
  /** 保存された値（何が入っているか分からない）を検証して、使える形にする */
  function sanitizeSettings(raw) {
    const out = cloneDefault();
    if (!raw || typeof raw !== 'object') return out;
    const g = raw.gesture && typeof raw.gesture === 'object' ? raw.gesture : {};
    for (const k of GESTURE_KEYS) if (GESTURE_ACTS.includes(g[k])) out.gesture[k] = g[k];
    out.move = clampStep(raw.move, MOVE_RANGE, out.move);
    out.flick = clampStep(raw.flick, FLICK_RANGE, out.flick);
    if (Array.isArray(raw.buttons) && raw.buttons.length === 4 && raw.buttons.every((a) => BUTTON_ACTS.includes(a))) out.buttons = raw.buttons.slice();
    return out;
  }
  /** フリックとみなす距離（マス）。速さの設定に連動: 0.15 → 1 マス、0.35（初期）→ 2 マス、0.8 → 3 マス */
  function flickCells(speed) {
    return speed <= 0.35 ? 1 + (speed - 0.15) / 0.2 : 2 + (speed - 0.35) / 0.45;
  }

  function initUi() {
    const app = document.getElementById('block-app');
    if (!app) return;
    const $ = (sel) => app.querySelector(sel);
    const play = $('#block-play');
    const cvBoard = $('#block-board');
    const cvHold = $('#block-hold');
    const cvNext = $('#block-next');
    const toast = $('#block-toast');
    const screens = {};
    app.querySelectorAll('[data-screen]').forEach((el) => { screens[el.dataset.screen] = el; });
    const ctxBoard = cvBoard.getContext('2d');
    const ctxHold = cvHold.getContext('2d');
    const ctxNext = cvNext.getContext('2d');
    const fmt = (n) => Number(n).toLocaleString('en-US');
    const setDlg = document.getElementById('block-settings');
    const gearBtn = document.getElementById('block-gear');
    let settings = loadSettings();

    let state = 'ready'; // ready | playing | paused | over
    let game = null;
    let geo = { c: 20, m: 11, slotH: 28, dpr: 1 };
    let raf = 0;
    let last = 0;

    /* ---------- 大きさ ---------- */
    function sizeCanvas(cv, ctx, w, h) {
      const dpr = window.devicePixelRatio || 1;
      cv.style.width = w + 'px';
      cv.style.height = h + 'px';
      cv.width = Math.round(w * dpr);
      cv.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0); // 以降は CSS ピクセルで描ける（くっきり）
    }

    /** 画面に収まる最大のマスの大きさを求めて、canvas の大きさを決める */
    function layout() {
      const main = app.closest('main') || document.body;
      const cs = getComputedStyle(main);
      const availW = Math.min(main.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight), 560);
      const top = app.getBoundingClientRect().top + window.scrollY;
      const nav = document.querySelector('.nav');
      const navH = nav && getComputedStyle(nav).position === 'fixed' ? nav.offsetHeight : 0; // スマホの下のナビ
      const statsH = $('.block-stats').offsetHeight;
      const ctlH = $('.block-controls').offsetHeight;
      const availH = window.innerHeight - top - navH - statsH - ctlH - 16 - 10; // 16 = 縦のすき間 2 つ、10 = 下の余白
      // 横: 盤面 10 マス + 両脇の HOLD / NEXT（小さいマス m で 4 マス幅 + 余白）+ すき間
      let c = Math.floor(Math.min(availH / 20.5, 36));
      const widthFor = (cc) => 10 * cc + 2 * (4 * Math.round(cc * 0.55) + 8) + 12;
      while (c > 12 && widthFor(c) > availW) c--;
      c = Math.max(c, 12);
      const m = Math.round(c * 0.55);
      const slotH = Math.round(m * 2.6);
      geo = { c, m, slotH };
      sizeCanvas(cvBoard, ctxBoard, 10 * c, Math.round(20.5 * c));
      sizeCanvas(cvHold, ctxHold, 4 * m + 8, slotH + 4);
      sizeCanvas(cvNext, ctxNext, 4 * m + 8, 5 * slotH + 4);
      render();
    }

    /* ---------- 描画 ---------- */
    function drawCell(ctx, px, py, size, color, alpha) {
      ctx.globalAlpha = alpha === undefined ? 1 : alpha;
      ctx.fillStyle = color;
      ctx.fillRect(px + 0.5, py + 0.5, size - 1, size - 1);
      ctx.fillStyle = 'rgba(255,255,255,.3)'; // 上の光
      ctx.fillRect(px + 0.5, py + 0.5, size - 1, Math.max(1, size * 0.16));
      ctx.fillStyle = 'rgba(0,0,0,.22)';      // 下の影
      ctx.fillRect(px + 0.5, py + size - 0.5 - Math.max(1, size * 0.14), size - 1, Math.max(1, size * 0.14));
      ctx.globalAlpha = 1;
    }

    /** y（下から数えた行）の画面上の上端。一番上は 21 行目を半分だけ見せるため 0.5 マス下げる */
    const rowTop = (y) => (VISIBLE - 1 - y) * geo.c + geo.c * 0.5;

    function drawBoard() {
      const c = geo.c;
      const W = 10 * c;
      const H = Math.round(20.5 * c);
      const ctx = ctxBoard;
      ctx.clearRect(0, 0, W, H);
      ctx.strokeStyle = 'rgba(255,255,255,.06)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      for (let x = 1; x < COLS; x++) { ctx.moveTo(x * c + 0.5, 0); ctx.lineTo(x * c + 0.5, H); }
      for (let y = 0; y <= VISIBLE; y++) { const py = Math.round(rowTop(y - 1)) + 0.5; ctx.moveTo(0, py); ctx.lineTo(W, py); }
      ctx.stroke();
      if (!game) return;
      for (let y = 0; y <= VISIBLE; y++) {
        const row = game.board[y];
        for (let x = 0; x < COLS; x++) {
          if (row[x]) drawCell(ctx, x * c, rowTop(y), c, COLORS[TYPES[row[x] - 1]]);
        }
      }
      if (!game.over) {
        const p = game.piece;
        const gy = game.ghostY();
        if (gy !== p.y) { // ゴースト（半透明）
          ctx.globalAlpha = 0.28;
          for (const [x, y] of game.cells({ type: p.type, rot: p.rot, x: p.x, y: gy })) {
            ctx.fillStyle = COLORS[p.type];
            ctx.fillRect(x * c + 1, rowTop(y) + 1, c - 2, c - 2);
          }
          ctx.globalAlpha = 1;
        }
        for (const [x, y] of game.cells()) {
          if (y <= VISIBLE) drawCell(ctx, x * c, rowTop(y), c, COLORS[p.type]);
        }
      }
    }

    /** 小さい枠の中央にミノを描く（HOLD・NEXT） */
    function drawMini(ctx, type, x, y, w, h, dim) {
      const m = geo.m;
      const cells = SHAPES[type][0];
      const cols = cells.map((q) => q[0]);
      const rows = cells.map((q) => q[1]);
      const minC = Math.min(...cols);
      const minR = Math.min(...rows);
      const pw = (Math.max(...cols) - minC + 1) * m;
      const ph = (Math.max(...rows) - minR + 1) * m;
      const ox = x + (w - pw) / 2;
      const oy = y + (h - ph) / 2;
      for (const [c, r] of cells) drawCell(ctx, ox + (c - minC) * m, oy + (r - minR) * m, m, COLORS[type], dim ? 0.3 : 1);
    }

    function drawSide() {
      const w = 4 * geo.m + 8;
      ctxHold.clearRect(0, 0, w, geo.slotH + 4);
      if (game && game.hold) drawMini(ctxHold, game.hold, 0, 2, w, geo.slotH, game.holdUsed);
      ctxNext.clearRect(0, 0, w, 5 * geo.slotH + 4);
      if (game) game.next(5).forEach((t, i) => drawMini(ctxNext, t, 0, 2 + i * geo.slotH, w, geo.slotH, false));
    }

    const statEls = {};
    app.querySelectorAll('[data-stat]').forEach((el) => { statEls[el.dataset.stat] = el; });
    const flagB2b = $('[data-flag="b2b"]');
    const flagRen = $('[data-flag="ren"]');
    const shown = {};
    function setText(key, el, text) {
      if (shown[key] !== text) { shown[key] = text; el.textContent = text; }
    }
    function updateStats() {
      const s = game ? game.score : 0;
      setText('score', statEls.score, fmt(s));
      setText('level', statEls.level, String(game ? game.level : 1));
      setText('lines', statEls.lines, String(game ? game.lines : 0));
      flagB2b.hidden = !(game && game.b2b);
      const ren = game && game.combo >= 1 ? 'REN ' + game.combo : '';
      flagRen.hidden = ren === '';
      if (ren) setText('ren', flagRen, ren);
    }

    function render() {
      drawBoard();
      drawSide();
      updateStats();
    }

    /* ---------- ライン消去の表示 ---------- */
    let toastTimer = 0;
    function showClear(info) {
      const lines = describeClear(info);
      toast.replaceChildren();
      lines.forEach((t, i) => {
        const d = document.createElement('div');
        d.className = 'block-toast__line' + (i === 0 ? ' block-toast__line--main' : '');
        d.textContent = t;
        toast.appendChild(d);
      });
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => toast.replaceChildren(), 1400);
    }

    /* ---------- 状態の切り替え ---------- */
    function showScreen(name) {
      Object.keys(screens).forEach((k) => { screens[k].hidden = k !== name; });
    }

    function resetInput() {
      dasKeys.length = 0;
      dir = 0;
      arr = false;
      dasT = 0;
      softKey = false;
      gesture = null;
    }

    function start() {
      game = new Game();
      game.onClear = showClear;
      state = 'playing';
      toast.replaceChildren();
      showScreen('');
      resetInput();
      window.scrollTo(0, 0);
      layout();
      last = performance.now();
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(frame);
    }

    function pause() {
      if (state !== 'playing') return;
      state = 'paused';
      cancelAnimationFrame(raf);
      resetInput();
      showScreen('paused');
    }

    function resume() {
      if (state !== 'paused') return;
      state = 'playing';
      showScreen('');
      last = performance.now();
      raf = requestAnimationFrame(frame);
    }

    function frame(now) {
      raf = 0;
      if (state !== 'playing') return;
      const dt = Math.min(0.1, Math.max(0, (now - last) / 1000)); // 長く止まったあと一気に進まないよう、1 回で進めるのは 0.1 秒まで
      last = now;
      keyRepeat(dt);
      game.update(dt, softKey);
      render();
      if (game.over) { finish(); return; }
      raf = requestAnimationFrame(frame);
    }

    /* ---------- ゲームオーバー・送信・ランキング ---------- */
    const res = {};
    app.querySelectorAll('[data-result]').forEach((el) => { res[el.dataset.result] = el; });
    const btnRetry = $('[data-act="retry"]');
    const rankBox = document.getElementById('block-ranking');

    function finish() {
      state = 'over';
      cancelAnimationFrame(raf);
      resetInput();
      res.score.textContent = fmt(game.score);
      res.best.hidden = true;
      res.err.hidden = true;
      btnRetry.hidden = true;
      res.rank.textContent = '';
      showScreen('over');
      submit();
    }

    function post(body) {
      if (typeof apiPost === 'function') return apiPost('api_block_score.php', body, '送信できませんでした');
      return fetch('api_block_score.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify(body),
      }).then((r) => r.json().then((j) => { if (!r.ok) throw new Error(j.error || '送信できませんでした'); return j; }));
    }

    async function submit() {
      const g = game;
      if (!g || g.score <= 0) return; // 0 点は送らない
      res.err.hidden = true;
      btnRetry.hidden = true;
      res.rank.textContent = '…';
      try {
        const r = await post({ score: g.score, lines: g.lines, level: g.level });
        if (g !== game || state !== 'over') { renderRanking(r); return; } // 送信中に再開始された
        res.best.hidden = !r.best;
        res.rank.textContent = r.mine ? r.mine.rank + '位' + (r.best ? '' : ' / 自己ベスト ' + fmt(r.mine.score)) : '';
        if (r.mine) app.dataset.best = String(r.mine.score);
        renderRanking(r);
      } catch (e) {
        if (g !== game || state !== 'over') return;
        res.rank.textContent = '';
        res.err.textContent = e.message || '送信できませんでした';
        res.err.hidden = false;
        btnRetry.hidden = false;
      }
    }

    function renderRanking(r) {
      if (!rankBox) return;
      const list = rankBox.querySelector('[data-rank-list]');
      const empty = rankBox.querySelector('[data-rank-empty]');
      const mine = rankBox.querySelector('[data-rank-mine]');
      const el = (tag, cls, text) => { const e = document.createElement(tag); e.className = cls; e.textContent = text; return e; };
      const row = (tag, cls, rank, name, score) => {
        const li = document.createElement(tag);
        li.className = cls;
        li.append(el('span', 'block-ranking__rank', String(rank)), el('span', 'block-ranking__name', name), el('span', 'block-ranking__score', fmt(score)));
        return li;
      };
      list.replaceChildren(...r.ranking.map((x) => row('li', 'block-ranking__row' + (x.me ? ' is-me' : ''), x.rank, x.name, x.score)));
      empty.hidden = r.ranking.length > 0;
      const outside = r.mine && !r.ranking.some((x) => x.me);
      mine.hidden = !outside;
      mine.replaceChildren();
      if (outside) mine.append(el('span', 'block-ranking__rank', String(r.mine.rank)), el('span', 'block-ranking__name', '自己ベスト'), el('span', 'block-ranking__score', fmt(r.mine.score)));
    }

    /* ---------- キーボード（PC） ---------- */
    let dir = 0;          // 連続移動の向き（-1 左 / 1 右 / 0 なし）
    let arr = false;      // 連続移動（ARR）に入ったか
    let dasT = 0;
    const dasKeys = [];   // 押している左右キー（押した順。最後が優先）
    let softKey = false;  // ↓を押している

    const KEYMAP = {
      ArrowLeft: 'left', ArrowRight: 'right', ArrowDown: 'soft', Space: 'hard',
      ArrowUp: 'cw', KeyX: 'cw', KeyZ: 'ccw', ControlLeft: 'ccw', ControlRight: 'ccw',
      KeyC: 'hold', ShiftLeft: 'hold', ShiftRight: 'hold', Escape: 'pause', KeyP: 'pause',
    };

    function setDir() {
      dir = dasKeys.length ? (dasKeys[dasKeys.length - 1] === 'left' ? -1 : 1) : 0;
      arr = false;
      dasT = 0;
    }

    /** DAS（押してから動き始めるまで）→ ARR（動き続ける間隔）。OS のキーリピートは使わず、自分で数える */
    function keyRepeat(dt) {
      if (!dir) return;
      dasT += dt;
      if (!arr) {
        if (dasT < DAS) return;
        dasT -= DAS;
        arr = true;
        game.move(dir);
      }
      while (dasT >= ARR) {
        dasT -= ARR;
        if (!game.move(dir)) { dasT = 0; break; } // 壁に当たったらためない
      }
    }

    function act(name) {
      if (name === 'pause') { state === 'playing' ? pause() : resume(); return; }
      if (state !== 'playing') return;
      if (name === 'cw') game.rotate(1);
      else if (name === 'ccw') game.rotate(-1);
      else if (name === 'hold') game.holdPiece();
      else if (name === 'hard') game.hardDrop();
      render();
      if (game.over) finish();
    }

    document.addEventListener('keydown', (e) => {
      if (setDlg && setDlg.open) return; // 操作設定を開いている間は、ゲームのキー操作を受けない（Esc は設定を閉じるだけ）
      const t = e.target;
      if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
      const a = KEYMAP[e.code];
      if (!a) return;
      if (state === 'playing' || state === 'paused') e.preventDefault(); // 矢印・Space でページがスクロールしないように
      if (e.repeat) return;
      if (a === 'pause') { act('pause'); return; }
      if (state !== 'playing') return;
      if (a === 'left' || a === 'right') {
        const i = dasKeys.indexOf(a);
        if (i >= 0) dasKeys.splice(i, 1);
        dasKeys.push(a); // あとから押した方が優先
        setDir();
        game.move(dir);
        render();
      } else if (a === 'soft') {
        softKey = true;
        game.softStep();
        render();
      } else {
        act(a);
      }
    });

    document.addEventListener('keyup', (e) => {
      const a = KEYMAP[e.code];
      if (!a) return;
      if (state === 'playing' || state === 'paused') e.preventDefault(); // Space で、フォーカスのあるボタンが押されないように
      if (a === 'left' || a === 'right') {
        const i = dasKeys.indexOf(a);
        if (i >= 0) { dasKeys.splice(i, 1); setDir(); }
      } else if (a === 'soft') {
        softKey = false;
      }
    });

    // 別のウィンドウへ移ったとき、押しっぱなしのまま残らないようにする
    window.addEventListener('blur', () => { dasKeys.length = 0; dir = 0; softKey = false; });
    // タブが見えなくなったら自動で一時停止
    document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); });

    /* ---------- ボタン ---------- */
    app.querySelectorAll('[data-act]').forEach((btn) => {
      const name = btn.dataset.act;
      btn.addEventListener('click', () => {
        if (name === 'start' || name === 'restart') start();
        else if (name === 'resume') resume();
        else if (name === 'retry') submit();
        btn.blur();
      });
    });

    // 盤面の下の操作ボタン。割り当ては設定で変えられる（data-slot = 左から 0〜3）
    const BTN_DEF = {
      ccw: { label: '左回転', icon: 'rotate_left', key: 'Z' },
      cw: { label: '右回転', icon: 'rotate_right', key: '↑' },
      hold: { label: 'HOLD', text: 'HOLD', key: 'C' },
      hard: { label: 'ハードドロップ', icon: 'vertical_align_bottom', key: 'Space' },
      pause: { label: '一時停止', icon: 'pause', key: 'Esc' },
    };
    const ctlBox = $('.block-controls');
    const slotBtns = Array.from(ctlBox.querySelectorAll('[data-slot]'));

    function runButton(action) {
      if (action === 'pause') { if (state === 'playing' || state === 'paused') act('pause'); }
      else act(action);
    }

    slotBtns.forEach((btn, i) => {
      // 指が触れた瞬間に反応させる（click は指を離すまで待つ）
      btn.addEventListener('pointerdown', (e) => { e.preventDefault(); runButton(settings.buttons[i]); });
      btn.addEventListener('click', (e) => { if (e.detail === 0) runButton(settings.buttons[i]); }); // キーボードで押したとき
    });

    /** 設定どおりにボタンの見た目を作り直す。「なし」の枠は隠し、残りが均等幅に広がる */
    function applyButtons() {
      let shownN = 0;
      slotBtns.forEach((btn, i) => {
        const a = settings.buttons[i];
        btn.hidden = a === 'none';
        if (a === 'none') return;
        shownN++;
        const d = BTN_DEF[a];
        btn.setAttribute('aria-label', d.label);
        btn.title = d.label + ' (' + d.key + ')';
        btn.classList.toggle('block-btn--text', !d.icon);
        if (d.icon) {
          const ic = document.createElement('span');
          ic.className = 'icon';
          ic.setAttribute('aria-hidden', 'true');
          ic.textContent = d.icon;
          btn.replaceChildren(ic);
        } else {
          btn.textContent = d.text;
        }
      });
      ctlBox.hidden = shownN === 0;
      ctlBox.style.setProperty('--block-btn-n', String(Math.max(shownN, 1)));
    }

    /* ---------- 操作設定 ---------- */
    function loadSettings() {
      try {
        const raw = localStorage.getItem(SETTINGS_KEY);
        return raw ? sanitizeSettings(JSON.parse(raw)) : cloneDefault();
      } catch (err) {
        return cloneDefault();
      }
    }
    function saveSettings() {
      try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (err) { /* 保存できなくても今回は反映される */ }
    }

    // スライダーは右ほど敏感（= 内部の値は小さい）。表示の値は 最小 + 最大 - 内部値 で反転する
    const flip = (v, range) => Math.round((range[0] + range[1] - v) * 100) / 100;

    let syncing = false; // パネルの表示を設定に合わせている間は、change を「ユーザーの操作」として扱わない
    function syncPanel() {
      syncing = true;
      // 共通のプルダウン（app.js の setupSelectPick）は change を聞いてボタンの文字を合わせるので、値を入れたら change を出す
      const put = (el, v) => { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); };
      setDlg.querySelectorAll('[data-set-gesture]').forEach((el) => put(el, settings.gesture[el.dataset.setGesture]));
      setDlg.querySelectorAll('[data-set-button]').forEach((el) => put(el, settings.buttons[Number(el.dataset.setButton)]));
      syncing = false;
      setDlg.querySelector('[data-set-range="move"]').value = String(flip(settings.move, MOVE_RANGE));
      setDlg.querySelector('[data-set-range="flick"]').value = String(flip(settings.flick, FLICK_RANGE));
    }

    function setupSettings() {
      applyButtons();
      if (!setDlg || !gearBtn) return;
      syncPanel();
      gearBtn.addEventListener('click', () => {
        if (state === 'playing') pause(); // ゲーム中なら一時停止。閉じても自動では再開しない
        syncPanel();
        if (!setDlg.open) setDlg.showModal();
        gearBtn.blur();
      });
      setDlg.querySelector('[data-set="close"]').addEventListener('click', () => setDlg.close());
      setDlg.addEventListener('click', (e) => { if (e.target === setDlg) setDlg.close(); }); // 枠の外（背景）を押したら閉じる
      setDlg.addEventListener('change', (e) => {
        if (syncing) return;
        const el = e.target;
        if (el.dataset.setGesture) {
          const k = el.dataset.setGesture;
          if (GESTURE_KEYS.includes(k) && GESTURE_ACTS.includes(el.value)) settings.gesture[k] = el.value;
        } else if (el.dataset.setButton !== undefined) {
          const i = Number(el.dataset.setButton);
          if (i >= 0 && i < 4 && BUTTON_ACTS.includes(el.value)) settings.buttons[i] = el.value;
          applyButtons();
          layout(); // 操作ボタンの高さが変わりうるので、盤面の大きさを取り直す
        }
        saveSettings();
      });
      setDlg.addEventListener('input', (e) => {
        const which = e.target.dataset.setRange;
        if (!which) return;
        const v = Number(e.target.value);
        if (which === 'move') settings.move = clampStep(flip(v, MOVE_RANGE), MOVE_RANGE, SETTINGS_DEFAULT.move);
        else if (which === 'flick') settings.flick = clampStep(flip(v, FLICK_RANGE), FLICK_RANGE, SETTINGS_DEFAULT.flick);
        saveSettings();
      });
      setDlg.querySelector('[data-set="reset"]').addEventListener('click', () => {
        settings = cloneDefault();
        saveSettings();
        syncPanel();
        applyButtons();
        layout();
      });
    }

    /* ---------- スマホのジェスチャー ---------- */
    let gesture = null;
    play.addEventListener('pointerdown', (e) => {
      if (state !== 'playing' || gesture) return;
      if (e.target.closest('button')) return;
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      gesture = { id: e.pointerId, x0: e.clientX, y0: e.clientY, t0: e.timeStamp, px: e.clientX, py: e.clientY, axis: null, accX: 0, accY: 0, samples: [] };
      try { play.setPointerCapture(e.pointerId); } catch (err) { /* 取れなくても動く */ }
      e.preventDefault();
    });

    play.addEventListener('pointermove', (e) => {
      const g = gesture;
      if (!g || e.pointerId !== g.id || state !== 'playing') return;
      const tx = e.clientX - g.x0;
      const ty = e.clientY - g.y0;
      g.samples.push({ t: e.timeStamp, y: e.clientY });
      while (g.samples.length > 1 && e.timeStamp - g.samples[0].t > 80) g.samples.shift();
      if (!g.axis) { // 最初に大きく動いた方向で、横か縦かを決める
        if (Math.hypot(tx, ty) <= SLOP) return;
        g.axis = Math.abs(tx) >= Math.abs(ty) ? 'h' : 'v';
      }
      const dx = e.clientX - g.px;
      const dy = e.clientY - g.py;
      g.px = e.clientX;
      g.py = e.clientY;
      const c = geo.c;
      if (g.axis === 'h') {
        // 横ドラッグ: 1 マス幅の設定倍（初期 0.8 倍）たまるごとに 1 マス（速く動かせば連続で動く）
        g.accX += dx;
        const step = c * settings.move;
        while (g.accX >= step) { game.move(1); g.accX -= step; }
        while (g.accX <= -step) { game.move(-1); g.accX += step; }
      }
      // 下ドラッグ: 1 マス分ごとにソフトドロップ 1 マス（横ドラッグの後のゆっくりした下ドラッグも許す）
      g.accY = Math.max(0, g.accY + dy);
      const s0 = g.samples[0];
      const v = e.timeStamp > s0.t ? (e.clientY - s0.y) / (e.timeStamp - s0.t) : 0; // 直近の縦の速さ（px/ms）
      // 縦の軸で速いときはフリックかもしれないので、まだ落とさない（離したときにフリックでなければ落とす）
      if (g.axis === 'h' || v < 1.0) flushSoft(g);
      render();
      if (game.over) finish();
    });

    /** ジェスチャーの割り当てを実行する（'none' は何もしない） */
    function runGesture(name) {
      if (name === 'cw') game.rotate(1);
      else if (name === 'ccw') game.rotate(-1);
      else if (name === 'hard') game.hardDrop();
      else if (name === 'hold') game.holdPiece();
    }

    function flushSoft(g) {
      while (g.accY >= geo.c) {
        game.softStep();
        g.accY -= geo.c;
      }
    }

    function endGesture(e) {
      const g = gesture;
      if (!g || e.pointerId !== g.id) return;
      gesture = null;
      if (state !== 'playing') return;
      const tx = e.clientX - g.x0;
      const ty = e.clientY - g.y0;
      const dur = Math.max(1, e.timeStamp - g.t0);
      const c = geo.c;
      if (!g.axis) {
        if (Math.hypot(tx, ty) < SLOP && dur < TAP_MS) { // タップ。盤面の中央より左か右かで割り当てを変える
          const r = cvBoard.getBoundingClientRect();
          runGesture(e.clientX < r.left + r.width / 2 ? settings.gesture.tapL : settings.gesture.tapR);
        }
      } else if (g.axis === 'v') {
        const dist = flickCells(settings.flick) * c;
        const down = settings.gesture.flickDown;
        const up = settings.gesture.flickUp;
        if (ty > dist && dur < TAP_MS && ty / dur > settings.flick && down !== 'none') runGesture(down);       // 下フリック
        else if (-ty > dist && dur < TAP_MS && -ty / dur > settings.flick && up !== 'none') runGesture(up);   // 上フリック
        else flushSoft(g);
      } else {
        flushSoft(g);
      }
      render();
      if (game.over) finish();
    }
    play.addEventListener('pointerup', endGesture);
    play.addEventListener('pointercancel', (e) => { if (gesture && e.pointerId === gesture.id) gesture = null; });

    // 長押しメニュー・ダブルタップ拡大・ピンチを止める
    play.addEventListener('contextmenu', (e) => e.preventDefault());
    play.addEventListener('dblclick', (e) => e.preventDefault());
    play.addEventListener('touchstart', (e) => { if (!e.target.closest('button')) e.preventDefault(); }, { passive: false });
    document.addEventListener('gesturestart', (e) => { if (play.contains(e.target)) e.preventDefault(); });

    window.addEventListener('resize', layout);
    window.addEventListener('orientationchange', layout);
    setupSettings();
    layout();
  }

  initUi();
})(typeof window !== 'undefined' ? window : this);
