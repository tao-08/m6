<?php
/**
 * =====================================================================
 *  lib/itunes.php — iTunes Search API でアルバムを探す
 * =====================================================================
 *  iTunes Search API = Apple が公開している「iTunes Store のカタログを検索できる窓口（API）」。
 *    ・APIキー（利用登録）が要らない
 *    ・URL を叩くと JSON（機械が読みやすい文字列）で結果が返ってくる
 *    ・ただし邦楽でも、アルバム名がローマ字で登録されていることがある（例: 魚図鑑 → Sakana Zukan）。
 *      これは Apple のデータそのものの表記なので、lang=ja_jp などを付けても直らない
 *
 *  今は Spotify のキーが無いとき・Spotify が失敗したときの予備（lib/albums.php が切り替える）。
 *
 *  使う窓口は2つ:
 *    検索   https://itunes.apple.com/search?term=アルバム名&entity=album&country=jp
 *    ID指定 https://itunes.apple.com/lookup?id=1441164426&country=jp
 *
 *  ★ 「クライアント（ブラウザ）を信用するな」
 *    保存するとき、ブラウザからはアルバムの ID だけを受け取り、
 *    タイトルや画像URLはサーバーが lookup で取り直す（itunes_lookup_album）。
 *    → 画面のHTMLを書き換えて「変なサイトの画像URL」を送り込まれても保存されない。
 * =====================================================================
 */
declare(strict_types=1);

/** iTunes のアルバムID（collectionId）は数字だけ */
function itunes_valid_id(string $id): bool
{
    return (bool)preg_match('/\A[1-9][0-9]{0,19}\z/', $id);
}

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
    $res = album_http('https://itunes.apple.com/' . $endpoint . '?' . http_build_query($params));
    $results = $res['json']['results'] ?? null;
    if ($res === null || $res['status'] !== 200 || !is_array($results)) {
        return null;
    }
    return $results;
}

/**
 * iTunes の結果1件を、このサイトで使う形（lib/albums.php の説明を参照）にそろえる。
 * 使えないデータ（アルバムでない・画像がない・URLが怪しい）なら null。
 */
function itunes_normalize_album(array $r): ?array
{
    // lookup の結果にはアルバム以外（曲など）が混ざることがあるので、アルバムだけ通す
    if (($r['wrapperType'] ?? '') !== 'collection') {
        return null;
    }
    $id = (string)($r['collectionId'] ?? '');
    $title = trim((string)($r['collectionName'] ?? ''));
    $artist = trim((string)($r['artistName'] ?? ''));
    $art = (string)($r['artworkUrl100'] ?? '');
    if (!itunes_valid_id($id) || $title === '' || $art === '') {
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
        'source'       => 'itunes',
        'album_id'     => $id,
        // DB の列の長さを超えないように切る（mb_substr は日本語を文字単位で数える）
        'title'        => mb_substr($title, 0, 255),
        'artist_name'  => mb_substr($artist, 0, 255),
        'artwork_url'  => $art,
        'release_year' => ($year >= 1900 && $year <= 2100) ? $year : null,
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
        'country' => 'jp',    // 日本のストア（邦楽が見つかりやすい）
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
 * アーティストのアルバム一覧（Apple Music で「検索しても出てこない」アルバムを探すとき用。lib/albums.php の album_find_on）。
 *   Apple はアルバム名をローマ字で登録していることがあり（例: 東方永夜抄 → "Touhou Eiyasho - Imperishable Night. SoundTrack"）、
 *   日本語の名前で検索しても出てこない。アーティストの一覧からなら見つけられる。
 *   ① アーティストを名前で検索 → ② 名前が一致したアーティストの全アルバム（lookup は1回で200枚まで取れる）
 * 戻り値: アルバムの配列（アーティストが見つからなければ []）、通信失敗なら null
 */
function itunes_artist_albums(string $artistName): ?array
{
    // アーティストの ID を集める。2通りで探す:
    //   ・アーティスト検索で名前が一致したもの
    //   ・アルバム検索で、アルバムのアーティスト名が一致したもの
    //   アーティスト検索だけだと見つからないことがある。上海アリス幻樂団は、アーティスト検索では
    //   英語名 "Team Shanghai Alice" で返ってくるのに、アルバムには "上海アリス幻樂団" と書かれている（実際に確認済み）
    $key = album_match_key($artistName);
    $ids = [];
    foreach ([['entity' => 'musicArtist', 'limit' => 5], ['entity' => 'album', 'limit' => 25]] as $opt) {
        $results = itunes_request('search', ['term' => $artistName, 'country' => 'jp'] + $opt);
        if ($results === null) {
            return null;
        }
        foreach ($results as $r) {
            if (is_array($r) && album_match_key((string)($r['artistName'] ?? '')) === $key && (int)($r['artistId'] ?? 0) > 0) {
                $ids[(int)$r['artistId']] = true; // キーにして重複を消す
            }
        }
    }

    $albums = [];
    // 同じ名前の別人がいることがあるので、2人まで見る
    foreach (array_slice(array_keys($ids), 0, 2) as $artistId) {
        $results = itunes_request('lookup', ['id' => $artistId, 'entity' => 'album', 'country' => 'jp', 'limit' => 200]);
        if ($results === null) {
            return null;
        }
        // lookup の結果の先頭はアーティスト自身（wrapperType = artist）なので、normalize が捨ててくれる
        foreach ($results as $r) {
            $album = is_array($r) ? itunes_normalize_album($r) : null;
            if ($album !== null) {
                $albums[] = $album;
            }
        }
    }
    return $albums;
}

/**
 * アルバムID（collectionId）から1件を取り直す（保存するとき用）。
 * 見つからない・通信失敗なら null。
 */
function itunes_lookup_album(string $albumId): ?array
{
    if (!itunes_valid_id($albumId)) {
        return null;
    }
    $results = itunes_request('lookup', ['id' => $albumId, 'country' => 'jp']);
    foreach ($results ?? [] as $r) {
        $album = is_array($r) ? itunes_normalize_album($r) : null;
        if ($album !== null && $album['album_id'] === $albumId) {
            return $album;
        }
    }
    return null;
}

/* =====================================================================
 *  曲（トラック）— 曲の編集画面の🔍検索で使う（lib/tracks.php から呼ぶ）
 *    検索   https://itunes.apple.com/search?term=曲名&entity=song&country=jp
 *    ID指定 https://itunes.apple.com/lookup?id=曲ID&country=jp
 *    曲の ID（trackId）もアルバムと同じく数字だけなので、itunes_valid_id で確かめられる
 * ===================================================================== */

/** iTunes の曲1件をこのサイトの形にそろえる。曲でない・画像がない・URLが怪しいなら null */
function itunes_normalize_track(array $r): ?array
{
    if (($r['wrapperType'] ?? '') !== 'track' || ($r['kind'] ?? '') !== 'song') {
        return null; // ミュージックビデオなどは除く
    }
    $id = (string)($r['trackId'] ?? '');
    $title = trim((string)($r['trackName'] ?? ''));
    $art = (string)($r['artworkUrl100'] ?? '');
    if (!itunes_valid_id($id) || $title === '' || $art === '') {
        return null;
    }
    // 小さく出すので 200x200 で十分（高解像度の画面でもぼやけない大きさ）
    $art = preg_replace('/\d+x\d+bb/', '200x200bb', $art);
    $parts = parse_url($art);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !str_ends_with($host, '.mzstatic.com')) {
        return null; // Apple の画像サーバー以外の URL は受け付けない（アルバムと同じ）
    }
    $year = (int)substr((string)($r['releaseDate'] ?? ''), 0, 4);
    return [
        'source'       => 'itunes',
        'track_id'     => $id,
        'title'        => mb_substr($title, 0, 255),
        'artist_name'  => mb_substr(trim((string)($r['artistName'] ?? '')), 0, 255),
        'album_title'  => mb_substr(trim((string)($r['collectionName'] ?? '')), 0, 255),
        'artwork_url'  => $art,
        'release_year' => ($year >= 1900 && $year <= 2100) ? $year : null,
        'preview_url'  => itunes_preview_url((string)($r['previewUrl'] ?? '')), // 30秒試聴（band.php の ▶。無い曲もある）
    ];
}

/**
 * 試聴の音源 URL（previewUrl）。https で、Apple の配信サーバー（audio-ssl.itunes.apple.com など）のものだけ通す。
 *   <audio src> に入れるので、変なサイトの URL を鳴らさない（画像の URL と同じ考え方）。それ以外・空なら null
 */
function itunes_preview_url(string $url): ?string
{
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || strlen($url) > 500
        || !(str_ends_with($host, '.apple.com') || str_ends_with($host, '.mzstatic.com'))) {
        return null;
    }
    return $url;
}

/** 曲を検索する。正常なら曲の配列（0件なら []）、通信失敗なら null */
function itunes_search_tracks(string $term, int $limit = 10): ?array
{
    $results = itunes_request('search', ['term' => $term, 'entity' => 'song', 'country' => 'jp', 'limit' => $limit]);
    if ($results === null) {
        return null;
    }
    return array_values(array_filter(array_map(
        static fn($r) => is_array($r) ? itunes_normalize_track($r) : null,
        $results
    )));
}

/** 曲ID（trackId）から1件を取り直す（保存するとき用）。見つからない・通信失敗なら null */
function itunes_lookup_track(string $trackId): ?array
{
    if (!itunes_valid_id($trackId)) {
        return null;
    }
    foreach (itunes_request('lookup', ['id' => $trackId, 'country' => 'jp']) ?? [] as $r) {
        $track = is_array($r) ? itunes_normalize_track($r) : null;
        if ($track !== null && $track['track_id'] === $trackId) {
            return $track;
        }
    }
    return null;
}
