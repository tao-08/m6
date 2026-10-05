<?php
/**
 * =====================================================================
 *  live_edit.php?id=ライブID — ライブ名・年度・各日程の情報を直す
 * =====================================================================
 *  取り込み時に年度を間違えた、日付が '0000-00-00' のまま、会場名の誤字、などを直す画面。
 *  live_master（1行）と live_detail（日程の数だけ）をまとめて1つのフォームで更新する。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM live_master WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    exit('ライブが見つかりません');
}
$st = $pdo->prepare('SELECT ld.*, v.name AS venue_name FROM live_detail ld JOIN venue v ON v.venue_id = ld.venue_id
    WHERE ld.live_id = ? ORDER BY ld.date, ld.live_detail_id');
$st->execute([$liveId]);
$days = $st->fetchAll();

$errors = [];
if (is_post()) {
    verify_csrf();
    $year = (int)($_POST['year'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($year < 1901 || $year > 2155) {
        $errors[] = '年度が正しくありません';
    }
    if ($name === '' || mb_strlen($name) > 50) {
        $errors[] = 'ライブ名は1〜50文字で入力してください';
    }
    // 変更後の (年度, 名前) が別のライブと被らないか
    $st = $pdo->prepare('SELECT 1 FROM live_master WHERE year = ? AND name = ? AND live_id <> ?');
    $st->execute([$year, $name, $liveId]);
    if ($st->fetchColumn()) {
        $errors[] = "{$year}年度「{$name}」は別に存在します";
    }

    // 日程ごとの入力をチェックしながら集める
    $dayInputs = [];
    foreach ($days as $d) {
        $in = $_POST['d'][$d['live_detail_id']] ?? [];
        $label = trim((string)($in['label'] ?? ''));
        $date = (string)($in['date'] ?? '');
        $venue = trim((string)($in['venue'] ?? ''));
        $note = trim((string)($in['note'] ?? ''));
        if ($label === '' || mb_strlen($label) > 50) {
            $errors[] = '日程名は1〜50文字で入力してください';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $errors[] = "「{$label}」の日付を入力してください";
        }
        if ($venue === '' || mb_strlen($venue) > 50) {
            $errors[] = "「{$label}」の会場を入力してください";
        }
        $dayInputs[(int)$d['live_detail_id']] = compact('label', 'date', 'venue', 'note'); // ['label' => $label, ...] と同じ
    }
    // 同じライブの中でラベル（1日目など）が被っていないか
    $labels = array_column($dayInputs, 'label');
    if (count($labels) !== count(array_unique($labels))) {
        $errors[] = '同じ日程名が2つあります';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE live_master SET year = ?, name = ? WHERE live_id = ?')->execute([$year, $name, $liveId]);
            $update = $pdo->prepare('UPDATE live_detail SET label = ?, date = ?, venue_id = ?, note = ? WHERE live_detail_id = ?');
            foreach ($dayInputs as $id => $in) {
                $update->execute([$in['label'], $in['date'], find_or_create_venue($pdo, $in['venue']), $in['note'] !== '' ? $in['note'] : null, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('ライブ情報を更新しました');
        redirect('live.php?id=' . $liveId);
    }
    // エラー時は入力値で表示し直す
    $live = array_merge($live, ['year' => $year, 'name' => $name]);
    foreach ($days as &$d) {
        $in = $dayInputs[(int)$d['live_detail_id']];
        $d = array_merge($d, ['label' => $in['label'], 'date' => $in['date'], 'venue_name' => $in['venue'], 'note' => $in['note']]);
    }
    unset($d);
}

$venues = $pdo->query('SELECT name FROM venue ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
render_header('ライブを編集', 'lives');
?>
<nav class="crumbs"><a href="live.php?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span>編集</nav>
<h1 class="display display--sm">ライブを編集</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="live_id" value="<?= $liveId ?>">
    <div class="form-grid">
        <label class="field"><span>年度</span><input type="number" name="year" min="1901" max="2155" value="<?= h((int)$live['year'] ?: '') ?>" required></label>
        <label class="field field--wide"><span>ライブ名</span><input name="name" value="<?= h($live['name']) ?>" maxlength="50" required></label>
    </div>
    <datalist id="dl-venues"><?php foreach ($venues as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>

    <?php foreach ($days as $d): $id = (int)$d['live_detail_id']; ?>
        <h2 class="section-title"><?= h($d['label'] ?: '日程') ?></h2>
        <div class="form-grid">
            <label class="field"><span>日程名</span><input name="d[<?= $id ?>][label]" value="<?= h($d['label']) ?>" maxlength="50" required></label>
            <!-- '0000-00-00' は日付入力欄に入らないので空にして、入力を促す -->
            <label class="field"><span>日付</span><input type="date" name="d[<?= $id ?>][date]" value="<?= h(str_starts_with((string)$d['date'], '0000') ? '' : $d['date']) ?>" required></label>
            <label class="field field--wide"><span>会場</span><input name="d[<?= $id ?>][venue]" value="<?= h($d['venue_name']) ?>" list="dl-venues" maxlength="50" required></label>
            <label class="field field--wide"><span>メモ（集合時間など）</span><input name="d[<?= $id ?>][note]" value="<?= h($d['note']) ?>"></label>
        </div>
        <p><a class="btn btn--ghost btn--sm" href="band_edit.php?day=<?= $id ?>">＋ この日程にバンドを追加</a></p>
    <?php endforeach; ?>

    <div class="form-actions">
        <a class="btn btn--ghost" href="live.php?id=<?= $liveId ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();
