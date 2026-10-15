/**
 * =====================================================================
 *  live_list.js — ライブ一覧（index.php）を、端末に保存したデータで描画する
 * =====================================================================
 *  流れ
 *    1. assets/app.js が起動するとき、まず window.pageHydrate() を待つ（app.js の DOMContentLoaded）
 *    2. ここで LocalCache.load('lives', 'api_lives.php') を呼ぶ
 *         保存データあり → すぐ描画（サーバーの重い集計を待たない）→ 裏で最新か確認
 *         保存データなし → サーバーから取って描画（初回だけ。今までのページ表示と同じくらいの待ち）
 *    3. 描画が終わってから app.js が年度スロット・絞り込み・並び替えを組み立てる
 *       （それらは「画面にあるカード」を探して動くので、先に描画しておく必要がある）
 *    4. 裏の確認で「データが変わっていた」ら、右上に更新ボタン（↻）を出す。押すと読み直す
 *
 *  描画は innerHTML ではなく createElement + textContent で作る（el 関数）。
 *    textContent は文字をただの文字として入れるので、ライブ名に <script> が入っていても動かない（XSS 対策）。
 *    innerHTML は文字を HTML として解釈するので、ユーザーが入力した値には使わない。
 * =====================================================================
 */
(function () {
  'use strict';

  const LIVE_KEY = 'lives';            // 端末の DB に保存するときの名前
  const LIVE_URL = 'api_lives.php';
  const SCHEMA = 1;                    // api_lives.php の 'schema' と同じ数字。JSON の形を変えたら両方上げる
  const ROOT_SELECTOR = '[data-live-app]';

  /* -------------------------------------------------------------------
   * 小さな部品
   * ----------------------------------------------------------------- */
  /**
   * 要素を作る。el('div', { class: 'card', 'data-x': 1 }, ['文字', 子要素, ...])
   *   子が文字列なら textContent として入る（安全）。null / false は無視。
   *   属性の値が null / false のときは付けない。
   */
  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    for (const [name, value] of Object.entries(attrs || {})) {
      if (value !== null && value !== undefined && value !== false) node.setAttribute(name, value === true ? '' : String(value));
    }
    for (const child of [].concat(children === undefined ? [] : children)) {
      if (child === null || child === undefined || child === false) continue;
      node.append(child); // append は文字列を Text ノードにしてくれる（HTML としては解釈されない）
    }
    return node;
  }

  /** Material Symbols のアイコン（lib/bootstrap.php の icon() と同じ形） */
  const icon = (name) => el('span', { class: 'icon', 'aria-hidden': 'true' }, name);

  /** 'YYYY-MM-DD' → '9/14（土）'。new Date('2024-09-14') だと時差で前日になることがあるので、数字に分けて作る */
  function fmtDate(date) {
    if (!date) return '';
    const [y, m, d] = date.split('-').map(Number);
    const week = ['日', '月', '火', '水', '木', '金', '土'][new Date(y, m - 1, d).getDay()];
    return `${m}/${d}（${week}）`;
  }

  /** 今日の日付を 'YYYY-MM-DD' で（端末の時計・時間帯）。保存データは古くなるので「予定」は毎回ここで判定する */
  function today() {
    const t = new Date();
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
  }

  /** YouTube のリンクとして使ってよい URL か（lib/bootstrap.php の youtube_url_valid と同じ決まり） */
  function youtubeOk(url) {
    try {
      const u = new URL(url);
      return u.protocol === 'https:'
        && ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'].includes(u.hostname);
    } catch {
      return false; // URL として読めない
    }
  }

  /** YouTube のロゴ。中身は固定の文字列（ユーザー入力が入らない）なので innerHTML で入れてよい */
  function youtubeIcon() {
    const wrap = document.createElement('span');
    wrap.innerHTML = '<svg class="youtube-icon" viewBox="0 3.55 24 16.9" width="34" height="24" aria-hidden="true">'
      + '<path fill="#FF0000" d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81z"/>'
      + '<path fill="#FFFFFF" d="M9.55 15.57V8.43L15.82 12l-6.27 3.57z"/></svg>';
    return wrap.firstChild;
  }

  /* -------------------------------------------------------------------
   * 描画
   * ----------------------------------------------------------------- */
  /** ライブ1件ぶんのカード（index.php に書いてあった PHP の HTML と同じ形） */
  function liveCard(live, todayStr) {
    const venues = [...new Set(live.days.map((d) => d.venue).filter(Boolean))]; // 重複と空を除く
    const searchText = `${live.name} ${venues.join(' ')} ${live.notes.join(' ')}`; // 絞り込みで当てる文字（元の PHP と同じ組み立て）
    const upcoming = live.last_date !== null && live.last_date >= todayStr;     // 最後の日程がまだ来ていなければ「予定」

    return el('div', { class: 'card live-card', 'data-text': searchText }, [
      el('div', { class: 'live-card__head' }, [
        el('h3', { class: 'live-card__title' }, [
          el('a', { class: 'live-card__link', href: `live?id=${live.id}` }, live.name),
          upcoming && ' ',
          upcoming && el('span', { class: 'tag tag--plan' }, '予定'),
        ]),
        el('span', { class: 'pill' }, `${live.days.length}日程`),
      ]),
      el('ul', { class: 'live-card__days' }, live.days.map((d) => el('li', {}, [
        el('span', { class: 'day-no' }, d.label || '—'),
        el('span', {}, fmtDate(d.date) || '日付未設定'),
        el('span', { class: 'muted ellipsis' }, d.venue || ''),
      ]))),
      el('p', { class: 'live-card__foot' }, [
        el('strong', {}, String(live.bands)),
        ' バンド出演 ',
        live.youtube && youtubeOk(live.youtube) && el('a', {
          class: 'live-card__yt', href: live.youtube, target: '_blank', rel: 'noopener noreferrer',
          'aria-label': `「${live.name}」を YouTube で見る`, title: 'YouTube で見る',
        }, youtubeIcon()),
        el('span', { class: 'arrow' }, icon('arrow_forward')),
      ]),
    ]);
  }

  /** 年度スロットの1段 */
  function slotItem(year) {
    if (year === 0) return el('div', { class: 'year-slot__item', 'data-value': '0', 'data-label': '未設定' }, '未設定');
    return el('div', { class: 'year-slot__item', 'data-value': String(year), 'data-label': `${year}年度` }, [
      el('span', { class: 'year-slot__full' }, [String(year), el('small', {}, '年度')]),
      el('span', { class: 'year-slot__short' }, `'${String(year % 100).padStart(2, '0')}`),
    ]);
  }

  /** ライブ一覧の中身（ツールバー + カード）を作って root に入れる */
  function render(root, data) {
    const todayStr = today();
    root.replaceChildren(); // 中身を空にする（「読み込み中」の表示などを消す）

    // 上の数字。index.php の SSR には「–」を置いてあるので、ここで数字に書き換える
    for (const [name, value] of Object.entries(data.stats)) {
      const dd = document.querySelector(`[data-live-stat="${name}"]`);
      if (dd) dd.textContent = String(value);
    }

    // ライブが1件も無いとき
    if (!data.years.length) {
      root.append(el('div', { class: 'empty card' }, [
        el('p', { class: 'empty__title' }, 'まだライブが登録されていません'),
        el('p', { class: 'muted' }, 'タイムテーブルと名簿（CSV / PDF）を取り込むか、手入力で登録しよう。'),
        el('a', { class: 'btn btn--primary', href: 'import' }, '＋ 新規追加'),
      ]));
      return;
    }

    // data-year-slot / data-filter / data-sort-toggle は assets/app.js が探して動かす目印
    const slotYears = data.years.map((y) => y.year).sort((a, b) => b - a); // 年度スロットは新しい年度が先
    root.append(
      el('div', { class: 'toolbar' }, [
        el('div', {
          class: 'year-slot year-slot--live', 'data-year-slot': true, tabindex: '0',
          role: 'spinbutton', 'aria-label': '年度で絞り込み', title: '上下にドラッグで年度を切り替え',
        }, el('div', { class: 'year-slot__reel' }, [
          el('div', { class: 'year-slot__item', 'data-value': '' }, 'すべて'),
          ...slotYears.map(slotItem),
        ])),
        el('input', {
          type: 'search', class: 'search', placeholder: 'ライブ名・会場・メモで絞り込み',
          'data-filter': '.live-card', 'aria-label': '絞り込み',
        }),
        el('a', { class: 'btn btn--primary', href: 'import' }, '＋ 新規追加'),
      ]),
      el('div', { class: 'sorted-list sorted-list--live' }, [
        el('button', {
          type: 'button', class: 'btn btn--ghost btn--sm sorted-list__btn',
          'data-sort-toggle': '#live-list', 'data-sort-items': '.grid', 'aria-pressed': 'false',
        }, '新しい順 ↓'),
        el('div', { id: 'live-list' }, data.years.map(({ year, lives }) => el('section', { class: 'year', 'data-year': String(year) }, [
          el('h2', { class: 'year__title' }, year > 0 ? [String(year), el('small', {}, '年度')] : '年度未設定'),
          el('div', { class: 'grid' }, lives.map((live) => liveCard(live, todayStr))),
        ]))),
      ]),
    );
  }

  /** 「最新に更新」ボタン。裏の確認で、サーバーのデータが変わっていたときだけ出す */
  function showRefreshButton(root) {
    if (root.querySelector('[data-live-refresh]')) return;
    const btn = el('button', {
      type: 'button', class: 'btn btn--ghost btn--sm', 'data-live-refresh': true,
      'aria-label': '最新のデータに更新', title: '最新のデータに更新',
    }, icon('refresh'));
    // 保存データはすでに最新に入れ替わっているので、読み直せば新しい内容が出る
    btn.addEventListener('click', () => location.reload());
    const toolbar = root.querySelector('.toolbar');
    (toolbar || root).append(btn);
  }

  /* -------------------------------------------------------------------
   * app.js から呼ばれる入口
   *   これが終わるまで、app.js は年度スロットなどの組み立てを待つ
   * ----------------------------------------------------------------- */
  window.pageHydrate = async function () {
    const root = document.querySelector(ROOT_SELECTOR);
    if (!root || !window.LocalCache) return; // ライブ一覧以外のページでは何もしない

    try {
      await window.LocalCache.dropIfSchemaDiffers(LIVE_KEY, SCHEMA); // 古い形の保存データは捨てる
      const result = await window.LocalCache.load(LIVE_KEY, LIVE_URL);
      if (result.data.schema !== SCHEMA) throw new Error('schema mismatch'); // サーバーだけ新しい形になったとき
      render(root, result.data);

      // 保存データで描画したときだけ、裏の確認の結果を見る
      if (result.fromCache) {
        result.revalidate
          .then((r) => { if (r.changed) showRefreshButton(root); })
          .catch(() => { /* オフライン・ログイン切れ。保存データのまま使う */ });
      }
    } catch (err) {
      // 取得も描画もできなかった。読み直しボタンを出す
      root.replaceChildren(el('div', { class: 'empty card' }, [
        el('p', { class: 'empty__title' }, 'ライブ一覧を読み込めませんでした'),
        el('a', { class: 'btn btn--primary', href: './' }, icon('refresh')),
      ]));
    }
  };
})();
