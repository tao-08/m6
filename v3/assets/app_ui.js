/*
 * app_ui.js — 共通の画面部品（ポップアップ・リンク・通知・戻るボタン・テーマ・ホーム画面・プッシュ通知ほか）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

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

  const canHover = window.matchMedia('(hover: hover)'); // マウスがある端末か（スマホは false）
  let current = null; // いま出しているジャケット
  let autoTimer = 0;  // スマホ用: 一定時間たったら自動で消すタイマー

  const show = (img) => {
    clearTimeout(autoTimer); // 別のジャケットを続けて押したとき、前のタイマーを引き継がない
    current = img;
    tip.querySelector('.album-tip__title').textContent = img.dataset.tipTitle;
    tip.querySelector('.album-tip__artist').textContent = img.dataset.tipArtist;
    tip.classList.add('is-visible');
    placeTip(tip, img); // ジャケットの真上に出す
    if (!canHover.matches) autoTimer = setTimeout(hide, tipHideMs(tip)); // スマホだけ、時間がたつと自動で消す
  };
  const hide = () => {
    clearTimeout(autoTimer); // 手動で消したら、残っているタイマーも止める
    tip.classList.remove('is-visible'); // フェードは CSS（.album-tip の transition）がやる
    current = null;
  };

  // mouseover / focusin は子要素から親へ伝わる（バブリング）ので、document に1個付けるだけで全部のジャケットに効く。
  // スマホはタップすると mouseover / focusin も自動で起きる（互換のための動き）。
  // それを拾うと、直後の click（下）が「同じジャケットをもう一度押した」と判定されて、出した瞬間に消えてしまう。
  // → マウスがある端末だけで拾う。スマホは click だけで扱う
  ['mouseover', 'focusin'].forEach((type) => document.addEventListener(type, (e) => {
    if (!canHover.matches) return;
    const img = e.target.closest('[data-album-tip] [data-tip-title]');
    if (img) show(img); else hide();
  }));
  // スマホは押したら出す。同じジャケットをもう一度押すか、他の所を押すと消える
  document.addEventListener('click', (e) => {
    if (canHover.matches) return; // PC は上の mouseover で足りる
    const img = e.target.closest('[data-album-tip] [data-tip-title]');
    if (!img) { hide(); return; }
    if (img === current) hide(); else show(img);
  });
  // fixed なのでスクロールするとずれる → 隠す。capture: true で、ページだけでなく表の横スクロールでも隠す
  document.addEventListener('scroll', hide, { passive: true, capture: true });
}

/* ---------------------------------------------------------------------
 * アプリ化（ホーム画面に追加）した Android・iPhone で、このサイト以外へのリンクを押したら、Chrome で開く
 *   iPhone は googlechrome(s):// で Chrome を開く（Chrome が無ければ今までどおりアプリ内で開く）
 *   何もしないと、アプリの中の小さなブラウザ（Chrome Custom Tab）で開いてしまう。
 *   → Android の intent:// の URL にして、OS に開く先（標準のブラウザ。YouTube などはそのアプリ）を選ばせる。
 *   アプリとして開いている時（display-mode: standalone）の Android だけ。PC・iPhone・ふつうのブラウザは今までどおり。
 * ------------------------------------------------------------------- */
function setupExternalLinksToBrowser() {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const android = /Android/i.test(navigator.userAgent);
  const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent) && !/CriOS/i.test(navigator.userAgent); // iOS 版 Chrome から追加した分は対象外
  if (!standalone || !(android || ios)) return;
  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    const a = e.target.closest('a[href]');
    if (!a) return;
    let u;
    try { u = new URL(a.href, window.location.href); } catch { return; }
    if (!/^https?:$/.test(u.protocol) || u.origin === window.location.origin) return;
    e.preventDefault();
    if (ios) {
      // googlechrome(s)://ホスト/パス で Chrome を開く。入っていなくて 1.5 秒たってもこのページが見えたままなら、元のリンクを開く
      let left = false;
      const onLeave = () => { left = true; };
      document.addEventListener('visibilitychange', onLeave, { once: true });
      window.addEventListener('pagehide', onLeave, { once: true });
      window.location.href = `googlechrome${u.protocol === 'https:' ? 's' : ''}://${u.href.slice(u.protocol.length + 2)}`;
      setTimeout(() => {
        document.removeEventListener('visibilitychange', onLeave);
        window.removeEventListener('pagehide', onLeave);
        if (!left && document.visibilityState === 'visible') window.location.href = u.href;
      }, 1500);
      return;
    }
    // intent://ホスト/パス#Intent;scheme=https;…;end（S.browser_fallback_url = 開けなかったときの飛び先）
    const rest = u.href.replace(/#.*$/, '').slice(u.protocol.length + 2); // 「//」より後ろ（# 以降は intent の区切りとぶつかるので落とす）
    window.location.href = `intent://${rest}#Intent;scheme=${u.protocol.slice(0, -1)};action=android.intent.action.VIEW;category=android.intent.category.BROWSABLE;package=com.android.chrome;S.browser_fallback_url=${encodeURIComponent(u.href)};end`;
  });
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
 * セトリなどの曲名（songs?open=曲キー）を押したとき、ページ移動せずに、そのページの上に曲カードを開く
 *   楽曲データベースへ移動すると 100 曲ぶんの一覧ごと読み込むので重い。ここでは次の2つだけ取る:
 *     1. 曲カードの見た目と動き（tab.css / songs.css / songs.js）… 最初に使うときだけ読み込む（<meta name="songs-modal-assets">）
 *     2. その曲1つぶんの HTML（songs?modalfor=曲キー）
 *   リンクに乗せた・触れた時点で先に取り始めるので、押したときにはほぼ揃っている。
 *   取れなかったら、リンクの本来の動き（ページ移動）に切り替える。Ctrl+クリックなど（別タブで開きたい）はそのままブラウザに任せる。
 *   サーバーが h() で無害化した HTML だけを <template> 経由で差し込む（スクリプトは動かさない）。
 * ------------------------------------------------------------------- */
function setupSongModalLinks() {
  const assets = (document.querySelector('meta[name="songs-modal-assets"]')?.content || '').split(' ').filter(Boolean);
  if (assets.length !== 3 || document.querySelector('[data-songs-results]')) return; // ログインしていない / 楽曲データベース自身では不要
  const [tabCss, songsCss, songsJs] = assets;
  let assetsReady = null;
  const loadAssets = () => assetsReady ??= new Promise((resolve, reject) => {
    for (const href of [tabCss, songsCss]) {
      const l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = href;
      document.head.appendChild(l);
    }
    const sc = document.createElement('script');
    sc.src = songsJs;
    sc.onload = resolve;
    sc.onerror = () => { assetsReady = null; reject(new Error('songs.js')); };
    document.head.appendChild(sc);
  });
  const modals = new Map(); // 曲キー → 取得中 / 取得済みの Promise<dialog>（同じ曲を何度押しても1回だけ取る）
  const fetchModal = (key) => {
    if (!modals.has(key)) {
      const job = fetch('songs?modalfor=' + encodeURIComponent(key), { credentials: 'same-origin' })
        .then((res) => { if (!res.ok) throw new Error(); return res.text(); })
        .then(async (html) => {
          const tpl = document.createElement('template');
          tpl.innerHTML = html.trim();
          const modal = tpl.content.firstElementChild;
          if (!modal || !modal.matches('[data-song-modal]')) throw new Error();
          await loadAssets(); // bindSongModal（songs.js）が要る
          document.body.appendChild(modal);
          window.bindSongModal(modal);
          return modal;
        });
      job.catch(() => modals.delete(key)); // 失敗は覚えない（次に押したとき取り直す）
      modals.set(key, job);
    }
    return modals.get(key);
  };
  const keyOf = (e) => {
    const a = e.target.closest?.('a[href^="songs?open="]');
    if (!a) return null;
    const key = new URL(a.href, location.href).searchParams.get('open');
    return key ? { a, key } : null;
  };
  const prefetch = (e) => { const hit = keyOf(e); if (hit) fetchModal(hit.key).catch(() => {}); };
  document.addEventListener('mouseover', prefetch, { passive: true });
  document.addEventListener('touchstart', prefetch, { passive: true });
  document.addEventListener('click', async (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    const hit = keyOf(e);
    if (!hit) return;
    e.preventDefault();
    try {
      (await fetchModal(hit.key)).showModal();
    } catch {
      window.location.href = hit.a.href; // 取れなかったら今までどおり楽曲データベースへ移動
    }
  });
}

/* ---------------------------------------------------------------------
 * タブ（.tabs > .tab）の選択中の赤い丸を、選んだタブへ滑らせる（全ページ共通の規格）
 *   丸は .tabs__glide を1個足して動かす。選択中のタブ自身の背景は消す（app.css の「タブの丸」）。
 *   選択中 = .is-active（リンク・JS で切り替えるボタン）か、中のラジオが checked（絞り込みの label）。
 *   JS で is-active を付け替えるタブ（日程調整・通知など）も MutationObserver で拾うので、各ページで呼ぶ必要はない。
 *   表示されていない間（幅 0）は置かず、見えたとき（ResizeObserver）に置く。最初の1回は動かさずに置く。
 * ------------------------------------------------------------------- */
function setupTabGlide() {
  document.querySelectorAll('.tabs').forEach((tabs) => {
    const glide = document.createElement('span');
    glide.className = 'tabs__glide';
    glide.setAttribute('aria-hidden', 'true');
    tabs.append(glide); // 末尾に足す（先頭だと .tab:nth-child(3) の数え方がずれる = app.css の日程タブ）
    // 丸の位置の基準にする。top: auto = .tabs の sticky 用の top: 70px が relative で効いてずれないように
    if (getComputedStyle(tabs).position === 'static') Object.assign(tabs.style, { position: 'relative', top: 'auto' });
    tabs.classList.add('has-glide');
    let placed = false;
    const place = (to = null) => {
      const tab = to ?? tabs.querySelector(':scope > .tab.is-active, :scope > .tab:has(input:checked)');
      if (!tab || !tab.offsetWidth) { glide.style.opacity = '0'; placed = false; return; }
      if (!placed) glide.style.transition = 'none'; // 最初・再表示のときは滑らせない
      glide.style.opacity = '';
      // offset* は整数に丸められて端が 1px 欠けることがあるので、小数まで出る getBoundingClientRect で測る（横スクロール分も足す）
      const r = tab.getBoundingClientRect();
      const box = tabs.getBoundingClientRect();
      const k = box.width / tabs.offsetWidth || 1; // 開くアニメで拡大・縮小している途中（通知メニュー）でも、元の大きさに直して測る
      glide.style.width = `${r.width / k}px`;
      glide.style.height = `${r.height / k}px`;
      glide.style.transform = `translate(${(r.left - box.left) / k - tabs.clientLeft + tabs.scrollLeft}px, ${(r.top - box.top) / k - tabs.clientTop}px)`;
      if (!placed) { glide.offsetWidth; glide.style.transition = ''; placed = true; } // offsetWidth = 位置を確定させてから transition を戻す
    };
    let queued = false;
    const later = () => { if (!queued) { queued = true; requestAnimationFrame(() => { queued = false; place(); }); } };
    tabs.addEventListener('change', later); // ラジオ（label のタブ）
    tabs.addEventListener('click', (e) => {
      const a = e.target.closest('a.tab');
      // リンクのタブ: is-active は次のページで付くので、移動する前に押したタブへ滑らせておく（新しいタブで開くときは動かさない）
      if (a?.parentElement === tabs && !e.defaultPrevented && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0 && a.target !== '_blank') place(a);
      else later();
    });
    new MutationObserver(later).observe(tabs, { subtree: true, attributes: true, attributeFilter: ['class'], childList: true });
    new ResizeObserver(() => { placed = false; later(); }).observe(tabs);
    place();
  });
}

/* ---------------------------------------------------------------------
 * セットリストの ✓（[data-tip]）を押したら「セットリスト登録済（◯曲）」をポップアップで出す
 *   スマホには「マウスを乗せる」が無いので、title では出ない → 押したら出す。もう一度押すか、他の所を押すと消える。
 *   見た目はジャケットのポップアップ（.album-tip）と同じ。1個だけ作って使い回す。
 * ------------------------------------------------------------------- */
function setupTip() {
  // data-tip-lazy … JS があとから data-tip を作るページ（スタジオ割）の印。読み込み時に data-tip が無くても準備する
  if (!document.querySelector('[data-tip], [data-tip-lazy]')) return;
  const tip = document.createElement('div');
  tip.className = 'album-tip';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);
  let current = null; // いま出しているバッジ
  let hideTimer = 0;
  let autoTimer = 0;  // スマホ用: 一定時間たったら自動で消すタイマー

  let swapTimer = 0;  // 表示中に別のバッジへ移るとき、フェードアウトを待つタイマー

  const hide = () => {
    clearTimeout(autoTimer); // 手動で消したら、残っているタイマーも止める（古いタイマーが次のポップアップを消すのを防ぐ）
    clearTimeout(swapTimer);
    tip.classList.remove('is-visible'); // フェードは CSS（.album-tip の transition）がやる。JS は is-visible を外すだけ
    current = null;
  };
  // 出ている最中に別のバッジへ移ったら、いったんフェードアウトしてから中身と位置を替えてフェードインする
  //   （そのまま中身を替えると、位置が瞬間移動するだけでアニメーションにならない）。待ち時間は CSS の transition（.12s）より少し短く
  const show = (badge) => {
    clearTimeout(swapTimer);
    if (current && current !== badge && tip.classList.contains('is-visible')) {
      clearTimeout(autoTimer);
      tip.classList.remove('is-visible');
      current = badge;
      swapTimer = setTimeout(() => render(badge), 100);
    } else {
      render(badge);
    }
  };
  const render = (badge) => {
    clearTimeout(autoTimer); // 別のバッジを続けて押したとき、前のタイマーを引き継がない
    // 中に [data-tip-body]（メンバー一覧の担当楽器「ほか」）があれば、そのバッジを写して出す。
    // サーバーが h() 済みで出した要素を複製するだけなので安全。無ければ文字だけ（textContent なので HTML として解釈されない）
    // data-tip-from="#id" なら、ページ内の別の要素（バンド編集の ⚠ のリンク付きの文）を写す（<button> の中に <a> は置けないため）
    const body = badge.querySelector('[data-tip-body]') || (badge.dataset.tipFrom ? document.querySelector(badge.dataset.tipFrom) : null);
    if (body) {
      const copy = body.cloneNode(true);
      copy.hidden = false;
      tip.replaceChildren(copy);
    } else {
      tip.textContent = badge.dataset.tip;
    }
    tip.classList.toggle('album-tip--parts', !!body);
    tip.classList.toggle('album-tip--link', !!tip.querySelector('a')); // リンクがあるときだけ、ポップアップを押せるようにする
    tip.classList.add('is-visible');
    current = badge;
    placeTip(tip, badge); // バッジの真上に出す

    // スマホ（マウスが無い端末）だけ、時間がたつと自動で消す。
    //   ・中にリンクがあるポップアップは対象外（押す前に消えると困る）
    //   ・長い文章は読み終わる前に消えないよう、文字数に比例して長くする（1500ms + 1文字100ms、最短2.5秒・最長10秒）
    if (!canHover.matches && !tip.querySelector('a')) autoTimer = setTimeout(hide, tipHideMs(tip));
  };

  // PC（マウスがある端末）はマウスを乗せたら出して、外したら消す
  const canHover = window.matchMedia('(hover: hover)');
  document.addEventListener('mouseover', (e) => {
    if (!canHover.matches) return;
    // ポップアップの上にマウスがある間は消さない（中のリンクを押せるように）。
    // バッジ → ポップアップへ動かす途中のすき間で消えないよう、消すのは少し待ってから
    clearTimeout(hideTimer);
    // ポップアップの中に写した楽器ラベルなど（data-tip 付き）には反応しない（中身が入れ替わってしまう）
    const badge = tip.contains(e.target) ? null : e.target.closest('[data-tip]');
    if (badge) { if (badge !== current) show(badge); return; }
    if (!current || tip.contains(e.target)) return;
    // リンクがあるポップアップだけ、押しに行く猶予（250ms）を置く。文字だけのものは外れたらすぐ消す
    if (tip.querySelector('a')) hideTimer = setTimeout(hide, 250); else hide();
  });
  // ウィンドウの外へ出たときは mouseover が起きないので、ここで消す
  document.addEventListener('mouseout', (e) => {
    if (canHover.matches && !e.relatedTarget && !tip.querySelector('a')) hide();
  });

  // スマホは押したら出す。もう一度押すか、他の所を押すと消える
  document.addEventListener('click', (e) => {
    const badge = tip.contains(e.target) ? null : e.target.closest('[data-tip]');
    if (!badge) { if (!tip.contains(e.target)) hide(); return; } // ポップアップの中（リンク）を押したときは消さない
    // <summary> の中のバッジ（よく組むメンバーのメダル）: 押しても一緒に出たバンドを開け閉めしない。
    //   preventDefault() =「ブラウザが本来やる動き（ここでは <details> の開け閉め）を止める」
    if (badge.closest('summary')) e.preventDefault();
    if (canHover.matches) { show(badge); return; } // PC で押しても消さない（乗せたまま押すと消えてしまうので）
    if (badge === current) hide(); else show(badge);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });

  // キーボード操作（Tab でバッジに移動）でも出す。マウスがある端末だけ。
  //   :focus-visible = キーボードで移ったときだけ true（マウスで押したときは false）。
  //   スマホは押すとフォーカスも動くので、拾うと click（上）の「もう一度押した」判定とぶつかって、出した瞬間に消える
  document.addEventListener('focusin', (e) => {
    if (!canHover.matches || !e.target.matches(':focus-visible')) return;
    const badge = e.target.closest('[data-tip]');
    if (badge) show(badge);
  });
  document.addEventListener('focusout', (e) => {
    if (canHover.matches && e.target.closest('[data-tip]') === current) hide();
  });

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
    if (reduceMotion) { toast.remove(); return; }
    toast.classList.add('is-leaving');                          // CSS でフェードアウト
    toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    setTimeout(() => toast.remove(), 500);                      // transitionend が来なかったとき用
  };
  setTimeout(close, TOAST_MS);
  toast.addEventListener('click', close);
}

/** 文字だけのお知らせを出す（cls = 見た目のクラス、role = 読み上げ用） */
function toastText(text, cls = '', role = '') {
  const t = document.createElement('div');
  if (cls) t.className = cls;
  if (role) t.setAttribute('role', role);
  t.textContent = text;
  showToast(t);
}

/** 画面上部のお知らせ（type = 'error' / 'warn' / 省略は成功） */
const flashToast = (message, type) => toastText(message, 'flash flash--' + (type || 'success'), 'status');

/** ttb-toast: 右下に一時的に出す小さなお知らせ（studio.js / tt_builder.js）。action = {label, run} で「元に戻す」などのボタンを付けられる。ms = 消えるまでの時間 */
let actionToastEl = null;
function showActionToast(msg, action, ms) {
  actionToastEl?.remove();
  actionToastEl = mkNode('div', { class: 'ttb-toast', role: 'status' }, mkNode('span', { text: msg }),
    action ? mkNode('button', { type: 'button', class: 'btn btn--ghost btn--sm', text: action.label, onclick: () => { action.run(); actionToastEl?.remove(); } }) : null);
  document.body.append(actionToastEl);
  const mine = actionToastEl;
  setTimeout(() => mine.remove(), action ? 8000 : ms);
}

/* ---------------------------------------------------------------------
 * 自動で開くモーダル（<dialog data-auto-open>）
 *   ページを開いたときに showModal() で開く。閉じるのは中の <form method="dialog"> のボタン・Esc・背景のクリック。
 *   初回のチュートリアル（[data-tutorial-auto]）が同時に開いているときは、それを閉じてから開く（モーダルが重なるのを防ぐ）
 * ------------------------------------------------------------------- */
function setupAutoDialog() {
  const tutorial = document.querySelector('dialog[data-tutorial-auto]');
  document.querySelectorAll('dialog[data-auto-open]').forEach((dialog) => {
    // 外側（背景）をクリックしても閉じる。<dialog> 自体がクリックされた = 中身の外側
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    const open = () => { if (!dialog.open) dialog.showModal(); };
    if (tutorial?.open) tutorial.addEventListener('close', open, { once: true });
    else open();
  });
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
  // 埋め込み表示（モーダルの <iframe> の中。body.is-embed）は、戻るボタンを出さず、戻り先の記録にも入れない（外のページの戻り先が変わらないように）
  if (document.body.classList.contains('is-embed')) return;

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
  // TAB の練習・編集画面（[data-tab]）も出さない: 「拍をタップ」やマーカー追加の上に被る。戻るのは画面上のパンくず（楽曲データベース / 曲名）でできる
  if (document.querySelector('[data-tab]')) items = [];
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

  if (before.length && !reduceMotion) {
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
  if (reduceMotion) shown.forEach((a) => a.classList.remove('is-entering'));
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
  document.querySelectorAll('[data-autosubmit]').forEach((sel) => sel.addEventListener('change', () => submitKeepScroll(sel.form)));
  // 集計の「よく組む学年区分」: 絞り込みで読み直しても選んだ区分を保つ
  document.querySelectorAll('input[name="grade-mix"]').forEach((r) => {
    try {
      if (r.value === sessionStorage.getItem('grade-mix')) r.checked = true;
    } catch (e) { /* 保存できないときは既定のまま */ }
    r.addEventListener('change', () => {
      try { sessionStorage.setItem('grade-mix', r.value); } catch (e) { /* 同上 */ }
    });
  });
  // submitKeepScroll で送ったあとの読み直し: 送る前のスクロール位置に戻す（同じページのときだけ）
  try {
    const saved = JSON.parse(sessionStorage.getItem('keep-scroll') || 'null');
    sessionStorage.removeItem('keep-scroll');
    if (saved && saved.path === location.pathname) {
      // 条件で上の表の行数が変わるので、y そのままではなく「見ていた見出し」が同じ位置に来るように戻す。
      // ほかの setup がレイアウトを変え終わってから（次のフレームで）合わせる
      const restore = () => {
        const anchor = saved.key && keepScrollAnchors().find((el) => keepScrollKey(el) === saved.key);
        if (anchor) window.scrollBy(0, anchor.getBoundingClientRect().top - saved.top);
        else window.scrollTo(0, saved.y);
      };
      window.scrollTo(0, saved.y);
      requestAnimationFrame(restore);
      addEventListener('load', restore, { once: true }); // フォント・画像で高さがずれた分をもう一度合わせる
    }
  } catch { /* sessionStorage が使えないときは戻さないだけ */ }
  // 集計の絞り込み（スマホは段ごと横スクロール）: 年度・学年のセレクトやスロットが出ている段は、右端まで送って見えるようにする
  document.querySelectorAll('.stats-filter__row').forEach((row) => {
    if ([...row.querySelectorAll('.stats-filter__extra')].some((el) => el.offsetParent)) row.scrollLeft = row.scrollWidth;
  });
  // 絞り込みが浮く位置（sticky の top）にどれだけ近いか → --stuck（0〜1）。残り 80px から近づくほど 1 に（スマホは見出しが薄くなる）
  document.querySelectorAll('.stats-filter').forEach((box) => {
    let stickyTop = parseFloat(getComputedStyle(box).top); // sticky の top は CSS（画面幅で変わる）。毎回は読まず、読み込み時と画面幅の変更時だけ読む
    const check = () => {
      const rest = box.getBoundingClientRect().top - stickyTop;
      box.style.setProperty('--stuck', Math.min(1, Math.max(0, 1 - rest / 80)).toFixed(3));
    };
    window.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', () => { stickyTop = parseFloat(getComputedStyle(box).top); check(); });
    check();
  });

  // メンバーページ: 1列表示だけ、出演履歴の先頭が近づくほど絞り込みカードが薄くなる（スクロール連動の opacity）
  //   終点: 履歴がカードに被る（カードの下端）まで。そこから 200px 手前で薄くなり始める。PC は何もしない
  const historyTop = document.querySelector('.split .history-toolbar, .split section > .section-title');
  const filterBox = document.querySelector('.split aside > .stats-filter');
  if (historyTop && filterBox) {
    const oneColumn = matchMedia('(max-width: 860px)');
    const checkFade = () => {
      let v = 1;
      if (oneColumn.matches) {
        const end = parseFloat(getComputedStyle(filterBox).top) + filterBox.offsetHeight;
        v = Math.min(1, Math.max(0, (historyTop.getBoundingClientRect().top - end) / 200));
      }
      filterBox.style.opacity = v === 1 ? '' : v.toFixed(3);
      filterBox.classList.toggle('is-faded', v < 0.05);
    };
    window.addEventListener('scroll', checkFade, { passive: true });
    window.addEventListener('resize', checkFade);
    checkFade();
  }
  // メンバーページ（PC）: 右の欄は、一番下（よく組む学年区分）が画面に見えるところで止まる（左の出演履歴が続いてもそこで貼り付く）。
  //   sticky の top を「画面の高さ − 欄の高さ」（マイナス）にすると、欄の下端が画面の下端に着いたところで止まる。低いときはいつも通り 80px
  const splitAside = document.querySelector('.split aside');
  if (splitAside) {
    const fitAsideTop = () => { splitAside.style.top = `${Math.min(80, innerHeight - splitAside.offsetHeight - 16)}px`; };
    new ResizeObserver(fitAsideTop).observe(splitAside);
    window.addEventListener('resize', fitAsideTop);
    fitAsideTop();
  }

  // 会場・日程名のプルダウン（取り込み・ライブ編集）: 「＋ 新しい○○を作る」を選んだときだけ名前の入力欄を出す
  //   日程名は data-new-select="__new__"（「new」という日程名とぶつからないように）、会場は 'new'。スタジオのエリア（.sinfo-field）も同じ
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-venue-select], [data-new-select]')) return;
    const box = e.target.closest('.field, .sinfo-field').querySelector('[data-venue-new], [data-new-input]');
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
    if (menu.open || reduceMotion) return; // menu.open = これから閉じる
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
    const body = new URLSearchParams({ kind: tab.dataset.notifTab });
    fetch('api_notice_read.php', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() }, body })
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
    if (!reduceMotion) {
      const dir = tab === tabs[0] ? -12 : 12;
      bell.closest('details').querySelector(`[data-notif-pane="${tab.dataset.notifTab}"]`).animate(
        [{ opacity: 0, transform: `translateX(${dir}px)` }, { opacity: 1, transform: 'none' }],
        { duration: 180, easing: 'ease-out' });
    }
    markRead(tab);
  }));

  // ツールのタブ（data-tool-tabs）: ページを移動せず、data-tool-pane が合う一覧だけ表示する。URL の ?tab= も書きかえる
  const toolTabs = document.querySelector('[data-tool-tabs]');
  if (toolTabs) {
    toolTabs.addEventListener('click', (e) => {
      const tab = e.target.closest('.tab');
      if (!tab) return;
      e.preventDefault();
      document.querySelectorAll('[data-tool-pane]').forEach((p) => { p.hidden = p.dataset.toolPane !== tab.dataset.tab; });
      toolTabs.querySelectorAll('.tab').forEach((t) => {
        const on = t === tab;
        t.classList.toggle('is-active', on);
        on ? t.setAttribute('aria-current', 'page') : t.removeAttribute('aria-current');
      });
      history.replaceState(null, '', tab.getAttribute('href'));
    });
  }

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
      if (animate && !reduceMotion) {
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
    if (card.id && location.hash === '#' + card.id) openWithoutAnimation(card); // 高さのアニメ無しで開く（飛び先がズレないように）
    card.querySelector('summary')?.addEventListener('click', () => {
      if (card.open || reduceMotion) return; // card.open = これから閉じる
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
    const res = await postJson('api_push_subscribe', body);
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || '保存できませんでした');
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
      toastText('プッシュ通知を設定できませんでした');
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
  let done = false;
  form.addEventListener('submit', async (e) => {
    if (done) return;
    e.preventDefault();
    const work = (async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      const sub = await reg?.pushManager.getSubscription();
      if (!sub) return;
      await postJson('api_push_subscribe', { action: 'unsubscribe', endpoint: sub.endpoint });
      await sub.unsubscribe();
    })().catch(() => { /* 解除できなくてもログアウトは続ける */ });
    await Promise.race([work, new Promise((resolve) => setTimeout(resolve, 3000))]);
    done = true;
    form.submit();
  });
}
