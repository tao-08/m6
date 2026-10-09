<?php
/**
 * =====================================================================
 *  lib/tracks.php — 曲（トラック）の検索・取得・聴くリンクの入口（lib/albums.php の曲版）
 * =====================================================================
 *  曲の編集画面で🔍検索して、Spotify / iTunes の曲を song に紐付ける。
 *  使うサービスの決め方はアルバムと同じ（Spotify のキーがあれば Spotify、失敗したら iTunes）。
 *
 *  どのサービスから取った曲も、次の同じ形にそろえて返す:
 *    ['source' => 'spotify', 'track_id' => '...', 'title' => '曲名', 'artist_name' => '...',
 *     'album_title' => '...', 'artwork_url' => 'https://...', 'release_year' => 2018 または null]
 *
 *  1曲は「source + track_id」で決まる。キーの形（"spotify:xxxx"）と ID のチェックはアルバムと同じなので、
 *  album_key() / album_parse_key() をそのまま使う。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/albums.php';

/**
 * 曲を検索する。戻り値は album_search と同じ形:
 *   ['tracks' => 曲の配列（全部失敗したら null）, 'source' => 実際に使ったサービス]
 */
function track_search(string $term): array
{
    if (spotify_enabled()) {
        $tracks = spotify_search_tracks($term);
        if ($tracks !== null) {
            return ['tracks' => $tracks, 'source' => 'spotify'];
        }
    }
    return ['tracks' => itunes_search_tracks($term), 'source' => 'itunes'];
}

/** source と track_id から1曲を取り直す（保存するとき用）。見つからない・通信失敗なら null */
function track_lookup(string $source, string $trackId): ?array
{
    return match ($source) {
        'spotify' => spotify_lookup_track($trackId),
        'itunes'  => itunes_lookup_track($trackId),
        default   => null,
    };
}

/** その曲のページ（Spotify / Apple Music）の URL */
function track_page_url(string $source, string $trackId): string
{
    return match ($source) {
        'spotify' => 'https://open.spotify.com/track/' . rawurlencode($trackId),
        default   => 'https://music.apple.com/jp/song/' . rawurlencode($trackId),
    };
}

/**
 * 曲名の「本体」だけにする。版違いを同じ曲として扱うため（集計・別のアプリで探すとき）。
 * Spotify / iTunes の実際の曲名を約900件集めて確かめた（tests/run.php の "song titles" にも主なものを入れてある）。
 * 消すのは次の注記:
 *   ① 曲名の後ろに出てきた最初のかっこから後ろ全部   "Let It Be (feat. X) [Live at Shea Stadium - July 2008]" → "Let It Be"
 *        () [] <> 〈〉 【】 《》 『』 「」 {}（全角も）。かっこの後ろの "1976" や "『風立ちぬ』より" もまとめて消える
 *        先頭のかっこは曲名の一部とみなして中身を残す   "(I Can't Get No) Satisfaction"、"「Paradise Has No Border」(…)"
 *   ② ~ から後ろ（〜 ～ も）、~xxx~                  "Lemon ~ドラマ「アンナチュラル」主題歌~"、"Highway Star〜エレキギターソロ〜"
 *   ③ 最後の -xxx-                                    "Lemon -Album ver.-"、"シルエット-TV SIZE-"、"Paradise Has No Border -SKY-HI Remix-"
 *   ④ 「 - 」「 / 」の後ろが注記らしいとき（song_cut_note）  "Let It Be - Remastered 2009"、"ピースサイン - Peace Sign"
 *        - は片側だけスペースでも区切りとみなす  "Link -KISS Mix-/- Remastered 2022"
 *   ⑤ feat. / Originally Performed By / オリジナルアーティスト / 原曲歌手 から後ろ  "道なき道、反骨の。feat.Ken Yokoyama"
 *   ⑥ カラオケのキー変更 "+1Key"、日本語の曲名の後ろの英字の注記 "シルエット New Go-Line ver."、
 *        再録音の西暦 "君の中で踊りたい 2023"
 *   ⑦ 最後にくっついたアーティスト名（$artist を渡したとき）  "First Love/宇多田ヒカル" → "First Love"
 * 全部消えて空になる曲名は、元のまま返す。
 * まとめられないもの: 「Let It Be」と「レット・イット・ビー」のような、英語とカタカナの違い（読みを変換する辞書が要る）
 * 間違えてまとめるもの（40アーティスト・約4000曲で確かめて見つかったもの）:
 *   記号だけが違う別の曲 "¡Viva la Gloria!" と "¿Viva la Gloria? (Little Girl)"、
 *   曲名の一部が西暦の曲 "tokyo 2012" → "tokyo"。どちらも同じアーティストに「その名前の別の曲」があるときだけ問題になる
 */
function song_title_core(string $title, ?string $artist = null): string
{
    // 全角英数・記号を半角に（（）→()、－→-、：→:）。いろいろなダッシュと波ダッシュもそろえる
    //   ※ 全角の ～ は mb_convert_kana の 'a' では変わらない（PHP の仕様）ので、下の str_replace でそろえる
    $t = trim(mb_convert_kana($title, 'as'));
    $t = str_replace(['～', '〜', '‐', '‑', '–', '—', '―', '−'], ['~', '~', '-', '-', '-', '-', '-', '-'], $t);
    $open = '(\[<〈【《『「{';
    $close = ')\]>〉】》』」}';
    $nonAscii = '(?<=[^\x00-\x7F])'; // 直前が日本語などの文字（英字の注記が、スペース無しでくっついているとき用）

    // ① 先頭のかっこは中身を残し、その後ろで最初に出てきたかっこから後ろを消す
    $lead = '';
    if (preg_match("/\A[$open]([^$open$close]*)[$close]/u", $t, $m)) {
        $lead = $m[1] . ' ';
        $t = substr($t, strlen($m[0]));
    }
    // かっこの中が英数字1〜2文字だけなら曲名の一部（"Ame(A)" と "Ame(B)" は別の曲。サカナクションに実際にある）
    while (preg_match("/[$open]\s*([a-z0-9]{1,2})\s*[$close]/iu", $t, $m, PREG_OFFSET_CAPTURE)
        && !preg_match("/[$open]/u", substr($t, 0, $m[0][1]))) { // それより前に別のかっこが無いときだけ
        $t = substr_replace($t, ' ' . $m[1][0] . ' ', $m[0][1], strlen($m[0][0]));
    }
    $t = $lead . preg_replace("/\s*[$open].*\z/su", '', $t);
    $t = preg_replace('/\s+~.*\z|~[^~]+~\s*\z/u', '', $t);                                         // ②
    $t = song_cut_note($t, '/\s+-\s*|\s*-\s+/u');                                                  // ④（片側だけスペースの - も区切り）
    $t = song_cut_note($t, '#\s+/\s+#u');
    $t = preg_replace("/(?:\s+|$nonAscii)-\S(?:.*\S)?-[\s\/]*\z/u", '', $t);                    // ③
    $t = preg_replace("/(?:\s+|$nonAscii)(?:feat\.|ft\.|feat(?![a-z])|featuring(?![a-z])|originally performed by"
        . '|オリジナルアーティスト|オリジナル歌手|原曲歌手).*\z/iu', '', $t);                       // ⑤
    $t = preg_replace('/\s*[+-]\d+\s*key\s*\z/iu', '', $t);                                         // ⑥ カラオケのキー変更
    // ⑥ 日本語の曲名の後ろに、かっこ無しで続く英字の注記 "シルエット New Go-Line ver."、"ミュージック・アワー Ver.164"
    if (preg_match('/(?<=[\p{Han}\p{Hiragana}\p{Katakana}ー])\s*([\x20-\x7E]+)\z/u', $t, $m) && preg_match(SONG_NOTE_WORDS, $m[1])) {
        $t = substr($t, 0, -strlen($m[0]));
    }
    // ⑥ 区切り無しで最後に付いた注記（英語の曲名でも）。曲名の最後に来ることがまず無い言葉だけ
    //   "Let It Be 2020 Remaster"、"Yesterday Remastered 2009"、"Lemon Piano Version"、"Pretender Instrumental"
    $t = preg_replace('/\s+(?:(?:19|20)\d{2}\s+)?(?:digital\s+)?remaster(?:ed|ing)?(?:\s+(?:19|20)\d{2})?(?:\s+version)?\s*\z/iu', '', $t);
    $t = preg_replace('/\s+(?:\S+\s+)?(?:version|ver\.?)(?:\s*[\d.]+)?\s*\z/iu', '', $t);
    $t = preg_replace('/\s+(?:instrumental|karaoke|inst\.)\s*\z/iu', '', $t);
    // ⑥ 再録音で後ろに付いた西暦 "君の中で踊りたい 2023"（曲名が西暦だけの "1980" は、最後の「空なら元のまま」で残る）
    $t = preg_replace('/\s+(?:19[5-9]\d|20[0-3]\d)\z/u', '', $t);
    if ($artist !== null && trim($artist) !== '') {                                                // ⑦
        $a = preg_quote(trim(mb_convert_kana($artist, 'as')), '/');
        $t = preg_replace('/(?:\s+|\s*[\/:-]\s*)' . $a . '\s*\z/iu', '', $t);
    }
    $t = trim($t);
    return album_match_key($t) !== '' ? $t : trim($title);
}

/**
 * 版違い・注記によく出てくる言葉（「 - 」「 / 」の後ろがこれを含んでいたら注記とみなす）。
 *   英単語は前後が英字でないときだけ一致させる（"live" が "Oliver" に当たらないように）。
 *   ※ \b（単語の境目）は使わない。PHP の /u 付きの正規表現ではカタカナも「単語の文字」になり、
 *     "サンライトLIVE" の LIVE の前に境目が無いことになって拾えないため（実際に起きた）
 */
const SONG_NOTE_WORDS = '/(?<![a-z])(?:remaster\w*|live|ver|version|mix|remix|edit|mono|stereo|acoustic|unplugged|instrumental|inst'
    . '|karaoke|demo|take|single|album|radio|extended|original|bonus|sessions?|rehearsal|re-?record\w*|from|feat|cover|tv|size'
    . '|short|full|revisited|reprise|slowed|reverb|sped up|nightcore|piano|orchestra|strings|english|japanese|spanish|korean|op|ed'
    . '|theme|soundtrack|ost|outtakes?|alternate|anniversary|deluxe|music box|a ?cappella|tour|concert|studio|key)(?![a-z])'
    . '|ライブ|ライヴ|バージョン|ヴァージョン|リマスター|弾き語り|アコースティック|主題歌|挿入歌|オープニング|エンディング|カラオケ'
    . '|オルゴール|ピアノ|インスト|生演奏|完全版|ミックス|リミックス|テーマ|英語|日本語|カバー|再録|アレンジ|より\z/iu';

/**
 * 曲名を「 - 」や「 / 」で区切り、注記らしい区切りから後ろを消す（song_title_core の ④）。
 *   注記とみなすのは、区切りの後ろが次のどれかのとき:
 *     ・SONG_NOTE_WORDS の言葉を含む   "Let It Be - Remastered 2009"、"Wonderwall - Unplugged"、"群青日和 - Bon Voyageより"
 *     ・西暦（1950〜2039）を含む        "Smells Like Teen Spirit - 1992/Live at Reading"
 *     ・前と比べて「日本語 ⇔ 英語だけ」が入れ替わっている（邦題 - 英題）  "ピースサイン - Peace Sign"
 *   どれでもなければ曲名の一部として残す  "Bling - Bang - Bang - Born"、"Hello - Goodbye"
 */
function song_cut_note(string $title, string $separator): string
{
    $parts = preg_split($separator, $title);
    $kept = $parts[0];
    for ($i = 1, $n = count($parts); $i < $n; $i++) {
        $part = $parts[$i];
        $isNote = preg_match(SONG_NOTE_WORDS, $part)
            || preg_match('/(?<!\d)(?:19[5-9]\d|20[0-3]\d)(?!\d)/', $part)
            || song_has_japanese($kept) !== song_has_japanese($part);
        if ($isNote) {
            break; // ここから後ろは注記
        }
        $kept .= ' - ' . $part; // 区切りの記号の違いは album_match_key で消えるので、- でつなぎ直してよい
    }
    return $kept;
}

/** ひらがな・カタカナ・漢字を含むか */
function song_has_japanese(string $s): bool
{
    return (bool)preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $s);
}

/**
 * 集計用のキー: 曲名の本体を比べやすい形にしたもの（大文字小文字・空白・記号・全角半角の違いを無くす）。
 * "Lemon" と "Lemon - Acoustic ver." と "ＬＥＭＯＮ（Piano Ver.）" が同じキーになる。
 */
function song_title_key(string $title, ?string $artist = null): string
{
    return album_match_key(song_title_core($title, $artist));
}

/** 別のアプリで探すときの検索語:「曲名 アーティスト名」 */
function track_search_query(array $track): string
{
    return song_title_core($track['title'], $track['artist_name']) . ' ' . $track['artist_name'];
}

/** そのアプリの検索ページの URL（YouTube Music・LINE MUSIC と、見つからなかったとき用） */
function track_search_url(string $app, array $track): string
{
    $q = track_search_query($track);
    return match ($app) {
        'spotify'       => 'https://open.spotify.com/search/' . rawurlencode($q),
        'apple_music'   => 'https://music.apple.com/jp/search?term=' . rawurlencode($q),
        'youtube_music' => 'https://music.youtube.com/search?q=' . rawurlencode($q),
        default         => 'https://music.line.me/webapp/search?query=' . rawurlencode($q),
    };
}

/**
 * 曲のリンク先。考え方は album_listen_url と同じ表（lib/albums.php）:
 *   アプリを選んでいない・紐付け元と同じアプリ → その曲のページ（直接）
 *   YouTube Music・LINE MUSIC                → 検索ページ
 *   Spotify ⇔ Apple Music をまたぐ            → 覚えていればその URL、無ければ song_go.php（押されたら探す）
 *   $track  ['source', 'track_id', 'title', 'artist_name']
 *   $cached track_link_cache を調べた結果。false = まだ調べていない、null = 調べたけど無かった、文字列 = 見つかった URL
 */
function track_listen_url(?string $app, array $track, string|false|null $cached = false): string
{
    $native = ['spotify' => 'spotify', 'apple_music' => 'itunes'];
    if ($app === null || ($native[$app] ?? null) === $track['source']) {
        return track_page_url($track['source'], $track['track_id']);
    }
    if (!isset($native[$app])) {
        return track_search_url($app, $track);
    }
    return match (true) {
        is_string($cached) => $cached,
        $cached === null   => track_search_url($app, $track),
        default            => 'song_go?track=' . rawurlencode(album_key($track['source'], $track['track_id'])),
    };
}

/**
 * $app（spotify / apple_music）で曲を検索して、同じ曲らしい結果の URL を返す（song_go.php から呼ぶ）。
 *   戻り値: URL / null（それらしいものが無かった）/ false（検索できなかった → 覚えておかない）
 *
 *  取り違え防止: 曲名の本体が一致するものだけを見て、
 *    ① アーティスト名も一致するもの → その中で曲名がかっこの注記まで同じものを優先
 *    ② アーティスト名がどれも一致しない → 採用しない（同名の別の曲かもしれないので、検索ページを開く）
 */
function track_find_on(string $app, array $track): string|false|null
{
    if ($app !== 'spotify' && $app !== 'apple_music') {
        return null;
    }
    $query = track_search_query($track);
    $results = $app === 'spotify'
        ? (spotify_enabled() ? spotify_search_tracks($query) : null)
        : itunes_search_tracks($query);
    if ($results === null) {
        return false;
    }
    return track_match_in_results($track, $results);
}

/** 検索結果の中から同じ曲らしいものを選んで、その曲のページの URL を返す（通信しない）。見つからなければ null */
function track_match_in_results(array $track, array $results): ?string
{
    $r = track_pick_in_results($track, $results);
    return $r === null ? null : track_page_url($r['source'], $r['track_id']);
}

/** 検索結果の中から同じ曲らしいもの（結果の1件そのまま）を選ぶ。選び方は track_find_on の説明のとおり */
function track_pick_in_results(array $track, array $results): ?array
{
    $titleKey = song_title_key($track['title'], $track['artist_name']);
    $fullKey = album_match_key($track['title']);
    $artistKey = album_match_key($track['artist_name']);
    if ($titleKey === '') {
        return null;
    }
    $best = null;
    foreach ($results as $r) {
        // Spotify は「A, B」のように複数アーティストをつないでいるので、1人ずつ比べる
        $artists = array_map('album_match_key', explode(', ', $r['artist_name']));
        if (song_title_key($r['title'], $r['artist_name']) !== $titleKey || !in_array($artistKey, $artists, true)) {
            continue;
        }
        if (album_match_key($r['title']) === $fullKey) {
            return $r; // 版の注記まで同じ → これで決まり
        }
        $best ??= $r; // ??= は「まだ無ければ入れる」→ 検索の上位を残す
    }
    return $best;
}

/**
 * 30秒試聴の音源 URL を iTunes で探す（api_track_preview.php から呼ぶ）。
 *   iTunes で紐付けた曲 → その曲を lookup。Spotify で紐付けた曲 → iTunes で同じ曲らしいものを探す（取り違え防止は track_find_on と同じ）
 *   戻り値: URL / null（無かった）/ false（通信できなかった → 覚えておかない）
 *   ※ Spotify の API は2024年11月から、新しいアプリには試聴の URL（preview_url）を返さなくなったので、試聴は iTunes だけ
 */
function track_preview_find(array $track): string|false|null
{
    if ($track['source'] === 'itunes') {
        $results = itunes_request('lookup', ['id' => $track['track_id'], 'country' => 'jp']);
        if ($results === null) {
            return false;
        }
        foreach ($results as $r) {
            $t = is_array($r) ? itunes_normalize_track($r) : null;
            if ($t !== null && $t['track_id'] === $track['track_id']) {
                return $t['preview_url'];
            }
        }
        return null;
    }
    $results = itunes_search_tracks(track_search_query($track));
    if ($results === null) {
        return false;
    }
    // 同じ曲らしいものの中で試聴があるものを選ぶ（いちばん合う版に試聴が無いこともあるので、無いものを外してから選ぶ）
    $withPreview = array_values(array_filter($results, static fn($r) => $r['preview_url'] !== null));
    return track_pick_in_results($track, $withPreview)['preview_url'] ?? null;
}

/**
 * 覚えてある試聴の URL をまとめて読む（band.php の表示用。通信しない）。
 *   戻り値: [キー => URL または null（無かった）]。覚えていない曲・探し直す時期の曲は入らない
 */
function track_preview_cache_for(PDO $pdo, array $keys): array
{
    $cache = [];
    $st = $pdo->prepare('SELECT preview_url FROM track_preview WHERE source = ? AND track_id = ?
        AND (preview_url IS NOT NULL OR checked_at > NOW() - INTERVAL ' . ALBUM_NOT_FOUND_RETRY_DAYS . ' DAY)');
    foreach (array_unique($keys) as $key) {
        [$source, $trackId] = explode(':', $key, 2);
        $st->execute([$source, $trackId]);
        $row = $st->fetch();
        if ($row) {
            $cache[$key] = $row['preview_url'];
        }
    }
    return $cache;
}

/**
 * 紐付けた曲の「別のアプリで見つけた URL」の覚え書きを、まとめて読む（ページを表示するとき用）。
 *   $keys "spotify:xxxx" の配列。戻り値: [キー => URL または null（見つからなかった）]。覚えていない曲は入らない
 */
function track_link_cache_for(PDO $pdo, ?string $app, array $keys): array
{
    if (($app !== 'spotify' && $app !== 'apple_music') || !$keys) {
        return [];
    }
    $cache = [];
    $st = $pdo->prepare('SELECT url FROM track_link_cache WHERE source = ? AND track_id = ? AND app = ?
        AND (url IS NOT NULL OR checked_at > NOW() - INTERVAL ' . ALBUM_NOT_FOUND_RETRY_DAYS . ' DAY)');
    foreach (array_unique($keys) as $key) {
        [$source, $trackId] = explode(':', $key, 2);
        $st->execute([$source, $trackId, $app]);
        $row = $st->fetch();
        if ($row) {
            $cache[$key] = $row['url'];
        }
    }
    return $cache;
}

/** 曲の情報を track テーブルに入れる（もうあれば最新の内容で上書き）。曲を紐付けて保存するとき用 */
function save_track(PDO $pdo, array $t): void
{
    $pdo->prepare('INSERT INTO track (source, track_id, title, artist_name, album_title, artwork_url, release_year)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), artist_name = VALUES(artist_name),
                album_title = VALUES(album_title), artwork_url = VALUES(artwork_url), release_year = VALUES(release_year)')
        ->execute([$t['source'], $t['track_id'], $t['title'], $t['artist_name'], $t['album_title'], $t['artwork_url'], $t['release_year']]);
}
