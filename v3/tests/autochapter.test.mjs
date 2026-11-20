// node tests/autochapter.test.mjs
import assert from 'node:assert/strict';

globalThis.window = globalThis;
await import('../assets/autochapter.js');
const { segment, coarseTransitions, fineWindows, mergeCurve, toLevels } = globalThis.AutoChapter;

// 決まった揺らぎ（±2 dB）を付けて、[秒数, dB] の並びから配列を作る
let seed = 1;
const rnd = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff - 0.5) * 4;
const build = (parts) => parts.flatMap(([n, db]) => Array.from({ length: n }, () => db + rnd()));
const near = (ms, sec) => Math.abs(ms / 1000 - sec) <= 2;
const MC = -35, SONG = -12;

let n = 0;
const test = (name, fn) => { fn(); console.log('ok -', name); n++; };

test('3曲 + 前後と間の MC', () => {
  const r = segment(build([[40, MC], [200, SONG], [40, MC], [200, SONG], [40, MC], [200, SONG], [40, MC]]), 3);
  const songs = r.filter((c) => c.kind === 'song'), mcs = r.filter((c) => c.kind === 'mc');
  assert.equal(songs.length, 3);
  [40, 280, 520].forEach((s, i) => assert.ok(near(songs[i].ms, s - 1), `song${i} ${songs[i].ms}`));
  assert.equal(mcs.length, 4);
  assert.equal(mcs[0].ms, 0);
  [240, 480, 720].forEach((s, i) => assert.ok(near(mcs[i + 1].ms, s), `mc${i + 1} ${mcs[i + 1].ms}`));
  assert.deepEqual(r.map((c) => c.ms), r.map((c) => c.ms).sort((a, b) => a - b));
});

test('曲の途中の 3 秒の落ち込みでは分割しない', () => {
  const r = segment(build([[40, MC], [100, SONG], [3, MC], [97, SONG], [40, MC]]), 0);
  assert.equal(r.filter((c) => c.kind === 'song').length, 1);
});

test('songCount=2 は長い 2 曲だけ残す', () => {
  const r = segment(build([[40, MC], [200, SONG], [40, MC], [120, SONG], [40, MC], [180, SONG], [40, MC]]), 2);
  const songs = r.filter((c) => c.kind === 'song');
  assert.equal(songs.length, 2);
  assert.ok(near(songs[0].ms, 39) && near(songs[1].ms, 439), JSON.stringify(songs));
});

test('songCount=0 は 45 秒未満を捨てて全部残す', () => {
  const r = segment(build([[20, SONG], [30, MC], [200, SONG], [30, MC], [120, SONG]]), 0);
  assert.equal(r.filter((c) => c.kind === 'song').length, 2);
});

test('大小の差がなければ空', () => {
  assert.deepEqual(segment(build([[300, SONG]]), 0), []);
});

test('NaN（デコード失敗）は前の値で埋める', () => {
  const a = build([[40, MC], [200, SONG], [40, MC]]);
  for (let i = 100; i < 130; i++) a[i] = NaN;
  assert.equal(segment(a, 1).filter((c) => c.kind === 'song').length, 1);
});

test('粗く → 細かく: 切り替わりの前後だけ読み直して、全部読んだときと同じ区切りになる', () => {
  const full = build([[40, MC], [200, SONG], [37, MC], [200, SONG], [40, MC], [200, SONG], [40, MC]]);
  const coarse = full.filter((_, i) => i % 10 === 5); // 10 秒おきに 1 つ（読まない所は粗い値のまま）
  const wins = fineWindows(coarseTransitions(coarse, 10), 15, full.length);
  assert.ok(wins.length >= 3 && wins.every(([a, b]) => b - a >= 30));
  const read = wins.reduce((t, [a, b]) => t + b - a, 0);
  assert.ok(read < full.length / 2, `読む秒数 ${read} / ${full.length}`);
  const fine = full.map((v, i) => (wins.some(([a, b]) => i >= a && i < b) ? v : NaN));
  const merged = mergeCurve(coarse, 10, fine, full.length);
  const a = segment(merged, 3), b = segment(full, 3);
  assert.equal(a.length, b.length);
  a.forEach((c, i) => assert.ok(c.kind === b[i].kind && Math.abs(c.ms - b[i].ms) <= 3000, `${i}: ${c.ms} / ${b[i].ms}`));
});

test('fineWindows: 重なりはまとめ、両端は音源の中に収める', () => {
  assert.deepEqual(fineWindows([10, 30, 200], 15, 215), [[0, 45], [185, 215]]);
  assert.deepEqual(fineWindows([], 15, 100), []);
});

test('mergeCurve: 細かい値があれば上書き、NaN は粗い値のまま', () => {
  const m = mergeCurve([-10, -50], 10, [NaN, -1, NaN, ...Array(17).fill(NaN)], 20);
  assert.deepEqual([m[0], m[1], m[9], m[10]], [-10, -1, -10, -50]);
});

test('coarseTransitions: 大小の差がなければ切り替わりなし', () => {
  assert.deepEqual(coarseTransitions([-12, -12.5, -11, -12], 10), []);
  assert.deepEqual(coarseTransitions([-40, -40, -10, -10, -40], 10), [20, 40]);
});

test('toLevels: 最小が 00・最大が ff、不明は直前の値', () => {
  assert.equal(toLevels([-40, -20, -30]), '00ff80');
  assert.equal(toLevels([NaN, -40, NaN, -20]), '000000ff'); // 先頭の不明は最初の値、途中の不明は直前の値
  assert.equal(toLevels([]), '');
});

test('toLevels: 差が 1 dB 未満なら全部 80', () => {
  assert.equal(toLevels([-20, -20.5, -20]), '808080');
});

console.log(`${n} passed`);
