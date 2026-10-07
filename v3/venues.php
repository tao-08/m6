<?php
/**
 * =====================================================================
 *  venues.php — 会場の管理（管理者のみ）
 * =====================================================================
 *  会場の名前を直す・いらない会場を消す画面。
 *
 *  名前の変更: venue.name は UNIQUE なので、他の会場と同じ名前にはできない。
 *  削除: 日程で使われている会場は消せない。
 *    live_day の外部キーは ON DELETE SET NULL なので DB は消させてくれるが、
 *    消すとその日程の会場が「未設定」になってしまうため、ここで止めている。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();

$pdo = db();

if (is_post()) {
    verify_csrf();
    $id = (int)($_POST['venue_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $st = $pdo->prepare('SELECT v.name, (SELECT COUNT(*) FROM live_day d WHERE d.venue_id = v.venue_id) AS used
        FROM venue v WHERE v.venue_id = ?');
    $st->execute([$id]);
    $target = $st->fetch();

    if (!$target) {
        flash('会場が見つかりません', 'error');
    } elseif ($action === 'rename') {
        $name = trim((string)($_POST['name'] ?? ''));
        $dup = $pdo->prepare('SELECT 1 FROM venue WHERE name = ? AND venue_id <> ?');
        $dup->execute([$name, $id]);
        if ($name === '' || mb_strlen($name) > 50) {
            flash('会場名は1〜50文字で入力してください', 'error');
        } elseif ($dup->fetchColumn()) {
            flash("「{$name}」という会場はすでにあります", 'error');
        } elseif ($name !== $target['name']) {
            $pdo->prepare('UPDATE venue SET name = ? WHERE venue_id = ?')->execute([$name, $id]);
            flash("「{$target['name']}」を「{$name}」に変更しました");
        }
    } elseif ($action === 'merge') {
        // 統合: この会場を使っている日程を統合先に付け替えてから、この会場を消す
        $toId = (int)($_POST['to_id'] ?? 0);
        $st = $pdo->prepare('SELECT name FROM venue WHERE venue_id = ?');
        $st->execute([$toId]);
        $toName = $st->fetchColumn();
        if ($toName === false || $toId === $id) {
            flash('統合先の会場を選んでください（同じ会場は選べません）', 'error');
        } else {
            // 付け替えと削除は「両方成功」か「両方なし」にしたいのでトランザクションにする。
            // 先に付け替える: 先に消すと ON DELETE SET NULL で日程の会場が空になってしまう
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE live_day SET venue_id = ? WHERE venue_id = ?')->execute([$toId, $id]);
                $pdo->prepare('DELETE FROM venue WHERE venue_id = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash("「{$target['name']}」を「{$toName}」に統合しました（日程 {$target['used']} 件を付け替え）");
        }
    } elseif ($action === 'delete') {
        if ((int)$target['used'] > 0) {
            flash("「{$target['name']}」は {$target['used']} 件の日程で使われているので消せません", 'error');
        } else {
            $pdo->prepare('DELETE FROM venue WHERE venue_id = ?')->execute([$id]);
            flash("「{$target['name']}」を消しました");
        }
    }
    redirect('venues.php');
}

// 会場ごとに「何件の日程で使われているか」も数える
$list = $pdo->query('SELECT v.venue_id, v.name, COUNT(d.live_day_id) AS used
    FROM venue v LEFT JOIN live_day d ON d.venue_id = v.venue_id
    GROUP BY v.venue_id, v.name
    ORDER BY v.name')->fetchAll();

render_header('会場の管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">会場の管理</h1>
        <p class="muted">会場の名前の変更・統合・削除ができます。表記ゆれで2つに分かれた会場は「統合」でまとめてください（日程は統合先に付け替わります）。日程で使われている会場は削除できません。</p>
    </div>
    <dl class="stats"><div><dt>会場</dt><dd><?= count($list) ?></dd></div></dl>
</section>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table table--edit">
        <thead><tr><th>会場名</th><th class="num">使われている日程</th><th>他の会場に統合</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $v): ?>
            <tr>
                <td>
                    <form method="post" class="row-form"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="venue_id" value="<?= (int)$v['venue_id'] ?>">
                        <input name="name" value="<?= h($v['name']) ?>" maxlength="50" required aria-label="会場名">
                        <button class="btn btn--ghost btn--sm" type="submit">名前を変更</button>
                    </form>
                </td>
                <td class="num"><?= (int)$v['used'] ?></td>
                <td>
                    <?php if (count($list) > 1): ?>
                        <form method="post" class="row-form" data-confirm="「<?= h($v['name']) ?>」を選んだ会場に統合します。「<?= h($v['name']) ?>」は消えて元に戻せません。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="merge">
                            <input type="hidden" name="venue_id" value="<?= (int)$v['venue_id'] ?>">
                            <select name="to_id" required aria-label="統合先の会場">
                                <option value="">統合先を選択</option>
                                <?php foreach ($list as $to): if ($to['venue_id'] === $v['venue_id']) continue; ?>
                                    <option value="<?= (int)$to['venue_id'] ?>"><?= h($to['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn--ghost btn--sm" type="submit">統合</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="num">
                    <?php if ((int)$v['used'] > 0): ?>
                        <!-- 消せないときはグレーアウト。disabled のボタンはマウスの反応が鈍いので、
                             外側の span に data-tooltip を付けて CSS の :hover でポップアップを出す（assets/app.css の .tooltip） -->
                        <span class="tooltip" data-tooltip="使用されているため削除できません" tabindex="0">
                            <button class="btn-trash" type="button" disabled aria-label="削除（使用されているため削除できません）"><?= icon('delete') ?></button>
                        </span>
                    <?php else: ?>
                        <form method="post" class="inline-form" data-confirm="「<?= h($v['name']) ?>」を消します。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="venue_id" value="<?= (int)$v['venue_id'] ?>">
                            <button class="btn-trash" type="submit" aria-label="「<?= h($v['name']) ?>」を削除" title="削除"><?= icon('delete') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?>
            <tr><td colspan="4" class="muted">まだ会場が登録されていません</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php render_footer();
