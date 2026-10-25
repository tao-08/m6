<?php
/**
 * =====================================================================
 *  lib/push.php — プッシュ通知（Web Push）で、お知らせを端末に飛ばす
 * =====================================================================
 *  仕組み
 *    1. 端末（ブラウザ）が通知の受け取りを許可すると、送り先の URL（endpoint）と暗号用の鍵が手に入る
 *       → api_push_subscribe.php が push_subscription に保存する（assets/app.js の setupPush）
 *    2. 重要なお知らせが公開されたら、保存した端末すべてに送る（push_dispatch_due）
 *    3. 端末の sw.js が受け取って、OS の通知として出す。押すとお知らせのページが開く
 *
 *  送るタイミング
 *    cron は使えない（予約投稿も公開日時が来たら送りたい）ので、ページが表示されたあとに
 *    「公開済みで未処理のお知らせ」を探して送る（partials/header.php から register_shutdown_function）。
 *    ・応答を返し終わってから送るので、ページの表示は遅くならない
 *    ・notice.pushed_at を UPDATE で先に取った 1 リクエストだけが送る（同時に開かれても二重に飛ばない）
 *
 *  対象（受け取る種類は、人ごとに account.php の「アプリ通知」で ON / OFF。user_account.push_◯◯）
 *    ・重要なお知らせ（push_important。初期 ON）/ その他のお知らせ（push_notice。初期 ON）
 *      宛先つき（target_user_id）なら、その人の端末だけ。
 *    ・掲示板（push_board。初期 OFF）… 書き込んだときに board_push_post()。アプリ内の通知設定と同じ条件
 *
 *  準備（本番）
 *    ・ライブラリ minishlink/web-push（composer で vendor/ に入れる。composer.json）
 *    ・config.php の 'push' に VAPID 鍵（public_key / private_key / subject）
 *    どちらかが無いときは push_enabled() が false になり、何もしない（サイトは今まで通り動く）。
 * =====================================================================
 */
declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** 通知の送り先として受け付けるサーバー（ドット始まりは、そのドメイン配下すべて） */
const PUSH_ENDPOINT_HOSTS = [
    'fcm.googleapis.com',                  // Chrome / Android
    'updates.push.services.mozilla.com',   // Firefox
    'web.push.apple.com',                  // Safari / iOS
    '.push.apple.com',
    '.notify.windows.com',                 // Edge
];

function push_config(): array
{
    $c = config('push');
    return is_array($c) ? $c : [];
}

/** 公開鍵（ブラウザに渡す。秘密ではない） */
function push_public_key(): string
{
    return (string)(push_config()['public_key'] ?? '');
}

/** ライブラリと鍵がそろっているか。そろっていなければ、プッシュ関係は全部休む */
function push_enabled(): bool
{
    $c = push_config();
    return is_file(__DIR__ . '/../vendor/autoload.php')
        && ($c['public_key'] ?? '') !== '' && ($c['private_key'] ?? '') !== '' && ($c['subject'] ?? '') !== '';
}

/**
 * 通知の送り先 URL として安全か。
 * ブラウザから送られてきた URL にサーバーが POST するので、何でも許すと
 * 「内部のサーバーや他人のサイトに勝手に通信させる」攻撃（SSRF）に使われる。
 * https で、ブラウザ各社の通知サーバーだけ許す。
 */
function push_endpoint_allowed(string $endpoint): bool
{
    if ($endpoint === '' || strlen($endpoint) > 500) {
        return false;
    }
    $p = parse_url($endpoint);
    if ($p === false || ($p['scheme'] ?? '') !== 'https' || !isset($p['host'])
        || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
        return false;
    }
    $host = strtolower($p['host']);
    foreach (PUSH_ENDPOINT_HOSTS as $allowed) {
        if ($allowed[0] === '.' ? str_ends_with($host, $allowed) : $host === $allowed) {
            return true;
        }
    }
    return false;
}

/**
 * 端末のリストに通知を送る。届かなくなった端末（アンインストール・許可の取り消し）は削除する。
 *
 * @param array<int, array{subscription_id:int|string, endpoint:string, p256dh:string, auth:string}> $subs
 * @param array{title:string, body:string, url:string, tag:string} $payload
 */
function push_send(array $subs, array $payload): void
{
    if ($subs === [] || !push_enabled()) {
        return;
    }
    require_once __DIR__ . '/../vendor/autoload.php';
    $c = push_config();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    try {
        $webPush = new WebPush(['VAPID' => [
            'subject'    => (string)$c['subject'],
            'publicKey'  => (string)$c['public_key'],
            'privateKey' => (string)$c['private_key'],
        ]], [], 10);
        $byEndpoint = [];
        foreach ($subs as $s) {
            $byEndpoint[$s['endpoint']] = (int)$s['subscription_id'];
            $webPush->queueNotification(
                Subscription::create(['endpoint' => $s['endpoint'], 'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']]]),
                $json,
                ['TTL' => 86400 * 3, 'urgency' => 'normal'] // 3日以内に端末がオンラインにならなければ諦める
            );
        }
        $gone = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired() && isset($byEndpoint[$report->getEndpoint()])) {
                $gone[] = $byEndpoint[$report->getEndpoint()]; // 404 / 410: この端末にはもう送れない
            }
        }
        if ($gone !== []) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            db()->prepare("DELETE FROM push_subscription WHERE subscription_id IN ({$in})")->execute($gone);
        }
    } catch (Throwable $e) {
        error_log('push_send: ' . $e->getMessage()); // 通知が飛ばなくても、サイトは止めない
    }
}

/** 公開済みで、まだ通知を処理していないお知らせを送る（重要 / その他は、受け取る人の設定で分ける） */
function push_dispatch_due(): void
{
    if (!push_enabled()) {
        return;
    }
    $pdo = db();
    $rows = $pdo->query(
        'SELECT n.notice_id, n.target_user_id, n.is_important, n.title, n.body, n.link_url, c.name AS category_name
           FROM notice n LEFT JOIN notice_category c ON c.category_id = n.category_id
          WHERE n.pushed_at IS NULL AND n.publish_at <= NOW() ORDER BY n.notice_id LIMIT 20'
    )->fetchAll();
    foreach ($rows as $n) {
        // 先に「処理済み」にした 1 リクエストだけが送る。0 行なら、ほかのリクエストが取った
        $claim = $pdo->prepare('UPDATE notice SET pushed_at = NOW() WHERE notice_id = ? AND pushed_at IS NULL');
        $claim->execute([(int)$n['notice_id']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $subs = push_subscriptions(
            $n['is_important'] ? 'push_important' : 'push_notice',
            $n['target_user_id'] !== null ? [(int)$n['target_user_id']] : null
        );
        push_send($subs, [
            'title' => (string)($n['category_name'] ?? 'お知らせ'), // タイトルはカテゴリ名
            'body'  => push_excerpt((string)$n['title']), // 本文はお知らせの題だけ
            'url'   => $n['link_url'] ?? 'notices',
            'tag'   => 'notice-' . (int)$n['notice_id'],
        ]);
    }
}

/** 通知に出す本文の抜粋（改行・連続する空白をつぶして、長ければ切る） */
function push_excerpt(string $text, int $width = 120): string
{
    return mb_strimwidth(trim((string)preg_replace('/\s+/u', ' ', $text)), 0, $width, '…');
}

/**
 * 通知を受け取る端末。$pref は受け取りの設定（user_account の列）で、これが ON の人の端末だけ返す。
 *   push_board … 掲示板 / push_important … 重要なお知らせ / push_notice … その他のお知らせ
 * $userIds を渡すと、その人たちの端末だけに絞る（空配列なら誰にも送らない）。
 */
function push_subscriptions(string $pref, ?array $userIds = null): array
{
    if (!in_array($pref, ['push_board', 'push_important', 'push_notice'], true)) {
        throw new InvalidArgumentException('不明な通知の種類: ' . $pref); // 列名を SQL に入れるので、決まったものだけ通す
    }
    $sql = "SELECT s.subscription_id, s.endpoint, s.p256dh, s.auth FROM push_subscription s
              JOIN user_account u ON u.user_id = s.user_id WHERE u.{$pref} = 1";
    $args = [];
    if ($userIds !== null) {
        if ($userIds === []) {
            return [];
        }
        $sql .= ' AND s.user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
        $args = array_values($userIds);
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * ページの応答を返し終わってから、$job を走らせる予約をする（何本でも予約できる）。
 * 応答を先に閉じ（fastcgi_finish_request）、セッションの鍵も手放すので、送信中にこの人の次の操作が待たされない。
 */
function push_defer(callable $job): void
{
    static $jobs = [];
    $jobs[] = $job;
    if (count($jobs) > 1) {
        return; // 最初の 1 回だけ shutdown 関数を登録する。あとは $jobs に溜まるのをまとめて処理
    }
    register_shutdown_function(static function () use (&$jobs): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        // 実行中に増えた予約も拾う
        while (($next = array_shift($jobs)) !== null) {
            try {
                $next();
            } catch (Throwable $e) {
                error_log('push_defer: ' . $e->getMessage());
            }
        }
    });
}

/** ページを返し終わってから、公開済みのお知らせの通知を送る（partials/header.php から） */
function push_dispatch_after_response(): void
{
    static $registered = false;
    if ($registered || !push_enabled()) {
        return;
    }
    $registered = true;
    push_defer('push_dispatch_due');
}

/** 掲示板に書き込んだあと、ページを返し終わってから通知（お知らせ＋アプリ通知）を出す（board.php・board_thread.php から） */
function board_push_post(int $threadId, int $postId): void
{
    push_defer(static fn() => board_push_send($threadId, $postId)); // アプリ通知が使えなくても、お知らせは作る
}

/**
 * 掲示板の書き込みを、通知する。誰に出すかは、アプリ内の通知（ヘッダーのバッジ。board_unread_count）と同じ条件:
 *   ・自分の書き込みへの返信（>>N）                 … いつも
 *   ・48時間以内に自分が書き込んだスレの新着        … 通知設定の participating が ON のとき
 *   ・現役 / 自分の同期スレの新着                    … 通知設定の cohort_active が ON のとき
 *   ・自由スレの「新しく立ったスレ」                 … 通知設定で選んだカテゴリ（無設定なら全部）
 * ミュートしたスレ・読めないスレ・書いた本人には出さない。
 * 出し方は2つ:
 *   ・お知らせ（カテゴリ「掲示板」の個人宛て。board_notice_add）… 全員
 *   ・アプリ通知 … 掲示板のアプリ通知（push_board）が ON で端末がある人だけ
 */
function board_push_send(int $threadId, int $postId): void
{
    require_once __DIR__ . '/board.php';
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM board_thread WHERE thread_id = ? AND deleted_at IS NULL');
    $st->execute([$threadId]);
    $thread = $st->fetch();
    $st = $pdo->prepare('SELECT p.user_id, p.body, p.anonymous, p.display_name, u.name, u.member_id FROM board_post p LEFT JOIN user_account u ON u.user_id = p.user_id
        WHERE p.post_id = ? AND p.thread_id = ? AND p.deleted_at IS NULL');
    $st->execute([$postId, $threadId]);
    $post = $st->fetch();
    if (!$thread || !$post) {
        return;
    }
    $authorId = (int)($post['user_id'] ?? 0);
    $st = $pdo->prepare('SELECT MIN(post_id) FROM board_post WHERE thread_id = ?');
    $st->execute([$threadId]);
    $isNewThread = (int)$st->fetchColumn() === $postId;

    // 48時間以内に書き込んだ人 / この書き込みの返信先の持ち主
    $st = $pdo->prepare('SELECT DISTINCT user_id FROM board_post WHERE thread_id = ? AND post_id < ? AND user_id IS NOT NULL
        AND deleted_at IS NULL AND created_at >= NOW() - INTERVAL 48 HOUR');
    $st->execute([$threadId, $postId]);
    $participants = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $st = $pdo->prepare('SELECT DISTINCT mp.user_id FROM board_post_reply rr JOIN board_post mp ON mp.post_id = rr.reply_to_post_id
        WHERE rr.post_id = ? AND mp.user_id IS NOT NULL');
    $st->execute([$postId]);
    $replied = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    // 候補: 書いた本人以外で、このスレをミュートしていない人（アプリ通知の ON / 端末は、送るときに push_subscriptions が絞る）
    $st = $pdo->prepare('SELECT u.user_id, u.member_id, u.is_admin FROM user_account u
        WHERE u.user_id <> ?
          AND NOT EXISTS (SELECT 1 FROM board_thread_mute m WHERE m.thread_id = ? AND m.user_id = u.user_id)');
    $st->execute([$authorId, $threadId]);
    $to = [];
    foreach ($st->fetchAll() as $c) {
        $uid = (int)$c['user_id'];
        $u = ['user_id' => $uid, 'member_id' => $c['member_id'], 'admin' => (bool)$c['is_admin']];
        if (!board_can_view($u, $thread)) {
            continue;
        }
        $pref = board_notify_pref($uid);
        $scope = board_scope($u);
        $send = isset($replied[$uid]) || ($pref['participating'] && isset($participants[$uid]));
        if (!$send && $thread['kind'] === 'active') {
            $send = $pref['cohort_active'] && $scope['active'];
        } elseif (!$send && $thread['kind'] === 'cohort') {
            $send = $pref['cohort_active'] && $scope['entry_year'] !== null && $scope['entry_year'] === (int)$thread['entry_year'];
        } elseif (!$send && $thread['kind'] === 'free' && $isNewThread) {
            $send = $pref['categories'] === null || in_array((int)$thread['category_id'], $pref['categories'], true);
        }
        if ($send) {
            $to[] = $uid;
        }
    }
    $alias = $post['anonymous'] ? '匿名' : ($post['display_name'] !== null && $post['display_name'] !== '' ? (string)$post['display_name'] : null);
    $name = $alias ?? (string)($post['name'] ?? '');
    board_notice_add($thread, $postId, $authorId, $post['member_id'] === null ? null : (int)$post['member_id'], $name, (string)$post['body'], $to, array_keys($replied), $alias);
    if (!push_enabled()) {
        return;
    }
    push_send(push_subscriptions('push_board', $to), [
        'title' => '掲示板', // タイトルはカテゴリ名。スレ名は本文の頭に出す
        'body'  => $thread['title'] . ' / ' . ($name !== '' ? $name . ': ' : '') . ((string)$post['body'] === '' ? '画像' : push_excerpt((string)$post['body'], 100)),
        'url'   => 'board_thread?id=' . $threadId . '&page=' . board_post_page($threadId, $postId) . '#p' . $postId,
        'tag'   => 'board-' . $threadId, // 同じスレの通知は 1 つにまとまる
    ]);
}
