/*
 * app_timetable.js — タイムテーブル・名簿の編集（並び替え・休憩・追随・列・切り替えボタン）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * タイムテーブルの出演順を振り直す（並び替え・取込の切り替えのあとに呼ぶ）
 *   取込にチェックがある行を上から 1, 2, 3…。チェックが無い行（休憩など）は空。
 *   タイムテーブル編集（timetable_edit.php）は取込のチェックが無い = バンドの行に全部番号を振る。
 *     休憩の行（[data-order] が無い）は番号なし。全部の行の pos（上から何行目か）も振り直す。
 *   表示用の文字と、送信用の hidden（PHP の commit_import_plan が並べ替えに使う）の両方を書き換える。
 * ------------------------------------------------------------------- */
function renumberSlots(tbody) {
  let n = 0;
  [...tbody.rows].forEach((tr, i) => {
    const pos = tr.querySelector('[data-pos]');
    if (pos) pos.value = i;
    if (!tr.querySelector('[data-order]')) return; // 休憩の行
    const include = tr.querySelector('[data-include]');
    const no = !include || include.checked ? String(++n) : '';
    tr.querySelector('[data-order]').value = no;
    tr.querySelector('[data-order-text]').textContent = no;
  });
}

/**
 * ドラッグ中の自動スクロール: 指の高さ（getY。ドラッグが終わっていれば undefined）が画面の上端・下端に近いと、16ms ごとに 12px ずつスクロールして onScroll を呼ぶ。
 *   戻り値は setInterval の id（ドラッグが終わったら clearInterval）
 */
function autoScrollWhileDragging(getY, onScroll) {
  return setInterval(() => {
    const y = getY();
    if (y == null) return;
    const edge = 60;
    const dy = y < edge ? -12 : y > window.innerHeight - edge ? 12 : 0;
    if (dy) {
      window.scrollBy(0, dy);
      onScroll();
    }
  }, 16);
}

/* ---------------------------------------------------------------------
 * タイムテーブルの行の並び替え（左端の ≡ をドラッグ。↑↓キーでも動く）
 *   時間の列（data-time のセル）は「何行目か」に固定: 行を動かしたら、時間のセルだけ元の位置の行に戻す。
 *     取り込み（import.php）… hidden の at（どの枠の時間を使うか。PHP の commit_import_plan が見る）も位置に合わせて付け直す
 *     タイムテーブル編集 … 時間が入力欄なので、name をその行（tr の data-name-prefix = b[ID] / k[番号]）に付け直す
 *   data-free-time の行（タイムテーブル編集の「＋ 休憩を追加」で足した行）は例外で、時間を行に付けたまま動かす。
 *     位置に固定する行だけで「n 番目の時間」を数える（足した休憩を差し込んでも、ほかの行の時間はずれない）
 *   行を足したり消したりしたら、tbody に slots:changed イベントを出してもらい、今の並びで覚え直す
 *   行を動かし終わったら（ドラッグを離したとき / ↑↓キーで1行動かしたとき）、tbody に slots:moved を出す
 *     detail: { row, fromIndex, toIndex }（tbody の何行目から何行目へ。位置が変わったときだけ。setupTimetableFollow が使う）
 *   pointer イベントなのでマウスでも指でも動く（≡ には CSS で touch-action: none）。
 *
 * アニメーション（時間のセルは動かさない。動くのは時間以外のセルだけ）
 *   つかんだ行 … ポインタに合わせて transform で上下に付いてくる。離すと定位置へ滑って戻る
 *   ほかの行   … FLIP という手法で、場所が入れ替わるときにスッと滑らせる
 *     First: 動かす前の位置を測る → Last: DOM を入れ替えて新しい位置を測る
 *     → Invert: 差の分だけ元の位置へ戻して見せる → Play: 0 までアニメーション
 * ------------------------------------------------------------------- */
function setupSlotSort() {
  const bodies = [...document.querySelectorAll('tbody[data-sortable]')];
  if (!bodies.length) return;

  // 「動きを減らす」設定の人にはアニメーションしない
  // 動かすセル（時間以外。時間を行に付けたまま動かす行は全部）
  const cellsOf = (tr) => [...tr.querySelectorAll(tr.hasAttribute('data-free-time') ? 'td' : 'td:not([data-time])')];

  // 位置に固定する行（＝「＋ 休憩を追加」で足した行以外）
  const pinnedRows = (tbody) => [...tbody.rows].filter((tr) => !tr.hasAttribute('data-free-time'));
  // 今の並びで「n 番目の時間のセル」を覚えておく（並び替えても n 番目の時間はこのセルのまま）
  //   index = 行の中で何列目にあったか（戻すときに同じ列へ差し込む）
  const capture = (tbody) => pinnedRows(tbody).map((tr) => ({
    cells: [...tr.querySelectorAll('td[data-time]')].map((td) => ({ td, index: td.cellIndex })),
    at: tr.querySelector('[data-at]')?.value,
  }));
  const times = new Map(bodies.map((tbody) => [tbody, capture(tbody)]));
  document.addEventListener('slots:changed', (e) => { if (times.has(e.target)) times.set(e.target, capture(e.target)); });

  // 並びが変わったあとの後始末: 時間のセルを位置に戻し、出演順を振り直す
  const settle = (tbody) => {
    const slots = times.get(tbody);
    // いったん全部の時間のセルを外してから、n 番目の行に n 番目のセルを元の列へ差し込む
    // （外さずに1行ずつ入れ替えると、まだ直していない行のセルが混ざって列がずれる）
    slots.forEach((slot) => slot.cells.forEach(({ td }) => td.remove()));
    pinnedRows(tbody).forEach((tr, i) => {
      slots[i].cells.forEach(({ td, index }) => tr.insertBefore(td, tr.cells[index] || null));
      if (slots[i].at !== undefined) tr.querySelector('[data-at]').value = slots[i].at;
      // 時間の入力欄は「この位置に来た行」の値として送る
      if (tr.dataset.namePrefix) {
        tr.querySelectorAll('[data-time-field]').forEach((el) => { el.name = `${tr.dataset.namePrefix}[${el.dataset.timeField}]`; });
      }
    });
    renumberSlots(tbody);
  };

  // change() で DOM を入れ替え、skip 以外の行を元の見た目の位置から新しい位置へ滑らせる（FLIP）
  const flip = (tbody, change, skip) => {
    // 動いている途中の行もあるので、行（動かない）ではなく最初のセル（動いている）で見た目の位置を測る
    const before = new Map([...tbody.rows].map((tr) => [tr, cellsOf(tr)[0].getBoundingClientRect().top]));
    change();
    if (reduceMotion) return;
    [...tbody.rows].forEach((tr) => {
      if (tr === skip) return;
      const dy = before.get(tr) - tr.getBoundingClientRect().top;
      if (Math.abs(dy) < 1) return;
      cellsOf(tr).forEach((td) => {
        td.getAnimations().forEach((a) => a.cancel()); // 前のアニメーションの途中なら止めて、今の位置から始め直す
        td.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 180, easing: 'ease-out' });
      });
    });
  };

  let drag = null; // ドラッグ中だけ { row, tbody, from, pointerId, y, grab, off, timer }（from = つかんだときの行番号）

  // 動かし終わったら「何行目から何行目へ動いたか」を知らせる（位置が変わっていなければ何も出さない）
  const announceMove = (tbody, row, fromIndex) => {
    const toIndex = [...tbody.rows].indexOf(row);
    if (toIndex !== fromIndex) tbody.dispatchEvent(new CustomEvent('slots:moved', { bubbles: true, detail: { row, fromIndex, toIndex } }));
  };

  // つかんだ行をポインタに付いてこさせる。off = 定位置からどれだけずらして見せているか
  const follow = () => {
    drag.off = drag.y - drag.grab - drag.row.getBoundingClientRect().top;
    cellsOf(drag.row).forEach((td) => { td.style.transform = `translateY(${drag.off}px)`; });
  };

  // ポインタの高さに合わせて行を差し込む: 自分以外で「真ん中がポインタより下」の最初の行の前へ（無ければ一番下）
  const moveTo = (y) => {
    const { row, tbody } = drag;
    const target = [...tbody.rows].find((r) => r !== row && y < r.getBoundingClientRect().top + r.offsetHeight / 2);
    if (!(target ? row.nextElementSibling === target : tbody.lastElementChild === row)) { // 位置が変わるときだけ
      flip(tbody, () => {
        tbody.insertBefore(row, target || null);
        settle(tbody);
      }, row);
    }
    follow(); // 行の定位置が変わったので、ずらす量を計算し直す
  };

  const end = () => {
    if (!drag) return;
    const { row, tbody, from, off, timer } = drag;
    clearInterval(timer);
    drag = null;
    document.body.classList.remove('is-row-dragging');
    // 離した場所から定位置へ滑って戻る。戻り終わってから「浮いている」見た目を外す
    cellsOf(row).forEach((td) => {
      td.style.transform = '';
      if (!reduceMotion && off) td.animate([{ transform: `translateY(${off}px)` }, { transform: 'none' }], { duration: 150, easing: 'ease-out' });
    });
    setTimeout(() => { if (drag?.row !== row) row.classList.remove('is-dragging'); }, reduceMotion ? 0 : 150);
    announceMove(tbody, row, from);
  };

  document.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('[data-drag-handle]');
    if (!handle || drag || (e.pointerType === 'mouse' && e.button !== 0)) return;
    e.preventDefault(); // 文字選択やスクロールを始めさせない
    const row = handle.closest('tr');
    // スマホは出演者が右にスクロールして隠れているので、つかんだら左端へ戻す（ドラッグ中もバンド名が見えるように）
    const scroller = row.closest('.table-scroll');
    if (scroller && scroller.scrollLeft) scroller.scrollLeft = 0;
    cellsOf(row).forEach((td) => td.getAnimations().forEach((a) => a.cancel()));
    // grab = 行の上端から、つかんだ所までの高さ（これを保ったまま付いてこさせる）
    drag = { row, tbody: row.parentElement, from: [...row.parentElement.rows].indexOf(row), pointerId: e.pointerId, y: e.clientY, grab: e.clientY - row.getBoundingClientRect().top, off: 0 };
    handle.setPointerCapture(e.pointerId); // 指やマウスが ≡ の外に出ても追いかける
    row.classList.add('is-dragging');
    document.body.classList.add('is-row-dragging');
    // 画面の上端・下端に近づけたら自動でスクロール（長い表を一度に動かせるように）
    drag.timer = autoScrollWhileDragging(() => drag?.y, () => moveTo(drag.y));
  });
  document.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    drag.y = e.clientY;
    moveTo(e.clientY);
  });
  document.addEventListener('pointerup', end);
  document.addEventListener('pointercancel', end);

  // キーボード: ≡ にフォーカスして ↑↓ で1行ずつ動かす
  document.addEventListener('keydown', (e) => {
    const handle = e.target.closest?.('[data-drag-handle]');
    if (!handle || drag || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    const row = handle.closest('tr');
    const tbody = row.parentElement;
    const prev = row.previousElementSibling;
    const next = row.nextElementSibling;
    if (e.key === 'ArrowUp' ? !prev : !next) return; // 端なのでもう動けない
    const from = [...tbody.rows].indexOf(row);
    flip(tbody, () => {
      if (e.key === 'ArrowUp') tbody.insertBefore(row, prev);
      else tbody.insertBefore(next, row);
      settle(tbody);
    });
    handle.focus(); // insertBefore で動かすとフォーカスが外れるブラウザがあるので戻す
    announceMove(tbody, row, from);
  });
}

/* ---------------------------------------------------------------------
 * 名簿の表に列を足す（「＋ 列を追加」ボタン）
 *   名簿に載っていない人や、1つのセルに書かれていた3人目以降のために、
 *   表の右端へ「Other」列（名前の入力欄 + 全部の楽器の切り替えボタン）を足す。
 *   name="rb[名簿][バンド][x][列番号][name]" の形にしておけば、PHP 側は他の追加列と同じように受け取れる。
 * ------------------------------------------------------------------- */
function setupRosterColumns() {
  const tpl = document.getElementById('tpl-extra-instrument');
  if (!tpl) return;

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-add-roster-col]');
    if (!btn) return;
    const table = btn.closest('section').querySelector('[data-roster-table]');
    const n = Number(table.dataset.extraCols); // 新しい列の番号（0 始まり）
    table.dataset.extraCols = n + 1;

    const th = document.createElement('th');
    th.textContent = 'Other';
    table.querySelector('thead tr').appendChild(th);

    table.querySelectorAll('tbody tr[data-roster-key]').forEach((tr) => {
      const [ri, bi] = tr.dataset.rosterKey.split(':');
      const base = `rb[${ri}][${bi}][x][${n}]`;
      const td = document.createElement('td');
      const input = document.createElement('input');
      input.name = `${base}[name]`;
      input.className = 'name-input';
      input.dataset.nameCell = '';
      input.dataset.suggestList = 'member-names'; // 入力候補（setupSuggest）
      input.autocomplete = 'off';
      input.setAttribute('aria-label', 'メンバー');
      // 楽器の切り替えボタンを複製して、ラジオボタンの name をこのセル用に付け直す（初期値はキーボード、名前が入るまで畳む）
      const pick = tpl.content.firstElementChild.cloneNode(true);
      pick.querySelectorAll('input').forEach((radio) => { radio.name = `${base}[inst]`; });
      pick.dataset.prev = pick.querySelector('input:checked')?.value || '';
      td.append(input, pick);
      tr.appendChild(td);
    });
    // 1行目の新しい入力欄にカーソルを置く
    table.querySelector(`tbody tr [name$="[x][${n}][name]"]`)?.focus();
  });

  // 名前が入ったら下の切り替えボタンを出し、空にしたら畳む（アニメーションは CSS）
  document.addEventListener('input', (e) => {
    if (!e.target.matches('.table--roster [data-name-cell]')) return;
    const select = e.target.parentElement.querySelector('.cell-instrument');
    if (select) select.classList.toggle('is-collapsed', e.target.value.trim() === '');
  });
}

/* ---------------------------------------------------------------------
 * タイムテーブル編集（timetable_edit.php）: 休憩の行の追加・削除
 *   出演者は名前だけ（楽器はバンドのページで直す）
 *   「＋ 列を追加」→ その日の表のバンドの行の右端に「名前」のセルを1つ足す（休憩の行は横に1マスつなげて伸ばす）
 *     name は b[バンドID][m][列の番号][id / name]。番号は data-cols（今ある列の数）から振る
 *   「＋ 休憩を追加」→ <template id="tpl-tt-break"> の行を表の一番下に足す（≡ で好きな所へ動かす）
 *   「この行を消す」→ 休憩の行を消す
 *   行を足す・消すときは slots:changed を出して、setupSlotSort に時間の並びを覚え直してもらう
 *   時刻の追随（ON の間、時刻を変えると下の行の時刻もずれる）は、次の setupTimetableFollow
 * ------------------------------------------------------------------- */
function setupTimetableColumns() {
  if (!document.querySelector('[data-tt-table]')) return;

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-add-tt-col]');
    if (!btn) return;
    const table = btn.closest('section').querySelector('[data-tt-table]');
    const n = Number(table.dataset.cols); // 新しい列の番号（0 始まり）
    table.dataset.cols = n + 1;
    table.querySelectorAll('[data-tt-members-head], [data-break-fill]').forEach((td) => { td.colSpan = n + 1; });

    table.querySelectorAll('tbody tr[data-name-prefix]:not(.tt-break)').forEach((tr) => {
      const base = `${tr.dataset.namePrefix}[m][${n}]`;
      const td = document.createElement('td');
      const input = document.createElement('input');
      input.name = `${base}[name]`;
      input.className = 'name-input';
      input.dataset.nameCell = '';
      input.dataset.suggestList = 'member-names'; // 入力候補（setupSuggest）
      input.autocomplete = 'off';
      input.setAttribute('aria-label', '出演者');
      const id = document.createElement('input'); // 元の人（新しく足した列なので空 = 新しい人。楽器は「その他」で登録される）
      id.type = 'hidden';
      id.name = `${base}[id]`;
      td.append(id, input);
      tr.appendChild(td);
    });
    table.querySelector(`tbody tr [name$="[m][${n}][name]"]`)?.focus();
  });

  const breakTpl = document.getElementById('tpl-tt-break');
  const nextBreak = document.querySelector('[data-next-break]');
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-tt-break]');
    if (add) {
      const table = add.closest('section').querySelector('[data-tt-table]');
      const tbody = table.tBodies[0];
      const n = nextBreak.value++;
      const tr = breakTpl.content.querySelector('tr').cloneNode(true);
      tr.dataset.namePrefix = `k[${n}]`;
      tr.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace('k[__N__]', `k[${n}]`); });
      tr.querySelector('[name$="[day]"]').value = add.dataset.day;
      tr.querySelector('[data-break-fill]').colSpan = Number(table.dataset.cols);
      // 時刻の初期値: 開始 = 表の一番下の行の終了、終了 = 開始 + 10 分（一番下の終了が空なら空のまま）。一番下に足すだけなので追随はしない
      const lastEnd = tbody.rows.length ? timeToMin(tbody.rows[tbody.rows.length - 1].querySelector('[data-time-field="end"]')?.value) : null;
      if (lastEnd !== null && lastEnd + 10 <= 24 * 60 - 1) {
        setTimeField(tr.querySelector('[data-time-field="start"]'), minToTime(lastEnd));
        setTimeField(tr.querySelector('[data-time-field="end"]'), minToTime(lastEnd + 10));
      }
      tbody.appendChild(tr);
      tbody.dispatchEvent(new Event('slots:changed', { bubbles: true }));
      renumberSlots(tbody);
      const name = tr.querySelector('[name$="[name]"]');
      name.focus();
      name.select();
      return;
    }
    const remove = e.target.closest('[data-remove-break]');
    if (remove) {
      const tbody = remove.closest('tbody');
      remove.closest('tr').remove();
      tbody.dispatchEvent(new Event('slots:changed', { bubbles: true }));
      renumberSlots(tbody);
    }
  });

  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-tt-table] [data-name-cell]')) return;
    e.target.parentElement.querySelector('.cell-instrument')?.classList.toggle('is-collapsed', e.target.value.trim() === '');
  });
}

/* ---------------------------------------------------------------------
 * タイムテーブル編集（timetable_edit.php）: 時刻の「追随」
 *   表の「開始」の見出しにある 🔗（[data-tt-follow]）が ON の間、時刻を変えると下の行の時刻も同じ分だけ一緒にずれる。
 *   ページ内の全部の日程が同じ状態（既定は ON。保存はしない）。ずれた結果を入力欄に入れるだけで、保存は今までどおりフォームの送信。
 *   「行」= tbody の上から順の tr（バンドの行も休憩の行も）。時刻の入力欄は行の [data-time-field="start" / "end"]。
 *     開始を変えた … その行の終了と、下の全部の行の開始・終了を、変えた分（Δ）だけずらす（1行目の開始 = 集合時間のズレ）
 *     終了を変えた … 下の全部の行の開始・終了を Δ だけずらす
 *     足した休憩（tr[data-free-time]）を動かした … 抜けた分を詰めて、動かした先の直前の行の終了から始まるように入れ直す
 *     足した休憩を消す … 消す前に、下の行をその休憩の長さの分だけ詰める
 *   変更前の値は各入力欄の data-prev に覚えておく（ページを開いたとき・JS が書き換えるたび）。空の欄はずらさない・追随もしない。
 *   0:00〜23:59 を出る行があるときは、何も変えずに元の値へ戻して、お知らせ（トースト）を出す。
 *   色が付くのはエラーのときだけ（is-error）: 開始が上の行の終了より早い（重なり）/ 終了が開始以前。
 *     変えたこと自体には色を付けない（追随で下の全行が動くので、全部が塗られてエラーに見えてしまう）。
 * ------------------------------------------------------------------- */

/** 'HH:MM'（'HH:MM:SS' も可）を 0 時からの分にする。空・不正は null */
function timeToMin(value) {
  const m = /^(\d{1,2}):(\d{2})/.exec(value || '');
  return m ? Number(m[1]) * 60 + Number(m[2]) : null;
}

/** 0 時からの分を 'HH:MM' にする（time 入力欄の value の形） */
function minToTime(min) {
  return `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
}

/**
 * 時刻のエラーの欄に is-error を付け外しする（表ごとに、上の行から順に見る）
 *   ・終了が開始以前 → その行の開始・終了
 *   ・開始が、上の行の終了（いちばん遅いもの）より早い = 重なり → その行の開始
 *   空の欄は見ない。保存はエラーでも止めない（色で気づかせるだけ）
 */
function checkTimes() {
  document.querySelectorAll('[data-tt-table] tbody').forEach((tbody) => {
    let prevEnd = null; // ここまでの行の終了の最大
    [...tbody.rows].forEach((tr) => {
      const startInput = tr.querySelector('[data-time-field="start"]');
      const endInput = tr.querySelector('[data-time-field="end"]');
      if (!startInput || !endInput) return;
      const start = timeToMin(startInput.value);
      const end = timeToMin(endInput.value);
      const badRange = start !== null && end !== null && end <= start;
      const overlap = start !== null && prevEnd !== null && start < prevEnd;
      startInput.classList.toggle('is-error', badRange || overlap);
      endInput.classList.toggle('is-error', badRange);
      const last = end ?? start;
      if (last !== null) prevEnd = prevEnd === null ? last : Math.max(prevEnd, last);
    });
  });
}

/** 時刻の入力欄に JS から値を入れる（変更前の値の記憶と、エラーの色も合わせて更新する） */
function setTimeField(input, value) {
  input.value = value;
  input.dataset.prev = value;
  checkTimes();
}

function setupTimetableFollow() {
  if (!document.querySelector('[data-tt-table]')) return;
  let following = true; // 追随が ON か（全部の日程で共通。開き直すと ON）

  const fieldOf = (tr, kind) => tr.querySelector(`[data-time-field="${kind}"]`);
  const allFields = () => [...document.querySelectorAll('[data-tt-table] [data-time-field]')];

  // ページを開いたときの値を覚え、エラーの色も付ける（ブラウザが戻る操作で入力を復元していることもあるので、そのときも合わせる）
  allFields().forEach((input) => { input.dataset.prev = input.value; });
  checkTimes();
  // 行を足す・消す・動かしたときも見直す（追随が OFF のときは setTimeField を通らないので）
  ['slots:changed', 'slots:moved'].forEach((type) => document.addEventListener(type, checkTimes));

  // 日付をまたぐときのお知らせ（既存のトースト。お知らせの入れ物が無ければ、入力欄の標準のメッセージで）
  const warn = (input) => {
    const text = '日付をまたぐため追随できません';
    if (document.querySelector('[data-toasts]')) {
      flashToast(text, 'error');
      return;
    }
    input.setCustomValidity(text);
    input.reportValidity();
    input.setCustomValidity('');
  };

  // 入力欄の値を delta 分ずらす。空の欄は飛ばす。1つでも 0:00〜23:59 を出るなら、何も変えずに false
  const shiftFields = (inputs, delta) => {
    const plan = [];
    for (const input of inputs) {
      const min = timeToMin(input.value);
      if (min === null) continue;
      if (min + delta < 0 || min + delta > 24 * 60 - 1) return false;
      plan.push([input, min + delta]);
    }
    plan.forEach(([input, min]) => setTimeField(input, minToTime(min)));
    return true;
  };

  // tbody の全部の時刻欄の値を覚える / 覚えた値へ戻す（途中で失敗したときに、何もなかったことにする）
  const snapshot = (tbody) => [...tbody.querySelectorAll('[data-time-field]')].map((input) => [input, input.value]);
  const restore = (snap) => snap.forEach(([input, value]) => setTimeField(input, value));

  // 行の時刻欄（開始・終了）を、上の行から順に並べたもの
  const fieldsIn = (rows) => rows.flatMap((tr) => [fieldOf(tr, 'start'), fieldOf(tr, 'end')]).filter(Boolean);

  // ---- トグル: 押すと全部の日程が同じ状態になる ----
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-tt-follow]');
    if (!btn) return;
    following = !following;
    document.querySelectorAll('[data-tt-follow]').forEach((b) => {
      b.setAttribute('aria-pressed', String(following));
      b.querySelector('.icon').textContent = following ? 'link' : 'link_off';
    });
  });

  // ---- オムニバスの一括チェック（見出しのチェックで、その日程の（外せるものすべて）を切り替える） ----
  document.addEventListener('change', (e) => {
    const all = e.target.closest?.('[data-omnibus-all]');
    if (!all) return;
    all.closest('table')?.querySelectorAll('[data-omnibus-check]:not(:disabled)').forEach((c) => { c.checked = all.checked; });
  });

  // ---- 時刻を変えた ----
  document.addEventListener('change', (e) => {
    const input = e.target;
    if (!input.matches?.('[data-tt-table] [data-time-field]')) return;
    const prev = input.dataset.prev ?? '';
    checkTimes();
    const before = timeToMin(prev);
    const after = timeToMin(input.value);
    // 次に変えたときの「変更前」。打っている途中で空になったとき（Backspace など）は前の値を残す
    //   （空で上書きすると、続けて打った値との差が分からなくなり、追随が切れる）
    if (after !== null || before === null) input.dataset.prev = input.value;
    if (!following || before === null || after === null || before === after) return;

    const delta = after - before;
    const tr = input.closest('tr');
    const rows = [...tr.parentElement.rows];
    const below = rows.slice(rows.indexOf(tr) + 1);
    // 開始を変えたら、その行の終了も。それより下の行は開始・終了とも
    const targets = [...(input.dataset.timeField === 'start' ? [fieldOf(tr, 'end')] : []), ...fieldsIn(below)].filter(Boolean);
    if (!shiftFields(targets, delta)) {
      setTimeField(input, prev); // 日付をまたぐので、変えた欄も元の値に戻す
      warn(input);
    }
  });

  // ---- 足した休憩の行を動かした（setupSlotSort が slots:moved を出す） ----
  document.addEventListener('slots:moved', (e) => {
    const { row, fromIndex, toIndex } = e.detail;
    const start = fieldOf(row, 'start');
    const end = fieldOf(row, 'end');
    // 位置に固定の行は、時刻がその位置に残るので何もしない。開始か終了が空なら長さが分からないので何もしない
    if (!following || !row.hasAttribute('data-free-time') || !start || !end) return;
    const length = timeToMin(end.value) - timeToMin(start.value);
    if (!(length > 0)) return; // どちらかが空（NaN）か、長さが 0 以下
    const tbody = row.parentElement;
    const others = [...tbody.rows].filter((tr) => tr !== row); // 動かした行を除いた並び（上下どちらへ動かしても同じ数え方になる）
    const snap = snapshot(tbody);

    // ① 元の位置より下にあった行を、抜けた分だけ詰める
    let ok = shiftFields(fieldsIn(others.slice(fromIndex)), -length);
    // ② 動かした行の開始 = 新しい位置の直前の行の終了（先頭に動かしたときは、いま下にいる行の開始）。終了 = 開始 + 長さ
    const ref = toIndex > 0 ? fieldOf(others[toIndex - 1], 'end') : others[toIndex] ? fieldOf(others[toIndex], 'start') : null;
    const refMin = timeToMin(ref?.value);
    if (ok && (refMin === null || refMin + length > 24 * 60 - 1)) {
      restore(snap);
      if (refMin !== null) warn(row); // 起点の時刻が空のときは黙って元のまま。足りなくて入らないときだけお知らせ
      return;
    }
    if (ok) {
      setTimeField(start, minToTime(refMin));
      setTimeField(end, minToTime(refMin + length));
      // ③ 新しい位置より下の行を、入れた分だけ繰り下げる
      ok = shiftFields(fieldsIn(others.slice(toIndex)), length);
    }
    if (!ok) {
      restore(snap);
      warn(row);
    }
  });

  // ---- 足した休憩の行を消す: 消す前に、下の行をその休憩の長さの分だけ詰める（capture 段階で待つので、行が消える前に動く） ----
  document.addEventListener('click', (e) => {
    const remove = e.target.closest('[data-remove-break]');
    if (!remove || !following) return;
    const tr = remove.closest('tr');
    const start = timeToMin(fieldOf(tr, 'start')?.value);
    const end = timeToMin(fieldOf(tr, 'end')?.value);
    if (start === null || end === null) return;
    const rows = [...tr.parentElement.rows];
    if (!shiftFields(fieldsIn(rows.slice(rows.indexOf(tr) + 1)), -(end - start))) warn(remove); // 詰められないときは、そのまま消すだけ
  }, true);
}

/* ---------------------------------------------------------------------
 * 名簿の切り替えボタン「Vo / Vo/Gt / ⋯」「Key / Vn / ⋯」（HTML は import.php の render_pick）
 *   「⋯」で残りの選択肢を出す
 *     マウス: 乗せると出る（CSS の :hover）。クリックでも開け閉めできる
 *     スマホ: タップで開け閉め。選んだら閉じる。外をタップしても閉じる
 *     開いた選択肢が表の横スクロールの外（スマホで右端の外）なら、見える所まで表をスクロールする
 *   しまってある選択肢を選んだら、「⋯」のボタンにその名前（Vo/Ba など）を出す
 *
 *   楽器の「etc」を選んだら、モーダルで新しい楽器（略称・楽器名）を書いてもらう
 *     追加する → api_instrument.php で楽器マスタに登録し、全部の楽器の欄に足して、この欄ではそれを選ぶ
 *     キャンセル / Esc → 直前に選んでいたものに戻す（だから選ぶたびに data-prev に覚えておく）
 * ------------------------------------------------------------------- */
function setupPicks() {
  if (!document.querySelector('[data-pick-more]')) return;

  const setOpen = (more, open) => {
    more.classList.toggle('is-open', open);
    more.querySelector('[data-pick-toggle]').setAttribute('aria-expanded', String(open));
    if (open) {
      // nearest = 隠れているときだけ、ちょうど見える分だけ動かす（見えていれば動かない）
      more.querySelector('.pick__menu').scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    }
  };

  // 「⋯」の文字を今の選択に合わせる（しまってある方を選んでいればその名前、そうでなければ空 = CSS が「⋯」を描く）
  const syncToggle = (group) => {
    const hidden = group.querySelector('.pick__menu input:checked');
    group.querySelector('[data-pick-toggle]').textContent = hidden ? hidden.nextElementSibling.textContent : '';
  };

  // 値を選んだ状態にする（直前の選択としても覚える）
  const choose = (group, value) => {
    const radio = [...group.querySelectorAll('input')].find((r) => r.value === value);
    if (radio) radio.checked = true;
    group.dataset.prev = value;
    syncToggle(group);
  };

  document.querySelectorAll('[data-pick]').forEach((group) => {
    group.dataset.prev = group.querySelector('input:checked')?.value || '';
  });

  document.addEventListener('click', (e) => {
    const more = e.target.closest('[data-pick-more]');
    // ほかの「⋯」は全部閉じる（外をタップしたときも、ここで閉じる）
    document.querySelectorAll('[data-pick-more].is-open').forEach((m) => { if (m !== more) setOpen(m, false); });
    if (e.target.closest('[data-pick-toggle]')) setOpen(more, !more.classList.contains('is-open'));
  });

  // ---- etc → 楽器追加のモーダル ----
  const dialog = document.querySelector('[data-new-instrument]');
  let pending = null; // モーダルを開いている間だけ { group: どの欄か, prev: 直前の選択 }

  const showError = (message) => {
    const box = dialog.querySelector('[data-modal-error]');
    box.textContent = message;
    box.hidden = message === '';
  };

  // モーダルを閉じて、この欄の選択を決める（value が null なら直前の選択に戻す）
  const finish = (value) => {
    if (!pending) return;
    const { group, prev } = pending;
    pending = null;
    choose(group, value ?? prev);
    if (dialog.open) dialog.close();
  };

  // 新しい楽器を、画面にある全部の楽器の欄（と「＋ 列を追加」のひな形）の「⋯」の中、etc の手前に足す
  const addInstrumentOption = (ins) => {
    const value = String(ins.instrument_id);
    const groups = [...document.querySelectorAll('[data-pick-kind="inst"]')];
    const tpl = document.getElementById('tpl-extra-instrument');
    if (tpl) groups.push(...tpl.content.querySelectorAll('[data-pick-kind="inst"]'));
    groups.forEach((group) => {
      if ([...group.querySelectorAll('input')].some((r) => r.value === value)) return; // もうある（同じ略称の楽器が既にあった）
      // textContent / createElement で作る（入力された文字を innerHTML に入れると XSS になる）
      const label = document.createElement('label');
      label.title = ins.name;
      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = group.querySelector('input').name;
      radio.value = value;
      radio.setAttribute('aria-label', ins.name);
      const span = document.createElement('span');
      span.textContent = ins.short_name;
      label.append(radio, span);
      const menu = group.querySelector('.pick__menu');
      menu.insertBefore(label, menu.querySelector('[data-pick-add]')?.closest('label') ?? null);
    });
  };

  const openDialog = (group) => {
    pending = { group, prev: group.dataset.prev || '' };
    dialog.querySelector('form').reset();
    showError('');
    dialog.showModal(); // showModal = 後ろの画面を触れなくする本物のモーダル。Esc で閉じる
  };

  document.addEventListener('change', (e) => {
    const group = e.target.closest('[data-pick]');
    if (!group) return;
    setOpen(group.querySelector('[data-pick-more]'), false); // 選んだら閉じる
    syncToggle(group);
    if (e.target.matches('[data-pick-add]') && dialog) {
      openDialog(group);
      return;
    }
    group.dataset.prev = e.target.value;
  });

  // もう etc を選んでいる欄で etc を押し直したとき。選択が変わらないので change が起きない → ここでモーダルを開く
  // （click は change より先に起きる。data-prev がまだ etc = 「押す前から etc だった」）
  document.addEventListener('click', (e) => {
    if (!dialog || !e.target.matches('[data-pick-add]')) return;
    const group = e.target.closest('[data-pick]');
    if (group.dataset.prev === e.target.value) {
      setOpen(group.querySelector('[data-pick-more]'), false);
      openDialog(group);
    }
  });

  if (!dialog) return;
  const form = dialog.querySelector('form');

  dialog.querySelector('[data-modal-cancel]').addEventListener('click', () => finish(null));
  // 楽器は追加せず、この欄の etc を選んだまま閉じる
  dialog.querySelector('[data-modal-keep-etc]').addEventListener('click', () => {
    finish(pending?.group.querySelector('[data-pick-add]')?.value ?? null);
  });
  // Esc キーで閉じたとき（cancel は Esc を押した瞬間に起きる）。直前の選択に戻す
  dialog.addEventListener('cancel', () => finish(null));
  // それ以外の閉じ方の保険。まだ決まっていなければ（= キャンセル扱い）直前の選択に戻す
  dialog.addEventListener('close', () => finish(null));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const shortName = form.elements.short_name.value.trim();
    const name = form.elements.name.value.trim();
    if (shortName === '' || name === '') {
      showError('略称と楽器名の両方を入力してください');
      return;
    }
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true; // 二重送信防止
    try {
      const res = await postJson('api_instrument', { short_name: shortName, name });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) {
        showError(data.error || '追加できませんでした。ページを再読み込みしてやり直してください');
        return;
      }
      addInstrumentOption(data);
      finish(String(data.instrument_id));
    } catch {
      showError('通信できませんでした。もう一度押してください');
    } finally {
      submit.disabled = false;
    }
  });
}
