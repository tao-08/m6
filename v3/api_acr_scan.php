<?php
/**
 * =====================================================================
 *  api_acr_scan.php — 動画から曲を検出する（ACRCloud）API（JSON を返す）
 * =====================================================================
 *  band_edit.php の「動画から曲を検出」（assets/acr_scan.js）が fetch() で呼ぶ。管理者だけ（料金がかかるため）。
 *
 *  リクエスト（POST, multipart/form-data）:
 *    action=upload, band_id, audio（ブラウザが動画から抜いたモノラル MP3）… ACRCloud に送り、file_id を band に保存
 *    action=status, band_id                                              … 解析の状態
 *  レスポンス（JSON）: {"state": "processing" | "done" | "none" | "error"}
 *    done = 曲が見つかった（songs_edit?band=…&from_acr=1 で確認画面へ）/ none = 見つからなかった
 *  エラー: {"error": "..."}（400 / 401 / 404 / 502 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/acrcloud.php';

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます'); // 送るたびに ACRCloud の料金がかかるので管理者だけ

$env = acr_config() ?? fail(503, '曲の検出は使えません');
$bandId = (int)($_POST['band_id'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT acr_file_id FROM band WHERE band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    fail(404, 'バンドが見つかりません');
}

if (($_POST['action'] ?? '') === 'upload') {
    $f = $_FILES['audio'] ?? null;
    if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        fail(400, in_array((int)($f['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '音声が大きすぎます' : '音声を受け取れませんでした');
    }
    if ($f['size'] > ACR_UPLOAD_MAX_BYTES) {
        fail(400, '音声が大きすぎます');
    }
    // 中身で確かめる（拡張子・ブラウザが言う種類は信用しない）
    if (!in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']), ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)) {
        fail(400, '音声を受け取れませんでした');
    }
    // 管理者だけの API なので、直すための手がかりとして ACRCloud の理由も出す
    $fileId = acr_upload_audio($env, $f['tmp_name'], 'band-' . $bandId . '.mp3', $why) ?? fail(502, '送信できませんでした: ' . $why);
    $pdo->prepare('UPDATE band SET acr_file_id = ? WHERE band_id = ?')->execute([$fileId, $bandId]);
    api_ok(['state' => 'processing']);
}

$fileId = (string)($band['acr_file_id'] ?? '');
if ($fileId === '') {
    fail(404, '動画を選び直してください');
}
$file = acr_fetch_file($env, $fileId);
$state = (int)($file['state'] ?? 0); // 取れないとき（一時的な通信エラー）は処理中として、もう一度待たせる
$result = match (true) {
    $state === 0 => 'processing',
    $state === 1 => acr_setlist_titles(is_array($file['results'] ?? null) ? $file['results'] : []) ? 'done' : 'none',
    $state === -1 => 'none',
    default => 'error',
};
// done は確認画面（songs_edit?from_acr=1）が結果を読んでから消す。それ以外はもう使わないので、ここで消す
if ($result === 'none' || $result === 'error') {
    acr_forget($pdo, $env, $bandId, $fileId);
}
api_ok(['state' => $result]);
