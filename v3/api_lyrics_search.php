<?php
/**
 * api_lyrics_search.php — Geminiで歌詞フレーズから曲を検索（管理者限定）。
 *
 * Geminiで最大10曲を推定し、iTunes（無ければ Spotify）の曲情報と照合する。
 * 歌詞本文は回答・表示・保存しない。試聴URLの有無は候補の採否に影響させない。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/gemini_chapters.php'; // gemini_call

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', '歌詞検索は管理者のみ利用できます');

$body = api_body();
$q = trim(is_array($body) && is_string($body['q'] ?? null) ? $body['q'] : '');
$requestedArtist = trim(is_array($body) && is_string($body['artist'] ?? null) ? $body['artist'] : '');
$requestedArtist = mb_substr($requestedArtist, 0, 100, 'UTF-8');
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
    . "曲名・アーティスト名・アルバム名・発売年・確信度（high/medium/low）だけを返してください。"
    . "回答はJSONオブジェクトのみ: {\"candidates\":[{\"title\":\"曲名\",\"artist\":\"アーティスト名\",\"album\":\"アルバム名または空文字\",\"year\":2020,\"confidence\":\"medium\"}]}"
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
$res = gemini_call($url, $apiKey, $opts, 60) ?? gemini_call($url, $apiKey, $opts, 60);
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
$text = trim($text);
$text = preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/i', '', $text) ?? $text;
$decoded = json_decode($text, true);
if (!is_array($decoded)) {
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    }
}
if (!is_array($decoded) || !is_array($decoded['candidates'] ?? null)) {
    error_log('Gemini lyrics search returned invalid candidate JSON');
    fail(502, 'Geminiから曲の候補を読み取れませんでした。別の歌詞で検索してください');
}

/** 表記ゆれを考慮した曲名・アーティスト名の類似度（0〜100）。 */
$similarity = static function (string $actual, string $expected): int {
    $a = album_match_key($actual);
    $e = album_match_key($expected);
    if ($a === '' || $e === '') return 0;
    if ($a === $e) return 100;
    if (mb_strlen($e, 'UTF-8') >= 3 && (str_contains($a, $e) || str_contains($e, $a))) return 85;
    similar_text($a, $e, $percent);
    return (int)round($percent);
};

$ranked = [];
$seen = [];
foreach (array_slice($decoded['candidates'], 0, 10) as $candidate) {
    if (!is_array($candidate)) continue;
    $title = trim((string)($candidate['title'] ?? ''));
    $artist = trim((string)($candidate['artist'] ?? ''));
    if ($title === '' || $artist === '') continue;

    // オムニバスでない場合は、フォームから渡されたバンドのアーティスト名を優先して検索する。
    // Geminiが別アーティスト名を返しても、検索・照合には指定アーティストを使う。
    // 指定アーティスト以外は、Geminiがほぼ完全一致(high)とした曲だけ残す。
    $isRequested = $requestedArtist === '' || $similarity($artist, $requestedArtist) >= 65;
    if (!$isRequested && strtolower(trim((string)($candidate['confidence'] ?? ''))) !== 'high') continue;
    $searchArtist = $isRequested && $requestedArtist !== '' ? $requestedArtist : $artist;
    $term = $title . ' ' . $searchArtist;
    $matched = false;

    // iTunes（試聴あり）で見つからなければ Spotify で探す。Apple Music だけで配信の曲は iTunes Search API に出ない。
    foreach (['itunes', 'spotify'] as $source) {
        if ($matched) break;
        $found = $source === 'itunes' ? itunes_search_tracks($term, 10) : (spotify_enabled() ? spotify_search_tracks($term, 10) : null);

        foreach (is_array($found) ? $found : [] as $track) {
            if (!is_array($track)) continue;
            $titleScore = $similarity(song_title_core((string)$track['title'], (string)$track['artist_name']), song_title_core($title, $artist));
            $artistNames = array_map('trim', explode(', ', (string)$track['artist_name']));
            $artistScore = 0;
            foreach ($artistNames as $actualArtist) {
                $artistScore = max($artistScore, $similarity($actualArtist, $searchArtist));
            }
            // タイトルとアーティストの両方が一定以上一致する候補に限定する。
            if ($titleScore < 75 || $artistScore < 65) continue;

            $id = (string)$track['track_id'];
            if ($id === '') continue;
            $matched = true;

            // 同じアーティスト・同じ曲の別バージョンは1候補にまとめる。
            // 既存の曲管理と同じ song_title_key() を使い、Remaster / Live / Acoustic 等の注記を除いて判定する。
            $mainArtist = trim(explode(', ', (string)$track['artist_name'])[0]);
            $groupKey = album_match_key($mainArtist) . "\0" . song_title_key((string)$track['title'], $mainArtist);
            if ($groupKey === "\0" || str_ends_with($groupKey, "\0")) continue;

            $versionRank = song_version_rank(
                (string)($track['album_title'] ?? ''),
                (string)$track['title'],
                $mainArtist
            );
            $releaseYear = isset($track['release_year']) ? (int)$track['release_year'] : 9999;
            $score = (int)round($titleScore * 0.65 + $artistScore * 0.35);
            $artistMatch = 0;
            if ($requestedArtist !== '') {
                foreach ($artistNames as $actualArtist) {
                    if ($similarity($actualArtist, $requestedArtist) >= 65) $artistMatch = 1;
                }
            }
            $row = [
                'score' => $score,
                'artist_match' => $artistMatch,
                'version_rank' => $versionRank,
                'release_year' => $releaseYear,
                'track' => [
                    'source' => $source,
                    'track_id' => $id,
                    'title' => $track['title'],
                    'artist_name' => $track['artist_name'],
                    'album_title' => $track['album_title'],
                    'artwork_url' => $track['artwork_url'],
                    'preview_url' => $track['preview_url'] ?? null,
                ],
            ];
            // 同一曲が複数のアルバム版で見つかった場合は、オリジナル盤に近い版を優先する。
            // 同順位なら発売年が古いもの、さらに同じなら照合スコアが高いものを残す。
            if (!isset($seen[$groupKey])
                || [$versionRank, $releaseYear, -$score] < [$seen[$groupKey]['version_rank'], $seen[$groupKey]['release_year'], -$seen[$groupKey]['score']]) {
                $seen[$groupKey] = $row;
            }
        }
    }

    // どちらにも無くても high なら、曲名とアーティストだけの候補にする。
    if (!$matched && strtolower(trim((string)($candidate['confidence'] ?? ''))) === 'high') {
        $groupKey = album_match_key($artist) . "\0" . song_title_key($title, $artist);
        $seen[$groupKey] ??= [
            'score' => 100,
            'artist_match' => $requestedArtist !== '' && $similarity($artist, $requestedArtist) >= 65 ? 1 : 0,
            'version_rank' => 0,
            'release_year' => 9999,
            'track' => [
                'source' => null,
                'track_id' => null,
                'title' => $title,
                'artist_name' => $artist,
                'album_title' => trim((string)($candidate['album'] ?? '')),
                'artwork_url' => null,
                'preview_url' => null,
            ],
        ];
    }
}
$ranked = array_values($seen);
usort($ranked, static fn(array $a, array $b): int => [$b['artist_match'], $b['score']] <=> [$a['artist_match'], $a['score']]);
$tracks = array_map(static fn(array $row): array => $row['track'], array_slice($ranked, 0, 10));

// Google 検索を使ったときは、Gemini が返す検索の候補（Google 指定の HTML）を出す決まり。中身は変えずにそのまま渡す
$suggest = (string)($response['json']['candidates'][0]['groundingMetadata']['searchEntryPoint']['renderedContent'] ?? '');
api_ok(['tracks' => $tracks, 'provider' => 'Gemini + Google Search', 'search_suggest' => $suggest]);
