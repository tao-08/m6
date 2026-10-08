<?php
/**
 * =====================================================================
 *  album_go.php — マイアルバムを「見ている人の音楽アプリ」で開く（リダイレクトするだけ）
 * =====================================================================
 *  member.php のマイアルバムのリンクのうち、Spotify ⇔ Apple Music をまたぐもの
 *  （例: Spotify から登録したアルバムを、Apple Music を使っている人が押した）がここに来る。
 *
 *    1. album_link_cache に「前に探した結果」があれば、それへ
 *    2. 無ければ、そのアプリ（Spotify / iTunes）で検索して、同じアルバムらしい一番上の結果へ（lib/albums.php の album_find_on）
 *       結果は album_link_cache に覚えておく → 次からは member.php が直接そのURLを出すので、ここに来なくなる
 *    3. 見つからなければ、そのアプリの検索ページへ
 *
 *  受け取る値（GET）: album … "spotify:xxxx" の形のキー
 *
 *  ★ リダイレクト先は、ここで組み立てた URL（Spotify / Apple Music のドメイン）だけ。
 *    URL そのものを受け取って飛ばす作りにすると、「このサイトのリンクに見せかけて、悪いサイトへ飛ばす」
 *    踏み台に使われてしまう（オープンリダイレクト）ので、そうしていない。
 *  ★ アルバム名・アーティスト名も URL からは受け取らず、DB から読む。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/albums.php';
$user = require_login();

$key = album_parse_key($_GET['album'] ?? null);
if ($key === null) {
    http_response_code(400);
    exit('アルバムの指定がおかしいです');
}
[$source, $albumId] = $key;

$pdo = db();
// 誰かのマイアルバムに入っているアルバムだけ（でたらめな ID で外部 API を叩かせないため）
//   release_year は、検索に出てこないアルバムを「アーティストのアルバム一覧」から探すときに使う（発売年で絞る）
$st = $pdo->prepare('SELECT source, album_id, title, artist_name, release_year FROM member_favorite_album
    WHERE source = ? AND album_id = ? LIMIT 1');
$st->execute([$source, $albumId]);
$album = $st->fetch();
if (!$album) {
    http_response_code(404);
    exit('アルバムが見つかりません');
}

$app = member_music_app($pdo, $user['member_id']);
$found = false; // false = まだ調べていない（album_listen_url の $cached と同じ決まり）

if ($app === 'spotify' || $app === 'apple_music') {
    // 「見つからなかった」（url が NULL）は30日で期限切れ → 探し直す（後からそのアプリで配信が始まることがあるため）
    $st = $pdo->prepare('SELECT url FROM album_link_cache WHERE source = ? AND album_id = ? AND app = ?
        AND (url IS NOT NULL OR checked_at > NOW() - INTERVAL ' . ALBUM_NOT_FOUND_RETRY_DAYS . ' DAY)');
    $st->execute([$source, $albumId, $app]);
    $row = $st->fetch();
    if ($row) {
        $found = $row['url']; // 前に探した結果（見つからなかったなら null）
    } else {
        $found = album_find_on($app, $album);
        if ($found !== false) {
            // 見つかった / 見つからなかった を覚えておく。
            //   ON DUPLICATE KEY UPDATE: 同時に2人が押して、もう行があったら上書きする（エラーにしない）
            $pdo->prepare('INSERT INTO album_link_cache (source, album_id, app, url) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE url = VALUES(url), checked_at = CURRENT_TIMESTAMP')
                ->execute([$source, $albumId, $app, $found]);
        } else {
            $found = null; // 検索できなかった（通信エラーなど）。覚えずに、今回は検索ページへ
        }
    }
}

// $found が URL ならそれへ、null なら検索ページへ。アプリを選んでいない人などは登録元のページへ
//   （ここで false を渡すと album_go.php へのリンクが返ってきて堂々巡りになるので、上で必ず URL か null にしている）
$url = album_listen_url($app, $album, $found);
// ?json=1 … スマホの app.js（setupSpotifyAppLinks）が飛び先だけを聞きに来る。Spotify ならアプリ用の URL に置き換えて開くため
if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['url' => $url], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
redirect($url);
