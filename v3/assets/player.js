/*
 * player.js — シャッフル再生の外枠（player.php）の動き。管理者だけ
 *   ・曲の一覧は api_shuffle.php（ミックス音源をチャプターで区切ったもの）。ここで混ぜて、1曲ずつ流す
 *   ・曲の途中から流すときは ?from=バイト位置（band.php のプレーヤー setupAudioPlayers と同じ見積もり: 時刻 × 大きさ ÷ 全体の長さ）
 *     全体の長さは audioProbeDuration（app_player.js）。曲の終わり（end_ms）を過ぎたら次の曲へ
 *   ・中のページ（iframe）を移るたびに、外枠の題名と URL（?u=）を中に合わせる。外のサイト・ログアウトのリンクは外枠ごと移る
 *   ・中のヘッダーの「シャッフル再生」は window.top.abbeyShuffle を呼ぶ（外枠を読み直さずに範囲だけ変える）
 *   ・並びと何曲目かは sessionStorage に残す。外枠を読み直したら、その曲の頭で止まった状態から（スマホは自動で鳴らせないため）
 *   ・ロック画面・イヤホンの操作に曲名と「次へ」を出す（Media Session）
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
  const audio = bar.querySelector('[data-shell-audio]');
  const playBtn = bar.querySelector('[data-shell-play]');
  const titleEl = bar.querySelector('[data-shell-title]');
  const bandEl = bar.querySelector('[data-shell-band]');
  const progEl = bar.querySelector('[data-shell-progress]');
  const KEY = 'shuffle_state';
  const BASE = new URL('.', location.href).href; // このサイトのフォルダ（中のページ名を取り出すのに使う）
  const durations = new Map(); // 音源の URL → 全体の長さ（秒）。null = わからない
  let queue = [];
  let idx = -1;
  let base = 0;   // いまの src の先頭の時刻（秒）
  let fails = 0;  // 続けて流せなかった数（全部だめなときに止まるため）

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

  const setIcon = (playing) => {
    playBtn.querySelector('.icon').textContent = playing ? 'pause' : 'play_arrow';
    playBtn.setAttribute('aria-label', playing ? '一時停止' : '再生');
    playBtn.title = playing ? '一時停止' : '再生';
  };
  const render = () => {
    const it = cur();
    titleEl.textContent = it ? it.title : '再生できる曲がありません';
    bandEl.textContent = it ? `${it.band}・${it.live}` : '';
    if (it) titleEl.href = `band?id=${it.band_id}`; else titleEl.removeAttribute('href');
    playBtn.disabled = !it;
    bar.querySelector('[data-shell-next]').disabled = queue.length < 2;
    if (it && 'mediaSession' in navigator) {
      navigator.mediaSession.metadata = new MediaMetadata({ title: it.title, artist: it.band, album: it.live });
    }
  };

  // i 曲目を用意する。play: true で鳴らす（false は頭で止めておく）
  const go = async (i, play) => {
    if (!queue.length) { render(); return; }
    idx = (i + queue.length) % queue.length;
    save();
    render();
    progEl.style.width = '0';
    const it = cur();
    const start = it.start_ms / 1000;
    let off = 0;
    if (start > 0) {
      const total = await probe(it);
      if (it !== cur()) return; // 待っている間に次へ進んだ
      if (!total) { skip(); return; }
      off = Math.round(start * it.size / total);
    }
    base = off > 0 ? start : 0;
    audio.src = off > 0 ? `${it.src}&from=${off}` : it.src;
    if (play) audio.play().catch(() => setIcon(false));
    const nx = queue[(idx + 1) % queue.length]; // 次の曲の長さを先に調べておく（次へ進むときに待たない）
    if (nx && nx.start_ms > 0) probe(nx);
  };
  const next = () => go(idx + 1, true);
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
    if (end) progEl.style.width = `${Math.min(100, Math.max(0, (t - it.start_ms) / (end - it.start_ms) * 100))}%`;
    if (it.end_ms !== null && t >= it.end_ms) { audio.pause(); next(); } // 先に止める（次の曲の用意を待つ間に、また呼ばれないように）
  });
  playBtn.addEventListener('click', () => {
    if (!audio.getAttribute('src')) { go(idx < 0 ? 0 : idx, true); return; }
    if (audio.paused) audio.play().catch(() => {}); else audio.pause();
  });
  bar.querySelector('[data-shell-next]').addEventListener('click', next);
  if ('mediaSession' in navigator) {
    navigator.mediaSession.setActionHandler('play', () => audio.play().catch(() => {}));
    navigator.mediaSession.setActionHandler('pause', () => audio.pause());
    navigator.mediaSession.setActionHandler('nexttrack', next);
  }

  // 閉じる: 再生をやめて、いま中で開いているページだけにする
  bar.querySelector('[data-shell-close]').addEventListener('click', () => {
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
    let doc;
    try { doc = frame.contentDocument; } catch (e) { return; }
    if (!doc) return;
    document.title = doc.title;
    document.documentElement.dataset.theme = doc.documentElement.dataset.theme || 'light';
    const tc = doc.querySelector('meta[name="theme-color"]');
    if (tc) document.querySelector('meta[name="theme-color"]').content = tc.content;
    syncUrl();
    // 外のサイト・ログアウトは外枠ごと移る（外のサイトは iframe に入れられないことが多く、ログアウトは再生も終わるため）
    doc.addEventListener('click', (e) => {
      const a = e.target.closest?.('a[href]');
      if (a && !a.target && (a.origin !== location.origin || /\/logout(\.php)?$/.test(a.pathname))) a.target = '_top';
    }, true);
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
