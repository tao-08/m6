<?php
/**
 * =====================================================================
 *  sched_google.php — Google カレンダー連携の入口と、Google から戻ってくる URL（リダイレクト URI）
 * =====================================================================
 *  POST（CSRF チェック）
 *    sched_google（マイスケジュールの「Google で連携」「再連携」）… state と PKCE の verifier をセッションに入れて、Google の許可画面へ移動する。poll = 連携後に戻る日程調整（任意）
 *  GET（Google が戻してくる）
 *    sched_google?code=…&state=…  … 許可された。state を確かめ、code をトークンに交換して保存し、予定を読み込む
 *    sched_google?error=access_denied … 許可されなかった
 *  許可されたら sched_google_pick（取り込むカレンダーを選ぶ画面）へ。キャンセル・失敗は sched_me#ical に戻る。
 *
 *  ■ 守っていること（仕組みは lib/sched_google.php の冒頭）
 *    ・開始は POST + CSRF（ほかのサイトから勝手に連携を始めさせない）
 *    ・戻ってきたら、セッションの state と hash_equals（タイミング攻撃を避ける比較）で照合。使い回せないよう、照合の前に必ずセッションから消す
 *    ・Google から受け取った値（code・state・error）は、画面に出さない（XSS を避ける。出すのは、こちらで決めた言葉だけ）
 *    ・自分の部員にだけ連携できる（member_id はセッションのもの）
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

/** 連携のあとに戻る URL（日程調整から来ていれば、その日程調整のマイスケジュール） */
$backTo = static fn(int $pollId): string => 'sched_me' . ($pollId > 0 ? '?poll=' . $pollId : '') . '#ical';

if (!sched_google_available()) {
    flash('Google 連携は現在使えません', 'error');
    redirect($backTo(0));
}

/* ==================== 開始 ==================== */
if (is_post()) {
    verify_csrf();
    $pollId = (int)($_POST['poll'] ?? 0);
    $state = bin2hex(random_bytes(16)); // 推測できない乱数（32文字）
    $verifier = sched_google_new_verifier();
    // 戻ってきたときに照合するため、セッションに預ける（Google には state と、verifier のハッシュだけを渡す）
    $_SESSION['sched_google'] = ['state' => $state, 'verifier' => $verifier, 'poll' => $pollId, 'at' => time()];
    redirect(sched_google_auth_url($state, $verifier));
}

/* ==================== 戻り（Google → このページ）==================== */
$saved = $_SESSION['sched_google'] ?? null;
unset($_SESSION['sched_google']); // 1回しか使えないようにする（成功でも失敗でも、ここで消す）
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    redirect($backTo(0)); // 直接開かれただけ
}
$pollId = is_array($saved) ? (int)($saved['poll'] ?? 0) : 0;
$back = $backTo($pollId);

// state の照合。無い・古い（10分より前）・違う、のどれかなら、自分が始めた連携ではないので断る
$given = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if (!is_array($saved) || !is_string($saved['state'] ?? null) || (int)($saved['at'] ?? 0) < time() - 600 || $given === '' || !hash_equals($saved['state'], $given)) {
    flash('連携できませんでした。もう一度試してください', 'error');
    redirect($backTo(0));
}
if (isset($_GET['error'])) {
    // ユーザーが「許可しない」を押した（access_denied）など。値は画面に出さず、こちらの言葉だけ出す
    flash($_GET['error'] === 'access_denied' ? '連携をキャンセルしました' : '連携できませんでした。もう一度試してください', $_GET['error'] === 'access_denied' ? 'success' : 'error');
    redirect($back);
}
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$tok = $code === '' || strlen($code) > 2048 ? null : sched_google_exchange($code, (string)$saved['verifier']);
if ($tok === null) {
    flash('連携できませんでした。もう一度試してください', 'error');
    redirect($back);
}
if (!sched_google_scopes_ok((string)($tok['scope'] ?? ''))) {
    // 許可の画面で、チェックを外された
    flash('すべての項目を許可してください', 'error');
    redirect($back);
}
if (empty($tok['refresh_token'])) {
    // prompt=consent を付けているので、まず返ってくる。無いのは Google 側の異常
    error_log('sched_google: refresh_token が返ってきません member=' . $me);
    flash('連携できませんでした。もう一度試してください', 'error');
    redirect($back);
}

// 保存（暗号化）。再連携なら上書き。メールアドレス・読んだ日時・エラーは、このあと入れ直す
$pdo->prepare('INSERT INTO sched_google (member_id, refresh_token, access_token, access_expires, email, fetched_at, error) VALUES (?, ?, ?, ?, NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE refresh_token = VALUES(refresh_token), access_token = VALUES(access_token), access_expires = VALUES(access_expires), email = NULL, fetched_at = NULL, error = NULL')
    ->execute([$me, sched_ical_encrypt((string)$tok['refresh_token']), sched_ical_encrypt((string)$tok['access_token']),
        date('Y-m-d H:i:s', time() + max(60, min(3600, (int)($tok['expires_in'] ?? 3600))))]);

// 連携先のメールアドレス = メインのカレンダー（primary）の id（userinfo は取らないので、権限を増やさずに済む）
try {
    foreach (sched_google_calendars((string)$tok['access_token']) as $c) {
        if ($c['primary']) {
            $pdo->prepare('UPDATE sched_google SET email = ? WHERE member_id = ?')->execute([mb_substr($c['id'], 0, 255), $me]);
            break;
        }
    }
} catch (RuntimeException $e) {
    // 表示用なので、取れなくても連携は続ける（読み込みの失敗は、次の行で画面に出る）
}
// 取り込むカレンダーを選んでもらう（予定の読み込みは、選んだあと）
redirect('sched_google_pick' . ($pollId > 0 ? '?poll=' . $pollId : ''));
