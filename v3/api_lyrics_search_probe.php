<?php
/**
 * api_lyrics_search_probe.php — lrc.red の管理者向け検索精度検証。
 * 歌詞本文・検索語・検証結果はDBやログに保存しない。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/lyrics_search.php';

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', '歌詞検索の検証は管理者のみ利用できます');

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

$lyricsConfig = (array)(config('lyrics_search') ?? []);
$contactEmail = trim((string)($lyricsConfig['contact_email'] ?? ''));
if ($contactEmail === '') {
    $contactEmail = trim((string)config('mail_from'));
}
if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    fail(503, 'config.php の common.lyrics_search.contact_email に、連絡先メールアドレスを設定してください');
}

$userAgent = APP_NAME . ' (' . APP_URL . '; contact: ' . $contactEmail . ')';
$variants = lyrics_search_query_variants($q);
$hits = [];
$seen = [];
$variantsUsed = 0;

foreach ($variants as $index => $variant) {
    $url = 'https://lrc.red/search.json?' . http_build_query([
        'q' => $variant,
        'in' => 'lyrics',
    ]);
    $response = album_http($url, [], null, $userAgent);
    if ($response === null || (int)$response['status'] !== 200 || !is_array($response['json'])) {
        $status = (int)($response['status'] ?? 0);
        error_log('lrc.red lyrics search failed: HTTP ' . $status);
        if ($index === 0) {
            fail($status === 429 ? 503 : 502, $status === 429
                ? 'lrc.red の検索回数制限に達しました。時間をおいて再試行してください'
                : 'lrc.red に接続できませんでした');
        }
        break;
    }

    $list = $response['json']['hits'] ?? null;
    if (!is_array($list)) {
        error_log('lrc.red lyrics search returned an invalid response');
        if ($index === 0) {
            fail(502, 'lrc.red から不正な応答がありました');
        }
        break;
    }
    $variantsUsed++;

    foreach ($list as $source) {
        if (!is_array($source)) {
            continue;
        }
        $title = trim((string)($source['title'] ?? ''));
        $artist = trim((string)($source['artist'] ?? ''));
        if ($title === '' || $artist === '') {
            continue;
        }

        $hit = [
            'isrc' => mb_substr(trim((string)($source['isrc'] ?? '')), 0, 20),
            'title' => mb_substr($title, 0, 255),
            'artist' => mb_substr($artist, 0, 255),
            'album' => mb_substr(trim((string)($source['album'] ?? '')), 0, 255),
            'year' => is_numeric($source['year'] ?? null) ? (int)$source['year'] : null,
            'duration' => is_numeric($source['duration'] ?? null) ? (float)$source['duration'] : null,
            'query_variant' => $index === 0 ? '原文' : ($index === 1 ? '正規化' : 'かな変換'),
        ];
        $key = lyrics_search_hit_key($hit);
        if ($key === 'meta::' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $hits[] = $hit;
        if (count($hits) >= 10) {
            break 2;
        }
    }

    // 充分な候補が出たら、追加の表記変換検索はしない。
    if (count($hits) >= 5) {
        break;
    }
}

$expectedRank = null;
if ($expectedTitle !== '' && $expectedArtist !== '') {
    foreach ($hits as $index => $hit) {
        if (lyrics_search_hit_matches($hit, $expectedTitle, $expectedArtist)) {
            $expectedRank = $index + 1;
            break;
        }
    }
}

// iTunes照合は検証用の期待曲が入力された場合だけ1回行う。
// 歌詞検索結果を試聴URLの有無だけで除外しない。
$itunesChecked = false;
$itunesCandidates = [];
$itunesExpectedMatch = false;
$itunesPreviewAvailable = false;
if ($expectedTitle !== '' && $expectedArtist !== '') {
    $found = itunes_search_tracks($expectedTitle . ' ' . $expectedArtist, 10);
    if ($found !== null) {
        $itunesChecked = true;
        foreach ($found as $candidate) {
            $matches = lyrics_search_field_matches((string)$candidate['title'], $expectedTitle)
                && lyrics_search_field_matches((string)$candidate['artist_name'], $expectedArtist);
            if ($matches) {
                $itunesExpectedMatch = true;
                $itunesPreviewAvailable = $itunesPreviewAvailable || $candidate['preview_url'] !== null;
            }
            $itunesCandidates[] = [
                'title' => $candidate['title'],
                'artist' => $candidate['artist_name'],
                'album' => $candidate['album_title'],
                'preview_available' => $candidate['preview_url'] !== null,
                'expected_match' => $matches,
            ];
            if (count($itunesCandidates) >= 5) {
                break;
            }
        }
    }
}

api_ok([
    'provider' => 'lrc.red',
    'hits' => array_slice($hits, 0, 10),
    'hit_count' => count($hits),
    'variants_used' => $variantsUsed,
    'expected_match_rank' => $expectedRank,
    'itunes_checked' => $itunesChecked,
    'itunes_expected_match' => $itunesChecked ? $itunesExpectedMatch : null,
    'itunes_preview_available' => $itunesChecked ? $itunesPreviewAvailable : null,
    'itunes_candidates' => $itunesCandidates,
]);
