<?php
/**
 * api_lyrics_search_gemini_probe.php — Gemini + Google Search による歌詞フレーズ検索の管理者向け検証。
 * 歌詞本文は取得・表示・保存せず、検索語もDBやログへ保存しない。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/lyrics_search.php';

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', 'Gemini歌詞検索の検証は管理者のみ利用できます');

$body = api_body();
$q = trim(is_array($body) && is_string($body['q'] ?? null) ? $body['q'] : '');
$expectedTitle = trim(is_array($body) && is_string($body['expected_title'] ?? null) ? $body['expected_title'] : '');
$expectedArtist = trim(is_array($body) && is_string($body['expected_artist'] ?? null) ? $body['expected_artist'] : '');

if (mb_strlen($q, 'UTF-8') < 3 || mb_strlen($q, 'UTF-8') > 100) {
    fail(400, '検索フレーズは3〜100文字で入力してください');
}
if (mb_strlen($expectedTitle, 'UTF-8') > 100 || mb_strlen($expectedArtist, 'UTF-8') > 100) {
    fail(400, '照合用の曲名・アーティスト名は100文字以内にしてください');
}

$geminiConfig = (array)(config('gemini') ?? []);
$apiKey = trim((string)($geminiConfig['api_key'] ?? ''));
$model = trim((string)($geminiConfig['lyrics_search_model'] ?? 'gemini-2.5-flash'));
if ($apiKey === '') {
    fail(503, 'config.php の common.gemini.api_key に Gemini API キーを設定してください');
}
if (!preg_match('/^gemini-[a-zA-Z0-9.-]+$/', $model)) {
    fail(503, 'Geminiのモデル設定が不正です');
}

$prompt = "あなたは楽曲の特定を行う検索アシスタントです。ユーザーが入力した歌詞の短い一節から、該当する楽曲を特定してください。Google検索を使って確認してください。"
    . "歌詞本文や続きの歌詞は絶対に出力しないでください。検索結果に歌詞ページがあっても引用しないでください。"
    . "曲名・アーティスト名・アルバム名・リリース年・確信度(0から1)だけを最大5件返してください。"
    . "歌詞の一節が曖昧な場合や根拠が弱い場合は、推測で埋めず候補を空にしてください。"
    . "回答はJSONオブジェクトのみ。形式: {\"candidates\":[{\"title\":\"曲名\",\"artist\":\"アーティスト\",\"album\":\"アルバムまたは空文字\",\"year\":2020,\"confidence\":0.8}]}"
    . "\n検索する歌詞の一節: " . $q;

$request = [
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => $prompt]],
    ]],
    'tools' => [['google_search' => new stdClass()]],
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

if ($response === null || (int)($response['status'] ?? 0) !== 200 || !is_array($response['json'] ?? null)) {
    $status = (int)($response['status'] ?? 0);
    error_log('Gemini lyrics probe failed: HTTP ' . $status);
    fail($status === 429 ? 503 : 502, $status === 429
        ? 'Gemini APIの利用上限に達しました。時間をおいて再試行してください'
        : 'Gemini APIに接続できませんでした（HTTP ' . $status . '）');
}

$data = $response['json'];
$text = '';
foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (is_array($part) && is_string($part['text'] ?? null)) {
        $text .= $part['text'];
    }
}
$text = trim(preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text);
$text = preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/i', '', $text) ?? $text;
$decoded = json_decode($text, true);
if (!is_array($decoded) || !is_array($decoded['candidates'] ?? null)) {
    error_log('Gemini lyrics probe returned invalid JSON');
    fail(502, 'Geminiから候補を読み取れませんでした。別の検索語で試してください');
}

$candidates = [];
foreach (array_slice($decoded['candidates'], 0, 5) as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }
    $title = trim((string)($candidate['title'] ?? ''));
    $artist = trim((string)($candidate['artist'] ?? ''));
    if ($title === '' || $artist === '') {
        continue;
    }
    $candidates[] = [
        'title' => mb_substr($title, 0, 255, 'UTF-8'),
        'artist' => mb_substr($artist, 0, 255, 'UTF-8'),
        'album' => mb_substr(trim((string)($candidate['album'] ?? '')), 0, 255, 'UTF-8'),
        'year' => is_numeric($candidate['year'] ?? null) ? (int)$candidate['year'] : null,
        'confidence' => is_numeric($candidate['confidence'] ?? null)
            ? max(0, min(1, (float)$candidate['confidence'])) : null,
        'expected_match' => $expectedTitle !== '' && $expectedArtist !== ''
            && lyrics_search_field_matches($title, $expectedTitle)
            && lyrics_search_field_matches($artist, $expectedArtist),
    ];
}

$grounding = $data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
$sources = [];
foreach ($grounding as $chunk) {
    $web = is_array($chunk) ? ($chunk['web'] ?? []) : [];
    $uri = is_array($web) ? trim((string)($web['uri'] ?? '')) : '';
    if ($uri !== '' && filter_var($uri, FILTER_VALIDATE_URL)) {
        $host = (string)(parse_url($uri, PHP_URL_HOST) ?? '');
        $sources[$uri] = ['title' => mb_substr((string)($web['title'] ?? $host), 0, 160), 'url' => $uri];
    }
}

$rank = null;
foreach ($candidates as $index => $candidate) {
    if ($candidate['expected_match']) {
        $rank = $index + 1;
        break;
    }
}

api_ok([
    'provider' => 'Gemini + Google Search',
    'model' => $model,
    'candidates' => $candidates,
    'candidate_count' => count($candidates),
    'expected_match_rank' => $rank,
    'sources' => array_slice(array_values($sources), 0, 8),
]);
