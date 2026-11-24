/*
 * app_form.js — フォームの部品（確認・未保存の警告・ドロップゾーン・プルダウン・まとめ送信・メンバー行・入力候補）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * 削除などの前に「本当に？」と聞く
 * ------------------------------------------------------------------- */
function setupConfirm() {
  // data-dirty-check="フォームのid" … そのフォームに未保存の変更があれば、確認の文に警告を足す
  //   （ライブ編集の「この日程を削除」: 削除するとページが移動して、書きかけの内容が消えるので）
  //   変更があるかは「読み込み直後の入力値」と「今の入力値」を文字列にして比べる。
  //   他の setup〜 が入力欄をいじり終わってから覚えたいので、setTimeout で一番最後に回す
  const snapshot = (form) => new URLSearchParams(new FormData(form)).toString();
  const initial = new Map();
  setTimeout(() => {
    document.querySelectorAll('form[data-dirty-check]').forEach((f) => {
      const target = document.getElementById(f.dataset.dirtyCheck);
      if (target && !initial.has(target)) initial.set(target, snapshot(target));
    });
  });

  // フォーム1つ1つではなく document で待ち受ける（submit イベントは外側へ伝わってくる = バブリング）。
  //   → 後から JS で足したフォーム（ページ移動なしで追加したアルバムの × など）にも効く
  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-confirm]');
    if (!form) return;
    let message = form.dataset.confirm;
    const target = form.dataset.dirtyCheck && document.getElementById(form.dataset.dirtyCheck);
    if (target && initial.has(target) && initial.get(target) !== snapshot(target)) {
      message = '⚠ 保存していない変更があります。続けると変更は失われます。\n\n' + message;
    }
    if (!confirm(message)) e.preventDefault();
  });
}

/* ---------------------------------------------------------------------
 * <a data-leave-check="フォームのid"> … そのフォームに未保存の変更があれば、
 *   移動する前にモーダル（[data-leave-dialog]）で「保存されていません。よろしいですか？」と聞く
 * ------------------------------------------------------------------- */
function setupLeaveCheck() {
  const dialog = document.querySelector('[data-leave-dialog]');
  if (!dialog) return;
  const snapshot = (form) => new URLSearchParams(new FormData(form)).toString();
  const initial = new Map();
  setTimeout(() => { // setupConfirm と同じく、他の setup〜 が入力欄をいじり終わってから覚える
    document.querySelectorAll('a[data-leave-check]').forEach((a) => {
      const form = document.getElementById(a.dataset.leaveCheck);
      if (form && !initial.has(form)) initial.set(form, snapshot(form));
    });
  });
  let href = null;
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-leave-check]');
    if (!a) return;
    const form = document.getElementById(a.dataset.leaveCheck);
    if (!form || !initial.has(form) || initial.get(form) === snapshot(form)) return; // 変更なし → そのまま移動
    e.preventDefault();
    href = a.href;
    dialog.returnValue = '';
    dialog.showModal();
  });
  dialog.addEventListener('close', () => {
    if (dialog.returnValue === 'leave' && href) { allowLeave(); location.href = href; } // モーダルで聞いたので、ブラウザの警告は出さない
    href = null;
  });
}

/* ---------------------------------------------------------------------
 * <form data-unsaved> … 未保存の変更があるままページを離れようとしたら（リンク・戻る・タブを閉じる・再読み込み）、
 *   ブラウザの「このサイトを離れますか？」を出す（beforeunload。文言はブラウザが決めるので変えられない）
 *
 *   変更があるかは setupConfirm と同じく「読み込み直後の入力値」と「今の入力値」を文字列にして比べる。
 *   data-unsaved="posted" … 送信してエラーで戻ってきた画面。入力はまだ保存されていないので、最初から「変更あり」
 *   data-unsaved-blank … この中の [data-unsaved-key] が空なら、中の入力欄は比べない（空のまま追加しただけの行。保存されないので）
 *   フォームを送信するとき（保存・削除など）はページ移動が正しい動きなので、警告しない
 * ------------------------------------------------------------------- */
let leaveAllowed = false;
function allowLeave() { leaveAllowed = true; }

function setupUnsavedGuard() {
  const forms = [...document.querySelectorAll('form[data-unsaved]')];
  if (forms.length === 0) return;
  // ファイル欄は FormData のままだと全部 "[object File]" になって比べられないので、名前と大きさにする
  const snapshot = (form) => {
    const skip = new Set();
    form.querySelectorAll('[data-unsaved-blank]').forEach((box) => {
      if (box.querySelector('[data-unsaved-key]')?.value.trim() !== '') return;
      box.querySelectorAll('[name]').forEach((el) => skip.add(el.name));
    });
    return JSON.stringify([...new FormData(form).entries()]
      .filter(([name]) => name !== 'csrf' && !skip.has(name))
      .map(([name, v]) => [name, v instanceof File ? `${v.name}:${v.size}` : v]));
  };
  const initial = new Map();
  setTimeout(() => { // 他の setup〜 が入力欄をいじり終わってから覚える
    forms.forEach((f) => initial.set(f, f.dataset.unsaved === 'posted' ? null : snapshot(f)));
  });
  const dirty = () => forms.some((f) => initial.has(f) && initial.get(f) !== snapshot(f));

  // 送信（キャンセルされなかったもの）はページ移動して良い。window で待ち受けると document の待ち受け
  //   （setupConfirm の確認ダイアログ・ページ移動なしで送る setupAlbumAdd など）が全部終わった後に動くので、
  //   キャンセルされた・JS で送った（ページ移動しない）かが e.defaultPrevented で分かる
  window.addEventListener('submit', (e) => {
    if (!e.defaultPrevented && (e.target.getAttribute('method') || 'get').toLowerCase() !== 'dialog') allowLeave();
  });
  window.addEventListener('beforeunload', (e) => {
    if (leaveAllowed || !dirty()) return;
    e.preventDefault();
    e.returnValue = ''; // 古いブラウザ（Safari など）はこれが無いと警告を出さない
  });
  // bfcache（戻る・進むでページがそのまま復元される）で戻ってきたら、また警告できるようにする
  window.addEventListener('pageshow', (e) => { if (e.persisted) leaveAllowed = false; });
}

/* ---------------------------------------------------------------------
 * ファイル選択欄: 選んだファイルを「足していく」 & 一覧表示 & ドラッグ中の見た目
 *
 *   <input type="file"> は選び直すと前の選択が消える（ブラウザの仕様）。
 *   そこで選んだファイルを配列 picked に貯めておき、DataTransfer で input.files に入れ直す。
 *   → 1ファイルずつ選んでも、ドロップを何回かに分けても、全部まとめて送信される。
 *   （input.files を JS で代入しても change は発生しないので、二重に足されることはない）
 * ------------------------------------------------------------------- */
function setupDropzone() {
  const drop = document.querySelector('[data-dropzone]');
  if (!drop) return;
  const input = drop.querySelector('[data-file-input]');
  const list = drop.querySelector('[data-file-list]');
  let picked = [];
  const sameFile = (a, b) => a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;

  const render = () => {
    const dt = new DataTransfer();
    picked.forEach((f) => dt.items.add(f));
    input.files = dt.files;
    list.replaceChildren(...picked.map((f, i) => {
      const li = document.createElement('li');
      li.className = 'dropzone__file';
      const name = document.createElement('span');
      const ext = f.name.includes('.') ? f.name.split('.').pop().toUpperCase() : '?';
      name.textContent = `${ext} · ${f.name}`; // textContent なので XSS にならない
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'dropzone__remove';
      remove.append(iconEl('close'));
      remove.setAttribute('aria-label', `${f.name} を外す`);
      remove.addEventListener('click', (e) => {
        e.preventDefault(); // <label> の中なので、ファイル選択の画面が開かないように止める
        picked.splice(i, 1);
        render();
      });
      li.append(name, remove);
      return li;
    }));
  };

  input.addEventListener('change', () => {
    [...input.files].forEach((f) => {
      if (!picked.some((p) => sameFile(p, f))) picked.push(f);
    });
    render();
  });
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.add('is-over')));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
}

/* ---------------------------------------------------------------------
 * 取り込みの送信後の「読み込み中」
 *
 *   ページはそのまま（サーバーの返事が来たら次のページに切り替わる）。返事を待つ間、
 *   フォームの中の [data-loading-box] を出して画面を覆う。画像が入っていれば AI の読み取りなので時間の目安も出す。
 *   ブラウザの「戻る」でこのページに戻ったとき（bfcache で前の見た目のまま復元される）は消す。
 * ------------------------------------------------------------------- */
function setupLoading() {
  document.querySelectorAll('form[data-loading]').forEach((form) => {
    const box = form.querySelector('[data-loading-box]');
    if (!box) return;
    form.addEventListener('submit', (e) => {
      // 確認ダイアログ（data-confirm）は document で待ち受けているので、フォームのこの処理のほうが先に走る。
      //   → 全部の処理が終わってから、キャンセルされていないかを見る
      setTimeout(() => { if (!e.defaultPrevented) start(); }, 0);
    });
    const start = () => {
      const files = [...form.querySelectorAll('input[type=file]')].flatMap((i) => [...i.files]);
      const images = files.filter((f) => /\.(jpe?g|png|webp|gif)$/i.test(f.name)).length;
      if (images > 0) {
        form.querySelector('[data-loading-title]').textContent = `解析中…`;
        form.querySelector('[data-loading-sub]').textContent = '30秒〜1分ほどかかります。このままお待ちください';
      }
      box.hidden = false;
      form.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; });
    };
  });
  window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    document.querySelectorAll('[data-loading-box]').forEach((b) => { b.hidden = true; });
    document.querySelectorAll('form[data-loading] button[type=submit]').forEach((b) => { b.disabled = false; });
  });
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

  let timer = null;
  let requestNo = 0; // 古い通信の結果が後から届いて上書きしないように番号を振る

  const check = async () => {
    const inputs = cells();
    const myNo = ++requestNo;
    try {
      const res = await postJson('api_name_check', { cells: inputs.map((i) => i.value) });
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
  if (document.querySelector('.suggest-pop')) return; // 入力候補（setupSuggest）が開いているあいだは重ねない
  let suggest = [];
  try { suggest = JSON.parse(input.dataset.suggest || '[]'); } catch { suggest = []; }
  const canSuggest = (input.classList.contains('is-similar') || input.classList.contains('is-new')) && suggest.length > 0;
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
 * プルダウン（<select>）を全部「登録済みのライブと統合」と同じ見た目のポップアップにする
 *
 *   <select> の開いたときのリストは CSS で見た目を変えられないので、
 *   <select> は見えなくして値の入れ物として残し（送信・PHP 側はそのまま）、ボタン + ポップアップを横に作る。
 *   選んだら select.value を変えて input / change イベントを出す → data-autosubmit などの今までの処理もそのまま動く。
 *
 *   ・これから作る <select> も、何もしなくても自動でこの見た目になる（後から JS で足した行も MutationObserver で拾う）
 *   ・元のブラウザのプルダウンのままにしたいときは <select data-native> と書く
 *   ・ポップアップは <body> の直下に position: fixed で出す（表の overflow で切れないように）
 * ------------------------------------------------------------------- */
function setupSelectPick() {
  let openPop = null; // { pop, btn }

  const closePop = (focusBtn = false) => {
    if (!openPop) return;
    const { pop, btn } = openPop;
    btn.setAttribute('aria-expanded', 'false');
    pop.remove();
    openPop = null;
    if (focusBtn) btn.focus();
  };

  // 対象外: data-native、複数選択（multiple）、リスト表示（size が2以上）
  const isTarget = (el) => el instanceof HTMLSelectElement && !el.multiple && el.size <= 1 && !el.hasAttribute('data-native');

  // ボタンの文字・状態を select に合わせる
  const sync = (select) => {
    const wrap = select.parentElement;
    if (!wrap?.matches('.live-pick[data-select-pick]')) return;
    const btn = wrap.querySelector(':scope > .live-pick__btn');
    const text = btn.firstElementChild;
    const opt = select.selectedOptions[0];
    text.textContent = opt ? opt.textContent : '';
    text.classList.toggle('is-placeholder', !select.value); // 「選択」「— 未設定 —」など値が空のものは薄く
    btn.disabled = select.disabled;
  };

  const done = new WeakSet(); // 変換済みの select（同じものを2回変換しない）
  const enhance = (select) => {
    if (done.has(select)) return;
    done.add(select);

    // <div class="live-pick"> の中に select を入れて、その前にボタンを置く
    // （select を箱の中に入れるので、グリッドの列もずれないし、required の吹き出しもボタンの位置に出る）
    let wrap = select.parentElement;
    if (wrap.matches('.live-pick[data-select-pick]')) {
      // cloneNode でコピーされた行: 箱ごとコピーされているが、ボタンにイベントが付いていない → ボタンだけ作り直す
      wrap.querySelector(':scope > .live-pick__btn')?.remove();
    } else {
      wrap = document.createElement('div');
      wrap.className = 'live-pick';
      wrap.dataset.selectPick = '';
      // 入力欄の中（.field など）は横いっぱい、それ以外（絞り込みの年度など）は中身の幅
      if (!select.closest('.field, .member-row-edit, .table--edit')) wrap.classList.add('live-pick--inline');
      if (select.matches('.select-sm') || select.closest('.performer')) wrap.classList.add('live-pick--sm');
      select.before(wrap);
      wrap.append(select);
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'live-pick__btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    const label = select.getAttribute('aria-label');
    if (label) btn.setAttribute('aria-label', label);
    const text = document.createElement('span');
    const icon = document.createElement('span');
    icon.className = 'icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = 'expand_more';
    btn.append(text, icon);
    wrap.prepend(btn);

    // hidden にすると required のチェックで「選択してください」が出せなくなるので、見えないだけにする
    select.classList.add('select-native');
    select.tabIndex = -1;
    sync(select);
  };

  const open = (btn) => {
    const select = btn.parentElement.querySelector(':scope > select');
    const pop = document.createElement('div');
    pop.className = 'live-pop live-pop--float';
    pop.setAttribute('role', 'listbox');
    if (btn.closest('.live-pick--sm')) pop.classList.add('live-pop--sm');
    [...select.options].forEach((o) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'live-pop__item';
      item.setAttribute('role', 'option');
      item.disabled = o.disabled;
      if (o.selected) item.setAttribute('aria-selected', 'true');
      const name = document.createElement('span');
      name.className = 'live-pop__name';
      name.textContent = o.textContent; // textContent なので XSS にならない
      if (!o.value) item.classList.add('is-empty'); // 「なし」「— 未設定 —」など値が空のものは細く薄く（ボタンの is-placeholder と同じ扱い）
      item.append(name);
      item.addEventListener('click', () => {
        const changed = select.value !== o.value;
        select.value = o.value;
        closePop(true);
        if (changed) {
          select.dispatchEvent(new Event('input', { bubbles: true }));
          select.dispatchEvent(new Event('change', { bubbles: true })); // 既存の change の処理（新しい会場の欄・自動送信など）を動かす
        }
      });
      pop.append(item);
    });
    // <dialog>（showModal）の中のボタンなら、ポップアップも dialog の中に出す。body の直下だと dialog（最前面の層）に隠れて見えず、触れもしない
    (btn.closest('dialog') || document.body).append(pop);

    // ボタンの真下に出す。下に入りきらなければ上に出す
    const r = btn.getBoundingClientRect();
    pop.style.left = `${r.left}px`;
    pop.style.minWidth = `${r.width}px`;
    const below = window.innerHeight - r.bottom - 8;
    if (below < Math.min(pop.offsetHeight, 200) && r.top > below) {
      pop.style.bottom = `${window.innerHeight - r.top + 4}px`;
      pop.style.maxHeight = `${Math.min(280, r.top - 8)}px`;
    } else {
      pop.style.top = `${r.bottom + 4}px`;
      pop.style.maxHeight = `${Math.min(280, below)}px`;
    }
    // 右にはみ出すなら左にずらす
    const over = pop.getBoundingClientRect().right - (window.innerWidth - 8);
    if (over > 0) pop.style.left = `${Math.max(8, r.left - over)}px`;

    btn.setAttribute('aria-expanded', 'true');
    openPop = { pop, btn };
    // 選択中の項目が見える位置までスクロールして、そこにフォーカス（↑↓ で動ける）
    const cur = pop.querySelector('[aria-selected="true"]') || pop.querySelector('.live-pop__item:not(:disabled)');
    if (cur) {
      pop.scrollTop = cur.offsetTop - pop.clientHeight / 2 + cur.offsetHeight / 2;
      cur.focus({ preventScroll: true });
    }
  };

  document.querySelectorAll('select').forEach((s) => { if (isTarget(s)) enhance(s); });

  // 後から足された <select>（メンバー行の追加など）も変換する
  new MutationObserver((records) => {
    records.forEach((rec) => rec.addedNodes.forEach((node) => {
      if (!(node instanceof Element)) return;
      [node, ...node.querySelectorAll('select')].forEach((s) => { if (isTarget(s)) enhance(s); });
    }));
  }).observe(document.body, { childList: true, subtree: true });

  // 選んだあと・JS が値を変えて change を出したときに、ボタンの文字を合わせる
  document.addEventListener('change', (e) => { if (isTarget(e.target)) sync(e.target); });
  // フォームのリセットボタン（リセットが終わってから読む）
  document.addEventListener('reset', (e) => setTimeout(() => e.target.querySelectorAll('select').forEach(sync)));

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-select-pick] > .live-pick__btn');
    if (btn) {
      const same = openPop?.btn === btn;
      closePop();
      if (!same) open(btn);
      return;
    }
    if (openPop && !openPop.pop.contains(e.target)) closePop();
  });

  document.addEventListener('keydown', (e) => {
    // ボタンで ↓↑ を押しても開く（ふつうの select と同じ）
    const btn = e.target.closest?.('[data-select-pick] > .live-pick__btn');
    if (btn && !openPop && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      open(btn);
      return;
    }
    if (!openPop) return;
    if (e.key === 'Escape' || e.key === 'Tab') {
      if (e.key === 'Escape') e.preventDefault();
      closePop(e.key === 'Escape');
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const items = [...openPop.pop.querySelectorAll('.live-pop__item:not(:disabled)')];
      const i = items.indexOf(document.activeElement);
      items[Math.min(items.length - 1, Math.max(0, i + (e.key === 'ArrowDown' ? 1 : -1)))]?.focus();
    }
  });

  // ページや表をスクロールしたら閉じる（fixed なのでボタンから離れてしまう）。ポップアップの中のスクロールは別
  window.addEventListener('scroll', (e) => { if (openPop && !openPop.pop.contains(e.target)) closePop(); }, true);
  window.addEventListener('resize', () => closePop());
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
      allowLeave(); // packed.submit() は submit イベントを起こさないので、ここで「離れて良い」にする
      form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; }); // 二重送信防止
      packed.submit();
    });
  });
}

/* ---------------------------------------------------------------------
 * 楽器欄の横の「Cho」トグル（HTML は lib/repository.php の chorus_toggle）
 *   押すと ON / OFF（aria-pressed と隠し項目の 0 / 1）を切り替える
 *   楽器欄がボーカル（Vo / Vo/Gt など）・Cho・その他のときは押せない（data-cho-block の値と 'vo:◯◯'）→ OFF に戻す
 *   バンド編集・タイムテーブル編集・セトリ編集で共通。行を足しても効くように document で待ち受ける
 * ------------------------------------------------------------------- */
function setChorus(wrap, on, enabled) {
  const btn = wrap.querySelector('button');
  const hidden = wrap.querySelector('input');
  btn.disabled = !enabled;
  btn.setAttribute('aria-pressed', on && enabled ? 'true' : 'false');
  if (hidden) hidden.value = on && enabled ? '1' : '0';
}

function setupChorusToggle() {
  // 開いた時点でトグルが無くても待ち受ける（セトリ編集で曲が未登録だと、トグルは <template> の中にしか無く、「＋ 曲を追加」で後から出てくる）
  // トグルが見る楽器欄 = 同じ行の最初の select（セトリ編集なら楽器1）
  const rowOf = (el) => el.closest('.member-row-edit, .cell-instrument, .performer');
  const allowed = (wrap, value) => !value.startsWith('vo:') && !wrap.dataset.choBlock.split(',').includes(value);

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-cho-toggle] button');
    if (!btn || btn.disabled) return;
    setChorus(btn.parentElement, btn.getAttribute('aria-pressed') !== 'true', true);
  });
  document.addEventListener('change', (e) => {
    if (!e.target.matches('select')) return;
    const row = rowOf(e.target);
    const wrap = row?.querySelector('[data-cho-toggle]');
    if (!wrap || row.querySelector('select') !== e.target || !wrap.querySelector('input')) return;
    const ok = allowed(wrap, e.target.value);
    setChorus(wrap, ok && wrap.querySelector('button').getAttribute('aria-pressed') === 'true', ok);
  });
}

/* ---------------------------------------------------------------------
 * 🚩 確認依頼（check_picker）: 「VoかVo/Gtか不明」を ON にしたら、
 * ボーカルの人（data-vocal の付いた「誰について」）を自動で選ぶ。OFF にしたときは外さない（手で選んだ人を消さないため）
 * ------------------------------------------------------------------- */
function setupCheckPicker() {
  document.addEventListener('change', (e) => {
    const reason = e.target;
    if (!reason.matches('.check-picker input[name$="[reason][]"][value="2"]') || !reason.checked) return;
    reason.closest('.check-picker').querySelectorAll('input[data-vocal]').forEach((who) => { who.checked = true; });
  });
}

/* ---------------------------------------------------------------------
 * トップの 🚩 確認依頼のお知らせ: ✕ を押したら、横にすべらせて消してから行を詰める（CSS の .is-leaving）
 *   消したことは api_check_dismiss に送って DB に残す（次に開いても出ない）。最後の1つを消したらカードごと消す
 * ------------------------------------------------------------------- */
function setupCheckDismiss() {
  const list = document.querySelector('[data-check-list]');
  if (!list) return;
  // アニメーションが終わったら消す（動きを減らす設定のときは、すぐ消す）
  const leave = (el) => {
    if (reduceMotion) { el.remove(); return; }
    el.style.height = `${el.offsetHeight}px`; // 高さを数字で固定してから 0 へ → なめらかに詰まる
    void el.offsetHeight; // ここで一度レイアウトを確定させる（しないと「固定した高さ」を飛ばしていきなり 0 になる）
    el.classList.add('is-leaving');
    // transitionend が来ない（タブが裏にある など）ときのために、時間でも消す
    const done = () => el.remove();
    el.addEventListener('transitionend', (e) => { if (e.target === el && e.propertyName === 'height') done(); });
    setTimeout(done, 600);
  };

  list.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-check-dismiss]');
    if (!btn) return;
    btn.disabled = true;
    const body = new URLSearchParams({ band_id: btn.dataset.checkDismiss });
    fetch('api_check_dismiss', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() }, body, keepalive: true }).catch(() => {});
    const item = btn.closest('[data-check-item]');
    if (list.querySelectorAll('[data-check-item]').length === 1) leave(list); else leave(item);
  });
}

/* ---------------------------------------------------------------------
 * バンド編集: メンバー行の追加・削除
 * ------------------------------------------------------------------- */
function setupMemberRows() {
  if (!document.querySelector('[data-rows]')) return;
  // document で待ち受ける（行の集まり [data-rows] はバンド編集の1個だけ）
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-row]');
    if (add) {
      const rows = document.querySelector('[data-rows]');
      const row = rows.lastElementChild.cloneNode(true); // 最後の行をコピーして
      const input = row.querySelector('[data-name-cell]') || row.querySelector('input');
      input.value = '';
      input.classList.remove('is-ok', 'is-similar', 'is-new');
      input.title = '';
      row.querySelector('select').value = '2';           // 楽器はギターに戻す（2 = Gt。コピー元の楽器を引き継がない）
      const inst2 = row.querySelector('[data-inst2]');
      if (inst2) inst2.value = '';                        // 2つ目の楽器は空に
      row.querySelector('[data-inst2-slot]')?.classList.remove('is-open');
      row.querySelector('[data-inst2-toggle]')?.classList.remove('is-open');
      const cho = row.querySelector('[data-cho-toggle]');
      if (cho) setChorus(cho, false, true);               // Cho のトグルも OFF に（ギターなので押せる）
      // data-next がある（name が b[ID][m][番号][…] の形）なら、番号を新しくする。
      //   コピー元と同じ番号のままだと、送信したとき後の行が前の行を上書きしてしまう
      if (rows.dataset.next) {
        const n = rows.dataset.next++;
        row.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace(/\[m\]\[\d+\]/, `[m][${n}]`); });
      }
      rows.appendChild(row);                             // 末尾に足す
      input.focus();
      return;
    }
    const btn = e.target.closest('[data-remove-row]');
    if (!btn) return;
    const rows = btn.closest('[data-rows]');
    const row = btn.closest('.member-row-edit');
    if (rows.children.length > 1) {
      row.remove();
    } else {
      const input = row.querySelector('input');
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true })); // 色の判定をやり直す
    }
  });
}

/* ---------------------------------------------------------------------
 * 入力候補のプルダウン（<input data-suggest-list="datalist の id">）
 *
 *   ブラウザ標準の <input list> は見た目が端末ごとにバラバラで、フォーカスしただけで全件出る。
 *   そこで datalist は「候補の入れ物」としてだけ使い、1文字以上打ったときに
 *   絞り込んだ候補を .live-pop（setupSelectPick と同じ見た目）で出す。
 *
 *   ・前方一致を先、部分一致を後に、最大 8 件
 *   ・それでも 8 件に足りなければ「ゆるい一致」で埋める: 先頭の何文字かが同じもの（一致が長い順）。
 *     先頭 2 文字以上の一致を探し、1 件も無ければ 1 文字まで広げる（打ち間違い「佐藤さくや」→ 佐藤さくら）
 *   ・ひらがな/カタカナ、全角/半角、大文字/小文字は区別しない
 *   ・<option data-kana="ふりがな"> があれば、ふりがなでも探す（「さと」→ 佐藤さくら）
 *     日本語入力の変換前（下線が付いている「さと」の状態）でも input イベントは来るので、変換しなくても候補が出る
 *   ・フォーカスは入力欄に残したまま ↑↓ で選び、Enter で確定（選んでいなければ一番上）、Esc で閉じる
 *     候補の欄では Enter でフォームを送信しない（打ちかけのまま「登録」されないように）
 *     （変換中の ↑↓ Enter は日本語入力のものなので横取りしない。変換中は候補を押して選ぶ）
 *   ・行の追加で後から増えた入力欄も、document で待ち受けているので何もしなくても効く
 *   ・<input data-suggest-seed="セレクタ"> は、押したときに同じ行（tr）のその欄の文字で前方一致の候補を出す
 *     （取り込み画面の「名簿内バンド」: 押すと、登録するバンド名で名簿のバンドを探して出す。部分一致は使わない）
 * ------------------------------------------------------------------- */
function setupSuggest() {
  // 「入力欄が無ければ何もしない」の早期 return はしない。
  //   曲編集で曲が0曲のとき、入力欄は <template> の中にしか無く querySelector で見つからないが、「＋ 曲を追加」で後から出てくるため
  const MAX = 8;
  let cur = null;        // { pop, input, items, names, active }
  let skipNext = false;  // 候補を選んで input イベントを出したとき、もう一度開かないように
  let composing = false; // 日本語入力の変換中か
  let pending = null;    // 変換中に候補を押したとき { input, value }（変換が終わったら入れ直す）
  let tappedInput = null; // スマホで候補が開いている入力欄を押して閉じたとき、その入力欄（直後の click で開き直さないため）

  // 比べる用に文字をそろえる（全角英数→半角、大文字→小文字、カタカナ→ひらがな）
  const norm = (s) => s.normalize('NFKC').toLowerCase()
    .replace(/[ァ-ヶ]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0x60));
  // ふりがな用: さらに空白を取る（「やまだ たろう」を「やまだた」でも当てる）
  const normKana = (s) => norm(s).replace(/\s+/g, '');

  const close = () => {
    if (!cur) return;
    cur.pop.remove();
    cur.input.removeAttribute('aria-activedescendant');
    cur.input.setAttribute('aria-expanded', 'false');
    cur = null;
  };

  const choose = (input, value) => {
    // 変換中に値を書き換えると、変換が終わったときに日本語入力が打ちかけの文字を書き戻すことがある → 終わってから入れ直す
    if (composing) pending = { input, value };
    input.value = value;
    close();
    skipNext = true;
    input.dispatchEvent(new Event('input', { bubbles: true })); // 名前の色判定などを動かす
  };

  const setActive = (i) => {
    if (!cur) return;
    cur.items.forEach((el, j) => el.classList.toggle('is-active', j === i));
    cur.active = i;
    const el = cur.items[i];
    if (el) {
      cur.input.setAttribute('aria-activedescendant', el.id);
      el.scrollIntoView({ block: 'nearest' });
    } else {
      cur.input.removeAttribute('aria-activedescendant');
    }
  };

  // 文字の中の一致した部分を <mark> で囲む（textContent で組むので XSS にならない）
  const highlight = (span, text, q, normFn) => {
    const n = normFn(text);
    const at = q && n.length === text.length ? n.indexOf(q) : -1; // そろえると長さが変わる文字は強調しない（位置がずれるので）
    if (at < 0) {
      span.textContent = text;
    } else {
      const mark = document.createElement('mark');
      mark.textContent = text.slice(at, at + q.length);
      span.append(text.slice(0, at), mark, text.slice(at + q.length));
    }
    return span;
  };

  // 先頭から何文字同じか（「さとうさくや」と「さとうさくら」→ 5）
  const commonPrefix = (a, b) => {
    let i = 0;
    while (i < a.length && i < b.length && a[i] === b[i]) i++;
    return i;
  };

  // 候補1行の中身: 名前（＋ ふりがなで当たったときは、右に小さくふりがな）
  //   hit.mark = 赤くする文字（そろえた後の文字。ゆるい一致は一致した先頭の部分だけ）
  const label = (hit) => {
    const name = highlight(document.createElement('span'), hit.name, hit.byKana ? '' : hit.mark, norm);
    name.className = 'live-pop__name';
    if (!hit.byKana) return [name];
    const kana = highlight(document.createElement('span'), hit.kana.replace(/\s+/g, ''), hit.mark, normKana);
    kana.className = 'suggest-pop__kana';
    return [name, kana];
  };

  // 候補の箱を入力欄の位置に合わせて置く（開いたとき・スマホでスクロールしたとき）
  const place = (pop, input) => {
    // 入力欄の真下に出す。下に入りきらなければ上に（setupSelectPick とほぼ同じ）
    //   ただし高さの上限は 280px にしない。候補は最大 8 件（約 296px）なので、280px で切ると少しだけはみ出して
    //   スクロールバーが出て、その幅のぶん候補のマウスオーバーの背景が右端まで届かなくなる → 8 件がそのまま入る高さにする
    const r = input.getBoundingClientRect();
    pop.style.top = pop.style.bottom = ''; // 置き直すときは前の位置を消してから
    pop.style.left = `${r.left}px`;
    pop.style.minWidth = `${r.width}px`;
    pop.style.maxHeight = 'none';
    const full = pop.offsetHeight;
    const below = window.innerHeight - r.bottom - 8;
    if (below < full && r.top - 8 > below) {
      pop.style.bottom = `${window.innerHeight - r.top + 4}px`;
      pop.style.maxHeight = `${Math.min(full, r.top - 8)}px`;
    } else {
      pop.style.top = `${r.bottom + 4}px`;
      pop.style.maxHeight = `${Math.min(full, below)}px`;
    }
    const over = pop.getBoundingClientRect().right - (window.innerWidth - 8);
    if (over > 0) pop.style.left = `${Math.max(8, r.left - over)}px`;
  };

  // seed: 入力欄の文字の代わりに、この文字で探す（data-suggest-seed で押したとき）。前方一致だけにし、完全一致の1件でも出す
  const open = (input, seed = null) => {
    close();
    const raw = seed ?? input.value;
    const q = norm(raw.trim());
    const kq = normKana(raw);
    const list = document.getElementById(input.dataset.suggestList);
    if (!q || !list) return;

    // 名前で当たったものを優先し、ふりがなだけで当たったものはその後ろに（それぞれ前方一致 → 部分一致の順）
    const groups = [[], [], [], []]; // 名前の前方一致 / ふりがなの前方一致 / 名前の部分一致 / ふりがなの部分一致
    const loose = [];                // どれにも当たらなかったもの（ゆるい一致の材料）{ ...hit, len: 先頭の一致の長さ }
    for (const o of list.options) {
      const n = norm(o.value);
      const k = normKana(o.dataset.kana || '');
      const hit = { name: o.value, kana: o.dataset.kana || '', byKana: false, mark: q };
      if (n.startsWith(q)) groups[0].push(hit);
      else if (k && k.startsWith(kq)) groups[1].push({ ...hit, byKana: true, mark: kq });
      else if (seed === null && n.includes(q)) groups[2].push(hit);
      else if (seed === null && k && k.includes(kq)) groups[3].push({ ...hit, byKana: true, mark: kq });
      else {
        // 名前とふりがなの、先頭が長く一致している方を使う
        const ln = commonPrefix(n, q);
        const lk = k ? commonPrefix(k, kq) : 0;
        const byKana = lk > ln;
        const len = Math.max(ln, lk);
        if (len > 0) loose.push({ ...hit, byKana, len, mark: (byKana ? kq : q).slice(0, len) });
      }
    }
    // ゆるい一致: 先頭 2 文字以上を一致が長い順に。2 文字の一致が無ければ 1 文字まで広げる
    //   sort は同じ長さなら元の並び（datalist の順 = 新しい順など）のまま
    let fuzzy = loose.filter((h) => h.len >= 2);
    if (!fuzzy.length) fuzzy = loose;
    fuzzy.sort((a, b) => b.len - a.len);
    let hits = [...groups.flat(), ...fuzzy].slice(0, MAX);
    // 押して開いた（seed）のに先頭が合うものが1つも無ければ、候補を全部出す（押したのに何も出ないと選べないので。多ければスクロール）
    if (seed !== null && !hits.length) {
      hits = [...list.options].map((o) => ({ name: o.value, kana: o.dataset.kana || '', byKana: false, mark: '' }));
    }
    // 候補なし、または打った文字がそのまま唯一の候補なら出さない
    if (!hits.length || (seed === null && hits.length === 1 && norm(hits[0].name) === q)) return;

    document.querySelectorAll('.hint-pop').forEach((p) => p.remove()); // 「もしかして」と重ならないように
    const pop = document.createElement('div');
    pop.className = 'live-pop live-pop--float suggest-pop';
    pop.setAttribute('role', 'listbox');
    pop.id = 'suggest-pop';
    const items = hits.map((hit, i) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.tabIndex = -1;
      item.id = `suggest-pop-${i}`;
      item.className = 'live-pop__item';
      item.setAttribute('role', 'option');
      item.append(...label(hit));
      item.addEventListener('click', () => choose(input, hit.name));
      pop.append(item);
      return item;
    });
    // 押した瞬間に入力欄からフォーカスが外れて（blur で）消えないように、フォーカスを移す動きを止める
    ['mousedown', 'pointerdown'].forEach((ev) => pop.addEventListener(ev, (e) => e.preventDefault()));
    (input.closest('dialog') || document.body).append(pop); // <dialog>（showModal）の中の欄なら中に出す。body の直下だと dialog に隠れる

    place(pop, input);

    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', pop.id);
    cur = { pop, input, items, names: hits.map((h) => h.name), active: -1 };
  };

  document.addEventListener('input', (e) => {
    if (!e.target.matches?.('[data-suggest-list]')) return;
    if (skipNext) { skipNext = false; return; }
    open(e.target);
  });

  // data-suggest-seed: 押したら、同じ行の別の欄（バンド名など）の文字で候補を出す。もう開いていれば何もしない
  document.addEventListener('click', (e) => {
    const input = e.target.closest?.('[data-suggest-seed]');
    const tapped = tappedInput === input;
    tappedInput = null;
    if (!input || cur?.input === input || tapped) return; // 開いていた候補を閉じるために押したときは開き直さない
    const src = input.closest('tr')?.querySelector(input.dataset.suggestSeed);
    if (src?.value.trim()) open(input, src.value);
  });

  document.addEventListener('keydown', (e) => {
    if (!e.target.matches?.('[data-suggest-list]')) return;
    if (e.isComposing || e.keyCode === 229) return; // 日本語の変換中の ↑↓ Enter は IME のもの
    // 候補の欄の Enter はフォームを送信しない（打ちかけで「登録」されないように）。
    //   候補が開いていれば、↑↓ で選んだもの（選んでいなければ一番上）を入れる
    if (e.key === 'Enter') {
      e.preventDefault();
      if (cur?.input === e.target) choose(cur.input, cur.names[Math.max(0, cur.active)]);
      return;
    }
    if (!cur || e.target !== cur.input) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const n = cur.items.length;
      setActive(e.key === 'ArrowDown' ? (cur.active + 1) % n : (cur.active - 1 + n) % n);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close();
    }
  });

  // 日本語入力の変換中かどうか。変換中に候補を押したときは、変換が終わった直後に選んだ名前を入れ直す
  document.addEventListener('compositionstart', (e) => { if (e.target.matches?.('[data-suggest-list]')) composing = true; });
  document.addEventListener('compositionend', (e) => {
    composing = false;
    if (!pending || pending.input !== e.target) return;
    const { input, value } = pending;
    pending = null;
    setTimeout(() => { if (input.value !== value) choose(input, value); });
  });

  document.addEventListener('focusout', (e) => { if (cur && e.target === cur.input) close(); });
  // スマホ（指で操作する画面）: スクロールしても閉じずに入力欄についていく（候補を見ながら画面を動かせるように）。
  //   キーボードの出し入れで画面の高さが変わる（resize）ときも同じ。
  //   閉じるのは、候補を押したとき・入力欄をもう一度押したとき・ほかの場所を押したとき
  // PC: fixed で出しているので、ページをスクロールしたら閉じる（候補の中のスクロールは別）
  const touch = window.matchMedia('(hover: none) and (pointer: coarse)');
  const follow = () => { if (cur) place(cur.pop, cur.input); };
  window.addEventListener('scroll', (e) => {
    if (!cur || cur.pop.contains(e.target)) return;
    if (touch.matches) follow(); else close();
  }, true);
  window.addEventListener('resize', () => { if (touch.matches) follow(); else close(); });
  window.visualViewport?.addEventListener('resize', () => { if (touch.matches) follow(); });
  document.addEventListener('pointerdown', (e) => {
    if (!cur || !touch.matches || cur.pop.contains(e.target)) return;
    // 入力欄をもう一度押した / ほかの場所を押した → 閉じる（入力欄からフォーカスが外れなくても閉じる）
    //   入力欄を押したときは、このあと文字を打てば input イベントでまた開く
    tappedInput = cur.input === e.target ? e.target : null;
    close();
  }, true);
}
