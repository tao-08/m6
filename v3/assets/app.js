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
 *    data-slot-input    … 集計の学年スロット（回したら hidden に入れて送信）
 *    data-sort-toggle   … メンバーの出演履歴を新しい順 ⇔ 古い順に並び替える
 *    data-tab           … ライブ詳細の日程タブ
 *    data-timeline-compact … ライブ詳細の縮小ボタン（カードをバンド名だけにする）
 *    data-confirm       … 送信前の確認ダイアログ（data-dirty-check で未保存の変更も警告）
 *    data-unsaved       … 未保存の変更があるままページを離れようとしたら、ブラウザの警告を出す
 *    data-dropzone      … ファイルのドラッグ&ドロップ
 *    data-name-cell     … 名前の入力欄（DB にいるかで色が変わる）
 *    data-roster-input  … タイムテーブルの枠 → 名簿のバンド（検索欄）
 *    data-sortable      … タイムテーブルの行を ≡ のドラッグで並び替え（時間の列は動かない）
 *    data-tt-follow     … タイムテーブル編集の「追随」トグル（ON の間は、時刻を変えると下の行の時刻も一緒にずれる）
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
 *    open.spotify.com のリンク … Spotify のアプリで開く（PC はデスクトップアプリ）
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
  setupSlotInput();
  setupScrollEnd();
  setupClientSort();
  setupSortToggle();
  setupInstFilter();
  setupTabs();
  setupTimelineCompact();
  setupConfirm();
  setupLeaveCheck();
  setupUnsavedGuard();
  setupDropzone();
  setupLoading();
  setupNameCheck();
  setupImportPreview();
  setupMergeToggle();
  setupSelectPick();
  setupChorusToggle();
  setupCheckPicker();
  setupCheckDismiss();
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
  setupTimetableFollow();
  setupPicks();
  setupLiveImages();
  setupDragScroll();
  setupPackedForm();
  setupMemberRows();
  setupSuggest();
  setupSmallThings();
  setupBoardReply();
  setupComments();
  setupAliasField();
  setupBoardPanels();
  setupBoardMute();
  setupBoardTabs();
  setupVenueSuggest();
  setupSongs();
  setupTrackSearch();
  setupPreview();
  setupLikes();
  setupAlbumTip();
  setupSetlistTip();
  setupSetlistNames();
  setupSpotifyAppLinks();
  setupVoSum();
  setupYoutubeLeftover();
  setupBackButton();
  setupTutorial();
  setupAddToHome();
  setupServiceWorker();
  setupPush();
  setupPushLogout();
  setupKanaAi();
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
 * Spotify のリンク（https://open.spotify.com/…）を押したら、Spotify のアプリで開く（スマホも PC のデスクトップアプリも）
 *   https のリンクのままだと、target="_blank"（新しいタブ）やブラウザの設定しだいでアプリに飛ばず、ブラウザの Spotify が開くことがある。
 *   → アプリ用の URL（spotify:album:ID のような「spotify:」で始まる形）に置き換えて開く。
 *   1.5秒たってもこのページが見えたまま（アプリが入っていない）なら、元の https のページを開く。
 *   album_go.php / song_go.php（Spotify ⇔ Apple Music をまたぐリンク）は、押してから飛び先が決まる
 *   → ?json=1 で飛び先だけ聞いて、Spotify ならアプリで、それ以外はそのページを開く。
 *   PC はアプリが開いてもページが見えたままのことがあるので、ウィンドウからフォーカスが外れた（blur）ことも「アプリが開いた」とみなす。
 *   ブラウザの「Spotify を開きますか？」の確認が出たまま 1.5 秒たつと、ブラウザの Spotify も開くことがある。
 * ------------------------------------------------------------------- */
function setupSpotifyAppLinks() {
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
    window.addEventListener('blur', onLeave, { once: true }); // PC: デスクトップアプリが前に出る
    window.location.href = uri;
    setTimeout(() => {
      document.removeEventListener('visibilitychange', onLeave);
      window.removeEventListener('pagehide', onLeave);
      window.removeEventListener('blur', onLeave);
      if (!left && document.visibilityState === 'visible') window.location.href = webUrl;
    }, 1500);
  };

  document.addEventListener('click', async (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return; // Ctrl+クリックなど（新しいタブで開きたい）はブラウザに任せる
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
  let hideTimer = 0;

  const hide = () => { tip.classList.remove('is-visible'); current = null; };
  const show = (badge) => {
    // 中に [data-tip-body]（メンバー一覧の担当楽器「ほか」）があれば、そのバッジを写して出す。
    // サーバーが h() 済みで出した要素を複製するだけなので安全。無ければ文字だけ（textContent なので HTML として解釈されない）
    // data-tip-from="#id" なら、ページ内の別の要素（バンド編集の ⚠ のリンク付きの文）を写す（<button> の中に <a> は置けないため）
    const body = badge.querySelector('[data-tip-body]') || (badge.dataset.tipFrom ? document.querySelector(badge.dataset.tipFrom) : null);
    if (body) {
      const copy = body.cloneNode(true);
      copy.hidden = false;
      tip.replaceChildren(copy);
    } else {
      tip.textContent = badge.dataset.setlistTip;
    }
    tip.classList.toggle('album-tip--parts', !!body);
    tip.classList.toggle('album-tip--link', !!tip.querySelector('a')); // リンクがあるときだけ、ポップアップを押せるようにする
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
    // ポップアップの上にマウスがある間は消さない（中のリンクを押せるように）。
    // バッジ → ポップアップへ動かす途中のすき間で消えないよう、消すのは少し待ってから
    clearTimeout(hideTimer);
    const badge = e.target.closest('[data-setlist-tip]');
    if (badge) { if (badge !== current) show(badge); } else if (current && !tip.contains(e.target)) hideTimer = setTimeout(hide, 250);
  });

  // スマホは押したら出す。もう一度押すか、他の所を押すと消える
  document.addEventListener('click', (e) => {
    const badge = e.target.closest('[data-setlist-tip]');
    if (!badge) { if (!tip.contains(e.target)) hide(); return; } // ポップアップの中（リンク）を押したときは消さない
    if (canHover.matches) { show(badge); return; } // PC で押しても消さない（乗せたまま押すと消えてしまうので）
    if (badge === current) hide(); else show(badge);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
  // fixed なのでスクロールするとずれる → 隠す。capture: true で、ページだけでなく表の横スクロールでも隠す
  document.addEventListener('scroll', hide, { passive: true, capture: true });
}

/* ---------------------------------------------------------------------
 * バンドページのセトリ: 曲名が1行に入り切らず折り返したら、その曲名だけ少し小さくする（.is-wrapped → app.css）
 *   CSS には「折り返したら」という条件が無いので、元の大きさで高さを測って、2行分以上あれば付ける。
 *   画面の幅が変わると折り返す曲も変わるので、幅が変わるたびに測り直す
 * ------------------------------------------------------------------- */
function setupSetlistNames() {
  const names = document.querySelectorAll('.band-section .setlist__name');
  if (!names.length) return;
  const check = () => {
    names.forEach((el) => el.classList.remove('is-wrapped')); // 先に全部元の大きさに戻してから測る（小さくした後の高さで判定しない）
    names.forEach((el) => {
      const lineHeight = parseFloat(getComputedStyle(el).lineHeight);
      el.classList.toggle('is-wrapped', el.getBoundingClientRect().height > lineHeight * 1.5);
    });
  };
  document.fonts?.ready.then(check); // 文字のフォントが後から読み込まれると幅が変わるので、読み込み後にもう一度
  // セトリの幅が変わったら測り直す（画面を回したとき・ウィンドウの幅を変えたとき）。
  // window の resize ではなく ResizeObserver で、セトリそのものの幅を見張る。高さだけ変わったときは測らない（小さくすると高さが変わるので、無限ループになる）
  let lastWidth = 0;
  new ResizeObserver(([entry]) => {
    if (entry.contentRect.width === lastWidth) return;
    lastWidth = entry.contentRect.width;
    check();
  }).observe(names[0].closest('ol'));
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
  const toasts = document.querySelectorAll('[data-toasts] .toast');
  // 初回のチュートリアルが自動で開くとき（新規登録の直後など）は、お知らせ（「ようこそ！」など）をチュートリアルを閉じてから出す。
  //   チュートリアルの裏で 4 秒たって消えてしまわないように、消えるまでのタイマーも閉じてから始める。
  //   閉じるまで見えないようにするのは CSS（body:has([data-tutorial-auto]) .toasts）
  const tutorial = document.querySelector('[data-tutorial-dialog][data-tutorial-auto]');
  if (tutorial) {
    tutorial.addEventListener('close', () => toasts.forEach(showToast), { once: true });
    return;
  }
  toasts.forEach(showToast);
}

/* ---------------------------------------------------------------------
 * 曲の編集（songs_edit.php）
 *   data-add-song       … <template data-song-template> をコピーして空の曲カードを足す（data-add-song-se は SE 用）
 *   data-performer-on   … チェックを外した人を薄く表示
 *   data-omnibus        … オムニバスのチェック。外すとアーティスト欄をバンドのアーティストに戻して編集不可にする。
 *                         付けると空欄（前に書いていた名前があれば戻す）にして編集できるようにする
 * ------------------------------------------------------------------- */
function setupSongs() {
  // バンド編集の2つ目の楽器の「＋」: 1つ目の楽器の下に開閉。閉じたら値を空（なし）に戻す
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-inst2-toggle]');
    if (!btn) return;
    const row = btn.closest('.member-row-edit');
    const slot = row.querySelector('[data-inst2-slot]');
    const select = slot.querySelector('select');
    const open = !slot.classList.contains('is-open');
    slot.classList.toggle('is-open', open);
    btn.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open);
    if (open) { (slot.querySelector('.live-pick__btn') || select).focus({ preventScroll: true }); }
    else { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); }
  });

  // 演奏者の楽器の「＋」:下に楽器2の段を出して、＋ は隠す（3つ目以降は無い）
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
    let n = 1; // SE は数えない（曲の番号は 1, 2, 3...）
    cards().forEach((c, i) => {
      const no = c.querySelector('[data-song-no]'); // SE のカードには番号が無い
      if (no) no.textContent = String(n++);
      const pos = c.querySelector('[data-song-pos]');
      if (pos) pos.value = String(i);
    });
  };
  renumber();

  // ---- 曲の並び替え（≡ をドラッグ / ≡ にフォーカスして ↑↓ キー） ----
  //   指の位置が上か下のカードの真ん中を越えたら、そのカードと入れ替える
  let drag = null; // ドラッグ中だけ { card, pointerId, grab（カードの上端からつかんだ所までの距離）, y（今の指の高さ）, raf（自動スクロールの予約） }
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
    // 縮んだあとのカードより下をつかんでいても、カードが指から外れないよう ≡ の行の高さに収める
    const headH = card.querySelector('.song-card__head').offsetHeight + 16;
    drag = { card, pointerId: e.pointerId, grab: Math.min(e.clientY - card.getBoundingClientRect().top, headH) };
    drag.card.classList.add('is-dragging');
    list.classList.add('is-sorting'); // ドラッグ中だけ、全カードのアーティスト欄から下を縮める
    handle.setPointerCapture(e.pointerId); // 指やマウスが ≡ の外に出ても追いかける
    drag.y = e.clientY;
    drag.raf = requestAnimationFrame(autoScroll);
  });
  // 指の高さ y に合わせて、入れ替え（上下のカードの真ん中を越えたら）とカードの位置を更新する
  const follow = (y) => {
    const all = cards();
    const i = all.indexOf(drag.card);
    const prev = all[i - 1];
    const next = all[i + 1];
    // 真ん中の位置は、滑っている途中のずれを引いた「本当の位置」で測る（途中の位置で測ると行ったり来たりする）
    const mid = (el) => layoutTop(el) + el.offsetHeight / 2;
    if (prev && y < mid(prev)) {
      move(() => list.insertBefore(drag.card, prev), drag.card);
    } else if (next && y > mid(next)) {
      move(() => list.insertBefore(drag.card, next.nextElementSibling), drag.card);
    }
    // つかんでいるカードを指に付いてこさせる（本当の位置からのずれを transform で付ける）
    drag.card.style.transform = `translateY(${y - drag.grab - layoutTop(drag.card)}px)`;
  };
  // 自動スクロール: 指が画面の上端・下端の近く（EDGE px 以内）にあるあいだ、ページを上下に動かす。端に近いほど速い
  //   スクロールすると指の下のカードが変わるので、毎フレーム follow() で入れ替えとカードの位置をやり直す
  //   EDGE は、スマホの下のナビ・上のヘッダーに指がかかっても届くように大きめ
  const EDGE = 100;
  const MAX_SPEED = 18; // 1フレーム（約 1/60 秒）に動かす最大の px
  const autoScroll = () => {
    if (!drag) return;
    const y = drag.y;
    const speed = y < EDGE ? -(EDGE - y) / EDGE : y > innerHeight - EDGE ? (y - (innerHeight - EDGE)) / EDGE : 0;
    if (speed) {
      const before = scrollY;
      scrollBy(0, Math.round(Math.max(-1, Math.min(1, speed)) * MAX_SPEED));
      if (scrollY !== before) follow(y); // 端まで行って動かなかったときはやり直さない
    }
    drag.raf = requestAnimationFrame(autoScroll);
  };
  list.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    drag.y = e.clientY;
    follow(e.clientY);
  });
  const endDrag = (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    const { card } = drag;
    cancelAnimationFrame(drag.raf); // 自動スクロールを止める
    drag = null;
    list.classList.remove('is-sorting');
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
  const templateSe = list.querySelector('[data-song-template-se]'); // SE 用（演奏者・アーティスト欄なし）
  // name="songs[3][title]" の 3 の部分。保存済みの曲（0, 1, 2...）より大きく、押すたびに 1 ずつ増やして被らないようにする
  //   （Date.now() をそのまま使うと、同じミリ秒に2回押したとき同じ番号になり、2曲が1曲に混ざる）
  let nextIndex = Date.now();
  const addCard = (tpl, atTop = false) => {
    const card = tpl.content.firstElementChild.cloneNode(true);
    const newIndex = nextIndex++;
    card.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace(/^songs\[[^\]]+\]/, `songs[${newIndex}]`);
    });
    // テンプレートはページを開いたときのオムニバスの状態で作られているので、今のチェックに合わせる
    const artist = card.querySelector('[data-song-artist]'); // SE には無い
    if (artist) {
      artist.value = omnibus.checked ? '' : defaultArtist;
      artist.readOnly = !omnibus.checked;
    }
    // ふわっと出す（CSS の .song-card.is-entering）。終わったらクラスを外しておく
    card.classList.add('is-entering');
    card.addEventListener('animationend', () => card.classList.remove('is-entering'), { once: true });
    list.insertBefore(card, atTop ? cards()[0] ?? null : null); // SE は一番上（オープニング SE）、曲は一番下
    renumber();
    card.querySelector('[name$="[title]"]').focus();
  };
  document.querySelector('[data-add-song]').addEventListener('click', () => addCard(template));
  document.querySelector('[data-add-song-se]').addEventListener('click', () => addCard(templateSe, true));

  // ---- 元に戻す / やり直す（保存前の操作を1つずつ） ----
  //   操作のたびに「全カードの並び・入力値・状態」を撮って（capture）積んでおき、戻すときはそれに合わせ直す（restore）。
  //   消したカード（要素）も持っておくので、戻せば入力ごと復活する。入力欄は change（欄を離れたとき）で1操作になる
  const undoBtn = document.querySelector('[data-song-undo]');
  const redoBtn = document.querySelector('[data-song-redo]');
  const cardIds = new WeakMap();
  let cardSeq = 0;
  const capture = () => {
    const snap = { omni: omnibus.checked, cards: cards().map((card) => {
      if (!cardIds.has(card)) cardIds.set(card, ++cardSeq);
      return {
        card,
        fields: [...card.querySelectorAll('[name]')].map((el) => ({ el, value: el.value, checked: el.checked, disabled: el.disabled })),
        deleted: card.classList.contains('is-deleted'),
        inst: [...card.querySelectorAll('[data-inst2], [data-add-inst]')].map((el) => ({ el, hidden: el.hidden })),
        thumb: card.querySelector('[data-track-thumb]').innerHTML,
        thumbTitle: card.querySelector('.song-thumb').title,
        clearHidden: card.querySelector('[data-track-clear]').hidden,
      };
    }) };
    // 中身が同じ操作を積まないための印（欄の値・並び順・削除の印・紐付け）
    snap.sig = JSON.stringify([snap.omni, snap.cards.map((s) => [cardIds.get(s.card), s.fields.map((f) => [f.value, f.checked]), s.deleted, s.inst.map((i) => i.hidden), s.clearHidden])]);
    return snap;
  };
  let restoring = false;
  const restore = (snap) => {
    restoring = true;
    const keep = new Set(snap.cards.map((s) => s.card));
    cards().forEach((c) => { if (!keep.has(c)) c.remove(); });
    omnibus.checked = snap.omni;
    snap.cards.forEach((s) => {
      const c = s.card;
      c.getAnimations().forEach((a) => a.cancel());
      c.classList.remove('is-leaving', 'is-entering', 'is-dragging');
      c.style.cssText = '';
      list.appendChild(c);
      s.fields.forEach(({ el, value, checked, disabled }) => {
        const changed = el.value !== value || el.checked !== checked;
        el.value = value;
        el.checked = checked;
        el.disabled = disabled;
        if (changed || el.matches('select')) el.dispatchEvent(new Event('change', { bubbles: true })); // 見た目（楽器のボタン・薄い表示）を合わせさせる
      });
      c.classList.toggle('is-deleted', s.deleted);
      const del = c.querySelector('[data-song-delete-btn]');
      del.setAttribute('aria-pressed', String(s.deleted));
      del.setAttribute('aria-label', s.deleted ? '削除を取り消す' : 'この曲を削除');
      s.inst.forEach(({ el, hidden }) => { el.hidden = hidden; });
      c.querySelector('[data-track-thumb]').innerHTML = s.thumb;
      c.querySelector('.song-thumb').title = s.thumbTitle;
      c.querySelector('[data-track-clear]').hidden = s.clearHidden;
      const artist = c.querySelector('[data-song-artist]');
      if (artist) artist.readOnly = !snap.omni;
    });
    renumber();
    restoring = false;
  };
  const history = [capture()];
  let pos = 0;
  const refreshHistoryBtns = () => {
    undoBtn.disabled = pos <= 0;
    redoBtn.disabled = pos >= history.length - 1;
  };
  const commit = () => {
    if (restoring) return;
    const snap = capture();
    if (snap.sig === history[pos].sig) return;
    history.length = pos + 1; // 戻したあとに新しい操作をしたら、やり直しは捨てる
    history.push(snap);
    if (history.length > 50) history.shift();
    pos = history.length - 1;
    refreshHistoryBtns();
  };
  const step = (d) => {
    commit(); // 先に今の状態を積んでおく（積み残しがあれば、それも戻せるように）
    const next = pos + d;
    if (next < 0 || next >= history.length) return;
    pos = next;
    restore(history[pos]);
    refreshHistoryBtns();
  };
  undoBtn.addEventListener('click', () => step(-1));
  redoBtn.addEventListener('click', () => step(1));
  refreshHistoryBtns();
  // 操作のあとに積む（他の処理が終わってから撮るので setTimeout 0）
  const later = () => setTimeout(commit, 0);
  list.addEventListener('change', later);
  list.addEventListener('click', later);
  list.addEventListener('keyup', (e) => { if (e.key === 'ArrowUp' || e.key === 'ArrowDown') later(); });
  list.addEventListener('pointerup', later);
  omnibus.addEventListener('change', later);
  document.querySelectorAll('[data-add-song], [data-add-song-se]').forEach((b) => b.addEventListener('click', later));

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
    }
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
    // 試聴があるときだけ印を出す（api_track_search.php の preview が true のとき。無い・わからないときは出さない）
    if (track.preview === true) {
      const mark = document.createElement('span');
      mark.className = 'track-option__preview';
      mark.append(iconEl('play_circle'), '試聴可');
      mark.title = '30秒試聴があります（バンドページの ▶ で聴けます）';
      btn.append(mark);
    }
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
    const artistInput = card.querySelector('[data-song-artist]'); // SE には無い
    const q = `${title} ${artistInput ? artistInput.value.trim() : ''}`.trim();
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
          const artist = card.querySelector('[data-song-artist]'); // SE には無い
          if (artist && !artist.readOnly) artist.value = track.artist_name.split(', ')[0];
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

  // ボタンは後から差し替わる（楽曲データベースの一覧更新）ので、document で受ける
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-preview]');
    if (!btn || btn.disabled) return;
    if (current === btn) { stop(); return; }
    stop();
    retried = false;
    if (!btn.dataset.previewUrl) { // URL を聞きに行く間に「押した瞬間」が過ぎるので、先に無音を鳴らしておく
      audio.src = SILENCE;
      audio.play().catch(() => {});
    }
    start(btn);
  });
  // モーダルを閉じたら止める（close は伝わらないのでキャプチャで受ける）
  document.addEventListener('close', (e) => {
    if (current && e.target.contains?.(current)) stop();
  }, true);

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
  //   掲示板もメニューと同じ扱い（ヘッダーのアイコンから1回で行ける）
  if (items.length === 2 && matchMedia('(max-width: 760px)').matches) {
    const menu = [...document.querySelectorAll('.nav a[href], .topbar a[href="board"]')].map((a) => keyOf(a.href));
    const dup = items.filter((t) => menu.includes(keyOf(t.url)));
    if (dup.length) items = items.filter((t) => t !== (dup.length === 2 ? items[1] : dup[0]));
  }
  // アカウント設定で非表示にしている人（partials/header.php の meta）。戻り先は上で覚えたので、出さずに終わる
  if (document.querySelector('meta[name="back-button"]')?.content === 'off') items = [];
  // タイムテーブル作成の編集画面は、元に戻す / やり直すを左下に浮かせるので戻るボタンは出さない
  if (document.querySelector('[data-tt-builder]')) items = [];
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
 * 掲示板のスレのミュート（board_thread.php のスレ名の右のベル）
 *   お気に入り（setupLikes）と同じ流れ: 押した瞬間に見た目を変えて（ぽんと弾ませる）、api_board_mute に送り、
 *   返ってきた本当の状態で書き直す。失敗したら押す前に戻す。ページは移動しない
 * ------------------------------------------------------------------- */
function setupBoardMute() {
  const btn = document.querySelector('[data-board-mute]');
  if (!btn) return;
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const paint = (muted) => {
    btn.classList.toggle('is-active', muted);
    btn.setAttribute('aria-pressed', String(muted));
    btn.querySelector('.icon').textContent = muted ? 'notifications_off' : 'notifications';
  };
  let pending = false;
  btn.addEventListener('click', async () => {
    if (pending) return;
    pending = true;
    const before = btn.classList.contains('is-active');
    paint(!before);
    btn.classList.remove('is-pop');
    void btn.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
    btn.classList.add('is-pop');
    try {
      const res = await fetch('api_board_mute', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ thread_id: Number(btn.dataset.boardMute) }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || '保存できませんでした');
      paint(data.muted);
    } catch (err) {
      console.error('ミュートの通信エラー:', err);
      paint(before);
      const toast = document.createElement('div');
      toast.textContent = 'ミュートを保存できませんでした。ページを再読み込みしてやり直してください';
      showToast(toast);
    } finally {
      pending = false;
    }
  });
}

/* ---------------------------------------------------------------------
 * 掲示板一覧のカテゴリ切り替え（board.php）
 *   タブ [data-board-tabs] を押したら、ページ移動せずに同じ URL を取ってきて、一覧 [data-board-free] だけ差し替える。
 *   差し替えたら軽くスッと出す。URL は pushState で変える（戻る・進むでも切り替わる）。
 *   取れなかったときは普通にページ移動する。タブの名前は textContent ではなくサーバーが作った HTML を DOMParser 経由で移す
 *   （自分のサーバーの同じページなので、中身はサーバー側で h() 済み）
 * ------------------------------------------------------------------- */
function setupBoardTabs() {
  const tabs = document.querySelector('[data-board-tabs]');
  const area = document.querySelector('[data-board-free]');
  if (!tabs || !area) return;
  let seq = 0; // 連打したとき、最後に押した分だけ反映する
  const select = document.querySelector('form [name="category_id"]'); // スレッド作成のカテゴリ（選んでいるカテゴリに合わせる）

  const show = (url) => {
    const my = ++seq;
    return fetch(url, { credentials: 'same-origin' })
      .then((res) => { if (!res.ok) throw new Error(res.status); return res.text(); })
      .then((html) => {
        if (my !== seq) return;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const next = doc.querySelector('[data-board-free]');
        if (!next) throw new Error('no list');
        area.innerHTML = next.innerHTML;
        area.dataset.cat = next.dataset.cat;
        const hrefs = new Map([...doc.querySelectorAll('[data-board-tabs] .tab')].map((a) => [a.getAttribute('href'), a.classList.contains('is-active')]));
        tabs.querySelectorAll('.tab').forEach((a) => {
          const on = hrefs.get(a.getAttribute('href')) === true;
          a.classList.toggle('is-active', on);
          if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        if (select && next.dataset.cat !== '0') select.value = next.dataset.cat;
        area.classList.remove('is-entering');
        void area.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
        area.classList.add('is-entering');
      });
  };

  tabs.addEventListener('click', (e) => {
    const a = e.target.closest('a.tab');
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    if (a.classList.contains('is-active')) return;
    show(a.href).then(() => history.pushState(null, '', a.href)).catch(() => { location.href = a.href; });
  });
  window.addEventListener('popstate', () => { show(location.href).catch(() => location.reload()); });
}

/* ---------------------------------------------------------------------
 * 掲示板一覧の「スレッド作成」「通知設定」（board.php）
 *   見出し横のアイコン [data-board-panel-open="id"] を押すと、同じ id のカード [data-board-panel] が開く（もう一度で閉じる。同時に開くのは1つ）。
 *   URL が #notify などのとき（保存して戻ってきたとき）は、最初からそのカードを開く
 * ------------------------------------------------------------------- */
function setupBoardPanels() {
  const panels = [...document.querySelectorAll('[data-board-panel]')];
  if (!panels.length) return;
  const buttons = [...document.querySelectorAll('[data-board-panel-open]')];
  const open = (id) => {
    panels.forEach((p) => { p.hidden = p.id !== id; });
    buttons.forEach((b) => {
      const on = b.dataset.boardPanelOpen === id;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-expanded', String(on));
    });
  };
  buttons.forEach((b) => b.addEventListener('click', () => {
    const id = b.dataset.boardPanelOpen;
    open(panels.find((p) => p.id === id && !p.hidden) ? '' : id);
  }));
  const first = location.hash.slice(1);
  if (panels.some((p) => p.id === first)) open(first);

  // 現役のみ・同期のみは、どちらかを ON にするともう片方と年度の範囲をグレーアウトする（展開は CSS。.restrict）
  document.querySelectorAll('[data-board-restrict]').forEach((box) => {
    const active = box.querySelector('[name="only_active"]');
    const cohort = box.querySelector('[data-board-cohort]');
    const sync = () => {
      active.disabled = cohort.checked;
      cohort.disabled = active.checked;
      box.querySelectorAll('[data-board-range] select').forEach((s) => { s.disabled = active.checked || cohort.checked; });
    };
    active.addEventListener('change', sync);
    cohort.addEventListener('change', sync);
    sync();
  });


}

/* ---------------------------------------------------------------------
 * 掲示板の返信先（board_thread.php）
 *   各書き込みの返信ボタン [data-board-reply] で、返信先に追加・解除（複数可。最大 MAX 件）。
 *   選んだものは返信欄の上にチップで並べ、送信用に hidden の reply_to[] を入れる。チップを押しても解除できる。
 *   書き込み番号は textContent で入れる（innerHTML は使わない）
 * ------------------------------------------------------------------- */
function setupBoardReply() {
  const box = document.querySelector('[data-board-reply-to]');
  if (!box) return;
  const MAX = 10;
  const picked = new Map(); // post_id → 書き込み番号
  const buttons = new Map(
    [...document.querySelectorAll('[data-board-reply]')].map((b) => [b.dataset.boardReply, b]),
  );
  const shown = new Set(); // もう出しているチップ（新しく増えた分だけアニメーションさせる）
  const render = () => {
    box.replaceChildren();
    shown.forEach((id) => { if (!picked.has(id)) shown.delete(id); });
    picked.forEach((no, id) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'tag board-reply__chip' + (shown.has(id) ? '' : ' is-entering');
      shown.add(id);
      chip.setAttribute('aria-label', `>>${no} の返信を解除`);
      chip.textContent = `>>${no} ×`;
      chip.addEventListener('click', () => toggle(id));
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'reply_to[]';
      input.value = id;
      box.append(chip, input);
    });
    box.hidden = picked.size === 0;
    buttons.forEach((b, id) => {
      const on = picked.has(id);
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', String(on));
    });
  };
  const toggle = (id) => {
    if (picked.has(id)) picked.delete(id);
    else if (picked.size < MAX) picked.set(id, buttons.get(id)?.dataset.no ?? '');
    render();
  };
  buttons.forEach((b, id) => b.addEventListener('click', () => {
    const first = picked.size === 0;
    toggle(id);
    // 最初の1件を選んだときだけ返信欄へ移す（続けて選ぶときは動かさない）
    if (first && picked.has(id)) box.closest('form')?.querySelector('textarea')?.focus();
  }));
}

/* ---------------------------------------------------------------------
 * ライブ・バンドのコメント（lib/comments.php）
 *   返信先の選び方は setupBoardReply と同じ（[data-comment-reply] で追加・解除、チップと hidden の reply_to[]）。
 *   #c12 のようなリンク（投稿後・>>N）で来たら、閉じているトグルカードを開いてから飛ぶ
 * ------------------------------------------------------------------- */
function setupComments() {
  const root = document.querySelector('[data-comments]');
  if (!root) return;
  const openFor = () => {
    if (!/^#(c\d+|comments)$/.test(location.hash)) return;
    root.open = true;
    document.querySelector(location.hash)?.scrollIntoView({ block: 'start' });
  };
  openFor();
  window.addEventListener('hashchange', openFor);

  const box = root.querySelector('[data-comment-reply-to]');
  const MAX = 10;
  const picked = new Map(); // comment_id → 番号
  const buttons = new Map([...root.querySelectorAll('[data-comment-reply]')].map((b) => [b.dataset.commentReply, b]));
  const render = () => {
    box.replaceChildren();
    picked.forEach((no, id) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'tag board-reply__chip';
      chip.setAttribute('aria-label', `>>${no} の返信を解除`);
      chip.textContent = `>>${no} ×`;
      chip.addEventListener('click', () => toggle(id));
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'reply_to[]';
      input.value = id;
      box.append(chip, input);
    });
    box.hidden = picked.size === 0;
    buttons.forEach((b, id) => {
      b.classList.toggle('is-active', picked.has(id));
      b.setAttribute('aria-pressed', String(picked.has(id)));
    });
  };
  const toggle = (id) => {
    if (picked.has(id)) picked.delete(id);
    else if (picked.size < MAX) picked.set(id, buttons.get(id)?.dataset.no ?? '');
    render();
  };
  buttons.forEach((b, id) => b.addEventListener('click', () => {
    const first = picked.size === 0;
    toggle(id);
    if (first && picked.has(id)) box.closest('form')?.querySelector('textarea')?.focus();
  }));
}

/* ---------------------------------------------------------------------
 * こまごました動き
 *   data-autosubmit … セレクトボックスやラジオボタンを変えたらすぐフォームを送信（集計の絞り込み）
 * ------------------------------------------------------------------- */
function setupSmallThings() {
  document.querySelectorAll('[data-autosubmit]').forEach((sel) => sel.addEventListener('change', () => submitKeepScroll(sel.form)));
  // submitKeepScroll で送ったあとの読み直し: 送る前のスクロール位置に戻す（同じページのときだけ）
  try {
    const saved = JSON.parse(sessionStorage.getItem('keep-scroll') || 'null');
    sessionStorage.removeItem('keep-scroll');
    if (saved && saved.path === location.pathname) window.scrollTo(0, saved.y);
  } catch { /* sessionStorage が使えないときは戻さないだけ */ }
  // 集計の絞り込み（スマホは段ごと横スクロール）: 年度・学年のセレクトやスロットが出ている段は、右端まで送って見えるようにする
  document.querySelectorAll('.stats-filter__row').forEach((row) => {
    if ([...row.querySelectorAll('.stats-filter__extra')].some((el) => el.offsetParent)) row.scrollLeft = row.scrollWidth;
  });
  // 絞り込みが浮く位置（sticky の top）にどれだけ近いか → --stuck（0〜1）。残り 80px から近づくほど 1 に（スマホは見出しが薄くなる）
  document.querySelectorAll('.stats-filter').forEach((box) => {
    const check = () => {
      const rest = box.getBoundingClientRect().top - parseFloat(getComputedStyle(box).top);
      box.style.setProperty('--stuck', Math.min(1, Math.max(0, 1 - rest / 80)).toFixed(3));
    };
    window.addEventListener('scroll', check, { passive: true });
    check();
  });

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

  // ベル（data-notice-open）を開いたら・タブ（data-notif-tab）を切り替えたら、そのタブの直近の分を既読にしてバッジを減らす。
  //   どれを既読にするかはサーバーが決める（api_notice_read.php）。メニューは同時に1つだけ開く
  const menus = [...document.querySelectorAll('.usermenu')];
  menus.forEach((m) => m.addEventListener('toggle', () => {
    if (m.open) menus.forEach((o) => { if (o !== m) o.open = false; });
  }));
  // メニューの外をタッチ（クリック）したら閉じる
  document.addEventListener('pointerdown', (e) => {
    menus.forEach((m) => { if (m.open && !m.contains(e.target)) m.open = false; });
  });
  const bell = document.querySelector('[data-notice-open]');
  const markRead = (tab) => {
    const count = tab.querySelector('[data-notif-count]');
    if (!count) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const body = new URLSearchParams({ kind: tab.dataset.notifTab });
    fetch('api_notice_read.php', { method: 'POST', headers: { 'X-CSRF-Token': token }, body })
      .then((r) => r.json())
      .then((d) => {
        if (typeof d.unread !== 'number') return;
        const fmt = (n) => (n > 9 ? '9+' : String(n));
        if (d.tab > 0) count.textContent = fmt(d.tab); else count.remove();
        const badge = bell.querySelector('[data-notice-badge]');
        if (!badge) return;
        if (d.unread > 0) badge.textContent = fmt(d.unread); else badge.remove();
      })
      .catch(() => {});
  };
  bell?.addEventListener('click', (e) => {
    if (e.currentTarget.closest('details').open) return;
    const active = bell.closest('details').querySelector('[data-notif-tab].is-active');
    if (active) markRead(active);
  });
  bell?.closest('details').querySelectorAll('[data-notif-tab]').forEach((tab, _, tabs) => tab.addEventListener('click', () => {
    tabs.forEach((t) => {
      const on = t === tab;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', String(on));
      bell.closest('details').querySelector(`[data-notif-pane="${t.dataset.notifTab}"]`).hidden = !on;
    });
    // 切り替えた先のタブを、タブの並びの向き（通知=左 / お知らせ=右）から軽くスライドして出す
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
      const dir = tab === tabs[0] ? -12 : 12;
      bell.closest('details').querySelector(`[data-notif-pane="${tab.dataset.notifTab}"]`).animate(
        [{ opacity: 0, transform: `translateX(${dir}px)` }, { opacity: 1, transform: 'none' }],
        { duration: 180, easing: 'ease-out' });
    }
    markRead(tab);
  }));

  // お知らせのカテゴリタブ（data-notice-tabs）: ページを移動せず、data-notice-cat が合う記事だけ表示する。
  //   表示の動きは CSS（.notice-item:not([hidden]) のアニメーション）。URL の ?cat= も書きかえて、共有・再読み込みしても同じタブになる
  const noticeTabs = document.querySelector('[data-notice-tabs]');
  if (noticeTabs) {
    const items = document.querySelectorAll('[data-notice-cat]');
    const empty = document.querySelector('[data-notice-empty]');
    const show = (cat, animate = true) => {
      let count = 0;
      items.forEach((it) => {
        const match = cat === '0' || it.dataset.noticeCat === cat;
        it.hidden = !match;
        if (match) count++;
      });
      if (empty) empty.hidden = count > 0;
      if (animate && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
        // 表示される記事を全部ふわっと出す（「すべて」⇔各カテゴリのように、前後で表示されたままの記事も動かすため JS で動かす）
        [...items, empty].filter((el) => el && !el.hidden).forEach((el) => el.animate(
          [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
          { duration: 250, easing: 'ease' }));
      }
      noticeTabs.querySelectorAll('.tab').forEach((t) => {
        const on = t.dataset.cat === cat;
        t.classList.toggle('is-active', on);
        on ? t.setAttribute('aria-current', 'page') : t.removeAttribute('aria-current');
      });
    };
    noticeTabs.addEventListener('click', (e) => {
      const tab = e.target.closest('.tab');
      if (!tab) return;
      e.preventDefault();
      show(tab.dataset.cat);
      history.replaceState(null, '', tab.getAttribute('href'));
    });
    show(noticeTabs.querySelector('.tab.is-active')?.dataset.cat ?? '0', false); // 最初のタブが空のときの「お知らせはありません」もここで出す
  }

  // お知らせの予約: 「予約」にチェックを入れたときだけ日時の入力欄を出す（隠している間は disabled で送らない）
  document.querySelectorAll('[data-notice-schedule]').forEach((box) => {
    const input = box.closest('form').querySelector('[data-notice-schedule-at]');
    const sync = () => { input.hidden = !box.checked; input.disabled = !box.checked; input.required = box.checked; };
    box.addEventListener('change', sync);
    sync();
  });

  // 管理者用のトグルカード（<details class="togglecard">）: 開くたびに中身をふわっと出す（動きを減らす設定の人には動かさない）。
  //   #categories で来たとき（カテゴリを操作した直後）は最初から開いておく
  document.querySelectorAll('.togglecard').forEach((card) => {
    if (card.id && location.hash === '#' + card.id) card.open = true;
    card.querySelector('summary')?.addEventListener('click', () => {
      if (card.open || matchMedia('(prefers-reduced-motion: reduce)').matches) return; // card.open = これから閉じる
      card.querySelector('.togglecard__body, .notice-body')?.animate(
        [{ opacity: 0, transform: 'translateY(-8px)' }, { opacity: 1, transform: 'none' }],
        { duration: 220, easing: 'cubic-bezier(.2, .9, .3, 1.1)' });
    });
  });

  // data-fill-hint … メンバープロフィールの一括編集で、空欄の入力欄に data-hint（初出演の年度）を入れる。保存はしない
  document.querySelectorAll('[data-fill-hint]').forEach((btn) => btn.addEventListener('click', () => {
    btn.form.querySelectorAll('input[data-hint]').forEach((input) => {
      if (input.value === '') {
        input.value = input.dataset.hint;
      }
    });
  }));

  // data-check-inactive … メンバープロフィールの一括編集で、data-inactive（1年以上ライブに出ていない現役）の人に退会のチェックを入れる。
  //   保存はしない（入ったチェックを見て、違う人は外してから保存してもらう）。何人に入れたかをボタンの横に出す
  document.querySelectorAll('[data-check-inactive]').forEach((btn) => btn.addEventListener('click', () => {
    const boxes = [...btn.form.querySelectorAll('input[type=checkbox][data-inactive]')].filter((cb) => !cb.checked);
    boxes.forEach((cb) => { cb.checked = true; });
    btn.textContent = boxes.length ? `${boxes.length}人にチェックしました（まだ保存していません）` : 'チェックする人はいませんでした';
    boxes[0]?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }));

  // data-send-changed … 送る直前に、変えていない入力欄を disabled にして送らない（disabled の欄はフォームから送られない）
  //   メンバープロフィールの一括編集は全員分だと入力欄が 1000 を超え、PHP の max_input_vars（初期値 1000）で
  //   後ろの人の分が黙って捨てられていた。変えた人の分だけ送れば、何百人いても上限に届かない
  //   チェックボックスは直前の hidden（外したときの 0）と組なので、変えていなければ両方送らない
  document.querySelectorAll('form[data-send-changed]').forEach((form) => {
    const fields = () => [...form.querySelectorAll('input[name]:not([type=hidden])')];
    form.addEventListener('submit', () => {
      fields().forEach((input) => {
        const same = input.type === 'checkbox' ? input.checked === input.defaultChecked : input.value === input.defaultValue;
        if (!same) return;
        input.disabled = true;
        const prev = input.previousElementSibling;
        if (input.type === 'checkbox' && prev?.matches(`input[type=hidden][name="${CSS.escape(input.name)}"]`)) prev.disabled = true;
      });
    });
    // 戻るボタンでこのページに戻ったとき（ブラウザがページをそのまま残している）に、disabled のままにしない
    window.addEventListener('pageshow', () => form.querySelectorAll('input[name]').forEach((input) => { input.disabled = false; }));
  });
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
// 画面の一番上（時計・電池の所）の色 = app.css の --bg。partials/header.php の <head> の script と同じ値
const THEME_COLORS = { light: '#f6f5f1', dark: '#0d0d11' };

function setupThemeToggle() {
  document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.content = THEME_COLORS[next];
      try { localStorage.setItem('theme', next); } catch (e) { /* プライベートモード等で保存できなくても無視 */ }
    });
  });
}

/* ---------------------------------------------------------------------
 * 「ホーム画面に追加する」の説明（[data-a2hs-dialog]。partials/add_to_home.php）
 *   右上メニューの [data-a2hs-open] で開く。メニューにはスマホ・タブレットのときだけ出る（app.css の .touch-only）
 *   開いたときは、使っているブラウザの説明を先に出す（iPhone・iPad の Safari → Safari、それ以外 → Chrome）
 *   Android の Chrome は「インストールできる」と判断すると beforeinstallprompt を送ってくる
 *     → それを取っておいて「今すぐインストール」ボタンを出す（押すと Chrome のインストール画面がすぐ出る）
 * ------------------------------------------------------------------- */
function setupAddToHome() {
  const dialog = document.querySelector('[data-a2hs-dialog]');
  if (!dialog) return;
  const openers = document.querySelectorAll('[data-a2hs-open]');
  const tabs = [...dialog.querySelectorAll('[data-a2hs-tab]')];
  const panels = [...dialog.querySelectorAll('[data-a2hs-panel]')];
  const installBtn = dialog.querySelector('[data-a2hs-install]');

  // 古い iPhone は CSS の display-mode を知らないので、ホーム画面から開いているかを navigator.standalone でも見る
  if (navigator.standalone) openers.forEach((btn) => { btn.hidden = true; });

  const show = (name) => {
    tabs.forEach((t) => t.setAttribute('aria-selected', String(t.dataset.a2hsTab === name)));
    panels.forEach((p) => { p.hidden = p.dataset.a2hsPanel !== name; });
  };
  tabs.forEach((t) => t.addEventListener('click', () => show(t.dataset.a2hsTab)));

  // iPad の Safari は「Mac です」と名乗るので、Mac なのに指で触れる → iPad とみなす
  //   iPhone の Chrome（CriOS）・Firefox（FxiOS）・Edge（EdgiOS）は Chrome 側に出す（そこに iPhone の Chrome の手順を書いてある）
  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  const isSafari = isIOS && !/CriOS|FxiOS|EdgiOS/.test(ua);

  let installEvent = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // Chrome が勝手に出す下のバナーは止めて、ボタンを押したときに出す
    installEvent = e;
    if (installBtn) installBtn.hidden = false;
  });
  installBtn?.addEventListener('click', async () => {
    if (!installEvent) return;
    installEvent.prompt();
    const { outcome } = await installEvent.userChoice;
    installEvent = null; // 1回しか使えない
    installBtn.hidden = true;
    if (outcome === 'accepted') dialog.close();
  });
  // インストールが終わったら、もうメニューに出さない
  window.addEventListener('appinstalled', () => openers.forEach((btn) => { btn.hidden = true; }));

  openers.forEach((btn) => btn.addEventListener('click', () => {
    btn.closest('details')?.removeAttribute('open'); // 右上メニューは閉じておく
    show(isSafari ? 'safari' : 'chrome');
    dialog.showModal();
  }));
  dialog.querySelector('[data-a2hs-close]').addEventListener('click', () => dialog.close());
  // 外側（背景）をクリックしても閉じる。<dialog> 自体がクリックされた = 中身の外側
  dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

  // ?a2hs=1 で開かれたとき（曲ページの練習のショートカットボタン）: そのページのホーム画面追加の案内をすぐ出す。
  //   beforeinstallprompt が届く前に出すと「今すぐインストール」が間に合わないので、少しだけ待つ。パラメーターは消して、再読み込みで出直さない
  const url = new URL(location.href);
  if (url.searchParams.get('a2hs') === '1' && !navigator.standalone) {
    url.searchParams.delete('a2hs');
    history.replaceState(null, '', url);
    setTimeout(() => { show(isSafari ? 'safari' : 'chrome'); dialog.showModal(); }, 600);
  }
}

/* ---------------------------------------------------------------------
 * ホーム画面に追加したとき（PWA）の裏方 sw.js を登録する。
 *   電波が無いときに offline.html を出すだけ（くわしくは sw.js の先頭）。
 *   https か localhost でしか動かない（それ以外ではブラウザが serviceWorker を出さない）
 * ------------------------------------------------------------------- */
function setupServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker.register('sw.js').catch(() => { /* 登録できなくてもサイトは普通に使える */ });
}

/* ---------------------------------------------------------------------
 * アプリ通知（プッシュ）の「この端末」スイッチ（account.php のアプリ通知。[data-push]）
 *   ON : 許可を聞く → 端末の購読を作る → api_push_subscribe に送る。OFF: 購読を消して api に伝える
 *   許可のダイアログは「ボタンを押した直後」でないと出ないので、ページを開いたときには聞かない。
 *   iPhone は「ホーム画面に追加」したアプリでだけ使える（Safari で開いただけだと PushManager が無い）。
 * ------------------------------------------------------------------- */
function setupPush() {
  const wrap = document.querySelector('[data-push]');
  if (!wrap) return;
  const box = wrap.querySelector('[data-push-toggle]');
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  wrap.hidden = false;
  // 端末の登録・解除が終わるまで、下の保存ボタンは押せない
  const saveBtns = document.querySelectorAll('[data-push-form] button[type="submit"]');
  const setBusy = (on) => saveBtns.forEach((b) => { b.disabled = on; });
  // 通知オンが外れている間は、下の受け取り設定をグレーにして触れなくする（inert。disabled だと保存で 0 に上書きされるため使わない）
  const prefChecks = document.querySelectorAll('[data-push-form] .check');
  const syncPrefs = () => prefChecks.forEach((l) => { l.inert = !box.checked; });
  syncPrefs();

  const lock = (message) => {
    box.disabled = true;
    box.checked = false;
    wrap.title = message;
    syncPrefs();
  };
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    lock('ホーム画面に追加すると使えます');
    return;
  }
  if (Notification.permission === 'denied') {
    lock('ブラウザの設定で通知がブロックされています');
    return;
  }

  // 公開鍵（base64url）→ subscribe() が求める Uint8Array
  const keyBytes = (b64) => {
    const raw = atob(b64.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(b64.length / 4) * 4, '='));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  };
  const post = async (body) => {
    const res = await fetch('api_push_subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || '保存できませんでした');
  };
  const toast = (text) => {
    const el = document.createElement('div');
    el.textContent = text;
    showToast(el);
  };

  // いまの状態を反映（この端末がすでに購読していれば ON）
  navigator.serviceWorker.ready
    .then((reg) => reg.pushManager.getSubscription())
    .then((sub) => { box.checked = sub !== null && Notification.permission === 'granted'; syncPrefs(); })
    .catch(() => {});

  box.addEventListener('change', async () => {
    const want = box.checked;
    box.disabled = true;
    setBusy(true);
    try {
      const reg = await navigator.serviceWorker.ready;
      if (want) {
        if ((await Notification.requestPermission()) !== 'granted') throw new Error('denied');
        const sub = (await reg.pushManager.getSubscription())
          || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(wrap.dataset.push) });
        const json = sub.toJSON();
        await post({ action: 'subscribe', endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth });
      } else {
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
          await post({ action: 'unsubscribe', endpoint: sub.endpoint });
          await sub.unsubscribe();
        }
      }
    } catch (err) {
      console.error('プッシュ通知の設定エラー:', err);
      box.checked = !want;
      if (Notification.permission === 'denied') lock('ブラウザの設定で通知がブロックされています');
      toast('プッシュ通知を設定できませんでした');
    } finally {
      if (Notification.permission !== 'denied') box.disabled = false;
      setBusy(false);
      syncPrefs();
    }
  });
}

/* ---------------------------------------------------------------------
 * ログアウトするとき、この端末のプッシュ通知を解除する（form[action="logout"]）
 *   解除しないと、共有の端末で次に使う人にも、前の人宛ての通知が届き続けてしまう。
 *   サーバーは「どの端末か」を知らないので、ログアウトの前に端末側から解除を伝える（ログイン中でないと API が通らない）。
 *   失敗・遅延してもログアウトは必ず進める（最長 3 秒待つ）。
 * ------------------------------------------------------------------- */
function setupPushLogout() {
  const form = document.querySelector('form[action="logout"]');
  if (!form || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let done = false;
  form.addEventListener('submit', async (e) => {
    if (done) return;
    e.preventDefault();
    const work = (async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      const sub = await reg?.pushManager.getSubscription();
      if (!sub) return;
      await fetch('api_push_subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ action: 'unsubscribe', endpoint: sub.endpoint }),
      });
      await sub.unsubscribe();
    })().catch(() => { /* 解除できなくてもログアウトは続ける */ });
    await Promise.race([work, new Promise((resolve) => setTimeout(resolve, 3000))]);
    done = true;
    form.submit();
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
function animateIn(elements, duration = 240) {
  if (reduceMotion) return;
  [...elements]
    .filter((el) => el.offsetParent !== null && el.getBoundingClientRect().top < window.innerHeight)
    .forEach((el, i) => {
      el.getAnimations().forEach((a) => a.cancel());
      el.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
        { duration, delay: Math.min(i, 12) * 25, easing: 'ease-out', fill: 'backwards' });
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
    // 最初の1回（読み込み直後）はアニメーションなしでその段に置く（集計で送信したあと、上から回って見えないように）
    reel.classList.toggle('is-dragging', first);
    move(-index * itemH());
    if (first) { reel.offsetHeight; reel.classList.remove('is-dragging'); }
    items.forEach((el, n) => el.classList.toggle('is-current', n === index));
    const value = items[index].dataset.value;
    slot.setAttribute('aria-valuetext', items[index].textContent);
    slot.classList.toggle('is-filtered', value !== '');
    onSelect(value, !first && index !== before);
  };

  // ---- タップで開くプルダウン（独自デザイン）: body 直下に固定位置で出す（スロットの overflow で切れないように） ----
  let menu = null;
  const closeMenu = () => {
    if (!menu) return;
    menu.remove();
    menu = null;
    document.removeEventListener('pointerdown', outside, true);
    removeEventListener('scroll', onScroll, true);
    removeEventListener('resize', closeMenu);
  };
  const onScroll = (e) => { if (!menu?.contains(e.target)) closeMenu(); };
  const outside = (e) => { if (!menu.contains(e.target) && !slot.contains(e.target)) closeMenu(); };
  const openMenu = () => {
    if (menu) { closeMenu(); return; }
    menu = document.createElement('div');
    menu.className = 'slot-menu';
    menu.setAttribute('role', 'listbox');
    items.forEach((el, n) => {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'slot-menu__item' + (n === index ? ' is-current' : '');
      opt.setAttribute('role', 'option');
      opt.textContent = el.dataset.label || el.textContent.trim();
      opt.addEventListener('click', () => { closeMenu(); select(n); slot.focus(); });
      menu.append(opt);
    });
    document.body.append(menu);
    const r = slot.getBoundingClientRect();
    const below = innerHeight - r.bottom - 8;
    menu.style.minWidth = `${r.width}px`;
    menu.style.left = `${Math.min(r.left, innerWidth - menu.offsetWidth - 8)}px`;
    if (below < Math.min(menu.offsetHeight, 200) && r.top > below) {
      menu.style.bottom = `${innerHeight - r.top + 4}px`;
      menu.style.maxHeight = `${Math.min(r.top - 12, 340)}px`;
    } else {
      menu.style.top = `${r.bottom + 4}px`;
      menu.style.maxHeight = `${Math.min(below, 340)}px`;
    }
    menu.querySelector('.is-current')?.scrollIntoView({ block: 'center' });
    document.addEventListener('pointerdown', outside, true);
    addEventListener('scroll', onScroll, true);
    addEventListener('resize', closeMenu);
  };

  // ---- ドラッグ（マウスもタッチも pointer イベントでまとめて扱う） ----
  let startY = null;
  let dy = 0;
  slot.addEventListener('pointerdown', (e) => {
    closeMenu();
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
            // ほぼ動いていない = タップ → プルダウンを開く
      select(index);
      openMenu();
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
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openMenu(); }
    if (e.key === 'Escape') closeMenu();
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
  // 横に検索バーがあれば（ライブ一覧・メンバーの出演履歴）、縦横の比率はそのままで検索バーの高さに合わせて縮める
  const search = slot.parentElement.querySelector('.search');
  if (search && slot.classList.contains('year-slot--live')) {
    // ライブ一覧: 縮めずに、高さだけ検索バーに合わせる（1段 = 高さの半分。幅と文字は CSS）
    const h = search.offsetHeight;
    slot.style.height = `${h}px`;
    slot.querySelectorAll('.year-slot__item').forEach((el) => { el.style.height = `${h / 2}px`; });
  } else if (search) {
    slot.style.zoom = search.offsetHeight / slot.offsetHeight;
  }
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

/* フォームを送る（ページを読み直す）けど、今のスクロール位置は保つ（集計の絞り込み）。戻すのは setupSmallThings */
function submitKeepScroll(form) {
  try { sessionStorage.setItem('keep-scroll', JSON.stringify({ path: location.pathname, y: scrollY })); } catch { /* 保てないだけ */ }
  form.submit();
}

/* ---------------------------------------------------------------------
 * フォームの中のスロット（集計の学年別）: data-slot-input
 *   回したら、中の hidden input に選んだ値を入れてフォームを送信する
 * ------------------------------------------------------------------- */
function setupSlotInput() {
  document.querySelectorAll('[data-slot-input]').forEach((slot) => {
    const input = slot.querySelector('input[type="hidden"]');
    makeSlot(slot, (value, changed) => {
      input.value = value;
      if (changed) submitKeepScroll(input.form);
    });
  });
}

/* ---------------------------------------------------------------------
 * 学年の絞り込み（メンバー一覧）: タブ（全学年 / 上下3学年 / 学年別）+ 学年別のときだけ出る学年スロット
 *   今の絞り込みの値: '' = 全学年 / 'near' = 上下3学年（タブの data-min〜data-max の入学年度）/ 入学年度 / 'none' = 入学年度が不明
 *   行（tr[data-entry]）は .is-grade-hidden で隠す（hidden は名前検索が使うので、ぶつからないよう別にする）
 *   切り替えたら:
 *     ・# の順位を、見えている人の中で付け直す（data-score の多い順。同じなら同じ順位。出演なしの人は —）
 *     ・上の「出演者」「登録」の人数を数え直す
 *     ・URL（?who=&entry=）に今の学年を入れる（再読み込みで戻らないように）
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
  const hideRetired = box.querySelector('[data-hide-retired]'); // 退会した人がいないときは出ていない（null）
  let slotValue = '';

  const apply = (changed) => {
    const radio = radios.find((r) => r.checked) || radios[0];
    const who = radio.value;
    box.classList.toggle('is-grade', who === 'grade'); // 学年スロットを出す（CSS の .grade-slot-wrap がアニメーションで広がる）
    const match = (entry) => {
      if (who === 'all') return true;
      // 上下3学年・現役・卒業生: 入学年度が data-min 〜 data-max の人（無い側は制限なし）。入学年度が不明な人は出さない
      if (who !== 'grade') {
        const { min, max } = radio.dataset;
        return entry !== '' && (min === undefined || +entry >= +min) && (max === undefined || +entry <= +max);
      }
      return slotValue === 'none' ? entry === '' : entry === slotValue;
    };
    const retiredOff = hideRetired && hideRetired.checked;
    rows.forEach((tr) => tr.classList.toggle('is-grade-hidden',
      !match(tr.dataset.entry) || (retiredOff && 'retired' in tr.dataset)));
    const shown = rows.filter((tr) => !tr.classList.contains('is-grade-hidden'));

    // 順位 = 自分より data-score が多い人の数 + 1（出演なしの人は —）
    const played = shown.filter((tr) => +tr.dataset.bands > 0).map((tr) => +tr.dataset.score);
    const ranking = !!document.querySelector('.table--members.is-ranking');
    shown.forEach((tr) => {
      const n = +tr.dataset.score;
      // # に順位を出すのはランキングの並びのときだけ（それ以外は空）。
      //   左端の # と、スマホで右端に出す # の2つがある
      const rank = !ranking ? '' : +tr.dataset.bands > 0 ? played.filter((m) => m > n).length + 1 : '—';
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
      params.set('who', who); // 全学年も書く（書かないと、再読み込みで最初のタブ = 現役の人は「現役」に戻ってしまう）
      if (who === 'grade') params.set('entry', slotValue);
      // 退会した人を表示する（チェックを外した）ときだけ書く。初期値は「隠す」
      params.delete('retired');
      if (hideRetired && !hideRetired.checked) params.set('retired', 'show');
    };
    const url = new URL(location.href);
    setParams(url.searchParams);
    history.replaceState(history.state, '', url);

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
  hideRetired?.addEventListener('change', () => apply(true));
  // 見出しで並べ替えたら（setupClientSort）: 出演・ライブ・トリならその列の順位を # に出す。ほかは # を空にする
  const membersTable = document.querySelector('.table--members');
  membersTable?.addEventListener('client-sort', (e) => {
    const col = ['bands', 'lives', 'headliners'].includes(e.detail.sort) ? e.detail.sort : '';
    membersTable.classList.toggle('is-ranking', !!col);
    const head = membersTable.querySelector('[data-rank-head]');
    if (head) head.textContent = col ? '#' : '';
    const prop = 'score' + (col || 'bands').replace(/^./, (c) => c.toUpperCase());
    rows.forEach((tr) => { tr.dataset.score = tr.dataset[prop]; });
    apply(false);
  });
  apply(false);
}

/* ---------------------------------------------------------------------
 * 再読み込みなしで並べ替える表（table[data-client-sort]。ユーザー管理・メンバー・アーティスト・マスター管理）
 *   並び方ごとの行の位置は PHP が計算して tr[data-pos] に入れてある（lib/bootstrap.php の sort_positions）。
 *   JS は比べ方を持たず、その番号の順に行を並べ直すだけ（開いたときの PHP の並びと必ず同じになる）
 *   見出しの button[data-sort=列][data-sort-first=最初の向き] を押すたびに 最初の向き → 逆の向き
 *     table[data-sort-clear] なら、その次で解除（いつもの並び = data-pos の '' ）
 *   並べ替えたら URL を ?sort=&dir= に書き換える（再読み込み・共有しても同じ並び。PHP が同じ GET で並べる）
 *     button[data-sort-clear-scroll] の列を解除したときは ?scroll=end も付ける（メンバー一覧: 表を右端に置いたまま開く）
 *     table[data-sort-save=URL] なら、そこにも ?sort=&dir= を送ってサーバーに覚えてもらう（マスター管理）
 *   並べ替えたら table に client-sort イベントを出す（メンバー一覧の # の付け直しが聞いている）
 * ------------------------------------------------------------------- */
function setupClientSort() {
  // form[data-keep-query]: 別のページに送るフォームにも今の並び順（URL の ?…）を付けて、処理のあと同じ並びで戻れるように
  document.querySelectorAll('form[data-keep-query]').forEach((form) => {
    const base = form.getAttribute('action');
    form.addEventListener('submit', () => { form.action = base + location.search; });
  });
  document.querySelectorAll('table[data-client-sort]').forEach((table) => {
    const tbody = table.tBodies[0];
    const pos = new Map([...tbody.rows].map((tr) => [tr, JSON.parse(tr.dataset.pos)]));
    const btns = [...table.querySelectorAll('button[data-sort]')];
    let sort = table.dataset.sortCurrent || '';
    let dir = table.dataset.dirCurrent || '';
    btns.forEach((btn) => btn.addEventListener('click', () => {
      const col = btn.dataset.sort;
      const first = btn.dataset.sortFirst || 'asc';
      const flip = first === 'asc' ? 'desc' : 'asc';
      let cleared = false;
      if (sort !== col) [sort, dir] = [col, first];
      else if (dir === first) dir = flip;
      else if ('sortClear' in table.dataset) { [sort, dir] = ['', '']; cleared = true; }
      else dir = first;

      const key = sort ? `${sort}:${dir}` : '';
      const rows = [...pos.keys()].sort((a, b) => pos.get(a)[key] - pos.get(b)[key]);
      tbody.append(...rows);
      btns.forEach((b) => {
        const th = b.closest('th');
        const on = b.dataset.sort === sort;
        if (on) th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        else th.removeAttribute('aria-sort');
        b.querySelector('.sortable__arrow').textContent = on ? (dir === 'asc' ? '▲' : '▼') : '';
      });

      const url = new URL(location.href);
      ['sort', 'dir', 'scroll'].forEach((k) => url.searchParams.delete(k));
      if (sort) {
        url.searchParams.set('sort', sort);
        url.searchParams.set('dir', dir);
      } else if (cleared && 'sortClearScroll' in btn.dataset) {
        url.searchParams.set('scroll', 'end');
      }
      history.replaceState(history.state, '', url);
      if (table.dataset.sortSave && sort) {
        fetch(`${table.dataset.sortSave}&sort=${encodeURIComponent(sort)}&dir=${dir}`, { credentials: 'same-origin' });
      }
      table.dispatchEvent(new CustomEvent('client-sort', { bubbles: true, detail: { sort, dir } }));
      animateIn(rows, 120);
    }));
  });
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
/* ---------------------------------------------------------------------
 * 楽器フィルター（lib/repository.php の inst_filter）
 *   data-inst-items があれば、その行を data-insts で隠す（メンバー一覧）
 *   無ければ出演履歴: バンド → 日のカード → 年度 の順に、残るものが無ければ隠す
 *   メニューは body に移して position: fixed（表の横スクロールの枠で切れない・translate の親の中だと fixed がずれるため）
 * ------------------------------------------------------------------- */
function setupInstFilter() {
  document.querySelectorAll('[data-inst-filter]').forEach((box) => {
    const list = document.querySelector(box.dataset.instFilter);
    const btn = box.querySelector('.inst-filter__btn');
    const menu = box.querySelector('.inst-filter__menu');
    if (!list) return;
    const funnel = btn.innerHTML;
    document.body.append(menu);
    const open = (on) => {
      menu.hidden = !on;
      btn.setAttribute('aria-expanded', String(on));
      if (!on) return;
      const r = btn.getBoundingClientRect();
      menu.style.top = `${r.bottom + 4}px`;
      menu.style.left = `${Math.max(8, Math.min(r.left, innerWidth - menu.offsetWidth - 8))}px`;
    };
    const has = (el, inst) => (el.dataset.insts || '').split(' ').includes(inst);
    btn.addEventListener('click', (e) => { e.stopPropagation(); open(menu.hidden); });
    document.addEventListener('click', (e) => { if (!box.contains(e.target) && !menu.contains(e.target)) open(false); });
    window.addEventListener('scroll', () => open(false), { passive: true, capture: true });
    menu.addEventListener('click', (e) => {
      const opt = e.target.closest('[data-inst]');
      if (!opt) return;
      const inst = opt.dataset.inst;
      btn.innerHTML = inst ? opt.innerHTML : funnel;
      btn.classList.toggle('is-active', !!inst);
      if (box.dataset.instItems) {
        list.querySelectorAll(box.dataset.instItems).forEach((row) => {
          row.classList.toggle('is-inst-hidden', !!inst && !has(row, inst));
        });
      } else {
        list.querySelectorAll('.history__band').forEach((b) => { b.hidden = !!inst && !has(b, inst); });
        list.querySelectorAll('.history__item').forEach((d) => {
          d.classList.toggle('is-inst-hidden', !d.querySelector('.history__band:not([hidden])'));
        });
        list.querySelectorAll('section').forEach((y) => {
          y.classList.toggle('is-inst-hidden', !y.querySelector('.history__item:not(.is-inst-hidden)'));
        });
      }
      // 絞り込み後の一覧をふわっと出す（動きを減らす設定なら出さない）
      if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
        list.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: 'ease-out' });
      }
      open(false);
    });
  });
}

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
  const tabs = document.querySelectorAll('a[data-tab]'); // a に限る: tab.php の TAB 譜（div[data-tab]）の中のクリックまで止めてしまうので
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
 * ライブ詳細の縮小ボタン（[data-timeline-compact]）
 *   押すと全日程のタイムテーブル（.timeline）がバンド名だけになる（左の順番・時刻はそのまま。CSS: .timeline--compact）
 *   もう一度で元に戻る。選んだ状態は localStorage に覚える
 *   アニメーション: 切り替えの前後で各行（.slot）の高さを測り、前の高さ → 後の高さへ滑らせる
 *   （見えている日程の行だけ。動きを減らす設定の人には動かさない）
 * ------------------------------------------------------------------- */
function setupTimelineCompact() {
  const buttons = document.querySelectorAll('[data-timeline-compact]');
  if (!buttons.length) return;
  const KEY = 'timelineCompact';
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  const apply = (on, animate = false) => {
    const slots = animate && !reduce.matches ? [...document.querySelectorAll('.timeline .slot')].filter((s) => s.offsetParent !== null) : [];
    // 行の高さと、行の上端から見たバンド名の位置（名前は中央へ飛ばずに、元の位置からスライドさせる）
    const measure = (s) => {
      const r = s.getBoundingClientRect();
      const n = s.querySelector('.slot__name');
      return { h: r.height, nameY: n ? n.getBoundingClientRect().top - r.top : 0 };
    };
    const before = slots.map(measure);
    document.querySelectorAll('.timeline').forEach((t) => t.classList.toggle('timeline--compact', on));
    slots.forEach((s, i) => {
      const after = measure(s);
      if (Math.abs(after.h - before[i].h) < 1) return; // 休憩の行など、高さが変わらないものは動かさない
      const opt = { duration: 280, easing: 'ease' };
      s.animate([{ height: before[i].h + 'px', overflow: 'hidden' }, { height: after.h + 'px', overflow: 'hidden' }], opt);
      const name = s.querySelector('.slot__name');
      const dy = before[i].nameY - after.nameY;
      if (name && Math.abs(dy) >= 1) name.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], opt);
    });
    buttons.forEach((b) => {
      b.setAttribute('aria-pressed', String(on));
      const label = on ? '展開' : '縮小';
      b.setAttribute('aria-label', label);
      b.title = label;
      const icon = b.querySelector('.icon');
      if (icon) icon.textContent = on ? 'unfold_more' : 'unfold_less';
    });
  };
  buttons.forEach((b) => b.addEventListener('click', () => {
    const on = b.getAttribute('aria-pressed') !== 'true';
    apply(on, true);
    try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* 覚えられなくても動く */ }
  }));
  try { apply(localStorage.getItem(KEY) === '1'); } catch (e) { /* 同上 */ }
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
      if (e.defaultPrevented) return; // 確認ダイアログでキャンセルされたなど
      const files = [...form.querySelectorAll('input[type=file]')].flatMap((i) => [...i.files]);
      const images = files.filter((f) => /\.(jpe?g|png|webp|gif)$/i.test(f.name)).length;
      if (images > 0) {
        form.querySelector('[data-loading-title]').textContent = `解析中…`;
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
      allowLeave();
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
      allowLeave();
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
      const el = document.createElement('div');
      el.className = 'flash flash--error';
      el.setAttribute('role', 'status');
      el.textContent = text;
      showToast(el);
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
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  // アニメーションが終わったら消す（動きを減らす設定のときは、すぐ消す）
  const leave = (el) => {
    if (reduce.matches) { el.remove(); return; }
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
    fetch('api_check_dismiss', { method: 'POST', headers: { 'X-CSRF-Token': token }, body, keepalive: true }).catch(() => {});
    const item = btn.closest('[data-check-item]');
    if (list.querySelectorAll('[data-check-item]').length === 1) leave(list); else leave(item);
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
    document.body.append(pop);

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

/* ---------------------------------------------------------------------
 * お気に入り（❤）（live.php のタイムテーブル / band.php のバンド名の右）
 *   data-like="バンドID" のボタンを押すと、通信の結果を待たずに先に見た目を変える（赤くして数を +1 / 戻して -1）。
 *   そのあと api_band_like.php に送り、返ってきた本当の数と状態で書き直す（ほかの人が同時に押した分もここで反映）。
 *   失敗したら押す前の見た目に戻す。同じバンドのボタンが1ページに複数あっても全部そろえる
 * ------------------------------------------------------------------- */
function setupLikes() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const pending = new Set(); // 通信中のバンドID（結果が返るまで同じバンドの2回目は受け付けない）

  // 同じバンドの ❤ を全部、liked / count の見た目にする
  //   data-like-api … 送り先の API（無ければバンド用の api_band_like）。掲示板の書き込みは api_board_like。
  //   バンドと書き込みで ID が重なっても混ざらないよう、同じ API のボタンだけそろえる
  const apiOf = (b) => b.dataset.likeApi || 'api_band_like';
  const KEYS = { api_band_like: 'band_id', api_board_like: 'post_id', api_comment_like: 'comment_id' };
  const paint = (api, id, liked, count) => {
    document.querySelectorAll(`[data-like="${id}"]`).forEach((b) => {
      if (apiOf(b) !== api) return;
      b.classList.toggle('is-liked', liked);
      b.setAttribute('aria-pressed', String(liked));
      b.setAttribute('aria-label', `お気に入り（${count}）`);
      b.querySelector('[data-like-count]').textContent = count;
    });
  };

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-like]');
    if (!btn) return;
    const id = btn.dataset.like;
    const api = apiOf(btn);
    const key = api + ':' + id;
    if (!KEYS[api]) return;
    if (pending.has(key)) return;
    pending.add(key);

    // 1. 先に見た目を変える（押した瞬間に数字が動く）
    const before = { liked: btn.classList.contains('is-liked'), count: Number(btn.querySelector('[data-like-count]').textContent) };
    const liked = !before.liked;
    paint(api, id, liked, Math.max(0, before.count + (liked ? 1 : -1)));
    if (liked) { // 付けたときだけハートをぽんと弾ませる
      btn.classList.remove('is-pop');
      void btn.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
      btn.classList.add('is-pop');
    }

    // 2. サーバーに送って、本当の値で書き直す
    try {
      const res = await fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ [KEYS[api]]: Number(id) }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || '保存できませんでした');
      paint(api, id, data.liked, data.count);
    } catch (err) {
      console.error('お気に入りの通信エラー:', err);
      paint(api, id, before.liked, before.count); // 失敗したので押す前に戻す
      const toast = document.createElement('div');
      toast.textContent = 'お気に入りを保存できませんでした。ページを再読み込みしてやり直してください';
      showToast(toast);
    } finally {
      pending.delete(key);
    }
  });
}

/* ---------------------------------------------------------------------
 * チュートリアル（[data-tutorial-dialog]。ログイン中は全ページに置いてある: partials/tutorial.php）
 *   まだ見ていない人（data-tutorial-auto）には自動で開く。右上メニューなどの [data-tutorial-open] を押しても開く。
 *   次へ / 戻る / 下の点 / ← → キー / 左右スワイプでページを送る。最後のページの「次へ」は「はじめる」になって閉じる。
 *   自動で開いた回を閉じたら（スキップ・Esc も）api_tutorial_seen に「見た」と送る → 次からは自動では出ない
 * ------------------------------------------------------------------- */
function setupTutorial() {
  const dialog = document.querySelector('[data-tutorial-dialog]');
  if (!dialog) return;
  const slides = [...dialog.querySelectorAll('[data-tutorial-slide]')];
  const dots = [...dialog.querySelectorAll('[data-tutorial-dot]')];
  const prev = dialog.querySelector('[data-tutorial-prev]');
  const next = dialog.querySelector('[data-tutorial-next]');
  const last = slides.length - 1;
  let page = 0;

  const show = (i) => {
    page = Math.max(0, Math.min(last, i));
    slides.forEach((s, j) => { s.hidden = j !== page; });
    dots.forEach((d, j) => d.toggleAttribute('aria-current', j === page));
    dialog.setAttribute('aria-labelledby', `tutorial-title-${page}`);
    prev.hidden = page === 0;
    // 中身を textContent で入れ替えるとアイコン（<span class="icon">）が消えるので、文字の部分だけ変える
    next.firstChild.textContent = page === last ? 'はじめる' : '次へ';
    next.querySelector('.icon')?.toggleAttribute('hidden', page === last);
  };

  prev.addEventListener('click', () => show(page - 1));
  next.addEventListener('click', () => (page === last ? dialog.close() : show(page + 1)));
  dialog.querySelector('[data-tutorial-skip]').addEventListener('click', () => dialog.close());
  dots.forEach((d) => d.addEventListener('click', () => show(Number(d.dataset.tutorialDot))));
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') show(page + 1);
    if (e.key === 'ArrowLeft') show(page - 1);
  });
  // スマホ: 横に 50px 以上なぞったらページ送り（縦のスクロールと区別するため、横の動きが縦より大きいときだけ）
  let startX = 0, startY = 0;
  dialog.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; startY = e.touches[0].clientY; }, { passive: true });
  dialog.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - startX;
    const dy = e.changedTouches[0].clientY - startY;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(page + (dx < 0 ? 1 : -1));
  });
  // 外側（背景）をクリックしても閉じる。<dialog> 自体がクリックされた = 中身の外側
  dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

  dialog.addEventListener('close', () => {
    if (!dialog.hasAttribute('data-tutorial-auto')) return; // 見たことがある人がメニューから開いた回は、送らなくていい
    dialog.removeAttribute('data-tutorial-auto');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    // keepalive: 閉じてすぐ別のページへ移っても送信が途中で切られない
    fetch('api_tutorial_seen', { method: 'POST', headers: { 'X-CSRF-Token': token }, keepalive: true }).catch(() => {});
  });

  const open = () => {
    // 画像は loading="lazy"。隠れているページの画像は読み込まれないので、開いた時点で全部読み込ませる（ページを送ったときに白く光らない）
    dialog.querySelectorAll('img[loading="lazy"]').forEach((img) => { img.loading = 'eager'; });
    show(0);
    if (!dialog.open) dialog.showModal();
    next.focus();
  };
  document.querySelectorAll('[data-tutorial-open]').forEach((btn) => btn.addEventListener('click', () => {
    btn.closest('details')?.removeAttribute('open'); // 右上メニューは閉じておく
    open();
  }));
  if (dialog.hasAttribute('data-tutorial-auto')) open();
}

/**
 * アーティストの編集欄の「AI で読みを入れる」（artist.php → api_artist_kana.php）。
 * 名前欄の今の値を送って、返ってきた読みをふりがな欄に入れるだけ。保存は「名前・ふりがなを変更」で人がする
 */
function setupKanaAi() {
  const btn = document.querySelector('[data-kana-ai]');
  if (!btn) return;
  const form = btn.closest('form');
  const nameInput = form.querySelector('input[name="name"]');
  const kanaInput = form.querySelector('input[name="name_kana"]');
  const status = form.querySelector('[data-kana-ai-status]');
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  btn.addEventListener('click', async () => {
    const name = nameInput.value.trim();
    if (!name) return;
    btn.disabled = true;
    status.textContent = 'AI に聞いています…';
    try {
      const res = await fetch('api_artist_kana', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ name }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.kana) throw new Error(data.error || 'AI の呼び出しに失敗しました');
      kanaInput.value = data.kana;
      // 値を JS で入れただけでは input イベントが出ない → 未保存の警告（form[data-unsaved]）が気づけるように出す
      kanaInput.dispatchEvent(new Event('input', { bubbles: true }));
      status.textContent = '入れました。確かめてから保存してください';
    } catch (e) {
      status.textContent = e.message;
    } finally {
      btn.disabled = false;
    }
  });
}

/**
 * プレビューの浮いた枠を、PC ではウィンドウのように動かせて、右下の角で大きさも変えられるようにする
 * （セッティングシート作成 setting_sheet.js・型紙エディタ sheet_editor.js）。スマホは今のまま（画面いっぱい）。
 *   上に「つかむバー」を足す。位置と大きさは localStorage（key）に覚えて、次に開いたときも同じ所に出す
 *   onResize: 大きさが変わったとき（中の PDF の幅を合わせ直す用）
 *   開いたあとに呼ぶ place() を返す（hidden の間は大きさが測れないので、開くたびに呼んでもらう）
 */
function floatWin(box, key, onResize = () => {}) {
  const pc = window.matchMedia('(min-width: 761px)');
  const bar = document.createElement('div');
  bar.className = 'float-win__bar';
  bar.innerHTML = '<span class="icon" aria-hidden="true">drag_indicator</span>';
  bar.title = 'ドラッグで移動';
  box.prepend(bar);
  box.classList.add('float-win');
  const load = () => { try { return JSON.parse(localStorage.getItem(key)); } catch { return null; } };
  const store = () => {
    if (!pc.matches || box.hidden) return;
    const r = box.getBoundingClientRect();
    try { localStorage.setItem(key, JSON.stringify({ x: r.left, y: r.top, w: r.width, h: r.height })); } catch { /* 覚えられなくても動く */ }
  };
  // 画面からはみ出さないように収めて、left / top / width / height で置く（right / bottom の指定は消す）
  const put = (x, y, w, h) => {
    w = Math.min(Math.max(w, 260), innerWidth - 16);
    h = Math.min(Math.max(h, 200), innerHeight - 16);
    x = Math.min(Math.max(x, 8), innerWidth - w - 8);
    y = Math.min(Math.max(y, 8), innerHeight - h - 8);
    Object.assign(box.style, { left: `${x}px`, top: `${y}px`, width: `${w}px`, height: `${h}px`, right: 'auto', bottom: 'auto' });
  };
  function place() {
    if (!pc.matches) {
      box.removeAttribute('style'); // スマホは CSS の置き方に戻す
      return;
    }
    const s = load();
    if (s) {
      put(s.x, s.y, s.w, s.h);
    } else {
      const r = box.getBoundingClientRect(); // CSS の初期位置（右側）のまま、動かせる形にする
      put(r.left, r.top, r.width, r.height);
    }
  }
  let drag = null;
  bar.addEventListener('pointerdown', (e) => {
    if (!pc.matches) return;
    e.preventDefault();
    const r = box.getBoundingClientRect();
    drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, w: r.width, h: r.height };
    bar.setPointerCapture(e.pointerId);
    box.classList.add('is-moving'); // 中の iframe にマウスを取られないように
  });
  bar.addEventListener('pointermove', (e) => {
    if (drag) put(e.clientX - drag.dx, e.clientY - drag.dy, drag.w, drag.h);
  });
  const end = () => {
    if (!drag) return;
    drag = null;
    box.classList.remove('is-moving');
    store();
  };
  bar.addEventListener('pointerup', end);
  bar.addEventListener('pointercancel', end);
  // 右下の角（CSS の resize）で大きさを変えたとき
  let timer = 0;
  new ResizeObserver(() => {
    if (box.hidden) return;
    onResize();
    clearTimeout(timer);
    timer = setTimeout(store, 300);
  }).observe(box);
  pc.addEventListener('change', place);
  return place;
}

/* -------------------------------------------------------------------
 * ライブの添付画像
 *   ライブページ: サムネイル（[data-live-img]）を押すと <dialog> で大きく表示。ダウンロードボタン付き。
 *   編集画面: 5枚の上限を数える。追加する枚数が超えるとき・5枚のときは追加ボタンを無効にして知らせる
 * ------------------------------------------------------------------- */
function setupLiveImages() {
  const modal = document.querySelector('[data-live-modal]');
  if (modal) {
    const img = modal.querySelector('[data-live-modal-img]');
    const taken = modal.querySelector('[data-live-modal-taken]');
    const dl = modal.querySelector('[data-live-modal-dl]');
    let clearTimer = 0;
    document.querySelectorAll('[data-live-img]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.liveImg;
        const base = btn.dataset.liveSrc || 'live_image?id='; // 掲示板・お知らせ・バンドは attach_image?k=…&id=
        if (btn.dataset.liveFile) { location.href = `${base}${id}&dl=1`; return; } // TAB 譜の PDF 以外のファイル: ダウンロードだけ
        clearTimeout(clearTimer); // 閉じてすぐ開き直したとき、前の「外す」予約で画像が消えないように
        const pdf = !!btn.dataset.livePdf; // TAB 譜に添付した PDF は iframe でプレビュー
        const frame = modal.querySelector('[data-live-modal-pdf]');
        img.hidden = pdf;
        if (frame) {
          frame.hidden = !pdf;
          if (pdf) frame.src = `${base}${id}`; else frame.removeAttribute('src');
        }
        if (pdf) img.removeAttribute('src'); else img.src = `${base}${id}`;
        dl.href = `${base}${id}&dl=1`;
        taken.textContent = btn.dataset.taken || '';
        modal.showModal();
      });
    });
    modal.querySelector('[data-live-modal-close]').addEventListener('click', () => modal.close());
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
    // 閉じるアニメーション（.modal の transition 0.2秒）が終わってから画像を外す。すぐ外すと、枠が縮む前に画像だけ消える
    modal.addEventListener('close', () => {
      clearTimer = setTimeout(() => { img.removeAttribute('src'); modal.querySelector('[data-live-modal-pdf]')?.removeAttribute('src'); }, 250);
    });
  }

  document.querySelectorAll('[data-live-img-edit]').forEach(setupLiveImageEdit);
}

function setupLiveImageEdit(edit) {
  const max = Number(edit.dataset.max);
  const input = edit.querySelector('[data-live-img-input]');
  const note = edit.querySelector('[data-live-img-note]');
  const addBtn = edit.querySelector('.live-img-edit__add');
  const list = edit.querySelector('.live-img-edit__list');
  // 選んだ画像をためておく入れ物。ファイル選択は選ぶたびに前の選択が消えるので、ここに足していって、input.files に入れ直す
  const picked = new DataTransfer();
  const pending = []; // 追加予定のプレビュー（✕で取り消せる）
  const shown = new WeakSet(); // もう出したファイル。新しく足した分だけアニメーションさせる（作り直しで全部が動かないように）
  const sync = () => {
    input.files = picked.files;
    pending.splice(0).forEach((el) => { URL.revokeObjectURL(el.dataset.url); el.remove(); });
    [...picked.files].forEach((f, i) => {
      const url = URL.createObjectURL(f);
      const el = document.createElement('button');
      el.type = 'button';
      el.className = 'live-img-edit__item live-img-edit__item--new' + (shown.has(f) ? '' : ' is-pop');
      shown.add(f);
      el.dataset.url = url;
      el.setAttribute('aria-label', '追加を取り消し');
      el.innerHTML = `<img src="${url}" alt=""><span class="live-img-edit__x"><span class="icon" aria-hidden="true">close</span></span>`;
      el.addEventListener('click', () => {
        const next = new DataTransfer();
        [...picked.files].forEach((g, j) => { if (j !== i) next.items.add(g); });
        picked.items.clear();
        [...next.files].forEach((g) => picked.items.add(g));
        sync();
      });
      list.appendChild(el);
      pending.push(el);
    });
    update();
  };
  const room = () => max - edit.querySelectorAll('[data-live-img-remove]:not(:checked)').length - picked.files.length;
  function update() {
    const left = room();
    addBtn.classList.toggle('is-disabled', left <= 0);
    note.textContent = '';
  }
  input.addEventListener('change', () => {
    const over = [...input.files].length > room();
    // 今回選んだ分を足す（上限を超える分は入れない）
    for (const f of [...input.files]) { if (room() > 0) picked.items.add(f); }
    sync();
    if (over) note.textContent = `${max} 枚までです`;
  });
  // 削除のチェックを外すと空きが減るので数え直す
  edit.querySelectorAll('[data-live-img-remove]').forEach((c) => c.addEventListener('change', update));
  update();
}

/* 名義の入力欄: 匿名にチェックが入っているあいだは入力できない。送信した名義は次回の初期値になる（このブラウザだけ） */
function setupAliasField() {
  const KEY = 'abbey.alias';
  document.querySelectorAll('[data-alias-field]').forEach((box) => {
    const name = box.querySelector('input[type="text"]');
    const anon = box.querySelector('input[type="checkbox"]');
    try { name.value = localStorage.getItem(KEY) ?? ''; } catch (e) { /* 保存が使えなくても入力はできる */ }
    box.closest('form')?.addEventListener('submit', () => {
      if (anon.checked) { return; } // 匿名のときは名義を送らないので、前回の名義を残す
      try { localStorage.setItem(KEY, name.value.trim()); } catch (e) { /* 同上 */ }
    });
    const sync = () => { name.disabled = anon.checked; };
    anon.addEventListener('change', sync);
    sync();
    // 説明: ⓘ を押すと開閉。外を押しても閉じる
    const btn = box.querySelector('[data-alias-info]');
    const pop = box.querySelector('.alias-field__pop');
    const toggle = (open) => { pop.hidden = !open; btn.setAttribute('aria-expanded', String(open)); };
    btn.addEventListener('click', (e) => { e.stopPropagation(); toggle(pop.hidden); });
    btn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(pop.hidden); } });
    document.addEventListener('click', () => toggle(false));
  });
}
