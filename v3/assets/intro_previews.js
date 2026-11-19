/* =====================================================================
 *  assets/intro_previews.js — 試聴の一括調査（intro_previews.php）
 * =====================================================================
 *  ① 「調べる」: api_intro_previews.php（action: next）を 3 秒おきに呼んで、未調査の曲を 1 曲ずつ調べる。
 *     3 秒おき = 1 分に 20 回。iTunes の API は 1 分あたり約 20 回までなので、その範囲に収める。
 *     見つかったカードは緑のチェック、無かったカードは「見つからない曲」の並びへ移す。
 *  ② 「探し直す」: 見つからない曲のカードを開いて、検索語を直して iTunes の候補（リマスター・別バージョンを含む）を出す。
 *     候補の ▶ で聴いて、「この曲にする」で、その試聴を元の曲の試聴として覚える（action: search / pick）。
 *  apiPost・mkEl は app.js の共通部品（POST して JSON を返す / 要素を作る）。曲名・候補は textContent で入れる（HTML として解釈されない）。
 * ===================================================================== */
(() => {
  const root = document.getElementById('intro-previews');
  if (!root) return;

  const API = 'api_intro_previews';
  const INTERVAL_MS = 3000; // 1 曲ごとの間隔
  const MAX_ERRORS = 3;     // 続けて失敗したら止める（iTunes が落ちている・制限に当たったとき）

  const remainingEl = root.querySelector('[data-remaining]');
  const missingEl = root.querySelector('[data-missing]');
  const logEl = root.querySelector('[data-log]');
  const startBtn = root.querySelector('[data-act="start"]');
  const stopBtn = root.querySelector('[data-act="stop"]');
  const lists = { pending: root.querySelector('[data-list="pending"]'), missing: root.querySelector('[data-list="missing"]') };
  let running = false;

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const num = (el) => Number(el.textContent.replace(/,/g, '')) || 0;
  const fmt = (n) => Number(n).toLocaleString('ja-JP');
  /** 数を 1 つ増減する（0 未満にはしない） */
  const bump = (el, d) => { el.textContent = fmt(Math.max(0, num(el) + d)); };
  const cardOf = (key) => [...root.querySelectorAll('.preview-card')].find((c) => c.dataset.key === key);

  /** カードを「見つかった」にする: 緑のチェックを出して、探し直すの入口は閉じる */
  function markOk(card) {
    card.classList.add('is-ok');
    card.querySelector('.preview-card__ok').hidden = false;
    card.querySelector('[data-act="retry-open"]').hidden = true;
    card.querySelector('.preview-search')?.remove();
  }

  /** カードを「見つからない曲」の並びへ移す（探し直すの入口を出す） */
  function markMissing(card) {
    lists.missing.prepend(card);
    card.querySelector('[data-act="retry-open"]').hidden = false;
    bump(missingEl, 1);
  }

  /* ---------- ① 未調査を 1 曲ずつ調べる ---------- */
  /** ボタンの出し分け: 動いている間は「止める」だけ */
  const setRunning = (on) => {
    running = on;
    startBtn.hidden = on;
    stopBtn.hidden = !on;
  };

  async function run() {
    setRunning(true);
    let errors = 0;
    while (running) {
      try {
        const res = await apiPost(API, { action: 'next' }, '調べられませんでした');
        errors = 0;
        remainingEl.textContent = fmt(res.remaining);
        if (res.done || res.remaining === 0) {
          logEl.textContent = 'すべて調べました';
          break;
        }
        logEl.textContent = `${res.title}: ${res.found ? '試聴あり' : '試聴なし'}`;
        const card = cardOf(res.key); // 画面に出ているカード（上限を超えた曲はカードが無い）
        if (card && res.found) markOk(card);
        else if (card) markMissing(card);
        else if (!res.found) bump(missingEl, 1);
      } catch (e) {
        errors += 1;
        logEl.textContent = e.message;
        if (errors >= MAX_ERRORS) break;
      }
      await sleep(INTERVAL_MS);
    }
    setRunning(false);
  }

  startBtn.addEventListener('click', run);
  stopBtn.addEventListener('click', () => { running = false; });

  /* ---------- ② 見つからない曲を探し直す ---------- */
  const player = new Audio(); // 候補の試聴は 1 つだけ鳴らす
  let playingRow = null;
  const stopPlayer = () => {
    player.pause();
    playingRow?.classList.remove('is-playing');
    playingRow = null;
  };
  player.addEventListener('ended', stopPlayer);

  /** 候補 1 行: 曲名・アーティスト・アルバム + ▶ + 「この曲にする」 */
  function candidateRow(card, c) {
    const row = mkEl('li', 'preview-cand');
    if (String(c.artwork).startsWith('https://')) {
      const img = mkEl('img', 'preview-card__art');
      img.src = c.artwork;
      img.alt = '';
      img.width = 40;
      img.height = 40;
      row.append(img);
    }
    const text = mkEl('div', 'preview-cand__text');
    text.append(mkEl('b', '', c.title), mkEl('span', 'muted', `${c.artist} / ${c.album}`));
    const play = mkEl('button', 'icon-btn');
    play.type = 'button';
    play.setAttribute('aria-label', '試聴');
    play.append(mkEl('span', 'icon', 'play_arrow'));
    play.addEventListener('click', () => {
      if (playingRow === row) { stopPlayer(); return; }
      stopPlayer();
      player.src = c.preview_url;
      player.play().catch(() => {});
      playingRow = row;
      row.classList.add('is-playing');
    });
    const pick = mkEl('button', 'btn btn--sm btn--primary', 'この曲にする');
    pick.type = 'button';
    pick.addEventListener('click', async () => {
      pick.disabled = true;
      try {
        await apiPost(API, { action: 'pick', key: card.dataset.key, candidate: c.id }, '保存できませんでした');
        stopPlayer();
        markOk(card);
        bump(missingEl, -1);
      } catch (e) {
        pick.disabled = false;
        card.querySelector('.preview-error').textContent = e.message;
      }
    });
    row.append(text, play, pick);
    return row;
  }

  /** 探し直すの入口を開く: 検索語の入力（初期値は曲名 + アーティスト名）と候補の並び */
  function openSearch(card) {
    const btn = card.querySelector('[data-act="retry-open"]');
    const open = card.querySelector('.preview-search');
    if (open) { open.remove(); stopPlayer(); btn.setAttribute('aria-expanded', 'false'); return; }
    btn.setAttribute('aria-expanded', 'true');

    const box = mkEl('div', 'preview-search');
    const form = mkEl('form', 'preview-search__form');
    const input = mkEl('input');
    input.type = 'text';
    input.maxLength = 100;
    input.value = card.dataset.query;
    input.setAttribute('aria-label', '検索語');
    const go = mkEl('button', 'btn btn--sm', '検索');
    go.type = 'submit';
    form.append(input, go);
    const err = mkEl('p', 'preview-error');
    err.setAttribute('role', 'alert');
    const cands = mkEl('ul', 'preview-candidates');
    box.append(form, err, cands);
    card.append(box);

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const q = input.value.trim();
      if (!q) { err.textContent = '検索語を入力してください'; return; }
      err.textContent = '';
      go.disabled = true;
      stopPlayer();
      try {
        const res = await apiPost(API, { action: 'search', q }, '検索できませんでした');
        cands.replaceChildren(...res.candidates.map((c) => candidateRow(card, c)));
        if (!res.candidates.length) err.textContent = '試聴のある候補がありません。検索語を変えてください';
      } catch (ex) {
        err.textContent = ex.message;
      } finally {
        go.disabled = false;
      }
    });
    input.focus();
  }

  root.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-act="retry-open"]');
    if (btn) openSearch(btn.closest('.preview-card'));
  });
})();
