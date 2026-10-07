<?php
/**
 * =====================================================================
 *  timetable_edit.php?id=ライブID — タイムテーブルをまとめて編集
 * =====================================================================
 *  ライブの全日程・全バンドの「時間・バンド名・出演者」を1画面で直す。
 *  1バンドずつ直すなら band_edit.php、ここは「表で一気に」直す用。
 *
 *  ・出演者の名前欄は取り込み画面と同じ色分け（緑 = DB に登録済み / 黄 = 似た人がいる / 赤 = 新しいメンバー）。
 *    色は JS が api_name_check.php に聞いて付ける（assets/app.js の setupNameCheck）
 *  ・入力欄が多い（20バンド × 5人 × 2欄 …）ので、取り込みと同じく JS が JSON 1個にまとめて送る（data-pack / read_form_input）
 *  ・メンバーは差分だけ更新する（sync_band_members）。曲ごとの演奏記録を消さないため
 *  ・出演順・曲数・メモ・コピー元アーティストはここでは触らない（band_edit.php で直す）。
 *    ただしバンド名を変えたときは、コピー元アーティストも新しい名前から決め直す
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

// ---- 日程・バンド・メンバー（live.php と同じく SQL 3回でまとめて取る = N+1 にしない） ----
$st = $pdo->prepare('SELECT * FROM live_day WHERE live_id = ? ORDER BY held_on IS NULL, held_on, live_day_id');
$st->execute([$liveId]);
$days = $st->fetchAll();

$st = $pdo->prepare('SELECT b.band_id, b.live_day_id, b.artist_id, b.name, b.play_order, b.start_time, b.end_time FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    WHERE d.live_id = ? ORDER BY b.play_order');
$st->execute([$liveId]);
$bands = []; // band_id => バンド（このライブのバンドだけ。POST で来た band_id はここに有るかで確かめる）
foreach ($st as $b) {
    $bands[(int)$b['band_id']] = $b + ['members' => []];
}

$st = $pdo->prepare('SELECT bm.band_id, m.name, bm.instrument_id FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id
    WHERE d.live_id = ? ORDER BY i.sort_order, m.name');
$st->execute([$liveId]);
$rowsByBand = [];
foreach ($st as $r) {
    $rowsByBand[(int)$r['band_id']][] = $r;
}
foreach ($rowsByBand as $id => $rows) {
    $bands[$id]['members'] = merge_vocal_roles($rows); // Vo + Gt の2行は「Gt/Vo」の1行にまとめて見せる
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $input = read_form_input();
    $in = is_array($input['b'] ?? null) ? $input['b'] : [];

    $edits = []; // band_id => [name, start, end, 登録する [名前, instrument_id] の配列]
    foreach ($bands as $id => $b) {
        $row = $in[$id] ?? null;
        if (!is_array($row)) {
            continue; // フォームに無かったバンドは触らない
        }
        $name = trim((string)($row['name'] ?? ''));
        $start = (string)($row['start'] ?? '');
        $end = (string)($row['end'] ?? '');
        $label = $b['play_order'] . '番目「' . ($name !== '' ? $name : $b['name']) . '」'; // エラー文でどのバンドか分かるように

        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = "{$label}: バンド名は1〜100文字で入力してください";
        }
        foreach ([$start, $end] as $t) {
            if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
                $errors[] = "{$label}: 時刻が正しくありません";
            }
        }
        if ($start !== '' && $end !== '' && $end <= $start) {
            $errors[] = "{$label}: 終了時刻は開始時刻より後にしてください"; // DB の CHECK 制約 ck_band_time と同じルール
        }

        $assign = [];  // 登録する [名前, instrument_id]（ギターボーカルは Vo と Gt の2つ）
        $picked = [];  // エラーで画面に戻すとき用の [名前, 楽器欄の値]
        foreach (is_array($row['m'] ?? null) ? $row['m'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $memberName = member_display((string)($m['name'] ?? ''));
            $choice = is_string($m['inst'] ?? null) ? $m['inst'] : '';
            if ($memberName === '') {
                continue;
            }
            if (mb_strlen($memberName) > 50) {
                $errors[] = "{$label}: 名前は50文字以内にしてください（{$memberName}）";
            }
            $picked[] = ['name' => $memberName, 'choice' => $choice];
            foreach (instruments_for_choice($choice) as $inst) {
                $assign[] = [$memberName, $inst];
            }
        }
        $edits[$id] = compact('name', 'start', 'end', 'assign');
        // エラーで戻ったときに入力した値で表示し直すため、先に上書きしておく
        $bands[$id] = array_merge($bands[$id], ['name' => $name, 'start_time' => $start, 'end_time' => $end, 'members' => $picked]);
    }

    if (!$errors) {
        $index = load_member_index($pdo);
        $old = $pdo->prepare('SELECT name, artist_id FROM band WHERE band_id = ?');
        $update = $pdo->prepare('UPDATE band SET name = ?, artist_id = ?, start_time = ?, end_time = ? WHERE band_id = ?');
        $pdo->beginTransaction();
        try {
            foreach ($edits as $id => $e) {
                $old->execute([$id]);
                $before = $old->fetch();
                // バンド名を変えたときだけ、コピー元アーティストを新しい名前から決め直す（「ヨルシカ（安田）」→ ヨルシカ）
                $artistId = $before['name'] === $e['name'] ? $before['artist_id'] : find_or_create_artist($pdo, $e['name']);
                $update->execute([$e['name'], $artistId, $e['start'] ?: null, $e['end'] ?: null, $id]);
                sync_band_members($pdo, $index, $id, $e['assign']);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        flash('タイムテーブルを更新しました');
        redirect('live.php?id=' . $liveId);
    }
}

$bandsByDay = [];
foreach ($bands as $id => $b) {
    $bandsByDay[(int)$b['live_day_id']][$id] = $b;
}
$allNames = $pdo->query('SELECT name FROM member ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

render_header('タイムテーブルを編集', 'lives'); ?>
<nav class="crumbs"><a href="live.php?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span>タイムテーブルを編集</nav>
<h1 class="display display--sm">タイムテーブルを編集</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<div class="legend">
    <span><i class="swatch swatch--ok"></i>DB に登録済み</span>
    <span><i class="swatch swatch--similar"></i>似た人がいる（書き間違い？）</span>
    <span><i class="swatch swatch--new"></i>新しいメンバーとして登録</span>
    <span class="muted small">名前の欄にマウスを乗せる（スマホはタップ）と理由が出ます。出演順・曲数・メモはバンドごとの編集画面で直せます。</span>
</div>

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

<!-- data-pack: 送信時に JS が全項目を JSON 1個にまとめる（read_form_input() の説明参照） -->
<form method="post" class="tt-form" data-pack>
    <?= csrf_field() ?>
    <datalist id="member-names"><?php foreach ($allNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>

    <?php foreach ($days as $i => $d):
        $dayBands = $bandsByDay[(int)$d['live_day_id']] ?? []; ?>
        <section class="day card import-day" id="day-<?= (int)$d['live_day_id'] ?>" <?= $i > 0 && count($days) > 1 ? 'data-hidden' : '' ?>>
            <header class="import-day__head">
                <div>
                    <p class="eyebrow"><?= h($d['label']) ?></p>
                    <h2 class="import-day__title"><?= h(fmt_date($d['held_on']) ?: '日付未設定') ?> · <?= count($dayBands) ?> バンド</h2>
                </div>
            </header>
            <?php if (!$dayBands): ?>
                <p class="muted">バンドが登録されていません</p>
            <?php else: ?>
            <div class="table-scroll">
                <table class="table table--edit">
                    <thead><tr><th>順</th><th>開始</th><th>終了</th><th>バンド名</th><th>出演者</th></tr></thead>
                    <tbody>
                    <?php foreach ($dayBands as $id => $b):
                        $members = $b['members'] ?: [['name' => '', 'choice' => '2']]; // 0人でも1行は出す（「＋ 出演者」は最後の行をコピーして作るので）
                        $p = "b[$id]"; ?>
                        <tr>
                            <td class="mono nowrap"><?= sprintf('%02d', (int)$b['play_order']) ?></td>
                            <td><input type="time" name="<?= $p ?>[start]" value="<?= h(fmt_time($b['start_time'])) ?>" aria-label="開始"></td>
                            <td><input type="time" name="<?= $p ?>[end]" value="<?= h(fmt_time($b['end_time'])) ?>" aria-label="終了"></td>
                            <td><input name="<?= $p ?>[name]" value="<?= h($b['name']) ?>" maxlength="100" required data-band-name aria-label="バンド名"></td>
                            <td>
                                <!-- data-rows-wrap: 「＋ 出演者」がどの行の集まりに足すかを決める箱（assets/app.js の setupMemberRows）
                                     data-next: 次に足す行の番号。name の [m][番号] を JS が付け替える -->
                                <div class="tt-members" data-rows-wrap>
                                    <div class="member-rows" data-rows data-next="<?= count($members) ?>">
                                        <?php foreach (array_values($members) as $n => $m): ?>
                                            <div class="member-row-edit">
                                                <select name="<?= $p ?>[m][<?= $n ?>][inst]" aria-label="楽器"><?= instrument_choice_options($m['choice']) ?></select>
                                                <input name="<?= $p ?>[m][<?= $n ?>][name]" value="<?= h($m['name']) ?>" list="member-names" placeholder="名前" aria-label="名前" class="name-input" data-name-cell>
                                                <button type="button" class="btn btn--ghost btn--sm" data-remove-row aria-label="この行を削除"><?= icon('close') ?></button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn--ghost btn--sm" data-add-row>＋ 出演者</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <div class="sticky-actions">
        <a class="btn btn--ghost" href="live.php?id=<?= $liveId ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();
