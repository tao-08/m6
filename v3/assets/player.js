/*
 * player.js — シャッフル再生の外枠（player.php）の動き。管理者だけ
 *   ・曲の一覧は api_shuffle.php（ミックス音源をチャプターで区切ったもの）。ここで混ぜて、1曲ずつ流す
 *   ・曲の途中から流すときは ?from=バイト位置（band.php のプレーヤー setupAudioPlayers と同じ見積もり: 時刻 × 大きさ ÷ 全体の長さ）
 *     曲の終わりは &to=バイト位置 で送るのを止めてもらう（1曲分だけ受け取る。lib/audio_stream.php）
 *     全体の長さは audioProbeDuration（app_player.js）。曲の終わり（end_ms）を過ぎたら次の曲へ。シーク・曲の頭へも同じ方法で読み直す
 *   ・中のページ（iframe）を移るたびに、外枠の題名と URL（?u=）を中に合わせる。外のサイト・ログアウトのリンクは外枠ごと移る
 *   ・中のヘッダーの「シャッフル再生」: 範囲のリンクは window.top.abbeyShuffle、アイコンは window.top.abbeyShufflePanel（再生中の曲のポップアップ）
 *     再生中は中の <html> に .is-shuffle-playing を付けて、アイコンを回す
 *   ・並びと何曲目かは sessionStorage に残す。外枠を読み直したら、その曲の頭で止まった状態から（スマホは自動で鳴らせないため）
 *   ・ロック画面・イヤホンの操作に曲名と「最初から再生」「次へ」を出す（Media Session）
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
  const KEY = 'shuffle_state';
  const BASE = new URL('.', location.href).href; // このサイトのフォルダ（中のページ名を取り出すのに使う）
  const durations = new Map(); // 音源の URL → 全体の長さ（秒）。null = わからない
  let queue = [];
  let idx = -1;
  let base = 0;      // いまの src の先頭の時刻（秒）
  let fails = 0;     // 続けて流せなかった数（全部だめなときに止まるため）
  let dragging = false; // シークバーをつかんでいる間は、再生位置で動かさない

  const cur = () => queue[idx] || null;
  const save = () => {
    try { sessionStorage.setItem(KEY, JSON.stringify({ scope: cfg.scope, id: cfg.id, queue, idx })); } catch (e) { /* 残せなくても再生は続ける */ }
  };
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
  const setPanel = (open) => {
    panel.hidden = !open;
    innerDoc()?.querySelector('[data-shuffle-menu] > summary')?.classList.toggle('is-active', open);
  };
  const render = () => {
    const it = cur();
    titleEl.textContent = it ? it.title : '再生できる曲がありません';
    bandEl.textContent = it ? `${it.band}・${it.live}` : '';
    titleEl.disabled = !it;
    playBtns.forEach((b) => { b.disabled = !it; });
    nextBtns.forEach((b) => { b.disabled = queue.length < 2; });
    $('[data-shell-restart]').disabled = !it;
    seek.disabled = !it;
    if (!it) { setPanel(false); return; }
    artImg.hidden = !it.art;
    if (it.art) artImg.src = it.art;
    $('[data-shell-live]').textContent = it.year ? `${it.year}年度 ${it.live}` : it.live;
    const band = $('[data-shell-bandlink]');
    band.textContent = it.band;
    band.href = `band?id=${it.band_id}`;
    $('[data-shell-song]').textContent = it.title;
    $('[data-shell-artist]').textContent = it.artist || '';
    if ('mediaSession' in navigator) {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: it.title, artist: it.artist ? `${it.band}（${it.artist}）` : it.band, album: it.live,
        artwork: it.art ? [{ src: it.art, sizes: '300x300' }] : [],
      });
    }
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
        if (it.end_ms !== null) to = `&to=${Math.round((it.end_ms / 1000 + 3) * it.size / total)}`;
      } else if (t > 0) { skip(); return; } // 頭からなら長さがわからなくても全部流せる
    }
    base = off > 0 ? t : 0;
    audio.src = off > 0 ? `${it.src}&from=${off}${to}` : it.src + to;
    if (play) audio.play().catch(() => setIcon(false));
  };
  // i 曲目を用意する。play: true で鳴らす（false は頭で止めておく）
  const go = async (i, play) => {
    if (!queue.length) { render(); return; }
    idx = (i + queue.length) % queue.length;
    save();
    render();
    progEl.style.width = '0';
    seek.value = 0;
    seekBox.style.setProperty('--q', 0);
    curEl.textContent = fmt(0);
    await playAt(cur().start_ms / 1000, play);
    const nx = queue[(idx + 1) % queue.length]; // 次の曲の長さを先に調べておく（次へ進むときに待たない）
    if (nx && (nx.start_ms > 0 || nx.end_ms !== null)) probe(nx);
  };
  const next = () => go(idx + 1, true);
  const restart = () => { if (cur()) playAt(cur().start_ms / 1000, true); };
  const skip = () => { // 流せなかった: 続けて全部だめなら止める
    fails += 1;
    if (fails >= Math.min(queue.length, 5)) { audio.pause(); setIcon(false); titleEl.textContent = '音源を読み込めませんでした'; return; }
    next();
  };

  const load = async (scope, id) => {
    const res = await fetch(`api_shuffle?scope=${encodeURIComponent(scope)}&id=${Number(id) || 0}`);
    const data = await res.json().catch(() => null);
    queue = res.ok && data ? shuffle(data.items) : [];
    cfg.scope = scope;
    cfg.id = Number(id) || 0;
    fails = 0;
    syncUrl();
  };

  audio.addEventListener('play', () => setIcon(true));
  audio.addEventListener('pause', () => setIcon(false));
  audio.addEventListener('playing', () => { fails = 0; });
  audio.addEventListener('ended', next);
  audio.addEventListener('error', () => { if (audio.getAttribute('src')) skip(); });
  audio.addEventListener('timeupdate', () => {
    const it = cur();
    if (!it) return;
    const t = (base + audio.currentTime) * 1000;
    const end = it.end_ms ?? (durations.get(it.src) ? durations.get(it.src) * 1000 : null);
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
  $('[data-shell-restart]').addEventListener('click', restart);
  if ('mediaSession' in navigator) {
    navigator.mediaSession.setActionHandler('play', () => audio.play().catch(() => {}));
    navigator.mediaSession.setActionHandler('pause', () => audio.pause());
    navigator.mediaSession.setActionHandler('previoustrack', restart);
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
  // 範囲を変える: 中のヘッダーの範囲のメニューを開く
  $('[data-shell-scope]').addEventListener('click', () => {
    setPanel(false);
    innerDoc()?.querySelector('[data-shuffle-menu]')?.setAttribute('open', '');
  });

  // 閉じる: 再生をやめて、いま中で開いているページだけにする
  $('[data-shell-close]').addEventListener('click', () => {
    try { sessionStorage.removeItem(KEY); } catch (e) { /* 無視 */ }
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
    setPanel(false);
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

  // 始め: 同じ範囲の続きがあればその曲から（止めた状態）、無ければ新しく混ぜて鳴らしてみる
  (async () => {
    let st = null;
    try { st = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { /* 無ければ新しく */ }
    if (st && st.scope === cfg.scope && st.id === cfg.id && Array.isArray(st.queue) && st.queue.length) {
      queue = st.queue;
      go(st.idx, false);
      return;
    }
    await load(cfg.scope, cfg.id);
    go(0, true);
  })();
})();
