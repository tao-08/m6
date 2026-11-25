// バンド編集の「動画から曲を検出」（band_edit.php の [data-acr-scan]）
//   1. 選んだ動画から、ブラウザの中で ffmpeg.wasm が音だけ抜いてモノラル MP3 にする（数百MB の動画を送らないため）
//   2. api_acr_scan に送る → サーバーが ACRCloud に渡す
//   3. 解析が終わるまで待ち、曲が見つかったらセトリの確認画面（songs_edit?from_acr=1）へ
//   shortcut: 解析中にページを離れると、もう一度選び直すことになる。困る人が出たら、開いたときに band.acr_file_id の状態を見て待ちを再開する
import { FFmpeg } from './ffmpeg/index.js';

const CORE = 'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.10/dist/esm/ffmpeg-core';
const POLL_MS = 10000;
const POLL_MAX = 90; // 15分で諦める

const box = document.querySelector('[data-acr-scan]');
const input = box?.querySelector('input[type="file"]');
const status = box?.querySelector('[data-acr-status]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

const say = (text) => { status.hidden = false; status.textContent = text; };

async function api(data) {
  const body = new FormData();
  body.append('band_id', box.dataset.band);
  Object.entries(data).forEach(([k, v]) => body.append(k, v));
  const res = await fetch('api_acr_scan', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json.error || '失敗しました');
  return json.state;
}

async function extractAudio(file) {
  const ffmpeg = new FFmpeg();
  ffmpeg.on('progress', ({ progress }) => {
    if (progress > 0 && progress <= 1) say(`音声を取り出しています ${Math.round(progress * 100)}%`);
  });
  say('準備しています');
  await ffmpeg.load({ coreURL: `${CORE}.js`, wasmURL: `${CORE}.wasm` });
  // WORKERFS: 動画をメモリに丸ごとコピーせず、そのまま読む（大きい動画でも落ちないように）
  await ffmpeg.createDir('/in');
  await ffmpeg.mount('WORKERFS', { files: [file] }, '/in');
  const code = await ffmpeg.exec(['-i', `/in/${file.name}`, '-vn', '-ac', '1', '-ar', '22050', '-b:a', '48k', 'out.mp3']);
  if (code !== 0) throw new Error('音声を取り出せませんでした');
  const data = await ffmpeg.readFile('out.mp3');
  ffmpeg.terminate();
  return new Blob([data], { type: 'audio/mpeg' });
}

input?.addEventListener('change', async () => {
  const file = input.files[0];
  if (!file) return;
  input.disabled = true;
  try {
    const mp3 = await extractAudio(file);
    say('送信しています');
    await api({ action: 'upload', audio: new File([mp3], 'audio.mp3', { type: 'audio/mpeg' }) });
    say('解析しています。数分かかります');
    for (let i = 0; i < POLL_MAX; i++) {
      await new Promise((r) => setTimeout(r, POLL_MS));
      const state = await api({ action: 'status' });
      if (state === 'done') { location.href = box.dataset.doneUrl; return; }
      if (state === 'none') throw new Error('曲が見つかりませんでした');
      if (state === 'error') throw new Error('解析に失敗しました');
    }
    throw new Error('解析が終わりませんでした');
  } catch (e) {
    say(e.message);
  } finally {
    input.disabled = false;
    input.value = '';
  }
});
