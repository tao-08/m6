/*
 * =====================================================================
 *  autochapter.js — ミックス音源の音量だけで MC / 曲のチャプター案を作る
 * =====================================================================
 *  AI は使わない。1秒ごとの音量（dB）を出して、「大きい所＝曲」「小さい所＝MC」とみなす。
 *  結果はあくまで下書き。画面でユーザーが確認して直す前提。
 *
 *  AutoChapter.analyze({ url, size, duration, songCount, concurrency, signal, onProgress, stats })
 *    → Promise<[{ kind: 'mc' | 'song', ms }]>
 *    url は ?from=<バイト位置> でその位置からのバイト列を返す前提（Range は使えない）。
 *    全部は読まない（粗く → 細かく）:
 *      1. 10 秒おきに 1.5 秒ぶんだけ読んで、大まかな音量の曲線を作る
 *      2. 大きい / 小さいが切り替わる所の前後だけを、1 秒ごとの細かさで読み直す
 *    stats = {} を渡すと、読んだバイト数（stats.bytes）が入る。
 *  AutoChapter.coarseTransitions(coarse) / fineWindows(...) / mergeCurve(...)
 *    → 上の粗く → 細かくの、読まない純粋な部分（テスト用に公開）
 *  AutoChapter.segment(dbPerSec, songCount)
 *    → 音量の配列からチャプターを作るだけの純粋な部分（テスト用に公開）
 */
(() => {
  'use strict';

  const root = typeof window !== 'undefined' ? window : globalThis;

  const FLOOR_DB = -90;
  const MEDIAN_SEC = 5;     // 中央値フィルタの幅（拍手やノイズの瞬間的な山を消す）
  const AVERAGE_SEC = 3;    // 移動平均の幅
  const MERGE_GAP_SEC = 4;  // これ未満の無音は曲の中のブレイクとして繋げる
  const MIN_SONG_SEC = 45;  // これより短い大音量は拍手・カウントとして捨てる
  const LEAD_MC_SEC = 10;   // 先頭がこれより長く空いていたら 0 秒に MC を置く
  const MC_GAP_SEC = 15;    // 曲と曲の間・最後がこれ以上空いていたら MC を置く
  const MIN_CONTRAST_DB = 6; // 大小の差がこれ未満なら区別できないので何も出さない
  const DECODE_RATE = 8000;  // 音量を見るだけなので低いサンプルレートで十分
  const CONCURRENCY = 4; // ドライブ（drive_audio 経由）に短時間で集中させすぎない
  const COARSE_STEP_SEC = 10;  // 粗い読みは、この間隔で
  const COARSE_READ_SEC = 1.5; // 1回あたりこの長さだけ読む
  const MIN_READ_BYTES = 24000; // MP3 として復号できる最小の大きさ
  const FINE_HALF_SEC = 15;    // 切り替わりの前後これだけを細かく読み直す
  const FINE_CHUNK_BYTES = 500000;
  const COARSE_SHARE = 0.6;    // 進み具合のうち、粗い読みが占める割合

  // ---- 純粋な部分 ----------------------------------------------------

  // 窓幅 w で fn（配列→値）を端を縮めながら当てる
  const slide = (a, w, fn) => {
    const h = w >> 1;
    return a.map((_, i) => fn(a.slice(Math.max(0, i - h), i + h + 1)));
  };
  const median = (s) => { const t = [...s].sort((x, y) => x - y); return t[t.length >> 1]; };
  const mean = (s) => s.reduce((x, y) => x + y, 0) / s.length;

  // 大津の方法: 0.5 dB ごとのヒストグラムで、2グループの分離が最大になる境目を探す
  function otsu(values) {
    const BIN = 0.5;
    const n = Math.round(-FLOOR_DB / BIN) + 1;
    const hist = new Array(n).fill(0);
    for (const v of values) hist[Math.min(n - 1, Math.max(0, Math.round((v - FLOOR_DB) / BIN)))]++;
    const total = values.length;
    let sumAll = 0;
    hist.forEach((c, i) => { sumAll += c * i; });
    let wB = 0, sumB = 0, best = -1, bestI = 0;
    for (let i = 0; i < n; i++) {
      wB += hist[i];
      if (!wB) continue;
      const wF = total - wB;
      if (!wF) break;
      sumB += hist[i] * i;
      const d = sumB / wB - (sumAll - sumB) / wF;
      const between = wB * wF * d * d;
      if (between > best) { best = between; bestI = i; }
    }
    return FLOOR_DB + (bestI + 0.5) * BIN;
  }

  // 計測できなかった秒（NaN）は直前の値で埋める（先頭は最初の値）
  function fillUnknown(db) {
    const first = db.find(Number.isFinite);
    if (first === undefined) return [];
    let last = first;
    return db.map((v) => (Number.isFinite(v) ? (last = v) : last));
  }

  function segment(dbPerSec, songCount) {
    const raw = fillUnknown(dbPerSec);
    const total = raw.length;
    if (!total) return [];

    const db = slide(slide(raw, MEDIAN_SEC, median), AVERAGE_SEC, mean);
    if (Math.max(...db) - Math.min(...db) < MIN_CONTRAST_DB) return [];
    const th = otsu(db);

    // 大きい区間 [start, end)（秒）を作る
    let segs = [];
    db.forEach((v, i) => {
      if (v < th) return;
      const last = segs[segs.length - 1];
      if (last && last.end === i) last.end = i + 1; else segs.push({ start: i, end: i + 1 });
    });
    // 短い切れ目は曲の中とみなして繋げる → 短い区間は捨てる
    segs = segs.reduce((acc, s) => {
      const p = acc[acc.length - 1];
      if (p && s.start - p.end < MERGE_GAP_SEC) p.end = s.end; else acc.push({ ...s });
      return acc;
    }, []).filter((s) => s.end - s.start >= MIN_SONG_SEC);

    if (songCount > 0 && segs.length > songCount) {
      segs = [...segs].sort((a, b) => (b.end - b.start) - (a.end - a.start))
        .slice(0, songCount).sort((a, b) => a.start - b.start);
    }
    if (!segs.length) return [];

    const out = [];
    const mc = (sec) => out.push({ kind: 'mc', ms: Math.round(sec * 1000) });
    if (segs[0].start > LEAD_MC_SEC) mc(0);
    segs.forEach((s, i) => {
      out.push({ kind: 'song', ms: Math.round(Math.max(0, s.start - 1) * 1000) });
      const next = segs[i + 1];
      if ((next ? next.start : total) - s.end >= MC_GAP_SEC) mc(s.end);
    });
    return out.sort((a, b) => a.ms - b.ms);
  }

  // ---- 粗く → 細かく（読まない純粋な部分） ------------------------------

  // 粗い曲線（step 秒ごとの dB。NaN は不明）から、大きい / 小さいが切り替わる所の「秒」を返す
  // 曲の中の静かな所や拍手の山も拾うが、細かく読み直す範囲が増えるだけなので、均さずにそのまま見る
  function coarseTransitions(coarse, step) {
    const v = fillUnknown(coarse);
    if (v.length < 2 || Math.max(...v) - Math.min(...v) < MIN_CONTRAST_DB) return [];
    const th = otsu(v);
    const out = [];
    for (let k = 1; k < v.length; k++) if ((v[k] >= th) !== (v[k - 1] >= th)) out.push(k * step);
    return out;
  }

  // 切り替わりの秒（昇順）の前後 half 秒を、重なりをまとめた [開始秒, 終了秒] の並びにする
  function fineWindows(transitions, half, duration) {
    const out = [];
    for (const t of transitions) {
      const a = Math.max(0, Math.floor(t - half));
      const b = Math.min(Math.ceil(duration), Math.ceil(t + half));
      const last = out[out.length - 1];
      if (last && a <= last[1]) last[1] = Math.max(last[1], b); else out.push([a, b]);
    }
    return out;
  }

  // 1 秒ごとの曲線: 粗い値を step 秒ぶん保ち、細かく読めた秒（有限の値）だけで上書きする
  function mergeCurve(coarse, step, fine, secs) {
    return Array.from({ length: secs }, (_, s) => (Number.isFinite(fine[s]) ? fine[s] : coarse[Math.min(coarse.length - 1, Math.floor(s / step))] ?? NaN));
  }

  // ---- 音源を読む部分 --------------------------------------------------

  // from= 以降のバイト列を len だけ読んで、残りは捨てる
  async function fetchChunk(url, from, len, signal, stats) {
    const res = await fetch(url + (url.includes('?') ? '&' : '?') + 'from=' + from, { signal });
    if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);
    const reader = res.body.getReader();
    const buf = new Uint8Array(len);
    let got = 0;
    while (got < len) {
      const { done, value } = await reader.read();
      if (done) break;
      const n = Math.min(value.length, len - got);
      buf.set(value.subarray(0, n), got);
      got += n;
    }
    reader.cancel().catch(() => {});
    if (stats) stats.bytes = (stats.bytes || 0) + got;
    return buf.slice(0, got).buffer;
  }

  const decode = (bytes) => new (root.OfflineAudioContext || root.webkitOfflineAudioContext)(1, 1, DECODE_RATE).decodeAudioData(bytes);

  // 1秒ごとの二乗和と個数を足し込む（秒の途中でチャンクが切れても合算できる）
  function accumulate(audio, t0, sumsq, cnt) {
    const sr = audio.sampleRate, len = audio.length, ch = audio.numberOfChannels;
    const data = Array.from({ length: ch }, (_, c) => audio.getChannelData(c));
    for (let sec = Math.floor(t0); sec < sumsq.length; sec++) {
      const i0 = Math.max(0, Math.ceil((sec - t0) * sr));
      const i1 = Math.min(len, Math.ceil((sec + 1 - t0) * sr));
      if (i0 >= len) break;
      let s = 0;
      for (let i = i0; i < i1; i++) {
        let m = 0;
        for (let c = 0; c < ch; c++) m += data[c][i];
        m /= ch;
        s += m * m;
      }
      sumsq[sec] += s;
      cnt[sec] += i1 - i0;
    }
  }

  const toDb = (sumsq, n) => Math.max(FLOOR_DB, 20 * Math.log10(Math.sqrt(sumsq / n) || 1e-9));

  // count 個の仕事を n 並列で回す。失敗は不明のまま進み、中断だけは止める。
  //   2 割以上失敗したら（ドライブの制限・通信切れなど）、間違った区切りを黙って出さないようにエラーにする
  async function pool(count, n, signal, job, tick) {
    let next = 0;
    let failed = 0;
    const worker = async () => {
      while (next < count) {
        const i = next++;
        if (signal && signal.aborted) throw new DOMException('Aborted', 'AbortError');
        try { await job(i); } catch (e) { if (signal && signal.aborted) throw e; failed++; }
        if (tick) tick();
      }
    };
    await Promise.all(Array.from({ length: Math.min(n, count) }, worker));
    if (count > 0 && failed / count >= 0.2) throw new Error(`読み込みに失敗した区間が多い（${failed}/${count}）`);
  }

  // t 秒（バイト位置は一定ビットレートとみなす）から len バイトだけ読んで、その音量（dB）を返す。音が取れなければ NaN
  async function readDb(url, size, bps, t, len, signal, stats) {
    const from = Math.max(0, Math.min(Math.round(t * bps), size - len));
    const audio = await decode(await fetchChunk(url, from, len, signal, stats));
    const sq = new Float64Array(2), cn = new Float64Array(2); // 2 秒ぶんの入れ物（読むのは最大 1.5 秒）
    accumulate(audio, 0, sq, cn);
    return cn[0] + cn[1] > 0 ? toDb(sq[0] + sq[1], cn[0] + cn[1]) : NaN;
  }

  async function analyze({ url, size, duration, songCount = 0, concurrency = CONCURRENCY, signal, onProgress, stats }) {
    const secs = Math.ceil(duration);
    const bps = size / duration; // 一定ビットレート（CBR）とみなして位置を時間に直す
    const report = onProgress || (() => {});

    // 1. 粗く: COARSE_STEP_SEC ごとに COARSE_READ_SEC だけ読んで、その区間の音量にする
    const n = Math.ceil(duration / COARSE_STEP_SEC);
    const readBytes = Math.max(MIN_READ_BYTES, Math.ceil(bps * COARSE_READ_SEC));
    const coarse = new Array(n).fill(NaN);
    let done = 0;
    await pool(n, concurrency, signal, async (k) => {
      const t = k * COARSE_STEP_SEC + (COARSE_STEP_SEC - COARSE_READ_SEC) / 2; // 区間の真ん中
      coarse[k] = await readDb(url, size, bps, t, readBytes, signal, stats);
    }, () => report(++done / n * COARSE_SHARE));

    // 2. 細かく: 切り替わりの前後だけ、1秒ごとに読み直す
    const wins = fineWindows(coarseTransitions(coarse, COARSE_STEP_SEC), FINE_HALF_SEC, duration);
    const jobs = [];
    for (const [a, b] of wins) {
      const end = Math.min(size, Math.round(b * bps));
      for (let off = Math.round(a * bps); off < end; off += FINE_CHUNK_BYTES) jobs.push({ from: off, len: Math.min(FINE_CHUNK_BYTES, end - off) });
    }
    const sumsq = new Float64Array(secs + 1), cnt = new Float64Array(secs + 1);
    done = 0;
    await pool(jobs.length, concurrency, signal, async (i) => {
      const { from, len } = jobs[i];
      accumulate(await decode(await fetchChunk(url, from, len, signal, stats)), from / bps, sumsq, cnt);
    }, () => report(COARSE_SHARE + ++done / jobs.length * (1 - COARSE_SHARE)));
    report(1);

    const fine = Array.from({ length: secs }, (_, s) => (cnt[s] > 0 ? toDb(sumsq[s], cnt[s]) : NaN));
    return segment(mergeCurve(coarse, COARSE_STEP_SEC, fine, secs), songCount);
  }

  root.AutoChapter = { analyze, segment, coarseTransitions, fineWindows, mergeCurve };
})();
