<?php
/**
 * =====================================================================
 *  band_edit.php — バンドの編集 / 追加 / 削除
 * =====================================================================
 *    band_edit.php?id=バンドID   … 既存バンドの編集
 *    band_edit.php?day=日程ID    … その日程に新しいバンドを追加
 *
 *  メンバーは「フォームの内容との差分だけ」更新する（sync_band_members）。
 *  曲ごとに誰が演奏したかは songs_edit.php で編集する。
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
    $st = $pdo->prepare('SELECT d.live_day_id, d.live_id, d.label, l.name AS live_name
        FROM live_day d JOIN live l ON l.live_id = d.live_id WHERE d.live_day_id = ?');
    $st->execute([$dayId]);
    $band = $st->fetch();
    if (!$band) {
        http_response_code(404);
        exit('日程が見つかりません');
    }
    $band += ['band_id' => 0, 'name' => '', 'artist_name' => '', 'song_count' => '', 'note' => '',
        'start_time' => null, 'end_time' => null, 'play_order' => next_play_order($pdo, $dayId)];
} else {
    $st = $pdo->prepare('SELECT b.*, a.name AS artist_name, d.live_id, d.label, l.name AS live_name FROM band b
        JOIN live_day d ON d.live_day_id = b.live_day_id
        JOIN live l ON l.live_id = d.live_id
        LEFT JOIN artist a ON a.artist_id = b.artist_id
        WHERE b.band_id = ?');
    $st->execute([$bandId]);
    $band = $st->fetch();
    if (!$band) {
        http_response_code(404);
        exit('バンドが見つかりません');
    }
}
$dayId = (int)$band['live_day_id'];
$backUrl = 'live.php?id=' . (int)$band['live_id'] . '#day-' . $dayId;
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
        delete_band($pdo, $bandId);   // band_member は ON DELETE CASCADE で一緒に消える
        renumber_bands($pdo, $dayId); // 抜けた番号を詰める
        $pdo->commit();
        flash('「' . $band['name'] . '」を削除しました');
        redirect($backUrl);
    }

    // ---------- 保存 ----------
    $name = trim((string)($_POST['name'] ?? ''));
    $artistName = trim((string)($_POST['artist'] ?? ''));
    $songs = (string)($_POST['song_count'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $order = max(1, (int)($_POST['play_order'] ?? 1));
    $start = (string)($_POST['start_time'] ?? '');
    $end = (string)($_POST['end_time'] ?? '');

    $rows = [];
    foreach ((array)($_POST['m_name'] ?? []) as $i => $memberName) {
        $memberName = member_display((string)$memberName);
        $inst = (int)($_POST['m_inst'][$i] ?? 0);
        if ($memberName !== '') {
            $rows[] = [$memberName, in_array($inst, $validInstruments, true) ? $inst : OTHER_INSTRUMENT_ID];
        }
    }

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'バンド名は1〜100文字で入力してください';
    }
    if (!ctype_digit($songs) || (int)$songs > 255) {
        $errors[] = '曲数を0〜255の数字で入力してください';
    }
    foreach ([$start, $end] as $t) {
        if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
            $errors[] = '時刻が正しくありません';
        }
    }
    if ($start !== '' && $end !== '' && $end <= $start) {
        $errors[] = '終了時刻は開始時刻より後にしてください'; // DB の CHECK 制約 ck_band_time と同じルール
    }
    if (mb_strlen($note) > 255) {
        $errors[] = 'メモは255文字以内にしてください';
    }

    if (!$errors) {
        $index = load_member_index($pdo);
        // アーティスト欄が空ならバンド名から推測（「ヨルシカ（安田）」→ ヨルシカ）
        $artistId = find_or_create_artist($pdo, $artistName !== '' ? $artistName : $name);
        $values = [$artistId, $name, (int)$songs, $start ?: null, $end ?: null, $note !== '' ? $note : null];

        $pdo->beginTransaction();
        try {
            if ($isNew) {
                // 出演順は UNIQUE なので、いったん最後尾に入れてから renumber_bands で正しい位置に動かす
                $pdo->prepare('INSERT INTO band (artist_id, name, song_count, start_time, end_time, note, live_day_id, play_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $dayId, next_play_order($pdo, $dayId)]);
                $bandId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE band SET artist_id = ?, name = ?, song_count = ?, start_time = ?, end_time = ?, note = ?
                    WHERE band_id = ?')->execute([...$values, $bandId]);
            }
            // 差分だけ更新（全部消すと曲ごとの演奏記録が CASCADE で消えるため。sync_band_members の説明参照）
            sync_band_members($pdo, $index, $bandId, $rows);
            renumber_bands($pdo, $dayId, $bandId, $order);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('「' . $name . '」を' . ($isNew ? '追加' : '更新') . 'しました');
        redirect($backUrl);
    }
    // エラーのときは入力した値をそのまま表示し直す
    $band = array_merge($band, ['name' => $name, 'artist_name' => $artistName, 'song_count' => $songs, 'note' => $note,
        'play_order' => $order, 'start_time' => $start, 'end_time' => $end]);
    $members = array_map(static fn($r) => ['name' => $r[0], 'instrument_id' => $r[1]], $rows);
} elseif ($isNew) {
    $members = array_map(static fn($i) => ['name' => '', 'instrument_id' => $i], [1, 2, 3, 4]); // Vo Gt Ba Dr の空欄
} else {
    $st = $pdo->prepare('SELECT m.name, bm.instrument_id FROM band_member bm
        JOIN member m ON m.member_id = bm.member_id
        JOIN instrument i ON i.instrument_id = bm.instrument_id
        WHERE bm.band_id = ? ORDER BY i.sort_order, m.name');
    $st->execute([$bandId]);
    $members = $st->fetchAll();
}
$members[] = ['name' => '', 'instrument_id' => 2]; // 最後に空の行を1つ（追加用）
$allNames = $pdo->query('SELECT name FROM member ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$allArtists = $pdo->query('SELECT name FROM artist ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

render_header($isNew ? 'バンドを追加' : 'バンドを編集', 'lives');
?>
<nav class="crumbs"><a href="<?= h($backUrl) ?>"><?= h($band['live_name']) ?></a><span>/</span><?= h($band['label']) ?></nav>
<h1 class="display display--sm"><?= $isNew ? 'バンドを追加' : 'バンドを編集' ?></h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= (int)$band['band_id'] ?>">
    <input type="hidden" name="day_id" value="<?= $dayId ?>">
    <div class="form-grid">
        <label class="field field--wide"><span>バンド名（タイムテーブルの表記）</span><input name="name" value="<?= h($band['name']) ?>" maxlength="100" required <?= $isNew ? 'autofocus' : '' ?>></label>
        <label class="field field--wide"><span>コピー元アーティスト（空欄ならバンド名から自動）</span><input name="artist" value="<?= h($band['artist_name']) ?>" list="artists" maxlength="100"></label>
        <label class="field"><span>出演順</span><input type="number" min="1" name="play_order" value="<?= h($band['play_order']) ?>" required></label>
        <label class="field"><span>曲数</span><input type="number" min="0" max="255" name="song_count" value="<?= h($band['song_count']) ?>" required></label>
        <label class="field"><span>開始</span><input type="time" name="start_time" value="<?= h(fmt_time($band['start_time'])) ?>"></label>
        <label class="field"><span>終了</span><input type="time" name="end_time" value="<?= h(fmt_time($band['end_time'])) ?>"></label>
        <label class="field field--wide"><span>メモ（鍵盤の私物/貸出など）</span><input name="note" value="<?= h($band['note']) ?>" maxlength="255"></label>
    </div>
    <datalist id="artists"><?php foreach ($allArtists as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>

    <h2 class="section-title">メンバー</h2>
    <p class="muted small">ギターボーカルは「Vo」と「Gt」の2行で入れます。名前が既存メンバーと同じ表記なら同一人物、違えば新しいメンバーになります（色で分かります）。</p>
    <div class="member-rows" data-rows>
        <?php foreach ($members as $m): ?>
            <div class="member-row-edit">
                <select name="m_inst[]" aria-label="楽器">
                    <?php foreach (instruments() as $ins): ?>
                        <option value="<?= (int)$ins['instrument_id'] ?>"<?= (int)$m['instrument_id'] === (int)$ins['instrument_id'] ? ' selected' : '' ?>><?= h($ins['short_name']) ?> <?= h($ins['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="m_name[]" value="<?= h($m['name']) ?>" list="member-names" placeholder="名前" aria-label="名前" class="name-input" data-name-cell>
                <button type="button" class="btn btn--ghost btn--sm" data-remove-row aria-label="この行を削除">✕</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-add-row>＋ 行を追加</button>
    <datalist id="member-names"><?php foreach ($allNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>

    <div class="form-actions">
        <?php if (!$isNew): ?><a class="btn btn--ghost" href="songs_edit.php?band=<?= (int)$band['band_id'] ?>">♪ 曲・演奏者を編集</a><?php endif; ?>
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
