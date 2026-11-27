/*
 * player.js — シャッフル再生の外枠（player.php）の動き。管理者だけ
 *   ・曲の一覧は api_shuffle.php（ミックス音源をチャプターで区切ったもの）。ここで混ぜて、1曲ずつ流す
 *   ・曲の途中から流すときは ?from=バイト位置（band.php のプレーヤー setupAudioPlayers と同じ見積もり: 時刻 × 大きさ ÷ 全体の長さ）
 *     曲の終わりは &to=バイト位置 で送るのを止めてもらう（1曲分だけ受け取る。lib/audio_stream.php）
 *     全体の長さは audioProbeDuration（app_player.js）。曲の終わり（end_ms）を過ぎたら次の曲へ。シーク・曲の頭へも同じ方法で読み直す
 *   ・中のページ（iframe）を移るたびに、外枠の題名と URL（?u=）を中に合わせる。外のサイト・ログアウトのリンクは外枠ごと移る
 *   ・中のヘッダーの「シャッフル再生」: 範囲のリンクは window.top.abbeyShuffle、アイコンは window.top.abbeyShufflePanel（再生中の曲のポップアップ）
 *     再生中は中の <html> に .is-shuffle-playing を付けて、アイコンを回す
 *   ・並び・何曲目か・止めた位置は localStorage に残す（ブラウザを閉じても残る）。外枠を読み直す・× で閉じたあとアイコンで開き直す（?resume=1）と、
 *     止めた位置で一時停止した状態から（スマホは自動で鳴らせないため）。アイコンの動きは app_player.js の setupShuffleLinks
 *   ・ロック画面・イヤホンの操作に曲名と「前の曲」「次へ」を出す（Media Session）
 *   ・前の曲: 曲の始めの3秒より後なら、その曲の頭へ（音楽アプリと同じ）
 */
(() => {
  'use strict';

  if (window.top !== window) { // 外枠の中にまた外枠を開かない
    window.top.location.replace(location.href);
    return;
  }
  const cfg = JSON.parse(document.getElementById('shell-data').textContent);
  const frame = document.querySelector('[data-shell-frame]');
  const bar = document.querySelector('[data-shell]');
  const $ = (s) => bar.querySelector(s);
  const audio = $('[data-shell-audio]');
  const playBtns = bar.querySelectorAll('[data-shell-play]');
  const nextBtns = bar.querySelectorAll('[data-shell-next]');
  const titleEl = $('[data-shell-title]');
  const bandEl = $('[data-shell-band]');
  const progEl = $('[data-shell-progress]');
  const panel = $('[data-shell-panel]');
  const artImg = $('[data-shell-art]');
  const seek = $('[data-shell-seek]');
  const seekBox = $('[data-shell-seekbox]');
  const curEl = $('[data-shell-cur]');
  const durEl = $('[data-shell-dur]');
  const KEY = 'shuffle_state'; // app_player.js の setupShuffleLinks も見る
  const POS = 'shuffle_pos';   // 止めた位置（音源の秒）。並びより頻繁に書くので分ける
  const BASE = new URL('.', location.href).href; // このサイトのフォルダ（中のページ名を取り出すのに使う）
  const durations = new Map(); // 音源の URL → 全体の長さ（秒）。null = わからない
  let queue = [];
  let idx = -1;
  let base = 0;      // いまの src の先頭の時刻（秒）
  let fails = 0;     // 続けて流せなかった数（全部だめなときに止まるため）
  let dragging = false; // シークバーをつかんでいる間は、再生位置で動かさない
  let cont = false;      // 通しで再生: バンドのミックスをセトリ順に、MC・曲間も切らずに流す
  let savedAt = 0;       // 最後に位置を残した秒（書きすぎないため）
  let openOnLoad = cfg.resume; // 開き直したときは、中のページを読み終えてもポップアップを開いたまま

  const cur = () => queue[idx] || null;
  const save = () => {
    try { localStorage.setItem(KEY, JSON.stringify({ scope: cfg.scope, id: cfg.id, queue, idx, cont })); } catch (e) { /* 残せなくても再生は続ける */ }
  };
  const savePos = (t) => {
    savedAt = t;
    try { localStorage.setItem(POS, String(t)); } catch (e) { /* 同上 */ }
  };
  const nowSec = () => base + audio.currentTime;
  const shuffle = (a) => {
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
  };
  const probe = async (it) => {
    if (!durations.has(it.src)) durations.set(it.src, await audioProbeDuration(it.src, it.size));
    return durations.get(it.src);
  };
  const fmt = (sec) => { const s = Math.max(0, Math.floor(sec)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
  const innerDoc = () => { try { return frame.contentDocument; } catch (e) { return null; } };

  const setIcon = (playing) => {
    playBtns.forEach((b) => {
      b.querySelector('.icon').textContent = playing ? 'pause' : 'play_arrow';
      b.setAttribute('aria-label', playing ? '一時停止' : '再生');
      b.title = playing ? '一時停止' : '再生';
    });
    innerDoc()?.documentElement.classList.toggle('is-shuffle-playing', playing);
  };
  // 入りきらない文字: 2秒止まる → 端まで流す → 2秒止まる → 戻す、をくり返して全文を見せる（中身を .marquee の span に包んで動かす）
  const marquee = (el) => {
    let inner = el.querySelector(':scope > .marquee');
    if (!inner) {
      inner = document.createElement('span');
      inner.className = 'marquee';
      inner.append(...el.childNodes);
      el.append(inner);
    }
    inner.getAnimations().forEach((a) => a.cancel());
    const d = inner.scrollWidth - el.clientWidth;
    if (!el.clientWidth || d <= 1 || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const wait = 2000;
    const move = d / 40 * 1000; // 1秒に 40px
    const total = 2 * (wait + move);
    const x = (px) => ({ transform: `translateX(${px}px)` });
    inner.animate([
      { ...x(0), offset: 0 }, { ...x(0), offset: wait / total },
      { ...x(-d), offset: (wait + move) / total }, { ...x(-d), offset: (2 * wait + move) / total },
      { ...x(0), offset: 1 },
    ], { duration: total, iterations: Infinity });
  };
  const marqueeAll = () => [titleEl, bandEl, ...panel.querySelectorAll('.shell-panel__meta > *')].forEach(marquee);
  const setPanel = (open) => {
    panel.hidden = !open;
    if (!open) setScopes(false);
    const sum = innerDoc()?.querySelector('[data-shuffle-menu] > summary');
    sum?.classList.toggle('is-active', open);
    // 中のヘッダーのアイコンの真下に、右端をそろえて出す（iframe は外枠の左上にあるので座標はそのまま）。見つからなければ CSS の位置
    const r = open && sum ? sum.getBoundingClientRect() : null;
    panel.style.top = r && r.width ? `${r.bottom + 8}px` : '';
    // 右端をアイコンにそろえる。ただし画面の左右からはみ出さない（狭い画面ではアイコンより右に寄る）
    panel.style.right = r && r.width ? `${Math.max(12, Math.min(innerWidth - r.right, innerWidth - panel.offsetWidth - 12))}px` : '';
    if (open) marqueeAll(); // 閉じている間は幅が 0 で測れないので、開いたときに測り直す
  };
  const render = () => {
    const it = cur();
    titleEl.textContent = it ? it.title : '再生できる曲がありません';
    bandEl.textContent = it ? `${it.band}・${it.live}` : '';
    titleEl.disabled = !it;
    playBtns.forEach((b) => { b.disabled = !it; });
    nextBtns.forEach((b) => { b.disabled = queue.length < 2; });
    $('[data-shell-prev]').disabled = !it;
    seek.disabled = !it;
    if (!it) { setPanel(false); return; }
    artImg.hidden = !it.art;
    if (it.art) artImg.src = it.art;
    const live = $('[data-shell-live]');
    live.textContent = it.year ? `${it.year}年度 ${it.live}` : it.live;
    if (it.live_id) live.href = `live?id=${it.live_id}`; else live.removeAttribute('href');
    const band = $('[data-shell-bandlink]');
    band.textContent = it.band;
    band.href = `band?id=${it.band_id}`;
    $('[data-shell-song]').textContent = it.title;
    const artist = $('[data-shell-artist]');
    artist.textContent = it.artist || '';
    artist.hidden = !it.artist;
    if (it.artist_id) artist.href = `artist?id=${it.artist_id}`; else artist.removeAttribute('href');
    // 演奏者: 楽器ラベル（サイト共通の .part）と名前（メンバーページへ）
    $('[data-shell-players]').replaceChildren(...(it.players || []).map((p) => {
      const li = document.createElement('li');
      // 楽器が2つ以上（Vo/Gt）は1つのラベルに（lib/repository.php の part_badge と同じ作り: 楽器ごとに色を分けた背景・境目だけ混ぜる）
      const tag = document.createElement('span');
      const n = p.parts.length;
      if (n === 1) {
        tag.className = `part part--${p.parts[0].c}`;
        tag.textContent = p.parts[0].s;
      } else if (n > 1) {
        tag.className = `part part--split part--${p.parts.map((pt) => pt.c).join('-')}`;
        const stops = p.parts.flatMap((pt, i) => {
          const color = `color-mix(in srgb, var(--${pt.c}) 13%, transparent)`;
          return [`${color} ${i === 0 ? 0 : Math.round(i / n * 100 + 10)}%`, `${color} ${i === n - 1 ? 100 : Math.round((i + 1) / n * 100 - 10)}%`];
        });
        tag.style.background = `linear-gradient(90deg, ${stops.join(', ')})`;
        p.parts.forEach((pt, i) => {
          if (i > 0) tag.append(Object.assign(document.createElement('span'), { className: 'part__slash', textContent: '/' }));
          tag.append(Object.assign(document.createElement('span'), { className: `part__seg part--${pt.c}`, textContent: pt.s }));
        });
      }
      if (n) li.append(tag);
      const a = document.createElement('a');
      a.href = `member?id=${p.id}`;
      a.target = 'site';
      a.textContent = p.name;
      li.append(a);
      return li;
    }));
    if ('mediaSession' in navigator) {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: it.title, artist: it.artist ? `${it.band}（${it.artist}）` : it.band, album: it.live,
        artwork: it.art ? [{ src: it.art, sizes: '300x300' }] : [],
      });
    }
    marqueeAll();
  };

  // いまの曲を、音源の t 秒の位置から読み直す。play: true で鳴らす
  const playAt = async (t, play) => {
    const it = cur();
    let off = 0;
    let to = '';
    if (t > 0 || it.end_ms !== null) {
      const total = await probe(it);
      if (it !== cur()) return; // 待っている間に次へ進んだ
      if (total) {
        off = Math.round(t * it.size / total);
        // 曲の終わり + 3秒分で送るのを止めてもらう（1曲分だけ受け取る。見積もりのずれで早く切れないよう余裕を持たせ、止めるのは timeupdate）
        if (it.end_ms !== null && !cont) to = `&to=${Math.round((it.end_ms / 1000 + 3) * it.size / total)}`;
      } else if (t > 0) { skip(); return; } // 頭からなら長さがわからなくても全部流せる
    }
    base = off > 0 ? t : 0;
    audio.src = off > 0 ? `${it.src}&from=${off}${to}` : it.src + to;
    if (play) audio.play().catch(() => setIcon(false));
  };
  // i 曲目を用意する。play: true で鳴らす（false は止めておく）。at: 音源の秒（省略 = 曲の頭）
  const go = async (i, play, at) => {
    if (!queue.length) { render(); return; }
    idx = (i + queue.length) % queue.length;
    save();
    render();
    progEl.style.width = '0';
    seek.value = 0;
    seekBox.style.setProperty('--q', 0);
    curEl.textContent = fmt(0);
    const t = at ?? cur().start_ms / 1000;
    savePos(t);
    await playAt(t, play);
    if (at) showPos(at);
    const nx = queue[(idx + 1) % queue.length]; // 次の曲の長さを先に調べておく（次へ進むときに待たない）
    if (nx && (nx.start_ms > 0 || nx.end_ms !== null)) probe(nx);
  };
  const next = () => go(idx + 1, true);
  const prev = () => {
    const it = cur();
    if (!it) return;
    if (queue.length > 1 && nowSec() - it.start_ms / 1000 < 3) go(idx - 1, true);
    else playAt(it.start_ms / 1000, true);
  };
  const skip = () => { // 流せなかった: 続けて全部だめなら止める
    fails += 1;
    if (fails >= Math.min(queue.length, 5)) { audio.pause(); setIcon(false); titleEl.textContent = '音源を読み込めませんでした'; return; }
    next();
  };

  const load = async (scope, id, through) => {
    const res = await fetch(`api_shuffle?scope=${encodeURIComponent(scope)}&id=${Number(id) || 0}`);
    const data = await res.json().catch(() => null);
    queue = res.ok && data ? (through ? data.items : shuffle(data.items)) : [];
    cont = !!through;
    cfg.scope = scope;
    cfg.id = Number(id) || 0;
    fails = 0;
    syncUrl();
  };

  audio.addEventListener('play', () => setIcon(true));
  audio.addEventListener('pause', () => { setIcon(false); if (cur()) savePos(nowSec()); });
  audio.addEventListener('playing', () => { fails = 0; });
  audio.addEventListener('ended', next);
  audio.addEventListener('error', () => { if (audio.getAttribute('src')) skip(); });
  // 再生位置の表示（音源の秒 sec）
  const showPos = (sec) => {
    const it = cur();
    const t = sec * 1000;
    const nx = queue[idx + 1];
    const fileEnd = durations.get(it.src) ? durations.get(it.src) * 1000 : null;
    const end = cont ? (nx && nx.src === it.src ? nx.start_ms : fileEnd) : it.end_ms ?? fileEnd;
    if (end) {
      const len = (end - it.start_ms) / 1000;
      const pos = Math.min(len, Math.max(0, (t - it.start_ms) / 1000));
      progEl.style.width = `${pos / len * 100}%`;
      durEl.textContent = fmt(len);
      seek.max = Math.floor(len);
      if (!dragging) {
        seek.value = pos;
        seekBox.style.setProperty('--q', pos / len);
        curEl.textContent = fmt(pos);
      }
    }
  };
  audio.addEventListener('timeupdate', () => {
    const it = cur();
    if (!it) return;
    const t = nowSec() * 1000;
    showPos(t / 1000);
    if (Math.abs(t / 1000 - savedAt) >= 3) savePos(t / 1000);
    if (cont) { // 通し: 同じ音源の次の曲の頭を過ぎたら表示だけ進める（読み直さない）。音源の最後で ended → 次
      const nx = queue[idx + 1];
      if (nx && nx.src === it.src && t >= nx.start_ms) { idx += 1; save(); render(); }
      return;
    }
    if (it.end_ms !== null && t >= it.end_ms) { audio.pause(); next(); } // 先に止める（次の曲の用意を待つ間に、また呼ばれないように）
  });
  seek.addEventListener('input', () => {
    dragging = true;
    seekBox.style.setProperty('--q', seek.max > 0 ? seek.value / seek.max : 0);
    curEl.textContent = fmt(seek.value);
  });
  seek.addEventListener('change', () => {
    dragging = false;
    if (cur()) playAt(cur().start_ms / 1000 + Number(seek.value), !audio.paused);
  });
  playBtns.forEach((b) => b.addEventListener('click', () => {
    if (!audio.getAttribute('src')) { go(idx < 0 ? 0 : idx, true); return; }
    if (audio.paused) audio.play().catch(() => {}); else audio.pause();
  }));
  nextBtns.forEach((b) => b.addEventListener('click', next));
  $('[data-shell-prev]').addEventListener('click', prev);
  if ('mediaSession' in navigator) {
    navigator.mediaSession.setActionHandler('play', () => audio.play().catch(() => {}));
    navigator.mediaSession.setActionHandler('pause', () => audio.pause());
    navigator.mediaSession.setActionHandler('previoustrack', prev);
    navigator.mediaSession.setActionHandler('nexttrack', next);
  }

  // ポップアップ: 下のバーの曲名・中のアイコンで開け閉め。外を押す・Esc で閉じる
  titleEl.addEventListener('click', () => setPanel(panel.hidden));
  window.abbeyShufflePanel = () => setPanel(panel.hidden);
  const closeOutside = (e) => {
    if (!panel.hidden && !panel.contains(e.target) && e.target !== titleEl && !e.target.closest?.('[data-shuffle-menu] > summary')) setPanel(false);
  };
  const closeOnEsc = (e) => { if (e.key === 'Escape') setPanel(false); };
  document.addEventListener('click', closeOutside);
  document.addEventListener('keydown', closeOnEsc);
  // 範囲を変える: ボタンの下にメニュー。サイト全体・再生中のライブ/バンド/アーティスト・中のページの範囲（ヘッダーのメニューと同じもの）
  const scopeBtn = $('[data-shell-scope]');
  const scopesEl = $('[data-shell-scopes]');
  function setScopes(open) {
    scopesEl.hidden = !open;
    scopeBtn.setAttribute('aria-expanded', String(open));
    if (open) buildScopes();
  }
  const scopeItem = (label, fn) => {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.addEventListener('click', () => { setScopes(false); fn(); });
    li.append(b);
    return li;
  };
  // 再生中のバンドを、いまの曲のいまの位置から通しで
  const through = async () => {
    const it = cur();
    const t = nowSec();
    audio.pause();
    await load('band', it.band_id, true);
    const i = queue.findIndex((q) => q.song_id === it.song_id);
    go(Math.max(0, i), true, i >= 0 ? t : undefined);
  };
  const buildScopes = () => {
    const it = cur();
    const items = [scopeItem('サイト全体', () => window.abbeyShuffle('all', 0))];
    if (it) {
      items.push(scopeItem('再生中のライブ', () => window.abbeyShuffle('live', it.live_id)));
      const thru = document.createElement('input');
      thru.type = 'checkbox';
      thru.checked = cont;
      const band = scopeItem('再生中のバンド', () => (thru.checked ? through() : window.abbeyShuffle('band', it.band_id)));
      const lab = document.createElement('label');
      lab.append(thru, '通しで再生');
      band.append(lab);
      items.push(band);
      if (it.artist_id) items.push(scopeItem('再生中のアーティスト', () => window.abbeyShuffle('artist', it.artist_id)));
    }
    innerDoc()?.querySelectorAll('[data-shuffle-menu] a[data-shuffle]').forEach((a) => {
      if (a.dataset.shuffle !== 'all') items.push(scopeItem(a.textContent, () => window.abbeyShuffle(a.dataset.shuffle, Number(a.dataset.shuffleId) || 0)));
    });
    scopesEl.replaceChildren(...items);
  };
  scopeBtn.addEventListener('click', () => setScopes(scopesEl.hidden));

  // 閉じる: 再生をやめて、いま中で開いているページだけにする（止めた位置は残す。アイコンで開き直せる）
  $('[data-shell-close]').addEventListener('click', () => {
    if (cur() && audio.getAttribute('src')) savePos(nowSec());
    location.href = innerPage() || './';
  });

  // 中のページ名（band?id=3 など）。読めなければ ''
  const innerPage = () => {
    try {
      const href = frame.contentWindow.location.href;
      return href.startsWith(BASE) ? href.slice(BASE.length) : '';
    } catch (e) { return ''; }
  };
  const syncUrl = () => {
    const q = new URLSearchParams({ scope: cfg.scope });
    if (cfg.scope !== 'all') q.set('id', cfg.id);
    const u = innerPage();
    if (u) q.set('u', u);
    history.replaceState(null, '', `player?${q}`);
  };
  frame.addEventListener('load', () => {
    const doc = innerDoc();
    if (!doc) return;
    document.title = doc.title;
    document.documentElement.dataset.theme = doc.documentElement.dataset.theme || 'light';
    const tc = doc.querySelector('meta[name="theme-color"]');
    if (tc) document.querySelector('meta[name="theme-color"]').content = tc.content;
    syncUrl();
    setPanel(openOnLoad);
    openOnLoad = false;
    setIcon(!audio.paused);
    // 外のサイト・ログアウトは外枠ごと移る（外のサイトは iframe に入れられないことが多く、ログアウトは再生も終わるため）
    doc.addEventListener('click', (e) => {
      closeOutside(e);
      const a = e.target.closest?.('a[href]');
      if (a && !a.target && (a.origin !== location.origin || /\/logout(\.php)?$/.test(a.pathname))) a.target = '_top';
    }, true);
    doc.addEventListener('keydown', closeOnEsc);
  });

  // 中のヘッダーから範囲を変える（押した操作のまま鳴らすので、スマホでも止められにくい）
  window.abbeyShuffle = async (scope, id) => {
    audio.pause();
    await load(scope, id);
    go(0, true);
  };

  // 始め: 開き直し（?resume=1）か同じ範囲なら、続きを止めた位置から（止めた状態）。無ければ新しく混ぜて鳴らしてみる
  (async () => {
    let st = null;
    let pos = null;
    try {
      st = JSON.parse(localStorage.getItem(KEY) || 'null');
      pos = Number(localStorage.getItem(POS)) || null;
    } catch (e) { /* 無ければ新しく */ }
    if (st && (cfg.resume || (st.scope === cfg.scope && st.id === cfg.id)) && Array.isArray(st.queue) && st.queue.length) {
      queue = st.queue;
      cont = !!st.cont;
      cfg.scope = st.scope;
      cfg.id = st.id;
      syncUrl();
      const it = queue[st.idx];
      // 残した位置がその曲の中のときだけ使う
      const ok = it && pos !== null && pos * 1000 >= it.start_ms && (it.end_ms === null || pos * 1000 < it.end_ms);
      await go(st.idx, false, ok ? pos : undefined);
      if (cfg.resume) setPanel(true);
      return;
    }
    await load(cfg.scope, cfg.id);
    go(0, true);
  })();
})();
