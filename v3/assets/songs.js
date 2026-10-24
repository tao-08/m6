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
  // 曲名を押すと、その行の <dialog> に演奏履歴・数字を出す。背景（dialog 自体）を押しても閉じる
  document.querySelectorAll('[data-song-open]').forEach((btn) => {
    const modal = btn.closest('li').querySelector('[data-song-modal]');
    btn.addEventListener('click', () => modal.showModal());
    // 閉じるときも動かす: is-closing を付けて、アニメーションが終わってから close()（Esc も同じ）
    const closeModal = () => {
      if (!modal.open || modal.classList.contains('is-closing')) return;
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { modal.close(); return; }
      modal.classList.add('is-closing');
      modal.addEventListener('animationend', () => { modal.classList.remove('is-closing'); modal.close(); }, { once: true });
    };
    // スマホ（hover が無い）: 1回目のタップ はアイコンを出すだけ。出たアイコンを押すと音楽アプリへ飛ぶ
    const wrap = modal.querySelector('[data-song-listen]');
    if (wrap) {
      const link = wrap.querySelector('a');
      link.addEventListener('click', (e) => {
        if (!window.matchMedia('(hover: none)').matches || wrap.classList.contains('is-open')) return;
        e.preventDefault();
        wrap.classList.add('is-open');
      });
      modal.addEventListener('click', (e) => { if (!wrap.contains(e.target)) wrap.classList.remove('is-open'); });
    }
    modal.querySelector('[data-song-close]').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    modal.addEventListener('cancel', (e) => { e.preventDefault(); closeModal(); });
  });

  // 高画質版の画像が読めなかったら、保存してある元の画像に戻す（error は伝わらないので capture で受ける）
  document.addEventListener('error', (e) => {
    const img = e.target;
    if (img instanceof HTMLImageElement && img.dataset.fallback && img.src !== img.dataset.fallback) img.src = img.dataset.fallback;
  }, true);

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
