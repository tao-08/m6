<?php
/**
 * =====================================================================
 *  api_setlist_detect.php — 動画から曲を検出する API（JSON を返す）。管理者だけ
 * =====================================================================
 *  band_edit.php の「動画から曲を検出」（assets/setlist_detect.js）が fetch() で呼ぶ。
 *  リクエスト（POST, multipart/form-data）: band_id, audio（ブラウザが動画から抜いたモノラル MP3）
 *  Gemini が聴き終わるまで待ち（1〜2分）、曲名と候補（自信が低い曲だけ）をセッションに置く → 画面が songs_edit?from_detect=1 で確認画面を開く
 *  レスポンス（JSON）: {"count": 見つかった曲数}
 *  エラー: {"error": "..."}（400 / 401 / 403 / 404 / 502 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/gemini_setlist.php';

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます'); // 送るたびに Gemini の料金がかかるので管理者だけ

const SETLIST_AUDIO_MAX_BYTES = 60 * 1024 * 1024; // バンドの MP3 と同じ上限

$cfg = gemini_setlist_config() ?? fail(503, '曲の検出は使えません');
$bandId = (int)($_POST['band_id'] ?? 0);
$st = db()->prepare('SELECT 1 FROM band WHERE band_id = ?');
$st->execute([$bandId]);
if (!$st->fetchColumn()) {
    fail(404, 'バンドが見つかりません');
}

$f = $_FILES['audio'] ?? null;
if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    fail(400, in_array((int)($f['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '音声が大きすぎます' : '音声を受け取れませんでした');
}
// 中身で確かめる（拡張子・ブラウザが言う種類は信用しない）
if ($f['size'] > SETLIST_AUDIO_MAX_BYTES || !in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']), ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)) {
    fail(400, '音声を受け取れませんでした');
}

session_write_close(); // 長く待つので、ほかの画面を待たせない
@set_time_limit(0);
$songs = gemini_setlist($f['tmp_name'], $cfg);
if (is_string($songs)) {
    fail(502, $songs);
}
if (!$songs) {
    fail(404, '曲が見つかりませんでした');
}
session_start(); // 曲名と候補を確認画面に渡すため、もう一度開く
$_SESSION['setlist_detect'][$bandId] = $songs;
api_ok(['count' => count($songs)]);
