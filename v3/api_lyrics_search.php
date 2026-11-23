<?php
/**
 * api_lyrics_search.php — Geminiで歌詞フレーズから曲を検索（管理者限定）。
 *
 * Gemini単体で曲名・アーティスト名を推定し、iTunesで試聴URLを照合する。
 * 歌詞本文は回答・表示・保存しない。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', '歌詞検索は管理者のみ利用できます');

$body = api_body();
$q = trim(is_array($body) && is_string($body['q'] ?? null) ? $body['q'] : '');
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

$prompt = "あなたは楽曲を特定するアシスタントです。入力された歌詞の一節と学習済み知識を手掛かりに、該当する曲を特定してください。外部検索・Google Search groundingは使用できません。"
    . "邦楽・洋楽・インディーズを対象にします。曲を特定できる確信がない場合は無理に推測しないでください。"
    . "歌詞本文、入力された歌詞の引用、歌詞の続きは回答に含めないでください。"
    . "曲名・アーティスト名・アルバム名・発売年だけを最大5件、関連度順で返してください。"
    . "回答はJSONオブジェクトのみとし、形式は {\"candidates\":[{\"title\":\"曲名\",\"artist\":\"アーティスト名\",\"album\":\"アルバム名または空文字\",\"year\":2020}]} です。"
    . "\n検索する歌詞の一節: " . $q;

$request = [
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => $prompt]],
    ]],
    'generationConfig' => [
        'temperature' => 0,
        'maxOutputTokens' => 1200,
    ],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
$response = album_http($url, [
    'Content-Type: application/json',
    'x-goog-api-key: ' . $apiKey,
], json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if ($response === null) {
    error_log('Gemini lyrics search failed: no HTTP response');
    fail(502, 'Gemini APIに接続できませんでした');
}
$status = (int)($response['status'] ?? 0);
if ($status !== 200 || !is_array($response['json'] ?? null)) {
    error_log('Gemini lyrics search failed: HTTP ' . $status);
    if ($status === 429) {
        fail(503, 'Gemini APIの利用上限に達しました。時間をおいて再試行してください');
    }
    if ($status === 400 || $status === 404) {
        fail(502, 'GeminiのモデルまたはAPI設定を確認してください');
    }
    fail(502, 'Gemini APIで検索できませんでした');
}

$data = $response['json'];
$text = '';
foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (is_array($part) && is_string($part['text'] ?? null)) {
        $text .= $part['text'];
    }
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

$tracks = [];
$seen = [];
foreach (array_slice($decoded['candidates'], 0, 5) as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }
    $title = trim((string)($candidate['title'] ?? ''));
    $artist = trim((string)($candidate['artist'] ?? ''));
    if ($title === '' || $artist === '') {
        continue;
    }

    // Geminiが特定した曲名・アーティストをiTunesで照合し、プレビュー可能な完全一致だけを返す。
    $found = itunes_search_tracks($title . ' ' . $artist, 10);
    if (!is_array($found) || $found === []) {
        continue;
    }
    $titleKey = album_match_key(song_title_core($title, $artist));
    $artistKey = album_match_key($artist);
    foreach ($found as $track) {
        if (!is_array($track) || empty($track['preview_url'])) {
            continue;
        }
        $candidateTitle = album_match_key(song_title_core((string)$track['title'], (string)$track['artist_name']));
        $candidateArtists = array_map('album_match_key', explode(', ', (string)$track['artist_name']));
        if ($candidateTitle !== $titleKey || !in_array($artistKey, $candidateArtists, true)) {
            continue;
        }
        $trackId = (string)$track['track_id'];
        if ($trackId === '' || isset($seen[$trackId])) {
            break;
        }
        $seen[$trackId] = true;
        $tracks[] = [
            'track_id' => $trackId,
            'title' => $track['title'],
            'artist_name' => $track['artist_name'],
            'album_title' => $track['album_title'],
            'artwork_url' => $track['artwork_url'],
            'preview_url' => $track['preview_url'],
        ];
        break;
    }
    if (count($tracks) >= 5) {
        break;
    }
}

api_ok(['tracks' => $tracks, 'provider' => 'Gemini']);
