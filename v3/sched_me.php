<?php
/**
 * =====================================================================
 *  sched_me.php — マイ予定（日程調整に使う「自分の埋まり」の入力）
 * =====================================================================
 *  GET
 *    sched_me                 … 今日から1週間の NG 枠（?from=2026-11-20 で週を移動）
 *    sched_me?poll=12         … その日程調整の期間・時間帯で NG 枠を出す（&week=1 で次の7日）
 *  POST（CSRF チェック）
 *    campus  … キャンパス（自動 / 和泉 / 駿河台）
 *    classes … 時間割（春・秋）。チェックの付いたコマを入れ直す
 *    copy    … もう片方の学期の時間割を写す
 *    ical / ical_refresh / ical_delete … カレンダー連携（iCal の URL）
 *    busy    … NG 枠（画面に出ている範囲の手入力分を入れ直す。JS から JSON で送る）
 *
 *  自分の予定だけを扱う（member_id はセッションのもの。フォームからは受け取らない＝他人の予定を書き換えられない）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // tip_attr（ポップアップの説明）
require_once __DIR__ . '/lib/sched.php';
require_once __DIR__ . '/lib/sched_ical.php';
$user = require_login();
$pdo = db();
if ($user['member_id'] === null) {
    render_header('マイ予定', 'tools');
    echo '<div class="empty card"><p class="empty__title">アカウントが部員に紐づいていません</p><p class="muted small">管理者に紐付けを頼んでください</p></div>';
    render_footer();
    exit;
}
$me = (int)$user['member_id'];
$settings = sched_settings($pdo);
$pollId = (int)($_REQUEST['poll'] ?? 0);
$poll = $pollId > 0 ? sched_poll_load($pdo, $pollId) : null;

/** プロフィールの行を必ず作る（INSERT IGNORE … 既にあれば何もしない） */
$ensureProfile = static fn() => $pdo->prepare('INSERT IGNORE INTO sched_profile (member_id) VALUES (?)')->execute([$me]);

/* ==================== 保存 ==================== */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'sched_me' . ($pollId > 0 ? '?poll=' . $pollId . (isset($_POST['week']) ? '&week=' . (int)$_POST['week'] : '') : (isset($_POST['from']) ? '?from=' . urlencode((string)$_POST['from']) : ''));

    if ($action === 'campus') {
        $c = (int)($_POST['campus'] ?? 0);
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET campus = ? WHERE member_id = ?')->execute([isset(SCHED_CAMPUSES[$c]) ? $c : null, $me]);
        flash('キャンパスを保存しました');
        redirect($back . '#campus');
    }

    if ($action === 'classes') {
        $term = (int)($_POST['term'] ?? 0);
        if (!isset(SCHED_TERMS[$term])) {
            redirect($back);
        }
        // "3-2"（水曜2限）の形だけ受け取る。それ以外は捨てる
        $cells = [];
        foreach ((array)($_POST['cls'] ?? []) as $v) {
            if (preg_match('/^([1-6])-([1-6])$/', (string)$v, $m)) {
                $cells[$v] = [(int)$m[1], (int)$m[2]];
            }
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM sched_class WHERE member_id = ? AND term = ?')->execute([$me, $term]);
        $ins = $pdo->prepare('INSERT INTO sched_class (member_id, term, weekday, period) VALUES (?, ?, ?, ?)');
        foreach ($cells as [$w, $p]) {
            $ins->execute([$me, $term, $w, $p]);
        }
        $pdo->commit();
        flash(SCHED_TERMS[$term] . 'の時間割を保存しました');
        redirect($back . '#classes');
    }

    if ($action === 'copy') {
        $to = (int)($_POST['term'] ?? 0);
        $from = $to === 1 ? 2 : 1;
        if (isset(SCHED_TERMS[$to])) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM sched_class WHERE member_id = ? AND term = ?')->execute([$me, $to]);
            // INSERT … SELECT … 別の行を読んで、そのまま新しい行として入れる（1回の SQL で写せる）
            $pdo->prepare('INSERT INTO sched_class (member_id, term, weekday, period) SELECT member_id, ?, weekday, period FROM sched_class WHERE member_id = ? AND term = ?')
                ->execute([$to, $me, $from]);
            $pdo->commit();
            flash(SCHED_TERMS[$from] . 'の時間割を写しました');
        }
        redirect($back . '#classes');
    }

    if ($action === 'ical') {
        if (!sched_ical_available()) {
            flash('カレンダー連携は現在使えません', 'error');
            redirect($back . '#ical');
        }
        $url = sched_ical_normalize((string)($_POST['url'] ?? ''));
        if ($url === null) {
            flash('Google カレンダーか iCloud の URL を貼ってください', 'error');
            redirect($back . '#ical');
        }
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET ical_url = ?, ical_fetched_at = NULL, ical_error = NULL WHERE member_id = ?')->execute([sched_ical_encrypt($url), $me]);
        $err = sched_ical_refresh($pdo, $me, true);
        flash($err === null ? 'カレンダーを連携しました' : '保存しましたが、読み込めませんでした: ' . $err, $err === null ? 'success' : 'error');
        redirect($back . '#ical');
    }

    if ($action === 'ical_refresh') {
        $err = sched_ical_refresh($pdo, $me, true);
        flash($err === null ? 'カレンダーを読み直しました' : $err, $err === null ? 'success' : 'error');
        redirect($back . '#ical');
    }

    if ($action === 'ical_delete') {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE sched_profile SET ical_url = NULL, ical_fetched_at = NULL, ical_error = NULL WHERE member_id = ?')->execute([$me]);
        $pdo->prepare('DELETE FROM sched_busy WHERE member_id = ? AND source = 1')->execute([$me]); // 取り込んだ予定も消す
        $pdo->commit();
        flash('カレンダー連携を解除しました');
        redirect($back . '#ical');
    }

    if ($action === 'busy') {
        // JS（assets/sched.js）が送る JSON: {"days": ["2026-11-20", …], "start": 540, "end": 1440, "cells": [絶対分, …]}
        $in = json_decode((string)($_POST['payload'] ?? ''), true);
        $days = array_values(array_filter((array)($in['days'] ?? []), static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
        $ws = (int)($in['start'] ?? 0);
        $we = (int)($in['end'] ?? 0);
        if (!$days || count($days) > 7 || $ws < 0 || $we > 1440 || $we <= $ws) {
            flash('保存できませんでした', 'error');
            redirect($back);
        }
        // 送られてきたマス（30分）→ 表示範囲の中のものだけ残して、続いているマスを1つの時間帯にまとめる
        $cells = [];
        foreach ((array)($in['cells'] ?? []) as $c) {
            $c = (int)$c;
            foreach ($days as $d) {
                $d0 = sched_day_abs($d);
                if ($c >= $d0 + $ws && $c + SCHED_STEP <= $d0 + $we && ($c - $d0) % SCHED_STEP === 0) {
                    $cells[] = [$c, $c + SCHED_STEP];
                }
            }
        }
        $spans = sched_merge_spans($cells);
        $pdo->beginTransaction();
        try {
            foreach ($days as $d) {
                $s = sched_day_abs($d) + $ws;
                $e = sched_day_abs($d) + $we;
                // 表示範囲にかかる手入力の行を読み、範囲の「外」にはみ出している部分だけ残す（別の時間帯で見たときの入力を消さないため）
                $st = $pdo->prepare('SELECT sched_busy_id, start_at, end_at FROM sched_busy WHERE member_id = ? AND source = 0 AND end_at > ? AND start_at < ?');
                $st->execute([$me, sched_abs_to_dt($s), sched_abs_to_dt($e)]);
                foreach ($st->fetchAll() as $r) {
                    $rs = sched_dt_to_abs($r['start_at']);
                    $re = sched_dt_to_abs($r['end_at']);
                    $pdo->prepare('DELETE FROM sched_busy WHERE sched_busy_id = ?')->execute([(int)$r['sched_busy_id']]);
                    if ($rs < $s) {
                        $spans[] = [$rs, $s];
                    }
                    if ($re > $e) {
                        $spans[] = [$e, $re];
                    }
                }
            }
            $ins = $pdo->prepare('INSERT INTO sched_busy (member_id, start_at, end_at, source) VALUES (?, ?, ?, 0)');
            foreach (sched_merge_spans($spans) as [$s, $e]) {
                $ins->execute([$me, sched_abs_to_dt($s), sched_abs_to_dt($e)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('NG の時間を保存しました');
        redirect($back . '#ng');
    }
    redirect($back);
}

/* ==================== 表示 ==================== */
$st = $pdo->prepare('SELECT m.name, m.entry_year, p.campus, p.ical_url, p.ical_fetched_at, p.ical_error FROM member m LEFT JOIN sched_profile p ON p.member_id = m.member_id WHERE m.member_id = ?');
$st->execute([$me]);
$prof = $st->fetch();
$autoCampus = sched_member_campus(null, $prof['entry_year'] === null ? null : (int)$prof['entry_year']);
$chosenCampus = $prof['campus'] === null ? 0 : (int)$prof['campus'];
$classes = [1 => [], 2 => []];
$st = $pdo->prepare('SELECT term, weekday, period FROM sched_class WHERE member_id = ?');
$st->execute([$me]);
foreach ($st as $r) {
    $classes[(int)$r['term']][$r['weekday'] . '-' . $r['period']] = true;
}
// いま（または次）の学期を先に開く
$today = date('Y-m-d');
//   授業期間の中ならその学期、夏休みなら秋、それ以外（冬〜春休み）は春（日曜・祝日でも学期は学期なので、sched_term_of は使わない）
$curTerm = ($today >= $settings['terms'][2][0] && $today <= $settings['terms'][2][1]) || ($today > $settings['terms'][1][1] && $today < $settings['terms'][2][0]) ? 2 : 1;

// ---- NG 枠の範囲（7日ずつ）----
if ($poll !== null) {
    $all = sched_dates(max((string)$poll['date_from'], $today), (string)$poll['date_to']);
    if (!$all) {
        $all = sched_dates((string)$poll['date_from'], (string)$poll['date_to']);
    }
    $week = max(0, min((int)($_GET['week'] ?? 0), intdiv(count($all) - 1, 7)));
    $days = array_slice($all, $week * 7, 7);
    $ws = (int)$poll['day_start'];
    $we = (int)$poll['day_end'];
    $prevUrl = $week > 0 ? 'sched_me?poll=' . $pollId . '&week=' . ($week - 1) : null;
    $nextUrl = ($week + 1) * 7 < count($all) ? 'sched_me?poll=' . $pollId . '&week=' . ($week + 1) : null;
} else {
    $from = (string)($_GET['from'] ?? $today);
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && strtotime($from) ? date('Y-m-d', strtotime($from)) : $today;
    $days = sched_dates($from, date('Y-m-d', strtotime($from . ' +6 day')));
    $ws = 540;
    $we = 1440;
    $prevUrl = 'sched_me?from=' . date('Y-m-d', strtotime($from . ' -7 day'));
    $nextUrl = 'sched_me?from=' . date('Y-m-d', strtotime($from . ' +7 day'));
}
sched_ical_refresh($pdo, $me); // 古ければ読み直す（1時間以内に読んでいれば何もしない）
$data = sched_collect_busy($pdo, [$me], $days[0], $days[count($days) - 1], $poll === null ? null : $pollId);
$grid = [
    'days' => array_map(static fn($d) => ['date' => $d, 'md' => sched_md($d), 'abs' => sched_day_abs($d), 'dow' => (int)date('w', strtotime($d))], $days),
    'start' => $ws, 'end' => $we, 'step' => SCHED_STEP,
    // 埋まり: [開始, 終了, 種類, ラベル]（場所は画面に要らないので落とす）
    'busy' => array_map(static fn($b) => [$b[0], $b[1], $b[3], $b[4]], $data['busy'][$me] ?? []),
    'warn' => array_values(array_map(static fn($p) => ['date' => $p['date'], 'title' => $p['title'], 's' => $p['s'], 'e' => $p['e']], array_filter($data['practices'], static fn($p) => in_array($me, $p['members'], true) && $p['status'] < 3))),
];
$icalOn = sched_ical_available();

render_header('マイ予定', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタ連の日程調整</a><?php if ($poll !== null): ?><span>/</span><a href="sched_poll?id=<?= $pollId ?>"><?= h($poll['title']) ?></a><?php endif; ?></nav>
<h1 class="display display--sm">マイ予定</h1>

<!-- ===== NG の時間 ===== -->
<section class="card sch-sec" id="ng">
    <div class="sch-sec__head">
        <h2 class="section-title section-title--card">NG の時間</h2>
        <span class="info-tip" <?= tip_attr('マスをタップ・なぞって NG を塗る。授業・カレンダーの予定・決定した練習は自動で入る') ?>><?= icon('info', 'icon--sm') ?></span>
    </div>
    <div class="sch-weeknav">
        <?php if ($prevUrl !== null): ?><a class="icon-btn" href="<?= h($prevUrl) ?>#ng" aria-label="前の週" title="前の週"><?= icon('chevron_left') ?></a><?php else: ?><span></span><?php endif; ?>
        <strong><?= h(sched_md($days[0]) . '〜' . sched_md($days[count($days) - 1])) ?></strong>
        <?php if ($nextUrl !== null): ?><a class="icon-btn" href="<?= h($nextUrl) ?>#ng" aria-label="次の週" title="次の週"><?= icon('chevron_right') ?></a><?php else: ?><span></span><?php endif; ?>
    </div>
    <div class="sch-legend small muted">
        <span><i class="sch-sw is-ng"></i>NG</span><span><i class="sch-sw is-class"></i>授業</span><span><i class="sch-sw is-ical"></i>カレンダー</span><span><i class="sch-sw is-practice"></i>練習</span>
    </div>
    <div class="sch-grid-wrap" data-sched-grid></div>
    <form method="post" class="form-actions" data-sched-grid-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="busy">
        <input type="hidden" name="payload" value="">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><input type="hidden" name="week" value="<?= (int)($week ?? 0) ?>"><?php else: ?><input type="hidden" name="from" value="<?= h($days[0]) ?>"><?php endif; ?>
        <button class="btn btn--primary" type="submit" disabled data-sched-grid-save>保存</button>
    </form>
    <?php if ($poll !== null): ?>
        <p class="sch-answer"><a class="btn btn--ghost" href="sched_poll?id=<?= $pollId ?>"><?= icon('chevron_left') ?> 候補・回答完了へ</a></p>
    <?php endif; ?>
</section>
<script type="application/json" id="sched-grid-data"><?= json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<!-- ===== 時間割 ===== -->
<section class="card sch-sec" id="classes">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">時間割</h2></div>
    <div class="tabs tabs--filter" role="tablist" data-sched-terms>
        <?php foreach (SCHED_TERMS as $t => $label): ?>
            <button type="button" class="tab<?= $t === $curTerm ? ' is-active' : '' ?>" data-term-tab="<?= $t ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach (SCHED_TERMS as $t => $label): ?>
        <form method="post" class="sch-tt" data-term="<?= $t ?>"<?= $t === $curTerm ? '' : ' hidden' ?><?= unsaved_attr() ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="classes"><input type="hidden" name="term" value="<?= $t ?>">
            <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
            <p class="muted small"><?= h(date('n/j', strtotime($settings['terms'][$t][0])) . '〜' . date('n/j', strtotime($settings['terms'][$t][1]))) ?></p>
            <table class="sch-tt__table">
                <thead><tr><th></th><?php foreach (SCHED_WEEKDAYS as $w): ?><th><?= h($w) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($settings['periods'] as $p => [$ps, $pe]): ?>
                        <tr>
                            <th><?= $p ?><small><?= h(sched_hm((int)$ps)) ?></small></th>
                            <?php foreach (SCHED_WEEKDAYS as $w => $wl): $key = $w . '-' . $p; ?>
                                <td><label class="sch-tt__cell"><input type="checkbox" name="cls[]" value="<?= $key ?>"<?= isset($classes[$t][$key]) ? ' checked' : '' ?> aria-label="<?= h($wl . $p . '限') ?>"><span></span></label></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="form-actions">
                <button class="btn btn--ghost btn--sm" type="submit" name="action" value="copy" formnovalidate><?= h(SCHED_TERMS[$t === 1 ? 2 : 1]) ?>から写す</button>
                <button class="btn btn--primary" type="submit">保存</button>
            </div>
        </form>
    <?php endforeach; ?>
</section>

<!-- ===== キャンパス ===== -->
<section class="card sch-sec" id="campus">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">キャンパス</h2>
        <span class="info-tip" <?= tip_attr('授業の前後の移動時間の計算に使う') ?>><?= icon('info', 'icon--sm') ?></span></div>
    <form method="post" class="sch-campus">
        <?= csrf_field() ?><input type="hidden" name="action" value="campus">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
        <label class="check"><input type="radio" name="campus" value="0"<?= $chosenCampus === 0 ? ' checked' : '' ?>> 自動（<?= h(SCHED_CAMPUSES[$autoCampus]) ?>）</label>
        <?php foreach (SCHED_CAMPUSES as $c => $name): ?>
            <label class="check"><input type="radio" name="campus" value="<?= $c ?>"<?= $chosenCampus === $c ? ' checked' : '' ?>> <?= h($name) ?></label>
        <?php endforeach; ?>
        <button class="btn btn--sm" type="submit">保存</button>
    </form>
</section>

<!-- ===== カレンダー連携 ===== -->
<section class="card sch-sec" id="ical">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">カレンダー連携</h2>
        <span class="info-tip" data-tip-from="#ical-help"><?= icon('info', 'icon--sm') ?></span></div>
    <div id="ical-help" hidden>
        <p><strong>Google</strong>: パソコンのブラウザで Google カレンダー → 設定 → 自分のカレンダー → 「iCal 形式の非公開 URL」をコピー</p>
        <p><strong>iCloud</strong>: iPhone のカレンダー → カレンダー一覧の ⓘ → 「公開カレンダー」をオン → リンクを共有でコピー</p>
        <p>読み込むのは予定の開始と終了の時刻だけ（タイトル等は保存しない）。終日の予定・「空き時間」の予定は無視</p>
    </div>
    <?php if (!$icalOn): ?>
        <p class="muted small">現在使えません（管理者の設定待ち）</p>
    <?php elseif ($prof['ical_url'] !== null): ?>
        <p class="sch-ical">
            <?= icon($prof['ical_error'] ? 'warning' : 'sync', 'icon--sm ' . ($prof['ical_error'] ? 'sch-warn' : 'sch-ok')) ?>
            <strong><?= h(sched_ical_label(sched_ical_decrypt($prof['ical_url']))) ?></strong>
            <span class="muted small"><?= $prof['ical_fetched_at'] !== null ? h(date('n/j G:i', strtotime((string)$prof['ical_fetched_at']))) . ' 読込' : '' ?></span>
        </p>
        <?php if ($prof['ical_error']): ?><p class="flash flash--error"><?= h($prof['ical_error']) ?></p><?php endif; ?>
        <div class="sch-row">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ical_refresh"><?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
                <button class="btn btn--sm" type="submit"><?= icon('refresh') ?> 読み直す</button></form>
            <form method="post" data-confirm="カレンダー連携を解除します。取り込んだ予定も消えます。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="action" value="ical_delete"><?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
                <button class="btn btn--sm btn--danger" type="submit">解除</button></form>
        </div>
    <?php else: ?>
        <form method="post" class="sch-row">
            <?= csrf_field() ?><input type="hidden" name="action" value="ical">
            <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
            <input class="search" type="url" name="url" required placeholder="https://calendar.google.com/…/basic.ics" autocomplete="off" aria-label="iCal の URL">
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('link') ?> 連携</button>
        </form>
    <?php endif; ?>
</section>
<script src="<?= asset_url('assets/sched.js') ?>" defer></script>
<?php render_footer();
