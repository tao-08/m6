<?php
/**
 * api_lyrics_search.php — Geminiで歌詞フレーズから曲を検索（管理者限定）。
 *
 * Geminiで最大10曲を推定し、曲名とアーティスト名だけ返す。
 * iTunes（無ければ Spotify）との照合は、候補の ▶・選択が押されたときだけ（action=match）。
 *   検索のたびに10曲ぶん照合すると、時間がかかってサーバーに切られ、iTunes の回数制限（1分に約20回）にも当たるため。
 * 歌詞本文は回答・表示・保存しない。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/gemini_chapters.php'; // gemini_call

header('Content-Type: application/json; charset=utf-8');
// Gemini（Google 検索つきで約15秒・やり直し込みで最大120秒）＋候補10曲の iTunes / Spotify 検索で、サーバーの既定 30 秒を超えると空の応答で切れる
@set_time_limit(180);
api_user('is_admin', '歌詞検索は管理者のみ利用できます');

$body = api_body();
$q = trim(is_array($body) && is_string($body['q'] ?? null) ? $body['q'] : '');
$requestedArtist = trim(is_array($body) && is_string($body['artist'] ?? null) ? $body['artist'] : '');
$requestedArtist = mb_substr($requestedArtist, 0, 100, 'UTF-8');

// 候補1曲を iTunes / Spotify と照合する（▶・選択を押したとき）
if (is_array($body) && ($body['action'] ?? '') === 'match') {
    $title = trim(is_string($body['title'] ?? null) ? $body['title'] : '');
    $artist = trim(is_string($body['artist_name'] ?? null) ? $body['artist_name'] : '');
    if ($title === '' || $artist === '' || mb_strlen($title, 'UTF-8') > 200 || mb_strlen($artist, 'UTF-8') > 200) {
        fail(400, '曲の指定がおかしいです');
    }
    api_ok(['track' => lyrics_match($title, $artist, $requestedArtist)]);
}
if (mb_strlen($q, 'UTF-8') < 3 || mb_strlen($q, 'UTF-8') > 100) {
    fail(400, '検索語は3〜100文字で入力してください');
}

$gemini = (array)(config('gemini') ?? []);
$apiKey = trim((string)($gemini['api_key'] ?? ''));
$model = trim((string)($gemini['lyrics_search_model'] ?? 'gemini-3.8-flash'));
if ($apiKey === '') {
    fail(503, '歌詞検索は未設定です。config.php の common.gemini.api_key に Gemini API キーを設定してください');
}
if (!preg_match('/^gemini-[a-zA-Z0-9.-]+$/', $model)) {
    fail(503, 'Geminiのモデル設定が不正です');
}

$prompt = "あなたは楽曲特定アシスタントです。入力された歌詞の一節から該当する曲を推定してください。"
    . ($requestedArtist !== '' ? "対象アーティストは「{$requestedArtist}」です。このアーティストの楽曲を優先して候補にしてください。別アーティストの曲は、歌詞がほぼ完全に一致すると確信できる場合だけ confidence を high にして含めてください。" : "アーティスト指定はありません。邦楽・洋楽・インディーズ、メジャー・マイナーを幅広く対象にします。")
    . "確信度が異なる候補も含め、可能性の高い順に最大10件返してください。"
    . "曲名・アーティスト名は正式表記を優先し、別バージョンやカバーは区別してください。"
    . "歌詞本文、入力された歌詞の引用、歌詞の続きは絶対に回答に含めないでください。"
    . "まず歌詞の一節を Google 検索で調べてから答えてください。"
    // JSON で答えさせると Google 検索を使わなくなる（2026-11 に試した）ので、1行1曲で受け取る
    . "答えは1行に1曲、「曲名 | アーティスト名 | high・medium・low のどれか」の形だけを書いてください。説明は書かないでください。"
    . "\n検索する歌詞の一節: " . $q;

$request = [
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    // 考える量を絞る。上限なしだと約800トークン考えて16秒かかった。256 にすると同じ答えで約6秒になった（2026-11）
    'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 2200, 'thinkingConfig' => ['thinkingBudget' => 256]],
    // Gemini 3.8 FlashのGoogle検索グラウンディングを有効化。
    'tools' => [['google_search' => new stdClass()]],
];
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
// album_http は接続を3秒で諦めるので、検出と同じ gemini_call（接続10秒）で呼ぶ。Google 検索つきは15秒ほどかかる。つながらなければ1回だけやり直す
$opts = [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
];
$res = gemini_call($url, $apiKey, $opts, 60);
if ($res === null && gemini_why() !== 'cURL errno 28') { // 時間切れ（28）はやり直さない。やり直すと最大120秒になり、サーバーに途中で切られる
    $res = gemini_call($url, $apiKey, $opts, 60);
}
if ($res === null) {
    fail(502, 'Gemini APIに接続できませんでした', ['detail' => gemini_why()]); // 管理者だけの画面なので理由も ⓘ に出す
}
$response = ['status' => $res[0], 'json' => json_decode($res[2], true)];
$status = (int)($response['status'] ?? 0);
if ($status !== 200 || !is_array($response['json'] ?? null)) {
    error_log('Gemini lyrics search failed: HTTP ' . $status);
    $detail = ['detail' => trim('HTTP ' . $status . ' ' . gemini_error_text($res))];
    if ($status === 429) fail(503, 'Gemini APIの利用上限に達しました。時間をおいて再試行してください', $detail);
    if ($status === 400 || $status === 404) fail(502, 'GeminiのモデルまたはAPI設定を確認してください', $detail);
    fail(502, 'Gemini APIで検索できませんでした', $detail);
}

$text = '';
foreach (($response['json']['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (is_array($part) && is_string($part['text'] ?? null)) $text .= $part['text'];
}
$decoded = ['candidates' => lyrics_parse_lines($text)];
if (!$decoded['candidates']) {
    error_log('Gemini lyrics search returned invalid candidate JSON');
    fail(502, 'Geminiから曲の候補を読み取れませんでした。別の歌詞で検索してください');
}

// 指定アーティスト以外は、Gemini がほぼ完全一致（high）とした曲だけ残す。同じ曲の別表記は1つにまとめる
$tracks = [];
$seen = [];
foreach (array_slice($decoded['candidates'], 0, 10) as $candidate) {
    $title = $candidate['title'];
    $artist = $candidate['artist'];
    $isRequested = $requestedArtist === '' || lyrics_similarity($artist, $requestedArtist) >= 65;
    if (!$isRequested && $candidate['confidence'] !== 'high') continue;
    $groupKey = album_match_key($artist) . "\0" . song_title_key($title, $artist);
    if (isset($seen[$groupKey])) continue;
    $seen[$groupKey] = true;
    $tracks[] = ['title' => $title, 'artist_name' => $artist, 'artist_match' => $requestedArtist !== '' && $isRequested];
}
usort($tracks, static fn(array $a, array $b): int => $b['artist_match'] <=> $a['artist_match']); // 同じなら Gemini の順のまま
$tracks = array_map(static fn(array $t): array => ['title' => $t['title'], 'artist_name' => $t['artist_name']], $tracks);

// Google 検索を使ったときは、Gemini が返す検索の候補（Google 指定の HTML）を出す決まり。中身は変えずにそのまま渡す
$suggest = (string)($response['json']['candidates'][0]['groundingMetadata']['searchEntryPoint']['renderedContent'] ?? '');
api_ok(['tracks' => $tracks, 'provider' => 'Gemini + Google Search', 'search_suggest' => $suggest]);

/** 表記ゆれを考慮した曲名・アーティスト名の類似度（0〜100）。 */
function lyrics_similarity(string $actual, string $expected): int
{
    $a = album_match_key($actual);
    $e = album_match_key($expected);
    if ($a === '' || $e === '') return 0;
    if ($a === $e) return 100;
    if (mb_strlen($e, 'UTF-8') >= 3 && (str_contains($a, $e) || str_contains($e, $a))) return 85;
    similar_text($a, $e, $percent);
    return (int)round($percent);
}

/**
 * Gemini の候補1曲を iTunes（無ければ Spotify）で探し、曲名とアーティストの両方が合う曲を返す。無ければ null
 * オムニバスでなければ、フォームのアーティスト名で探す（Gemini が別名で返しても）。
 * 同じ曲が複数のアルバムにあれば、オリジナル盤に近い版 → 発売年が古い版 → 照合スコアが高い版。
 */
function lyrics_match(string $title, string $artist, string $requestedArtist): ?array
{
    $isRequested = $requestedArtist === '' || lyrics_similarity($artist, $requestedArtist) >= 65;
    $searchArtist = $isRequested && $requestedArtist !== '' ? $requestedArtist : $artist;
    $term = $title . ' ' . $searchArtist;
    $itunesDown = false;

    // Apple Music だけで配信の曲は iTunes Search API に出ないので、Spotify でも探す
    foreach (['itunes', 'spotify'] as $source) {
        $found = $source === 'itunes' ? itunes_search_tracks($term, 10) : (spotify_enabled() ? spotify_search_tracks($term, 10) : null);
        if ($source === 'itunes' && $found === null) $itunesDown = true;
        $best = null;
        foreach (is_array($found) ? $found : [] as $track) {
            if (!is_array($track) || (string)$track['track_id'] === '') continue;
            $titleScore = lyrics_similarity(song_title_core((string)$track['title'], (string)$track['artist_name']), song_title_core($title, $artist));
            $artistScore = 0;
            foreach (array_map('trim', explode(', ', (string)$track['artist_name'])) as $actualArtist) {
                $artistScore = max($artistScore, lyrics_similarity($actualArtist, $searchArtist));
            }
            if ($titleScore < 75 || $artistScore < 65) continue;
            $mainArtist = trim(explode(', ', (string)$track['artist_name'])[0]);
            $rank = [
                song_version_rank((string)($track['album_title'] ?? ''), (string)$track['title'], $mainArtist),
                isset($track['release_year']) ? (int)$track['release_year'] : 9999,
                -(int)round($titleScore * 0.65 + $artistScore * 0.35),
            ];
            if ($best === null || $rank < $best[0]) $best = [$rank, $track];
        }
        if ($best !== null) {
            $t = $best[1];
            return [
                'source' => $source,
                'track_id' => (string)$t['track_id'],
                'title' => $t['title'],
                'artist_name' => $t['artist_name'],
                'album_title' => $t['album_title'],
                'artwork_url' => $t['artwork_url'],
                'preview_url' => $t['preview_url'] ?? null,
            ];
        }
    }
    if ($itunesDown) {
        fail(502, 'iTunes に接続できませんでした: ' . itunes_why());
    }
    return null;
}
