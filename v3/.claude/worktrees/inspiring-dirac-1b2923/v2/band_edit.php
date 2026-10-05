<?php
/**
 * =====================================================================
 *  band_edit.php — バンドの編集 / 追加 / 削除
 * =====================================================================
 *    band_edit.php?id=バンドID   … 既存バンドの編集
 *    band_edit.php?day=日程ID    … その日程に新しいバンドを追加
 *
 *  メンバーは「一度全部消して、フォームの内容で入れ直す」方式。
 *  1行ずつ「追加された？消された？変わった？」を比べるより単純で、バグりにくい。
 *  （トランザクションの中でやるので、途中で失敗しても消えたままにはならない）
 *
 *  出演順を変えると、同じ日のほかのバンドも 1,2,3... と振り直される（renumber_bands）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$bandId = (int)($_GET['id'] ?? $_POST['band_id'] ?? 0);
$dayId = (int)($_GET['day'] ?? $_POST['day_id'] ?? 0);
$isNew = $bandId === 0; // id が無ければ「新規追加」モード

if ($isNew) {
    // 追加先の日程を確認。新しいバンドは最後（トリの後ろ）に入れる
    $st = $pdo->prepare('SELECT ld.live_detail_id, ld.live_id, ld.label, lm.name AS live_name,
            (SELECT COALESCE(MAX(play_order), 0) + 1 FROM band WHERE live_detail_id = ld.live_detail_id) AS next_order
        FROM live_detail ld JOIN live_master lm ON lm.live_id = ld.live_id WHERE ld.live_detail_id = ?');
    $st->execute([$dayId]);
    $band = $st->fetch();
    if (!$band) {
        http_response_code(404);
        exit('日程が見つかりません');
    }
    $band += ['band_id' => 0, 'name' => '', 'song_count' => '', 'note' => '', 'play_order' => $band['next_order']];
} else {
    $st = $pdo->prepare('SELECT b.*, ld.live_id, ld.label, lm.name AS live_name FROM band b
        JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
        JOIN live_master lm ON lm.live_id = ld.live_id WHERE b.band_id = ?');
    $st->execute([$bandId]);
    $band = $st->fetch();
    if (!$band) {
        http_response_code(404);
        exit('バンドが見つかりません');
    }
}
$detailId = (int)$band['live_detail_id'];
$backUrl = 'live.php?id=' . (int)$band['live_id'] . '#day-' . $detailId;
$validInstruments = array_map('intval', array_column(instruments(), 'instrument_id'));

$errors = [];
if (is_post()) {
    verify_csrf();

    // ---------- 削除（管理者のみ） ----------
    if (($_POST['action'] ?? '') === 'delete' && !$isNew) {
        if (!is_admin()) {
            http_response_code(403);
            exit('削除は管理者のみできます');
        }
        $pdo->beginTransaction();
        delete_band($pdo, $bandId);
        renumber_bands($pdo, $detailId); // 抜けた番号を詰める
        $pdo->commit();
        flash('「' . $band['name'] . '」を削除しました');
        redirect($backUrl);
    }

    // ---------- 保存 ----------
    $name = trim((string)($_POST['name'] ?? ''));
    $songs = (string)($_POST['song_count'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $order = max(1, (int)($_POST['play_order'] ?? 1));

    // メンバーの行: m_name[] と m_inst[] は同じ番号同士がペア
    $rows = [];
    foreach ((array)($_POST['m_name'] ?? []) as $i => $memberName) {
        $memberName = member_display((string)$memberName);
        $inst = (int)($_POST['m_inst'][$i] ?? 0);
        if ($memberName === '') {
            continue; // 空欄の行は無視
        }
        $rows[] = [$memberName, in_array($inst, $validInstruments, true) ? $inst : null];
    }

    if ($name === '' || mb_strlen($name) > 50) {
        $errors[] = 'バンド名は1〜50文字で入力してください';
    }
    if (!ctype_digit($songs)) {
        $errors[] = '曲数を数字で入力してください';
    }

    if (!$errors) {
        $index = load_member_index($pdo);
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $pdo->prepare('INSERT INTO band (name, live_detail_id, play_order, song_count, note) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $detailId, $order, (int)$songs, $note !== '' ? $note : null]);
                $bandId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE band SET name = ?, song_count = ?, note = ? WHERE band_id = ?')
                    ->execute([$name, (int)$songs, $note !== '' ? $note : null, $bandId]);
                detach_members($pdo, $bandId);           // 一度全部消して
            }
            attach_members($pdo, $index, $bandId, $rows); // 入れ直す
            renumber_bands($pdo, $detailId, $bandId, $order);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('「' . $name . '」を' . ($isNew ? '追加' : '更新') . 'しました');
        redirect($backUrl);
    }
    // エラーのときは入力した値をそのまま表示し直す
    $band = array_merge($band, ['name' => $name, 'song_count' => $songs, 'note' => $note, 'play_order' => $order]);
    $members = array_map(static fn($r) => ['name' => $r[0], 'instrument_id' => $r[1]], $rows);
} elseif ($isNew) {
    // 新規: 4人編成の空欄を用意しておく
    $members = array_map(static fn($i) => ['name' => '', 'instrument_id' => $i], [1, 2, 3, 4]);
} else {
    // 今のメンバー（楽器が複数なら複数行になる）
    $st = $pdo->prepare('SELECT m.name, bmi.instrument_id FROM (' . MEMBERSHIP_SQL . ') bm
        JOIN member m ON m.member_id = bm.member_id
        LEFT JOIN band_member_instrument bmi ON bmi.band_id = bm.band_id AND bmi.member_id = bm.member_id
        WHERE bm.band_id = ?');
    $st->execute([$bandId]);
    $members = $st->fetchAll();
    usort($members, static fn($a, $b) => instrument_sort_key($a['instrument_id'] === null ? null : (int)$a['instrument_id'])
        <=> instrument_sort_key($b['instrument_id'] === null ? null : (int)$b['instrument_id']));
}
$members[] = ['name' => '', 'instrument_id' => 2]; // 最後に空の行を1つ（追加用）
$allNames = $pdo->query('SELECT name FROM member ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

render_header($isNew ? 'バンドを追加' : 'バンドを編集', 'lives');
?>
<nav class="crumbs"><a href="<?= h($backUrl) ?>"><?= h($band['live_name']) ?></a><span>/</span><?= h($band['label']) ?></nav>
<h1 class="display display--sm"><?= $isNew ? 'バンドを追加' : 'バンドを編集' ?></h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= (int)$band['band_id'] ?>">
    <input type="hidden" name="day_id" value="<?= $detailId ?>">
    <div class="form-grid">
        <label class="field field--wide"><span>バンド名</span><input name="name" value="<?= h($band['name']) ?>" maxlength="50" required <?= $isNew ? 'autofocus' : '' ?>></label>
        <label class="field"><span>出演順</span><input type="number" min="1" name="play_order" value="<?= h($band['play_order']) ?>" required></label>
        <label class="field"><span>曲数</span><input type="number" min="0" name="song_count" value="<?= h($band['song_count']) ?>" required></label>
        <label class="field field--wide"><span>メモ（鍵盤の私物/貸出など）</span><input name="note" value="<?= h($band['note']) ?>"></label>
    </div>

    <h2 class="section-title">メンバー</h2>
    <p class="muted small">名前が既存メンバーと同じ表記なら同一人物として扱われ、違えば新しいメンバーが作られます。入力すると色で分かります。</p>
    <div class="member-rows" data-rows>
        <?php foreach ($members as $m): ?>
            <div class="member-row-edit">
                <select name="m_inst[]" aria-label="楽器">
                    <option value="">楽器なし</option>
                    <?php foreach (instruments() as $ins): ?>
                        <option value="<?= (int)$ins['instrument_id'] ?>"<?= (int)$m['instrument_id'] === (int)$ins['instrument_id'] ? ' selected' : '' ?>><?= h($ins['instrument_short']) ?> <?= h($ins['instrument_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- list="member-names": 入力中に既存メンバーの名前を候補として出す -->
                <input name="m_name[]" value="<?= h($m['name']) ?>" list="member-names" placeholder="名前" aria-label="名前" class="name-input" data-name-cell>
                <button type="button" class="btn btn--ghost btn--sm" data-remove-row aria-label="この行を削除">✕</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-add-row>＋ 行を追加</button>
    <datalist id="member-names">
        <?php foreach ($allNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?>
    </datalist>

    <div class="form-actions">
        <a class="btn btn--ghost" href="<?= h($backUrl) ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit"><?= $isNew ? '追加する' : '保存する' ?></button>
    </div>
</form>

<?php if (!$isNew && is_admin()): ?>
    <!-- 削除は別フォーム（保存フォームの中に入れると、Enter キーで誤って削除が送られることがある） -->
    <form method="post" class="danger-zone" data-confirm="「<?= h($band['name']) ?>」を削除します。メンバーの出演記録も消えます。よろしいですか？">
        <?= csrf_field() ?>
        <input type="hidden" name="band_id" value="<?= (int)$band['band_id'] ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn--ghost btn--danger btn--sm" type="submit">このバンドを削除</button>
    </form>
<?php endif; ?>
<?php render_footer();
