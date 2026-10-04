<?php
/**
 * live_delete.php — 日程（live_detail）の削除。管理者だけ。
 * 実際の削除処理は lib/repository.php の delete_live_detail()（消す順番の説明もそこ）。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

if (!is_post()) { // 削除は必ず POST（GET だとリンクを踏ませるだけで消せてしまう）
    redirect('index.php');
}
verify_csrf();

$pdo = db();
$detailId = (int)($_POST['live_detail_id'] ?? 0);
$st = $pdo->prepare('SELECT live_id FROM live_detail WHERE live_detail_id = ?');
$st->execute([$detailId]);
$liveId = $st->fetchColumn();
if ($liveId === false) {
    flash('削除対象が見つかりません', 'error');
    redirect('index.php');
}

$pdo->beginTransaction();
delete_live_detail($pdo, $detailId);
$pdo->commit();

// まだ他の日程が残っていればライブのページへ、全部消えたら一覧へ
$st = $pdo->prepare('SELECT 1 FROM live_master WHERE live_id = ?');
$st->execute([$liveId]);
flash('削除しました');
redirect($st->fetchColumn() ? 'live.php?id=' . (int)$liveId : 'index.php');
