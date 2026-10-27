<?php
/**
 * =====================================================================
 *  studio_api.php — スタジオ割の保存・回答の取得 API（JSON。幹部と管理者だけ）
 * =====================================================================
 *  assets/studio.js が fetch() で呼ぶ。リクエストは POST + JSON: {"action": …, "live": ライブの id, …}
 *
 *    save  … {"v": 画面が持っている版, "state": {...}}
 *             → {"v": 新しい版}。版が違う（他の人が先に保存した）ときは 409 + {"conflict": true, "v", "state"}
 *    poll  … {"v": 画面が持っている版} → {"needs": {...}, "v", "state"（版が変わったときだけ）}
 *             数十秒おきに呼んで、部員の回答と他の幹部の変更を画面に反映する
 *    need  … {"band": band_id, "need": 1 | 0 | null} → {"needs": {...}}   幹部が代わりに要否を入れる
 *    open  … {"open": true | false} → {"open"}   回答の受付・締切
 *    token … {} → {"token"}   共有リンクの作り直し（古いリンクは使えなくなる）
 *
 *  「楽観ロック」（optimistic lock）について:
 *    保存のたびに version を +1 し、画面は「自分が読んだときの version」を一緒に送る。
 *    DB の version と違えば「その間に誰かが保存した」とわかるので、上書きせずに断る。
 *    ロックを取りっぱなしにする方式（悲観ロック）と違い、普段は誰も待たされないのが利点。Web アプリで定番のやり方。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio.php';

header('Content-Type: application/json; charset=utf-8');

/** エラーを JSON で返して終わる。never = この関数からは戻ってこない（必ず exit する）という型 */
function fail(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(['error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 成功を JSON で返して終わる */
function ok(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
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
verify_csrf(); // fetch() からは X-CSRF-Token ヘッダーで送る
session_write_close(); // セッションのロックをすぐ外す（数十秒おきに呼ばれるので、他のタブの読み込みを待たせない）

$raw = (string)file_get_contents('php://input'); // php://input = POST の本文そのもの（JSON はここから読む。$_POST はフォーム形式のときだけ）
if (strlen($raw) > STUDIO_MAX_STATE_BYTES) {
    fail(413, '大きすぎます');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    fail(400, 'JSON が読めません');
}
$liveId = is_int($body['live'] ?? null) ? $body['live'] : 0;
$action = $body['action'] ?? '';
$me = (int)$user['user_id'];
$pdo = db();

$data = studio_bands($pdo, $liveId);
$bandIds = array_keys($data['bands']);

try {
    switch ($action) {
        // ---------- 保存 ----------
        case 'save':
            $state = studio_sanitize($body['state'] ?? null, $bandIds);
            if ($state === null) {
                fail(400, '内容がおかしいです');
            }
            $clientV = is_int($body['v'] ?? null) ? $body['v'] : -1;
            // トランザクション: 「読む → 確かめる → 書く」の間に他の人が割り込まないように、まとめて1つの処理にする
            $pdo->beginTransaction();
            $plan = studio_load($pdo, $liveId, true); // FOR UPDATE でこの行をロック（同時に保存した2人目はここで待つ）
            if ($plan === null) {
                $pdo->rollBack();
                fail(404, 'スタジオ割が見つかりません（削除された可能性があります）');
            }
            if ((int)$plan['version'] !== $clientV) {
                $pdo->rollBack();
                fail(409, '他の人が先に保存しました', [
                    'conflict' => true, 'v' => (int)$plan['version'],
                    'state' => studio_sanitize($plan['state'], $bandIds),
                ]);
            }
            $v = $clientV + 1;
            $pdo->prepare('UPDATE studio_plan SET state = ?, version = ?, updated_by = ?, updated_at = NOW() WHERE live_id = ?')
                ->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $v, $me, $liveId]);
            $pdo->commit();
            ok(['v' => $v]);

        // ---------- 回答と他の人の変更の受け取り ----------
        case 'poll':
            $plan = studio_load($pdo, $liveId);
            if ($plan === null) {
                fail(404, 'スタジオ割が見つかりません（削除された可能性があります）');
            }
            $res = ['needs' => (object)studio_needs($pdo, $bandIds), 'v' => (int)$plan['version'], 'open' => (bool)$plan['answer_open']];
            if ((int)$plan['version'] !== ($body['v'] ?? null)) {
                $res['state'] = studio_sanitize($plan['state'], $bandIds);
            }
            ok($res);

        // ---------- 幹部が要否を入れる ----------
        case 'need':
            $band = is_int($body['band'] ?? null) ? $body['band'] : 0;
            $need = $body['need'] ?? null;
            if (!isset($data['bands'][$band]) || !in_array($need, [0, 1, null], true)) {
                fail(400, '内容がおかしいです');
            }
            studio_save_need($pdo, $band, $need, $me, false);
            ok(['needs' => (object)studio_needs($pdo, $bandIds)]);

        // ---------- 受付・締切 ----------
        case 'open':
            $open = ($body['open'] ?? false) === true;
            $st = $pdo->prepare('UPDATE studio_plan SET answer_open = ? WHERE live_id = ?');
            $st->execute([$open ? 1 : 0, $liveId]);
            ok(['open' => $open]);

        // ---------- 共有リンクの作り直し ----------
        case 'token':
            $token = studio_new_token();
            $pdo->prepare('UPDATE studio_plan SET share_token = ? WHERE live_id = ?')->execute([$token, $liveId]);
            ok(['token' => $token]);

        default:
            fail(400, '不明な操作です');
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : '保存できませんでした');
}
