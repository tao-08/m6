/* =====================================================================
 *  assets/sched.js — スタ連の日程調整の動き
 * =====================================================================
 *  1. 作成フォーム（sched.php の [data-sched-form]）… 部員の行の追加・削除、エリアのチェックでまとめて選ぶ
 *  2. 時間割の学期タブ（sched_me.php の [data-sched-terms]）
 *  3. NG の時間のマス（sched_me.php の #sched-grid-data）… タップ・なぞって塗る → JSON で送信
 *  4. 候補の一覧（sched_poll.php の #sched-data）… 料金を計算して並べ、仮決定・決定のボタンを付ける
 *
 *  ■ XSS 対策
 *    DB から来た文字（バンド名・スタジオ名・部員名）は textContent で入れる（HTML として解釈されない）
 *  ■ IIFE（(() => { … })()）… 中の変数が他の JS とぶつからないように閉じ込める定番の書き方
 * ===================================================================== */
(() => {
  'use strict';

  /** <script type="application/json" id="…"> を読む（無ければ null） */
  const readJson = (id) => {
    const n = document.getElementById(id);
    if (!n) return null;
    try {
      return JSON.parse(n.textContent);
    } catch (e) {
      return null;
    }
  };

  /** 要素を作る。el('span', 'muted', '文字') → <span class="muted">文字</span> */
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };

  /** 0時からの分 → "H:MM"（1440 は 24:00） */
  const hm = (min) => Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0');
  const yen = (n) => '¥' + Math.round(n).toLocaleString('ja-JP');

  // ===================================================================
  //  1. 作成フォーム
  // ===================================================================
  const setupForm = () => {
    const form = document.querySelector('[data-sched-form]');
    if (!form) return;
    const rows = form.querySelector('[data-member-rows]');
    const count = form.querySelector('[data-member-count]');

    /** 人数の表示と、「最後の行が埋まったら空の行を足す」 */
    const refresh = () => {
      const inputs = [...rows.querySelectorAll('input')];
      const filled = inputs.filter((i) => i.value.trim() !== '').length;
      count.textContent = filled ? filled + '人' : '';
      const last = inputs[inputs.length - 1];
      if (!last || last.value.trim() !== '') {
        // cloneNode(true) … 行を中身ごと複製する。値だけ空にして末尾に足す
        const tpl = rows.firstElementChild.cloneNode(true);
        tpl.querySelector('input').value = '';
        rows.append(tpl);
      }
    };
    rows.addEventListener('input', refresh);
    rows.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove-member]');
      if (!btn) return;
      const row = btn.closest('.sch-member-row');
      if (rows.children.length > 1) row.remove();
      else row.querySelector('input').value = '';
      refresh();
    });
    refresh();

    // ---- エリアのチェック = その中のスタジオを全部 ON / OFF ----
    form.querySelectorAll('[data-area]').forEach((box) => {
      const all = box.querySelector('[data-area-all]');
      const items = [...box.querySelectorAll('input[name="studio[]"]')];
      // 中の状態に合わせて、エリアのチェックを ✓ / ー（一部だけ = indeterminate）/ 空 にする
      const sync = () => {
        const on = items.filter((i) => i.checked).length;
        all.checked = on === items.length;
        all.indeterminate = on > 0 && on < items.length;
      };
      all.addEventListener('change', () => {
        items.forEach((i) => { i.checked = all.checked; });
        sync();
      });
      items.forEach((i) => i.addEventListener('change', sync));
      sync();
    });
  };

  // ===================================================================
  //  2. 時間割の学期タブ
  // ===================================================================
  const setupTerms = () => {
    const tabs = document.querySelector('[data-sched-terms]');
    if (!tabs) return;
    tabs.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-term-tab]');
      if (!btn) return;
      tabs.querySelectorAll('[data-term-tab]').forEach((b) => b.classList.toggle('is-active', b === btn));
      document.querySelectorAll('form[data-term]').forEach((f) => { f.hidden = f.dataset.term !== btn.dataset.termTab; });
    });
  };

  // ===================================================================
  //  3. NG の時間のマス
  //     ・1マス = 30分。手入力の NG だけ塗ったり消したりできる（授業・カレンダー・練習は自動なので触れない）
  //     ・押したマスが「塗られていない」なら塗るモード、「塗られている」なら消すモードで、なぞった所に同じ操作をする
  //       （Excel の塗りつぶしや when2meet と同じ操作感）
  //     ・スマホでなぞるとページがスクロールしないよう、マスの上だけ touch-action: none（sched.css）
  //       時刻の列はスクロールできる（そこを持てばページを動かせる）
  // ===================================================================
  const setupGrid = () => {
    const data = readJson('sched-grid-data');
    const wrap = document.querySelector('[data-sched-grid]');
    const form = document.querySelector('[data-sched-grid-form]');
    if (!data || !wrap || !form) return;
    const save = form.querySelector('[data-sched-grid-save]');
    const kindLabel = { class: '授業', ical: '予定', practice: '練習' };

    const table = el('table', 'sch-grid');
    const head = el('tr');
    head.append(el('th'));
    data.days.forEach((d) => {
      const th = el('th', d.dow === 0 ? 'is-sun' : d.dow === 6 ? 'is-sat' : '', d.md.replace(/\((.)\)/, '\n$1')); // 「11/21」改行「土」
      head.append(th);
    });
    const thead = el('thead');
    thead.append(head);
    table.append(thead);
    const tbody = el('tbody');
    const cells = []; // 塗れるマス
    for (let m = data.start; m < data.end; m += data.step) {
      const tr = el('tr', m % 60 === 0 ? 'is-hour' : '');
      tr.append(el('th', '', m % 60 === 0 ? hm(m) : ''));
      data.days.forEach((d) => {
        const abs = d.abs + m;
        const td = el('td');
        td.dataset.abs = abs;
        // このマスにかかる埋まり（自動のもの優先。手入力は塗れる）
        const hit = data.busy.filter((b) => b[0] < abs + data.step && b[1] > abs);
        const auto = hit.find((b) => b[2] !== 'manual');
        if (auto) {
          td.className = 'is-' + auto[2] + ' is-auto';
          // 予定の最初のマスにだけラベル（授業の「2限」・練習のバンド名）
          if (auto[0] >= abs && auto[0] < abs + data.step || abs === d.abs + data.start) td.textContent = auto[3] || kindLabel[auto[2]];
        } else {
          if (hit.length) td.classList.add('is-ng');
          cells.push(td);
        }
        // 仮決定の注意（その日に仮予定がある）
        if (data.warn.some((w) => w.date === d.date && (w.s === null || (w.s < abs + data.step && w.e > abs)))) td.classList.add('is-warn');
        tr.append(td);
      });
      tbody.append(tr);
    }
    table.append(tbody);
    wrap.append(table);

    let mode = null; // true = 塗る / false = 消す / null = なぞっていない
    let dirty = false;
    const paint = (td) => {
      if (!td || !td.dataset.abs || td.classList.contains('is-auto')) return;
      if (td.classList.contains('is-ng') !== mode) {
        td.classList.toggle('is-ng', mode);
        dirty = true;
        save.disabled = false;
      }
    };
    table.addEventListener('pointerdown', (e) => {
      const td = e.target.closest('td');
      if (!td || td.classList.contains('is-auto')) return;
      e.preventDefault();
      mode = !td.classList.contains('is-ng');
      paint(td);
    });
    // 指を動かしている間: 指の下のマスを探して塗る（elementFromPoint = その座標にある一番上の要素）
    table.addEventListener('pointermove', (e) => {
      if (mode === null) return;
      paint(document.elementFromPoint(e.clientX, e.clientY)?.closest('td'));
    });
    const end = () => { mode = null; };
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);

    // 保存: 塗られているマスの「絶対分」を JSON にして送る
    form.addEventListener('submit', () => {
      const payload = {
        days: data.days.map((d) => d.date), start: data.start, end: data.end,
        cells: cells.filter((td) => td.classList.contains('is-ng')).map((td) => Number(td.dataset.abs)),
      };
      form.querySelector('input[name="payload"]').value = JSON.stringify(payload);
      dirty = false;
    });
    // 保存しないまま離れようとしたら警告（beforeunload = ページを離れる直前に来るイベント）
    window.addEventListener('beforeunload', (e) => {
      if (dirty) e.preventDefault();
    });
  };

  // ===================================================================
  //  4. 候補の一覧
  // ===================================================================
  const setupCands = () => {
    const data = readJson('sched-data');
    const list = document.querySelector('[data-sched-list]');
    if (!data || !list || !window.studioSimEngine) return;
    const engines = {}; // スタジオ id → 料金の計算部品（1回作って使い回す）
    const studios = Object.fromEntries(data.studios.map((s) => [s.id, s]));

    /**
     * 部屋が人数に合うか（PHP の sched_room_fit と同じ判定）
     *   'ok' / 'small' / 'unknown'（定員も広さも不明）
     */
    const fit = (room) => {
      if (room.cap) return room.cap >= data.people ? 'ok' : 'small';
      if (room.a) return room.a / data.jo >= data.people ? 'ok' : 'small';
      return 'unknown';
    };

    /**
     * 1つのスタジオ・開始時刻での「いちばん安い部屋」と、人数に「ちょうどいい部屋」（合う部屋のうち一番狭い）
     *   戻り値: { total, room, disc, best } / null（料金未登録）
     */
    const quote = (sid, day, start) => {
      const sim = data.sim[sid];
      if (!sim) return null;
      engines[sid] ||= window.studioSimEngine(sim); // ||= … 無ければ作って入れる
      const { calc, dayOk } = engines[sid];
      const rooms = sim.rooms.length ? sim.rooms.filter((r) => fit(r) !== 'small') : [null];
      // 合う部屋のうち、定員（無ければ広さ）がいちばん小さいもの = ちょうどいい
      const sized = rooms.filter((r) => r && fit(r) === 'ok').sort((a, b) => (a.cap || a.a / data.jo) - (b.cap || b.a / data.jo));
      const best = sized[0] || null;
      const discs = [{ id: 0, name: '' }, ...sim.discounts.filter((d) => dayOk(d.day, day))];
      let top = null;
      rooms.forEach((room) => {
        const v = { ppl: 0, room: room ? room.id : 0, day, start, rentals: [] };
        discs.forEach((d) => {
          const r = calc(v, d.id, data.len);
          if (r.missing || (!r.pack && !r.normal.segs.length)) return; // 料金表に無い時間がある → 比べない
          if (!top || r.total < top.total) top = { total: r.total, room, disc: d.name };
        });
      });
      return top ? { ...top, best } : null;
    };

    /** 確定のボタン（POST のフォーム）。kind = date / time / final */
    const fixForm = (kind, label, w, getStart, getRoom, primary) => {
      const f = el('form');
      f.method = 'post';
      const add = (name, value) => {
        const i = el('input');
        i.type = 'hidden';
        i.name = name;
        i.value = value;
        f.append(i);
        return i;
      };
      add('csrf', data.csrf);
      add('action', 'fix');
      add('v', data.v);
      add('kind', kind);
      add('date', w.date);
      add('studio', w.studio);
      const s = add('start', '');
      const r = add('room', '');
      const b = el('button', 'btn btn--sm' + (primary ? ' btn--primary' : ' btn--ghost'), label);
      b.type = 'submit';
      f.append(b);
      // 送信の直前に、選んでいる開始時刻・部屋を入れ、確認の文（data-confirm → app.js の setupConfirm が出す）を作る
      //   このフォームでの submit は、document で待ち受けている app.js より先に呼ばれる（イベントは内側 → 外側へ伝わる）
      f.dataset.confirm = '';
      f.addEventListener('submit', () => {
        s.value = getStart();
        r.value = getRoom() || '';
        f.dataset.confirm = `${w.md} ${kind === 'date' ? '' : hm(getStart()) + '〜 '}${kind === 'date' ? '' : studios[w.studio]?.name || ''} で${label}します。部員に通知が届きます。よろしいですか？`;
      });
      return f;
    };

    /** 候補1件のカード */
    const card = (w) => {
      const c = el('article', 'card sch-cand' + (w.late.length ? ' is-late' : ''));
      // ---- 1行目: 日付・時間帯・バッジ ----
      const top = el('div', 'sch-cand__top');
      top.append(el('strong', 'sch-cand__date', w.md));
      const span = w.starts.length > 1 ? `${hm(w.from)}〜${hm(w.to)}` : `${hm(w.from)}〜${hm(w.from + data.len)}`;
      top.append(el('span', 'sch-cand__time', span));
      if (w.adj) {
        const b = el('span', 'tag ' + (w.adj === 2 ? 'tag--mine' : 'tag--plan'), w.adj === 2 ? '連続◎' : '連続○');
        b.dataset.tip = '前後に同じスタジオの練習: ' + w.adjWith.join('・');
        top.append(b);
      }
      c.append(top);
      // ---- 2行目: スタジオ・部屋・料金 ----
      const mid = el('div', 'sch-cand__mid');
      const st = studios[w.studio];
      mid.append(el('span', 'sch-cand__studio', st ? st.name : ''));
      const price = el('span', 'sch-cand__price');
      mid.append(price);
      c.append(mid);
      // ---- 3行目: 遅刻・仮予定の注意 ----
      if (w.late.length || w.warn.length) {
        const notes = el('div', 'sch-cand__notes');
        w.late.forEach((l) => notes.append(el('span', 'pill pill--warn', `${l.name} +${l.min}分`)));
        w.warn.forEach((t) => {
          const p = el('span', 'pill', '⚠ ' + t);
          p.dataset.tip = 'この時間・日に仮決定の練習あり';
          notes.append(p);
        });
        c.append(notes);
      }
      // ---- 開始時刻の選択（窓に複数の開始時刻があるとき）----
      let start = w.starts[0];
      let room = null;
      const foot = el('div', 'sch-cand__foot');
      if (w.starts.length > 1) {
        const sel = el('select', 'select-sm');
        sel.setAttribute('aria-label', '開始時刻');
        w.starts.forEach((m) => {
          const o = el('option', '', `${hm(m)}〜${hm(m + data.len)}`);
          o.value = m;
          sel.append(o);
        });
        sel.addEventListener('change', () => {
          start = Number(sel.value);
          renderPrice();
        });
        foot.append(sel);
      }
      // 料金の表示（開始時刻を変えたら計算し直す）
      const renderPrice = () => {
        const q = quote(w.studio, w.day, start);
        price.replaceChildren();
        room = null;
        if (!q) {
          price.append(el('span', 'muted small', '料金未登録'));
          w.price = Infinity;
          return;
        }
        room = q.room ? q.room.id : null;
        price.append(el('strong', '', yen(q.total)));
        price.append(el('span', 'muted small', ` 1人 ${yen(Math.ceil(q.total / data.people))}`));
        if (q.room) price.append(el('span', 'muted small', ' ' + q.room.name));
        if (q.best && q.room && q.best.id === q.room.id) price.append(el('span', 'tag tag--plan', 'おすすめ'));
        if (q.disc) price.append(el('span', 'tag', q.disc));
        w.price = q.total;
      };
      renderPrice();
      if (data.canFix) {
        const acts = el('div', 'sch-cand__acts');
        if (data.status === 0) acts.append(fixForm('date', '日だけ仮決定', w, () => start, () => room, false));
        acts.append(fixForm('time', '仮決定', w, () => start, () => room, false));
        acts.append(fixForm('final', '決定', w, () => start, () => room, true));
        foot.append(acts);
      }
      c.append(foot);
      return c;
    };

    const cards = data.windows.map((w, i) => ({ w, i, node: card(w) }));
    const sorters = {
      best: (a, b) => a.i - b.i, // PHP の並び（全員そろう → 連続◎ → 連続○ → 日付）
      date: (a, b) => a.w.date.localeCompare(b.w.date) || a.w.from - b.w.from,
      price: (a, b) => a.w.price - b.w.price || a.i - b.i,
    };
    const render = (key) => {
      list.replaceChildren(...[...cards].sort(sorters[key]).map((c) => c.node));
    };
    render('best');
    document.querySelector('[data-sched-sort]')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-sort]');
      if (!btn) return;
      btn.parentElement.querySelectorAll('[data-sort]').forEach((b) => b.classList.toggle('is-active', b === btn));
      render(btn.dataset.sort);
    });
  };

  setupForm();
  setupTerms();
  setupGrid();
  setupCands();
})();
