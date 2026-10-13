<?php
/**
 * =====================================================================
 *  history.php — 変更履歴と「元に戻す」（管理者のみ）
 * =====================================================================
 *  ・誰が・いつ・何を変えたかを新しい順に出す（1ページ50件。?live=ID でそのライブだけ）
 *  ・「この変更の前に戻す」で、その操作で変わったバンド・日程・ライブを変更前の状態に戻す
 *    戻す前の状態も履歴に残るので、戻したこともまた戻せる
 *  記録のしくみ・戻し方は lib/history.php
 *  1年より古い履歴は自動で消える（CHANGE_KEEP_DAYS）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/history.php';
require_admin();

$pdo = db();
const HISTORY_PER_PAGE = 50;

// ---------- 元に戻す ----------
if (is_post()) {
    verify_csrf();
    $opId = (int)($_POST['op_id'] ?? 0);
    try {
        $result = restore_change_op($pdo, $opId);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error'); // 戻せない理由（日程が無い・同じ名前のライブがある など）
        redirect('history' . (isset($_POST['live']) && ctype_digit((string)$_POST['live']) ? '?live=' . (int)$_POST['live'] : ''));
    }
    flash('#' . $opId . ' の変更の前に戻しました'
        . ($result['skipped'] ? '（メンバーなどが削除済みで戻せなかった行が ' . $result['skipped'] . ' 件あります）' : ''));
    redirect($result['live_id'] !== null ? 'live?id=' . $result['live_id'] : 'history');
}

// ---------- 一覧 ----------
$liveFilter = ctype_digit((string)($_GET['live'] ?? '')) ? (int)$_GET['live'] : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = $liveFilter !== null ? 'WHERE o.live_id = ?' : '';
$params = $liveFilter !== null ? [$liveFilter] : [];

// 1件多く取って「次のページがあるか」を知る
$st = $pdo->prepare("SELECT o.*, u.name AS user_name, r.name AS restored_name
    FROM change_op o
    LEFT JOIN user_account u ON u.user_id = o.user_id
    LEFT JOIN user_account r ON r.user_id = o.restored_by
    $where
    ORDER BY o.op_id DESC
    LIMIT " . (HISTORY_PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * HISTORY_PER_PAGE));
$st->execute($params);
$ops = $st->fetchAll();
$hasMore = count($ops) > HISTORY_PER_PAGE;
$ops = array_slice($ops, 0, HISTORY_PER_PAGE);

$units = [];    // op_id => [['unit_type', 'label', 'created'], ...]
$laterOf = [];  // op_id => その後に、同じバンド・日程・ライブを変えた操作の数（戻すとそれも消える）
if ($ops) {
    $ids = array_map(static fn($o) => (int)$o['op_id'], $ops);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT op_id, unit_type, label, snapshot IS NULL AS created FROM change_unit WHERE op_id IN ($in)
        ORDER BY FIELD(unit_type, 'live', 'day', 'band'), label");
    $st->execute($ids);
    foreach ($st as $u) {
        $units[(int)$u['op_id']][] = $u;
    }
    $st = $pdo->prepare("SELECT a.op_id, COUNT(DISTINCT b.op_id) AS n FROM change_unit a
        JOIN change_unit b ON b.unit_type = a.unit_type AND b.unit_id = a.unit_id AND b.op_id > a.op_id
        WHERE a.op_id IN ($in) GROUP BY a.op_id");
    $st->execute($ids);
    $laterOf = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

$filterName = null;
if ($liveFilter !== null) {
    $filterName = change_label($pdo, 'live', $liveFilter);
}
$typeNames = ['live' => 'ライブ', 'day' => '日程', 'band' => 'バンド'];
$pageUrl = static fn(int $p) => 'history?' . http_build_query(array_filter(['live' => $liveFilter, 'page' => $p > 1 ? $p : null]));

render_header('変更履歴');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">変更履歴</h1>
        <p class="muted small">誰が・いつ・何を変えたかの記録です（1年より前のものは自動で消えます）。<br>
            「この変更の前に戻す」を押すと、その変更で変わったバンド・日程・ライブが変更前の状態に戻ります。
            戻したことも履歴に残るので、間違えたらもう一度戻せます。</p>
        <?php if ($liveFilter !== null): ?>
            <p><?= icon('filter_alt') ?> <?= h($filterName) ?> の履歴だけ表示中 · <a href="history">すべて表示</a></p>
        <?php endif; ?>
    </div>
</section>

<?php if (!$ops): ?>
    <div class="empty card"><p class="empty__title">変更履歴はまだありません</p></div>
<?php else: ?>
    <ol class="history-list">
        <?php foreach ($ops as $o):
            $id = (int)$o['op_id'];
            $later = $laterOf[$id] ?? 0;
            $confirm = "#{$id}「" . (CHANGE_ACTIONS[$o['action']] ?? $o['action']) . "」の前の状態に戻します。"
                . ($later ? "この後に、同じバンド・日程への変更が {$later} 件あります。それも取り消されます。" : '')
                . 'よろしいですか？'; ?>
            <li class="card history-item<?= $o['restored_at'] ? ' is-restored' : '' ?>">
                <div class="history-item__head">
                    <span class="tag"><?= h(CHANGE_ACTIONS[$o['action']] ?? $o['action']) ?></span>
                    <span class="muted small mono">#<?= $id ?></span>
                    <span class="small"><?= h(fmt_datetime($o['created_at'])) ?></span>
                    <span class="small"><?= icon('person') ?><?= $o['user_name'] !== null ? h($o['user_name']) : '<span class="muted">削除済みのユーザー</span>' ?></span>
                </div>
                <p class="history-item__summary"><?= h($o['summary']) ?></p>
                <?php if (!empty($units[$id])): ?>
                    <details class="history-item__units">
                        <summary class="muted small">変わったもの（<?= count($units[$id]) ?>）</summary>
                        <ul>
                            <?php foreach ($units[$id] as $u): ?>
                                <li class="small"><span class="muted"><?= h($typeNames[$u['unit_type']]) ?></span> <?= h($u['label']) ?><?= $u['created'] ? ' <span class="muted">（この変更で追加）</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <div class="history-item__foot">
                    <?php if ($o['restored_at']): ?>
                        <span class="muted small"><?= icon('undo') ?> <?= h(fmt_datetime($o['restored_at'])) ?> に<?= $o['restored_name'] !== null ? h($o['restored_name']) . ' が' : '' ?>戻しました</span>
                    <?php endif; ?>
                    <?php if (!empty($units[$id])): ?>
                        <form method="post" class="inline-form" data-confirm="<?= h($confirm) ?>">
                            <?= csrf_field() ?><input type="hidden" name="op_id" value="<?= $id ?>">
                            <?php if ($liveFilter !== null): ?><input type="hidden" name="live" value="<?= $liveFilter ?>"><?php endif; ?>
                            <button class="btn btn--sm btn--ghost" type="submit"><?= icon('undo') ?> この変更の前に戻す</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <nav class="history-pager">
        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= h($pageUrl($page - 1)) ?>">← 新しい履歴</a><?php endif; ?>
        <?php if ($hasMore): ?><a class="btn btn--ghost btn--sm" href="<?= h($pageUrl($page + 1)) ?>">古い履歴 →</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php render_footer();
