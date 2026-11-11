<?php
/**
 * =====================================================================
 *  lib/api.php — JSON を返す API（api_*.php・studio_api.php・tt_builder_api.php）の共通部品
 * =====================================================================
 *  各 API は lib/bootstrap.php のあとにこれを読み込む。
 *  Content-Type の header() は出力より前に出す必要があるので、各 API のファイルの先頭に置いたまま。
 * =====================================================================
 */
declare(strict_types=1);

/** エラーの JSON を返して終わる（$extra = エラーの文言のほかに足すキー） */
function fail(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(['error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 成功の JSON を返して終わる */
function api_ok(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 入口のチェック。API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す。
 * 順番は 401（未ログイン）→ 403（$allow が false）→ 405（POST 以外）→ CSRF。通ればログイン中のユーザーを返す。
 * $allow は 401 を通ったあとで呼ぶので、関数を渡す（例: tab_allowed(...)）
 */
function api_user(?callable $allow = null, string $denied = ''): array
{
    $user = current_user();
    if ($user === null) {
        fail(401, 'ログインしてください');
    }
    if ($allow !== null && !$allow()) {
        fail(403, $denied);
    }
    if (!is_post()) {
        fail(405, 'POST only');
    }
    verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる
    return $user;
}

/** リクエスト本文の JSON を読む。php://input = 本文そのもの（JSON は $_POST に入らない）。読めなければ null */
function api_body(): mixed
{
    return json_decode((string)file_get_contents('php://input'), true);
}

/**
 * ❤・ミュートのトグル（付ける ⇔ 外す）。付けた = true、外した = false を返す。
 * まず消してみる。1行消えた = 付いていた → 外した。0行 = 付いていなかった → 付ける
 *   「SELECT してから INSERT / DELETE」より、連打しても食い違わない。INSERT IGNORE: 同時に2回届いても主キーで1行だけになる
 * $table / $keyCol は SQL に直接入れるので、呼び出し側のコードの定数だけを渡すこと（入力値は渡さない）。
 * $table は ($keyCol, user_id) の2列が主キーの表
 */
function api_toggle(PDO $pdo, string $table, string $keyCol, int $keyId, int $userId): bool
{
    $del = $pdo->prepare("DELETE FROM $table WHERE $keyCol = ? AND user_id = ?");
    $del->execute([$keyId, $userId]);
    $on = $del->rowCount() === 0;
    if ($on) {
        $pdo->prepare("INSERT IGNORE INTO $table ($keyCol, user_id) VALUES (?, ?)")->execute([$keyId, $userId]);
    }
    return $on;
}

/** api_toggle のあとに、その対象に付いている数も数える。[付けたか, 数] を返す（トランザクションは呼び出し側で張る） */
function api_toggle_count(PDO $pdo, string $table, string $keyCol, int $keyId, int $userId): array
{
    $on = api_toggle($pdo, $table, $keyCol, $keyId, $userId);
    $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $keyCol = ?");
    $st->execute([$keyId]);
    return [$on, (int)$st->fetchColumn()];
}

/**
 * AI に渡す画像のアップロード（$_FILES['images']）を調べて、[['path' => 一時ファイル, 'name' => 元の名前], ...] を返す。
 * $_FILES['images'] は複数ファイルのとき「name[] / tmp_name[] / error[] …」と項目ごとの配列になる（PHP の仕様）
 */
function api_ai_images(): array
{
    $images = [];
    $f = $_FILES['images'] ?? null;
    if (is_array($f) && is_array($f['name'])) {
        foreach ($f['name'] as $i => $name) {
            $err = (int)$f['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                // INI_SIZE / FORM_SIZE = サーバーの上限（php.ini）を超えた
                fail(400, in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '画像が大きすぎます' : '画像を受け取れませんでした');
            }
            // is_uploaded_file = 本当に今の POST でアップロードされた一時ファイルか（サーバー上の別のファイルのパスを偽装されない）
            if (!is_uploaded_file($f['tmp_name'][$i]) || $f['size'][$i] > 10 * 1024 * 1024) {
                fail(400, '画像は1枚10MBまでです');
            }
            $images[] = ['path' => $f['tmp_name'][$i], 'name' => basename((string)$name)];
        }
    }
    if (count($images) > AI_MAX_IMAGES) {
        fail(400, '画像は一度に ' . AI_MAX_IMAGES . ' 枚までです');
    }
    return $images;
}
