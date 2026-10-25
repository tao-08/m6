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
  const send = (keepalive) => fetch('api_tab_save', {
    method: 'POST',
    keepalive: !!keepalive,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    body: JSON.stringify({ action: 'link_practice', link_id: D.link_id, data: { m: P.m, ab: P.ab, rate: P.rate, back: P.back } }),
  }).then(async (res) => { if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || '保存できませんでした'); });
  function changed() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { saveTimer = null; send().catch((e) => notify(e.message, 'error')); }, 1000);
  }
  window.addEventListener('pagehide', () => { if (saveTimer) { clearTimeout(saveTimer); send(true).catch(() => {}); } });

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
        onStateChange: (e) => { playing = e.data === 1 || e.data === 3; render(); },
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
  const back = () => seek(now() - P.back * 1000);
  const fwd = () => seek(now() + P.back * 1000);
  $('[data-ytp-back]').addEventListener('click', back);
  $('[data-ytp-fwd]').addEventListener('click', fwd);

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
    const [a, b] = P.ab || [null, null];
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
  function addMark(label) {
    if (P.m.length >= 100) { notify('マークは100個までです', 'error'); return; }
    P.m.push({ t: now(), e: null, l: label || '' });
    P.m.sort((x, y) => x.t - y.t);
    changed();
    renderMarks();
  }
  $('[data-ytp-mark]').addEventListener('click', () => addMark(''));
  root.querySelectorAll('[data-ytp-sec]').forEach((b) => b.addEventListener('click', () => addMark(b.dataset.ytpSec)));
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
      ab.addEventListener('click', () => {
        const end = P.m[i + 1]?.t ?? dur();
        if (end <= m.t) return;
        P.ab = [m.t, end];
        loop = true;
        seek(m.t);
        if (ready && !playing) player.playVideo();
        changed();
        render();
      });
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'icon-btn';
      del.title = '削除';
      del.setAttribute('aria-label', '削除');
      del.appendChild(icon('delete'));
      del.addEventListener('click', () => { P.m.splice(i, 1); changed(); renderMarks(); });
      li.append(go, name, ab, del);
      marksEl.appendChild(li);
    });
    renderLine();
  }

  // ---- タイムライン ----
  const line = $('[data-ytp-line]');
  const pos = $('[data-ytp-pos]');
  const band = $('[data-ytp-abband]');
  line.addEventListener('click', (e) => {
    const r = line.getBoundingClientRect();
    seek(((e.clientX - r.left) / r.width) * dur());
  });
  function renderLine() {
    line.querySelectorAll('.ytp__tick').forEach((t) => t.remove());
    const d = dur();
    if (!d) return;
    P.m.forEach((m) => {
      const t = document.createElement('div');
      t.className = 'ytp__tick' + (m.l ? ' ytp__tick--sec' : '');
      t.style.left = (m.t / d) * 100 + '%';
      t.title = (m.l ? m.l + ' ' : '') + fmt(m.t);
      line.appendChild(t);
    });
  }

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

  // ---- キーボード（入力欄に書いているときは効かない） ----
  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select') || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === ' ') { e.preventDefault(); togglePlay(); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); back(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); fwd(); }
    else if (e.key === 'm' || e.key === 'M') addMark('');
  });

  renderMarks();
  render();
})();
