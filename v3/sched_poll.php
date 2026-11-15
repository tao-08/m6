<?php
/**
 * =====================================================================
 *  sched_poll.php — 日程調整1件（候補・回答状況・仮決定・決定）
 * =====================================================================
 *  GET
 *    sched_poll?id=12        … 候補の一覧（料金・連続・遅刻つき）と、決定した練習
 *    sched_poll?id=12&ics=1  … 決定した練習の .ics ファイル（カレンダーに追加）
 *  POST（CSRF チェック）
 *    answer … 自分の「回答済み」を切り替える（呼ばれた部員だけ）
 *    fix    … kind = date（日だけ仮決定）/ time（日時まで仮決定）/ final（決定）
 *    reopen … 仮決定・決定を取り消して「調整中」に戻す
 *
 *  ■ 決定ボタンの取り合い（同時に2人が別の調整で同じ部員・同じ時間を決定する）を防ぐ
 *    GET_LOCK('sched_fix') … MySQL / MariaDB の「名前つきの鍵」。同じ名前の鍵は1人しか持てない。
 *    鍵を持っている間に「その時間に全員来られるか」を DB の最新の状態でもう一度計算して、OK なら保存する。
 *    → 後から押した人は、先の人の保存が終わるまで待たされ、計算し直した結果「来られない」になって弾かれる
 *    （行ロック FOR UPDATE は「まだ無い行」を守れないので、こういう「全体で1人ずつ」には名前つきの鍵が手軽）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // tip_attr（ポップアップの説明）
require_once __DIR__ . '/lib/sched.php';
require_once __DIR__ . '/lib/sched_ical.php';
$user = require_login();
$pdo = db();

$pollId = (int)($_REQUEST['id'] ?? 0);
$poll = sched_poll_load($pdo, $pollId);
if ($poll === null) {
    not_found_page('日程調整が見つかりません', 'sched');
}
$members = sched_poll_members($pdo, $pollId);
$memberIds = array_map('intval', array_column($members, 'member_id'));
$canManage = sched_can_manage($poll, $user, $memberIds);
$myMember = $user['member_id'] === null ? null : (int)$user['member_id'];
$isMember = $myMember !== null && in_array($myMember, $memberIds, true);
$studios = sched_poll_studios($pdo, $pollId);
$studioById = array_column($studios, null, 'studio_id'); // [studio_id => 行]
$names = array_column($members, 'name', 'member_id'); // [member_id => 名前]

/** 決定・仮決定した部屋の名前（無ければ ''） */
$roomName = static function (?int $roomId) use ($pdo): string {
    if ($roomId === null) {
        return '';
    }
    $st = $pdo->prepare('SELECT name FROM studio_room WHERE studio_room_id = ?');
    $st->execute([$roomId]);
    return (string)($st->fetchColumn() ?: '');
};

/** カレンダーの予定に入れる「場所」と「メモ」 */
$eventInfo = static function () use ($poll, $studioById, $members, $roomName, $pdo): array {
    $sid = $poll['fix_studio_id'] === null ? null : (int)$poll['fix_studio_id'];
    $studio = $sid !== null ? ($studioById[$sid] ?? null) : null;
    if ($studio === null && $sid !== null) {
        // 候補から外したあとでも決定は残るので、スタジオ表から直接読む
        $st = $pdo->prepare('SELECT studio_id, name, address, reserve_url, website_url FROM studio WHERE studio_id = ?');
        $st->execute([$sid]);
        $studio = $st->fetch() ?: null;
    }
    $room = $roomName($poll['fix_room_id'] === null ? null : (int)$poll['fix_room_id']);
    $location = $studio === null ? '' : trim($studio['name'] . ($room !== '' ? ' ' . $room : '') . ' ' . (string)($studio['address'] ?? ''));
    $details = 'メンバー: ' . implode('・', array_column($members, 'name')) . "\n" . APP_URL . 'sched_poll?id=' . (int)$poll['sched_poll_id'];
    return [$studio, $room, $location, $details];
};

/* ==================== .ics のダウンロード ==================== */
if (isset($_GET['ics'])) {
    if ((int)$poll['status'] < 2 || $poll['fix_start'] === null) {
        not_found_page('まだ日時が決まっていません', 'sched_poll?id=' . $pollId);
    }
    [, , $location, $details] = $eventInfo();
    header('Content-Type: text/calendar; charset=utf-8');
    // attachment … ブラウザに「開く」ではなく「保存（またはカレンダーで開く）」として渡す
    header('Content-Disposition: attachment; filename="sched-' . $pollId . '.ics"');
    echo sched_ics($pollId, 'スタ連 ' . $poll['title'], sched_dt_to_abs($poll['fix_start']), sched_dt_to_abs($poll['fix_end']), $location, $details);
    exit;
}

/* ==================== 操作 ==================== */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'sched_poll?id=' . $pollId;

    if ($action === 'answer') {
        if (!$isMember) {
            error_page(403, 'この日程調整に呼ばれていません');
        }
        // NOT … 「回答済み」を押すたびに切り替える（NULL → 現在時刻 / 現在時刻 → NULL）
        $pdo->prepare('UPDATE sched_poll_member SET answered_at = IF(answered_at IS NULL, NOW(), NULL) WHERE sched_poll_id = ? AND member_id = ?')
            ->execute([$pollId, $myMember]);
        redirect($back);
    }

    if (!$canManage) {
        error_page(403, 'この日程調整は操作できません');
    }
    // 楽観ロック: 画面を開いたときの version と違えば、その間に誰かが変えている → やり直してもらう
    if ((int)($_POST['v'] ?? -1) !== (int)$poll['version']) {
        flash('ほかの人が先に変更しました。もう一度確認してください', 'error');
        redirect($back);
    }

    if ($action === 'reopen') {
        $pdo->prepare('UPDATE sched_poll SET status = 0, fix_date = NULL, fix_start = NULL, fix_end = NULL, fix_studio_id = NULL, fix_room_id = NULL, version = version + 1 WHERE sched_poll_id = ?')
            ->execute([$pollId]);
        sched_notify($pdo, $pollId, $poll['title'] . ' の日程が調整中に戻りました', '[[sched_poll?id=' . $pollId . '|候補]]から選び直してください', (int)$user['user_id']);
        flash('調整中に戻しました');
        redirect($back);
    }

    if ($action === 'fix') {
        $kind = (string)($_POST['kind'] ?? '');
        $date = (string)($_POST['date'] ?? '');
        $start = (int)($_POST['start'] ?? -1);
        $sid = (int)($_POST['studio'] ?? 0);
        $roomId = (int)($_POST['room'] ?? 0);
        $len = (int)$poll['length_min'];
        // 期間・時間帯・候補のスタジオの中か（画面を書き換えて範囲外を送られても通さない）
        $okRange = in_array($kind, ['date', 'time', 'final'], true) && $date >= $poll['date_from'] && $date <= $poll['date_to']
            && ($kind === 'date' || ($start >= (int)$poll['day_start'] && $start + $len <= (int)$poll['day_end'] && $start % SCHED_STEP === 0 && isset($studioById[$sid])));
        if (!$okRange) {
            flash('候補の範囲外です', 'error');
            redirect($back);
        }
        if ($roomId > 0) {
            $st = $pdo->prepare('SELECT 1 FROM studio_room WHERE studio_room_id = ? AND studio_id = ?');
            $st->execute([$roomId, $sid]);
            $roomId = $st->fetchColumn() ? $roomId : 0; // そのスタジオの部屋でなければ、部屋は空にする
        }
        $statusNew = ['date' => 1, 'time' => 2, 'final' => 3][$kind];
        $t0 = sched_day_abs($date) + $start;

        // ---- 名前つきの鍵を取ってから、最新の状態で「全員来られるか」を確かめる ----
        $got = (int)$pdo->query("SELECT GET_LOCK('sched_fix', 10)")->fetchColumn(); // 10秒まで待つ。取れたら 1
        if ($got !== 1) {
            flash('混み合っています。もう一度押してください', 'error');
            redirect($back);
        }
        $fail = null;
        try {
            if ($kind !== 'date') {
                $data = sched_collect_busy($pdo, $memberIds, $date, $date, $pollId);
                $travel = sched_travel_fn(sched_place_coords($pdo), sched_travel_overrides($pdo));
                foreach ($memberIds as $mid) {
                    if (sched_member_late($data['busy'][$mid] ?? [], $t0, $len, 'studio:' . $sid, $travel, (int)$poll['late_max']) === null) {
                        $fail = 'この時間は ' . ($names[$mid] ?? '') . ' さんが来られなくなりました（ほかの練習が先に決定された等）';
                        break;
                    }
                }
            }
            if ($fail === null) {
                $pdo->prepare('UPDATE sched_poll SET status = ?, fix_date = ?, fix_start = ?, fix_end = ?, fix_studio_id = ?, fix_room_id = ?, version = version + 1
                    WHERE sched_poll_id = ? AND version = ?')
                    ->execute([
                        $statusNew, $date,
                        $kind === 'date' ? null : sched_abs_to_dt($t0),
                        $kind === 'date' ? null : sched_abs_to_dt($t0 + $len),
                        $kind === 'date' ? null : $sid,
                        $kind === 'date' || $roomId === 0 ? null : $roomId,
                        $pollId, (int)$poll['version'],
                    ]);
            }
        } finally {
            // finally … 成功しても例外でも必ず通る。鍵を返し忘れると、次の人が10秒待たされる
            //   ※ exit（redirect）は finally を通らないので、redirect は try の外（この後）でする
            $pdo->query("SELECT RELEASE_LOCK('sched_fix')");
        }
        if ($fail !== null) {
            flash($fail, 'error');
            redirect($back);
        }
        $when = sched_md($date) . ($kind === 'date' ? '' : ' ' . sched_hm($start) . '〜' . sched_hm($start + $len));
        $where = $kind === 'date' ? '' : ' ' . $studioById[$sid]['name'];
        $label = ['date' => '仮決定（日のみ）', 'time' => '仮決定', 'final' => '決定'][$kind];
        sched_notify($pdo, $pollId, $poll['title'] . ' の日程が' . $label . 'しました', $when . $where . "\n[[sched_poll?id={$pollId}|詳細]]", (int)$user['user_id']);
        flash($label . 'しました');
        redirect($back);
    }
    redirect($back);
}

/* ==================== 表示 ==================== */
$status = (int)$poll['status'];
$len = (int)$poll['length_min'];
$settings = sched_settings($pdo);

// ---- 候補の計算（決定済みなら出さない）----
$windows = [];
$practices = [];
if ($status < 3) {
    sched_ical_refresh_many($pdo, $memberIds); // 古くなった iCal を読み直す（時間で区切るので、表示は最大でも数秒）
    // 過去の日は候補にしない（今日の、もう過ぎた時間も）
    $from = max((string)$poll['date_from'], date('Y-m-d'));
    if ($status === 1 && $poll['fix_date'] !== null) {
        $from = (string)$poll['fix_date']; // 日だけ仮決定 → その日の中から時間を選ぶ
    }
    $to = $status === 1 && $poll['fix_date'] !== null ? (string)$poll['fix_date'] : (string)$poll['date_to'];
    if ($from <= $to && $studios) {
        $data = sched_collect_busy($pdo, $memberIds, $from, $to, $pollId);
        $practices = $data['practices'];
        $nowAbs = intdiv(time(), 60);
        $windows = sched_candidates([
            'dates' => sched_dates($from, $to),
            'day_start' => (int)$poll['day_start'], 'day_end' => (int)$poll['day_end'], 'length' => $len, 'late_max' => (int)$poll['late_max'],
            'members' => array_column($members, 'name', 'member_id'),
            'busy' => $data['busy'],
            'not_before' => $nowAbs, // 今より前に始まる枠は出さない
            'studios' => array_map(static fn($s) => ['place' => 'studio:' . (int)$s['studio_id']], $studioById),
            'practices' => $practices, 'travel' => sched_travel_fn(sched_place_coords($pdo), sched_travel_overrides($pdo)),
        ]);
    }
}

// ---- 料金の計算データ（JS: studioSimEngine）。部屋の定員（capacity）を足す ----
$sim = [];
foreach ($studios as $s) {
    $sid = (int)$s['studio_id'];
    $payload = studio_sim_load($pdo, $sid);
    $st = $pdo->prepare('SELECT studio_room_id, capacity FROM studio_room WHERE studio_id = ?');
    $st->execute([$sid]);
    $cap = array_map(static fn($v) => $v === null ? null : (int)$v, $st->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach ($payload['rooms'] as &$r) {
        $r['cap'] = $cap[$r['id']] ?? null;
    }
    unset($r); // foreach の参照を切る（PHP の落とし穴。lib/sched.php の sched_candidates のコメント参照）
    $sim[$sid] = $payload;
}
$boot = [
    'poll' => $pollId, 'v' => (int)$poll['version'], 'status' => $status, 'len' => $len, 'people' => count($members), 'jo' => $settings['jo'],
    'canFix' => $canManage, 'csrf' => csrf_token(),
    'studios' => array_map(static fn($s) => ['id' => (int)$s['studio_id'], 'name' => $s['name'], 'area' => (string)$s['area'], 'reserve' => (string)($s['reserve_url'] ?: $s['website_url'])], array_values($studios)),
    'sim' => (object)$sim,
    // 窓 → 画面用（日付の表示・曜日区分・遅刻の人の名前を足す）
    'windows' => array_map(static fn($w) => [
        'date' => $w['date'], 'md' => sched_md($w['date']), 'day' => sched_day_type($w['date']), 'studio' => $w['studio_id'],
        'starts' => $w['starts'], 'from' => $w['from'], 'to' => $w['to'], 'adj' => $w['adj'], 'adjWith' => $w['adj_with'], 'warn' => $w['warn'],
        'late' => array_map(static fn($mid, $min) => ['name' => $names[$mid] ?? '', 'min' => $min], array_keys($w['late']), array_values($w['late'])),
    ], $windows),
];

// ---- 仮決定・決定の表示 ----
$fixed = null;
if ($status >= 1) {
    [$fStudio, $fRoom, $location, $details] = $eventInfo();
    $fixed = ['studio' => $fStudio, 'room' => $fRoom];
    if ($status >= 2) {
        $s = sched_dt_to_abs($poll['fix_start']);
        $e = sched_dt_to_abs($poll['fix_end']);
        $fixed['when'] = sched_md((string)$poll['fix_date']) . ' ' . date('G:i', $s * 60) . '〜' . date('G:i', $e * 60);
        $fixed['gcal'] = sched_gcal_url('スタ連 ' . $poll['title'], $s, $e, $location, $details);
    } else {
        $fixed['when'] = sched_md((string)$poll['fix_date']);
    }
}
$myRow = null;
foreach ($members as $m) {
    if ((int)$m['member_id'] === $myMember) {
        $myRow = $m;
    }
}

render_header($poll['title'] . ' — 日程調整', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタ連の日程調整</a></nav>
<div class="sch-head">
    <h1 class="display display--sm"><?= h($poll['title']) ?> <?= sched_status_pill($poll) ?></h1>
    <?php if ($canManage): ?><a class="btn btn--sm btn--ghost" href="sched?edit=<?= $pollId ?>"><?= icon('edit') ?> 編集</a><?php endif; ?>
</div>
<p class="muted small sch-meta">
    <?= h(sched_md($poll['date_from']) . '〜' . sched_md($poll['date_to'])) ?> ・ <?= h(sched_hm((int)$poll['day_start']) . '〜' . sched_hm((int)$poll['day_end'])) ?>
    ・ <?= h(studio_fmt_hours($len)) ?><?= (int)$poll['late_max'] > 0 ? ' ・ 遅刻' . (int)$poll['late_max'] . '分まで' : '' ?>
</p>

<?php if ($fixed !== null): ?>
    <section class="card sch-fixed<?= $status === 3 ? ' is-final' : '' ?>">
        <div class="sch-fixed__when"><?= icon($status === 3 ? 'event_available' : 'schedule') ?> <strong><?= h($fixed['when']) ?></strong></div>
        <?php if ($fixed['studio'] !== null): ?>
            <div class="sch-fixed__where"><?= h($fixed['studio']['name']) ?><?= $fixed['room'] !== '' ? ' ' . h($fixed['room']) : '' ?></div>
        <?php endif; ?>
        <div class="sch-fixed__actions">
            <?php if (isset($fixed['gcal'])): ?>
                <a class="btn btn--sm" href="<?= h($fixed['gcal']) ?>" target="_blank" rel="noopener"><?= icon('event') ?> Googleカレンダー</a>
                <a class="btn btn--sm" href="sched_poll?id=<?= $pollId ?>&amp;ics=1"><?= icon('download') ?> .ics</a>
            <?php endif; ?>
            <?php $reserve = $fixed['studio'] !== null ? (string)($fixed['studio']['reserve_url'] ?: $fixed['studio']['website_url']) : ''; ?>
            <?php if ($reserve !== '' && $status >= 2): ?>
                <a class="btn btn--sm btn--primary" href="<?= h($reserve) ?>" target="_blank" rel="noopener"><?= icon('open_in_new') ?> 予約ページ</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" data-confirm="<?= $status === 3 ? '決定' : '仮決定' ?>を取り消して調整中に戻します。よろしいですか？">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit">取り消し</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($status === 2): ?><p class="muted small">仮決定はほかの調整では埋まりとして扱いません（注意だけ出ます）</p><?php endif; ?>
    </section>
<?php endif; ?>

<section class="sch-people">
    <div class="chip-list">
        <?php foreach ($members as $m): ?>
            <span class="chip<?= (int)$m['member_id'] === $myMember ? ' chip--me' : '' ?>" <?= tip_attr($m['answered_at'] !== null ? '回答済み' : '未回答') ?>>
                <?= $m['answered_at'] !== null ? icon('check_circle', 'icon--sm sch-ok') : icon('schedule', 'icon--sm muted') ?>
                <?= h($m['name']) ?>
                <?php if ($m['has_ical']): ?><?= icon($m['ical_error'] ? 'warning' : 'sync', 'icon--sm ' . ($m['ical_error'] ? 'sch-warn' : 'muted')) ?><?php endif; ?>
            </span>
        <?php endforeach; ?>
    </div>
    <?php if ($myRow !== null): ?>
        <div class="sch-me-actions">
            <a class="btn btn--sm" href="sched_me?poll=<?= $pollId ?>"><?= icon('edit') ?> 空き予定を入力</a>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="answer">
                <button class="btn btn--sm<?= $myRow['answered_at'] === null ? ' btn--primary' : ' btn--ghost' ?>" type="submit">
                    <?= $myRow['answered_at'] === null ? icon('check') . ' 回答完了' : '回答を取り消し' ?>
                </button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php if ($status < 3): ?>
    <section class="sch-cands" data-sched-cands>
        <div class="sch-cands__head">
            <h2 class="section-title">候補 <small class="muted"><?= count($windows) ?></small></h2>
            <div class="tabs tabs--filter sch-sort" role="tablist" data-sched-sort>
                <button type="button" class="tab is-active" data-sort="best">おすすめ順</button>
                <button type="button" class="tab" data-sort="date">日付順</button>
                <button type="button" class="tab" data-sort="price">安い順</button>
            </div>
        </div>
        <?php if (!$studios): ?>
            <div class="empty card"><p class="empty__title">候補のスタジオがありません</p></div>
        <?php elseif (!$windows): ?>
            <div class="empty card"><p class="empty__title">全員がそろう時間がありません</p>
                <p class="muted small">期間・時間帯を広げるか、遅刻の上限を延ばしてみてください</p></div>
        <?php endif; ?>
        <div class="sch-list" data-sched-list></div>
        <p class="muted small">料金は目安です。スタジオの空き状況は予約ページで確認してください</p>
    </section>
    <script type="application/json" id="sched-data"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="<?= asset_url('assets/studio_sim.js') ?>" defer></script>
    <script src="<?= asset_url('assets/sched.js') ?>" defer></script>
<?php endif; ?>
<?php render_footer();
