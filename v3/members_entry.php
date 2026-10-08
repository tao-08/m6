<?php
/**
 * =====================================================================
 *  members_entry.php — メンバープロフィールの一括編集（管理者のみ）
 * =====================================================================
 *  入学年度・ふりがなはアカウント登録（register.php）や本人の編集でしか入らないので、
 *  名簿の取り込みで作られた人はほぼ全員「未登録（NULL）」になっている。
 *  入学年度が未登録のままだと、集計・メンバー一覧の学年の絞り込みに出てこない。
 *  1人ずつ member.php を開くのは大変なので、ここで表にまとめて直せるようにした。
 *
 *  ・送られてくるのは entry[メンバーID] = 年度、kana[メンバーID] = ふりがな（空欄 = 未登録に戻す）
 *  ・変わった人だけ UPDATE する。全員分をトランザクションで1回にまとめるので、途中で失敗したら全部取り消し
 *  ・「初出演」は入学年度の目安。1年生で出るとは限らないので、自動では保存しない（JS で空欄に入れるだけ）
 *
 *  表示の絞り込み（?show=）
 *    unknown … 入学年度かふりがなが未登録の人だけ（初期値）
 *    all     … 全員
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();

$pdo = db();
$thisYear = current_fiscal_year();
$minYear = 1950;
$kanaMax = 50; // member.name_kana VARCHAR(50)

$show = ($_GET['show'] ?? 'unknown') === 'all' ? 'all' : 'unknown';
$self = 'members_entry' . ($show === 'all' ? '?show=all' : '');
$unknownWhere = 'entry_year IS NULL OR name_kana IS NULL';

if (is_post()) {
    verify_csrf();
    // entry=文字列 のように形を変えて送られてきたら何もしない
    $entryInput = is_array($_POST['entry'] ?? null) ? $_POST['entry'] : [];
    $kanaInput = is_array($_POST['kana'] ?? null) ? $_POST['kana'] : [];

    // 今の値と比べて「変わった人」だけ集める
    $current = [];
    foreach ($pdo->query('SELECT member_id, entry_year, name_kana FROM member') as $r) {
        $current[(int)$r['member_id']] = [
            'entry' => $r['entry_year'] === null ? null : (int)$r['entry_year'],
            'kana'  => $r['name_kana'],
        ];
    }

    $entryChanges = [];
    $kanaChanges = [];
    $errors = [];
    foreach ($entryInput as $id => $value) {
        $id = (int)$id;
        if (!array_key_exists($id, $current)) {
            continue; // 存在しないメンバー（消された・書き換えられた）は無視
        }
        $value = trim((string)$value);
        if ($value === '') {
            $year = null;
        } elseif (ctype_digit($value) && (int)$value >= $minYear && (int)$value <= $thisYear) {
            $year = (int)$value;
        } else {
            $errors[] = "「{$value}」は入学年度として使えません（{$minYear}〜{$thisYear} の西暦4桁）";
            continue;
        }
        if ($year !== $current[$id]['entry']) {
            $entryChanges[$id] = $year;
        }
    }
    foreach ($kanaInput as $id => $value) {
        $id = (int)$id;
        if (!array_key_exists($id, $current)) {
            continue;
        }
        $value = trim((string)$value);
        if (mb_strlen($value) > $kanaMax) {
            $errors[] = "ふりがなは{$kanaMax}文字以内にしてください";
            continue;
        }
        $kana = $value !== '' ? $value : null; // 空欄は '' ではなく NULL（schema.sql の方針）
        if ($kana !== $current[$id]['kana']) {
            $kanaChanges[$id] = $kana;
        }
    }

    if ($errors) {
        foreach (array_unique($errors) as $e) {
            flash($e, 'error');
        }
        flash('エラーがあったので、何も保存していません', 'error');
        redirect($self);
    }

    if ($entryChanges || $kanaChanges) {
        $updateEntry = $pdo->prepare('UPDATE member SET entry_year = ? WHERE member_id = ?');
        $updateKana = $pdo->prepare('UPDATE member SET name_kana = ? WHERE member_id = ?');
        $pdo->beginTransaction();
        try {
            foreach ($entryChanges as $id => $year) {
                $updateEntry->execute([$year, $id]);
            }
            foreach ($kanaChanges as $id => $kana) {
                $updateKana->execute([$kana, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $done = [];
        if ($entryChanges) {
            $done[] = '入学年度 ' . count($entryChanges) . '人';
        }
        if ($kanaChanges) {
            $done[] = 'ふりがな ' . count($kanaChanges) . '人';
        }
        flash(implode('・', $done) . 'を保存しました');
    } else {
        flash('変更はありませんでした', 'info');
    }
    redirect($self);
}

// ---- 一覧 ----
//   first_year: 初めて出演したライブの年度（入学年度の目安）
//   linked: アカウントと紐付いているか（紐付いている人は本人がプロフィールで直せる）
$list = $pdo->query('SELECT m.member_id, m.name, m.name_kana, m.entry_year,
        (SELECT MIN(lm.fiscal_year) FROM (' . MEMBERSHIP_SQL . ') bm
            JOIN band b ON b.band_id = bm.band_id
            JOIN live_day ld ON ld.live_day_id = b.live_day_id
            JOIN live lm ON lm.live_id = ld.live_id
            WHERE bm.member_id = m.member_id) AS first_year,
        EXISTS (SELECT 1 FROM user_account ua WHERE ua.member_id = m.member_id) AS linked
    FROM member m' . ($show === 'unknown' ? ' WHERE m.entry_year IS NULL OR m.name_kana IS NULL' : '') . '
    ORDER BY first_year IS NULL, first_year DESC, m.name')->fetchAll();
$unknownCount = (int)$pdo->query("SELECT COUNT(*) FROM member WHERE {$unknownWhere}")->fetchColumn();

render_header('メンバープロフィールの一括編集');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">メンバープロフィールの一括編集</h1>
        <p class="muted">入学年度が空の人は、集計やメンバー一覧の学年の絞り込みに出てきません。ふりがなは名前の並び順と検索に使います。まとめて入力して「保存」を押してください。空欄にすると未登録に戻ります。</p>
    </div>
    <dl class="stats"><div><dt>未登録</dt><dd><?= $unknownCount ?></dd></div></dl>
</section>

<nav class="tabs tabs--static no-print" aria-label="表示する人">
    <a class="tab<?= $show === 'unknown' ? ' is-active' : '' ?>" href="members_entry"<?= $show === 'unknown' ? ' aria-current="page"' : '' ?>>未登録の人だけ</a>
    <a class="tab<?= $show === 'all' ? ' is-active' : '' ?>" href="members_entry?show=all"<?= $show === 'all' ? ' aria-current="page"' : '' ?>>全員</a>
</nav>

<?php if (!$list): ?>
    <div class="empty card"><p class="muted">入学年度・ふりがなが未登録の人はいません</p></div>
<?php else: ?>
<form method="post" action="<?= h($self) ?>" class="card table-card">
    <?= csrf_field() ?>
    <div class="table-scroll table-scroll--flush">
    <table class="table table--edit">
        <thead><tr><th>名前</th><th>ふりがな</th><th class="hide-sm">アカウント</th><th class="num">初出演</th><th>入学年度</th></tr></thead>
        <tbody>
        <?php foreach ($list as $m):
            $first = $m['first_year'] !== null ? (int)$m['first_year'] : null; ?>
            <tr>
                <!-- tabindex="-1": Tab キーでふりがな → 入学年度 → 次の人… と入力欄だけを移動できるように、名前のリンクは飛ばす -->
                <td><a class="strong" tabindex="-1" href="member?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a></td>
                <td>
                    <input type="text" name="kana[<?= (int)$m['member_id'] ?>]" maxlength="<?= $kanaMax ?>" autocomplete="off"
                           value="<?= h($m['name_kana'] ?? '') ?>"
                           aria-label="<?= h($m['name']) ?>のふりがな">
                </td>
                <td class="hide-sm"><?= $m['linked'] ? '<span class="tag">紐付け済み</span>' : '<span class="muted small">なし</span>' ?></td>
                <td class="num"><?= $first !== null ? $first . '年度' : '<span class="muted small">—</span>' ?></td>
                <td>
                    <!-- data-hint: 「初出演の年度で空欄を埋める」ボタンが使う値 -->
                    <input type="number" class="input-year" name="entry[<?= (int)$m['member_id'] ?>]"
                           min="<?= $minYear ?>" max="<?= $thisYear ?>" inputmode="numeric"
                           value="<?= $m['entry_year'] !== null ? (int)$m['entry_year'] : '' ?>"
                           placeholder="<?= $first ?? '' ?>"
                           <?= $first !== null ? 'data-hint="' . $first . '"' : '' ?>
                           aria-label="<?= h($m['name']) ?>の入学年度">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="form-actions entry-actions">
        <button class="btn btn--sm" type="button" data-fill-hint title="空欄の人に、初めて出演した年度を入れます（まだ保存はされません）">初出演の年度で空欄を埋める</button>
        <button class="btn btn--primary btn--sm" type="submit">保存</button>
    </div>
</form>
<?php endif; ?>
<?php render_footer();
