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
 *    data-year-slot     … ライブ一覧・メンバーの出演履歴の年度スロット（縦ドラッグで年度を切り替える）
 *    data-grade-filter  … メンバー一覧の学年の絞り込み（タブ + 学年別の学年スロット）
 *    data-sort-toggle   … メンバーの出演履歴を新しい順 ⇔ 古い順に並び替える
 *    data-tab           … ライブ詳細の日程タブ
 *    data-confirm       … 送信前の確認ダイアログ（data-dirty-check で未保存の変更も警告）
 *    data-dropzone      … ファイルのドラッグ&ドロップ
 *    data-name-cell     … 名前の入力欄（DB にいるかで色が変わる）
 *    data-roster-input  … タイムテーブルの枠 → 名簿のバンド（検索欄）
 *    data-sortable      … タイムテーブルの行を ≡ のドラッグで並び替え（時間の列は動かない）
 *    data-album-box     … マイアルバムの開け閉め（閉じているときは先頭5枚だけ）
 *    data-partner-box   … よく組むメンバーの開け閉め（閉じているときは上位5人だけ）
 *    .kings             … 楽器別出演数ランキングの上位5位をふわっと出す
 *    data-album-sort    … マイアルバムをドラッグで並び替えて保存
 *    data-album-search  … アルバム検索をページ移動なしで（結果の部分だけ差し替える）
 *    data-album-add     … アルバムの追加をページ移動なしで（追加したカードを一覧に足す）
 *    data-add-roster-col … 名簿の表の右端に「Other」列を足す
 *    data-pick          … 名簿の「Vo / Vo/Gt / ⋯」「Key / Vn / ⋯」の切り替えボタンと、etc の楽器追加モーダル
 *    .table-scroll      … 横にはみ出す表をマウスのドラッグで左右に動かす
 *    data-scroll-end    … 横にはみ出す表を、右端まで動かした状態で開く（メンバー一覧のランキングの並び）
 *    data-pack          … 送信時に全項目を JSON 1個にまとめるフォーム
 *    data-rows          … バンド編集・タイムテーブル編集のメンバー行（追加・削除）
 *    data-autosubmit    … 選んだら即送信
 *    data-loading       … 送信したら「読み込み中」を出す（取り込みの AI 読み取りは数十秒かかる）
 *    <select>           … 全部のプルダウンをボタン + ポップアップの見た目にする（data-native で元のまま）
 *    data-song-list / data-add-song … 曲の編集
 *    data-track-search  … 曲の編集の🔍（Spotify / iTunes の曲を探して紐付ける）
 *    data-preview       … バンドページのセットリストの ▶（30秒試聴）
 *    data-toasts        … お知らせのポップアップ（4秒で消える）
 *    data-album-tip     … メンバー一覧のジャケットに乗せるとアルバム名・アーティスト名を出す
 *    data-setlist-tip   … セットリストの ✓ を押すと「セットリスト登録済（◯曲）」を出す
 *    open.spotify.com のリンク … スマホでは Spotify のアプリで開く
 *    data-vo-sum-toggle … 統計の「Voを合算する」をページ移動なしで切り替える
 * =====================================================================
 */

// HTML の読み込みが終わってから動かす（まだ無い要素は探せないので）
document.addEventListener('DOMContentLoaded', () => {
  setupToasts();
  setupThemeToggle();
  setupFilter();
  setupYearSlot();
  setupGradeSlot();
  setupScrollEnd();
  setupSortToggle();
  setupTabs();
  setupConfirm();
  setupLeaveCheck();
  setupDropzone();
  setupLoading();
  setupNameCheck();
  setupImportPreview();
  setupMergeToggle();
  setupSelectPick();
  setupChorusToggle();
  setupNewDayToggle();
  setupExtraDays();
  setupSameYear();
  setupSlotSort();
  setupAlbumBox();
  setupPartnerBox();
  setupKings();
  setupAlbumSort();
  setupAlbumSearch();
  setupAlbumAdd();
  setupRosterColumns();
  setupTimetableColumns();
  setupPicks();
  setupDragScroll();
  setupPackedForm();
  setupMemberRows();
  setupSuggest();
  setupSmallThings();
  setupVenueSuggest();
  setupSongs();
  setupTrackSearch();
  setupPreview();
  setupLikes();
  setupAlbumTip();
  setupSetlistTip();
  setupSpotifyAppLinks();
  setupVoSum();
  setupYoutubeLeftover();
  setupBackButton();
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
  // fixed なのでスクロールするとずれる → 隠す。capture: true で、ページだけでなく表の横スクロールでも隠す
  document.addEventListener('scroll', hide, { passive: true, capture: true });
}

/* ---------------------------------------------------------------------
 * スマホで Spotify のリンク（https://open.spotify.com/…）を押したら、Spotify のアプリで開く
 *   https のリンクのままだと、target="_blank"（新しいタブ）やブラウザの設定しだいでアプリに飛ばず、ブラウザの Spotify が開くことがある。
 *   → アプリ用の URL（spotify:album:ID のような「spotify:」で始まる形）に置き換えて開く。
 *   1.5秒たってもこのページが見えたまま（アプリが入っていない）なら、元の https のページを開く。
 *   album_go.php / song_go.php（Spotify ⇔ Apple Music をまたぐリンク）は、押してから飛び先が決まる
 *   → ?json=1 で飛び先だけ聞いて、Spotify ならアプリで、それ以外はそのページを開く。
 *   PC（マウスがある端末）は今までどおり（ブラウザの Spotify で困らないので）。
 * ------------------------------------------------------------------- */
function setupSpotifyAppLinks() {
  const isPhone = window.matchMedia('(hover: none) and (pointer: coarse)');

  // open.spotify.com/album/ID → spotify:album:ID（track・artist・search も同じ形）。当てはまらなければ null
  const appUri = (url) => {
    const u = new URL(url, window.location.href);
    if (u.origin !== 'https://open.spotify.com') return null;
    const m = u.pathname.match(/^\/(album|track|artist|playlist|search)\/([^/]+)/);
    return m ? `spotify:${m[1]}:${m[2]}` : null;
  };
  const openInApp = (uri, webUrl) => {
    let left = false; // アプリが開くと、このページは裏に回る（visibilitychange / pagehide が起きる）
    const onLeave = () => { left = true; };
    document.addEventListener('visibilitychange', onLeave, { once: true });
    window.addEventListener('pagehide', onLeave, { once: true });
    window.location.href = uri;
    setTimeout(() => {
      document.removeEventListener('visibilitychange', onLeave);
      window.removeEventListener('pagehide', onLeave);
      if (!left && document.visibilityState === 'visible') window.location.href = webUrl;
    }, 1500);
  };

  document.addEventListener('click', async (e) => {
    if (!isPhone.matches || e.defaultPrevented) return;
    const a = e.target.closest('a[href]');
    if (!a) return;
    const uri = appUri(a.href);
    if (uri) {
      e.preventDefault();
      openInApp(uri, a.href);
      return;
    }
    const go = a.getAttribute('href').match(/^(album_go|song_go)\?/);
    if (!go) return;
    e.preventDefault();
    try {
      const res = await fetch(`${a.getAttribute('href')}&json=1`, { credentials: 'same-origin' });
      const { url } = await res.json();
      const goUri = appUri(url);
      if (goUri) openInApp(goUri, url); else window.location.href = url;
    } catch {
      window.location.href = a.href; // 聞けなかったら、今までどおりリダイレクトで飛ぶ
    }
  });
}

/* ---------------------------------------------------------------------
 * セットリストの ✓（[data-setlist-tip]）を押したら「セットリスト登録済（◯曲）」をポップアップで出す
 *   スマホには「マウスを乗せる」が無いので、title では出ない → 押したら出す。もう一度押すか、他の所を押すと消える。
 *   見た目はジャケットのポップアップ（.album-tip）と同じ。1個だけ作って使い回す。
 * ------------------------------------------------------------------- */
function setupSetlistTip() {
  if (!document.querySelector('[data-setlist-tip]')) return;
  const tip = document.createElement('div');
  tip.className = 'album-tip';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);
  let current = null; // いま出しているバッジ

  const hide = () => { tip.classList.remove('is-visible'); current = null; };
  const show = (badge) => {
    // 中に [data-tip-body]（メンバー一覧の担当楽器「ほか」）があれば、そのバッジを写して出す。
    // サーバーが h() 済みで出した要素を複製するだけなので安全。無ければ文字だけ（textContent なので HTML として解釈されない）
    const body = badge.querySelector('[data-tip-body]');
    if (body) {
      const copy = body.cloneNode(true);
      copy.hidden = false;
      tip.replaceChildren(copy);
    } else {
      tip.textContent = badge.dataset.setlistTip;
    }
    tip.classList.toggle('album-tip--parts', !!body);
    tip.classList.add('is-visible');
    current = badge;
    // バッジの真上に出す。上に入らないときは下。左右は画面からはみ出さないように寄せる
    const r = badge.getBoundingClientRect();
    const t = tip.getBoundingClientRect();
    let top = r.top - t.height - 8;
    if (top < 8) top = r.bottom + 8;
    const left = Math.min(Math.max(8, r.left + r.width / 2 - t.width / 2), window.innerWidth - t.width - 8);
    tip.style.top = `${top}px`;
    tip.style.left = `${left}px`;
  };

  // PC（マウスがある端末）はマウスを乗せたら出して、外したら消す
  const canHover = window.matchMedia('(hover: hover)');
  document.addEventListener('mouseover', (e) => {
    if (!canHover.matches) return;
    const badge = e.target.closest('[data-setlist-tip]');
    if (badge) { if (badge !== current) show(badge); } else if (current) hide();
  });

  // スマホは押したら出す。もう一度押すか、他の所を押すと消える
  document.addEventListener('click', (e) => {
    const badge = e.target.closest('[data-setlist-tip]');
    if (!badge) { hide(); return; }
    if (canHover.matches) { show(badge); return; } // PC で押しても消さない（乗せたまま押すと消えてしまうので）
    if (badge === current) hide(); else show(badge);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
  // fixed なのでスクロールするとずれる → 隠す。capture: true で、ページだけでなく表の横スクロールでも隠す
  document.addEventListener('scroll', hide, { passive: true, capture: true });
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
 *   data-add-song       … <template data-song-template> をコピーして空の曲カードを足す
 *   data-toggle-all     … そのカードの全員のチェックを一括で ON / OFF
 *   data-performer-on   … チェックを外した人を薄く表示
 *   data-omnibus        … オムニバスのチェック。外すとアーティスト欄をバンドのアーティストに戻して編集不可にする。
 *                         付けると空欄（前に書いていた名前があれば戻す）にして編集できるようにする
 * ------------------------------------------------------------------- */
function setupSongs() {
  // 演奏者の楽器の「＋」: 下に楽器2の段を出して、＋ は隠す（3つ目以降は無い）
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-inst]');
    if (!add) return;
    const second = add.closest('.performer').querySelector('[data-inst2]');
    second.hidden = false;
    add.hidden = true;
    (second.querySelector('.live-pick__btn') || second.querySelector('select')).focus();
  });
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  const omnibus = document.querySelector('[data-omnibus]');
  const defaultArtist = list.dataset.defaultArtist;
  omnibus.addEventListener('change', () => {
    list.querySelectorAll('[data-song-artist]').forEach((input) => {
      if (omnibus.checked) {
        input.readOnly = false;
        // 前に書いていた名前を戻す。無ければ空欄（オムニバスはバンドのアーティストを持たないので、バンドの名前は入れない）
        input.value = input.dataset.typed ?? '';
      } else {
        input.dataset.typed = input.value; // チェックを付け直したときのために覚えておく
        input.value = defaultArtist;
        input.readOnly = true;
      }
    });
  });

  // 「何曲目」を画面の上から 1, 2, 3... と振り直す（カードを足した・消したとき）。消えている途中のカードは数えない
  //   並び順の隠し項目（data-song-pos）も同じ順に振り直す。保存はこの順（songs_edit.php）
  const cards = () => [...list.querySelectorAll('[data-song-card]:not(.is-leaving)')];
  const renumber = () => {
    cards().forEach((c, i) => {
      c.querySelector('[data-song-no]').textContent = String(i + 1);
      const pos = c.querySelector('[data-song-pos]');
      if (pos) pos.value = String(i);
    });
  };
  renumber();

  // ---- 曲の並び替え（≡ をドラッグ / ≡ にフォーカスして ↑↓ キー） ----
  //   指の位置が上か下のカードの真ん中を越えたら、そのカードと入れ替える
  let drag = null; // ドラッグ中だけ { card, pointerId, grab（カードの上端からつかんだ所までの距離） }
  // 滑っている途中・指に付いている途中のずれ（transform の translateY）を引いた「本当の位置」の上端
  const layoutTop = (el) => el.getBoundingClientRect().top - new DOMMatrixReadOnly(getComputedStyle(el).transform).m42;
  // 入れ替えのアニメーション（FLIP）: 動かす前の位置を測る → DOM を入れ替える → 前の位置から今の位置へ滑らせる
  //   skip = ドラッグ中のカード（指に付いているので滑らせない）
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const move = (change, skip = null) => {
    const before = new Map(cards().map((c) => [c, c.getBoundingClientRect().top]));
    change();
    renumber();
    if (reduceMotion) return;
    cards().forEach((c) => {
      if (c === skip) return;
      c.getAnimations().forEach((a) => a.cancel()); // 前のアニメーションの途中なら止めてから測る
      const dy = before.get(c) - c.getBoundingClientRect().top;
      if (Math.abs(dy) < 1) return;
      c.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 200, easing: 'ease-out' });
    });
  };
  list.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('[data-song-handle]');
    if (!handle || drag || (e.pointerType === 'mouse' && e.button !== 0)) return;
    e.preventDefault(); // 文字の選択が始まらないように
    const card = handle.closest('[data-song-card]');
    drag = { card, pointerId: e.pointerId, grab: e.clientY - card.getBoundingClientRect().top };
    drag.card.classList.add('is-dragging');
    handle.setPointerCapture(e.pointerId); // 指やマウスが ≡ の外に出ても追いかける
  });
  list.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    const all = cards();
    const i = all.indexOf(drag.card);
    const prev = all[i - 1];
    const next = all[i + 1];
    // 真ん中の位置は、滑っている途中のずれを引いた「本当の位置」で測る（途中の位置で測ると行ったり来たりする）
    const mid = (el) => layoutTop(el) + el.offsetHeight / 2;
    if (prev && e.clientY < mid(prev)) {
      move(() => list.insertBefore(drag.card, prev), drag.card);
    } else if (next && e.clientY > mid(next)) {
      move(() => list.insertBefore(drag.card, next.nextElementSibling), drag.card);
    }
    // つかんでいるカードを指に付いてこさせる（本当の位置からのずれを transform で付ける）
    drag.card.style.transform = `translateY(${e.clientY - drag.grab - layoutTop(drag.card)}px)`;
  });
  const endDrag = (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    const { card } = drag;
    drag = null;
    // 離したら、指の位置から並びの位置へスッと戻す
    const dy = new DOMMatrixReadOnly(getComputedStyle(card).transform).m42;
    card.style.transform = '';
    const done = () => card.classList.remove('is-dragging');
    if (reduceMotion || Math.abs(dy) < 1) {
      done();
      return;
    }
    card.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 150, easing: 'ease-out' }).onfinish = done;
  };
  list.addEventListener('pointerup', endDrag);
  list.addEventListener('pointercancel', endDrag);
  list.addEventListener('keydown', (e) => {
    const handle = e.target.closest('[data-song-handle]');
    if (!handle || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault(); // ページがスクロールしないように
    const card = handle.closest('[data-song-card]');
    const all = cards();
    const i = all.indexOf(card);
    // キーボードのときは動かしたカードも滑らせる（skip なし）
    if (e.key === 'ArrowUp' && all[i - 1]) move(() => list.insertBefore(card, all[i - 1]));
    if (e.key === 'ArrowDown' && all[i + 1]) move(() => list.insertBefore(card, all[i + 1].nextElementSibling));
    handle.focus(); // 動かしたあともフォーカスを ≡ に残す（続けて押せるように）
  });

  // 空のカードの元（songs_edit.php の <template>）。template の中身は画面に出ず、送信もされない
  const template = list.querySelector('[data-song-template]');
  // name="songs[3][title]" の 3 の部分。保存済みの曲（0, 1, 2...）より大きく、押すたびに 1 ずつ増やして被らないようにする
  //   （Date.now() をそのまま使うと、同じミリ秒に2回押したとき同じ番号になり、2曲が1曲に混ざる）
  let nextIndex = Date.now();
  document.querySelector('[data-add-song]').addEventListener('click', () => {
    const card = template.content.firstElementChild.cloneNode(true);
    const newIndex = nextIndex++;
    card.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace(/^songs\[[^\]]+\]/, `songs[${newIndex}]`);
    });
    // テンプレートはページを開いたときのオムニバスの状態で作られているので、今のチェックに合わせる
    const artist = card.querySelector('[data-song-artist]');
    artist.value = omnibus.checked ? '' : defaultArtist;
    artist.readOnly = !omnibus.checked;
    // ふわっと出す（CSS の .song-card.is-entering）。終わったらクラスを外しておく
    card.classList.add('is-entering');
    card.addEventListener('animationend', () => card.classList.remove('is-entering'), { once: true });
    list.appendChild(card);
    renumber();
    card.querySelector('[name$="[title]"]').focus();
  });

  // カードが後から増えるので、list でまとめてイベントを受ける（イベント委譲）
  list.addEventListener('click', (e) => {
    const del = e.target.closest('[data-song-delete-btn]');
    if (del) {
      const card = del.closest('[data-song-card]');
      // 追加したばかりの曲: まだ保存していないので、カードごと消すだけ
      if (card.classList.contains('song-card--new')) {
        if (card.classList.contains('is-leaving')) return; // 消えている途中にもう一度押された
        card.classList.add('is-leaving');
        // 消えるアニメーションの途中で保存されても送られないように、先に入力欄を無効にしておく（disabled の欄は送信されない）
        card.querySelectorAll('[name]').forEach((el) => { el.disabled = true; });
        renumber();
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
          card.remove();
          return;
        }
        // 薄くなりながら高さを 0 まで縮める。高さはカードごとに違うので、今の高さを測ってから animate() で動かす。
        //   margin-bottom: -12px … .song-list の gap（カードの間の 12px）のぶんも詰める
        card.style.overflow = 'hidden';
        card.animate([
          { opacity: 1, transform: 'none', height: `${card.offsetHeight}px` },
          { opacity: 0, transform: 'scale(.98)', height: '0px', paddingTop: '0px', paddingBottom: '0px', borderWidth: '0px', marginBottom: '-12px' },
        ], { duration: 220, easing: 'ease-in' }).onfinish = () => card.remove();
        return;
      }
      // 保存済みの曲: 削除の印を付ける / 外す（消えるのは保存したとき）
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
 *                        オムニバスなら、アーティスト欄も選んだ曲のアーティストにする
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

  // 曲名の欄で Enter → その曲の「曲を探す」を押す（フォームは送信しない）
  //   isComposing: 日本語入力の変換を確定する Enter では探さない
  list.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing || e.keyCode === 229) return;
    if (!e.target.matches('[name$="[title]"]')) return;
    e.preventDefault();
    const card = e.target.closest('[data-song-card]');
    // 候補が開いていたら閉じ始めておく。ボタンは「開いているときに押すと閉じる」ので、これで探し直しになる
    closeResults(card.querySelector('[data-track-results]'));
    card.querySelector('[data-track-search]').click();
  });

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
      const res = await fetch(`api_track_search?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);
      if (data.tracks.length === 0) {
        message(results, '見つかりませんでした。曲名やアーティスト名を変えて探してください');
        return;
      }
      results.replaceChildren(...data.tracks.map((track) => {
        const btn = optionButton(track);
        btn.addEventListener('click', () => {
          // 選んだ曲の正式な曲名で置き換える（打ち間違い・表記ゆれをそろえる）
          card.querySelector('[name$="[title]"]').value = track.title;
          // オムニバスのとき（アーティスト欄が編集できるとき）だけ、選んだ曲のアーティストを入れる。
          //   Spotify は「A, B」と複数人をつないで返すので、先頭の1人だけ（"A, B" という新しいアーティストを作らないため）
          const artist = card.querySelector('[data-song-artist]');
          if (!artist.readOnly) artist.value = track.artist_name.split(', ')[0];
          setTrack(card, track);
        });
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
 * 30秒試聴（バンドページのセットリストの ▶）
 *   data-preview="spotify:xxxx" … 押すと試聴を鳴らす。もう一度押すと止める。鳴るのは1曲ずつ
 *   data-preview-url             … 覚えてある音源の URL。無ければ api_track_preview.php に聞きに行く
 *   ・<audio> はページに1個だけ作って使い回す。押すまで何も読み込まない（通信量は1曲 約1MB）
 *   ・iPhone の Safari は「押したその瞬間」に play() しないと鳴らさない。URL を聞きに行っている間に
 *     その瞬間が過ぎてしまうので、押した瞬間に無音を鳴らして <audio> を使える状態にしておく
 *   ・覚えてある URL で鳴らなかったら（Apple 側で URL が変わったなど）、1回だけ探し直す
 *   ・どこまで聴いたかを --p（0〜1）に入れて、ボタンの丸い枠に出す（app.css の .setlist__play）
 *   ・音量は VOLUME（0〜1）。試聴の音源は音が大きいので下げておく
 *     iPhone の Safari は audio.volume を無視するので、本体の音量ボタンでしか変わらない
 * ------------------------------------------------------------------- */
function setupPreview() {
  const buttons = document.querySelectorAll('[data-preview]');
  if (!buttons.length) return;
  // 0.01秒の無音（8kHz・8bit の WAV）。iPhone の Safari 用
  const SILENCE = 'data:audio/wav;base64,UklGRnQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YVAAAACAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgA==';
  const VOLUME = 0.4;
  const audio = new Audio();
  audio.volume = VOLUME; // 1回設定すれば src を替えても残る
  audio.preload = 'none';
  let current = null;   // 今鳴らしている（読み込み中の）ボタン
  let retried = false;  // 探し直しは1曲につき1回だけ
  const isSilence = () => audio.src.startsWith('data:');

  const setState = (btn, state) => { // 'loading' | 'play' | 'stop'
    btn.classList.toggle('is-loading', state === 'loading');
    btn.classList.toggle('is-playing', state === 'play');
    btn.querySelector('.icon').textContent = state === 'stop' ? 'play_arrow' : 'pause';
    if (state === 'stop') btn.style.removeProperty('--p');
  };
  const stop = () => {
    audio.pause();
    if (current) setState(current, 'stop');
    current = null;
  };
  const noPreview = (btn) => {
    btn.disabled = true;
    btn.classList.add('is-none');
    btn.title = '試聴がありません';
    btn.setAttribute('aria-label', '試聴がありません');
    btn.querySelector('.icon').textContent = 'music_off';
  };
  const fail = (text) => {
    const toast = document.createElement('div');
    toast.className = 'flash flash--warn';
    toast.textContent = text;
    showToast(toast);
  };

  const start = async (btn, refresh = false) => {
    current = btn;
    setState(btn, 'loading');
    let url = refresh ? null : btn.dataset.previewUrl;
    if (!url) {
      try {
        const res = await fetch(`api_track_preview?track=${encodeURIComponent(btn.dataset.preview)}${refresh ? '&refresh=1' : ''}`,
          { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error);
        url = data.url;
      } catch (err) {
        if (current === btn) stop();
        fail(err.message || '試聴を探せませんでした');
        return;
      }
    }
    if (current !== btn) return; // 待っている間に止められた・別の曲が押された
    if (!url) {
      stop();
      noPreview(btn);
      return;
    }
    btn.dataset.previewUrl = url;
    audio.src = url;
    audio.play().catch(() => {}); // 鳴らなかったときは下の 'error' で扱う
  };

  buttons.forEach((btn) => btn.addEventListener('click', () => {
    if (current === btn) { stop(); return; }
    stop();
    retried = false;
    if (!btn.dataset.previewUrl) { // URL を聞きに行く間に「押した瞬間」が過ぎるので、先に無音を鳴らしておく
      audio.src = SILENCE;
      audio.play().catch(() => {});
    }
    start(btn);
  }));

  audio.addEventListener('playing', () => { if (current && !isSilence()) setState(current, 'play'); });
  audio.addEventListener('timeupdate', () => {
    if (current && !isSilence() && audio.duration) current.style.setProperty('--p', audio.currentTime / audio.duration);
  });
  audio.addEventListener('ended', () => { if (!isSilence()) stop(); });
  audio.addEventListener('error', () => {
    if (!current || isSilence()) return;
    if (!retried) { retried = true; start(current, true); return; }
    const btn = current;
    stop();
    noPreview(btn);
  });
}

/* ---------------------------------------------------------------------
 * 左下の「← ページ名」ボタン（2つまで。上下に並べる）
 *   上: 前に見ていたページ（ブラウザの戻ると同じ。ホーム画面に追加したアプリには戻るボタンが無いので）
 *   下: パンくず（nav.crumbs）の最後のリンク = 1つ上の階層のページ
 *   2つが同じページなら、下の1つだけ出す
 *
 *   ブラウザは前のページの URL（document.referrer）は教えてくれるが、タイトルは教えてくれない。
 *   そこで開いたページの「URL → ページ名」を sessionStorage（このタブの間だけ残る）に覚えておき、referrer から引く。
 *   フォームを送信して来たとき（編集 → 保存）は、編集画面を飛ばしてその前のページを出す
 *   （「← バンドを編集」に戻っても、もう用は無いので）。そのときは history.back() ではなくリンクで移動する。
 *   ページ名は textContent で入れる（バンド名などユーザーが入れた文字が入るので、innerHTML は使わない）
 * ------------------------------------------------------------------- */
function setupBackButton() {
  const KEY = 'back-pages';      // { URL: { title, prev: { url, title } | null } }
  const SUBMIT = 'back-submitted'; // フォームを送信したページの URL
  const MAX = 50;
  // 比べる用の URL（# 以降は無視。同じサイトでなければ null）
  const keyOf = (u) => {
    try {
      const x = new URL(u, location.href);
      return x.origin === location.origin ? x.origin + x.pathname + x.search : null;
    } catch { return null; }
  };
  // sessionStorage はプライベートモードなどで使えないことがある。使えなければボタンを出さないだけ
  const load = (k, fallback) => { try { return JSON.parse(sessionStorage.getItem(k)) ?? fallback; } catch { return fallback; } };
  const save = (k, v) => { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch { /* 何もしない */ } };
  const drop = (k) => { try { sessionStorage.removeItem(k); } catch { /* 何もしない */ } };

  // 次のページのために「フォームを送信した / リンクで移動した」を覚える
  document.addEventListener('submit', (e) => {
    if ((e.target.getAttribute('method') || '').toLowerCase() === 'post') save(SUBMIT, keyOf(location.href));
  }, true);
  document.addEventListener('click', (e) => { if (e.target.closest?.('a[href]')) drop(SUBMIT); }, true);

  const title = document.querySelector('meta[name="page-title"]')?.content;
  const here = keyOf(location.href);
  if (!title || !here) return;
  const pages = load(KEY, {});
  const ref = keyOf(document.referrer);
  const submitted = load(SUBMIT, null);
  drop(SUBMIT);

  // 前のページ（戻り先）。back = true なら history.back() で戻れる
  //   戻る・進む・再読み込みで来たときは、referrer が最初に来たときのまま（編集画面のことがある）なので、
  //   最初に来たときに覚えた戻り先を使う
  const nav = performance.getEntriesByType?.('navigation')[0]?.type;
  const remembered = pages[here]?.prev ?? null;
  let prev = null;
  let back = false;
  if (pages[here] && (nav === 'back_forward' || nav === 'reload' || !ref)) {
    prev = remembered;
  } else if (ref && pages[ref]) {
    if (ref === submitted) {
      // 送信元（編集画面など）を飛ばして、その前のページへ。それが今のページなら（バンド → 編集 → 保存 → バンド）前に覚えた戻り先
      prev = pages[ref].prev && keyOf(pages[ref].prev.url) !== here ? pages[ref].prev : remembered;
    } else if (ref !== here) {
      prev = { url: ref, title: pages[ref].title };
      back = true;
    }
  }
  if (prev && keyOf(prev.url) === here) prev = null;

  // 今のページを覚える（古いものから捨てる。オブジェクトのキーは入れた順に並ぶ）
  delete pages[here];
  pages[here] = { title, prev };
  const keys = Object.keys(pages);
  keys.slice(0, Math.max(0, keys.length - MAX)).forEach((k) => delete pages[k]);
  save(KEY, pages);

  // パンくずの1つ上
  const crumb = [...document.querySelectorAll('nav.crumbs a[href]')].pop();
  const up = crumb ? { url: crumb.href, title: crumb.textContent.trim() } : null;
  if (prev && up && keyOf(prev.url) === keyOf(up.url)) prev = null; // 同じページなら下の1つだけ

  const make = (target, label, useBack) => {
    const a = document.createElement('a');
    a.className = 'back-fab';
    a.href = target.url;
    a.setAttribute('aria-label', `${label}: ${target.title}`);
    a.title = `${label}: ${target.title}`;
    const icon = document.createElement('span');
    icon.className = 'icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = 'arrow_back';
    const text = document.createElement('span');
    text.className = 'back-fab__text';
    text.textContent = target.title;
    a.append(icon, text);
    if (useBack) {
      a.addEventListener('click', (e) => {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return; // 新しいタブで開くときはリンクのまま
        e.preventDefault();
        history.back();
      });
    }
    return a;
  };
  // 前のページで出していたボタンと比べて、変わったボタンだけその場でフェードする（ボタンは動かさない）
  //   ページを移るたびに作り直すので、何もしないとボタンがパッと入れ替わる。
  //   前のページのボタンを、同じ位置にもう1組（.back-fabs--ghost。押せない）並べて消し、新しいボタンは今の位置で出す。
  //   前と同じボタンが同じ位置にあるときは、どちらも動かさない（そのまま見えている）
  const SHOWN = 'back-shown';
  const before = load(SHOWN, []);
  let items = [prev && { ...prev, label: '前のページに戻る', useBack: back }, up && { ...up, label: '1つ上のページに戻る', useBack: false }]
    .filter(Boolean);
  // スマホ: 2つ出るとき、片方が下のメニューの項目（ライブ・メンバー・アーティスト・集計）と同じページなら、そちらは出さない（1つだけ）
  //   メニューから1回で行けるページなので、ボタンにしなくても困らない。両方ともメニューと同じなら、前のページの方を残す
  if (items.length === 2 && matchMedia('(max-width: 760px)').matches) {
    const menu = [...document.querySelectorAll('.nav a[href]')].map((a) => keyOf(a.href));
    const dup = items.filter((t) => menu.includes(keyOf(t.url)));
    if (dup.length) items = items.filter((t) => t !== (dup.length === 2 ? items[1] : dup[0]));
  }
  save(SHOWN, items.map((t) => ({ url: t.url, title: t.title })));
  if (!items.length && !before.length) return;
  const same = (a, b) => keyOf(a.url) === keyOf(b.url) && a.title === b.title;
  const lift = !!document.querySelector('.sticky-actions'); // 下に固定の保存ボタンがあるページでは、スマホで重ならないように上にずらす（app.css）

  const box = document.createElement('nav');
  box.className = 'back-fabs' + (lift ? ' back-fabs--lift' : '');
  box.setAttribute('aria-label', '戻る');
  const shown = items.map((t) => {
    const a = make(t, t.label, t.useBack);
    a.classList.add('is-entering');
    box.append(a);
    return a;
  });
  document.body.append(box);

  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (before.length && !reduce) {
    const ghostBox = document.createElement('div');
    ghostBox.className = 'back-fabs back-fabs--ghost' + (lift ? ' back-fabs--lift' : '');
    ghostBox.setAttribute('aria-hidden', 'true');
    const ghosts = before.map((t) => {
      const g = make(t, '', false);
      g.removeAttribute('href');
      g.tabIndex = -1;
      ghostBox.append(g);
      return g;
    });
    document.body.append(ghostBox);
    // 同じボタンが同じ位置にある → 新しい方はフェードしない、古い方は最初から隠す（位置は測って比べる）
    const near = (a, b) => Math.abs(a.top - b.top) < 1 && Math.abs(a.left - b.left) < 1;
    shown.forEach((a, i) => {
      const r = a.getBoundingClientRect();
      ghosts.forEach((g, j) => {
        if (!g.hidden && same(items[i], before[j]) && near(r, g.getBoundingClientRect())) {
          a.classList.remove('is-entering');
          g.hidden = true;
        }
      });
    });
    ghosts.forEach((g) => { if (!g.hidden) g.classList.add('is-leaving'); });
    // 消え終わったら（動きを減らす設定などでアニメーションが無いときも残らないように、時間でも）片付ける
    const clean = () => ghostBox.remove();
    ghostBox.addEventListener('animationend', (e) => { if (e.target.classList.contains('is-leaving')) clean(); });
    setTimeout(clean, 600);
  }
  shown.forEach((a) => a.addEventListener('animationend', () => a.classList.remove('is-entering'), { once: true }));
  if (reduce) shown.forEach((a) => a.classList.remove('is-entering'));
  if (!items.length) return;

  // スクロール中は隠し、止まったらすぐ出す（読んでいる所にかぶらないように）。隠すのはスマホと、左の余白が無い幅の PC だけ（app.css）
  //   scrollend（スクロールが止まった）が使えるブラウザはそれで出す。使えない Safari などは、0.12 秒スクロールが来なければ止まったとみなす
  let timer = 0;
  const show = () => { clearTimeout(timer); box.classList.remove('is-scrolling'); };
  window.addEventListener('scroll', () => {
    box.classList.add('is-scrolling');
    clearTimeout(timer);
    timer = setTimeout(show, 120);
  }, { passive: true });
  if ('onscrollend' in window) window.addEventListener('scrollend', show);
}

/* ---------------------------------------------------------------------
 * こまごました動き
 *   data-autosubmit … セレクトボックスやラジオボタンを変えたらすぐフォームを送信（集計の絞り込み）
 * ------------------------------------------------------------------- */
function setupSmallThings() {
  document.querySelectorAll('[data-autosubmit]').forEach((sel) => sel.addEventListener('change', () => sel.form.submit()));

  // 会場・日程名のプルダウン（取り込み・ライブ編集）: 「＋ 新しい○○を作る」を選んだときだけ名前の入力欄を出す
  //   日程名は data-new-select="__new__"（「new」という日程名とぶつからないように）、会場は 'new'
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-venue-select], [data-new-select]')) return;
    const box = e.target.closest('.field').querySelector('[data-venue-new], [data-new-input]');
    box.hidden = e.target.value !== (e.target.dataset.newSelect || 'new');
    if (!box.hidden) box.focus();
  });

  // data-yt-pick … バンド編集の「ライブのプレイリストから選ぶ」。選んだ動画の URL を、同じフォームの URL 欄に入れる（保存はしない）
  //   「— 動画を選ぶ —」（空）に戻しても、URL 欄は消さない
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-yt-pick]') || e.target.value === '') return;
    const input = e.target.form?.querySelector('[name="youtube_url"]');
    if (input) input.value = e.target.value;
  });

  // 右上のメニュー（<details class="usermenu">）: 開くたびに、右上から少し下りながらふわっと出す
  //   toggle イベントは開いて1回描画した後に来る（そこから動かすと、一瞬全部見えてから消えて出直す）ので、
  //   開く直前の summary のクリックで動かし始める（Enter / スペースで開いたときも click が来る）。動きを減らす設定の人には動かさない
  document.querySelectorAll('.usermenu').forEach((menu) => menu.querySelector('summary')?.addEventListener('click', () => {
    if (menu.open || matchMedia('(prefers-reduced-motion: reduce)').matches) return; // menu.open = これから閉じる
    menu.querySelector('.usermenu__panel')?.animate(
      [{ opacity: 0, transform: 'translateY(-6px) scale(.96)' }, { opacity: 1, transform: 'none' }],
      { duration: 180, easing: 'cubic-bezier(.2, .9, .3, 1.1)' });
  }));

  // data-fill-hint … メンバープロフィールの一括編集で、空欄の入力欄に data-hint（初出演の年度）を入れる。保存はしない
  document.querySelectorAll('[data-fill-hint]').forEach((btn) => btn.addEventListener('click', () => {
    btn.form.querySelectorAll('input[data-hint]').forEach((input) => {
      if (input.value === '') {
        input.value = input.dataset.hint;
      }
    });
  }));
}

/* ---------------------------------------------------------------------
 * 統計の「Voを合算する」（stats.php）
 *   JS が無くてもリンク（?vo=sum）で切り替わるが、JS があればページを移動せずに切り替える。
 *   両方の数え方の表はもうページに入っている（data-vo-view="combo" / "sum"）ので、hidden を付け替えるだけ。
 *   アドレスバーの URL と絞り込みフォームの vo もそろえる（再読み込み・絞り込みをしても切り替えた状態のまま）。
 * ------------------------------------------------------------------- */
/* ---------------------------------------------------------------------
 * live_youtube.php: 「どのバンドにも入っていない動画」の一覧を、プルダウンで選ぶたびに減らす（外せば戻る）
 *   一覧には全部の動画が <li data-yt-video="動画ID"> で入っていて、どれかのバンドで選ばれているものを hidden にする
 * ------------------------------------------------------------------- */
function setupYoutubeLeftover() {
  const form = document.querySelector('[data-yt-form]');
  const box = form?.querySelector('[data-yt-leftover]');
  if (!box) return;
  const update = () => {
    const picked = new Set([...form.querySelectorAll('select')].map((s) => s.value));
    let left = 0;
    box.querySelectorAll('[data-yt-video]').forEach((li) => {
      li.hidden = picked.has(li.dataset.ytVideo);
      if (!li.hidden) left++;
    });
    box.querySelector('[data-yt-left-count]').textContent = left;
    box.hidden = left === 0;
  };
  form.addEventListener('change', update); // setupSelectPick のポップアップで選んでも change が出る
}

function setupVoSum() {
  const toggles = [...document.querySelectorAll('[data-vo-sum-toggle]')];
  if (!toggles.length) return;
  toggles.forEach((toggle) => toggle.addEventListener('click', (e) => {
    e.preventDefault();
    const on = toggle.getAttribute('aria-checked') !== 'true';
    document.querySelectorAll('[data-vo-view]').forEach((list) => {
      list.hidden = (list.dataset.voView === 'sum') !== on;
      // 出した方のアニメーション（CSS の .is-switched）を最初から。offsetWidth を読むとクラスを外した状態が一度反映される
      list.classList.remove('is-switched');
      if (!list.hidden) {
        void list.offsetWidth;
        list.classList.add('is-switched');
      }
    });

    // 今の URL（#◯◯ は付けない）
    const url = new URL(location.href);
    url.hash = '';
    if (on) url.searchParams.set('vo', 'sum'); else url.searchParams.delete('vo');
    history.replaceState(null, '', url);

    // 2つのボタンの見た目と、JS が無いとき・新しいタブで開いたとき用のリンク先（もう一度押したら戻る URL）
    toggles.forEach((t) => {
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-checked', String(on));
      t.querySelector('.icon').textContent = on ? 'check_box' : 'check_box_outline_blank';
      const next = new URL(url);
      if (on) next.searchParams.delete('vo'); else next.searchParams.set('vo', 'sum');
      next.hash = new URL(t.href).hash;
      t.href = next.href;
    });

    // 絞り込みフォームの <input type="hidden" name="vo">
    const form = document.querySelector('form.stats-filter');
    let input = form?.querySelector('input[name="vo"]');
    if (on && form && !input) {
      input = Object.assign(document.createElement('input'), { type: 'hidden', name: 'vo', value: 'sum' });
      form.append(input);
    } else if (!on && input) {
      input.remove();
    }
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
 * カードなどを上から順にふわっと出す（年度スロットの切り替え・並び替えボタンで共通）
 *   画面の外の分まで動かすと遅れて見えるので、見えている所だけ。動きを減らす設定の人には何もしない
 *   連続で切り替えたときは、前のアニメーションを止めてから出し直す（重なってチラつかないように）
 * ------------------------------------------------------------------- */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
function animateIn(elements) {
  if (reduceMotion) return;
  [...elements]
    .filter((el) => el.offsetParent !== null && el.getBoundingClientRect().top < window.innerHeight)
    .forEach((el, i) => {
      el.getAnimations().forEach((a) => a.cancel());
      el.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
        { duration: 240, delay: Math.min(i, 12) * 25, easing: 'ease-out', fill: 'backwards' });
    });
}

/* ---------------------------------------------------------------------
 * スロット: リールを縦にドラッグして1つ選ぶ部品（年度スロット・学年スロットで共通）
 *   上にドラッグ → 次の段へ / 下にドラッグ → 前の段へ
 *   ホイール・↑↓キー・上下の段のタップでも1つずつ動く
 *   最初は .is-current が付いた段（無ければ先頭）を選ぶ
 *   選ぶたびに onSelect(value, changed) を呼ぶ（changed = 前と違う段になったか。最初の1回は false）
 * ------------------------------------------------------------------- */
function makeSlot(slot, onSelect) {
  const reel = slot.querySelector('.year-slot__reel');
  const items = [...reel.children];
  const max = items.length - 1;
  let index = Math.max(0, items.findIndex((el) => el.classList.contains('is-current')));

  const clamp = (n) => Math.max(0, Math.min(max, n));
  // 1段の高さと、0番目を真ん中の段に置くためのずらし量（窓は3段ぶんの高さ）
  const itemH = () => items[0].offsetHeight;
  const top = () => (slot.clientHeight - itemH()) / 2;
  const move = (px) => { reel.style.transform = `translateY(${top() + px}px)`; };

  const select = (i, first = false) => {
    const before = index;
    index = clamp(i);
    reel.classList.remove('is-dragging');
    move(-index * itemH());
    items.forEach((el, n) => el.classList.toggle('is-current', n === index));
    const value = items[index].dataset.value;
    slot.setAttribute('aria-valuetext', items[index].textContent);
    slot.classList.toggle('is-filtered', value !== '');
    onSelect(value, !first && index !== before);
  };

  // ---- ドラッグ（マウスもタッチも pointer イベントでまとめて扱う） ----
  let startY = null;
  let dy = 0;
  slot.addEventListener('pointerdown', (e) => {
    startY = e.clientY;
    dy = 0;
    slot.setPointerCapture(e.pointerId);
    reel.classList.add('is-dragging');
  });
  slot.addEventListener('pointermove', (e) => {
    if (startY === null) return;
    dy = e.clientY - startY;
    const h = itemH();
    let pos = -index * h + dy;
    // 端より先はゴムのように 1/3 しか動かない
    const min = -max * h;
    if (pos > 0) pos /= 3;
    if (pos < min) pos = min + (pos - min) / 3;
    move(pos);
  });
  const end = (e) => {
    if (startY === null) return;
    startY = null;
    const h = itemH();
    if (Math.abs(dy) < 4) {
      // ほぼ動いていない = タップ。上の段なら1つ前、下の段なら1つ次（真ん中はそのまま）
      const r = slot.getBoundingClientRect();
      const y = e.clientY - (r.top + r.height / 2);
      if (Math.abs(y) > h / 2) select(index + Math.sign(y));
      else select(index);
    } else {
      select(Math.round(index - dy / h));
    }
  };
  slot.addEventListener('pointerup', end);
  slot.addEventListener('pointercancel', end);

  // ---- ホイール（連続で回りすぎないよう 120ms に1回） ----
  let wheelAt = 0;
  slot.addEventListener('wheel', (e) => {
    e.preventDefault();
    const now = Date.now();
    if (now - wheelAt < 120 || e.deltaY === 0) return;
    wheelAt = now;
    select(index + Math.sign(e.deltaY));
  }, { passive: false });

  slot.addEventListener('keydown', (e) => {
    const step = { ArrowUp: -1, ArrowDown: 1 }[e.key];
    if (step) { e.preventDefault(); select(index + step); }
    if (e.key === 'Home') { e.preventDefault(); select(0); }
    if (e.key === 'End') { e.preventDefault(); select(max); }
  });

  select(index, true);
  // 隠れていた（高さ 0 の）スロットを出したときに、位置を測り直すための関数を返す
  return () => select(index, true);
}

/* ---------------------------------------------------------------------
 * 年度スロット（ライブ一覧・メンバーの出演履歴）
 *   上にドラッグ → 古い年度へ / 下にドラッグ → 新しい年度（先頭は「すべて」）
 *   選んだ年度以外の <section data-year> を隠し、残った年度の見出しとカードをふわっと出す（animateIn）
 *   data-year-slot="セレクタ" なら、それに当たる要素を隠す（メンバーの出演履歴: section[data-history-year]）
 * ------------------------------------------------------------------- */
function setupYearSlot() {
  const slot = document.querySelector('[data-year-slot]');
  if (!slot) return;
  const sections = document.querySelectorAll(slot.dataset.yearSlot || 'section[data-year]');
  makeSlot(slot, (value, changed) => {
    sections.forEach((sec) => { sec.hidden = value !== '' && (sec.dataset.year || sec.dataset.historyYear) !== value; });
    // 年度が変わったときだけ動かす（端でさらに回した・真ん中をタップした、では動かさない）
    //   見出し（h2 / h3）→ カード（ライブ一覧は .grid の中、出演履歴は <ol> の <li>）の順
    if (changed) {
      animateIn([...sections].filter((sec) => !sec.hidden)
        .flatMap((sec) => [...sec.querySelectorAll(':scope > :is(h2, h3), .grid > *, ol > li')]));
    }
  });
}

/* ---------------------------------------------------------------------
 * 学年の絞り込み（メンバー一覧）: タブ（全学年 / 上下3学年 / 学年別）+ 学年別のときだけ出る学年スロット
 *   今の絞り込みの値: '' = 全学年 / 'near' = 上下3学年（タブの data-min〜data-max の入学年度）/ 入学年度 / 'none' = 入学年度が不明
 *   行（tr[data-entry]）は .is-grade-hidden で隠す（hidden は名前検索が使うので、ぶつからないよう別にする）
 *   切り替えたら:
 *     ・# の順位を、見えている人の中で付け直す（data-score の多い順。同じなら同じ順位。出演なしの人は —）
 *     ・上の「出演者」「登録」の人数を数え直す
 *     ・URL（?who=&entry=）と列見出しの並べ替えリンクに今の学年を入れる（再読み込み・並べ替えで戻らないように）
 * ------------------------------------------------------------------- */
function setupGradeSlot() {
  const box = document.querySelector('[data-grade-filter]');
  if (!box) return;
  const slot = box.querySelector('[data-grade-slot]');
  const radios = [...box.querySelectorAll('input[name="who"]')];
  const rows = [...document.querySelectorAll('tr[data-entry]')];
  const table = document.querySelector('[data-grade-table]');
  const empty = document.querySelector('[data-grade-empty]');
  const count = (name) => document.querySelector(`[data-grade-count="${name}"]`);
  let slotValue = '';

  const apply = (changed) => {
    const radio = radios.find((r) => r.checked) || radios[0];
    const who = radio.value;
    const match = (entry) => {
      if (who === 'all') return true;
      if (who === 'near') return entry !== '' && +entry >= +radio.dataset.min && +entry <= +radio.dataset.max;
      return slotValue === 'none' ? entry === '' : entry === slotValue;
    };
    rows.forEach((tr) => tr.classList.toggle('is-grade-hidden', !match(tr.dataset.entry)));
    const shown = rows.filter((tr) => !tr.classList.contains('is-grade-hidden'));

    // 順位 = 自分より data-score が多い人の数 + 1（出演なしの人は —）
    const played = shown.filter((tr) => +tr.dataset.bands > 0).map((tr) => +tr.dataset.score);
    shown.forEach((tr) => {
      const n = +tr.dataset.score;
      // # に順位を出すのはランキングの並びのときだけ（それ以外は空のマスで data-rank が無い）。
      //   左端の # と、スマホで右端に出す # の2つがある
      const rank = +tr.dataset.bands > 0 ? played.filter((m) => m > n).length + 1 : '—';
      tr.querySelectorAll('[data-rank]').forEach((cell) => { cell.textContent = rank; });
    });
    if (count('played')) count('played').textContent = played.length;
    if (count('all')) count('all').textContent = shown.length;
    if (table) table.hidden = shown.length === 0;
    if (empty) empty.hidden = shown.length > 0;

    // URL と並べ替えリンクに今の学年を入れる
    const setParams = (params) => {
      params.delete('who');
      params.delete('entry');
      if (who !== 'all') params.set('who', who);
      if (who === 'grade') params.set('entry', slotValue);
    };
    const url = new URL(location.href);
    setParams(url.searchParams);
    history.replaceState(history.state, '', url);
    document.querySelectorAll('th.sortable a').forEach((a) => {
      const u = new URL(a.href);
      setParams(u.searchParams);
      a.href = u;
    });

    if (changed) animateIn(shown);
  };

  // スロットは最初の1回（changed = false）で今の段を覚えるだけ。そのあと回したら絞り込み直す
  const refreshSlot = slot ? makeSlot(slot, (value, changed) => {
    slotValue = value;
    if (changed) apply(true);
  }) : () => {};
  radios.forEach((r) => r.addEventListener('change', () => {
    // 学年別にしたときは、隠れていて高さ 0 だったスロットの位置を測り直す
    if (r.value === 'grade') refreshSlot();
    apply(true);
  }));
  apply(false);
}

/* ---------------------------------------------------------------------
 * 横にはみ出す表を、右端まで動かした状態で開く（data-scroll-end）
 *   はみ出していない（パソコンの幅）ときは、動かす先が無いので何も変わらない
 * ------------------------------------------------------------------- */
function setupScrollEnd() {
  const boxes = document.querySelectorAll('[data-scroll-end]');
  if (!boxes.length) return;
  const toEnd = () => boxes.forEach((box) => { box.scrollLeft = box.scrollWidth; });
  toEnd();
  // 文字（Web フォント）が読み込まれると表の幅が広がって右端がずれるので、読み込み後にもう一度
  document.fonts?.ready.then(toEnd);
  window.addEventListener('load', toEnd, { once: true });
}

/* ---------------------------------------------------------------------
 * 並び替えボタン: 押すたびに新しい順 ⇔ 古い順（メンバーの出演履歴・ライブ一覧）
 *   <button data-sort-toggle="#history-list"> → その中の年度 section と、各 <ol> の <li> の順番を逆にする
 *   data-sort-items=".grid" なら、<ol> の代わりにそれの中身を逆にする（ライブ一覧の年度ごとのカード）
 *   append は「今ある要素を最後に移動する」ので、逆順に append し直せば並びが逆になる（作り直さない）
 *   並べ替えたら、見えているカードを上から順にふわっと出す（どこが動いたか分かるように）
 * ------------------------------------------------------------------- */
function setupSortToggle() {
  document.querySelectorAll('[data-sort-toggle]').forEach((btn) => {
    const list = document.querySelector(btn.dataset.sortToggle);
    if (!list) return;
    const itemsSel = btn.dataset.sortItems || 'ol';
    const reverse = (parent) => [...parent.children].reverse().forEach((el) => parent.append(el));
    btn.addEventListener('click', () => {
      reverse(list);
      list.querySelectorAll(itemsSel).forEach(reverse);
      const asc = btn.getAttribute('aria-pressed') !== 'true';
      btn.setAttribute('aria-pressed', String(asc));
      btn.textContent = asc ? '古い順 ↑' : '新しい順 ↓';
      animateIn(list.querySelectorAll(`:scope > * > :is(h2, h3), ${itemsSel} > *`)); // 年度の見出しとカードを、上から順に
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
    // スマホで日程が4つ以上あるとタブのバーは横にスクロールする → 選んだタブがバーの外にあれば見える所まで動かす
    //   scrollIntoView だとページごと縦に動くことがあるので、バーの scrollLeft だけを変える
    const active = [...tabs].find((t) => t.classList.contains('is-active'));
    const bar = active && active.parentElement;
    if (bar && bar.scrollWidth > bar.clientWidth) {
      const left = active.offsetLeft - bar.offsetLeft;
      if (left < bar.scrollLeft) bar.scrollLeft = left - 8;
      else if (left + active.offsetWidth > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = left + active.offsetWidth - bar.clientWidth + 8;
    }
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
    if (dialog.returnValue === 'leave' && href) location.href = href;
    href = null;
  });
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
      if (e.defaultPrevented) return; // 確認ダイアログでキャンセルされたなど
      const files = [...form.querySelectorAll('input[type=file]')].flatMap((i) => [...i.files]);
      const images = files.filter((f) => /\.(jpe?g|png|webp|gif)$/i.test(f.name)).length;
      if (images > 0) {
        form.querySelector('[data-loading-title]').textContent = `AI 解析中…`;
        form.querySelector('[data-loading-sub]').textContent = '30秒〜1分ほどかかります。このままお待ちください';
      }
      box.hidden = false;
      form.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; });
    });
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

  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let timer = null;
  let requestNo = 0; // 古い通信の結果が後から届いて上書きしないように番号を振る

  const check = async () => {
    const inputs = cells();
    const myNo = ++requestNo;
    try {
      const res = await fetch('api_name_check', {
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
  if (document.querySelector('.suggest-pop')) return; // 入力候補（setupSuggest）が開いているあいだは重ねない
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
 * 会場の「もしかして」（取り込み・ライブ編集の「＋ 新しい会場を作る」）
 *   新しい会場名の欄に入っている名前と、先頭が2文字以上同じ登録済みの会場があれば、
 *   欄の真下に「もしかして ◯◯？」のポップアップを出す（モーダルではないので、ほかの入力はそのまま続けられる）。
 *   ◯◯ を押すとプルダウンでその会場を選び（新しい会場の欄は閉じる）、× で閉じる。
 *   候補は同じ欄のプルダウン（<select data-venue-select>）の選択肢から探すので、サーバーには聞かない。
 *   比べるときは全角/半角・大文字/小文字・空白をそろえる（PHP の band_key とだいたい同じ）
 * ------------------------------------------------------------------- */
function setupVenueSuggest() {
  const boxes = [...document.querySelectorAll('[data-venue-new]')];
  if (!boxes.length) return;
  const norm = (s) => s.normalize('NFKC').toLowerCase().replace(/\s+/g, '');
  const prefixLen = (a, b) => {
    const x = [...a];
    const y = [...b]; // [...文字列] で1文字ずつ（絵文字などの2つで1文字も正しく数える）
    let n = 0;
    while (n < x.length && n < y.length && x[n] === y[n]) n++;
    return n;
  };
  const pops = new Map();   // 入力欄 → 出しているポップアップ
  const closed = new Map(); // 入力欄 → × で閉じたときの名前（同じ名前のあいだは出し直さない）

  // 先頭が2文字以上同じ会場を、同じ文字数が多い順に最大3つ（完全に同じ名前は候補にしない）
  const candidates = (box, select) => {
    const key = norm(box.value);
    if ([...key].length < 2) return [];
    return [...select.options]
      .filter((o) => o.value !== '' && o.value !== 'new')
      .map((o) => ({ option: o, len: prefixLen(key, norm(o.textContent)) }))
      .filter((c) => c.len >= 2 && norm(c.option.textContent) !== key)
      .sort((a, b) => b.len - a.len)
      .slice(0, 3)
      .map((c) => c.option);
  };

  const place = (box, pop) => {
    const r = box.getBoundingClientRect();
    const left = Math.min(r.left, document.documentElement.clientWidth - pop.offsetWidth - 8); // 右端からはみ出さない
    pop.style.top = `${r.bottom + window.scrollY + 4}px`;
    pop.style.left = `${Math.max(8, left) + window.scrollX}px`;
  };

  const update = (box) => {
    pops.get(box)?.remove();
    pops.delete(box);
    const select = box.closest('.field').querySelector('[data-venue-select]');
    if (!select || box.hidden || select.value !== 'new' || closed.get(box) === box.value) return;
    const options = candidates(box, select);
    if (!options.length) return;

    const pop = document.createElement('div');
    pop.className = 'venue-pop';
    pop.setAttribute('role', 'status');
    pop.append('もしかして ');
    options.forEach((o, i) => {
      if (i > 0) pop.append(' / ');
      // 会場名は textContent で入れる（innerHTML だと、名前に < > があったとき XSS になる）
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'hint-pop__pick';
      pick.textContent = o.textContent;
      pick.addEventListener('click', () => {
        select.value = o.value;
        select.dispatchEvent(new Event('change', { bubbles: true })); // 新しい会場の欄を閉じ、プルダウンのボタンの文字も合わせる
        update(box);
      });
      pop.append(pick);
    });
    pop.append('？');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'venue-pop__close';
    close.setAttribute('aria-label', '閉じる');
    close.textContent = '×';
    close.addEventListener('click', () => {
      closed.set(box, box.value);
      update(box);
    });
    pop.append(close);
    document.body.appendChild(pop);
    place(box, pop);
    pops.set(box, pop);
  };

  boxes.forEach(update); // プレビューを開いた直後（ファイルの会場名が新しいと判定されたとき）
  document.addEventListener('input', (e) => { if (boxes.includes(e.target)) update(e.target); });
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-venue-select]')) return;
    const box = e.target.closest('.field').querySelector('[data-venue-new]');
    if (box) update(box);
  });
  // ページや表のスクロール・画面の大きさが変わったら位置を合わせ直す。
  // 「この日程は取り込まない」などで欄が見えなくなった（幅が0）ときは隠す
  const replace = () => pops.forEach((pop, box) => {
    pop.hidden = box.offsetParent === null;
    if (!pop.hidden) place(box, pop);
  });
  window.addEventListener('scroll', replace, true);
  window.addEventListener('resize', replace);
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

    // 名簿側: どの枠にも選ばれていない（= 登録されない）行を薄くして ⚠ を出す
    document.querySelectorAll('tr[data-roster-key]').forEach((tr) => {
      const unused = !usedBy[tr.dataset.rosterKey];
      tr.classList.toggle('is-excluded', unused);
      tr.querySelector('[data-roster-missing]').hidden = !unused;
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
    // 「登録済みのライブと統合」が ON なのにライブを選んでいない日程（取り込まない日程は数えない）
    const unpicked = [...document.querySelectorAll('[data-timetable]')].filter((card) =>
      !card.querySelector('[data-skip]')?.checked
      && card.querySelector('[data-merge-toggle]')?.getAttribute('aria-pressed') === 'true'
      && !card.querySelector('[data-live-id]').value).length;

    const blocks = [];
    if (dupCount > 0) blocks.push('名簿の重複を直す');
    if (unpicked > 0) blocks.push('統合するライブを選ぶ');
    submit.disabled = submitDisabled || blocks.length > 0;
    submitBlock.hidden = blocks.length === 0;
    submitBlock.querySelector('[data-submit-block-text]').textContent = `${blocks.join('・')}と登録できます`;
  };

  const showFiscalYear = (input) => {
    const out = input.closest('.field').querySelector('[data-fiscal-year]');
    const year = fiscalYear(input.value);
    out.textContent = year !== null ? `→ ${year}年度` : '';
  };

  // 開催日の「年」を1つ直したら、ほかの日程も同じ年にそろえる（月・日はそのまま）
  //   年はファイルに無く推測で入れているので、ずれるときは全部の日程が同じ年でずれている。
  //   ※ 1回のライブの日程が年をまたぐことは無い前提（12/31 と 1/1 のような日程は無い）
  const yearOf = (value) => (/^\d{4}-/.test(value) ? parseInt(value, 10) : null);
  let syncing = false; // 下で他の欄に change を出す → またここに来る、の繰り返しを防ぐ
  const syncYears = (input) => {
    const year = yearOf(input.value);
    // 年を1桁ずつ打っている途中（0002 → 0020 → 0202）は、ほかの欄を変な年にしない
    if (syncing || year === null || year < 1990) return;
    syncing = true;
    document.querySelectorAll('[data-date-input]').forEach((other) => {
      if (other === input || yearOf(other.value) === null || yearOf(other.value) === year) return;
      other.value = String(year) + other.value.slice(4);
      other.dispatchEvent(new Event('change', { bubbles: true })); // 年度の表示・統合の候補なども更新させる
      other.classList.remove('is-synced');
      void other.offsetWidth; // アニメーションを最初からやり直す
      other.classList.add('is-synced');
    });
    syncing = false;
  };

  // 「この日程は取り込まない」: そのカードの入力欄を触れなくする（そのチェックボックス自身は除く）
  //   disabled だと値が送信されず、登録失敗で戻ってきたときに「取込」などの状態が消えてしまう。
  //   inert は「クリック・入力・フォーカスができない」だけで値はそのまま送られるので、状態を保ったまま固められる
  const applySkip = (card) => {
    const skip = card.querySelector('[data-skip]');
    const on = skip.checked;
    card.classList.toggle('is-skipped', on);
    [...card.children].forEach((el) => {
      if (el.matches('.import-day__head')) return; // 見出し（ファイル名）は読めるようにそのまま
      if (el.contains(skip)) {
        // チェックボックスの並び: 「取り込まない」以外（上書き）だけ固める
        [...el.children].forEach((c) => { c.inert = on && !c.contains(skip); });
      } else {
        el.inert = on;
      }
    });
  };
  document.querySelectorAll('[data-timetable]').forEach(applySkip); // 失敗して戻ってきたときにチェック済みのことがある

  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-include]')) {
      renumberSlots(e.target.closest('tbody')); // 取込を切り替えたら出演順を振り直す
    }
    if (e.target.matches('[data-skip]')) applySkip(e.target.closest('[data-timetable]'));
    if (e.target.matches('[data-include], [data-roster-input], [data-skip]')) refresh();
    if (e.target.matches('[data-date-input]')) {
      showFiscalYear(e.target);
      syncYears(e.target);
    }
  });
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-roster-input], [data-band-name]')) refresh();
  });
  // 統合トグルの切り替え・ライブの選択（setupMergeToggle が知らせてくる）
  document.addEventListener('merge-change', refresh);

  refresh();
  // 最初に開いたときの注意（PHP が初回だけ <dialog> を置く）を1つずつ順番に出す
  //   1. 年を確認してください（年はファイルに無いので推測で入れている）→ 閉じたら開催日の欄へ
  //   2. タイムテーブルに無い名簿のバンド
  const notices = ['[data-year-check-dialog]', '[data-roster-missing-dialog]']
    .map((sel) => document.querySelector(sel)).filter(Boolean);
  const showNext = () => {
    const dialog = notices.shift();
    if (!dialog) return;
    dialog.addEventListener('close', () => {
      if (dialog.matches('[data-year-check-dialog]')) {
        document.querySelector('[data-date-input]')?.focus({ preventScroll: true });
        document.querySelector('[data-date-input]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      showNext();
    }, { once: true });
    dialog.showModal();
  };
  showNext();
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
    document.body.append(pop);

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
 * ライブ編集: 日程名の重複チェックと「＋ 日程を追加」
 *
 *   ・同じライブの日程どうしで日程名がかぶったら「重複しています」を出し、保存できないようにする
 *     （選べなくはしない。「1日目 ⇄ 2日目」の入れ替えで、途中で一時的に重複するのは許す）
 *     setCustomValidity を付けると、ブラウザが「保存する」を押したときに止めて吹き出しを出してくれる。
 *     PHP 側（live_edit.php）でも同じチェックをしている
 *   ・「＋ 日程を追加」にチェックしたら、追加する日程の入力欄を開く
 *     閉じているあいだは日付の required も外す（見えない欄が「入力してください」で送信を止めないように）
 * ------------------------------------------------------------------- */
/** 日程名のプルダウンが今表している日程名（「＋ 新しい日程名を作る」なら横の入力欄の文字） */
function dayLabelOf(select) {
  if (select.value !== '__new__') return select.value;
  return select.closest('.field').querySelector('[data-label-new]').value.trim();
}

function setupNewDayToggle() {
  const checkDuplicates = (form) => {
    const addBox = form.querySelector('[data-new-day-add]');
    // 追加する日程は、チェックが入っているとき（新規ライブなら常に）だけ数える
    const selects = [...form.querySelectorAll('[data-day-label]')]
      .filter((s) => !s.matches('[data-new-day]') || !addBox || addBox.checked);
    form.querySelectorAll('[data-day-label]').forEach((select) => {
      const label = dayLabelOf(select);
      const dup = label !== '' && selects.includes(select) && selects.some((o) => o !== select && dayLabelOf(o) === label);
      select.setCustomValidity(dup ? '他の日程と重複しています' : '');
      const note = select.closest('.field').querySelector('[data-dup-note]');
      if (note) note.hidden = !dup;
    });
  };

  document.querySelectorAll('[data-day-label]').forEach((s) => s.form && checkDuplicates(s.form));
  // 新しい日程名を打つたびにも重複を見直す
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-label-new]')) checkDuplicates(e.target.form);
  });
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-day-label]')) checkDuplicates(e.target.form);
    if (!e.target.matches('[data-new-day-add]')) return;
    const fields = e.target.closest('form').querySelector('[data-new-day-fields]');
    const on = e.target.checked;
    fields.hidden = !on;
    fields.querySelector('input[type="date"]').required = on;
    checkDuplicates(e.target.form);
    if (on) fields.querySelector('.live-pick__btn, select')?.focus();
  });
}

/* ---------------------------------------------------------------------
 * ライブの新規追加（live_edit.php）: 「＋ 日程を追加」で2つ目以降の日程の入力欄を増やす
 *   <template id="extra-day-tpl"> を複製し、name の __i__ を番号に置き換える。
 *   日程名はまだ使っていないものを最初から選んでおく。全部使っていたら「＋ 新しい日程名を作る」にしておく。
 *   「この日程をやめる」で欄ごと消す（消した欄は送信されない）
 * ------------------------------------------------------------------- */
/* ---------------------------------------------------------------------
 * ライブの編集（live_edit.php）: どれかの日付の「年」を変えたら、ほかの日程の日付も同じ年にする
 *   月日はそのまま。年が変わったときだけ動く（月日だけ変えたときは、ほかの日程に触らない）。
 *   年を打っている途中（0002 → 0020 → 0202 → 2025）は動かさないよう、1990〜2100 年のときだけ。
 *   2/29 → うるう年でない年なら 2/28 にする。空の日付欄は触らない
 * ------------------------------------------------------------------- */
function setupSameYear() {
  const form = document.querySelector('[data-same-year]');
  if (!form) return;
  const prev = new WeakMap(); // 日付欄 => 変える前の値
  const yearOf = (v) => (/^\d{4}-\d{2}-\d{2}$/.test(v) ? Number(v.slice(0, 4)) : null);
  const isLeap = (y) => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
  form.addEventListener('change', (e) => {
    const input = e.target;
    if (!input.matches('input[type="date"]')) return;
    const before = prev.has(input) ? prev.get(input) : input.defaultValue;
    prev.set(input, input.value);
    const year = yearOf(input.value);
    if (year === null || year < 1990 || year > 2100 || year === yearOf(before)) return;
    form.querySelectorAll('input[type="date"]').forEach((other) => {
      if (other === input || yearOf(other.value) === null) return;
      let md = other.value.slice(5); // "MM-DD"
      if (md === '02-29' && !isLeap(year)) md = '02-28';
      other.value = `${year}-${md}`;
      prev.set(other, other.value);
    });
  });
}

function setupExtraDays() {
  const btn = document.querySelector('[data-extra-day-add]');
  const tpl = document.getElementById('extra-day-tpl');
  if (!btn || !tpl) return;
  const form = btn.closest('form');
  const list = form.querySelector('[data-extra-days]');
  let next = list.children.length; // エラーで戻ってきたときは、出し直した分の続きの番号から
  const selects = () => [...form.querySelectorAll('[data-day-label]')];

  const refresh = () => {
    // change を起こして、setupNewDayToggle の重複チェックをやり直させる
    selects()[0].dispatchEvent(new Event('change', { bubbles: true }));
  };

  btn.addEventListener('click', () => {
    const used = selects().map(dayLabelOf);
    const block = tpl.content.firstElementChild.cloneNode(true);
    block.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace('__i__', next); });
    next++;
    const select = block.querySelector('[data-day-label]');
    const free = [...select.options].find((o) => o.value !== '__new__' && !used.includes(o.value));
    select.value = free ? free.value : '__new__';
    block.querySelector('[data-label-new]').hidden = !!free;
    list.append(block);
    refresh();
    block.querySelector(free ? 'input[type="date"]' : '[data-label-new]').focus();
  });

  list.addEventListener('click', (e) => {
    const rm = e.target.closest('[data-extra-day-remove]');
    if (!rm) return;
    rm.closest('[data-extra-day]').remove();
    refresh();
  });
  refresh();
}

/** 開催日 "2025-03-15" → 年度（4月始まり。1〜3月は前の年の年度）。PHP の academic_year() と同じ計算 */
function fiscalYear(dateStr) {
  const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(dateStr || '');
  if (!m) return null;
  return Number(m[2]) >= 4 ? Number(m[1]) : Number(m[1]) - 1;
}

/* ---------------------------------------------------------------------
 * 取り込み / ライブ編集: 「登録済みのライブと統合」「他のライブに統合」トグル
 *
 *   ON  … ライブ名の入力欄（と [data-merge-hide] の欄）を隠して disabled（送信されない）にし、代わりに「ライブを選択 ▾」を出す。
 *          押すと <template id="live-picker">（partials/live_picker.php）を複製したポップアップが開き、
 *          開催日（ライブ編集では今の年度）の年度の見出しまでスクロールした状態で表示される。選ぶと hidden の live_id に入る。
 *   OFF … 元の入力欄に戻す（打ってあった文字はそのまま）。merge / live_id は disabled にして送らない。
 *
 *   ライブ編集（[data-live-merge]）では、統合先にもうある日程名を選んでいる日程に
 *   「統合先にもある日程です」を出し、保存前に確認ダイアログ（data-confirm）を出す。
 *   両方にバンドがいる日程は、1回目の保存でバンドごとの選択欄が出る（live_edit.php）。
 * ------------------------------------------------------------------- */
function setupMergeToggle() {
  const tpl = document.getElementById('live-picker');
  if (!tpl) return;
  let openPop = null; // 今開いているポップアップ（同時に開くのは1つだけ）

  const closePop = () => {
    if (!openPop) return;
    openPop.closest('[data-merge]').querySelector('[data-live-pick]').setAttribute('aria-expanded', 'false');
    openPop.remove();
    openPop = null;
  };

  // 登録済みのライブの一覧は、ポップアップの <template> の項目をそのまま使う（同じデータを二重に埋め込まない）
  const lives = [...tpl.content.querySelectorAll('[data-live-option]')].map((o) => ({
    year: o.dataset.year,
    key: o.dataset.name.trim().toLowerCase(), // DB の照合順序（_general_ci）は大文字・小文字を区別しないので合わせる
    labels: o.dataset.labels ? o.dataset.labels.split('・') : [],
  }));

  // 注意文: 選んだライブにもう同じ日程がある / 年度が開催日とずれている
  // あわせて、カード上部の「この日程は登録済みです」の帯も出し入れする
  // ライブ編集: 統合先にもうある日程名の日程に注意を出す + 保存前の確認文を付け外し
  const updateLiveEdit = (box) => {
    const form = box.closest('form');
    const btn = box.querySelector('[data-live-pick]');
    const note = box.querySelector('[data-merge-note]');
    const on = box.querySelector('[data-merge-toggle]').getAttribute('aria-pressed') === 'true';
    const chosen = on && !!box.querySelector('[data-live-id]').value;
    const labels = chosen && btn.dataset.labels ? btn.dataset.labels.split('・') : [];
    const addNew = form.querySelector('[data-new-day-add]')?.checked;
    let overwrites = 0;
    form.querySelectorAll('[data-day-label]').forEach((select) => {
      const warn = select.closest('.field').querySelector('[data-overwrite-note]');
      const hit = labels.includes(dayLabelOf(select)) && (!select.matches('[data-new-day]') || addNew);
      warn.hidden = !hit;
      if (hit) overwrites++;
    });
    note.textContent = chosen ? 'このライブの日程を全部、選んだライブへ移します（このライブは消えます）' : '';
    if (chosen) {
      form.dataset.confirm = `このライブを「${btn.dataset.year}年度 ${btn.dataset.name}」に統合します。`
        + (overwrites ? `\n統合先にもある日程が ${overwrites} 件あります（両方にバンドがいれば、次の画面でバンドごとに残す・統合・捨てるを選べます）。` : '')
        + '\n元に戻せません。よろしいですか？';
    } else {
      delete form.dataset.confirm;
    }
  };

  const updateNote = (box) => {
    const card = box.closest('[data-timetable]');
    if (!card) {
      updateLiveEdit(box);
      return;
    }
    const note = box.querySelector('[data-merge-note]');
    const btn = box.querySelector('[data-live-pick]');
    const flash = card.querySelector('[data-exists-flash]');
    const on = box.querySelector('[data-merge-toggle]').getAttribute('aria-pressed') === 'true';
    const label = dayLabelOf(card.querySelector('[name$="[label]"]'));
    const year = fiscalYear(card.querySelector('[data-date-input]').value);
    note.textContent = '';
    note.className = 'merge-note';

    if (!on) {
      // 手入力: 「開催日の年度 + ライブ名」が同じライブに、同じ日程ラベルがあるか（PHP 側の判定と同じ）
      const key = box.querySelector('[data-live-name]').value.trim().toLowerCase();
      const hit = lives.find((l) => l.year === String(year) && l.key === key);
      flash.hidden = !(hit && hit.labels.includes(label));
      return;
    }
    if (!box.querySelector('[data-live-id]').value) {
      flash.hidden = true;
      return;
    }
    const msgs = [];
    const exists = !!btn.dataset.labels && btn.dataset.labels.split('・').includes(label);
    flash.hidden = !exists;
    if (exists) {
      msgs.push(`⚠ 「${label}」は登録済みです（「上書き」にチェックすると置き換え）`);
    }
    if (year !== null && String(year) !== btn.dataset.year) {
      msgs.push(`⚠ 開催日は${year}年度ですが、${btn.dataset.year}年度のライブに統合します`);
    }
    if (msgs.length) {
      note.textContent = msgs.join('\n');
      note.classList.add('merge-note--warn');
    } else {
      note.textContent = `「${label}」として追加します`;
    }
  };

  const setMode = (box, on) => {
    box.querySelector('[data-merge-toggle]').setAttribute('aria-pressed', on ? 'true' : 'false');
    const name = box.querySelector('[data-live-name]');
    name.hidden = on;
    name.disabled = on;
    box.querySelector('[data-merge-flag]').disabled = !on;
    box.querySelector('[data-live-id]').disabled = !on;
    box.querySelector('[data-live-pick-wrap]').hidden = !on;
    // ライブ編集の年度の欄など、統合するときは使わない欄
    box.parentElement.querySelectorAll('[data-merge-hide]').forEach((el) => {
      el.hidden = on;
      el.querySelectorAll('input, select').forEach((i) => { i.disabled = on; });
    });
    if (!on) {
      closePop();
      name.focus();
    }
    updateNote(box);
    document.dispatchEvent(new Event('merge-change')); // 「登録する」を押せるかどうかを判定し直してもらう
  };

  const open = (box) => {
    closePop();
    const btn = box.querySelector('[data-live-pick]');
    const pop = tpl.content.firstElementChild.cloneNode(true);
    btn.after(pop);
    btn.setAttribute('aria-expanded', 'true');
    openPop = pop;

    const current = box.querySelector('[data-live-id]').value;
    pop.querySelector(`[data-live-option="${current}"]`)?.setAttribute('aria-selected', 'true');

    // 開催日の年度の見出しまでスクロール。無ければそれより前で一番近い年度（リストは新しい年度が上）
    const dateInput = box.closest('[data-timetable]')?.querySelector('[data-date-input]');
    const year = dateInput ? fiscalYear(dateInput.value) : (Number(box.dataset.year) || null); // ライブ編集は今のライブの年度
    if (year !== null) {
      const head = [...pop.querySelectorAll('[data-year-head]')].find((h) => Number(h.dataset.yearHead) <= year);
      if (head) pop.scrollTop = head.offsetTop; // .live-pop は position: absolute なので、offsetTop はポップアップの上端からの距離
    }
  };

  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-merge-toggle]');
    if (toggle) {
      const box = toggle.closest('[data-merge]');
      const on = toggle.getAttribute('aria-pressed') !== 'true';
      setMode(box, on);
      if (on && !box.querySelector('[data-live-id]').value) open(box); // まだ選んでいなければすぐ開く
      return;
    }
    const pick = e.target.closest('[data-live-pick]');
    if (pick) {
      const box = pick.closest('[data-merge]');
      if (openPop && box.contains(openPop)) closePop(); else open(box);
      return;
    }
    const opt = e.target.closest('[data-live-option]');
    if (opt) {
      const box = opt.closest('[data-merge]');
      const btn = box.querySelector('[data-live-pick]');
      box.querySelector('[data-live-id]').value = opt.dataset.liveOption;
      btn.dataset.year = opt.dataset.year;
      btn.dataset.labels = opt.dataset.labels;
      btn.dataset.name = opt.dataset.name;
      const text = box.querySelector('[data-live-pick-text]');
      text.textContent = `${opt.dataset.year}年度 ${opt.dataset.name}`;
      text.classList.remove('is-placeholder');
      closePop();
      updateNote(box);
      document.dispatchEvent(new Event('merge-change'));
      btn.focus();
      return;
    }
    if (openPop && !openPop.contains(e.target)) closePop(); // 外側をクリックしたら閉じる
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && openPop) {
      const btn = openPop.closest('[data-merge]').querySelector('[data-live-pick]');
      closePop();
      btn.focus();
    }
  });
  // 開催日・日程ラベル・ライブ名を変えたら注意文と帯を作り直す
  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-date-input], [name$="[label]"], [data-label-new], [data-live-name]')) return;
    const box = e.target.closest('[data-timetable]')?.querySelector('[data-merge]');
    if (box) updateNote(box);
  });
  // ライブ編集: 日程名・「この日程を追加する」を変えたら警告を出し直す
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-day-label], [data-new-day-add], [data-label-new]')) return;
    const box = e.target.closest('form')?.querySelector('[data-live-merge] [data-merge]');
    if (box) updateNote(box);
  });

  document.querySelectorAll('[data-merge]').forEach(updateNote);
}

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

/* ---------------------------------------------------------------------
 * タイムテーブルの行の並び替え（左端の ≡ をドラッグ。↑↓キーでも動く）
 *   時間の列（data-time のセル）は「何行目か」に固定: 行を動かしたら、時間のセルだけ元の位置の行に戻す。
 *     取り込み（import.php）… hidden の at（どの枠の時間を使うか。PHP の commit_import_plan が見る）も位置に合わせて付け直す
 *     タイムテーブル編集 … 時間が入力欄なので、name をその行（tr の data-name-prefix = b[ID] / k[番号]）に付け直す
 *   data-free-time の行（タイムテーブル編集の「＋ 休憩を追加」で足した行）は例外で、時間を行に付けたまま動かす。
 *     位置に固定する行だけで「n 番目の時間」を数える（足した休憩を差し込んでも、ほかの行の時間はずれない）
 *   行を足したり消したりしたら、tbody に slots:changed イベントを出してもらい、今の並びで覚え直す
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
      const res = await fetch('api_album_order', {
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
    //   getAttribute('action') は "member#albums"。# 以降は要らないので切り落とす
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
  // 閉じているときに見せる枚数。PC は5枚、スマホ（600px 以下）は1行3枚（CSS の nth-child と合わせる）
  const shown = () => (window.matchMedia('(max-width: 600px)').matches ? 3 : 5);
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 画面の表示を「開いているか」に合わせる
  const render = () => {
    const open = box.classList.contains('is-open');
    const count = list ? list.children.length : 0;
    // 閉じると隠れるものがあるか（6枚目以降 or 自分のページの「アルバムを追加」）。無ければボタンは要らない
    const hasHidden = count > shown() || box.querySelector('.album-search') !== null;
    toggle.setAttribute('aria-expanded', String(open));
    more.hidden = !hasHidden;
    more.textContent = open ? '閉じる' : 'さらに表示';
  };

  const setOpen = (open) => {
    box.classList.toggle('is-open', open);
    render();
    // 開いたとき、隠れていたカード（6枚目以降）をふわっと出す
    if (open && list && !reduceMotion) {
      [...list.children].slice(shown()).forEach((li, i) => {
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
 * よく組むメンバー・よく演奏するアーティストの開け閉め（member.php の data-partner-box）
 *   マイアルバム（setupAlbumBox）と同じ操作感。閉じているときは上位 data-shown 人（無ければ5人）だけ見せる
 *   （CSS の nth-child と数を合わせる。よく組むメンバーは n+6、よく演奏するアーティストは n+4）。
 *   ボタンの文字は data-more-label（無ければ「すべて表示」）。
 *   見出しボタンと、一覧の下の「すべて表示」/「閉じる」のどちらでも切り替えられる。
 *   js-collapsible を付けたときだけ CSS が隠す → JS が動かないときは全員見えたまま。
 * ------------------------------------------------------------------- */
function setupPartnerBox() {
  document.querySelectorAll('[data-partner-box]').forEach(setupOnePartnerBox);
}

function setupOnePartnerBox(box) {
  const toggle = box.querySelector('[data-partner-toggle]');
  const more = box.querySelector('[data-partner-more]');
  const count = box.querySelectorAll('.ranking > li').length;
  const SHOWN = Number(box.dataset.shown) || 5;
  const moreLabel = box.dataset.moreLabel || 'すべて表示';
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 名前の ▸ を開くたびに、一緒に出たバンドの一覧をふわっと出す。
  //   toggle イベントは開いて画面に描いた「あと」に届くので、そこで動かすと完成した表示が一瞬見えてしまう。
  //   → 開く「前」の click で動かし始める（click のあとにブラウザが開く → 最初に描かれるのは透明な状態）。
  //   summary の中の名前（リンク）を押したときはページを移るだけで開かないので、何もしない
  box.addEventListener('click', (e) => {
    const summary = e.target.closest('.partner > summary');
    if (reduceMotion || !summary || e.target.closest('a') || summary.parentElement.open) return;
    summary.parentElement.querySelector('.partner__bands')?.animate(
      [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
      { duration: 220, easing: 'ease-out' });
  });

  // 5人以下なら隠すものが無いので、▸ もボタンも出さずに全員見せる
  if (count <= SHOWN) {
    toggle.querySelector('.album-box__chevron')?.remove();
    toggle.disabled = true;
    return;
  }

  const setOpen = (open) => {
    box.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    more.textContent = open ? '閉じる' : moreLabel;
    // 開いたとき、隠れていた6人目以降を上から順にふわっと出す（マイアルバムと同じ動き）
    if (open && !reduceMotion) {
      [...box.querySelectorAll('.ranking > li')].slice(SHOWN).forEach((li, i) => {
        li.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
          { duration: 220, delay: Math.min(i, 10) * 20, easing: 'ease-out', fill: 'backwards' });
      });
    }
  };

  box.classList.add('js-collapsible');
  more.hidden = false;
  toggle.addEventListener('click', () => setOpen(!box.classList.contains('is-open')));
  more.addEventListener('click', () => {
    const closing = box.classList.contains('is-open');
    setOpen(!closing);
    // 閉じると下の方が無くなって画面が飛ぶので、見出しが見える位置まで戻す
    if (closing && box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: 'smooth' });
  });
  setOpen(false);
}

/* ---------------------------------------------------------------------
 * 楽器別出演数ランキング（stats.php の .kings）
 *   ▸ を開いたとき、上位5位の行を上から順にふわっと出す（よく組むメンバーと同じく、開く「前」の click で動かし始める）
 * ------------------------------------------------------------------- */
function setupKings() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.querySelectorAll('.kings').forEach((list) => {
    list.addEventListener('click', (e) => {
      const summary = e.target.closest('.partner > summary');
      if (!summary || e.target.closest('a') || summary.parentElement.open) return;
      summary.parentElement.querySelectorAll('.kings__top > li').forEach((li, i) => {
        li.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
          { duration: 200, delay: i * 30, easing: 'ease-out', fill: 'backwards' });
      });
    });
  });
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
      const res = await fetch('api_instrument', {
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
 * 横にはみ出す表（.table-scroll / 一覧の表のカード .table-card）をマウスでつかんで左右に動かす
 *   .table-card の中に .table-scroll がある画面では、はみ出すのは中の .table-scroll だけ
 *   （外のカードは scrollWidth <= clientWidth なので何もしない）
 *   入力欄・プルダウン・ボタンの上で押したときは、いつもどおり文字選択や操作をさせる。
 *   5px 以上動かしたら「ドラッグ」とみなし、離したときのクリックは無効にする（誤クリック防止）。
 *   スマホ（タッチ）は元々指でスクロールできるので、マウスのときだけ動かす。
 * ------------------------------------------------------------------- */
function setupDragScroll() {
  const boxes = [...document.querySelectorAll('.table-scroll, .table-card')];
  if (!boxes.length) return;

  // はみ出しているときだけ「つかめる」カーソルにする（列の追加や画面幅で変わるので毎回見直す）
  const markDraggable = () => boxes.forEach((box) => box.classList.toggle('is-draggable', box.scrollWidth > box.clientWidth));
  markDraggable();
  window.addEventListener('resize', markDraggable);
  document.addEventListener('click', (e) => { if (e.target.closest('[data-add-roster-col], [data-add-tt-col]')) setTimeout(markDraggable); });

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
  if (!document.querySelector('[data-cho-toggle]')) return;
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
 * バンド編集: メンバー行の追加・削除
 * ------------------------------------------------------------------- */
function setupMemberRows() {
  if (!document.querySelector('[data-rows]')) return;
  // 行の集まり（[data-rows]）が1ページに何個あってもいいように、document で待ち受ける
  //   バンド編集: 1個だけ / タイムテーブル編集: バンドの数だけ（[data-rows-wrap] の中のボタン → その中の [data-rows]）
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-row]');
    if (add) {
      const rows = add.closest('[data-rows-wrap]')?.querySelector('[data-rows]') || document.querySelector('[data-rows]');
      const row = rows.lastElementChild.cloneNode(true); // 最後の行をコピーして
      const input = row.querySelector('[data-name-cell]') || row.querySelector('input');
      input.value = '';
      input.classList.remove('is-ok', 'is-similar', 'is-new');
      input.title = '';
      row.querySelector('select').value = '2';           // 楽器はギターに戻す（2 = Gt。コピー元の楽器を引き継がない）
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
    document.body.append(pop);

    // 入力欄の真下に出す。下に入りきらなければ上に（setupSelectPick とほぼ同じ）
    //   ただし高さの上限は 280px にしない。候補は最大 8 件（約 296px）なので、280px で切ると少しだけはみ出して
    //   スクロールバーが出て、その幅のぶん候補のマウスオーバーの背景が右端まで届かなくなる → 8 件がそのまま入る高さにする
    const r = input.getBoundingClientRect();
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
    if (!input || cur?.input === input) return;
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
  // fixed で出しているので、ページをスクロールしたら閉じる（候補の中のスクロールは別）
  window.addEventListener('scroll', (e) => { if (cur && !cur.pop.contains(e.target)) close(); }, true);
  window.addEventListener('resize', close);
}

/* ---------------------------------------------------------------------
 * お気に入り（❤）（live.php のタイムテーブル / band.php のバンド名の右）
 *   data-like="バンドID" のボタンを押すと api_band_like.php に送って、付ける ⇔ 外す。
 *   返ってきた数と状態で書き換える（同じバンドのボタンが1ページに複数あっても全部そろえる）
 * ------------------------------------------------------------------- */
function setupLikes() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-like]');
    if (!btn || btn.disabled) return;
    btn.disabled = true; // 通信中の連打を止める
    try {
      const res = await fetch('api_band_like', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ band_id: Number(btn.dataset.like) }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || '保存できませんでした');
      document.querySelectorAll(`[data-like="${btn.dataset.like}"]`).forEach((b) => {
        b.classList.toggle('is-liked', data.liked);
        b.setAttribute('aria-pressed', String(data.liked));
        b.setAttribute('aria-label', `お気に入り（${data.count}）`);
        b.querySelector('[data-like-count]').textContent = data.count;
      });
      if (data.liked) { // 付けたときだけハートをぽんと弾ませる
        btn.classList.remove('is-pop');
        void btn.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
        btn.classList.add('is-pop');
      }
    } catch (err) {
      console.error('お気に入りの通信エラー:', err);
      const toast = document.createElement('div');
      toast.textContent = 'お気に入りを保存できませんでした。ページを再読み込みしてやり直してください';
      showToast(toast);
    } finally {
      btn.disabled = false;
    }
  });
}
