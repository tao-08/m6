/* スペシャルコンテンツ（special.php）: 追加のモーダルと、動画・画像・PDF を開くポップアップ */
(() => {
  const add = document.querySelector('[data-special-add-dialog]');
  const view = document.querySelector('[data-special-modal]');
  const edit = document.querySelector('[data-special-edit-dialog]');
  if (!add || !view || !edit) return;
  // 背景を押したら閉じる（<dialog> 自身が押された = 中身の外側）
  [add, view, edit].forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));

  document.querySelector('[data-special-add]').addEventListener('click', () => add.showModal());
  add.querySelector('[data-special-add-close]').addEventListener('click', () => add.close());

  // 編集: 押したカードのタイトル・作者を入れて開く。削除のフォームにも同じ id を入れる
  document.querySelectorAll('[data-special-edit]').forEach((btn) => btn.addEventListener('click', () => {
    edit.querySelectorAll('input[name=id]').forEach((i) => { i.value = btn.dataset.id; });
    edit.querySelector('input[name=title]').value = btn.dataset.title;
    edit.querySelector('input[name=author]').value = btn.dataset.author;
    edit.showModal();
  }));
  edit.querySelector('[data-special-edit-close]').addEventListener('click', () => edit.close());

  const frame = view.querySelector('[data-special-frame]');
  const img = view.querySelector('[data-special-img]');
  const title = view.querySelector('[data-special-title]');
  const dl = view.querySelector('[data-special-dl]');
  document.querySelectorAll('[data-special-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const image = btn.dataset.kind === 'image';
      img.hidden = !image;
      frame.hidden = image;
      if (image) img.src = btn.dataset.src; else frame.src = btn.dataset.src;
      title.textContent = btn.dataset.title;
      // 動画は元の動画のページ、ファイルはダウンロード
      dl.href = btn.dataset.dl;
      dl.toggleAttribute('download', btn.dataset.kind !== 'yt');
      dl.target = btn.dataset.kind === 'yt' ? '_blank' : '';
      view.showModal();
    });
  });
  view.querySelector('[data-special-close]').addEventListener('click', () => view.close());
  // 閉じたら外す（動画の再生を止める）。.modal の閉じるアニメーション（0.2秒）が終わってから
  view.addEventListener('close', () => setTimeout(() => {
    frame.removeAttribute('src');
    img.removeAttribute('src');
  }, 250));
})();
