/*
 * =====================================================================
 *  practice.js — 練習プレイヤーの入口（practice.php）
 * =====================================================================
 *  1. 動画検索ダイアログ: api_tab_save の yt_link_search で探して、サムネイルで試し見。
 *     ▶ を押すと link_add で曲全体のカードに登録して、そのまま練習プレイヤー（tab?link=）へ。登録済みならそのリンクへ
 *  2. 楽曲データベースに登録: api_track_search で探して、選んだ曲を songs.php の add_linked に送る（戻り先は practice?key=）
 *  XSS 対策: 検索結果の文字は textContent / src にだけ入れる（innerHTML は使わない）。
 * =====================================================================
 */
(() => {
  'use strict';
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  async function post(body) {
    const res = await fetch('api_tab_save', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || '通信できませんでした');
    return json;
  }
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }
  const icon = (name) => { const s = el('span', 'icon', name); s.setAttribute('aria-hidden', 'true'); return s; };

  // ---- 0. 未ログインのピン留め: 端末（localStorage）に持つ。ログインすると使われない ----
  const GUEST = !!window.PRAC_GUEST;
  const PIN_KEY = 'prac_pins';
  const loadPins = () => {
    try { const a = JSON.parse(localStorage.getItem(PIN_KEY) || '[]'); return Array.isArray(a) ? a.filter((p) => p && typeof p.key === 'string' && typeof p.title === 'string') : []; } catch (err) { return []; }
  };
  const savePins = (a) => { try { localStorage.setItem(PIN_KEY, JSON.stringify(a.slice(0, 50))); } catch (err) { /* 保存できない端末では何もしない */ } };
  if (GUEST) {
    const pins = loadPins();
    document.querySelectorAll('[data-pin]').forEach((b) => {
      const on = pins.some((p) => p.key === b.dataset.key);
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    const ul = document.querySelector('[data-guest-pins]');
    if (ul && pins.length) {
      ul.replaceChildren(...pins.map((p) => {
        const li = el('li', 'card prac-item');
        const a = el('a', 'prac-item__main');
        a.href = 'practice?key=' + encodeURIComponent(p.key);
        const body = el('span', 'prac-item__body');
        body.append(el('span', 'prac-item__title', p.title), el('span', 'muted small', p.artist || ''));
        a.append(icon('search'), body);
        const x = el('button', 'icon-btn prac-pin is-on');
        x.type = 'button';
        x.dataset.pin = '';
        Object.assign(x.dataset, { key: p.key, title: p.title, artist: p.artist || '' });
        x.setAttribute('aria-pressed', 'true');
        x.title = x.ariaLabel = 'ピン留め';
        x.appendChild(icon('push_pin'));
        li.append(a, x);
        return li;
      }));
      ul.hidden = false;
      document.querySelector('[data-guest-pins-head]').hidden = false;
    }
  }

  // ---- 0. ピン留め: api_practice_pin に送る。ピン留め欄の並びも変わるので、押したら再読み込み ----
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-pin]');
    if (!btn) return;
    const on = btn.getAttribute('aria-pressed') !== 'true';
    if (GUEST) {
      const pins = loadPins().filter((p) => p.key !== btn.dataset.key);
      if (on) pins.unshift({ key: btn.dataset.key, title: btn.dataset.title.slice(0, 100), artist: (btn.dataset.artist || '').slice(0, 255) });
      savePins(pins);
      location.reload();
      return;
    }
    btn.disabled = true;
    try {
      const res = await fetch('api_practice_pin', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ key: btn.dataset.key, title: btn.dataset.title, artist: btn.dataset.artist, on }),
      });
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'ピン留めできませんでした');
      location.reload();
    } catch (err) {
      btn.disabled = false;
      window.alert(err.message);
    }
  });

  // ---- 0. 練習中から外す（×）: 練習の設定は残して、リストからだけ消す。最後の1件なら見出しも消す ----
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-unpractice]');
    if (!btn) return;
    btn.disabled = true;
    try {
      await post({ action: 'link_practicing', link_id: Number(btn.dataset.unpractice), on: false });
      const ul = btn.closest('ul');
      btn.closest('li').remove();
      if (ul && !ul.children.length) { ul.previousElementSibling?.remove(); ul.remove(); }
    } catch (err) {
      btn.disabled = false;
      window.alert(err.message);
    }
  });

  // ---- 1. 動画検索 ----
  const dlg = document.querySelector('[data-yts]');
  if (dlg) {
    const form = dlg.querySelector('[data-yts-form]');
    const qInput = form.elements.q;
    const list = dlg.querySelector('[data-yts-list]');
    let ctx = null; // 開いた曲 { key, songId, urls: {url: link_id} }
    const setMsg = (text) => list.replaceChildren(el('li', 'muted small', text));

    // 動画タイトルから楽器を判別（登録先のパートのカード）。1つに決まらなければ etc（その他）のカード。あとで tab 画面で変更できる
    const INST = [
      ['Ba', /ベース|bass/i],
      ['Dr', /ドラム|drum|叩いてみた/i],
      ['Gt', /ギター|guitar/i],
      ['Key', /ピアノ|キーボード|piano|keyboard/i],
      ['Vo', /歌ってみた|ボーカル|vocal/i],
    ];
    const detectInst = (title) => {
      if (/ベースギター|bass\s*guitar/i.test(title)) return 'Ba';
      const hit = INST.filter(([, re]) => re.test(title)).map(([k]) => k);
      return hit.length === 1 ? hit[0] : 'etc'; // 判別できなければ「その他」
    };

    // DB に無い曲（未ログイン）は登録せず、動画IDのまま再生画面へ（設定は端末に保存。DB に同じ動画があれば tab.php が ?link= へ移す）
    const VIDEO_ID = /(?:[?&]v=|youtu\.be\/|shorts\/|embed\/|live\/)([\w-]{11})(?![\w-])/;
    const goFree = (id, title) => { location.href = 'tab?yt=' + encodeURIComponent(id) + (title ? '&t=' + encodeURIComponent(title.slice(0, 100)) : ''); };

    const go = async (v, btn) => {
      if (ctx.free) { goFree(v.video_id, v.title); return; }
      const known = ctx.urls[v.url];
      if (known) { location.href = 'tab?link=' + encodeURIComponent(known); return; }
      btn.disabled = true;
      try {
        const inst = detectInst(v.title);
        const json = await post({ action: 'link_add', song_key: ctx.key, part: '', url: v.url, label: v.title.slice(0, 100),
          ...(inst ? { instrument: inst } : {}), ...(ctx.songId ? { song_id: ctx.songId } : {}) });
        location.href = 'tab?link=' + encodeURIComponent(json.link.id);
      } catch (err) {
        btn.disabled = false;
        window.alert(err.message);
      }
    };

    const search = async () => {
      // 検索中は前の結果を薄くして残す（結果が来たら1件ずつずらして出す。CSS の .is-loading と --i）
      if (list.querySelector('.yts__item')) list.classList.add('is-loading'); else setMsg('…');
      try {
        if (ctx.free && !qInput.value.trim()) { list.classList.remove('is-loading'); setMsg('…'); return; }
        const json = await post(ctx.free ? { action: 'yt_free_search', q: qInput.value.trim() }
          : { action: 'yt_link_search', song_key: ctx.key, part: '', q: qInput.value.trim(), ...(ctx.songId ? { song_id: ctx.songId } : {}) });
        if (!qInput.value.trim()) qInput.value = json.q;
        list.classList.remove('is-loading');
        list.replaceChildren();
        list.scrollTop = 0;
        if (!json.items.length) { setMsg('見つかりませんでした'); return; }
        json.items.forEach((v, i) => {
          const li = el('li', 'yts__item');
          li.style.setProperty('--i', i);
          const thumb = el('button', 'yts__thumb');
          thumb.type = 'button';
          thumb.title = '試し見';
          const img = el('img');
          img.src = 'https://i.ytimg.com/vi/' + encodeURIComponent(v.video_id) + '/mqdefault.jpg';
          img.alt = '';
          img.loading = 'lazy';
          thumb.append(img, icon('play_circle'));
          thumb.addEventListener('click', () => {
            list.querySelectorAll('.yts__item--play').forEach((o) => o.classList.remove('yts__item--play'));
            list.querySelectorAll('iframe').forEach((f) => f.replaceWith(f._img));
            const frame = el('iframe');
            frame.src = 'https://www.youtube.com/embed/' + encodeURIComponent(v.video_id) + '?autoplay=1&playsinline=1&rel=0';
            frame.allow = 'autoplay; encrypted-media; picture-in-picture';
            frame.title = v.title;
            frame._img = img;
            img.replaceWith(frame);
            li.classList.add('yts__item--play');
          });
          const text = el('span', 'yts__title', v.title);
          text.appendChild(el('span', 'yts__ch', v.channel));
          const btn = el('button', 'btn btn--primary btn--sm');
          btn.type = 'button';
          btn.title = '練習プレイヤー';
          btn.setAttribute('aria-label', '練習プレイヤー');
          btn.appendChild(icon('slow_motion_video'));
          const inst = detectInst(v.title); // 判別できた楽器を見せる（登録済みでも。etc は出さない）
          if (inst && inst !== 'etc') { btn.append(' ' + inst); btn.title = ctx.free || ctx.urls[v.url] ? inst : inst + ' に追加して練習プレイヤー'; }
          btn.addEventListener('click', () => go(v, btn));
          li.append(thumb, text, btn);
          list.appendChild(li);
        });
      } catch (err) {
        list.classList.remove('is-loading');
        setMsg(err.message);
      }
    };

    const open = (b) => {
      let urls = {};
      try { urls = JSON.parse(b.dataset.urls || '{}') || {}; } catch (err) { /* 空のまま */ }
      ctx = { free: 'free' in b.dataset, key: b.dataset.key, songId: b.dataset.songId ? Number(b.dataset.songId) : null, urls };
      qInput.value = b.dataset.q || '';
      dlg.showModal();
      search();
    };
    document.querySelectorAll('[data-yts-open]').forEach((b) => b.addEventListener('click', () => open(b)));
    form.addEventListener('submit', (e) => { e.preventDefault(); search(); });
    // API が使えない（枠切れ・キー無し）ときのために、動画の URL を貼り付けて登録（link_add がサーバーで URL を確かめる）
    const paste = dlg.querySelector('[data-yts-paste]');
    paste.addEventListener('submit', async (e) => {
      e.preventDefault();
      const url = paste.elements.url.value.trim();
      if (!url) return;
      const btn = paste.querySelector('button');
      btn.disabled = true;
      try {
        if (ctx.free) {
          const m = VIDEO_ID.exec(url);
          if (!m) throw new Error('YouTube の動画の URL を貼り付けてください');
          goFree(m[1], '');
          return;
        }
        const json = await post({ action: 'link_add', song_key: ctx.key, part: '', url, label: '', ...(ctx.songId ? { song_id: ctx.songId } : {}) });
        if (json.link.site !== 'youtube') throw new Error('YouTube の動画の URL を貼り付けてください');
        location.href = 'tab?link=' + encodeURIComponent(json.link.id);
      } catch (err) {
        btn.disabled = false;
        window.alert(err.message);
      }
    });
    dlg.querySelectorAll('[data-yts-word]').forEach((c) => c.addEventListener('click', () => {
      const w = c.dataset.ytsWord;
      if (!qInput.value.includes(w)) qInput.value = (qInput.value.trim() + ' ' + w).trim();
      search();
    }));
    dlg.querySelector('[data-yts-close]').addEventListener('click', () => dlg.close());
    dlg.addEventListener('close', () => list.replaceChildren()); // 再生中の試し見を止める
    const auto = document.querySelector('[data-yts-open][data-auto]'); // 登録直後（?key=）でリンクが無ければ、すぐ検索
    if (auto) open(auto);
  }

  // ---- 0. 入力中の候補（practice?suggest=1&q=…）。↑↓ で選んで Enter、押すとリンクがあれば練習プレイヤーへ直接 ----
  const sForm = document.querySelector('[data-prac-suggest]');
  if (sForm) {
    const input = sForm.elements.q;
    const box = sForm.querySelector('[data-prac-suggest-list]');
    let timer = null;
    let ctrl = null;
    let active = -1;
    const items = () => [...box.querySelectorAll('.prac-suggest__item')];
    const setActive = (i) => {
      const all = items();
      active = all.length ? (i + all.length) % all.length : -1;
      all.forEach((a, n) => a.classList.toggle('is-active', n === active));
    };
    const fetchSuggest = async () => {
      const q = input.value.trim();
      if (!q) { box.hidden = true; return; }
      ctrl?.abort();
      ctrl = new AbortController();
      try {
        const res = await fetch('practice?suggest=1&q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctrl.signal });
        const json = await res.json();
        active = -1;
        box.replaceChildren(...json.items.map((s, i) => {
          const li = el('li');
          const a = el('a', 'prac-suggest__item' + (s.links ? ' prac-suggest__item--link' : ''));
          a.style.setProperty('--i', i); // 出てくる順番（CSS のアニメーションの遅れ）
          a.href = s.url; // サーバーが作った tab?link= / practice?key= だけ
          const body = el('span', 'prac-item__body');
          body.append(el('span', 'prac-item__title', s.title), el('span', 'muted small', s.artist));
          a.append(icon(s.links ? 'slow_motion_video' : 'search'), body);
          a.addEventListener('mousedown', (e) => e.preventDefault()); // blur で閉じる前にクリックを通す
          li.appendChild(a);
          return li;
        }));
        box.hidden = json.items.length === 0 || document.activeElement !== input;
      } catch (err) { /* 中断・失敗は候補なしのまま */ }
    };
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(fetchSuggest, 200); });
    input.addEventListener('focus', () => { if (box.children.length) box.hidden = false; else if (input.value.trim()) fetchSuggest(); });
    input.addEventListener('blur', () => { box.hidden = true; });
    input.addEventListener('keydown', (e) => {
      if (box.hidden) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); setActive(active + (e.key === 'ArrowDown' ? 1 : -1)); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items()[active].click(); }
      else if (e.key === 'Escape') { box.hidden = true; }
    });
  }

  // ---- 2. 楽曲データベースに登録 ----
  const box = document.querySelector('[data-prac-new]');
  if (box) {
    const list = box.querySelector('[data-prac-new-list]');
    const form = box.querySelector('[data-prac-new-form]');
    let loaded = false;
    const load = async () => {
      if (loaded || !box.open) return;
      loaded = true;
      list.replaceChildren(el('li', 'muted small', '…'));
      try {
        const res = await fetch('api_track_search?q=' + encodeURIComponent(box.dataset.q), { credentials: 'same-origin' });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || '検索できませんでした');
        list.replaceChildren();
        if (!json.tracks.length) list.appendChild(el('li', 'muted small', '見つかりません'));
        json.tracks.forEach((t) => {
          const li = el('li');
          const b = el('button', 'prac-new__item');
          b.type = 'button';
          if (typeof t.artwork_url === 'string' && t.artwork_url.startsWith('https://')) {
            const img = el('img');
            img.src = t.artwork_url;
            img.alt = '';
            img.width = img.height = 40;
            b.appendChild(img);
          }
          const text = el('span', 'prac-item__body');
          text.append(el('span', 'prac-item__title', t.title), el('span', 'muted small', [t.artist_name, t.album_title].filter(Boolean).join(' · ')));
          b.append(text, icon('add'));
          b.addEventListener('click', () => { form.elements.track.value = t.key; form.submit(); });
          li.appendChild(b);
          list.appendChild(li);
        });
      } catch (err) {
        loaded = false;
        list.replaceChildren(el('li', 'small', err.message));
      }
    };
    box.addEventListener('toggle', load);
    load();
  }
})();
