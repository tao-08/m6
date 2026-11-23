/*
 * app_import.js — 取り込み・ライブ編集（会場の候補・プレビュー・日程・統合）
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * 会場の「もしかして」（取り込み・ライブ編集の「＋ 新しい会場を作る」）
 *   新しい会場名の欄に入っている名前と、先頭が2文字以上同じ登録済みの会場があれば、
 *   欄の真下に「もしかして ◯◯？」のポップアップを出す（モーダルではないので、ほかの入力はそのまま続けられる）。
 *   ◯◯ を押すとプルダウンでその会場を選び（新しい会場の欄は閉じる）、× で閉じる。
 *   候補は同じ欄のプルダウン（<select data-venue-select>）の選択肢から探すので、サーバーには聞かない。
 *   比べるときは全角/半角・大文字/小文字・空白をそろえる（PHP の band_key とだいたい同じ）
 * ------------------------------------------------------------------- */
function setupVenueSuggest() {
  const boxes = [...document.querySelectorAll('[data-venue-new]')];
  if (!boxes.length) return;
  const norm = (s) => s.normalize('NFKC').toLowerCase().replace(/\s+/g, '');
  const prefixLen = (a, b) => {
    const x = [...a];
    const y = [...b]; // [...文字列] で1文字ずつ（絵文字などの2つで1文字も正しく数える）
    let n = 0;
    while (n < x.length && n < y.length && x[n] === y[n]) n++;
    return n;
  };
  const pops = new Map();   // 入力欄 → 出しているポップアップ
  const closed = new Map(); // 入力欄 → × で閉じたときの名前（同じ名前のあいだは出し直さない）

  // 先頭が2文字以上同じ会場を、同じ文字数が多い順に最大3つ（完全に同じ名前は候補にしない）
  const candidates = (box, select) => {
    const key = norm(box.value);
    if ([...key].length < 2) return [];
    return [...select.options]
      .filter((o) => o.value !== '' && o.value !== 'new')
      .map((o) => ({ option: o, len: prefixLen(key, norm(o.textContent)) }))
      .filter((c) => c.len >= 2 && norm(c.option.textContent) !== key)
      .sort((a, b) => b.len - a.len)
      .slice(0, 3)
      .map((c) => c.option);
  };

  const place = (box, pop) => {
    const r = box.getBoundingClientRect();
    const left = Math.min(r.left, document.documentElement.clientWidth - pop.offsetWidth - 8); // 右端からはみ出さない
    pop.style.top = `${r.bottom + window.scrollY + 4}px`;
    pop.style.left = `${Math.max(8, left) + window.scrollX}px`;
  };

  const update = (box) => {
    pops.get(box)?.remove();
    pops.delete(box);
    const select = box.closest('.field').querySelector('[data-venue-select]');
    if (!select || box.hidden || select.value !== 'new' || closed.get(box) === box.value) return;
    const options = candidates(box, select);
    if (!options.length) return;

    const pop = document.createElement('div');
    pop.className = 'venue-pop';
    pop.setAttribute('role', 'status');
    pop.append('もしかして ');
    options.forEach((o, i) => {
      if (i > 0) pop.append(' / ');
      // 会場名は textContent で入れる（innerHTML だと、名前に < > があったとき XSS になる）
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'hint-pop__pick';
      pick.textContent = o.textContent;
      pick.addEventListener('click', () => {
        select.value = o.value;
        select.dispatchEvent(new Event('change', { bubbles: true })); // 新しい会場の欄を閉じ、プルダウンのボタンの文字も合わせる
        update(box);
      });
      pop.append(pick);
    });
    pop.append('？');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'venue-pop__close';
    close.setAttribute('aria-label', '閉じる');
    close.textContent = '×';
    close.addEventListener('click', () => {
      closed.set(box, box.value);
      update(box);
    });
    pop.append(close);
    document.body.appendChild(pop);
    place(box, pop);
    pops.set(box, pop);
  };

  boxes.forEach(update); // プレビューを開いた直後（ファイルの会場名が新しいと判定されたとき）
  document.addEventListener('input', (e) => { if (boxes.includes(e.target)) update(e.target); });
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-venue-select]')) return;
    const box = e.target.closest('.field').querySelector('[data-venue-new]');
    if (box) update(box);
  });
  // ページや表のスクロール・画面の大きさが変わったら位置を合わせ直す。
  // 「この日程は取り込まない」などで欄が見えなくなった（幅が0）ときは隠す
  const replace = () => pops.forEach((pop, box) => {
    pop.hidden = box.offsetParent === null;
    if (!pop.hidden) place(box, pop);
  });
  window.addEventListener('scroll', replace, true);
  window.addEventListener('resize', replace);
}

/* ---------------------------------------------------------------------
 * 取り込みプレビュー
 *   - 「取込」のチェックを外した行を薄くする
 *   - 「名簿」検索欄の値が名簿のバンドと一致したら緑、空/不一致なら赤
 *   - 名簿側の「登録」チェック（外すと薄く。タイムテーブルの「取込」を外すと連動して外れる）
 *     どの枠にも選ばれていない名簿のバンドに「タイムテーブルにないため登録されません」を出す
 *   - 「名簿なし ◯件」バッジを更新
 *   - 開催日 → 年度の表示、会場「新規作成」で会場名の入力欄を出す
 * ------------------------------------------------------------------- */
function setupImportPreview() {
  const slotRows = [...document.querySelectorAll('tr[data-slot]')];
  if (!slotRows.length) return;

  // 検索欄の文字 → 'ri:bi'（<datalist id="dl-roster"> の option から作る）
  const rosterKeys = {};
  document.querySelectorAll('#dl-roster option').forEach((opt) => { rosterKeys[opt.value] = opt.dataset.key; });

  const submit = document.querySelector('[data-submit]');
  const submitBlock = document.querySelector('[data-submit-block]');
  const submitDisabled = submit.disabled; // PHP が最初から押せなくしていた（タイムテーブルが無い）なら、ずっとそのまま

  const refresh = () => {
    const usedBy = {}; // 'ri:bi' → [出演バンド名...]
    // 本当に登録される枠か（「取込」にチェック & 「この日程は取り込まない」ではない）。PHP の重複チェックと同じ条件
    const counted = (tr) => tr.querySelector('[data-include]').checked
      && !tr.closest('[data-timetable]').querySelector('[data-skip]').checked;

    // 1周目: どの名簿をどの枠が使っているか集める
    slotRows.forEach((tr) => {
      tr.classList.toggle('is-excluded', !tr.querySelector('[data-include]').checked);
      const key = rosterKeys[tr.querySelector('[data-roster-input]').value.trim()];
      if (key && counted(tr)) {
        (usedBy[key] ||= []).push(tr.querySelector('[data-band-name]').value);
      }
    });

    // 2周目: 色を付ける。緑 = 名簿あり / 黄 = 同じ名簿を他の枠でも選んでいる / 赤 = 名簿なし
    let dupCount = 0;
    slotRows.forEach((tr) => {
      const include = tr.querySelector('[data-include]').checked;
      const input = tr.querySelector('[data-roster-input]');
      const key = rosterKeys[input.value.trim()];
      const dup = !!key && counted(tr) && usedBy[key].length > 1;
      if (dup) dupCount++;
      input.classList.toggle('is-ok', !!key && !dup);
      input.classList.toggle('is-similar', dup);
      input.classList.toggle('is-new', !key && include);
      input.title = dup ? `同じ名簿を ${usedBy[key].length} つの枠で選んでいます: ${usedBy[key].join(' / ')}` : '';
    });

    // 名簿側: どの枠にも選ばれていない（= 登録されない）行を薄くして ⚠ を出す
    document.querySelectorAll('tr[data-roster-key]').forEach((tr) => {
      const unused = !usedBy[tr.dataset.rosterKey];
      tr.classList.toggle('is-excluded', unused);
      tr.querySelector('[data-roster-missing]').hidden = !unused;
    });

    // 日程ごとの「名簿なし ◯件」バッジ
    document.querySelectorAll('[data-timetable]').forEach((card) => {
      const rows = [...card.querySelectorAll('tr[data-slot]')].filter((tr) => tr.querySelector('[data-include]').checked);
      const missing = rows.filter((tr) => !rosterKeys[tr.querySelector('[data-roster-input]').value.trim()]).length;
      const dups = rows.filter((tr) => tr.querySelector('[data-roster-input]').classList.contains('is-similar')).length;
      const badge = card.querySelector('[data-unmatched-badge]');
      const msgs = [missing && `名簿なし ${missing}`, dups && `名簿重複 ${dups}`].filter(Boolean);
      badge.textContent = msgs.length ? msgs.join(' · ') : '全バンド名簿あり';
      badge.className = 'pill ' + (msgs.length ? 'pill--warn' : 'pill--ok');
    });

    // 名簿の重複が1つでもあれば「登録する」を押せなくする（同じバンドが2回出ることは無いので入力ミス）
    // 「登録済みのライブと統合」が ON なのにライブを選んでいない日程（取り込まない日程は数えない）
    const unpicked = [...document.querySelectorAll('[data-timetable]')].filter((card) =>
      !card.querySelector('[data-skip]')?.checked
      && card.querySelector('[data-merge-toggle]')?.getAttribute('aria-pressed') === 'true'
      && !card.querySelector('[data-live-id]').value).length;

    const blocks = [];
    if (dupCount > 0) blocks.push('名簿の重複を直す');
    if (unpicked > 0) blocks.push('統合するライブを選ぶ');
    submit.disabled = submitDisabled || blocks.length > 0;
    submitBlock.hidden = blocks.length === 0;
    submitBlock.querySelector('[data-submit-block-text]').textContent = `${blocks.join('・')}と登録できます`;
  };

  const showFiscalYear = (input) => {
    const out = input.closest('.field').querySelector('[data-fiscal-year]');
    const year = fiscalYear(input.value);
    out.textContent = year !== null ? `→ ${year}年度` : '';
  };

  // 開催日の「年」を1つ直したら、ほかの日程も同じ年にそろえる（月・日はそのまま）
  //   年はファイルに無く推測で入れているので、ずれるときは全部の日程が同じ年でずれている。
  //   ※ 1回のライブの日程が年をまたぐことは無い前提（12/31 と 1/1 のような日程は無い）
  const yearOf = (value) => (/^\d{4}-/.test(value) ? parseInt(value, 10) : null);
  let syncing = false; // 下で他の欄に change を出す → またここに来る、の繰り返しを防ぐ
  const syncYears = (input) => {
    const year = yearOf(input.value);
    // 年を1桁ずつ打っている途中（0002 → 0020 → 0202）は、ほかの欄を変な年にしない
    if (syncing || year === null || year < 1990) return;
    syncing = true;
    document.querySelectorAll('[data-date-input]').forEach((other) => {
      if (other === input || yearOf(other.value) === null || yearOf(other.value) === year) return;
      other.value = String(year) + other.value.slice(4);
      other.dispatchEvent(new Event('change', { bubbles: true })); // 年度の表示・統合の候補なども更新させる
      other.classList.remove('is-synced');
      void other.offsetWidth; // アニメーションを最初からやり直す
      other.classList.add('is-synced');
    });
    syncing = false;
  };

  // 「この日程は取り込まない」: そのカードの入力欄を触れなくする（そのチェックボックス自身は除く）
  //   disabled だと値が送信されず、登録失敗で戻ってきたときに「取込」などの状態が消えてしまう。
  //   inert は「クリック・入力・フォーカスができない」だけで値はそのまま送られるので、状態を保ったまま固められる
  const applySkip = (card) => {
    const skip = card.querySelector('[data-skip]');
    const on = skip.checked;
    card.classList.toggle('is-skipped', on);
    [...card.children].forEach((el) => {
      if (el.matches('.import-day__head')) return; // 見出し（ファイル名）は読めるようにそのまま
      if (el.contains(skip)) {
        // チェックボックスの並び: 「取り込まない」以外（上書き）だけ固める
        [...el.children].forEach((c) => { c.inert = on && !c.contains(skip); });
      } else {
        el.inert = on;
      }
    });
  };
  document.querySelectorAll('[data-timetable]').forEach(applySkip); // 失敗して戻ってきたときにチェック済みのことがある

  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-include]')) {
      renumberSlots(e.target.closest('tbody')); // 取込を切り替えたら出演順を振り直す
    }
    if (e.target.matches('[data-skip]')) applySkip(e.target.closest('[data-timetable]'));
    if (e.target.matches('[data-include], [data-roster-input], [data-skip]')) refresh();
    if (e.target.matches('[data-date-input]')) {
      showFiscalYear(e.target);
      syncYears(e.target);
    }
  });
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-roster-input], [data-band-name]')) refresh();
  });
  // 統合トグルの切り替え・ライブの選択（setupMergeToggle が知らせてくる）
  document.addEventListener('merge-change', refresh);

  refresh();
  // 最初に開いたときの注意（PHP が初回だけ <dialog> を置く）を1つずつ順番に出す
  //   1. 年を確認してください（年はファイルに無いので推測で入れている）→ 閉じたら開催日の欄へ
  //   2. タイムテーブルに無い名簿のバンド
  const notices = ['[data-year-check-dialog]', '[data-roster-missing-dialog]']
    .map((sel) => document.querySelector(sel)).filter(Boolean);
  const showNext = () => {
    const dialog = notices.shift();
    if (!dialog) return;
    dialog.addEventListener('close', () => {
      if (dialog.matches('[data-year-check-dialog]')) {
        document.querySelector('[data-date-input]')?.focus({ preventScroll: true });
        document.querySelector('[data-date-input]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      showNext();
    }, { once: true });
    dialog.showModal();
  };
  showNext();
}

/* ---------------------------------------------------------------------
 * ライブ編集: 日程名の重複チェックと「＋ 日程を追加」
 *
 *   ・同じライブの日程どうしで日程名がかぶったら「重複しています」を出し、保存できないようにする
 *     （選べなくはしない。「1日目 ⇄ 2日目」の入れ替えで、途中で一時的に重複するのは許す）
 *     setCustomValidity を付けると、ブラウザが「保存する」を押したときに止めて吹き出しを出してくれる。
 *     PHP 側（live_edit.php）でも同じチェックをしている
 *   ・「＋ 日程を追加」にチェックしたら、追加する日程の入力欄を開く
 *     閉じているあいだは日付の required も外す（見えない欄が「入力してください」で送信を止めないように）
 * ------------------------------------------------------------------- */
/** 日程名のプルダウンが今表している日程名（「＋ 新しい日程名を作る」なら横の入力欄の文字） */
function dayLabelOf(select) {
  if (select.value !== '__new__') return select.value;
  return select.closest('.field').querySelector('[data-label-new]').value.trim();
}

function setupNewDayToggle() {
  const checkDuplicates = (form) => {
    const addBox = form.querySelector('[data-new-day-add]');
    // 追加する日程は、チェックが入っているとき（新規ライブなら常に）だけ数える
    const selects = [...form.querySelectorAll('[data-day-label]')]
      .filter((s) => !s.matches('[data-new-day]') || !addBox || addBox.checked);
    form.querySelectorAll('[data-day-label]').forEach((select) => {
      const label = dayLabelOf(select);
      const dup = label !== '' && selects.includes(select) && selects.some((o) => o !== select && dayLabelOf(o) === label);
      select.setCustomValidity(dup ? '他の日程と重複しています' : '');
      const note = select.closest('.field').querySelector('[data-dup-note]');
      if (note) note.hidden = !dup;
    });
  };

  document.querySelectorAll('[data-day-label]').forEach((s) => s.form && checkDuplicates(s.form));
  // 新しい日程名を打つたびにも重複を見直す
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-label-new]')) checkDuplicates(e.target.form);
  });
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-day-label]')) checkDuplicates(e.target.form);
    if (!e.target.matches('[data-new-day-add]')) return;
    const fields = e.target.closest('form').querySelector('[data-new-day-fields]');
    const on = e.target.checked;
    fields.hidden = !on;
    fields.querySelector('input[type="date"]').required = on;
    checkDuplicates(e.target.form);
    if (on) fields.querySelector('.live-pick__btn, select')?.focus();
  });
}

/* ---------------------------------------------------------------------
 * ライブの新規追加（live_edit.php）: 「＋ 日程を追加」で2つ目以降の日程の入力欄を増やす
 *   <template id="extra-day-tpl"> を複製し、name の __i__ を番号に置き換える。
 *   日程名はまだ使っていないものを最初から選んでおく。全部使っていたら「＋ 新しい日程名を作る」にしておく。
 *   「この日程をやめる」で欄ごと消す（消した欄は送信されない）
 * ------------------------------------------------------------------- */
/* ---------------------------------------------------------------------
 * ライブの編集（live_edit.php）: どれかの日付の「年」を変えたら、ほかの日程の日付も同じ年にする
 *   月日はそのまま。年が変わったときだけ動く（月日だけ変えたときは、ほかの日程に触らない）。
 *   年を打っている途中（0002 → 0020 → 0202 → 2025）は動かさないよう、1990〜2100 年のときだけ。
 *   2/29 → うるう年でない年なら 2/28 にする。空の日付欄は触らない
 * ------------------------------------------------------------------- */
function setupSameYear() {
  const form = document.querySelector('[data-same-year]');
  if (!form) return;
  const prev = new WeakMap(); // 日付欄 => 変える前の値
  const yearOf = (v) => (/^\d{4}-\d{2}-\d{2}$/.test(v) ? Number(v.slice(0, 4)) : null);
  const isLeap = (y) => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
  form.addEventListener('change', (e) => {
    const input = e.target;
    if (!input.matches('input[type="date"]')) return;
    const before = prev.has(input) ? prev.get(input) : input.defaultValue;
    prev.set(input, input.value);
    const year = yearOf(input.value);
    if (year === null || year < 1990 || year > 2100 || year === yearOf(before)) return;
    form.querySelectorAll('input[type="date"]').forEach((other) => {
      if (other === input || yearOf(other.value) === null) return;
      let md = other.value.slice(5); // "MM-DD"
      if (md === '02-29' && !isLeap(year)) md = '02-28';
      other.value = `${year}-${md}`;
      prev.set(other, other.value);
    });
  });
}

function setupExtraDays() {
  const btn = document.querySelector('[data-extra-day-add]');
  const tpl = document.getElementById('extra-day-tpl');
  if (!btn || !tpl) return;
  const form = btn.closest('form');
  const list = form.querySelector('[data-extra-days]');
  let next = list.children.length; // エラーで戻ってきたときは、出し直した分の続きの番号から
  const selects = () => [...form.querySelectorAll('[data-day-label]')];

  const refresh = () => {
    // change を起こして、setupNewDayToggle の重複チェックをやり直させる
    selects()[0].dispatchEvent(new Event('change', { bubbles: true }));
  };

  btn.addEventListener('click', () => {
    const used = selects().map(dayLabelOf);
    const block = tpl.content.firstElementChild.cloneNode(true);
    block.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace('__i__', next); });
    next++;
    const select = block.querySelector('[data-day-label]');
    const free = [...select.options].find((o) => o.value !== '__new__' && !used.includes(o.value));
    select.value = free ? free.value : '__new__';
    block.querySelector('[data-label-new]').hidden = !!free;
    list.append(block);
    refresh();
    block.querySelector(free ? 'input[type="date"]' : '[data-label-new]').focus();
  });

  list.addEventListener('click', (e) => {
    const rm = e.target.closest('[data-extra-day-remove]');
    if (!rm) return;
    rm.closest('[data-extra-day]').remove();
    refresh();
  });
  refresh();
}

/** 開催日 "2025-03-15" → 年度（4月始まり。1〜3月は前の年の年度）。PHP の academic_year() と同じ計算 */
function fiscalYear(dateStr) {
  const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(dateStr || '');
  if (!m) return null;
  return Number(m[2]) >= 4 ? Number(m[1]) : Number(m[1]) - 1;
}

/* ---------------------------------------------------------------------
 * 取り込み / ライブ編集: 「登録済みのライブと統合」「他のライブに統合」トグル
 *
 *   ON  … ライブ名の入力欄（と [data-merge-hide] の欄）を隠して disabled（送信されない）にし、代わりに「ライブを選択 ▾」を出す。
 *          押すと <template id="live-picker">（partials/live_picker.php）を複製したポップアップが開き、
 *          開催日（ライブ編集では今の年度）の年度の見出しまでスクロールした状態で表示される。選ぶと hidden の live_id に入る。
 *   OFF … 元の入力欄に戻す（打ってあった文字はそのまま）。merge / live_id は disabled にして送らない。
 *
 *   ライブ編集（[data-live-merge]）では、統合先にもうある日程名を選んでいる日程に
 *   「統合先にもある日程です」を出し、保存前に確認ダイアログ（data-confirm）を出す。
 *   両方にバンドがいる日程は、1回目の保存でバンドごとの選択欄が出る（live_edit.php）。
 * ------------------------------------------------------------------- */
function setupMergeToggle() {
  const tpl = document.getElementById('live-picker');
  if (!tpl) return;
  let openPop = null; // 今開いているポップアップ（同時に開くのは1つだけ）

  const closePop = () => {
    if (!openPop) return;
    openPop.closest('[data-merge]').querySelector('[data-live-pick]').setAttribute('aria-expanded', 'false');
    openPop.remove();
    openPop = null;
  };

  // 登録済みのライブの一覧は、ポップアップの <template> の項目をそのまま使う（同じデータを二重に埋め込まない）
  const lives = [...tpl.content.querySelectorAll('[data-live-option]')].map((o) => ({
    year: o.dataset.year,
    key: o.dataset.name.trim().toLowerCase(), // DB の照合順序（_general_ci）は大文字・小文字を区別しないので合わせる
    labels: o.dataset.labels ? o.dataset.labels.split('・') : [],
  }));

  // 注意文: 選んだライブにもう同じ日程がある / 年度が開催日とずれている
  // あわせて、カード上部の「この日程は登録済みです」の帯も出し入れする
  // ライブ編集: 統合先にもうある日程名の日程に注意を出す + 保存前の確認文を付け外し
  const updateLiveEdit = (box) => {
    const form = box.closest('form');
    const btn = box.querySelector('[data-live-pick]');
    const note = box.querySelector('[data-merge-note]');
    const on = box.querySelector('[data-merge-toggle]').getAttribute('aria-pressed') === 'true';
    const chosen = on && !!box.querySelector('[data-live-id]').value;
    const labels = chosen && btn.dataset.labels ? btn.dataset.labels.split('・') : [];
    const addNew = form.querySelector('[data-new-day-add]')?.checked;
    let overwrites = 0;
    form.querySelectorAll('[data-day-label]').forEach((select) => {
      const warn = select.closest('.field').querySelector('[data-overwrite-note]');
      const hit = labels.includes(dayLabelOf(select)) && (!select.matches('[data-new-day]') || addNew);
      warn.hidden = !hit;
      if (hit) overwrites++;
    });
    note.textContent = chosen ? 'このライブの日程を全部、選んだライブへ移します（このライブは消えます）' : '';
    if (chosen) {
      form.dataset.confirm = `このライブを「${btn.dataset.year}年度 ${btn.dataset.name}」に統合します。`
        + (overwrites ? `\n統合先にもある日程が ${overwrites} 件あります（両方にバンドがいれば、次の画面でバンドごとに残す・統合・捨てるを選べます）。` : '')
        + '\n元に戻せません。よろしいですか？';
    } else {
      delete form.dataset.confirm;
    }
  };

  const updateNote = (box) => {
    const card = box.closest('[data-timetable]');
    if (!card) {
      updateLiveEdit(box);
      return;
    }
    const note = box.querySelector('[data-merge-note]');
    const btn = box.querySelector('[data-live-pick]');
    const flash = card.querySelector('[data-exists-flash]');
    const on = box.querySelector('[data-merge-toggle]').getAttribute('aria-pressed') === 'true';
    const label = dayLabelOf(card.querySelector('[name$="[label]"]'));
    const year = fiscalYear(card.querySelector('[data-date-input]').value);
    note.textContent = '';
    note.className = 'merge-note';

    if (!on) {
      // 手入力: 「開催日の年度 + ライブ名」が同じライブに、同じ日程ラベルがあるか（PHP 側の判定と同じ）
      const key = box.querySelector('[data-live-name]').value.trim().toLowerCase();
      const hit = lives.find((l) => l.year === String(year) && l.key === key);
      flash.hidden = !(hit && hit.labels.includes(label));
      return;
    }
    if (!box.querySelector('[data-live-id]').value) {
      flash.hidden = true;
      return;
    }
    const msgs = [];
    const exists = !!btn.dataset.labels && btn.dataset.labels.split('・').includes(label);
    flash.hidden = !exists;
    if (exists) {
      msgs.push(`⚠ 「${label}」は登録済みです（「上書き」にチェックすると置き換え）`);
    }
    if (year !== null && String(year) !== btn.dataset.year) {
      msgs.push(`⚠ 開催日は${year}年度ですが、${btn.dataset.year}年度のライブに統合します`);
    }
    if (msgs.length) {
      note.textContent = msgs.join('\n');
      note.classList.add('merge-note--warn');
    } else {
      note.textContent = `「${label}」として追加します`;
    }
  };

  // 統合先に同じ日程名があり、開催日が登録済みなら、開催日の欄にその日付を入れる（取り込み画面のみ）
  const fillDate = (box) => {
    const card = box.closest('[data-timetable]');
    if (!card || box.querySelector('[data-merge-toggle]').getAttribute('aria-pressed') !== 'true') return;
    const btn = box.querySelector('[data-live-pick]');
    if (!box.querySelector('[data-live-id]').value || !btn.dataset.dates) return;
    let dates = {};
    try { dates = JSON.parse(btn.dataset.dates); } catch (err) { return; }
    const date = dates[dayLabelOf(card.querySelector('[name$="[label]"]'))];
    const input = card.querySelector('[data-date-input]');
    if (!date || input.value === date) return;
    input.value = date;
    input.dispatchEvent(new Event('input', { bubbles: true })); // 年度の表示などを更新させる
  };

  const setMode = (box, on) => {
    box.querySelector('[data-merge-toggle]').setAttribute('aria-pressed', on ? 'true' : 'false');
    const name = box.querySelector('[data-live-name]');
    name.hidden = on;
    name.disabled = on;
    box.querySelector('[data-merge-flag]').disabled = !on;
    box.querySelector('[data-live-id]').disabled = !on;
    box.querySelector('[data-live-pick-wrap]').hidden = !on;
    // ライブ編集の年度の欄など、統合するときは使わない欄
    box.parentElement.querySelectorAll('[data-merge-hide]').forEach((el) => {
      el.hidden = on;
      el.querySelectorAll('input, select').forEach((i) => { i.disabled = on; });
    });
    if (!on) {
      closePop();
      name.focus();
    }
    fillDate(box);
    updateNote(box);
    document.dispatchEvent(new Event('merge-change')); // 「登録する」を押せるかどうかを判定し直してもらう
  };

  const open = (box) => {
    closePop();
    const btn = box.querySelector('[data-live-pick]');
    const pop = tpl.content.firstElementChild.cloneNode(true);
    btn.after(pop);
    btn.setAttribute('aria-expanded', 'true');
    openPop = pop;

    const current = box.querySelector('[data-live-id]').value;
    pop.querySelector(`[data-live-option="${current}"]`)?.setAttribute('aria-selected', 'true');

    // 開催日の年度の見出しまでスクロール。無ければそれより前で一番近い年度（リストは新しい年度が上）
    const dateInput = box.closest('[data-timetable]')?.querySelector('[data-date-input]');
    const year = dateInput ? fiscalYear(dateInput.value) : (Number(box.dataset.year) || null); // ライブ編集は今のライブの年度
    if (year !== null) {
      const head = [...pop.querySelectorAll('[data-year-head]')].find((h) => Number(h.dataset.yearHead) <= year);
      if (head) pop.scrollTop = head.offsetTop; // .live-pop は position: absolute なので、offsetTop はポップアップの上端からの距離
    }
  };

  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-merge-toggle]');
    if (toggle) {
      const box = toggle.closest('[data-merge]');
      const on = toggle.getAttribute('aria-pressed') !== 'true';
      setMode(box, on);
      if (on && !box.querySelector('[data-live-id]').value) open(box); // まだ選んでいなければすぐ開く
      return;
    }
    const pick = e.target.closest('[data-live-pick]');
    if (pick) {
      const box = pick.closest('[data-merge]');
      if (openPop && box.contains(openPop)) closePop(); else open(box);
      return;
    }
    const opt = e.target.closest('[data-live-option]');
    if (opt) {
      const box = opt.closest('[data-merge]');
      const btn = box.querySelector('[data-live-pick]');
      box.querySelector('[data-live-id]').value = opt.dataset.liveOption;
      btn.dataset.year = opt.dataset.year;
      btn.dataset.labels = opt.dataset.labels;
      btn.dataset.name = opt.dataset.name;
      btn.dataset.dates = opt.dataset.dates;
      fillDate(box);
      const text = box.querySelector('[data-live-pick-text]');
      text.textContent = `${opt.dataset.year}年度 ${opt.dataset.name}`;
      text.classList.remove('is-placeholder');
      closePop();
      updateNote(box);
      document.dispatchEvent(new Event('merge-change'));
      btn.focus();
      return;
    }
    if (openPop && !openPop.contains(e.target)) closePop(); // 外側をクリックしたら閉じる
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && openPop) {
      const btn = openPop.closest('[data-merge]').querySelector('[data-live-pick]');
      closePop();
      btn.focus();
    }
  });
  // 開催日・日程ラベル・ライブ名を変えたら注意文と帯を作り直す
  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-date-input], [name$="[label]"], [data-label-new], [data-live-name]')) return;
    const box = e.target.closest('[data-timetable]')?.querySelector('[data-merge]');
    if (!box) return;
    if (!e.target.matches('[data-date-input]')) fillDate(box); // 開催日を手で直したときは上書きしない
    updateNote(box);
  });
  // ライブ編集: 日程名・「この日程を追加する」を変えたら警告を出し直す
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-day-label], [data-new-day-add], [data-label-new]')) return;
    const box = e.target.closest('form')?.querySelector('[data-live-merge] [data-merge]');
    if (box) updateNote(box);
  });

  document.querySelectorAll('[data-merge]').forEach(updateNote);
}
