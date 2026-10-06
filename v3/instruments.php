<?php
/**
 * =====================================================================
 *  instruments.php — 楽器の管理（管理者のみ）
 * =====================================================================
 *  名簿の取り込みで「etc」から追加された楽器のうち、いらないものを消す画面。
 *
 *  消せないもの:
 *    ・最初から入っている楽器（BUILTIN_INSTRUMENTS）… プログラムが略称で探しているものがあるため
 *    ・出演記録で使われている楽器 … band_member の外部キーが ON DELETE RESTRICT なので DB も拒否する。
 *      先に、その楽器で登録されている人の楽器をバンド編集で直してから消す。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

$pdo = db();

if (is_post()) {
    verify_csrf();
    $id = (int)($_POST['instrument_id'] ?? 0);
    $st = $pdo->prepare('SELECT i.short_name, i.name, (SELECT COUNT(*) FROM band_member bm WHERE bm.instrument_id = i.instrument_id) AS used
        FROM instrument i WHERE i.instrument_id = ?');
    $st->execute([$id]);
    $target = $st->fetch();

    if (!$target) {
        flash('楽器が見つかりません', 'error');
    } elseif (in_array($target['short_name'], BUILTIN_INSTRUMENTS, true)) {
        flash('最初から入っている楽器は消せません', 'error');
    } elseif ((int)$target['used'] > 0) {
        flash("「{$target['name']}」は {$target['used']} 件の出演記録で使われているので消せません", 'error');
    } else {
        $pdo->prepare('DELETE FROM instrument WHERE instrument_id = ?')->execute([$id]);
        flash("「{$target['name']}（{$target['short_name']}）」を消しました");
    }
    redirect('instruments.php');
}

// 楽器ごとに「何件の出演記録で使われているか」も数える
$list = $pdo->query('SELECT i.instrument_id, i.short_name, i.name, COUNT(bm.band_id) AS used
    FROM instrument i LEFT JOIN band_member bm ON bm.instrument_id = i.instrument_id
    GROUP BY i.instrument_id, i.short_name, i.name, i.sort_order
    ORDER BY i.sort_order, i.instrument_id')->fetchAll();

render_header('楽器の管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">楽器の管理</h1>
        <p class="muted">名簿の取り込みで「etc」から追加された楽器を消せます。最初から入っている楽器と、出演記録で使われている楽器は消せません。</p>
    </div>
    <dl class="stats"><div><dt>楽器</dt><dd><?= count($list) ?></dd></div></dl>
</section>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table">
        <thead><tr><th>略称</th><th>楽器名</th><th class="num">使われている数</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $ins):
            $builtin = in_array($ins['short_name'], BUILTIN_INSTRUMENTS, true); ?>
            <tr>
                <td class="strong"><?= h($ins['short_name']) ?></td>
                <td><?= h($ins['name']) ?></td>
                <td class="num"><?= (int)$ins['used'] ?></td>
                <td class="num">
                    <?php if ($builtin): ?>
                        <span class="muted small">最初からある楽器</span>
                    <?php elseif ((int)$ins['used'] > 0): ?>
                        <span class="muted small">使われているので消せません</span>
                    <?php else: ?>
                        <form method="post" class="inline-form" data-confirm="「<?= h($ins['name']) ?>（<?= h($ins['short_name']) ?>）」を消します。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="instrument_id" value="<?= (int)$ins['instrument_id'] ?>">
                            <button class="btn btn--ghost btn--danger btn--sm" type="submit">削除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php render_footer();
