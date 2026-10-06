<?php
/**
 * =====================================================================
 *  live_edit.php — ライブの新規追加 / 編集
 * =====================================================================
 *    live_edit.php          … 新しいライブを手入力で追加（タイムテーブルのファイルが無いとき用）
 *    live_edit.php?id=ID    … ライブ名・年度・各日程の情報を直す。日程の追加もここ
 *
 *  live（1行）と live_day（日程の数だけ）をまとめて1つのフォームで更新する。
 *  日付・会場は「分からなければ空欄」= NULL で保存する。
 *  バンドとメンバーは、保存したあとライブページの「＋ バンドを追加」（band_edit.php）から入れる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$isNew = $liveId === 0; // id が無ければ「新規追加」モード

// 今年度（4月始まり）。新規追加のときの年度の初期値
$thisYear = current_fiscal_year();

if ($isNew) {
    $live = ['live_id' => 0, 'fiscal_year' => $thisYear, 'name' => ''];
    $days = [];
} else {
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
}

/**
 * フォームの日程1つ分を取り出してチェックする。
 * 既存の日程（d[ID][...]）と追加する日程（nd[...]）の両方で同じチェックを使うので関数にしている。
 * @return array{0: array, 1: string[]}  [入力値, エラーメッセージの配列]
 */
function read_day_input(mixed $in): array
{
    $in = is_array($in) ? $in : []; // 改造されたリクエストで配列以外が来ても落ちないように
    $label = trim((string)($in['label'] ?? ''));
    $date = (string)($in['held_on'] ?? '');
    $venue = trim((string)($in['venue'] ?? ''));
    $note = trim((string)($in['note'] ?? ''));
    $errors = [];
    if ($label === '' || mb_strlen($label) > 50) {
        $errors[] = '日程名は1〜50文字で入力してください';
    }
    if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
        $errors[] = "「{$label}」の日付が正しくありません";
    }
    if (mb_strlen($venue) > 50) {
        $errors[] = "「{$label}」の会場は50文字以内にしてください";
    }
    return [compact('label', 'date', 'venue', 'note'), $errors]; // compact は ['label' => $label, ...] と同じ
}

/** 日程1つ分の値を、SQL に渡す配列にする（空欄 → NULL） */
function day_params(PDO $pdo, array $in): array
{
    return [
        $in['label'],
        $in['date'] !== '' ? $in['date'] : null,          // 空欄 → NULL
        find_or_create_venue($pdo, $in['venue']),           // 空欄 → NULL
        $in['note'] !== '' ? $in['note'] : null,
    ];
}

// 追加する日程の入力欄の初期値（新規ライブなら「1日目」を入れておく）
$newDay = ['label' => $isNew ? '1日目' : '', 'date' => '', 'venue' => '', 'note' => ''];

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
        [$in, $dayErrors] = read_day_input($_POST['d'][$d['live_day_id']] ?? []);
        $errors = array_merge($errors, $dayErrors);
        $dayInputs[(int)$d['live_day_id']] = $in;
    }

    // 追加する日程: 新規ライブなら必須。既存ライブでは「何か入力されたときだけ」追加する
    $nd = is_array($_POST['nd'] ?? null) ? $_POST['nd'] : [];
    // 全部の欄をつなげて空文字なら「何も入力されていない」
    $wantsNewDay = $isNew || trim(implode('', array_filter($nd, 'is_string'))) !== '';
    [$newDay, $newDayErrors] = read_day_input($nd);
    if ($wantsNewDay) {
        $errors = array_merge($errors, $newDayErrors);
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $pdo->prepare('INSERT INTO live (fiscal_year, name) VALUES (?, ?)')->execute([$year, $name]);
                $liveId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE live SET fiscal_year = ?, name = ? WHERE live_id = ?')->execute([$year, $name, $liveId]);
            }
            $update = $pdo->prepare('UPDATE live_day SET label = ?, held_on = ?, venue_id = ?, note = ? WHERE live_day_id = ?');
            foreach ($dayInputs as $id => $in) {
                $update->execute([...day_params($pdo, $in), $id]); // ...（スプレッド構文）で配列を展開して、最後に id を足す
            }
            if ($wantsNewDay) {
                $pdo->prepare('INSERT INTO live_day (label, held_on, venue_id, note, live_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([...day_params($pdo, $newDay), $liveId]);
            }
            $pdo->commit();
            flash($isNew ? 'ライブを追加しました。次は「＋ バンドを追加」から出演バンドを入れよう' : 'ライブ情報を更新しました');
            redirect('live.php?id=' . $liveId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($isNew) {
                $liveId = 0; // ロールバックで INSERT は取り消されているので、新規モードに戻す
            }
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
            'note' => $in['note']]);
    }
    unset($d);
}

$venues = $pdo->query('SELECT name FROM venue ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
if ($isNew) {
    // 新規追加は「新規追加」ページの1タブとして見せる（見出しとタブは共通パーツ）
    render_header('新規追加', 'import');
    $addTab = 'live';
    require __DIR__ . '/partials/add_tabs.php';
} else {
    render_header('ライブを編集', 'lives'); ?>
    <nav class="crumbs"><a href="live.php?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span>編集</nav>
    <h1 class="display display--sm">ライブを編集</h1>
<?php } ?>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="live_id" value="<?= $liveId ?>">
    <div class="form-grid">
        <label class="field"><span>年度</span><input type="number" name="year" min="1990" max="2100" value="<?= h($live['fiscal_year']) ?>" required></label>
        <label class="field field--wide"><span>ライブ名</span><input name="name" value="<?= h($live['name']) ?>" maxlength="50" placeholder="例: 9月ライブ" required></label>
    </div>
    <datalist id="dl-venues"><?php foreach ($venues as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>

    <?php foreach ($days as $d): $id = (int)$d['live_day_id']; ?>
        <h2 class="section-title"><?= h($d['label'] ?: '日程') ?></h2>
        <div class="form-grid">
            <label class="field"><span>日程名</span><input name="d[<?= $id ?>][label]" value="<?= h($d['label']) ?>" maxlength="50" required></label>
            <label class="field"><span>日付（不明なら空欄）</span><input type="date" name="d[<?= $id ?>][held_on]" value="<?= h($d['held_on']) ?>"></label>
            <label class="field"><span>会場</span><input name="d[<?= $id ?>][venue]" value="<?= h($d['venue_name']) ?>" list="dl-venues" maxlength="50"></label>
            <label class="field field--wide"><span>メモ</span><input name="d[<?= $id ?>][note]" value="<?= h($d['note']) ?>"></label>
        </div>
        <p><a class="btn btn--ghost btn--sm" href="band_edit.php?day=<?= $id ?>">＋ この日程にバンドを追加</a></p>
    <?php endforeach; ?>

    <!-- 追加する日程。新規ライブでは最初の日程（必須）、既存ライブでは「入力したときだけ追加」 -->
    <h2 class="section-title"><?= $isNew ? '日程' : '＋ 日程を追加' ?></h2>
    <?php if (!$isNew): ?><p class="muted small">合宿ライブの「2日目」など。追加しないなら空欄のままでOK。</p><?php endif; ?>
    <div class="form-grid">
        <label class="field"><span>日程名</span><input name="nd[label]" value="<?= h($newDay['label']) ?>" maxlength="50" placeholder="例: 2日目"<?= $isNew ? ' required' : '' ?>></label>
        <label class="field"><span>日付（不明なら空欄）</span><input type="date" name="nd[held_on]" value="<?= h($newDay['date']) ?>"></label>
        <label class="field"><span>会場</span><input name="nd[venue]" value="<?= h($newDay['venue']) ?>" list="dl-venues" maxlength="50"></label>
        <label class="field field--wide"><span>メモ</span><input name="nd[note]" value="<?= h($newDay['note']) ?>"></label>
    </div>

    <div class="form-actions">
        <?php if (!$isNew): ?><a class="btn btn--ghost" href="live.php?id=<?= $liveId ?>">キャンセル</a><?php endif; ?>
        <button class="btn btn--primary" type="submit"><?= $isNew ? '追加する' : '保存する' ?></button>
    </div>
</form>
<?php render_footer();
