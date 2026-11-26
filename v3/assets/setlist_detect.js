// セットリスト編集の「YouTube から曲を検出」（songs_edit.php の [data-setlist-detect]）
//   押すと api_setlist_detect に送る → サーバーが裏でバンドの YouTube から音声を落とし、Gemini が聴いて曲名を返す（数分〜15分）
//   5秒ごとに途中経過（status=1）を見て、終わったらセトリの確認画面（songs_edit?from_detect=1）へ
//   画面を閉じても裏の処理は続く。開き直したとき解析中なら（data-pending）すぐ見に行く
const box = document.querySelector('[data-setlist-detect]');
const btns = [...(box?.querySelectorAll('button') ?? [])];
const lock = (on) => btns.forEach((b) => { b.disabled = on; });
const status = box?.querySelector('[data-setlist-status]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

const say = (text) => { status.hidden = false; status.textContent = text; };

const post = async (extra = {}) => {
  const body = new FormData();
  body.append('band_id', box.dataset.band);
  Object.entries(extra).forEach(([k, v]) => body.append(k, v));
  const res = await fetch('api_setlist_detect', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json.error || '解析に失敗しました');
  return json;
};

const watch = async (flex = true) => {
  lock(true);
  say(flex ? '解析しています。15分ほどかかることがあります' : '解析しています');
  try {
    for (;;) {
      if ((await post({ status: 1 })).state === 'done') break;
      await new Promise((r) => setTimeout(r, 5000));
    }
    location.href = box.dataset.doneUrl;
  } catch (e) {
    say(e.message);
    lock(false);
  }
};

btns.forEach((btn) => btn.addEventListener('click', async () => {
  lock(true);
  try {
    await post({ flex: btn.dataset.flex });
    watch(btn.dataset.flex === '1');
  } catch (e) {
    say(e.message);
    lock(false);
  }
}));

if (box?.hasAttribute('data-pending')) watch();
