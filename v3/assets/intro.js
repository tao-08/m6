/* =====================================================================
 *  assets/intro.js — イントロクイズ（intro.php）の画面の動き
 * =====================================================================
 *  出題・採点はすべて api_intro.php（正解はブラウザに渡らない）。ここは「音を鳴らす」「選択肢を出す」「時間のバーを動かす」「結果を見せる」だけ。
 *
 *  1 問の流れ:
 *    ① 音源を読み込む（canplaythrough が来るまで。鳴らない・8 秒以内に読めないときは broken で別の曲に差し替える）
 *    ② reveal（サーバーが時刻を記録して、選択肢の曲名 4 つを返す）→ 選択肢を出すのと同時に再生開始
 *    ③ 選ぶ（または 10 秒で時間切れ）→ answer（正解・点数・答え合わせの情報が返る）。音は次へを押すまで鳴らし続ける
 *    ④ 答え合わせの間に、次の問の音源を先読みする
 *  音は Audio 要素を 2 つ使い回す（偶数の問と奇数の問）。iOS は「ボタンを押した操作の中」でしか再生を許さないので、
 *  開始ボタンのクリックの中で両方を一度 play → pause して、あとから自動で鳴らせるようにする。
 *  曲名・バンド名は textContent で入れる（HTML として解釈されない）。ランキングの描き直しは assets/ranking.js。
 * ===================================================================== */
(() => {
  'use strict';
  const app = document.getElementById('intro-app');
  if (!app) return;

  const SECONDS = 10;        // 1 問の制限時間（lib/intro.php の INTRO_SECONDS と同じ）
  const LOAD_TIMEOUT = 8000; // 音源がこの時間（ミリ秒）で読めなければ差し替える
  const LOW_SECONDS = 3;     // 残りがこれ以下になったらバーの色を変える
  // 音の長さが 0 の WAV。開始ボタンの中で一度鳴らして、再生の許可をもらうのに使う
  const SILENT = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=';

  const $ = (sel) => app.querySelector(sel);
  const screens = {};
  app.querySelectorAll('[data-screen]').forEach((el) => { screens[el.dataset.screen] = el; });
  const rankBox = document.getElementById('intro-ranking');
  const fmt = (n) => Number(n).toLocaleString('ja-JP');

  const readyErr = screens.ready.querySelector('[data-error]');
  const playErr = screens.play.querySelector('[data-error]');
  const note = $('[data-note]');
  const stat = { n: $('[data-stat="n"]'), total: $('[data-stat="total"]'), score: $('[data-stat="score"]') };
  const timerBar = $('[data-timer]');
  const loading = $('[data-loading]');
  const choicesBox = $('[data-choices]');
  const answerBox = $('[data-answer]');
  const btnRetry = $('[data-act="retry"]');
  const res = {};
  app.querySelectorAll('[data-result]').forEach((el) => { res[el.dataset.result] = el; });

  const players = [new Audio(), new Audio()];
  players.forEach((a) => { a.preload = 'auto'; });

  let st = null; // いまのラウンド: {round, total, ranked, i, score, token, answered, pending, next, final, raf}

  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
  const post = (body) => apiPost('api_intro.php', body, '送信できませんでした');

  function showScreen(name) {
    Object.entries(screens).forEach(([k, node]) => { node.hidden = k !== name; });
  }

  function stopAll() {
    if (st) cancelAnimationFrame(st.raf);
    players.forEach((a) => a.pause());
  }

  /** 続けられないエラー（通信失敗・音源の差し替え切れ）。開始前の画面に戻して理由を出す */
  function fatal(message) {
    stopAll();
    if (st) st.token++; // 途中の非同期処理を捨てる
    showScreen('ready');
    readyErr.textContent = message || '送信できませんでした';
    readyErr.hidden = false;
  }

  /* ---------- 絞り込み ---------- */
  const filterEl = (k) => app.querySelector(`[data-filter="${k}"]`);
  const liveSel = filterEl('live');
  const allLives = [...liveSel.options].slice(1).map((o) => ({ value: o.value, text: o.textContent, year: o.dataset.year }));

  // ライブの候補は、選んだ年度のものだけにする
  filterEl('year').addEventListener('change', () => {
    const year = filterEl('year').value;
    const keep = liveSel.value;
    const opts = allLives.filter((l) => !year || l.year === year).map((l) => {
      const o = document.createElement('option');
      o.value = l.value;
      o.textContent = l.text;
      o.dataset.year = l.year;
      return o;
    });
    liveSel.replaceChildren(liveSel.options[0], ...opts);
    liveSel.value = opts.some((o) => o.value === keep) ? keep : '';
  });
  app.querySelectorAll('[data-filter]').forEach((s) => s.addEventListener('change', () => {
    note.hidden = !['year', 'live', 'artist'].some((k) => filterEl(k).value !== '');
  }));
  const filterValue = (k) => (filterEl(k).value === '' ? null : Number(filterEl(k).value));

  /* ---------- 音 ---------- */
  function setSource(audio, url) {
    audio.dataset.url = url;
    audio.src = url;
    audio.load();
  }

  /** 音源が鳴らせる状態になるまで待つ。鳴らせれば true、エラー・時間切れなら false */
  function waitReady(audio) {
    return new Promise((resolve) => {
      if (audio.readyState >= 4) { resolve(true); return; }
      let done = false;
      let t2 = 0;
      const off = () => {
        ['canplaythrough', 'canplay', 'error'].forEach((ev) => audio.removeEventListener(ev, handlers[ev]));
      };
      const end = (ok) => {
        if (done) return;
        done = true;
        clearTimeout(t1);
        clearTimeout(t2);
        off();
        resolve(ok);
      };
      // canplaythrough が来ない端末（iOS など）向けに、canplay から少し待って鳴らせることにする
      const soon = () => { if (!t2) t2 = setTimeout(() => end(true), 400); };
      const handlers = { canplaythrough: () => end(true), canplay: soon, error: () => end(false) };
      Object.entries(handlers).forEach(([ev, fn]) => audio.addEventListener(ev, fn));
      const t1 = setTimeout(() => end(false), LOAD_TIMEOUT);
      if (audio.error) end(false);
      else if (audio.readyState >= 3) soon(); // 先読みのうちに canplay が過ぎていた
    });
  }

  /* ---------- 1 問 ---------- */
  async function present(i, url) {
    const token = ++st.token;
    const audio = players[i % 2];
    st.i = i;
    st.answered = false;
    st.pending = null;
    st.next = null;
    stat.n.textContent = String(i + 1);
    answerBox.hidden = true;
    choicesBox.hidden = true;
    playErr.hidden = true;
    btnRetry.hidden = true;
    loading.hidden = false;
    timerBar.style.transform = 'scaleX(1)';
    timerBar.classList.remove('is-low');

    if (audio.dataset.url !== url) setSource(audio, url);
    let ok = await waitReady(audio);
    while (!ok) { // 鳴らない曲は、サーバーに別の曲へ差し替えてもらう（1 ラウンド 3 回まで）
      if (token !== st.token) return;
      try {
        const r = await post({ action: 'broken', round: st.round, q: i });
        if (token !== st.token) return;
        setSource(audio, r.preview);
      } catch (e) {
        if (token === st.token) fatal(e.message);
        return;
      }
      ok = await waitReady(audio);
    }
    if (token !== st.token) return;

    let choices;
    try {
      choices = (await post({ action: 'reveal', round: st.round, q: i })).choices;
    } catch (e) {
      if (token === st.token) fatal(e.message);
      return;
    }
    if (token !== st.token) return;

    // 選択肢を出すのと同時に再生を始める
    choicesBox.replaceChildren(...choices.map((title, idx) => {
      const b = el('button', 'intro-choice', title);
      b.type = 'button';
      b.addEventListener('click', () => submit(idx));
      return b;
    }));
    loading.hidden = true;
    choicesBox.hidden = false;
    audio.currentTime = 0;
    audio.play().catch(() => {});
    startTimer(token);
  }

  function startTimer(token) {
    const t0 = performance.now();
    const tick = () => {
      if (token !== st.token || st.answered) return;
      const left = Math.max(0, SECONDS - (performance.now() - t0) / 1000);
      timerBar.style.transform = `scaleX(${left / SECONDS})`;
      timerBar.classList.toggle('is-low', left <= LOW_SECONDS);
      if (left <= 0) {
        players[st.i % 2].pause(); // 10 秒で止める
        submit(null); // 時間切れ
        return;
      }
      st.raf = requestAnimationFrame(tick);
    };
    st.raf = requestAnimationFrame(tick);
  }

  async function submit(choice) {
    if (!st || st.answered) return;
    st.answered = true;
    cancelAnimationFrame(st.raf);
    st.pending = choice;
    choicesBox.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    await sendAnswer();
  }

  async function sendAnswer() {
    const token = st.token;
    playErr.hidden = true;
    btnRetry.hidden = true;
    try {
      const r = await post({ action: 'answer', round: st.round, q: st.i, choice: st.pending });
      if (token !== st.token) return;
      showAnswer(r);
    } catch (e) {
      if (token !== st.token) return;
      playErr.textContent = e.message || '送信できませんでした';
      playErr.hidden = false;
      btnRetry.hidden = false;
    }
  }

  function showAnswer(r) {
    st.score = r.score;
    stat.score.textContent = fmt(r.score);
    choicesBox.querySelectorAll('button').forEach((b, idx) => {
      b.classList.toggle('is-correct', idx === r.correct);
      b.classList.toggle('is-wrong', idx === st.pending && idx !== r.correct);
    });
    // 正誤と獲得点
    const verdict = $('[data-verdict]');
    verdict.classList.toggle('is-right', r.right);
    const icon = $('[data-verdict-icon]');
    icon.className = 'icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = r.right ? 'check_circle' : 'cancel';
    $('[data-points]').textContent = r.right ? `+${fmt(r.points)}` : '0';
    // 曲の情報
    const art = $('[data-art]');
    const url = String(r.info.artwork || '');
    art.hidden = !url.startsWith('https://');
    if (!art.hidden) art.src = url;
    $('[data-title]').textContent = r.info.title;
    $('[data-artist]').textContent = r.info.artist;
    $('[data-bands]').replaceChildren(...r.info.bands.map((b) => {
      const li = document.createElement('li');
      const a = el('a', '', b.name);
      a.href = `band?id=${Number(b.id)}`;
      li.append(a, el('span', 'muted small', ` ${b.live}`));
      return li;
    }));
    answerBox.hidden = false;
    answerBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

    if (r.finished) {
      st.final = r;
    } else {
      st.next = r.next.preview;
      setSource(players[(st.i + 1) % 2], st.next); // 答え合わせの間に次の問の音源を先読みする
    }
  }

  function next() {
    if (!st || !st.answered) return;
    players[st.i % 2].pause();
    if (st.final) { showOver(st.final); return; }
    present(st.i + 1, st.next);
  }

  function showOver(r) {
    stopAll();
    res.score.textContent = fmt(r.score);
    // 「自己ベスト更新」はベストスコアを超えたとき、「今月の自己ベスト更新」は今月の記録だけ超えたとき
    res.best.hidden = !(r.best || r.month_best);
    res.best.textContent = r.best ? '自己ベスト更新' : '今月の自己ベスト更新';
    // 順位は月間とベストの両方を短く出す（ランキングに載らないラウンドは出さない）
    res.rank.textContent = st.ranked
      ? [r.month_mine && `月間 ${r.month_mine.rank}位`, r.mine && `ベスト ${r.mine.rank}位`].filter(Boolean).join(' / ')
      : '';
    if (rankBox) Ranking.render(rankBox, r);
    showScreen('over');
  }

  /* ---------- 開始 ---------- */
  async function start() {
    readyErr.hidden = true;
    // iOS の自動再生制限: ボタンを押した操作の中で、両方の Audio を一度鳴らして止めておく
    const unlocked = players.map((a) => {
      a.src = SILENT;
      delete a.dataset.url;
      return a.play().then(() => a.pause()).catch(() => {});
    });
    const btn = $('[data-act="start"]');
    btn.disabled = true;
    try {
      const body = { action: 'start', year: filterValue('year'), live: filterValue('live'), artist: filterValue('artist') };
      const [r] = await Promise.all([post(body), Promise.all(unlocked)]);
      st = { round: r.round, total: r.total, ranked: r.ranked, i: 0, score: 0, token: 0, answered: false, pending: null, next: null, final: null, raf: 0 };
      stat.total.textContent = String(r.total);
      stat.score.textContent = '0';
      showScreen('play');
      present(0, r.preview);
    } catch (e) {
      readyErr.textContent = e.message || '送信できませんでした';
      readyErr.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  app.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'start') start();
    else if (act === 'next') next();
    else if (act === 'retry') sendAnswer();
    else if (act === 'again') { stopAll(); showScreen('ready'); }
  });
})();
