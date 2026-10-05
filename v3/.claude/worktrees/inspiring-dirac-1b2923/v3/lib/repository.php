<?php
/**
 * =====================================================================
 *  repository.php — 「探して、無ければ作る」系の DB 操作をまとめたファイル（v3）
 * =====================================================================
 *  取り込み・バンド編集・ライブ編集で同じ処理が必要になるので、ここに1つだけ書いて使い回す。
 *
 *  v2（local_abbeydb）との違い:
 *    - band_member が1テーブルなので、出演の登録・削除が1か所で済む
 *    - 外部キーに ON DELETE CASCADE があるので、ライブを消すと日程・バンド・出演記録が DB 側で一緒に消える
 *      （PHP で「孫 → 子 → 親」の順に消す必要がない）
 *    - 複合主キーがあるので、重複は INSERT IGNORE で DB に弾かせる
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/import/text.php';

/** 会場名 → venue_id。無ければ作る。空なら NULL（venue_id は NULL 可） */
function find_or_create_venue(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    // venue.name は UNIQUE なので、INSERT IGNORE で「無ければ作る」を1文で書ける
    $pdo->prepare('INSERT IGNORE INTO venue (name) VALUES (?)')->execute([mb_substr($name, 0, 50)]);
    $st = $pdo->prepare('SELECT venue_id FROM venue WHERE name = ?');
    $st->execute([mb_substr($name, 0, 50)]);
    return (int)$st->fetchColumn();
}

/** (年度, ライブ名) → live_id。無ければ作る。 */
function find_or_create_live(PDO $pdo, int $year, string $name): int
{
    $pdo->prepare('INSERT IGNORE INTO live (fiscal_year, name) VALUES (?, ?)')->execute([$year, $name]);
    $st = $pdo->prepare('SELECT live_id FROM live WHERE fiscal_year = ? AND name = ?');
    $st->execute([$year, $name]);
    return (int)$st->fetchColumn();
}

/**
 * バンド名 → artist_id。「ヨルシカ（安田）」なら括弧を外した「ヨルシカ」で探す。
 *
 * 表記ゆれ（KingGnu / King Gnu、全角/半角）は band_key() でそろえて既存アーティストを探し、
 * 見つからなければ新しく作る。
 */
function find_or_create_artist(PDO $pdo, string $bandName): ?int
{
    [$base] = band_split_suffix($bandName);
    $base = mb_substr(trim($base), 0, 100);
    if ($base === '') {
        return null;
    }
    static $index = null; // band_key → artist_id（1リクエスト中は使い回す）
    if ($index === null) {
        $index = [];
        foreach ($pdo->query('SELECT artist_id, name FROM artist') as $r) {
            $index[band_key($r['name'])] = (int)$r['artist_id'];
        }
    }
    $key = band_key($base);
    if (isset($index[$key])) {
        return $index[$key];
    }
    $pdo->prepare('INSERT IGNORE INTO artist (name) VALUES (?)')->execute([$base]);
    $st = $pdo->prepare('SELECT artist_id FROM artist WHERE name = ?');
    $st->execute([$base]);
    return $index[$key] = (int)$st->fetchColumn();
}

/**
 * 既存メンバーを「比較用キー → [id, name]」の配列にして返す。
 * member_key() で空白除去・全角半角統一・異体字（﨑→崎）をそろえてから比べるため。
 */
function load_member_index(PDO $pdo): array
{
    $index = [];
    foreach ($pdo->query('SELECT member_id, name FROM member') as $row) {
        $index[member_key($row['name'])] = ['id' => (int)$row['member_id'], 'name' => $row['name']];
    }
    return $index;
}

/**
 * 名前 → member_id。無ければ作る。
 * @param array $index load_member_index() の結果。新しく作った人もここに足していく（参照渡し &）
 */
function find_or_create_member(PDO $pdo, array &$index, string $name): int
{
    $key = member_key($name);
    if (isset($index[$key])) {
        return $index[$key]['id'];
    }
    $display = mb_substr(member_display($name), 0, 50);
    // name_kana は NULL 可なので省略できる（v2 の local_abbeydb では '' を入れる必要があった）
    $pdo->prepare('INSERT IGNORE INTO member (name) VALUES (?)')->execute([$display]);
    $st = $pdo->prepare('SELECT member_id FROM member WHERE name = ?');
    $st->execute([$display]);
    $id = (int)$st->fetchColumn();
    $index[$key] = ['id' => $id, 'name' => $display];
    return $id;
}

/**
 * バンドにメンバーを登録する。
 * @param array $assignments [[名前, instrument_id], ...]
 *
 * band_member の主キーが (band_id, member_id, instrument_id) なので、
 * 同じ組み合わせが2回来ても INSERT IGNORE で DB が無視してくれる（PHP で重複管理しなくていい）。
 */
function attach_members(PDO $pdo, array &$index, int $bandId, array $assignments): void
{
    $ins = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    foreach ($assignments as [$name, $instrumentId]) {
        $ins->execute([$bandId, find_or_create_member($pdo, $index, $name), $instrumentId ?? OTHER_INSTRUMENT_ID]);
    }
}

/** 楽器が分からないときに使う「その他」 */
const OTHER_INSTRUMENT_ID = 10;

function detach_members(PDO $pdo, int $bandId): void
{
    $pdo->prepare('DELETE FROM band_member WHERE band_id = ?')->execute([$bandId]);
}

/**
 * バンドのメンバーを「フォームの内容と同じ状態」にそろえる（差分だけ更新）。
 *
 * ⚠ なぜ「全部消して入れ直す」をやめたか:
 *   song_performer は band_member に ON DELETE CASCADE でぶら下がっている。
 *   全部消すと、曲ごとの演奏記録まで道連れで消えてしまう。
 *   → 「フォームから消えた (人, 楽器)」だけ DELETE、「新しく増えたもの」だけ INSERT する。
 */
function sync_band_members(PDO $pdo, array &$index, int $bandId, array $assignments): void
{
    // ほしい状態: "member_id-instrument_id" => true
    $want = [];
    foreach ($assignments as [$name, $instrumentId]) {
        $want[find_or_create_member($pdo, $index, $name) . '-' . ($instrumentId ?? OTHER_INSTRUMENT_ID)] = true;
    }
    // 今の状態
    $st = $pdo->prepare('SELECT member_id, instrument_id FROM band_member WHERE band_id = ?');
    $st->execute([$bandId]);
    $have = [];
    foreach ($st as $r) {
        $have[$r['member_id'] . '-' . $r['instrument_id']] = true;
    }

    $del = $pdo->prepare('DELETE FROM band_member WHERE band_id = ? AND member_id = ? AND instrument_id = ?');
    foreach (array_diff_key($have, $want) as $key => $_) {   // 今あるけど、ほしくないもの
        [$m, $i] = explode('-', $key);
        $del->execute([$bandId, $m, $i]);
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    foreach (array_diff_key($want, $have) as $key => $_) {   // ほしいけど、まだ無いもの
        [$m, $i] = explode('-', $key);
        $ins->execute([$bandId, $m, $i]);
    }
}

/**
 * バンドの曲（セットリスト）と、曲ごとの演奏者を保存する。
 *
 * @param array $songs 上から順に
 *   [['song_id' => 既存ならID / 新規なら null, 'title' => '曲名',
 *     'performers' => [[member_id, instrument_id], ...]], ...]
 *
 *  1. フォームから消えた曲を DELETE（演奏者は CASCADE で消える）
 *  2. track_no を 1,2,3... に振り直す（UNIQUE なので、いったん +100 に逃がしてから）
 *  3. 曲ごとに演奏者を入れ直す。曲だけ別の楽器を弾いた人は、先に band_member にその楽器を足す
 *     （song_performer の外部キーが band_member を指しているので、足さないと INSERT できない）
 *  4. band.song_count を曲数に合わせる
 */
function save_songs(PDO $pdo, int $bandId, array $songs): void
{
    $st = $pdo->prepare('SELECT song_id FROM song WHERE band_id = ?');
    $st->execute([$bandId]);
    $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $keep = array_filter(array_map(static fn($s) => $s['song_id'], $songs));

    $del = $pdo->prepare('DELETE FROM song WHERE song_id = ? AND band_id = ?');
    foreach (array_diff($existing, $keep) as $id) {
        $del->execute([$id, $bandId]);
    }

    $pdo->prepare('UPDATE song SET track_no = track_no + 100 WHERE band_id = ?')->execute([$bandId]);
    $update = $pdo->prepare('UPDATE song SET track_no = ?, title = ? WHERE song_id = ? AND band_id = ?');
    $insert = $pdo->prepare('INSERT INTO song (band_id, track_no, title) VALUES (?, ?, ?)');
    $addRole = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    $clear = $pdo->prepare('DELETE FROM song_performer WHERE song_id = ?');
    $addPerformer = $pdo->prepare('INSERT IGNORE INTO song_performer (song_id, band_id, member_id, instrument_id) VALUES (?, ?, ?, ?)');

    foreach (array_values($songs) as $i => $song) {
        if ($song['song_id'] && in_array($song['song_id'], $existing, true)) {
            $update->execute([$i + 1, $song['title'], $song['song_id'], $bandId]);
            $songId = $song['song_id'];
        } else {
            $insert->execute([$bandId, $i + 1, $song['title']]);
            $songId = (int)$pdo->lastInsertId();
        }
        $clear->execute([$songId]);
        foreach ($song['performers'] as [$memberId, $instrumentId]) {
            $addRole->execute([$bandId, $memberId, $instrumentId]);
            $addPerformer->execute([$songId, $bandId, $memberId, $instrumentId]);
        }
    }
    if ($songs) {
        $pdo->prepare('UPDATE band SET song_count = ? WHERE band_id = ?')->execute([count($songs), $bandId]);
    }
}

/**
 * 日程を削除する。band / band_member は ON DELETE CASCADE で DB が一緒に消す。
 * 日程が1つも無くなったライブも消す。
 */
function delete_live_day(PDO $pdo, int $liveDayId): void
{
    $st = $pdo->prepare('SELECT live_id FROM live_day WHERE live_day_id = ?');
    $st->execute([$liveDayId]);
    $liveId = $st->fetchColumn();
    if ($liveId === false) {
        return;
    }
    $pdo->prepare('DELETE FROM live_day WHERE live_day_id = ?')->execute([$liveDayId]);
    // NOT EXISTS: 「日程が1つも無いライブなら」消す
    $pdo->prepare('DELETE FROM live WHERE live_id = ? AND NOT EXISTS (SELECT 1 FROM live_day WHERE live_id = ?)')
        ->execute([$liveId, $liveId]);
}

/** バンドを1組削除（band_member は CASCADE で消える） */
function delete_band(PDO $pdo, int $bandId): void
{
    $pdo->prepare('DELETE FROM band WHERE band_id = ?')->execute([$bandId]);
}

/**
 * 2人のメンバーを1人にまとめる（表記ゆれで同じ人が2人登録されたとき用）。
 *
 *   1. INSERT IGNORE ... SELECT で、統合元の出演記録（バンド・曲）を統合先の名前でコピー
 *      （統合先にすでに同じ (バンド, 楽器) があれば主キーで弾かれる = 重複しない）
 *   2. 統合元の出演記録を消す
 *   3. ふりがな・入部年度は、統合先が空なら引き継ぐ
 *   4. 統合元を消す（アカウントの紐付けは、統合先が空いていれば付け替え）
 */
function merge_members(PDO $pdo, int $fromId, int $toId): void
{
    if ($fromId === $toId) {
        throw new RuntimeException('同じメンバー同士は統合できません');
    }
    $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id)
        SELECT band_id, ?, instrument_id FROM band_member WHERE member_id = ?')->execute([$toId, $fromId]);
    // 曲ごとの演奏記録も同じようにコピー（band_member を先にコピーしたので外部キーを満たせる）
    $pdo->prepare('INSERT IGNORE INTO song_performer (song_id, band_id, member_id, instrument_id)
        SELECT song_id, band_id, ?, instrument_id FROM song_performer WHERE member_id = ?')->execute([$toId, $fromId]);
    // 統合元の band_member を消すと、統合元の song_performer も CASCADE で消える
    $pdo->prepare('DELETE FROM band_member WHERE member_id = ?')->execute([$fromId]);

    // COALESCE(a, b): a が NULL なら b を使う
    $pdo->prepare('UPDATE member t JOIN member f ON f.member_id = ?
        SET t.name_kana = COALESCE(t.name_kana, f.name_kana), t.entry_year = COALESCE(t.entry_year, f.entry_year)
        WHERE t.member_id = ?')->execute([$fromId, $toId]);

    // 統合先にアカウントが紐付いていなければ、統合元のアカウントを付け替える
    $pdo->prepare('UPDATE user_account SET member_id = ?
        WHERE member_id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT member_id FROM user_account WHERE member_id = ?) t)')
        ->execute([$toId, $fromId, $toId]);

    // 統合元を消す（まだアカウントが紐付いていても ON DELETE SET NULL で外れる）
    $pdo->prepare('DELETE FROM member WHERE member_id = ?')->execute([$fromId]);
}

/**
 * ある日程のバンドの出演順を 1, 2, 3... に振り直す。
 *
 * ⚠ (live_day_id, play_order) が UNIQUE なので、いきなり 1,2,3 と UPDATE すると
 *   途中で「3番が2組」になった瞬間に制約エラーになる。
 *   → いったん全部を 1000 番台に逃がしてから、正しい番号に入れ直す（2段階）。
 */
function renumber_bands(PDO $pdo, int $liveDayId, ?int $movedBandId = null, ?int $position = null): void
{
    $st = $pdo->prepare('SELECT band_id FROM band WHERE live_day_id = ? ORDER BY play_order, band_id');
    $st->execute([$liveDayId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if ($movedBandId !== null && $position !== null) {
        $ids = array_values(array_diff($ids, [$movedBandId]));  // いったん抜いて
        $position = max(1, min($position, count($ids) + 1));
        array_splice($ids, $position - 1, 0, [$movedBandId]);  // 指定の位置に差し込む
    }
    $pdo->prepare('UPDATE band SET play_order = play_order + 1000 WHERE live_day_id = ?')->execute([$liveDayId]);
    $update = $pdo->prepare('UPDATE band SET play_order = ? WHERE band_id = ?');
    foreach ($ids as $i => $id) {
        $update->execute([$i + 1, $id]);
    }
}

/** その日程の「次の出演順」（最後尾） */
function next_play_order(PDO $pdo, int $liveDayId): int
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(play_order), 0) + 1 FROM band WHERE live_day_id = ?');
    $st->execute([$liveDayId]);
    return (int)$st->fetchColumn();
}

/**
 * user_account.member_id がまだ無いユーザーを、同じ名前のメンバーに紐付ける。
 * member_id は UNIQUE なので、既に誰かに紐付いているメンバーには紐付けない。
 */
function link_users_to_members(PDO $pdo): void
{
    $index = load_member_index($pdo);
    $update = $pdo->prepare('UPDATE IGNORE user_account SET member_id = ? WHERE user_id = ?');
    foreach ($pdo->query('SELECT user_id, name FROM user_account WHERE member_id IS NULL')->fetchAll() as $u) {
        $m = $index[member_key($u['name'])] ?? null;
        if ($m) {
            $update->execute([$m['id'], $u['user_id']]); // 既に使われていれば UNIQUE で無視される
        }
    }
}
