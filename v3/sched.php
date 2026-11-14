<?php
/**
 * =====================================================================
 *  sched.php — スタ連の日程調整（一覧・作成・編集）
 * =====================================================================
 *  GET
 *    sched            … 自分が入っている（作った）調整の一覧
 *    sched?new        … 新しく作るフォーム
 *    sched?edit=12    … 編集フォーム
 *  POST（CSRF チェック）
 *    save   … 作成 / 保存（sched_poll_id があれば編集）
 *    delete … 削除
 *
 *  作れるのはログインしている部員なら誰でも。編集・削除は「作った人・呼ばれた部員・幹部」（sched_can_manage）
 *  候補の計算・確定は sched_poll.php、自分の予定の入力は sched_me.php
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // member_name_choices / render_suggest_datalist（名前の入力候補）
require_once __DIR__ . '/lib/sched.php';
$user = require_login();
$pdo = db();

/* ==================== 保存・削除 ==================== */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pollId = (int)($_POST['sched_poll_id'] ?? 0);
    $poll = $pollId > 0 ? sched_poll_load($pdo, $pollId) : null;
    if ($pollId > 0) {
        // 編集・削除は、その調整を操作できる人だけ（HTML を書き換えて他人の調整の id を送られても通さない）
        $mids = $poll ? array_column(sched_poll_members($pdo, $pollId), 'member_id') : [];
        if ($poll === null || !sched_can_manage($poll, $user, $mids)) {
            error_page(403, 'この日程調整は操作できません');
        }
    }

    if ($action === 'delete' && $poll !== null) {
        $pdo->prepare('DELETE FROM sched_poll WHERE sched_poll_id = ?')->execute([$pollId]); // 部員・スタジオの行は ON DELETE CASCADE で一緒に消える
        flash('日程調整を削除しました');
        redirect('sched');
    }

    if ($action === 'save') {
        // ---- 入力を読む（全部「信用しない」前提でチェックする）----
        $in = [
            'title'     => trim((string)($_POST['title'] ?? '')),
            'date_from' => (string)($_POST['date_from'] ?? ''),
            'date_to'   => (string)($_POST['date_to'] ?? ''),
            'day_start' => (int)($_POST['day_start'] ?? 0),
            'day_end'   => (int)($_POST['day_end'] ?? 0),
            'length'    => (int)($_POST['length'] ?? 0),
            'late_max'  => (int)($_POST['late_max'] ?? 0),
        ];
        $names = array_values(array_unique(array_filter(array_map(static fn($n) => trim((string)$n), (array)($_POST['m_name'] ?? [])), static fn($n) => $n !== '')));
        $studioIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['studio'] ?? [])), static fn($v) => $v > 0)));
        $errors = [];
        if ($in['title'] === '' || mb_strlen($in['title']) > 100) {
            $errors[] = 'タイトルを1〜100文字で入力してください';
        }
        // checkdate で「2026-02-30」のような無い日を弾く
        $validDate = static fn(string $d): bool => (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        if (!$validDate($in['date_from']) || !$validDate($in['date_to']) || $in['date_to'] < $in['date_from']) {
            $errors[] = '期間を正しく入力してください';
        } elseif (count(sched_dates($in['date_from'], $in['date_to'])) > SCHED_MAX_DAYS) {
            $errors[] = '期間は' . SCHED_MAX_DAYS . '日以内にしてください';
        }
        if ($in['day_start'] < 0 || $in['day_end'] > 1440 || $in['day_start'] % SCHED_STEP || $in['day_end'] % SCHED_STEP || $in['day_end'] - $in['day_start'] < $in['length']) {
            $errors[] = '時間帯が練習の長さより短くなっています';
        }
        if (!in_array($in['length'], SCHED_LENGTHS, true)) {
            $errors[] = '練習の長さを選んでください';
        }
        if (!in_array($in['late_max'], SCHED_LATE_OPTIONS, true)) {
            $errors[] = '遅刻の上限を選んでください';
        }
        // 名前 → member_id。名前は member.name と完全一致で探す（同姓同名は考えない方針）
        $memberIds = [];
        if ($names) {
            $ph = implode(',', array_fill(0, count($names), '?'));
            $st = $pdo->prepare("SELECT name, member_id FROM member WHERE name IN ($ph)");
            $st->execute($names);
            $found = $st->fetchAll(PDO::FETCH_KEY_PAIR); // [名前 => member_id]
            foreach ($names as $n) {
                if (isset($found[$n])) {
                    $memberIds[] = (int)$found[$n];
                } else {
                    $errors[] = '「' . $n . '」は部員に見つかりません';
                }
            }
        }
        if (!$memberIds || count($memberIds) > SCHED_MAX_MEMBERS) {
            $errors[] = '部員を1〜' . SCHED_MAX_MEMBERS . '人選んでください';
        }
        if ($studioIds) {
            $ph = implode(',', array_fill(0, count($studioIds), '?'));
            $st = $pdo->prepare("SELECT studio_id FROM studio WHERE studio_id IN ($ph)");
            $st->execute($studioIds);
            $studioIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); // 実在するスタジオだけ残す
        }
        if (!$studioIds) {
            $errors[] = 'スタジオを1つ以上選んでください';
        }

        if ($errors) {
            // エラーのときは入力をセッションに預けて、フォームに戻す（PRG パターン。再読み込みで二重送信しない）
            $_SESSION['sched_form'] = ['in' => $in, 'names' => $names, 'studios' => $studioIds, 'errors' => $errors];
            redirect($pollId > 0 ? 'sched?edit=' . $pollId : 'sched?new');
        }

        $pdo->beginTransaction();
        try {
            $vals = [$in['title'], $in['date_from'], $in['date_to'], $in['day_start'], $in['day_end'], $in['length'], $in['late_max']];
            if ($poll === null) {
                $pdo->prepare('INSERT INTO sched_poll (title, date_from, date_to, day_start, day_end, length_min, late_max, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$vals, (int)$user['user_id']]);
                $pollId = (int)$pdo->lastInsertId();
                $before = [];
            } else {
                $pdo->prepare('UPDATE sched_poll SET title = ?, date_from = ?, date_to = ?, day_start = ?, day_end = ?, length_min = ?, late_max = ?, version = version + 1 WHERE sched_poll_id = ?')
                    ->execute([...$vals, $pollId]);
                $before = array_map('intval', array_column(sched_poll_members($pdo, $pollId), 'member_id'));
            }
            // 部員: 外れた人だけ消し、新しい人だけ足す（残った人の「回答済み」を消さないため。全部消して入れ直すと消えてしまう）
            $removed = array_diff($before, $memberIds);
            if ($removed) {
                $ph = implode(',', array_fill(0, count($removed), '?'));
                $pdo->prepare("DELETE FROM sched_poll_member WHERE sched_poll_id = ? AND member_id IN ($ph)")->execute([$pollId, ...array_values($removed)]);
            }
            $ins = $pdo->prepare('INSERT IGNORE INTO sched_poll_member (sched_poll_id, member_id) VALUES (?, ?)');
            foreach ($memberIds as $mid) {
                $ins->execute([$pollId, $mid]);
            }
            // スタジオは「回答」のような付随データが無いので、全部消して入れ直してよい
            $pdo->prepare('DELETE FROM sched_poll_studio WHERE sched_poll_id = ?')->execute([$pollId]);
            $ins = $pdo->prepare('INSERT INTO sched_poll_studio (sched_poll_id, studio_id) VALUES (?, ?)');
            foreach ($studioIds as $sid) {
                $ins->execute([$pollId, $sid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // 新しく呼ばれた人にだけお知らせ（編集のたびに全員に飛ばさない）
        $added = array_diff($memberIds, $before);
        if ($added) {
            $ph = implode(',', array_fill(0, count($added), '?'));
            $st = $pdo->prepare("SELECT DISTINCT u.user_id FROM user_account u WHERE u.member_id IN ($ph)");
            $st->execute(array_values($added));
            $targets = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($targets) {
                sched_notify_users($pdo, $targets, $pollId, $in['title'] . ' の日程調整に追加されました',
                    sched_md($in['date_from']) . '〜' . sched_md($in['date_to']) . "\n[[sched_me?poll={$pollId}|空き予定]]を確認して、回答してください", (int)$user['user_id']);
            }
        }
        flash($poll === null ? '日程調整を作成しました' : '保存しました');
        redirect('sched_poll?id=' . $pollId);
    }
    redirect('sched');
}

/* ==================== フォーム（新規・編集） ==================== */
if (isset($_GET['new']) || isset($_GET['edit'])) {
    $pollId = (int)($_GET['edit'] ?? 0);
    $poll = $pollId > 0 ? sched_poll_load($pdo, $pollId) : null;
    if ($pollId > 0 && $poll === null) {
        not_found_page('日程調整が見つかりません', 'sched');
    }
    // 初期値: 明日から2週間・10:00〜23:00・2時間・遅刻30分まで・自分＋初期候補のスタジオ
    $me = null;
    if ($user['member_id'] !== null) {
        $st = $pdo->prepare('SELECT name FROM member WHERE member_id = ?');
        $st->execute([(int)$user['member_id']]);
        $me = $st->fetchColumn() ?: null;
    }
    $in = $poll !== null ? [
        'title' => $poll['title'], 'date_from' => $poll['date_from'], 'date_to' => $poll['date_to'], 'day_start' => (int)$poll['day_start'],
        'day_end' => (int)$poll['day_end'], 'length' => (int)$poll['length_min'], 'late_max' => (int)$poll['late_max'],
    ] : ['title' => '', 'date_from' => date('Y-m-d', strtotime('+1 day')), 'date_to' => date('Y-m-d', strtotime('+14 day')), 'day_start' => 600, 'day_end' => 1380, 'length' => 120, 'late_max' => 30];
    $names = $poll !== null ? array_column(sched_poll_members($pdo, $pollId), 'name') : ($me !== null ? [$me] : []);
    $chosen = $poll !== null ? array_map('intval', array_column(sched_poll_studios($pdo, $pollId), 'studio_id')) : null;
    $errors = [];
    // エラーで戻ってきたときは、さっきの入力で埋める（?? [] … 無ければ空の配列）
    if (isset($_SESSION['sched_form'])) {
        ['in' => $in, 'names' => $names, 'studios' => $chosen, 'errors' => $errors] = $_SESSION['sched_form'];
        unset($_SESSION['sched_form']);
    }
    $studios = $pdo->query("SELECT studio_id, name, area, sched_default FROM studio ORDER BY sort_order, studio_id")->fetchAll();
    $chosen ??= array_map('intval', array_column(array_filter($studios, static fn($s) => (int)$s['sched_default'] === 1), 'studio_id'));
    // エリアごとにまとめる（エリアの並び順はスタジオ情報と同じ。エリア未入力は「その他」）
    $byArea = [];
    foreach (studio_areas($pdo) as $a) {
        $byArea[$a] = [];
    }
    foreach ($studios as $s) {
        $byArea[$s['area'] !== null && $s['area'] !== '' ? $s['area'] : 'その他'][] = $s;
    }
    $byArea = array_filter($byArea); // スタジオの無いエリアは出さない
    $times = static function (int $from, int $to, int $selected): string {
        $html = '';
        for ($m = $from; $m <= $to; $m += SCHED_STEP) {
            $html .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . h(sched_hm($m)) . '</option>';
        }
        return $html;
    };
    $allNames = member_name_choices($pdo);
    render_header($poll === null ? '日程調整を作成' : '日程調整を編集', 'tools');
    ?>
    <link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
    <nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタ連の日程調整</a></nav>
    <h1 class="display display--sm"><?= $poll === null ? '日程調整を作成' : '日程調整を編集' ?></h1>
    <?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" class="card form-card sch-form" data-sched-form<?= unsaved_attr() ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="sched_poll_id" value="<?= $pollId ?>">
        <label class="field"><span>タイトル</span><input name="title" value="<?= h($in['title']) ?>" maxlength="100" required placeholder="例: バンド名" autocomplete="off"></label>

        <div class="field">
            <span>部員 <small class="muted" data-member-count></small></span>
            <div class="sch-members" data-member-rows>
                <?php foreach ([...$names, ''] as $n): ?>
                    <div class="sch-member-row">
                        <input name="m_name[]" value="<?= h($n) ?>" data-suggest-list="member-names" autocomplete="off" placeholder="名前" aria-label="名前" class="name-input">
                        <button type="button" class="icon-btn icon-btn--sm" data-remove-member aria-label="削除" title="削除"><?= icon('close') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?= render_suggest_datalist('member-names', array_keys($allNames), $allNames) ?>

        <div class="sch-grid2">
            <label class="field"><span>開始日</span><input type="date" name="date_from" value="<?= h($in['date_from']) ?>" required></label>
            <label class="field"><span>終了日</span><input type="date" name="date_to" value="<?= h($in['date_to']) ?>" required></label>
            <label class="field"><span>時間帯（から）</span><select name="day_start"><?= $times(360, 1380, $in['day_start']) ?></select></label>
            <label class="field"><span>時間帯（まで）</span><select name="day_end"><?= $times(420, 1440, $in['day_end']) ?></select></label>
            <label class="field"><span>練習の長さ</span><select name="length">
                <?php foreach (SCHED_LENGTHS as $l): ?><option value="<?= $l ?>"<?= $l === $in['length'] ? ' selected' : '' ?>><?= h(studio_fmt_hours($l)) ?></option><?php endforeach; ?>
            </select></label>
            <label class="field"><span>遅刻の上限</span><select name="late_max">
                <?php foreach (SCHED_LATE_OPTIONS as $l): ?><option value="<?= $l ?>"<?= $l === $in['late_max'] ? ' selected' : '' ?>><?= $l === 0 ? '遅刻なし' : $l . '分まで' ?></option><?php endforeach; ?>
            </select></label>
        </div>

        <fieldset class="field sch-studios">
            <legend>スタジオ</legend>
            <?php foreach ($byArea as $area => $list): ?>
                <div class="sch-area" data-area>
                    <label class="check sch-area__head"><input type="checkbox" data-area-all> <strong><?= h($area) ?></strong></label>
                    <div class="sch-area__list">
                        <?php foreach ($list as $s): ?>
                            <label class="check"><input type="checkbox" name="studio[]" value="<?= (int)$s['studio_id'] ?>"<?= in_array((int)$s['studio_id'], $chosen, true) ? ' checked' : '' ?>> <?= h($s['name']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$byArea): ?><p class="muted small">スタジオ情報が未登録です。<a class="text-link" href="studio_list">スタジオ情報</a>から追加してください</p><?php endif; ?>
        </fieldset>

        <div class="form-actions">
            <a class="btn btn--ghost" href="<?= $poll === null ? 'sched' : 'sched_poll?id=' . $pollId ?>">キャンセル</a>
            <button class="btn btn--primary" type="submit"><?= $poll === null ? '作成' : '保存' ?></button>
        </div>
    </form>
    <?php if ($poll !== null): ?>
        <form method="post" class="danger-zone" data-confirm="「<?= h($poll['title']) ?>」の日程調整を削除します。元に戻せません。よろしいですか？">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="sched_poll_id" value="<?= $pollId ?>">
            <button class="btn btn--danger" type="submit"><?= icon('delete') ?> 削除</button>
        </form>
    <?php endif; ?>
    <script src="<?= asset_url('assets/sched.js') ?>" defer></script>
    <?php render_footer();
    exit;
}

/* ==================== 一覧 ==================== */
// 自分が呼ばれている調整・自分が作った調整。終わったもの（決定した日・期間の最終日が1週間より前）は出さない
$myMember = $user['member_id'] === null ? 0 : (int)$user['member_id'];
// 集計は相関サブクエリ（外側の行ごとに数える）で書く。GROUP BY と p.* を混ぜると、MySQL の設定（ONLY_FULL_GROUP_BY）によっては通らないため
$st = $pdo->prepare('SELECT p.*,
        (SELECT COUNT(*) FROM sched_poll_member x WHERE x.sched_poll_id = p.sched_poll_id) AS n_members,
        (SELECT COUNT(x.answered_at) FROM sched_poll_member x WHERE x.sched_poll_id = p.sched_poll_id) AS n_answered
    FROM sched_poll p
    WHERE (p.created_by = ? OR EXISTS (SELECT 1 FROM sched_poll_member y WHERE y.sched_poll_id = p.sched_poll_id AND y.member_id = ?))
      AND COALESCE(p.fix_date, p.date_to) >= ?
    ORDER BY p.status = 3, COALESCE(p.fix_date, p.date_from), p.sched_poll_id');
$st->execute([(int)$user['user_id'], $myMember, date('Y-m-d', strtotime('-7 day'))]);
$polls = $st->fetchAll();
// マイ予定の入力状況（時間割もカレンダーも無ければ、入力を促す）
$hasClass = $hasIcal = false;
if ($myMember > 0) {
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM sched_class WHERE member_id = ?) AS c, (SELECT ical_url IS NOT NULL FROM sched_profile WHERE member_id = ?) AS i');
    $st->execute([$myMember, $myMember]);
    $r = $st->fetch();
    $hasClass = (int)$r['c'] > 0;
    $hasIcal = (bool)$r['i'];
}
render_header('スタ連の日程調整', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a></nav>
<div class="sch-head">
    <h1 class="display display--sm">スタ連の日程調整</h1>
    <a class="btn btn--primary" href="sched?new"><?= icon('add') ?> 作成</a>
</div>
<div class="tool-list">
    <a class="card tool-card" href="sched_me">
        <span class="tool-card__icon"><?= icon('event') ?></span>
        <span class="tool-card__body">
            <span class="tool-card__name">マイ予定<?= (!$hasClass && !$hasIcal) ? ' <span class="pill pill--warn">未入力</span>' : '' ?></span>
            <span class="muted small">時間割・カレンダー連携・NG の時間</span>
        </span>
    </a>
    <?php if (is_executive()): ?>
        <a class="card tool-card" href="sched_settings">
            <span class="tool-card__icon"><?= icon('settings') ?></span>
            <span class="tool-card__body">
                <span class="tool-card__name">設定 <span class="tag">幹部</span></span>
                <span class="muted small">時限・学期・移動時間・初期候補のスタジオ・部屋の定員</span>
            </span>
        </a>
    <?php endif; ?>
</div>

<h2 class="section-title">日程調整</h2>
<?php if (!$polls): ?>
    <div class="empty card"><p class="empty__title">日程調整はありません</p></div>
<?php else: ?>
    <div class="tool-list">
        <?php foreach ($polls as $p): $status = (int)$p['status']; ?>
            <a class="card tool-card" href="sched_poll?id=<?= (int)$p['sched_poll_id'] ?>">
                <span class="tool-card__icon"><?= icon($status === 3 ? 'event_available' : 'schedule') ?></span>
                <span class="tool-card__body">
                    <span class="tool-card__name"><?= h($p['title']) ?> <?= sched_status_pill($p) ?></span>
                    <span class="muted small">
                        <?php if ($status === 3): ?>
                            <?= h(sched_md((string)$p['fix_date']) . ' ' . date('G:i', strtotime((string)$p['fix_start'])) . '〜' . date('G:i', strtotime((string)$p['fix_end']))) ?>
                        <?php elseif ($status >= 1): ?>
                            <?= h(sched_md((string)$p['fix_date'])) ?><?= $status === 2 ? ' ' . h(date('G:i', strtotime((string)$p['fix_start'])) . '〜' . date('G:i', strtotime((string)$p['fix_end']))) : '' ?>
                        <?php else: ?>
                            <?= h(sched_md($p['date_from']) . '〜' . sched_md($p['date_to'])) ?> ・ 回答 <?= (int)$p['n_answered'] ?>/<?= (int)$p['n_members'] ?>
                        <?php endif; ?>
                    </span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_footer();
