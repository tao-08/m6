<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/import/text.php';
require_login();

$pdo = db();
$bandId = (int)($_GET['id'] ?? $_POST['band_id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, ld.live_id, ld.day_no, lm.live_name, lm.year FROM band_master b
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    JOIN live_master lm ON lm.live_id = ld.live_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    http_response_code(404);
    exit('バンドが見つかりません');
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $name = trim((string)($_POST['band_name'] ?? ''));
    $songs = ($_POST['song_count'] ?? '') === '' ? null : (int)$_POST['song_count'];
    $count = ($_POST['member_count'] ?? '') === '' ? null : (int)$_POST['member_count'];
    $keyNote = trim((string)($_POST['key_note'] ?? ''));
    $rows = [];
    foreach ((array)($_POST['m_name'] ?? []) as $i => $memberName) {
        $memberName = member_display((string)$memberName);
        $part = (string)($_POST['m_part'][$i] ?? '');
        if ($memberName !== '' && isset(PART_ORDER[$part])) {
            $rows[$memberName . "\0" . $part] = [$memberName, $part];
        }
    }
    if ($name === '' || mb_strlen($name) > 128) {
        $errors[] = 'バンド名を入力してください（128文字以内）';
    }
    if (($songs !== null && ($songs < 0 || $songs > 255)) || ($count !== null && ($count < 0 || $count > 255))) {
        $errors[] = '曲数・人数は0〜255で入力してください';
    }

    if (!$errors) {
        require_once __DIR__ . '/lib/import/planner.php';
        $index = load_member_index($pdo);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE band_master SET band_name = ?, song_count = ?, member_count = ?, key_note = ? WHERE band_id = ?')
            ->execute([$name, $songs, $count, mb_substr($keyNote, 0, 128), $bandId]);
        $pdo->prepare('DELETE FROM band_member WHERE band_id = ?')->execute([$bandId]);
        $ins = $pdo->prepare('INSERT INTO band_member (band_id, member_id, part) VALUES (?, ?, ?)');
        foreach ($rows as [$memberName, $part]) {
            $key = member_key($memberName);
            if (!isset($index[$key])) {
                $pdo->prepare('INSERT INTO member (member_name) VALUES (?)')->execute([$memberName]);
                $index[$key] = ['id' => (int)$pdo->lastInsertId(), 'name' => $memberName];
            }
            $ins->execute([$bandId, $index[$key]['id'], $part]);
        }
        $pdo->exec('DELETE m FROM member m
            LEFT JOIN band_member bm ON bm.member_id = m.member_id
            LEFT JOIN user_index u ON u.member_id = m.member_id
            WHERE bm.member_id IS NULL AND u.user_auto_id IS NULL');
        $pdo->commit();
        flash('「' . $name . '」を更新しました');
        redirect('live.php?id=' . (int)$band['live_id'] . '#day-' . (int)$band['day_no']);
    }
    $band = array_merge($band, ['band_name' => $name, 'song_count' => $songs, 'member_count' => $count, 'key_note' => $keyNote]);
    $members = array_map(static fn($r) => ['member_name' => $r[0], 'part' => $r[1]], array_values($rows));
} else {
    $st = $pdo->prepare('SELECT m.member_name, bm.part FROM band_member bm JOIN member m ON m.member_id = bm.member_id
        WHERE bm.band_id = ? ORDER BY FIELD(bm.part, \'Vo\', \'Gt\', \'Ba\', \'Dr\', \'Key\', \'Other\'), m.member_name');
    $st->execute([$bandId]);
    $members = $st->fetchAll();
}
$members[] = ['member_name' => '', 'part' => 'Gt'];
$allNames = $pdo->query('SELECT member_name FROM member ORDER BY member_name')->fetchAll(PDO::FETCH_COLUMN);

render_header('バンドを編集', 'lives');
?>
<nav class="crumbs"><a href="live.php?id=<?= (int)$band['live_id'] ?>"><?= h($band['live_name']) ?></a><span>/</span>DAY <?= (int)$band['day_no'] ?></nav>
<h1 class="display display--sm">バンドを編集</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= (int)$bandId ?>">
    <div class="form-grid">
        <label class="field field--wide"><span>バンド名</span><input name="band_name" value="<?= h($band['band_name']) ?>" required></label>
        <label class="field"><span>曲数</span><input type="number" min="0" max="255" name="song_count" value="<?= h($band['song_count']) ?>"></label>
        <label class="field"><span>人数</span><input type="number" min="0" max="255" name="member_count" value="<?= h($band['member_count']) ?>"></label>
        <label class="field field--wide"><span>鍵盤（私物/貸出など）</span><input name="key_note" value="<?= h($band['key_note']) ?>"></label>
    </div>

    <h2 class="section-title">メンバー</h2>
    <p class="muted small">名前が既存メンバーと同じ表記なら同一人物として扱われます。空欄の行は無視されます。</p>
    <div class="member-rows" data-rows>
        <?php foreach ($members as $m): ?>
            <div class="member-row-edit">
                <select name="m_part[]" aria-label="パート">
                    <?php foreach (array_keys(PART_ORDER) as $p): ?>
                        <option value="<?= h($p) ?>"<?= $m['part'] === $p ? ' selected' : '' ?>><?= h($p) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="m_name[]" value="<?= h($m['member_name']) ?>" list="member-names" placeholder="名前" aria-label="名前">
                <button type="button" class="btn btn--ghost btn--sm" data-remove-row aria-label="この行を削除">✕</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-add-row>＋ 行を追加</button>
    <datalist id="member-names">
        <?php foreach ($allNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?>
    </datalist>

    <div class="form-actions">
        <a class="btn btn--ghost" href="live.php?id=<?= (int)$band['live_id'] ?>#day-<?= (int)$band['day_no'] ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();
