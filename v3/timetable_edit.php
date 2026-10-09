<?php
/**
 * =====================================================================
 *  timetable_edit.php?id=ライブID — タイムテーブルをまとめて編集
 * =====================================================================
 *  ライブの全日程・全バンドの「時間・バンド名・出演順・休憩」を1画面で直す。
 *  出演者（名前・楽器）はここでは触らない → バンドごとに band_edit.php で直す（メンバーには一切書き込まない）。
 *
 *  ・入力欄が多い（20バンド × 何欄も）ので、取り込みと同じく JS が JSON 1個にまとめて送る（data-pack / read_form_input）
 *  ・左端の ≡ をドラッグで出演順を並び替える（assets/app.js の setupSlotSort。取り込み画面と同じ部品）。
 *    時間の欄は「何行目か」に固定 = 並び替えたバンドは、その位置の時間に出ることになる
 *  ・休憩・転換など（live_break）も同じ表に1行で出し、並び替え・名前の変更・追加・削除ができる。
 *    まだ1つも登録されていない日程は、バンドの間の空き時間を「休憩」として出す（保存すると登録される）。
 *    「＋ 休憩を追加」で足した行だけは、時間を行に付けたまま動かす（位置に固定すると、差し込んだ所から下の時間が全部ずれるので）
 *  ・曲数・メモ・コピー元アーティストはここでは触らない（band_edit.php で直す）。
 *    ただしバンド名を変えたときは、コピー元アーティストも新しい名前から決め直す（オムニバスのバンドは無しのまま）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$liveId = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    exit('ライブが見つかりません');
}

// ---- 日程・バンド（live.php と同じく SQL でまとめて取る = N+1 にしない） ----
$st = $pdo->prepare('SELECT * FROM live_day WHERE live_id = ? ORDER BY held_on IS NULL, held_on, live_day_id');
$st->execute([$liveId]);
$days = $st->fetchAll();

$st = $pdo->prepare('SELECT b.band_id, b.live_day_id, b.artist_id, b.name, b.play_order, b.start_time, b.end_time, b.needs_check FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    WHERE d.live_id = ? ORDER BY b.play_order');
$st->execute([$liveId]);
$bands = []; // band_id => バンド（このライブのバンドだけ。POST で来た band_id はここに有るかで確かめる）
foreach ($st as $b) {
    $bands[(int)$b['band_id']] = $b;
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $input = read_form_input();
    $in = is_array($input['b'] ?? null) ? $input['b'] : [];
    $breakIn = is_array($input['k'] ?? null) ? $input['k'] : [];
    $checkTimes = static function (string $label, string $start, string $end) use (&$errors): void {
        foreach ([$start, $end] as $t) {
            if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
                $errors[] = "{$label}: 時刻が正しくありません";
            }
        }
        if ($start !== '' && $end !== '' && $end <= $start) {
            $errors[] = "{$label}: 終了時刻は開始時刻より後にしてください"; // DB の CHECK 制約 ck_band_time / ck_break_time と同じルール
        }
    };
    $entries = []; // live_day_id => [[pos, 'band', band_id] | [pos, 'break', 休憩の行], ...]（表の上から何行目か）

    $edits = [];        // band_id => [name, start, end, order, flag]
    $ordersByDay = [];  // live_day_id => [出演順 => band_id]（同じ日に同じ番号が2つないか確かめる）
    foreach ($bands as $id => $b) {
        $row = $in[$id] ?? null;
        if (!is_array($row)) {
            continue; // フォームに無かったバンドは触らない
        }
        $name = trim((string)($row['name'] ?? ''));
        $start = (string)($row['start'] ?? '');
        $end = (string)($row['end'] ?? '');
        $order = filter_var($row['order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
        $flag = (int)!empty($row['flag']); // 🚩 楽器の確認待ち（チェックが無ければ外す）
        $label = $b['play_order'] . '番目「' . ($name !== '' ? $name : $b['name']) . '」'; // エラー文でどのバンドか分かるように

        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = "{$label}: バンド名は1〜100文字で入力してください";
        }
        $checkTimes($label, $start, $end);
        if ($order === false) {
            $errors[] = "{$label}: 出演順が正しくありません";
            $order = (int)$b['play_order'];
        } elseif (isset($ordersByDay[(int)$b['live_day_id']][$order])) {
            $errors[] = "{$label}: 出演順 {$order} が重なっています"; // DB の UNIQUE (live_day_id, play_order) と同じルール
        }
        $ordersByDay[(int)$b['live_day_id']][$order] = $id;
        $entries[(int)$b['live_day_id']][] = [(int)($row['pos'] ?? 9999), 'band', $id];

        $edits[$id] = compact('name', 'start', 'end', 'order', 'flag');
        // エラーで戻ったときに入力した値で表示し直すため、先に上書きしておく
        $bands[$id] = array_merge($bands[$id], ['name' => $name, 'start_time' => $start, 'end_time' => $end, 'play_order' => $order, 'needs_check' => $flag]);
    }

    // ---- 休憩 ----
    // 書き換えられたリクエストで他のライブの日程に足されないよう、このライブの「バンドがいる日程」だけ受け付ける
    //（バンドがいない日程は表を出していないので、休憩もフォームに無い）
    $editDays = array_unique(array_map(static fn($b) => (int)$b['live_day_id'], $bands));
    foreach ($breakIn as $k) {
        if (!is_array($k)) {
            continue;
        }
        $dayId = (int)($k['day'] ?? 0);
        if (!in_array($dayId, $editDays, true)) {
            continue;
        }
        $name = trim((string)($k['name'] ?? ''));
        $start = (string)($k['start'] ?? '');
        $end = (string)($k['end'] ?? '');
        $label = '休憩「' . $name . '」';
        if ($name === '' || mb_strlen($name) > 50) {
            $errors[] = "{$label}: 名前は1〜50文字で入力してください";
        }
        $checkTimes($label, $start, $end);
        $entries[$dayId][] = [(int)($k['pos'] ?? 9999), 'break', ['name' => $name, 'start_time' => $start, 'end_time' => $end]];
    }

    // 表の上から順に見て、休憩に「どのバンドの後か」（after_order）と、同じ場所での並び（seq）を付ける
    $breaksByDay = array_fill_keys($editDays, []);
    foreach ($entries as $dayId => $list) {
        usort($list, static fn($x, $y) => $x[0] <=> $y[0]);
        $after = 0;
        $seq = 0;
        foreach ($list as [, $type, $v]) {
            if ($type === 'band') {
                $after = (int)$bands[$v]['play_order'];
                $seq = 0;
            } else {
                $breaksByDay[$dayId][] = $v + ['after_order' => $after, 'seq' => $seq++];
            }
        }
    }

    if (!$errors) {
        $old = $pdo->prepare('SELECT name, artist_id, is_omnibus FROM band WHERE band_id = ?');
        $update = $pdo->prepare('UPDATE band SET name = ?, artist_id = ?, start_time = ?, end_time = ?, play_order = ?, needs_check = ? WHERE band_id = ?');
        // 出演順は UNIQUE (live_day_id, play_order)。1組ずつ書き換えると途中で「3番目が2組」になって弾かれるので、
        // 先に全部を使っていない大きい番号（1000 + 新しい番号）へ逃がしてから、本当の番号を入れる
        $park = $pdo->prepare('UPDATE band SET play_order = ? WHERE band_id = ?');
        $pdo->beginTransaction();
        try {
            foreach ($edits as $id => $e) {
                $park->execute([1000 + $e['order'], $id]);
            }
            // 休憩は行ごとの履歴を持たないので、日程ごとに全部消して入れ直す
            $delBreaks = $pdo->prepare('DELETE FROM live_break WHERE live_day_id = ?');
            $insBreak = $pdo->prepare('INSERT INTO live_break (live_day_id, after_order, seq, name, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($breaksByDay as $dayId => $list) {
                $delBreaks->execute([$dayId]);
                foreach ($list as $k) {
                    $insBreak->execute([$dayId, $k['after_order'], $k['seq'], $k['name'], $k['start_time'] ?: null, $k['end_time'] ?: null]);
                }
            }
            foreach ($edits as $id => $e) {
                $old->execute([$id]);
                $before = $old->fetch();
                // バンド名を変えたときだけ、コピー元アーティストを新しい名前から決め直す（「ヨルシカ（安田）」→ ヨルシカ）
                // オムニバスのバンドはコピー元アーティストを持たないので、名前を変えても NULL のまま
                $artistId = $before['is_omnibus'] || $before['name'] === $e['name'] ? $before['artist_id'] : find_or_create_artist($pdo, $e['name']);
                $update->execute([$e['name'], $artistId, $e['start'] ?: null, $e['end'] ?: null, $e['order'], $e['flag'], $id]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        flash('タイムテーブルを更新しました');
        redirect('live?id=' . $liveId);
    }
}

$bandsByDay = [];
foreach ($bands as $id => $b) {
    $bandsByDay[(int)$b['live_day_id']][$id] = $b;
}
// エラーで戻ってきたときは、並び替えた後の順番で出す（キーの band_id は保ったまま）
foreach ($bandsByDay as &$dayBands) {
    uasort($dayBands, static fn($x, $y) => (int)$x['play_order'] <=> (int)$y['play_order']);
}
unset($dayBands); // 参照のまま残すと、下の foreach ($dayBands) で最後の日が書き換わる
if (!isset($breaksByDay)) { // 初めて開いたとき（エラーで戻ってきたときは、上で入力から作ったもの）
    $breaksByDay = load_breaks_by_day($pdo, $liveId);
    foreach ($bandsByDay as $dayId => $dayBands) {
        // まだ休憩を1つも登録していない日程は、バンドの間の空き時間を休憩として出す
        $breaksByDay[$dayId] ??= gap_breaks($dayBands, '休憩');
    }
}

/**
 * 休憩の1行（ページの表と、JS が複製する <template> の両方で使う）
 * @param bool $free true = 「＋ 休憩を追加」で足す行。時間を位置に固定せず、行と一緒に動かす
 */
function render_break_row(string $n, string $dayId, array $k, bool $free, int $pos = 0): void
{
    $p = "k[$n]"; ?>
    <tr class="tt-break" data-name-prefix="<?= h($p) ?>"<?= $free ? ' data-free-time' : '' ?>>
        <td><button type="button" class="drag-handle" data-drag-handle aria-label="ドラッグで並び替え（↑↓キーでも動く）" title="ドラッグで並び替え"><?= icon('drag_indicator') ?></button>
            <input type="hidden" name="<?= h($p) ?>[pos]" value="<?= $pos ?>" data-pos>
            <input type="hidden" name="<?= h($p) ?>[day]" value="<?= h($dayId) ?>"></td>
        <td class="muted" title="休憩など（出演順には数えない）"><?= icon('coffee') ?></td>
        <td data-time><input type="time" name="<?= h($p) ?>[start]" value="<?= h(fmt_time($k['start_time'])) ?>" aria-label="開始" data-time-field="start"></td>
        <td data-time><input type="time" name="<?= h($p) ?>[end]" value="<?= h(fmt_time($k['end_time'])) ?>" aria-label="終了" data-time-field="end"></td>
        <td><input name="<?= h($p) ?>[name]" value="<?= h($k['name']) ?>" maxlength="50" required aria-label="休憩の名前" placeholder="休憩 / 転換 など"></td>
        <td data-break-fill>
            <button type="button" class="btn btn--ghost btn--sm" data-remove-break><?= icon('close') ?> この行を消す</button>
        </td>
    </tr>
<?php }

render_header('タイムテーブルを編集', 'lives'); ?>
<nav class="crumbs"><a href="live?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span>タイムテーブルを編集</nav>
<h1 class="display display--sm">タイムテーブルを編集</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>


<?php if (count($days) > 1): ?>
<!-- 日程タブ（live.php と同じ。JS が動かなければ全日程が縦に並ぶ）。隠れている日程の入力欄もちゃんと送信される -->
<div class="tabs tabs--days" role="tablist">
    <?php foreach ($days as $i => $d): ?>
        <a href="#day-<?= (int)$d['live_day_id'] ?>" class="tab<?= $i === 0 ? ' is-active' : '' ?>" data-tab>
            <?= h($d['label']) ?><small><?= h(fmt_date($d['held_on'])) ?></small>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 「＋ 休憩を追加」で JS が複製する行。__N__ = 休憩の番号、__DAY__ = 日程の ID（JS が置き換える）
     data-free-time: この行の時間は位置に固定せず、行と一緒に動かす -->
<template id="tpl-tt-break"><table><tbody><?php render_break_row('__N__', '__DAY__', ['name' => '休憩', 'start_time' => null, 'end_time' => null], true); ?></tbody></table></template>

<!-- data-pack: 送信時に JS が全項目を JSON 1個にまとめる（read_form_input() の説明参照） -->
<form method="post" class="tt-form" data-pack>
    <?= csrf_field() ?>

    <?php $breakNo = 0; // 休憩の行の番号（name の k[番号]。ページ全体で通し番号。JS で足す行はこの続きから）
    foreach ($days as $i => $d):
        $dayId = (int)$d['live_day_id'];
        $dayBands = $bandsByDay[$dayId] ?? []; ?>
        <section class="day card import-day" id="day-<?= $dayId ?>" <?= $i > 0 && count($days) > 1 ? 'data-hidden' : '' ?>>
            <header class="import-day__head">
                <div>
                    <p class="eyebrow"><?= h($d['label']) ?></p>
                    <h2 class="import-day__title"><?= h(fmt_date($d['held_on']) ?: '日付未設定') ?> · <?= count($dayBands) ?> バンド</h2>
				<div class="legend">
					<span><?= icon('flag', 'icon--fill flag-icon') ?> 楽器の確認をメンバーにお願いする</span>
					<span>出演者・楽器はバンドごとの編集で直します</span>
				</div>

				</div>
                <?php if ($dayBands): ?>
                    <div class="tt-actions">
                        <button type="button" class="btn btn--ghost btn--sm" data-add-tt-break data-day="<?= $dayId ?>">＋ 休憩を追加</button>
                    </div>
                <?php endif; ?>
            </header>
            <?php if (!$dayBands): ?>
                <p class="muted">バンドが登録されていません</p>
            <?php else: ?>
            <div class="table-scroll">
                <!-- data-cols: 休憩の行の右端（「この行を消す」）のセルの幅。出演者の列は無いので 1 -->
                <table class="table table--edit table--roster table--tt" data-tt-table data-cols="1">
                    <thead><tr>
                        <th aria-label="並び替え"></th><th>順</th><th title="並び替えても時間はその位置に残る">開始</th><th title="並び替えても時間はその位置に残る">終了</th><th>バンド名</th>
                        <th aria-label="操作"></th>
                    </tr></thead>
                    <!-- data-sortable: 左端の ≡ をドラッグで行を並び替える（assets/app.js の setupSlotSort）。
                         時間のセル（data-time）は動かない。JS が入力欄の name を、その位置に来た行の b[ID] / k[番号] に付け直す
                         pos: 表の上から何行目か（休憩が「どのバンドの後か」を PHP が決めるのに使う。並び替えたら JS が振り直す） -->
                    <tbody data-sortable>
                    <?php foreach (timetable_rows($dayBands, $breaksByDay[$dayId] ?? []) as $pos => $r):
                        if ($r['type'] === 'break') {
                            render_break_row((string)$breakNo++, (string)$dayId, $r['row'], false, $pos);
                            continue;
                        }
                        $id = $r['key'];
                        $b = $r['row'];
                        $p = "b[$id]"; ?>
                        <tr data-name-prefix="<?= $p ?>">
                            <td><button type="button" class="drag-handle" data-drag-handle aria-label="ドラッグで並び替え（↑↓キーでも動く）" title="ドラッグで並び替え"><?= icon('drag_indicator') ?></button>
                                <input type="hidden" name="<?= $p ?>[pos]" value="<?= $pos ?>" data-pos></td>
                            <td class="mono nowrap"><span data-order-text><?= (int)$b['play_order'] ?></span>
                                <input type="hidden" name="<?= $p ?>[order]" value="<?= (int)$b['play_order'] ?>" data-order></td>
                            <td data-time><input type="time" name="<?= $p ?>[start]" value="<?= h(fmt_time($b['start_time'])) ?>" aria-label="開始" data-time-field="start"></td>
                            <td data-time><input type="time" name="<?= $p ?>[end]" value="<?= h(fmt_time($b['end_time'])) ?>" aria-label="終了" data-time-field="end"></td>
                            <!-- バンド名の横に 🚩（楽器の確認待ち。取り込み画面の名簿と同じ部品）。列を増やすと並び替え・列の追加の JS に響くので同じセルに置く -->
                            <td><div class="tt-band-name"><input name="<?= $p ?>[name]" value="<?= h($b['name']) ?>" maxlength="100" required data-band-name aria-label="バンド名">
                                <label class="flag-toggle" title="楽器があってるか、バンドのメンバーに確認してもらう"><input type="checkbox" name="<?= $p ?>[flag]" value="1"<?= $b['needs_check'] ? ' checked' : '' ?> aria-label="楽器の確認をお願いする"><?= icon('flag') ?></label></div></td>
                            <td></td><!-- 休憩の行の「この行を消す」の列（並び替えで行の長さをそろえるため） -->
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <!-- JS が「＋ 休憩を追加」で使う、次の休憩の番号（disabled なので送信はされない） -->
    <input type="hidden" value="<?= $breakNo ?>" data-next-break disabled>

    <div class="sticky-actions">
        <a class="btn btn--ghost" href="live?id=<?= $liveId ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();
