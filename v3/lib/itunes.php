<?php
/**
 * =====================================================================
 *  lib/itunes.php — iTunes Search API でアルバムを探す
 * =====================================================================
 *  iTunes Search API = Apple が公開している「iTunes Store のカタログを検索できる窓口（API）」。
 *    ・APIキー（利用登録）が要らない
 *    ・URL を叩くと JSON（機械が読みやすい文字列）で結果が返ってくる
 *
 *  使う窓口は2つ:
 *    検索   https://itunes.apple.com/search?term=アルバム名&entity=album&country=jp
 *    ID指定 https://itunes.apple.com/lookup?id=1441164426&country=jp
 *
 *  ★ 「クライアント（ブラウザ）を信用するな」
 *    保存するとき、ブラウザからは collectionId（アルバムの番号）だけを受け取り、
 *    タイトルや画像URLはサーバーが lookup で取り直す（itunes_lookup_album）。
 *    → 画面のHTMLを書き換えて「変なサイトの画像URL」を送り込まれても保存されない。
 *
 *  ※ もしかしたら Apple 側の仕様変更や停止で動かなくなる可能性がある（外部サービス依存）。
 *    そのときはこのファイルだけ直せば済むように、iTunes とのやり取りはここに閉じ込めてある。
 * =====================================================================
 */
declare(strict_types=1);

/** 1人が登録できる「好きなアルバム」の上限枚数（member.php と member_album_save.php で使う） */
const FAVORITE_ALBUM_LIMIT = 30;

/** 通信の待ち時間の上限（秒）。iTunes が遅いときにページごと固まるのを防ぐ */
const ITUNES_TIMEOUT_SEC = 5;

/**
 * iTunes の API を叩いて、返ってきた JSON の results 部分（配列）を返す。
 * 通信に失敗したら null（呼び出し側で「今は検索できません」と出す）。
 *
 * @param string $endpoint 'search' か 'lookup'
 * @param array  $params   URL の ? 以降に付ける値（['term' => 'abbey road', ...]）
 */
function itunes_request(string $endpoint, array $params): ?array
{
    // http_build_query: 配列 → "term=abbey+road&entity=album" 形式の文字列。
    //   日本語や記号も自動でエンコード（URL で使える文字に変換）してくれる。
    $url = 'https://itunes.apple.com/' . $endpoint . '?' . http_build_query($params);

    // レンタルサーバーによって「cURL は使えるが file_get_contents で URL は開けない」
    // （allow_url_fopen = Off）ことがあるので、cURL があれば cURL を優先して使う。
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,               // 結果を画面に出さず、戻り値として受け取る
            CURLOPT_TIMEOUT        => ITUNES_TIMEOUT_SEC, // 全体の待ち時間の上限
            CURLOPT_CONNECTTIMEOUT => 3,                  // 接続するまでの待ち時間の上限
            CURLOPT_FOLLOWLOCATION => true,               // リダイレクトされたら付いていく
            CURLOPT_USERAGENT      => 'AbbeyRoad.online',  // 「誰が叩いているか」の名乗り（マナー）
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // ※ curl_close() は PHP 8.0 から何もしない関数になり、PHP 8.5 で非推奨。
        //   変数が使われなくなった時点で自動で閉じられるので、呼ばない。
        if ($body === false || $status !== 200) {
            return null;
        }
    } else {
        // stream_context_create: file_get_contents に「通信の設定」を渡すための入れ物
        $context = stream_context_create(['http' => [
            'timeout'       => ITUNES_TIMEOUT_SEC,
            'ignore_errors' => true, // 404 などでも警告を出さずに中身を返させる
            'header'        => "User-Agent: AbbeyRoad.online\r\n",
        ]]);
        // @ は「警告を画面に出さない」記号。失敗は戻り値 false で判断する。
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
    }

    // json_decode(文字列, true): JSON → PHP の連想配列。壊れた JSON なら null になる
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
        return null;
    }
    return $json['results'];
}

/**
 * iTunes の結果1件を、このサイトで使う形にそろえる。
 * 使えないデータ（アルバムでない・画像がない・URLが怪しい）なら null。
 *
 * 返す形: ['collection_id' => 123, 'title' => '...', 'artist_name' => '...',
 *          'artwork_url' => 'https://...600x600bb.jpg', 'release_year' => 1969 または null]
 */
function itunes_normalize_album(array $r): ?array
{
    // lookup の結果にはアルバム以外（曲など）が混ざることがあるので、アルバムだけ通す
    if (($r['wrapperType'] ?? '') !== 'collection') {
        return null;
    }
    $id = (int)($r['collectionId'] ?? 0);
    $title = trim((string)($r['collectionName'] ?? ''));
    $artist = trim((string)($r['artistName'] ?? ''));
    $art = (string)($r['artworkUrl100'] ?? '');
    if ($id <= 0 || $title === '' || $art === '') {
        return null;
    }

    // 画像URLの末尾 ".../100x100bb.jpg" の数字を 600x600 に書き換えると高画質版になる（定番の小技）
    //   preg_replace(正規表現, 置き換え後, 対象): パターンに合う部分を置き換える
    //   \d+ は「数字1文字以上」。100x100bb → 600x600bb
    $art = preg_replace('/\d+x\d+bb/', '600x600bb', $art);

    // 念のため「Apple の画像サーバー（*.mzstatic.com）の https URL」以外は受け付けない（多重の防御）
    //   parse_url: URL を scheme / host / path などに分解する
    $parts = parse_url($art);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !str_ends_with($host, '.mzstatic.com')) {
        return null;
    }

    // releaseDate は "1969-09-26T07:00:00Z" のような文字列。先頭4文字が年
    $year = (int)substr((string)($r['releaseDate'] ?? ''), 0, 4);

    return [
        'collection_id' => $id,
        // DB の列の長さを超えないように切る（mb_substr は日本語を文字単位で数える）
        'title'         => mb_substr($title, 0, 255),
        'artist_name'   => mb_substr($artist, 0, 255),
        'artwork_url'   => $art,
        'release_year'  => ($year >= 1900 && $year <= 2100) ? $year : null,
    ];
}

/**
 * アルバムを検索する。
 * 戻り値: 正常なら アルバムの配列（0件なら []）、通信失敗なら null
 */
function itunes_search_albums(string $term, int $limit = 12): ?array
{
    $results = itunes_request('search', [
        'term'    => $term,
        'entity'  => 'album', // アルバムだけ探す（曲やアプリを除外）
        'country' => 'jp',    // 日本のストア（邦楽が見つかりやすく、表記も日本語）
        'limit'   => $limit,
    ]);
    if ($results === null) {
        return null;
    }
    // array_map で1件ずつ整形 → array_filter で null（使えない結果）を捨てる
    // → array_values で番号を 0,1,2... に振り直す
    return array_values(array_filter(array_map('itunes_normalize_album', $results)));
}

/**
 * collectionId からアルバム1件を取り直す（保存するとき用）。
 * 見つからない・通信失敗なら null。
 */
function itunes_lookup_album(int $collectionId): ?array
{
    $results = itunes_request('lookup', ['id' => $collectionId, 'country' => 'jp']);
    foreach ($results ?? [] as $r) {
        $album = itunes_normalize_album($r);
        if ($album !== null && $album['collection_id'] === $collectionId) {
            return $album;
        }
    }
    return null;
}
