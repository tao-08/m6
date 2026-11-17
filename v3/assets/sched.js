/* =====================================================================
 *  assets/sched.js — スタ連の日程調整の動き
 * =====================================================================
 *  1. 作成フォーム（sched.php の [data-sched-form]）… 部員の行の追加・削除、バンドから入力、エリアのチェックでまとめて選ぶ
 *  2. 時間割の学期タブ（sched_me.php の [data-sched-terms]）
 *  3. NG の時間のマス（sched_me.php の #sched-grid-data）… タップ・なぞって塗る、日付を押して1日まるごと → JSON で送信
 *  4. 候補の一覧（sched_poll.php の #sched-data）… 料金を計算して並べ、仮決定・決定のボタンを付ける
 *  5. メンバーの予定（sched_poll.php の [data-sched-over]）… 全員の埋まりを重ねた週ごとの表
 *  6. 画像から予定を読み込む（sched_me.php の [data-photo-open]）… スクリーンショットを AI に読ませて、確認してから追加
 *
 *  ■ XSS 対策
 *    DB から来た文字（バンド名・スタジオ名・部員名）は textContent で入れる（HTML として解釈されない）
 *  ■ IIFE（(() => { … })()）… 中の変数が他の JS とぶつからないように閉じ込める定番の書き方
 * ===================================================================== */
(() => {
  'use strict';

  /** 表の日付の見出しの色の class: 日曜・祝日（md に「祝」が入る）= is-sun（赤）/ 土曜 = is-sat（青） */
  const dayClass = (d) => (d.dow === 0 || d.md.includes('祝') ? 'is-sun' : d.dow === 6 ? 'is-sat' : '');

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

    // ---- ライブ: 選ぶと期間を「初日の N週間前〜最終日」にする（N は期間のタブ。カスタムなら手入力） ----
    // 何日目に出るかは練習を決める時点でわからないことが多いので、最終日まで候補に入れる
    const live = form.querySelector('[data-live-pick]');
    const leadBox = form.querySelector('[data-lead]');
    const dFrom = form.querySelector('[name="date_from"]');
    const dTo = form.querySelector('[name="date_to"]');
    const applyLead = () => {
      const opt = live.selectedOptions[0];
      const first = opt?.dataset.first || ''; // 初日（Y-m-d）。「なし」・日程が未登録なら空
      const last = opt?.dataset.last || '';   // 最終日
      const weeks = Number(leadBox.querySelector('input:checked')?.value || 0); // 0 = カスタム
      leadBox.hidden = !first;
      const lock = first !== '' && weeks > 0;
      dFrom.readOnly = dTo.readOnly = lock; // 週を選んでいる間は手で変えさせない（readonly でも値は送信される）
      if (!lock) return; // 「なし」「カスタム」は日付をそのまま残す
      // Date.UTC で計算する: ローカル時刻の Date だと、toISOString（UTC）にしたとき日本時間では前日にずれる
      const [y, m, d] = first.split('-').map(Number);
      const from = new Date(Date.UTC(y, m - 1, d - 7 * weeks)).toISOString().slice(0, 10);
      const today = live.dataset.today; // 「今日」はサーバーの日付（端末の時計がずれていても合う）
      // 開始日は今日より前にしない（Y-m-d の文字列は、大小の比較がそのまま日付の前後になる）。PHP の $leadFrom と同じ計算
      dFrom.value = from < today && today <= last ? today : from;
      dTo.value = last;
      form.dispatchEvent(new Event('input', { bubbles: true })); // 未保存の警告を効かせる
    };
    // ライブかタブを変えたときだけ書き換える（開いたときに書き換えると、編集画面で保存済みの期間が消える）
    if (live && leadBox && dFrom && dTo) {
      live.addEventListener('change', applyLead);
      leadBox.addEventListener('change', applyLead);
    }

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
        // バンドの出るライブも選ぶ。change を出す → 期間の自動入力（applyLead）と、プルダウンの表示（app.js の setupSelectPick）が動く
        if (live && band.liveId) {
          live.value = String(band.liveId);
          live.dispatchEvent(new Event('change', { bubbles: true }));
        }
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

    setupTrip(form, rows);
  };

  /**
   * スタジオの目安: 自宅の最寄り駅 → スタジオ の「往復の交通費（1人平均）」と「片道の所要時間 最短〜最長」
   *   部員の名前を変えたら、覚えてある結果だけで出し直す（fetch = 0。API は呼ばない）
   *   「交通費・所要時間を調べる」で API を呼ぶ（fetch = 1）
   *   所要時間の差（最長 − 最短）がいちばん小さいスタジオに「差最小」を付ける（2人以上分かるスタジオの中で）
   */
  const setupTrip = (form, rows) => {
    const spans = [...form.querySelectorAll('[data-trip]')];
    if (!spans.length) return;
    const btn = form.querySelector('[data-trip-fetch]');
    const status = form.querySelector('[data-trip-status]');
    const csrf = form.querySelector('[name="csrf"]').value;
    // 目安はチェックの <label> の中にある → タップで説明を出すとき、チェックが切り替わらないようにする
    spans.forEach((s) => s.addEventListener('click', (e) => e.preventDefault()));

    const show = (res) => {
      const st = res.studios || {};
      const ok = Object.values(st).filter((s) => s.known >= 2).map((s) => s.hi - s.lo);
      const best = ok.length ? Math.min(...ok) : null;
      spans.forEach((span) => {
        const s = st[span.dataset.trip];
        span.replaceChildren();
        span.hidden = !s || (s.fare === null && s.lo === null);
        if (span.hidden) return;
        const part = (iconName, text) => {
          const p = el('span', 'sch-trip__part');
          const ic = el('span', 'icon icon--sm', iconName);
          ic.setAttribute('aria-hidden', 'true');
          p.append(ic, text);
          span.append(p);
        };
        if (s.fare !== null) part('train', yen(s.fare) + '/人');
        if (s.lo !== null) part('schedule', s.lo === s.hi ? `${s.lo}分` : `${s.lo}〜${s.hi}分`);
        if (best !== null && s.known >= 2 && s.hi - s.lo === best) span.append(el('span', 'tag tag--plan', '差最小'));
        span.dataset.tip = `往復の交通費（1人平均）・片道の所要時間。${s.total}人中${s.known}人分` + (s.known < s.total ? '（駅が未登録・未調査の人は除く）' : '');
      });
    };

    let seq = 0; // 古い返事が後から届いても、新しい表示を上書きしないように
    const load = (fetchApi) => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'trip_stats');
      fd.append('fetch', fetchApi ? '1' : '0');
      rows.querySelectorAll('input[name="m_name[]"]').forEach((i) => { if (i.value.trim()) fd.append('m_name[]', i.value.trim()); });
      const my = ++seq;
      return fetch(location.pathname, { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((res) => {
          if (my !== seq) return res;
          show(res);
          return res;
        });
    };

    let timer = 0;
    rows.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(false).catch(() => {}), 600); });
    rows.addEventListener('click', (e) => { if (e.target.closest('[data-remove-member]')) load(false).catch(() => {}); });
    form.querySelector('[data-band-pick]')?.addEventListener('change', () => load(false).catch(() => {}));
    load(false).catch(() => {});

    if (!btn) return;
    btn.addEventListener('click', () => {
      if (!confirm('部員の自宅の最寄り駅から、全スタジオまでの交通費と所要時間を検索します。')) return;
      btn.disabled = true;
      status.textContent = '調査中…';
      load(true)
        .then((res) => { status.textContent = res.error || ''; })
        .catch(() => { status.textContent = '調べられませんでした。もう一度押してください'; })
        .finally(() => { btn.disabled = false; });
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
  // 自動保存（約1秒まとめて fetch で送る。X-Autosave を付けると sched_me.php は画面を移動せず 204 を返す）
  //   ページを離れる前・週送りのリンクを押したときは、待たずに今すぐ送る（keepalive = ページ移動後も送り切る）
  const makeAutosave = (getBody) => {
    const a = { build: getBody, timer: 0, pending: false };
    a.flush = () => {
      clearTimeout(a.timer);
      if (!a.pending) return Promise.resolve();
      a.pending = false;
      return fetch(location.pathname + location.search, { method: 'POST', body: a.build(), headers: { 'X-Autosave': '1' }, keepalive: true })
        .then((r) => { if (!r.ok) throw new Error(); })
        .catch(() => { alert('保存できませんでした'); });
    };
    a.schedule = () => { a.pending = true; clearTimeout(a.timer); a.timer = setTimeout(a.flush, 1000); };
    window.addEventListener('pagehide', a.flush);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') a.flush(); });
    // 週送りなどの同じ画面内のリンク: 送ってから移動
    document.addEventListener('click', (e) => {
      const link = e.target.closest('a[href]');
      if (!link || !a.pending || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || link.target) return;
      e.preventDefault();
      a.flush().then(() => { location.href = link.href; });
    });
    return a;
  };

  const setupGrid = () => {
    const data = readJson('sched-grid-data');
    const wrap = document.querySelector('[data-sched-grid]');
    const form = document.querySelector('[data-sched-grid-form]');
    if (!data || !wrap || !form) return;
    const autosave = makeAutosave(() => new FormData(form));
    const kindLabel = { class: '授業', ical: '予定', photo: '予定', weekly: '繰り返し', practice: '練習' };

    // マスの見た目を、埋まり（data.busy）から決める。自動の予定があれば塗れない（is-auto）。
    //   「この予定を無視」にした予定（b[4]）は、予定ありに数えず網掛け（is-ignored）。タップすると無視を切り替えられる
    const ignorable = ['class', 'ical', 'photo', 'weekly'];
    // 終日の予定 = 取り込んだ予定のうち、日の始まり（0:00）から日の終わりまでぴったり埋めるもの。
    //   表のマスには塗らず、日付の下に予定名だけ出す（練習の候補の計算では、今まで通り1日予定あり）
    const day0 = data.days[0]?.abs ?? 0;
    const isAllDay = (b) => (b[2] === 'ical' || b[2] === 'photo') && b[1] > b[0] && (b[0] - day0) % 1440 === 0 && (b[1] - b[0]) % 1440 === 0;
    const classify = (td) => {
      const abs = Number(td.dataset.abs);
      const first = abs === Number(td.dataset.first); // その日の表示の先頭のマス
      const hit = data.busy.filter((b) => !isAllDay(b) && b[0] < abs + data.step && b[1] > abs);
      // 取り込んだ予定の前後の枠（kind 'buffer'）は、授業などとは別に扱う（予定ありの枠: 手動と同じ見た目で、タップで消せる）
      const frame = hit.find((b) => b[2] === 'buffer' && !b[4]);
      const frameOff = hit.find((b) => b[2] === 'buffer' && b[4]); // タップで消した枠
      const rest = hit.filter((b) => b[2] !== 'buffer');
      const auto = rest.find((b) => b[2] !== 'manual' && !b[4]);
      const ign = rest.find((b) => b[4]);
      const item = ign || auto;
      td.classList.remove('is-class', 'is-ical', 'is-photo', 'is-weekly', 'is-practice', 'is-auto', 'is-ignored', 'is-ng', 'is-buf', 'is-bufoff');
      td.textContent = '';
      td._item = null;
      td._buf = null;
      if (item) {
        td.classList.add('is-' + item[2], 'is-auto');
        if (item[4]) td.classList.add('is-ignored');
        if (ignorable.includes(item[2])) td._item = item;
        // 予定の最初のマスにだけラベル（授業の「2限」・練習のバンド名）
        //   ラベルは予定の高さ（マスの数）いっぱいまで折り返す。2行を超える分は「…」（CSS: .sch-lbl）
        if (item[0] >= abs && item[0] < abs + data.step || first) {
          const dayEnd = Number(td.dataset.first) - data.start + data.end; // その日の表示の終わり
          const rows = Math.max(1, Math.ceil((Math.min(item[1], dayEnd) - abs) / data.step));
          const lbl = el('span', 'sch-lbl', item[3] || kindLabel[item[2]]);
          lbl.style.setProperty('--rows', rows);
          lbl.style.setProperty('--lines', Math.min(2, rows));
          td.append(lbl);
        }
      } else if (rest.length || frame) {
        td.classList.add('is-ng');
      }
      if (!item) {
        if (frame) {
          td._buf = frame;
          td.classList.add('is-buf');
        } else if (frameOff && !rest.length) {
          td._buf = frameOff; // 消した枠: 薄い点線。もう一度タップすると戻る
          td.classList.add('is-bufoff');
        }
      }
      // 仮決定（時間まで決めたもの）: 練習の色を少し透かして重ねる。最初のマスに題名（日だけの仮決定は、日付の下に出している）
      //   classify は毎回マスを作り直す（textContent = ''）ので、題名もここで入れる（別の所で入れると、作り直しで消える）
      const prov = data.warn.find((w) => w.date === td.dataset.day && w.s !== null && w.s < abs + data.step && w.e > abs);
      td.classList.toggle('is-prov', !!prov);
      if (prov && !td.querySelector('.sch-lbl') && (prov.s >= abs || first)) {
        const dayEnd = Number(td.dataset.first) - data.start + data.end;
        const rows = Math.max(1, Math.ceil((Math.min(prov.e, dayEnd) - abs) / data.step));
        const lbl = el('span', 'sch-lbl sch-lbl--prov', prov.title); // 仮決定の箱（四方 PROV_INSET 縮めてある）の中に収める
        lbl.style.setProperty('--rows', rows);
        lbl.style.setProperty('--lines', Math.min(2, rows));
        td.append(lbl);
      }
    };

    const table = el('table', 'sch-grid');
    const head = el('tr');
    head.append(el('th'));
    data.days.forEach((d) => {
      const th = el('th', (dayClass(d) + (d.off ? ' is-off' : '')).trim(), d.md.replace(/\((.+?)\)/, '\n$1')); // 「11/21」改行「土」（祝日は「月祝」）。is-off = 調整の期間の外（グレー・塗れない）
      // 日付を押すと、その日を1日まるごと NG にする（操作ボタンなので data-tip は付けず、title と aria-label）
      th.dataset.day = d.date;
      th.append(el('span', 'sch-daychk')); // 押すと1日まるごと、の印（全部塗られていたら塗りつぶし）
      th.title = '1日まるごと予定あり';
      th.setAttribute('aria-label', d.md + ' 1日まるごと予定あり'); // 読み上げで日付が消えないよう、日付も入れる
      // その日にかかる終日の予定（何日も続く予定は、その日数ぶんの見出しに出る）。予定ありには数えない（lib/sched.php）ので、予定名を見せるだけ
      //   押すと予定名の全文をポップアップ（data-tip = app.js の setupTip。押しても何も実行されないラベルなので data-tip）
      data.busy.filter((b) => isAllDay(b) && b[0] < d.abs + 1440 && b[1] > d.abs).forEach((b) => {
        const name = b[3] || kindLabel[b[2]];
        const chip = el('span', 'sch-allday', name);
        chip.dataset.tip = name;
        chip.title = ''; // 見出し（th）の title「1日まるごと予定あり」を、予定名の上では出さない
        th.append(chip);
      });
      // 日だけ仮決定: 終日の予定と同じように、日付の下に題名（時間は未定なのでマスには塗らない）
      data.warn.filter((w) => w.date === d.date && w.s === null).forEach((w) => {
        const chip = el('span', 'sch-allday sch-allday--prov', w.title);
        chip.dataset.tip = '仮決定: ' + w.title;
        chip.title = '';
        th.append(chip);
      });
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
        td.dataset.first = d.abs + data.start; // その日の先頭のマス（ラベルを出す）
        td.dataset.day = d.date; // 日付の見出しから「その日のマス」を探すため
        // このマスにかかる埋まり（自動のもの優先。手入力は塗れる）
        classify(td);
        if (d.off) td.classList.add('is-off'); // 調整の期間の外: グレーにして塗れない（cells に入れない）
        else if (!td.classList.contains('is-auto')) cells.push(td);
        tr.append(td);
      });
      tbody.append(tr);
    }
    table.append(tbody);
    wrap.append(table);

    // 仮決定（時間まで決めたもの）の枠: マスより一回り小さく、角を丸めた箱を、表の上に重ねる（続くマスで1つ。押しても下のマスが反応する）
    const drawProv = () => {
      wrap.querySelectorAll('.sch-prov').forEach((n) => n.remove());
      const rows = tbody.rows;
      const w = wrap.getBoundingClientRect();
      data.warn.filter((x) => x.s !== null).forEach((x) => {
        const ci = data.days.findIndex((d) => d.date === x.date);
        if (ci < 0) return;
        const r0 = Math.max(0, Math.floor((x.s - data.days[ci].abs - data.start) / data.step));
        const r1 = Math.min(rows.length, Math.ceil((x.e - data.days[ci].abs - data.start) / data.step)) - 1;
        const first = rows[r0]?.cells[ci + 1];
        const last = rows[r1]?.cells[ci + 1];
        if (!first || !last) return;
        const a = first.getBoundingClientRect();
        const z = last.getBoundingClientRect();
        const box = el('div', 'sch-prov');
        box.style.left = `${a.left - w.left + wrap.scrollLeft + PROV_INSET}px`;
        box.style.top = `${a.top - w.top + wrap.scrollTop + PROV_INSET}px`;
        box.style.width = `${a.width - PROV_INSET * 2}px`;
        box.style.height = `${z.bottom - a.top - PROV_INSET * 2}px`;
        wrap.append(box);
      });
    };
    drawProv();
    window.addEventListener('resize', drawProv);

    // 日付の印: その日の塗れるマスが全部 NG なら塗りつぶし
    const syncDays = () => {
      thead.querySelectorAll('th[data-day]').forEach((th) => {
        const day = cells.filter((td) => td.dataset.day === th.dataset.day);
        th.classList.toggle('is-all', day.length > 0 && day.every((td) => td.classList.contains('is-ng')));
        th.classList.toggle('is-none', day.length === 0); // 塗れるマスが無い日（授業などで全部埋まっている）
      });
    };
    syncDays();
    // ---- 自動で入った予定をタップ → 「この予定を無視」のチェックボックス（その1回分だけ）----
    let pop = null;
    const closePop = () => { pop?.remove(); pop = null; };
    const openPop = (td) => {
      closePop();
      const item = td._item;
      pop = el('div', 'sch-pop');
      const head = el('div', 'sch-pop__head'); // 1行目: 予定名（画像の予定は右に削除ボタン）。2行目: 「この予定を無視」
      head.append(el('span', 'sch-pop__name', item[3] || kindLabel[item[2]]));
      const ignore = el('label', 'sch-pop__ignore');
      const cb = el('input');
      cb.type = 'checkbox';
      cb.checked = !!item[4];
      cb.addEventListener('change', () => {
        const body = new FormData(form);
        body.set('action', 'ignore');
        body.set('s', item[0]);
        body.set('e', item[1]);
        body.set('on', cb.checked ? '1' : '0');
        const was = !cb.checked;
        item[4] = cb.checked;
        classifyAll();
        fetch(location.pathname + location.search, { method: 'POST', body, headers: { 'X-Autosave': '1' } })
          .then((r) => { if (!r.ok) throw new Error(); })
          .catch(() => { alert('保存できませんでした'); item[4] = was; cb.checked = was; classifyAll(); });
      });
      ignore.append(cb, el('span', '', 'この予定を無視'));
      pop.append(head, ignore);
      // 画像から読み込んだ予定だけは、本当に削除もできる（読み間違いを残さないため）。操作ボタンなので data-tip は付けない
      if (item[2] === 'photo') {
        const del = el('button', 'icon-btn icon-btn--sm');
        del.type = 'button';
        del.title = '削除';
        del.setAttribute('aria-label', '削除');
        const icon = el('span', 'icon', 'delete');
        icon.setAttribute('aria-hidden', 'true');
        del.append(icon);
        del.addEventListener('click', () => {
          if (!confirm('この予定を削除します。よろしいですか？')) return;
          const body = new FormData(form);
          body.set('action', 'photo_delete');
          body.set('s', item[0]);
          body.set('e', item[1]);
          del.disabled = true;
          autosave.flush() // 塗りかけの手入力を先に保存してから読み直す
            .then(() => fetch(location.pathname + location.search, { method: 'POST', body, headers: { 'X-Autosave': '1' } }))
            .then((r) => { if (!r.ok) throw new Error(); location.reload(); })
            .catch(() => { alert('削除できませんでした'); del.disabled = false; });
        });
        head.append(del);
      }
      document.body.append(pop);
      const r = td.getBoundingClientRect();
      const pw = pop.offsetWidth;
      pop.style.left = Math.max(8, Math.min(window.innerWidth - pw - 8, r.left + r.width / 2 - pw / 2)) + 'px';
      pop.style.top = Math.min(window.innerHeight - pop.offsetHeight - 8, r.bottom + 4) + 'px';
    };
    const classifyAll = () => {
      table.querySelectorAll('tbody td').forEach(classify);
      syncDays();
    };
    classifyAll();
    // 前後の枠をタップ: 消す（消した枠をタップすると戻る）。「この予定を無視」と同じ印（sched_ignore）で覚える。予定が動くと枠の時間も変わるので、消した印は外れて枠が付き直す
    const toggleFrame = (item) => {
      const body = new FormData(form);
      body.set('action', 'ignore');
      body.set('s', item[0]);
      body.set('e', item[1]);
      const was = !!item[4];
      item[4] = !was;
      body.set('on', item[4] ? '1' : '0');
      classifyAll();
      fetch(location.pathname + location.search, { method: 'POST', body, headers: { 'X-Autosave': '1' } })
        .then((r) => { if (!r.ok) throw new Error(); })
        .catch(() => { alert('保存できませんでした'); item[4] = was; classifyAll(); });
    };
    table.addEventListener('click', (e) => {
      const td = e.target.closest('td');
      if (td?._buf) {
        e.stopPropagation();
        closePop();
        toggleFrame(td._buf);
        return;
      }
      if (!td?._item) return;
      e.stopPropagation();
      // ポップアップが出ているときに予定を押したら、開き直さず消す（もう一度押せば開く）
      if (pop) closePop();
      else openPop(td);
    });
    document.addEventListener('pointerdown', (e) => { if (pop && !pop.contains(e.target) && !e.target.closest('td.is-auto')) closePop(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePop(); });
    window.addEventListener('scroll', closePop, true);

    let mode = null; // true = 塗る / false = 消す / null = なぞっていない
    const paint = (td) => {
      if (!td || !td.dataset.abs || td.classList.contains('is-auto') || td.classList.contains('is-off') || td._buf) return;
      if (td.classList.contains('is-ng') !== mode) {
        td.classList.toggle('is-ng', mode);
        autosave.schedule();
        syncDays();
      }
    };
    table.addEventListener('pointerdown', (e) => {
      const td = e.target.closest('td');
      if (!td || td.classList.contains('is-auto') || td.classList.contains('is-off') || td._buf) return; // 前後の枠は、なぞって塗らない（タップで消す）
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
      if (!th || e.target.closest('.sch-allday')) return; // 終日の予定名を押したときは、1日まるごと塗らない（予定名のポップアップだけ）
      const day = cells.filter((td) => td.dataset.day === th.dataset.day);
      if (!day.length) return; // 塗れるマスが無い日（授業などで全部埋まっている）は何もしない
      mode = !day.every((td) => td.classList.contains('is-ng')); // 全部塗られている → 消すモード
      day.forEach(paint);
      mode = null;
    });
    const end = () => { mode = null; };
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);

    // 自動保存: 塗られているマスの「絶対分」を JSON にして送る（なぞり終わって約1秒後。ページを離れる前にも送る）
    autosave.build = () => {
      const payload = {
        days: data.days.filter((d) => !d.off).map((d) => d.date), start: data.start, end: data.end, // 期間の外の日は送らない（そこの保存済みの予定を消さない）
        cells: cells.filter((td) => td.classList.contains('is-ng') && !td._buf).map((td) => Number(td.dataset.abs)),
      };
      form.querySelector('input[name="payload"]').value = JSON.stringify(payload);
      return new FormData(form);
    };
  };

  // ===================================================================
  //  4. 候補の一覧
  //     ・カード1枚 = 窓1つ（「人の条件が同じで、開始時刻が続いている枠」）。スタジオはカードの中に、料金の安い順に並ぶ
  //     ・カードは日付ごとに <details>（1日 = 1つ）にまとめる。最初は閉じていて、見出しの1行（日付・時間帯・印・最安の料金）だけが並ぶ
  //       見出しは、その日のカードを集めて作る（日が1つしか無い一覧だけ、最初から開く）。中のカードは常に PHP の順（i の昇順）
  //     ・開始時刻を選び直すと、取れるスタジオ・遅刻の分・料金を計算し直す
  //     ・スタジオの行には部屋のプルダウン（広さ・料金つき）が付く。最初は「人数に狭すぎない部屋のうち最安」（無ければ狭い部屋の最安）
  //       選び直すと、その行の料金・タグだけを書き換える（行は並べ替えない = 選んだ行が飛んで見失わないように）
  //     ・[data-sched-list]（全員そろう候補）と [data-sched-list-absent]（1人欠席の候補）の両方に、同じ並べ替えが効く
  //       並べ替えは「日の並び」に効く（日付順・おすすめ順・安い順）。<details> は作り直さず並べ直すだけなので、開いた日は開いたまま
  //     ・日の行は多くても1か月ぶん（31行）なので、「さらに表示」のような分割はしない
  // ===================================================================
  const PROV_INSET = 4; // 仮決定の箱を、マスの四方から内側へ縮める量（px）
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
     * スタジオ情報のモーダル（候補カードのスタジオ名を押すと開く）
     *   中身は studio_info.php?embed（見るだけの表示）を <iframe> で出す。<dialog> は最初に開くときに1つだけ作って使い回す
     *   見出しの ↗ は、スタジオ情報のページを別のタブで開く
     */
    let studioDlg = null;
    const openStudio = (sid) => {
      if (!studioDlg) {
        studioDlg = el('dialog', 'modal sch-studio');
        const head = el('div', 'sch-studio__head');
        const title = el('strong', 'sch-studio__title');
        const open = el('a', 'sch-studio__btn');
        open.target = '_blank';
        open.rel = 'noopener';
        open.title = 'ページを開く';
        open.setAttribute('aria-label', 'ページを開く');
        open.append(el('span', 'icon', 'open_in_new'));
        const close = el('button', 'sch-studio__btn');
        close.type = 'button';
        close.title = '閉じる';
        close.setAttribute('aria-label', '閉じる');
        close.append(el('span', 'icon', 'close'));
        close.addEventListener('click', () => studioDlg.close());
        head.append(title, open, close);
        const frame = el('iframe', 'sch-studio__frame');
        studioDlg.append(head, frame);
        // 外側（背景）を押しても閉じる。<dialog> 自体が押された = 中身の外側
        studioDlg.addEventListener('click', (e) => { if (e.target === studioDlg) studioDlg.close(); });
        document.body.append(studioDlg);
      }
      const url = 'studio_info?id=' + encodeURIComponent(sid);
      studioDlg.querySelector('.sch-studio__title').textContent = studios[sid]?.name || '';
      studioDlg.querySelector('.sch-studio__btn').href = url;
      const frame = studioDlg.querySelector('.sch-studio__frame');
      frame.title = studios[sid]?.name || 'スタジオ情報';
      if (frame.dataset.sid !== String(sid)) { // 同じスタジオなら読み直さない（スクロール位置・試算の入力が残る）
        frame.dataset.sid = sid;
        frame.src = url + '&embed';
      }
      studioDlg.showModal();
    };

    /**
     * 部屋が人数に合うか（PHP の sched_room_fit と同じ判定）
     *   'ok' / 'small' / 'unknown'（広さが不明）
     */
    const fit = (room) => {
      if (room.a) return room.a / data.jo >= data.people ? 'ok' : 'small';
      return 'unknown';
    };

    /**
     * 部屋の「余り」= 広さ ÷ 1人あたりの帖 − 人数。0 に近いほど人数にぴったり
     *   狭すぎる（マイナス）・広さが不明 → null（おすすめの対象外）
     */
    const gap = (room) => {
      const size = room.a ? room.a / data.jo : null;
      return size == null || size < data.people ? null : size - data.people;
    };

    /**
     * おすすめの部屋の条件（カードごと・選んでいる開始時刻ごとに決まる）
     *   1. 人数にぴったりの部屋を「ぴったり圏」とする = 余りが、候補に出ている全スタジオの部屋のいちばん小さい余り + 1人以内
     *   2. その開始時刻で借りられるぴったり圏の部屋のうち、料金がいちばん安い部屋（同じなら余りが小さい方。それも同じ = 同じ料金・同じ広さなら、
     *      いま選んでいる部屋を優先する。選んでいる部屋があるのに別の同条件の部屋がおすすめされるのを防ぐ）
     *   余りが出せない部屋（狭すぎる・広さが不明）・料金が出せない部屋は対象外
     *   → カードに1つだけ付く（そのスタジオの行に出る）
     */
    let minGap = Infinity;
    data.studios.forEach((st) => {
      (data.sim[st.id]?.rooms || []).forEach((r) => {
        const g = gap(r);
        if (g !== null && g < minGap) minGap = g;
      });
    });
    const GAP_RANGE = 1; // ぴったり圏の幅（人）
    /** items（その開始時刻で取れるスタジオ [{ s, opts, q }]）から、おすすめの { sid, room } を選ぶ。curSid = いま選んでいるスタジオ。無ければ null */
    const pickRecommend = (items, curSid) => {
      let best = null;
      items.forEach(({ s, opts, q }) => {
        opts.forEach((o) => {
          const g = o.room ? gap(o.room) : null;
          if (g === null || g - minGap > GAP_RANGE + 0.01) return; // 小数の誤差を見込む
          // 同点のときの優先: 選んでいる部屋（2）・選んでいるスタジオ（1）
          const pri = (q?.room && q.room.id === o.room.id ? 2 : 0) + (s.id === curSid ? 1 : 0);
          const c = { sid: s.id, room: o.room, total: o.rank ?? o.total, g, pri };
          if (!best || c.total < best.total || (c.total === best.total && (c.g < best.g - 0.01 || (Math.abs(c.g - best.g) < 0.01 && c.pri > best.pri)))) best = c;
        });
      });
      return best;
    };

    /**
     * その部屋をこの開始時刻から借りられるか（スタジオ情報の料金計算 = studio_info.js の applyRoomRule と同じ決まり）
     *   0分開始（zs）なら :00、30分開始（hs）なら :30 から借りられる（両方なら どちらでも）。部屋の無いスタジオ（room = null）は制限なし
     *   zs が無い古いデータは「30分開始でなければ 0分開始」とみなす
     */
    const startOk = (room, m) => !room || (m % 60 === 30 ? !!room.hs : m % 60 === 0 && !!(room.zs ?? !room.hs));
    /** スタジオをこの開始時刻で借りられるか（借りられる部屋が1つでもあるか。部屋が未登録なら制限なし） */
    const usable = (sid, m) => {
      const rooms = data.sim[sid]?.rooms || [];
      return !rooms.length || rooms.some((r) => startOk(r, m));
    };
    /**
     * 候補（窓）から、借りられない開始時刻を除く（PHP は部屋の開始の決まりを見ていないので、ここで絞る）
     *   スタジオごとの開始時刻を絞り → どのスタジオも借りられない時刻を窓から除き → 時間帯（from / to）を合わせ直す
     *   1つも残らなければ null（その候補は出さない）
     */
    const trimWindow = (w) => {
      const studios = w.studios
        .map((s) => ({ ...s, starts: s.starts.filter((m) => usable(s.id, m)) }))
        .filter((s) => s.starts.length);
      const starts = w.starts.filter((m) => studios.some((s) => s.starts.includes(m)));
      if (!starts.length) return null;
      return { ...w, studios, starts, from: starts[0], to: starts[starts.length - 1] + data.len };
    };

    /**
     * 1つのスタジオ・開始時刻で、料金が出せる部屋ぜんぶの料金（人数に対して狭すぎる部屋も含める。選ぶのは利用者なので）
     *   部屋ごとに「割引のうち最安」を出す。料金表に無い時間がある部屋は入れない
     *   戻り値: [{ room, total, disc, small }]（料金の安い順。同額は元の順 = sort は同じ値なら順番を変えない）
     *     small = 人数に対して狭すぎる部屋か / 部屋が未登録のスタジオは room = null の1件だけ
     */
    const calcRoomQuotes = (sid, day, start) => {
      const sim = data.sim[sid];
      if (!sim) return [];
      engines[sid] ||= window.studioSimEngine(sim); // ||= … 無ければ作って入れる
      const { calc, dayOk } = engines[sid];
      const rooms = sim.rooms.length ? sim.rooms : [null];
      const discs = [{ id: 0, name: '' }, ...sim.discounts.filter((d) => dayOk(d.day, day))];
      const list = [];
      rooms.forEach((room) => {
        if (!startOk(room, start)) return; // この時刻からは借りられない部屋（30分始まり / 毎時ちょうど始まり）
        // 1時間単位の部屋で練習が 1時間半などのときは、1時間単位に切り上げて借りる（2時間ぶんの料金）
        const len = room && !room.hu ? Math.ceil(data.len / 60) * 60 : data.len;
        const v = { ppl: 0, room: room ? room.id : 0, day, start, rentals: [] };
        let top = null;
        discs.forEach((d) => {
          const r = calc(v, d.id, len);
          if (r.missing || (!r.pack && !r.normal.segs.length)) return; // 料金表に無い時間がある → 比べない
          // pack = 使ったパック料金の名前（通常料金より安いとき。無ければ ''）
          if (!top || r.total < top.total) top = { room, total: r.total, disc: d.name, pack: r.pack ? r.pack.name : '', small: room ? fit(room) === 'small' : false };
        });
        if (top) list.push(top);
      });
      return list.sort((a, b) => a.total - b.total);
    };
    // 同じ（スタジオ・曜日区分・開始時刻）は何度も聞かれる（選び直すたび・並べ替えのたび）ので、1回計算したら覚えておく
    //   覚えた配列は使い回すので、中身を書き換えない（選び直しは「どの要素を選ぶか」だけ変える）
    const quotes = new Map();
    const roomQuotes = (sid, day, start) => {
      const key = `${sid}:${day}:${start}`;
      if (!quotes.has(key)) quotes.set(key, calcRoomQuotes(sid, day, start));
      return quotes.get(key);
    };
    /**
     * 交通費（全員の定期外の運賃の合計）。窓 w・スタジオ sid・開始 m ごとに PHP が持たせてくる（{ sum, miss }。miss = 不明の片道の数）
     *   だれも自宅の駅を登録していなければ null（交通費は順位にも表示にも使わない）
     */
    const fareAt = (w, sid, m) => {
      const f = w.studios.find((s) => s.id === sid)?.fare?.[m];
      return f && f.miss < data.people * 2 ? f : null;
    };
    /** 料金の一覧に、順位用の rank（= 部屋の料金 + 交通費）を足したコピーを返す */
    const withRank = (opts, w, sid, m) => {
      const add = fareAt(w, sid, m)?.sum || 0;
      return opts.map((o) => ({ ...o, rank: o.total + add }));
    };
    /** 最初に選ぶ部屋: 狭すぎない部屋の中で最安。1つも無ければ狭い部屋の中で最安（opts は安い順なので先頭から探す）。無ければ null */
    const firstPick = (opts) => opts.find((o) => !o.small) || opts[0] || null;
    /** 部屋の表示: "Fst 12帖"（広さが不明なら "Fst"）。room.sz は PHP の studio_fmt_jo の結果（"12帖" / ""）なので、PHP 側と見た目が必ず同じになる */
    const roomText = (room) => room.name + (room.sz ? ' ' + room.sz : '');
    const perHead = (total) => yen(Math.ceil(total / data.people));
    /** プルダウンの選択肢の文字: "⚠ Fst 12帖 ¥1,215/人"（合計は出さない。長いと、スマホで行からはみ出すため） */
    const optionText = (o) => `${o.small ? '⚠ ' : ''}${roomText(o.room)} ${perHead(o.total)}/人`;

    /** 欠席の人がいるときの添え書き（確認の文に使う） */
    const absentNote = (w) => (w.absent.length ? `（${names[w.absent[0]] || ''}さん欠席）` : '');

    /** 最初に選ぶ開始時刻: 連続（◎ > ○）がいちばん高いマスがある開始時刻。同じ高さ（連続が無いときも）なら安い方、それも同じなら早い方 */
    const firstStart = (w) => {
      let best = w.starts[0];
      let top = -1;
      let cheap = Infinity;
      w.starts.forEach((m) => {
        const level = Math.max(0, ...w.studios.map((s) => s.adj[m]?.level || 0));
        const p = minAt(w, m);
        if (level > top || (level === top && p < cheap)) {
          top = level;
          cheap = p;
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
        // 部屋は自分で選べるので、確認の文にも入れる（選び間違いに、押す前に気づけるように）
        const where = kind === 'date' ? '' : `${hm(sel.start)}〜 ${studios[sel.sid]?.name || ''} ${sel.roomName ? sel.roomName + ' ' : ''}`;
        f.dataset.confirm = `${w.md} ${where}で${label}します${kind === 'date' ? '' : absentNote(w)}。部員に通知が届きます。よろしいですか？`;
      });
      return f;
    };

    /** その開始時刻でいちばん安い料金（合計）= 取れるスタジオの「最初に選ぶ部屋」の料金の最小。1つも出せなければ Infinity */
    const minAt = (w, m) => minInfo(w, m)[0];
    /** minAt の詳細: [最安の料金, その料金になるスタジオの id の配列]。最安が出せなければ [Infinity, []] */
    const minInfo = (w, m) => {
      const list = w.studios
        .filter((s) => s.starts.includes(m))
        .map((s) => [firstPick(withRank(roomQuotes(s.id, w.day, m), w, s.id, m))?.rank ?? Infinity, s.id]);
      const low = Math.min(Infinity, ...list.map(([p]) => p));
      return [low, low === Infinity ? [] : list.filter(([p]) => p === low).map(([, id]) => id)];
    };
    /**
     * 開始時刻の一覧 → 続いている所（間隔1時間以内）を「最初の開始〜最後の開始＋練習時間」の時間帯にまとめた文字
     *   例: [600, 630, 660, 1080] → "10:00〜13:00・18:00〜20:00"（練習2時間）。3つ以上あれば2つまで出して「ほか」
     */
    const spanText = (starts) => {
      const sorted = [...new Set(starts)].sort((a, b) => a - b); // 別のカードに同じ開始時刻があっても1つに
      const spans = [];
      sorted.forEach((m) => {
        const last = spans[spans.length - 1];
        // 1時間以内の間隔はつなげる（30分始まりの部屋は :30 だけ・普通の部屋は :00 だけなので、安い開始時刻が1時間おきに並ぶ）
        if (last && m - last[1] <= 60) last[1] = m;
        else spans.push([m, m]);
      });
      const text = spans.slice(0, 2).map(([a, b]) => `${hm(a)}〜${hm(b + data.len)}`).join('・');
      return spans.length > 2 ? text + ' ほか' : text;
    };

    let uid = 0; // ラジオボタンの name をカードごとに別にするための連番（同じ name だと、別のカードの選択が外れてしまう）

    /** 候補1件（窓1つ）のカード。戻り値: { node, price, mins }（price = 最初の開始時刻での最安 / mins = [開始時刻, 最安] の一覧。日の見出し用） */
    const card = (w) => {
      const id = ++uid;
      const c = el('article', 'card sch-cand' + (w.lateMembers.length || w.earlyMembers.length || w.absent.length ? ' is-late' : ''));
      let start = firstStart(w); // いま選んでいる開始時刻
      let sid = null;            // いま選んでいるスタジオ
      let expanded = false;      // 「ほか n件」を開いたか
      let items = [];            // いまの開始時刻で取れるスタジオ [{ s, i, opts, q }]（いま選んでいる部屋の料金の安い順）
                                 //   opts = 選べる部屋の一覧（roomQuotes の結果）/ q = そのうち選んでいる部屋（無ければ null = 料金未登録）
      const chosen = {};         // スタジオ id → 選んでいる部屋の id（開始時刻を変えても、その時刻で料金が出せるなら引き継ぐ）

      // ---- 1行目: 時間帯・ピル・開始時刻の選択（日付は、まとめている日の見出しに出るので、ここには書かない）----
      const top = el('div', 'sch-cand__top');
      top.append(el('span', 'sch-cand__time', `${hm(w.from)}〜${hm(w.to)}`));
      const pills = el('span', 'sch-cand__pills');
      top.append(pills);
      /** 遅刻・欠席・仮予定の注意のピル。遅れの分は「いま選んでいる開始時刻・スタジオ」での値なので、選び直すたびに作り直す */
      const renderPills = () => {
        pills.replaceChildren();
        const cur = items.find((x) => x.s.id === sid);
        const late = cur?.s.late[start] || {};
        w.lateMembers.forEach((mid) => {
          if (late[mid]) pills.append(el('span', 'pill pill--warn', `${names[mid] || ''} 遅刻${late[mid]}分`));
        });
        const early = cur?.s.early[start] || {};
        w.earlyMembers.forEach((mid) => {
          if (early[mid]) pills.append(el('span', 'pill pill--warn', `${names[mid] || ''} 早退${early[mid]}分`));
        });
        w.absent.forEach((mid) => pills.append(el('span', 'pill pill--warn', `${names[mid] || ''} 欠席`)));
        w.warn.forEach(({ t, where }) => {
          const p = el('span', 'pill', '⚠ ' + t);
          p.dataset.tip = where || 'この時間・日に仮決定の練習あり'; // 仮決定の練習の「スタジオ名 部屋名」（分からなければ、これまでの説明）
          pills.append(p);
        });
      };
      // 開始時刻ごとの最安（合計）。プルダウンの料金と、日の見出しの安い時間帯に使う
      const mins = w.starts.map((m) => { const [p, sids] = minInfo(w, m); return [m, p, sids]; }); // [開始時刻, 最安, その最安のスタジオ id]
      let sel = null;
      if (w.starts.length > 1) {
        sel = el('select', 'select-sm sch-cand__start');
        sel.setAttribute('aria-label', '開始時刻');
        mins.forEach(([m, p]) => {
          const o = el('option', '', `${hm(m)}〜${hm(m + data.len)}${p !== Infinity ? ' ' + perHead(p) + '/人〜' : ''}`); // 〜 = その時刻に取れるスタジオのうちの最安（スタジオ・部屋で高くなる）
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
        /** このスタジオを選んだ状態にする（ラジオ・枠の色・ピル）。再描画はしない = 開いているプルダウンを閉じない */
        const choose = () => {
          sid = x.s.id;
          radio.checked = true;
          box.querySelectorAll('.sch-srow').forEach((r) => r.classList.toggle('is-on', r === lab));
          renderPills();
        };
        radio.addEventListener('change', () => {
          choose();
          rec = pickRecommend(items, sid); // 選んでいるスタジオが変わると、同点のおすすめの付き先も変わる
          refreshers.forEach((f) => f());
        });
        // ラベル（割引・パック・おすすめ・連続）を押したときのポップアップ（data-tip）が出てすぐ消えるのを防ぐ:
        //   行は <label> なので、押すとラジオへの2回目のクリックが飛び、app.js の setupTip が「ラベル以外を押した」と見て消してしまう。
        //   ラベルでは preventDefault() で、ラジオへの転送（= 行の選択）を止める（行のほかの所を押せば選べる）
        lab.addEventListener('click', (e) => {
          if (e.target.closest('.tag[data-tip], .sch-warn[data-tip]')) e.preventDefault();
        });
        const main = el('span', 'sch-srow__main');
        // 名前は1行目を横いっぱいに使う（部屋・ピル・料金は2行目）。右に料金があると、名前の幅が狭くなって折り返すため
        //   名前はボタン: 押すとスタジオ情報をモーダルで開く（行の選択はしない。<label> の中のボタンは、ラジオへクリックを転送しない）
        const nameEl = el('button', 'sch-srow__name', studios[x.s.id]?.name || '');
        nameEl.type = 'button';
        nameEl.addEventListener('click', (e) => {
          e.preventDefault();
          openStudio(x.s.id);
        });
        // ---- 部屋: 選べるのが2つ以上ならプルダウン / 1つだけなら文字 / 部屋が未登録（room = null）なら何も出さない ----
        if (x.opts.length > 1) {
          const roomSel = el('select', 'select-sm sch-srow__room');
          roomSel.setAttribute('aria-label', '部屋');
          x.opts.forEach((o) => {
            const op = el('option', '', optionText(o)); // textContent で入る（DB から来た部屋名が HTML として解釈されない）
            op.value = o.room.id;
            roomSel.append(op);
          });
          roomSel.value = x.q.room.id;
          roomSel.addEventListener('change', () => {
            x.q = x.opts.find((o) => o.room.id === Number(roomSel.value)); // 並びは変えず、この行の中身だけ更新する
            chosen[x.s.id] = x.q.room.id;
            choose();
            rec = pickRecommend(items, sid); // 部屋を選び直したら、おすすめも付け直す（同じ料金・広さなら、選んだ部屋に付く）
            refreshers.forEach((f) => f());
          });
          // プルダウンを触っただけでも、そのスタジオを選ぶ（app.js が select を「ボタン + ポップアップ」に変えるので、ボタンのクリックも見る）
          lab.addEventListener('click', (e) => {
            if (e.target.closest('select, .live-pick__btn')) choose();
          });
          main.append(roomSel);
        } else if (x.q?.room) {
          main.append(el('span', 'muted small', roomText(x.q.room)));
        }
        const price = el('span', 'sch-srow__price');
        // 選んでいる部屋で変わる所（⚠・おすすめ・割引・料金）。部屋を選び直すたびに、これだけ作り直す
        let dyn = []; // main に足した「変わる所」の要素（消すときに使う）
        const update = () => {
          dyn.forEach((n) => n.remove());
          dyn = [];
          const add = (n) => {
            main.append(n);
            dyn.push(n);
          };
          // 狭すぎる部屋を選んでいるときの注意（押しても何も実行されないので data-tip でよい）
          if (x.q?.small) {
            const warn = el('span', 'sch-warn sch-srow__small', '⚠');
            warn.dataset.tip = '狭い可能性あり';
            add(warn);
          }
          // おすすめ（人数にぴったりの部屋のうち最安）があるスタジオだけ。選んでいる部屋が違うなら部屋名も添える
          if (rec && rec.sid === x.s.id) {
            const tag = el('span', 'tag tag--plan', x.q?.room && x.q.room.id === rec.room.id ? 'おすすめ' : 'おすすめ ' + rec.room.name);
            tag.dataset.tip = `${data.people}人にぴったりで安い部屋: ${rec.room.name}`;
            add(tag);
          }
          // 割引・パックのラベルは、押す（乗せる）と「適用後の料金」と説明する（押しても何も実行されないので data-tip でよい。cursor は sched.css の .sch-cand .tag[data-tip]）
          if (x.q?.disc) {
            const tag = el('span', 'tag', x.q.disc);
            tag.dataset.tip = '割引適用後の料金を表示中';
            add(tag);
          }
          if (x.q?.pack) { // パック料金が適用されているとき（例「3時間パック」）
            const tag = el('span', 'tag', x.q.pack);
            tag.dataset.tip = 'パック適用後の料金を表示中';
            add(tag);
          }
          const adj = x.s.adj[start];
          if (adj) {
            const tag = el('span', 'tag ' + (adj.level === 2 ? 'tag--mine' : 'tag--plan'), adj.level === 2 ? '連続◎' : '連続○');
            tag.dataset.tip = '前後に同じスタジオの練習: ' + adj.with.join('・');
            add(tag);
          }
          price.replaceChildren();
          if (x.q) {
            price.append(el('strong', '', perHead(x.q.total) + '/人')); // 黒い太字は1人あたり、合計は薄く
            price.append(el('span', 'muted small', yen(x.q.total)));
            const fare = fareAt(w, x.s.id, start);
            if (fare && fare.sum > 0) {
              // 電車マーク +¥000（駅が未登録・未調査の人の分は含まない）
              const f = el('span', 'muted small sch-fare');
              const ic = el('span', 'icon icon--sm', 'train');
              ic.setAttribute('aria-hidden', 'true');
              f.append(ic, `+${yen(fare.sum)}`);
              f.dataset.tip = '交通費（全員の定期外の運賃の合計）';
              price.append(f);
            }
            const tmax = studios[x.s.id]?.tmax;
            if (tmax != null) {
              // 時計マーク 最長00分（駅が未登録・未調査の人は含まない）
              const t = el('span', 'muted small sch-fare');
              const ic = el('span', 'icon icon--sm', 'schedule');
              ic.setAttribute('aria-hidden', 'true');
              t.append(ic, `最長${tmax}分`);
              t.dataset.tip = '自宅からの片道の所要時間（いちばん遠い人）';
              price.append(t);
            }
          } else {
            price.append(el('span', 'muted small', '料金未登録'));
          }
        };
        update();
        refreshers.push(update);
        const sub = el('span', 'sch-srow__sub');
        sub.append(main, price);
        const body = el('span', 'sch-srow__body');
        body.append(nameEl, sub);
        // このスタジオの最安: 取れる開始時刻のうち、いちばん安い時刻の時間帯と料金。時刻で料金が変わるスタジオだけ出す
        //   部屋は各時刻の「最初に選ぶ部屋」で比べる（選んでいる部屋では変わらない）
        const at = x.s.starts.map((m) => [m, firstPick(roomQuotes(x.s.id, w.day, m))?.total ?? Infinity]);
        const low = Math.min(...at.map(([, p]) => p));
        // 開始を30分ずらすと、人数に合う部屋（狭すぎない）でこのスタジオの最安になるとき → 「10:30開始で最安 Ast ¥930/人」
        //   30分始まりの部屋（:30 だけ）と普通の部屋（:00 だけ）が混ざるスタジオで、いまの時刻では安い部屋が借りられない場合
        const now = firstPick(x.opts)?.total ?? Infinity; // いまの開始時刻での、このスタジオの最安
        //   前も後ろも最安なら、時刻を2つ並べる（「9:30・10:30開始で最安 …」）
        const shifts = [start - 30, start + 30]
          .filter((m) => x.s.starts.includes(m)) // メンバーが空いていて、借りられる時刻だけ
          .map((m) => [m, firstPick(roomQuotes(x.s.id, w.day, m))])
          .filter(([, q]) => q && !q.small && q.total === low && q.total < now);
        if (shifts.length) {
          const q = shifts[0][1];
          const rooms = [...new Set(shifts.map(([, s]) => s.room?.name).filter(Boolean))].join('・'); // 前後で部屋が違えば両方
          body.append(el('span', 'sch-srow__cheap small', `${shifts.map(([m]) => hm(m)).join('・')}開始で最安 ${rooms ? rooms + ' ' : ''}${perHead(q.total)}/人`));
        } else if (low !== Infinity) {
          // 時刻で料金が変わらないスタジオは「終日同一料金」（時間帯を書いても意味がない）
          const same = at.every(([, p]) => p === low);
          body.append(el('span', 'sch-srow__cheap muted small', same ? '終日同一料金' : `最安 ${spanText(at.filter(([, p]) => p === low).map(([m]) => m))} ${perHead(low)}/人`));
        }
        lab.append(radio, body);
        return lab;
      };
      /**
       * いまの開始時刻で取れるスタジオを、いま選んでいる部屋の料金の安い順（料金未登録は最後）に並べ直す
       *   部屋: 前に選んだ部屋がこの時刻でも料金を出せるならそのまま、出せなければ最初の決め方（firstPick）に戻す
       *   選んでいたスタジオが取れるならそのまま、無ければ先頭
       */
      let rec = null;            // おすすめ { sid, room }（いまの開始時刻のもの）
      let refreshers = [];       // 表示中の各行の update（おすすめの付け替え用）
      const renderStudios = () => {
        refreshers = [];
        items = w.studios
          .filter((s) => s.starts.includes(start))
          .map((s, i) => {
            const opts = withRank(roomQuotes(s.id, w.day, start), w, s.id, start);
            const q = opts.find((o) => o.room && o.room.id === chosen[s.id]) || firstPick(opts);
            chosen[s.id] = q?.room ? q.room.id : null;
            return { s, i, opts, q };
          })
          .sort((a, b) => (a.q ? a.q.rank : Infinity) - (b.q ? b.q.rank : Infinity) || a.i - b.i); // Infinity 同士の引き算は NaN（偽）→ 元の順
        if (!items.some((x) => x.s.id === sid)) sid = items[0].s.id;
        rec = pickRecommend(items, sid);
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
      const price = items[0].q ? items[0].q.rank : Infinity; // 「最初に選ばれた開始時刻での最安（料金 + 交通費）」（安い順の並べ替え用）
      c.append(box);

      // ---- 下段: 確定のボタン ----
      if (data.canFix) {
        const getSel = () => {
          const x = items.find((i) => i.s.id === sid);
          return { start, sid, room: x?.q?.room ? x.q.room.id : null, roomName: x?.q?.room ? x.q.room.name : '' };
        };
        const acts = el('div', 'sch-cand__acts');
        if (data.status === 0) acts.append(fixForm('date', '日だけ仮決定', w, getSel, false));
        acts.append(fixForm('time', '仮決定', w, getSel, false));
        acts.append(fixForm('final', '決定', w, getSel, true));
        c.append(acts);
      }
      /** 開始時刻を m に合わせる（その時刻が選べるときだけ。表のマスを押したとき用） */
      const setStart = (m0) => {
        // 押した時刻で借りられなければ（部屋の開始の決まりで :00 / :30 だけ等）、いちばん近い開始時刻にする（同じ近さなら後ろ）
        const m = w.starts.reduce((best, x) => (Math.abs(x - m0) < Math.abs(best - m0) || (Math.abs(x - m0) === Math.abs(best - m0) && x > best) ? x : best), w.starts[0]);
        if (m === start) return;
        start = m;
        if (sel) {
          sel.value = m;
          sel.dispatchEvent(new Event('change', { bubbles: true })); // プルダウンのボタンの文字を合わせさせる（app.js の変換後は、value を替えただけでは文字が変わらない）。上の change の処理が renderStudios も呼ぶ
        } else {
          renderStudios();
        }
      };
      return { node: c, price, mins, setStart };
    };

    /**
     * 1日ぶんの <details>。es = その日のカード [{ w, i, node, price }]（i の昇順 = PHP の順）
     *   見出し（summary）は、その日のカードを集めて1行にする: 日付・時間帯・印・最安の料金
     *   見出しは data-tip を付けない（押すと開閉する操作なので。操作ボタンに付けない規約）
     *   戻り値: { node, date, best, price }（best / price は並べ替え用）
     */
    const dayGroup = (es, open) => {
      const d = el('details', 'card sch-day');
      d.open = open;
      const head = el('summary', 'sch-day__head');
      // 土 = 青 / 日・祝日 = 赤（表の見出しと同じ色）。w.day は料金の曜日区分（3 = 土 / 4 = 日祝）
      head.append(el('strong', 'sch-day__date' + (es[0].w.day === 4 ? ' is-sun' : es[0].w.day === 3 ? ' is-sat' : ''), es[0].w.md));
      // 時間帯: その日のカードの いちばん早い開始 〜 いちばん遅い終わり
      head.append(el('span', 'sch-day__time', `${hm(Math.min(...es.map((e) => e.w.from)))}〜${hm(Math.max(...es.map((e) => e.w.to)))}`));
      const marks = el('span', 'sch-day__marks'); // 印（あるものだけ）。残りの幅をぜんぶ取って、料金を右端へ押しやる
      const adj = Math.max(...es.map((e) => e.w.adj)); // その日の連続の最大（2 = ◎ / 1 = ○ / 0 = 無し）
      if (adj) marks.append(el('span', 'tag ' + (adj === 2 ? 'tag--mine' : 'tag--plan'), adj === 2 ? '連続◎' : '連続○'));
      // 全部のカードが遅刻か早退のどちらかを持つときだけ出す（1枚でも遅刻・早退なしがあれば出さない）。遅刻のカードがあれば「遅刻あり」、早退のカードがあれば「早退あり」
      if (es.every((e) => e.w.lateMembers.length || e.w.earlyMembers.length)) {
        const hasLate = es.some((e) => e.w.lateMembers.length);
        const hasEarly = es.some((e) => e.w.earlyMembers.length);
        marks.append(el('span', 'pill pill--warn', hasLate && hasEarly ? '遅刻・早退あり' : hasLate ? '遅刻あり' : '早退あり')); // 両方あるときは1つにまとめる
      }
      // 欠席の人（欠席の一覧だけ中身がある）。同じ人が何枚にも出るので、Set で重複を消す
      [...new Set(es.flatMap((e) => e.w.absent))].forEach((mid) => marks.append(el('span', 'pill pill--warn', `${names[mid] || ''} 欠席`)));
      head.append(marks);
      // 最安: その日のどの開始時刻・スタジオでもいちばん安い料金（合計）。料金未登録は Infinity なので、登録済みがあればそちらが選ばれる
      const all = es.flatMap((e) => e.mins); // [開始時刻, 最安] の一覧（その日の全カード）
      const price = Math.min(...all.map(([, p]) => p));
      if (price !== Infinity) {
        // 最安になる時間帯。どの時刻も同じ料金なら「終日同一料金」
        const same = all.every(([, p]) => p === price);
        // 最安の時間帯の横に、その料金になるスタジオ名（2つまで。それ以上は「ほか」）
        const cheap = all.filter(([, p]) => p === price);
        const sids = [...new Set(cheap.flatMap(([, , ids]) => ids))];
        const studioText = sids.slice(0, 2).map((id) => studios[id]?.name || '').filter(Boolean).join('・') + (sids.length > 2 ? ' ほか' : '');
        head.append(el('span', 'sch-day__cheap muted small', same ? '終日同一料金' : `最安 ${spanText(cheap.map(([m]) => m))} ${studioText}`.trim()));
        head.append(el('span', 'sch-day__price', perHead(price) + '/人〜')); // 並べ替えは合計（price）のまま。見せるのは1人あたり
      }
      d.append(head);
      const body = el('div', 'sch-day__body');
      body.append(...es.map((e) => e.node));
      d.append(body);
      // 中のカードの並び: おすすめ順のときだけ、連続（◎ > ○）のあるカードを上へ（同じなら PHP の順）
      const order = (key) => {
        const list = key === 'best' ? [...es].sort((x, y) => y.w.adj - x.w.adj || x.i - y.i) : es;
        body.replaceChildren(...list.map((e) => e.node));
      };
      return { node: d, date: es[0].w.date, best: Math.min(...es.map((e) => e.i)), price, order };
    };

    const focusables = []; // 全カードの { date, from, to, card, day }（表のマスから探す用）

    // ---- 並べ替え（2つの一覧に同じ並べ替えを効かせる。並べ替えるのは日の行）----
    const sorters = {
      best: (a, b) => a.price - b.price || a.date.localeCompare(b.date), // おすすめ順 = まず日を安い順（料金未登録の日は最後）。日の中は order() で連続のあるカードを上に
      date: (a, b) => a.date.localeCompare(b.date), // 'YYYY-MM-DD' なので、文字の比べっこで日付順になる
      price: (a, b) => a.price - b.price || a.date.localeCompare(b.date), // Infinity - Infinity は NaN（偽）→ 日付順。料金未登録だけの日は Infinity なので最後
    };
    lists.forEach((l) => {
      const byDate = new Map(); // 日付 → その日のカード（windows は PHP の順なので、i の昇順のまま入る）
      l.windows.forEach((raw, i) => {
        const w = trimWindow(raw); // 部屋の開始の決まりで借りられない時刻を除く
        if (!w) return;
        if (!byDate.has(w.date)) byDate.set(w.date, []);
        byDate.get(w.date).push({ w, i, ...card(w) });
      });
      // 日が1つしか無い一覧は、閉じたままだと中身が見えないので最初から開く
      l.days = [...byDate.values()].map((es) => {
        const g = dayGroup(es, byDate.size === 1);
        es.forEach((e) => focusables.push({ date: e.w.date, from: e.w.from, to: e.w.to, starts: e.w.starts, absent: e.w.absent.length, setStart: e.setStart, card: e.node, day: g.node }));
        return g;
      });
    });
    // メンバーの予定の表の網掛け用: 候補全体（全部の日・時刻）でいちばん安い開始時刻（日 => [[開始, 終了]…]）。全部同じ料金なら無し
    const priced = []; // [日付, 開始時刻, 最安の料金]
    lists.forEach((l) => l.windows.forEach((raw) => {
      const w = trimWindow(raw);
      if (w) w.starts.forEach((m) => priced.push([w.date, m, minAt(w, m)]));
    }));
    const lowest = Math.min(...priced.map(([, , p]) => p));
    window.schedCheap = {};
    if (lowest !== Infinity && priced.some(([, , p]) => p !== lowest)) {
      priced.filter(([, , p]) => p === lowest).forEach(([date, m]) => (window.schedCheap[date] ||= []).push([m, m + data.len]));
    }
    // メンバーの予定の表のマスから呼ぶ: その日・時刻にかかる候補のカードを開いて、見える所へ動かして強調する（候補が無ければ何もしない）
    // その日・時刻から始まる、欠席なしの候補（遅刻・早退が上限に収まる枠）があるか。予定で埋まったマスを押せるかの判定に使う
    window.schedHasCand = (date, m) => focusables.some((f) => f.date === date && !f.absent && f.starts.includes(m));
    window.schedFocusCand = (date, m) => {
      const same = focusables.filter((f) => f.date === date && f.from <= m && m < f.to);
      const hit = same.find((f) => f.starts.includes(m)) || same[0]; // 押した時刻から始められるカードを優先
      if (!hit) return false;
      hit.setStart(m);
      // 押した日以外の日は、アニメ無しで閉じる（上の日が閉じて高さが変わっても、スクロールの行き先がずれない）
      new Set(focusables.map((f) => f.day)).forEach((d) => {
        if (d === hit.day || !d.open) return;
        d.classList.add('is-instant');
        d.open = false;
        setTimeout(() => d.classList.remove('is-instant'), 100);
      });
      const go = () => {
        hit.card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        hit.card.classList.remove('is-focus');
        void hit.card.offsetWidth; // アニメを最初からやり直す
        hit.card.classList.add('is-focus');
        hit.card.tabIndex = -1;
        hit.card.focus({ preventScroll: true });
      };
      if (hit.day.open) {
        go();
      } else {
        // 閉じていた日: アニメ無しで一気に開いてから動かす（アニメの終わりを待つと、押してから動くまでワンテンポ遅れる。途中だとカードの位置もずれる）
        hit.day.classList.add('is-instant');
        hit.day.open = true;
        go();
        setTimeout(() => hit.day.classList.remove('is-instant'), 100);
      }
      return true;
    };
    let sortKey = 'date';
    const render = (key = sortKey) => {
      sortKey = key;
      // <details> は作り直さず、同じ要素を並べ直すだけ（開閉の状態・中で選んだ開始時刻やスタジオが残る）
      lists.forEach((l) => {
        l.days.forEach((g) => g.order(key));
        l.node.replaceChildren(...[...l.days].sort(sorters[key]).map((g) => g.node));
      });
    };
    /** 日の見出しの開閉の矢印: 入り切らず次の行に落ちるときは消す（矢印を付けた高さと付けない高さを比べて、変わるなら消す。見出し全体が押せるので、矢印が無くても開閉できる） */
    const fitArrows = () => {
      const heads = lists.flatMap((l) => [...l.node.querySelectorAll('.sch-day__head')]);
      heads.forEach((h) => h.classList.remove('no-arrow'));
      const withArrow = heads.map((h) => h.offsetHeight);
      heads.forEach((h) => h.classList.add('no-arrow'));
      const without = heads.map((h) => h.offsetHeight);
      heads.forEach((h, i) => h.classList.toggle('no-arrow', withArrow[i] > without[i]));
    };
    render('date');
    fitArrows();
    if (window.ResizeObserver) {
      let lastW = 0;
      const ro = new ResizeObserver(([e]) => { if (Math.round(e.contentRect.width) !== lastW) { lastW = Math.round(e.contentRect.width); fitArrows(); } });
      lists.forEach((l) => ro.observe(l.node));
    }
    document.fonts?.ready.then(fitArrows);
    document.querySelector('[data-sched-sort]')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-sort]');
      if (!btn) return;
      btn.parentElement.querySelectorAll('[data-sort]').forEach((b) => b.classList.toggle('is-active', b === btn));
      render(btn.dataset.sort);
    });
  };

  // ===================================================================
  //  5. メンバーの予定（sched_poll.php の <details data-sched-over>）
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
    const provs = over.prov || []; // 仮決定（日だけ = s が null / 時間まで）
    /** 仮決定のポップアップ: 1行目 = その練習に入る人の名前（個人名）と練習名 / 2行目 = スタジオ名 部屋名（改行は .album-tip の white-space: pre-line） */
    const provTip = (p) => `${(p.members || []).map((id) => names[id]).filter(Boolean).join('・')}（${p.title}）${p.where ? '\n' + p.where : ''}`;
    const weeks = []; // 7日ずつに区切る
    for (let i = 0; i < over.days.length; i += 7) weeks.push(over.days.slice(i, i + 7));
    let page = 0;
    const len = Number(boot.len) || over.step; // 練習時間（分）
    let pick = null; // 押したマス { date, min }

    /** 押したマスから練習時間ぶんの枠。表の上に1つの箱を重ねる（マスの罫線で途切れず、角も丸くできる）。表は作り直さない */
    const frame = el('div', 'sch-pick');
    const applyPick = () => {
      frame.remove();
      if (!pick) return;
      const first = wrap.querySelector(`td[data-date="${pick.date}"][data-min="${pick.min}"]`);
      if (!first) return; // 押した日が今の週に無い
      // 練習時間ぶん下のマス（予定で埋まったマスは data-date が無いので、行をたどって探す）
      const rows = Math.min(Math.ceil(len / over.step), Math.round((over.end - pick.min) / over.step));
      let tr = first.parentElement;
      for (let i = 1; i < rows && tr.nextElementSibling; i++) tr = tr.nextElementSibling;
      const last = tr.children[first.cellIndex] || first;
      const w = wrap.getBoundingClientRect();
      const a = first.getBoundingClientRect();
      const b = last.getBoundingClientRect();
      frame.style.left = `${a.left - w.left + wrap.scrollLeft}px`;
      frame.style.top = `${a.top - w.top + wrap.scrollTop}px`;
      frame.style.width = `${a.width}px`;
      frame.style.height = `${b.bottom - a.top}px`;
      wrap.append(frame);
    };
    /** 最安の時間帯の網掛け。マスごとに背景を付けると縞がマスの境目で途切れるので、続いているマスをまとめて1つの箱にして重ねる */
    const applyCheap = () => {
      wrap.querySelectorAll('.sch-cheap').forEach((n) => n.remove());
      const w = wrap.getBoundingClientRect();
      const cells = [...wrap.querySelectorAll('td.is-cheap')];
      const dates = [...new Set(cells.map((td) => td.dataset.date))];
      dates.forEach((date) => {
        const col = cells.filter((td) => td.dataset.date === date).sort((a, b) => a.dataset.min - b.dataset.min);
        let run = [];
        const flush = () => {
          if (!run.length) return;
          const a = run[0].getBoundingClientRect();
          const b = run[run.length - 1].getBoundingClientRect();
          const box = el('div', 'sch-cheap');
          box.style.left = `${a.left - w.left + wrap.scrollLeft}px`;
          box.style.top = `${a.top - w.top + wrap.scrollTop}px`;
          box.style.width = `${a.width}px`;
          box.style.height = `${b.bottom - a.top}px`;
          box.style.backgroundPosition = `${-Math.round(a.left - w.left + wrap.scrollLeft)}px ${-Math.round(a.top - w.top + wrap.scrollTop)}px`; // 縞の起点を表全体でそろえる（隣の日の箱ともつながる）
          wrap.prepend(box); // 表の下に敷く（マスの区切り線が縞の上に見える。マスの色は半透明なので縞が透ける）
          run = [];
        };
        col.forEach((td) => {
          const prev = run[run.length - 1];
          if (prev && Number(td.dataset.min) - Number(prev.dataset.min) !== over.step) flush();
          run.push(td);
        });
        flush();
      });
    };
    /** 仮決定（時間まで決めたもの）の枠: マスより一回り小さく、角を丸めた箱を表の上に重ねる（続くマスで1つ。押しても下のマスが反応する） */
    const applyProv = () => {
      wrap.querySelectorAll('.sch-prov').forEach((n) => n.remove());
      const rows = wrap.querySelector('tbody')?.rows;
      if (!rows) return;
      const w = wrap.getBoundingClientRect();
      const days = weeks[page];
      provs.filter((p) => p.s !== null).forEach((p) => {
        const ci = days.findIndex((d) => d.date === p.date);
        if (ci < 0) return;
        const r0 = Math.max(0, Math.floor((p.s - days[ci].abs - over.start) / over.step));
        const r1 = Math.min(rows.length, Math.ceil((p.e - days[ci].abs - over.start) / over.step)) - 1;
        const first = rows[r0]?.cells[ci + 1];
        const last = rows[r1]?.cells[ci + 1];
        if (!first || !last) return;
        const a = first.getBoundingClientRect();
        const z = last.getBoundingClientRect();
        const box = el('div', 'sch-prov');
        box.style.left = `${a.left - w.left + wrap.scrollLeft + PROV_INSET}px`;
        box.style.top = `${a.top - w.top + wrap.scrollTop + PROV_INSET}px`;
        box.style.width = `${a.width - PROV_INSET * 2}px`;
        box.style.height = `${z.bottom - a.top - PROV_INSET * 2}px`;
        wrap.append(box);
      });
    };
    window.addEventListener('resize', () => { applyCheap(); applyProv(); applyPick(); });

    const draw = () => {
      const days = weeks[page];
      const lo = days[0].abs;
      const hi = days[days.length - 1].abs + 1440;
      // この週にかかる埋まりだけに絞っておく（マスごとに全部を調べると重いので）
      const busy = ids.map((id) => [id, (over.busy[id] || []).filter((b) => b[0] < hi && b[1] > lo)]);
      const table = el('table', 'sch-grid sch-grid--over');
      const head = el('tr');
      head.append(el('th'));
      days.forEach((d) => {
        const th = el('th', (dayClass(d) + (d.off ? ' is-off' : '')).trim(), d.md.replace(/\((.+?)\)/, '\n$1')); // 「11/21」改行「土」（祝日は「月祝」）。is-off = 調整の期間の外（月曜始まりの週に入っただけ）
        // 日だけ仮決定: 終日の予定と同じように、日付の下に題名（時間は未定なのでマスには塗らない。全文は押すと data-tip）
        provs.filter((p) => p.date === d.date && p.s === null).forEach((p) => {
          const chip = el('span', 'sch-allday sch-allday--prov', p.title);
          chip.dataset.tip = provTip(p);
          th.append(chip);
        });
        head.append(th);
      });
      const thead = el('thead');
      thead.append(head);
      table.append(thead);
      const tbody = el('tbody');
      for (let m = over.start; m < over.end; m += over.step) {
        const tr = el('tr', m % 60 === 0 ? 'is-hour' : '');
        tr.append(el('th', '', m % 60 === 0 ? hm(m) : ''));
        days.forEach((d) => {
          if (d.off) {
            tr.append(el('td', 'is-off')); // 調整の期間の外: グレー・押せない
            return;
          }
          const abs = d.abs + m;
          // このマスにかかる埋まりを、人ごとに最初の1つだけ拾う（同じ人の埋まりが重なっていても1人と数える）
          const who = [];
          const groups = new Map(); // 予定の種類（予定あり・同じ練習など）→ { ns: 名前の一覧, text: 名前のあとに続ける文 }。同じ種類の人は名前をまとめて、あとに1回だけ書く
          let sp = false; // スタジオ練習（決定した練習）で埋まっている人がいるか
          busy.forEach(([id, list]) => {
            const hit = list.find((b) => b[0] < abs + over.step && b[1] > abs);
            if (hit) {
              who.push(names[id]);
              // スタジオ練習（決定）は仮決定と同じ形: 「名前（練習名）」+ 改行 + 「スタジオ名 部屋名」。それ以外は「名前（予定あり）」など
              const key = hit[3] ? `P|${hit[4]}|${hit[2]}` : `B|${hit[2]}`;
              if (!groups.has(key)) groups.set(key, { ns: [], text: hit[3] ? `（${hit[4]}）${hit[2] ? '\n' + hit[2] : ''}` : `（${hit[2]}）` });
              groups.get(key).ns.push(names[id]);
              if (hit[3]) sp = true;
            }
          });
          const td = el('td', (who.length === 0 ? 'is-free' : who.length === 1 ? 'is-n1' : 'is-n2') + (sp ? ' is-sp' : ''));
          // 全員空きのマスのうち、その日の最安の時間帯は青（候補カードの最安と同じ時間帯）
          if (!who.length && (window.schedCheap?.[d.date] || []).some(([a, b]) => m >= a && m < b)) td.classList.add('is-cheap');
          // 仮決定（時間まで決めたもの）: スタジオ練習の色を少し透かして重ねる。埋まりにはしないので、押せるかどうかは変わらない
          const pv = provs.filter((p) => p.date === d.date && p.s !== null && p.s < abs + over.step && p.e > abs);
          if (pv.length) td.classList.add('is-prov');
          const tips = [...groups.values()].map((g) => g.ns.join('・') + g.text).concat(pv.map(provTip));
          if (tips.length) td.dataset.tip = tips.join('\n'); // 属性に入れる（HTML としては解釈されない）
          // 押せるのは、そこから始まる候補（欠席なし・遅刻と早退が上限に収まる枠）があるマスだけ。空きのマスでも、練習の途中に予定がかかって早退が上限を超えるなら押せない
          //   候補を出していない画面（決定済みなど）では、全員空きのマスだけ
          if (window.schedHasCand ? window.schedHasCand(d.date, m) : !who.length) {
            td.dataset.date = d.date;
            td.dataset.min = m;
          }
          tr.append(td);
        });
        tbody.append(tr);
      }
      table.append(tbody);
      wrap.replaceChildren(table);
      applyCheap();
      applyProv();
      applyPick();
      range.textContent = `${days[0].md}〜${days[days.length - 1].md}`;
      prev.disabled = page === 0;
      next.disabled = page === weeks.length - 1;
    };
    // マスを押すと、その日・時刻の候補カードを開いて強調する（候補が無いマスは何も起きない）
    wrap.addEventListener('click', (e) => {
      const td = e.target.closest('td[data-date]');
      if (!td) return;
      pick = { date: td.dataset.date, min: Number(td.dataset.min) };
      applyPick();
      // カードを開く・開始時刻を変える（高さが変わる）→ 描き終わってから動かす。同時だと、スクロールの行き先がずれて止まることがある
      requestAnimationFrame(() => { if (window.schedFocusCand) window.schedFocusCand(pick.date, pick.min); });
    });
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

  // ===================================================================
  //  6. 画像から予定を読み込む（sched_me.php の [data-photo-open]）
  //     iCal で連携できないカレンダー（TimeTree・Android の標準アプリなど）の人が、スクリーンショットを選ぶ
  //     流れ: 画像を選ぶ → photo_read（AI が予定を読む。数十秒かかる）→ 確認画面（<dialog>）で直す・外す → photo_add → 画面を読み直す
  //     ・AI の読み間違いはあるので、確認画面で日付・時間・タイトルを直せる。終了時刻が無い・開始 >= 終了の行は、直すまで追加できない
  //     ・AI の返事（タイトルなど）は、必ず textContent / value で入れる（HTML として解釈させない = XSS 対策）
  // ===================================================================
  const setupPhoto = () => {
    const openBtn = document.querySelector('[data-photo-open]');
    const input = document.querySelector('[data-photo-input]');
    const form = document.querySelector('[data-sched-grid-form]'); // CSRF トークンと、今の週（from / poll / week）を持っている
    if (!openBtn || !input || !form) return;
    const MAX_IMAGES = 3;               // lib/sched_photo.php の SCHED_PHOTO_MAX_IMAGES と同じ
    const MAX_BYTES = 10 * 1024 * 1024; // 〃 SCHED_PHOTO_MAX_BYTES
    const MAX_ADD = 100;                // 〃 SCHED_PHOTO_MAX_ADD
    const STEP = 30;

    // モデルの選択。前回選んだものを覚えておく（この端末だけ。使えない環境では覚えないだけで、初期のモデルで動く）
    const modelSel = document.querySelector('[data-photo-model]');
    const MODEL_KEY = 'sched-photo-model';
    if (modelSel) {
      try {
        const saved = localStorage.getItem(MODEL_KEY);
        if (saved && [...modelSel.options].some((o) => o.value === saved)) modelSel.value = saved;
      } catch (e) { /* 保存できない環境 */ }
      modelSel.addEventListener('change', () => {
        try { localStorage.setItem(MODEL_KEY, modelSel.value); } catch (e) { /* 保存できない環境 */ }
      });
    }

    /** サーバーに送る（表示中のページ = 今の週のまま）。X-Autosave は「画面を移動しない」合図 */
    const send = (fd) => fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: { 'X-Autosave': '1' } });

    const setBusy = (on) => {
      openBtn.disabled = on;
      openBtn.classList.toggle('is-busy', on);
      openBtn.setAttribute('aria-busy', on ? 'true' : 'false');
    };

    // ---- 1. 画像を選ぶ → 読み込む ----
    openBtn.addEventListener('click', () => input.click());
    input.addEventListener('change', async () => {
      const files = [...input.files];
      input.value = ''; // 同じ画像をもう一度選んでも change が起きるように
      if (!files.length) return;
      if (files.length > MAX_IMAGES) {
        alert('画像は ' + MAX_IMAGES + ' 枚までです');
        return;
      }
      if (files.some((f) => f.size > MAX_BYTES)) {
        alert('画像は 1 枚 ' + MAX_BYTES / 1048576 + 'MB までです');
        return;
      }
      const fd = new FormData(form);
      fd.delete('payload');
      fd.set('action', 'photo_read');
      files.forEach((f) => fd.append('images[]', f));
      if (modelSel) fd.set('model', modelSel.value);
      setBusy(true);
      try {
        const res = await send(fd);
        let body = null;
        try {
          body = await res.json();
        } catch (e) {
          // JSON でなければ（サーバーのエラーページなど）、下の汎用のメッセージにする
        }
        if (!res.ok || !body || !Array.isArray(body.events)) {
          alert(body?.error || '読み込めませんでした');
          return;
        }
        openConfirm(body.events, Array.isArray(body.notes) ? body.notes : []);
      } catch (e) {
        alert('通信できませんでした');
      } finally {
        setBusy(false);
      }
    });

    // ---- 2. 確認画面 ----
    /** <option> の並び。空（— = 未入力）+ 30分刻み */
    const timeOptions = (from, to) => {
      const frag = document.createDocumentFragment();
      const blank = el('option', '', '—');
      blank.value = '';
      frag.append(blank);
      for (let m = from; m <= to; m += STEP) {
        const o = el('option', '', hm(m));
        o.value = String(m);
        frag.append(o);
      }
      return frag;
    };
    /** 読み上げ用のラベル（aria-label）を付けた要素を作る */
    const field = (tag, label) => {
      const n = el(tag);
      n.setAttribute('aria-label', label);
      return n;
    };

    const openConfirm = (events, notes) => {
      const dlg = el('dialog', 'modal sch-photo');
      const body = el('div', 'modal__body');
      body.append(el('h3', 'modal__title', '画像から読込'));
      if (notes.length) {
        const ul = el('ul', 'sch-photo__notes muted small');
        notes.forEach((n) => ul.append(el('li', '', n)));
        body.append(ul);
      }
      const rows = [];
      const list = el('div', 'sch-photo__list');
      events.forEach((ev) => {
        const row = el('div', 'sch-photo__row');
        const cb = field('input', '追加');
        cb.type = 'checkbox';
        // 終了時刻が無い予定（終日以外）は、最初は外しておく（直してから自分で入れる）
        cb.checked = !!ev.all_day || ev.end != null;
        const date = field('input', '日付');
        date.type = 'date';
        date.value = ev.date || '';
        const start = field('select', '開始');
        start.append(timeOptions(0, 24 * 60 - STEP));
        start.value = ev.start == null ? '' : String(ev.start);
        const end = field('select', '終了');
        end.append(timeOptions(STEP, 24 * 60));
        end.value = ev.end == null ? '' : String(ev.end);
        const title = field('input', 'タイトル');
        title.type = 'text';
        title.maxLength = 100;
        title.value = ev.title || '';
        title.className = 'sch-photo__title';
        const range = el('span', 'sch-photo__range');
        range.append(start, el('span', 'muted', '〜'), end);
        row.append(cb, date, range, title);
        list.append(row);
        rows.push({ row, cb, date, start, end, title });
      });
      body.append(list);

      const acts = el('div', 'form-actions');
      const over = el('span', 'sch-photo__over small', MAX_ADD + '件まで');
      over.hidden = true;
      const cancel = el('button', 'btn btn--ghost', events.length ? 'キャンセル' : '閉じる');
      cancel.type = 'button';
      const add = el('button', 'btn btn--primary', '追加');
      add.type = 'button';
      acts.append(over, cancel);
      if (events.length) acts.append(add);
      else body.append(el('p', 'muted', '予定が見つかりませんでした'));
      body.append(acts);
      dlg.append(body);

      // 追加できる状態か確かめて、ダメな欄を赤くする（チェックの付いた行だけ見る）
      const refresh = () => {
        let bad = false;
        let count = 0;
        rows.forEach((r) => {
          r.row.classList.toggle('is-off', !r.cb.checked);
          const s = r.start.value === '' ? null : Number(r.start.value);
          const e = r.end.value === '' ? null : Number(r.end.value);
          const ok = {
            date: r.date.value !== '',
            start: s !== null,
            end: e !== null && (s === null || e > s), // 終了が無い・開始以前はダメ
          };
          if (r.cb.checked) {
            count++;
            if (!ok.date || !ok.start || !ok.end) bad = true;
          }
          r.date.setAttribute('aria-invalid', r.cb.checked && !ok.date ? 'true' : 'false');
          r.start.setAttribute('aria-invalid', r.cb.checked && !ok.start ? 'true' : 'false');
          r.end.setAttribute('aria-invalid', r.cb.checked && !ok.end ? 'true' : 'false');
        });
        over.hidden = count <= MAX_ADD;
        add.disabled = count === 0 || count > MAX_ADD || bad;
      };
      list.addEventListener('input', refresh);
      list.addEventListener('change', refresh);
      refresh();

      cancel.addEventListener('click', () => dlg.close());
      dlg.addEventListener('close', () => dlg.remove()); // Esc でも閉じたら片付ける
      add.addEventListener('click', async () => {
        const items = rows.filter((r) => r.cb.checked).map((r) => ({
          date: r.date.value, start: Number(r.start.value), end: Number(r.end.value), title: r.title.value.trim(),
        }));
        const fd = new FormData(form);
        fd.set('action', 'photo_add');
        fd.set('payload', JSON.stringify({ items }));
        add.disabled = true;
        cancel.disabled = true;
        try {
          const res = await send(fd);
          if (!res.ok) throw new Error();
          location.reload(); // 今の週のまま読み直して、グリッドに出す
        } catch (e) {
          alert('追加できませんでした');
          cancel.disabled = false;
          refresh();
        }
      });
      document.body.append(dlg);
      dlg.showModal();
    };
  };

  // 練習の書式（sched_me.php の [data-ev-format]）: {…} のボタンを押すと、最後に触った入力欄のカーソル位置に差し込む
  const setupEventFormat = () => {
    const form = document.querySelector('[data-ev-format]');
    if (!form) return;
    let target = form.querySelector('[name="ev_title"]');
    form.querySelectorAll('input[type="text"][name^="ev_"], textarea').forEach((f) => f.addEventListener('focus', () => { target = f; }));
    // プレビュー: 見本の値（sched_me.php の data-ev-sample）を書式に差し込んで、各欄の下に出す（lib/sched.php の sched_event_render と同じ規則）
    let sample = null;
    try { sample = JSON.parse(form.dataset.evSample || ''); } catch (e) { /* 見本が無ければプレビューは出さない */ }
    const OLD = { 'タイトル': 'バンド名', 'スタジオ': 'スタジオ名', '短縮': 'スタジオ略称', 'スタジオ短縮名': 'スタジオ略称' }; // 旧名（SCHED_EVENT_OLD_VARS）
    const render = (tpl, multi) => {
      const vars = sample.vars;
      const shortInput = sample.sid ? form.querySelector(`[name="short[${sample.sid}]"]`) : null;
      const short = shortInput ? shortInput.value.trim() : vars['スタジオ略称']; // 未入力なら空
      const s = tpl.replace(/\r\n/g, '\n').replace(/\{([^{}]+)\}/g, (m, k) => {
        k = OLD[k] || k;
        if (k === 'スタジオ略称') return short;
        return Object.prototype.hasOwnProperty.call(vars, k) ? vars[k] : m;
      });
      return multi ? s.replace(/[ \t　]+$/gm, '').replace(/\n{3,}/g, '\n\n').trim() : s.replace(/\s+/g, ' ').trim();
    };
    const preview = () => {
      if (!sample) return;
      form.querySelectorAll('[data-ev-preview]').forEach((el) => {
        const f = form.querySelector(`[name="${el.dataset.evPreview}"]`);
        // タイトルは空なら初期の書式になる。場所・メモは空なら何も入らない
        const v = f.value.trim() === '' && f.name === 'ev_title' ? f.placeholder : f.value;
        el.textContent = render(v, f.tagName === 'TEXTAREA');
      });
    };
    form.addEventListener('input', preview);
    preview();
    form.querySelectorAll('[data-ev-ins]').forEach((b) => b.addEventListener('click', () => {
      const s = target.selectionStart ?? target.value.length;
      const e = target.selectionEnd ?? s;
      target.value = target.value.slice(0, s) + b.dataset.evIns + target.value.slice(e);
      const pos = s + b.dataset.evIns.length;
      target.focus();
      target.setSelectionRange(pos, pos);
      preview();
    }));
  };

  // 時間割: サブのキャンパスがあるとき（フォームに data-sub-mark がある）は、マスをタップするたびに 空 → メイン → サブ → 空 と回す。
  //   マスの中には、メイン用（cls[]）とサブ用（sub[]）の2つの隠しチェックがある。
  //   メイン = cls だけ ON / サブ = cls と sub の両方 ON / 空 = どちらも OFF
  //   label の普通のクリック（チェックの切り替え）は止めて、自分で2つの checked を決める
  const setupClassCycle = () => {
    document.querySelectorAll('form.sch-tt[data-sub-mark]').forEach((form) => {
      const mainMark = form.dataset.mainMark;
      const subMark = form.dataset.subMark;
      form.querySelectorAll('.sch-tt__cell').forEach((cell) => {
        const cls = cell.querySelector('input[name="cls[]"]');
        const sub = cell.querySelector('input[name="sub[]"]');
        const mark = cell.querySelector('span');
        if (!cls || !sub || !mark) return;
        cell.addEventListener('click', (e) => {
          e.preventDefault();
          // preventDefault したチェックボックスのクリックは、イベントの後に元の状態へ戻される（キーボードの空白キー）。
          //   戻されたあとに値を決めるため、次のタイミングで回す
          setTimeout(() => {
            if (!cls.checked) {
              cls.checked = true; sub.checked = false; // 空 → メイン
            } else if (!sub.checked) {
              sub.checked = true; // メイン → サブ
            } else {
              cls.checked = false; sub.checked = false; // サブ → 空
            }
            mark.dataset.mark = sub.checked ? subMark : mainMark;
            // 既存の自動保存は form の change イベントで動く（cls[]・sub[] の両方が送られる）
            cls.dispatchEvent(new Event('change', { bubbles: true }));
          }, 0);
        });
      });
    });
  };

  setupForm();
  setupTerms();
  setupEventFormat();
  setupClassCycle();
  // 時間割: チェックを入れるたびに自動保存（学期ごとのフォーム）
  document.querySelectorAll('form[data-autosave]').forEach((f) => {
    const a = makeAutosave(() => new FormData(f));
    f.addEventListener('change', a.schedule);
  });
  setupGrid();
  setupCands();
  setupOver();
  setupPhoto();
})();
