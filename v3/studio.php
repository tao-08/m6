<?php
/**
 * =====================================================================
 *  studio.php — スタジオ割（ライブ前の練習場所の割り当て。幹部と管理者だけ）
 * =====================================================================
 *  画面
 *    studio            … 予定のライブの一覧（スタジオ割を作ったものは編集へ、まだのものは作成ボタン）
 *    studio?live=12    … 編集画面（中身は assets/studio.js が作る。ここは JSON を埋め込むだけ）
 *  POST（すべて CSRF チェック）
 *    create … スタジオ割を作る（GET で作らないのは、リンクを開いただけで DB が変わるのを避けるため。
 *              「GET は読むだけ・変更は POST」は HTTP の基本ルール。ブラウザの先読みやクローラーが GET を勝手に踏むことがある）
 *    delete … スタジオ割を消す（練習の要否の回答は band に付いているので残る）
 *  編集中の保存・回答の取得・締切などは studio_api.php（JSON）へ。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio.php';
$user = require_executive();
$pdo = db();

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $liveId = (int)($_POST['live'] ?? 0);

    // ライブが本当にあるか確かめる（存在しない live_id を送られても作らない）
    $st = $pdo->prepare('SELECT name FROM live WHERE live_id = ?');
    $st->execute([$liveId]);
    $liveName = $st->fetchColumn();
    if ($liveName === false) {
        flash('ライブが見つかりません', 'error');
        redirect('studio');
    }

    if ($action === 'create') {
        if (studio_load($pdo, $liveId) === null) {
            $data = studio_bands($pdo, $liveId);
            $state = studio_default_state($data['days'], (string)$liveName);
            // INSERT IGNORE … 2人が同時に押して UNIQUE(live_id) にぶつかっても、エラーにせず1枚だけ作る
            $pdo->prepare('INSERT IGNORE INTO studio_plan (live_id, state, share_token, created_by, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())')
                ->execute([$liveId, json_encode($state, JSON_UNESCAPED_UNICODE), studio_new_token(), $user['user_id'], $user['user_id']]);
        }
        redirect('studio?live=' . $liveId);
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM studio_plan WHERE live_id = ?')->execute([$liveId]);
        flash('スタジオ割を削除しました');
        redirect('studio');
    }
    redirect('studio');
}

$liveId = (int)($_GET['live'] ?? 0);

/* ==================== 一覧 ==================== */
if ($liveId <= 0) {
    // 予定のライブ（最終日が今日以降）と、スタジオ割を作ってあるライブ
    //   SQL は「ライブごとの最初・最後の開催日 + スタジオ割の有無」を集めるだけにする（GROUP BY + 集計関数のみ）。
    //   絞り込み（今日以降か）と並べ替えは PHP でやる。
    //   なぜ？ → HAVING の OR や、別名を使った ORDER BY は、MySQL の設定（sql_mode）やバージョンで通らないことがある。
    //            SQL を単純にして、環境差で落ちない形にするのは実務でもよく使う安全策
    $st = $pdo->query('SELECT l.live_id, l.name, MIN(d.held_on) AS first_day, MAX(d.held_on) AS last_day,
            MAX(p.studio_plan_id) AS plan_id, MAX(p.updated_at) AS updated_at
        FROM live l
        JOIN live_day d ON d.live_id = l.live_id
        LEFT JOIN studio_plan p ON p.live_id = l.live_id
        GROUP BY l.live_id, l.name');
    $today = date('Y-m-d');
    // array_filter … 条件に合う行だけ残す。最終日が今日以降（日付 'YYYY-MM-DD' は文字列のまま比べて大小が合う）か、スタジオ割がある
    $lives = array_filter($st->fetchAll(), static fn($l) => $l['plan_id'] !== null || ($l['last_day'] !== null && $l['last_day'] >= $today));
    // 開催日の早い順（日付が未定の行は後ろ）
    usort($lives, static fn($a, $b) => [$a['first_day'] === null, (string)$a['first_day']] <=> [$b['first_day'] === null, (string)$b['first_day']]);
    render_header('スタジオ割', 'tools');
    ?>
    <link rel="stylesheet" href="<?= asset_url('assets/studio.css') ?>">
    <nav class="crumbs"><a href="tools">ツール</a></nav>
    <h1 class="display display--sm">スタジオ割</h1>
    <?php if (!$lives): ?>
        <div class="empty card"><p class="empty__title">予定のライブがありません</p></div>
    <?php else: ?>
        <div class="tool-list">
            <?php foreach ($lives as $l): ?>
                <?php if ($l['plan_id'] !== null): ?>
                    <a class="card tool-card" href="studio?live=<?= (int)$l['live_id'] ?>">
                        <span class="tool-card__icon"><?= icon('meeting_room') ?></span>
                        <span class="tool-card__body">
                            <span class="tool-card__name"><?= h($l['name']) ?></span>
                            <span class="muted small"><?= h(fmt_date($l['first_day'])) ?> ・ <?= h(date('n/j H:i', strtotime((string)$l['updated_at']))) ?> 更新</span>
                        </span>
                    </a>
                <?php else: ?>
                    <form method="post" class="card tool-card">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="live" value="<?= (int)$l['live_id'] ?>">
                        <span class="tool-card__icon"><?= icon('add') ?></span>
                        <span class="tool-card__body">
                            <span class="tool-card__name"><?= h($l['name']) ?></span>
                            <span class="muted small"><?= h(fmt_date($l['first_day'])) ?></span>
                        </span>
                        <button class="btn btn--sm" type="submit">作成</button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif;
    render_footer();
    exit;
}

/* ==================== 編集画面 ==================== */
$st = $pdo->prepare('SELECT name FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$liveName = $st->fetchColumn();
$plan = $liveName === false ? null : studio_load($pdo, $liveId);
if ($plan === null) {
    http_response_code(404);
    render_header('スタジオ割', 'tools');
    echo '<div class="empty card"><p class="empty__title">スタジオ割が見つかりません</p><a class="btn" href="studio">一覧へ</a></div>';
    render_footer();
    exit;
}
$data = studio_bands($pdo, $liveId);
// 共有リンクの URL。https かどうか・ホスト名はリクエストから組み立てる（サーバーの設定に書かなくても本番・ローカルの両方で動く）
$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$boot = [
    'live' => $liveId,
    'liveName' => (string)$liveName,
    'v' => (int)$plan['version'],
    'state' => studio_sanitize($plan['state'], array_keys($data['bands'])) ?? studio_default_state($data['days'], (string)$liveName),
    'days' => $data['days'],
    'bands' => array_values($data['bands']),
    'needs' => (object)studio_needs($pdo, array_keys($data['bands'])),
    'open' => (bool)$plan['answer_open'],
    'shareUrl' => $base . '/studio_answer?t=' . $plan['share_token'],
    'memberUrl' => $base . '/studio_answer?live=' . $liveId,
];
render_header($liveName . ' — スタジオ割', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/studio.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="studio">スタジオ割</a></nav>
<div class="std" data-studio>
    <noscript><p class="card">JavaScript を有効にしてください</p></noscript>
</div>
<form method="post" id="std-delete" data-confirm="「<?= h($liveName) ?>」のスタジオ割を削除します。元に戻せません。よろしいですか？" hidden>
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="live" value="<?= $liveId ?>">
</form>
<script type="application/json" id="std-data"><?= studio_json($boot) ?></script>
<script src="<?= asset_url('assets/studio.js') ?>" defer></script>
<?php render_footer();
