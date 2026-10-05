<?php
/**
 * =====================================================================
 *  member_new.php — メンバーを手入力で追加する（「新規追加」ページの「メンバーを手入力」タブ）
 * =====================================================================
 *  名簿ファイルが無いとき用。追加したメンバーは、バンドの編集画面（band_edit.php）で
 *  同じ表記の名前を入れると同一人物として扱われる。
 *
 *  重複チェックは member_key()（空白・異体字の違いを吸収した比較用の名前）で行う。
 *  「岩﨑 太一」と「岩崎太一」は同じ人とみなして、追加せずに既存のメンバーを案内する。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$thisYear = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
$entryYears = range($thisYear, $thisYear - 30); // セレクトの選択肢（新しい順）

$errors = [];
$existing = null; // 同じ人がすでにいたら ['id' => ..., 'name' => ...]
$v = ['name' => '', 'name_kana' => '', 'entry_year' => ''];
if (is_post()) {
    verify_csrf();
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $display = member_display($v['name']); // 保存用の名前（空白を詰める）

    if ($display === '' || mb_strlen($display) > 50) {
        $errors[] = '名前を入力してください（50文字以内）';
    }
    if (mb_strlen($v['name_kana']) > 50) {
        $errors[] = 'ふりがなは50文字以内にしてください';
    }
    // 入学年度は「わからない」（空欄）も OK。選ぶなら選択肢にある年度だけ
    $entryYear = null;
    if ($v['entry_year'] !== '') {
        $entryYear = filter_var($v['entry_year'], FILTER_VALIDATE_INT);
        if (!is_int($entryYear) || !in_array($entryYear, $entryYears, true)) {
            $errors[] = '入学年度が正しくありません';
        }
    }
    if (!$errors) {
        $existing = load_member_index($pdo)[member_key($display)] ?? null;
        if ($existing) {
            $errors[] = '「' . $existing['name'] . '」さんはすでに登録されています';
        }
    }

    if (!$errors) {
        $pdo->prepare('INSERT INTO member (name, name_kana, entry_year) VALUES (?, ?, ?)')
            ->execute([$display, $v['name_kana'] !== '' ? $v['name_kana'] : null, $entryYear]);
        $newId = (int)$pdo->lastInsertId();
        flash('「' . $display . '」さんを追加しました');
        redirect('member.php?id=' . $newId);
    }
}

render_header('新規追加', 'import');
$addTab = 'member';
require __DIR__ . '/partials/add_tabs.php';
?>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>
<?php if ($existing): ?>
    <p><a class="btn btn--sm" href="member.php?id=<?= (int)$existing['id'] ?>">「<?= h($existing['name']) ?>」さんのページを開く</a></p>
<?php endif; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <label class="field field--wide"><span>名前（フルネーム）</span>
            <input name="name" value="<?= h($v['name']) ?>" maxlength="50" autocomplete="off" placeholder="例: 山田太郎" required></label>
        <label class="field"><span>ふりがな（任意）</span>
            <input name="name_kana" value="<?= h($v['name_kana']) ?>" maxlength="50" autocomplete="off"></label>
        <label class="field"><span>入学年度</span>
            <select name="entry_year">
                <option value="">わからない</option>
                <?php foreach ($entryYears as $y): ?>
                    <option value="<?= $y ?>"<?= (string)$y === $v['entry_year'] ? ' selected' : '' ?>><?= $y ?>年度</option>
                <?php endforeach; ?>
            </select></label>
    </div>
    <p class="muted small">入学年度が「わからない」のままだと、集計の「現役」「上下3学年」には出てきません。</p>
    <div class="form-actions">
        <button class="btn btn--primary" type="submit">追加する</button>
    </div>
</form>
<?php render_footer();
