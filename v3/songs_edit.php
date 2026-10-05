<?php
/**
 * =====================================================================
 *  songs_edit.php?band=バンドID — 曲（セットリスト）と、曲ごとの演奏者の編集
 * =====================================================================
 *  1曲 = 1枚のカード。カードの中に「バンドのメンバー全員」が並び、
 *    ☑ チェック … その曲を演奏した
 *    楽器1 / 楽器2 … その曲で弾いた楽器（ギターボーカルなら Vo と Gt）
 *  を選ぶ。
 *
 *  曲の追加: 空のカードに曲名を書けば追加。曲名が空のカードは無視される。
 *  曲の削除: 「この曲を削除」にチェック。
 *  並び順  : 「何曲目」の数字の順に並べ直して、1,2,3... と振り直す。
 *
 *  保存の中身は lib/repository.php の save_songs()。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_login();

$pdo = db();
$bandId = (int)($_GET['band'] ?? $_POST['band_id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, d.live_id, d.label, l.name AS live_name FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    http_response_code(404);
    exit('バンドが見つかりません');
}
$backUrl = 'live.php?id=' . (int)$band['live_id'] . '#day-' . (int)$band['live_day_id'];

// ---- バンドのメンバー（人ごと）と、バンドでの楽器（初期値に使う） ----
$st = $pdo->prepare('SELECT m.member_id, m.name, bm.instrument_id FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id = ? ORDER BY i.sort_order, m.name');
$st->execute([$bandId]);
$members = []; // member_id => ['name' => ..., 'roles' => [instrument_id, ...]]
foreach ($st as $r) {
    $members[(int)$r['member_id']]['name'] = $r['name'];
    $members[(int)$r['member_id']]['roles'][] = (int)$r['instrument_id'];
}
$validInstruments = array_map('intval', array_column(instruments(), 'instrument_id'));

/* =====================================================================
 *  保存
 * ===================================================================== */
$errors = [];
if (is_post()) {
    verify_csrf();
    $songs = [];
    foreach ((array)($_POST['songs'] ?? []) as $k => $in) {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '' || !empty($in['delete'])) {
            continue; // 曲名が空 or 削除チェック → 保存しない（既存の曲なら save_songs が消す）
        }
        if (mb_strlen($title) > 100) {
            $errors[] = "「{$title}」: 曲名は100文字以内にしてください";
            continue;
        }
        $performers = [];
        foreach ($members as $memberId => $_) {
            $p = $in['p'][$memberId] ?? [];
            if (empty($p['on'])) {
                continue; // チェックが付いていない = その曲は弾いていない
            }
            foreach (['i1', 'i2'] as $slot) {
                $inst = (int)($p[$slot] ?? 0);
                if (in_array($inst, $validInstruments, true)) {
                    $performers["$memberId-$inst"] = [$memberId, $inst]; // キーにして重複を消す
                }
            }
        }
        $songs[] = [
            'song_id' => ctype_digit((string)($in['id'] ?? '')) ? (int)$in['id'] : null,
            'title' => $title,
            'order' => (int)($in['order'] ?? 99),
            'k' => (int)$k,
            'performers' => array_values($performers),
        ];
    }
    if (count($songs) > 50) {
        $errors[] = '曲は50曲までです';
    }
    if (!$errors) {
        // 「何曲目」の数字順 → 同じなら画面の並び順
        usort($songs, static fn($a, $b) => [$a['order'], $a['k']] <=> [$b['order'], $b['k']]);
        $pdo->beginTransaction();
        try {
            save_songs($pdo, $bandId, $songs);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('「' . $band['name'] . '」の曲を保存しました（' . count($songs) . '曲）');
        redirect($backUrl);
    }
}

/* =====================================================================
 *  表示用データ
 * ===================================================================== */
$st = $pdo->prepare('SELECT song_id, track_no, title FROM song WHERE band_id = ? ORDER BY track_no');
$st->execute([$bandId]);
$songs = $st->fetchAll();

// 曲ごとの演奏者: [song_id][member_id] = [instrument_id, ...]
$st = $pdo->prepare('SELECT sp.song_id, sp.member_id, sp.instrument_id FROM song_performer sp
    JOIN song s ON s.song_id = sp.song_id JOIN instrument i ON i.instrument_id = sp.instrument_id
    WHERE s.band_id = ? ORDER BY i.sort_order');
$st->execute([$bandId]);
$played = [];
foreach ($st as $r) {
    $played[$r['song_id']][$r['member_id']][] = (int)$r['instrument_id'];
}

// 空のカード: 曲が1つも無ければ「曲数」の分、あれば1枚
$blankCount = $songs ? 1 : max(1, min(10, (int)$band['song_count']));
$cards = $songs;
for ($i = 0; $i < $blankCount; $i++) {
    $cards[] = ['song_id' => null, 'track_no' => count($songs) + $i + 1, 'title' => ''];
}

render_header('曲を編集', 'lives');
?>
<nav class="crumbs"><a href="<?= h($backUrl) ?>"><?= h($band['live_name']) ?> <?= h($band['label']) ?></a><span>/</span><?= h($band['name']) ?></nav>
<h1 class="display display--sm">♪ <?= h($band['name']) ?> の曲</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<?php if (!$members): ?>
    <div class="flash flash--warn">先にバンドのメンバーを登録してください（<a href="band_edit.php?id=<?= $bandId ?>">バンドを編集</a>）</div>
<?php endif; ?>
<p class="muted small">チェックを付けた人がその曲の演奏者になります。楽器はバンドでの担当が初期値。曲だけ持ち替えた（例: ギターの人が1曲だけキーボード）ら、ここで変えてください。</p>

<form method="post" class="songs-form">
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= $bandId ?>">
    <div class="song-list" data-song-list>
        <?php foreach ($cards as $k => $song):
            $isNew = $song['song_id'] === null;
            $base = "songs[$k]"; ?>
            <section class="card song-card<?= $isNew ? ' song-card--new' : '' ?>" data-song-card>
                <div class="song-card__head">
                    <label class="song-card__no" title="何曲目"><input type="number" name="<?= $base ?>[order]" value="<?= (int)$song['track_no'] ?>" min="1" max="99" class="input-num" aria-label="何曲目"><span>曲目</span></label>
                    <input name="<?= $base ?>[title]" value="<?= h($song['title']) ?>" maxlength="100" placeholder="<?= $isNew ? '曲名を入力して追加' : '曲名' ?>" class="song-card__title" aria-label="曲名">
                    <input type="hidden" name="<?= $base ?>[id]" value="<?= $isNew ? '' : (int)$song['song_id'] ?>">
                    <?php if (!$isNew): ?><label class="check check--danger"><input type="checkbox" name="<?= $base ?>[delete]" value="1"> この曲を削除</label><?php endif; ?>
                </div>
                <div class="performers">
                    <div class="performers__tools"><button type="button" class="linkbtn small" data-toggle-all>全員 ON / OFF</button></div>
                    <?php foreach ($members as $memberId => $m):
                        // 既存の曲: 記録どおり / 新しい曲: バンドの担当を初期値にして全員ON
                        $insts = $isNew ? $m['roles'] : ($played[$song['song_id']][$memberId] ?? []);
                        $on = $isNew || $insts !== [];
                        $defaults = $insts ?: $m['roles']; ?>
                        <div class="performer<?= $on ? '' : ' is-off' ?>">
                            <label class="check performer__name"><input type="checkbox" name="<?= $base ?>[p][<?= $memberId ?>][on]" value="1"<?= $on ? ' checked' : '' ?> data-performer-on> <?= h($m['name']) ?></label>
                            <?php foreach (['i1', 'i2'] as $n => $slot): $selected = $defaults[$n] ?? 0; ?>
                                <select name="<?= $base ?>[p][<?= $memberId ?>][<?= $slot ?>]" class="select-sm" aria-label="楽器<?= $n + 1 ?>">
                                    <?php if ($n === 1): ?><option value="">—</option><?php endif; ?>
                                    <?php foreach (instruments() as $ins): ?>
                                        <option value="<?= (int)$ins['instrument_id'] ?>"<?= $selected === (int)$ins['instrument_id'] ? ' selected' : '' ?>><?= h($ins['short_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-add-song>＋ 曲を追加</button>

    <div class="sticky-actions">
        <a class="btn btn--ghost" href="<?= h($backUrl) ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();
