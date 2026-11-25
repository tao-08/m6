// セットリスト編集の「YouTube から曲を検出」（songs_edit.php の [data-setlist-detect]）
//   押すと api_setlist_detect に送る → サーバーがバンドの YouTube から音声を落とし、Gemini が聴いて曲名を返す（1〜2分）
//   曲が見つかったらセトリの確認画面（songs_edit?from_detect=1）へ
const box = document.querySelector('[data-setlist-detect]');
const btn = box?.querySelector('button');
const status = box?.querySelector('[data-setlist-status]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

const say = (text) => { status.hidden = false; status.textContent = text; };

btn?.addEventListener('click', async () => {
  btn.disabled = true;
  say('解析しています。数分かかります');
  try {
    const body = new FormData();
    body.append('band_id', box.dataset.band);
    const res = await fetch('api_setlist_detect', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || '解析に失敗しました');
    location.href = box.dataset.doneUrl;
  } catch (e) {
    say(e.message);
    btn.disabled = false;
  }
});
