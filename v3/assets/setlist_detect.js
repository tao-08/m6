// バンド編集の「動画から曲を検出」（band_edit.php の [data-setlist-detect]）
//   1. 選んだ動画から、ブラウザの中で ffmpeg.wasm が音声だけ抜いてモノラル MP3 にする（数百MB の動画を送らないため）。MP3 を選んだらそのまま送る
//      低音のこもりを削り（highpass）、MC と演奏の音量差をならす（loudnorm）。歌詞を聞き取りやすくするため
//   2. api_setlist_detect に送る → Gemini が聴いて曲名を返す（1〜2分）
//   3. 曲が見つかったらセトリの確認画面（songs_edit?from_detect=1）へ
import { FFmpeg } from './ffmpeg/index.js';

const CORE = 'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.10/dist/esm/ffmpeg-core';

const box = document.querySelector('[data-setlist-detect]');
const input = box?.querySelector('input[type="file"]');
const status = box?.querySelector('[data-setlist-status]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

const say = (text) => { status.hidden = false; status.textContent = text; };

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
  const code = await ffmpeg.exec(['-i', `/in/${file.name}`, '-vn', '-af', 'highpass=f=90,loudnorm', '-ac', '1', '-ar', '22050', '-b:a', '48k', 'out.mp3']);
  if (code !== 0) throw new Error('音声を取り出せませんでした');
  const data = await ffmpeg.readFile('out.mp3');
  ffmpeg.terminate();
  return new File([data], 'audio.mp3', { type: 'audio/mpeg' });
}

input?.addEventListener('change', async () => {
  const file = input.files[0];
  if (!file) return;
  input.disabled = true;
  try {
    const body = new FormData();
    body.append('band_id', box.dataset.band);
    // MP3 は加工済み（scripts/yt_setlist_audio.php で落としたもの）として、取り出しを飛ばす。60MB を超えるものは小さくするため取り出す
    const ready = file.type === 'audio/mpeg' && file.size <= 60 * 1024 * 1024;
    body.append('audio', ready ? file : await extractAudio(file));
    say('解析しています。数分かかります');
    const res = await fetch('api_setlist_detect', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || '解析に失敗しました');
    location.href = box.dataset.doneUrl;
  } catch (e) {
    say(e.message);
  } finally {
    input.disabled = false;
    input.value = '';
  }
});
