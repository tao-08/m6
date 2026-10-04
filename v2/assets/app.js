// 小さなUI補助。JSが無くても全ページ動くようにしてある
document.addEventListener('DOMContentLoaded', () => {
  // 絞り込み検索
  document.querySelectorAll('[data-filter]').forEach((input) => {
    const items = document.querySelectorAll(input.dataset.filter);
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      items.forEach((el) => {
        el.hidden = q !== '' && !(el.dataset.text || el.textContent).toLowerCase().includes(q);
      });
    });
  });

  // ライブ詳細の DAY タブ
  const tabs = document.querySelectorAll('[data-tab]');
  const showDay = (hash) => {
    const target = hash && document.querySelector(hash.split('?')[0]);
    if (!target || !target.classList.contains('day')) return;
    document.querySelectorAll('.day').forEach((d) => { d.hidden = d !== target; });
    tabs.forEach((t) => t.classList.toggle('is-active', t.getAttribute('href') === '#' + target.id));
  };
  if (tabs.length) {
    document.querySelectorAll('.day[data-hidden]').forEach((d) => { d.hidden = true; });
    tabs.forEach((t) => t.addEventListener('click', (e) => {
      e.preventDefault();
      history.replaceState(null, '', t.getAttribute('href'));
      showDay(t.getAttribute('href'));
    }));
    showDay(location.hash);
  }

  // 削除などの確認
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // ファイル選択の表示 & ドラッグ中の見た目
  const drop = document.querySelector('[data-dropzone]');
  if (drop) {
    const input = drop.querySelector('[data-file-input]');
    const list = drop.querySelector('[data-file-list]');
    const render = () => {
      list.replaceChildren(...[...input.files].map((f) => {
        const li = document.createElement('li');
        li.textContent = `${f.name.toLowerCase().endsWith('.pdf') ? 'PDF' : 'CSV'} · ${f.name}`;
        return li;
      }));
    };
    input.addEventListener('change', render);
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.add('is-over')));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
  }

  // 取り込みプレビュー: 選んだメンバー表の中身を表示
  const rosterEl = document.getElementById('roster-data');
  if (rosterEl) {
    const roster = JSON.parse(rosterEl.textContent);
    document.querySelectorAll('[data-roster-select]').forEach((sel) => {
      const out = sel.parentElement.querySelector('[data-roster-preview]');
      const update = () => {
        const members = roster[sel.value] || [];
        out.textContent = sel.value === '' ? '' : (members.length ? members.join(' / ') : 'メンバーの記載なし');
        sel.closest('tr').classList.toggle('is-unmatched', sel.value === '');
      };
      sel.addEventListener('change', update);
      update();
    });
  }

  // バンド編集: メンバー行の追加・削除
  const rows = document.querySelector('[data-rows]');
  if (rows) {
    document.querySelector('[data-add-row]').addEventListener('click', () => {
      const row = rows.lastElementChild.cloneNode(true);
      row.querySelector('input').value = '';
      rows.appendChild(row);
      row.querySelector('input').focus();
    });
    rows.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove-row]');
      if (!btn) return;
      const row = btn.closest('.member-row-edit');
      if (rows.children.length > 1) row.remove(); else row.querySelector('input').value = '';
    });
  }
});
