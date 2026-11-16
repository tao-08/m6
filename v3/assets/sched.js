/* =====================================================================
 *  assets/sched.js — スタ連の日程調整の動き
 * =====================================================================
 *  1. 作成フォーム（sched.php の [data-sched-form]）… 部員の行の追加・削除、バンドから入力、エリアのチェックでまとめて選ぶ
 *  2. 時間割の学期タブ（sched_me.php の [data-sched-terms]）
 *  3. NG の時間のマス（sched_me.php の #sched-grid-data）… タップ・なぞって塗る、日付を押して1日まるごと → JSON で送信
 *  4. 候補の一覧（sched_poll.php の #sched-data）… 料金を計算して並べ、仮決定・決定のボタンを付ける
 *  5. みんなの予定（sched_poll.php の [data-sched-over]）… 全員の埋まりを重ねた週ごとの表
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

    // ---- バンドから入力: タイトルと部員の行を、選んだバンドのものに置き換える ----
    const pick = form.querySelector('[data-band-pick]');
    const bands = readJson('sched-bands');
    if (pick && bands) {
      pick.addEventListener('change', () => {
        const band = bands.find((b) => String(b.id) === pick.value);
        if (!band) return; // 空の選択肢に戻したときは何もしない
        form.querySelector('[name="title"]').value = band.title; // .value で入れる（HTML として解釈されない）
        // 部員の行: 1行目を雛形に残して、ほかは捨てる → メンバーの数だけ複製して名前を入れる
        const tpl = rows.firstElementChild.cloneNode(true);
        rows.replaceChildren();
        (band.names.length ? band.names : ['']).forEach((name) => { // メンバーが0人でも、行が1つも無くならないように
          const row = tpl.cloneNode(true);
          row.querySelector('input').value = name;
          rows.append(row);
        });
        refresh(); // 最後の行が埋まっていれば空の行を1つ足し、人数の表示も更新する
        // 値を JS で変えても input イベントは出ないので、自分で出す（未保存の警告を効かせるため）
        form.dispatchEvent(new Event('input', { bubbles: true }));
      });
    }

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
    const kindLabel = { class: '授業', ical: '予定', weekly: '毎週', practice: '練習' };

    const table = el('table', 'sch-grid');
    const head = el('tr');
    head.append(el('th'));
    data.days.forEach((d) => {
      const th = el('th', d.dow === 0 ? 'is-sun' : d.dow === 6 ? 'is-sat' : '', d.md.replace(/\((.)\)/, '\n$1')); // 「11/21」改行「土」
      // 日付を押すと、その日を1日まるごと NG にする（操作ボタンなので data-tip は付けず、title と aria-label）
      th.dataset.day = d.date;
      th.title = '1日まるごと NG';
      th.setAttribute('aria-label', d.md + ' 1日まるごと NG'); // 読み上げで日付が消えないよう、日付も入れる
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
        td.dataset.day = d.date; // 日付の見出しから「その日のマス」を探すため
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
    // 日付の見出しを押す: その日の塗れるマスを全部塗る（もう全部塗られていたら全部消す）
    thead.addEventListener('click', (e) => {
      const th = e.target.closest('th[data-day]');
      if (!th) return;
      const day = cells.filter((td) => td.dataset.day === th.dataset.day);
      if (!day.length) return; // 塗れるマスが無い日（授業などで全部埋まっている）は何もしない
      mode = !day.every((td) => td.classList.contains('is-ng')); // 全部塗られている → 消すモード
      day.forEach(paint);
      mode = null;
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
  //     ・カード1枚 = 窓1つ（「人の条件が同じで、開始時刻が続いている枠」）。スタジオはカードの中に、料金の安い順に並ぶ
  //     ・開始時刻を選び直すと、取れるスタジオ・遅刻の分・料金を計算し直す
  //     ・[data-sched-list]（全員そろう候補）と [data-sched-list-absent]（1人欠席の候補）の両方に、同じ並べ替えが効く
  // ===================================================================
  const setupCands = () => {
    const data = readJson('sched-data');
    if (!data || !window.studioSimEngine) return;
    const lists = [
      { node: document.querySelector('[data-sched-list]'), windows: data.windows || [] },
      { node: document.querySelector('[data-sched-list-absent]'), windows: data.absent || [] },
    ].filter((l) => l.node);
    if (!lists.length) return;
    const engines = {}; // スタジオ id → 料金の計算部品（1回作って使い回す）
    const studios = Object.fromEntries(data.studios.map((s) => [s.id, s]));
    const names = data.names || {}; // member_id → 名前
    const SHOW = 3; // スタジオの行を最初に何行出すか（残りは「ほか n件」）

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
     * 部屋の「余り」= 定員（無ければ 広さ ÷ 1人あたりの帖）− 人数。0 に近いほど人数にぴったり
     *   狭すぎる（マイナス）・定員も広さも不明 → null（おすすめの対象外）
     */
    const gap = (room) => {
      const size = room.cap || (room.a ? room.a / data.jo : null);
      return size == null || size < data.people ? null : size - data.people;
    };

    /**
     * おすすめの部屋: 候補に出ている全スタジオの部屋を比べて、余りがいちばん小さい部屋（同点なら複数）
     *   スタジオごとではなく「候補全体で一番」なので、おすすめが付くのは一部のスタジオだけになる
     *   recommend = { スタジオ id: 部屋 }
     */
    const recommend = {};
    let minGap = Infinity;
    data.studios.forEach((st) => {
      (data.sim[st.id]?.rooms || []).forEach((r) => {
        const g = gap(r);
        if (g !== null && g < minGap) minGap = g;
      });
    });
    data.studios.forEach((st) => {
      // 小数（広さ ÷ 帖）の誤差で「同じ」を取りこぼさないよう、0.01 未満の差は同点にする
      const r = (data.sim[st.id]?.rooms || []).find((x) => gap(x) !== null && gap(x) - minGap < 0.01);
      if (r) recommend[st.id] = r;
    });

    /**
     * 1つのスタジオ・開始時刻での「いちばん安い部屋」（人数に対して狭すぎる部屋は除く）
     *   戻り値: { total, room, disc } / null（料金未登録）
     */
    const calcQuote = (sid, day, start) => {
      const sim = data.sim[sid];
      if (!sim) return null;
      engines[sid] ||= window.studioSimEngine(sim); // ||= … 無ければ作って入れる
      const { calc, dayOk } = engines[sid];
      const rooms = sim.rooms.length ? sim.rooms.filter((r) => fit(r) !== 'small') : [null];
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
      return top;
    };
    // 同じ（スタジオ・曜日区分・開始時刻）は何度も聞かれる（選び直すたび・並べ替えのたび）ので、1回計算したら覚えておく
    const quotes = new Map();
    const quote = (sid, day, start) => {
      const key = `${sid}:${day}:${start}`;
      if (!quotes.has(key)) quotes.set(key, calcQuote(sid, day, start));
      return quotes.get(key);
    };

    /** 欠席の人がいるときの添え書き（確認の文に使う） */
    const absentNote = (w) => (w.absent.length ? `（${names[w.absent[0]] || ''}さん欠席）` : '');

    /** 最初に選ぶ開始時刻: 連続（◎ > ○）がいちばん高いマスがある開始時刻。連続が無ければ先頭 */
    const firstStart = (w) => {
      let best = w.starts[0];
      let top = -1;
      w.starts.forEach((m) => {
        const level = Math.max(0, ...w.studios.map((s) => s.adj[m]?.level || 0));
        if (level > top) {
          top = level;
          best = m;
        }
      });
      return best;
    };

    /** 確定のボタン（POST のフォーム）。kind = date / time / final。getSel() は いま選んでいる {start, sid, room} */
    const fixForm = (kind, label, w, getSel, primary) => {
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
      const sidIn = add('studio', '');
      const startIn = add('start', '');
      const roomIn = add('room', '');
      const absentIn = add('absent', '');
      const b = el('button', 'btn btn--sm' + (primary ? ' btn--primary' : ' btn--ghost'), label);
      b.type = 'submit';
      f.append(b);
      // 送信の直前に、選んでいる開始時刻・スタジオ・部屋を入れ、確認の文（data-confirm → app.js の setupConfirm が出す）を作る
      //   このフォームでの submit は、document で待ち受けている app.js より先に呼ばれる（イベントは内側 → 外側へ伝わる）
      f.dataset.confirm = '';
      f.addEventListener('submit', () => {
        const sel = getSel();
        sidIn.value = sel.sid;
        startIn.value = sel.start;
        roomIn.value = sel.room || '';
        // 欠席は日時まで決めるとき（仮決定・決定）だけ有効（サーバーも日だけの仮決定では無視する）
        absentIn.value = kind === 'date' ? '' : w.absent[0] || '';
        const where = kind === 'date' ? '' : `${hm(sel.start)}〜 ${studios[sel.sid]?.name || ''} `;
        f.dataset.confirm = `${w.md} ${where}で${label}します${kind === 'date' ? '' : absentNote(w)}。部員に通知が届きます。よろしいですか？`;
      });
      return f;
    };

    let uid = 0; // ラジオボタンの name をカードごとに別にするための連番（同じ name だと、別のカードの選択が外れてしまう）

    /** 候補1件（窓1つ）のカード。戻り値: { node, price }（price は並べ替え用 = 最初の開始時刻での最安） */
    const card = (w) => {
      const id = ++uid;
      const c = el('article', 'card sch-cand' + (w.lateMembers.length || w.absent.length ? ' is-late' : ''));
      let start = firstStart(w); // いま選んでいる開始時刻
      let sid = null;            // いま選んでいるスタジオ
      let expanded = false;      // 「ほか n件」を開いたか
      let items = [];            // いまの開始時刻で取れるスタジオ [{ s, i, q }]（料金の安い順）

      // ---- 1行目: 日付・時間帯・ピル・開始時刻の選択 ----
      const top = el('div', 'sch-cand__top');
      top.append(el('strong', 'sch-cand__date', w.md));
      top.append(el('span', 'sch-cand__time', `${hm(w.from)}〜${hm(w.to)}`));
      const pills = el('span', 'sch-cand__pills');
      top.append(pills);
      /** 遅刻・欠席・仮予定の注意のピル。遅れの分は「いま選んでいる開始時刻・スタジオ」での値なので、選び直すたびに作り直す */
      const renderPills = () => {
        pills.replaceChildren();
        const cur = items.find((x) => x.s.id === sid);
        const late = cur?.s.late[start] || {};
        w.lateMembers.forEach((mid) => {
          if (late[mid]) pills.append(el('span', 'pill pill--warn', `${names[mid] || ''} +${late[mid]}分`));
        });
        w.absent.forEach((mid) => pills.append(el('span', 'pill pill--warn', `${names[mid] || ''} 欠席`)));
        w.warn.forEach((t) => {
          const p = el('span', 'pill', '⚠ ' + t);
          p.dataset.tip = 'この時間・日に仮決定の練習あり';
          pills.append(p);
        });
      };
      let sel = null;
      if (w.starts.length > 1) {
        sel = el('select', 'select-sm sch-cand__start');
        sel.setAttribute('aria-label', '開始時刻');
        w.starts.forEach((m) => {
          const o = el('option', '', `${hm(m)}〜${hm(m + data.len)}`);
          o.value = m;
          sel.append(o);
        });
        sel.value = start;
        sel.addEventListener('change', () => {
          start = Number(sel.value);
          renderStudios();
        });
        top.append(sel);
      }
      c.append(top);

      // ---- スタジオの一覧（ラジオボタンの行。料金の安い順）----
      const box = el('div', 'sch-srows');
      /** スタジオ1行 */
      const row = (x) => {
        const lab = el('label', 'sch-srow' + (x.s.id === sid ? ' is-on' : ''));
        const radio = el('input');
        radio.type = 'radio';
        radio.name = 'sch-c' + id;
        radio.value = x.s.id;
        radio.checked = x.s.id === sid;
        radio.addEventListener('change', () => {
          sid = x.s.id;
          box.querySelectorAll('.sch-srow').forEach((r) => r.classList.toggle('is-on', r === lab));
          renderPills();
        });
        const main = el('span', 'sch-srow__main');
        main.append(el('strong', 'sch-srow__name', studios[x.s.id]?.name || ''));
        if (x.q?.room) main.append(el('span', 'muted small', x.q.room.name));
        // おすすめ（人数にいちばん合う部屋）があるスタジオだけ。表示中の最安の部屋と違うなら部屋名も添える
        const rec = recommend[x.s.id];
        if (rec) {
          const tag = el('span', 'tag tag--plan', x.q?.room && x.q.room.id === rec.id ? 'おすすめ' : 'おすすめ ' + rec.name);
          tag.dataset.tip = `${data.people}人にいちばん合う部屋: ${rec.name}`;
          main.append(tag);
        }
        if (x.q?.disc) main.append(el('span', 'tag', x.q.disc));
        const adj = x.s.adj[start];
        if (adj) {
          const tag = el('span', 'tag ' + (adj.level === 2 ? 'tag--mine' : 'tag--plan'), adj.level === 2 ? '連続◎' : '連続○');
          tag.dataset.tip = '前後に同じスタジオの練習: ' + adj.with.join('・');
          main.append(tag);
        }
        const price = el('span', 'sch-srow__price');
        if (x.q) {
          price.append(el('strong', '', yen(x.q.total)));
          price.append(el('span', 'muted small', `1人 ${yen(Math.ceil(x.q.total / data.people))}`));
        } else {
          price.append(el('span', 'muted small', '料金未登録'));
        }
        lab.append(radio, main, price);
        return lab;
      };
      /** いまの開始時刻で取れるスタジオを、安い順（料金未登録は最後）に並べ直す。選んでいたスタジオが取れるならそのまま、無ければ先頭 */
      const renderStudios = () => {
        items = w.studios
          .filter((s) => s.starts.includes(start))
          .map((s, i) => ({ s, i, q: quote(s.id, w.day, start) }))
          .sort((a, b) => (a.q ? a.q.total : Infinity) - (b.q ? b.q.total : Infinity) || a.i - b.i); // Infinity 同士の引き算は NaN（偽）→ 元の順
        if (!items.some((x) => x.s.id === sid)) sid = items[0].s.id;
        if (items.findIndex((x) => x.s.id === sid) >= SHOW) expanded = true; // 選んでいる行を隠さない
        box.replaceChildren();
        items.forEach((x, i) => {
          if (i < SHOW || expanded) box.append(row(x));
        });
        if (!expanded && items.length > SHOW) {
          const more = el('button', 'btn btn--ghost btn--sm sch-more', `ほか ${items.length - SHOW}件`);
          more.type = 'button';
          more.addEventListener('click', () => {
            expanded = true;
            renderStudios();
          });
          box.append(more);
        }
        renderPills();
      };
      renderStudios();
      const price = items[0].q ? items[0].q.total : Infinity; // 「最初に選ばれた開始時刻での最安」（安い順の並べ替え用）
      c.append(box);

      // ---- 下段: 確定のボタン ----
      if (data.canFix) {
        const getSel = () => {
          const x = items.find((i) => i.s.id === sid);
          return { start, sid, room: x?.q?.room ? x.q.room.id : null };
        };
        const acts = el('div', 'sch-cand__acts');
        if (data.status === 0) acts.append(fixForm('date', '日だけ仮決定', w, getSel, false));
        acts.append(fixForm('time', '仮決定', w, getSel, false));
        acts.append(fixForm('final', '決定', w, getSel, true));
        c.append(acts);
      }
      return { node: c, price };
    };

    // ---- 並べ替え（2つの一覧に同じ並べ替えを効かせる）----
    const sorters = {
      best: (a, b) => a.i - b.i, // PHP の並び（全員そろう → 連続◎ → 連続○ → 遅れが少ない → 日付）
      date: (a, b) => a.w.date.localeCompare(b.w.date) || a.w.from - b.w.from || a.i - b.i,
      price: (a, b) => a.price - b.price || a.i - b.i,
    };
    lists.forEach((l) => {
      l.cards = l.windows.map((w, i) => ({ w, i, ...card(w) }));
    });
    // 一度に出すのは PAGE 枚まで（期間が長いと数十枚になり、スマホで下まで行けなくなるため）。残りは「さらに表示」で足す
    const PAGE = 10;
    let sortKey = 'best';
    const render = (key = sortKey) => {
      sortKey = key;
      lists.forEach((l) => {
        l.shown ||= PAGE;
        const sorted = [...l.cards].sort(sorters[key]);
        l.node.replaceChildren(...sorted.slice(0, l.shown).map((e) => e.node));
        if (sorted.length > l.shown) {
          const more = el('button', 'btn btn--ghost sch-more', `さらに表示（残り ${sorted.length - l.shown}）`);
          more.type = 'button';
          more.addEventListener('click', () => {
            l.shown += PAGE;
            render();
          });
          l.node.append(more);
        }
      });
    };
    render('best');
    document.querySelector('[data-sched-sort]')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-sort]');
      if (!btn) return;
      btn.parentElement.querySelectorAll('[data-sort]').forEach((b) => b.classList.toggle('is-active', b === btn));
      render(btn.dataset.sort);
    });
  };

  // ===================================================================
  //  5. みんなの予定（sched_poll.php の <details data-sched-over>）
  //     ・部員全員の埋まりを重ねて、30分ごとに「何人埋まっているか」を色で見せる（7日ずつ・前後の週ボタンで切り替え）
  //     ・マスに乗せる（スマホはタップ）と、誰がどの種類で埋まっているかを出す。種類は サーバー側で「授業・練習・予定」に丸めてある
  //     ・開閉は localStorage に覚える（開けないブラウザでも動くよう try/catch で囲む）
  // ===================================================================
  const setupOver = () => {
    const box = document.querySelector('[data-sched-over]');
    const boot = readJson('sched-data');
    const over = boot?.over;
    if (!box || !over) return;
    const names = boot.names || {};
    const ids = Object.keys(names);
    const wrap = box.querySelector('[data-sched-over-grid]');
    const prev = box.querySelector('[data-over-prev]');
    const next = box.querySelector('[data-over-next]');
    const range = box.querySelector('[data-over-range]');
    const weeks = []; // 7日ずつに区切る
    for (let i = 0; i < over.days.length; i += 7) weeks.push(over.days.slice(i, i + 7));
    let page = 0;

    const draw = () => {
      const days = weeks[page];
      const lo = days[0].abs;
      const hi = days[days.length - 1].abs + 1440;
      // この週にかかる埋まりだけに絞っておく（マスごとに全部を調べると重いので）
      const busy = ids.map((id) => [id, (over.busy[id] || []).filter((b) => b[0] < hi && b[1] > lo)]);
      const table = el('table', 'sch-grid sch-grid--over');
      const head = el('tr');
      head.append(el('th'));
      days.forEach((d) => head.append(el('th', d.dow === 0 ? 'is-sun' : d.dow === 6 ? 'is-sat' : '', d.md.replace(/\((.)\)/, '\n$1')))); // 「11/21」改行「土」
      const thead = el('thead');
      thead.append(head);
      table.append(thead);
      const tbody = el('tbody');
      for (let m = over.start; m < over.end; m += over.step) {
        const tr = el('tr', m % 60 === 0 ? 'is-hour' : '');
        tr.append(el('th', '', m % 60 === 0 ? hm(m) : ''));
        days.forEach((d) => {
          const abs = d.abs + m;
          // このマスにかかる埋まりを、人ごとに最初の1つだけ拾う（同じ人の埋まりが重なっていても1人と数える）
          const who = [];
          busy.forEach(([id, list]) => {
            const hit = list.find((b) => b[0] < abs + over.step && b[1] > abs);
            if (hit) who.push(`${names[id]}（${hit[2]}）`);
          });
          const td = el('td', who.length === 0 ? 'is-free' : who.length === 1 ? 'is-n1' : 'is-n2');
          if (who.length) td.dataset.tip = who.join('・'); // 属性に入れる（HTML としては解釈されない）
          tr.append(td);
        });
        tbody.append(tr);
      }
      table.append(tbody);
      wrap.replaceChildren(table);
      range.textContent = `${days[0].md}〜${days[days.length - 1].md}`;
      prev.disabled = page === 0;
      next.disabled = page === weeks.length - 1;
    };
    prev.addEventListener('click', () => {
      if (page > 0) page--;
      draw();
    });
    next.addEventListener('click', () => {
      if (page < weeks.length - 1) page++;
      draw();
    });
    draw();

    try {
      box.open = localStorage.getItem('sched-over-open') === '1';
    } catch (e) {
      // localStorage が使えない（プライベートモード等）→ 閉じたまま
    }
    box.addEventListener('toggle', () => {
      try {
        localStorage.setItem('sched-over-open', box.open ? '1' : '0');
      } catch (e) {
        // 覚えられなくても動作には困らない
      }
    });
  };

  setupForm();
  setupTerms();
  setupGrid();
  setupCands();
  setupOver();
})();
