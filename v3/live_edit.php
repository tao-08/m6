<?php
/**
 * =====================================================================
 *  live_edit.php?id=ライブID — ライブ名・年度・各日程の情報を直す
 * =====================================================================
 *  live（1行）と live_day（日程の数だけ）をまとめて1つのフォームで更新する。
 *  日付・会場・集合は「分からなければ空欄」= NULL で保存する。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    exit('ライブが見つかりません');
}
$st = $pdo->prepare('SELECT d.*, v.name AS venue_name FROM live_day d LEFT JOIN venue v ON v.venue_id = d.venue_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id');
$st->execute([$liveId]);
$days = $st->fetchAll();

$errors = [];
if (is_post()) {
    verify_csrf();
    $year = (int)($_POST['year'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($year < 1990 || $year > 2100) {
        $errors[] = '年度が正しくありません';
    }
    if ($name === '' || mb_strlen($name) > 50) {
        $errors[] = 'ライブ名は1〜50文字で入力してください';
    }

    $dayInputs = [];
    foreach ($days as $d) {
        $in = $_POST['d'][$d['live_day_id']] ?? [];
        $label = trim((string)($in['label'] ?? ''));
        $date = (string)($in['held_on'] ?? '');
        $venue = trim((string)($in['venue'] ?? ''));
        $meeting = (string)($in['meeting_time'] ?? '');
        $note = trim((string)($in['note'] ?? ''));
        if ($label === '' || mb_strlen($label) > 50) {
            $errors[] = '日程名は1〜50文字で入力してください';
        }
        if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
            $errors[] = "「{$label}」の日付が正しくありません";
        }
        if (mb_strlen($venue) > 50) {
            $errors[] = "「{$label}」の会場は50文字以内にしてください";
        }
        $dayInputs[(int)$d['live_day_id']] = compact('label', 'date', 'venue', 'meeting', 'note'); // ['label' => $label, ...] と同じ
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE live SET fiscal_year = ?, name = ? WHERE live_id = ?')->execute([$year, $name, $liveId]);
            $update = $pdo->prepare('UPDATE live_day SET label = ?, held_on = ?, venue_id = ?, meeting_time = ?, note = ? WHERE live_day_id = ?');
            foreach ($dayInputs as $id => $in) {
                $update->execute([
                    $in['label'],
                    $in['date'] !== '' ? $in['date'] : null,          // 空欄 → NULL
                    find_or_create_venue($pdo, $in['venue']),           // 空欄 → NULL
                    preg_match('/^\d{2}:\d{2}$/', $in['meeting']) ? $in['meeting'] : null,
                    $in['note'] !== '' ? $in['note'] : null,
                    $id,
                ]);
            }
            $pdo->commit();
            flash('ライブ情報を更新しました');
            redirect('live.php?id=' . $liveId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            // 23000 = 一意制約違反（UNIQUE）。「同じ年度に同じ名前のライブ」「同じライブに同じ日程名」が既にある
            // → DB の制約にチェックを任せて、引っかかったらメッセージにする
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $errors[] = '同じ年度・名前のライブ、または同じ日程名がすでにあります';
        }
    }
    // エラー時は入力値で表示し直す
    $live = array_merge($live, ['fiscal_year' => $year, 'name' => $name]);
    foreach ($days as &$d) {
        $in = $dayInputs[(int)$d['live_day_id']];
        $d = array_merge($d, ['label' => $in['label'], 'held_on' => $in['date'], 'venue_name' => $in['venue'],
            'meeting_time' => $in['meeting'], 'note' => $in['note']]);
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
        <label class="field"><span>年度</span><input type="number" name="year" min="1990" max="2100" value="<?= h($live['fiscal_year']) ?>" required></label>
        <label class="field field--wide"><span>ライブ名</span><input name="name" value="<?= h($live['name']) ?>" maxlength="50" required></label>
    </div>
    <datalist id="dl-venues"><?php foreach ($venues as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>

    <?php foreach ($days as $d): $id = (int)$d['live_day_id']; ?>
        <h2 class="section-title"><?= h($d['label'] ?: '日程') ?></h2>
        <div class="form-grid">
            <label class="field"><span>日程名</span><input name="d[<?= $id ?>][label]" value="<?= h($d['label']) ?>" maxlength="50" required></label>
            <label class="field"><span>日付（不明なら空欄）</span><input type="date" name="d[<?= $id ?>][held_on]" value="<?= h($d['held_on']) ?>"></label>
            <label class="field"><span>集合</span><input type="time" name="d[<?= $id ?>][meeting_time]" value="<?= h(fmt_time($d['meeting_time'])) ?>"></label>
            <label class="field"><span>会場</span><input name="d[<?= $id ?>][venue]" value="<?= h($d['venue_name']) ?>" list="dl-venues" maxlength="50"></label>
            <label class="field field--wide"><span>メモ</span><input name="d[<?= $id ?>][note]" value="<?= h($d['note']) ?>"></label>
        </div>
        <p><a class="btn btn--ghost btn--sm" href="band_edit.php?day=<?= $id ?>">＋ この日程にバンドを追加</a></p>
    <?php endforeach; ?>

    <div class="form-actions">
        <a class="btn btn--ghost" href="live.php?id=<?= $liveId ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();
