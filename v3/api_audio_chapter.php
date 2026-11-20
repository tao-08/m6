<?php
/**
 * =====================================================================
 *  api_audio_chapter.php — 音源のチャプター（MC・曲）を付ける / 消す API（JSON を返す）
 * =====================================================================
 *  band.php のプレーヤー（assets/app.js の setupAudioPlayers）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:
 *    {"action": "add", "band_id": 12, "key": "a:3", "kind": "mc" | "song", "at_ms": 61000}
 *    {"action": "replace", "band_id": 12, "key": "a:3", "chapters": [{"kind": "mc", "ms": 0}, ...]}  その音源のチャプターを全部置き換える
 *    {"action": "wave", "band_id": 12, "key": "a:3", "levels": "00ff80…"}  音源の波形を保存する（棒ごとの音量。16 進 2 文字 = 1 本）。最初の1回だけ保存し、あとからは書き換えない
 *    {"action": "delete", "band_id": 12, "chapter_id": 5}
 *    {"action": "move", "band_id": 12, "chapter_id": 5, "at_ms": 62000}  チャプターの時間を直す
 *      key … 'a:<band_audio の image_id>'（アップロードした MP3）か 'd:<ドライブのファイル ID>'（音源だけ）
 *  レスポンス（JSON）: {"chapters": [{"id", "kind", "ms"}, ...]}  その音源の今のチャプター全部（時間順）。wave は {"ok": true}
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

/** 1つのチャプター（kind と ms）が正しいか */
function audio_chapter_valid(mixed $kind, mixed $ms): bool
{
    return in_array($kind, ['mc', 'song'], true) && is_int($ms) && $ms >= 0 && $ms <= AUDIO_CHAPTER_MAX_MS;
}

if (in_array($action, ['add', 'replace', 'wave'], true)) {
    $key = (string)($body['key'] ?? '');
    $items = [['kind' => $body['kind'] ?? null, 'ms' => $body['at_ms'] ?? null]];
    $levels = (string)($body['levels'] ?? '');
    if ($action === 'wave') {
        $items = [];
        if (!preg_match('/^(?:[0-9a-f]{2}){16,120}$/', $levels)) {
            fail(400, '波形の指定が正しくありません');
        }
    } elseif ($action === 'replace') {
        $items = is_array($body['chapters'] ?? null) ? array_values($body['chapters']) : null;
        if ($items === null) {
            fail(400, 'チャプターの指定が正しくありません');
        }
        if (count($items) > AUDIO_CHAPTER_MAX) {
            fail(400, 'チャプターは ' . AUDIO_CHAPTER_MAX . ' つまでです');
        }
    }
    foreach ($items as $c) {
        if (!is_array($c) || !audio_chapter_valid($c['kind'] ?? null, $c['ms'] ?? null)) {
            fail(400, 'チャプターの指定が正しくありません');
        }
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
    if ($action === 'wave') { // 最初の1つだけ残す（INSERT IGNORE。あとから書き換えられない）
        $pdo->prepare('INSERT IGNORE INTO band_audio_wave (band_id, audio_key, levels) VALUES (?, ?, ?)')->execute([$bandId, $key, $levels]);
        api_ok(['ok' => true]);
    }
    $ins = $pdo->prepare('INSERT INTO band_audio_chapter (band_id, audio_key, kind, at_ms) VALUES (?, ?, ?, ?)');
    if ($action === 'add') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM band_audio_chapter WHERE band_id = ? AND audio_key = ?');
        $st->execute([$bandId, $key]);
        if ((int)$st->fetchColumn() >= AUDIO_CHAPTER_MAX) {
            fail(400, 'チャプターは ' . AUDIO_CHAPTER_MAX . ' つまでです');
        }
        $ins->execute([$bandId, $key, $items[0]['kind'], $items[0]['ms']]);
    } else { // 置き換え: 消して入れ直すまでを1つのトランザクションで（途中で失敗したら元のまま）
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM band_audio_chapter WHERE band_id = ? AND audio_key = ?')->execute([$bandId, $key]);
            foreach ($items as $c) {
                $ins->execute([$bandId, $key, $c['kind'], $c['ms']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
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
} elseif ($action === 'move') {
    $ms = $body['at_ms'] ?? null;
    if (!is_int($ms) || $ms < 0 || $ms > AUDIO_CHAPTER_MAX_MS) {
        fail(400, 'チャプターの指定が正しくありません');
    }
    $chapterId = is_int($body['chapter_id'] ?? null) ? $body['chapter_id'] : 0;
    $st = $pdo->prepare('SELECT audio_key FROM band_audio_chapter WHERE chapter_id = ? AND band_id = ?');
    $st->execute([$chapterId, $bandId]);
    $key = (string)$st->fetchColumn();
    if ($key === '') {
        fail(404, 'チャプターが見つかりません');
    }
    $pdo->prepare('UPDATE band_audio_chapter SET at_ms = ? WHERE chapter_id = ? AND band_id = ?')->execute([$ms, $chapterId, $bandId]);
} else {
    fail(400, '操作の指定が正しくありません');
}

api_ok(['chapters' => audio_chapter_list(audio_chapters($pdo, $bandId)[$key] ?? [])]);
