/*
 * app_board.js — 掲示板・コメント・お気に入り（❤）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * 掲示板のスレのミュート（board_thread.php のスレ名の右のベル）
 *   お気に入り（setupLikes）と同じ流れ: 押した瞬間に見た目を変えて（ぽんと弾ませる）、api_board_mute に送り、
 *   返ってきた本当の状態で書き直す。失敗したら押す前に戻す。ページは移動しない
 * ------------------------------------------------------------------- */
function setupBoardMute() {
  const btn = document.querySelector('[data-board-mute]');
  if (!btn) return;
  const paint = (muted) => {
    btn.classList.toggle('is-active', muted);
    btn.setAttribute('aria-pressed', String(muted));
    btn.querySelector('.icon').textContent = muted ? 'notifications_off' : 'notifications';
  };
  let pending = false;
  btn.addEventListener('click', async () => {
    if (pending) return;
    pending = true;
    const before = btn.classList.contains('is-active');
    paint(!before);
    btn.classList.remove('is-pop');
    void btn.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
    btn.classList.add('is-pop');
    try {
      const res = await postJson('api_board_mute', { thread_id: Number(btn.dataset.boardMute) });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || '保存できませんでした');
      paint(data.muted);
    } catch (err) {
      console.error('ミュートの通信エラー:', err);
      paint(before);
      toastText('ミュートを保存できませんでした。ページを再読み込みしてやり直してください');
    } finally {
      pending = false;
    }
  });
}

/* ---------------------------------------------------------------------
 * 掲示板一覧のカテゴリ切り替え（board.php）
 *   タブ [data-board-tabs] を押したら、ページ移動せずに同じ URL を取ってきて、一覧 [data-board-free] だけ差し替える。
 *   差し替えたら軽くスッと出す。URL は pushState で変える（戻る・進むでも切り替わる）。
 *   取れなかったときは普通にページ移動する。タブの名前は textContent ではなくサーバーが作った HTML を DOMParser 経由で移す
 *   （自分のサーバーの同じページなので、中身はサーバー側で h() 済み）
 * ------------------------------------------------------------------- */
function setupBoardTabs() {
  const tabs = document.querySelector('[data-board-tabs]');
  const area = document.querySelector('[data-board-free]');
  if (!tabs || !area) return;
  let seq = 0; // 連打したとき、最後に押した分だけ反映する
  const select = document.querySelector('form [name="category_id"]'); // スレッド作成のカテゴリ（選んでいるカテゴリに合わせる）

  const show = (url) => {
    const my = ++seq;
    return fetch(url, { credentials: 'same-origin' })
      .then((res) => { if (!res.ok) throw new Error(res.status); return res.text(); })
      .then((html) => {
        if (my !== seq) return;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const next = doc.querySelector('[data-board-free]');
        if (!next) throw new Error('no list');
        area.innerHTML = next.innerHTML;
        area.dataset.cat = next.dataset.cat;
        const hrefs = new Map([...doc.querySelectorAll('[data-board-tabs] .tab')].map((a) => [a.getAttribute('href'), a.classList.contains('is-active')]));
        tabs.querySelectorAll('.tab').forEach((a) => {
          const on = hrefs.get(a.getAttribute('href')) === true;
          a.classList.toggle('is-active', on);
          if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        if (select && next.dataset.cat !== '0') select.value = next.dataset.cat;
        area.classList.remove('is-entering');
        void area.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
        area.classList.add('is-entering');
      });
  };

  tabs.addEventListener('click', (e) => {
    const a = e.target.closest('a.tab');
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    if (a.classList.contains('is-active')) return;
    show(a.href).then(() => history.pushState(null, '', a.href)).catch(() => { location.href = a.href; });
  });
  window.addEventListener('popstate', () => { show(location.href).catch(() => location.reload()); });
}

/* ---------------------------------------------------------------------
 * 掲示板一覧の「スレッド作成」「通知設定」（board.php）
 *   見出し横のアイコン [data-board-panel-open="id"] を押すと、同じ id のカード [data-board-panel] が開く（もう一度で閉じる。同時に開くのは1つ）。
 *   URL が #notify などのとき（保存して戻ってきたとき）は、最初からそのカードを開く
 * ------------------------------------------------------------------- */
function setupBoardPanels() {
  const panels = [...document.querySelectorAll('[data-board-panel]')];
  if (!panels.length) return;
  const buttons = [...document.querySelectorAll('[data-board-panel-open]')];
  const open = (id) => {
    panels.forEach((p) => { p.hidden = p.id !== id; });
    buttons.forEach((b) => {
      const on = b.dataset.boardPanelOpen === id;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-expanded', String(on));
    });
  };
  buttons.forEach((b) => b.addEventListener('click', () => {
    const id = b.dataset.boardPanelOpen;
    open(panels.find((p) => p.id === id && !p.hidden) ? '' : id);
  }));
  const first = location.hash.slice(1);
  if (panels.some((p) => p.id === first)) open(first);

  // 現役のみ・同期のみは、どちらかを ON にするともう片方と年度の範囲をグレーアウトする（展開は CSS。.restrict）
  document.querySelectorAll('[data-board-restrict]').forEach((box) => {
    const active = box.querySelector('[name="only_active"]');
    const cohort = box.querySelector('[data-board-cohort]');
    const sync = () => {
      active.disabled = cohort.checked;
      cohort.disabled = active.checked;
      box.querySelectorAll('[data-board-range] select').forEach((s) => { s.disabled = active.checked || cohort.checked; });
    };
    active.addEventListener('change', sync);
    cohort.addEventListener('change', sync);
    sync();
  });


}

/* ---------------------------------------------------------------------
 * 掲示板の返信先（board_thread.php）
 *   各書き込みの返信ボタン [data-board-reply] で、返信先に追加・解除（複数可。最大 MAX 件）。
 *   選んだものは返信欄の上にチップで並べ、送信用に hidden の reply_to[] を入れる。チップを押しても解除できる。
 *   書き込み番号は textContent で入れる（innerHTML は使わない）
 * ------------------------------------------------------------------- */
/**
 * 返信先のチップ選び（掲示板・コメント共通）。box = チップを並べる欄、buttons = Map(id → 返信ボタン)。
 *   animate … 新しく増えたチップだけ is-entering を付ける（掲示板）。コメントは付けない
 */
function makeReplyPicker(box, buttons, animate) {
  const MAX = 10;
  const picked = new Map(); // id → 書き込み・コメントの番号
  const shown = new Set(); // もう出しているチップ（animate のとき、新しく増えた分だけアニメーションさせる）
  const render = () => {
    box.replaceChildren();
    shown.forEach((id) => { if (!picked.has(id)) shown.delete(id); });
    picked.forEach((no, id) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'tag board-reply__chip' + (animate && !shown.has(id) ? ' is-entering' : '');
      shown.add(id);
      chip.setAttribute('aria-label', `>>${no} の返信を解除`);
      chip.textContent = `>>${no} ×`;
      chip.addEventListener('click', () => toggle(id));
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'reply_to[]';
      input.value = id;
      box.append(chip, input);
    });
    box.hidden = picked.size === 0;
    buttons.forEach((b, id) => {
      const on = picked.has(id);
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', String(on));
    });
  };
  const toggle = (id) => {
    if (picked.has(id)) picked.delete(id);
    else if (picked.size < MAX) picked.set(id, buttons.get(id)?.dataset.no ?? '');
    render();
  };
  buttons.forEach((b, id) => b.addEventListener('click', () => {
    const first = picked.size === 0;
    toggle(id);
    // 最初の1件を選んだときだけ返信欄へ移す（続けて選ぶときは動かさない）
    if (first && picked.has(id)) box.closest('form')?.querySelector('textarea')?.focus();
  }));
}

function setupBoardReply() {
  const box = document.querySelector('[data-board-reply-to]');
  if (!box) return;
  makeReplyPicker(box, new Map([...document.querySelectorAll('[data-board-reply]')].map((b) => [b.dataset.boardReply, b])), true);
}

/* ---------------------------------------------------------------------
 * ライブ・バンドのコメント（lib/comments.php）
 *   返信先の選び方は setupBoardReply と同じ（[data-comment-reply] で追加・解除、チップと hidden の reply_to[]）。
 *   #c12 のようなリンク（投稿後・>>N）で来たら、閉じているトグルカードを開いてから飛ぶ
 * ------------------------------------------------------------------- */
/**
 * <details> を「高さのアニメ無し」で開ける（#c12 や #categories のリンクで来て、JS が自動で開くとき用）。
 *   開く高さのアニメ（app.css の ::details-content）が動いている途中は、まだ高さが伸びきっていない。
 *   その間にスクロールする（ブラウザの #リンクへの移動も含む）と、飛び先の位置がズレたり、動かなかったりする。
 *   → 目印の class（.is-instant）を付けてアニメを止めてから開く。
 *   toggle イベント =「open が変わったあと（非同期）」に起きる。そこで目印を外して、次の手動の開け閉めからはアニメを戻す
 */
function openWithoutAnimation(details) {
  if (!(details instanceof HTMLDetailsElement) || details.open) return;
  details.classList.add('is-instant');
  details.addEventListener('toggle', () => details.classList.remove('is-instant'), { once: true });
  details.open = true;
}

function setupComments() {
  const root = document.querySelector('[data-comments]');
  if (!root) return;
  const openFor = () => {
    if (!/^#(c\d+|comments)$/.test(location.hash)) return;
    openWithoutAnimation(root);
    document.querySelector(location.hash)?.scrollIntoView({ block: 'start' });
  };
  openFor();
  window.addEventListener('hashchange', openFor);

  const box = root.querySelector('[data-comment-reply-to]');
  makeReplyPicker(box, new Map([...root.querySelectorAll('[data-comment-reply]')].map((b) => [b.dataset.commentReply, b])), false);
}

/* ---------------------------------------------------------------------
 * お気に入り（❤）（live.php のタイムテーブル / band.php のバンド名の右）
 *   data-like="バンドID" のボタンを押すと、通信の結果を待たずに先に見た目を変える（赤くして数を +1 / 戻して -1）。
 *   そのあと api_band_like.php に送り、返ってきた本当の数と状態で書き直す（ほかの人が同時に押した分もここで反映）。
 *   失敗したら押す前の見た目に戻す。同じバンドのボタンが1ページに複数あっても全部そろえる
 * ------------------------------------------------------------------- */
function setupLikes() {
  const pending = new Set(); // 通信中のバンドID（結果が返るまで同じバンドの2回目は受け付けない）

  // 同じバンドの ❤ を全部、liked / count の見た目にする
  //   data-like-api … 送り先の API（無ければバンド用の api_band_like）。掲示板の書き込みは api_board_like。
  //   バンドと書き込みで ID が重なっても混ざらないよう、同じ API のボタンだけそろえる
  const apiOf = (b) => b.dataset.likeApi || 'api_band_like';
  const KEYS = { api_band_like: 'band_id', api_board_like: 'post_id', api_comment_like: 'comment_id' };
  const paint = (api, id, liked, count) => {
    document.querySelectorAll(`[data-like="${id}"]`).forEach((b) => {
      if (apiOf(b) !== api) return;
      b.classList.toggle('is-liked', liked);
      b.setAttribute('aria-pressed', String(liked));
      b.setAttribute('aria-label', `お気に入り（${count}）`);
      b.querySelector('[data-like-count]').textContent = count;
    });
  };

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-like]');
    if (!btn) return;
    const id = btn.dataset.like;
    const api = apiOf(btn);
    const key = api + ':' + id;
    if (!KEYS[api]) return;
    if (pending.has(key)) return;
    pending.add(key);

    // 1. 先に見た目を変える（押した瞬間に数字が動く）
    const before = { liked: btn.classList.contains('is-liked'), count: Number(btn.querySelector('[data-like-count]').textContent) };
    const liked = !before.liked;
    paint(api, id, liked, Math.max(0, before.count + (liked ? 1 : -1)));
    if (liked) { // 付けたときだけハートをぽんと弾ませる
      btn.classList.remove('is-pop');
      void btn.offsetWidth; // 一度描き直させて、アニメーションを最初からやり直す
      btn.classList.add('is-pop');
    }

    // 2. サーバーに送って、本当の値で書き直す
    try {
      const res = await postJson(api, { [KEYS[api]]: Number(id) });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || '保存できませんでした');
      paint(api, id, data.liked, data.count);
    } catch (err) {
      console.error('お気に入りの通信エラー:', err);
      paint(api, id, before.liked, before.count); // 失敗したので押す前に戻す
      toastText('お気に入りを保存できませんでした。ページを再読み込みしてやり直してください');
    } finally {
      pending.delete(key);
    }
  });
}

/* ---------------------------------------------------------------------
 * 書き込みの投票（返信欄・スレッド作成）
 *   [data-board-poll-open] を押すと、同じフォームの選択肢の入力欄 [data-board-poll] を開く / 閉じる。
 *   閉じている間は fieldset ごと disabled にして、入力した選択肢を送らない（もう一度開けば残っている）
 * ------------------------------------------------------------------- */
function setupBoardPoll() {
  document.querySelectorAll('[data-board-poll-open]').forEach((b) => {
    const box = b.form?.querySelector('[data-board-poll]');
    if (!box) return;
    b.addEventListener('click', () => {
      const on = box.hidden;
      box.hidden = box.disabled = !on;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-expanded', String(on));
      if (on) box.querySelector('input')?.focus();
    });
    // + で隠れている次の選択肢を出す。全部出たら + を隠す
    const add = box.querySelector('[data-board-poll-add]');
    add?.addEventListener('click', () => {
      const next = box.querySelector('input[hidden]');
      if (!next) return;
      next.hidden = false;
      next.focus();
      add.hidden = !box.querySelector('input[hidden]');
    });
  });
}
