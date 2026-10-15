<?php
/**
 * =====================================================================
 *  lib/board.php — 掲示板の共通処理（board.php・board_thread.php・partials/header.php）
 * =====================================================================
 *  スレの種類（board_thread.kind）
 *    free   … 誰でも立てられる。ログインしていれば全員が読み書きできる
 *    active … 現役用。1〜4年で退会していないメンバーだけ
 *    cohort … 同期用。入部年度（member.entry_year）が同じメンバーだけ。学年が上がっても同じスレ
 *  管理者はすべて見られる（削除されたスレ・書き込みも）。
 *  メンバーに紐付いていないアカウントは free だけ。
 * =====================================================================
 */
declare(strict_types=1);

/** ログイン中のユーザーが読み書きできる範囲。['entry_year' => 2025 か null, 'active' => bool] */
function board_scope(array $user): array
{
    static $cache = [];
    $uid = (int)$user['user_id'];
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    $entryYear = null;
    $active = false;
    if (!empty($user['member_id'])) {
        $st = db()->prepare('SELECT entry_year, retired FROM member WHERE member_id = ?');
        $st->execute([(int)$user['member_id']]);
        if ($m = $st->fetch()) {
            $entryYear = $m['entry_year'] === null ? null : (int)$m['entry_year'];
            $grade = grade_of($entryYear);
            $active = !$m['retired'] && $grade !== null && $grade <= 4;
        }
    }
    return $cache[$uid] = ['entry_year' => $entryYear, 'active' => $active];
}

/**
 * 見られるスレだけに絞る WHERE 句（テーブルの別名は t）と、その ? に入れる値。
 * 管理者は削除されたスレも含めてすべて。
 */
function board_visible_sql(array $user): array
{
    if (!empty($user['admin'])) {
        return ['1 = 1', []];
    }
    $s = board_scope($user);
    return [
        "t.deleted_at IS NULL AND (t.kind = 'free' OR (t.kind = 'active' AND ? = 1) OR (t.kind = 'cohort' AND t.entry_year = ?))",
        [$s['active'] ? 1 : 0, $s['entry_year'] ?? 0],
    ];
}

/** そのスレを読み書きできるか */
function board_can_view(array $user, array $thread): bool
{
    if (!empty($user['admin'])) {
        return true;
    }
    if ($thread['deleted_at'] !== null) {
        return false;
    }
    $s = board_scope($user);
    return match ($thread['kind']) {
        'free'   => true,
        'active' => $s['active'],
        'cohort' => $s['entry_year'] !== null && (int)$thread['entry_year'] === $s['entry_year'],
        default  => false,
    };
}

/**
 * 現役用スレと、メンバーにいる入部年度ぶんの同期スレを用意する（無ければ作る）。
 * fixed_key が UNIQUE なので、何回呼んでも1本ずつにしかならない。
 */
function board_ensure_fixed(): void
{
    $pdo = db();
    $pdo->exec("INSERT IGNORE INTO board_thread (kind, fixed_key, title) VALUES ('active', 'active', '現役')");
    $pdo->exec("INSERT IGNORE INTO board_thread (kind, entry_year, fixed_key, title)
        SELECT DISTINCT 'cohort', entry_year, CONCAT('cohort:', entry_year), CONCAT(entry_year, '年度入部')
          FROM member WHERE entry_year IS NOT NULL");
}

/**
 * 通知設定。['cohort_active' => bool, 'categories' => int[] | null]
 * categories が null = 設定を保存していない人（すべてのカテゴリを通知）
 */
function board_notify_pref(int $userId): array
{
    $st = db()->prepare('SELECT cohort_active, participating FROM board_notify WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        return ['cohort_active' => true, 'participating' => true, 'categories' => null];
    }
    $st = db()->prepare('SELECT category_id FROM board_notify_category WHERE user_id = ?');
    $st->execute([$userId]);
    return ['cohort_active' => (bool)$row['cohort_active'], 'participating' => (bool)$row['participating'], 'categories' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
}

/**
 * 通知する（ヘッダーのバッジに数える）スレの数。通知設定で決まる:
 *   ・現役 / 自分の同期スレ … 未読の書き込みがあるとき（cohort_active が ON のとき）
 *   ・自由スレ             … 選んだカテゴリの「まだ開いていない新しいスレ」だけ（書き込みが増えても数えない）
 * 一覧の NEW タグは通知設定に関係なく、これまで通り全スレの未読に付く。
 * 管理者も数えるのは自分の現役 / 同期スレだけ（他の年度のスレは数えない）。
 */
function board_unread_count(array $user): int
{
    [$where, $params] = board_visible_sql($user);
    $uid = (int)$user['user_id'];
    $s = board_scope($user);
    $pref = board_notify_pref($uid);

    $catSql = '1 = 1';
    $catParams = [];
    if ($pref['categories'] !== null) {
        if (!$pref['categories']) {
            $catSql = '1 = 0';
        } else {
            $catSql = 't.category_id IN (' . implode(',', array_fill(0, count($pref['categories']), '?')) . ')';
            $catParams = $pref['categories'];
        }
    }
    // ミュートしたスレは、下のどの条件でも数えない。次の2つは通知設定に関係なく数える:
    //   ・自分の書き込みへの返信（>>N）の、未読のもの（常に）
    //   ・48時間以内に自分が書き込んだスレの、未読の書き込み（participating が ON のとき。通知設定で変えられる）
    $st = db()->prepare("SELECT COUNT(*) FROM board_thread t
        LEFT JOIN board_read r ON r.thread_id = t.thread_id AND r.user_id = ?
        LEFT JOIN board_thread_mute m ON m.thread_id = t.thread_id AND m.user_id = ?
        WHERE t.deleted_at IS NULL AND m.thread_id IS NULL AND $where AND (
            (t.kind = 'active' AND ? = 1 AND t.last_post_id > COALESCE(r.last_post_id, 0))
            OR (t.kind = 'cohort' AND ? = 1 AND t.entry_year = ? AND t.last_post_id > COALESCE(r.last_post_id, 0))
            OR (t.kind = 'free' AND r.thread_id IS NULL AND t.last_post_id > 0 AND $catSql)
            OR (? = 1 AND t.last_post_id > COALESCE(r.last_post_id, 0) AND EXISTS (
                SELECT 1 FROM board_post mp WHERE mp.thread_id = t.thread_id AND mp.user_id = ?
                    AND mp.deleted_at IS NULL AND mp.created_at >= NOW() - INTERVAL 48 HOUR))
            OR EXISTS (
                SELECT 1 FROM board_post_reply rr
                JOIN board_post mp ON mp.post_id = rr.reply_to_post_id
                JOIN board_post np ON np.post_id = rr.post_id
                WHERE mp.thread_id = t.thread_id AND mp.user_id = ? AND np.deleted_at IS NULL
                    AND np.user_id <> ? AND np.post_id > COALESCE(r.last_post_id, 0))
        )");
    $on = $pref['cohort_active'];
    $st->execute([$uid, $uid, ...$params, ($on && $s['active']) ? 1 : 0, $on ? 1 : 0, $s['entry_year'] ?? 0, ...$catParams, $pref['participating'] ? 1 : 0, $uid, $uid, $uid]);
    return (int)$st->fetchColumn();
}

/** ここまで読んだ、を記録する（戻ることはしない） */
function board_mark_read(int $userId, int $threadId, int $postId): void
{
    db()->prepare('INSERT INTO board_read (user_id, thread_id, last_post_id) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE last_post_id = GREATEST(last_post_id, VALUES(last_post_id))')
        ->execute([$userId, $threadId, $postId]);
}

/** 書き込む。スレの最終書き込みを更新し、自分の書き込みは既読にする。新しい post_id を返す */
function board_add_post(int $threadId, int $userId, string $body): int
{
    $pdo = db();
    $pdo->prepare('INSERT INTO board_post (thread_id, user_id, body) VALUES (?, ?, ?)')->execute([$threadId, $userId, $body]);
    $postId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE board_thread SET last_post_id = ?, last_posted_at = NOW() WHERE thread_id = ?')->execute([$postId, $threadId]);
    board_mark_read($userId, $threadId, $postId);
    return $postId;
}
