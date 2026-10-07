<?php
/**
 * =====================================================================
 *  live_edit.php — ライブの新規追加 / 編集
 * =====================================================================
 *    live_edit.php          … 新しいライブを手入力で追加（タイムテーブルのファイルが無いとき用）
 *    live_edit.php?id=ID    … ライブ名・年度・各日程の情報を直す。日程の追加もここ
 *
 *  live（1行）と live_day（日程の数だけ）をまとめて1つのフォームで更新する。
 *  日付は必須（年度を日付から決めるため）。会場は「分からなければ空欄」= NULL で保存する。
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
    $live = ['live_id' => 0, 'fiscal_year' => $thisYear, 'name' => '', 'youtube_url' => null];
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
    if (!in_array($label, DAY_LABELS, true)) { // 選択式だが、書き換えられたリクエストも弾く
        $errors[] = '日程名は一覧から選んでください';
    }
    if ($date === '') {
        $errors[] = "「{$label}」の日付を入力してください";
    } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
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
        $in['date'],                                       // 必須（read_day_input でチェック済み）
        find_or_create_venue($pdo, $in['venue']),           // 空欄 → NULL
        $in['note'] !== '' ? $in['note'] : null,
    ];
}

// 追加する日程の初期値: まだ使っていない最初の日程名（新規ライブなら「1日目」）
$usedLabels = array_column($days, 'label');
$freeLabels = array_values(array_diff(DAY_LABELS, $usedLabels));
$newDay = ['label' => $freeLabels[0] ?? DAY_LABELS[0], 'date' => '', 'venue' => '', 'note' => '', 'add' => false];

// 「他のライブに統合」で選んだライブ（統合しないなら null）
$mergeLive = null;

$errors = [];
if (is_post()) {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? $live['name'])); // 統合 ON のときはライブ名の欄は送られてこない
    $youtube = trim((string)($_POST['youtube_url'] ?? ($live['youtube_url'] ?? '')));

    // ---- 他のライブに統合（管理者のみ。統合先の日程・バンドを消すことがあるので） ----
    $merge = !$isNew && !empty($_POST['merge']);
    if ($merge && !is_admin()) {
        // ボタンは管理者にしか出さないが、リクエストを自作すれば送れてしまうのでサーバーでも止める
        http_response_code(403);
        exit('統合は管理者のみできます');
    }
    if ($merge) {
        // フォームの値は書き換えられる可能性があるので、本当に存在するライブか DB で確認する
        $mergeIdIn = (string)($_POST['merge_live_id'] ?? '');
        $st = $pdo->prepare("SELECT l.live_id, l.fiscal_year, l.name,
                GROUP_CONCAT(d.label ORDER BY d.label SEPARATOR '・') AS labels
            FROM live l LEFT JOIN live_day d ON d.live_id = l.live_id
            WHERE l.live_id = ? GROUP BY l.live_id, l.fiscal_year, l.name");
        $st->execute([ctype_digit($mergeIdIn) ? (int)$mergeIdIn : 0]);
        $mergeLive = $st->fetch() ?: null;
        if ($mergeLive === null || (int)$mergeLive['live_id'] === $liveId) {
            $errors[] = '統合先のライブを選んでください';
            $mergeLive = null;
        }
    } else {
        if ($name === '' || mb_strlen($name) > 50) {
            $errors[] = 'ライブ名は1〜50文字で入力してください';
        }
        // 空欄はOK（リンクなし）。入れたなら YouTube の https のリンクだけ受け付ける
        if ($youtube !== '' && !youtube_url_valid($youtube)) {
            $errors[] = 'YouTube のリンクは https://www.youtube.com/… か https://youtu.be/… の形で入力してください';
        }
    }

    $dayInputs = [];
    foreach ($days as $d) {
        [$in, $dayErrors] = read_day_input($_POST['d'][$d['live_day_id']] ?? []);
        $errors = array_merge($errors, $dayErrors);
        $dayInputs[(int)$d['live_day_id']] = $in;
    }

    // 追加する日程: 新規ライブなら必須。既存ライブでは「この日程を追加する」にチェックしたときだけ追加する
    // （日程名は選択式で常に値が入るので、「何か入力されたか」では判定できない）
    $nd = is_array($_POST['nd'] ?? null) ? $_POST['nd'] : [];
    $wantsNewDay = $isNew || !empty($nd['add']);
    [$newDay, $newDayErrors] = read_day_input($nd);
    $newDay['add'] = $wantsNewDay;
    if ($wantsNewDay) {
        $errors = array_merge($errors, $newDayErrors);
    }

    // 日程名の重複チェック（同じライブに「1日目」が2つは保存できない）
    //   プルダウンでは入れ替えのために一時的に重複させられるので、保存のときにここで止める
    $labels = array_column($dayInputs, 'label');
    if ($wantsNewDay) {
        $labels[] = $newDay['label'];
    }
    foreach (array_count_values($labels) as $label => $count) {
        if ($count > 1) {
            $errors[] = "日程名「{$label}」が{$count}つあります。別々の日程名にしてください";
        }
    }

    // 年度は入力させず、日程の日付から決める（取り込みと同じ。年度の入れ間違いが起きない）
    //   一番早い日付の年度にする（日付は必須。日程が1つも無いときだけ今の年度のまま）
    $dates = array_filter(array_column($dayInputs, 'date'));
    if ($wantsNewDay && $newDay['date'] !== '') {
        $dates[] = $newDay['date'];
    }
    $year = $dates ? (fiscal_year_from_date(min($dates)) ?? (int)$live['fiscal_year']) : (int)$live['fiscal_year'];
    if (!$mergeLive && ($year < 1990 || $year > 2100)) { // live.fiscal_year の CHECK 制約と同じ範囲
        $errors[] = '日付の年が範囲外です（年度は日付から自動で決まります）';
    }

    if (!$errors && $mergeLive !== null) {
        // ---- 統合: このライブの日程を全部、統合先のライブへ移す ----
        $targetId = (int)$mergeLive['live_id'];
        $pdo->beginTransaction();
        try {
            // 統合先に同じ日程名があれば、統合先のその日程を消して置き換える（上書き）
            //   バンド・出演記録は ON DELETE CASCADE で一緒に消える。
            //   delete_live_day() は「日程が0になったライブも消す」ので、統合先が消えないようにここでは直接 DELETE する
            $findSame = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ? AND label = ?');
            $deleteDay = $pdo->prepare('DELETE FROM live_day WHERE live_day_id = ?');
            $moving = array_column($dayInputs, 'label');
            if ($wantsNewDay) {
                $moving[] = $newDay['label'];
            }
            foreach (array_unique($moving) as $label) {
                $findSame->execute([$targetId, $label]);
                $sameId = $findSame->fetchColumn();
                if ($sameId !== false) {
                    $deleteDay->execute([(int)$sameId]);
                }
            }
            $move = $pdo->prepare('UPDATE live_day SET label = ?, held_on = ?, venue_id = ?, note = ?, live_id = ? WHERE live_day_id = ?');
            foreach ($dayInputs as $id => $in) {
                $move->execute([...day_params($pdo, $in), $targetId, $id]);
            }
            if ($wantsNewDay) {
                $pdo->prepare('INSERT INTO live_day (label, held_on, venue_id, note, live_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([...day_params($pdo, $newDay), $targetId]);
            }
            // 日程が全部いなくなった元のライブを消す
            $pdo->prepare('DELETE FROM live WHERE live_id = ? AND NOT EXISTS (SELECT 1 FROM live_day WHERE live_id = ?)')
                ->execute([$liveId, $liveId]);
            $pdo->commit();
            flash('「' . $mergeLive['fiscal_year'] . '年度 ' . $mergeLive['name'] . '」に統合しました');
            redirect('live.php?id=' . $targetId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // 移す日程どうしで日程名がかぶっている（例: 2つとも「1日目」）
            $errors[] = 'このライブの日程どうしで日程名が重複しています。別々の日程名にしてください';
        }
    } elseif (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $pdo->prepare('INSERT INTO live (fiscal_year, name, youtube_url) VALUES (?, ?, ?)')
                    ->execute([$year, $name, $youtube !== '' ? $youtube : null]);
                $liveId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE live SET fiscal_year = ?, name = ?, youtube_url = ? WHERE live_id = ?')
                    ->execute([$year, $name, $youtube !== '' ? $youtube : null, $liveId]);
            }
            // 「1日目 ⇄ 2日目」の入れ替えは、1行ずつ UPDATE すると途中で UNIQUE(live_id, label) にぶつかる
            //   → いったん全部の日程名を重ならない仮の名前（#日程ID）にしてから、本当の値を入れる
            $tmp = $pdo->prepare("UPDATE live_day SET label = CONCAT('#', live_day_id) WHERE live_day_id = ?");
            foreach (array_keys($dayInputs) as $id) {
                $tmp->execute([$id]);
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
    $live = array_merge($live, ['fiscal_year' => $year, 'name' => $name, 'youtube_url' => $youtube]);
    foreach ($days as &$d) {
        $in = $dayInputs[(int)$d['live_day_id']];
        $d = array_merge($d, ['label' => $in['label'], 'held_on' => $in['date'], 'venue_name' => $in['venue'],
            'note' => $in['note']]);
    }
    unset($d);
}

$venues = $pdo->query('SELECT name FROM venue ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$canMerge = !$isNew && is_admin(); // 「他のライブに統合」ボタンは管理者だけに出す
$merging = $canMerge && is_post() && !empty($_POST['merge']); // エラーで戻ったときもトグル ON のまま見せる
if ($canMerge) {
    $lives = lives_with_labels($pdo); // 統合先のポップアップ用
    $excludeLiveId = $liveId;         // 自分自身には統合させない
}
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
    <?php if (!$canMerge): ?>
        <div class="form-grid">
            <label class="field field--wide"><span>ライブ名</span><input name="name" value="<?= h($live['name']) ?>" maxlength="50" placeholder="例: 9月ライブ" required></label>
            <label class="field field--wide"><span>YouTube のリンク（任意）</span><input type="url" name="youtube_url" value="<?= h((string)$live['youtube_url']) ?>" maxlength="500" placeholder="https://www.youtube.com/playlist?list=…" inputmode="url"></label>
        </div>
    <?php else: ?>
        <!-- 取り込み画面と同じ「統合」トグル（assets/app.js の setupMergeToggle / setupLiveEditMerge）
             ON: 年度・ライブ名の欄を隠して disabled（送信されない）にし、統合先のライブを選ばせる -->
        <?php require __DIR__ . '/partials/live_picker.php'; ?>
        <div class="form-grid" data-live-merge>
            <div class="field field--wide" data-merge data-year="<?= (int)$live['fiscal_year'] ?>">
                <span class="live-name-head">ライブ名
                    <button type="button" class="merge-toggle" aria-pressed="<?= $merging ? 'true' : 'false' ?>" data-merge-toggle><?= icon('merge') ?> 他のライブに統合</button>
                </span>
                <input name="name" value="<?= h($live['name']) ?>" maxlength="50" placeholder="例: 9月ライブ" required data-live-name<?= $merging ? ' hidden disabled' : '' ?>>
                <!-- disabled の欄は送信されない → OFF のときは merge / merge_live_id を送らない -->
                <input type="hidden" name="merge" value="1" data-merge-flag<?= $merging ? '' : ' disabled' ?>>
                <input type="hidden" name="merge_live_id" value="<?= $mergeLive ? (int)$mergeLive['live_id'] : '' ?>" data-live-id<?= $merging ? '' : ' disabled' ?>>
                <div class="live-pick" data-live-pick-wrap<?= $merging ? '' : ' hidden' ?>>
                    <button type="button" class="live-pick__btn" aria-haspopup="listbox" aria-expanded="false" data-live-pick
                        data-year="<?= $mergeLive ? (int)$mergeLive['fiscal_year'] : '' ?>" data-labels="<?= $mergeLive ? h((string)$mergeLive['labels']) : '' ?>"
                        data-name="<?= $mergeLive ? h($mergeLive['name']) : '' ?>">
                        <span data-live-pick-text<?= $mergeLive ? '' : ' class="is-placeholder"' ?>><?= $mergeLive ? h($mergeLive['fiscal_year'] . '年度 ' . $mergeLive['name']) : 'ライブを選択' ?></span>
                        <?= icon('expand_more') ?>
                    </button>
                </div>
                <small class="merge-note" data-merge-note></small>
            </div>
            <!-- 統合するとこのライブは消えるので、統合 ON のときは隠す（data-merge-hide） -->
            <label class="field field--wide" data-merge-hide<?= $merging ? ' hidden' : '' ?>><span>YouTube のリンク（任意）</span><input type="url" name="youtube_url" value="<?= h((string)$live['youtube_url']) ?>" maxlength="500" placeholder="https://www.youtube.com/watch?v=…" inputmode="url"<?= $merging ? ' disabled' : '' ?>></label>
        </div>
    <?php endif; ?>
    <datalist id="dl-venues"><?php foreach ($venues as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>

    <?php foreach ($days as $d): $id = (int)$d['live_day_id']; ?>
        <h2 class="section-title"><?= h($d['label'] ?: '日程') ?></h2>
        <div class="form-grid">
            <label class="field"><span>日程名</span><select name="d[<?= $id ?>][label]" data-day-label><?= day_label_options((string)$d['label']) ?></select>
                <small class="merge-note merge-note--warn" data-overwrite-note hidden>⚠ 登録済の日程のため上書きされます</small>
                <small class="merge-note merge-note--warn" data-dup-note hidden>⚠ 他の日程と重複しています</small></label>
            <label class="field"><span>日付</span><input type="date" name="d[<?= $id ?>][held_on]" value="<?= h($d['held_on']) ?>" required></label>
            <label class="field"><span>会場</span><input name="d[<?= $id ?>][venue]" value="<?= h($d['venue_name']) ?>" list="dl-venues" maxlength="50"></label>
            <label class="field field--wide"><span>メモ</span><input name="d[<?= $id ?>][note]" value="<?= h($d['note']) ?>"></label>
        </div>
        <p><a class="btn btn--ghost btn--sm" href="band_edit.php?day=<?= $id ?>">＋ この日程にバンドを追加</a></p>
    <?php endforeach; ?>

    <!-- 追加する日程。新規ライブでは最初の日程（必須）。
         既存ライブでは「＋ 日程を追加」のチェックボックスだけ見せ、チェックすると入力欄が開く（assets/app.js の setupNewDayToggle） -->
    <?php if ($isNew): ?>
        <h2 class="section-title">日程</h2>
    <?php else: ?>
        <label class="new-day-toggle"><input type="checkbox" name="nd[add]" value="1" data-new-day-add<?= $newDay['add'] ? ' checked' : '' ?>> ＋ 日程を追加</label>
    <?php endif; ?>
    <div class="form-grid" data-new-day-fields<?= !$isNew && !$newDay['add'] ? ' hidden' : '' ?>>
        <label class="field"><span>日程名</span><select name="nd[label]" data-day-label data-new-day><?= day_label_options($newDay['label']) ?></select>
            <small class="merge-note merge-note--warn" data-overwrite-note hidden>⚠ 登録済の日程のため上書きされます</small>
            <small class="merge-note merge-note--warn" data-dup-note hidden>⚠ ほかの日程と日程名が重複しています</small></label>
        <label class="field"><span>日付</span><input type="date" name="nd[held_on]" value="<?= h($newDay['date']) ?>"<?= $isNew || $newDay['add'] ? ' required' : '' ?>></label>
        <label class="field"><span>会場</span><input name="nd[venue]" value="<?= h($newDay['venue']) ?>" list="dl-venues" maxlength="50"></label>
        <label class="field field--wide"><span>メモ</span><input name="nd[note]" value="<?= h($newDay['note']) ?>"></label>
    </div>

    <div class="form-actions">
        <?php if (!$isNew): ?><a class="btn btn--ghost" href="live.php?id=<?= $liveId ?>">キャンセル</a><?php endif; ?>
        <button class="btn btn--primary" type="submit"><?= $isNew ? '追加する' : '保存する' ?></button>
    </div>
</form>
<?php render_footer();
