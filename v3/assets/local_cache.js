/**
 * =====================================================================
 *  local_cache.js — 端末の中の DB（IndexedDB）に、サーバーのデータを保存しておく部品
 * =====================================================================
 *  ■ なぜ必要？
 *    ページを開くたびにサーバーが DB を検索して HTML を作ると、その待ち時間が毎回かかる。
 *    前回のデータを端末に保存しておけば、まずそれを使って画面を出し、
 *    裏で「サーバーのデータは変わった？」と確認するだけで済む。
 *
 *  ■ このパターンの名前: stale-while-revalidate（SWR / 古いものを先に出し、裏で更新確認）
 *    Web の業界標準のキャッシュ戦略。Service Worker・SWR(React)・TanStack Query も同じ考え方。
 *      1. 端末に保存済みのデータがある → すぐ返す（stale = 少し古いかもしれない）
 *      2. 同時に、サーバーへ「最新ですか？」と聞く（revalidate = 再検証）
 *      3. 変わっていれば、新しいデータを保存して「更新あり」と知らせる
 *
 *  ■ 使うもの
 *    IndexedDB … ブラウザに入っている、端末内のデータベース（キー → 値の形）。
 *                localStorage（5MB・同期処理で画面が止まる）より大きく、非同期なので画面を止めない。
 *    ETag      … サーバーがデータにつける「指紋」。次の問い合わせで If-None-Match として送ると、
 *                同じなら 304（本文なし）が返る。通信量もほぼゼロ。
 *
 *  ■ 使い方（assets/live_list.js が例）
 *    const result = await LocalCache.load('lives', 'api_lives.php');
 *    result.data        … 使うデータ
 *    result.fromCache   … true なら端末の保存データ（false ならサーバーから取ったばかり）
 *    result.revalidate  … 裏の確認（Promise）。fromCache のときだけ入る。{changed: true/false} を返す
 *
 *  ■ 安全面
 *    ・ログインしていないページ（ログイン画面など）を開いたら、保存データを全部消す
 *      → 共用のパソコンで、ログアウトした後に前の人のデータが残らない
 *    ・API が 401（ログイン切れ）を返したときも消す
 *    ・IndexedDB が使えない（プライベートモードなど）ときは、保存せずに普通に通信するだけ（壊れない）
 * =====================================================================
 */
(function () {
  'use strict';

  const DB_NAME = 'abbey-local';   // 端末の中の DB の名前
  const DB_VERSION = 1;            // DB の作りを変えたら上げる（onupgradeneeded が走る）
  const STORE = 'snapshots';       // 「テーブル」にあたる入れ物の名前（IndexedDB では object store と呼ぶ）

  /* -------------------------------------------------------------------
   * IndexedDB の下回り
   *   IndexedDB は古い API なので、コールバック式の操作を Promise に包んで使いやすくしている
   * ----------------------------------------------------------------- */
  let dbPromise = null; // 一度開いたら使い回す（毎回開くと遅い）

  function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) {
        reject(new Error('IndexedDB unavailable'));
        return;
      }
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      // 初めて作るとき（または DB_VERSION を上げたとき）だけ呼ばれる。入れ物はここでしか作れない
      req.onupgradeneeded = () => {
        if (!req.result.objectStoreNames.contains(STORE)) {
          req.result.createObjectStore(STORE, { keyPath: 'key' }); // keyPath: 値の中の 'key' を主キーにする
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    // 開けなかったとき、次回また試せるよう記憶を捨てる
    dbPromise.catch(() => { dbPromise = null; });
    return dbPromise;
  }

  /** 1つの操作（読む・書く・消す）を Promise にして実行する。mode: 'readonly' | 'readwrite' */
  async function run(mode, action) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode); // トランザクション = 「全部成功か、全部なかったことか」のひとかたまり
      const req = action(tx.objectStore(STORE));
      tx.oncomplete = () => resolve(req ? req.result : undefined);
      tx.onerror = () => reject(tx.error);
      tx.onabort = () => reject(tx.error);
    });
  }

  const get = (key) => run('readonly', (s) => s.get(key));
  const put = (record) => run('readwrite', (s) => s.put(record));
  const remove = (key) => run('readwrite', (s) => s.delete(key));
  const clearAll = () => run('readwrite', (s) => s.clear()).catch(() => { /* 消せなくても困らない */ });

  /* -------------------------------------------------------------------
   * サーバーへの問い合わせ
   * ----------------------------------------------------------------- */
  /**
   * API を呼ぶ。etag があれば If-None-Match として送る（条件付きリクエスト）。
   * 戻り値: { notModified: true }  … 304。前回と同じ
   *         { notModified: false, json, etag } … 200。新しいデータ
   */
  async function fetchSnapshot(url, etag) {
    const headers = { Accept: 'application/json' };
    if (etag) headers['If-None-Match'] = etag;
    const res = await fetch(url, {
      headers,
      credentials: 'same-origin', // Cookie（ログイン状態）を送る
      cache: 'no-cache',          // ブラウザ自身の HTTP キャッシュも、使う前にサーバーへ確認させる
    });
    if (res.status === 304) return { notModified: true };
    if (res.status === 401) {
      // ログイン切れ。端末に残っているデータは消す（次にログインした人に見せないため）
      await clearAll();
      throw new Error('unauthorized');
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return { notModified: false, json: await res.json(), etag: res.headers.get('ETag') };
  }

  /** 端末に保存する（失敗しても、画面は出せるので握りつぶす） */
  async function save(key, json, etag) {
    try {
      await put({ key, schema: json.schema, etag, savedAt: Date.now(), data: json });
    } catch { /* 容量オーバー・プライベートモードなど。保存できないだけ */ }
  }

  /* -------------------------------------------------------------------
   * load: stale-while-revalidate の本体
   * ----------------------------------------------------------------- */
  async function load(key, url) {
    // 1. 端末の保存データを探す（読めなければ「無い」扱い）
    let record = null;
    try { record = (await get(key)) || null; } catch { /* IndexedDB が使えない */ }

    // 2a. ある → すぐ返す。確認は裏で（await しない）
    if (record && record.data) {
      const revalidate = fetchSnapshot(url, record.etag).then(async (r) => {
        if (r.notModified) return { changed: false };
        // 形（schema）が違うデータは、保存だけして「変わった」と知らせる（画面は次回から新しい形になる）
        await save(key, r.json, r.etag);
        return { changed: r.etag !== record.etag, data: r.json };
      });
      return { data: record.data, fromCache: true, revalidate };
    }

    // 2b. 無い（初回・消された・schema が古い）→ サーバーから取って、保存してから返す
    const r = await fetchSnapshot(url, null);
    await save(key, r.json, r.etag);
    return { data: r.json, fromCache: false, revalidate: null };
  }

  /**
   * schema（JSON の形のバージョン）が合わない保存データを捨てる。
   * 呼び出し側が「今のコードが読める schema」を渡す。api_lives.php の 'schema' と同じ数字にすること。
   */
  async function dropIfSchemaDiffers(key, schema) {
    try {
      const record = await get(key);
      if (record && record.schema !== schema) await remove(key);
    } catch { /* 無視 */ }
  }

  // ---- ログインしていないページを開いたら、保存データを全部消す ----
  //   <meta name="page-title"> はログインしているページにだけある（partials/header.php）
  //   なので、無ければ「ログアウト済み」とみなせる
  function clearIfLoggedOut() {
    if (!document.querySelector('meta[name="page-title"]')) clearAll();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', clearIfLoggedOut);
  } else {
    clearIfLoggedOut();
  }

  // 他のスクリプトから使えるように window に出す
  window.LocalCache = { load, get, remove, clearAll, dropIfSchemaDiffers };
})();
