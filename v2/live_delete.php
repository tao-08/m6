<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();

if (!is_post()) {
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
// band_master / band_member は ON DELETE CASCADE で一緒に消える
$pdo->prepare('DELETE FROM live_detail WHERE live_detail_id = ?')->execute([$detailId]);
// 日程が1つも無くなったライブ自体も消す
$st = $pdo->prepare('SELECT COUNT(*) FROM live_detail WHERE live_id = ?');
$st->execute([$liveId]);
$remaining = (int)$st->fetchColumn();
if ($remaining === 0) {
    $pdo->prepare('DELETE FROM live_master WHERE live_id = ?')->execute([$liveId]);
}
// どのバンドにも出ていない & アカウントに紐付いていないメンバーを掃除
$pdo->exec('DELETE m FROM member m
    LEFT JOIN band_member bm ON bm.member_id = m.member_id
    LEFT JOIN user_index u ON u.member_id = m.member_id
    WHERE bm.member_id IS NULL AND u.user_auto_id IS NULL');
$pdo->commit();

flash('削除しました');
redirect($remaining ? 'live.php?id=' . (int)$liveId : 'index.php');
