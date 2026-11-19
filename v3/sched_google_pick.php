<?php
/**
 * =====================================================================
 *  sched_google_pick.php — Google 連携：予定を取り込むカレンダーを選ぶ
 * =====================================================================
 *  GET   … Google のカレンダーの一覧（calendarlist.readonly）をチェックボックスで出す。連携の直後と、マイスケジュールの「カレンダーを選ぶ」から来る
 *           最初は、すでに選んだものにチェック。まだ選んでいなければ、Google で表示中のものにチェック
 *  POST  … cal[] = 選んだカレンダーの id（CSRF チェック）。Google の一覧にあるものだけを保存し、予定を読み直す。1つは選ぶ必要がある
 *  poll  … 終わったあとに戻る日程調整（任意）
 *  カレンダーの id と名前は Google から来た値なので、画面に出すときは h() を通す。保存する id は、Google の一覧にあるものだけ。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/sched.php';
require_once __DIR__ . '/lib/sched_ical.php';
require_once __DIR__ . '/lib/sched_google.php';
$user = require_login(); // ログインしていれば誰でも
if ($user['member_id'] === null) {
    error_page(403, 'アカウントが部員に紐づいていません');
}
$me = (int)$user['member_id'];
$pdo = db();
$pollId = (int)($_REQUEST['poll'] ?? 0);
$back = 'sched_me' . ($pollId > 0 ? '?poll=' . $pollId : '') . '#ical';

if (!sched_google_available() || sched_google_row($pdo, $me) === null) {
    redirect($back);
}
$error = null;
$token = sched_google_access_token($pdo, $me, $error);
$cals = [];
if ($token !== null) {
    try {
        $cals = sched_google_selectable(sched_google_calendars($token));
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
if ($token === null || $error !== null || !$cals) {
    flash($error ?? 'カレンダーが見つかりません', 'error');
    redirect($back);
}

if (is_post()) {
    verify_csrf();
    $posted = is_array($_POST['cal'] ?? null) ? array_map('strval', $_POST['cal']) : [];
    $ids = array_values(array_map(static fn($c) => $c['id'], array_filter($cals, static fn($c) => in_array($c['id'], $posted, true))));
    if (!$ids) {
        flash('カレンダーを1つ以上選んでください', 'error');
        redirect('sched_google_pick' . ($pollId > 0 ? '?poll=' . $pollId : ''));
    }
    sched_google_chosen_save($pdo, $me, $ids);
    $err = sched_google_refresh_busy($pdo, $me, true);
    sched_google_sync_member($pdo, $me);
    flash($err === null ? 'Google カレンダーを連携しました' : '連携しましたが、読み込めませんでした: ' . $err, $err === null ? 'success' : 'error');
    redirect($back);
}

$chosen = sched_google_chosen($pdo, $me);
$checked = $chosen ?? array_map(static fn($c) => $c['id'], array_filter($cals, static fn($c) => $c['selected']));

render_header('取り込むカレンダー', 'tools');
?>
<section class="card">
    <h1><?= icon('event') ?> 取り込むカレンダー</h1>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="poll" value="<?= $pollId ?>">
        <ul>
            <?php foreach ($cals as $c): ?>
                <li><label class="check"><input type="checkbox" name="cal[]" value="<?= h($c['id']) ?>"<?= in_array($c['id'], $checked, true) ? ' checked' : '' ?>>
                    <?= h($c['name']) ?></label></li>
            <?php endforeach; ?>
        </ul>
        <div class="sch-row sch-row--wrap">
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('check') ?> 保存</button>
            <a class="btn btn--sm" href="<?= h($back) ?>">キャンセル</a>
        </div>
    </form>
</section>
<?php render_footer();
