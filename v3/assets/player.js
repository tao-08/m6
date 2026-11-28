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
  const audio = el; // YouTube も MP3 の音だけ流す（api_shuffle.php）
  const on = (name, fn) => el.addEventListener(name, fn);
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
  let busy = false;  // 曲を読み直している途中（前の曲の位置で timeupdate・pause を見ない。新しい src の loadstart で戻す）
  let dragging = false; // シークバーをつかんでいる間は、再生位置で動かさない
  let shuffled = true;   // シャッフル。オフは出演順・セトリ順
  let byBand = false;    // バンド単位: バンドごとにセトリ順でまとめて流す（シャッフルはバンドの順だけ混ぜる）
  let cont = false;      // 通し（バンド単位のときだけ効く）: 曲のあとの MC・曲間も流す（through_end_ms まで）。次に流す曲が同じミックスの続きなら切らずにつなぐ
  let savedAt = 0;       // 最後に位置を残した秒（書きすぎないため）
  let filter = { src: 'all', off: [], bands: [] }; // 絞り込み: src = all | mix | yt、off = 外したライブの ID、bands = 一部だけ外したライブの、外したバンドの ID（範囲の上にかける。新しいライブ・バンドは入る側）
  try { filter = { ...filter, ...JSON.parse(localStorage.getItem('shuffle_filter') || '{}') }; } catch (e) { /* 無しで */ }
  const kept = (q) => !filter.off.includes(q.live_id) && !filter.bands.includes(q.band_id);
  const passes = (q) => (filter.src === 'all' || (filter.src === 'yt') === !!q.yt) && kept(q);
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
  const probing = new Map(); // 調べている途中の音源 → Promise（先読みと曲送りで同じ音源を2回取りに行かない）
  const probe = (it) => {
    if (durations.has(it.src)) return Promise.resolve(durations.get(it.src));
    if (!probing.has(it.src)) {
      probing.set(it.src, audioProbeDuration(it.src, it.size).then((d) => {
        probing.delete(it.src);
        if (d) durations.set(it.src, d); // 失敗は覚えない（一度の通信の失敗で、そのファイルの曲をずっと飛ばさない）
        return d;
      }));
    }
    return probing.get(it.src);
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
    el.classList.remove('is-shrunk');
    let d = inner.scrollWidth - el.clientWidth;
    if (el.clientWidth && d > 1 && el.matches('.shell-panel__song, .shell-panel__band')) { // ポップアップの曲名・バンド名は1段階だけ小さくしてから測り直す（下のバーは流す前提）
      el.classList.add('is-shrunk');
      d = inner.scrollWidth - el.clientWidth;
    }
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
  const marqueeAll = () => [titleEl, bandEl, ...panel.querySelectorAll('.shell-panel__meta > :not(.shell-panel__liveline), .shell-panel__live, .shell-panel__band')].forEach(marquee);
  const isOpen = () => !panel.hidden;
  const setPanel = (open) => {
    panel.hidden = !open;
    const sum = innerDoc()?.querySelector('[data-shuffle-menu] > summary');
    sum?.classList.toggle('is-active', open);
    if (!open) return; // 閉じるときは位置を変えない（消える動きの途中で CSS の位置へ飛ぶため）
    // 中のヘッダーのアイコンの真下に、右端をそろえて出す（iframe は外枠の左上にあるので座標はそのまま）。見つからなければ CSS の位置
    const r = sum ? sum.getBoundingClientRect() : null;
    panel.style.top = r && r.width ? `${r.bottom + 8}px` : '';
    // 右端をアイコンにそろえる。ただし画面の左右からはみ出さない（狭い画面ではアイコンより右に寄る）
    panel.style.right = r && r.width ? `${Math.max(12, Math.min(innerWidth - r.right, innerWidth - panel.offsetWidth - 12))}px` : '';
    if (cur()) showPos(nowSec()); // 吹き出しの被りは開いてから測る
    songLinkEvent($('[data-shell-song]'), 'mouseover'); // 曲名のモーダルを先に取る（中のページで曲ごとに1回だけ）
    marqueeAll(); refresh(cur()); // 閉じている間は幅が 0 で測れないので、開いたときに測り直す
  };
  // 通しで MC のチャプターを流している間（MC のチャプター mc_ms 〜 次の曲 through_end_ms）。MC のない曲間は曲のまま
  let mcShown = '';
  const hasMc = (it) => !!it && thru() && it.mc_ms != null;
  const inMc = (it) => hasMc(it) && nowSec() * 1000 >= it.mc_ms && (it.through_end_ms === null || nowSec() * 1000 < it.through_end_ms); // 上限: 曲を移った直後の古い位置で MC と出さない
  // 通しの冒頭の MC・SE（1曲目の前の MC・SE のチャプター intro_ms 〜 曲の頭）。その曲の前から流す
  const startOf = (it) => (thru() && it.intro_ms != null ? it.intro_ms : it.start_ms);
  const inIntro = (it) => !!it && thru() && it.intro_ms != null && nowSec() * 1000 < it.start_ms;
  const gapKind = (it) => (inIntro(it) ? 'intro' : inMc(it) ? 'mc' : ''); // 曲の外を流しているところ
  const gapName = (k) => ({ se: 'SE', blank: '未登録' })[k] || 'MC'; // blank = 曲なしのチャプター（api_shuffle.php）
  const gapLabel = (it) => (inIntro(it) ? gapName(it.intro_kind || 'se') : inMc(it) ? gapName(it.mc_kind) : 'MC');
  const seOf = (it) => (inIntro(it) ? it.intro_se : inMc(it) && it.mc_kind === 'se' ? it.mc_se : null); // 流している SE の曲名・ジャケット（セトリにあれば）
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
  const segStart = (it) => { const r = bandRange(); return r ? queue[r.a].intro_ms ?? queue[r.a].start_ms : // バンド全体のバーは冒頭の MC・SE から（飛ばすときは灰色）
     inMc(it) ? it.mc_ms : startOf(it); };
  const segEnd = (it) => { const r = bandRange(); return r ? endOf(queue[r.b]) : inMc(it) ? endOf(it) : hasMc(it) ? it.mc_ms : endOf(it); };
  const render = () => {
    const it = cur();
    const mc = !!(mcShown = it ? gapKind(it) : ''); // MC・SE の間は曲名を「MC」「SE」に（セトリに SE の曲名があればその曲名・ジャケット）
    const se = it && mc ? seOf(it) : null;
    const title = it && mc ? se?.title || gapLabel(it) : it?.title;
    const show = mc ? se || {} : it || {}; // ジャケット・アーティスト・アルバムを出す元
    titleEl.textContent = it ? title : '再生できる曲がありません';
    bandEl.textContent = it ? `${it.band} ・ ${it.year ? `${String(it.year % 100).padStart(2, '0')}’ ` : ''}${it.live}` : ''; // 年度は下2桁（26’ 文化祭ライブ）
    titleEl.disabled = !it;
    playBtns.forEach((b) => { b.disabled = !it; });
    nextBtns.forEach((b) => { b.disabled = queue.length < 2 && !hasMc(it); });
    $('[data-shell-prev]').disabled = !it;
    seek.disabled = !it;
    buildScopes();
    if (!it) { setPanel(false); return; }
    // 画像を変えると読み終わるまで前の画像が出たままなので、読み終わるまで隠して音符を出す
    if (!show.art) artImg.hidden = true;
    else if (artImg.getAttribute('src') !== show.art) { artImg.hidden = true; artImg.onload = () => { artImg.hidden = false; }; }
    else artImg.hidden = !artImg.complete;
    $('.shell-panel__liveline > .tag--mix').hidden = !!it.yt;
    const yt = $('[data-shell-yt]'); // YouTube の区分の音源: 動画へのリンク（前に保存したキューの yt: true は refresh で動画ID になる）
    yt.hidden = typeof it.yt !== 'string';
    if (!yt.hidden) yt.href = `https://www.youtube.com/watch?v=${encodeURIComponent(it.yt)}`;
    if (show.art) artImg.src = show.art;
    const artBtn = $('[data-shell-artbtn]'); // ジャケットを押すとアルバムのモーダル（app_album.js の setupAlbumModal）
    artBtn.disabled = !(show.art && show.alb);
    if (show.alb) {
      const a = show.alb;
      Object.assign(artBtn.dataset, { albumOpen: '', albumTitle: a.title, albumArtist: a.artist, albumYear: a.year || '', albumArt: a.art || '', albumApp: a.app, albumUrl: a.url });
    }
    const live = $('[data-shell-live]');
    live.textContent = it.year ? `${it.year}年度 ${it.live}` : it.live;
    if (it.live_id) live.href = `live?id=${it.live_id}`; else live.removeAttribute('href');
    const band = $('[data-shell-bandlink]');
    band.textContent = it.band;
    band.href = `band?id=${it.band_id}`;
    $('[data-shell-se]').hidden = !((inIntro(it) && it.intro_kind !== 'mc') || (inMc(it) && it.mc_kind === 'se'));
    const song = $('[data-shell-song]');
    song.textContent = title;
    if (!mc && it.key) song.href = `songs?open=${encodeURIComponent(it.key)}`; else song.removeAttribute('href');
    const artist = $('[data-shell-artist]');
    artist.textContent = show.artist || '';
    artist.hidden = !show.artist;
    const album = $('[data-shell-album]');
    album.textContent = show.album || '';
    album.hidden = !show.album;
    if (!mc && it.artist_id) artist.href = `artist?id=${it.artist_id}`; else artist.removeAttribute('href');
    // 演奏者: 楽器ラベル（サイト共通の .part）と名前（メンバーページへ）
    const playersEl = $('[data-shell-players]');
    const h0 = playersEl.offsetHeight;
    const p0 = panel.offsetHeight;
    playersEl.replaceChildren(...(it.players || []).map((p) => {
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
    // 演奏者の行数が変わったら、下（シークバーから下）を前の位置から滑らせ、ポップアップの高さも合わせて動かす
    const dh = playersEl.offsetHeight - h0;
    if (dh && isOpen() && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      const css = getComputedStyle(document.documentElement);
      const opt = { duration: (parseFloat(css.getPropertyValue('--t-slow')) || .3) * 1000, easing: css.getPropertyValue('--ease-out').trim() || 'ease-out' };
      for (let e = playersEl.nextElementSibling; e; e = e.nextElementSibling) e.animate({ translate: [`0 ${-dh}px`, '0 0'] }, opt);
      if (panel.offsetHeight !== p0) panel.animate({ height: [`${p0}px`, `${panel.offsetHeight}px`], overflow: ['hidden', 'hidden'] }, opt);
    }
    if ('mediaSession' in navigator) {
      navigator.mediaSession.metadata = new MediaMetadata({
        title, artist: !mc && it.artist ? `${it.band}（${it.artist}）` : it.band, album: it.live,
        artwork: !mc && it.art ? [{ src: it.art, sizes: '300x300' }] : [],
      });
    }
    marqueeAll();
  };

  // いまの曲を、音源の t 秒の位置から読み直す。play: true で鳴らす
  let loadSeq = 0; // 最後に呼んだ playAt（連打で古い呼び出しが後から音源を差し替えないように）
  let srcSeq = 0;  // el に src を入れた playAt
  const playAt = async (t, play) => {
    const it = cur();
    const my = ++loadSeq;
    // 同じ音源で、読み込み済みの範囲かつこの曲の終わりまで受け取っているなら、読み直さずに動かす（通信量を減らす）
    const rel = t - base;
    if (elSrc === it.src && el.readyState > 0 && (endOf(it) ?? Infinity) / 1000 <= elEnd
      && Array.from({ length: el.buffered.length }, (_, i) => i).some((i) => rel >= el.buffered.start(i) && rel < el.buffered.end(i) - 0.5)) {
      busy = false;
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
    // 読み直す間は前の曲を止めておく（鳴り続けると、表示だけ次の曲で音は前の曲になり、古い位置で終わりを判定して勝手に進む）
    busy = true;
    if (play) loading(true); // 長さを調べている間も輪を回す（止めた pause では消さない）
    prepWait(it.src, 'サーバーが用意中'); // 長さを調べる要求は、サーバーがドライブから落とす・変換するのを待つ
    if (!el.paused) el.pause();
    if (t > 0 || endOf(it) !== null) {
      const total = await probe(it);
      if (my !== loadSeq || it !== cur()) return; // 待っている間に次へ進んだ
      if (total) {
        off = Math.round(t * it.size / total);
        // 曲の終わり + 3秒分で送るのを止めてもらう（1曲分だけ受け取る。見積もりのずれで早く切れないよう余裕を持たせ、止めるのは timeupdate）
        // バンド単位は通しが OFF でも MC の終わりまで受け取る（通しの切り替えで読み直して音が途切れないように。止めるのは timeupdate）
        const fullEnd = (q) => (byBand && q.through_end_ms !== undefined ? q.through_end_ms : endOf(q));
        let last = idx; // 続きでつながる曲の最後まで受け取る
        while (byBand && queue[last + 1]?.src === it.src && queue[last + 1].start_ms === fullEnd(queue[last])) last += 1;
        const end = fullEnd(queue[last]);
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
    srcSeq = my;
    if (play) audio.play().catch(() => setIcon(false));
  };
  // i 曲目を用意する。play: true で鳴らす（false は止めておく）。at: 音源の秒（省略 = 曲の頭）
  let jumpNext = false; // 次に位置を出すとき、シークバーのつまみを滑らせる
  let jumpTimer = 0;
  const go = async (i, play, at) => {
    if (!queue.length) { render(); return; }
    idx = (i + queue.length) % queue.length;
    save();
    render();
    jumpNext = !!bandRange(); // バンド全体のバーは、次の位置が出たときにつまみを滑らせる
    if (!jumpNext) { // バンド全体のバーは続きなので 0 に戻さない
      progEl.style.width = '0';
      seek.value = 0;
      seekBox.style.setProperty('--q', 0);
      curEl.textContent = fmt(0);
    }
    const t = at ?? startOf(cur()) / 1000;
    savePos(t);
    await playAt(t, play);
    if (at) showPos(at);
    const nx = queue[(idx + 1) % queue.length]; // 次の曲の長さを先に調べておく（次へ進むときに待たない）。先の曲の音源をサーバーに用意させるのはしない（ffmpeg が重なってサーバーの上限に当たった）
    if (nx && (nx.start_ms > 0 || nx.end_ms !== null)) probe(nx);
    if (nx && nx.band_id !== cur().band_id) refresh(nx); // 次の曲を先に取り直しておく（同じバンドなら開いたときに読んだ分で足りる）
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
  // 同じ曲の中で飛ぶ（MC・冒頭の SE・曲の頭）。バンド全体のバーはつまみを滑らせる（go と同じ）
  const jump = (sec) => { jumpNext = !!bandRange(); playAt(sec, true); };
  const next = () => {
    if (pending) { const p = pending; pending = null; switchTo(p.scope, p.id, false); return; }
    const it = cur();
    if (inIntro(it)) { jump(it.start_ms / 1000); return; } // 通し: オープニング SE → その曲（飛ばさない）
    if (hasMc(it) && !inMc(it) && nowSec() * 1000 < it.mc_ms) { jump(it.mc_ms / 1000); return; } // 通し: 曲のあとの MC へ
    slide(1);
    go(idx + 1, true);
  };
  const prev = () => {
    const it = cur();
    if (!it) return;
    if (inMc(it)) { jump((nowSec() - it.mc_ms / 1000 < 3 ? it.start_ms : it.mc_ms) / 1000); return; } // MC の前の曲 = いまの曲
    const head = nowSec() - it.start_ms / 1000 < 3;
    if (head && !inIntro(it) && thru() && it.intro_ms != null) { jump(it.intro_ms / 1000); return; } // 曲の頭 → その前のオープニング SE
    if (queue.length > 1 && head) {
      const pv = queue[(idx - 1 + queue.length) % queue.length];
      slide(-1); go(idx - 1, true, (hasMc(pv) ? pv.mc_ms : pv.start_ms) / 1000); // 前の曲の最後の区切り（MC か曲の頭。オープニング SE からではない）
    }
    else jump(it.start_ms / 1000);
  };
  // 管理者だけ: 止まっている理由を曲名の下に出す（待っているもの + 秒 / 最後に飛ばした理由）。流れ始めたら消す
  const diagEl = $('[data-shell-diag]');
  let waitMsg = '';
  let waitDetail = ''; // 進み具合（45% / 12.3MB）
  let waitAt = 0;
  let failMsg = '';
  let prepSrc = ''; // サーバーの用意を待っている音源（2秒ごとに &state=1 で様子を見る）
  const showDiag = () => {
    if (!diagEl) return;
    const msg = [waitMsg && `${waitMsg} ${waitDetail ? `${waitDetail} ` : ''}${Math.round((Date.now() - waitAt) / 1000)}秒`, failMsg].filter(Boolean).join(' / ');
    diagEl.hidden = !msg;
    diagEl.textContent = msg;
  };
  const diagWait = (msg, detail = '', prep = false) => {
    if (!prep) prepSrc = '';
    if (msg !== waitMsg) { waitMsg = msg; waitAt = Date.now(); }
    waitDetail = detail;
    showDiag();
  };
  let prepPhase = '';
  const prepWait = (src, phase) => { diagWait(phase); prepSrc = src; prepPhase = phase; };
  const MB = (n) => `${(n / 1048576).toFixed(1)}MB`;
  let asking = false;
  if (diagEl) {
    setInterval(() => { if (waitMsg) showDiag(); }, 1000);
    setInterval(async () => {
      if (!prepSrc || asking) return; // 前の様子見が返るまで次を送らない（サーバーが重いときに積まない）
      const src = prepSrc;
      asking = true;
      const s = await fetch(`${src}${src.includes('?') ? '&' : '?'}state=1`).then((r) => r.json()).catch(() => null);
      asking = false;
      if (src !== prepSrc || !s) return;
      if (s.step === 'download') diagWait('ドライブから取得中', s.total ? `${Math.round(s.done * 100 / s.total)}%` : MB(s.done), true);
      else if (s.step === 'convert') diagWait('変換中', MB(s.done), true);
      else diagWait(prepPhase, '', true);
    }, 2000);
  }
  // 流せなかった理由: 同じ音源に 1 バイトだけ頼んで、HTTP の番号とドライブの理由を見る（音源の error には番号が無い）
  const why = async (src, mediaErr) => {
    const res = await fetch(`${src}${src.includes('?') ? '&' : '?'}to=0`).catch(() => null);
    if (!res) return '通信できない';
    if (res.ok) return { 2: '途中で切れた', 3: '音源が壊れている', 4: '再生できない形式' }[mediaErr] ?? '長さを読めない';
    const up = res.headers.get('X-Upstream-Reason');
    return `${res.status} ${{ 404: 'ファイルがない', 502: 'ドライブ', 503: 'ドライブから取得中', 508: 'サーバーの上限' }[res.status] ?? ''}${up ? ` ${up}` : ''}`;
  };
  const skip = (mediaErr) => { // 流せなかった（mediaErr: 音源の error の番号）: 続けて全部だめなら止める
    fails += 1;
    if (diagEl && cur()) {
      const prefix = fails >= Math.min(queue.length, 5) ? '止めた' : '飛ばした';
      why(cur().src, mediaErr).then((m) => { failMsg = `${prefix} ${m}`; showDiag(); });
    }
    if (fails >= Math.min(queue.length, 5)) { busy = false; audio.pause(); setIcon(false); titleEl.textContent = '音源を読み込めませんでした'; waitMsg = ''; prepSrc = ''; showDiag(); return; }
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
      if (f) for (const k of ['title', 'key', 'artist_id', 'artist', 'album', 'art', 'band', 'live', 'players', 'through_end_ms', 'mc_ms', 'mc_kind', 'intro_ms', 'intro_kind', 'intro_se', 'mc_se', 'alb', 'yt']) q[k] = f[k]; // 通しの区切り: 前に保存したキューは古い数え方のまま（終わりのチャプターで止まっていた）
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
    // 外した音源（MIX / YouTube）にしか曲が無い範囲なら、その音源も入れて流す
    if (filter.src !== 'all' && !items.some(passes) && items.some(kept)) { filter.src = 'all'; saveFilter(); showFilter(); }
    queue = arrange(items.filter(passes), null);
    cfg.scope = scope;
    cfg.id = Number(id) || 0;
    fails = 0;
    syncUrl();
    showFilter();
  };

  on('play', () => setIcon(true));
  on('pause', () => { if (busy) return; setIcon(false); if (cur()) savePos(nowSec()); }); // 読み直しのために止めたときは、一時停止の表示にしない
  on('loadstart', () => { if (srcSeq === loadSeq) busy = false; }); // 連打で前の呼び出しが入れた src の loadstart では戻さない
  // 読み込み中は再生ボタンの輪を回す（app_player.js と同じ）
  const loading = (v) => playBtns.forEach((b) => b.classList.toggle('is-loading', v));
  on('play', () => loading(audio.readyState < 3));
  on('waiting', () => { loading(true); diagWait('読み込み中'); });
  on('loadstart', () => { if (srcSeq === loadSeq && cur()) prepWait(cur().src, '読み込み中'); }); // 初めの1回は、この要求もサーバーの用意を待つ
  on('loadeddata', () => { prepSrc = ''; });
  on('playing', () => { waitMsg = ''; failMsg = ''; prepSrc = ''; showDiag(); });
  on('pause', () => { if (!busy) { waitMsg = ''; prepSrc = ''; showDiag(); } });
  ['pause', 'ended', 'error'].forEach((ev) => on(ev, () => { if (!(ev === 'pause' && busy)) loading(false); }));
  on('playing', () => { fails = 0; loading(false); });
  on('ended', next);
  on('error', () => { if (audio.getAttribute('src')) skip(audio.error?.code); });
  // バンド単位: シークバーの上に曲（番号）と MC（通しのときだけ）の吹き出し（band.php のチャプターと同じ見た目）。押すとそこから
  //   曲間をスキップするときは、飛ばす所（曲の終わり〜次の曲の頭）を棒の上で薄くする（--gaps。通しでは .is-thru で色だけ消す）
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
        if (thru() && q.intro_ms != null) { const l = gapName(q.intro_kind || 'se'); marks.push({ k, ms: q.intro_ms, label: l, full: q.intro_se ? `SE. ${q.intro_se.title}` : l, kind: 'intro' }); }
        marks.push({ k, ms: q.start_ms, label: String(k - r.a + 1), full: `${k - r.a + 1}. ${q.title}`, kind: 'song' });
        if (hasMc(q)) { const l = gapName(q.mc_kind); marks.push({ k, ms: q.mc_ms, label: q.mc_kind === 'blank' ? '—' : l, // 曲なしの吹き出しはチャプターのチップと同じ「—」
           full: q.mc_se ? `SE. ${q.mc_se.title}` : l, kind: 'mc' }); }
        if (k === r.a && q.intro_ms != null && q.intro_ms < q.start_ms) gaps.push([x(q.intro_ms), x(q.start_ms)]); // 冒頭の MC・SE
        const nx = queue[k + 1];
        if (k < r.b && q.end_ms !== null && nx.start_ms > q.end_ms) gaps.push([x(q.end_ms), x(nx.start_ms)]);
      }
      marks.forEach((m) => { m.x = x(m.ms); });
    }
    const key = JSON.stringify([marks, gaps]);
    if (key !== bubbleKey) {
      bubbleKey = key;
      // チャプター（app_player.js）と同じく、残る吹き出しは使い回して位置を滑らせ、新しいものは ap-pop で出し、消えるものは ap-out で消す
      const old = new Map([...bubblesEl.children].filter((e) => !e.classList.contains('is-leaving')).map((e) => [e.dataset.id, e]));
      const motion = !matchMedia('(prefers-reduced-motion: reduce)').matches;
      let fresh = 0;
      const els = marks.map((m) => {
        const id = `${queue[m.k].song_id}|${m.kind}`;
        let b = old.get(id);
        old.delete(id);
        if (!b) {
          b = document.createElement('button');
          b.type = 'button';
          b.dataset.id = id;
          b.className = 'audio-bubble';
          if (motion) {
            b.style.setProperty('--i', fresh++);
            b.classList.add('is-new');
            b.addEventListener('animationend', () => b.classList.remove('is-new'), { once: true });
          }
        }
        b.textContent = m.label;
        b.dataset.k = m.k;
        b.dataset.ms = m.ms;
        b.dataset.kind = m.kind;
        b.style.setProperty('--x', m.x);
        b.setAttribute('aria-label', `${m.full} から再生`);
        b.title = `${m.full} から再生`;
        return b;
      });
      const gone = [...old.values()];
      gone.forEach((e) => {
        if (!motion) return;
        e.classList.add('is-leaving');
        e.addEventListener('animationend', () => e.remove(), { once: true });
      });
      bubblesEl.replaceChildren(...els, ...(motion ? gone : []));
      seekBox.classList.toggle('has-bubbles', marks.length > 0);
      // MC・SE はシークバーの下、曲は上（MC は次の曲の頭のすぐ手前で、上に並べると被るため）
      [...bubblesEl.children].forEach((b) => b.classList.toggle('is-below', b.dataset.kind !== 'song'));
      seekBox.classList.toggle('has-below', !!bubblesEl.querySelector('.is-below:not(.is-leaving)')); // 消えていく吹き出しは数えない（残ると下の余白が空いたまま）
      const thumb = (p) => `calc(var(--ap-thumb) / 2 + (100% - var(--ap-thumb)) * ${p})`; // 棒の上の位置（つまみの半分ずつ内側）
      seekBox.style.setProperty('--gaps', gaps.length
        ? `linear-gradient(to right, ${gaps.map(([a, b]) => { // 流した所（赤の塗りの端 = --q まで）は --gap-done
          const [l, r] = [a <= 0 ? '0%' : thumb(a), b >= 1 ? '100%' : thumb(b)]; // 棒の端に接する所は端まで（つまみの半分の赤が残らないように）
          const q = `clamp(${l}, var(--q) * 100%, ${r})`;
          return `transparent ${l}, var(--gap-done) ${l}, var(--gap-done) ${q}, var(--gap) ${q}, var(--gap) ${r}, transparent ${r}`;
        }).join(', ')})`
        : 'none');
    }
    seekBox.classList.toggle('is-thru', thru()); // 通しでは曲間も流すので、飛ばす所の色をフェードで消す（--gap-a）
    const kind = gapKind(it) || 'song';
    [...bubblesEl.children].forEach((b) => b.classList.toggle('is-current', Number(b.dataset.k) === idx && b.dataset.kind === kind));
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
    const fileEnd = durations.get(it.src) ? durations.get(it.src) * 1000 : null;
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
        if (jumpNext) { // 曲送りで飛んだ: 位置の変化を滑らせる（app_player.js の jump と同じ .is-jumping）
          jumpNext = false;
          if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
            seekBox.classList.add('is-jumping');
            clearTimeout(jumpTimer);
            jumpTimer = setTimeout(() => seekBox.classList.remove('is-jumping'), (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--t-base')) || .2) * 1500);
          }
        }
        seek.value = pos;
        seekBox.style.setProperty('--q', pos / len);
        curEl.textContent = fmt(pos);
      }
    }
  };
  on('timeupdate', () => {
    const it = cur();
    if (!it || busy) return;
    const t = nowSec() * 1000;
    showPos(t / 1000);
    const g = gapKind(it);
    const over = endOf(it) !== null && t >= endOf(it); // 曲の終わりを越えた: 下で次の曲へ進めるので、ここでは動かさない（MC を抜けたと見て逆向きに動くため）
    if (g !== mcShown && !over) { const d = g === 'mc' || mcShown === 'intro' ? 1 : -1; render(); slide(d); } // MC・SE に入った・抜けた（先へ進んだら右から、戻ったら左から）
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
      playAt(Math.max(t, k === r.a ? startOf(queue[k]) : queue[k].start_ms) / 1000, !audio.paused);
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

  // ポップアップ: 下のバー（ボタン以外のどこでも）・中のアイコンで開け閉め。外を押す・Esc で閉じる
  const opensPanel = (t) => bar.contains(t) && !panel.contains(t) && !t.closest('button:not([data-shell-title]), .toasts');
  bar.addEventListener('click', (e) => { if (opensPanel(e.target)) setPanel(!isOpen()); });
  // 曲名: 中のページで楽曲データベースのモーダルを開く（app_ui.js の songs?open= のリンクの仕組み。押したときだけ1曲分を取る）。無いページなら songs へ移る
  // 中のページの見えないリンクに type のイベントを送る（mouseover = 先読み、click = 開く）。送れたら true
  function songLinkEvent(el, type) { // 宣言を上げる（setPanel から呼ぶ）
    const doc = innerDoc();
    const href = el.getAttribute('href');
    if (!doc?.body || !href) return false;
    const a = doc.createElement('a');
    a.href = href;
    a.hidden = true;
    doc.body.append(a);
    a.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, button: 0 }));
    a.remove();
    return true;
  }
  const songLink = $('[data-shell-song]');
  // 触れた・乗せた瞬間に取り始める（押したときには取り終わっている）
  songLink.addEventListener('pointerenter', () => songLinkEvent(songLink, 'mouseover'));
  songLink.addEventListener('pointerdown', () => songLinkEvent(songLink, 'mouseover'));
  songLink.addEventListener('click', (e) => {
    if (!songLinkEvent(songLink, 'click')) return;
    e.preventDefault();
    setPanel(false);
  });
  window.abbeyShufflePanel = () => setPanel(!isOpen());
  const closeOutside = (e) => {
    // isConnected: 押したあとに作り直して消えたもの（範囲のチップ）は外ではない
    if (isOpen() && e.target.isConnected && !panel.contains(e.target) && !opensPanel(e.target) && !e.target.closest?.('[data-shuffle-menu] > summary, [data-album-modal]')) setPanel(false); // アルバムのモーダルの中は外ではない
  };
  const closeOnEsc = (e) => { if (e.key === 'Escape' && !filterDlg.open && !document.querySelector('[data-album-modal]').open) setPanel(false); }; // モーダル（絞り込み・アルバム）の Esc はモーダルだけ閉じる
  document.addEventListener('click', closeOutside);
  document.addEventListener('keydown', closeOnEsc);
  // 範囲: ポップアップに常に出す（チップ）。サイト全体・再生中のライブ/バンド/アーティスト・中のページの範囲（ヘッダーのメニューと同じもの）
  // 左のボタンで、押したらすぐ変えるか、いまの曲が終わってから変えるか（次の曲から = pending に置いて next で切り替える）
  const scopesEl = $('[data-shell-scopes]');
  const laterBtn = $('[data-shell-later]');
  let later = true; // 最初は次の曲から（外した人だけすぐ）
  try { later = localStorage.getItem('shuffle_when') !== 'now'; } catch (e) { /* 次の曲から */ }
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
  const livesCache = new Map(); // URL → Promise（api_shuffle の ?lives=1。絞り込みの中身・チップの曲数）
  const getLives = (url) => { // 開くたびに取り直さない（失敗したときだけ次に取り直す）
    if (!livesCache.has(url)) livesCache.set(url, fetch(url).then((res) => (res.ok ? res.json() : null)).catch(() => null).then((j) => { if (!j) livesCache.delete(url); return j; }));
    return livesCache.get(url);
  };
  const livesUrl = (scope, id) => `api_shuffle?scope=${encodeURIComponent(scope)}&id=${Number(id) || 0}&lives=1`;
  const livesDone = new Map(); // 読み終えた分（曲数をすぐ出す = チップを作り直しても幅が跳ねず、赤い枠の滑る先がずれない）
  const showCounts = () => scopesEl.querySelectorAll('[data-count]').forEach(async (n) => { // チップの曲数 = 絞り込みを通る曲
    const url = n.dataset.count;
    const data = livesDone.get(url) ?? await getLives(url);
    if (data) livesDone.set(url, data);
    const items = data ? data.items.filter(passes) : [];
    const bands = byBand && !url.includes('scope=band&'); // バンド単位はバンドの数（バンドの範囲は曲数のまま）
    const txt = !data ? '' : bands ? `${new Set(items.map((q) => q.band_id)).size}バンド` : `${items.reduce((a, q) => a + q.n, 0)}曲`;
    if (n.textContent && txt && n.textContent !== txt) { // 変わった数はフェードで出す（app.css .is-new）
      n.classList.remove('is-new');
      void n.offsetWidth; // 続けて変わってもアニメを頭から
      n.classList.add('is-new');
    }
    n.textContent = txt;
  });
  const scopeItem = (label, scope, id, name) => {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip';
    b.dataset.key = `${scope}:${id}`;
    b.textContent = label;
    b.classList.toggle('is-current', cfg.scope === scope && cfg.id === id); // いま流している範囲
    b.classList.toggle('is-pending', !!pending && pending.scope === scope && pending.id === id);
    b.addEventListener('click', () => {
      if ((label.startsWith('表示中の') && scope !== 'band') || label === '再生中のアーティスト') clearFilter(); else leaveScope();
      pick(scope, id);
    });
    if (scope !== 'band') { // シャッフルの印。シャッフル中だけ CSS で幅を広げて見せる（バンドは選ぶとシャッフルを外すので付けない）
      const ic = document.createElement('span');
      ic.className = 'icon';
      ic.setAttribute('aria-hidden', 'true');
      ic.textContent = 'shuffle';
      b.append(ic);
    }
    const n = document.createElement('span');
    n.className = 'chip__count';
    n.dataset.count = livesUrl(scope, id);
    b.append(n);
    if (name) {
      const nm = document.createElement('span');
      nm.className = 'chip__name';
      nm.textContent = name;
      nm.title = name;
      b.append(nm);
    }
    li.append(b);
    if (label === '再生中のバンド' && !b.classList.contains('is-current')) li.className = 'is-playing-band'; // バンド単位では隠す（app.css。選んでいる範囲なら残す）
    return li;
  };
  // 選んだ範囲が変わったら、新しいチップの色をフェードで出す（チップは作り直すので CSS の transition が効かない。.is-fresh = app.css）
  const buildScopes = () => {
    const before = ['is-current', 'is-pending'].map((c) => scopesEl.querySelector(`.chip.${c}`)?.dataset.key ?? (scopesEl.children.length ? '' : undefined)); // '' = どれも選ばれていなかった、undefined = 初めて作る
    const it = cur();
    const list = [['サイト全体', 'all', 0]];
    if (it) {
      list.push(['再生中のライブ', 'live', it.live_id, `${it.year ? `${String(it.year % 100).padStart(2, '0')}’ ` : ''}${it.live}`], ['再生中のバンド', 'band', it.band_id, it.band]);
      if (it.artist_id) list.push(['再生中のアーティスト', 'artist', it.artist_id, it.artist]);
    }
    innerDoc()?.querySelectorAll('[data-shuffle-menu] a[data-shuffle]').forEach((a) => {
      if (a.dataset.shuffle !== 'all') list.push([a.textContent, a.dataset.shuffle, Number(a.dataset.shuffleId) || 0, a.dataset.shuffleName]);
    });
    // 「再生中のバンド」と「表示中のバンド」が同じなら「表示中の〜」を残す（流している範囲なら is-current で選ばれた見た目になる）
    const here = new Set(list.filter(([label]) => !label.startsWith('再生中の')).map(([, sc, id]) => `${sc}:${id}`));
    const seen = new Set();
    scopesEl.replaceChildren(...list
      .filter(([label, sc, id]) => !(label.startsWith('再生中の') && here.has(`${sc}:${id}`)))
      .filter(([, sc, id]) => !seen.has(`${sc}:${id}`) && seen.add(`${sc}:${id}`))
      .map((x) => scopeItem(...x)));
    ['is-current', 'is-pending'].forEach((c, i) => {
      const to = scopesEl.querySelector(`.chip.${c}`);
      if (before[i] === undefined || to?.dataset.key === before[i]) return;
      to?.classList.add('is-fresh');
      if (before[i]) scopesEl.querySelector(`.chip[data-key="${before[i]}"]:not(.is-current, .is-pending)`)?.classList.add(`was-${c.slice(3)}`); // 外れたチップは前の色・枠から戻す
    });
    showCounts();
  };

  const pick = (scope, id) => {
    if (later && cur()) { pending = { scope, id }; buildScopes(); } else switchTo(scope, id, true);
  };

  // 絞り込み: 開くたびに全ライブを読み、年度（開ける）→ ライブのチェックを作る。いまの範囲の外のライブはチェックなし
  // 閉じたら保存して、変わっていれば読み直す。範囲の外にチェックを入れたら、チェックしたライブだけ（scope all・id -1 = どのチップでもない）
  const filterBtn = $('[data-shell-filter]');
  const yearsEl = $('[data-shell-filter-years]');
  let scopeBands = null; // 絞り込みを開いたときの、いまの範囲にあるバンドの ID（null = すべて）
  const srcBtns = bar.querySelectorAll('[data-shell-src]');
  const saveFilter = () => { try { localStorage.setItem('shuffle_filter', JSON.stringify(filter)); } catch (e) { /* 覚えなくても使える */ } };
  const showFilter = () => {
    filterBtn.setAttribute('aria-pressed', String(filter.off.length > 0 || filter.bands.length > 0 || cfg.scope !== 'all')); // 外したライブ・バンドがある・チップでサイト全体より狭めた
    srcBtns.forEach((b) => b.setAttribute('aria-pressed', String(filter.src === 'all' || filter.src === b.dataset.shellSrc)));
    showCounts();
  };
  showFilter();
  // チップで範囲を替えるとき、外したライブを戻す: 絞り込みで選んだライブから（id -1）・再生中のライブ / バンドから（ライブ1つの範囲で外したものが、広い範囲の曲を減らさないように）
  const clearFilter = () => { // 外したライブ・バンドを戻す（MIX / YouTube はそのまま）。変わったら true
    const changed = filter.off.length > 0 || filter.bands.length > 0;
    filter.off = [];
    filter.bands = [];
    saveFilter();
    showFilter();
    return changed;
  };
  const leaveScope = () => {
    if (cfg.id === -1 || cfg.scope === 'live' || cfg.scope === 'band') clearFilter();
  };
  // MIX / YouTube: それぞれ入り切り。両方は外せない
  srcBtns.forEach((b) => b.addEventListener('click', () => {
    const on = [...srcBtns].filter((x) => (x === b) !== (x.getAttribute('aria-pressed') === 'true')).map((x) => x.dataset.shellSrc);
    // 片方だけのときにそれを外したら、もう片方だけにする
    filter.src = on.length === 2 ? 'all' : on[0] ?? [...srcBtns].find((x) => x !== b).dataset.shellSrc;
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
  const tri = (c, boxes) => { // 中のバンドすべてならチェック、一部なら中間
    const on = boxes.filter((b) => b.checked).length;
    c.checked = on === boxes.length;
    c.indeterminate = on > 0 && on < boxes.length;
  };
  const syncYear = (d) => { // 年度・ライブのチェック = 中のバンドから
    d.querySelectorAll('[data-live-box]').forEach((c) => tri(c, [...c.closest('details').querySelectorAll('[data-band]')]));
    tri(d.querySelector('[data-year]'), [...d.querySelectorAll('[data-band]')]);
  };
  const fold = (c, ...label) => { // 開ける段: チェック・開閉の印・名前
    const d = document.createElement('details');
    const s = document.createElement('summary');
    const ic = document.createElement('span');
    ic.className = 'icon';
    ic.setAttribute('aria-hidden', 'true');
    ic.textContent = 'expand_more'; // 開ける印（開くと上向き）
    s.append(c, ic, ...label); // 開閉の印はチェックの右
    d.append(s);
    return d;
  };
  filterBtn.addEventListener('click', async () => {
    yearsEl.replaceChildren();
    filterDlg.showModal();
    const [data, inScope] = await Promise.all([
      getLives(livesUrl('all', 0)),
      cfg.scope === 'all' ? null : getLives(livesUrl(cfg.scope, cfg.id)),
    ]);
    scopeBands = inScope ? new Set(inScope.items.map((q) => q.band_id)) : null; // null = すべて範囲の中
    const years = new Map(); // 年度 → Map(live_id → { name, date, mix, yt, bands: Map(band_id → 名前) })
    (data?.items || []).forEach((q) => {
      if (!years.has(q.year)) years.set(q.year, new Map());
      const lv = years.get(q.year);
      if (!lv.has(q.live_id)) lv.set(q.live_id, { name: q.live, date: q.date || '9999', mix: false, yt: false, bands: new Map() });
      const v = lv.get(q.live_id);
      v.bands.set(q.band_id, q.band); // 出演順（api_shuffle の並び）
      if (q.date && q.date < v.date) v.date = q.date; // 何日かあるライブは初日
      v[q.yt ? 'yt' : 'mix'] = true; // passes と同じ分け方
    });
    years.forEach((lv, y) => years.set(y, new Map([...lv].sort((a, b) => a[1].date.localeCompare(b[1].date))))); // 日付順（日付なしは最後）
    const mark = (src) => bar.querySelector(`[data-shell-src="${src}"] > *`).cloneNode(true); // 見出しの MIX / YouTube の印を写す
    yearsEl.replaceChildren(...[...years].reverse().map(([year, lives]) => { // 新しい年度から
      const y = box(false, `${year}年度`);
      y.dataset.year = '';
      const d = fold(y, `${year}年度`);
      const sync = () => syncYear(d);
      y.addEventListener('change', () => { d.querySelectorAll('[data-band]').forEach((c) => { c.checked = y.checked; }); sync(); });
      const ul = document.createElement('ul');
      lives.forEach(({ name, mix, yt, bands }, id) => {
        const lc = box(false, name);
        lc.dataset.liveBox = '';
        const marks = document.createElement('span');
        marks.className = 'shell-filter__marks';
        if (yt) marks.append(mark('yt'));
        if (mix) marks.append(mark('mix'));
        const ld = fold(lc, name, marks);
        lc.addEventListener('change', () => { ld.querySelectorAll('[data-band]').forEach((c) => { c.checked = lc.checked; }); sync(); });
        const bl = document.createElement('ul');
        bands.forEach((band, bid) => {
          const li = document.createElement('li');
          const l = document.createElement('label');
          l.className = 'check';
          const c = box(!filter.off.includes(id) && !filter.bands.includes(bid) && (!scopeBands || scopeBands.has(bid)));
          c.dataset.band = String(bid);
          c.dataset.live = String(id);
          c.addEventListener('change', sync);
          l.append(c, band);
          li.append(l);
          bl.append(li);
        });
        ld.append(bl);
        const li = document.createElement('li');
        li.append(ld);
        ul.append(li);
      });
      d.append(ul);
      sync();
      d.querySelectorAll('[data-live-box]').forEach((c) => { c.closest('details').open = c.indeterminate; }); // 一部だけ外れた段は開いて見せる
      d.open = y.indeterminate;
      return d;
    }));
  });
  $('[data-shell-filter-clear]').addEventListener('click', () => { // 外したライブ・バンドを戻す。アーティスト・メンバーの範囲はそのまま、ほかはサイト全体に（MIX / YouTube はそのまま。モーダルは開いたまま）
    const keep = cfg.scope === 'artist' || cfg.scope === 'member';
    if (!keep) scopeBands = null;
    yearsEl.querySelectorAll('[data-band]').forEach((c) => { c.checked = !scopeBands || scopeBands.has(Number(c.dataset.band)); });
    yearsEl.querySelectorAll(':scope > details').forEach(syncYear);
    const changed = clearFilter();
    if (keep) { if (changed) pick(cfg.scope, cfg.id); } else if (changed || cfg.scope !== 'all' || cfg.id !== 0) pick('all', 0);
  });
  filterDlg.addEventListener('close', () => {
    const shown = [...yearsEl.querySelectorAll('[data-band]')];
    if (!shown.length) return; // 読み終わる前に閉じた
    const bandOf = (c) => Number(c.dataset.band);
    const liveOf = (c) => Number(c.dataset.live);
    const inside = (c) => !scopeBands || scopeBands.has(bandOf(c));
    const widen = shown.some((c) => c.checked && !inside(c)); // 範囲の外にチェックを入れた
    const on = (c) => (widen || inside(c) ? c.checked : !filter.off.includes(liveOf(c)) && !filter.bands.includes(bandOf(c))); // 範囲の外は前の絞り込みのまま
    const byLive = new Map();
    shown.forEach((c) => byLive.set(liveOf(c), [...(byLive.get(liveOf(c)) || []), c]));
    const shownBands = new Set(shown.map(bandOf));
    const off = filter.off.filter((id) => !byLive.has(id)); // 一覧に無いライブ・バンドは前のまま
    const bands = filter.bands.filter((id) => !shownBands.has(id));
    byLive.forEach((cs, id) => {
      if (!cs.some(on)) off.push(id); // ライブごと外した（あとで入るバンドも外す）
      else bands.push(...cs.filter((c) => !on(c)).map(bandOf));
    });
    const next = { src: filter.src, off, bands };
    if (!widen && JSON.stringify(next) === JSON.stringify(filter)) return;
    filter = next;
    saveFilter();
    showFilter();
    if (widen) pick('all', -1); else pick(cfg.scope, cfg.id);
  });

  // 通し: 曲間（MC など）も流す。ON にしたら、いまの曲も MC まで受け取れるよう、いまの位置から読み直す（OFF は timeupdate で止まるだけ）
  const contBtn = $('[data-shell-cont]');
  // 通しで流す所まで受け取っていないときだけ読み直す（読み直すと一瞬途切れる）
  const needMore = () => thru() && cur() && audio.getAttribute('src') && (endOf(cur()) ?? Infinity) / 1000 > elEnd;
  const showCont = () => contBtn.setAttribute('aria-pressed', String(!cont)); // 光る = 曲間をスキップしている
  contBtn.addEventListener('click', () => {
    cont = !cont;
    save();
    showCont();
    if (needMore()) playAt(nowSec(), !audio.paused);
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
    // 再生中のバンドのチップ: バンド単位で消してから残りを詰める・曲単位で場所を空けてから出す（CSS の display: none）
    const chip = scopesEl.querySelector('.is-playing-band');
    if (scopesEl.classList.contains('is-band') === byBand) return showCounts();
    const motion = chip && !matchMedia('(prefers-reduced-motion: reduce)').matches;
    const css = getComputedStyle(document.documentElement);
    const ms = (t) => (parseFloat(css.getPropertyValue(t)) || .2) * 1000;
    const ease = css.getPropertyValue('--ease-out').trim() || 'ease-out';
    const flip = () => { // 並びが変わったチップを前の位置から滑らせる
      const lis = [...scopesEl.children];
      const from = lis.map((e) => e.getBoundingClientRect());
      scopesEl.classList.toggle('is-band', byBand);
      if (motion) lis.forEach((e, i) => {
        const to = e.getBoundingClientRect();
        const [dx, dy] = [from[i].left - to.left, from[i].top - to.top];
        if (to.width && from[i].width && (dx || dy)) e.animate({ translate: [`${dx}px ${dy}px`, '0 0'] }, { duration: ms('--t-slow'), easing: ease });
      });
      if (motion) { // 滑る間は枠の外を切る（行が減ると、下の行から上がるチップの分だけ一瞬ポップアップに縦のスクロールバーが出る）
        scopesEl.classList.add('is-moving');
        setTimeout(() => scopesEl.classList.remove('is-moving'), ms('--t-slow'));
      }
    };
    if (!motion) flip();
    else if (byBand) chip.animate({ opacity: [1, 0], scale: [1, .8] }, { duration: ms('--t-base'), easing: 'ease-in', fill: 'forwards' }).finished.then((a) => { // ap-out と同じ
      if (byBand && !scopesEl.classList.contains('is-band')) flip();
      a.cancel();
    }).catch(() => {}); // 途中で作り直された
    else {
      flip();
      chip.animate({ opacity: [0, 1], scale: [.8, 1] }, { duration: ms('--t-base'), easing: ease, delay: ms('--t-fast'), fill: 'backwards' }); // ap-pop と同じ
    }
    showCounts(); // チップの「◯曲」⇔「◯バンド」
  };
  unitBtn.addEventListener('click', () => {
    const was = thru();
    byBand = !byBand;
    reorder();
    showUnit();
    if (cur()) showPos(nowSec()); // シークバーの範囲・吹き出し
    toast(byBand ? 'バンド単位で再生します' : '曲単位で再生します');
    if (!was && needMore()) playAt(nowSec(), !audio.paused); // 通しが効き始めたら MC まで受け取れるよう読み直す
  });

  // 閉じる: 再生をやめて、いま中で開いているページだけにする（止めた位置は残す。アイコンで開き直せる）
  $('[data-shell-close]').addEventListener('click', () => {
    if (cur() && audio.getAttribute('src')) savePos(nowSec());
    let left = false;
    const leave = () => { if (!left) { left = true; location.href = innerPage() || './'; } };
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { leave(); return; }
    audio.pause();
    bar.classList.add('is-closing'); // 下へ沈んでから移る（app.css の shell-bar-out）
    bar.addEventListener('animationend', (e) => { if (e.target === bar) leave(); }); // 中の要素の動きの終わりは数えない
    setTimeout(leave, 600); // animationend が来なくても移る
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
    if (cfg.scope !== 'all' || cfg.id) q.set('id', cfg.id);
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
    if (['live', 'member', 'artist'].includes(scope)) clearFilter(); else leaveScope(); // 表示中の〜（バンド以外）は絞り込みを外す
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
      queue = st.queue.filter((q) => !q.video); // 前に保存した動画（埋め込み）で流す曲は外す。YouTube も MP3 だけ流す（api_shuffle.php）
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
      showFilter();
      const it = queue[st.idx];
      // 残した位置がその曲の中のときだけ使う
      const ok = it && pos !== null && pos * 1000 >= startOf(it) && (endOf(it) === null || pos * 1000 < endOf(it)); // 通しなら MC の途中も
      await go(st.idx, false, ok ? pos : undefined);
      refresh(cur()); // 保存したキューは古いことがある（演奏者・通しの終わり）
      if (cfg.resume) setPanel(true);
      return;
    }
    await load(cfg.scope, cfg.id);
    go(0, true);
  })();

  // 外枠の高さ = 実際の画面の高さ。Android のアプリで × → 開き直すと、100dvh が 57px 大きいまま残り、下のバーとタブが画面の外に出ていた（innerHeight は正しい）
  const fitHeight = () => { document.body.style.height = `${innerHeight}px`; };
  fitHeight();
  addEventListener('resize', fitHeight);
  setupAlbumModal(); // ジャケットを押したときのアルバムのモーダル（app_album.js）
})();
