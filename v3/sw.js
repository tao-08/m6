/**
 * =====================================================================
 *  sw.js — Service Worker（ホーム画面に追加したときの「アプリ」の裏方）
 * =====================================================================
 *  やることは1つだけ: 電波が無くてページが開けないとき、offline.html を代わりに出す。
 *
 *  ページ（PHP）はキャッシュしない。
 *    - ログインした人ごとに中身が違う → 前の人の画面が出たら事故
 *    - フォームの CSRF トークンが古いまま残ると、送信が全部はじかれる
 *  だから「いつもネットから取る。取れなかったときだけ offline.html」。
 *
 *  このファイルを変えたら CACHE の数字を上げる（古いキャッシュを消すため）。
 *  assets/app.js の setupServiceWorker() から登録している。
 * =====================================================================
 */
const CACHE = 'abbey-offline-v1';
const OFFLINE_URL = 'offline.html';

// 入れたとき: offline.html だけ先に保存しておく
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE_URL)));
  self.skipWaiting();
});

// 新しい版になったとき: 古い版のキャッシュを消す
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  // ページを開く（GET）ときだけ見る。フォームの送信（POST）・画像・CSS・fetch() には一切さわらない
  if (req.mode !== 'navigate' || req.method !== 'GET') {
    return;
  }
  event.respondWith(
    fetch(req).catch(() => caches.match(OFFLINE_URL))
  );
});
