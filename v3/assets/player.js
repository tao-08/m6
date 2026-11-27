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
 *   ・再生・一時停止はフェード。中のページでほかの音（MIX・試聴・YouTube）が鳴ったらフェードで止め、すべて止まって1秒後にフェードで戻す（watchOthers）
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
  let shuffled = true;   // シャッフル。オフは出演順・セトリ順
  let byBand = false;    // バンド単位: バンドごとにセトリ順でまとめて流す（シャッフルはバンドの順だけ混ぜる）
  let cont = false;      // 通し（バンド単位のときだけ効く）: 曲のあとの MC・曲間も流す（through_end_ms まで）。次に流す曲が同じミックスの続きなら切らずにつなぐ
  let savedAt = 0;       // 最後に位置を残した秒（書きすぎないため）
  let openOnLoad = cfg.resume; // 開き直したときは、中のページを読み終えてもポップアップを開いたまま

  const cur = () => queue[idx] || null;
  const thru = () => byBand && cont;
  const endOf = (it) => (thru() ? (it.through_end_ms === undefined ? it.end_ms : it.through_end_ms) : it.end_ms); // 曲の終わり（ms、null = ファイルの最後）
  const joined = (it, nx) => thru() && nx && nx.src === it.src && nx.start_ms === endOf(it); // 次の曲が同じミックスの続き
  const save = () => {
    try { localStorage.setItem(KEY, JSON.stringify({ scope: cfg.scope, id: cfg.id, queue, idx, cont, shuffled, byBand })); } catch (e) { /* 残せなくても再生は続ける */ }
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
  // 並べる: シャッフルなし = 出演順・セトリ順（n）。曲単位で混ぜる = いまの曲 it を先頭に残りを混ぜる。バンド単位で混ぜる = いまのバンドを先頭にバンドの順だけ混ぜる
  const arrange = (items, it) => {
    const sorted = [...items].sort((a, b) => (a.n ?? 0) - (b.n ?? 0));
    if (!shuffled) return sorted;
    if (!byBand) {
      const rest = shuffle(sorted.filter((q) => q !== it));
      return it ? [it, ...rest] : rest;
    }
    const bands = new Map();
    sorted.forEach((q) => { if (!bands.has(q.band_id)) bands.set(q.band_id, []); bands.get(q.band_id).push(q); });
    const mine = it ? bands.get(it.band_id) : null;
    if (mine) bands.delete(it.band_id);
    const rest = shuffle([...bands.values()]);
    return (mine ? [mine, ...rest] : rest).flat();
  };
  const reorder = () => { // いまの曲はそのまま、並びだけ変える
    const it = cur();
    queue = arrange(queue, it);
    idx = it ? queue.indexOf(it) : 0;
    save();
  };
  const fmt = (sec) => { const s = Math.max(0, Math.floor(sec)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
  const innerDoc = () => { try { return frame.contentDocument; } catch (e) { return null; } };
  // フェード: 音量を ms かけて to へ。新しいフェード・読み直しで前のは止まる（false で終わる）。
  // 裏のタブでも止まらないよう rAF でなく setTimeout
  // shortcut: iOS は audio.volume を変えられないので、フェードせずに止まる・鳴る。要るなら app.js の GainNode の方法へ（裏で鳴らすときに止まりやすくなる）
  let ramp = 0;
  const fade = (to, ms) => new Promise((done) => {
    const id = ++ramp;
    const from = audio.volume;
    const t0 = performance.now();
    const step = () => {
      if (id !== ramp) { done(false); return; }
      const k = Math.min(1, (performance.now() - t0) / ms);
      audio.volume = from + (to - from) * k;
      if (k < 1) setTimeout(step, 16); else done(true);
    };
    step();
  });
  let fadingOut = false; // 止めるフェードの途中（もう止めたものとして扱う）
  const fadeOut = async () => {
    fadingOut = true;
    const ok = await fade(0, 300);
    fadingOut = false;
    if (ok) { audio.pause(); audio.volume = 1; }
  };
  const fadeIn = (ms) => {
    if (audio.paused) audio.volume = 0;
    audio.play().catch(() => { ramp += 1; audio.volume = 1; });
    fade(1, ms);
  };

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
    const move = d / 25 * 1000; // 1秒に 25px
    const total = 2 * (wait + move);
    const x = (px) => ({ transform: `translateX(${px}px)` });
    inner.animate([
      { ...x(0), offset: 0 }, { ...x(0), offset: wait / total },
      { ...x(-d), offset: (wait + move) / total }, { ...x(-d), offset: (2 * wait + move) / total },
      { ...x(0), offset: 1 },
    ], { duration: total, iterations: Infinity });
  };
  const marqueeAll = () => [titleEl, bandEl, ...panel.querySelectorAll('.shell-panel__meta > :not(.shell-panel__liveline), .shell-panel__live')].forEach(marquee);
  const setPanel = (open) => {
    panel.hidden = !open;
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
    buildScopes();
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
    const album = $('[data-shell-album]');
    album.textContent = it.album || '';
    album.hidden = !it.album;
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
    if (t > 0 || endOf(it) !== null) {
      const total = await probe(it);
      if (it !== cur()) return; // 待っている間に次へ進んだ
      if (total) {
        off = Math.round(t * it.size / total);
        // 曲の終わり + 3秒分で送るのを止めてもらう（1曲分だけ受け取る。見積もりのずれで早く切れないよう余裕を持たせ、止めるのは timeupdate）
        let last = idx; // 続きでつながる曲の最後まで受け取る
        while (joined(queue[last], queue[last + 1])) last += 1;
        const end = endOf(queue[last]);
        if (end !== null) to = `&to=${Math.round((end / 1000 + 3) * it.size / total)}`;
      } else if (t > 0) { skip(); return; } // 頭からなら長さがわからなくても全部流せる
    }
    base = off > 0 ? t : 0;
    ramp += 1; // 途中のフェードをやめて、元の音量で
    audio.volume = 1;
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
  const next = () => {
    if (pending) { const p = pending; pending = null; switchTo(p.scope, p.id, false); return; }
    go(idx + 1, true);
  };
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

  const load = async (scope, id) => {
    const res = await fetch(`api_shuffle?scope=${encodeURIComponent(scope)}&id=${Number(id) || 0}`);
    const data = await res.json().catch(() => null);
    const items = res.ok && data ? data.items : [];
    items.forEach((q, i) => { q.n = i; }); // シャッフルを切ったときに元の順へ戻す
    queue = arrange(items, null);
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
    const end = endOf(it) ?? fileEnd;
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
    const end = endOf(it);
    if (end === null || t < end) return; // ファイルの最後まで流す曲は ended で次へ
    if (!pending && joined(it, queue[idx + 1])) { idx += 1; save(); render(); return; } // 続き: 読み直さず表示だけ進める
    audio.pause(); // 先に止める（次の曲の用意を待つ間に、また呼ばれないように）
    next();
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
  // ほかの音（中のページの音源・動画・YouTube）が鳴り始めたらフェードで止め、すべて止まって1秒たったらフェードで戻す
  const others = new Set(); // 鳴っているもの: <audio>/<video> か YouTube の iframe の window
  let interrupted = false;  // ほかの音で止めた（自分で再生・一時停止したら解除）
  let resumeTimer = 0;
  const othersChanged = () => {
    clearTimeout(resumeTimer);
    if (others.size) {
      if (!audio.paused && !fadingOut) { interrupted = true; fadeOut(); }
    } else if (interrupted) {
      resumeTimer = setTimeout(() => { if (interrupted && !others.size) { interrupted = false; fadeIn(1000); } }, 1000);
    }
  };
  const mediaOn = (e) => { if (!e.target.muted && !others.has(e.target)) { others.add(e.target); othersChanged(); } };
  const mediaOff = (e) => { if (others.delete(e.target)) othersChanged(); };
  const byHand = (play) => {
    interrupted = false;
    clearTimeout(resumeTimer);
    if (play) fadeIn(300); else fadeOut();
  };

  playBtns.forEach((b) => b.addEventListener('click', () => {
    if (!audio.getAttribute('src')) { go(idx < 0 ? 0 : idx, true); return; }
    byHand(audio.paused || fadingOut);
  }));
  nextBtns.forEach((b) => b.addEventListener('click', next));
  $('[data-shell-prev]').addEventListener('click', prev);
  if ('mediaSession' in navigator) {
    navigator.mediaSession.setActionHandler('play', () => byHand(true));
    navigator.mediaSession.setActionHandler('pause', () => byHand(false));
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
  // 範囲: ポップアップに常に出す（チップ）。サイト全体・再生中のライブ/バンド/アーティスト・中のページの範囲（ヘッダーのメニューと同じもの）
  // 左のボタンで、押したらすぐ変えるか、いまの曲が終わってから変えるか（次の曲から = pending に置いて next で切り替える）
  const scopesEl = $('[data-shell-scopes]');
  const laterBtn = $('[data-shell-later]');
  let later = false;
  try { later = localStorage.getItem('shuffle_when') === 'next'; } catch (e) { /* すぐ */ }
  let pending = null; // 次の曲から変える範囲 {scope, id}
  // お知らせ: 上に2秒（中のページのトーストはポップアップに隠れるので外枠に出す。見た目は app.css の .toast）
  const toast = (text) => {
    const t = document.createElement('div');
    t.className = 'toast flash flash--info';
    t.setAttribute('role', 'status');
    t.textContent = text;
    const box = $('[data-shell-toasts]');
    box.replaceChildren(t); // 続けて押したら差し替える
    const close = () => { t.classList.add('is-leaving'); setTimeout(() => t.remove(), 500); };
    setTimeout(close, 2000);
    t.addEventListener('click', close);
  };
  const showLater = () => laterBtn.setAttribute('aria-pressed', String(later));
  laterBtn.addEventListener('click', () => {
    later = !later;
    try { localStorage.setItem('shuffle_when', later ? 'next' : 'now'); } catch (e) { /* 覚えなくても使える */ }
    if (!later) pending = null;
    showLater();
    buildScopes();
    toast(later ? '次の曲から範囲を変えます' : 'すぐに再生されます');
  });
  showLater();
  // 範囲を変える。順番どおり（シャッフルしない）で、いまの曲が新しい範囲にあれば、すぐ = その位置から続ける・次の曲から = その次から
  const switchTo = async (scope, id, now) => {
    const it = cur();
    const t = it ? nowSec() : 0;
    if (now) audio.pause();
    await load(scope, id);
    const i = it && !shuffled ? queue.findIndex((q) => q.song_id === it.song_id) : -1;
    if (i >= 0) { if (now) go(i, true, t); else go(i + 1, true); return; }
    if (it && queue.length > 1 && queue[0].song_id === it.song_id) { go(1, true); return; } // 混ぜた先頭がいまの曲
    go(0, true);
  };
  const scopeItem = (label, scope, id) => {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip';
    b.textContent = label;
    b.classList.toggle('is-current', cfg.scope === scope && cfg.id === id); // いま流している範囲
    b.classList.toggle('is-pending', !!pending && pending.scope === scope && pending.id === id);
    b.addEventListener('click', () => {
      if (later && cur()) { pending = { scope, id }; buildScopes(); } else switchTo(scope, id, true);
    });
    li.append(b);
    return li;
  };
  const buildScopes = () => {
    const it = cur();
    const list = [['サイト全体', 'all', 0]];
    if (it) {
      list.push(['再生中のライブ', 'live', it.live_id], ['再生中のバンド', 'band', it.band_id]);
      if (it.artist_id) list.push(['再生中のアーティスト', 'artist', it.artist_id]);
    }
    innerDoc()?.querySelectorAll('[data-shuffle-menu] a[data-shuffle]').forEach((a) => {
      if (a.dataset.shuffle !== 'all') list.push([a.textContent, a.dataset.shuffle, Number(a.dataset.shuffleId) || 0]);
    });
    const seen = new Set(); // 「再生中のバンド」と「このバンド」が同じなら再生中のほうだけ
    scopesEl.replaceChildren(...list.filter(([, sc, id]) => !seen.has(`${sc}:${id}`) && seen.add(`${sc}:${id}`)).map((x) => scopeItem(...x)));
  };

  // 通し: 曲間（MC など）も流す。ボタンは裏返しの「曲間をスキップ」。ON にしたら、いまの曲も MC まで受け取れるよう、いまの位置から読み直す（OFF は timeupdate で止まるだけ）
  const contBtn = $('[data-shell-cont]');
  const showCont = () => contBtn.setAttribute('aria-pressed', String(!cont)); // ボタンは「曲間をスキップ」: 押している = 通しでない
  contBtn.addEventListener('click', () => {
    cont = !cont;
    save();
    showCont();
    if (thru() && cur() && audio.getAttribute('src')) playAt(nowSec(), !audio.paused);
    toast(cont ? '曲間も再生します' : '曲間をスキップします');
  });
  // シャッフル: オフは出演順・セトリ順。いまの曲はそのまま、並びだけ変える
  const shuffleBtn = $('[data-shell-shuffle]');
  const showShuffle = () => shuffleBtn.setAttribute('aria-pressed', String(shuffled));
  shuffleBtn.addEventListener('click', () => {
    shuffled = !shuffled;
    reorder();
    showShuffle();
  });
  // 単位: 曲単位 ↔ バンド単位。バンド単位のときだけ通しのボタンを出す（曲単位では通しは効かない）
  const unitBtn = $('[data-shell-unit]');
  const showUnit = () => {
    unitBtn.classList.toggle('is-band', byBand);
    unitBtn.setAttribute('aria-label', byBand ? 'バンド単位' : '曲単位');
    unitBtn.title = byBand ? 'バンド単位' : '曲単位';
    contBtn.parentElement.classList.toggle('is-band', byBand); // 通しのボタンが滑って出る・隠れる（CSS）
  };
  unitBtn.addEventListener('click', () => {
    const was = thru();
    byBand = !byBand;
    reorder();
    showUnit();
    toast(byBand ? 'バンド単位で再生します' : '曲単位で再生します');
    if (!was && thru() && cur() && audio.getAttribute('src')) playAt(nowSec(), !audio.paused); // 通しが効き始めたら MC まで受け取れるよう読み直す
  });

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
    // テーマ: 読み込んだときと、中で切り替えたとき（<html data-theme> が変わる）に写す
    const theme = () => {
      document.documentElement.dataset.theme = doc.documentElement.dataset.theme || 'light';
      const tc = doc.querySelector('meta[name="theme-color"]');
      if (tc) document.querySelector('meta[name="theme-color"]').content = tc.content;
    };
    theme();
    new doc.defaultView.MutationObserver(theme).observe(doc.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
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
    watchOthers(doc);
    buildScopes();
  });

  // 中のページの音を見張る（ページを移るたび。前のページの音は止まったものとする）
  const YT_ORIGIN = /^https:\/\/www\.youtube(-nocookie)?\.com$/;
  const YT_EMBED = /^https:\/\/www\.youtube(-nocookie)?\.com\/embed\//;
  const watchOthers = (doc) => {
    const win = doc.defaultView;
    others.clear();
    othersChanged();
    // <audio>/<video>: play などは泡立たないので、捕まえる側（capture）で
    doc.addEventListener('play', mediaOn, true);
    ['pause', 'ended', 'emptied'].forEach((ev) => doc.addEventListener(ev, mediaOff, true));
    // ページに置かない new Audio()（試聴など）は上に届かないので、play() のときに直接聞く
    const P = win.HTMLMediaElement.prototype;
    const play = P.play;
    const watched = new WeakSet();
    P.play = function (...args) {
      if (!this.isConnected && !watched.has(this)) {
        watched.add(this);
        this.addEventListener('play', mediaOn);
        ['pause', 'ended', 'emptied'].forEach((ev) => this.addEventListener(ev, mediaOff));
      }
      return play.apply(this, args);
    };
    // YouTube: 埋め込みに状態を送ってもらう（enablejsapi=1 と listening の合図。YT.Player で作ったもの＝widgetid 付きは送ってくる）
    const ytPrep = (f) => {
      if (!YT_EMBED.test(f.src) || /[?&]widgetid=/.test(f.src)) return;
      if (!/[?&]enablejsapi=1/.test(f.src)) {
        const u = new URL(f.src);
        u.searchParams.set('enablejsapi', '1');
        f.src = u.href; // 読み直す（まだ鳴っていないうちに）。load でもう一度ここへ
        return;
      }
      [0, 1000].forEach((ms) => setTimeout(() => {
        f.contentWindow?.postMessage(JSON.stringify({ event: 'listening', id: 'abbey-shell', channel: 'widget' }), '*');
        f.contentWindow?.postMessage(JSON.stringify({ event: 'command', func: 'addEventListener', args: ['onStateChange'], id: 'abbey-shell', channel: 'widget' }), '*');
      }, ms));
    };
    doc.querySelectorAll('iframe').forEach(ytPrep);
    doc.addEventListener('load', (e) => { if (e.target.tagName === 'IFRAME') ytPrep(e.target); }, true);
    new win.MutationObserver((list) => {
      list.forEach((m) => {
        if (m.type === 'attributes') ytPrep(m.target);
        m.addedNodes.forEach((n) => { if (n.nodeType === 1) (n.tagName === 'IFRAME' ? [n] : n.querySelectorAll('iframe')).forEach(ytPrep); });
      });
      // 消えた埋め込みは止まったものとする（閉じた window は closed）
      const n = others.size;
      others.forEach((s) => { if (s.closed) others.delete(s); });
      if (others.size !== n) othersChanged();
    }).observe(doc.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    win.addEventListener('message', (e) => {
      if (!YT_ORIGIN.test(e.origin) || typeof e.data !== 'string' || !e.source) return;
      let d;
      try { d = JSON.parse(e.data); } catch (err) { return; }
      const s = d.event === 'onStateChange' ? d.info : d.event === 'infoDelivery' ? d.info?.playerState : undefined;
      if (s === 1 && !others.has(e.source)) { others.add(e.source); othersChanged(); }
      else if ([0, 2, 5, -1].includes(s) && others.delete(e.source)) othersChanged(); // 終わり・一時停止・頭出し・未再生
    });
  };

  // 中のヘッダーから範囲を変える（押した操作のまま鳴らすので、スマホでも止められにくい）
  window.abbeyShuffle = async (scope, id) => {
    pending = null;
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
      shuffled = st.shuffled ?? !st.cont;
      showShuffle();
      byBand = !!st.byBand;
      showCont();
      showUnit();
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
