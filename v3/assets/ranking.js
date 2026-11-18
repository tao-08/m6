/* =====================================================================
 *  assets/ranking.js — ランキングのカード（partials/ranking_card.php）の動き。ブロックパズル・イントロクイズ共通
 *    ・「月間」「ベストスコア」のタブの切り替え
 *    ・「すべて表示 / 畳む」（一覧ごと）
 *    ・API の返事（ranking / month_ranking / last_month_top）で一覧を描き直す: Ranking.render(root, data)
 *  名前は textContent で入れる（HTML として解釈されない）。
 *  ブロックパズル・イントロクイズの JS より先に読み込む（どちらも defer で、並んだ順に実行される）
 * ===================================================================== */
(() => {
  const fmt = (n) => Number(n).toLocaleString('ja-JP');
  const el = (tag, cls, text) => { const e = document.createElement(tag); e.className = cls; e.textContent = text; return e; };

  /** 先月の1位の文。lib/ranking.php の ranking_last_text と同じ形 */
  const lastText = (top) => (top ? `先月の1位: ${top.names.join('、')} ${fmt(top.score)}` : '');

  function setup(root) {
    const tabs = root.querySelectorAll('[data-rank-tab]');
    const panels = root.querySelectorAll('[data-rank-panel]');
    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabs.forEach((t) => {
          const on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', String(on));
        });
        panels.forEach((p) => { p.hidden = p.dataset.rankPanel !== tab.dataset.rankTab; });
      });
    });
    // 畳む行（.is-fold）を隠すのは CSS（一覧が is-collapsed のとき）。一覧ごとにボタンで付け外しする
    panels.forEach((p) => {
      const tgl = p.querySelector('[data-rank-toggle]');
      const lst = p.querySelector('[data-rank-list]');
      if (!tgl || !lst) return;
      tgl.addEventListener('click', () => {
        const open = lst.classList.toggle('is-collapsed') === false; // toggle は「付いた」なら true を返す → false なら展開中
        const label = open ? '畳む' : 'すべて表示';
        tgl.setAttribute('aria-expanded', String(open));
        tgl.setAttribute('aria-label', label);
        tgl.title = label;
        // 向きの反転は CSS（[aria-expanded="true"] で回転）。アイコン名の差し替えはしない: フォントは使う名前だけ読み込む（lib/icon_names.php）
      });
    });
  }

  /** API の返事で描き直す。開閉・選んでいるタブの状態は要素の class が持つので、描き直しても保たれる */
  function render(root, data) {
    const lists = { month: data.month_ranking || [], best: data.ranking || [] };
    root.querySelectorAll('[data-rank-panel]').forEach((p) => {
      const rows = lists[p.dataset.rankPanel] || [];
      p.querySelector('[data-rank-list]').replaceChildren(...rows.map((x) => {
        const li = document.createElement('li');
        li.className = 'ranking__row' + (x.me ? ' is-me' : '') + (x.fold ? ' is-fold' : '');
        li.append(el('span', 'ranking__rank', String(x.rank)), el('span', 'ranking__name', x.name), el('span', 'ranking__score', fmt(x.score)));
        return li;
      }));
      p.querySelector('[data-rank-empty]').hidden = rows.length > 0;
      p.querySelector('[data-rank-toggle]').hidden = !rows.some((x) => x.fold); // 畳む行が無ければボタンも要らない
      const last = p.querySelector('[data-rank-last]');
      if (last) {
        last.textContent = lastText(data.last_month_top);
        last.hidden = !data.last_month_top;
      }
    });
  }

  window.Ranking = { fmt, render };
  document.querySelectorAll('[data-ranking]').forEach(setup);
})();
