/*
 * app_tutorial.js — チュートリアル・説明モーダル
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * チュートリアル・説明モーダル（[data-tutorial-dialog]）
 *   1ページに複数置ける。ログイン中は全ページにある初回チュートリアル（id="tutorial"。partials/tutorial.php）と、
 *   ページごとの説明（例: マイスケジュールの「カレンダーの連携方法」 id="ical-help"。partials/ical_help.php）。
 *   まだ見ていない人（data-tutorial-auto）には自動で開く。[data-tutorial-open="ダイアログの id"] を押しても開く（値が空なら tutorial）。
 *   次へ / 戻る / 下の点 / ← → キー / 左右スワイプでページを送る。最後のページの「次へ」は「はじめる」（data-tutorial-done で変えられる）になって閉じる。
 *   自動で開いた回を閉じたら（スキップ・Esc も）api_tutorial_seen に「見た」と送る → 次からは自動では出ない
 *
 *   分岐: スライドに data-tutorial-branch="google" のように付けると、[data-tutorial-choose="google"] のボタンで選んだときだけ出る。
 *     ページ送りの対象は「branch の無いスライド」と「今の選択と同じ branch のスライド」だけ。
 *     選ぶボタンのあるページ（分岐の前）では「次へ」を隠す（選ばないと進めない）。戻って来たら選択は解除される。
 * ------------------------------------------------------------------- */
function setupTutorial() {
  const openers = new Map(); // ダイアログの id → 開く関数
  document.querySelectorAll('[data-tutorial-dialog]').forEach((dialog) => {
    const open = setupTutorialDialog(dialog);
    if (dialog.id) openers.set(dialog.id, open);
  });
  document.querySelectorAll('[data-tutorial-open]').forEach((btn) => btn.addEventListener('click', () => {
    const open = openers.get(btn.dataset.tutorialOpen || 'tutorial');
    if (!open) return;
    btn.closest('details')?.removeAttribute('open'); // 右上メニューは閉じておく
    open();
  }));
}

/** ダイアログ1つぶんの動き。開く関数を返す（自動表示が付いていれば、ここで開く） */
function setupTutorialDialog(dialog) {
  const slides = [...dialog.querySelectorAll('[data-tutorial-slide]')];
  const dots = [...dialog.querySelectorAll('[data-tutorial-dot]')]; // 点・スキップは無いダイアログもある（そのときは何もしない）
  const prev = dialog.querySelector('[data-tutorial-prev]');
  const next = dialog.querySelector('[data-tutorial-next]');
  const doneLabel = dialog.dataset.tutorialDone || 'はじめる';
  let branch = null; // 選んだ分岐（まだ選んでいなければ null）
  let page = 0; // 今見えているページの番号（visiblePages() の中での位置）

  // 今のページ送りの対象。branch の無いスライドと、選んだ branch のスライド（HTML に書いた順）
  const visiblePages = () => slides.filter((s) => !s.dataset.tutorialBranch || s.dataset.tutorialBranch === branch);

  const show = (i) => {
    let pages = visiblePages();
    const target = pages[Math.max(0, Math.min(pages.length - 1, i))];
    // 選ぶボタンのあるページに来たら選択を解除する（戻ってきたときは選び直し。ここで → を押しても分岐には進めない）
    if (target.querySelector('[data-tutorial-choose]')) {
      branch = null;
      pages = visiblePages();
    }
    page = pages.indexOf(target);
    const isLast = page === pages.length - 1;
    slides.forEach((s) => { s.hidden = s !== target; });
    dots.forEach((d, j) => d.toggleAttribute('aria-current', j === page));
    // 見出し（h2）の id を指す。id はダイアログごとに重ならないよう HTML 側で付けてある
    const title = target.querySelector('h2');
    if (title?.id) dialog.setAttribute('aria-labelledby', title.id);
    prev.hidden = page === 0;
    next.hidden = target.querySelector('[data-tutorial-choose]') !== null;
    // 中身を textContent で入れ替えるとアイコン（<span class="icon">）が消えるので、文字の部分だけ変える
    next.firstChild.textContent = isLast ? doneLabel : '次へ';
    next.querySelector('.icon')?.toggleAttribute('hidden', isLast);
  };

  prev.addEventListener('click', () => {
    show(page - 1);
    // 戻った先が「選ぶページ」だと「次へ」が無い → 押したボタンが隠れてフォーカスが迷子にならないよう、選ぶボタンへ
    if (next.hidden) dialog.querySelector('[data-tutorial-slide]:not([hidden]) [data-tutorial-choose]')?.focus();
  });
  next.addEventListener('click', () => {
    if (page === visiblePages().length - 1) dialog.close();
    else show(page + 1);
  });
  dialog.querySelectorAll('[data-tutorial-choose]').forEach((btn) => btn.addEventListener('click', () => {
    branch = btn.dataset.tutorialChoose;
    show(page + 1); // 選んだ branch のスライドが「次のページ」になる
    next.focus();
  }));
  dialog.querySelector('[data-tutorial-skip]')?.addEventListener('click', () => dialog.close());
  dots.forEach((d) => d.addEventListener('click', () => show(Number(d.dataset.tutorialDot))));
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') show(page + 1);
    if (e.key === 'ArrowLeft') show(page - 1);
  });
  // スマホ: 横に 50px 以上なぞったらページ送り（縦のスクロールと区別するため、横の動きが縦より大きいときだけ）
  let startX = 0, startY = 0;
  dialog.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; startY = e.touches[0].clientY; }, { passive: true });
  dialog.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - startX;
    const dy = e.changedTouches[0].clientY - startY;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(page + (dx < 0 ? 1 : -1));
  });
  // 外側（背景）をクリックしても閉じる。<dialog> 自体がクリックされた = 中身の外側
  dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

  dialog.addEventListener('close', () => {
    if (!dialog.hasAttribute('data-tutorial-auto')) return; // 見たことがある人がメニューから開いた回・説明モーダルは、送らなくていい
    dialog.removeAttribute('data-tutorial-auto');
    // keepalive: 閉じてすぐ別のページへ移っても送信が途中で切られない
    fetch('api_tutorial_seen', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() }, keepalive: true }).catch(() => {});
  });

  const open = () => {
    // 画像は loading="lazy"。隠れているページの画像は読み込まれないので、開いた時点で全部読み込ませる（ページを送ったときに白く光らない）
    dialog.querySelectorAll('img[loading="lazy"]').forEach((img) => { img.loading = 'eager'; });
    branch = null; // 開き直したら選択は解除して、最初のページから
    show(0);
    if (!dialog.open) dialog.showModal();
    // 最初のページに「次へ」が無い（選ぶページ）ときは、選ぶボタンへ
    (next.hidden ? dialog.querySelector('[data-tutorial-choose]') : next)?.focus();
  };
  if (dialog.hasAttribute('data-tutorial-auto')) open();
  return open;
}
