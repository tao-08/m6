<?php
/**
 * =====================================================================
 *  api_setlist_detect.php — バンドの YouTube から曲を検出する API（JSON を返す）。管理者だけ
 * =====================================================================
 *  songs_edit.php の「YouTube から曲を検出」（assets/setlist_detect.js）が fetch() で呼ぶ。
 *  リクエスト（POST）: band_id
 *  バンドの YouTube（限定公開も）から音声を落とし（lib/ytdlp.php）、Gemini が聴き終わるまで待つ（1〜2分）。
 *  曲名・候補（自信が低い曲だけ）・始まる時刻をセッションに置く → 画面が songs_edit?from_detect=1 で確認画面を開く
 *  レスポンス（JSON）: {"count": 見つかった曲数}
 *  エラー: {"error": "..."}（400 / 401 / 403 / 404 / 502 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/youtube.php';
require_once __DIR__ . '/lib/ytdlp.php';
require_once __DIR__ . '/lib/gemini_setlist.php';

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます'); // 送るたびに Gemini の料金がかかるので管理者だけ

$cfg = gemini_setlist_config() ?? fail(503, '曲の検出は使えません');
$bandId = (int)($_POST['band_id'] ?? 0);
// コピー元のアーティスト（オムニバスのバンドは持たないので NULL）。わからない曲が残ったとき、Gemini に伝えてもう一度聴かせる
$st = db()->prepare('SELECT b.youtube_url, IF(b.is_omnibus = 1, NULL, a.name) AS artist FROM band b LEFT JOIN artist a ON a.artist_id = b.artist_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch() ?: fail(404, 'バンドが見つかりません');
$videoId = youtube_video_id((string)$band['youtube_url']) ?? fail(400, 'YouTube のリンクがありません');

session_write_close(); // 長く待つので、ほかの画面を待たせない
@set_time_limit(0);
$audio = ytdlp_audio($videoId) ?? fail(502, 'YouTube から音声を取得できませんでした');
try {
    $songs = gemini_setlist($audio, 'audio/aac', $cfg, $band['artist']); // m4a（AAC）をそのまま聴かせる
} finally {
    @unlink($audio);
}
if (is_string($songs)) {
    fail(502, $songs);
}
if (!array_filter(array_column($songs, 'title'), 'strlen')) { // 曲名が1つも無ければ、確認画面を開いても意味がない
    fail(404, '曲が見つかりませんでした');
}
session_start(); // 曲名と候補を確認画面に渡すため、もう一度開く
$_SESSION['setlist_detect'][$bandId] = $songs;
api_ok(['count' => count($songs)]);
