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
  const READY_MS = 1000;     // 問の前の Ready を見せる時間（リングが一周する時間。intro.css の intro-ring と同じ）
  const UNLOCK_MAX_MS = 1000;   // 開始ボタンの再生の許可取りを待つ上限（ミリ秒）
  const START_TIMEOUT_MS = 20000; // 開始の通信を待つ上限（ミリ秒）
  const COUNT_MS = 600;      // 合計点が増えていく時間（終了画面はこの倍）
  const calm = window.matchMedia('(prefers-reduced-motion: reduce)'); // 動きを減らす設定なら、点数は数えずにすぐ出す
  // 音の長さが 0 の WAV。開始ボタンの中で一度鳴らして、再生の許可をもらうのに使う
  const SILENT = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=';

  const $ = (sel) => app.querySelector(sel);
  const screens = {};
  app.querySelectorAll('[data-screen]').forEach((el) => { screens[el.dataset.screen] = el; });
  const rankBox = document.getElementById('intro-ranking');
  const fmt = (n) => Number(n).toLocaleString('ja-JP');

  const readyErr = screens.ready.querySelector('[data-error]');
  const playErr = screens.play.querySelector('[data-error]');
  const notes = app.querySelectorAll('[data-note]'); // 「絞り込み中はランキングに載りません」（タイトル画面と範囲の小窓）
  const scopeDlg = document.getElementById('intro-scope');
  const scopeLabel = $('[data-scope-label]');
  const countEl = $('[data-count]');
  const btnStart = $('[data-act="start"]');
  let countOk = !btnStart.disabled; // 出題できる曲が 4 曲以上あるか（無ければ開始できない）
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
  const post = (body, opts) => apiPost('api_intro.php', body, '送信できませんでした', opts);

  function showScreen(name) {
    Object.entries(screens).forEach(([k, node]) => { node.hidden = k !== name; });
  }

  const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

  /** 数字を今の値から to まで数えて増やす（ease-out）。途中で呼ばれたら、前の数えを止めて今の値から数え直す */
  function countUp(node, to, ms) {
    cancelAnimationFrame(node.countRaf);
    const from = Number(node.dataset.value || 0);
    node.dataset.value = String(to);
    if (calm.matches || from === to) { node.textContent = fmt(to); return; }
    const t0 = performance.now();
    const step = (now) => {
      const p = Math.min(1, (now - t0) / ms);
      node.textContent = fmt(Math.round(from + (to - from) * (1 - (1 - p) ** 3)));
      if (p < 1) node.countRaf = requestAnimationFrame(step);
    };
    node.countRaf = requestAnimationFrame(step);
  }

  /** Ready のリングを最初から回す。READY_MS たっても読み込み中なら is-wait（くるくる回り続ける）に切り替える */
  function runReady() {
    loading.classList.remove('is-run', 'is-wait');
    void loading.offsetWidth; // 一度描かせて、同じ class を付け直したときもアニメーションを最初から動かす
    loading.classList.add('is-run');
    return setTimeout(() => loading.classList.add('is-wait'), READY_MS);
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
  const filterValue = (k) => (filterEl(k).value === '' ? null : Number(filterEl(k).value));
  const FILTER_KEYS = ['year', 'live', 'artist', 'mine']; // サーバーへ送る絞り込み。mine は 1 か null
  const filtered = () => FILTER_KEYS.some((k) => filterEl(k).value !== '');
  /** 絞り込みを送信用にまとめる（{ year, live, artist, mine }） */
  const filterBody = () => Object.fromEntries(FILTER_KEYS.map((k) => [k, filterValue(k)]));
  const optText = (k) => filterEl(k).selectedOptions[0].textContent;

  /** 範囲のボタンの文字（「すべて」/「2025年度 · アーティスト名」など）。ライブを選んでいれば年度はライブ名に入っているので省く */
  function scopeText() {
    const parts = [];
    if (filterEl('live').value) parts.push(optText('live'));
    else if (filterEl('year').value) parts.push(`${optText('year')}年度`);
    if (filterEl('artist').value) parts.push(optText('artist'));
    if (filterEl('mine').value) parts.push('自分の出演');
    return parts.length ? parts.join(' · ') : 'すべて';
  }

  /** 範囲が変わったら、ボタンの文字・注意書き・出題できる曲の数を直す（曲の数はサーバーに聞く。続けて変えたら最後の答えだけ使う） */
  let countSeq = 0;
  async function refreshScope() {
    const on = filtered();
    notes.forEach((n) => { n.hidden = !on; });
    scopeLabel.textContent = scopeText();
    app.querySelector('[data-act="scope"]').classList.toggle('is-on', on);
    const seq = ++countSeq;
    countEl.textContent = '…';
    try {
      const r = await post({ action: 'count', ...filterBody() });
      if (seq !== countSeq) return;
      countEl.textContent = fmt(r.count);
      countOk = r.count >= 4; // lib/intro.php の INTRO_MIN_SONGS と同じ
    } catch (e) {
      if (seq !== countSeq) return;
      countEl.textContent = '?';
      countOk = true; // 数えられなくても、開始はサーバーが判断する
    }
    btnStart.disabled = !countOk;
  }
  app.querySelectorAll('[data-filter]').forEach((s) => s.addEventListener('change', refreshScope));

  /** 自分の記録の表示（点数と順位）。intro.php の intro_mine_html と同じ形 */
  function setMine(key, mine) {
    const dd = app.querySelector(`[data-mine="${key}"]`);
    if (!mine) { dd.textContent = '—'; return; }
    dd.replaceChildren(document.createTextNode(fmt(mine.score)), el('small', '', `${mine.rank}位`));
  }

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
    const readyTimer = runReady();

    if (audio.dataset.url !== url) setSource(audio, url);
    // Ready は最低 READY_MS 見せる（先読みで音源がもう読めていても、リングが一周するまで待つ）
    let [ok] = await Promise.all([waitReady(audio), sleep(READY_MS)]);
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

    // 選択肢を出すのと同時に再生を始める（ボタンは上から順に飛び出す: --i = 何番目か）
    clearTimeout(readyTimer);
    choicesBox.replaceChildren(...choices.map((title, idx) => {
      const b = el('button', 'intro-choice', title);
      b.type = 'button';
      b.style.setProperty('--i', String(idx));
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
    countUp(stat.score, r.score, COUNT_MS);
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
      // リンクにはしない（押すとページを離れて、遊んでいる途中のラウンドが消えるため）
      const li = document.createElement('li');
      li.append(el('span', 'intro-bands__name', b.name), el('span', 'muted small', ` ${b.live}`));
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
    res.score.dataset.value = '0'; // 終了画面の点数は 0 から数える
    res.score.textContent = '0';
    // 「自己ベスト更新」はベストスコアを超えたとき、「今月の自己ベスト更新」は今月の記録だけ超えたとき
    res.best.hidden = !(r.best || r.month_best);
    res.best.textContent = r.best ? '自己ベスト更新' : '今月の自己ベスト更新';
    // 順位は月間とベストの両方を短く出す（ランキングに載らないラウンドは出さない）
    res.rank.textContent = st.ranked
      ? [r.month_mine && `月間 ${r.month_mine.rank}位`, r.mine && `ベスト ${r.mine.rank}位`].filter(Boolean).join(' / ')
      : '';
    if (rankBox) Ranking.render(rankBox, r);
    if (st.ranked) { setMine('month', r.month_mine); setMine('best', r.mine); }
    showScreen('over');
    countUp(res.score, r.score, COUNT_MS * 2);
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
    const btn = btnStart;
    btn.disabled = true;
    try {
      const body = { action: 'start', ...filterBody() };
      // 開始が止まり続けないよう、待つ時間に上限を付ける（上限なしだと、ボタンが押せないまま戻らなくなる）
      //   ・再生の許可取り: play() が決着しない端末があるので、待つのは最大 1 秒（過ぎても開始する。許可取りはもう済んでいる）
      //   ・通信: 20 秒で切る（AbortSignal.timeout が無い古いブラウザは、上限なし）
      const unlockDone = Promise.race([Promise.all(unlocked), sleep(UNLOCK_MAX_MS)]);
      const opts = typeof AbortSignal.timeout === 'function' ? { signal: AbortSignal.timeout(START_TIMEOUT_MS) } : {};
      const [r] = await Promise.all([post(body, opts), unlockDone]);
      st = { round: r.round, total: r.total, ranked: r.ranked, i: 0, score: 0, token: 0, answered: false, pending: null, next: null, final: null, raf: 0 };
      stat.total.textContent = String(r.total);
      cancelAnimationFrame(stat.score.countRaf);
      stat.score.dataset.value = '0';
      stat.score.textContent = '0';
      showScreen('play');
      present(0, r.preview);
    } catch (e) {
      // 通信が上限を超えたとき（TimeoutError）は、ブラウザの英語の文言ではなく、こちらの文言を出す
      readyErr.textContent = e.name === 'TimeoutError' ? '開始できませんでした。もう一度試してください' : (e.message || '送信できませんでした');
      readyErr.hidden = false;
    } finally {
      btn.disabled = !countOk;
    }
  }

  // 遊んでいる途中にページを離れようとしたら、ブラウザの「このページを離れますか？」を出す（離れるとラウンドが消える）
  window.addEventListener('beforeunload', (e) => {
    if (screens.play.hidden) return;
    e.preventDefault();
    e.returnValue = ''; // 古いブラウザは returnValue が要る
  });

  app.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'start') start();
    else if (act === 'next') next();
    else if (act === 'retry') sendAnswer();
    else if (act === 'again') { stopAll(); showScreen('ready'); }
    else if (act === 'scope') scopeDlg.showModal();
    else if (act === 'scope-close') scopeDlg.close();
    else if (act === 'scope-reset') {
      FILTER_KEYS.forEach((k) => { filterEl(k).value = ''; });
      filterEl('year').dispatchEvent(new Event('change')); // ライブの候補を全部に戻す（refreshScope もここで呼ばれる）
    }
  });
})();
