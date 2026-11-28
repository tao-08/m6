// セットリスト編集の「YouTube から曲を検出」（MIX があればセレクトで音源を選ぶ）（songs_edit.php の [data-setlist-detect]）
//   押すと api_setlist_detect に送る → サーバーが裏でバンドの YouTube から音声を落とし、Gemini が聴いて曲名を返す（数分〜15分）
//   5秒ごとに途中経過（status=1）を見て、終わったらセトリの確認画面（songs_edit?from_detect=1）へ
//   画面を閉じても裏の処理は続く。開き直したとき解析中なら（data-pending）すぐ見に行く
const box = document.querySelector('[data-setlist-detect]');
const btns = [...(box?.querySelectorAll('button, select') ?? [])];
const src = box?.querySelector('[data-setlist-src]'); // YouTube（''）か MIX（a:N / d:ファイルID）
const lock = (on) => btns.forEach((b) => { b.disabled = on; });
const status = box?.querySelector('[data-setlist-status]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

// detail: AI まわりのエラーの理由（API の detail）。ⓘ（data-tip）に出す
const say = (text, detail) => {
  status.hidden = false;
  status.textContent = text;
  if (!detail) return;
  const tip = document.createElement('button');
  tip.type = 'button';
  tip.className = 'info-tip';
  tip.dataset.tip = detail;
  tip.setAttribute('aria-label', '補足を表示');
  tip.innerHTML = '<span class="icon" aria-hidden="true">info</span>';
  status.append(' ', tip);
};

const post = async (extra = {}) => {
  const body = new FormData();
  body.append('band_id', box.dataset.band);
  Object.entries(extra).forEach(([k, v]) => body.append(k, v));
  const res = await fetch('api_setlist_detect', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(json.error || '解析に失敗しました'), { detail: json.detail });
  return json;
};

const watch = async () => {
  lock(true);
  say('解析中… ');
  status.append(Object.assign(document.createElement('a'), { href: 'setlist_jobs', textContent: '曲の検出の状況' }), 'で進捗を確認できます');
  try {
    for (;;) {
      const res = await post({ status: 1 });
      if (res.state === 'done') {
        // MIX の解析: その MIX にチャプターがあれば、上書きするか聞く（無ければサーバーがもう保存した）
        if (res.overwrite && await askYesNo('この MIX のチャプターを上書きしますか？')) await post({ chapters: 1 });
        break;
      }
      await new Promise((r) => setTimeout(r, 5000));
    }
    location.href = box.dataset.doneUrl;
  } catch (e) {
    say(e.message, e.detail);
    lock(false);
  }
};

btns.filter((b) => b.dataset.flex).forEach((btn) => btn.addEventListener('click', async () => {
  lock(true);
  try {
    await post({ flex: btn.dataset.flex, src: src?.value ?? '' });
    watch();
  } catch (e) {
    say(e.message, e.detail);
    lock(false);
  }
}));

if (box?.hasAttribute('data-pending')) watch();
