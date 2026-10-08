<?php
/**
 * live_delete.php — 日程（live_day）の削除。管理者だけ。
 * バンド・出演記録は外部キーの ON DELETE CASCADE で DB が一緒に消す（lib/repository.php の delete_live_day）。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

if (!is_post()) { // 削除は必ず POST（GET だとリンクを踏ませるだけで消せてしまう）
    redirect('./');
}
verify_csrf();

$pdo = db();
$detailId = (int)($_POST['live_day_id'] ?? 0);
$st = $pdo->prepare('SELECT live_id FROM live_day WHERE live_day_id = ?');
$st->execute([$detailId]);
$liveId = $st->fetchColumn();
if ($liveId === false) {
    flash('削除対象が見つかりません', 'error');
    redirect('./');
}

$pdo->beginTransaction();
delete_live_day($pdo, $detailId);
$pdo->commit();

// まだ他の日程が残っていればライブのページへ、全部消えたら一覧へ
$st = $pdo->prepare('SELECT 1 FROM live WHERE live_id = ?');
$st->execute([$liveId]);
flash('削除しました');
redirect($st->fetchColumn() ? 'live?id=' . (int)$liveId : './');
