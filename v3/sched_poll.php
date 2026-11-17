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
 *    fix    … kind = date（日だけ仮決定）/ time（日時まで仮決定）/ final（決定）。absent = 欠席する部員の id（0 = なし）
 *    reopen … 仮決定・決定を取り消して「調整中」に戻す（欠席の印も戻す）
 *    reserve   … 決定した練習のスタジオを「予約済み」にする（reserved_at / reserved_by に記録。通知は送らない）
 *    unreserve … 予約済みを取り消す（決定はそのまま）。fix・reopen をすると、予約済みも未予約に戻る
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
require_once __DIR__ . '/lib/sched_google.php'; // 決定した練習を Google カレンダーに入れる・直す・消す
$user = require_login(); // ログインしていれば誰でも
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

/** 決定・仮決定した部屋の広さ（"12帖"。部屋が無い・広さ不明なら ''）。画面に出すだけ（カレンダーの「場所」には入れない） */
$roomArea = static function (?int $roomId) use ($pdo): string {
    if ($roomId === null) {
        return '';
    }
    $st = $pdo->prepare('SELECT area_jo FROM studio_room WHERE studio_room_id = ?'); // プリペアドステートメント（? に値を渡す）
    $st->execute([$roomId]);
    return studio_fmt_jo($st->fetchColumn() ?: null); // 該当なし（false）・NULL は null にそろえる → 広さ不明は ''
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
    $location = sched_event_location($studio === null ? null : $studio['name'], $room, $studio['address'] ?? null); // 購読（sched_feed.php）と同じ文にするため lib/sched.php に共通化
    // 欠席の人（fix_absent = 1）はメンバーに入れない
    $present = array_filter($members, static fn($m) => (int)$m['fix_absent'] === 0);
    $details = sched_event_details(array_column($present, 'name'), (int)$poll['sched_poll_id']);
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

    if ($action === 'reserve') {
        // 決定（status = 3）した練習だけ「予約済み」にできる。WHERE にも status = 3 を入れて、確認から押すまでの間に決定が外れても通さない
        if ((int)$poll['status'] !== 3) {
            flash('まだ決定していません', 'error');
            redirect($back);
        }
        $pdo->prepare('UPDATE sched_poll SET reserved_at = NOW(), reserved_by = ?, version = version + 1 WHERE sched_poll_id = ? AND status = 3')
            ->execute([(int)$user['user_id'], $pollId]);
        flash('予約済みにしました');
        redirect($back);
    }

    if ($action === 'unreserve') {
        // 予約済みの取り消し（決定はそのまま。予約のうながしがまた出る）
        $pdo->prepare('UPDATE sched_poll SET reserved_at = NULL, reserved_by = NULL, version = version + 1 WHERE sched_poll_id = ?')
            ->execute([$pollId]);
        flash('予約済みを取り消しました');
        redirect($back);
    }

    if ($action === 'reopen') {
        // 2つの UPDATE を1つのトランザクションに入れる（片方だけ成功して、欠席の印だけ残る…を防ぐ）
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE sched_poll SET status = 0, fix_date = NULL, fix_start = NULL, fix_end = NULL, fix_studio_id = NULL, fix_room_id = NULL, reserved_at = NULL, reserved_by = NULL, version = version + 1 WHERE sched_poll_id = ?')
                ->execute([$pollId]);
            $pdo->prepare('UPDATE sched_poll_member SET fix_absent = 0 WHERE sched_poll_id = ?')->execute([$pollId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        sched_google_sync_poll($pdo, $pollId); // Google 連携中の部員のカレンダーから、この練習を消す（失敗しても、ここの操作は成功のまま）
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
        $absentId = (int)($_POST['absent'] ?? 0); // 欠席する人（member_id。0 = なし）
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
        // 欠席が有効なのは「日時まで決めるとき」「その調整の部員」「部員が3人以上」だけ（画面を書き換えて送られても、それ以外は 0 にする）
        //   in_array の第3引数 true … 型も同じか比べる（"1" と 1 を同じとみなさない）
        if ($kind === 'date' || count($memberIds) < 3 || !in_array($absentId, $memberIds, true)) {
            $absentId = 0;
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
                    if ($mid === $absentId) {
                        continue; // 欠席する人は、来られるか確かめない
                    }
                    if (sched_member_times($data['busy'][$mid] ?? [], $t0, $len, 'studio:' . $sid, $travel, (int)$poll['late_max']) === null) {
                        $fail = 'この時間は ' . ($names[$mid] ?? '') . ' さんが来られなくなりました（ほかの練習が先に決定された等）';
                        break;
                    }
                }
            }
            if ($fail === null) {
                // 調整の更新と欠席の印は、同じトランザクションで（片方だけ保存される状態を作らない）
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare('UPDATE sched_poll SET status = ?, fix_date = ?, fix_start = ?, fix_end = ?, fix_studio_id = ?, fix_room_id = ?, reserved_at = NULL, reserved_by = NULL, version = version + 1
                        WHERE sched_poll_id = ? AND version = ?');
                    $up->execute([
                        $statusNew, $date,
                        $kind === 'date' ? null : sched_abs_to_dt($t0),
                        $kind === 'date' ? null : sched_abs_to_dt($t0 + $len),
                        $kind === 'date' ? null : $sid,
                        $kind === 'date' || $roomId === 0 ? null : $roomId,
                        $pollId, (int)$poll['version'],
                    ]);
                    if ($up->rowCount() === 0) {
                        // version が変わっていた = 確認中に、ほかの人が先に変更した（楽観ロックの失敗）
                        $pdo->rollBack();
                        $fail = 'ほかの人が先に変更しました。もう一度確認してください';
                    } else {
                        // (member_id = ?) は、一致する行が 1、それ以外が 0。absent が 0 なら全員 0 になる
                        $pdo->prepare('UPDATE sched_poll_member SET fix_absent = (member_id = ?) WHERE sched_poll_id = ?')->execute([$absentId, $pollId]);
                        $pdo->commit();
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e; // 鍵は finally で必ず返す
                }
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
        sched_google_sync_poll($pdo, $pollId); // 決定（status = 3）なら Google 連携中の部員のカレンダーに入れる・直す。仮決定なら（前の決定が残っていれば）消す。欠席の人の分も合わせる
        $when = sched_md($date) . ($kind === 'date' ? '' : ' ' . sched_hm($start) . '〜' . sched_hm($start + $len));
        $where = $kind === 'date' ? '' : ' ' . $studioById[$sid]['name'];
        $label = ['date' => '仮決定（日のみ）', 'time' => '仮決定', 'final' => '決定'][$kind];
        $absentNote = $absentId > 0 ? '（' . ($names[$absentId] ?? '') . 'さん欠席）' : '';
        sched_notify($pdo, $pollId, $poll['title'] . ' の日程が' . $label . 'しました', $when . $where . $absentNote . "\n[[sched_poll?id={$pollId}|詳細]]", (int)$user['user_id']);
        flash($label . 'しました' . $absentNote);
        redirect($back);
    }
    redirect($back);
}

/* ==================== 表示 ==================== */
$status = (int)$poll['status'];
$len = (int)$poll['length_min'];
$settings = sched_settings($pdo);

/**
 * 練習（決定・仮決定）ごとの「スタジオ名 部屋名」。キーは「題|開始の絶対分」（同じ題の別の日・時間を取り違えない）
 *   注意のポップアップ（仮決定）と、メンバーの予定表（決定した練習）に出す
 */
$practiceWhere = static function (array $practices) use ($pdo): array {
    $sids = array_values(array_unique(array_filter(array_column($practices, 'studio_id'))));
    $rids = array_values(array_unique(array_filter(array_column($practices, 'room_id'))));
    $sNames = [];
    $rNames = [];
    if ($sids) {
        $st = $pdo->prepare('SELECT studio_id, name FROM studio WHERE studio_id IN (' . implode(',', array_fill(0, count($sids), '?')) . ')'); // ? を件数ぶん並べる（プリペアドステートメント）
        $st->execute($sids);
        $sNames = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    if ($rids) {
        $st = $pdo->prepare('SELECT studio_room_id, name FROM studio_room WHERE studio_room_id IN (' . implode(',', array_fill(0, count($rids), '?')) . ')');
        $st->execute($rids);
        $rNames = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $out = [];
    foreach ($practices as $pr) {
        $out[$pr['title'] . '|' . $pr['s']] = trim(($sNames[$pr['studio_id']] ?? '') . ' ' . ($rNames[$pr['room_id']] ?? ''));
    }
    return $out;
};

// ---- 候補の計算（決定済みなら出さない）----
$windows = [];       // 全員そろう候補
$absentWindows = []; // 1人欠席の候補
$over = null;        // メンバーの予定
$practices = [];
if ($status < 3) {
    // 古くなった予定を読み直す（時間で区切るので、表示は最大でも数秒）。Google 連携中の人は Google から、それ以外の人は iCal から。
    //   全体の上限は 6 秒のまま（Google に使った分を引いた残りを、iCal に回す）
    $used = sched_google_refresh_many($pdo, $memberIds);
    sched_ical_refresh_many($pdo, $memberIds, max(1, 6 - (int)ceil($used)));
    // 過去の日は候補にしない（今日の、もう過ぎた時間も）
    $from = max((string)$poll['date_from'], date('Y-m-d'));
    if ($status === 1 && $poll['fix_date'] !== null) {
        $from = (string)$poll['fix_date']; // 日だけ仮決定 → その日の中から時間を選ぶ
    }
    $to = $status === 1 && $poll['fix_date'] !== null ? (string)$poll['fix_date'] : (string)$poll['date_to'];
    if ($from <= $to) {
        $data = sched_collect_busy($pdo, $memberIds, $from, $to, $pollId, false, $myMember); // 最後 = 自分の iCal の予定のタイトルだけ復号する（他の人の分は '' のまま）
        $practices = $data['practices'];
        if ($studios) {
            $calc = [
                'dates' => sched_dates($from, $to),
                'day_start' => (int)$poll['day_start'], 'day_end' => (int)$poll['day_end'], 'length' => $len, 'late_max' => (int)$poll['late_max'],
                'members' => array_column($members, 'name', 'member_id'),
                'busy' => $data['busy'],
                'not_before' => intdiv(time(), 60), // 今より前に始まる枠は出さない
                'studios' => array_map(static fn($s) => ['place' => 'studio:' . (int)$s['studio_id']], $studioById),
                'practices' => $practices, 'travel' => sched_travel_fn(sched_place_coords($pdo), sched_travel_overrides($pdo)),
            ];
            $windows = sched_candidates($calc + ['absent_max' => 0]); // まず、全員そろう候補
            // 全員そろう候補が少なく、部員が3人以上なら、「1人欠席」の候補も出す（2人だと、1人欠席は「1人練習」になってしまう）
            //   absent_max = 1 の結果には、全員そろう窓も混ざっているので、欠席のある窓（absent が空でないもの）だけ取り出す
            if (count($members) >= 3 && count($windows) < SCHED_ABSENT_BELOW) {
                $absentWindows = array_values(array_filter(sched_candidates($calc + ['absent_max' => 1]), static fn($w) => $w['absent']));
            }
        }
        // ---- メンバーの予定（週ごとの表の元データ）。授業・練習・それ以外は種類を丸めて送る（プライバシーのため）----
        //   「バイト」「カレンダー」「手入力」のどれなのかは、画面に出さない（まとめて「予定あり」）
        //   例外: 自分（$myMember）の iCal・画像から読み込んだ予定だけ、タイトルを見せる（他の人の行には、タイトルを絶対に出さない）
        $where = $canManage ? $practiceWhere($practices) : [];
        $overLabel = static fn(array $b, int $mid): string => match (true) {
            $b[3] === 'class' => '授業 ' . $b[4],
            $b[3] === 'practice' => ($where[$b[4] . '|' . $b[0]] ?? '') ?: '練習 ' . $b[4], // スタジオ名 部屋名（分からなければ「練習 題」）
            in_array($b[3], ['ical', 'photo'], true) && $mid === $myMember && $b[4] !== '' => $b[4],
            default => '予定あり',
        };
        //   見せるのは、この調整を操作できる人（作った人・呼ばれた部員・幹部）だけ。URL の id を変えただけの人に、他人の予定の時間帯を見せない
        if ($canManage) {
            $overBusy = [];
            foreach ($memberIds as $mid) {
                $overBusy[$mid] = array_map(static fn($b) => [$b[0], $b[1], $overLabel($b, (int)$mid), $b[3] === 'practice' ? 1 : 0], $data['busy'][$mid] ?? []); // 4つ目 = 練習か（表の色分け用）
            }
            $over = [
                'days' => array_map(static fn($d) => ['date' => $d, 'md' => sched_md($d), 'abs' => sched_day_abs($d), 'dow' => (int)date('w', strtotime($d))], sched_dates($from, $to)),
                'start' => (int)$poll['day_start'], 'end' => (int)$poll['day_end'], 'step' => SCHED_STEP,
                'busy' => (object)$overBusy,
            ];
        }
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
// 仮決定の練習の「スタジオ名 部屋名」（注意のポップアップ用。題 => 文字。同じ題が複数あれば最後のもの）
$warnWhere = [];
foreach ($practices ? $practiceWhere($practices) : [] as $k => $v) {
    $warnWhere[substr($k, 0, (int)strrpos($k, '|'))] = $v;
}
/** 窓 → 画面用（日付の表示・曜日区分を足し、スタジオは配列にする。late / adj は空でも {} になるよう (object) で包む） */
$winOut = static fn(array $w): array => [
    'date' => $w['date'], 'md' => sched_md($w['date']), 'day' => sched_day_type($w['date']),
    'starts' => $w['starts'], 'from' => $w['from'], 'to' => $w['to'],
    'lateMembers' => $w['late_members'], 'earlyMembers' => $w['early_members'], 'absent' => $w['absent'], 'warn' => array_map(static fn($t) => ['t' => $t, 'where' => $warnWhere[$t] ?? ''], $w['warn']), 'adj' => $w['adj'],
    'studios' => array_map(static fn($sid, $s) => [
        'id' => $sid, 'starts' => $s['starts'],
        'late' => (object)array_map(static fn($l) => (object)$l, $s['late']),
        'early' => (object)array_map(static fn($l) => (object)$l, $s['early']),
        'adj' => (object)$s['adj'],
    ], array_keys($w['studios']), array_values($w['studios'])),
];
$boot = [
    'poll' => $pollId, 'v' => (int)$poll['version'], 'status' => $status, 'len' => $len, 'people' => count($members), 'jo' => $settings['jo'],
    'canFix' => $canManage, 'csrf' => csrf_token(),
    'studios' => array_map(static fn($s) => ['id' => (int)$s['studio_id'], 'name' => $s['name'], 'area' => (string)$s['area'], 'reserve' => (string)($s['reserve_url'] ?: $s['website_url'])], array_values($studios)),
    'sim' => (object)$sim,
    'names' => (object)$names, // 遅刻・欠席の人の名前（member_id => 名前）
    'windows' => array_map($winOut, $windows),
    'absent' => array_map($winOut, $absentWindows),
    'over' => $over,
];

// ---- 仮決定・決定の表示 ----
$fixed = null;
if ($status >= 1) {
    [$fStudio, $fRoom, $location, $details] = $eventInfo();
    $fixed = ['studio' => $fStudio, 'room' => $fRoom, 'area' => $roomArea($poll['fix_room_id'] === null ? null : (int)$poll['fix_room_id'])];
    if ($status >= 2) {
        $s = sched_dt_to_abs($poll['fix_start']);
        $e = sched_dt_to_abs($poll['fix_end']);
        $fixed['when'] = sched_md((string)$poll['fix_date']) . ' ' . date('G:i', $s * 60) . '〜' . date('G:i', $e * 60);
        $fixed['gcal'] = sched_gcal_url('スタ連 ' . $poll['title'], $s, $e, $location, $details);
    } else {
        $fixed['when'] = sched_md((string)$poll['fix_date']);
    }
}
// ---- 予約済みの記録（決定のときだけ。「予約済み」のピルと、予約のうながしモーダルで使う）----
$reserve = $fixed !== null && $fixed['studio'] !== null ? (string)($fixed['studio']['reserve_url'] ?: $fixed['studio']['website_url']) : ''; // 予約ページ（無ければ公式サイト）
$reservedAt = $status === 3 ? $poll['reserved_at'] : null;
$reservedBy = ''; // 押した人の表示名（アカウントが消えていれば ''）
if ($reservedAt !== null && $poll['reserved_by'] !== null) {
    $st = $pdo->prepare('SELECT name FROM user_account WHERE user_id = ?');
    $st->execute([(int)$poll['reserved_by']]);
    $reservedBy = (string)($st->fetchColumn() ?: '');
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
<nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタジオ練習の日程調整</a></nav>
<div class="sch-head">
    <h1 class="display display--sm"><?= h($poll['title']) ?> <?= sched_status_pill($poll) ?></h1>
    <?php if ($canManage): ?><a class="btn btn--sm btn--ghost" href="sched?edit=<?= $pollId ?>"><?= icon('edit') ?> 編集</a><?php endif; ?>
</div>
<?php
// 向けのライブ（あれば、見出しの下にライブのページへのリンクを出す）
$live = null;
if (!empty($poll['live_id'])) {
    // 初日・最終日は日程（live_day）の最小・最大。集計（MIN/MAX）するので GROUP BY でライブ1行にまとめる
    $st = $pdo->prepare('SELECT l.live_id, l.name, MIN(d.held_on) AS first_on, MAX(d.held_on) AS last_on
        FROM live l LEFT JOIN live_day d ON d.live_id = l.live_id WHERE l.live_id = ? GROUP BY l.live_id, l.name');
    $st->execute([(int)$poll['live_id']]);
    $live = $st->fetch() ?: null;
}
?>
<p class="muted small sch-meta">
    <?php if ($live !== null):
        $when = $live['first_on'] === null ? '' : ' ' . sched_md((string)$live['first_on']) . ($live['last_on'] !== $live['first_on'] ? '〜' . sched_md((string)$live['last_on']) : ''); ?>
        <a href="live?id=<?= (int)$live['live_id'] ?>"><?= h($live['name'] . $when) ?></a> ・
    <?php endif; ?>
    <?= h(sched_md($poll['date_from']) . '〜' . sched_md($poll['date_to'])) ?> ・ <?= h(sched_hm((int)$poll['day_start']) . '〜' . sched_hm((int)$poll['day_end'])) ?>
    ・ <?= h(studio_fmt_hours($len)) ?><?= (int)$poll['late_max'] > 0 ? ' ・ 遅刻' . (int)$poll['late_max'] . '分まで' : '' ?>
</p>

<?php if ($fixed !== null): ?>
    <section class="card sch-fixed<?= $status === 3 ? ' is-final' : '' ?>">
        <div class="sch-fixed__when"><?= icon($status === 3 ? 'event_available' : 'schedule') ?> <strong><?= h($fixed['when']) ?></strong>
            <?php if ($reservedAt !== null): ?>
                <span class="pill pill--ok sch-fixed__reserved" <?= tip_attr(trim($reservedBy . ' ' . date('n/j G:i', strtotime((string)$reservedAt)))) ?>>予約済み</span>
            <?php endif; ?>
        </div>
        <?php if ($fixed['studio'] !== null): ?>
            <div class="sch-fixed__where"><?= h($fixed['studio']['name']) ?><?= $fixed['room'] !== '' ? ' ' . h($fixed['room']) : '' ?><?= $fixed['area'] !== '' ? ' ' . h($fixed['area']) : '' ?></div>
        <?php endif; ?>
        <?php $absentNames = array_column(array_filter($members, static fn($m) => (int)$m['fix_absent'] === 1), 'name'); ?>
        <?php if ($absentNames): ?>
            <div class="sch-fixed__absent">欠席: <?= h(implode('・', $absentNames)) ?></div>
        <?php endif; ?>
        <div class="sch-fixed__actions">
            <?php if (isset($fixed['gcal'])): ?>
                <a class="btn btn--sm" href="<?= h($fixed['gcal']) ?>" target="_blank" rel="noopener"><?= icon('event') ?> Googleカレンダー</a>
                <a class="btn btn--sm" href="sched_poll?id=<?= $pollId ?>&amp;ics=1"><?= icon('download') ?> .ics</a>
            <?php endif; ?>
            <?php if ($reserve !== '' && $status >= 2): ?>
                <a class="btn btn--sm btn--primary" href="<?= h($reserve) ?>" target="_blank" rel="noopener"><?= icon('open_in_new') ?> 予約ページ</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" data-confirm="<?= $status === 3 ? '決定' : '仮決定' ?>を取り消して調整中に戻します。よろしいですか？">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit">取り消し</button>
                </form>
                <?php if ($reservedAt !== null): ?>
                    <form method="post" data-confirm="予約済みを取り消します。よろしいですか？">
                        <?= csrf_field() ?><input type="hidden" name="action" value="unreserve"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>">
                        <button class="btn btn--sm btn--ghost" type="submit">予約済みを取り消し</button>
                    </form>
                <?php endif; ?>
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
    <?php if ($over !== null): ?>
        <?php /* メンバーの予定: 中身は assets/sched.js の setupOver が作る。開閉は localStorage に覚える */ ?>
        <details class="card sch-over" data-sched-over data-tip-lazy>
            <summary><?= icon('groups') ?> メンバーの予定</summary>
            <div class="sch-over__body">
                <div class="sch-weeknav">
                    <button type="button" class="icon-btn" data-over-prev aria-label="前の週" title="前の週"><?= icon('chevron_left') ?></button>
                    <strong data-over-range></strong>
                    <button type="button" class="icon-btn" data-over-next aria-label="次の週" title="次の週"><?= icon('chevron_right') ?></button>
                </div>
                <div class="sch-legend small muted">
                    <span><i class="sch-sw is-free"></i>全員空き</span><span><i class="sch-sw is-free is-cheap"></i>最安</span><span><i class="sch-sw is-n1"></i>1人予定あり</span><span><i class="sch-sw is-n2"></i>2人以上予定あり</span><span><i class="sch-sw is-sp"></i>スタジオ練習</span>
                </div>
                <div class="sch-grid-wrap" data-sched-over-grid></div>
            </div>
        </details>
    <?php endif; ?>
    <section class="sch-cands" data-sched-cands>
        <div class="sch-cands__head">
            <h2 class="section-title">候補 <small class="muted"><?= count(array_unique(array_column($windows, 'date'))) ?>日</small></h2>
            <div class="tabs tabs--filter sch-sort" role="tablist" data-sched-sort>
                <button type="button" class="tab is-active" data-sort="date">日付順</button>
                <button type="button" class="tab" data-sort="best">おすすめ順</button>
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
        <?php if ($absentWindows): ?>
            <h2 class="section-title">1人欠席の候補 <small class="muted"><?= count(array_unique(array_column($absentWindows, 'date'))) ?>日</small></h2>
            <div class="sch-list" data-sched-list-absent></div>
        <?php endif; ?>
        <p class="muted small">料金は目安です。スタジオの空き状況は予約ページで確認してください</p>
    </section>
    <script type="application/json" id="sched-data"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="<?= asset_url('assets/studio_sim.js') ?>" defer></script>
    <script src="<?= asset_url('assets/sched.js') ?>" defer></script>
<?php endif; ?>

<?php if ($status === 3 && $poll['reserved_at'] === null && $canManage && $fixed['studio'] !== null): ?>
    <?php /* 予約のうながし: 決定して未予約のあいだ、開くたびに自動で出る（data-auto-open → app.js の setupAutoDialog）。スマホは全画面で、アプリ履歴を見ても予約先がわかる */ ?>
    <dialog class="modal sch-reserve" data-auto-open aria-labelledby="sch-reserve-title">
        <form method="dialog"><button class="icon-btn sch-reserve__close" aria-label="閉じる" title="閉じる"><?= icon('close') ?></button></form>
        <div class="sch-reserve__body">
            <h2 class="sch-reserve__studio" id="sch-reserve-title"><?= h($fixed['studio']['name']) ?></h2>
            <div class="sch-reserve__when"><?= h($fixed['when']) ?></div>
            <?php if ($fixed['room'] !== '' || $fixed['area'] !== ''): ?>
                <div class="sch-reserve__room"><?= h(trim($fixed['room'] . ' ' . $fixed['area'])) ?></div>
            <?php endif; ?>
            <div class="sch-reserve__actions">
                <?php if ($reserve !== ''): ?>
                    <a class="btn btn--primary" href="<?= h($reserve) ?>" target="_blank" rel="noopener"><?= icon('open_in_new') ?> 予約ページ</a>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reserve"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>">
                    <button class="btn<?= $reserve === '' ? ' btn--primary' : '' ?>" type="submit"><?= icon('check') ?> 予約完了</button>
                </form>
            </div>
        </div>
    </dialog>
<?php endif; ?>
<?php render_footer();
