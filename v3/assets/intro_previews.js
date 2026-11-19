/* =====================================================================
 *  assets/intro_previews.js — 試聴の一括調査（intro_previews.php）
 * =====================================================================
 *  「調べる」を押すと、api_intro_previews.php を 3 秒おきに呼んで、未調査の曲を 1 曲ずつ調べる。
 *  3 秒おき = 1 分に 20 回。iTunes の API は 1 分あたり約 20 回までなので、その範囲に収める。
 *  apiPost（app.js の共通部品）: POST して JSON を返す。失敗は Error を投げる。
 * ===================================================================== */
(() => {
  const root = document.getElementById('intro-previews');
  if (!root) return;

  const INTERVAL_MS = 3000; // 1 曲ごとの間隔
  const MAX_ERRORS = 3;     // 続けて失敗したら止める（iTunes が落ちている・制限に当たったとき）

  const remainingEl = root.querySelector('[data-remaining]');
  const logEl = root.querySelector('[data-log]');
  const startBtn = root.querySelector('[data-act="start"]');
  const stopBtn = root.querySelector('[data-act="stop"]');
  let running = false;

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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
        const res = await apiPost('api_intro_previews', {}, '調べられませんでした');
        errors = 0;
        remainingEl.textContent = Number(res.remaining).toLocaleString();
        if (res.done || res.remaining === 0) {
          logEl.textContent = 'すべて調べました';
          break;
        }
        logEl.textContent = `${res.title}: ${res.found ? '試聴あり' : '試聴なし'}`;
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
})();
