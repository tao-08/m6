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

/* =====================================================================
 *  「見ている人の音楽アプリ」でアルバムを開く
 * ---------------------------------------------------------------------
 *  プロフィール編集で選んだアプリ（member.music_app）で、マイアルバムのリンクを開く。
 *
 *    アプリ \ 登録元     spotify                      itunes
 *    Spotify             アルバムのページ（直接）     Spotify で検索した一番上へ（album_go.php 経由）
 *    Apple Music         iTunes で検索した一番上へ    アルバムのページ（直接）
 *    YouTube Music       YouTube Music の検索ページ   同左
 *    LINE MUSIC          LINE MUSIC の検索ページ      同左
 *    選んでいない        登録元のページ               同左
 *
 *  「一番上へ」は、ページを表示するときではなく、リンクが押されたときに album_go.php が検索する
 *  （30枚ぶんを毎回検索するとページが遅くなるため）。結果は album_link_cache に覚えておく。
 *  YouTube Music・LINE MUSIC は誰でも使える検索APIが無いので、検索ページを開く。
 * ===================================================================== */

/** 「別のアプリで見つからなかった」という覚え書きを、何日で捨てて探し直すか（後から配信が始まることがあるため） */
const ALBUM_NOT_FOUND_RETRY_DAYS = 30;

/** 選べる音楽アプリ。キーは DB に保存する値、値は画面に出す名前 */
const MUSIC_APPS = [
    'spotify'       => 'Spotify',
    'apple_music'   => 'Apple Music',
    'youtube_music' => 'YouTube Music',
    'line_music'    => 'LINE MUSIC',
];

/**
 * 音楽アプリのアイコン（24×24 の SVG）。CSS で color を付けると、その色で塗られる（fill="currentColor"）。
 *   Spotify / Apple Music / YouTube Music は公式ロゴ（Simple Icons https://simpleicons.org/ 、CC0 で配布されている形）。
 *   LINE MUSIC は Simple Icons に無いので、公式サイトからダウンロードしたロゴ画像（PNG）を、同じ形の SVG に描き直したもの。
 * 自分で書いた固定の文字列なので、出力するときに h() は通さない（ユーザーの入力は混ざらない）。
 */
const MUSIC_APP_ICONS = [
    'spotify' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>',
    'apple_music' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><defs><linearGradient id="am-grad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fb5c74"/><stop offset="1" stop-color="#fa233b"/></linearGradient></defs><rect width="24" height="24" rx="5.4" fill="url(#am-grad)"/><path fill="#ffe1e7" d="M17.571 10.114v5.712c0 .417-.058.827-.244 1.206-.29.59-.76.962-1.388 1.14-.35.1-.706.157-1.07.173-.95.045-1.773-.6-1.943-1.536a1.88 1.88 0 011.038-2.022c.323-.16.67-.25 1.018-.324.378-.082.758-.153 1.134-.24.274-.063.457-.23.51-.516a.904.904 0 00.02-.193c0-1.815 0-3.63-.002-5.443a.725.725 0 00-.026-.185c-.04-.15-.15-.243-.304-.234-.16.01-.318.035-.475.066-.76.15-1.52.303-2.28.456l-2.325.47-1.374.278c-.016.003-.032.01-.048.013-.277.077-.377.203-.39.49-.002.042 0 .086 0 .13-.002 2.602 0 5.204-.003 7.805 0 .42-.047.836-.215 1.227-.278.64-.77 1.04-1.434 1.233-.35.1-.71.16-1.075.172-.96.036-1.755-.6-1.92-1.544-.14-.812.23-1.685 1.154-2.075.357-.15.73-.232 1.108-.31.287-.06.575-.116.86-.177.383-.083.583-.323.6-.714v-.15c0-2.96 0-5.922.002-8.882 0-.123.013-.25.042-.37.07-.285.273-.448.546-.518.255-.066.515-.112.774-.165.733-.15 1.466-.296 2.2-.444l2.27-.46c.67-.134 1.34-.27 2.01-.403.22-.043.442-.088.663-.106.31-.025.523.17.554.482.008.073.012.148.012.223.002 1.91.002 3.822 0 5.732z"/></svg>',
    'youtube_music' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/></svg>',
    'line_music' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect width="24" height="24" rx="5.4" fill="#0ee071"/><path fill="#fff" fill-rule="evenodd" transform="translate(12 12.2) scale(.0166) translate(-771.5 -832)" d="M651 498L1105 416Q1127 414 1127 436V473Q1127 492 1107 497L655 578Q631 581 631 558V524Q631 502 651 498ZM653 660L1105 578Q1127 576 1127 598V1038A148 148 0 0 1 831 1038A148 148 0 0 1 979 890H1047V670L711 731V1102A148 148 0 0 1 416 1102A148 148 0 0 1 564 954H631V686Q631 664 653 660ZM631 1034H564A68 68 0 1 0 631 1102ZM1047 970H979A68 68 0 1 0 1047 1038Z"/></svg>',
];

/**
 * 「聴く」を押したときに開くアプリ（アイコンと名前を出すため）。
 *   見ている人が選んだアプリ。選んでいなければ紐付け元（spotify → Spotify、itunes → Apple Music）
 */
function listen_app(?string $viewerApp, string $source): string
{
    return $viewerApp ?? ($source === 'spotify' ? 'spotify' : 'apple_music');
}

/** そのメンバーが選んだ音楽アプリ。選んでいない・メンバーと紐付いていないなら null */
function member_music_app(PDO $pdo, ?int $memberId): ?string
{
    if (!$memberId) {
        return null;
    }
    $st = $pdo->prepare('SELECT music_app FROM member WHERE member_id = ?');
    $st->execute([$memberId]);
    $app = $st->fetchColumn();
    // isset($配列[キー]): MUSIC_APPS に載っている値だけ通す（DB の値も念のため確かめる）
    return is_string($app) && isset(MUSIC_APPS[$app]) ? $app : null;
}

/**
 * アルバム名の「本体」だけにする。別のサービスで探すとき、余計な飾りがあると見つからないため。
 *   "IRIS OUT - Single" → "IRIS OUT"、"CAMERA TALK (Remastered 2006)" → "CAMERA TALK"
 */
function album_title_core(string $title): string
{
    // iTunes が付ける " - Single" / " - EP"
    $title = preg_replace('/\s+-\s+(Single|EP)\z/u', '', $title);
    // かっこの中（リマスター・デラックス版などの注記）。() [] （） <> ＜＞ 〈〉 【】 を消す
    //   例: "JP<2016 リマスター>" と "JP<2016デジタル・リマスター>" → どちらも "JP"
    $core = trim(preg_replace('/\s*[(\[（<＜〈【][^)\]）>＞〉】]*[)\]）>＞〉】]\s*/u', ' ', $title));
    return $core !== '' ? $core : trim($title); // 全部かっこだったときは元のまま
}

/** 別のアプリで探すときの検索語:「アルバム名 アーティスト名」 */
function album_search_query(array $album): string
{
    $title = album_title_core($album['title']);
    // アルバム名にアーティスト名が入っているとき（"andymori / andymori" など）は足さない。
    //   Spotify は "andymori andymori" のように同じ単語が2回並ぶと0件になるため（実際に確認済み）
    if (album_title_has_artist($album)) {
        return $title;
    }
    return $title . ' ' . $album['artist_name'];
}

/** アルバム名にアーティスト名が入っているか（"andymori / andymori" など。検索語にアーティスト名を足さないケース） */
function album_title_has_artist(array $album): bool
{
    $artistKey = album_match_key($album['artist_name']);
    return $artistKey !== '' && str_contains(album_match_key(album_title_core($album['title'])), $artistKey);
}

/**
 * 同じ名前のアルバムが何枚もあるとき（オリジナル版・リマスター版・完全版など）、登録したものに一番近い版を選ぶ。
 *   発売年が同じ → +2点、かっこの注記まで含めて名前が同じ → +1点。同点なら先に出てきた方（検索の上位）
 */
function album_pick_edition(array $album, array $candidates): string
{
    $fullKey = album_match_key($album['title']);
    $best = null;
    $bestScore = -1;
    foreach ($candidates as $c) {
        $score = 0;
        if (!empty($album['release_year']) && (int)($c['release_year'] ?? 0) === (int)$album['release_year']) {
            $score += 2;
        }
        if (album_match_key($c['title']) === $fullKey) {
            $score += 1;
        }
        if ($score > $bestScore) { // > なので、同点のときは先に出てきた方が残る
            $best = $c;
            $bestScore = $score;
        }
    }
    return album_page_url($best['source'], $best['album_id']);
}

/** アルバム名がローマ字（半角英数字と記号だけ）か。片方だけローマ字なら「表記の違い」とみなすのに使う */
function album_is_roman(string $title): bool
{
    return (bool)preg_match('/\A[\x20-\x7E]*\z/', mb_convert_kana(album_title_core($title), 'as'));
}

/**
 * アルバム名の中の英単語（3文字以上）を取り出す。「ローマ字 + 英語」と「日本語 + 英語」の名前を比べるため。
 *   "東方永夜抄 ～ Imperishable Night. サウンドトラック" → ['imperishable', 'night']
 *   "Touhou Eiyasho - Imperishable Night. SoundTrack"   → ['touhou', 'eiyasho', 'imperishable', 'night']
 *   soundtrack や remaster のような「どのアルバムにも付く飾りの言葉」は、一致しても同じアルバムの証拠にならないので除く
 */
function album_title_words(string $title): array
{
    $noise = ['the', 'and', 'soundtrack', 'original', 'remaster', 'remastered', 'edition', 'deluxe', 'version',
        'live', 'single', 'album', 'best', 'vol', 'disc', 'bonus', 'track', 'tracks', 'expanded', 'anniversary', 'complete'];
    preg_match_all('/[a-z0-9]{3,}/', mb_strtolower(mb_convert_kana($title, 'as')), $m);
    return array_values(array_unique(array_diff($m[0], $noise)));
}

/**
 * 名前を比べるための形にそろえる（表記ゆれを無くす）。
 *   "FLIPPER'S GUITAR" と "Flipper's Guitar"、全角の "ＡＢＣ" と "abc" を同じとみなすため
 *   mb_convert_kana: 'a' 全角英数→半角、's' 全角スペース→半角、'K' 半角カナ→全角カナ、'V' 濁点をくっつける
 */
function album_match_key(string $name): string
{
    $name = mb_strtolower(mb_convert_kana($name, 'asKV'));
    // \p{P} = 句読点・記号、\p{S} = その他の記号。空白と一緒に全部消す
    return preg_replace('/[\s\p{P}\p{S}]+/u', '', $name);
}

/** そのアプリの検索ページの URL（YouTube Music・LINE MUSIC と、「一番上」が見つからなかったとき用） */
function album_search_url(string $app, array $album): string
{
    $q = album_search_query($album);
    return match ($app) {
        'spotify'       => 'https://open.spotify.com/search/' . rawurlencode($q),
        'apple_music'   => 'https://music.apple.com/jp/search?term=' . rawurlencode($q),
        'youtube_music' => 'https://music.youtube.com/search?q=' . rawurlencode($q),
        default         => 'https://music.line.me/webapp/search?query=' . rawurlencode($q),
    };
}

/**
 * マイアルバムのリンク先。上の表のとおり。
 *   $app    見ている人のアプリ（null = 選んでいない）
 *   $album  ['source', 'album_id', 'title', 'artist_name']
 *   $cached album_link_cache を調べた結果。false = まだ調べていない、null = 調べたけど無かった、文字列 = 見つかった URL
 */
function album_listen_url(?string $app, array $album, string|false|null $cached = false): string
{
    $native = ['spotify' => 'spotify', 'apple_music' => 'itunes']; // アプリ → そのアプリの登録元
    if ($app === null || ($native[$app] ?? null) === $album['source']) {
        return album_page_url($album['source'], $album['album_id']); // 選んでいない or 登録元と同じアプリ → 直接
    }
    if (!isset($native[$app])) {
        return album_search_url($app, $album); // YouTube Music・LINE MUSIC → 検索ページ
    }
    // Spotify ⇔ Apple Music をまたぐとき
    return match (true) {
        is_string($cached) => $cached,                                   // 前に見つけた URL
        $cached === null   => album_search_url($app, $album),            // 前に探して見つからなかった
        default            => 'album_go?album=' . rawurlencode(album_key($album['source'], $album['album_id'])), // 押されたら探す
    };
}

/**
 * $app（spotify / apple_music）でアルバムを検索して、同じアルバムらしい一番上の結果の URL を返す。
 *   戻り値: URL / null（それらしいものが無かった）/ false（検索できなかった。通信エラーやキーが無い → 覚えておかない）
 *
 *  取り違え防止: 上から順に見て、次の順で選ぶ。どれにも当たらなければ採用しない（→ 検索ページを開く）
 *    ① アルバム名もアーティスト名も一致するもの
 *    ② アルバム名が一致するもの（"オアシス" と "Oasis" のように、アーティストの表記がサービスで違うことがあるため）
 *    ③ 一番上の結果が、アーティスト名が一致して、アルバム名が「ローマ字 ⇔ 日本語」の違いだけらしいもの
 *       （Apple はアルバム名をローマ字で登録していることがある: "Oyasumi Monster" ⇔ "おやすみモンスター"）
 *    アーティストだけ一致して、アルバム名が同じ文字の種類で違う、は採用しない（同じアーティストの別のアルバムなので）
 */
function album_find_on(string $app, array $album): string|false|null
{
    if ($app !== 'spotify' && $app !== 'apple_music') {
        return null;
    }

    // 段階A: 「アルバム名 アーティスト名」で検索して、上位の結果から選ぶ
    $query = album_search_query($album);
    $results = $app === 'spotify'
        ? (spotify_enabled() ? spotify_search_albums($query) : null)
        : itunes_search_albums($query, 10);
    if ($results === null) {
        return false;
    }
    $found = album_match_in_results($album, $results);
    if ($found !== null) {
        return $found;
    }

    // 段階B: 検索に出てこないとき（Apple でローマ字名になっているアルバムなど）は、アーティストのアルバム一覧から探す
    $catalog = $app === 'spotify'
        ? spotify_artist_albums($album['artist_name'])
        : itunes_artist_albums($album['artist_name']);
    if ($catalog === null) {
        return false;
    }
    return album_match_in_catalog($album, $catalog);
}

/**
 * 段階A: 検索結果の中から、同じアルバムらしいものを選ぶ（通信しない。tests/run.php でテストできる）。
 *   ①②③ は album_find_on の説明を参照。見つからなければ null
 */
function album_match_in_results(array $album, array $results): ?string
{
    $artistKey = album_match_key($album['artist_name']);
    $titleKey = album_match_key(album_title_core($album['title']));
    if ($titleKey === '') {
        return null;
    }
    $sameBoth = [];  // ① の候補
    $titleOnly = []; // ② の候補
    foreach ($results as $r) {
        if (album_match_key(album_title_core($r['title'])) !== $titleKey) {
            continue;
        }
        // Spotify は「A, B」のように複数アーティストをつないでいるので、1人ずつ比べる
        $artists = array_map('album_match_key', explode(', ', $r['artist_name']));
        if (in_array($artistKey, $artists, true)) {
            $sameBoth[] = $r;
        } else {
            $titleOnly[] = $r;
        }
    }
    if ($sameBoth) {
        return album_pick_edition($album, $sameBoth); // ①
    }
    // ② は「検索語にアーティスト名が入っている」から安全と言える（上位に別人の同名アルバムは来にくい）。
    //   アルバム名にアーティスト名が入っていて検索語から外したときは使わない
    //   （例: "NEE / NEE" を "NEE" だけで探すと、DREAMS COME TRUE の "Nee - Single" が上に来る。実際に起きた）
    if ($titleOnly && !album_title_has_artist($album)) {
        return album_pick_edition($album, $titleOnly); // ②
    }

    // ③ 一番上の結果だけを見る
    $top = $results[0] ?? null;
    if ($top === null) {
        return null;
    }
    $topArtists = array_map('album_match_key', explode(', ', $top['artist_name']));
    // 片方だけローマ字なら「表記の違い」
    if (in_array($artistKey, $topArtists, true) && album_is_roman($album['title']) !== album_is_roman($top['title'])) {
        return album_page_url($top['source'], $top['album_id']);
    }
    return null;
}

/**
 * 段階B: アーティストのアルバム一覧の中から、同じアルバムらしいものを選ぶ（通信しない。tests/run.php でテストできる）。
 *   一覧は全部そのアーティストのアルバムなので、「同じアーティストの別のアルバム」を選ばないよう、条件は厳しめ:
 *    (a) アルバム名の本体が一致
 *    (b) 発売年が同じ かつ 英単語が2つ以上共通（"東方永夜抄 ～ Imperishable Night." ⇔ "Touhou Eiyasho - Imperishable Night."）
 *    (c) 発売年が同じ かつ 名前が「ローマ字 ⇔ 日本語」の違い かつ その年のアルバムがその1枚だけ
 *   見つからなければ null
 */
function album_match_in_catalog(array $album, array $catalog): ?string
{
    $titleKey = album_match_key(album_title_core($album['title']));
    $year = $album['release_year'] ?? null;
    $words = album_title_words($album['title']);

    // (a) 同じ名前が何枚もあるとき（リマスター版・完全版など）は album_pick_edition で一番近い版を選ぶ
    $sameTitle = array_values(array_filter($catalog,
        static fn(array $c): bool => $titleKey !== '' && album_match_key(album_title_core($c['title'])) === $titleKey));
    if ($sameTitle) {
        return album_pick_edition($album, $sameTitle);
    }
    if (!$year) {
        return null; // (b)(c) は発売年が分からないと使えない
    }
    // array_filter: 発売年が同じものだけ残す
    $sameYear = array_values(array_filter($catalog, static fn(array $c): bool => (int)($c['release_year'] ?? 0) === (int)$year));
    foreach ($sameYear as $c) { // (b)
        // array_intersect: 両方に入っている単語
        if (count(array_intersect($words, album_title_words($c['title']))) >= 2) {
            return album_page_url($c['source'], $c['album_id']);
        }
    }
    if (count($sameYear) === 1 && album_is_roman($album['title']) !== album_is_roman($sameYear[0]['title'])) { // (c)
        return album_page_url($sameYear[0]['source'], $sameYear[0]['album_id']);
    }
    return null;
}
