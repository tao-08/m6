/*
 * app_admin.js — 管理・編集の補助（アーティストの読み・楽器・ライブの添付画像・浮き枠）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/**
 * アーティストの編集欄の「AI で読みを入れる」（artist.php → api_artist_kana.php）。
 * 名前欄の今の値を送って、返ってきた読みをふりがな欄に入れるだけ。保存は「名前・ふりがなを変更」で人がする
 */
function setupKanaAi() {
  const btn = document.querySelector('[data-kana-ai]');
  if (!btn) return;
  const form = btn.closest('form');
  const nameInput = form.querySelector('input[name="name"]');
  const kanaInput = form.querySelector('input[name="name_kana"]');
  const status = form.querySelector('[data-kana-ai-status]');
  btn.addEventListener('click', async () => {
    const name = nameInput.value.trim();
    if (!name) return;
    btn.disabled = true;
    status.textContent = 'AI に聞いています…';
    try {
      const res = await postJson('api_artist_kana', { name });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.kana) throw new Error(data.error || 'AI の呼び出しに失敗しました');
      kanaInput.value = data.kana;
      // 値を JS で入れただけでは input イベントが出ない → 未保存の警告（form[data-unsaved]）が気づけるように出す
      kanaInput.dispatchEvent(new Event('input', { bubbles: true }));
      status.textContent = '入れました。確かめてから保存してください';
    } catch (e) {
      status.textContent = e.message;
    } finally {
      btn.disabled = false;
    }
  });
}

/**
 * 入力欄 1 つを「入力が止まって約0.8秒後に自動保存」にする共通の部品（管理 > アーティスト・楽器の管理）。
 *   input    … 対象の欄
 *   opts.url / opts.body(value) … 送り先と、送る JSON（value = 整えた入力値）
 *   opts.required … true なら空のときは送らず赤くするだけ
 *   opts.onSaved(savedValue) … 保存できたあとの処理（返ってきた値は、サーバーが整えたもの）
 *   デバウンスとは: 入力のたびに送るとサーバーも DB も忙しくなるので、「入力が止まってから1回だけ」送る業界標準の工夫。
 *   確定（change = 欄を離れた・ピッカーを閉じた）ときは待たずにすぐ送る。ページを離れる直前も、待っている分は keepalive で送りきる。
 *   見た目: 保存中 .is-saving / 成功 .is-saved（緑の枠が少しだけ出る）/ 失敗 .is-error ＋ 画面上部にエラー
 */
function bindAutosave(input, opts) {
  const DELAY_MS = 800;
  let timer = null;
  let saved = input.value;  // 最後に保存できた値（同じ値なら送らない）
  let seq = 0;              // 送った順番。遅れて返ってきた古い返事で、新しい入力を上書きしないため

  const save = async (keepalive = false) => {
    clearTimeout(timer);
    timer = null;
    const value = input.value.trim();
    if (value === saved.trim()) { input.classList.remove('is-saving', 'is-error'); return; }
    // 必須の欄を空のまま送ると必ずエラーになるので、送らずに赤くするだけ
    if (opts.required && value === '') { input.classList.add('is-error'); return; }
    const mine = ++seq;
    input.classList.remove('is-error', 'is-saved');
    input.classList.add('is-saving');
    try {
      const data = await apiPost(opts.url, opts.body(value), '保存できませんでした', { keepalive });
      if (mine !== seq) return; // もっと新しい入力を送っている。そちらの結果に任せる
      saved = data.value;
      if (input.value.trim() === value && input.type !== 'color') input.value = data.value; // サーバーが整えた形に直す。入力の続きがあれば触らない
      input.classList.remove('is-saving');
      input.classList.add('is-saved');
      setTimeout(() => input.classList.remove('is-saved'), 1200);
      opts.onSaved?.(data.value);
    } catch (e) {
      if (mine !== seq) return;
      input.classList.remove('is-saving');
      input.classList.add('is-error');
      flashToast(e.message, 'error');
    }
  };

  // 入力のたびに「0.8秒後に保存」を予約し直す（= デバウンス）。touch() は外から同じ予約をしたいとき用
  const touch = () => {
    input.classList.remove('is-error', 'is-saved');
    clearTimeout(timer);
    timer = setTimeout(save, DELAY_MS);
  };
  input.addEventListener('input', touch);
  input.addEventListener('change', () => save());
  // ページを離れる（別タブへ移る・閉じる）直前に、待っている分があれば送る
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden' && timer !== null) save(true);
  });
  return { touch };
}

/**
 * 管理 > アーティスト（masters.php）の名前・ふりがな・別称を、保存ボタンなしで自動保存する。
 *   対象: input[data-artist-save="name|name_kana|aliases"][data-artist-id]（送り先は api_artist_save.php）
 */
function setupArtistAutosave() {
  document.querySelectorAll('input[data-artist-save]').forEach((input) => {
    const field = input.dataset.artistSave;
    bindAutosave(input, {
      url: 'api_artist_save',
      body: (value) => ({ artist_id: Number(input.dataset.artistId), field, value }),
      required: field === 'name',
      onSaved: (v) => { if (field === 'name') syncArtistRow(input, v); },
    });
  });
}

/**
 * 楽器の管理（instruments.php）の色・略称・正式名称を、保存ボタンなしで自動保存する。
 *   対象: [data-inst-save="color|short_name|name"][data-inst-id]（送り先は api_instrument_save.php）
 *   色はピッカーの値だけを送る（色コード欄は setupInstrumentHex がピッカーに写すので、ピッカーを見れば足りる）
 */
function setupInstrumentAutosave() {
  document.querySelectorAll('[data-inst-save]').forEach((input) => {
    const field = input.dataset.instSave;
    const ctl = bindAutosave(input, {
      url: 'api_instrument_save',
      body: (value) => ({ instrument_id: Number(input.dataset.instId), field, value }),
      required: field !== 'color',
    });
    // 色コード欄に打った色は、JS でピッカーの値を書き換えるだけで input イベントが出ない → こちらから保存を予約する
    //   （setupInstrumentHex が先に登録されているので、このときピッカーは更新済み。形が違う入力ならピッカーは変わらず、保存はされない）
    if (field === 'color') input.closest('.inst-admin__colorbox')?.querySelector('[data-inst-hex]')?.addEventListener('input', ctl.touch);
  });
}

/** 名前を保存したあと、同じ行の絞り込み用の文字・統合/削除の確認文に残っている古い名前を新しい名前に直す */
function syncArtistRow(input, newName) {
  const row = input.closest('tr');
  if (!row) return;
  const oldName = row.dataset.text;
  row.dataset.text = newName;
  if (!oldName || oldName === newName) return;
  row.querySelectorAll('form[data-confirm]').forEach((f) => {
    f.dataset.confirm = f.dataset.confirm.split(oldName).join(newName);
  });
  row.querySelectorAll('[aria-label]').forEach((el) => {
    if (el !== input) el.setAttribute('aria-label', el.getAttribute('aria-label').split(oldName).join(newName));
  });
}

/**
 * プレビューの浮いた枠を、PC ではウィンドウのように動かせて、右下の角で大きさも変えられるようにする
 * （セッティングシート作成 setting_sheet.js・型紙エディタ sheet_editor.js）。スマホは今のまま（画面いっぱい）。
 *   上に「つかむバー」を足す。位置と大きさは localStorage（key）に覚えて、次に開いたときも同じ所に出す
 *   onResize: 大きさが変わったとき（中の PDF の幅を合わせ直す用）
 *   開いたあとに呼ぶ place() を返す（hidden の間は大きさが測れないので、開くたびに呼んでもらう）
 */
function floatWin(box, key, onResize = () => {}) {
  const pc = window.matchMedia('(min-width: 761px)');
  const bar = document.createElement('div');
  bar.className = 'float-win__bar';
  bar.innerHTML = '<span class="icon" aria-hidden="true">drag_indicator</span>';
  bar.title = 'ドラッグで移動';
  box.prepend(bar);
  box.classList.add('float-win');
  const load = () => { try { return JSON.parse(localStorage.getItem(key)); } catch { return null; } };
  const store = () => {
    if (!pc.matches || box.hidden) return;
    const r = box.getBoundingClientRect();
    try { localStorage.setItem(key, JSON.stringify({ x: r.left, y: r.top, w: r.width, h: r.height })); } catch { /* 覚えられなくても動く */ }
  };
  // 画面からはみ出さないように収めて、left / top / width / height で置く（right / bottom の指定は消す）
  const put = (x, y, w, h) => {
    w = Math.min(Math.max(w, 260), innerWidth - 16);
    h = Math.min(Math.max(h, 200), innerHeight - 16);
    x = Math.min(Math.max(x, 8), innerWidth - w - 8);
    y = Math.min(Math.max(y, 8), innerHeight - h - 8);
    Object.assign(box.style, { left: `${x}px`, top: `${y}px`, width: `${w}px`, height: `${h}px`, right: 'auto', bottom: 'auto' });
  };
  function place() {
    if (!pc.matches) {
      box.removeAttribute('style'); // スマホは CSS の置き方に戻す
      return;
    }
    const s = load();
    if (s) {
      put(s.x, s.y, s.w, s.h);
    } else {
      const r = box.getBoundingClientRect(); // CSS の初期位置（右側）のまま、動かせる形にする
      put(r.left, r.top, r.width, r.height);
    }
  }
  let drag = null;
  bar.addEventListener('pointerdown', (e) => {
    if (!pc.matches) return;
    e.preventDefault();
    const r = box.getBoundingClientRect();
    drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, w: r.width, h: r.height };
    bar.setPointerCapture(e.pointerId);
    box.classList.add('is-moving'); // 中の iframe にマウスを取られないように
  });
  bar.addEventListener('pointermove', (e) => {
    if (drag) put(e.clientX - drag.dx, e.clientY - drag.dy, drag.w, drag.h);
  });
  const end = () => {
    if (!drag) return;
    drag = null;
    box.classList.remove('is-moving');
    store();
  };
  bar.addEventListener('pointerup', end);
  bar.addEventListener('pointercancel', end);
  // 右下の角（CSS の resize）で大きさを変えたとき
  let timer = 0;
  new ResizeObserver(() => {
    if (box.hidden) return;
    onResize();
    clearTimeout(timer);
    timer = setTimeout(store, 300);
  }).observe(box);
  pc.addEventListener('change', place);
  return place;
}

/* -------------------------------------------------------------------
 * ライブの添付画像
 *   ライブページ: サムネイル（[data-live-img]）を押すと <dialog> で大きく表示。ダウンロードボタン付き。
 *   編集画面: 5枚の上限を数える。追加する枚数が超えるとき・5枚のときは追加ボタンを無効にして知らせる
 * ------------------------------------------------------------------- */
function setupLiveImages() {
  const modal = document.querySelector('[data-live-modal]');
  if (modal) {
    const img = modal.querySelector('[data-live-modal-img]');
    const taken = modal.querySelector('[data-live-modal-taken]');
    const dl = modal.querySelector('[data-live-modal-dl]');
    let clearTimer = 0;
    document.querySelectorAll('[data-live-img]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.liveImg;
        const base = btn.dataset.liveSrc || 'live_image?id='; // 掲示板・お知らせ・バンドは attach_image?k=…&id=
        if (btn.dataset.liveFile) { location.href = `${base}${id}&dl=1`; return; } // TAB 譜の PDF 以外のファイル: ダウンロードだけ
        // ドライブの PDF: スマホは枠の中で1ページ目しか出ない・拡大できないので、新しいタブで開く
        if (btn.dataset.liveTab && matchMedia('(pointer: coarse)').matches) { window.open(`${base}${id}`, '_blank', 'noopener'); return; }
        clearTimeout(clearTimer); // 閉じてすぐ開き直したとき、前の「外す」予約で画像が消えないように
        const pdf = !!btn.dataset.livePdf; // TAB 譜に添付した PDF は iframe でプレビュー
        const frame = modal.querySelector('[data-live-modal-pdf]');
        img.hidden = pdf;
        if (frame) {
          frame.hidden = !pdf;
          if (pdf) frame.src = `${base}${id}`; else frame.removeAttribute('src');
        }
        if (pdf) img.removeAttribute('src'); else img.src = `${base}${id}`;
        dl.href = `${base}${id}&dl=1`;
        taken.textContent = btn.dataset.taken || '';
        modal.showModal();
      });
    });
    modal.querySelector('[data-live-modal-close]').addEventListener('click', () => modal.close());
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
    // 閉じるアニメーション（.modal の transition 0.2秒）が終わってから画像を外す。すぐ外すと、枠が縮む前に画像だけ消える
    modal.addEventListener('close', () => {
      clearTimer = setTimeout(() => { img.removeAttribute('src'); modal.querySelector('[data-live-modal-pdf]')?.removeAttribute('src'); }, 250);
    });
  }

  document.querySelectorAll('[data-live-img-edit]').forEach(setupLiveImageEdit);
}

function setupLiveImageEdit(edit) {
  const max = Number(edit.dataset.max);
  const input = edit.querySelector('[data-live-img-input]');
  const note = edit.querySelector('[data-live-img-note]');
  const addBtn = edit.querySelector('.live-img-edit__add');
  const list = edit.querySelector('.live-img-edit__list');
  // 選んだ画像をためておく入れ物。ファイル選択は選ぶたびに前の選択が消えるので、ここに足していって、input.files に入れ直す
  const picked = new DataTransfer();
  const pending = []; // 追加予定のプレビュー（✕で取り消せる）
  const shown = new WeakSet(); // もう出したファイル。新しく足した分だけアニメーションさせる（作り直しで全部が動かないように）
  const sync = () => {
    input.files = picked.files;
    pending.splice(0).forEach((el) => { URL.revokeObjectURL(el.dataset.url); el.remove(); });
    [...picked.files].forEach((f, i) => {
      const url = URL.createObjectURL(f);
      const el = document.createElement('button');
      el.type = 'button';
      el.className = 'live-img-edit__item live-img-edit__item--new' + (shown.has(f) ? '' : ' is-pop');
      shown.add(f);
      el.dataset.url = url;
      el.setAttribute('aria-label', '追加を取り消し');
      // 画像以外（バンドの PDF・MP3）はアイコン
      const thumb = f.type.startsWith('image/') ? `<img src="${url}" alt="">`
        : `<span class="icon live-img-edit__file" aria-hidden="true">${isAudio(f) ? 'music_note' : 'picture_as_pdf'}</span>`;
      el.innerHTML = `${thumb}<span class="live-img-edit__x"><span class="icon" aria-hidden="true">close</span></span>`;
      el.addEventListener('click', () => {
        const next = new DataTransfer();
        [...picked.files].forEach((g, j) => { if (j !== i) next.items.add(g); });
        picked.items.clear();
        [...next.files].forEach((g) => picked.items.add(g));
        sync();
      });
      list.appendChild(el);
      pending.push(el);
    });
    update();
  };
  // MP3 は別の上限（サーバーが数える）なので、ここでは数えない
  const isAudio = (f) => /\.mp3$/i.test(f.name);
  const room = () => max - edit.querySelectorAll('[data-live-img-remove]:not(:checked)').length - [...picked.files].filter((f) => !isAudio(f)).length;
  function update() {
    const left = room();
    addBtn.classList.toggle('is-disabled', left <= 0);
    note.textContent = '';
  }
  input.addEventListener('change', () => {
    const over = [...input.files].filter((f) => !isAudio(f)).length > room();
    // 今回選んだ分を足す（上限を超える分は入れない）
    for (const f of [...input.files]) { if (isAudio(f) || room() > 0) picked.items.add(f); }
    sync();
    if (over) note.textContent = `${max} 枚までです`;
  });
  // 削除のチェックを外すと空きが減るので数え直す
  edit.querySelectorAll('[data-live-img-remove]').forEach((c) => c.addEventListener('change', update));
  update();
}

/* ---------------------------------------------------------------------
 * 楽器の管理（instruments.php）: 色コードの入力欄
 *   カラーピッカー（<input type="color">）と、色コード（#0f9ba0）を打つ欄を、お互いに写し合う。
 *   送られる（name がある）のはピッカーだけ。ここは「打った色をピッカーに入れる」役目
 *   形が正しいか（#rrggbb か）はサーバー側の is_hex_color() が必ず確かめるので、ここは入力の助けだけ
 * ------------------------------------------------------------------- */
function setupInstrumentHex() {
  // 6 桁の 16 進数（先頭の # は付けても付けなくてもよい。大文字でもよい）
  const HEX = /^#?([0-9a-f]{6})$/i;
  document.querySelectorAll('[data-inst-hex]').forEach((text) => {
    const picker = text.closest('.inst-admin__colorbox')?.querySelector('[data-inst-color]');
    if (!picker) return;

    // 打つたびに: 正しい形ならピッカーに写す（# が無くても、大文字でも、'#' 付きの小文字にそろえる）。違う形なら赤い枠にする
    text.addEventListener('input', () => {
      const m = HEX.exec(text.value.trim());
      text.classList.toggle('is-invalid', !m && text.value.trim() !== '');
      if (m) picker.value = '#' + m[1].toLowerCase();
    });

    // 欄から出たとき: 形が違ったままなら、今のピッカーの色に戻す（変な値のまま保存してしまわないように）。正しければ整える
    text.addEventListener('blur', () => {
      text.value = picker.value;
      text.classList.remove('is-invalid');
    });

    // ピッカーで選んだとき: 色コードの欄にも写す
    picker.addEventListener('input', () => {
      text.value = picker.value;
      text.classList.remove('is-invalid');
    });
  });
}

/**
 * 楽器の色の自前ピッカー（H・S・L のスライダー）。
 *   なぜ自前か: ブラウザ／OS 標準のピッカーの「カスタム」画面は、サイト側から初期値をいじれない。
 *   なので標準の <input type="color"> は「値を持つだけ」の部品にして隠し、押せる色見本ボタンから自前のポップアップを開く。
 *   開いた瞬間に、いま選ばれている色を HSL（色相 H 0〜360 / 彩度 S 0〜100 / 明度 L 0〜100）に直した値が入る。
 *   スライダーを動かすたび、input[type=color] の値を書き換えて input イベントを出す
 *   → 色コード欄・プレビュー・自動保存が、標準ピッカーのときと同じように反応する（それぞれ別の関数がこのイベントを聞いている）。
 *   JS が動かない環境では標準のピッカーがそのまま見えて使える（段階的な機能追加 = プログレッシブエンハンスメント）。
 */
function setupInstrumentColorPicker() {
  const pickers = document.querySelectorAll('[data-inst-color]');
  if (!pickers.length) return;

  // ---- 色の変換（16進 #rrggbb ⇔ HSL）----
  const hexToHsl = (hex) => {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
    const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
    const l = (max + min) / 2;
    const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
    let h = 0;
    if (d !== 0) {
      if (max === r) h = ((g - b) / d) % 6;
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h = (h * 60 + 360) % 360;
    }
    return [Math.round(h), Math.round(s * 100), Math.round(l * 100)];
  };
  const hslToHex = (h, s, l) => {
    s /= 100; l /= 100;
    const a = s * Math.min(l, 1 - l);
    const f = (n) => {
      const k = (n + h / 30) % 12;
      return Math.round(255 * (l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))));
    };
    return '#' + [f(0), f(8), f(4)].map((v) => v.toString(16).padStart(2, '0')).join('');
  };

  // ---- ポップアップ（ページに1つだけ作って使い回す）----
  const AXES = [['h', 'H', 360], ['s', 'S', 100], ['l', 'L', 100]];
  const ranges = {}, numbers = {};
  const swatch = mkEl('div', 'cpick__swatch');
  const code = mkEl('span', 'cpick__code');
  const pop = mkNode('div', { class: 'cpick', role: 'dialog', 'aria-label': '色の調整', hidden: true }, mkNode('div', { class: 'cpick__head' }, swatch, code));
  AXES.forEach(([key, label, max]) => {
    // 同じ値を、スライダー（指で動かす）と数字の欄（打ち込む）の両方で持つ。どちらを変えても反対側に写す
    ranges[key] = mkNode('input', { type: 'range', min: 0, max, step: 1, 'aria-label': label });
    numbers[key] = mkNode('input', { type: 'number', min: 0, max, step: 1, inputmode: 'numeric', 'aria-label': label + 'の値' });
    pop.append(mkNode('label', { class: 'cpick__row' }, mkEl('span', 'cpick__label', label), ranges[key], numbers[key]));
  });
  document.body.append(pop);

  let current = null; // いま開いている {picker, btn}
  let dirty = false;  // 開いてから色を変えたか（閉じるときの change = 即保存を出すか決める）
  const val = (key) => Math.min(Number(ranges[key].max), Math.max(0, Math.round(Number(ranges[key].value) || 0)));

  /** スライダーの背景（色の帯）を、いまの値に合わせて描き直す。見た目のためだけ */
  const paintTracks = () => {
    const [h, s, l] = [val('h'), val('s'), val('l')];
    ranges.h.style.setProperty('--track', 'linear-gradient(to right,' + [0, 60, 120, 180, 240, 300, 360].map((d) => `hsl(${d} ${s}% ${l}%)`).join(',') + ')');
    ranges.s.style.setProperty('--track', `linear-gradient(to right, hsl(${h} 0% ${l}%), hsl(${h} 100% ${l}%))`);
    ranges.l.style.setProperty('--track', `linear-gradient(to right, #000, hsl(${h} ${s}% 50%), #fff)`);
    const hex = hslToHex(h, s, l);
    swatch.style.background = hex;
    code.textContent = hex;
  };

  const sync = (picker, btn) => { btn.style.background = picker.value; };

  /** スライダー・数字の変更 → 色を計算して input[type=color] に入れる */
  const apply = () => {
    if (!current) return;
    const hex = hslToHex(val('h'), val('s'), val('l'));
    paintTracks();
    current.picker.value = hex;
    sync(current.picker, current.btn);
    current.picker.dispatchEvent(new Event('input', { bubbles: true }));
    dirty = true;
  };
  AXES.forEach(([key]) => {
    ranges[key].addEventListener('input', () => { numbers[key].value = ranges[key].value; apply(); });
    numbers[key].addEventListener('input', () => {
      if (numbers[key].value === '') return; // 打ち直し中（空）は何もしない
      ranges[key].value = numbers[key].value;
      numbers[key].value = ranges[key].value; // 範囲外（400 など）は範囲内に丸めた値に直す
      apply();
    });
  });

  const close = () => {
    if (!current) return;
    const { picker } = current;
    current = null;
    pop.hidden = true;
    if (dirty) picker.dispatchEvent(new Event('change', { bubbles: true })); // 閉じたら待たずに保存（自動保存は change を即保存として扱う）
    dirty = false;
  };

  const open = (picker, btn) => {
    current = { picker, btn };
    dirty = false;
    // ここが肝: いま選ばれている色を HSL にして、スライダーと数字の初期値にする
    const [h, s, l] = hexToHsl(picker.value);
    ranges.h.value = numbers.h.value = h;
    ranges.s.value = numbers.s.value = s;
    ranges.l.value = numbers.l.value = l;
    paintTracks();
    pop.hidden = false;
    // ボタンの真下に置く。右にはみ出すときは画面内へ寄せる（スマホ対策）
    const r = btn.getBoundingClientRect();
    const left = Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8));
    pop.style.left = `${left + window.scrollX}px`;
    pop.style.top = `${r.bottom + window.scrollY + 6}px`;
  };

  pickers.forEach((picker) => {
    const btn = mkNode('button', { type: 'button', class: 'cpick-btn', 'aria-label': picker.getAttribute('aria-label') || '色' });
    sync(picker, btn);
    picker.hidden = true; // 標準のピッカーは値を持つだけにして隠す（フォーム送信の値としては今まで通り使われる）
    picker.after(btn);
    btn.addEventListener('click', () => (current && current.btn === btn ? close() : (close(), open(picker, btn))));
    // 色コード欄に打った色・ほかの経路で変わった色を、見本ボタンにも写す
    picker.addEventListener('input', () => sync(picker, btn));
    picker.closest('.inst-admin__colorbox')?.querySelector('[data-inst-hex]')?.addEventListener('input', () => sync(picker, btn));
  });

  // 外を押す・Esc・スクロール（表が横にスクロールするとボタンの位置がずれるため）で閉じる
  document.addEventListener('pointerdown', (e) => {
    if (current && !pop.contains(e.target) && !current.btn.contains(e.target)) close();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  window.addEventListener('scroll', (e) => { if (current && !pop.contains(e.target)) close(); }, true);
}

/**
 * 楽器の管理（instruments.php）の「表示」欄: 実際のラベルを、色・略称を変えた瞬間にその場で更新する。
 *   色: ピッカー（input.inst-admin__color）か色コード欄を変えると、プレビューの --pv（CSS 変数）に色を入れ直す
 *   略称: 略称の入力欄の文字を、プレビューのラベルの文字に写す
 *   ※ 見た目の計算（ダークでの明るさ調整）は CSS 側。JS は値を入れるだけ（保存はこれまでどおり「保存」ボタン）
 */
function setupInstrumentPreview() {
  document.querySelectorAll('[data-inst-preview]').forEach((preview) => {
    const row = preview.closest('tr');
    const picker = row?.querySelector('[data-inst-color]');
    const hex = row?.querySelector('[data-inst-hex]');
    const short = row?.querySelector('input[name="short_name"]');
    if (!picker) return;
    const color = () => preview.style.setProperty('--pv', picker.value); // 色コード欄の入力は setupInstrumentHex がピッカーに写すので、ピッカーの値を見ればよい
    picker.addEventListener('input', color);
    hex?.addEventListener('input', color); // setupInstrumentHex の input より後に登録しているので、ピッカーは更新済み
    short?.addEventListener('input', () => {
      preview.querySelectorAll('[data-inst-preview-label]').forEach((el) => { el.textContent = short.value.trim() || '—'; });
    });
  });
}

/* 名義の入力欄: 匿名にチェックが入っているあいだは入力できない。送信した名義は次回の初期値になる（このブラウザだけ） */
function setupAliasField() {
  const KEY = 'abbey.alias';
  document.querySelectorAll('[data-alias-field]').forEach((box) => {
    const name = box.querySelector('input[type="text"]');
    const anon = box.querySelector('input[type="checkbox"]');
    try { name.value = localStorage.getItem(KEY) ?? ''; } catch (e) { /* 保存が使えなくても入力はできる */ }
    box.closest('form')?.addEventListener('submit', () => {
      if (anon.checked) { return; } // 匿名のときは名義を送らないので、前回の名義を残す
      try { localStorage.setItem(KEY, name.value.trim()); } catch (e) { /* 同上 */ }
    });
    const sync = () => { name.disabled = anon.checked; };
    anon.addEventListener('change', sync);
    sync();
    // 説明: ⓘ を押すと開閉。外を押しても閉じる
    const btn = box.querySelector('[data-alias-info]');
    const pop = box.querySelector('.alias-field__pop');
    const toggle = (open) => { pop.hidden = !open; btn.setAttribute('aria-expanded', String(open)); };
    btn.addEventListener('click', (e) => { e.stopPropagation(); toggle(pop.hidden); });
    btn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(pop.hidden); } });
    document.addEventListener('click', () => toggle(false));
  });
}

/* 楽器ラベル（.part）のポップアップは、共通の data-tip（setupTip）に統一した。
   以前ここにあった専用のポップアップ（.part-tip）は二重に出るので削除 */

/**
 * 解析の状況（setlist_jobs.php?tab=chapter）の「MP3 を紐付け」。ドライブのフォルダの「動画ID.mp3」をバンドに紐付け（api_yt_drive.php）、
 * バンドにもスペシャルコンテンツにも無い動画を、開け閉めできる欄に動画のタイトルで並べる（タイトルが無ければファイル名）
 */
function setupYtDrive() {
  const form = document.querySelector('[data-yt-drive]');
  if (!form) return;
  const miss = form.querySelector('[data-yt-drive-miss]');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button');
    btn.disabled = true;
    try {
      const { count, unmatched } = await apiPost('api_yt_drive', { url: form.url.value }, '紐付けられませんでした');
      flashToast(`${count}本を紐付けました`);
      miss.querySelector('summary').textContent = `紐付けなかった動画 ${unmatched.length}本`;
      miss.querySelector('ul').replaceChildren(...unmatched.map(({ name, video_id: vid, title }) => {
        const li = document.createElement('li');
        li.append(vid ? Object.assign(document.createElement('a'), { href: `https://www.youtube.com/watch?v=${vid}`, target: '_blank', rel: 'noopener noreferrer', textContent: title || name }) : name);
        return li;
      }));
      miss.hidden = !unmatched.length;
    } catch (err) {
      flashToast(err.message, 'error');
    }
    btn.disabled = false;
  });
}

/**
 * 解析の状況（setlist_jobs.php?tab=chapter）の「未解析を解析」。api_chapter_bulk.php に頼んで、画面は移らずに状態だけ「順番待ち」にする
 */
function setupChapterBulk() {
  document.querySelectorAll('[data-chapter-bulk]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const live = btn.closest('[data-chapter-live]');
      btn.disabled = true;
      try {
        const { count } = await apiPost('api_chapter_bulk', { live_id: Number(live.dataset.chapterLive) }, '解析を始められませんでした');
        live.querySelectorAll('[data-chapter-state="none"], [data-chapter-state="error"]').forEach((s) => {
          s.dataset.chapterState = 'queued';
          s.textContent = '順番待ち';
          s.removeAttribute('data-tip');
          s.style.cursor = '';
        });
        flashToast(count ? `${count}本を解析します` : '未解析の動画はありません');
        btn.remove();
      } catch (e) {
        flashToast(e.message, 'error');
        btn.disabled = false;
      }
    });
  });
}
