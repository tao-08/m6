<?php
/**
 * =====================================================================
 *  api_audio_chapter.php — 音源のチャプター（MC・曲）を付ける / 消す API（JSON を返す）
 * =====================================================================
 *  band.php のプレーヤー（assets/app_player.js の setupAudioPlayers）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:
 *    {"action": "add", "band_id": 12, "key": "a:3", "kind": "mc" | "song" | "end" | "se", "at_ms": 61000}
 *    {"action": "replace", "band_id": 12, "key": "a:3", "chapters": [{"kind": "mc", "ms": 0}, ...]}  その音源のチャプターを全部置き換える
 *    {"action": "delete", "band_id": 12, "chapter_id": 5}
 *    {"action": "move", "band_id": 12, "chapter_id": 5, "at_ms": 62000}  チャプターの時間を直す
 *      key … 'a:<band_audio の image_id>'（アップロードした MP3）か 'd:<ドライブのファイル ID>'（音源だけ）か 'y:<動画 ID>'（バンドの YouTube と、その YouTube の区分の音源。band.php のカード・video_chapters.php）
 *    {"action": "auto", "band_id": 12, "key": "a:3"}  裏で Gemini に聴かせ、終わったらその音源のチャプターを置き換える（lib/chapter_job.php）
 *      すぐ {"state": "running"} を返す。画面を閉じても続く。結果は status で見る
 *    {"action": "status", "band_id": 12, "key": "a:3"}   {"state": "none" | "queued" | "running" | "saved" | "error", "error"?, "chapters": [...]}
 *    {"action": "discard", "band_id": 12, "key": "a:3"}  解析中のものを止める（「中止」。結果を書かない）
 *    {"action": "numbers", "band_id": 12, "key": "a:3", "numbers": {"5": 3, "8": 4}}  曲のチャプター（chapter_id）ごとに当たるセトリの何曲目か（migrations/157）
 *  付ける・消す・直すと、解析の状況に「編集済み」と出す（chapter_job_edited）
 *  レスポンス（JSON）: {"chapters": [{"id", "kind", "ms"}, ...]}  その音源の今のチャプター全部（時間順）
 *  エラー            : {"error": "..."}（400 / 401 / 404 / 405）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/drive.php'; // drive_id_valid・audio_chapter_list
require_once __DIR__ . '/lib/chapter_job.php'; // chapter_job_edited

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
    return in_array($kind, ['mc', 'song', 'end', 'se'], true) && is_int($ms) && $ms >= 0 && $ms <= AUDIO_CHAPTER_MAX_MS;
}

if (in_array($action, ['add', 'replace', 'auto', 'status', 'discard', 'numbers'], true)) {
    $key = (string)($body['key'] ?? '');
    $items = in_array($action, ['auto', 'status', 'discard', 'numbers'], true) ? [] : [['kind' => $body['kind'] ?? null, 'ms' => $body['at_ms'] ?? null]];
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
    } elseif (preg_match('/^y:([A-Za-z0-9_-]{11})$/', $key, $y)) {
        require_once __DIR__ . '/lib/youtube.php';
        $st = $pdo->prepare('SELECT band_id, youtube_url FROM band WHERE band_id = ?');
        $st->execute([$bandId]);
        $ids = array_map('youtube_video_id', band_video_urls($pdo, $st->fetchAll())[$bandId] ?? []);
        $st = null;
        if (!in_array($y[1], $ids, true)) {
            fail(404, '動画が見つかりません');
        }
    } else {
        fail(400, '音源の指定が正しくありません');
    }
    if ($st !== null && !$st->fetchColumn()) {
        fail(404, '音源が見つかりません');
    }
    if (in_array($action, ['auto', 'status', 'discard'], true)) { // 裏で解析して、終わったら保存する（lib/chapter_job.php）
        require_once __DIR__ . '/lib/chapter_job.php';
        $id = chapter_job_id($key);
        $job = chapter_job_get($id);
        if ($action === 'discard') {
            chapter_job_put($id, null);
            api_ok(['state' => 'none']);
        }
        if ($action === 'status' || in_array($job['state'] ?? '', ['queued', 'running'], true)) { // もう解析している（二重に払わない）
            $out = $job ? array_intersect_key($job, ['state' => 1, 'error' => 1]) : ['state' => 'none'];
            api_ok($out + ['chapters' => audio_chapter_list(audio_chapters($pdo, $bandId)[$key] ?? [])]);
        }
        $cfg = chapter_job_config() ?? fail(503, '自動チャプターは未設定です');
        if (chapter_job_running_count() >= CHAPTER_JOB_MAX) {
            fail(429, 'ほかの音源を解析中です。終わってから試してください');
        }
        chapter_job_put($id, ['state' => 'running', 'band_id' => $bandId, 'at' => $at = time()]);
        api_detach(['state' => 'running']);
        chapter_job_run($id, $bandId, $at, $cfg);
        exit;
    }
    if ($action === 'numbers') {
        $nums = $body['numbers'] ?? null;
        if (!is_array($nums) || count($nums) > AUDIO_CHAPTER_MAX) {
            fail(400, '曲の指定が正しくありません');
        }
        foreach ($nums as $id => $no) {
            if (!ctype_digit((string)$id) || !is_int($no) || $no < 0 || $no > 999) {
                fail(400, '曲の指定が正しくありません');
            }
        }
        // この音源の曲のチャプターだけ（ほかの音源・バンドの id は何も変わらない）
        $up = $pdo->prepare("UPDATE band_audio_chapter SET song_no = ? WHERE chapter_id = ? AND band_id = ? AND audio_key = ? AND kind = 'song'");
        $pdo->beginTransaction();
        foreach ($nums as $id => $no) {
            $up->execute([$no, (int)$id, $bandId, $key]);
        }
        $pdo->commit();
        chapter_job_edited($key, $bandId);
        api_ok(['chapters' => audio_chapter_list(audio_chapters($pdo, $bandId)[$key] ?? [])]);
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
chapter_job_edited($key, $bandId); // 解析の状況に「編集済み」と出す

api_ok(['chapters' => audio_chapter_list(audio_chapters($pdo, $bandId)[$key] ?? [])]);
