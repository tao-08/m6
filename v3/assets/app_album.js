/*
 * app_album.js — アルバム（並び替え・モーダル・検索・追加・開け閉め・ランキング）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * 好きなアルバムの並び替え（member.php。本人だけ）
 *   カードをつかんで動かすだけ。ボタンを押して「並び替えモード」にする必要はない。
 *     マウス … 押したまま 5px 以上動かしたらドラッグ開始（動かさずに離せば普通のクリック。× ボタンも押せる）
 *     指     … 0.35 秒長押しでドラッグ開始（すぐ指を動かしたら、いつもどおりページのスクロール）
 *   離したら api_album_order.php に新しい順番を fetch() で送って保存する（保存ボタンは無い）。
 *   キーボード: カードにフォーカス（Tab）して ← → ↑ ↓ で1つずつ動かす。
 *
 * アニメーションは setupSlotSort と同じ FLIP。ただし表の行と違って「縦横」に動くので translate(x, y)。
 *   つかんだカード … 少し大きく浮いて、ポインタに付いてくる。離すと定位置へ滑って戻る
 *   ほかのカード   … 場所が入れ替わるとき、元の位置から新しい位置へスッと滑る
 * ------------------------------------------------------------------- */
function setupAlbumSort() {
  const list = document.querySelector('[data-album-sort]');
  if (!list) return; // 0枚・1枚でも準備しておく（ページ移動なしで追加されて2枚以上になることがあるので）
  const status = document.querySelector('[data-album-sort-status]');
  const cards = () => [...list.children];
  const idsOf = () => cards().map((li) => li.dataset.id); // "spotify:xxxx" のようなキーの並び
  let saved = idsOf().join(); // 最後に保存できた並び。これと比べて、変わったときだけ送る

  // 何もしていないときに出しておく案内（指で操作する端末かどうかで言い方を変える）
  //   1枚以下なら並び替えるものが無いので出さない
  const hintText = window.matchMedia('(pointer: coarse)').matches ? '長押しで並び替え' : 'ドラッグで並び替え';
  const hint = () => (list.children.length > 1 ? hintText : '');
  let statusTimer = null;
  // done = true なら頭にチェックのアイコンを付ける
  const showStatus = (text, backToHintAfter = 0, done = false) => {
    clearTimeout(statusTimer);
    status.textContent = text;
    if (done) status.prepend(iconEl('check'), ' ');
    if (backToHintAfter) statusTimer = setTimeout(() => { status.textContent = hint(); }, backToHintAfter);
  };

  // 左上の順位バッジを今の並びに合わせる
  const renumber = () => cards().forEach((li, i) => { li.querySelector('.album__rank').textContent = i + 1; });

  // カードが増えたとき（setupAlbumAdd が 'albums:changed' を送ってくる）にも呼ぶ準備
  //   tabindex=0: Tab キーでカードに移れるようにする（キーボードで並び替えるため）
  const prepare = () => {
    cards().forEach((li) => { li.tabIndex = 0; });
    renumber();
    saved = idsOf().join(); // 追加された分も「保存済みの並び」に含める
    if (!saving) showStatus(hint());
  };
  list.addEventListener('albums:changed', prepare);

  // change() で DOM を入れ替え、skip 以外のカードを「元の見た目の位置」から「新しい位置」へ滑らせる（FLIP）
  const flip = (change, skip) => {
    const before = new Map(cards().map((li) => [li, li.getBoundingClientRect()])); // First（動いている途中ならその位置）
    change();
    renumber();
    if (reduceMotion) return;
    cards().forEach((li) => {
      if (li === skip) return;
      li.getAnimations().forEach((a) => a.cancel()); // 前のアニメーションを止めてから測る（止めないと途中の位置を測ってしまう）
      const now = li.getBoundingClientRect();         // Last
      const dx = before.get(li).left - now.left;      // Invert
      const dy = before.get(li).top - now.top;
      if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
      li.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: 200, easing: 'ease-out' }); // Play
    });
  };

  // ---- 保存（fetch） ----
  let saving = false;
  let again = false; // 保存中にまた動かされたら、終わってからもう一度保存する
  const save = async () => {
    const order = idsOf();
    if (order.join() === saved) return;
    if (saving) { again = true; return; }
    saving = true;
    showStatus('保存中…');
    try {
      const res = await postJson('api_album_order', { member_id: Number(list.dataset.memberId), order }).catch(() => null); // 通信そのものが失敗したら null
      if (!res) throw new Error('通信できませんでした。もう一度動かしてみてください');
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || '保存できませんでした。ページを再読み込みしてください');
      saved = order.join();
      showStatus('保存しました', 2000, true);
    } catch (err) {
      showStatus(err.message, 6000);
      // 保存できなかったので、最後に保存できた並びへ戻す（画面と DB がずれたままにしない）
      const byId = new Map(cards().map((li) => [li.dataset.id, li]));
      flip(() => saved.split(',').forEach((id) => list.appendChild(byId.get(id))));
    } finally {
      saving = false;
      if (again) { again = false; save(); }
    }
  };

  // ---- ドラッグ ----
  //   press … 押した直後〜ドラッグが始まるまで { li, pointerId, type, x, y, startX, startY, timer }
  //   drag  … ドラッグ中だけ                  { li, pointerId, x, y, grabX, grabY, timer }
  let press = null;
  let drag = null;

  // つかんだカードをポインタに付いてこさせる。
  //   offsetLeft / offsetTop は transform の影響を受けない「本来の位置」（ul の左上から）。
  //   そこから「ポインタ − つかんだ点」までの差だけ translate でずらして見せる
  const follow = () => {
    const box = list.getBoundingClientRect();
    const { li } = drag;
    const dx = drag.x - drag.grabX - box.left - li.offsetLeft;
    const dy = drag.y - drag.grabY - box.top - li.offsetTop;
    li.style.transform = `translate(${dx}px, ${dy}px)`;
  };

  // ポインタが乗っている「ほかのカードの本来の場所」に割り込む。
  //   前から来たならそのカードの後ろ、後ろから来たなら前へ（どちらも、そのカードの場所に自分が入る）
  //   見た目の位置（アニメーション中）ではなく本来の位置で判定するので、行ったり来たりのブルブルが起きない
  const moveTo = () => {
    const { li, x, y } = drag;
    const box = list.getBoundingClientRect();
    const all = cards();
    const target = all.find((c) => {
      if (c === li) return false;
      const left = box.left + c.offsetLeft;
      const top = box.top + c.offsetTop;
      return x >= left && x < left + c.offsetWidth && y >= top && y < top + c.offsetHeight;
    });
    if (target) {
      const fromBehind = all.indexOf(li) > all.indexOf(target);
      flip(() => list.insertBefore(li, fromBehind ? target : target.nextElementSibling), li);
    }
    follow(); // 自分の本来の位置が変わったので、ずらす量を計算し直す
  };

  const cancelPress = () => {
    if (!press) return;
    clearTimeout(press.timer);
    press.li.classList.remove('is-pressing');
    press = null;
  };

  // press → drag に切り替える（マウスは動かしたとき、指は長押しが終わったとき）
  const startDrag = () => {
    const { li, pointerId, x, y, startX, startY, type } = press;
    cancelPress();
    li.getAnimations().forEach((a) => a.cancel());
    const r = li.getBoundingClientRect();
    // grabX / grabY = カードの左上から、押した所までの距離（これを保ったまま付いてこさせる）
    drag = { li, pointerId, x, y, grabX: startX - r.left, grabY: startY - r.top };
    try { li.setPointerCapture(pointerId); } catch { /* もう離されていたら何もしない */ }
    li.classList.add('is-dragging');
    list.classList.add('is-sorting'); // 順位バッジを出す
    document.body.classList.add('is-album-dragging');
    if (type === 'touch') navigator.vibrate?.(10); // 対応している端末だけ短く震わせて、つかんだことを伝える
    follow();
    // 画面の上端・下端に近づけたら自動でスクロール（30枚あると画面に収まらないので）
    drag.timer = autoScrollWhileDragging(() => drag?.y, () => moveTo());
  };

  const end = (e) => {
    if (press && e.pointerId === press.pointerId) cancelPress(); // 動かさずに離した = ただのクリック / タップ
    if (!drag || e.pointerId !== drag.pointerId) return;
    const { li, timer } = drag;
    clearInterval(timer);
    drag = null;
    list.classList.remove('is-sorting');
    document.body.classList.remove('is-album-dragging');
    // 離した場所から定位置へ滑って戻る。戻り終わってから「浮いている」見た目を外す
    const from = li.style.transform;
    li.style.transform = '';
    if (!reduceMotion && from) li.animate([{ transform: from }, { transform: 'none' }], { duration: 180, easing: 'ease-out' });
    setTimeout(() => { if (drag?.li !== li) li.classList.remove('is-dragging'); }, reduceMotion ? 0 : 180);
    // ドラッグのあとに離したときの click で、アルバムのモーダルが開かないようにする（setupAlbumModal が見る）
    list.dataset.justDragged = '1';
    setTimeout(() => { delete list.dataset.justDragged; }, 300);
    save();
  };

  list.addEventListener('pointerdown', (e) => {
    const li = e.target.closest('.album');
    // × ボタン（削除フォーム）や文字のリンクの上で押したときは、ドラッグではなく普通にボタン・リンクとして使わせる
    //   → ドラッグできるのはジャケットの部分だけ
    if (!li || press || drag || e.target.closest('form, button, a')) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return; // 左クリック以外は無視
    if (e.pointerType === 'mouse') e.preventDefault(); // マウスでの文字選択を始めさせない（指のときはスクロールのために止めない）
    press = { li, pointerId: e.pointerId, type: e.pointerType, x: e.clientX, y: e.clientY, startX: e.clientX, startY: e.clientY, timer: null };
    if (e.pointerType === 'touch') {
      li.classList.add('is-pressing'); // 長押し中は少し沈ませて「今つかもうとしている」ことを見せる
      press.timer = setTimeout(startDrag, 350);
    }
  });
  document.addEventListener('pointermove', (e) => {
    if (press && e.pointerId === press.pointerId) {
      press.x = e.clientX;
      press.y = e.clientY;
      const moved = Math.hypot(e.clientX - press.startX, e.clientY - press.startY);
      if (press.type === 'touch') {
        if (moved > 8) cancelPress(); // 長押しの前に指が動いた = スクロールしたい
      } else if (moved > 5) {
        startDrag();
      }
      return;
    }
    if (!drag || e.pointerId !== drag.pointerId) return;
    drag.x = e.clientX;
    drag.y = e.clientY;
    moveTo();
  });
  document.addEventListener('pointerup', end);
  document.addEventListener('pointercancel', end); // 指でスクロールが始まったときもここに来る
  // ドラッグ中に指を動かしても、ページがスクロールしないようにする。
  //   passive: false にしないと preventDefault() が効かない（ブラウザがスクロールを優先する）
  list.addEventListener('touchmove', (e) => { if (drag) e.preventDefault(); }, { passive: false });
  // 画像やリンクはブラウザ標準の「ドラッグ」（URL や画像を他の場所へ運ぶ機能）が始まってしまうので止める
  list.addEventListener('dragstart', (e) => e.preventDefault());
  list.addEventListener('contextmenu', (e) => { if (press || drag) e.preventDefault(); });

  // ---- キーボード ----
  let keyTimer = null;
  list.addEventListener('keydown', (e) => {
    if (drag) return;
    const li = e.target.closest('.album');
    const back = e.key === 'ArrowLeft' || e.key === 'ArrowUp';
    const forward = e.key === 'ArrowRight' || e.key === 'ArrowDown';
    // カードそのものにフォーカスがあるときだけ（× ボタンにフォーカスがあるときは動かさない）
    if (!li || li !== e.target || (!back && !forward)) return;
    e.preventDefault();
    const prev = li.previousElementSibling;
    const next = li.nextElementSibling;
    if (back ? !prev : !next) return; // 端なのでもう動けない
    flip(() => (back ? list.insertBefore(li, prev) : list.insertBefore(next, li)));
    li.focus(); // insertBefore で動かすとフォーカスが外れるブラウザがあるので戻す
    // 1回押すごとに送ると多すぎるので、押し終わって 0.5 秒たったらまとめて保存
    clearTimeout(keyTimer);
    keyTimer = setTimeout(save, 500);
  });

  prepare(); // 最初の準備（let で宣言した変数を使うので、全部の宣言が終わったこの位置で呼ぶ）
}

/* ---------------------------------------------------------------------
 * アルバムのモーダル（マイアルバム・バンドページのセットリストのジャケット）
 *   [data-album-open] を押すと、フッターの <dialog data-album-modal>（partials/album_modal.php）を暗転して開く。
 *   中身は押された要素の data-album-* から入れる。文字は textContent、画像・リンクは属性で入れる（innerHTML は使わない = XSS 対策）。
 *   アイコンは <template> に入れてあるものを写す。検索は全体の検索（search?q=アルバム名）へ。
 * ------------------------------------------------------------------- */
function setupAlbumModal() {
  const dlg = document.querySelector('[data-album-modal]');
  if (!dlg) return;
  const icons = dlg.querySelector('[data-album-icons]').content;
  const art = dlg.querySelector('[data-album-art]');
  const listen = dlg.querySelector('[data-album-listen]');

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-album-open]');
    if (!el) return;
    e.preventDefault(); // リンク（JS なしのときの行き先）の移動は止める
    if (el.closest('[data-just-dragged]')) return; // 並び替えのドラッグを離したときの click
    const { albumTitle: title, albumYear: year, albumArtist: artist, albumArt: src, albumApp: app, albumUrl: url } = el.dataset;
    dlg.querySelector('[data-album-name]').textContent = title;
    dlg.querySelector('[data-album-year]').textContent = [artist, year].filter(Boolean).join(' · ');
    art.hidden = !src;
    if (src) art.src = src;
    dlg.querySelector('[data-album-search]').href = `search?q=${encodeURIComponent(title)}`;
    // 音楽アプリのアイコン（そのアプリの色クラス setlist__listen--xxx は app.css が持っている）
    const icon = icons.querySelector(`[data-app="${CSS.escape(app || '')}"]`);
    listen.hidden = !icon || !url;
    listen.className = `album-modal__app setlist__listen--${icon ? app : ''}`;
    listen.replaceChildren(...(icon ? [icon.firstElementChild.cloneNode(true)] : []));
    if (icon) {
      listen.href = url;
      listen.setAttribute('aria-label', `${icon.dataset.label} で聴く`);
      listen.title = icon.dataset.label;
    }
    dlg.showModal();
  });

  // 閉じるときも動かす: is-closing を付けて、縮むアニメーション（album-modal-out）が終わってから close()（Esc も同じ）
  const closeModal = () => {
    if (!dlg.open || dlg.classList.contains('is-closing')) return;
    if (reduceMotion) { dlg.close(); return; }
    dlg.classList.add('is-closing');
    // ::backdrop や中身のアニメーションも dlg に届くので、閉じるアニメーションだけを待つ。1回だけ動かす
    const onEnd = (e) => {
      if (e.target !== dlg || e.animationName !== 'album-modal-out') return;
      dlg.removeEventListener('animationend', onEnd);
      dlg.classList.remove('is-closing');
      dlg.close();
    };
    dlg.addEventListener('animationend', onEnd);
  };
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); closeModal(); }); // Esc
  dlg.querySelector('[data-album-close]').addEventListener('click', closeModal);
  dlg.addEventListener('click', (e) => { if (e.target === dlg) closeModal(); }); // 暗い背景を押しても閉じる
}

/* ---------------------------------------------------------------------
 * アルバム検索をページ移動なしで（member.php の「＋ アルバムを追加」）
 *   普通に送信するとページごと読み直しになり、スクロール位置も変わってしまう。
 *   そこで送信を止めて、同じ URL（member.php?id=..&album_q=..）を fetch() で裏で取りに行き、
 *   返ってきた HTML から [data-album-results] の部分だけ抜き出して、今の画面の同じ部分と入れ替える。
 *   → 検索や表示の処理は PHP のものをそのまま使える（JS で同じ表示を作り直さなくていい）
 *   うまくいかなかったとき（通信エラー・ログインが切れた等）は、今までどおり普通に送信する。
 * ------------------------------------------------------------------- */
function setupAlbumSearch() {
  const form = document.querySelector('form[data-album-search]');
  if (!form) return;
  const button = form.querySelector('[type="submit"]');
  let busy = false;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return; // 検索中にもう一度押されても無視（二重送信防止）
    busy = true;
    const label = button.textContent;
    button.disabled = true;
    button.textContent = '検索中…';

    // FormData: フォームの入力をまとめて取り出す → URLSearchParams で "id=1&album_q=..." の形にする
    //   getAttribute('action') は "member#albums"。# 以降は要らないので切り落とす
    const url = `${form.getAttribute('action').split('#')[0]}?${new URLSearchParams(new FormData(form))}`;
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      // DOMParser: 文字列の HTML を、画面に出さずに「部品の木（DOM）」として読み込む
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const fresh = doc.querySelector('[data-album-results]');
      // 見つからない = 別のページ（ログイン切れで login.php に飛ばされた等）が返ってきた
      if (!fresh) throw new Error();
      document.querySelector('[data-album-results]').replaceWith(fresh);
      fresh.classList.add('is-fresh'); // ふわっと出す（CSS）
      // アドレスバーの URL だけ書き換える（移動はしない）。再読み込みしても同じ検索結果が出るように
      history.replaceState(null, '', url);
    } catch {
      allowLeave();
      form.submit(); // 普通の送信（ページ移動）でやり直す。submit() は submit イベントを起こさないので、ここに戻ってこない
      return;
    } finally {
      busy = false;
      button.disabled = false;
      button.textContent = label;
    }
  });
}

/* ---------------------------------------------------------------------
 * マイアルバムの開け閉め（member.php）
 *   閉じているとき … 先頭5枚だけ（6枚目以降と「アルバムを追加」は CSS で隠す）
 *   開いているとき … 全部 ＋ 自分のページなら一番下に「アルバムを追加」
 *   見出しボタンと、一覧の下の「さらに表示」/「閉じる」ボタンのどちらでも切り替えられる。
 *   js-collapsible クラスを付けたときだけ CSS が隠す → JS が動かない環境では全部見えたまま。
 * ------------------------------------------------------------------- */
function setupAlbumBox() {
  const box = document.querySelector('[data-album-box]');
  if (!box) return;
  const list = box.querySelector('.albums');
  const toggle = box.querySelector('[data-album-toggle]');
  const more = box.querySelector('[data-album-more]');
  // 閉じているときに見せる枚数。PC は5枚、スマホ（600px 以下）は1行3枚（CSS の nth-child と合わせる）
  const shown = () => (window.matchMedia('(max-width: 600px)').matches ? 3 : 5);

  // 画面の表示を「開いているか」に合わせる
  const render = () => {
    const open = box.classList.contains('is-open');
    const count = list ? list.children.length : 0;
    // 閉じると隠れるものがあるか（6枚目以降 or 自分のページの「アルバムを追加」）。無ければボタンは要らない
    const hasHidden = count > shown() || box.querySelector('.album-search') !== null;
    toggle.setAttribute('aria-expanded', String(open));
    more.hidden = !hasHidden;
    more.textContent = open ? '閉じる' : 'さらに表示';
  };

  const setOpen = (open) => {
    box.classList.toggle('is-open', open);
    render();
    // 開いたとき、隠れていたカード（6枚目以降）をふわっと出す
    if (open && list && !reduceMotion) {
      [...list.children].slice(shown()).forEach((li, i) => {
        li.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
          { duration: 220, delay: Math.min(i, 10) * 20, easing: 'ease-out', fill: 'backwards' });
      });
    }
  };

  box.classList.add('js-collapsible');
  toggle.addEventListener('click', () => setOpen(!box.classList.contains('is-open')));
  more.addEventListener('click', () => {
    const closing = box.classList.contains('is-open');
    setOpen(!closing);
    // 閉じると下の方が無くなって画面が飛ぶので、見出しが見える位置まで戻す
    if (closing && box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth' });
  });
  // JS を使わずに追加・削除したあとは member.php#albums に戻ってくる → 開いた状態にする
  if (location.hash === '#albums') box.classList.add('is-open');
  // 枚数が変わったら（ページ移動なしで追加したとき）ボタンの出し方を見直す
  list?.addEventListener('albums:changed', render);
  render();
}

/* ---------------------------------------------------------------------
 * よく組むメンバー・よく演奏するアーティストの開け閉め（member.php の data-partner-box）
 *   マイアルバム（setupAlbumBox）と同じ操作感。閉じているときは上位 data-shown 人（無ければ5人）だけ見せる
 *   （CSS の nth-child と数を合わせる。よく組むメンバーは n+6、よく演奏するアーティストは n+4）。
 *   ボタンの文字は data-more-label（無ければ「すべて表示」）。
 *   見出しボタンと、一覧の下の「すべて表示」/「閉じる」のどちらでも切り替えられる。
 *   js-collapsible を付けたときだけ CSS が隠す → JS が動かないときは全員見えたまま。
 * ------------------------------------------------------------------- */
function setupPartnerBox() {
  document.querySelectorAll('[data-partner-box]').forEach(setupOnePartnerBox);
}

function setupOnePartnerBox(box) {
  const toggle = box.querySelector('[data-partner-toggle]');
  const more = box.querySelector('[data-partner-more]');
  const items = [...box.querySelectorAll('.ranking > li')]; // 全員の <li>（退会者の <li> も含む）
  const SHOWN = Number(box.dataset.shown) || 5;
  const moreLabel = box.dataset.moreLabel || 'すべて表示';

  // 「退会者を非表示」チェック（よく組むメンバーだけ。member.php が退会者のいるときだけ出す）
  const filterRow = box.querySelector('[data-retired-filter]');
  const retiredCheck = filterRow?.querySelector('input');
  const countEl = box.querySelector('[data-partner-count]'); // 見出しの「◯人」の数字
  const emptyEl = box.querySelector('[data-partner-empty]'); // 全員退会者だったときの一言

  // 今の状態（チェックの有無・開閉）から、各行の「隠す / 順位 / 閉じているとき隠す（is-extra）」を決め直す。
  //   ・チェックオン → 退会者（data-retired="1"）の行は hidden（CSS の display: none）にする
  //   ・順位は「見えている人」の中で振り直す。同じ回数（data-n）は同じ順位で、次は「何番目か」に進める（PHP の tie_ranks と同じ）
  //   ・閉じているときに隠す 6 人目以降は、CSS の nth-child ではなく is-extra クラスで決める
  //     （hidden の行も nth-child に数えられてしまい、退会者がいると上位 5 人がずれるため）
  //   animate が true のときは、出る行はふわっと出し、消える行はふわっと消してから隠す
  const refresh = (animate) => {
    const hideRetired = !!retiredCheck?.checked;
    let shown = 0, rank = 0, prevN = null;
    items.forEach((li) => {
      const gone = hideRetired && li.dataset.retired === '1';
      const wasHidden = li.hidden;
      if (gone) {
        // 消える行: アニメ中は見せたまま、終わってから hidden にする
        if (!wasHidden) {
          if (animate && !reduceMotion) {
            li.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-6px)' }],
              { duration: 160, easing: 'ease-in' }).finished.then(() => { li.hidden = true; }, () => { li.hidden = true; });
          } else {
            li.hidden = true;
          }
        }
        return; // 隠す行には順位を振らない
      }
      li.hidden = false;
      shown++;
      // 順位: 回数（data-n）が前の行と違うときだけ「何番目か」に進める。data-n が無い箱（よく演奏するアーティスト）は PHP の順位のまま
      if (li.dataset.n !== undefined) {
        if (shown === 1 || li.dataset.n !== prevN) rank = shown;
        prevN = li.dataset.n;
        li.dataset.rank = String(rank);
      }
      // 閉じているときに隠す行 / 最後に見せる行（下の線を消す行）
      li.classList.toggle('is-extra', shown > SHOWN);
      li.classList.toggle('is-edge', shown === SHOWN);
      // 退会者が出てきた行: ふわっと出す
      if (wasHidden && animate && !reduceMotion) {
        li.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
          { duration: 220, easing: 'ease-out' });
      }
    });
    if (countEl) countEl.textContent = String(shown);
    if (emptyEl) emptyEl.hidden = shown > 0 || items.length === 0;
    // 「すべて表示」は、隠れる行があるときだけ
    if (more) more.hidden = shown <= SHOWN;
    return shown;
  };
  const count = refresh(false); // 最初の状態（チェックオン = 退会者は隠れている）にそろえる

  // 名前の ▸ を開くたびに、一緒に出たバンドの一覧をふわっと出す。
  //   toggle イベントは開いて画面に描いた「あと」に届くので、そこで動かすと完成した表示が一瞬見えてしまう。
  //   → 開く「前」の click で動かし始める（click のあとにブラウザが開く → 最初に描かれるのは透明な状態）。
  //   summary の中の名前（リンク）を押したときはページを移るだけで開かないので、何もしない
  box.addEventListener('click', (e) => {
    const summary = e.target.closest('.partner > summary');
    if (reduceMotion || !summary || e.target.closest('a') || summary.parentElement.open) return;
    summary.parentElement.querySelector('.partner__bands')?.animate(
      [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
      { duration: 220, easing: 'ease-out' });
  });

  // 5人以下で、開いて出すもの（退会者のチェック）も無いなら、▸ もボタンも出さずに全員見せる。
  //   退会者のチェックがあるときは、見えている人が5人以下でも「開く」とチェックが出るので、開閉は残す
  if (count <= SHOWN && !retiredCheck) {
    toggle.querySelector('.album-box__chevron')?.remove();
    toggle.disabled = true;
    return;
  }

  // 閉じるときのアニメーション（高さを 0 まで縮めて、下の要素を上へ滑らせる）の管理用
  let closeAnims = [];
  const setOpen = (open) => {
    const wasOpen = box.classList.contains('is-open');
    // 閉じるアニメーションの途中でもう一度押されたら、動きを止めて元の状態から始める
    closeAnims.forEach((a) => a.cancel());
    closeAnims = [];
    box.classList.remove('is-closing');
    box.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    more.textContent = open ? '閉じる' : moreLabel;

    // 閉じるとき: 「退会者を非表示」と 6 人目以降の行を、高さ 0 まで縮めてから隠す。
    //   is-open を外すと CSS が即 display: none にしてしまうので、is-closing を付けて縮め終わるまで見せておく。
    //   高さ・上下の余白・下線をそろって 0 にするので、下の要素が一緒にスーッと上がる。
    //   fill: 'forwards' で縮んだ状態を保ち、終わってから is-closing を外す（外した瞬間に元の高さへ戻って一瞬ちらつくのを防ぐ）。
    //   border-box 指定（全体の CSS）なので、height は offsetHeight をそのまま使える
    if (!open && wasOpen && !reduceMotion) {
      const targets = [filterRow, ...items.filter((li) => !li.hidden && li.classList.contains('is-extra'))].filter(Boolean);
      if (targets.length) {
        box.classList.add('is-closing');
        closeAnims = targets.map((el) => {
          const cs = getComputedStyle(el);
          const from = { height: el.offsetHeight + 'px', paddingTop: cs.paddingTop, paddingBottom: cs.paddingBottom,
            marginTop: cs.marginTop, marginBottom: cs.marginBottom, borderBottomWidth: cs.borderBottomWidth, opacity: 1 };
          const to = { height: '0px', paddingTop: '0px', paddingBottom: '0px',
            marginTop: '0px', marginBottom: '0px', borderBottomWidth: '0px', opacity: 0 };
          el.style.overflow = 'hidden'; // 縮む間、はみ出す中身を見せない
          return el.animate([from, to], { duration: 260, easing: 'ease-in-out', fill: 'forwards' });
        });
        const anims = closeAnims;
        // 全部縮み終わったら後片付け（途中で cancel されたときは何もしない = 開き直した側が状態を持っている）
        Promise.all(anims.map((a) => a.finished)).then(() => {
          if (closeAnims !== anims) return;
          box.classList.remove('is-closing');
          anims.forEach((a) => a.cancel());
          targets.forEach((el) => { el.style.overflow = ''; });
          closeAnims = [];
        }, () => { targets.forEach((el) => { el.style.overflow = ''; }); });
      }
    }
    if (open && !reduceMotion) {
      // 開いたとき、見出しの下に「退会者を非表示」が上から伸びて出て、下のリストをスッと押し下げる。
      //   transform（見た目だけ動かす）ではなく height を 0 → 本来の高さに変えるので、下の要素も一緒に動く。
      //   本来の高さと上下の余白は、display: flex になった直後に getComputedStyle で測る。
      //   はみ出す文字が見えないよう、動いている間だけ overflow: hidden にして、終わったら外す
      if (filterRow) {
        const cs = getComputedStyle(filterRow);
        const full = { height: filterRow.offsetHeight + 'px', marginTop: cs.marginTop, marginBottom: cs.marginBottom, opacity: 1 };
        const zero = { height: '0px', marginTop: '0px', marginBottom: '0px', opacity: 0 };
        filterRow.style.overflow = 'hidden';
        const slide = filterRow.animate([zero, full], { duration: 260, easing: 'ease-out' });
        const done = () => { filterRow.style.overflow = ''; };
        slide.finished.then(done, done);
      }
      // 隠れていた6人目以降を上から順にふわっと出す（マイアルバムと同じ動き）。見えている行（hidden でない行）だけ数える
      items.filter((li) => !li.hidden).slice(SHOWN).forEach((li, i) => {
        li.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
          { duration: 220, delay: Math.min(i, 10) * 20, easing: 'ease-out', fill: 'backwards' });
      });
    }
  };

  box.classList.add('js-collapsible');
  // チェックを切り替えたら、行の出し入れ・順位・人数を作り直す（animate = true でふわっと）
  retiredCheck?.addEventListener('change', () => refresh(true));
  toggle.addEventListener('click', () => setOpen(!box.classList.contains('is-open')));
  more.addEventListener('click', () => {
    const closing = box.classList.contains('is-open');
    setOpen(!closing);
    // 閉じると下の方が無くなって画面が飛ぶので、見出しが見える位置まで戻す
    if (closing && box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: 'smooth' });
  });
  setOpen(false);
}

/* ---------------------------------------------------------------------
 * 楽器別出演数ランキング（stats.php の .kings）
 *   ▸ を開いたとき、上位5位の行を上から順にふわっと出す（よく組むメンバーと同じく、開く「前」の click で動かし始める）
 * ------------------------------------------------------------------- */
function setupKings() {
  if (reduceMotion) return;
  document.querySelectorAll('.kings').forEach((list) => {
    list.addEventListener('click', (e) => {
      const summary = e.target.closest('.partner > summary');
      if (!summary || e.target.closest('a') || summary.parentElement.open) return;
      summary.parentElement.querySelectorAll('.kings__top > li').forEach((li, i) => {
        li.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
          { duration: 200, delay: i * 30, easing: 'ease-out', fill: 'backwards' });
      });
    });
  });
}

/* ---------------------------------------------------------------------
 * アルバムの「追加」をページ移動なしで（member.php の検索結果の「追加」ボタン）
 *   member_album_save.php に fetch() で POST する。保存が終わると member.php へリダイレクトされ、
 *   fetch はそれに自動で付いていくので、最後は「追加後の member.php の HTML」が返ってくる。
 *   その中から次のものを取り出して、今の画面に反映する:
 *     ・追加されたアルバムのカード（data-id が送ったキーと同じ <li>）→ 一覧の最後に足す
 *     ・「◯ / 30」の枚数
 *     ・サーバーのメッセージ（.flash。「追加しました」「30枚までです」など）
 *   検索結果は差し替えた後の要素なので、document で待ち受ける（後から増えた「追加」ボタンにも効く）。
 * ------------------------------------------------------------------- */
function setupAlbumAdd() {
  const list = document.querySelector('[data-album-sort]');
  if (!list) return;

  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-album-add]');
    if (!form || e.defaultPrevented) return;
    e.preventDefault();
    const button = form.querySelector('[type="submit"]');
    if (button.disabled) return; // 追加中にもう一度押されても無視（二重送信防止）
    button.disabled = true;
    button.textContent = '追加中…';
    const key = form.elements.album.value;
    const item = form.closest('li');

    try {
      // ⚠ form.action と書くと、<input name="action"> の方が返ってきてしまう（名前がかぶるため）。
      //   なので getAttribute('action') で「action 属性の文字列」を取る
      const res = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const freshList = doc.querySelector('[data-album-sort]');
      if (!freshList) throw new Error(); // 別のページ（ログイン切れで login.php など）が返ってきた

      // 検索結果の位置がずれないように、変更前の位置を覚えておく（上の一覧が1行増えると、下が押し下げられるため）
      const before = item.getBoundingClientRect().top;

      // サーバーのメッセージは、ほかのページと同じくヘッダーの下のポップアップで出す
      doc.querySelectorAll('[data-toasts] .flash').forEach((f) => showToast(document.importNode(f, true)));
      const card = [...freshList.children].find((li) => li.dataset.id === key);
      if (!card) {
        // 追加されていない（30枚の上限・Spotify から情報を取れなかった など）。理由は上のメッセージに出ている
        button.disabled = false;
        button.textContent = '追加';
        return;
      }

      // CSS.escape: キーに記号が入っていてもセレクタ（[data-id="..."]）が壊れないようにする
      if (!list.querySelector(`[data-id="${CSS.escape(key)}"]`)) {
        const newCard = document.importNode(card, true); // 別の文書（doc）の要素を、この画面で使える形にコピー
        list.appendChild(newCard);
        list.dispatchEvent(new CustomEvent('albums:changed')); // 並び替えの準備をし直してもらう（setupAlbumSort）
        if (!reduceMotion) newCard.animate([{ opacity: 0, transform: 'scale(.9)' }, { opacity: 1, transform: 'none' }], { duration: 250, easing: 'ease-out' });
      }
      document.querySelector('[data-album-empty]')?.remove();
      document.querySelector('[data-album-count]').textContent = doc.querySelector('[data-album-count]').textContent;

      // 「追加」ボタンを「登録済み」に変える
      const pill = document.createElement('span');
      pill.className = 'pill';
      pill.textContent = '登録済み';
      form.replaceWith(pill);

      // 30枚に達したら、検索欄の代わりに「上限です」の案内を出す（サーバーが返したものに入れ替える）
      const freshSearch = doc.querySelector('.album-search');
      if (freshSearch && !freshSearch.querySelector('form[data-album-search]')) {
        document.querySelector('.album-search').replaceWith(document.importNode(freshSearch, true));
        return;
      }

      // 位置がずれた分だけスクロールして戻す（ブラウザが自動で直してくれていれば差は0なので何もしない）
      const shift = item.getBoundingClientRect().top - before;
      if (Math.abs(shift) >= 1) window.scrollBy(0, shift);
    } catch {
      allowLeave();
      form.submit(); // 普通の送信（ページ移動）でやり直す
    }
  });
}
