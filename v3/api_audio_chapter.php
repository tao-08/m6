<?php
/**
 * =====================================================================
 *  api_audio_chapter.php — 音源のチャプター（MC・曲）を付ける / 消す API（JSON を返す）
 * =====================================================================
 *  band.php のプレーヤー（assets/app_player.js の setupAudioPlayers）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:
 *    {"action": "add", "band_id": 12, "key": "a:3", "kind": "mc" | "song", "at_ms": 61000}
 *    {"action": "replace", "band_id": 12, "key": "a:3", "chapters": [{"kind": "mc", "ms": 0}, ...]}  その音源のチャプターを全部置き換える
 *    {"action": "delete", "band_id": 12, "chapter_id": 5}
 *    {"action": "move", "band_id": 12, "chapter_id": 5, "at_ms": 62000}  チャプターの時間を直す
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

/** 1つのチャプター（kind と ms）が正しいか */
function audio_chapter_valid(mixed $kind, mixed $ms): bool
{
    return in_array($kind, ['mc', 'song'], true) && is_int($ms) && $ms >= 0 && $ms <= AUDIO_CHAPTER_MAX_MS;
}

if (in_array($action, ['add', 'replace', 'auto'], true)) {
    $key = (string)($body['key'] ?? '');
    $items = $action === 'auto' ? [] : [['kind' => $body['kind'] ?? null, 'ms' => $body['at_ms'] ?? null]];
    if ($action === 'replace') {
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
    if ($action === 'auto') { // Gemini に聴かせて、チャプターの下書きだけ返す（保存は replace）
        require_once __DIR__ . '/lib/gemini_chapters.php';
        require_once __DIR__ . '/lib/live_image.php'; // LIVE_IMAGE_DIR
        $gemini = (array)(config('gemini') ?? []);
        $apiKey = trim((string)($gemini['api_key'] ?? ''));
        $model = trim((string)($gemini['chapter_model'] ?? $gemini['lyrics_search_model'] ?? 'gemini-3.8-flash'));
        if ($apiKey === '' || !preg_match('/^gemini-[a-zA-Z0-9.-]+$/', $model)) {
            fail(503, '自動チャプターは未設定です');
        }
        session_write_close(); // 長く待つので、ほかの画面を待たせない
        @set_time_limit(0);
        $mime = 'audio/mpeg';
        if ($m ?? false) { // a:N（アップロードした MP3）
            $st = $pdo->prepare('SELECT file_name FROM band_audio WHERE image_id = ?');
            $st->execute([(int)$m[1]]);
            $path = LIVE_IMAGE_DIR . '/' . live_file_disk_name((string)$st->fetchColumn());
        } else { // d:ID（ドライブの音源。まだ落としていなければここで落とす）
            require_once __DIR__ . '/lib/drive_cache.php';
            $st = $pdo->prepare('SELECT mime_type, size FROM band_drive_file WHERE band_id = ? AND file_id = ?');
            $st->execute([$bandId, substr($key, 2)]);
            $row = $st->fetch();
            $mime = (string)$row['mime_type'];
            $size = (int)$row['size'];
            if (drive_cache_ensure(substr($key, 2), $size)) {
                fail(502, '音源を読み込めませんでした');
            }
            $path = drive_cache_path(substr($key, 2));
        }
        if (!is_file($path)) {
            fail(404, '音源が見つかりません');
        }
        $found = gemini_audio_chapters($path, $mime, $apiKey, $model);
        if (is_string($found)) {
            fail(502, $found);
        }
        api_ok(['draft' => $found]);
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
