/*
 * =====================================================================
 *  ytplayer.js — YouTube の練習プレイヤー（tab.php?link=）
 * =====================================================================
 *  tab.php が <script type="application/json" id="yt-data"> に入れた {link_id, video, practice} を読んで動く。
 *    AB リピート … A・B を現在位置で決めて、ループをオンにすると B に来たら A へ戻る
 *    マーク      … 現在位置に印。ラベル（イントロ・サビ…）を付けると曲構成になる。押すとその位置へ移動
 *    ◯秒戻す    … 秒数は自分で決められる（1〜60）。← / → キーでも
 *    速度        … YouTube が使える速度（0.25〜2）を − / ＋ で1段ずつ
 *  設定（マーク・AB・速度・秒数）は自分だけのもの。変更してから約1秒で api_tab_save の link_practice に自動保存する
 *  XSS 対策: マークのラベルは textContent / value にだけ入れる（innerHTML には入れない）
 * =====================================================================
 */
(() => {
  'use strict';

  const dataEl = document.getElementById('yt-data');
  const root = document.querySelector('[data-ytp]');
  if (!dataEl || !root) return;
  const D = JSON.parse(dataEl.textContent);
  const P = D.practice; // {m: [{t, e, l}], ab: [A, B] | null, rate, back}（時刻はミリ秒）
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (sel) => root.querySelector(sel);

  // 未ログインの設定は端末（localStorage）に持つ。形は読むときに確かめる
  const LS_KEY = D.local ? 'yt_practice_v_' + D.video : 'yt_practice_' + D.link_id; // D.local = DB に無い動画（動画ID で持つ）
  if (D.guest) {
    try {
      const s = JSON.parse(localStorage.getItem(LS_KEY) || 'null');
      const num = (v) => typeof v === 'number' && Number.isFinite(v);
      if (s && typeof s === 'object') {
        if (s.ch === 1) P.ch = 1;
        if (Array.isArray(s.m)) P.m = s.m.filter((x) => x && num(x.t) && x.t >= 0).slice(0, 100).map((x) => ({ t: x.t, e: num(x.e) ? x.e : null, l: typeof x.l === 'string' ? x.l.slice(0, 30) : '' }));
        if (Array.isArray(s.ab) && s.ab.length === 2 && s.ab.every(num)) P.ab = s.ab;
        if (num(s.rate) && s.rate >= 0.25 && s.rate <= 2) P.rate = s.rate;
        if (num(s.back) && s.back >= 1 && s.back <= 60) P.back = s.back;
        if (num(s.pad) && s.pad >= 0 && s.pad <= 10) P.pad = s.pad;
      }
    } catch (err) { /* 読めなければ初期値 */ }
  }
  // ゲストは、動画にチャプターがあれば最初に開いたとき1回だけマーカーにする（以後の編集は端末に残す）
  if (D.guest && !P.ch && Array.isArray(D.chapters)) {
    P.m = D.chapters.slice(0, 100).map((x) => ({ t: x.t, l: String(x.l).slice(0, 30) }));
    P.ch = 1;
    try { localStorage.setItem(LS_KEY, JSON.stringify({ m: P.m, ab: P.ab, rate: P.rate, back: P.back, pad: P.pad, ch: 1 })); } catch (err) { /* 端末に保存できなければ次回また */ }
  }
  // 曲構成のラベル（イントロ・サビ…）のマーカーは動画ごとに全員共通（song_link_shared_mark）。それ以外は人ごと。
  //   D.shared が null の間（まだ誰も共通に保存していない）は、人ごとのマーカーに入っているものをそのまま使う
  const SHARED = D.local ? new Set() : new Set([...root.querySelectorAll('[data-ytp-sec]')].map((b) => b.dataset.ytpSec)); // DB に無い動画は共通マーカーも端末に持つ（SHARED を空にして全部人ごと扱い）
  if (Array.isArray(D.shared)) {
    P.m = [...P.m.filter((m) => !SHARED.has(m.l)), ...D.shared.filter((x) => SHARED.has(x.l)).map((x) => ({ t: x.t, e: null, l: x.l }))].sort((a, b) => a.t - b.t);
  }
  let player = null, ready = false, playing = false, loop = !!P.ab;
  const RATES_DEFAULT = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

  const fmt = (ms) => {
    const s = Math.max(0, Math.floor(ms / 1000));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  };
  const now = () => (ready ? Math.round((player.getCurrentTime() || 0) * 1000) : 0);
  const dur = () => (ready ? Math.round((player.getDuration() || 0) * 1000) : 0);
  const seek = (ms) => { if (ready) player.seekTo(Math.max(0, ms) / 1000, true); };
  const icon = (name) => { const s = document.createElement('span'); s.className = 'icon'; s.setAttribute('aria-hidden', 'true'); s.textContent = name; return s; };
  function notify(message, type) {
    const t = document.createElement('div');
    t.className = 'toast' + (type === 'error' ? ' toast--error' : '');
    t.setAttribute('role', 'status');
    t.textContent = message;
    if (typeof window.showToast === 'function') window.showToast(t); else window.alert(message);
  }

  // ---- 自動保存（変更から約1秒後。ページを閉じるときは keepalive で送る） ----
  let saveTimer = null;
  // 未ログイン（D.guest）は端末に保存
  const saveLocal = () => {
    try { localStorage.setItem(LS_KEY, JSON.stringify({ m: personal(), ab: P.ab, rate: P.rate, back: P.back, pad: P.pad, ch: P.ch })); } catch (err) { /* 保存できない端末では何もしない */ }
    return Promise.resolve();
  };
  const personal = () => P.m.filter((m) => !SHARED.has(m.l));
  const sharedList = () => P.m.filter((m) => SHARED.has(m.l)).map((m) => ({ t: m.t, l: m.l }));
  const post = (body, keepalive) => fetch('api_tab_save', {
    method: 'POST',
    keepalive: !!keepalive,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    body: JSON.stringify(body),
  }).then(async (res) => { if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || '保存できませんでした'); });
  let sentShared = JSON.stringify(sharedList()); // 変わったときだけ共通マーカーを送る
  const sendShared = (keepalive) => {
    const list = sharedList(), sig = JSON.stringify(list), prev = sentShared;
    if (sig === prev) return Promise.resolve();
    sentShared = sig;
    return post({ action: 'link_shared', link_id: D.link_id, m: list }, keepalive).catch((e) => { sentShared = prev; throw e; });
  };
  const send = (keepalive) => Promise.all([
    D.guest ? saveLocal() : post({ action: 'link_practice', link_id: D.link_id, data: { m: personal(), ab: P.ab, rate: P.rate, back: P.back, pad: P.pad, ch: P.ch } }, keepalive),
    sendShared(keepalive),
  ]);
  function changed() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { saveTimer = null; send().catch((e) => notify(e.message, 'error')); }, 1000);
  }
  window.addEventListener('pagehide', () => { if (saveTimer) { clearTimeout(saveTimer); send(true).catch(() => {}); } });

  // ---- 再生したら、練習プレイヤーの「練習中」リストに載せる（開くたびに最初の再生で1回だけ） ----
  let marked = false;
  function markPracticing() {
    if (marked || D.guest) return;
    marked = true;
    fetch('api_tab_save', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify({ action: 'link_practicing', link_id: D.link_id, on: true }),
    }).catch(() => { marked = false; });
  }

  // ---- YouTube の IFrame API ----
  const s = document.createElement('script');
  s.src = 'https://www.youtube.com/iframe_api';
  window.onYouTubeIframeAPIReady = () => {
    player = new window.YT.Player($('[data-ytp-mount]'), {
      videoId: D.video,
      width: '100%',
      height: '100%',
      playerVars: { playsinline: 1, rel: 0 },
      events: {
        onReady: () => { ready = true; setRate(P.rate); render(); },
        onStateChange: (e) => { playing = e.data === 1 || e.data === 3; if (e.data === 1) markPracticing(); render(); },
        onPlaybackRateChange: () => { P.rate = player.getPlaybackRate() || 1; renderRate(); },
      },
    });
  };
  document.head.appendChild(s);

  // ---- 再生・戻す・進める ----
  const playBtn = $('[data-ytp-play]');
  const togglePlay = () => { if (!ready) return; if (playing) player.pauseVideo(); else player.playVideo(); };
  playBtn.addEventListener('click', togglePlay);
  const backSec = $('[data-ytp-backsec]');
  backSec.value = P.back;
  backSec.addEventListener('change', () => {
    P.back = Math.min(60, Math.max(1, Math.floor(Number(backSec.value)) || 5));
    backSec.value = P.back;
    changed();
  });
  P.pad ??= 2;
  const padSec = $('[data-ytp-pad]');
  padSec.value = P.pad;
  padSec.addEventListener('change', () => {
    const v = Math.floor(Number(padSec.value));
    P.pad = Number.isFinite(v) ? Math.min(10, Math.max(0, v)) : 2;
    padSec.value = P.pad;
    changed();
  });
  const back = () => seek(now() - P.back * 1000);
  const fwd = () => seek(now() + P.back * 1000);
  $('[data-ytp-back]').addEventListener('click', back);
  $('[data-ytp-fwd]').addEventListener('click', fwd);

  // ---- 全画面（動画に操作を重ねる）と拡大（全体 → 上半分 → 下半分）。動画内の TAB 譜を大きく見る用 ----
  const ZOOMS = ['', 'top', 'bottom'];
  const ZOOM_ICON = { '': 'fit_screen', top: 'vertical_align_top', bottom: 'vertical_align_bottom' };
  const fsBtn = $('[data-ytp-fs]'), zoomBtn = $('[data-ytp-zoom]');
  let zoom = '';
  try { const z = localStorage.getItem('ytp_zoom'); if (ZOOMS.includes(z)) zoom = z; } catch (err) { /* 保存できない端末では全体 */ }
  const isFull = () => root.classList.contains('is-full');
  function renderFull() {
    root.classList.toggle('is-zoom-top', zoom === 'top');
    root.classList.toggle('is-zoom-bottom', zoom === 'bottom');
    zoomBtn.querySelector('.icon').textContent = ZOOM_ICON[zoom];
    fsBtn.querySelector('.icon').textContent = isFull() ? 'fullscreen_exit' : 'fullscreen';
    document.body.classList.toggle('ytp-lock', isFull());
  }
  function setFull(on) {
    root.classList.toggle('is-full', on);
    renderFull();
    if (on) {
      // 端末が対応していれば本物の全画面 + 横向き。だめでも画面いっぱいの重ね表示で動く
      root.requestFullscreen?.()?.then(() => screen.orientation?.lock?.('landscape')?.catch(() => {})).catch(() => {});
    } else {
      if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
      try { screen.orientation?.unlock?.(); } catch (err) { /* 非対応 */ }
    }
  }
  fsBtn.addEventListener('click', () => setFull(!isFull()));
  zoomBtn.addEventListener('click', () => {
    zoom = ZOOMS[(ZOOMS.indexOf(zoom) + 1) % ZOOMS.length];
    try { localStorage.setItem('ytp_zoom', zoom); } catch (err) { /* 保存できなければ次回は全体 */ }
    renderFull();
  });
  document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement && isFull()) setFull(false); }); // 端末の戻る操作で抜けたとき
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && isFull() && !document.fullscreenElement) setFull(false); });
  renderFull();

  // ---- 速度（YouTube が使える速度に1段ずつ） ----
  const rates = () => (ready && player.getAvailablePlaybackRates ? player.getAvailablePlaybackRates() : RATES_DEFAULT);
  function setRate(r) {
    if (!ready) return;
    const avail = rates();
    const snap = avail.reduce((a, b) => (Math.abs(b - r) < Math.abs(a - r) ? b : a), avail[0]);
    player.setPlaybackRate(snap);
    P.rate = snap;
    renderRate();
  }
  const stepRate = (dir) => {
    const avail = rates();
    const i = avail.findIndex((r) => Math.abs(r - P.rate) < 0.001);
    const next = avail[Math.min(avail.length - 1, Math.max(0, (i < 0 ? avail.indexOf(1) : i) + dir))];
    if (next !== undefined) { setRate(next); changed(); }
  };
  $('[data-ytp-slower]').addEventListener('click', () => stepRate(-1));
  $('[data-ytp-faster]').addEventListener('click', () => stepRate(1));
  $('[data-ytp-rate1]').addEventListener('click', () => { setRate(1); changed(); });
  function renderRate() { $('[data-ytp-rate]').textContent = Math.round(P.rate * 100) + '%'; }

  // ---- AB リピート ----
  const loopBtn = $('[data-ytp-loop]');
  function setAB(which) {
    const t = now();
    const [a, b] = P.ab || [pendingAB?.a ?? null, pendingAB?.b ?? null]; // 片方だけ決めた状態からも続ける
    let na = which === 'a' ? t : a, nb = which === 'b' ? t : b;
    if (na !== null && nb !== null && na >= nb) { if (which === 'a') nb = null; else na = null; } // 逆転したら反対側を外す
    P.ab = na !== null && nb !== null ? [na, nb] : null;
    pendingAB = P.ab ? null : { a: na, b: nb };
    if (P.ab) loop = true;
    changed();
    render();
  }
  let pendingAB = null; // 片方だけ決めた状態（保存は両方そろってから）
  $('[data-ytp-a]').addEventListener('click', () => setAB('a'));
  $('[data-ytp-b]').addEventListener('click', () => setAB('b'));
  loopBtn.addEventListener('click', () => { loop = !loop; render(); });
  $('[data-ytp-abclear]').addEventListener('click', () => { P.ab = null; pendingAB = null; loop = false; changed(); render(); });

  // ---- マーク・曲構成 ----
  const marksEl = $('[data-ytp-marks]');
  // 追加・削除は編集中だけ。マークが無い初期は最初から編集中
  let editing = P.m.length === 0;
  const editBtn = $('[data-ytp-edit]');
  const secsEl = $('[data-ytp-secs]');
  function applyEdit() {
    secsEl.classList.toggle('is-closed', !editing);
    secsEl.inert = !editing;
    marksEl.classList.toggle('is-editing', editing);
    editBtn.setAttribute('aria-pressed', String(editing));
    editBtn.classList.toggle('is-active', editing);
  }
  editBtn.addEventListener('click', () => { editing = !editing; applyEdit(); });
  function addMark(label) {
    if (P.m.length >= 100) { notify('マークは100個までです', 'error'); return; }
    P.m.push({ t: now(), e: null, l: label || '' });
    P.m.sort((x, y) => x.t - y.t);
    changed();
    renderMarks();
  }
  // マークから次のマークまで（最後のマークは動画の終わりまで）を AB にして繰り返す。前後に余白（P.pad 秒）を足す
  function repeatMark(m) {
    const i = P.m.indexOf(m);
    const end = P.m[i + 1]?.t ?? dur();
    if (i < 0 || end <= m.t) return;
    const pad = P.pad * 1000;
    const a = Math.max(0, m.t - pad), b = Math.min(dur() || end + pad, end + pad);
    P.ab = [a, b];
    pendingAB = null;
    loop = true;
    seek(a);
    if (ready && !playing) player.playVideo();
    changed();
    render();
  }
  // 練習用マーカーは「マーカー1」「マーカー2」…と、空いている最小でなく最大+1の番号で付ける
  const nextMarkerName = () => 'マーカー' + (Math.max(0, ...P.m.map((m) => Number(m.l.match(/^マーカー(\d+)$/)?.[1] || 0))) + 1);
  $('[data-ytp-mark]').addEventListener('click', () => addMark(nextMarkerName()));
  root.querySelectorAll('[data-ytp-sec]').forEach((b) => b.addEventListener('click', () => addMark(b.dataset.ytpSec)));
  // 曲構成の定型ラベル（イントロ・サビ…）以外は自分用のマークとして見た目を分ける
  const isOwn = (m) => !SHARED.has(m.l);
  function renderMarks() {
    marksEl.replaceChildren();
    P.m.forEach((m, i) => {
      const li = document.createElement('li');
      li.className = 'ytp__mark';
      const go = document.createElement('button');
      go.type = 'button';
      go.className = 'tab-chip';
      go.title = '移動';
      go.textContent = fmt(m.t);
      go.addEventListener('click', () => { seek(m.t); if (ready && !playing) player.playVideo(); });
      const name = document.createElement('input');
      name.type = 'text';
      name.maxLength = 20;
      name.value = m.l;
      name.placeholder = 'ラベル';
      name.setAttribute('aria-label', 'ラベル');
      name.addEventListener('change', () => { m.l = name.value.trim(); changed(); });
      // ここから次のマークまでを AB に（曲構成の1区間だけを繰り返す）
      const ab = document.createElement('button');
      ab.type = 'button';
      ab.className = 'icon-btn';
      ab.title = '次のマークまでを AB リピート';
      ab.setAttribute('aria-label', ab.title);
      ab.appendChild(icon('repeat_one'));
      ab.addEventListener('click', () => repeatMark(m));
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'icon-btn ytp__del';
      del.title = '削除';
      del.setAttribute('aria-label', '削除');
      del.appendChild(icon('delete'));
      // 削除は2回押し（1回目で赤くなり、3秒以内にもう一度押すと削除）
      let armTimer = null;
      del.addEventListener('click', () => {
        if (armTimer === null) {
          del.classList.add('is-armed');
          del.title = del.ariaLabel = 'もう一度で削除';
          armTimer = setTimeout(() => {
            armTimer = null;
            del.classList.remove('is-armed');
            del.title = del.ariaLabel = '削除';
          }, 3000);
          return;
        }
        clearTimeout(armTimer);
        P.m.splice(i, 1);
        changed();
        renderMarks();
      });
      li.classList.toggle('ytp__mark--own', isOwn(m));
      li.append(go, name, ab);
      li.appendChild(del); // 表示は .is-editing が切り替える（アニメーションのため常に置く）
      marksEl.appendChild(li);
    });
    renderLine();
  }

  // ---- タイムライン ----
  const line = $('[data-ytp-line]');
  const pos = $('[data-ytp-pos]');
  const band = $('[data-ytp-abband]');
  const bubbles = $('[data-ytp-bubbles]');
  const bubblesUp = $('[data-ytp-bubbles-up]');
  // 押す・ドラッグで再生位置を変更
  const seekAt = (e) => {
    const r = line.getBoundingClientRect();
    seek(Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)) * dur());
  };
  let dragging = false;
  line.addEventListener('pointerdown', (e) => { dragging = true; line.setPointerCapture(e.pointerId); seekAt(e); });
  line.addEventListener('pointermove', (e) => { if (dragging) seekAt(e); });
  const endDrag = () => { dragging = false; };
  line.addEventListener('pointerup', endDrag);
  line.addEventListener('pointercancel', endDrag);
  // 吹き出しの短縮名: Aメロ→A、イントロ→In、アウトロ→Out、ほかは先頭3文字
  const bubbleEls = new Map(); // マーク → 吹き出し要素
  const SHORT ={ 'イントロ': 'In', 'アウトロ': 'Out', 'ソロ': 'Solo' };
  const shortLabel = (l) => SHORT[l] || l.replace(/^マーカー(\d+)$/, 'M$1').match(/^M\d+$/)?.[0] || l.match(/^([A-Za-z])メロ$/)?.[1] || [...l].slice(0, 3).join('');
  let lineSig = '';
  function renderLine() {
    // マーク・長さ・幅が同じなら作り直さない（早送りなどの再生状態の更新で吹き出しが再アニメーションしないように）
    const sig = [dur(), bubbles.clientWidth, ...P.m.map((m) => m.t + ':' + m.l)].join('|');
    if (sig === lineSig) return;
    lineSig = sig;
    line.querySelectorAll('.ytp__tick').forEach((t) => t.remove());
    // 再配置は前の位置から滑らせる（FLIP）。前に無かった吹き出しだけ出現アニメーション
    const prev = new Map(bubbleEls);
    const prevRect = new Map([...prev].map(([m, el]) => [m, el.getBoundingClientRect()]));
    bubbleEls.clear();
    bubbles.replaceChildren();
    bubblesUp.replaceChildren();
    const d = dur();
    if (!d) return;
    // 下段 → 上段の順に、被らない方へ配置（右端の位置を段ごとに記録）
    const lanes = [{ el: bubbles, end: -Infinity }, { el: bubblesUp, end: -Infinity, up: true }];
    const W = bubbles.clientWidth;
    [...P.m].sort((a, b) => a.t - b.t).forEach((m) => {
      if (m.l) {
        const b = document.createElement('span');
        b.className = 'ytp__bubble' + (isOwn(m) ? ' ytp__bubble--own' : '');
        b.style.left = (m.t / d) * 100 + '%';
        b.textContent = shortLabel(m.l);
        b.addEventListener('click', () => repeatMark(m)); // 吹き出しを押してもそのマークをリピート
        bubbles.appendChild(b);
        const x = (m.t / d) * W, w = b.offsetWidth, l = x - w / 2;
        const lane = lanes.find((ln) => l >= ln.end + 2) || lanes[0];
        if (lane.up) { b.classList.add('ytp__bubble--up'); bubblesUp.appendChild(b); }
        lane.end = x + w / 2;
        bubbleEls.set(m, b);
        const o = prevRect.get(m);
        if (o) {
          b.style.animation = 'none';
          const n = b.getBoundingClientRect();
          const dx = o.left - n.left, dy = o.top - n.top;
          if (dx || dy) b.animate([{ transform: `translate(${dx}px, ${dy}px) translateX(-50%)` }, { transform: 'translateX(-50%)' }], { duration: 200, easing: 'ease-out' });
        }
      }
      const t = document.createElement('div');
      t.className = 'ytp__tick' + (isOwn(m) ? ' ytp__tick--own' : ' ytp__tick--sec');
      t.style.left = (m.t / d) * 100 + '%';
      t.title = (m.l ? m.l + ' ' : '') + fmt(m.t);
      line.appendChild(t);
    });
  }

  // 幅が変わったら被り判定をやり直す（回転・リサイズ）
  let lastW = bubbles.clientWidth;
  new ResizeObserver(() => {
    if (bubbles.clientWidth !== lastW) { lastW = bubbles.clientWidth; renderLine(); }
  }).observe(bubbles);

  function render() {
    playBtn.replaceChildren(icon(playing ? 'pause' : 'play_arrow'));
    const ab = P.ab || (pendingAB ? [pendingAB.a, pendingAB.b] : [null, null]);
    $('[data-ytp-at]').textContent = ab[0] !== null ? fmt(ab[0]) : '';
    $('[data-ytp-bt]').textContent = ab[1] !== null ? fmt(ab[1]) : '';
    loopBtn.setAttribute('aria-pressed', String(loop && !!P.ab));
    loopBtn.classList.toggle('is-active', loop && !!P.ab);
    const d = dur();
    band.hidden = !P.ab || !d;
    if (P.ab && d) {
      band.style.left = (P.ab[0] / d) * 100 + '%';
      band.style.width = ((P.ab[1] - P.ab[0]) / d) * 100 + '%';
    }
    $('[data-ytp-dur]').textContent = fmt(d);
    renderRate();
    renderLine();
  }

  // ---- 毎フレーム: 再生位置の表示と AB リピート ----
  (function tick() {
    if (ready) {
      const t = now(), d = dur();
      if (loop && P.ab && playing && t >= P.ab[1]) seek(P.ab[0]);
      pos.style.left = d ? (t / d) * 100 + '%' : '0';
      $('[data-ytp-cur]').textContent = fmt(t);
    }
    requestAnimationFrame(tick);
  })();

  // ---- マーカージャンプ: 現在位置の前後で一番近いマーカーへ ----
  // 前は「マーカーの直後」で押しても1つ前へ行けるよう、1秒の余裕を見る。前が無ければ先頭へ
  function jumpMark(dir) {
    const t = now();
    const ts = P.m.map((m) => m.t).sort((a, b) => a - b);
    const to = dir < 0 ? ts.filter((x) => x < t - 1000).pop() ?? 0 : ts.find((x) => x > t + 100);
    if (to === undefined) return;
    seek(to);
  }
  $('[data-ytp-start]').addEventListener('click', () => seek(0));
  $('[data-ytp-prevmark]').addEventListener('click', () => jumpMark(-1));
  $('[data-ytp-nextmark]').addEventListener('click', () => jumpMark(1));

  // ---- キーボード（入力欄に書いているときは効かない） ----
  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select') || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === ' ') { e.preventDefault(); togglePlay(); }
    else if (e.key === 'Home') { e.preventDefault(); seek(0); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); if (e.shiftKey) jumpMark(-1); else back(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); if (e.shiftKey) jumpMark(1); else fwd(); }
    else if ((e.key === 'm' || e.key === 'M') && editing) addMark(nextMarkerName());
  });

  applyEdit();
  renderMarks();
  render();
})();
