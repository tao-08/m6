/*
 * app_list.js — 一覧の絞り込み・検索候補・スロット・並び替え・表のドラッグ
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * 統計の「Voを合算する」（stats.php）
 *   JS が無くてもリンク（?vo=sum）で切り替わるが、JS があればページを移動せずに切り替える。
 *   両方の数え方の表はもうページに入っている（data-vo-view="combo" / "sum"）ので、hidden を付け替えるだけ。
 *   アドレスバーの URL と絞り込みフォームの vo もそろえる（再読み込み・絞り込みをしても切り替えた状態のまま）。
 * ------------------------------------------------------------------- */
/** data-autofocus-pc … PC（マウスのある環境）だけ、開いたとき入力欄にカーソルを置く。
 *  スマホは autofocus だとキーボードが開いて、ページ遷移のアニメーションが途切れるため使わない */
function setupAutofocusPc() {
  if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;
  document.querySelector('[data-autofocus-pc]')?.focus({ preventScroll: true });
}

/** data-search-suggest … 検索ページの入力欄で、打っている途中に検索語の候補を出す（api_search_suggest.php）。
 *  候補を選ぶとその語で検索する。↑↓ で選んで Enter、Esc で閉じる。
 *  通信は 150ms 待ってから（打ち続けている間は送らない）。前の問い合わせは中止し、同じ文字の結果は覚えておく。
 *  日本語の変換中も候補を出す（ふりがなで当てるため）。ただし変換中の ↑↓ Enter は IME のものなので触らない */
function setupSearchSuggest() {
  const input = document.querySelector('[data-search-suggest]');
  if (!input) return;
  const KIND = { member: 'メンバー', band: 'バンド', artist: 'アーティスト', live: 'ライブ', venue: '会場', studio: 'スタジオ', equip: '機材', special: 'スペシャルコンテンツ', role: '係', faculty: '学部', song: '曲名' };
  const cache = new Map(); // 入力した文字 → 候補の配列
  let ctrl = null;         // 通信中の問い合わせ（新しく打ったら中止する）
  let timer = 0;
  let pop = null;
  let buttons = [];
  let list = [];
  let active = -1;

  const close = () => {
    pop?.remove();
    pop = null; buttons = []; list = []; active = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  };

  const place = () => {
    if (!pop) return;
    const r = input.getBoundingClientRect();
    pop.style.left = `${r.left}px`;
    pop.style.top = `${r.bottom + 4}px`;
    pop.style.minWidth = `${r.width}px`;
    // 8件がそのまま入る高さにする（既定の上限だと最後の1件がスクロールバーで切れる）。画面の下に入りきらないときだけ縮める
    pop.style.maxHeight = 'none';
    pop.style.maxHeight = `${Math.min(pop.offsetHeight, window.innerHeight - r.bottom - 12)}px`;
  };

  const choose = (text) => {
    input.value = text;
    close();
    input.form?.requestSubmit();
  };

  const setActive = (i) => {
    active = i;
    buttons.forEach((b, j) => b.classList.toggle('is-active', j === i));
    if (i >= 0) input.setAttribute('aria-activedescendant', buttons[i].id);
    else input.removeAttribute('aria-activedescendant');
  };

  // 一致した部分を赤くする（textContent で組むので XSS にならない）
  const label = (text, q) => {
    const span = document.createElement('span');
    span.className = 'live-pop__name';
    const at = text.toLowerCase().indexOf(q.toLowerCase());
    if (at < 0) { span.textContent = text; return span; }
    const mark = document.createElement('mark');
    mark.textContent = text.slice(at, at + q.length);
    span.append(text.slice(0, at), mark, text.slice(at + q.length));
    return span;
  };

  const render = (items, q) => {
    close();
    if (!items.length || document.activeElement !== input) return;
    list = items;
    pop = document.createElement('div');
    pop.className = 'live-pop live-pop--float suggest-pop';
    pop.id = 'search-suggest-pop';
    pop.setAttribute('role', 'listbox');
    buttons = items.map((it, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.tabIndex = -1;
      b.id = `search-suggest-${i}`;
      b.className = 'live-pop__item';
      b.setAttribute('role', 'option');
      const kind = document.createElement('span');
      kind.className = 'suggest-pop__kana suggest-pop__kind';
      kind.textContent = KIND[it.kind] || '';
      b.append(label(it.text, q));
      if (it.kana) { // ふりがなで当たったときは、一致した部分を赤くしたふりがなも名前の右に出す
        const k = label(it.kana, q);
        k.className = 'suggest-pop__kana';
        b.append(k);
      }
      b.append(kind);
      b.addEventListener('click', () => choose(it.text));
      pop.append(b);
      return b;
    });
    // 押した瞬間に入力欄からフォーカスが外れて（blur で）消えないようにする
    ['mousedown', 'pointerdown'].forEach((ev) => pop.addEventListener(ev, (e) => e.preventDefault()));
    document.body.append(pop);
    place();
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', pop.id);
  };

  const load = async (q) => {
    ctrl?.abort();
    ctrl = new AbortController();
    try {
      const res = await fetch(`api_search_suggest?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', signal: ctrl.signal });
      if (!res.ok) return;
      const items = (await res.json()).items || [];
      cache.set(q, items);
      if (input.value.trim() === q) render(items, q); // 返ってくる間に打ち直していたら捨てる
    } catch (e) { /* 中止・通信エラーは何も出さない（候補が出ないだけ） */ }
  };

  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (!q) { ctrl?.abort(); close(); return; }
    if (cache.has(q)) { render(cache.get(q), q); return; }
    timer = setTimeout(() => load(q), 150);
  });

  input.addEventListener('keydown', (e) => {
    if (e.isComposing || e.keyCode === 229 || !pop) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const n = list.length;
      setActive(e.key === 'ArrowDown' ? (active + 1) % n : (active - 1 + n) % n);
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      choose(list[active].text);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close();
    }
  });

  input.addEventListener('focusout', () => { clearTimeout(timer); ctrl?.abort(); close(); });
  window.addEventListener('scroll', place, true);
  window.addEventListener('resize', place);
  window.visualViewport?.addEventListener('resize', place);
}

function setupVoSum() {
  const toggles = [...document.querySelectorAll('[data-vo-sum-toggle]')];
  if (!toggles.length) return;
  toggles.forEach((toggle) => toggle.addEventListener('click', (e) => {
    e.preventDefault();
    const on = toggle.getAttribute('aria-checked') !== 'true';
    document.querySelectorAll('[data-vo-view]').forEach((list) => {
      list.hidden = (list.dataset.voView === 'sum') !== on;
      // 出した方のアニメーション（CSS の .is-switched）を最初から。offsetWidth を読むとクラスを外した状態が一度反映される
      list.classList.remove('is-switched');
      if (!list.hidden) {
        void list.offsetWidth;
        list.classList.add('is-switched');
      }
    });

    // 今の URL（#◯◯ は付けない）
    const url = new URL(location.href);
    url.hash = '';
    if (on) url.searchParams.set('vo', 'sum'); else url.searchParams.delete('vo');
    history.replaceState(null, '', url);

    // 2つのボタンの見た目と、JS が無いとき・新しいタブで開いたとき用のリンク先（もう一度押したら戻る URL）
    toggles.forEach((t) => {
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-checked', String(on));
      t.querySelector('.icon').textContent = on ? 'check_box' : 'check_box_outline_blank';
      const next = new URL(url);
      if (on) next.searchParams.delete('vo'); else next.searchParams.set('vo', 'sum');
      next.hash = new URL(t.href).hash;
      t.href = next.href;
    });

    // 絞り込みフォームの <input type="hidden" name="vo">
    const form = document.querySelector('form.stats-filter');
    let input = form?.querySelector('input[name="vo"]');
    if (on && form && !input) {
      input = Object.assign(document.createElement('input'), { type: 'hidden', name: 'vo', value: 'sum' });
      form.append(input);
    } else if (!on && input) {
      input.remove();
    }
  }));
}

/* ---------------------------------------------------------------------
 * 絞り込み検索: 入力した文字を含まない行（カード）を隠す
 *   <input data-filter=".member-row"> → .member-row の data-text を見て絞る
 * ------------------------------------------------------------------- */
function setupFilter() {
  document.querySelectorAll('[data-filter]').forEach((input) => {
    const items = document.querySelectorAll(input.dataset.filter);
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      const shown = [];
      items.forEach((el) => {
        const text = (el.dataset.text || el.textContent).toLowerCase();
        const hide = q !== '' && !text.includes(q);
        if (el.hidden && !hide) shown.push(el); // 隠れていたのが出てきたものだけ動かす
        el.hidden = hide;
      });
      animateIn(shown, 200);
    });
  });
}

/* ---------------------------------------------------------------------
 * カードなどを上から順にふわっと出す（年度スロットの切り替え・並び替えボタンで共通）
 *   画面の外の分まで動かすと遅れて見えるので、見えている所だけ。動きを減らす設定の人には何もしない
 *   連続で切り替えたときは、前のアニメーションを止めてから出し直す（重なってチラつかないように）
 * ------------------------------------------------------------------- */
function animateIn(elements, duration = 240) {
  if (reduceMotion) return;
  [...elements]
    .filter((el) => el.offsetParent !== null && el.getBoundingClientRect().top < window.innerHeight)
    .forEach((el, i) => {
      el.getAnimations().forEach((a) => a.cancel());
      el.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
        { duration, delay: Math.min(i, 12) * 25, easing: 'ease-out', fill: 'backwards' });
    });
}

/* ---------------------------------------------------------------------
 * スロット: リールを縦にドラッグして1つ選ぶ部品（年度スロット・学年スロットで共通）
 *   上にドラッグ → 次の段へ / 下にドラッグ → 前の段へ
 *   ホイール・↑↓キー・上下の段のタップでも1つずつ動く
 *   最初は .is-current が付いた段（無ければ先頭）を選ぶ
 *   選ぶたびに onSelect(value, changed) を呼ぶ（changed = 前と違う段になったか。最初の1回は false）
 * ------------------------------------------------------------------- */
function makeSlot(slot, onSelect) {
  const reel = slot.querySelector('.year-slot__reel');
  const items = [...reel.children];
  const max = items.length - 1;
  let index = Math.max(0, items.findIndex((el) => el.classList.contains('is-current')));

  const clamp = (n) => Math.max(0, Math.min(max, n));
  const slim = slot.classList.contains('year-slot--slim');
  // 1段の高さと、0番目を真ん中の段に置くためのずらし量（窓は3段ぶんの高さ）
  const itemH = () => items[0].offsetHeight;
  const top = () => (slot.clientHeight - itemH()) / 2;
  const move = (px) => { reel.style.transform = `translateY(${top() + px}px)`; };

  const select = (i, first = false) => {
    const before = index;
    index = clamp(i);
    // 最初の1回（読み込み直後）はアニメーションなしでその段に置く（集計で送信したあと、上から回って見えないように）
    reel.classList.toggle('is-dragging', first);
    move(-index * itemH());
    if (first) { reel.offsetHeight; reel.classList.remove('is-dragging'); }
    items.forEach((el, n) => el.classList.toggle('is-current', n === index));
    const value = items[index].dataset.value;
    slot.setAttribute('aria-valuetext', items[index].textContent);
    slot.classList.toggle('is-filtered', value !== '');
    onSelect(value, !first && index !== before);
  };

  // ---- タップで開くプルダウン（独自デザイン）: body 直下に固定位置で出す（スロットの overflow で切れないように） ----
  let menu = null;
  const closeMenu = () => {
    if (!menu) return;
    menu.remove();
    menu = null;
    document.removeEventListener('pointerdown', outside, true);
    removeEventListener('scroll', onScroll, true);
    removeEventListener('resize', closeMenu);
  };
  const onScroll = (e) => { if (!menu?.contains(e.target)) closeMenu(); };
  const outside = (e) => { if (!menu.contains(e.target) && !slot.contains(e.target)) closeMenu(); };
  const openMenu = () => {
    if (menu) { closeMenu(); return; }
    menu = document.createElement('div');
    menu.className = 'slot-menu';
    menu.setAttribute('role', 'listbox');
    items.forEach((el, n) => {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'slot-menu__item' + (n === index ? ' is-current' : '');
      opt.setAttribute('role', 'option');
      opt.textContent = el.dataset.label || el.textContent.trim();
      opt.addEventListener('click', () => { closeMenu(); select(n); slot.focus(); });
      menu.append(opt);
    });
    document.body.append(menu);
    const r = slot.getBoundingClientRect();
    const below = innerHeight - r.bottom - 8;
    menu.style.minWidth = `${r.width}px`;
    menu.style.left = `${Math.min(r.left, innerWidth - menu.offsetWidth - 8)}px`;
    if (below < Math.min(menu.offsetHeight, 200) && r.top > below) {
      menu.style.bottom = `${innerHeight - r.top + 4}px`;
      menu.style.maxHeight = `${Math.min(r.top - 12, 340)}px`;
    } else {
      menu.style.top = `${r.bottom + 4}px`;
      menu.style.maxHeight = `${Math.min(below, 340)}px`;
    }
    menu.querySelector('.is-current')?.scrollIntoView({ block: 'center' });
    document.addEventListener('pointerdown', outside, true);
    addEventListener('scroll', onScroll, true);
    addEventListener('resize', closeMenu);
  };

  // ---- ドラッグ（マウスもタッチも pointer イベントでまとめて扱う） ----
  let startY = null;
  let dy = 0;
  slot.addEventListener('pointerdown', (e) => {
    closeMenu();
    startY = e.clientY;
    dy = 0;
    slot.setPointerCapture(e.pointerId);
    reel.classList.add('is-dragging');
  });
  slot.addEventListener('pointermove', (e) => {
    if (startY === null) return;
    dy = e.clientY - startY;
    const h = itemH();
    let pos = -index * h + dy;
    // 端より先はゴムのように 1/3 しか動かない
    const min = -max * h;
    if (pos > 0) pos /= 3;
    if (pos < min) pos = min + (pos - min) / 3;
    move(pos);
  });
  const end = (e) => {
    if (startY === null) return;
    startY = null;
    const h = itemH();
    if (Math.abs(dy) < 4) {
      // ほぼ動いていない = タップ → プルダウンを開く（.year-slot--slim はプルダウンなしで次の段へ。最後の次は最初）
      if (slim) { select(index === max ? 0 : index + 1); return; }
      select(index);
      openMenu();
    } else {
      select(Math.round(index - dy / h));
    }
  };
  slot.addEventListener('pointerup', end);
  slot.addEventListener('pointercancel', end);

  // ---- ホイール（連続で回りすぎないよう 120ms に1回） ----
  let wheelAt = 0;
  slot.addEventListener('wheel', (e) => {
    e.preventDefault();
    const now = Date.now();
    if (now - wheelAt < 120 || e.deltaY === 0) return;
    wheelAt = now;
    select(index + Math.sign(e.deltaY));
  }, { passive: false });

  slot.addEventListener('keydown', (e) => {
    const step = { ArrowUp: -1, ArrowDown: 1 }[e.key];
    if (step) { e.preventDefault(); select(index + step); }
    if (e.key === 'Home') { e.preventDefault(); select(0); }
    if (e.key === 'End') { e.preventDefault(); select(max); }
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (slim) select(index === max ? 0 : index + 1); else openMenu(); }
    if (e.key === 'Escape') closeMenu();
  });

  select(index, true);

  // ---- 非表示（display: none）だったスロットが表示されたとき、位置を測り直す ----
  //   集計の絞り込みは、タブを切り替えるまで スロットが display: none（CSS の [data-show-when]）。
  //   非表示のあいだは高さが 0 なので、上の select(index, true) は「高さ 0」で位置を計算してしまい、
  //   表示された瞬間に今の年度が真ん中からズレる。
  //   → ResizeObserver（要素の大きさの変化を教えてくれるブラウザ標準の API）で、
  //     高さが 0 → 表示サイズに変わったら、もう一度 select(index, true) で置き直す。
  //   ResizeObserver はレイアウト計算のあと・画面に描く前に呼ばれるので、ズレた状態は一瞬も見えない
  if (typeof ResizeObserver !== 'undefined') {
    let lastH = slot.clientHeight;
    new ResizeObserver(() => {
      const h = slot.clientHeight;
      const shown = lastH === 0 && h > 0; // 非表示 → 表示 になった瞬間だけ
      lastH = h;
      if (shown) select(index, true);
    }).observe(slot);
  }

  // 隠れていた（高さ 0 の）スロットを出したときに、位置を測り直すための関数を返す（学年絞り込み用。上の ResizeObserver とは別に残す）
  return () => select(index, true);
}

/* ---------------------------------------------------------------------
 * 年度スロット（ライブ一覧・メンバーの出演履歴）
 *   上にドラッグ → 古い年度へ / 下にドラッグ → 新しい年度（先頭は「すべて」）
 *   選んだ年度以外の <section data-year> を隠し、残った年度の見出しとカードをふわっと出す（animateIn）
 *   data-year-slot="セレクタ" なら、それに当たる要素を隠す（メンバーの出演履歴: section[data-history-year]）
 * ------------------------------------------------------------------- */
function setupYearSlot() {
  const slot = document.querySelector('[data-year-slot]');
  if (!slot) return;
  const sections = document.querySelectorAll(slot.dataset.yearSlot || 'section[data-year]');
  // 横に検索バーがあれば（ライブ一覧・メンバーの出演履歴）、縦横の比率はそのままで検索バーの高さに合わせて縮める
  const search = slot.parentElement.querySelector('.search');
  if (search && slot.classList.contains('year-slot--live')) {
    // ライブ一覧: 縮めずに、高さだけ検索バーに合わせる（1段 = 高さの半分。幅と文字は CSS）
    const h = search.offsetHeight;
    slot.style.height = `${h}px`;
    slot.querySelectorAll('.year-slot__item').forEach((el) => { el.style.height = `${h / 2}px`; });
  } else if (search) {
    slot.style.zoom = search.offsetHeight / slot.offsetHeight;
  }
  makeSlot(slot, (value, changed) => {
    sections.forEach((sec) => { sec.hidden = value !== '' && (sec.dataset.year || sec.dataset.historyYear) !== value; });
    // 年度が変わったときだけ動かす（端でさらに回した・真ん中をタップした、では動かさない）
    //   見出し（h2 / h3）→ カード（ライブ一覧は .grid の中、出演履歴は <ol> の <li>）の順
    if (changed) {
      animateIn([...sections].filter((sec) => !sec.hidden)
        .flatMap((sec) => [...sec.querySelectorAll(':scope > :is(h2, h3), .grid > *, ol > li')]));
    }
  });
}

/* フォームを送る（ページを読み直す）けど、今のスクロール位置は保つ（集計の絞り込み）。戻すのは setupSmallThings */
function submitKeepScroll(form) {
  if (form.hasAttribute('data-live')) return liveFilter(form); // メンバーページ: ページ移動なし
  // 目印: 絞り込み（sticky）の下に見えている最初の見出し。その画面上の位置も覚える
  const under = form.getBoundingClientRect().bottom;
  const anchor = keepScrollAnchors().find((el) => el.getBoundingClientRect().bottom > under);
  const data = { path: location.pathname, y: scrollY };
  if (anchor) Object.assign(data, { key: keepScrollKey(anchor), top: anchor.getBoundingClientRect().top });
  try { sessionStorage.setItem('keep-scroll', JSON.stringify(data)); } catch { /* 保てないだけ */ }
  form.submit();
}
/* メンバーページの絞り込み（data-live）: ページを移動せず、読み直した HTML から上の数字・楽器と右の集計カードだけ差し替える。
 *   応答が速いとちらつくので、読み込み中に薄くするのは 150ms たっても返らないときだけ（.is-updating）。
 *   差し替えは View Transitions で古い表示と新しい表示をクロスフェード（対応していない・動きを減らす設定のときは animateIn）。失敗したら今まで通り送信 */
let liveFilterCtl = null;
async function liveFilter(form) {
  liveFilterCtl?.abort();
  const ctl = liveFilterCtl = new AbortController();
  const query = `?${new URLSearchParams(new FormData(form))}`;
  const targets = () => document.querySelectorAll('.hero dl.stats, .hero .partbar, .split aside > :not(.stats-filter)');
  const dimTimer = setTimeout(() => targets().forEach((el) => el.classList.add('is-updating')), 150);
  try {
    const res = await fetch(location.pathname + query, { signal: ctl.signal, credentials: 'same-origin' });
    if (!res.ok) throw new Error(String(res.status));
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const newHero = doc.querySelector('.hero');
    const newAside = doc.querySelector('.split aside');
    if (!document.querySelector('.hero') || !newHero || !document.querySelector('.split aside') || !newAside) throw new Error('no target');
    const swap = () => {
      const aside = document.querySelector('.split aside');
      document.querySelector('.hero').replaceWith(document.importNode(newHero, true));
      aside.querySelectorAll(':scope > :not(.stats-filter)').forEach((el) => el.remove());
      newAside.querySelectorAll(':scope > :not(.stats-filter)').forEach((el) => aside.append(document.importNode(el, true)));
      setupPartnerBox();
      targets().forEach((el) => el.classList.remove('is-updating'));
    };
    history.replaceState(null, '', location.pathname + query);
    if (document.startViewTransition && !reduceMotion) {
      document.documentElement.classList.add('live-vt');
      try { await document.startViewTransition(swap).finished; } finally { document.documentElement.classList.remove('live-vt'); }
    } else {
      swap();
      animateIn(document.querySelectorAll('.hero dl.stats > div, .hero .partbar, .split aside > *'));
    }
  } catch (e) {
    if (e.name === 'AbortError') return; // 続けて変えたので、新しい方に任せる
    form.submit();
  } finally {
    clearTimeout(dimTimer);
    if (liveFilterCtl === ctl) targets().forEach((el) => el.classList.remove('is-updating'));
  }
}
const keepScrollAnchors = () => [...document.querySelectorAll('main .section-title, main .table-card')];
const keepScrollKey = (el) => (el.matches('.table-card') ? 'table' : el.textContent.trim());

/* ---------------------------------------------------------------------
 * フォームの中のスロット（集計の学年別）: data-slot-input
 *   回したら、中の hidden input に選んだ値を入れてフォームを送信する
 * ------------------------------------------------------------------- */
function setupSlotInput() {
  document.querySelectorAll('[data-slot-input]').forEach((slot) => {
    const input = slot.querySelector('input[type="hidden"]');
    makeSlot(slot, (value, changed) => {
      input.value = value;
      if (changed) submitKeepScroll(input.form);
    });
  });
}

/* ---------------------------------------------------------------------
 * 学年の絞り込み（メンバー一覧）: タブ（全学年 / 上下3学年 / 学年別）+ 学年別のときだけ出る学年スロット
 *   今の絞り込みの値: '' = 全学年 / 'near' = 上下3学年（タブの data-min〜data-max の入学年度）/ 入学年度 / 'none' = 入学年度が不明
 *   行（tr[data-entry]）は .is-grade-hidden で隠す（hidden は名前検索が使うので、ぶつからないよう別にする）
 *   切り替えたら:
 *     ・# の順位を、見えている人の中で付け直す（data-score の多い順。同じなら同じ順位。出演なしの人は —）
 *     ・上の「出演者」「登録」の人数を数え直す
 *     ・URL（?who=&entry=）に今の学年を入れる（再読み込みで戻らないように）
 * ------------------------------------------------------------------- */
function setupGradeSlot() {
  const box = document.querySelector('[data-grade-filter]');
  if (!box) return;
  const slot = box.querySelector('[data-grade-slot]');
  const radios = [...box.querySelectorAll('input[name="who"]')];
  const rows = [...document.querySelectorAll('tr[data-entry]')];
  const table = document.querySelector('[data-grade-table]');
  const empty = document.querySelector('[data-grade-empty]');
  const count = (name) => document.querySelector(`[data-grade-count="${name}"]`);
  const hideRetired = box.querySelector('[data-hide-retired]'); // 退会した人がいないときは出ていない（null）
  let slotValue = '';

  const apply = (changed) => {
    const radio = radios.find((r) => r.checked) || radios[0];
    const who = radio.value;
    box.classList.toggle('is-grade', who === 'grade'); // 学年スロットを出す（CSS の .grade-slot-wrap がアニメーションで広がる）
    const match = (entry) => {
      if (who === 'all') return true;
      // 上下3学年・現役・卒業生: 入学年度が data-min 〜 data-max の人（無い側は制限なし）。入学年度が不明な人は出さない
      if (who !== 'grade') {
        const { min, max } = radio.dataset;
        return entry !== '' && (min === undefined || +entry >= +min) && (max === undefined || +entry <= +max);
      }
      return slotValue === 'none' ? entry === '' : entry === slotValue;
    };
    const retiredOff = hideRetired && hideRetired.checked;
    rows.forEach((tr) => tr.classList.toggle('is-grade-hidden',
      !match(tr.dataset.entry) || (retiredOff && 'retired' in tr.dataset)));
    const shown = rows.filter((tr) => !tr.classList.contains('is-grade-hidden'));

    // 順位 = 自分より data-score が多い人の数 + 1（出演なしの人は —）
    const played = shown.filter((tr) => +tr.dataset.bands > 0).map((tr) => +tr.dataset.score);
    const ranking = !!document.querySelector('.table--members.is-ranking');
    shown.forEach((tr) => {
      const n = +tr.dataset.score;
      // # に順位を出すのはランキングの並びのときだけ（それ以外は空）。
      //   左端の # と、スマホで右端に出す # の2つがある
      const rank = !ranking ? '' : +tr.dataset.bands > 0 ? played.filter((m) => m > n).length + 1 : '—';
      tr.querySelectorAll('[data-rank]').forEach((cell) => { cell.textContent = rank; });
    });
    if (count('played')) count('played').textContent = played.length;
    if (count('all')) count('all').textContent = shown.length;
    if (table) table.hidden = shown.length === 0;
    if (empty) empty.hidden = shown.length > 0;

    // URL と並べ替えリンクに今の学年を入れる
    const setParams = (params) => {
      params.delete('who');
      params.delete('entry');
      params.set('who', who); // 全学年も書く（書かないと、再読み込みで最初のタブ = 現役の人は「現役」に戻ってしまう）
      if (who === 'grade') params.set('entry', slotValue);
      // 退会した人を表示する（チェックを外した）ときだけ書く。初期値は「隠す」
      params.delete('retired');
      if (hideRetired && !hideRetired.checked) params.set('retired', 'show');
    };
    const url = new URL(location.href);
    setParams(url.searchParams);
    history.replaceState(history.state, '', url);

    if (changed) animateIn(shown);
  };

  // スロットは最初の1回（changed = false）で今の段を覚えるだけ。そのあと回したら絞り込み直す
  const refreshSlot = slot ? makeSlot(slot, (value, changed) => {
    slotValue = value;
    if (changed) apply(true);
  }) : () => {};
  radios.forEach((r) => r.addEventListener('change', () => {
    // 学年別にしたときは、隠れていて高さ 0 だったスロットの位置を測り直す
    if (r.value === 'grade') refreshSlot();
    apply(true);
  }));
  hideRetired?.addEventListener('change', () => apply(true));
  // 見出しで並べ替えたら（setupClientSort）: 出演・ライブ・トリならその列の順位を # に出す。ほかは # を空にする
  const membersTable = document.querySelector('.table--members');
  membersTable?.addEventListener('client-sort', (e) => {
    const col = ['bands', 'lives', 'headliners'].includes(e.detail.sort) ? e.detail.sort : '';
    membersTable.classList.toggle('is-ranking', !!col);
    const head = membersTable.querySelector('[data-rank-head]');
    if (head) head.textContent = col ? '#' : '';
    const prop = 'score' + (col || 'bands').replace(/^./, (c) => c.toUpperCase());
    rows.forEach((tr) => { tr.dataset.score = tr.dataset[prop]; });
    apply(false);
  });
  apply(false);
}

/* ---------------------------------------------------------------------
 * 再読み込みなしで並べ替える表（table[data-client-sort]。ユーザー管理・メンバー・アーティスト・マスター管理）
 *   並び方ごとの行の位置は PHP が計算して tr[data-pos] に入れてある（lib/bootstrap.php の sort_positions）。
 *   JS は比べ方を持たず、その番号の順に行を並べ直すだけ（開いたときの PHP の並びと必ず同じになる）
 *   見出しの button[data-sort=列][data-sort-first=最初の向き] を押すたびに 最初の向き → 逆の向き
 *     table[data-sort-clear] なら、その次で解除（いつもの並び = data-pos の '' ）
 *   並べ替えたら URL を ?sort=&dir= に書き換える（再読み込み・共有しても同じ並び。PHP が同じ GET で並べる）
 *     button[data-sort-clear-scroll] の列を解除したときは ?scroll=end も付ける（メンバー一覧: 表を右端に置いたまま開く）
 *     table[data-sort-save=URL] なら、そこにも ?sort=&dir= を送ってサーバーに覚えてもらう（マスター管理）
 *   並べ替えたら table に client-sort イベントを出す（メンバー一覧の # の付け直しが聞いている）
 * ------------------------------------------------------------------- */
function setupClientSort() {
  // form[data-keep-query]: 別のページに送るフォームにも今の並び順（URL の ?…）を付けて、処理のあと同じ並びで戻れるように
  document.querySelectorAll('form[data-keep-query]').forEach((form) => {
    const base = form.getAttribute('action');
    form.addEventListener('submit', () => { form.action = base + location.search; });
  });
  document.querySelectorAll('table[data-client-sort]').forEach((table) => {
    const tbody = table.tBodies[0];
    const pos = new Map([...tbody.rows].map((tr) => [tr, JSON.parse(tr.dataset.pos)]));
    const btns = [...table.querySelectorAll('button[data-sort]')];
    let sort = table.dataset.sortCurrent || '';
    let dir = table.dataset.dirCurrent || '';
    btns.forEach((btn) => btn.addEventListener('click', () => {
      const col = btn.dataset.sort;
      const first = btn.dataset.sortFirst || 'asc';
      const flip = first === 'asc' ? 'desc' : 'asc';
      let cleared = false;
      if (sort !== col) [sort, dir] = [col, first];
      else if (dir === first) dir = flip;
      else if ('sortClear' in table.dataset) { [sort, dir] = ['', '']; cleared = true; }
      else dir = first;

      const key = sort ? `${sort}:${dir}` : '';
      const rows = [...pos.keys()].sort((a, b) => pos.get(a)[key] - pos.get(b)[key]);
      tbody.append(...rows);
      btns.forEach((b) => {
        const th = b.closest('th');
        const on = b.dataset.sort === sort;
        if (on) th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        else th.removeAttribute('aria-sort');
        b.querySelector('.sortable__arrow').textContent = on ? (dir === 'asc' ? '▲' : '▼') : '';
      });

      const url = new URL(location.href);
      ['sort', 'dir', 'scroll'].forEach((k) => url.searchParams.delete(k));
      if (sort) {
        url.searchParams.set('sort', sort);
        url.searchParams.set('dir', dir);
      } else if (cleared && 'sortClearScroll' in btn.dataset) {
        url.searchParams.set('scroll', 'end');
      }
      history.replaceState(history.state, '', url);
      if (table.dataset.sortSave && sort) {
        fetch(`${table.dataset.sortSave}&sort=${encodeURIComponent(sort)}&dir=${dir}`, { credentials: 'same-origin' });
      }
      table.dispatchEvent(new CustomEvent('client-sort', { bubbles: true, detail: { sort, dir } }));
      animateIn(rows, 120);
    }));
  });
}

/* ---------------------------------------------------------------------
 * 横にはみ出す表を、右端まで動かした状態で開く（data-scroll-end）
 *   はみ出していない（パソコンの幅）ときは、動かす先が無いので何も変わらない
 * ------------------------------------------------------------------- */
function setupScrollEnd() {
  const boxes = document.querySelectorAll('[data-scroll-end]');
  if (!boxes.length) return;
  const toEnd = () => boxes.forEach((box) => { box.scrollLeft = box.scrollWidth; });
  toEnd();
  // 文字（Web フォント）が読み込まれると表の幅が広がって右端がずれるので、読み込み後にもう一度
  document.fonts?.ready.then(toEnd);
  window.addEventListener('load', toEnd, { once: true });
}

/* ---------------------------------------------------------------------
 * 並び替えボタン: 押すたびに新しい順 ⇔ 古い順（メンバーの出演履歴・ライブ一覧）
 *   <button data-sort-toggle="#history-list"> → その中の年度 section と、各 <ol> の <li> の順番を逆にする
 *   data-sort-items=".grid" なら、<ol> の代わりにそれの中身を逆にする（ライブ一覧の年度ごとのカード）
 *   append は「今ある要素を最後に移動する」ので、逆順に append し直せば並びが逆になる（作り直さない）
 *   並べ替えたら、見えているカードを上から順にふわっと出す（どこが動いたか分かるように）
 * ------------------------------------------------------------------- */
/* ---------------------------------------------------------------------
 * 楽器フィルター（lib/repository.php の inst_filter）
 *   data-inst-items があれば、その行を data-insts で隠す（メンバー一覧）
 *   無ければ出演履歴: バンド → 日のカード → 年度 の順に、残るものが無ければ隠す
 *   メニューは body に移して position: fixed（表の横スクロールの枠で切れない・translate の親の中だと fixed がずれるため）
 * ------------------------------------------------------------------- */
function setupInstFilter() {
  document.querySelectorAll('[data-inst-filter]').forEach((box) => {
    const list = document.querySelector(box.dataset.instFilter);
    const btn = box.querySelector('.inst-filter__btn');
    const menu = box.querySelector('.inst-filter__menu');
    if (!list) return;
    const funnel = btn.innerHTML;
    document.body.append(menu);
    const open = (on) => {
      menu.hidden = !on;
      btn.setAttribute('aria-expanded', String(on));
      if (!on) return;
      const r = btn.getBoundingClientRect();
      menu.style.top = `${r.bottom + 4}px`;
      menu.style.left = `${Math.max(8, Math.min(r.left, innerWidth - menu.offsetWidth - 8))}px`;
    };
    const has = (el, inst) => (el.dataset.insts || '').split(' ').includes(inst);
    btn.addEventListener('click', (e) => { e.stopPropagation(); open(menu.hidden); });
    document.addEventListener('click', (e) => { if (!box.contains(e.target) && !menu.contains(e.target)) open(false); });
    window.addEventListener('scroll', () => open(false), { passive: true, capture: true });
    menu.addEventListener('click', (e) => {
      const opt = e.target.closest('[data-inst]');
      if (!opt) return;
      const inst = opt.dataset.inst;
      btn.innerHTML = inst ? opt.innerHTML : funnel;
      btn.classList.toggle('is-active', !!inst);
      if (box.dataset.instItems) {
        list.querySelectorAll(box.dataset.instItems).forEach((row) => {
          row.classList.toggle('is-inst-hidden', !!inst && !has(row, inst));
        });
      } else {
        list.querySelectorAll('.history__band').forEach((b) => { b.hidden = !!inst && !has(b, inst); });
        list.querySelectorAll('.history__item').forEach((d) => {
          d.classList.toggle('is-inst-hidden', !d.querySelector('.history__band:not([hidden])'));
        });
        list.querySelectorAll('section').forEach((y) => {
          y.classList.toggle('is-inst-hidden', !y.querySelector('.history__item:not(.is-inst-hidden)'));
        });
      }
      // 絞り込み後の一覧をふわっと出す（動きを減らす設定なら出さない）
      if (!reduceMotion) {
        list.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: 'ease-out' });
      }
      open(false);
    });
  });
}

function setupSortToggle() {
  document.querySelectorAll('[data-sort-toggle]').forEach((btn) => {
    const list = document.querySelector(btn.dataset.sortToggle);
    if (!list) return;
    const itemsSel = btn.dataset.sortItems || 'ol';
    const reverse = (parent) => [...parent.children].reverse().forEach((el) => parent.append(el));
    btn.addEventListener('click', () => {
      reverse(list);
      list.querySelectorAll(itemsSel).forEach(reverse);
      const asc = btn.getAttribute('aria-pressed') !== 'true';
      btn.setAttribute('aria-pressed', String(asc));
      btn.textContent = asc ? '古い順 ↑' : '新しい順 ↓';
      animateIn(list.querySelectorAll(`:scope > * > :is(h2, h3), ${itemsSel} > *`)); // 年度の見出しとカードを、上から順に
    });
  });
}

/* ---------------------------------------------------------------------
 * ライブ詳細の日程タブ
 *   URL の #day-12 を見て、その日程だけ表示する（リンクで直接その日を開ける）
 * ------------------------------------------------------------------- */
function setupTabs() {
  const tabs = document.querySelectorAll('a[data-tab]'); // a に限る: tab.php の TAB 譜（div[data-tab]）の中のクリックまで止めてしまうので
  if (!tabs.length) return;

  const showDay = (hash) => {
    const target = hash && document.querySelector(hash);
    if (!target || !target.classList.contains('day')) return;
    document.querySelectorAll('.day').forEach((d) => { d.hidden = d !== target; });
    tabs.forEach((t) => t.classList.toggle('is-active', t.getAttribute('href') === '#' + target.id));
    // スマホで日程が4つ以上あるとタブのバーは横にスクロールする → 選んだタブがバーの外にあれば見える所まで動かす
    //   scrollIntoView だとページごと縦に動くことがあるので、バーの scrollLeft だけを変える
    const active = [...tabs].find((t) => t.classList.contains('is-active'));
    const bar = active && active.parentElement;
    if (bar && bar.scrollWidth > bar.clientWidth) {
      const left = active.offsetLeft - bar.offsetLeft;
      if (left < bar.scrollLeft) bar.scrollLeft = left - 8;
      else if (left + active.offsetWidth > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = left + active.offsetWidth - bar.clientWidth + 8;
    }
  };

  // JS が動くときだけ2日目以降を隠す（動かなければ全日程が縦に並ぶ）
  document.querySelectorAll('.day[data-hidden]').forEach((d) => { d.hidden = true; });
  tabs.forEach((t) => t.addEventListener('click', (e) => {
    e.preventDefault(); // 本来の「#へジャンプ」を止める
    history.replaceState(null, '', t.getAttribute('href'));
    showDay(t.getAttribute('href'));
  }));
  showDay(location.hash);
}

/* ---------------------------------------------------------------------
 * ライブ詳細の縮小ボタン（[data-timeline-compact]）
 *   押すと全日程のタイムテーブル（.timeline）がバンド名だけになる（左の順番・時刻はそのまま。CSS: .timeline--compact）
 *   もう一度で元に戻る。選んだ状態は localStorage に覚える
 *   アニメーション: 切り替えの前後で各行（.slot）の高さを測り、前の高さ → 後の高さへ滑らせる
 *   （見えている日程の行だけ。動きを減らす設定の人には動かさない）
 * ------------------------------------------------------------------- */
function setupTimelineCompact() {
  const buttons = document.querySelectorAll('[data-timeline-compact]');
  if (!buttons.length) return;
  const KEY = 'timelineCompact';
  const apply = (on, animate = false) => {
    const slots = animate && !reduceMotion ? [...document.querySelectorAll('.timeline .slot')].filter((s) => s.offsetParent !== null) : [];
    // 行の高さと、行の上端から見たバンド名の位置（名前は中央へ飛ばずに、元の位置からスライドさせる）
    const measure = (s) => {
      const r = s.getBoundingClientRect();
      const n = s.querySelector('.slot__name');
      return { h: r.height, nameY: n ? n.getBoundingClientRect().top - r.top : 0 };
    };
    const before = slots.map(measure);
    document.querySelectorAll('.timeline').forEach((t) => t.classList.toggle('timeline--compact', on));
    slots.forEach((s, i) => {
      const after = measure(s);
      if (Math.abs(after.h - before[i].h) < 1) return; // 休憩の行など、高さが変わらないものは動かさない
      const opt = { duration: 280, easing: 'ease' };
      s.animate([{ height: before[i].h + 'px', overflow: 'hidden' }, { height: after.h + 'px', overflow: 'hidden' }], opt);
      const name = s.querySelector('.slot__name');
      const dy = before[i].nameY - after.nameY;
      if (name && Math.abs(dy) >= 1) name.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], opt);
    });
    buttons.forEach((b) => {
      b.setAttribute('aria-pressed', String(on));
      const label = on ? '展開' : '縮小';
      b.setAttribute('aria-label', label);
      b.title = label;
      const icon = b.querySelector('.icon');
      if (icon) icon.textContent = on ? 'unfold_more' : 'unfold_less';
    });
  };
  buttons.forEach((b) => b.addEventListener('click', () => {
    const on = b.getAttribute('aria-pressed') !== 'true';
    apply(on, true);
    try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* 覚えられなくても動く */ }
  }));
  try { apply(localStorage.getItem(KEY) === '1'); } catch (e) { /* 同上 */ }
}

/* ---------------------------------------------------------------------
 * ライブ詳細の MIX ボタン（[data-mix-filter]）
 *   押すと全日程のタイムテーブルが MIX のバンド（.slot--mix）だけになる（CSS: .timeline--mix）。もう一度で元に戻る
 * ------------------------------------------------------------------- */
function setupMixFilter() {
  const buttons = document.querySelectorAll('[data-mix-filter]');
  buttons.forEach((b) => b.addEventListener('click', () => {
    const on = b.getAttribute('aria-pressed') !== 'true';
    document.querySelectorAll('.timeline').forEach((t) => t.classList.toggle('timeline--mix', on));
    buttons.forEach((x) => x.setAttribute('aria-pressed', String(on)));
  }));
}

/* ---------------------------------------------------------------------
 * ライブ一覧の「1ヶ月以上先を非表示」（input[data-hide-far="#一覧のid"]）
 *   チェックすると一覧に .hide-far が付いて、data-far のカードを CSS で隠す。状態は localStorage に覚える（最初はオン）
 * ------------------------------------------------------------------- */
function setupHideFar() {
  const box = document.querySelector('[data-hide-far]');
  const list = box && document.querySelector(box.dataset.hideFar);
  if (!list) return;
  const KEY = 'liveHideFar';
  const apply = (on) => { box.checked = on; list.classList.toggle('hide-far', on); };
  box.addEventListener('change', () => {
    apply(box.checked);
    // 解除したときは、出てきたカードをふわっと表示（検索・年度スロットと同じ animateIn）
    animateIn(box.checked ? [] : list.querySelectorAll('.live-card[data-far]'), 200);
    try { localStorage.setItem(KEY, box.checked ? '1' : '0'); } catch (e) { /* 覚えられなくても動く */ }
  });
  // 最初（覚えた値がないとき）はオン。外した人は '0' を覚えているのでオフのまま
  let saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) { /* 覚えが使えなければオン */ }
  apply(saved !== '0');
}

/* ---------------------------------------------------------------------
 * 横にはみ出す表（.table-scroll / 一覧の表のカード .table-card）をマウスでつかんで左右に動かす
 *   .table-card の中に .table-scroll がある画面では、はみ出すのは中の .table-scroll だけ
 *   （外のカードは scrollWidth <= clientWidth なので何もしない）
 *   入力欄・プルダウン・ボタンの上で押したときは、いつもどおり文字選択や操作をさせる。
 *   5px 以上動かしたら「ドラッグ」とみなし、離したときのクリックは無効にする（誤クリック防止）。
 *   スマホ（タッチ）は元々指でスクロールできるので、マウスのときだけ動かす。
 * ------------------------------------------------------------------- */
function setupDragScroll() {
  const boxes = [...document.querySelectorAll('.table-scroll, .table-card')];
  if (!boxes.length) return;

  // はみ出しているときだけ「つかめる」カーソルにする（列の追加や画面幅で変わるので毎回見直す）
  const markDraggable = () => boxes.forEach((box) => box.classList.toggle('is-draggable', box.scrollWidth > box.clientWidth));
  markDraggable();
  window.addEventListener('resize', markDraggable);
  document.addEventListener('click', (e) => { if (e.target.closest('[data-add-roster-col], [data-add-tt-col]')) setTimeout(markDraggable); });

  boxes.forEach((box) => {
    let startX = 0;
    let startScroll = 0;
    let pressed = false;
    let moved = false;

    box.addEventListener('pointerdown', (e) => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      if (e.target.closest('input, select, textarea, button, a, label')) return;
      if (box.scrollWidth <= box.clientWidth) return;
      pressed = true;
      moved = false;
      startX = e.clientX;
      startScroll = box.scrollLeft;
    });
    box.addEventListener('pointermove', (e) => {
      if (!pressed) return;
      const dx = e.clientX - startX;
      if (!moved && Math.abs(dx) < 5) return;
      if (!moved) {
        moved = true;
        box.classList.add('is-dragging');
        box.setPointerCapture(e.pointerId); // 表の外までマウスが出ても追いかける
        window.getSelection()?.removeAllRanges(); // 押した瞬間に始まった文字選択を消す
      }
      box.scrollLeft = startScroll - dx;
    });
    const release = () => {
      pressed = false;
      box.classList.remove('is-dragging');
      setTimeout(() => { moved = false; }); // 直後のクリックを捨て終わったら戻す
    };
    box.addEventListener('pointerup', release);
    box.addEventListener('pointercancel', release);
    // ドラッグの終わりに起きるクリックを捨てる（チェックボックスなどが勝手に切り替わらないように）
    box.addEventListener('click', (e) => {
      if (!moved) return;
      e.preventDefault();
      e.stopPropagation();
    }, true);
  });
}
