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
  const el = $('[data-shell-audio]');
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
  // YouTube の曲（MIX の無い曲）: ポップアップの動画（IFrame API）。<audio> と同じ形（play・pause・currentTime・volume・イベント）で扱えるようにする
  //   動画は見えている所でしか流さない（YouTube の決まり）。流し始めたらポップアップを開き、閉じたら止める
  const ytBox = $('[data-shell-yt]');
  const yt = (() => {
    const t = new EventTarget();
    const fire = (n) => t.dispatchEvent(new Event(n));
    let player = null;
    let ready = null;
    let src = '';
    let cued = ''; // 先に頭出ししておいた動画（cue）
    let paused = true;
    let vol = 1;
    let timer = 0;
    const onState = ({ data }) => {
      if (data === 1) { // 再生中
        paused = false;
        fire('play'); fire('playing');
        clearInterval(timer);
        timer = setInterval(() => fire('timeupdate'), 250);
      } else if (data === 2 || data === 0) { // 一時停止・終わり
        clearInterval(timer);
        if (!paused) { paused = true; fire('pause'); }
        if (data === 0) fire('ended');
      }
    };
    const api = () => (ready ??= new Promise((ok, ng) => {
      window.onYouTubeIframeAPIReady = ok;
      const sc = document.createElement('script');
      sc.src = 'https://www.youtube.com/iframe_api';
      sc.onerror = () => { ready = null; ng(); };
      document.head.append(sc);
    }).then(() => new Promise((ok) => {
      player = new YT.Player(ytBox.appendChild(document.createElement('div')), {
        width: '100%', height: '100%',
        playerVars: { playsinline: 1, rel: 0, controls: 0, disablekb: 1 },
        events: { onReady: () => { player.setVolume(vol * 100); ok(); }, onStateChange: onState, onError: () => fire('error') },
      });
    })));
    return Object.defineProperties(t, Object.getOwnPropertyDescriptors({ // Object.assign だと getter が作った時の値で固まる
      get currentTime() { return player?.getCurrentTime?.() || 0; },
      get duration() { return player?.getDuration?.() || 0; },
      get paused() { return paused; },
      get volume() { return vol; },
      set volume(v) { vol = v; player?.setVolume?.(v * 100); },
      getAttribute: () => src || null,
      play() { return api().then(() => player.playVideo()); },
      pause() { player?.pauseVideo?.(); if (!paused) { paused = true; clearInterval(timer); fire('pause'); } },
      // 動画 id の sec 秒から。play: false は頭出しだけ
      async load(id, sec, play) {
        const same = (src === id || cued === id) && player?.getVideoData?.().video_id === id;
        src = id;
        cued = '';
        try { await api(); } catch (e) { fire('error'); return; }
        if (src !== id) return; // 待っている間に別の曲へ
        if (same) { player.seekTo(sec, true); if (play) player.playVideo(); return; } // 同じ動画の中は読み直さず飛ぶ
        const o = { videoId: id, startSeconds: sec };
        if (play) player.loadVideoById(o); else player.cueVideoById(o);
      },
      stop() { src = ''; this.pause(); player?.stopVideo?.(); },
      // 次の曲の動画を、前の曲（MIX）を流している間に読んでおく（切り替わったときに待たない）
      async cue(id, sec) {
        if (cued === id) return;
        cued = id;
        try { await api(); } catch (e) { return; }
        if (cued === id && !src) player.cueVideoById({ videoId: id, startSeconds: sec });
      },
    }));
  })();
  let audio = el; // いま鳴らしているもの: el（MIX）か yt
  // イベントは両方に付けて、いま鳴らしている方の分だけ受け取る
  const on = (name, fn) => [el, yt].forEach((m) => m.addEventListener(name, (e) => { if (e.currentTarget === audio) fn(e); }));
  const filterDlg = $('[data-shell-filter-dialog]');
  const KEY = 'shuffle_state'; // app_player.js の setupShuffleLinks も見る
  const POS = 'shuffle_pos';   // 止めた位置（音源の秒）。並びより頻繁に書くので分ける
  const BASE = new URL('.', location.href).href; // このサイトのフォルダ（中のページ名を取り出すのに使う）
  const durations = new Map(); // 音源の URL → 全体の長さ（秒）。null = わからない
  let queue = [];
  let idx = -1;
  let base = 0;      // いまの src の先頭の時刻（秒）
  let elSrc = '';    // el に読ませている音源（from / to を除いた it.src）
  let elEnd = Infinity; // el に読ませている範囲の終わり（音源の秒。to で止めたとき）
  let fails = 0;     // 続けて流せなかった数（全部だめなときに止まるため）
  let dragging = false; // シークバーをつかんでいる間は、再生位置で動かさない
  let shuffled = true;   // シャッフル。オフは出演順・セトリ順
  let byBand = false;    // バンド単位: バンドごとにセトリ順でまとめて流す（シャッフルはバンドの順だけ混ぜる）
  let cont = false;      // 通し（バンド単位のときだけ効く）: 曲のあとの MC・曲間も流す（through_end_ms まで）。次に流す曲が同じミックスの続きなら切らずにつなぐ
  let savedAt = 0;       // 最後に位置を残した秒（書きすぎないため）
  let filter = { src: 'all', off: [] }; // 絞り込み: src = all | mix | yt、off = 外したライブの ID（範囲の上にかける。新しいライブは入る側）
  try { filter = { ...filter, ...JSON.parse(localStorage.getItem('shuffle_filter') || '{}') }; } catch (e) { /* 無しで */ }
  const passes = (q) => (filter.src === 'all' || (filter.src === 'yt') === !!q.video) && !filter.off.includes(q.live_id);
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
    if (it.video) return null; // YouTube は流し始めてから yt.duration
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
  const TAIL = 250; // 曲の終わりのフェードアウト（ms）
  let tail = null;    // 終わりのフェードを始めた曲（1曲に1回）
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
  const isOpen = () => !panel.hidden && !panel.classList.contains('is-mini');
  const setPanel = (open) => {
    // YouTube の曲は、閉じても動画だけ右下に小さく残して流し続ける（見えない所で流さないのは YouTube の決まり）
    const mini = !open && audio === yt && !!yt.getAttribute();
    panel.classList.toggle('is-mini', mini);
    panel.hidden = !open && !mini;
    const sum = innerDoc()?.querySelector('[data-shuffle-menu] > summary');
    sum?.classList.toggle('is-active', open);
    if (mini) { panel.style.top = 'auto'; panel.style.right = ''; panel.style.bottom = `${bar.offsetHeight + 8}px`; return; }
    panel.style.bottom = '';
    if (!open) return; // 閉じるときは位置を変えない（消える動きの途中で CSS の位置へ飛ぶため）
    // 中のヘッダーのアイコンの真下に、右端をそろえて出す（iframe は外枠の左上にあるので座標はそのまま）。見つからなければ CSS の位置
    const r = sum ? sum.getBoundingClientRect() : null;
    panel.style.top = r && r.width ? `${r.bottom + 8}px` : '';
    // 右端をアイコンにそろえる。ただし画面の左右からはみ出さない（狭い画面ではアイコンより右に寄る）
    panel.style.right = r && r.width ? `${Math.max(12, Math.min(innerWidth - r.right, innerWidth - panel.offsetWidth - 12))}px` : '';
    if (cur()) showPos(nowSec()); // 吹き出しの被りは開いてから測る
    marqueeAll(); refresh(cur()); // 閉じている間は幅が 0 で測れないので、開いたときに測り直す
  };
  // 通しで MC のチャプターを流している間（MC のチャプター mc_ms 〜 次の曲 through_end_ms）。MC のない曲間は曲のまま
  let mcShown = false;
  const hasMc = (it) => !!it && thru() && it.mc_ms != null;
  const inMc = (it) => hasMc(it) && nowSec() * 1000 >= it.mc_ms && (it.through_end_ms === null || nowSec() * 1000 < it.through_end_ms); // 上限: 曲を移った直後の古い位置で MC と出さない
  // シークバー・時間の区間（ms）。MC は別の曲として扱い、曲は mc_ms まで・MC は mc_ms から次の曲まで（null = ファイルの最後）
  // バンド単位: シークバーはバンド全体（キューで続いている同じバンド・同じ音源の曲の、最初の曲の頭〜最後の曲の終わり）
  const bandRange = () => {
    const it = cur();
    if (!byBand || !it) return null;
    const same = (q) => q && q.band_id === it.band_id && q.src === it.src;
    let a = idx;
    let b = idx;
    while (same(queue[a - 1])) a -= 1;
    while (same(queue[b + 1])) b += 1;
    return { a, b };
  };
  const segStart = (it) => { const r = bandRange(); return r ? queue[r.a].start_ms : inMc(it) ? it.mc_ms : it.start_ms; };
  const segEnd = (it) => { const r = bandRange(); return r ? endOf(queue[r.b]) : inMc(it) ? endOf(it) : hasMc(it) ? it.mc_ms : endOf(it); };
  const render = () => {
    const it = cur();
    const mc = mcShown = inMc(it); // MC の間は曲名を「MC」にして、アーティスト・アルバム・ジャケットは出さない
    const title = it && mc ? 'MC' : it?.title;
    titleEl.textContent = it ? title : '再生できる曲がありません';
    bandEl.textContent = it ? `${it.band} ・ ${it.year ? `${String(it.year % 100).padStart(2, '0')}’ ` : ''}${it.live}` : ''; // 年度は下2桁（26’ 文化祭ライブ）
    titleEl.disabled = !it;
    playBtns.forEach((b) => { b.disabled = !it; });
    nextBtns.forEach((b) => { b.disabled = queue.length < 2 && !hasMc(it); });
    $('[data-shell-prev]').disabled = !it;
    seek.disabled = !it;
    buildScopes();
    if (!it) { setPanel(false); return; }
    artImg.hidden = mc || !it.art;
    panel.classList.toggle('is-video', !!it.video); // ジャケットの代わりに動画
    ytBox.hidden = !it.video;
    $('.shell-panel__liveline > .tag--mix').hidden = !!it.video;
    if (it.art) artImg.src = it.art;
    const live = $('[data-shell-live]');
    live.textContent = it.year ? `${it.year}年度 ${it.live}` : it.live;
    if (it.live_id) live.href = `live?id=${it.live_id}`; else live.removeAttribute('href');
    const band = $('[data-shell-bandlink]');
    band.textContent = it.band;
    band.href = `band?id=${it.band_id}`;
    $('[data-shell-song]').textContent = title;
    const artist = $('[data-shell-artist]');
    artist.textContent = it.artist || '';
    artist.hidden = mc || !it.artist;
    const album = $('[data-shell-album]');
    album.textContent = it.album || '';
    album.hidden = mc || !it.album;
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
        title, artist: !mc && it.artist ? `${it.band}（${it.artist}）` : it.band, album: it.live,
        artwork: !mc && it.art ? [{ src: it.art, sizes: '300x300' }] : [],
      });
    }
    marqueeAll();
  };

  // いまの曲を、音源の t 秒の位置から読み直す。play: true で鳴らす
  const playAt = async (t, play) => {
    const it = cur();
    if (it.video) {
      if (audio === el) el.pause();
      audio = yt;
      base = 0;
      ramp += 1;
      tail = null;
      yt.volume = 1;
      yt.load(it.video, t, play);
      if (play && panel.hidden) setPanel(false); // 閉じていたら小さく出す
      return;
    }
    if (audio === yt) { yt.stop(); audio = el; if (panel.classList.contains('is-mini')) setPanel(false); }
    // 同じ音源で、読み込み済みの範囲かつこの曲の終わりまで受け取っているなら、読み直さずに動かす（通信量を減らす）
    const rel = t - base;
    if (elSrc === it.src && el.readyState > 0 && (endOf(it) ?? Infinity) / 1000 <= elEnd
      && Array.from({ length: el.buffered.length }, (_, i) => i).some((i) => rel >= el.buffered.start(i) && rel < el.buffered.end(i) - 0.5)) {
      ramp += 1;
      tail = null;
      audio.volume = 1;
      el.currentTime = rel;
      if (play) audio.play().catch(() => setIcon(false)); else el.pause();
      return;
    }
    let off = 0;
    let to = '';
    let capSec = Infinity; // 受け取る範囲の終わり（音源の秒）
    if (t > 0 || endOf(it) !== null) {
      const total = await probe(it);
      if (it !== cur()) return; // 待っている間に次へ進んだ
      if (total) {
        off = Math.round(t * it.size / total);
        // 曲の終わり + 3秒分で送るのを止めてもらう（1曲分だけ受け取る。見積もりのずれで早く切れないよう余裕を持たせ、止めるのは timeupdate）
        let last = idx; // 続きでつながる曲の最後まで受け取る
        while (joined(queue[last], queue[last + 1])) last += 1;
        const end = endOf(queue[last]);
        if (end !== null) { to = `&to=${Math.round((end / 1000 + 3) * it.size / total)}`; capSec = end / 1000; }
      } else if (t > 0) { skip(); return; } // 頭からなら長さがわからなくても全部流せる
    }
    base = off > 0 ? t : 0;
    ramp += 1; // 途中のフェードをやめて、元の音量で
    tail = null;
    audio.volume = 1;
    el.src = off > 0 ? `${it.src}&from=${off}${to}` : it.src + to;
    elSrc = it.src;
    elEnd = capSec;
    if (play) audio.play().catch(() => setIcon(false));
  };
  // i 曲目を用意する。play: true で鳴らす（false は止めておく）。at: 音源の秒（省略 = 曲の頭）
  const go = async (i, play, at) => {
    if (!queue.length) { render(); return; }
    idx = (i + queue.length) % queue.length;
    save();
    render();
    if (!bandRange()) { // バンド全体のバーは続きなので 0 に戻さない
      progEl.style.width = '0';
      seek.value = 0;
      seekBox.style.setProperty('--q', 0);
      curEl.textContent = fmt(0);
    }
    const t = at ?? cur().start_ms / 1000;
    savePos(t);
    await playAt(t, play);
    if (at) showPos(at);
    const nx = queue[(idx + 1) % queue.length]; // 次の曲の長さを先に調べておく（次へ進むときに待たない）
    if (nx && (nx.start_ms > 0 || nx.end_ms !== null)) probe(nx);
    if (nx && nx.band_id !== cur().band_id) refresh(nx); // 次の曲を先に取り直しておく（同じバンドなら開いたときに読んだ分で足りる）
    if (nx?.video && audio !== yt) yt.cue(nx.video, nx.start_ms / 1000);
  };
  // 曲送り・戻しの動き: d = 1（次へ。右から入る）/ -1（前へ。左から入る）。入ってくる側だけ動かす（.2s・--ease-out と同じ）
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const slide = (d) => {
    if (reduced.matches) return;
    [titleEl, bandEl, $('.shell-panel__head'), $('[data-shell-players]')].forEach((el) => el.animate(
      [{ opacity: 0, translate: `${d * 24}px 0` }, { opacity: 1, translate: '0 0' }],
      { duration: 200, easing: 'cubic-bezier(.2, .8, .2, 1)' },
    ));
  };
  const next = () => {
    if (pending) { const p = pending; pending = null; switchTo(p.scope, p.id, false); return; }
    const it = cur();
    if (hasMc(it) && !inMc(it) && nowSec() * 1000 < it.mc_ms) { playAt(it.mc_ms / 1000, true); return; } // 通し: 曲のあとの MC へ
    slide(1);
    go(idx + 1, true);
  };
  const prev = () => {
    const it = cur();
    if (!it) return;
    if (inMc(it)) { playAt((nowSec() - it.mc_ms / 1000 < 3 ? it.start_ms : it.mc_ms) / 1000, true); return; } // MC の前の曲 = いまの曲
    if (queue.length > 1 && nowSec() - it.start_ms / 1000 < 3) {
      const pv = queue[(idx - 1 + queue.length) % queue.length];
      slide(-1); go(idx - 1, true, hasMc(pv) ? pv.mc_ms / 1000 : undefined); // 通し: 前の曲のあとの MC へ
    }
    else playAt(it.start_ms / 1000, true);
  };
  const skip = () => { // 流せなかった: 続けて全部だめなら止める
    fails += 1;
    if (fails >= Math.min(queue.length, 5)) { audio.pause(); setIcon(false); titleEl.textContent = '音源を読み込めませんでした'; return; }
    next();
  };

  // 曲名・演奏者などを取り直す（キューは保存したものなので、セトリを直しても古いまま）。読むのはその曲のバンドの分だけ。流す位置（src・start_ms など）は変えない
  const refresh = async (it) => {
    if (!it) return;
    const res = await fetch(`api_shuffle?scope=band&id=${Number(it.band_id) || 0}`).catch(() => null);
    const data = res?.ok ? await res.json().catch(() => null) : null;
    if (!data) return;
    const fresh = new Map(data.items.map((q) => [q.song_id ?? q.src, q])); // song_id が無いものは src で
    queue.forEach((q) => {
      const f = fresh.get(q.song_id ?? q.src);
      if (f) for (const k of ['title', 'artist_id', 'artist', 'album', 'art', 'band', 'live', 'players', 'through_end_ms', 'mc_ms']) q[k] = f[k]; // 通しの区切り: 前に保存したキューは古い数え方のまま（終わりのチャプターで止まっていた）
    });
    save();
    if (queue.includes(it) && cur()?.band_id === it.band_id) render();
  };
  const load = async (scope, id) => {
    if (scope === 'band' && shuffled) { shuffled = false; showShuffle(); } // バンドはセトリ順で聞く（ヘッダーのメニューから始めたときも）
    const res = await fetch(`api_shuffle?scope=${encodeURIComponent(scope)}&id=${Number(id) || 0}`);
    const data = await res.json().catch(() => null);
    const items = res.ok && data ? data.items : [];
    items.forEach((q, i) => { q.n = i; }); // シャッフルを切ったときに元の順へ戻す
    queue = arrange(items.filter(passes), null);
    cfg.scope = scope;
    cfg.id = Number(id) || 0;
    fails = 0;
    syncUrl();
  };

  on('play', () => { setIcon(true); if (audio === yt && panel.hidden) setPanel(false); }); // 動画は見える所で（閉じていたら小さく出す）
  on('pause', () => { setIcon(false); if (cur()) savePos(nowSec()); });
  on('playing', () => { fails = 0; });
  on('ended', next);
  on('error', () => { if (audio.getAttribute('src')) skip(); });
  // バンド単位: シークバーの上に曲（番号）と MC（通しのときだけ）の吹き出し（band.php のチャプターと同じ見た目）。押すとそこから
  //   曲間をスキップするときは、飛ばす所（曲の終わり〜次の曲の頭）を棒の上で薄くする（--gaps）
  const bubblesEl = $('[data-shell-bubbles]');
  let bubbleKey = '';
  const drawBubbles = (it, s0, end) => {
    const r = bandRange();
    const marks = [];
    const gaps = [];
    if (r && end) {
      const x = (ms) => Math.min(1, Math.max(0, (ms - s0) / (end - s0)));
      for (let k = r.a; k <= r.b; k += 1) {
        const q = queue[k];
        marks.push({ k, ms: q.start_ms, label: String(k - r.a + 1), full: `${k - r.a + 1}. ${q.title}`, kind: 'song' });
        if (hasMc(q)) marks.push({ k, ms: q.mc_ms, label: 'MC', full: 'MC', kind: 'mc' });
        const nx = queue[k + 1];
        if (!thru() && k < r.b && q.end_ms !== null && nx.start_ms > q.end_ms) gaps.push([x(q.end_ms), x(nx.start_ms)]);
      }
      marks.forEach((m) => { m.x = x(m.ms); });
    }
    const key = JSON.stringify([marks, gaps]);
    if (key !== bubbleKey) {
      bubbleKey = key;
      bubblesEl.replaceChildren(...marks.map((m) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'audio-bubble';
        b.textContent = m.label;
        b.dataset.k = m.k;
        b.dataset.ms = m.ms;
        b.dataset.kind = m.kind;
        b.style.setProperty('--x', m.x);
        b.setAttribute('aria-label', `${m.full} から再生`);
        b.title = `${m.full} から再生`;
        return b;
      }));
      seekBox.classList.toggle('has-bubbles', marks.length > 0);
      // MC はシークバーの下、曲は上（MC は次の曲の頭のすぐ手前で、上に並べると被るため）
      [...bubblesEl.children].forEach((b) => b.classList.toggle('is-below', b.dataset.kind === 'mc'));
      seekBox.classList.toggle('has-below', !!bubblesEl.querySelector('.is-below'));
      const thumb = (p) => `calc(var(--ap-thumb) / 2 + (100% - var(--ap-thumb)) * ${p})`; // 棒の上の位置（つまみの半分ずつ内側）
      seekBox.style.setProperty('--gaps', gaps.length
        ? `linear-gradient(to right, ${gaps.map(([a, b]) => `transparent ${thumb(a)}, var(--gap) ${thumb(a)}, var(--gap) ${thumb(b)}, transparent ${thumb(b)}`).join(', ')})`
        : 'none');
    }
    const mc = inMc(it);
    [...bubblesEl.children].forEach((b) => b.classList.toggle('is-current', Number(b.dataset.k) === idx && (b.dataset.kind === 'mc') === mc));
  };
  bubblesEl.addEventListener('click', (e) => {
    const b = e.target.closest('.audio-bubble');
    if (!b) return;
    const k = Number(b.dataset.k);
    if (k !== idx) { idx = k; save(); render(); }
    playAt(Number(b.dataset.ms) / 1000, !audio.paused);
  });
  // 再生位置の表示（音源の秒 sec）
  const showPos = (sec) => {
    const it = cur();
    const t = sec * 1000;
    const nx = queue[idx + 1];
    const fileEnd = it.video ? (audio === yt && yt.duration ? yt.duration * 1000 : null) : durations.get(it.src) ? durations.get(it.src) * 1000 : null;
    const end = segEnd(it) ?? fileEnd;
    const s0 = segStart(it);
    drawBubbles(it, s0, end);
    if (end) {
      const len = (end - s0) / 1000;
      const pos = Math.min(len, Math.max(0, (t - s0) / 1000));
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
  on('timeupdate', () => {
    const it = cur();
    if (!it) return;
    const t = nowSec() * 1000;
    showPos(t / 1000);
    if (inMc(it) !== mcShown) render(); // MC に入った・抜けた
    if (Math.abs(t / 1000 - savedAt) >= 3) savePos(t / 1000);
    const end = endOf(it);
    if (end === null) return; // ファイルの最後まで流す曲は ended で次へ
    const goesOn = !pending && joined(it, queue[idx + 1]); // 次が同じミックスの続き（フェードしない）
    if (t < end) {
      if (!goesOn && tail !== it && !audio.paused && !fadingOut && end - t <= TAIL) { tail = it; fade(0, end - t); } // 終わりをわずかにフェードアウト（音量は次の playAt で戻す）
      return;
    }
    if (goesOn) { idx += 1; save(); render(); slide(1); return; } // 続き: 読み直さず表示だけ進める
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
    if (!cur()) return;
    const t = segStart(cur()) + Number(seek.value) * 1000;
    const r = bandRange();
    if (r) { // バンド全体のバーでは、その位置の曲へ移る（曲間を飛ばす設定で曲の外なら、次の曲の頭）
      let k = r.a;
      while (k < r.b && t >= (endOf(queue[k]) ?? Infinity)) k += 1;
      if (k !== idx) { idx = k; save(); render(); }
      playAt(Math.max(t, queue[k].start_ms) / 1000, !audio.paused);
      return;
    }
    playAt(t / 1000, !audio.paused);
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
  titleEl.addEventListener('click', () => setPanel(!isOpen()));
  window.abbeyShufflePanel = () => setPanel(!isOpen());
  const closeOutside = (e) => {
    if (isOpen() && !panel.contains(e.target) && e.target !== titleEl && !e.target.closest?.('[data-shuffle-menu] > summary')) setPanel(false);
  };
  const closeOnEsc = (e) => { if (e.key === 'Escape' && !filterDlg.open) setPanel(false); }; // 絞り込みの Esc はモーダルだけ閉じる
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
    b.addEventListener('click', () => pick(scope, id));
    if (scope !== 'band') { // シャッフルの印。シャッフル中だけ CSS で幅を広げて見せる（バンドは選ぶとシャッフルを外すので付けない）
      const ic = document.createElement('span');
      ic.className = 'icon';
      ic.setAttribute('aria-hidden', 'true');
      ic.textContent = 'shuffle';
      b.append(ic);
    }
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
    // 「再生中のバンド」と「このバンド」が同じなら「この〜」を残す（流している範囲なら is-current で選ばれた見た目になる）
    const here = new Set(list.filter(([label]) => !label.startsWith('再生中の')).map(([, sc, id]) => `${sc}:${id}`));
    const seen = new Set();
    scopesEl.replaceChildren(...list
      .filter(([label, sc, id]) => !(label.startsWith('再生中の') && here.has(`${sc}:${id}`)))
      .filter(([, sc, id]) => !seen.has(`${sc}:${id}`) && seen.add(`${sc}:${id}`))
      .map((x) => scopeItem(...x)));
  };

  const pick = (scope, id) => {
    if (later && cur()) { pending = { scope, id }; buildScopes(); } else switchTo(scope, id, true);
  };

  // 絞り込み: 開くたびにいまの範囲の曲を読み、年度（開ける）→ ライブのチェックを作る。閉じたら保存して、変わっていれば同じ範囲を読み直す
  const filterBtn = $('[data-shell-filter]');
  const yearsEl = $('[data-shell-filter-years]');
  const srcBtns = bar.querySelectorAll('[data-shell-src]');
  const saveFilter = () => { try { localStorage.setItem('shuffle_filter', JSON.stringify(filter)); } catch (e) { /* 覚えなくても使える */ } };
  const showFilter = () => {
    filterBtn.setAttribute('aria-pressed', String(filter.off.length > 0));
    srcBtns.forEach((b) => b.setAttribute('aria-pressed', String(filter.src === 'all' || filter.src === b.dataset.shellSrc)));
  };
  showFilter();
  // MIX / YouTube: それぞれ入り切り。両方は外せない
  srcBtns.forEach((b) => b.addEventListener('click', () => {
    const on = [...srcBtns].filter((x) => (x === b) !== (x.getAttribute('aria-pressed') === 'true')).map((x) => x.dataset.shellSrc);
    if (!on.length) { toast('MIX と YouTube の両方は外せません'); return; }
    filter.src = on.length === 2 ? 'all' : on[0];
    saveFilter();
    showFilter();
    pick(cfg.scope, cfg.id);
  }));
  const box = (checked, label) => {
    const c = document.createElement('input');
    c.type = 'checkbox';
    c.checked = checked;
    if (label) c.setAttribute('aria-label', label);
    return c;
  };
  const syncYear = (d) => { // 年度のチェック = 中のライブすべて（一部なら中間）
    const lives = [...d.querySelectorAll('[data-live]')];
    const on = lives.filter((c) => c.checked).length;
    const y = d.querySelector('[data-year]');
    y.checked = on === lives.length;
    y.indeterminate = on > 0 && on < lives.length;
  };
  filterBtn.addEventListener('click', async () => {
    yearsEl.replaceChildren();
    filterDlg.showModal();
    const res = await fetch(`api_shuffle?scope=${encodeURIComponent(cfg.scope)}&id=${Number(cfg.id) || 0}`).catch(() => null);
    const data = res?.ok ? await res.json().catch(() => null) : null;
    const years = new Map(); // 年度 → Map(live_id → ライブ名)（出演順のまま）
    (data?.items || []).forEach((q) => {
      if (!years.has(q.year)) years.set(q.year, new Map());
      years.get(q.year).set(q.live_id, q.live);
    });
    yearsEl.replaceChildren(...[...years].reverse().map(([year, lives]) => { // 新しい年度から
      const d = document.createElement('details');
      const s = document.createElement('summary');
      const y = box(false, `${year}年度`);
      y.dataset.year = '';
      y.addEventListener('change', () => { d.querySelectorAll('[data-live]').forEach((c) => { c.checked = y.checked; }); });
      const ic = document.createElement('span');
      ic.className = 'icon';
      ic.setAttribute('aria-hidden', 'true');
      ic.textContent = 'expand_more'; // 開ける印（開くと上向き）
      s.append(y, `${year}年度`, ic);
      const ul = document.createElement('ul');
      lives.forEach((name, id) => {
        const li = document.createElement('li');
        const l = document.createElement('label');
        l.className = 'check';
        const c = box(!filter.off.includes(id));
        c.dataset.live = String(id);
        c.addEventListener('change', () => syncYear(d));
        l.append(c, name);
        li.append(l);
        ul.append(li);
      });
      d.append(s, ul);
      syncYear(d);
      return d;
    }));
  });
  filterDlg.addEventListener('close', () => {
    const shown = [...yearsEl.querySelectorAll('[data-live]')];
    const shownIds = new Set(shown.map((c) => Number(c.dataset.live)));
    const next = {
      src: filter.src,
      off: [...filter.off.filter((id) => !shownIds.has(id)), ...shown.filter((c) => !c.checked).map((c) => Number(c.dataset.live))], // ほかの範囲で外したライブは残す
    };
    if (JSON.stringify(next) === JSON.stringify(filter)) return;
    filter = next;
    saveFilter();
    showFilter();
    pick(cfg.scope, cfg.id);
  });

  // 通し: 曲間（MC など）も流す。ON にしたら、いまの曲も MC まで受け取れるよう、いまの位置から読み直す（OFF は timeupdate で止まるだけ）
  const contBtn = $('[data-shell-cont]');
  const showCont = () => contBtn.setAttribute('aria-pressed', String(cont));
  contBtn.addEventListener('click', () => {
    cont = !cont;
    save();
    showCont();
    if (thru() && cur() && audio.getAttribute('src')) playAt(nowSec(), !audio.paused);
    if (cur()) showPos(nowSec()); // 吹き出しの MC・飛ばす所
    toast(cont ? '曲間も再生します' : '曲間をスキップします');
  });
  // シャッフル: オフは出演順・セトリ順。いまの曲はそのまま、並びだけ変える
  const shuffleBtn = $('[data-shell-shuffle]');
  const showShuffle = () => {
    shuffleBtn.setAttribute('aria-pressed', String(shuffled));
    scopesEl.classList.toggle('is-shuffled', shuffled); // チップの印が幅ごと滑って出る・引っ込む
  };
  showShuffle();
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
    if (cur()) showPos(nowSec()); // シークバーの範囲・吹き出し
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
      const was = st.queue[st.idx];
      queue = st.queue.filter((q) => !(q.video && q.song_id == null)); // 前に保存した動画まるごとの曲は外す（今は流さない）
      st.idx = Math.max(0, queue.indexOf(was));
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
      const ok = it && pos !== null && pos * 1000 >= it.start_ms && (endOf(it) === null || pos * 1000 < endOf(it)); // 通しなら MC の途中も
      await go(st.idx, false, ok ? pos : undefined);
      refresh(cur()); // 保存したキューは古いことがある（演奏者・通しの終わり）
      if (cfg.resume) setPanel(true);
      return;
    }
    await load(cfg.scope, cfg.id);
    go(0, true);
  })();

  // shortcut: 一時的な計測（Android で下のタブが隠れる件）。原因がわかったら消す
  const dbg = document.createElement('pre');
  dbg.style.cssText = 'position:fixed;left:0;top:0;z-index:99;margin:0;padding:4px;font:10px/1.3 monospace;background:rgb(0 0 0/.75);color:#0f0;pointer-events:none';
  document.body.append(dbg);
  const rb = (el) => { const r = el?.getBoundingClientRect(); return r ? `${Math.round(r.top)}-${Math.round(r.bottom)}` : '-'; };
  setInterval(() => {
    const d = innerDoc();
    const vv = visualViewport;
    dbg.textContent = [
      `win ${innerWidth}x${innerHeight} doc ${document.documentElement.clientHeight} scrollY ${scrollY}`,
      `vv ${vv ? `${Math.round(vv.height)} top ${Math.round(vv.offsetTop)}` : '-'}`,
      `body ${rb(document.body)} frame ${rb(frame)} bar ${rb(bar)} pb ${getComputedStyle(bar).paddingBottom}`,
      `in ${d ? `${d.defaultView.innerHeight} scrollY ${d.defaultView.scrollY} nav ${rb(d.querySelector('.nav'))} ${d.documentElement.className}` : '-'}`,
    ].join('\n');
  }, 500);
})();
