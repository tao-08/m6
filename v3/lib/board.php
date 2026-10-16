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
    $a = $s['active'] ? 1 : 0;
    $y = $s['entry_year'] ?? 0;
    // 自由スレは、立てた人本人か、現役のみ・同期のみ・入部年度の指定（board_thread_year。行なし = 制限なし）をすべて満たす人
    return [
        "t.deleted_at IS NULL AND ((t.kind = 'free' AND (t.user_id = ? OR ((t.only_active = 0 OR ? = 1) AND (t.cohort_year IS NULL OR t.cohort_year = ?)
            AND (NOT EXISTS (SELECT 1 FROM board_thread_year y WHERE y.thread_id = t.thread_id)
                OR EXISTS (SELECT 1 FROM board_thread_year y WHERE y.thread_id = t.thread_id AND y.entry_year = ?)))))
            OR (t.kind = 'active' AND ? = 1) OR (t.kind = 'cohort' AND t.entry_year = ?))",
        [(int)$user['user_id'], $a, $y, $y, $a, $y],
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
        'free'   => board_can_view_free($user, $thread, $s),
        'active' => $s['active'],
        'cohort' => $s['entry_year'] !== null && (int)$thread['entry_year'] === $s['entry_year'],
        default  => false,
    };
}

/** 自由スレの参加制限（board_visible_sql と同じ決まり。制限は $thread ではなく DB から読む） */
function board_can_view_free(array $user, array $thread, array $scope): bool
{
    static $cache = [];
    $id = (int)$thread['thread_id'];
    if (!isset($cache[$id])) {
        $st = db()->prepare('SELECT user_id, only_active, cohort_year FROM board_thread WHERE thread_id = ?');
        $st->execute([$id]);
        $t = $st->fetch() ?: ['user_id' => null, 'only_active' => 0, 'cohort_year' => null];
        $st = db()->prepare('SELECT entry_year FROM board_thread_year WHERE thread_id = ?');
        $st->execute([$id]);
        $cache[$id] = $t + ['years' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
    }
    $t = $cache[$id];
    if ($t['user_id'] !== null && (int)$t['user_id'] === (int)$user['user_id']) {
        return true;
    }
    $y = $scope['entry_year'];
    return (!$t['only_active'] || $scope['active'])
        && ($t['cohort_year'] === null || $y === (int)$t['cohort_year'])
        && (!$t['years'] || ($y !== null && in_array($y, $t['years'], true)));
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

const BOARD_PER_PAGE = 100; // スレの中身は、書き込み100件ごとにページを分ける

/** その書き込みが、スレの何ページ目にあるか（削除された書き込みも番号に数える） */
function board_post_page(int $threadId, int $postId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM board_post WHERE thread_id = ? AND post_id <= ?');
    $st->execute([$threadId, $postId]);
    return max(1, (int)ceil((int)$st->fetchColumn() / BOARD_PER_PAGE));
}

/**
 * 掲示板の書き込みを、個人宛てのお知らせ（カテゴリ「掲示板」）にする。呼ぶのは lib/push.php の board_push_send()。
 *   $to … お知らせを出す user_id の一覧（誰に出すかは呼ぶ側で決める）/ $replied … そのうち、書き込みへ返信された人
 *   ・自分への返信は毎回出す。それ以外は、同じスレの未読のお知らせが残っている人には足さない（現役・同期スレで山にならないように）
 *   ・アプリ通知は board_push_send() が別に送るので、ここでは pushed_at を入れて、お知らせ側の通知（push_dispatch_due）で二重に飛ばない
 *   ・スレ名・本文は通知のときの文字を保存する（あとで消しても残る。表示側で h() する）
 */
function board_notice_add(array $thread, int $postId, int $authorId, ?int $authorMemberId, string $authorName, string $body, array $to, array $replied): void
{
    if (!$to) {
        return;
    }
    $pdo = db();
    $threadId = (int)$thread['thread_id'];
    $cat = board_notice_category();

    $in = implode(',', array_fill(0, count($to), '?'));
    $st = $pdo->prepare("SELECT DISTINCT n.target_user_id FROM notice n
        LEFT JOIN notice_read r ON r.notice_id = n.notice_id AND r.user_id = n.target_user_id
        WHERE n.category_id = ? AND r.user_id IS NULL AND n.link_url LIKE ? AND n.target_user_id IN ($in)");
    $st->execute([$cat, 'board_thread?id=' . $threadId . '&%', ...$to]);
    $pending = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    $link = 'board_thread?id=' . $threadId . '&page=' . board_post_page($threadId, $postId) . '#p' . $postId;
    $who = $authorMemberId !== null ? '[[member?id=' . $authorMemberId . '|' . $authorName . ']]' : ($authorName !== '' ? $authorName : '退会ユーザー');
    $excerpt = "\n\n" . mb_substr($body, 0, 200) . (mb_strlen($body) > 200 ? '…' : '');
    $threadName = mb_substr((string)$thread['title'], 0, 70);
    $head = '[[board_thread?id=' . $threadId . '|' . $thread['title'] . ']] に' . $who;
    $ins = $pdo->prepare('INSERT INTO notice (user_id, target_user_id, category_id, is_important, title, body, link_url, pushed_at)
        VALUES (?, ?, ?, 0, ?, ?, ?, NOW())');
    foreach ($to as $uid) {
        $isReply = in_array($uid, $replied, true);
        if (!$isReply && isset($pending[$uid])) {
            continue;
        }
        $ins->execute([
            $authorId > 0 ? $authorId : null, $uid, $cat,
            $threadName . ($isReply ? ' に返信がありました' : ' に書き込みがありました'),
            $head . ($isReply ? 'が返信しました' : 'が書き込みました') . $excerpt,
            $link,
        ]);
    }
}

/** お知らせのカテゴリ「掲示板」の ID（名前で探す。無ければ作る。NOTICE_SYSTEM_CATEGORIES なので画面からは変えられない） */
function board_notice_category(): int
{
    $pdo = db();
    $pdo->exec("INSERT IGNORE INTO notice_category (name, color) VALUES ('掲示板', 'purple')");
    return (int)$pdo->query("SELECT category_id FROM notice_category WHERE name = '掲示板'")->fetchColumn();
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
