<?php
/**
 * =====================================================================
 *  tt_builder_api.php — タイムテーブル作成ツールの自動保存・同期 API（JSON。管理者だけ）
 * =====================================================================
 *  assets/tt_builder.js が数秒おき（と変更したとき）に fetch() で呼ぶ。保存と「他の人の変更の受け取り」を1本でやる。
 *
 *  リクエスト（POST, JSON）: {"id": 下書き, "v": 画面が持っている版, "ops": [操作, ...], "focus": 操作中の行 id | null}
 *  レスポンス（JSON）      : {"v": 今の版, "state": {...}（版が変わったときだけ）, "rejected": 受け付けなかった操作の数, "people": [...]}
 *
 *  操作は lib/tt_builder.php の tt_apply_op で1つずつ当てる。下書きの行は SELECT … FOR UPDATE でロックするので、
 *  2人が同時に送っても順番に当たり、片方の変更が消えることはない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tt_builder.php';

header('Content-Type: application/json; charset=utf-8');

function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if ($user === null) {
    fail(401, 'ログインしてください');
}
if (!is_executive()) {
    fail(403, '幹部のみ使えます');
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf();
// 数秒おきに呼ばれるので、セッションのロックをすぐ外す（他のタブ・ページの読み込みを待たせない）
session_write_close();

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 200_000) {
    fail(413, '変更が大きすぎます');
}
$body = json_decode($raw, true);
$id = is_int($body['id'] ?? null) ? $body['id'] : 0;
$clientV = is_int($body['v'] ?? null) ? $body['v'] : -1;
$ops = is_array($body['ops'] ?? null) ? array_slice($body['ops'], 0, 200) : [];
$focus = $body['focus'] ?? null;
$focus = is_string($focus) && preg_match(TT_ROW_ID, $focus) ? $focus : null;
$me = (int)$user['user_id'];

$pdo = db();
try {
    $pdo->beginTransaction();
    $draft = tt_load($pdo, $id, (bool)$ops); // 書くときだけロック
    if ($draft === null) {
        $pdo->rollBack();
        fail(404, '下書きが見つかりません（削除された可能性があります）');
    }
    $state = $draft['state'];
    $v = (int)$draft['version'];
    $rejected = 0;
    if ($ops) {
        $applied = 0;
        foreach ($ops as $op) {
            tt_apply_op($state, $op) ? $applied++ : $rejected++;
        }
        if ($applied > 0) {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE);
            if (strlen($json) > TT_MAX_STATE_BYTES) {
                $pdo->rollBack();
                fail(413, '下書きが大きすぎます');
            }
            $v++;
            $pdo->prepare('UPDATE timetable_draft SET state = ?, title = ?, version = ?, updated_by = ?, updated_at = NOW() WHERE draft_id = ?')
                ->execute([$json, $state['title'], $v, $me, $id]);
        }
    }
    // 在席（INSERT か UPDATE。主キー (draft_id, user_id) なので1人1行）
    $pdo->prepare('INSERT INTO timetable_draft_presence (draft_id, user_id, focus, last_seen) VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE focus = VALUES(focus), last_seen = VALUES(last_seen)')->execute([$id, $me, $focus]);
    $people = tt_presence($pdo, $id, $me);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : '保存できませんでした');
}

$res = ['v' => $v, 'rejected' => $rejected, 'people' => $people];
if ($v !== $clientV || $rejected > 0) {
    $res['state'] = $state;
}
echo json_encode($res, JSON_UNESCAPED_UNICODE);
