<?php
/**
 * =====================================================================
 *  members_merge.php — 重複メンバーの統合（管理者のみ）
 * =====================================================================
 *  名簿の表記ゆれで「清水啓之介」「清水啓乃介」のように同じ人が2人登録されてしまったとき、
 *  片方の出演記録をもう片方に付け替えて1人にまとめる。
 *
 *  ・上半分: 名前が似ているペアを自動で探して候補として出す
 *  ・下半分: 自分で2人を選んで統合する
 *  実際の付け替え処理は lib/repository.php の merge_members()。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

$pdo = db();

if (is_post()) {
    verify_csrf();
    $from = (int)($_POST['from'] ?? 0);
    $to = (int)($_POST['to'] ?? 0);
    $st = $pdo->prepare('SELECT member_id, name FROM member WHERE member_id IN (?, ?)');
    $st->execute([$from, $to]);
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR); // [member_id => name]
    if (count($names) !== 2) {
        flash('統合する2人を選んでください（同じ人は選べません）', 'error');
        redirect('members_merge');
    }
    $pdo->beginTransaction();
    try {
        merge_members($pdo, $from, $to);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // 自分のアカウントの紐付けが変わったかもしれないので、セッションも合わせる
    if ($_SESSION['user']['member_id'] === $from) {
        $_SESSION['user']['member_id'] = $to;
    }
    flash("「{$names[$from]}」を「{$names[$to]}」に統合しました");
    redirect('members_merge');
}

// ---- 全メンバーと出演数 ----
$members = $pdo->query('SELECT m.member_id, m.name,
        (SELECT COUNT(DISTINCT x.band_id) FROM (' . MEMBERSHIP_SQL . ') x WHERE x.member_id = m.member_id) AS bands
    FROM member m ORDER BY m.name')->fetchAll();

// ---- 似ている名前のペアを探す ----
// 全員 × 全員を比べる（n 人なら n×(n-1)/2 回）。数百人なら一瞬で終わる。
$keys = array_map(static fn($m) => member_key($m['name']), $members);
$candidates = [];
for ($i = 0; $i < count($members); $i++) {
    for ($j = $i + 1; $j < count($members); $j++) {
        // キーが完全一致（「岩﨑太一」と「岩崎太一」など）も、似ているものも候補
        if ($keys[$i] === $keys[$j] || names_look_similar($keys[$i], $keys[$j])) {
            $candidates[] = [$members[$i], $members[$j]];
        }
    }
}

render_header('メンバーの統合');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">メンバーの統合</h1>
        <p class="muted">表記ゆれで同じ人が2人いるときに、出演記録を片方にまとめます。<strong>元に戻せない</strong>ので、本当に同じ人か確認してから押してください。</p>
    </div>
</section>

<h2 class="section-title">名前が似ているペア（<?= count($candidates) ?>）</h2>
<?php if (!$candidates): ?>
    <p class="muted">似ている名前のペアは見つかりませんでした <?= icon('celebration') ?></p>
<?php else: ?>
    <div class="merge-list">
        <?php foreach ($candidates as [$a, $b]): ?>
            <div class="card merge-item">
                <div class="merge-item__names">
                    <a href="member?id=<?= (int)$a['member_id'] ?>" class="strong"><?= h($a['name']) ?></a> <span class="muted small"><?= (int)$a['bands'] ?>組</span>
                    <span class="muted"><?= icon('swap_horiz') ?></span>
                    <a href="member?id=<?= (int)$b['member_id'] ?>" class="strong"><?= h($b['name']) ?></a> <span class="muted small"><?= (int)$b['bands'] ?>組</span>
                </div>
                <div class="merge-item__actions">
                    <!-- 「◯◯に統合」= その人を残す。もう片方の出演記録がその人に移って、もう片方は消える -->
                    <?php foreach ([[$b, $a], [$a, $b]] as [$from, $to]): ?>
                        <form method="post" data-confirm="「<?= h($from['name']) ?>」を「<?= h($to['name']) ?>」に統合します。元に戻せません。">
                            <?= csrf_field() ?>
                            <input type="hidden" name="from" value="<?= (int)$from['member_id'] ?>">
                            <input type="hidden" name="to" value="<?= (int)$to['member_id'] ?>">
                            <button class="btn btn--sm" type="submit">「<?= h($to['name']) ?>」に統合</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="section-title">自分で選んで統合</h2>
<form method="post" class="card form-grid" data-confirm="選んだ2人を統合します。元に戻せません。">
    <?= csrf_field() ?>
    <label class="field field--wide"><span>消す方（統合元）</span>
        <select name="from" required>
            <option value="">選択</option>
            <?php foreach ($members as $m): ?><option value="<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?>（<?= (int)$m['bands'] ?>組）</option><?php endforeach; ?>
        </select></label>
    <label class="field field--wide"><span>残す方（統合先）</span>
        <select name="to" required>
            <option value="">選択</option>
            <?php foreach ($members as $m): ?><option value="<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?>（<?= (int)$m['bands'] ?>組）</option><?php endforeach; ?>
        </select></label>
    <div class="form-actions field--wide"><button class="btn btn--primary" type="submit">統合する</button></div>
</form>
<?php render_footer();
