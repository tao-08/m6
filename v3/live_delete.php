<?php
/**
 * live_delete.php — 日程（live_day）の削除。管理者だけ。
 * バンド・出演記録は外部キーの ON DELETE CASCADE で DB が一緒に消す（lib/repository.php の delete_live_day）。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/history.php'; // 変更履歴（元に戻すための「変更前の状態」を残す）
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
// 消す前の日程（バンド・セトリごと）と、日程が0になると一緒に消えるライブの行を履歴に残す
$op = change_begin($pdo, 'day_delete', '日程「' . change_label($pdo, 'day', $detailId) . '」を削除', (int)$liveId);
change_capture($pdo, $op, 'live', (int)$liveId);
change_capture_day($pdo, $op, $detailId);
delete_live_day($pdo, $detailId);
$pdo->commit();

// まだ他の日程が残っていればライブのページへ、全部消えたら一覧へ
$st = $pdo->prepare('SELECT 1 FROM live WHERE live_id = ?');
$st->execute([$liveId]);
flash('削除しました');
redirect($st->fetchColumn() ? 'live?id=' . (int)$liveId : './');
