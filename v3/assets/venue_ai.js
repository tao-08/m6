/* =====================================================================
 *  assets/venue_ai.js — 会場詳細（venue.php）の「✨AIで入力」
 * =====================================================================
 *  assets/studio_info.js の setupAi の会場版。
 *   ・api_venue_ai.php に頼む（調べる項目 sections[]・検索元 source・モデル・画像）→ 提案をプレビュー → 項目ごとにチェックして「反映」（venue.php の apply_ai）
 *   ・提案の中身はサーバーのセッションにあるので、反映で送るのは「どの項目を使うか」だけ
 *  XSS 対策: AI が返した文字は textContent で入れる（HTML として解釈されない）
 * ===================================================================== */
(() => {
  'use strict';
  const box = document.getElementById('venue-ai');
  if (!box) return;

  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };

  const SECTIONS = { info: '基本情報', stations: '最寄り駅', equipment: '機材' };
  const INFO = { website_url: 'サイト', address: '住所', note: 'メモ' };
  const LINES = {
    info: (p) => Object.keys(INFO).filter((k) => p.info[k]).map((k) => INFO[k] + ': ' + p.info[k]),
    stations: (p) => p.stations.map((s) => s.name + '駅 徒歩' + s.minutes + '分'),
    equipment: (p) => p.equipment.map((e) => e.category + ': ' + e.model_name + (e.quantity !== 1 ? ' × ' + e.quantity : '')),
  };

  const btn = box.querySelector('[data-ai-run]');
  const status = box.querySelector('[data-ai-status]');
  const form = document.getElementById('venue-ai-result');
  const body = form.querySelector('[data-ai-body]');

  const show = (p, yenUsed) => {
    body.replaceChildren();
    if (!p.found) body.append(el('p', 'venue-ai__warn', '情報が見つかりませんでした。サイトの URL を入れてから試すと見つかりやすくなります'));
    let any = false;
    Object.keys(SECTIONS).forEach((key) => {
      const lines = LINES[key](p);
      if (!lines.length) return; // 提案が無い項目は出さない（反映もできない）
      any = true;
      const d = el('details', 'venue-ai__sec');
      const sum = el('summary');
      const label = el('label');
      const cb = el('input');
      cb.type = 'checkbox';
      cb.name = 'sections[]';
      cb.value = key;
      cb.checked = true;
      label.append(cb, document.createTextNode(' ' + SECTIONS[key]));
      sum.append(label, el('span', 'muted small', lines.length + '件'));
      const ul = el('ul', 'venue-ai__lines');
      lines.forEach((t) => ul.append(el('li', '', t)));
      d.append(sum, ul);
      body.append(d);
    });
    if (p.caution) body.append(el('p', 'venue-ai__warn', p.caution));
    if (p.sources.length) {
      const src = el('div', 'venue-ai__src muted small');
      src.append(el('span', '', '出典: '));
      p.sources.forEach((u) => {
        const a = el('a', '', new URL(u).hostname);
        a.href = u; // サーバーで http(s) だけに絞ってある
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        src.append(a, document.createTextNode(' '));
      });
      body.append(src);
    }
    form.querySelector('[type=submit]').hidden = !any;
    form.hidden = false;
    status.textContent = yenUsed != null ? '約' + yenUsed + '円' : '';
  };

  // ---- 画像（選ぶ・貼り付け）。長辺 1568px の JPEG に縮小してから送る ----
  const maxImages = Number(box.dataset.maxImages) || 5;
  const picker = box.querySelector('[data-ai-files]');
  const thumbs = box.querySelector('[data-ai-thumbs]');
  const images = [];

  const renderThumbs = () => {
    thumbs.replaceChildren();
    images.forEach((it, i) => {
      const wrap = el('span', 'venue-ai__thumb');
      const img = el('img');
      img.src = it.url; // URL.createObjectURL で作った、この端末の中だけの一時 URL
      img.alt = '画像' + (i + 1);
      const x = el('button', 'venue-ai__thumb-x', '×');
      x.type = 'button';
      x.setAttribute('aria-label', '画像を外す');
      x.addEventListener('click', () => {
        URL.revokeObjectURL(it.url);
        images.splice(images.indexOf(it), 1);
        renderThumbs();
      });
      wrap.append(img, x);
      thumbs.append(wrap);
    });
    thumbs.hidden = images.length === 0;
  };

  const shrink = async (file) => {
    try {
      const bmp = await createImageBitmap(file);
      const scale = Math.min(1, 1568 / Math.max(bmp.width, bmp.height));
      const c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bmp.width * scale));
      c.height = Math.max(1, Math.round(bmp.height * scale));
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; // 透明な PNG は白背景に（JPEG は透明にできない）
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.85));
      return blob ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) {
      return file; // ブラウザが読めない形式は、そのまま送ってサーバーに任せる
    }
  };

  const addImages = async (files) => {
    for (const f of [...files].filter((x) => x.type.startsWith('image/'))) {
      if (images.length >= maxImages) {
        status.textContent = '画像は' + maxImages + '枚までです';
        break;
      }
      const small = await shrink(f);
      images.push({ file: small, url: URL.createObjectURL(small) });
    }
    renderThumbs();
  };

  box.querySelector('[data-ai-pick]').addEventListener('click', () => picker.click());
  picker.addEventListener('change', async () => {
    await addImages(picker.files);
    picker.value = ''; // 同じ画像をもう一度選んでも change が起きるように
  });
  // 貼り付け（Ctrl+V）。文字の貼り付けは邪魔しない（画像があるときだけ受け取る）。編集を開いているときだけ
  document.addEventListener('paste', (e) => {
    const files = [...(e.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/'));
    if (!files.length || !box.closest('details')?.open) return;
    e.preventDefault();
    addImages(files);
  });

  btn.addEventListener('click', async () => {
    const picked = [...box.querySelectorAll('[data-ai-section]:checked')];
    if (!picked.length) {
      status.textContent = '調べる項目を選んでください';
      return;
    }
    btn.disabled = true;
    form.hidden = true;
    const t0 = Date.now();
    const verb = images.length ? '読んでいます' : '調べています'; // 画像があれば画像だけを読む（検索しない）
    const tick = () => { status.textContent = verb + '… ' + Math.round((Date.now() - t0) / 1000) + '秒'; };
    tick();
    const timer = setInterval(tick, 1000);
    try {
      // FormData で送る（画像ファイルも送れる）。Content-Type は自分で書かない（ブラウザが境界つきで付ける）
      const fd = new FormData();
      fd.append('venue_id', box.dataset.venueId);
      fd.append('model', box.querySelector('[data-ai-model]').value);
      picked.forEach((c) => fd.append('sections[]', c.value));
      if (box.querySelector('[data-ai-official]')?.checked) fd.append('source', 'official');
      const extra = box.querySelector('[data-ai-extra]')?.value.trim();
      if (extra) fd.append('extra', extra);
      images.forEach((it) => fd.append('images[]', it.file, it.file.name));
      const res = await fetch('api_venue_ai', { method: 'POST', headers: { 'X-CSRF-Token': box.dataset.csrf }, body: fd });
      const data = await res.json().catch(() => ({ error: 'サーバーの返事を読めませんでした' }));
      if (!res.ok || !data.ok) throw new Error(data.error || 'HTTP ' + res.status);
      show(data.proposal, data.yen);
    } catch (e) {
      status.textContent = e.message;
    } finally {
      clearInterval(timer);
      btn.disabled = false;
    }
  });
})();
