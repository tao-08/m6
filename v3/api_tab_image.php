<?php
/**
 * =====================================================================
 *  api_tab_image.php — TAB 譜・曲に添付する画像の追加・削除 API（JSON を返す）
 * =====================================================================
 *  assets/tab.js（プレイヤー・編集）と、曲の一覧ページ（tab?song= / tab?key=）の曲のメモの画像から、fetch() で呼ぶ。
 *  画像ファイルの保存・縮小・検査は lib/live_image.php、「何枚まで・どの表か」は lib/attach_image.php（kind = song_tab / song_meta）。
 *
 *  リクエスト（POST, multipart/form-data。CSRF は X-CSRF-Token ヘッダー）:
 *    action=add    kind=tab|song  id=（tab なら tab_id、song なら曲キー）  images[]=ファイル（複数可）
 *    action=remove kind=tab|song  id=（同上）  image_id=画像ID
 *  レスポンス（JSON）: {"ok":true, "images":[画像ID, ...]}   エラー: {"error":"..."}（400 / 401 / 403 / 404 / 405）
 *
 *  TAB を削除しても画像は消さない（復元すると同じ tab_id で戻るので、そのとき画像も戻る。song_tab_image に外部キーが無い理由）。
 *  誰でも（今は管理人だけ）追加・削除できる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tabs.php';
require_once __DIR__ . '/lib/attach_image.php';

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
if (!tab_allowed()) {
    fail(403, '管理人のみ利用できます'); // 今は管理人だけ（lib/tabs.php の tab_allowed）
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf();

$pdo = db();
$userId = (int)$user['user_id'];
$action = (string)($_POST['action'] ?? '');
$kind = (string)($_POST['kind'] ?? '');
$id = (string)($_POST['id'] ?? '');

// ---- 持ち主（TAB なら tab_id、曲なら song_meta の meta_id）を決める ----
if ($kind === 'tab') {
    $attachKind = 'song_tab';
    $ownerId = (int)$id;
    $st = $pdo->prepare('SELECT 1 FROM song_tab WHERE tab_id = ?');
    $st->execute([$ownerId]);
    if ($ownerId <= 0 || !$st->fetchColumn()) {
        fail(404, 'TAB が見つかりません');
    }
} elseif ($kind === 'song') {
    $attachKind = 'song_meta';
    if (!tab_key_valid($id)) {
        fail(400, '曲の指定がおかしいです');
    }
    // 曲のメモの行（song_meta）が無ければ作る。画像は meta_id にぶら下がる
    $pdo->prepare('INSERT IGNORE INTO song_meta (song_key, updated_by) VALUES (?, ?)')->execute([$id, $userId]);
    $st = $pdo->prepare('SELECT meta_id FROM song_meta WHERE song_key = ?');
    $st->execute([$id]);
    $ownerId = (int)$st->fetchColumn();
} else {
    fail(400, '指定がおかしいです');
}
$max = ATTACH_KINDS[$attachKind]['max'];
$current = attach_images($pdo, $attachKind, [$ownerId])[$ownerId] ?? [];

$newFiles = [];
$removeFiles = [];
try {
    $pdo->beginTransaction();
    if ($action === 'add') {
        $uploads = live_image_uploads($_FILES['images'] ?? null);
        if (!$uploads) {
            $pdo->rollBack();
            fail(400, '画像を選んでください');
        }
        if (count($current) + count($uploads) > $max) {
            $pdo->rollBack();
            fail(400, '画像は ' . $max . ' 枚までです');
        }
        attach_add($pdo, $attachKind, $ownerId, $uploads, $userId, $newFiles);
    } elseif ($action === 'remove') {
        // その持ち主の画像だけ消せる（attach_remove が持ち主で絞っている）
        $removeFiles = attach_remove($pdo, $attachKind, $ownerId, [(int)($_POST['image_id'] ?? 0)]);
    } else {
        $pdo->rollBack();
        fail(400, '操作の指定がおかしいです');
    }
    $pdo->commit();
} catch (RuntimeException $e) { // 画像のエラー（形式・大きさなど）。取り消して理由を返す
    $pdo->rollBack();
    array_map('live_image_unlink', $newFiles);
    fail(400, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    array_map('live_image_unlink', $newFiles);
    fail(500, config('debug') ? $e->getMessage() : '画像を保存できませんでした');
}
array_map('live_image_unlink', $removeFiles); // コミットのあとにファイルを消す

$images = attach_images($pdo, $attachKind, [$ownerId])[$ownerId] ?? [];
echo json_encode(['ok' => true, 'images' => array_map(static fn($r) => (int)$r['image_id'], $images),
    'pdfs' => tab_pdf_ids($images), 'files' => tab_file_ids($images)], JSON_UNESCAPED_UNICODE);
