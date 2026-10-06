<?php
/**
 * =====================================================================
 *  lib/spotify.php — Spotify Web API でアルバムを探す
 * =====================================================================
 *  iTunes と違って、Spotify は「誰が使っているか」の登録（APIキー）が要る。
 *    1. https://developer.spotify.com/dashboard でアプリを作る
 *       ※ 2026年2月から、開発モードのアプリは「作った人が Spotify Premium に入っていること」が条件。
 *         Premium が切れると Spotify の検索は止まる（そのときは lib/albums.php が iTunes に切り替える）
 *    2. 表示された Client ID と Client secret を config.php の 'spotify' に書く
 *
 *  使う流れ（Client Credentials フロー = ユーザーのログイン無しで、アプリとして使う方式）:
 *    ① https://accounts.spotify.com/api/token に Client ID と secret を送る
 *       → 1時間だけ使える「アクセストークン」（通行証）がもらえる
 *    ② API を叩くときは、ヘッダーに Authorization: Bearer <トークン> を付ける
 *       検索   https://api.spotify.com/v1/search?q=アルバム名&type=album&market=JP
 *       ID指定 https://api.spotify.com/v1/albums/{id}?market=JP
 *
 *  トークンは毎回もらい直すと遅い & 叩きすぎになるので、ファイルに保存して1時間使い回す。
 *
 *  ★ Client secret は「パスワード」と同じ。config.php（git に入れない）にだけ書く。
 *    JavaScript やHTML に出したら、誰でも見られてしまうので絶対ダメ。
 * =====================================================================
 */
declare(strict_types=1);

/** どの国のカタログで探すか（日本で配信されているものに絞る） */
const SPOTIFY_MARKET = 'JP';

/** config.php に Spotify のキーが書いてあるか */
function spotify_enabled(): bool
{
    $c = config('spotify');
    return is_array($c) && ($c['client_id'] ?? '') !== '' && ($c['client_secret'] ?? '') !== '';
}

/** Spotify のアルバムIDは英数字22文字（例: 4aawyAB9vmqN3uQ7FjRGTy） */
function spotify_valid_id(string $id): bool
{
    // \A と \z は「文字列の先頭」と「本当の末尾」。$ だと末尾の改行を見逃すので \z を使う
    return (bool)preg_match('/\A[0-9A-Za-z]{22}\z/', $id);
}

/**
 * アクセストークンを返す。保存してあって、まだ1分以上使えるならそれを使う。
 * $refresh = true なら、保存してあるものを無視してもらい直す。失敗したら null。
 */
function spotify_token(bool $refresh = false): ?string
{
    $conf = config('spotify');
    // 保存先はサーバーの一時フォルダ（公開フォルダの中に置くと、URL を叩かれて盗まれる）
    $path = sys_get_temp_dir() . '/abbey_spotify_token_' . md5($conf['client_id']) . '.json';

    if (!$refresh && is_file($path)) {
        $saved = json_decode((string)@file_get_contents($path), true);
        if (is_string($saved['access_token'] ?? null) && (int)($saved['expires_at'] ?? 0) > time() + 60) {
            return $saved['access_token'];
        }
    }

    // Basic 認証: "ID:secret" を base64 という形式に変換してヘッダーに入れる（Spotify の決まり）
    $res = album_http(
        'https://accounts.spotify.com/api/token',
        [
            'Authorization: Basic ' . base64_encode($conf['client_id'] . ':' . $conf['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'grant_type=client_credentials'
    );
    $token = $res['json']['access_token'] ?? null;
    if ($res === null || $res['status'] !== 200 || !is_string($token)) {
        return null; // キーが間違っている・Premium が切れている・Spotify が落ちている など
    }

    $expiresAt = time() + (int)($res['json']['expires_in'] ?? 3600);
    // LOCK_EX: 書いている途中に別のアクセスが同じファイルを書かないようにする
    @file_put_contents($path, json_encode(['access_token' => $token, 'expires_at' => $expiresAt]), LOCK_EX);
    @chmod($path, 0600); // 自分（PHP）以外は読めないようにする（共用サーバー対策）
    return $token;
}

/**
 * Spotify の API を叩いて、返ってきた JSON（配列）を返す。失敗したら null。
 *   $path   'search' や 'albums/xxxx'
 *   $params URL の ? 以降に付ける値
 */
function spotify_request(string $path, array $params): ?array
{
    $url = 'https://api.spotify.com/v1/' . $path . '?' . http_build_query($params);
    // 1回目は保存してあるトークンで。401（トークン切れ）が返ってきたら、もらい直して1回だけやり直す
    foreach ([false, true] as $refresh) {
        $token = spotify_token($refresh);
        if ($token === null) {
            return null;
        }
        // Accept-Language: ja … アーティスト名などを日本語の表記で返してもらう
        $res = album_http($url, ['Authorization: Bearer ' . $token, 'Accept-Language: ja']);
        if ($res === null) {
            return null;
        }
        if ($res['status'] === 401) {
            continue;
        }
        return $res['status'] === 200 && is_array($res['json']) ? $res['json'] : null;
    }
    return null;
}

/**
 * Spotify のアルバム1件を、このサイトで使う形（lib/albums.php の説明を参照）にそろえる。
 * 使えないデータ（ID がおかしい・画像がない・URLが怪しい）なら null。
 */
function spotify_normalize_album(array $a): ?array
{
    $id = (string)($a['id'] ?? '');
    $title = trim((string)($a['name'] ?? ''));
    // アーティストは複数いることがある（コラボ作品など）ので「, 」でつなぐ
    $artists = array_filter(array_map(
        static fn($artist) => is_array($artist) ? trim((string)($artist['name'] ?? '')) : '',
        is_array($a['artists'] ?? null) ? $a['artists'] : []
    ));
    // images は大きい順に並んでいる（640px, 300px, 64px）。一番大きいものを使う
    $art = (string)($a['images'][0]['url'] ?? '');
    if (!spotify_valid_id($id) || $title === '' || $art === '') {
        return null;
    }

    // 念のため「Spotify の画像サーバー（i.scdn.co）の https URL」以外は受け付けない（多重の防御）
    $parts = parse_url($art);
    if (($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'i.scdn.co') {
        return null;
    }

    // release_date は "1969-09-26" や "1969"（年しか分からない作品）。先頭4文字が年
    $year = (int)substr((string)($a['release_date'] ?? ''), 0, 4);

    return [
        'source'       => 'spotify',
        'album_id'     => $id,
        // DB の列の長さを超えないように切る（mb_substr は日本語を文字単位で数える）
        'title'        => mb_substr($title, 0, 255),
        'artist_name'  => mb_substr(implode(', ', $artists), 0, 255),
        'artwork_url'  => $art,
        'release_year' => ($year >= 1900 && $year <= 2100) ? $year : null,
    ];
}

/**
 * アルバムを検索する。
 * 戻り値: 正常なら アルバムの配列（0件なら []）、失敗なら null
 *   ※ 開発モードのアプリは1回の検索で最大10件まで（2026年2月の仕様変更）
 */
function spotify_search_albums(string $term, int $limit = 10): ?array
{
    $json = spotify_request('search', [
        'q'      => $term,
        'type'   => 'album', // アルバムだけ探す（曲やアーティストを除外）
        'market' => SPOTIFY_MARKET,
        'limit'  => $limit,
    ]);
    $items = $json['albums']['items'] ?? null;
    if (!is_array($items)) {
        return null;
    }
    // 結果に null が混ざることがあるので、配列のものだけ整形する
    $albums = array_map(static fn($a) => is_array($a) ? spotify_normalize_album($a) : null, $items);
    return array_values(array_filter($albums));
}

/** アルバムID から1件を取り直す（保存するとき用）。見つからない・失敗なら null */
function spotify_lookup_album(string $albumId): ?array
{
    if (!spotify_valid_id($albumId)) {
        return null;
    }
    $json = spotify_request('albums/' . $albumId, ['market' => SPOTIFY_MARKET]);
    $album = is_array($json) ? spotify_normalize_album($json) : null;
    return ($album !== null && $album['album_id'] === $albumId) ? $album : null;
}
