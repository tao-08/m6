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

/**
 * 戻すと何がどう変わるか（change_preview の結果）を出す。確認画面と、一覧の「変わったもの」を開いたときの両方で使う
 */
function render_history_preview(array $preview, bool $asLog = false): void
{
    $typeNames = ['live' => 'ライブ', 'day' => '日程', 'band' => 'バンド'];
    // $asLog: 一覧用。その変更の「変更前 → 変更後」として見せる（変更前 = 戻した後、変更後 = 今）
    $kinds = $asLog ? ['change' => '変更', 'delete' => '追加', 'revive' => '削除'] : ['change' => '変更', 'delete' => '削除', 'revive' => '復活'];
    $same = array_filter($preview, static fn($p) => $p['kind'] === 'same');
    $name = static fn(array $p) => $p['url'] !== null ? '<a href="' . h($p['url']) . '">' . h($p['label']) . '</a>' : h($p['label']);
    ?>
    <div class="history-preview">
        <?php foreach ($preview as $p): if ($p['kind'] === 'same') continue; ?>
            <section class="card history-unit history-unit--<?= h($p['kind']) ?>">
                <h2 class="history-unit__title"><span class="tag tag--<?= h($p['kind']) ?>"><?= h($kinds[$p['kind']]) ?></span>
                    <span class="muted small"><?= h($typeNames[$p['type']]) ?></span> <?= $name($p) ?></h2>
                <?php if ($asLog): ?>
                <?php elseif ($p['kind'] === 'delete'): ?>
                    <p class="small">この変更で追加されたものなので、<strong>中のバンド・メンバー・セトリごと消えます</strong>。</p>
                <?php elseif ($p['kind'] === 'revive'): ?>
                    <p class="small">今は削除されています。変更前の状態で<strong>同じ ID のまま復活します</strong>。</p>
                <?php endif; ?>
                <?php if ($p['changes']): ?>
                    <div class="table-scroll table-scroll--flush">
                        <table class="table history-diff">
                            <thead><tr><th>項目</th><?= $asLog ? '<th>変更前</th><th>変更後</th>' : '<th>現在</th><th>復元後</th>' ?></tr></thead>
                            <tbody>
                                <?php foreach ($p['changes'] as [$item, $now, $after]): ?>
                                    <tr><th scope="row"><?= h($item) ?></th><?php if ($asLog): ?><td class="history-diff__after"><?= change_cell_html($after) ?></td><td class="history-diff__now"><?= change_cell_html($now) ?></td><?php else: ?><td class="history-diff__now"><?= change_cell_html($now) ?></td><td class="history-diff__after"><?= change_cell_html($after) ?></td><?php endif; ?></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <?php if ($same): ?>
            <p class="muted small">ほかの <?= count($same) ?> 件（<?= implode('・', array_map($name, array_slice(array_values($same), 0, 5))) ?><?= count($same) > 5 ? ' など' : '' ?>）は、変更なし。</p>
        <?php endif; ?>
        <?php if (count($same) === count($preview)): ?>
            <p><?= icon('info') ?> 変更なし</p>
        <?php endif; ?>
    </div>
    <?php
}

// ---------- 一覧の「変わったもの」を開いたときに読み込む部分（?op=ID&part=1）: 確認画面と同じ比べる表だけ返す ----------
if (ctype_digit((string)($_GET['op'] ?? '')) && isset($_GET['part'])) {
    render_history_preview(change_preview($pdo, (int)$_GET['op']), true);
    exit;
}

$liveFilter = ctype_digit((string)($_GET['live'] ?? '')) ? (int)$_GET['live'] : null;
$backUrl = 'history' . ($liveFilter !== null ? '?live=' . $liveFilter : '');
$typeNames = ['live' => 'ライブ', 'day' => '日程', 'band' => 'バンド'];

// ---------- 確認画面（?op=ID）: 戻すと今の状態から何が変わるかを見せてから戻す ----------
if (ctype_digit((string)($_GET['op'] ?? ''))) {
    $opId = (int)$_GET['op'];
    $st = $pdo->prepare('SELECT o.*, u.name AS user_name FROM change_op o LEFT JOIN user_account u ON u.user_id = o.user_id WHERE o.op_id = ?');
    $st->execute([$opId]);
    $op = $st->fetch();
    if (!$op) {
        flash('その変更履歴は見つかりません（1年より前のものは消えています）', 'error');
        redirect($backUrl);
    }
    // この後に、同じバンド・日程・ライブを変えた操作（戻すと、それも取り消される）
    $st = $pdo->prepare('SELECT DISTINCT o.op_id, o.action, o.summary, o.created_at, u.name AS user_name FROM change_unit a
        JOIN change_unit b ON b.unit_type = a.unit_type AND b.unit_id = a.unit_id AND b.op_id > a.op_id
        JOIN change_op o ON o.op_id = b.op_id
        LEFT JOIN user_account u ON u.user_id = o.user_id
        WHERE a.op_id = ? ORDER BY o.op_id');
    $st->execute([$opId]);
    $later = $st->fetchAll();
    $preview = change_preview($pdo, $opId);

    render_header('元に戻す前の確認');
    ?>
    <section class="hero">
        <div>
            <p class="eyebrow">Admin · 変更履歴</p>
            <h1 class="display display--sm">復元内容</h1>
            <p><span class="tag"><?= h(CHANGE_ACTIONS[$op['action']] ?? $op['action']) ?></span>
                <span class="muted small mono">#<?= $opId ?></span>
                <span class="small"><?= h(fmt_datetime($op['created_at'])) ?> · <?= $op['user_name'] !== null ? h($op['user_name']) : '削除済みのユーザー' ?></span></p>
            <p><?= h($op['summary']) ?></p>
            <p class="muted small">「元に戻す」を押すと、今の状態が下の「復元後」になります。戻す前の状態も履歴に残るので、あとでやり直せます。</p>
        </div>
    </section>

    <?php if ($later): ?>
        <div class="flash flash--warn">
            <p><?= icon('warning', 'flag-icon') ?> この後に、同じバンド・日程への変更が <?= count($later) ?> 件あります。戻すと、それも取り消されます。</p>
            <ul class="small">
                <?php foreach ($later as $l): ?>
                    <li>#<?= (int)$l['op_id'] ?> <?= h(CHANGE_ACTIONS[$l['action']] ?? $l['action']) ?> · <?= h(fmt_datetime($l['created_at'])) ?> · <?= $l['user_name'] !== null ? h($l['user_name']) : '削除済みのユーザー' ?> · <?= h($l['summary']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php render_history_preview($preview); ?>

    <form method="post" class="form-actions history-confirm">
        <?= csrf_field() ?><input type="hidden" name="op_id" value="<?= $opId ?>">
        <?php if ($liveFilter !== null): ?><input type="hidden" name="live" value="<?= $liveFilter ?>"><?php endif; ?>
        <a class="btn btn--ghost" href="<?= h($backUrl) ?>">やめる</a>
        <button class="btn btn--primary" type="submit"><?= icon('undo') ?> 元に戻す</button>
    </form>
    <?php
    render_footer();
    exit;
}

// ---------- 一覧 ----------
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
    $st = $pdo->prepare("SELECT op_id, unit_type, unit_id, label, snapshot IS NULL AS created FROM change_unit WHERE op_id IN ($in)
        ORDER BY FIELD(unit_type, 'live', 'day', 'band'), label");
    $st->execute($ids);
    $all = $st->fetchAll();
    // 今もあるものはそのページへのリンクにする
    $unitUrls = change_unit_urls($pdo, array_map(static fn($u) => ['type' => $u['unit_type'], 'id' => (int)$u['unit_id']], $all));
    foreach ($all as $u) {
        $u['url'] = $unitUrls[$u['unit_type'] . ':' . $u['unit_id']] ?? null;
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
$pageUrl = static fn(int $p) => 'history?' . http_build_query(array_filter(['live' => $liveFilter, 'page' => $p > 1 ? $p : null]));

render_header('変更履歴');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">変更履歴</h1>
        <p class="muted small">誰が・いつ・何を変えたかの記録です（1年より前のものは自動で消えます）。<br>
            「この変更の前に戻す」を押すと、戻すと何がどう変わるかを確認してから、変更前の状態に戻せます。
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
            $later = $laterOf[$id] ?? 0; ?>
            <li class="card history-item<?= $o['restored_at'] ? ' is-restored' : '' ?>">
                <div class="history-item__head">
                    <span class="tag"><?= h(CHANGE_ACTIONS[$o['action']] ?? $o['action']) ?></span>
                    <span class="muted small mono">#<?= $id ?></span>
                    <span class="small"><?= h(fmt_datetime($o['created_at'])) ?></span>
                    <span class="small"><?= icon('person') ?><?= $o['user_name'] !== null ? h($o['user_name']) : '<span class="muted">削除済みのユーザー</span>' ?></span>
                </div>
                <p class="history-item__summary"><?= h($o['summary']) ?></p>
                <?php if (!empty($units[$id])): ?>
                    <!-- 開くと、確認画面と同じ「戻すと何がどう変わるか」の表を読み込む（下の script） -->
                    <details class="history-item__units" data-preview="history?op=<?= $id ?>&amp;part=1">
                        <summary class="muted small">変更内容（<?= count($units[$id]) ?>）</summary>
                        <ul>
                            <?php foreach ($units[$id] as $u): ?>
                                <li class="small"><span class="muted"><?= h($typeNames[$u['unit_type']]) ?></span>
                                    <?= $u['url'] !== null ? '<a href="' . h($u['url']) . '">' . h($u['label']) . '</a>' : h($u['label']) ?><?= $u['created'] ? ' <span class="muted">（この変更で追加）</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="history-item__compare" hidden>
                                                        <div class="history-item__compare-body"><p class="muted small">読み込み中…</p></div>
                        </div>
                    </details>
                <?php endif; ?>
                <div class="history-item__foot">
                    <?php if ($o['restored_at']): ?>
                        <span class="muted small"><?= icon('undo') ?> <?= h(fmt_datetime($o['restored_at'])) ?> に<?= $o['restored_name'] !== null ? h($o['restored_name']) . ' が' : '' ?>戻しました</span>
                    <?php endif; ?>
                    <?php if (!empty($units[$id])): ?>
                        <?php if ($later): ?><span class="muted small">この後の変更 <?= $later ?> 件</span><?php endif; ?>
                        <!-- 押すと確認画面（戻すと何がどう変わるか）へ。戻すのはそこで -->
                        <a class="btn btn--sm btn--ghost" href="history?<?= h(http_build_query(array_filter(['op' => $id, 'live' => $liveFilter]))) ?>"><?= icon('undo') ?> この変更の前に戻す</a>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <nav class="history-pager">
        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= h($pageUrl($page - 1)) ?>">← 新しい履歴</a><?php endif; ?>
        <?php if ($hasMore): ?><a class="btn btn--ghost btn--sm" href="<?= h($pageUrl($page + 1)) ?>">古い履歴 →</a><?php endif; ?>
    </nav>
    <script>
    // 「変わったもの」を初めて開いたときに、戻すと何がどう変わるかの表を読み込む（一覧を開くたびに全部計算すると重いので）
    document.querySelectorAll('.history-item__units[data-preview]').forEach((box) => {
        box.addEventListener('toggle', async () => {
            if (!box.open || box.dataset.loaded) return;
            box.dataset.loaded = '1';
            const compare = box.querySelector('.history-item__compare');
            const body = box.querySelector('.history-item__compare-body');
            compare.hidden = false;
            try {
                const res = await fetch(box.dataset.preview, { credentials: 'same-origin' });
                if (!res.ok) throw new Error(res.status);
                body.innerHTML = await res.text(); // サーバーが h() を通して作った HTML
            } catch (e) {
                body.innerHTML = '<p class="muted small">読み込めませんでした。「この変更の前に戻す」から確認できます。</p>';
                delete box.dataset.loaded;
            }
        });
    });
    </script>
<?php endif; ?>
<?php render_footer();
