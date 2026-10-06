<?php
/**
 * =====================================================================
 *  api_album_order.php — 好きなアルバムの並び順を保存する API（JSON を返す）
 * =====================================================================
 *  member.php でアルバムのカードをドラッグして離したときに、
 *  JavaScript（assets/app.js の setupAlbumSort）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"member_id": 1, "order": ["spotify:4aawyAB9vmqN3uQ7FjRGTy", "itunes:1441164426", ...]}
 *                             order = アルバムのキー（lib/albums.php）を「新しい並び順」で全部
 *  レスポンス（JSON）      :  {"ok": true}
 *  エラー                 :  {"error": "..."}（400 / 403 / 409）
 *
 *  権限: 本人だけ（追加と同じ。自分の好きなアルバムの順番を他人が決めるのはおかしい）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/** エラーの JSON を返して終わる */
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
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$body = json_decode((string)file_get_contents('php://input'), true);
$memberId = (int)($body['member_id'] ?? 0);
$order = $body['order'] ?? null;

if ($memberId !== $user['member_id']) {
    fail(403, '並び替えできるのは本人だけです');
}
if (!is_array($order)) {
    fail(400, '並び順が送られていません');
}
// 中身は全部 "source:ID" の文字列のはず。それ以外（数字や配列）は '' にして、下の比較で不一致にする
$order = array_map(static fn($v) => is_string($v) ? $v : '', array_values($order));

$pdo = db();
$st = $pdo->prepare('SELECT CONCAT(source, ":", album_id) FROM member_favorite_album WHERE member_id = ?');
$st->execute([$memberId]);
$current = $st->fetchAll(PDO::FETCH_COLUMN);

// 「送られてきたID の集まり」と「DB にあるID の集まり」が完全に同じかを確かめる。
//   別のタブで追加・削除した後の古い画面から送られてきた場合などは、ここで止める。
//   （並びは関係ないので、両方ソートしてから比べる。重複があれば数がずれて不一致になる）
//   SORT_STRING: 必ず「文字列として」並べる（数字っぽい文字列が混ざっても比べ方がぶれないように）
$sent = $order;
sort($sent, SORT_STRING);
sort($current, SORT_STRING);
if ($sent !== $current) {
    fail(409, 'アルバムの一覧が変わっています。ページを再読み込みしてやり直してください');
}

// 主キーが (member_id, sort_order) なので、いきなり 3→1 にすると「1番が2つ」になってエラーになる。
//   ① いったん全部を +1000 して空いている番号へ逃がす（上限30枚なので 1001〜1030 に収まる）
//   ② 新しい順番で 1, 2, 3... を振り直す
//   途中で失敗したら rollBack() で元の並びに戻る
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE member_favorite_album SET sort_order = sort_order + 1000
            WHERE member_id = ? ORDER BY sort_order DESC')
        ->execute([$memberId]);
    $update = $pdo->prepare('UPDATE member_favorite_album SET sort_order = ?
            WHERE member_id = ? AND source = ? AND album_id = ?');
    foreach ($order as $i => $key) {
        // 上で DB の一覧と完全に一致すると確かめたので、ここでは必ず "source:ID" に分けられる
        [$source, $albumId] = explode(':', $key, 2);
        $update->execute([$i + 1, $memberId, $source, $albumId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail(500, config('debug') ? $e->getMessage() : '並び順を保存できませんでした');
}

echo json_encode(['ok' => true]);
