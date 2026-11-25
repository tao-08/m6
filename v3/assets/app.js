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
 *    data-fit-zoom      … 表の全体を枠に収め、ピンチ・Ctrl+ホイール・ダブルタップで拡大縮小（バンド希望調査）
 *    data-loading       … 送信したら「読み込み中」を出す（取り込みの AI 読み取りは数十秒かかる）
 *    <select>           … 全部のプルダウンをボタン + ポップアップの見た目にする（data-native で元のまま）
 *    data-song-list / data-add-song … 曲の編集
 *    data-track-search  … 曲の編集の🔍（Spotify / iTunes の曲を探して紐付ける）
 *    data-preview       … バンドページのセットリストの ▶（30秒試聴）
 *    data-toasts        … お知らせのポップアップ（4秒で消える）
 *    data-album-tip     … メンバー一覧のジャケットに乗せるとアルバム名・アーティスト名を出す
 *    data-tip           … 共通のポップアップ。PC は乗せる／スマホはタップでフェード表示（外れたらすぐ消える）。
 *                          PHP では tip_attr('文') を使う（title は使わない）。セトリの ✓・楽器ラベル・メダルなど全部これ
 *    open.spotify.com のリンク … Spotify のアプリで開く（PC はデスクトップアプリ）
 *    data-vo-sum-toggle … 統計の「Voを合算する」をページ移動なしで切り替える
 *    data-search-suggest … 検索ページの入力欄で検索語の候補を出す（api_search_suggest.php。メンバー・バンド・アーティスト・ライブ・会場・スタジオ・機材・スペシャルコンテンツ・係・学部・曲名）
 *    data-autofocus-pc  … PC だけ、開いたとき入力欄にカーソルを置く（スマホはキーボードが出てアニメが途切れるので置かない）
 *    data-auto-open     … <dialog> をページを開いたときに自動で開く（日程調整の「予約のうながし」など）
 * =====================================================================
 *
 *  ファイルの分け方（全部 <script defer> で app.js の後に読み込む。setup◯◯ の呼び出しは下の DOMContentLoaded に集めてある）:
 *    app.js           … 目印の一覧・起動・共通の小さな部品
 *    app_ui.js        … ポップアップ・リンク・通知・戻るボタン・テーマ・ホーム画面・プッシュ通知
 *    app_songs.js     … 曲の編集・曲の紐付け・歌詞検索・30秒試聴
 *    app_board.js     … 掲示板・コメント・お気に入り
 *    app_list.js      … 一覧の絞り込み・検索候補・スロット・並び替え
 *    app_player.js    … 音源・動画のプレーヤー
 *    app_form.js      … フォームの部品（確認・未保存の警告・プルダウン・メンバー行・入力候補）
 *    app_import.js    … 取り込み・ライブ編集
 *    app_timetable.js … タイムテーブル・名簿の編集
 *    app_album.js     … アルバム
 *    app_tutorial.js  … チュートリアル
 *    app_admin.js     … 管理・編集の補助
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
  setupMixFilter();
  setupHideFar();
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
  setupAlbumModal();
  setupAlbumSearch();
  setupAlbumAdd();
  setupRosterColumns();
  setupTimetableColumns();
  setupTimetableFollow();
  setupPicks();
  setupLiveImages();
  setupFitZoom(); // 表を縮めてから、はみ出しているか（つかめるか）を setupDragScroll が見る
  setupDragScroll();
  setupPackedForm();
  setupMemberRows();
  setupSuggest();
  setupSmallThings();
  setupBoardReply();
  setupComments();
  setupAliasField();
  setupInstrumentHex();
  setupInstrumentPreview();
  setupInstrumentAutosave();
  setupInstrumentColorPicker();
  setupBoardPanels();
  setupBoardPoll();
  setupBoardMute();
  setupBoardReport();
  setupBoardTabs();
  setupVenueSuggest();
  setupSongs();
  setupTrackSearch();
  setupLyricsSearch();
  setupPreview();
  setupLikes();
  setupAlbumTip();
  setupTip();
  setupTabGlide();
  setupSetlistNames();
  setupSpotifyAppLinks();
  setupExternalLinksToBrowser(); // Spotify の処理より後に登録する（先に処理されたリンクは defaultPrevented で見分ける）
  setupSongModalLinks();
  setupVoSum();
  setupAutofocusPc();
  setupSearchSuggest();
  setupYoutubeLeftover();
  setupDrivePlayer();
  setupDriveWarmup();
  setupDriveSwitch();
  setupAudioPlayers();
  setupBackButton();
  setupTutorial();
  setupAutoDialog(); // チュートリアルが自動で開いたあとに呼ぶ（開いているかを見て、閉じるのを待つため）
  setupAddToHome();
  setupServiceWorker();
  setupPush();
  setupPushLogout();
  setupKanaAi();
  setupArtistAutosave();
});

/* ---------------------------------------------------------------------
 * 共通の小さな部品（app.js は全ページで先に読み込まれるので、practice.js / songs.js / tab.js / ytplayer.js /
 *   studio.js / tt_builder.js からもそのまま呼べる）
 * ------------------------------------------------------------------- */

/** CSRF トークン（<meta name="csrf-token">） */
/** 「動きを減らす」設定の人か（animateIn・タブの滑りなど、アニメーションの前に見る） */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

/** JSON を POST する（CSRF ヘッダー付き）。Response をそのまま返す（エラーの扱いは呼び出し側）。opts は fetch に足す設定（keepalive など） */
function postJson(url, body, opts = {}) {
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(body),
    ...opts,
  });
}

/** postJson して JSON を返す。失敗（!ok）は json.error（無ければ fallback）の Error を投げる */
async function apiPost(url, body, fallback, opts) {
  const res = await postJson(url, body, opts);
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json.error || fallback);
  return json;
}

/** 音を一瞬（ms）でフェードアウトしてから止める。フェード中に止め直す・鳴らし直すときは cancelFade で音量を戻す */
const volumeLocked = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iOS は audio.volume を変えられない
function fadePause(audio, ms = 150) {
  if (audio.paused) return;
  if (volumeLocked) {
    // Web Audio の GainNode で下げる。別サイトの音は CORS が無いと無音になるので、同じサイトの音か crossOrigin を付けた音だけ（それ以外はすぐ止める）
    if (!audio.crossOrigin && !audio.currentSrc.startsWith(location.origin)) { audio.pause(); return; }
    if (!audio._gain) {
      const ctx = new AudioContext();
      audio._gain = ctx.createGain();
      ctx.createMediaElementSource(audio).connect(audio._gain).connect(ctx.destination);
    }
    const g = audio._gain;
    clearTimeout(audio._fade);
    audio._gain.context.resume();
    g.gain.cancelScheduledValues(0);
    g.gain.setValueAtTime(g.gain.value, g.context.currentTime);
    g.gain.linearRampToValueAtTime(0, g.context.currentTime + ms / 1000);
    audio._fade = setTimeout(() => { audio.pause(); cancelFade(audio); }, ms);
    return;
  }
  if (audio._fade === undefined) audio._v = audio.volume;
  clearInterval(audio._fade);
  const t0 = performance.now();
  audio._fade = setInterval(() => {
    const k = Math.min(1, (performance.now() - t0) / ms);
    audio.volume = audio._v * (1 - k);
    if (k === 1) { audio.pause(); cancelFade(audio); }
  }, 16);
}
function cancelFade(audio) {
  if (audio._fade === undefined) return;
  clearInterval(audio._fade);
  audio._fade = undefined;
  if (audio._gain) { audio._gain.gain.cancelScheduledValues(0); audio._gain.gain.value = 1; } else audio.volume = audio._v;
}

/** 要素を作る: mkEl('div', 'クラス', '文字')。文字は textContent に入る */
function mkEl(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
}

/** 要素を作る: mkNode('div', {class: 'x', text: '文字', onclick: fn}, 子要素…)。text は textContent に入るので HTML として解釈されない */
function mkNode(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  Object.entries(attrs).forEach(([k, val]) => {
    if (val == null || val === false) return;
    if (k === 'class') e.className = val;
    else if (k === 'text') e.textContent = val;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), val);
    else e.setAttribute(k, val === true ? '' : val);
  });
  children.flat().forEach((c) => { if (c != null && c !== false) e.append(c instanceof Node ? c : String(c)); });
  return e;
}

/** 固定位置のポップアップ(.album-tip)を anchor の真上に置く。上に入らないときは下。左右は画面からはみ出さないように寄せる */
function placeTip(tip, anchor) {
  // 測る前に左上へ戻す（前に画面の右端で出した位置のままだと、position: fixed の幅が端までの残りに縮んで、文字が折り返してしまう）
  tip.style.left = '0px';
  tip.style.top = '0px';
  const r = anchor.getBoundingClientRect();
  const t = tip.getBoundingClientRect();
  let top = r.top - t.height - 8;
  if (top < 8) top = r.bottom + 8;
  const left = Math.min(Math.max(8, r.left + r.width / 2 - t.width / 2), window.innerWidth - t.width - 8);
  tip.style.top = `${top}px`;
  tip.style.left = `${left}px`;
}

/** スマホでポップアップが自動で消えるまでの時間（文字数に比例。1500ms + 1文字100ms、最短2.5秒・最長10秒） */
const tipHideMs = (tip) => Math.min(10000, Math.max(2500, 1500 + tip.textContent.length * 100));
