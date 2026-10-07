<?php
/**
 * =====================================================================
 *  songs_edit.php?band=バンドID — 曲（セットリスト）と、曲ごとの演奏者の編集
 * =====================================================================
 *  1曲 = 1枚のカード。カードの中に「バンドのメンバー全員」が並び、
 *    ☑ チェック … その曲を演奏した
 *    楽器1 / 楽器2 … その曲で弾いた楽器（ギターボーカルなら「Gt/Vo」1つで Vo と Gt の両方になる）
 *  を選ぶ。
 *
 *  曲の追加: 空のカードに曲名を書けば追加。曲名が空のカードは無視される。
 *  曲の削除: 🗑 ボタン（押すとカードが薄くなり、保存したときに消える。もう一度押すと取り消し）。
 *  並び順  : 画面の上から順に 1,2,3... と振る（番号は手で変えない）。
 *  アーティスト: 一番上の「オムニバス」にチェックしたときだけ、曲ごとに書ける。
 *    チェックなし → 全曲バンドのアーティスト（song.artist_id は NULL）。
 *  曲の紐付け: 🔍で Spotify / iTunes の曲を検索して選ぶ（assets/app.js の setupTrackSearch → api_track_search.php）。
 *    選んだ曲のキー（"spotify:xxxx"）だけが送られてくるので、曲名・ジャケットはサーバーが取り直す。
 *
 *  保存の中身は lib/repository.php の save_songs()。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/tracks.php';
require_login();

$pdo = db();
$bandId = (int)($_GET['band'] ?? $_POST['band_id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, a.name AS artist_name, d.live_id, d.label, l.name AS live_name FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    http_response_code(404);
    exit('バンドが見つかりません');
}
$liveUrl = 'live.php?id=' . (int)$band['live_id'] . '#day-' . (int)$band['live_day_id'];
$backUrl = 'band.php?id=' . $bandId; // 保存・キャンセルの戻り先はバンド詳細
// オムニバスでないときにアーティスト欄に出す名前（アーティスト未設定のバンドはバンド名）
$defaultArtist = $band['artist_name'] ?? $band['name'];
$bandArtistId = $band['artist_id'] === null ? null : (int)$band['artist_id'];

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
// 楽器 ID の配列 → 楽器欄の値の配列。Vo と Gt を両方持っていたら 'vo:gt'（Gt/Vo）の1つにまとめる
//   例: [Vo, Gt] → ['vo:gt']、[Vo, Gt, Key] → ['vo:gt', 'Key の id']
$choicesOf = static fn(array $ids): array => array_column(
    merge_vocal_roles(array_map(static fn(int $id) => ['name' => '', 'instrument_id' => $id], $ids)), 'choice');

/* =====================================================================
 *  保存
 * ===================================================================== */
$errors = [];
if (is_post()) {
    verify_csrf();
    $omnibus = !empty($_POST['omnibus']);
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
        // オムニバスでなければ、送られてきたアーティスト欄は見ない（全曲バンドのアーティスト）
        $artist = $omnibus ? trim((string)($in['artist'] ?? '')) : '';
        if (mb_strlen($artist) > 100) {
            $errors[] = "「{$title}」: アーティスト名は100文字以内にしてください";
            continue;
        }
        // 紐付けた曲（空 = 紐付けなし）。形がおかしいキーは受け付けない
        $trackKey = (string)($in['track'] ?? '');
        $track = $trackKey === '' ? null : album_parse_key($trackKey);
        if ($trackKey !== '' && $track === null) {
            $errors[] = "「{$title}」: 紐付けた曲の指定がおかしいです";
            continue;
        }
        $performers = [];
        foreach ($members as $memberId => $_) {
            $p = $in['p'][$memberId] ?? [];
            if (empty($p['on'])) {
                continue; // チェックが付いていない = その曲は弾いていない
            }
            foreach (['i1', 'i2'] as $slot) {
                if (($p[$slot] ?? '') === '') {
                    continue; // 楽器2の「—」
                }
                // 'vo:gt'（Gt/Vo）なら Vo と Gt の2つになる
                foreach (instruments_for_choice($p[$slot]) as $inst) {
                    $performers["$memberId-$inst"] = [$memberId, $inst]; // キーにして重複を消す
                }
            }
        }
        $songs[] = [
            'song_id' => ctype_digit((string)($in['id'] ?? '')) ? (int)$in['id'] : null,
            'title' => $title,
            'artist' => $artist,
            'track' => $track,
            'k' => (int)$k,
            'performers' => array_values($performers),
        ];
    }
    if (count($songs) > 50) {
        $errors[] = '曲は50曲までです';
    }
    // まだ track テーブルに無い曲は、Spotify / iTunes から取り直す（ブラウザから来た曲名や画像URLは使わない）。
    //   通信するので、トランザクションの外で先にやっておく（DB をロックしたまま外部の返事を待たないため）
    $newTracks = []; // "source:id" => 曲の情報
    $exists = $pdo->prepare('SELECT 1 FROM track WHERE source = ? AND track_id = ?');
    foreach ($errors ? [] : $songs as $song) {
        if ($song['track'] === null) {
            continue;
        }
        $key = album_key(...$song['track']); // ...$配列 = 配列の中身を引数として順に渡す（source, track_id）
        $exists->execute($song['track']);
        if (isset($newTracks[$key]) || $exists->fetchColumn()) {
            continue;
        }
        $info = track_lookup(...$song['track']);
        if ($info === null) {
            $errors[] = "「{$song['title']}」: 紐付けた曲の情報を取得できませんでした。時間をおいてもう一度試してください";
            continue;
        }
        $newTracks[$key] = $info;
    }
    if (!$errors) {
        // 画面の並び順（songs[番号] の番号順。JS で足したカードは大きい番号なので最後になる）
        usort($songs, static fn($a, $b) => $a['k'] <=> $b['k']);
        $pdo->beginTransaction();
        try {
            foreach ($songs as &$song) {
                // アーティスト名 → artist_id（無ければ作る）。空欄・バンドと同じなら NULL
                $artistId = $song['artist'] === '' ? null : find_or_create_artist($pdo, $song['artist']);
                $song['artist_id'] = $artistId === $bandArtistId ? null : $artistId;
            }
            unset($song); // foreach の & を切っておく（後で $song を使ったときに最後の曲を書き換えないため）
            foreach ($newTracks as $info) {
                save_track($pdo, $info); // song から外部キーで指すので、先に track に入れる
            }
            $pdo->prepare('UPDATE band SET is_omnibus = ? WHERE band_id = ?')->execute([(int)$omnibus, $bandId]);
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
$st = $pdo->prepare('SELECT s.song_id, s.track_no, s.title, a.name AS artist_name,
        s.track_source, s.track_id, t.title AS track_title, t.artist_name AS track_artist, t.artwork_url
    FROM song s
    LEFT JOIN artist a ON a.artist_id = s.artist_id
    LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
    WHERE s.band_id = ? ORDER BY s.track_no');
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
    $cards[] = ['song_id' => null, 'track_no' => count($songs) + $i + 1, 'title' => '', 'artist_name' => null,
        'track_source' => null, 'track_id' => null, 'track_title' => null, 'track_artist' => null, 'artwork_url' => null];
}
$omnibus = (bool)$band['is_omnibus'];
$artistNames = $pdo->query('SELECT name FROM artist ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

// アイコン（Material Symbols のアイコン名。lib/bootstrap.php の icon() で出す）
const ICON_TRASH = 'delete';
const ICON_UNLINK = 'link_off';

render_header('曲を編集', 'lives');
?>
<nav class="crumbs"><a href="<?= h($liveUrl) ?>"><?= h($band['live_name']) ?> <?= h($band['label']) ?></a><span>/</span><a href="<?= h($backUrl) ?>"><?= h($band['name']) ?></a></nav>
<h1 class="display display--sm"><?= icon('queue_music') ?> <?= h($band['name']) ?> の曲</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<?php if (!$members): ?>
    <div class="flash flash--warn">先にバンドのメンバーを登録してください（<a href="band_edit.php?id=<?= $bandId ?>">バンドを編集</a>）</div>
<?php endif; ?>
<p class="muted small">チェックを付けた人がその曲の演奏者になります。楽器はバンドでの担当が初期値（ギターボーカルは「Gt/Vo」）。曲だけ持ち替えた（例: ギターの人が1曲だけキーボード）ら、ここで変えてください。</p>

<form method="post" class="songs-form">
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= $bandId ?>">
    <!-- チェックなし: アーティスト欄はバンドのアーティストで固定（薄く表示）。チェックあり: 曲ごとに書ける（assets/app.js の setupSongs） -->
    <label class="check song-omnibus"><input type="checkbox" name="omnibus" value="1"<?= $omnibus ? ' checked' : '' ?> data-omnibus> オムニバスバンド</label>
    <datalist id="artists"><?php foreach ($artistNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
    <div class="song-list" data-song-list data-default-artist="<?= h($defaultArtist) ?>">
        <?php foreach ($cards as $k => $song):
            $isNew = $song['song_id'] === null;
            $base = "songs[$k]";
            $artist = $omnibus ? ($song['artist_name'] ?? $defaultArtist) : $defaultArtist;
            $trackKey = $song['track_id'] === null ? '' : album_key($song['track_source'], $song['track_id']); ?>
            <section class="card song-card<?= $isNew ? ' song-card--new' : '' ?>" data-song-card>
                <div class="song-card__head">
                    <span class="song-card__no"><span data-song-no><?= (int)$song['track_no'] ?></span>曲目</span>
                    <!-- ジャケット: 紐付けた曲の画像。紐付けていなければ ♪。
                         紐付けているときは、マウスを乗せると「リンクが切れるマーク」が重なり、押すと紐付けを外す -->
                    <span class="song-thumb" title="<?= $trackKey === '' ? '' : h($song['track_title'] . ' / ' . $song['track_artist']) ?>">
                        <span class="song-thumb__art" data-track-thumb><?php if ($trackKey !== ''): ?><img src="<?= h($song['artwork_url']) ?>" alt="" loading="lazy"><?php else: ?><?= icon('music_note') ?><?php endif; ?></span>
                        <button type="button" class="song-thumb__clear" data-track-clear aria-label="紐付けを外す"<?= $trackKey === '' ? ' hidden' : '' ?>><?= icon(ICON_UNLINK) ?></button>
                    </span>
                    <input name="<?= $base ?>[title]" value="<?= h($song['title']) ?>" maxlength="100" placeholder="<?= $isNew ? '曲名を入力して追加' : '曲名' ?>" class="song-card__title" aria-label="曲名">
                    <input name="<?= $base ?>[artist]" value="<?= h($artist) ?>" maxlength="100" list="artists" placeholder="アーティスト" class="song-card__artist" aria-label="アーティスト" data-song-artist<?= $omnibus ? '' : ' readonly' ?>>
                    <input type="hidden" name="<?= $base ?>[track]" value="<?= h($trackKey) ?>" data-track-key>
                    <button type="button" class="btn btn--ghost btn--sm" data-track-search><?= icon('search') ?> 曲を探す</button>
                    <input type="hidden" name="<?= $base ?>[id]" value="<?= $isNew ? '' : (int)$song['song_id'] ?>">
                    <?php if (!$isNew): ?>
                        <!-- 🗑 押すと「削除する」印（隠し項目を 1）が付いてカードが薄くなる。もう一度押すと取り消し。消えるのは保存したとき -->
                        <input type="hidden" name="<?= $base ?>[delete]" value="" data-song-delete>
                        <button type="button" class="song-card__delete" data-song-delete-btn aria-label="この曲を削除" aria-pressed="false"><?= icon(ICON_TRASH) ?></button>
                    <?php endif; ?>
                </div>
                <!-- 🔍 の検索結果（assets/app.js の setupTrackSearch が中身を入れる） -->
                <div class="track-results" data-track-results hidden></div>
                <div class="performers">
                    <div class="performers__tools"><button type="button" class="linkbtn small" data-toggle-all>全員 ON / OFF</button></div>
                    <?php foreach ($members as $memberId => $m):
                        // 既存の曲: 記録どおり / 新しい曲: バンドの担当を初期値にして全員ON
                        $insts = $isNew ? $m['roles'] : ($played[$song['song_id']][$memberId] ?? []);
                        $on = $isNew || $insts !== [];
                        $choices = $choicesOf($insts ?: $m['roles']); ?>
                        <div class="performer<?= $on ? '' : ' is-off' ?>">
                            <label class="check performer__name"><input type="checkbox" name="<?= $base ?>[p][<?= $memberId ?>][on]" value="1"<?= $on ? ' checked' : '' ?> data-performer-on> <?= h($m['name']) ?></label>
                            <?php foreach (['i1', 'i2'] as $n => $slot): ?>
                                <select name="<?= $base ?>[p][<?= $memberId ?>][<?= $slot ?>]" class="select-sm" aria-label="楽器<?= $n + 1 ?>">
                                    <?php if ($n === 1): ?><option value="">—</option><?php endif; ?>
                                    <?= instrument_choice_options($choices[$n] ?? '') ?>
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
