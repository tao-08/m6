<?php
/**
 * =====================================================================
 *  api_audio_chapter.php — 音源のチャプター（MC・曲）を付ける / 消す API（JSON を返す）
 * =====================================================================
 *  band.php のプレーヤー（assets/app.js の setupAudioPlayers）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:
 *    {"action": "add", "band_id": 12, "key": "a:3", "kind": "mc" | "song", "at_ms": 61000}
 *    {"action": "delete", "band_id": 12, "chapter_id": 5}
 *      key … 'a:<band_audio の image_id>'（アップロードした MP3）か 'd:<ドライブのファイル ID>'（音源だけ）
 *  レスポンス（JSON）: {"chapters": [{"id", "kind", "ms"}, ...]}  その音源の今のチャプター全部（時間順）
 *  エラー            : {"error": "..."}（400 / 401 / 404 / 405）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/drive.php'; // drive_id_valid・audio_chapter_list

header('Content-Type: application/json; charset=utf-8');

api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

const AUDIO_CHAPTER_MAX = 200; // 1つの音源に付けられる数（際限なく増やされないように）
const AUDIO_CHAPTER_MAX_MS = 86400000; // 24 時間

$body = api_body();
$body = is_array($body) ? $body : [];
$bandId = is_int($body['band_id'] ?? null) ? $body['band_id'] : 0;
$action = (string)($body['action'] ?? '');
$pdo = db();

$st = $pdo->prepare('SELECT 1 FROM band WHERE band_id = ?');
$st->execute([$bandId]);
if ($bandId <= 0 || !$st->fetchColumn()) {
    fail(404, 'バンドが見つかりません');
}

if ($action === 'add') {
    $key = (string)($body['key'] ?? '');
    $kind = (string)($body['kind'] ?? '');
    $atMs = $body['at_ms'] ?? null;
    if (!in_array($kind, ['mc', 'song'], true) || !is_int($atMs) || $atMs < 0 || $atMs > AUDIO_CHAPTER_MAX_MS) {
        fail(400, 'チャプターの指定が正しくありません');
    }
    // key が、このバンドの音源を指しているか
    if (preg_match('/^a:([1-9][0-9]{0,9})$/', $key, $m)) {
        $st = $pdo->prepare('SELECT 1 FROM band_audio WHERE image_id = ? AND band_id = ?');
        $st->execute([(int)$m[1], $bandId]);
    } elseif (str_starts_with($key, 'd:') && drive_id_valid(substr($key, 2))) {
        $st = $pdo->prepare("SELECT 1 FROM band_drive_file WHERE band_id = ? AND file_id = ? AND mime_type LIKE 'audio/%'");
        $st->execute([$bandId, substr($key, 2)]);
    } else {
        fail(400, '音源の指定が正しくありません');
    }
    if (!$st->fetchColumn()) {
        fail(404, '音源が見つかりません');
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM band_audio_chapter WHERE band_id = ? AND audio_key = ?');
    $st->execute([$bandId, $key]);
    if ((int)$st->fetchColumn() >= AUDIO_CHAPTER_MAX) {
        fail(400, 'チャプターは ' . AUDIO_CHAPTER_MAX . ' つまでです');
    }
    $pdo->prepare('INSERT INTO band_audio_chapter (band_id, audio_key, kind, at_ms) VALUES (?, ?, ?, ?)')->execute([$bandId, $key, $kind, $atMs]);
} elseif ($action === 'delete') {
    $chapterId = is_int($body['chapter_id'] ?? null) ? $body['chapter_id'] : 0;
    // このバンドのチャプターだけ（chapter_id だけでは消さない）
    $st = $pdo->prepare('SELECT audio_key FROM band_audio_chapter WHERE chapter_id = ? AND band_id = ?');
    $st->execute([$chapterId, $bandId]);
    $key = (string)$st->fetchColumn();
    if ($key === '') {
        fail(404, 'チャプターが見つかりません');
    }
    $pdo->prepare('DELETE FROM band_audio_chapter WHERE chapter_id = ? AND band_id = ?')->execute([$chapterId, $bandId]);
} else {
    fail(400, '操作の指定が正しくありません');
}

api_ok(['chapters' => audio_chapter_list(audio_chapters($pdo, $bandId)[$key] ?? [])]);
