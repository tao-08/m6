<?php
/**
 * =====================================================================
 *  sheet_log.php — セッティングシートの PDF 作成ログ（管理者のみ）
 * =====================================================================
 *  誰が・いつ・どの型紙で・どのバンドの PDF を作ったかを新しい順に出す（1ページ50件）。
 *  記録は api_sheet_log.php（PDF を作れたあとに setting_sheet.js が送る）。削除はしない（無期限）。
 *  表示するだけ（POST を受けない）ので CSRF 対策は不要。値は全部 h() を通して出す。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // fmt_datetime()
require_once __DIR__ . '/lib/setting_sheet.php'; // all_sheet_templates()
require_admin();

const SHEET_LOG_PER_PAGE = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

// 1件多く取って「次のページがあるか」を知る
$st = db()->prepare('SELECT l.*, u.name AS user_name
    FROM sheet_log l
    LEFT JOIN user_account u ON u.user_id = l.user_id
    ORDER BY l.log_id DESC
    LIMIT ' . (SHEET_LOG_PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * SHEET_LOG_PER_PAGE));
$st->execute();
$rows = $st->fetchAll();
$hasMore = count($rows) > SHEET_LOG_PER_PAGE;
$rows = array_slice($rows, 0, SHEET_LOG_PER_PAGE);

$templates = all_sheet_templates();
$pageUrl = static fn(int $p) => 'sheet_log' . ($p > 1 ? '?page=' . $p : '');

render_header('PDF作成ログ');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">PDF作成ログ</h1>
    </div>
</section>

<?php if (!$rows): ?>
    <div class="empty card"><p class="empty__title">ログはまだありません</p></div>
<?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>日時</th><th>ユーザー</th><th>バンド</th><th>出演日</th><th>型紙</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h(fmt_datetime($r['created_at'])) ?></td>
                    <td><?= $r['user_name'] !== null ? h($r['user_name']) : '<span class="muted">削除済み</span>' ?></td>
                    <td><?php if ($r['band_id'] !== null): ?><a href="band?id=<?= (int)$r['band_id'] ?>"><?= h($r['band_name'] ?: '（無題）') ?></a>
                        <?php else: ?><?= h($r['band_name'] ?: '（無題）') ?><?php endif; ?></td>
                    <td><?= h($r['held_on']) ?></td>
                    <td><?= h($templates[$r['template_key']]['label'] ?? $r['template_key']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav class="history-pager">
        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= h($pageUrl($page - 1)) ?>">← 新しい順</a><?php endif; ?>
        <?php if ($hasMore): ?><a class="btn btn--ghost btn--sm" href="<?= h($pageUrl($page + 1)) ?>">古い順 →</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php render_footer();
