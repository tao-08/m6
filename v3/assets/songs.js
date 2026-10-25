/*
 * =====================================================================
 *  songs.js — 楽曲データベース（songs.php）の「楽曲情報を検索」登録
 * =====================================================================
 *  🔍で api_track_search（曲の編集画面と同じ API）を呼んで、出てきた曲を1つ選ぶ。
 *  フォームに入れるのは曲のキー（"spotify:xxxx"）だけ。曲名・アーティスト名・画像は
 *  サーバーが取り直して保存する（ブラウザから来た値は信用しない）。
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
  function notify(message, type) {
    const t = document.createElement('div');
    t.className = 'flash flash--' + (type || 'success');
    t.setAttribute('role', 'status');
    t.textContent = message;
    if (typeof window.showToast === 'function') window.showToast(t); else window.alert(message);
  }
  function icon(name) {
    const s = document.createElement('span');
    s.className = 'icon';
    s.setAttribute('aria-hidden', 'true');
    s.textContent = name;
    return s;
  }
  // 埋め込みプレイヤー。video_id は PHP / API が 11 文字の英数字と - _ だけに検証したもの
  function embed(videoId, title) {
    const f = document.createElement('iframe');
    f.src = 'https://www.youtube.com/embed/' + encodeURIComponent(videoId) + '?autoplay=1&playsinline=1&rel=0';
    f.allow = 'autoplay; encrypted-media; picture-in-picture';
    f.allowFullscreen = true;
    f.title = title || 'MV';
    return f;
  }

  // 曲モーダルの MV（1曲に1本）: 🎬🔍 で検索して一番上を自動で設定 / サムネイルでその場再生 / YouTube アイコンで YouTube へ / ✎ で検索リストから選び直し / 🗑で削除
  //   ユーザーや YouTube から来た文字（タイトル）は textContent にだけ入れる（innerHTML は使わない）
  function setupMv(modal) {
    const box = modal.querySelector('[data-mv]');
    if (!box) return;
    const list = box.querySelector('[data-mv-list]');
    const ids = { song_key: box.dataset.mvKey };
    const autoBtn = box.querySelector('[data-mv-auto]');
    const tpl = box.querySelector('[data-mv-tpl]');
    const dlg = box.querySelector('[data-mv-dlg]');

    const bindPlay = (btn) => btn.addEventListener('click', () => {
      btn.replaceChildren(embed(btn.dataset.mvPlay, btn.title));
      btn.classList.add('is-playing');
    }, { once: true });
    const bindDel = (btn) => btn.addEventListener('click', async () => {
      if (!window.confirm('削除しますか？')) return;
      btn.disabled = true;
      try {
        await post({ action: 'link_delete', link_id: Number(btn.closest('[data-link-id]').dataset.linkId) });
        btn.closest('li').remove();
        if (autoBtn) autoBtn.hidden = false;
      } catch (err) {
        btn.disabled = false;
        notify(err.message || '削除できませんでした', 'error');
      }
    });
    const bindItem = (li) => {
      bindPlay(li.querySelector('[data-mv-play]'));
      bindDel(li.querySelector('[data-mv-del]'));
      const edit = li.querySelector('[data-mv-open]');
      if (edit && dlg) edit.addEventListener('click', openDlg);
    };
    list.querySelectorAll('li').forEach(bindItem);
    if (!dlg || !tpl) return;

    const form = dlg.querySelector('[data-mv-form]');
    const results = dlg.querySelector('[data-mv-results]');
    const setMsg = (text) => { results.replaceChildren(); const li = document.createElement('li'); li.className = 'muted small'; li.textContent = text; results.appendChild(li); };
    const closeDlg = () => dlg.close();
    const query = () => form.elements.q.value.trim() || box.dataset.mvQuery || '';

    // 動画を1本だけ設定する（サーバーが古い MV を置き換える）。一覧もその1件に差し替える
    const setMv = async (v) => {
      const res = await post({ action: 'link_add', ...ids, url: v.url, label: v.title, kind: 'mv' });
      const li = tpl.content.firstElementChild.cloneNode(true);
      li.dataset.linkId = String(res.link.id);
      li.classList.add('is-new'); // 貼られたときの軽いアニメーション
      const play = li.querySelector('[data-mv-play]');
      play.dataset.mvPlay = v.video_id;
      play.title = v.title;
      li.querySelector('.songs-mv__title').textContent = v.title;
      play.querySelector('img').src = 'https://i.ytimg.com/vi/' + encodeURIComponent(v.video_id) + '/mqdefault.jpg';
      list.replaceChildren(li);
      bindItem(li);
      if (autoBtn) autoBtn.hidden = true;
    };
    const find = async () => {
      const json = await post({ action: 'yt_link_search', ...ids, q: query() });
      if (!Array.isArray(json.items)) throw new Error('検索できませんでした'); // 200 でも JSON でない応答（PHP の警告など）は {} になる
      return json.items;
    };

    const search = async () => {
      setMsg('…');
      try {
        const items = await find();
        results.replaceChildren();
        if (!items.length) { setMsg('見つかりませんでした'); return; }
        items.forEach((v) => {
          const li = document.createElement('li');
          li.className = 'yts__item';
          const thumb = document.createElement('button');
          thumb.type = 'button';
          thumb.className = 'yts__thumb';
          thumb.title = thumb.ariaLabel = '再生';
          const img = document.createElement('img');
          img.src = 'https://i.ytimg.com/vi/' + encodeURIComponent(v.video_id) + '/mqdefault.jpg';
          img.alt = '';
          img.loading = 'lazy';
          thumb.append(img, icon('play_circle'));
          thumb.addEventListener('click', () => { // 試し見（押したらその場で埋め込みに差し替え）
            li.classList.add('yts__item--play');
            thumb.replaceChildren(embed(v.video_id, v.title));
          }, { once: true });
          const text = document.createElement('div');
          text.className = 'yts__title';
          text.textContent = v.title;
          const ch = document.createElement('span');
          ch.className = 'yts__ch';
          ch.textContent = v.channel;
          text.appendChild(ch);
          const pick = document.createElement('button');
          pick.type = 'button';
          pick.className = 'icon-btn';
          pick.title = pick.ariaLabel = v.added ? '設定中' : '設定';
          pick.disabled = !!v.added;
          pick.appendChild(icon(v.added ? 'check' : 'add'));
          pick.addEventListener('click', async () => {
            pick.disabled = true;
            try {
              await setMv(v);
              closeDlg();
            } catch (err) {
              pick.disabled = false;
              notify(err.message || '設定できませんでした', 'error');
            }
          });
          li.append(thumb, text, pick);
          results.appendChild(li);
        });
      } catch (err) {
        setMsg(err.message || '検索できませんでした');
      }
    };
    function openDlg() {
      if (!form.elements.q.value) form.elements.q.value = box.dataset.mvQuery || '';
      dlg.showModal();
      if (!results.children.length) search();
    }
    if (autoBtn) {
      autoBtn.addEventListener('click', async () => { // 検索して一番上をそのまま設定
        autoBtn.disabled = true;
        try {
          const items = await find();
          if (!items.length) { notify('見つかりませんでした', 'error'); return; }
          await setMv(items[0]);
        } catch (err) {
          notify(err.message || '設定できませんでした', 'error');
        } finally {
          autoBtn.disabled = false;
        }
      });
    }
    form.addEventListener('submit', (e) => { e.preventDefault(); search(); });
    dlg.querySelector('[data-mv-close]').addEventListener('click', closeDlg);
    dlg.addEventListener('click', (e) => { e.stopPropagation(); if (e.target === dlg) closeDlg(); });
    dlg.addEventListener('close', () => results.replaceChildren()); // 閉じたら試し見の再生も止める（次に開くと検索し直す。結果はサーバーがセッションに覚えている）
  }

  // 曲名を押すと、その行の <dialog> に演奏履歴・数字を出す。背景（dialog 自体）を押しても閉じる
  // 検索で一覧を差し替えたあとにも呼ぶので、root の中だけを対象にする
  function setupModals(root) {
  root.querySelectorAll('[data-song-open]').forEach((btn) => {
    const modal = btn.closest('li').querySelector('[data-song-modal]');
    btn.addEventListener('click', () => modal.showModal());
    // 閉じるときも動かす: is-closing を付けて、アニメーションが終わってから close()（Esc も同じ）
    const closeModal = () => {
      if (!modal.open || modal.classList.contains('is-closing')) return;
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { modal.close(); return; }
      modal.classList.add('is-closing');
      modal.addEventListener('animationend', () => { modal.classList.remove('is-closing'); modal.close(); }, { once: true });
    };
    // ジャケットを押すと拡大表示（閉じる・背景を押す・Esc で閉じる。Esc で親のモーダルまで閉じないよう止める）
    const zoomBtn = modal.querySelector('[data-song-zoom]');
    const zoom = modal.querySelector('[data-song-zoom-box]');
    if (zoomBtn && zoom) {
      const closeZoom = () => {
        if (!zoom.open || zoom.classList.contains('is-closing')) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { zoom.close(); return; }
        zoom.classList.add('is-closing');
        // ::backdrop や中身のアニメーションも zoom に届くので、閉じるアニメーション（songs-zoom-out）だけを待つ。1回だけ動かす（残すと次に開いた直後に閉じる）
        const onEnd = (e) => {
          if (e.target !== zoom || e.animationName !== 'songs-zoom-out') return;
          zoom.removeEventListener('animationend', onEnd);
          zoom.classList.remove('is-closing');
          zoom.close();
        };
        zoom.addEventListener('animationend', onEnd);
      };
      zoomBtn.addEventListener('click', () => zoom.showModal());
      zoom.querySelector('[data-song-zoom-close]').addEventListener('click', closeZoom);
      zoom.addEventListener('click', (e) => { e.stopPropagation(); if (e.target === zoom) closeZoom(); });
      zoom.addEventListener('cancel', (e) => { e.preventDefault(); e.stopPropagation(); closeZoom(); });
    }
    // 「メインにまとめる」: 押すと警告のモーダルを開く（キャンセルで閉じる）
    modal.querySelectorAll('[data-merge-ask]').forEach((ask) => {
      const box = ask.nextElementSibling;
      ask.addEventListener('click', () => box.showModal());
      box.querySelector('[data-merge-cancel]').addEventListener('click', () => box.close());
      box.addEventListener('click', (e) => { if (e.target === box) box.close(); });
    });
    setupMv(modal);
    modal.querySelector('[data-song-close]').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    modal.addEventListener('cancel', (e) => { e.preventDefault(); closeModal(); });
  });
  }
  setupModals(document);

  // 検索: ページ移動なしで、同じ URL を取り直して一覧だけ入れ替える（並べ替え・ページ送りのリンクは今までどおり）
  const filter = document.querySelector('[data-songs-filter]');
  const results = document.querySelector('[data-songs-results]');
  if (filter && results) {
    let timer = null;
    let ctrl = null;
    const refresh = async () => {
      const params = new URLSearchParams(new FormData(filter));
      for (const [k, v] of [...params]) if (v === '') params.delete(k);
      const url = 'songs' + (params.toString() ? '?' + params : '');
      ctrl?.abort();
      ctrl = new AbortController();
      try {
        const res = await fetch(url, { credentials: 'same-origin', signal: ctrl.signal });
        if (!res.ok) throw new Error();
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const next = doc.querySelector('[data-songs-results]');
        if (!next) throw new Error();
        results.replaceChildren(...document.importNode(next, true).childNodes);
        setupModals(results);
        results.classList.remove('is-updated');
        void results.offsetWidth; // アニメーションを毎回やり直す
        results.classList.add('is-updated');
        history.replaceState(null, '', url);
      } catch (err) {
        if (err.name !== 'AbortError') window.location.href = url; // 取り直せなければ通常の検索へ
      }
    };
    filter.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(timer); refresh(); });
    filter.querySelectorAll('select').forEach((s) => s.addEventListener('change', () => { clearTimeout(timer); refresh(); }));
    const qInput = filter.querySelector('input[name="q"]');
    qInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 250); });

    // 検索履歴（直近5件。この端末のブラウザにだけ保存）。バーを押すと出て、選ぶとその語で検索する
    const HISTORY_KEY = 'songs_search_history';
    const readHistory = () => {
      try {
        const v = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
        return Array.isArray(v) ? v.filter((s) => typeof s === 'string').slice(0, 5) : [];
      } catch (err) { return []; }
    };
    const saveHistory = (word) => {
      word = word.trim();
      if (!word) return;
      try { localStorage.setItem(HISTORY_KEY, JSON.stringify([word, ...readHistory().filter((s) => s !== word)].slice(0, 5))); } catch (err) { /* 保存できなくても検索は動く */ }
    };
    const box = document.createElement('ul');
    box.className = 'songs-history';
    box.hidden = true;
    qInput.parentNode.insertBefore(box, qInput.nextSibling);
    const showHistory = () => {
      const items = readHistory();
      box.replaceChildren(...items.map((word) => {
        const li = document.createElement('li');
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'songs-history__item';
        b.append(icon('history'), document.createTextNode(word)); // textContent 相当（innerHTML は使わない）
        // mousedown で処理（click だと先にバーの blur で閉じてしまう）
        b.addEventListener('mousedown', (e) => {
          e.preventDefault();
          qInput.value = word;
          box.hidden = true;
          saveHistory(word);
          clearTimeout(timer);
          refresh();
        });
        li.appendChild(b);
        return li;
      }));
      box.hidden = items.length === 0;
    };
    qInput.addEventListener('focus', showHistory);
    qInput.addEventListener('click', showHistory);
    qInput.addEventListener('blur', () => { box.hidden = true; saveHistory(qInput.value); });
    filter.addEventListener('submit', () => { box.hidden = true; saveHistory(qInput.value); });
  }

  // 高画質版の画像が読めなかったら、保存してある元の画像に戻す（error は伝わらないので capture で受ける）
  document.addEventListener('error', (e) => {
    const img = e.target;
    if (img instanceof HTMLImageElement && img.dataset.fallback && img.src !== img.dataset.fallback) img.src = img.dataset.fallback;
  }, true);

  // セットリストの曲名から来たとき（?open=）は、その曲のモーダルを最初から開く
  const auto = document.querySelector('[data-song-autoopen] [data-song-modal]');
  if (auto) { auto.showModal(); auto.closest('li').scrollIntoView({ block: 'center' }); }
  // パンくずから戻ったとき（?at=）は、その曲の位置までスクロールだけする
  const at = document.querySelector('[data-song-scroll]');
  if (at) at.scrollIntoView({ block: 'center' });

  const form = document.querySelector('[data-song-link]');
  if (!form) return;
  const q = form.querySelector('[data-song-q]');
  const list = form.querySelector('[data-song-results]');
  const chosen = form.querySelector('[data-song-chosen]');
  const hidden = form.querySelector('[data-song-track]');
  const submit = form.querySelector('[data-song-submit]');

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function choose(t) {
    hidden.value = t.key;
    submit.disabled = false;
    chosen.hidden = false;
    chosen.textContent = `${t.title} / ${t.artist_name}`;
    list.replaceChildren();
  }

  async function search() {
    const term = q.value.trim();
    if (term === '') return;
    list.replaceChildren(el('li', 'muted small', '…'));
    try {
      const res = await fetch(`api_track_search?q=${encodeURIComponent(term)}`, { credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error || '検索できませんでした');
      list.replaceChildren();
      if (json.tracks.length === 0) list.appendChild(el('li', 'muted small', '見つかりません'));
      json.tracks.forEach((t) => {
        const li = el('li');
        const b = el('button', 'songs-add__result');
        b.type = 'button';
        if (typeof t.artwork_url === 'string' && t.artwork_url.startsWith('https://')) {
          const img = el('img');
          img.src = t.artwork_url;
          img.alt = '';
          img.width = img.height = 40;
          b.appendChild(img);
        }
        const text = el('span', 'songs-add__result-text');
        text.append(el('span', 'songs-add__result-title', t.title), el('span', 'muted small', t.artist_name));
        b.appendChild(text);
        b.addEventListener('click', () => choose(t));
        li.appendChild(b);
        list.appendChild(li);
      });
    } catch (err) {
      list.replaceChildren(el('li', 'small', err.message || '検索できませんでした'));
    }
  }

  form.querySelector('[data-song-search]').addEventListener('click', search);
  // Enter で検索（フォームの送信ではなく）
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
})();
