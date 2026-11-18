<?php
/**
 * =====================================================================
 *  sched_feed.php?m=部員&t=鍵 — 決定した練習の「購読するカレンダー」（webcal）
 * =====================================================================
 *  マイスケジュール（sched_me）の「決定した練習を追加」から、Google カレンダー・iPhone に一度登録すると、
 *  カレンダーアプリが自分で定期的にこの URL を読みに来て、その部員が入っている決定済みの練習が自動で入る（変更・取り消しも反映）。
 *
 *  ■ ログイン不要（カレンダーアプリはログインできない）。代わりに URL の「鍵」が合言葉になる
 *    鍵 = lib/sched_ical.php の sched_feed_token（部員 id と版 + 秘密から計算。DB には保存しない）
 *    鍵が違う・部員がいない・機能が休み中のときは、どれも同じ 404 を返す（「その部員は存在する」とすら教えない）
 *  ■ セッションを始めない（APP_NO_SESSION）… 読みに来るのは Cookie を持たないアプリ。毎回セッションを作ってもむだ
 *  ■ 中身は読むだけ（DB を変えない）。SQL は全部プリペアドステートメント
 * =====================================================================
 */
declare(strict_types=1);
define('APP_NO_SESSION', true); // bootstrap.php より前に書く（セッションを始めない）
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/sched.php';
require_once __DIR__ . '/lib/sched_ical.php';

/** 404 を返して終わる（理由は教えない。ErrorDocument の画面も出さず、中身は空） */
$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit;
};

// $_GET['m'] が配列（?m[]=1）のこともある。文字列のときだけ受け付ける
$memberId = is_string($_GET['m'] ?? null) && ctype_digit($_GET['m']) ? (int)$_GET['m'] : 0;
$given = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
if ($memberId <= 0 || $given === '') {
    $notFound();
}

// 版は DB から（行が無い部員は 0）。再発行すると +1 されて、古い URL の鍵は合わなくなる
$pdo = db();
$st = $pdo->prepare('SELECT feed_version FROM sched_profile WHERE member_id = ?');
$st->execute([$memberId]);
$version = (int)($st->fetchColumn() ?: 0);

// hash_equals … 文字列を「時間差で当てられない」やり方で比べる（== だと、先頭が合っているほど早く終わる差から鍵を推測される恐れがある）
$expected = sched_feed_token($memberId, $version);
if ($expected === null || !hash_equals($expected, $given)) {
    $notFound();
}

// 60 日前より後に始まった練習から先、全部。過去を少し残すのは、直近の練習をあとで見返せるように
$events = sched_feed_events($pdo, $memberId, date('Y-m-d H:i:s', time() - 60 * 86400));

header('Content-Type: text/calendar; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: private, max-age=300'); // 5分は同じ中身を使い回してよい（アプリが短い間隔で何度も読みに来ても負担にならない）
echo sched_ics_calendar($events, 'スタジオ練習');
