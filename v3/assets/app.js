/*
 * =====================================================================
 *  app.js — 画面を便利にする JavaScript
 * =====================================================================
 *  方針: JavaScript が動かなくてもサイトの機能は全部使えるようにしてある。
 *        ここに書いてあるのは「あると便利」な部分だけ。
 *
 *  data-◯◯ 属性（HTML 側に書いた目印）で「どの要素に何をするか」を決めている。
 *    data-theme-toggle  … ライト/ダーク切り替えボタン
 *    data-filter        … 一覧の絞り込み検索
 *    data-tab           … ライブ詳細の日程タブ
 *    data-confirm       … 送信前の確認ダイアログ
 *    data-dropzone      … ファイルのドラッグ&ドロップ
 *    data-name-cell     … 名前の入力欄（DB にいるかで色が変わる）
 *    data-roster-input  … タイムテーブルの枠 → 名簿のバンド（検索欄）
 *    data-add-roster-col … 名簿の表の右端に「Other」列を足す
 *    data-pick          … 名簿の「Vo / Gt/Vo / ⋯」「Key / Vn / ⋯」の切り替えボタンと、etc の楽器追加モーダル
 *    .table-scroll      … 横にはみ出す表をマウスのドラッグで左右に動かす
 *    data-pack          … 送信時に全項目を JSON 1個にまとめるフォーム
 *    data-rows          … バンド編集のメンバー行（追加・削除）
 *    data-print / data-autosubmit … 印刷ボタン / 選んだら即送信
 *    data-song-list / data-add-song … 曲の編集
 * =====================================================================
 */

// HTML の読み込みが終わってから動かす（まだ無い要素は探せないので）
document.addEventListener('DOMContentLoaded', () => {
  setupThemeToggle();
  setupFilter();
  setupTabs();
  setupConfirm();
  setupDropzone();
  setupNameCheck();
  setupImportPreview();
  setupRosterColumns();
  setupPicks();
  setupDragScroll();
  setupPackedForm();
  setupMemberRows();
  setupSmallThings();
  setupSongs();
});

/* ---------------------------------------------------------------------
 * 曲の編集（songs_edit.php）
 *   data-add-song       … 最後のカードをコピーして空の曲カードを足す
 *   data-toggle-all     … そのカードの全員のチェックを一括で ON / OFF
 *   data-performer-on   … チェックを外した人を薄く表示
 * ------------------------------------------------------------------- */
function setupSongs() {
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  document.querySelector('[data-add-song]').addEventListener('click', () => {
    const cards = list.querySelectorAll('[data-song-card]');
    const last = cards[cards.length - 1];
    const card = last.cloneNode(true);
    const newIndex = Date.now(); // name="songs[3][title]" の 3 の部分を、他と被らない番号にする
    card.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace(/^songs\[[^\]]+\]/, `songs[${newIndex}]`);
    });
    card.querySelector('[name$="[title]"]').value = '';
    card.querySelector('[name$="[id]"]').value = '';                       // 新しい曲として保存させる
    card.querySelector('[name$="[delete]"]')?.closest('label').remove();  // 新しい曲に「削除」は不要
    const order = card.querySelector('[name$="[order]"]');
    order.value = String(Number(order.value || cards.length) + 1);
    card.querySelectorAll('[data-performer-on]').forEach((cb) => { cb.checked = true; cb.closest('.performer').classList.remove('is-off'); });
    card.classList.add('song-card--new');
    list.appendChild(card);
    card.querySelector('[name$="[title]"]').focus();
  });

  // カードが後から増えるので、list でまとめてイベントを受ける（イベント委譲）
  list.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-toggle-all]');
    if (!btn) return;
    const boxes = [...btn.closest('[data-song-card]').querySelectorAll('[data-performer-on]')];
    const turnOn = boxes.some((b) => !b.checked); // 1人でもOFFなら全員ON、全員ONなら全員OFF
    boxes.forEach((b) => { b.checked = turnOn; b.closest('.performer').classList.toggle('is-off', !turnOn); });
  });
  list.addEventListener('change', (e) => {
    if (e.target.matches('[data-performer-on]')) {
      e.target.closest('.performer').classList.toggle('is-off', !e.target.checked);
    }
  });
}

/* ---------------------------------------------------------------------
 * こまごました動き
 *   data-print      … クリックで印刷ダイアログ
 *   data-autosubmit … セレクトボックスやラジオボタンを変えたらすぐフォームを送信（集計の絞り込み）
 * ------------------------------------------------------------------- */
function setupSmallThings() {
  document.querySelectorAll('[data-print]').forEach((btn) => btn.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-autosubmit]').forEach((sel) => sel.addEventListener('change', () => sel.form.submit()));
}

/* ---------------------------------------------------------------------
 * ライト/ダーク切り替え
 *   <html data-theme="dark"> にすると CSS の [data-theme="dark"] のルールが効く。
 *   選んだテーマは localStorage に保存（次に開いたときも同じテーマ）。
 *   最初のテーマ決定は partials/header.php の <head> 内でやっている（ちらつき防止）。
 * ------------------------------------------------------------------- */
function setupThemeToggle() {
  document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) { /* プライベートモード等で保存できなくても無視 */ }
    });
  });
}

/* ---------------------------------------------------------------------
 * 絞り込み検索: 入力した文字を含まない行（カード）を隠す
 *   <input data-filter=".member-row"> → .member-row の data-text を見て絞る
 * ------------------------------------------------------------------- */
function setupFilter() {
  document.querySelectorAll('[data-filter]').forEach((input) => {
    const items = document.querySelectorAll(input.dataset.filter);
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      items.forEach((el) => {
        const text = (el.dataset.text || el.textContent).toLowerCase();
        el.hidden = q !== '' && !text.includes(q);
      });
    });
  });
}

/* ---------------------------------------------------------------------
 * ライブ詳細の日程タブ
 *   URL の #day-12 を見て、その日程だけ表示する（リンクで直接その日を開ける）
 * ------------------------------------------------------------------- */
function setupTabs() {
  const tabs = document.querySelectorAll('[data-tab]');
  if (!tabs.length) return;

  const showDay = (hash) => {
    const target = hash && document.querySelector(hash);
    if (!target || !target.classList.contains('day')) return;
    document.querySelectorAll('.day').forEach((d) => { d.hidden = d !== target; });
    tabs.forEach((t) => t.classList.toggle('is-active', t.getAttribute('href') === '#' + target.id));
  };

  // JS が動くときだけ2日目以降を隠す（動かなければ全日程が縦に並ぶ）
  document.querySelectorAll('.day[data-hidden]').forEach((d) => { d.hidden = true; });
  tabs.forEach((t) => t.addEventListener('click', (e) => {
    e.preventDefault(); // 本来の「#へジャンプ」を止める
    history.replaceState(null, '', t.getAttribute('href'));
    showDay(t.getAttribute('href'));
  }));
  showDay(location.hash);
}

/* ---------------------------------------------------------------------
 * 削除などの前に「本当に？」と聞く
 * ------------------------------------------------------------------- */
function setupConfirm() {
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!confirm(form.dataset.confirm)) e.preventDefault();
    });
  });
}

/* ---------------------------------------------------------------------
 * ファイル選択欄: 選んだファイル名を一覧表示 & ドラッグ中の見た目
 * ------------------------------------------------------------------- */
function setupDropzone() {
  const drop = document.querySelector('[data-dropzone]');
  if (!drop) return;
  const input = drop.querySelector('[data-file-input]');
  const list = drop.querySelector('[data-file-list]');
  input.addEventListener('change', () => {
    list.replaceChildren(...[...input.files].map((f) => {
      const li = document.createElement('li');
      li.textContent = `${f.name.toLowerCase().endsWith('.pdf') ? 'PDF' : 'CSV'} · ${f.name}`; // textContent なので XSS にならない
      return li;
    }));
  });
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.add('is-over')));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
}

/* ---------------------------------------------------------------------
 * 名前の入力欄の色分け（元の m6 の data_upload.js と同じ考え方）
 *
 *   緑 (is-ok)      … DB に登録済み
 *   黄 (is-similar) … 未登録だけど似た人がいる（書き間違い？）
 *   赤 (is-new)     … 未登録 → 新しいメンバーとして登録される
 *
 *   入力が止まって 300ms たったら api_name_check.php に全セルを送り、結果で class を付け替える。
 *   （1文字打つごとに通信すると重いので、少し待つ = デバウンス）
 * ------------------------------------------------------------------- */
function setupNameCheck() {
  const cells = () => [...document.querySelectorAll('[data-name-cell]')];
  if (!cells().length) return;

  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let timer = null;
  let requestNo = 0; // 古い通信の結果が後から届いて上書きしないように番号を振る

  const check = async () => {
    const inputs = cells();
    const myNo = ++requestNo;
    try {
      const res = await fetch('api_name_check.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ cells: inputs.map((i) => i.value) }),
      });
      if (!res.ok || myNo !== requestNo) return;
      const results = await res.json();
      inputs.forEach((input, i) => {
        const r = results[i] || { status: '', hint: '' };
        input.classList.remove('is-ok', 'is-similar', 'is-new');
        if (r.status) input.classList.add('is-' + r.status);
        input.title = r.hint; // マウスを乗せると理由が出る
        input.dataset.suggest = JSON.stringify(r.suggest || []); // 黄色のときの「もしかして」の候補
      });
      // 入力中の欄は、色が変わったのに合わせてポップアップ（もしかして / 理由）も出し直す
      if (document.activeElement?.matches('[data-name-cell]')) showHint(document.activeElement);
    } catch (e) {
      console.error('名前チェックの通信エラー:', e);
    }
  };

  // 入力欄が後から増える（行の追加）こともあるので、親の document で input イベントを拾う
  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-name-cell]')) return;
    clearTimeout(timer);
    timer = setTimeout(check, 300);
  });
  // 名前の入力欄をタップしたら理由（title）を下に表示（スマホはマウスを乗せられないので）
  // 黄色（似た人がいる）なら「もしかして ◯◯？」を出し、◯◯ を押すとその名前が入る
  document.addEventListener('focusin', (e) => {
    if (!e.target.matches('[data-name-cell]')) return;
    showHint(e.target);
  });

  // サーバーで色を付けていない画面（バンド編集）は最初に1回チェック
  if (!cells().some((i) => /is-(ok|similar|new)/.test(i.className))) check();
}

/**
 * 入力欄の真下に title の文章をポップアップで出す。
 *   入力欄の隣に差し込むと行の幅・高さが変わり、表のスクロール枠（overflow）で切れるので、
 *   body の直下に置いて座標で位置を合わせる。ページや表をスクロールしたら位置を合わせ直す。
 *
 * 黄色（is-similar）で候補（data-suggest）があるときは「もしかして ◯◯？」にする。
 *   ◯◯ は下線付きのボタン。押すとその名前を入力欄に入れ、input イベントで色の判定をやり直す。
 */
function showHint(input) {
  document.querySelectorAll('.hint-pop').forEach((p) => p.remove());
  let suggest = [];
  try { suggest = JSON.parse(input.dataset.suggest || '[]'); } catch { suggest = []; }
  const canSuggest = input.classList.contains('is-similar') && suggest.length > 0;
  if (!canSuggest && !input.title) return;

  const pop = document.createElement('div');
  pop.className = 'hint-pop';
  if (canSuggest) {
    pop.classList.add('hint-pop--suggest');
    pop.append('もしかして ');
    suggest.forEach((name, i) => {
      if (i > 0) pop.append(' / ');
      // 名前は textContent で入れる（innerHTML に入れると、名前に < > が入っていたとき XSS になる）
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'hint-pop__pick';
      pick.textContent = name;
      pick.addEventListener('click', () => {
        input.value = name;
        input.dispatchEvent(new Event('input', { bubbles: true })); // 色の判定（300ms 後）と、楽器ボタンの開閉を動かす
        pop.remove();
      });
      pop.append(pick);
    });
    pop.append('？');
    // 押した瞬間に入力欄からフォーカスが外れると、下の blur でポップアップが消えて押せなくなる。
    // mousedown / pointerdown の「フォーカスを移す」動きを止めて、入力欄にフォーカスを残す
    ['mousedown', 'pointerdown'].forEach((ev) => pop.addEventListener(ev, (e) => e.preventDefault()));
  } else {
    pop.textContent = input.title;
  }
  document.body.appendChild(pop);

  const place = () => {
    const r = input.getBoundingClientRect();
    const left = Math.min(r.left, document.documentElement.clientWidth - pop.offsetWidth - 8); // 画面の右端からはみ出さない
    pop.style.top = `${r.bottom + window.scrollY + 4}px`;
    pop.style.left = `${Math.max(8, left) + window.scrollX}px`;
  };
  place();
  window.addEventListener('scroll', place, true); // true = 表の横スクロールも拾う
  window.addEventListener('resize', place);
  input.addEventListener('blur', () => {
    // 候補のポップアップは少し待ってから消す（スマホはタップでフォーカスが先に外れることがあり、すぐ消すと押せない）
    setTimeout(() => pop.remove(), canSuggest ? 200 : 0);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place);
  }, { once: true });
}

/* ---------------------------------------------------------------------
 * 取り込みプレビュー
 *   - 「取込」のチェックを外した行を薄くする
 *   - 「名簿」検索欄の値が名簿のバンドと一致したら緑、空/不一致なら赤
 *   - 名簿側の「使っている出演枠」と「名簿なし ◯件」バッジを更新
 *   - 開催日 → 年度の表示、会場「新規作成」で会場名の入力欄を出す
 * ------------------------------------------------------------------- */
function setupImportPreview() {
  const slotRows = [...document.querySelectorAll('tr[data-slot]')];
  if (!slotRows.length) return;

  // 検索欄の文字 → 'ri:bi'（<datalist id="dl-roster"> の option から作る）
  const rosterKeys = {};
  document.querySelectorAll('#dl-roster option').forEach((opt) => { rosterKeys[opt.value] = opt.dataset.key; });

  const refresh = () => {
    const usedBy = {}; // 'ri:bi' → [出演バンド名...]

    // 1周目: どの名簿をどの枠が使っているか集める
    slotRows.forEach((tr) => {
      const include = tr.querySelector('[data-include]').checked;
      tr.classList.toggle('is-excluded', !include);
      const key = rosterKeys[tr.querySelector('[data-roster-input]').value.trim()];
      if (key && include) {
        (usedBy[key] ||= []).push(tr.querySelector('[data-band-name]').value);
      }
    });

    // 2周目: 色を付ける。緑 = 名簿あり / 黄 = 同じ名簿を他の枠でも選んでいる / 赤 = 名簿なし
    slotRows.forEach((tr) => {
      const include = tr.querySelector('[data-include]').checked;
      const input = tr.querySelector('[data-roster-input]');
      const key = rosterKeys[input.value.trim()];
      const dup = !!key && include && usedBy[key].length > 1;
      input.classList.toggle('is-ok', !!key && !dup);
      input.classList.toggle('is-similar', dup);
      input.classList.toggle('is-new', !key && include);
      input.title = dup ? `同じ名簿を ${usedBy[key].length} つの枠で選んでいます: ${usedBy[key].join(' / ')}` : '';
    });

    // 名簿側の「使っている出演枠」
    document.querySelectorAll('tr[data-roster-key]').forEach((tr) => {
      const names = usedBy[tr.dataset.rosterKey];
      const span = document.createElement('span');
      if (!names) {
        span.className = 'status status--new';
        span.textContent = '未使用';
      } else if (names.length > 1) {
        span.className = 'status status--warn';
        span.textContent = `⚠ ${names.length}枠で重複: ${names.join(' / ')}`;
      } else {
        span.className = 'status status--ok';
        span.textContent = '✓ ' + names[0];
      }
      tr.querySelector('[data-roster-used]').replaceChildren(span);
    });

    // 日程ごとの「名簿なし ◯件」バッジ
    document.querySelectorAll('[data-timetable]').forEach((card) => {
      const rows = [...card.querySelectorAll('tr[data-slot]')].filter((tr) => tr.querySelector('[data-include]').checked);
      const missing = rows.filter((tr) => !rosterKeys[tr.querySelector('[data-roster-input]').value.trim()]).length;
      const dups = rows.filter((tr) => tr.querySelector('[data-roster-input]').classList.contains('is-similar')).length;
      const badge = card.querySelector('[data-unmatched-badge]');
      const msgs = [missing && `名簿なし ${missing}`, dups && `名簿重複 ${dups}`].filter(Boolean);
      badge.textContent = msgs.length ? msgs.join(' · ') : '全バンド名簿あり';
      badge.className = 'pill ' + (msgs.length ? 'pill--warn' : 'pill--ok');
    });
  };

  // 開催日 → 年度（4月始まり。1〜3月は前の年の年度）。PHP の academic_year() と同じ計算
  const showFiscalYear = (input) => {
    const out = input.closest('.field').querySelector('[data-fiscal-year]');
    const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(input.value);
    out.textContent = m ? `→ ${Number(m[2]) >= 4 ? Number(m[1]) : Number(m[1]) - 1}年度` : '';
  };

  // 取込のチェックを切り替えたら、同じ日程の出演順を詰め直す
  //   外す → その行の番号を消して、後ろの行を1つずつ繰り上げ
  //   付ける → 上にある取込行の数+1 を入れて、それ以降の行を1つずつ繰り下げ
  const renumber = (checkbox) => {
    const tr = checkbox.closest('tr[data-slot]');
    const others = [...tr.closest('[data-timetable]').querySelectorAll('tr[data-slot]')]
      .filter((row) => row !== tr && row.querySelector('[data-include]').checked)
      .map((row) => row.querySelector('[name$="[order]"]'))
      .filter((input) => input.value !== '');
    const order = tr.querySelector('[name$="[order]"]');
    if (checkbox.checked) {
      const rows = [...tr.parentElement.children];
      const pos = rows.slice(0, rows.indexOf(tr)).filter((row) => row.querySelector('[data-include]').checked).length + 1;
      others.forEach((input) => { if (Number(input.value) >= pos) input.value = Number(input.value) + 1; });
      order.value = pos;
    } else {
      const old = Number(order.value);
      if (old) others.forEach((input) => { if (Number(input.value) > old) input.value = Number(input.value) - 1; });
      order.value = '';
    }
  };

  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-include]')) renumber(e.target);
    if (e.target.matches('[data-include], [data-roster-input]')) refresh();
    if (e.target.matches('[data-date-input]')) showFiscalYear(e.target);
    if (e.target.matches('[data-venue-select]')) {
      const box = e.target.closest('.field').querySelector('[data-venue-new]');
      box.hidden = e.target.value !== 'new';
      if (!box.hidden) box.focus();
    }
  });
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-roster-input], [data-band-name]')) refresh();
  });

  refresh();
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
 * 名簿の切り替えボタン「Vo / Gt/Vo / ⋯」「Key / Vn / ⋯」（HTML は import.php の render_pick）
 *   「⋯」で残りの選択肢を出す
 *     マウス: 乗せると出る（CSS の :hover）。クリックでも開け閉めできる
 *     スマホ: タップで開け閉め。選んだら閉じる。外をタップしても閉じる
 *     開いた選択肢が表の横スクロールの外（スマホで右端の外）なら、見える所まで表をスクロールする
 *   しまってある選択肢を選んだら、「⋯」のボタンにその名前（Ba/Vo など）を出す
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
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
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
      const res = await fetch('api_instrument.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ short_name: shortName, name }),
      });
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

/* ---------------------------------------------------------------------
 * 横にはみ出す表（.table-scroll）をマウスでつかんで左右に動かす
 *   入力欄・プルダウン・ボタンの上で押したときは、いつもどおり文字選択や操作をさせる。
 *   5px 以上動かしたら「ドラッグ」とみなし、離したときのクリックは無効にする（誤クリック防止）。
 *   スマホ（タッチ）は元々指でスクロールできるので、マウスのときだけ動かす。
 * ------------------------------------------------------------------- */
function setupDragScroll() {
  const boxes = [...document.querySelectorAll('.table-scroll')];
  if (!boxes.length) return;

  // はみ出しているときだけ「つかめる」カーソルにする（列の追加や画面幅で変わるので毎回見直す）
  const markDraggable = () => boxes.forEach((box) => box.classList.toggle('is-draggable', box.scrollWidth > box.clientWidth));
  markDraggable();
  window.addEventListener('resize', markDraggable);
  document.addEventListener('click', (e) => { if (e.target.closest('[data-add-roster-col]')) setTimeout(markDraggable); });

  boxes.forEach((box) => {
    let startX = 0;
    let startScroll = 0;
    let pressed = false;
    let moved = false;

    box.addEventListener('pointerdown', (e) => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      if (e.target.closest('input, select, textarea, button, a, label')) return;
      if (box.scrollWidth <= box.clientWidth) return;
      pressed = true;
      moved = false;
      startX = e.clientX;
      startScroll = box.scrollLeft;
    });
    box.addEventListener('pointermove', (e) => {
      if (!pressed) return;
      const dx = e.clientX - startX;
      if (!moved && Math.abs(dx) < 5) return;
      if (!moved) {
        moved = true;
        box.classList.add('is-dragging');
        box.setPointerCapture(e.pointerId); // 表の外までマウスが出ても追いかける
        window.getSelection()?.removeAllRanges(); // 押した瞬間に始まった文字選択を消す
      }
      box.scrollLeft = startScroll - dx;
    });
    const release = () => {
      pressed = false;
      box.classList.remove('is-dragging');
      setTimeout(() => { moved = false; }); // 直後のクリックを捨て終わったら戻す
    };
    box.addEventListener('pointerup', release);
    box.addEventListener('pointercancel', release);
    // ドラッグの終わりに起きるクリックを捨てる（チェックボックスなどが勝手に切り替わらないように）
    box.addEventListener('click', (e) => {
      if (!moved) return;
      e.preventDefault();
      e.stopPropagation();
    }, true);
  });
}

/* ---------------------------------------------------------------------
 * 送信時に全項目を JSON 1個にまとめる
 *   PHP は1回の POST で受け取れる項目数に上限（max_input_vars = 1000）があり、
 *   超えた分はエラーも出さずに捨てられる。プレビューは項目が多いので、
 *   [["名前", "値"], ...] の JSON にして payload という1項目で送る。
 *   受け取り側: import.php の read_form_input()
 * ------------------------------------------------------------------- */
function setupPackedForm() {
  document.querySelectorAll('form[data-pack]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      // 「やり直す」ボタン（form="reset-form"）は別フォームなのでここには来ない
      const pairs = [...new FormData(form).entries()].filter(([name]) => name !== 'csrf' && name !== 'payload');
      e.preventDefault();

      // 送信用の小さなフォームを作り直して送る（元のフォームの入力欄は送らない）
      const packed = document.createElement('form');
      packed.method = 'post';
      packed.action = form.getAttribute('action') || location.href;
      packed.hidden = true;
      const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        packed.appendChild(input);
      };
      add('csrf', form.querySelector('input[name="csrf"]').value);
      add('payload', JSON.stringify(pairs));
      document.body.appendChild(packed);
      form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; }); // 二重送信防止
      packed.submit();
    });
  });
}

/* ---------------------------------------------------------------------
 * バンド編集: メンバー行の追加・削除
 * ------------------------------------------------------------------- */
function setupMemberRows() {
  const rows = document.querySelector('[data-rows]');
  if (!rows) return;
  document.querySelector('[data-add-row]').addEventListener('click', () => {
    const row = rows.lastElementChild.cloneNode(true); // 最後の行をコピーして
    const input = row.querySelector('input');
    input.value = '';
    input.classList.remove('is-ok', 'is-similar', 'is-new');
    input.title = '';
    rows.appendChild(row);                             // 末尾に足す
    input.focus();
  });
  rows.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-remove-row]');
    if (!btn) return;
    const row = btn.closest('.member-row-edit');
    if (rows.children.length > 1) row.remove(); else row.querySelector('input').value = '';
  });
}
