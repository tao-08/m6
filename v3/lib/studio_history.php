<?php
/**
 * =====================================================================
 *  lib/studio_history.php — スタジオ情報の編集履歴と復元（表は migrations/097）
 * =====================================================================
 *  しくみ（lib/history.php のライブ・バンドの履歴と同じ「変更前の状態を残す」方式。表は別にしてある）
 *    ・保存・AI反映・削除の POST が来たら、まず「今のスタジオ1件ぶん」を $before に取っておく（studio_history_watch）
 *    ・処理が終わったとき（redirect の exit のあと = register_shutdown_function）にもう一度取って、$before と違えば1行残す
 *      → 入力ミスで弾かれた・何も変えなかった保存は残らない。保存処理のほうは1行も変えなくてよい
 *    ・1行 = 「その操作の前」の状態。復元すると、スタジオ全体がその状態に戻る
 *    ・復元する前の状態も新しい行（action = restore_history）として残す → 戻したこともまた戻せる
 *    ・削除したスタジオも、削除の行（action = delete_studio）から復元できる（履歴は studio を消しても残る）
 *
 *  戻し方: 部屋・割引区分・料金・パック・レンタル・機材は「今のものを全部消して、snapshot から入れ直す」。
 *          行の番号（*_id）は新しく振る（番号は画面にも URL にも出さないので、同じでなくてよい。スタジオの番号だけは同じに戻す）
 * =====================================================================
 */
declare(strict_types=1);

/** 操作の種類 → 画面に出す名前 */
const STUDIO_HISTORY_ACTIONS = [
    'save_info'      => '基本情報',
    'save_rooms'     => '部屋',
    'save_discounts' => '割引区分',
    'save_rates'     => '料金',
    'save_packs'     => 'パック',
    'save_rentals'   => 'レンタル',
    'save_equipment' => '機材',
    'apply_ai'       => 'AIで入力',
    'delete_studio'  => '削除',
    'restore_history' => '復元',
];

/** 履歴の1行に出す題（基本情報の変更 / AIで入力 / 復元）。知らない action はそのまま出す */
function studio_history_label(string $action): string
{
    $name = STUDIO_HISTORY_ACTIONS[$action] ?? $action;
    return in_array($action, ['apply_ai', 'delete_studio', 'restore_history'], true) ? $name : $name . 'の変更';
}

/** 履歴を残す日数。これより古い行は、新しく記録するときに消す */
const STUDIO_HISTORY_KEEP_DAYS = 365;

/** 画面に出す件数（スタジオごと） */
const STUDIO_HISTORY_SHOW = 30;

/**
 * snapshot に入れる表（SQL に名前を入れてよいのはここに書いたものだけ）。
 *   [表 => [主キー, 取り方]]。studio_id を持つ表は直接、持たない中間テーブルは親の表を JOIN して取る
 */
const STUDIO_HISTORY_TABLES = [
    'studio_room'           => ['studio_room_id',      'SELECT * FROM studio_room WHERE studio_id = ? ORDER BY studio_room_id'],
    'studio_discount'       => ['studio_discount_id',  'SELECT * FROM studio_discount WHERE studio_id = ? ORDER BY studio_discount_id'],
    'studio_rate'           => ['studio_rate_id',      'SELECT * FROM studio_rate WHERE studio_id = ? ORDER BY studio_rate_id'],
    'studio_pack'           => ['studio_pack_id',      'SELECT * FROM studio_pack WHERE studio_id = ? ORDER BY studio_pack_id'],
    'studio_rental'         => ['studio_rental_id',    'SELECT * FROM studio_rental WHERE studio_id = ? ORDER BY studio_rental_id'],
    'studio_equipment'      => ['studio_equipment_id', 'SELECT * FROM studio_equipment WHERE studio_id = ? ORDER BY studio_equipment_id'],
    'studio_rate_room'      => [null, 'SELECT c.* FROM studio_rate_room c JOIN studio_rate p ON p.studio_rate_id = c.studio_rate_id WHERE p.studio_id = ? ORDER BY c.studio_rate_id, c.studio_room_id'],
    'studio_rate_discount'  => [null, 'SELECT c.* FROM studio_rate_discount c JOIN studio_rate p ON p.studio_rate_id = c.studio_rate_id WHERE p.studio_id = ? ORDER BY c.studio_rate_id, c.studio_discount_id'],
    'studio_pack_room'      => [null, 'SELECT c.* FROM studio_pack_room c JOIN studio_pack p ON p.studio_pack_id = c.studio_pack_id WHERE p.studio_id = ? ORDER BY c.studio_pack_id, c.studio_room_id'],
    'studio_pack_discount'  => [null, 'SELECT c.* FROM studio_pack_discount c JOIN studio_pack p ON p.studio_pack_id = c.studio_pack_id WHERE p.studio_id = ? ORDER BY c.studio_pack_id, c.studio_discount_id'],
    'studio_equipment_room' => [null, 'SELECT c.* FROM studio_equipment_room c JOIN studio_equipment p ON p.studio_equipment_id = c.studio_equipment_id WHERE p.studio_id = ? ORDER BY c.studio_equipment_id, c.studio_room_id'],
];

/** 中間テーブルの入れ直し方: [中間テーブル, 親の表, 親の主キー, 相手の表, 相手の主キー]（相手 = 部屋 / 割引区分） */
const STUDIO_HISTORY_LINKS = [
    ['studio_rate_room',      'studio_rate',      'studio_rate_id',      'studio_room',     'studio_room_id'],
    ['studio_rate_discount',  'studio_rate',      'studio_rate_id',      'studio_discount', 'studio_discount_id'],
    ['studio_pack_room',      'studio_pack',      'studio_pack_id',      'studio_room',     'studio_room_id'],
    ['studio_pack_discount',  'studio_pack',      'studio_pack_id',      'studio_discount', 'studio_discount_id'],
    ['studio_equipment_room', 'studio_equipment', 'studio_equipment_id', 'studio_room',     'studio_room_id'],
];

// =====================================================================
//  今の状態を取る / 文字にする
// =====================================================================

/** スタジオ1件ぶん（基本情報 + 子の表）の今の状態。スタジオが無ければ null */
function studio_history_snapshot(PDO $pdo, int $studioId): ?array
{
    $st = $pdo->prepare('SELECT * FROM studio WHERE studio_id = ?');
    $st->execute([$studioId]);
    $studio = $st->fetch(PDO::FETCH_ASSOC);
    if (!$studio) {
        return null;
    }
    $data = ['studio' => $studio];
    foreach (STUDIO_HISTORY_TABLES as $table => [, $sql]) {
        $st = $pdo->prepare($sql); // $sql は上の定数だけ（外から来た文字は入れない）
        $st->execute([$studioId]);
        $data[$table] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/** 2つの状態が同じか。「いつ・誰が更新したか」の欄は、中身の変更ではないので比べない */
function studio_history_same(?array $a, ?array $b): bool
{
    $strip = static function (?array $x): ?array {
        if ($x !== null) {
            unset($x['studio']['updated_by'], $x['studio']['updated_at']);
        }
        return $x;
    };
    return json_encode($strip($a)) === json_encode($strip($b));
}

/** snapshot → DB に入れる文字。JSON を圧縮して base64（文字コードの変換をさせないため ASCII にする）。先頭 Z = 圧縮 / J = そのまま */
function studio_history_encode(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $z = function_exists('gzdeflate') ? gzdeflate($json, 6) : false;
    return $z !== false ? 'Z' . base64_encode($z) : 'J' . $json;
}

function studio_history_decode(string $blob): array
{
    $json = ($blob[0] ?? '') === 'Z' ? gzinflate((string)base64_decode(substr($blob, 1), true)) : substr($blob, 1);
    if (!is_string($json)) {
        throw new RuntimeException('履歴の中身を読めませんでした');
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

// =====================================================================
//  記録する
// =====================================================================

/** 履歴を1行足す。$before = その操作の前の状態。古い行もここで消す */
function studio_history_add(PDO $pdo, string $action, array $before, ?int $userId): void
{
    $pdo->exec('DELETE FROM studio_history WHERE created_at < NOW() - INTERVAL ' . STUDIO_HISTORY_KEEP_DAYS . ' DAY');
    $pdo->prepare('INSERT INTO studio_history (studio_id, studio_name, user_id, action, snapshot) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int)$before['studio']['studio_id'], mb_substr((string)$before['studio']['name'], 0, 100), $userId, $action, studio_history_encode($before)]);
}

/**
 * この POST が終わったとき、スタジオが変わっていれば「変わる前」を1行残す（POST の最初で1回呼ぶ）。
 *   記録に失敗しても、保存そのものは終わっているので、エラーページにはせずログだけ残す
 *   （表をまだ作っていない本番でも、編集は止まらない）
 */
function studio_history_watch(PDO $pdo, int $studioId, string $action, ?int $userId): void
{
    try {
        $before = studio_history_snapshot($pdo, $studioId);
    } catch (Throwable $e) {
        error_log('studio_history_watch: ' . $e->getMessage());
        return;
    }
    if ($before === null) {
        return;
    }
    register_shutdown_function(static function () use ($pdo, $studioId, $action, $userId, $before): void {
        try {
            if ($pdo->inTransaction()) {
                return; // 途中で止まった（保存が終わっていない）
            }
            if (!studio_history_same($before, studio_history_snapshot($pdo, $studioId))) {
                studio_history_add($pdo, $action, $before, $userId);
            }
        } catch (Throwable $e) {
            error_log('studio_history_watch: ' . $e->getMessage());
        }
    });
}

// =====================================================================
//  読む
// =====================================================================

/** 1スタジオの履歴（新しい順）。['history_id', 'action', 'created_at', 'user_name'] */
function studio_history_list(PDO $pdo, int $studioId): array
{
    $st = $pdo->prepare('SELECT h.history_id, h.action, h.created_at, u.name AS user_name FROM studio_history h
            LEFT JOIN user_account u ON u.user_id = h.user_id WHERE h.studio_id = ? ORDER BY h.history_id DESC LIMIT ' . STUDIO_HISTORY_SHOW);
    $st->execute([$studioId]);
    return $st->fetchAll();
}

/** 削除されたスタジオ（新しい順。同じスタジオは最後の削除だけ）。復元の候補 */
function studio_history_deleted(PDO $pdo): array
{
    return $pdo->query("SELECT h.history_id, h.studio_name, h.created_at, u.name AS user_name FROM studio_history h
            LEFT JOIN user_account u ON u.user_id = h.user_id
            WHERE h.action = 'delete_studio'
              AND NOT EXISTS (SELECT 1 FROM studio s WHERE s.studio_id = h.studio_id)
              AND h.history_id = (SELECT MAX(x.history_id) FROM studio_history x WHERE x.studio_id = h.studio_id AND x.action = 'delete_studio')
            ORDER BY h.history_id DESC LIMIT 20")->fetchAll();
}

// =====================================================================
//  復元する
// =====================================================================

/**
 * 履歴 $historyId の「前」の状態にスタジオを戻す。戻す前の状態は新しい履歴として残す。
 *   $onlyStudioId を渡すと、そのスタジオの履歴でなければ戻さない（別のスタジオの履歴番号を送られても触らない）
 * @return int 戻したスタジオの番号
 * @throws RuntimeException 戻せないとき（画面にそのまま出す文）
 */
function studio_history_restore(PDO $pdo, int $historyId, ?int $userId, ?int $onlyStudioId = null): int
{
    $st = $pdo->prepare('SELECT studio_id, action, snapshot FROM studio_history WHERE history_id = ?');
    $st->execute([$historyId]);
    $h = $st->fetch();
    if (!$h || ($onlyStudioId !== null && (int)$h['studio_id'] !== $onlyStudioId)) {
        throw new RuntimeException('その履歴は見つかりません（1年より前のものは削除されます）');
    }
    $snap = studio_history_decode((string)$h['snapshot']);
    $studioId = (int)$snap['studio']['studio_id'];

    $pdo->beginTransaction();
    try {
        // 1. 戻す前の状態を残す（スタジオが削除済みなら残すものは無い）
        $now = studio_history_snapshot($pdo, $studioId);
        if ($now !== null) {
            studio_history_add($pdo, 'restore_history', $now, $userId);
            // 2. 今の子の行を全部消す（中間テーブルは ON DELETE CASCADE で一緒に消える）
            foreach (['studio_rate', 'studio_pack', 'studio_equipment', 'studio_rental', 'studio_room', 'studio_discount'] as $table) {
                $pdo->prepare("DELETE FROM `$table` WHERE studio_id = ?")->execute([$studioId]);
            }
        }
        // 3. 基本情報。更新者は復元した人（前の更新者のアカウントが消えていても外部キーで失敗しないように）
        $row = $snap['studio'];
        $row['updated_by'] = $userId;
        unset($row['updated_at']);
        if ($now !== null) {
            $set = array_diff(array_keys($row), ['studio_id']);
            $pdo->prepare('UPDATE studio SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", $set)) . ' WHERE studio_id = ?')
                ->execute([...array_map(static fn($c) => $row[$c], $set), $studioId]);
        } else {
            studio_history_insert($pdo, 'studio', $row);
        }
        // 4. 子の行を入れ直す。番号は新しく振り、{前の番号 => 新しい番号} を覚えて中間テーブルをつなぐ
        $map = [];
        foreach (['studio_room', 'studio_discount', 'studio_rate', 'studio_pack', 'studio_rental', 'studio_equipment'] as $table) {
            $pk = STUDIO_HISTORY_TABLES[$table][0];
            foreach ($snap[$table] as $r) {
                $old = (int)$r[$pk];
                unset($r[$pk]);
                $r['studio_id'] = $studioId;
                $map[$table][$old] = studio_history_insert($pdo, $table, $r);
            }
        }
        foreach (STUDIO_HISTORY_LINKS as [$link, $parent, $parentPk, $other, $otherPk]) {
            foreach ($snap[$link] as $r) {
                $p = $map[$parent][(int)$r[$parentPk]] ?? null;
                $o = $map[$other][(int)$r[$otherPk]] ?? null;
                if ($p === null || $o === null) {
                    continue; // つなぐ先が snapshot に無い（途中で壊れた履歴）。この行だけ飛ばす
                }
                $r[$parentPk] = $p;
                $r[$otherPk] = $o;
                studio_history_insert($pdo, $link, $r);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $studioId;
}

/** 表の今の列だけで INSERT する（履歴を取ったあとに列が増えたり減ったりしても戻せるように）。戻り値 = 新しい AUTO_INCREMENT の番号（無い表は 0） */
function studio_history_insert(PDO $pdo, string $table, array $row): int
{
    static $columns = [];
    if ($table !== 'studio' && !isset(STUDIO_HISTORY_TABLES[$table])) {
        throw new LogicException("studio_history_insert: {$table}"); // 表の名前は定数にあるものだけ
    }
    $columns[$table] ??= $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_values(array_intersect(array_keys($row), $columns[$table]));
    $pdo->prepare("INSERT INTO `$table` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_map(static fn($c) => $row[$c], $cols));
    return (int)$pdo->lastInsertId();
}
