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
 *    data-sortable      … タイムテーブルの行を ≡ のドラッグで並び替え（時間の列は動かない）
 *    data-album-box     … マイアルバムの開け閉め（閉じているときは先頭5枚だけ）
 *    data-album-sort    … マイアルバムをドラッグで並び替えて保存
 *    data-album-search  … アルバム検索をページ移動なしで（結果の部分だけ差し替える）
 *    data-album-add     … アルバムの追加をページ移動なしで（追加したカードを一覧に足す）
 *    data-add-roster-col … 名簿の表の右端に「Other」列を足す
 *    data-pick          … 名簿の「Vo / Gt/Vo / ⋯」「Key / Vn / ⋯」の切り替えボタンと、etc の楽器追加モーダル
 *    .table-scroll      … 横にはみ出す表をマウスのドラッグで左右に動かす
 *    data-pack          … 送信時に全項目を JSON 1個にまとめるフォーム
 *    data-rows          … バンド編集のメンバー行（追加・削除）
 *    data-print / data-autosubmit … 印刷ボタン / 選んだら即送信
 *    data-song-list / data-add-song … 曲の編集
 *    data-track-search  … 曲の編集の🔍（Spotify / iTunes の曲を探して紐付ける）
 *    data-toasts        … お知らせのポップアップ（4秒で消える）
 *    data-album-tip     … メンバー一覧のジャケットに乗せるとアルバム名・アーティスト名を出す
 * =====================================================================
 */

// HTML の読み込みが終わってから動かす（まだ無い要素は探せないので）
document.addEventListener('DOMContentLoaded', () => {
  setupToasts();
  setupThemeToggle();
  setupFilter();
  setupTabs();
  setupConfirm();
  setupDropzone();
  setupNameCheck();
  setupImportPreview();
  setupSlotSort();
  setupAlbumBox();
  setupAlbumSort();
  setupAlbumSearch();
  setupAlbumAdd();
  setupRosterColumns();
  setupPicks();
  setupDragScroll();
  setupPackedForm();
  setupMemberRows();
  setupSmallThings();
  setupSongs();
  setupTrackSearch();
  setupAlbumTip();
});

/* ---------------------------------------------------------------------
 * メンバー一覧のジャケットにマウスを乗せたら、アルバム名とアーティスト名をポップアップで出す（[data-album-tip]）
 *   表のカード（.table-card）は overflow: hidden なので、ジャケットの中に置くと端で切れる。
 *   → ページに1個だけ position: fixed のポップアップを作り、乗せたジャケットの上に動かして使い回す。
 *   文字は textContent で入れる（innerHTML だとアルバム名に < があったとき HTML として解釈される＝XSS）。
 * ------------------------------------------------------------------- */
function setupAlbumTip() {
  if (!document.querySelector('[data-album-tip]')) return;
  const tip = document.createElement('div');
  tip.className = 'album-tip';
  tip.setAttribute('role', 'tooltip');
  tip.innerHTML = '<strong class="album-tip__title"></strong><span class="album-tip__artist"></span>';
  document.body.appendChild(tip);

  const show = (img) => {
    tip.querySelector('.album-tip__title').textContent = img.dataset.tipTitle;
    tip.querySelector('.album-tip__artist').textContent = img.dataset.tipArtist;
    tip.classList.add('is-visible');
    // ジャケットの真上に出す。上に入らないときは下に出し、左右は画面からはみ出さないように寄せる
    const r = img.getBoundingClientRect();
    const t = tip.getBoundingClientRect();
    let top = r.top - t.height - 8;
    if (top < 8) top = r.bottom + 8;
    const left = Math.min(Math.max(8, r.left + r.width / 2 - t.width / 2), window.innerWidth - t.width - 8);
    tip.style.top = `${top}px`;
    tip.style.left = `${left}px`;
  };
  const hide = () => tip.classList.remove('is-visible');

  // mouseover / focusin は子要素から親へ伝わる（バブリング）ので、document に1個付けるだけで全部のジャケットに効く
  document.addEventListener('mouseover', (e) => {
    const img = e.target.closest('[data-album-tip] [data-tip-title]');
    if (img) show(img); else hide();
  });
  document.addEventListener('focusin', (e) => {
    const img = e.target.closest('[data-album-tip] [data-tip-title]');
    if (img) show(img); else hide();
  });
  window.addEventListener('scroll', hide, { passive: true }); // fixed なのでスクロールするとずれる → 隠す
}

/* ---------------------------------------------------------------------
 * お知らせのポップアップ（partials/header.php の [data-toasts]）
 *   「更新しました」などを、ヘッダーの下に4秒だけ出す。クリックするとすぐ消える。
 *   showToast(要素) で、ページ移動なしの処理（setupAlbumAdd）からも出せる。
 * ------------------------------------------------------------------- */
const TOAST_MS = 4000;

function showToast(toast) {
  const box = document.querySelector('[data-toasts]');
  if (!box) return;
  toast.classList.add('toast');
  if (!toast.isConnected) box.appendChild(toast); // ページを開いた時点のお知らせは、もう入れ物の中にある

  let gone = false;
  const close = () => {
    if (gone) return;
    gone = true;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { toast.remove(); return; }
    toast.classList.add('is-leaving');                          // CSS でフェードアウト
    toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    setTimeout(() => toast.remove(), 500);                      // transitionend が来なかったとき用
  };
  setTimeout(close, TOAST_MS);
  toast.addEventListener('click', close);
}

function setupToasts() {
  document.querySelectorAll('[data-toasts] .toast').forEach(showToast);
}

/* ---------------------------------------------------------------------
 * 曲の編集（songs_edit.php）
 *   data-add-song       … 最後のカードをコピーして空の曲カードを足す
 *   data-toggle-all     … そのカードの全員のチェックを一括で ON / OFF
 *   data-performer-on   … チェックを外した人を薄く表示
 *   data-omnibus        … オムニバスのチェック。外すとアーティスト欄をバンドのアーティストに戻して編集不可にする
 * ------------------------------------------------------------------- */
function setupSongs() {
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  const omnibus = document.querySelector('[data-omnibus]');
  const defaultArtist = list.dataset.defaultArtist;
  omnibus.addEventListener('change', () => {
    list.querySelectorAll('[data-song-artist]').forEach((input) => {
      if (omnibus.checked) {
        input.readOnly = false;
        if (input.dataset.typed !== undefined) input.value = input.dataset.typed; // 前に書いていた名前を戻す
      } else {
        input.dataset.typed = input.value; // チェックを付け直したときのために覚えておく
        input.value = defaultArtist;
        input.readOnly = true;
      }
    });
  });

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
    card.querySelector('[data-song-delete]')?.remove();      // 新しい曲に「削除」は不要
    card.querySelector('[data-song-delete-btn]')?.remove();
    card.classList.remove('is-deleted');
    card.querySelector('[data-song-no]').textContent = String(cards.length + 1);
    const artist = card.querySelector('[data-song-artist]');
    artist.value = defaultArtist; // コピー元の曲のアーティストは引き継がない
    delete artist.dataset.typed;
    setTrack(card, null); // コピー元の曲の紐付けも引き継がない
    card.querySelectorAll('[data-performer-on]').forEach((cb) => { cb.checked = true; cb.closest('.performer').classList.remove('is-off'); });
    card.classList.add('song-card--new');
    list.appendChild(card);
    card.querySelector('[name$="[title]"]').focus();
  });

  // カードが後から増えるので、list でまとめてイベントを受ける（イベント委譲）
  list.addEventListener('click', (e) => {
    // 🗑 削除の印を付ける / 外す（消えるのは保存したとき）
    const del = e.target.closest('[data-song-delete-btn]');
    if (del) {
      const card = del.closest('[data-song-card]');
      const on = !card.classList.contains('is-deleted');
      card.classList.toggle('is-deleted', on);
      card.querySelector('[data-song-delete]').value = on ? '1' : '';
      del.setAttribute('aria-pressed', String(on));
      del.setAttribute('aria-label', on ? '削除を取り消す' : 'この曲を削除');
      return;
    }
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
 * 曲を Spotify / iTunes の曲と紐付ける（songs_edit.php）
 *   data-track-search  … 🔍「曲名 アーティスト」で api_track_search.php に聞いて、候補をカードの中に出す
 *   data-track-results … 候補の一覧。押すとその曲を紐付ける（隠し項目 data-track-key にキーを入れる）
 *   data-track-clear   … 紐付けを外す（ジャケットに重なったリンクが切れるマーク）
 *   ここで入れるのはキー（"spotify:xxxx"）だけ。曲名やジャケットは保存するときにサーバーが取り直す
 * ------------------------------------------------------------------- */

/** Material Symbols のアイコン（PHP の icon() と同じ形）を作る。名前は textContent で入れる */
function iconEl(name) {
  const span = document.createElement('span');
  span.className = 'icon';
  span.setAttribute('aria-hidden', 'true');
  span.textContent = name;
  return span;
}

/** カードの紐付けを変える。track = 候補1件（{key, title, artist_name, artwork_url}）/ null = 外す */
function setTrack(card, track) {
  card.querySelector('[data-track-key]').value = track ? track.key : '';
  const thumb = card.querySelector('[data-track-thumb]'); // ジャケットの中身（画像か ♪）
  thumb.replaceChildren();
  if (track) {
    const img = document.createElement('img');
    img.src = track.artwork_url;
    img.alt = '';
    thumb.appendChild(img);
    thumb.parentElement.title = `${track.title} / ${track.artist_name}`;
  } else {
    thumb.appendChild(iconEl('music_note'));
    thumb.parentElement.title = '';
  }
  card.querySelector('[data-track-clear]').hidden = !track;
  closeResults(card.querySelector('[data-track-results]'));
}

/**
 * 検索結果の窓を、アニメーションしてから閉じる。
 *   hidden を付けると一瞬で消えてしまうので、先に is-closing（CSS で縮みながら消える）を付けて、
 *   アニメーションが終わってから hidden を付ける。
 *   閉じている途中でまた開いたら（openResults）、閉じるのをやめる。
 */
function closeResults(results) {
  if (results.hidden || results.classList.contains('is-closing')) return;
  results.classList.add('is-closing');
  const done = () => {
    if (!results.classList.contains('is-closing')) return; // 途中で開き直された
    results.classList.remove('is-closing');
    results.hidden = true;
    results.replaceChildren();
  };
  // 中の候補のアニメーションの終わり（泡のように上がってくる）では閉じないよう、窓そのもののときだけ
  const onEnd = (e) => {
    if (e.target !== results) return;
    results.removeEventListener('animationend', onEnd);
    done();
  };
  results.addEventListener('animationend', onEnd);
  setTimeout(done, 300); // 動きを減らす設定などで animationend が来ないときの保険
}

function openResults(results) {
  results.classList.remove('is-closing');
  results.hidden = false;
}

function setupTrackSearch() {
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  // 候補1件ぶんのボタンを作る。外から来た文字は textContent で入れる（innerHTML だと XSS になりうる）
  const optionButton = (track) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'track-option';
    const img = document.createElement('img');
    img.src = track.artwork_url;
    img.alt = '';
    img.loading = 'lazy';
    const text = document.createElement('span');
    const title = document.createElement('div');
    title.className = 'track-option__title';
    title.textContent = track.title;
    const sub = document.createElement('div');
    sub.className = 'track-option__sub';
    sub.textContent = `${track.artist_name} ・ ${track.album_title}`;
    text.append(title, sub);
    btn.append(img, text);
    return btn;
  };
  const message = (results, text) => {
    const p = document.createElement('p');
    p.className = 'track-results__msg';
    p.textContent = text;
    results.replaceChildren(p);
    openResults(results);
  };

  list.addEventListener('click', async (e) => {
    const card = e.target.closest('[data-song-card]');
    if (!card) return;

    if (e.target.closest('[data-track-clear]')) {
      setTrack(card, null);
      return;
    }

    const searchBtn = e.target.closest('[data-track-search]');
    if (!searchBtn) return;
    const results = card.querySelector('[data-track-results]');
    if (!results.hidden && !results.classList.contains('is-closing')) { closeResults(results); return; } // もう一度押したら閉じる
    const title = card.querySelector('[name$="[title]"]').value.trim();
    if (title === '') {
      message(results, '先に曲名を入れてから探してください');
      return;
    }
    const q = `${title} ${card.querySelector('[data-song-artist]').value.trim()}`;
    message(results, '検索中…');
    searchBtn.disabled = true;
    try {
      // encodeURIComponent: 日本語や & などを URL で使える形にする
      const res = await fetch(`api_track_search.php?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);
      if (data.tracks.length === 0) {
        message(results, '見つかりませんでした。曲名やアーティスト名を変えて探してください');
        return;
      }
      results.replaceChildren(...data.tracks.map((track) => {
        const btn = optionButton(track);
        btn.addEventListener('click', () => setTrack(card, track));
        return btn;
      }));
    } catch (err) {
      message(results, err.message || '検索できませんでした');
    } finally {
      searchBtn.disabled = false;
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

  // data-fill-hint … 入学年度の一括編集で、空欄の入力欄に data-hint（初出演の年度）を入れる。保存はしない
  document.querySelectorAll('[data-fill-hint]').forEach((btn) => btn.addEventListener('click', () => {
    btn.form.querySelectorAll('input[data-hint]').forEach((input) => {
      if (input.value === '') {
        input.value = input.dataset.hint;
      }
    });
  }));
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
  // フォーム1つ1つではなく document で待ち受ける（submit イベントは外側へ伝わってくる = バブリング）。
  //   → 後から JS で足したフォーム（ページ移動なしで追加したアルバムの × など）にも効く
  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-confirm]');
    if (form && !confirm(form.dataset.confirm)) e.preventDefault();
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
 *   - 名簿側の「登録」チェック（外すと薄く。タイムテーブルの「取込」を外すと連動して外れる）
 *     どの枠にも選ばれていない名簿のバンドに「タイムテーブルにないため登録されません」を出す
 *   - 「名簿なし ◯件」バッジを更新
 *   - 開催日 → 年度の表示、会場「新規作成」で会場名の入力欄を出す
 * ------------------------------------------------------------------- */
function setupImportPreview() {
  const slotRows = [...document.querySelectorAll('tr[data-slot]')];
  if (!slotRows.length) return;

  // 検索欄の文字 → 'ri:bi'（<datalist id="dl-roster"> の option から作る）
  const rosterKeys = {};
  document.querySelectorAll('#dl-roster option').forEach((opt) => { rosterKeys[opt.value] = opt.dataset.key; });

  const submit = document.querySelector('[data-submit]');
  const submitBlock = document.querySelector('[data-submit-block]');
  const submitDisabled = submit.disabled; // PHP が最初から押せなくしていた（タイムテーブルが無い）なら、ずっとそのまま

  const refresh = () => {
    const usedBy = {}; // 'ri:bi' → [出演バンド名...]
    // 本当に登録される枠か（「取込」にチェック & 「この日程は取り込まない」ではない）。PHP の重複チェックと同じ条件
    const counted = (tr) => tr.querySelector('[data-include]').checked
      && !tr.closest('[data-timetable]').querySelector('[data-skip]').checked;

    // 1周目: どの名簿をどの枠が使っているか集める
    slotRows.forEach((tr) => {
      tr.classList.toggle('is-excluded', !tr.querySelector('[data-include]').checked);
      const key = rosterKeys[tr.querySelector('[data-roster-input]').value.trim()];
      if (key && counted(tr)) {
        (usedBy[key] ||= []).push(tr.querySelector('[data-band-name]').value);
      }
    });

    // 2周目: 色を付ける。緑 = 名簿あり / 黄 = 同じ名簿を他の枠でも選んでいる / 赤 = 名簿なし
    let dupCount = 0;
    slotRows.forEach((tr) => {
      const include = tr.querySelector('[data-include]').checked;
      const input = tr.querySelector('[data-roster-input]');
      const key = rosterKeys[input.value.trim()];
      const dup = !!key && counted(tr) && usedBy[key].length > 1;
      if (dup) dupCount++;
      input.classList.toggle('is-ok', !!key && !dup);
      input.classList.toggle('is-similar', dup);
      input.classList.toggle('is-new', !key && include);
      input.title = dup ? `同じ名簿を ${usedBy[key].length} つの枠で選んでいます: ${usedBy[key].join(' / ')}` : '';
    });

    // 名簿側: 「登録」を外した行を薄くし、どの枠にも選ばれていない（= 登録されない）行に ⚠ を出す
    document.querySelectorAll('tr[data-roster-key]').forEach((tr) => {
      const on = tr.querySelector('[data-roster-on]').checked;
      tr.classList.toggle('is-excluded', !on);
      tr.querySelector('[data-roster-missing]').hidden = !on || !!usedBy[tr.dataset.rosterKey];
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

    // 名簿の重複が1つでもあれば「登録する」を押せなくする（同じバンドが2回出ることは無いので入力ミス）
    submit.disabled = submitDisabled || dupCount > 0;
    submitBlock.hidden = dupCount === 0;
  };

  // タイムテーブルの「取込」を切り替えたら、その枠が選んでいる名簿の「登録」も合わせる
  //   （同じバンドが2日とも出ることは無いので、名簿の1バンドを使うのは1枠だけ、という前提）
  const syncRosterOn = (checkbox) => {
    const key = rosterKeys[checkbox.closest('tr[data-slot]').querySelector('[data-roster-input]').value.trim()];
    const row = key && document.querySelector(`tr[data-roster-key="${key}"]`);
    if (row) row.querySelector('[data-roster-on]').checked = checkbox.checked;
  };

  // 開催日 → 年度（4月始まり。1〜3月は前の年の年度）。PHP の academic_year() と同じ計算
  const showFiscalYear = (input) => {
    const out = input.closest('.field').querySelector('[data-fiscal-year]');
    const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(input.value);
    out.textContent = m ? `→ ${Number(m[2]) >= 4 ? Number(m[1]) : Number(m[1]) - 1}年度` : '';
  };

  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-include]')) {
      renumberSlots(e.target.closest('tbody')); // 取込を切り替えたら出演順を振り直す
      syncRosterOn(e.target);
    }
    if (e.target.matches('[data-include], [data-roster-input], [data-roster-on], [data-skip]')) refresh();
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
  // タイムテーブルに無い名簿のバンドがあれば注意を出す（PHP が初回だけ <dialog> を置く）
  document.querySelector('[data-roster-missing-dialog]')?.showModal();
}

/* ---------------------------------------------------------------------
 * タイムテーブルの出演順を振り直す（並び替え・取込の切り替えのあとに呼ぶ）
 *   取込にチェックがある行を上から 1, 2, 3…。チェックが無い行（休憩など）は空。
 *   表示用の文字と、送信用の hidden（PHP の commit_import_plan が並べ替えに使う）の両方を書き換える。
 * ------------------------------------------------------------------- */
function renumberSlots(tbody) {
  let n = 0;
  [...tbody.rows].forEach((tr) => {
    const no = tr.querySelector('[data-include]').checked ? String(++n) : '';
    tr.querySelector('[data-order]').value = no;
    tr.querySelector('[data-order-text]').textContent = no;
  });
}

/* ---------------------------------------------------------------------
 * タイムテーブルの行の並び替え（左端の ≡ をドラッグ。↑↓キーでも動く）
 *   時間の列は「何行目か」に固定: 行を動かしたら、各行の時間の表示と
 *   hidden の at（どの枠の時間を使うか。PHP の commit_import_plan が見る）を位置に合わせて付け直す。
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
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const cellsOf = (tr) => [...tr.querySelectorAll('td:not([data-time])')]; // 動かすセル（時間以外）

  // 最初の並びで「n 行目の時間」を覚えておく（並び替えても n 行目の時間はこれのまま）
  const times = new Map(bodies.map((tbody) => [tbody, [...tbody.rows].map((tr) => ({
    text: tr.querySelector('[data-time]').textContent,
    at: tr.querySelector('[data-at]').value,
  }))]));

  // 並びが変わったあとの後始末: 時間を位置に戻し、出演順を振り直す
  const settle = (tbody) => {
    [...tbody.rows].forEach((tr, i) => {
      tr.querySelector('[data-time]').textContent = times.get(tbody)[i].text;
      tr.querySelector('[data-at]').value = times.get(tbody)[i].at;
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

  let drag = null; // ドラッグ中だけ { row, tbody, pointerId, y, grab, off, timer }

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
    const { row, off, timer } = drag;
    clearInterval(timer);
    drag = null;
    document.body.classList.remove('is-row-dragging');
    // 離した場所から定位置へ滑って戻る。戻り終わってから「浮いている」見た目を外す
    cellsOf(row).forEach((td) => {
      td.style.transform = '';
      if (!reduceMotion && off) td.animate([{ transform: `translateY(${off}px)` }, { transform: 'none' }], { duration: 150, easing: 'ease-out' });
    });
    setTimeout(() => { if (drag?.row !== row) row.classList.remove('is-dragging'); }, reduceMotion ? 0 : 150);
  };

  document.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('[data-drag-handle]');
    if (!handle || drag || (e.pointerType === 'mouse' && e.button !== 0)) return;
    e.preventDefault(); // 文字選択やスクロールを始めさせない
    const row = handle.closest('tr');
    cellsOf(row).forEach((td) => td.getAnimations().forEach((a) => a.cancel()));
    // grab = 行の上端から、つかんだ所までの高さ（これを保ったまま付いてこさせる）
    drag = { row, tbody: row.parentElement, pointerId: e.pointerId, y: e.clientY, grab: e.clientY - row.getBoundingClientRect().top, off: 0 };
    handle.setPointerCapture(e.pointerId); // 指やマウスが ≡ の外に出ても追いかける
    row.classList.add('is-dragging');
    document.body.classList.add('is-row-dragging');
    // 画面の上端・下端に近づけたら自動でスクロール（長い表を一度に動かせるように）
    drag.timer = setInterval(() => {
      if (!drag) return;
      const edge = 60;
      const dy = drag.y < edge ? -12 : drag.y > window.innerHeight - edge ? 12 : 0;
      if (dy) {
        window.scrollBy(0, dy);
        moveTo(drag.y);
      }
    }, 16);
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
    flip(tbody, () => {
      if (e.key === 'ArrowUp') tbody.insertBefore(row, prev);
      else tbody.insertBefore(next, row);
      settle(tbody);
    });
    handle.focus(); // insertBefore で動かすとフォーカスが外れるブラウザがあるので戻す
  });
}

/* ---------------------------------------------------------------------
 * 好きなアルバムの並び替え（member.php。本人だけ）
 *   カードをつかんで動かすだけ。ボタンを押して「並び替えモード」にする必要はない。
 *     マウス … 押したまま 5px 以上動かしたらドラッグ開始（動かさずに離せば普通のクリック。× ボタンも押せる）
 *     指     … 0.35 秒長押しでドラッグ開始（すぐ指を動かしたら、いつもどおりページのスクロール）
 *   離したら api_album_order.php に新しい順番を fetch() で送って保存する（保存ボタンは無い）。
 *   キーボード: カードにフォーカス（Tab）して ← → ↑ ↓ で1つずつ動かす。
 *
 * アニメーションは setupSlotSort と同じ FLIP。ただし表の行と違って「縦横」に動くので translate(x, y)。
 *   つかんだカード … 少し大きく浮いて、ポインタに付いてくる。離すと定位置へ滑って戻る
 *   ほかのカード   … 場所が入れ替わるとき、元の位置から新しい位置へスッと滑る
 * ------------------------------------------------------------------- */
function setupAlbumSort() {
  const list = document.querySelector('[data-album-sort]');
  if (!list) return; // 0枚・1枚でも準備しておく（ページ移動なしで追加されて2枚以上になることがあるので）
  const status = document.querySelector('[data-album-sort-status]');
  const token = document.querySelector('meta[name="csrf-token"]').content;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const cards = () => [...list.children];
  const idsOf = () => cards().map((li) => li.dataset.id); // "spotify:xxxx" のようなキーの並び
  let saved = idsOf().join(); // 最後に保存できた並び。これと比べて、変わったときだけ送る

  // 何もしていないときに出しておく案内（指で操作する端末かどうかで言い方を変える）
  //   1枚以下なら並び替えるものが無いので出さない
  const hintText = window.matchMedia('(pointer: coarse)').matches ? '長押しで並び替え' : 'ドラッグで並び替え';
  const hint = () => (list.children.length > 1 ? hintText : '');
  let statusTimer = null;
  // done = true なら頭にチェックのアイコンを付ける
  const showStatus = (text, backToHintAfter = 0, done = false) => {
    clearTimeout(statusTimer);
    status.textContent = text;
    if (done) status.prepend(iconEl('check'), ' ');
    if (backToHintAfter) statusTimer = setTimeout(() => { status.textContent = hint(); }, backToHintAfter);
  };

  // 左上の順位バッジを今の並びに合わせる
  const renumber = () => cards().forEach((li, i) => { li.querySelector('.album__rank').textContent = i + 1; });

  // カードが増えたとき（setupAlbumAdd が 'albums:changed' を送ってくる）にも呼ぶ準備
  //   tabindex=0: Tab キーでカードに移れるようにする（キーボードで並び替えるため）
  const prepare = () => {
    cards().forEach((li) => { li.tabIndex = 0; });
    renumber();
    saved = idsOf().join(); // 追加された分も「保存済みの並び」に含める
    if (!saving) showStatus(hint());
  };
  list.addEventListener('albums:changed', prepare);

  // change() で DOM を入れ替え、skip 以外のカードを「元の見た目の位置」から「新しい位置」へ滑らせる（FLIP）
  const flip = (change, skip) => {
    const before = new Map(cards().map((li) => [li, li.getBoundingClientRect()])); // First（動いている途中ならその位置）
    change();
    renumber();
    if (reduceMotion) return;
    cards().forEach((li) => {
      if (li === skip) return;
      li.getAnimations().forEach((a) => a.cancel()); // 前のアニメーションを止めてから測る（止めないと途中の位置を測ってしまう）
      const now = li.getBoundingClientRect();         // Last
      const dx = before.get(li).left - now.left;      // Invert
      const dy = before.get(li).top - now.top;
      if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
      li.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: 200, easing: 'ease-out' }); // Play
    });
  };

  // ---- 保存（fetch） ----
  let saving = false;
  let again = false; // 保存中にまた動かされたら、終わってからもう一度保存する
  const save = async () => {
    const order = idsOf();
    if (order.join() === saved) return;
    if (saving) { again = true; return; }
    saving = true;
    showStatus('保存中…');
    try {
      const res = await fetch('api_album_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ member_id: Number(list.dataset.memberId), order }),
      }).catch(() => null); // 通信そのものが失敗したら null
      if (!res) throw new Error('通信できませんでした。もう一度動かしてみてください');
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || '保存できませんでした。ページを再読み込みしてください');
      saved = order.join();
      showStatus('保存しました', 2000, true);
    } catch (err) {
      showStatus(err.message, 6000);
      // 保存できなかったので、最後に保存できた並びへ戻す（画面と DB がずれたままにしない）
      const byId = new Map(cards().map((li) => [li.dataset.id, li]));
      flip(() => saved.split(',').forEach((id) => list.appendChild(byId.get(id))));
    } finally {
      saving = false;
      if (again) { again = false; save(); }
    }
  };

  // ---- ドラッグ ----
  //   press … 押した直後〜ドラッグが始まるまで { li, pointerId, type, x, y, startX, startY, timer }
  //   drag  … ドラッグ中だけ                  { li, pointerId, x, y, grabX, grabY, timer }
  let press = null;
  let drag = null;

  // つかんだカードをポインタに付いてこさせる。
  //   offsetLeft / offsetTop は transform の影響を受けない「本来の位置」（ul の左上から）。
  //   そこから「ポインタ − つかんだ点」までの差だけ translate でずらして見せる
  const follow = () => {
    const box = list.getBoundingClientRect();
    const { li } = drag;
    const dx = drag.x - drag.grabX - box.left - li.offsetLeft;
    const dy = drag.y - drag.grabY - box.top - li.offsetTop;
    li.style.transform = `translate(${dx}px, ${dy}px)`;
  };

  // ポインタが乗っている「ほかのカードの本来の場所」に割り込む。
  //   前から来たならそのカードの後ろ、後ろから来たなら前へ（どちらも、そのカードの場所に自分が入る）
  //   見た目の位置（アニメーション中）ではなく本来の位置で判定するので、行ったり来たりのブルブルが起きない
  const moveTo = () => {
    const { li, x, y } = drag;
    const box = list.getBoundingClientRect();
    const all = cards();
    const target = all.find((c) => {
      if (c === li) return false;
      const left = box.left + c.offsetLeft;
      const top = box.top + c.offsetTop;
      return x >= left && x < left + c.offsetWidth && y >= top && y < top + c.offsetHeight;
    });
    if (target) {
      const fromBehind = all.indexOf(li) > all.indexOf(target);
      flip(() => list.insertBefore(li, fromBehind ? target : target.nextElementSibling), li);
    }
    follow(); // 自分の本来の位置が変わったので、ずらす量を計算し直す
  };

  const cancelPress = () => {
    if (!press) return;
    clearTimeout(press.timer);
    press.li.classList.remove('is-pressing');
    press = null;
  };

  // press → drag に切り替える（マウスは動かしたとき、指は長押しが終わったとき）
  const startDrag = () => {
    const { li, pointerId, x, y, startX, startY, type } = press;
    cancelPress();
    li.getAnimations().forEach((a) => a.cancel());
    const r = li.getBoundingClientRect();
    // grabX / grabY = カードの左上から、押した所までの距離（これを保ったまま付いてこさせる）
    drag = { li, pointerId, x, y, grabX: startX - r.left, grabY: startY - r.top };
    try { li.setPointerCapture(pointerId); } catch { /* もう離されていたら何もしない */ }
    li.classList.add('is-dragging');
    list.classList.add('is-sorting'); // 順位バッジを出す
    document.body.classList.add('is-album-dragging');
    if (type === 'touch') navigator.vibrate?.(10); // 対応している端末だけ短く震わせて、つかんだことを伝える
    follow();
    // 画面の上端・下端に近づけたら自動でスクロール（30枚あると画面に収まらないので）
    drag.timer = setInterval(() => {
      if (!drag) return;
      const edge = 60;
      const dy = drag.y < edge ? -12 : drag.y > window.innerHeight - edge ? 12 : 0;
      if (dy) {
        window.scrollBy(0, dy);
        moveTo();
      }
    }, 16);
  };

  const end = (e) => {
    if (press && e.pointerId === press.pointerId) cancelPress(); // 動かさずに離した = ただのクリック / タップ
    if (!drag || e.pointerId !== drag.pointerId) return;
    const { li, timer } = drag;
    clearInterval(timer);
    drag = null;
    list.classList.remove('is-sorting');
    document.body.classList.remove('is-album-dragging');
    // 離した場所から定位置へ滑って戻る。戻り終わってから「浮いている」見た目を外す
    const from = li.style.transform;
    li.style.transform = '';
    if (!reduceMotion && from) li.animate([{ transform: from }, { transform: 'none' }], { duration: 180, easing: 'ease-out' });
    setTimeout(() => { if (drag?.li !== li) li.classList.remove('is-dragging'); }, reduceMotion ? 0 : 180);
    save();
  };

  list.addEventListener('pointerdown', (e) => {
    const li = e.target.closest('.album');
    // × ボタン（削除フォーム）や文字のリンクの上で押したときは、ドラッグではなく普通にボタン・リンクとして使わせる
    //   → ドラッグできるのはジャケットの部分だけ
    if (!li || press || drag || e.target.closest('form, button, a')) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return; // 左クリック以外は無視
    if (e.pointerType === 'mouse') e.preventDefault(); // マウスでの文字選択を始めさせない（指のときはスクロールのために止めない）
    press = { li, pointerId: e.pointerId, type: e.pointerType, x: e.clientX, y: e.clientY, startX: e.clientX, startY: e.clientY, timer: null };
    if (e.pointerType === 'touch') {
      li.classList.add('is-pressing'); // 長押し中は少し沈ませて「今つかもうとしている」ことを見せる
      press.timer = setTimeout(startDrag, 350);
    }
  });
  document.addEventListener('pointermove', (e) => {
    if (press && e.pointerId === press.pointerId) {
      press.x = e.clientX;
      press.y = e.clientY;
      const moved = Math.hypot(e.clientX - press.startX, e.clientY - press.startY);
      if (press.type === 'touch') {
        if (moved > 8) cancelPress(); // 長押しの前に指が動いた = スクロールしたい
      } else if (moved > 5) {
        startDrag();
      }
      return;
    }
    if (!drag || e.pointerId !== drag.pointerId) return;
    drag.x = e.clientX;
    drag.y = e.clientY;
    moveTo();
  });
  document.addEventListener('pointerup', end);
  document.addEventListener('pointercancel', end); // 指でスクロールが始まったときもここに来る
  // ドラッグ中に指を動かしても、ページがスクロールしないようにする。
  //   passive: false にしないと preventDefault() が効かない（ブラウザがスクロールを優先する）
  list.addEventListener('touchmove', (e) => { if (drag) e.preventDefault(); }, { passive: false });
  // 画像やリンクはブラウザ標準の「ドラッグ」（URL や画像を他の場所へ運ぶ機能）が始まってしまうので止める
  list.addEventListener('dragstart', (e) => e.preventDefault());
  list.addEventListener('contextmenu', (e) => { if (press || drag) e.preventDefault(); });

  // ---- キーボード ----
  let keyTimer = null;
  list.addEventListener('keydown', (e) => {
    if (drag) return;
    const li = e.target.closest('.album');
    const back = e.key === 'ArrowLeft' || e.key === 'ArrowUp';
    const forward = e.key === 'ArrowRight' || e.key === 'ArrowDown';
    // カードそのものにフォーカスがあるときだけ（× ボタンにフォーカスがあるときは動かさない）
    if (!li || li !== e.target || (!back && !forward)) return;
    e.preventDefault();
    const prev = li.previousElementSibling;
    const next = li.nextElementSibling;
    if (back ? !prev : !next) return; // 端なのでもう動けない
    flip(() => (back ? list.insertBefore(li, prev) : list.insertBefore(next, li)));
    li.focus(); // insertBefore で動かすとフォーカスが外れるブラウザがあるので戻す
    // 1回押すごとに送ると多すぎるので、押し終わって 0.5 秒たったらまとめて保存
    clearTimeout(keyTimer);
    keyTimer = setTimeout(save, 500);
  });

  prepare(); // 最初の準備（let で宣言した変数を使うので、全部の宣言が終わったこの位置で呼ぶ）
}

/* ---------------------------------------------------------------------
 * アルバム検索をページ移動なしで（member.php の「＋ アルバムを追加」）
 *   普通に送信するとページごと読み直しになり、スクロール位置も変わってしまう。
 *   そこで送信を止めて、同じ URL（member.php?id=..&album_q=..）を fetch() で裏で取りに行き、
 *   返ってきた HTML から [data-album-results] の部分だけ抜き出して、今の画面の同じ部分と入れ替える。
 *   → 検索や表示の処理は PHP のものをそのまま使える（JS で同じ表示を作り直さなくていい）
 *   うまくいかなかったとき（通信エラー・ログインが切れた等）は、今までどおり普通に送信する。
 * ------------------------------------------------------------------- */
function setupAlbumSearch() {
  const form = document.querySelector('form[data-album-search]');
  if (!form) return;
  const button = form.querySelector('[type="submit"]');
  let busy = false;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return; // 検索中にもう一度押されても無視（二重送信防止）
    busy = true;
    const label = button.textContent;
    button.disabled = true;
    button.textContent = '検索中…';

    // FormData: フォームの入力をまとめて取り出す → URLSearchParams で "id=1&album_q=..." の形にする
    //   getAttribute('action') は "member.php#albums"。# 以降は要らないので切り落とす
    const url = `${form.getAttribute('action').split('#')[0]}?${new URLSearchParams(new FormData(form))}`;
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      // DOMParser: 文字列の HTML を、画面に出さずに「部品の木（DOM）」として読み込む
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const fresh = doc.querySelector('[data-album-results]');
      // 見つからない = 別のページ（ログイン切れで login.php に飛ばされた等）が返ってきた
      if (!fresh) throw new Error();
      document.querySelector('[data-album-results]').replaceWith(fresh);
      fresh.classList.add('is-fresh'); // ふわっと出す（CSS）
      // アドレスバーの URL だけ書き換える（移動はしない）。再読み込みしても同じ検索結果が出るように
      history.replaceState(null, '', url);
    } catch {
      form.submit(); // 普通の送信（ページ移動）でやり直す。submit() は submit イベントを起こさないので、ここに戻ってこない
      return;
    } finally {
      busy = false;
      button.disabled = false;
      button.textContent = label;
    }
  });
}

/* ---------------------------------------------------------------------
 * マイアルバムの開け閉め（member.php）
 *   閉じているとき … 先頭5枚だけ（6枚目以降と「アルバムを追加」は CSS で隠す）
 *   開いているとき … 全部 ＋ 自分のページなら一番下に「アルバムを追加」
 *   見出しボタンと、一覧の下の「さらに表示」/「閉じる」ボタンのどちらでも切り替えられる。
 *   js-collapsible クラスを付けたときだけ CSS が隠す → JS が動かない環境では全部見えたまま。
 * ------------------------------------------------------------------- */
function setupAlbumBox() {
  const box = document.querySelector('[data-album-box]');
  if (!box) return;
  const list = box.querySelector('.albums');
  const toggle = box.querySelector('[data-album-toggle]');
  const more = box.querySelector('[data-album-more]');
  const SHOWN = 5; // 閉じているときに見せる枚数（CSS の nth-child(n+6) と合わせる）
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 画面の表示を「開いているか」に合わせる
  const render = () => {
    const open = box.classList.contains('is-open');
    const count = list ? list.children.length : 0;
    // 閉じると隠れるものがあるか（6枚目以降 or 自分のページの「アルバムを追加」）。無ければボタンは要らない
    const hasHidden = count > SHOWN || box.querySelector('.album-search') !== null;
    toggle.setAttribute('aria-expanded', String(open));
    more.hidden = !hasHidden;
    more.textContent = open ? '閉じる' : 'さらに表示';
  };

  const setOpen = (open) => {
    box.classList.toggle('is-open', open);
    render();
    // 開いたとき、隠れていたカード（6枚目以降）をふわっと出す
    if (open && list && !reduceMotion) {
      [...list.children].slice(SHOWN).forEach((li, i) => {
        li.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
          { duration: 220, delay: Math.min(i, 10) * 20, easing: 'ease-out', fill: 'backwards' });
      });
    }
  };

  box.classList.add('js-collapsible');
  toggle.addEventListener('click', () => setOpen(!box.classList.contains('is-open')));
  more.addEventListener('click', () => {
    const closing = box.classList.contains('is-open');
    setOpen(!closing);
    // 閉じると下の方が無くなって画面が飛ぶので、見出しが見える位置まで戻す
    if (closing && box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth' });
  });
  // JS を使わずに追加・削除したあとは member.php#albums に戻ってくる → 開いた状態にする
  if (location.hash === '#albums') box.classList.add('is-open');
  // 枚数が変わったら（ページ移動なしで追加したとき）ボタンの出し方を見直す
  list?.addEventListener('albums:changed', render);
  render();
}

/* ---------------------------------------------------------------------
 * アルバムの「追加」をページ移動なしで（member.php の検索結果の「追加」ボタン）
 *   member_album_save.php に fetch() で POST する。保存が終わると member.php へリダイレクトされ、
 *   fetch はそれに自動で付いていくので、最後は「追加後の member.php の HTML」が返ってくる。
 *   その中から次のものを取り出して、今の画面に反映する:
 *     ・追加されたアルバムのカード（data-id が送ったキーと同じ <li>）→ 一覧の最後に足す
 *     ・「◯ / 30」の枚数
 *     ・サーバーのメッセージ（.flash。「追加しました」「30枚までです」など）
 *   検索結果は差し替えた後の要素なので、document で待ち受ける（後から増えた「追加」ボタンにも効く）。
 * ------------------------------------------------------------------- */
function setupAlbumAdd() {
  const list = document.querySelector('[data-album-sort]');
  if (!list) return;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-album-add]');
    if (!form || e.defaultPrevented) return;
    e.preventDefault();
    const button = form.querySelector('[type="submit"]');
    if (button.disabled) return; // 追加中にもう一度押されても無視（二重送信防止）
    button.disabled = true;
    button.textContent = '追加中…';
    const key = form.elements.album.value;
    const item = form.closest('li');

    try {
      // ⚠ form.action と書くと、<input name="action"> の方が返ってきてしまう（名前がかぶるため）。
      //   なので getAttribute('action') で「action 属性の文字列」を取る
      const res = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const freshList = doc.querySelector('[data-album-sort]');
      if (!freshList) throw new Error(); // 別のページ（ログイン切れで login.php など）が返ってきた

      // 検索結果の位置がずれないように、変更前の位置を覚えておく（上の一覧が1行増えると、下が押し下げられるため）
      const before = item.getBoundingClientRect().top;

      // サーバーのメッセージは、ほかのページと同じくヘッダーの下のポップアップで出す
      doc.querySelectorAll('[data-toasts] .flash').forEach((f) => showToast(document.importNode(f, true)));
      const card = [...freshList.children].find((li) => li.dataset.id === key);
      if (!card) {
        // 追加されていない（30枚の上限・Spotify から情報を取れなかった など）。理由は上のメッセージに出ている
        button.disabled = false;
        button.textContent = '追加';
        return;
      }

      // CSS.escape: キーに記号が入っていてもセレクタ（[data-id="..."]）が壊れないようにする
      if (!list.querySelector(`[data-id="${CSS.escape(key)}"]`)) {
        const newCard = document.importNode(card, true); // 別の文書（doc）の要素を、この画面で使える形にコピー
        list.appendChild(newCard);
        list.dispatchEvent(new CustomEvent('albums:changed')); // 並び替えの準備をし直してもらう（setupAlbumSort）
        if (!reduceMotion) newCard.animate([{ opacity: 0, transform: 'scale(.9)' }, { opacity: 1, transform: 'none' }], { duration: 250, easing: 'ease-out' });
      }
      document.querySelector('[data-album-empty]')?.remove();
      document.querySelector('[data-album-count]').textContent = doc.querySelector('[data-album-count]').textContent;

      // 「追加」ボタンを「登録済み」に変える
      const pill = document.createElement('span');
      pill.className = 'pill';
      pill.textContent = '登録済み';
      form.replaceWith(pill);

      // 30枚に達したら、検索欄の代わりに「上限です」の案内を出す（サーバーが返したものに入れ替える）
      const freshSearch = doc.querySelector('.album-search');
      if (freshSearch && !freshSearch.querySelector('form[data-album-search]')) {
        document.querySelector('.album-search').replaceWith(document.importNode(freshSearch, true));
        return;
      }

      // 位置がずれた分だけスクロールして戻す（ブラウザが自動で直してくれていれば差は0なので何もしない）
      const shift = item.getBoundingClientRect().top - before;
      if (Math.abs(shift) >= 1) window.scrollBy(0, shift);
    } catch {
      form.submit(); // 普通の送信（ページ移動）でやり直す
    }
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
    row.querySelector('select').value = '2';           // 楽器はギターに戻す（2 = Gt。コピー元の楽器を引き継がない）
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
