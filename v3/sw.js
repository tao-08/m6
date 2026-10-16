/**
 * =====================================================================
 *  sw.js — Service Worker（ホーム画面に追加したときの「アプリ」の裏方）
 * =====================================================================
 *  やること:
 *    1. 電波が無くてページが開けないとき、offline.html を代わりに出す。
 *    2. プッシュ通知（お知らせ）を受け取って、OS の通知として出す。押したらそのページを開く。
 *       送る側は lib/push.php。中身は {title, body, url, tag} の JSON。
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
const CACHE = 'abbey-offline-v3';
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

// プッシュ通知が届いたとき。iOS / Chrome は「届いたら必ず通知を出す」決まりなので、中身が読めなくても出す
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = {};
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'AbbeyRoad', {
      body: data.body || '',
      tag: data.tag || undefined, // 同じ tag は上書き（同じお知らせが重ならない）
      icon: 'assets/favicons/app-192.png',
      badge: 'assets/favicons/badge-96.png', // Android のステータスバー用。透過の単色でないと白い四角になる
      data: { url: data.url || './' },
    })
  );
});

// 通知を押したとき: 開いているウィンドウがあればそれを使い、無ければ新しく開く
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  // 行き先はこのサイトの中だけ。ほかのオリジンが指定されても、トップに戻す
  let target = new URL('./', self.registration.scope);
  try {
    const u = new URL(event.notification.data?.url || './', self.registration.scope);
    if (u.origin === target.origin) target = u;
  } catch (e) { /* 壊れた URL はトップへ */ }
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      const win = wins.find((w) => w.url.startsWith(self.registration.scope));
      if (win) return win.focus().then(() => win.navigate(target.href));
      return self.clients.openWindow(target.href);
    })
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
