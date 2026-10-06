<?php
/**
 * =====================================================================
 *  lib/albums.php — 「好きなアルバム」の検索・取得の入口
 * =====================================================================
 *  アルバムの情報は外部のサービスから取ってくる。今使えるのは2つ:
 *    spotify … lib/spotify.php。日本語の表記がきれい。ただし config.php にキーが要る
 *    itunes  … lib/itunes.php。キー不要。邦楽のアルバム名がローマ字のことがある
 *
 *  どちらを使うかはここで決める（呼び出す側は気にしなくていい）:
 *    Spotify のキーがある → Spotify で探す。Spotify が失敗したら iTunes で探し直す
 *    キーが無い           → iTunes で探す
 *  → Spotify が止まっても（Premium が切れた等）、検索ごと使えなくなることはない
 *
 *  どのサービスから取ったアルバムも、次の同じ形にそろえて返す:
 *    ['source' => 'spotify', 'album_id' => '4aawyAB9vmqN3uQ7FjRGTy', 'title' => '...',
 *     'artist_name' => '...', 'artwork_url' => 'https://...', 'release_year' => 1969 または null]
 *
 *  1枚のアルバムは「source + album_id」で決まる（iTunes と Spotify で ID の形が違うため）。
 *  HTML やフォームでは "spotify:4aawyAB9vmqN3uQ7FjRGTy" のように : でつないだ1つの文字列（キー）で扱う。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/itunes.php';
require_once __DIR__ . '/spotify.php';

/** 1人が登録できる「好きなアルバム」の上限枚数 */
const FAVORITE_ALBUM_LIMIT = 30;

/** 通信の待ち時間の上限（秒）。外部サービスが遅いときにページごと固まるのを防ぐ */
const ALBUM_HTTP_TIMEOUT_SEC = 5;

/**
 * 外部の API に HTTP で問い合わせて、返ってきた JSON を配列にして返す。
 *   $postBody を渡すと POST、渡さなければ GET。
 *   戻り値: ['status' => 200, 'json' => [...]]。通信そのものに失敗したら null
 *
 * @param string[] $headers 追加のヘッダー（'Authorization: Bearer xxx' など）
 */
function album_http(string $url, array $headers = [], ?string $postBody = null): ?array
{
    $headers[] = 'User-Agent: AbbeyRoad.online'; // 「誰が叩いているか」の名乗り（マナー）

    // レンタルサーバーによって「cURL は使えるが file_get_contents で URL は開けない」
    // （allow_url_fopen = Off）ことがあるので、cURL があれば cURL を優先して使う。
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,                   // 結果を画面に出さず、戻り値として受け取る
            CURLOPT_TIMEOUT        => ALBUM_HTTP_TIMEOUT_SEC, // 全体の待ち時間の上限
            CURLOPT_CONNECTTIMEOUT => 3,                      // 接続するまでの待ち時間の上限
            CURLOPT_FOLLOWLOCATION => true,                   // リダイレクトされたら付いていく
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($postBody !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postBody;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // ※ curl_close() は PHP 8.0 から何もしない関数になり、PHP 8.5 で非推奨。
        //   変数が使われなくなった時点で自動で閉じられるので、呼ばない。
        if ($body === false) {
            return null;
        }
    } else {
        // stream_context_create: file_get_contents に「通信の設定」を渡すための入れ物
        $http = [
            'timeout'       => ALBUM_HTTP_TIMEOUT_SEC,
            'ignore_errors' => true, // 404 などでも警告を出さずに中身を返させる
            'header'        => implode("\r\n", $headers) . "\r\n",
        ];
        if ($postBody !== null) {
            $http['method'] = 'POST';
            $http['content'] = $postBody;
        }
        // @ は「警告を画面に出さない」記号。失敗は戻り値 false で判断する。
        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        if ($body === false) {
            return null;
        }
        // $http_response_header: file_get_contents が自動で作る変数。[0] が "HTTP/1.1 200 OK" の行
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
        $status = (int)($m[1] ?? 0);
    }

    // json_decode(文字列, true): JSON → PHP の連想配列。壊れた JSON なら null になる
    return ['status' => $status, 'json' => json_decode($body, true)];
}

/** source と album_id を1つの文字列（キー）にする。例: "spotify:4aawyAB9vmqN3uQ7FjRGTy" */
function album_key(string $source, string $albumId): string
{
    return $source . ':' . $albumId;
}

/**
 * フォームや JS から送られてきたキーを [source, album_id] に分ける。
 * 形がおかしければ null（ブラウザから来た値は信用しない）。
 */
function album_parse_key(mixed $key): ?array
{
    if (!is_string($key)) {
        return null;
    }
    // explode(区切り, 文字列, 2): 最初の : で2つに分ける
    $parts = explode(':', $key, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$source, $albumId] = $parts;
    $ok = match ($source) {
        'spotify' => spotify_valid_id($albumId),
        'itunes'  => itunes_valid_id($albumId),
        default   => false,
    };
    return $ok ? [$source, $albumId] : null;
}

/**
 * アルバムを検索する。
 * 戻り値: ['albums' => アルバムの配列（0件なら []。全部失敗したら null）,
 *          'source' => 実際に使ったサービス, 'fell_back' => Spotify が失敗して iTunes に切り替えたか]
 */
function album_search(string $term): array
{
    if (spotify_enabled()) {
        $albums = spotify_search_albums($term);
        if ($albums !== null) {
            return ['albums' => $albums, 'source' => 'spotify', 'fell_back' => false];
        }
    }
    return ['albums' => itunes_search_albums($term), 'source' => 'itunes', 'fell_back' => spotify_enabled()];
}

/**
 * source と album_id からアルバム1件を取り直す（保存するとき用）。
 * 見つからない・通信失敗なら null。
 */
function album_lookup(string $source, string $albumId): ?array
{
    return match ($source) {
        'spotify' => spotify_lookup_album($albumId),
        'itunes'  => itunes_lookup_album($albumId),
        default   => null,
    };
}

/** そのアルバムのページ（Spotify / Apple Music）の URL。表示するアルバムからリンクするため */
function album_page_url(string $source, string $albumId): string
{
    return match ($source) {
        'spotify' => 'https://open.spotify.com/album/' . rawurlencode($albumId),
        default   => 'https://music.apple.com/jp/album/' . rawurlencode($albumId),
    };
}
