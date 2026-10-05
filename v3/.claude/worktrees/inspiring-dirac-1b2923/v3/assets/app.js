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
 *    data-slot-select   … 名簿のバンド ↔ 出演バンドの対応
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
      });
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
  document.addEventListener('focusin', (e) => {
    if (!e.target.matches('[data-name-cell]')) return;
    showHint(e.target);
  });

  // サーバーで色を付けていない画面（バンド編集）は最初に1回チェック
  if (!cells().some((i) => /is-(ok|similar|new)/.test(i.className))) check();
}

/** 入力欄の下に title の文章を小さく出す */
function showHint(input) {
  document.querySelectorAll('.hint-pop').forEach((p) => p.remove());
  if (!input.title) return;
  const pop = document.createElement('div');
  pop.className = 'hint-pop';
  pop.textContent = input.title;
  input.insertAdjacentElement('afterend', pop);
  input.addEventListener('blur', () => pop.remove(), { once: true });
}

/* ---------------------------------------------------------------------
 * 取り込みプレビュー
 *   - 「取込」のチェックを外した行を薄くする
 *   - 名簿の「対応する出演バンド」を変えたら、タイムテーブル側の「名簿」列を更新
 *   - タイムテーブルでバンド名を書き換えたら、名簿側の選択肢の文字も更新
 * ------------------------------------------------------------------- */
function setupImportPreview() {
  const selects = [...document.querySelectorAll('[data-slot-select]')];
  const slotRows = [...document.querySelectorAll('tr[data-slot]')];
  if (!slotRows.length) return;

  const refresh = () => {
    // "日程:枠" → 名簿のバンド名
    const assigned = {};
    selects.forEach((sel) => {
      sel.classList.toggle('is-ok', sel.value !== '');
      sel.classList.toggle('is-new', sel.value === '');
      if (sel.value !== '') {
        assigned[sel.value] = sel.closest('tr').querySelector('td:nth-child(2)').firstChild.textContent.trim();
      }
    });

    // タイムテーブルの各行の「名簿」列
    slotRows.forEach((tr) => {
      const include = tr.querySelector('[data-include]').checked;
      tr.classList.toggle('is-excluded', !include);
      const cell = tr.querySelector('[data-roster-status]');
      cell.replaceChildren();
      const span = document.createElement('span');
      if (assigned[tr.dataset.slot]) {
        span.className = 'status status--ok';
        span.textContent = '✓ ' + assigned[tr.dataset.slot];
      } else if (include) {
        span.className = 'status status--new';
        span.textContent = '名簿なし';
      }
      cell.appendChild(span);
    });

    // 日程ごとの「名簿なし ◯件」バッジ
    document.querySelectorAll('[data-timetable]').forEach((card) => {
      const rows = [...card.querySelectorAll('tr[data-slot]')].filter((tr) => tr.querySelector('[data-include]').checked);
      const missing = rows.filter((tr) => !assigned[tr.dataset.slot]).length;
      const badge = card.querySelector('[data-unmatched-badge]');
      badge.textContent = missing ? `名簿なし ${missing}` : '全バンド名簿あり';
      badge.className = 'pill ' + (missing ? 'pill--warn' : 'pill--ok');
    });
  };

  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-slot-select], [data-include]')) refresh();
  });

  // バンド名の書き換え → 名簿側の <option> の文字を同じにする
  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-band-name]')) return;
    const key = e.target.closest('tr').dataset.slot;
    document.querySelectorAll(`option[value="${CSS.escape(key)}"]`).forEach((opt) => { opt.textContent = e.target.value; });
  });

  refresh();
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
