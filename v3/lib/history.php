<?php
/**
 * =====================================================================
 *  history.php — 変更履歴と「元に戻す」（画面は ../history.php。migrations/028）
 * =====================================================================
 *  しくみ:
 *    ・保存ボタン1回 = change_op 1行（誰が・いつ・何をしたか）
 *    ・その操作で変わるもの（ライブ / 日程 / バンド）の「変更前の状態」を change_unit に1つずつ保存する
 *      変更前は無かった（その操作で作られた）ものは snapshot = NULL
 *    ・「元に戻す」は、その op の unit を全部「変更前の状態」に戻す。戻す前の状態も新しい op として残すので、戻したこともまた戻せる
 *
 *  使い方（各ページの保存処理。必ずトランザクションの中で、変更する「前」に呼ぶ）:
 *      $op = change_begin($pdo, 'band_edit', '「King Gnu」を更新', $liveId);
 *      change_capture($pdo, $op, 'band', $bandId);   // ← ここで今の状態を保存
 *      ...UPDATE / DELETE...
 *    新しく作ったものは、作った「後」に change_created($pdo, $op, 'band', $newId)
 *    失敗してロールバックすると、履歴も一緒に取り消される（同じトランザクションなので）
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/repository.php';

/** 操作の種類 → 画面に出す名前 */
const CHANGE_ACTIONS = [
    'band_add'    => 'バンド追加',
    'band_edit'   => 'バンド編集',
    'band_delete' => 'バンド削除',
    'setlist'     => 'セットリスト編集',
    'flag_check'  => '確認済み',
    'timetable'   => 'タイムテーブル編集',
    'youtube'     => 'YouTube のリンク',
    'live_add'    => 'ライブ追加',
    'live_edit'   => 'ライブ編集',
    'live_merge'  => 'ライブ統合',
    'day_delete'  => '日程削除',
    'import'      => '取り込み',
    'restore'     => '元に戻す',
];

/** 履歴を残す日数。これより古い操作は、新しく記録するときに消す */
const CHANGE_KEEP_DAYS = 365;

/** バンドの snapshot に入れる表（どれも band_id を持つ） */
const CHANGE_BAND_CHILDREN = ['band_member', 'band_check_member', 'song', 'song_performer', 'band_like', 'check_dismiss'];

/** SQL に名前を入れてよい表（ここに書いたものだけ。外から来た文字は入れない） */
const CHANGE_TABLES = ['live', 'live_day', 'live_break', 'band', ...CHANGE_BAND_CHILDREN,
    'artist', 'venue', 'member', 'instrument', 'user_account', 'track'];

// =====================================================================
//  記録する
// =====================================================================

/** 操作を1つ始める（change_op を1行作る）。戻り値の op_id を change_capture などに渡す */
function change_begin(PDO $pdo, string $action, string $summary = '', ?int $liveId = null): int
{
    // 1年より古い履歴を消す（change_unit は ON DELETE CASCADE で一緒に消える）
    $pdo->exec('DELETE FROM change_op WHERE created_at < NOW() - INTERVAL ' . CHANGE_KEEP_DAYS . ' DAY');
    $pdo->prepare('INSERT INTO change_op (user_id, action, summary, live_id) VALUES (?, ?, ?, ?)')
        ->execute([current_user()['user_id'] ?? null, $action, mb_substr($summary, 0, 255), $liveId]);
    return (int)$pdo->lastInsertId();
}

/** 操作の説明を後から書き換える（取り込みのように、終わるまで中身が決まらないとき） */
function change_set_summary(PDO $pdo, int $op, string $summary, ?int $liveId = null): void
{
    $pdo->prepare('UPDATE change_op SET summary = ?, live_id = COALESCE(?, live_id) WHERE op_id = ?')
        ->execute([mb_substr($summary, 0, 255), $liveId, $op]);
}

/** ライブ / 日程 / バンドの「今の状態」を保存する。変更する前に呼ぶ。同じ op で2回目は何もしない（最初の状態を残す） */
function change_capture(PDO $pdo, int $op, string $type, int $id): void
{
    if (change_has_unit($pdo, $op, $type, $id)) {
        return;
    }
    $snapshot = change_snapshot($pdo, $type, $id);
    $pdo->prepare('INSERT INTO change_unit (op_id, unit_type, unit_id, label, snapshot) VALUES (?, ?, ?, ?, ?)')
        ->execute([$op, $type, $id, change_label($pdo, $type, $id), $snapshot === null ? null : change_encode($snapshot)]);
}

/** この操作で新しく作ったもの（変更前は無かった）を記録する。作った後に呼ぶ */
function change_created(PDO $pdo, int $op, string $type, int $id): void
{
    if (change_has_unit($pdo, $op, $type, $id)) {
        return;
    }
    $pdo->prepare('INSERT INTO change_unit (op_id, unit_type, unit_id, label, snapshot) VALUES (?, ?, ?, ?, NULL)')
        ->execute([$op, $type, $id, change_label($pdo, $type, $id)]);
}

/** 日程と、その日程の全バンドを保存する */
function change_capture_day(PDO $pdo, int $op, int $dayId): void
{
    change_capture($pdo, $op, 'day', $dayId);
    $st = $pdo->prepare('SELECT band_id FROM band WHERE live_day_id = ?');
    $st->execute([$dayId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bandId) {
        change_capture($pdo, $op, 'band', (int)$bandId);
    }
}

/** ライブと、その全日程・全バンドを保存する */
function change_capture_live(PDO $pdo, int $op, int $liveId): void
{
    change_capture($pdo, $op, 'live', $liveId);
    $st = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ?');
    $st->execute([$liveId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $dayId) {
        change_capture_day($pdo, $op, (int)$dayId);
    }
}

function change_has_unit(PDO $pdo, int $op, string $type, int $id): bool
{
    $st = $pdo->prepare('SELECT 1 FROM change_unit WHERE op_id = ? AND unit_type = ? AND unit_id = ?');
    $st->execute([$op, $type, $id]);
    return (bool)$st->fetchColumn();
}

/** 今の状態を配列にする。無ければ null */
function change_snapshot(PDO $pdo, string $type, int $id): ?array
{
    $rows = static function (string $sql) use ($pdo, $id): array {
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    if ($type === 'live') {
        $live = $rows('SELECT * FROM live WHERE live_id = ?');
        return $live ? ['live' => $live[0]] : null;
    }
    if ($type === 'day') {
        $day = $rows('SELECT * FROM live_day WHERE live_day_id = ?');
        return $day ? ['live_day' => $day[0], 'live_break' => $rows('SELECT * FROM live_break WHERE live_day_id = ?')] : null;
    }
    $band = $rows('SELECT * FROM band WHERE band_id = ?');
    if (!$band) {
        return null;
    }
    $data = ['band' => $band[0]];
    foreach (CHANGE_BAND_CHILDREN as $table) { // 表の名前は上の定数だけ
        $data[$table] = $rows("SELECT * FROM `$table` WHERE band_id = ?");
    }
    return $data;
}

/** 画面に出す名前（2024年度 2月ライブ 1日目「King Gnu」）。無ければ「（削除済み）」 */
function change_label(PDO $pdo, string $type, int $id): string
{
    $sql = [
        'live' => 'SELECT l.fiscal_year, l.name AS live_name, NULL AS label, NULL AS band_name FROM live l WHERE l.live_id = ?',
        'day'  => 'SELECT l.fiscal_year, l.name AS live_name, d.label, NULL AS band_name FROM live_day d JOIN live l ON l.live_id = d.live_id WHERE d.live_day_id = ?',
        'band' => 'SELECT l.fiscal_year, l.name AS live_name, d.label, b.name AS band_name FROM band b
                   JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id WHERE b.band_id = ?',
    ][$type];
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        return '（削除済み）';
    }
    $text = $r['fiscal_year'] . '年度 ' . $r['live_name'] . ($r['label'] !== null ? ' ' . $r['label'] : '')
        . ($r['band_name'] !== null ? '「' . $r['band_name'] . '」' : '');
    return mb_substr($text, 0, 150);
}

/**
 * snapshot を DB に入れる文字にする。JSON を圧縮して base64（BLOB に文字コードの変換をさせないため ASCII にしておく）。
 * 圧縮の関数が無いサーバーでは JSON のまま。先頭1文字（Z / J）でどちらか分かるようにする
 */
function change_encode(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (function_exists('gzdeflate')) {
        $z = gzdeflate($json, 6);
        if ($z !== false) {
            return 'Z' . base64_encode($z);
        }
    }
    return 'J' . $json;
}

function change_decode(?string $blob): ?array
{
    if ($blob === null) {
        return null;
    }
    $json = $blob[0] === 'Z' ? gzinflate((string)base64_decode(substr($blob, 1), true)) : substr($blob, 1);
    if (!is_string($json)) {
        throw new RuntimeException('履歴の中身を読めませんでした');
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

// =====================================================================
//  元に戻す
// =====================================================================

/**
 * 操作 $opId の前の状態に戻す。
 *   1. 戻す前の状態を、新しい op（restore）として残す（戻したこともまた戻せる）
 *   2. その操作で作られたもの（snapshot = NULL）を消す（バンド → 日程 → ライブ）
 *   3. 変更前の状態を入れ直す（ライブ → 日程 → バンド。ID は前と同じ）
 * @return array{live_id: ?int, skipped: int} skipped = 参照先（メンバーなど）が消えていて戻せなかった行の数
 * @throws RuntimeException 戻せないとき（画面にそのまま出す文）
 */
function restore_change_op(PDO $pdo, int $opId): array
{
    $st = $pdo->prepare('SELECT * FROM change_op WHERE op_id = ?');
    $st->execute([$opId]);
    $op = $st->fetch();
    if (!$op) {
        throw new RuntimeException('その変更履歴は見つかりません（1年より前のものは消えています）');
    }
    $st = $pdo->prepare('SELECT unit_type, unit_id, snapshot FROM change_unit WHERE op_id = ?');
    $st->execute([$opId]);
    $units = ['live' => [], 'day' => [], 'band' => []]; // 種類 => [id => snapshot（null = 作られたもの）]
    foreach ($st as $u) {
        $units[$u['unit_type']][(int)$u['unit_id']] = change_decode($u['snapshot']);
    }

    $skipped = 0;
    $pdo->beginTransaction();
    try {
        // 1. 戻す前の状態を残す
        $new = change_begin($pdo, 'restore', '#' . $opId . '「' . (CHANGE_ACTIONS[$op['action']] ?? $op['action']) . '」を元に戻した', $op['live_id'] !== null ? (int)$op['live_id'] : null);
        //   下の 2. で消すもの（その操作で作られたもの）は中身ごと消えるので、中の日程・バンドまで残す
        //   （残さないと、この「元に戻す」をさらに戻したときに、空の日程だけが戻ってしまう）
        foreach ($units as $type => $list) {
            foreach ($list as $id => $snap) {
                if ($snap === null && $type === 'live') {
                    change_capture_live($pdo, $new, $id);
                } elseif ($snap === null && $type === 'day') {
                    change_capture_day($pdo, $new, $id);
                } else {
                    change_capture($pdo, $new, $type, $id);
                }
            }
        }

        // 2. その操作で作られたものを消す（中身は ON DELETE CASCADE で一緒に消える）
        foreach ($units['band'] as $id => $snap) {
            if ($snap === null) {
                $dayId = change_band_day($pdo, $id);
                $pdo->prepare('DELETE FROM band WHERE band_id = ?')->execute([$id]);
                if ($dayId !== null) {
                    renumber_bands($pdo, $dayId); // 抜けた出演順を詰める
                }
            }
        }
        foreach ($units['day'] as $id => $snap) {
            if ($snap === null) {
                $pdo->prepare('DELETE FROM live_day WHERE live_day_id = ?')->execute([$id]);
            }
        }
        foreach ($units['live'] as $id => $snap) {
            if ($snap === null) {
                $pdo->prepare('DELETE FROM live WHERE live_id = ?')->execute([$id]);
            }
        }

        // 3. 変更前の状態を入れ直す（親から順に）
        foreach (array_filter($units['live']) as $id => $snap) {
            restore_live_row($pdo, $id, $snap['live']);
        }
        restore_days($pdo, array_filter($units['day']));
        // バンドは元の出演順の小さい順に戻す（1組ずつ「元の位置」へ差し込むので、前から入れれば全部が元の並びになる）
        $bands = array_filter($units['band']);
        uksort($bands, static fn($a, $b) => [$bands[$a]['band']['live_day_id'], $bands[$a]['band']['play_order']]
            <=> [$bands[$b]['band']['live_day_id'], $bands[$b]['band']['play_order']]);
        foreach ($bands as $id => $snap) {
            $skipped += restore_band($pdo, $id, $snap);
        }

        $pdo->prepare('UPDATE change_op SET restored_at = NOW(), restored_by = ? WHERE op_id = ?')
            ->execute([current_user()['user_id'] ?? null, $opId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // 戻した後に見せるライブ（まだあれば）
    $liveId = $op['live_id'] !== null ? (int)$op['live_id'] : null;
    if ($liveId !== null && !change_row_exists($pdo, 'live', 'live_id', $liveId)) {
        $liveId = null;
    }
    return ['live_id' => $liveId, 'skipped' => $skipped];
}

function restore_live_row(PDO $pdo, int $id, array $row): void
{
    $st = $pdo->prepare('SELECT live_id FROM live WHERE fiscal_year = ? AND name = ? AND live_id <> ?');
    $st->execute([$row['fiscal_year'], $row['name'], $id]);
    if ($st->fetchColumn()) {
        throw new RuntimeException("同じ年度・同じ名前のライブ（{$row['fiscal_year']}年度 {$row['name']}）が別にあるので戻せません。先にそのライブを統合・改名してください");
    }
    change_upsert($pdo, 'live', 'live_id', $id, $row);
}

/** 日程をまとめて戻す。「1日目 ⇄ 2日目」の入れ替えを戻せるよう、先に全部を仮の名前（#ID）にしてから入れる */
function restore_days(PDO $pdo, array $days): void
{
    $tmp = $pdo->prepare("UPDATE live_day SET label = CONCAT('#', live_day_id) WHERE live_day_id = ?");
    foreach (array_keys($days) as $id) {
        $tmp->execute([$id]);
    }
    foreach ($days as $id => $snap) {
        $row = $snap['live_day'];
        if (!change_row_exists($pdo, 'live', 'live_id', (int)$row['live_id'])) {
            throw new RuntimeException("日程「{$row['label']}」のライブがもう無いので戻せません");
        }
        if ($row['venue_id'] !== null && !change_row_exists($pdo, 'venue', 'venue_id', (int)$row['venue_id'])) {
            $row['venue_id'] = null; // 会場が消えていたら「会場なし」で戻す
        }
        try {
            change_upsert($pdo, 'live_day', 'live_day_id', $id, $row);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            throw new RuntimeException("同じライブに「{$row['label']}」という日程が別にあるので戻せません");
        }
        // 休憩は日程ごとに入れ直す（break_id は新しく振る）
        $pdo->prepare('DELETE FROM live_break WHERE live_day_id = ?')->execute([$id]);
        foreach ($snap['live_break'] as $k) {
            unset($k['break_id']);
            change_insert($pdo, 'live_break', $k);
        }
    }
}

/**
 * バンドを1組、前の状態に戻す（同じ band_id で入れ直す）。
 * @return int 参照先が消えていて入れられなかった行の数
 */
function restore_band(PDO $pdo, int $id, array $snap): int
{
    $row = $snap['band'];
    $dayId = (int)$row['live_day_id'];
    if (!change_row_exists($pdo, 'live_day', 'live_day_id', $dayId)) {
        throw new RuntimeException("「{$row['name']}」の日程がもう無いので戻せません。日程を消した変更を先に元に戻してください");
    }
    $skipped = 0;

    // いいね・お知らせの ✕ は、戻した後も今の分を残す（戻す操作で、関係ない人のいいねを消さない）
    $keep = [];
    foreach (['band_like', 'check_dismiss'] as $table) {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE band_id = ?");
        $st->execute([$id]);
        $keep[$table] = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // 今のバンドを消して（中身は CASCADE で消える）、同じ ID で入れ直す。出演順はいったん最後尾 → 元の位置へ
    $oldDay = change_band_day($pdo, $id);
    $pdo->prepare('DELETE FROM band WHERE band_id = ?')->execute([$id]);
    if ($row['artist_id'] !== null && !change_row_exists($pdo, 'artist', 'artist_id', (int)$row['artist_id'])) {
        $row['artist_id'] = null;
    }
    $position = (int)$row['play_order'];
    $row['play_order'] = next_play_order($pdo, $dayId);
    change_insert($pdo, 'band', $row);
    renumber_bands($pdo, $dayId, $id, $position);
    if ($oldDay !== null && $oldDay !== $dayId) {
        renumber_bands($pdo, $oldDay); // 別の日程に移っていたら、そっちの出演順も詰める
    }

    // メンバー（メンバー・楽器が消えていたら飛ばす）
    $roles = []; // 入れられた "member_id:instrument_id"（曲ごとの演奏者は、これがある人だけ入れられる）
    foreach ($snap['band_member'] as $m) {
        if (!change_row_exists($pdo, 'member', 'member_id', (int)$m['member_id'])
            || !change_row_exists($pdo, 'instrument', 'instrument_id', (int)$m['instrument_id'])) {
            $skipped++;
            continue;
        }
        change_insert($pdo, 'band_member', $m);
        $roles[$m['member_id'] . ':' . $m['instrument_id']] = true;
    }
    foreach ($snap['band_check_member'] as $c) {
        if (!change_row_exists($pdo, 'member', 'member_id', (int)$c['member_id'])) {
            $skipped++;
            continue;
        }
        change_insert($pdo, 'band_check_member', $c);
    }

    // 曲。song_id はなるべく同じにする（もう別の曲が使っていたら新しく振って、演奏者の行を付け替える）
    $songIds = []; // 前の song_id => 入れた song_id
    foreach ($snap['song'] as $s) {
        if ($s['artist_id'] !== null && !change_row_exists($pdo, 'artist', 'artist_id', (int)$s['artist_id'])) {
            $s['artist_id'] = null;
        }
        if ($s['track_id'] !== null && !change_track_exists($pdo, (string)$s['track_source'], (string)$s['track_id'])) {
            $s['track_source'] = $s['track_id'] = null; // ck_song_ext_track: 2つとも NULL にする
        }
        $old = (int)$s['song_id'];
        if (change_row_exists($pdo, 'song', 'song_id', $old)) {
            unset($s['song_id']);
            change_insert($pdo, 'song', $s);
            $songIds[$old] = (int)$pdo->lastInsertId();
        } else {
            change_insert($pdo, 'song', $s);
            $songIds[$old] = $old;
        }
    }
    foreach ($snap['song_performer'] as $p) {
        if (!isset($songIds[(int)$p['song_id']], $roles[$p['member_id'] . ':' . $p['instrument_id']])) {
            $skipped++;
            continue;
        }
        $p['song_id'] = $songIds[(int)$p['song_id']];
        change_insert($pdo, 'song_performer', $p);
    }

    // いいね・✕: 前の分と今の分を両方入れる（同じ人は主キーで1つ。消えたアカウントの分は入れない）
    foreach (['band_like', 'check_dismiss'] as $table) {
        foreach ([...$snap[$table], ...$keep[$table]] as $r) {
            if (change_row_exists($pdo, 'user_account', 'user_id', (int)$r['user_id'])) {
                change_insert($pdo, $table, $r, true);
            }
        }
    }
    sync_day_total_bands($pdo, $dayId);
    return $skipped;
}

// ---------------------------------------------------------------------
//  小さな道具（表・列の名前は CHANGE_TABLES と DB の SHOW COLUMNS からだけ作る）
// ---------------------------------------------------------------------

/** 表の今の列（snapshot の後で列が増えたり減ったりしても、今ある列だけで INSERT する） */
function change_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!in_array($table, CHANGE_TABLES, true)) {
        throw new LogicException("change_columns: {$table}");
    }
    return $cache[$table] ??= $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
}

function change_insert(PDO $pdo, string $table, array $row, bool $ignore = false): void
{
    $cols = array_values(array_intersect(array_keys($row), change_columns($pdo, $table)));
    $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO `$table` (`" . implode('`, `', $cols) . '`) VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_map(static fn($c) => $row[$c], $cols));
}

/** 行があれば UPDATE、無ければ同じ ID で INSERT */
function change_upsert(PDO $pdo, string $table, string $key, int $id, array $row): void
{
    if (!change_row_exists($pdo, $table, $key, $id)) {
        change_insert($pdo, $table, $row);
        return;
    }
    $cols = array_values(array_diff(array_intersect(array_keys($row), change_columns($pdo, $table)), [$key]));
    $sql = "UPDATE `$table` SET " . implode(', ', array_map(static fn($c) => "`$c` = ?", $cols)) . " WHERE `$key` = ?";
    $pdo->prepare($sql)->execute([...array_map(static fn($c) => $row[$c], $cols), $id]);
}

function change_row_exists(PDO $pdo, string $table, string $key, int $id): bool
{
    if (!in_array($table, CHANGE_TABLES, true) || !in_array($key, change_columns($pdo, $table), true)) {
        throw new LogicException("change_row_exists: {$table}.{$key}");
    }
    $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$key` = ?");
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function change_track_exists(PDO $pdo, string $source, string $trackId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM track WHERE source = ? AND track_id = ?');
    $st->execute([$source, $trackId]);
    return (bool)$st->fetchColumn();
}

/** バンドが今いる日程（いなければ null） */
function change_band_day(PDO $pdo, int $bandId): ?int
{
    $st = $pdo->prepare('SELECT live_day_id FROM band WHERE band_id = ?');
    $st->execute([$bandId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}
