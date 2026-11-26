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
 *  1曲だけ検出し直す（確認画面の ↻）: from, to（秒）も送ると、その区間だけ落として聴かせ、{"candidates": [{title, artist}]} を返す
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

$section = null;
if (isset($_POST['from'])) {
    $from = max(0, (int)$_POST['from']);
    $to = (int)($_POST['to'] ?? 0);
    $section = [$from, $to > $from ? min($to, $from + 900) : $from + 420]; // 最後の曲は次の曲が無いので7分。長すぎる区間は15分で切る
}

session_write_close(); // 長く待つので、ほかの画面を待たせない
@set_time_limit(0);
$why = null;
$audio = ytdlp_audio($videoId, $why, $section) ?? fail(502, 'YouTube から音声を取得できませんでした / ' . $why); // 管理者だけの画面なので理由も出す
if ($section !== null) {
    try {
        $cands = gemini_setlist_one($audio, 'audio/aac', $cfg);
    } finally {
        @unlink($audio);
    }
    if (is_string($cands)) {
        fail(502, $cands . ' / ' . gemini_why());
    }
    if (!$cands) {
        fail(404, '曲が見つかりませんでした');
    }
    api_ok(['candidates' => $cands]);
}
try {
    $songs = gemini_setlist($audio, 'audio/aac', $cfg, $band['artist']); // m4a（AAC）をそのまま聴かせる
} finally {
    @unlink($audio);
}
if (is_string($songs)) {
    fail(502, $songs . " / " . gemini_why()); // 管理者だけの画面なので、直せるように理由も出す
}
if (!array_filter(array_column($songs, 'title'), 'strlen')) { // 曲名が1つも無ければ、確認画面を開いても意味がない
    fail(404, '曲が見つかりませんでした');
}
session_start(); // 曲名と候補を確認画面に渡すため、もう一度開く
$_SESSION['setlist_detect'][$bandId] = $songs;
api_ok(['count' => count($songs)]);
