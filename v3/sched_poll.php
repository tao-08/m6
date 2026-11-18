<?php
/**
 * =====================================================================
 *  sched_poll.php — 日程調整1件（候補・回答状況・仮決定・決定）
 * =====================================================================
 *  GET
 *    sched_poll?id=12            … 候補の一覧（料金・連続・遅刻つき）と、決定した練習（1つの調整で、別々の日に何回でも決められる）
 *    sched_poll?id=12&len=90     … 候補の練習の長さ（分）を変えて見る（30分刻み。外れた値は、作成時の長さ）
 *    sched_poll?id=12&ics=5      … 決定1回（sched_fix_id = 5）の .ics ファイル（カレンダーに追加）
 *  POST（CSRF チェック）
 *    answer … 自分の「回答済み」を切り替える（呼ばれた部員だけ）
 *    fix    … kind = date（日だけ仮決定）/ time（日時まで仮決定）/ final（決定）。absent = 欠席する部員の id（0 = なし）。len = 練習の長さ
 *             同じ日にすでに決定があれば、その行を更新する（1つの調整で1日1回まで）
 *    reopen … fix = 決定1回の id。その1回だけ取り消す（Google の予定も消す）
 *    reserve   … fix[] = 決定の id（複数可）。決定（status = 3）したものを「予約済み」にする（reserved_at / reserved_by に記録。通知は送らない）
 *    unreserve … fix = 決定1回の id。予約済みを取り消す（決定はそのまま）。fix をし直すと、その回の予約済みは未予約に戻る
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
require_once __DIR__ . '/lib/sched_fare.php';   // 定期外の交通費（NAVITIME + Google。結果は DB に覚える）
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

/** メンバーの交通費の元（自宅最寄り駅・定期・キャンパス）。[member_id => ['campus', 'campus2', 'home_station', 'pass_to', …]]（campus2 = サブのキャンパス。無ければ null） */
$fareProfiles = [];
if ($memberIds) {
    $in = implode(',', array_fill(0, count($memberIds), '?'));
    $st = $pdo->prepare("SELECT m.member_id, m.entry_year, m.faculty, p.campus, p.campus2, p.home_station, p.pass_to, p.pass_via, p.pass_on FROM member m LEFT JOIN sched_profile p ON p.member_id = m.member_id WHERE m.member_id IN ($in)");
    $st->execute($memberIds);
    foreach ($st as $r) {
        $mainCampus = sched_member_campus($r['campus'] === null ? null : (int)$r['campus'], $r['entry_year'] === null ? null : (int)$r['entry_year'], $r['faculty'] === null ? null : (string)$r['faculty']);
        $fareProfiles[(int)$r['member_id']] = [
            'campus' => $mainCampus,
            // サブのキャンパス。メインと同じ・未設定なら null（= サブなし）
            'campus2' => $r['campus2'] !== null && isset(SCHED_CAMPUSES[(int)$r['campus2']]) && (int)$r['campus2'] !== $mainCampus ? (int)$r['campus2'] : null,
            'home_station' => (string)$r['home_station'], 'pass_to' => (string)$r['pass_to'], 'pass_via' => (string)$r['pass_via'],
            'pass_on' => $r['pass_on'] === null ? 1 : (int)$r['pass_on'],
        ];
    }
}
$placeCoords = sched_place_coords($pdo);
$transferMin = (int)sched_settings($pdo)['transfer_min'];
/** 画面用: 覚えてある結果だけで、メンバー・出発地・スタジオごとの片道の運賃（円。不明は null） */
$fareOf = static function (int $mid, string $origin, int $sid, bool $fetch = false) use ($pdo, $fareProfiles, $placeCoords, $studioById, $transferMin): ?int {
    static $memo = [];
    $k = $mid . '|' . $origin . '|' . $sid;
    if (!$fetch && array_key_exists($k, $memo)) {
        return $memo[$k];
    }
    $coord = $placeCoords['studio:' . $sid] ?? null;
    $prof = $fareProfiles[$mid] ?? null;
    if ($coord === null || $prof === null) {
        return $memo[$k] = null;
    }
    try {
        // 行き先はスタジオの最寄り駅（複数あれば所要時間が短い方。同じ駅のスタジオは結果を共有する）
        $dest = sched_studio_dests($pdo, $coord, (string)($studioById[$sid]['access'] ?? ''), $fetch);
    } catch (SchedNavMiss $e) {
        return $memo[$k] = null;
    }
    return $memo[$k] = sched_member_fare($pdo, $prof, $origin, $dest, $transferMin, $fetch);
};
/** 自宅の駅を登録した人 × 候補のスタジオ × 出発地（自宅・メインのキャンパス・サブのキャンパス）ごとに $fn を呼ぶ。出発地は 'home' か授業の場所キー（'campus:和泉' など） */
$fareEach = static function (callable $fn) use ($fareProfiles, $studioById): void {
    foreach ($fareProfiles as $mid => $prof) {
        if ($prof['home_station'] === '') {
            continue;
        }
        foreach (array_keys($studioById) as $sid) {
            $origins = ['home', 'campus:' . SCHED_CAMPUSES[$prof['campus']]];
            if ($prof['campus2'] !== null) {
                $origins[] = 'campus:' . SCHED_CAMPUSES[$prof['campus2']];
            }
            foreach ($origins as $origin) {
                $fn((int)$mid, $origin, (int)$sid);
            }
        }
    }
};

/* ---- 決定（sched_fix）。1つの調整で、別々の日に何回でも決められる ---- */
$fixes = sched_poll_fixes($pdo, $pollId);   // 日付順
$status = sched_poll_state($fixes);         // 調整全体の状態。0 = 調整中 / 2 = 仮決定がある / 3 = 決定が1つでもある
$finalFixes = array_values(array_filter($fixes, static fn($f) => (int)$f['status'] === 3));
$finalDates = array_column($finalFixes, 'fix_date'); // 決定した日（この日は候補から外す。同じ日にもう1回は無い）

/** 候補の画面で増減した練習の長さ（分）。検証は sched_poll_len（30分刻み・時間帯に収まる範囲。外れたら作成時の長さ） */
$lenBase = (int)$poll['length_min'];
$lenMax = (int)$poll['day_end'] - (int)$poll['day_start'];
$len = sched_poll_len($poll, $_POST['len'] ?? $_GET['len'] ?? null);

/** 決定に出てくるスタジオ・部屋（決定の数だけ SELECT しないよう、1回ずつまとめて読む） */
$fixStudios = []; // [studio_id => 行]
$fixRooms = [];   // [studio_room_id => ['name', 'area_jo']]
$fixSids = array_values(array_unique(array_map('intval', array_filter(array_column($fixes, 'studio_id'), static fn($v) => $v !== null))));
$fixRids = array_values(array_unique(array_map('intval', array_filter(array_column($fixes, 'room_id'), static fn($v) => $v !== null))));
if ($fixSids) {
    // 短縮形（short_name）まで要るので、スタジオ表から直接読む（候補から外したあとでも決定は残る）
    $st = $pdo->prepare('SELECT studio_id, name, short_name, address, reserve_url, website_url FROM studio WHERE studio_id IN (' . implode(',', array_fill(0, count($fixSids), '?')) . ')');
    $st->execute($fixSids);
    $fixStudios = array_column($st->fetchAll(), null, 'studio_id');
}
if ($fixRids) {
    $st = $pdo->prepare('SELECT studio_room_id, name, area_jo FROM studio_room WHERE studio_room_id IN (' . implode(',', array_fill(0, count($fixRids), '?')) . ')');
    $st->execute($fixRids);
    $fixRooms = array_column($st->fetchAll(), null, 'studio_room_id');
}

/** 決定・仮決定した部屋の名前（無ければ ''） */
$roomName = static fn(?int $roomId): string => $roomId === null ? '' : (string)($fixRooms[$roomId]['name'] ?? '');

/** 決定・仮決定した部屋の広さ（"12帖"。部屋が無い・広さ不明なら ''）。画面に出すだけ（カレンダーの「場所」には入れない） */
$roomArea = static fn(?int $roomId): string => $roomId === null ? '' : studio_fmt_jo($fixRooms[$roomId]['area_jo'] ?? null);

/** カレンダーの予定に入れる「タイトル・場所・メモ」（決定1回ぶん）。見ている人の書式（マイスケジュールの「練習の書式」）で作る。戻り値: [スタジオ, 部屋, 場所, メモ, タイトル] */
$eventInfo = static function (array $fx) use ($poll, $members, $roomName, $fixStudios, $pdo, $myMember): array {
    static $fmt = null; // 書式は人ごとに1つ。決定の数だけ読まない
    $fmt ??= sched_event_format($pdo, $myMember);
    $sid = $fx['studio_id'] === null ? null : (int)$fx['studio_id'];
    $studio = $sid !== null ? ($fixStudios[$sid] ?? null) : null;
    $room = $roomName($fx['room_id'] === null ? null : (int)$fx['room_id']);
    // その回に欠席の人はメンバーに入れない（欠席は1回につき最大1人）
    $present = array_filter($members, static fn($m) => (int)$m['member_id'] !== (int)$fx['absent_member_id']);
    $text = sched_event_text($fmt, [
        'poll_id' => (int)$poll['sched_poll_id'], 'title' => (string)$poll['title'], 'studio_id' => $sid, 'studio' => $studio['name'] ?? null,
        'studio_short' => $studio['short_name'] ?? null, 'room' => $room, 'address' => $studio['address'] ?? null, 'names' => array_column($present, 'name'),
    ]);
    return [$studio, $room, $text['location'], $text['details'], $text['title']];
};

/* ==================== .ics のダウンロード ==================== */
if (isset($_GET['ics'])) {
    // ics = 決定1回の id。この調整の決定だけ（ほかの調整の id を書き換えて送られても通さない）
    $icsFix = sched_fix_load($pdo, (int)$_GET['ics'], $pollId);
    if ($icsFix === null || (int)$icsFix['status'] < 2 || $icsFix['fix_start'] === null) {
        not_found_page('まだ日時が決まっていません', 'sched_poll?id=' . $pollId);
    }
    [, , $location, $details, $evTitle] = $eventInfo($icsFix);
    header('Content-Type: text/calendar; charset=utf-8');
    // attachment … ブラウザに「開く」ではなく「保存（またはカレンダーで開く）」として渡す
    header('Content-Disposition: attachment; filename="sched-' . (int)$icsFix['sched_fix_id'] . '.ics"');
    echo sched_ics((int)$icsFix['sched_fix_id'], $evTitle, sched_dt_to_abs($icsFix['fix_start']), sched_dt_to_abs($icsFix['fix_end']), $location, $details);
    exit;
}

/* ==================== 操作 ==================== */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    // 戻り先。長さを変えて見ていたときは、その長さのまま戻る（続けて別の日を決めやすいように）
    $back = 'sched_poll?id=' . $pollId . ($len !== $lenBase ? '&len=' . $len : '');

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

    if ($action === 'fare_fetch') {
        // 交通費の元データを調べる（NAVITIME・Google の API を呼ぶ。結果は180日覚える。覚えてある分は呼ばない）。自宅の駅を登録している人だけ
        try {
            $fareEach(static fn(int $mid, string $origin, int $sid) => $fareOf($mid, $origin, $sid, true));
            flash('交通費を調べました');
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect($back);
    }

    if ($action === 'reserve') {
        // 決定（status = 3）した練習だけ「予約済み」にできる。fix[] = 決定の id（複数まとめて押せる）
        //   WHERE にも status = 3 とこの調整の id を入れて、確認から押すまでの間に決定が外れても・別の調整の id を送られても通さない
        $ids = [];
        foreach ((array)($_POST['fix'] ?? []) as $v) {
            if (is_string($v) && ctype_digit($v) && (int)$v > 0) {
                $ids[(int)$v] = (int)$v; // キーも id にして、重複を1つにする
            }
        }
        $ids = array_values($ids);
        $n = 0;
        if ($ids) {
            $up = $pdo->prepare('UPDATE sched_fix SET reserved_at = NOW(), reserved_by = ? WHERE sched_poll_id = ? AND status = 3 AND sched_fix_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $up->execute([(int)$user['user_id'], $pollId, ...$ids]);
            $n = $up->rowCount();
        }
        if ($n === 0) {
            flash('まだ決定していません', 'error');
            redirect($back);
        }
        $pdo->prepare('UPDATE sched_poll SET version = version + 1 WHERE sched_poll_id = ?')->execute([$pollId]);
        flash('予約済みにしました');
        redirect($back);
    }

    if ($action === 'unreserve') {
        // 予約済みの取り消し（決定はそのまま。予約のうながしがまた出る）。1回ぶんだけ
        $fixId = (int)($_POST['fix'] ?? 0);
        $pdo->prepare('UPDATE sched_fix SET reserved_at = NULL, reserved_by = NULL WHERE sched_fix_id = ? AND sched_poll_id = ?')
            ->execute([$fixId, $pollId]);
        $pdo->prepare('UPDATE sched_poll SET version = version + 1 WHERE sched_poll_id = ?')->execute([$pollId]);
        flash('予約済みを取り消しました');
        redirect($back);
    }

    if ($action === 'reopen') {
        // 決定1回だけを取り消す（ほかの日の決定はそのまま。決定が1つも無くなれば、調整全体が「調整中」に戻る）
        $gone = sched_fix_load($pdo, (int)($_POST['fix'] ?? 0), $pollId);
        if ($gone === null) {
            flash('すでに取り消されています', 'error');
            redirect($back);
        }
        // 先に Google カレンダーから予定を消す（DELETE すると sched_google_event の行も CASCADE で消えて、どの予定を消せばいいかわからなくなる）
        //   失敗しても、ここの操作は成功のまま
        sched_google_remove_fix($pdo, (int)$gone['sched_fix_id']);
        // 決定の削除と version の更新は、1つのトランザクションに（片方だけ成功する状態を作らない）
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM sched_fix WHERE sched_fix_id = ? AND sched_poll_id = ?')->execute([(int)$gone['sched_fix_id'], $pollId]);
            $pdo->prepare('UPDATE sched_poll SET version = version + 1 WHERE sched_poll_id = ?')->execute([$pollId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $goneMd = sched_md((string)$gone['fix_date']);
        sched_notify($pdo, $pollId, $poll['title'] . ' の ' . $goneMd . ' の練習が取り消されました', '[[sched_poll?id=' . $pollId . '|候補]]から選び直してください', (int)$user['user_id']);
        flash($goneMd . ' の' . ((int)$gone['status'] === 3 ? '決定' : '仮決定') . 'を取り消しました');
        redirect($back);
    }

    if ($action === 'fix') {
        $kind = (string)($_POST['kind'] ?? '');
        $date = (string)($_POST['date'] ?? '');
        $start = (int)($_POST['start'] ?? -1);
        $sid = (int)($_POST['studio'] ?? 0);
        $roomId = (int)($_POST['room'] ?? 0);
        $absentId = (int)($_POST['absent'] ?? 0); // 欠席する人（member_id。0 = なし）
        // 練習の長さは、画面で増減した値（$len）。sched_poll_len が 30分刻み・時間帯に収まる範囲かを検証済み
        // 期間・時間帯・候補のスタジオの中か（画面を書き換えて範囲外を送られても通さない）。日付は「YYYY-MM-DD」の実在する日だけ
        $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && date('Y-m-d', (int)strtotime($date)) === $date;
        $okRange = in_array($kind, ['date', 'time', 'final'], true) && $okDate && $date >= $poll['date_from'] && $date <= $poll['date_to']
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
                // 同じ調整のほかの日の決定は関係ない（別の日なので）。ほかの調整の決定は、埋まりとして数える（$pollId を渡すと、この調整の分は除かれる）
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
                // 調整の version と決定の保存は、同じトランザクションで（片方だけ保存される状態を作らない）
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare('UPDATE sched_poll SET version = version + 1 WHERE sched_poll_id = ? AND version = ?');
                    $up->execute([$pollId, (int)$poll['version']]);
                    if ($up->rowCount() === 0) {
                        // version が変わっていた = 確認中に、ほかの人が先に変更した（楽観ロックの失敗）
                        $pdo->rollBack();
                        $fail = 'ほかの人が先に変更しました。もう一度確認してください';
                    } else {
                        // 同じ日（UNIQUE (sched_poll_id, fix_date)）にすでに決定があれば、その行を更新する（仮決定 → 決定、時刻の変更など。予約済みは未予約に戻す）
                        //   ON DUPLICATE KEY UPDATE … 同じキーの行があれば INSERT のかわりに UPDATE する。VALUES(列) は INSERT しようとした値
                        $pdo->prepare('INSERT INTO sched_fix (sched_poll_id, status, fix_date, fix_start, fix_end, studio_id, room_id, absent_member_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE status = VALUES(status), fix_start = VALUES(fix_start), fix_end = VALUES(fix_end), studio_id = VALUES(studio_id),
                                room_id = VALUES(room_id), absent_member_id = VALUES(absent_member_id), reserved_at = NULL, reserved_by = NULL')
                            ->execute([
                                $pollId, $statusNew, $date,
                                $kind === 'date' ? null : sched_abs_to_dt($t0),
                                $kind === 'date' ? null : sched_abs_to_dt($t0 + $len),
                                $kind === 'date' ? null : $sid,
                                $kind === 'date' || $roomId === 0 ? null : $roomId,
                                $absentId > 0 ? $absentId : null,
                            ]);
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
        // 保存した決定1回の id（INSERT でも UPDATE でも同じ日の行は1つ）
        $st = $pdo->prepare('SELECT sched_fix_id FROM sched_fix WHERE sched_poll_id = ? AND fix_date = ?');
        $st->execute([$pollId, $date]);
        $savedFixId = (int)$st->fetchColumn();
        sched_google_sync_fix($pdo, $savedFixId); // 決定（status = 3）なら Google 連携中の部員のカレンダーに入れる・直す。仮決定なら（前の決定が残っていれば）消す。欠席の人の分も合わせる
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

// ---- 候補の計算（決定があっても、候補は常に出す。決定した日だけ外す）----
$windows = [];       // 全員そろう候補
$absentWindows = []; // 1人欠席の候補
$over = null;        // メンバーの予定
$practices = [];
{
    // 古くなった予定を読み直す（時間で区切るので、表示は最大でも数秒）。Google 連携中の人は Google から、それ以外の人は iCal から。
    //   全体の上限は 6 秒のまま（Google に使った分を引いた残りを、iCal に回す）
    $used = sched_google_refresh_many($pdo, $memberIds);
    sched_ical_refresh_many($pdo, $memberIds, max(1, 6 - (int)ceil($used)));
    // 過去の日は候補にしない（今日の、もう過ぎた時間も）
    $from = max((string)$poll['date_from'], date('Y-m-d'));
    $to = (string)$poll['date_to'];
    if ($from <= $to) {
        $data = sched_collect_busy($pdo, $memberIds, $from, $to, $pollId, false, $myMember); // 最後 = 自分の iCal の予定のタイトルだけ復号する（他の人の分は '' のまま）
        $practices = $data['practices'];
        if ($studios) {
            $calc = [
                // 決定（3）した日は候補にしない（同じ日にもう1回は無い）。仮決定の日は残す（そこから時間を選んで決定に上げるため）
                'dates' => array_values(array_diff(sched_dates($from, $to), $finalDates)),
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
        // ---- 交通費（覚えてある分だけ）。開始時刻ごとに、全員の定期外の運賃の合計を窓のスタジオに持たせる ----
        $addFares = static function (array &$ws) use ($fareOf, $data, $len, $memberIds, $settings): void {
            foreach ($ws as &$w) {
                foreach ($w['studios'] as $sid => &$s) {
                    $s['fare'] = [];
                    foreach ($s['starts'] as $m) {
                        $sum = 0;
                        $miss = 0;
                        foreach ($memberIds as $mid) {
                            if (in_array($mid, $w['absent'], true)) {
                                continue;
                            }
                            foreach (sched_fare_legs($data['busy'][$mid] ?? [], (int)$m, $len, (int)$settings['fare_gap']) as $origin) {
                                $f = $fareOf($mid, $origin, (int)$sid);
                                $f === null ? $miss++ : $sum += $f;
                            }
                        }
                        $s['fare'][$m] = ['sum' => $sum, 'miss' => $miss];
                    }
                }
                unset($s);
            }
            unset($w);
        };
        $addFares($windows);
        $addFares($absentWindows);
        // ---- メンバーの予定（週ごとの表の元データ）。授業・練習・それ以外は種類を丸めて送る（プライバシーのため）----
        //   「バイト」「カレンダー」「手入力」のどれなのかは、画面に出さない（まとめて「予定あり」）
        //   例外: 自分（$myMember）の iCal・画像から読み込んだ予定だけ、タイトルを見せる（他の人の行には、タイトルを絶対に出さない）
        $where = $canManage ? $practiceWhere($practices) : [];
        $overLabel = static fn(array $b, int $mid): string => match (true) {
            $b[3] === 'class' => '授業 ' . $b[4],
            $b[3] === 'practice' => $where[$b[4] . '|' . $b[0]] ?? '', // スタジオ名 部屋名（分からなければ空。題は5つ目で渡す）
            in_array($b[3], ['ical', 'photo'], true) && $mid === $myMember && $b[4] !== '' => $b[4],
            default => '予定あり',
        };
        //   見せるのは、この調整を操作できる人（作った人・呼ばれた部員・幹部）だけ。URL の id を変えただけの人に、他人の予定の時間帯を見せない
        if ($canManage) {
            $overBusy = [];
            foreach ($memberIds as $mid) {
                $overBusy[$mid] = array_map(static fn($b) => [$b[0], $b[1], $overLabel($b, (int)$mid), $b[3] === 'practice' ? 1 : 0, $b[3] === 'practice' ? $b[4] : ''], $data['busy'][$mid] ?? []); // 4つ目 = 練習か（表の色分け用）/ 5つ目 = 練習の題（ポップアップ用）
            }
            // この調整の決定（3）も、練習として表に出す（sched_collect_busy はこの調整を除くので。決定した日が「全員空き」に見えないように）。欠席の人には出さない
            foreach ($finalFixes as $fx) {
                $d = (string)$fx['fix_date'];
                if ($fx['fix_start'] === null || $fx['fix_end'] === null || $d < $from || $d > $to) {
                    continue;
                }
                $fWhere = trim((string)($fixStudios[(int)$fx['studio_id']]['name'] ?? '') . ' ' . $roomName($fx['room_id'] === null ? null : (int)$fx['room_id']));
                foreach (sched_fix_present($fx, $memberIds) as $mid) {
                    $overBusy[$mid][] = [sched_dt_to_abs($fx['fix_start']), sched_dt_to_abs($fx['fix_end']), $fWhere, 1, (string)$poll['title']];
                }
            }
            // 仮決定（日だけ・時間まで）: ほかの調整の分（部員が重なるもの）と、この調整の分（全部の回）。日だけは日付の下に題名、時間まではマスに色を重ねる
            $prov = [];
            foreach ($practices as $pr) {
                if ((int)$pr['status'] < 3 && array_intersect($pr['members'], $memberIds)) {
                    $prov[] = ['date' => $pr['date'], 's' => $pr['s'], 'e' => $pr['e'], 'title' => $pr['title'], 'where' => $pr['s'] === null ? '' : ($where[$pr['title'] . '|' . $pr['s']] ?? ''), 'members' => array_values(array_intersect($pr['members'], $memberIds))];
                }
            }
            foreach ($fixes as $fx) {
                $d = (string)$fx['fix_date'];
                if (!in_array((int)$fx['status'], [1, 2], true) || $d < $from || $d > $to) {
                    continue;
                }
                $timed = (int)$fx['status'] === 2 && $fx['fix_start'] !== null && $fx['fix_end'] !== null;
                $fWhere = $timed ? trim((string)($fixStudios[(int)$fx['studio_id']]['name'] ?? '') . ' ' . $roomName($fx['room_id'] === null ? null : (int)$fx['room_id'])) : '';
                $prov[] = [
                    'date' => $d, 's' => $timed ? sched_dt_to_abs($fx['fix_start']) : null, 'e' => $timed ? sched_dt_to_abs($fx['fix_end']) : null,
                    'title' => (string)$poll['title'], 'where' => $fWhere, 'members' => sched_fix_present($fx, $memberIds), // 欠席する人は入れない
                ];
            }
            // 表は月曜〜日曜の7日ずつ（左端は月曜。マイスケジュールと同じ）。調整の期間の外の日は off（グレー・押せない）
            $overFrom = date('Y-m-d', strtotime($from . ' -' . ((int)date('N', strtotime($from)) - 1) . ' day'));
            $overTo = date('Y-m-d', strtotime($to . ' +' . (7 - (int)date('N', strtotime($to))) . ' day'));
            $over = [
                'days' => array_map(static fn($d) => ['date' => $d, 'md' => sched_md($d), 'abs' => sched_day_abs($d), 'dow' => (int)date('w', strtotime($d)), 'off' => $d < $from || $d > $to], sched_dates($overFrom, $overTo)),
                'start' => (int)$poll['day_start'], 'end' => (int)$poll['day_end'], 'step' => SCHED_STEP,
                'busy' => (object)$overBusy, 'prov' => $prov,
            ];
        }
    }
}

// ---- 料金の計算データ（JS: studioSimEngine） ----
$sim = [];
foreach ($studios as $s) {
    $sid = (int)$s['studio_id'];
    $sim[$sid] = studio_sim_load($pdo, $sid);
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
        'fare' => (object)($s['fare'] ?? []), // 開始の分 => ['sum' => 全員の運賃の合計, 'miss' => 不明の片道の数]
    ], array_keys($w['studios']), array_values($w['studios'])),
];
/** スタジオごとの、自宅からの片道の所要時間の最長（分。覚えてある分だけ。だれも分からなければ null） */
$tripMax = static function (int $sid) use ($pdo, $fareProfiles, $placeCoords, $studioById, $transferMin): ?int {
    $coord = $placeCoords['studio:' . $sid] ?? null;
    if ($coord === null) {
        return null;
    }
    try {
        $dests = sched_studio_dests($pdo, $coord, (string)($studioById[$sid]['access'] ?? ''), false);
    } catch (SchedNavMiss $e) {
        return null;
    }
    $mins = [];
    foreach ($fareProfiles as $prof) {
        $t = sched_member_trip($pdo, $prof, $dests, $transferMin, false);
        if ($t !== null) {
            $mins[] = $t['min'];
        }
    }
    return $mins ? max($mins) : null;
};
$boot = [
    'poll' => $pollId, 'v' => (int)$poll['version'], 'len' => $len, 'people' => count($members), 'jo' => $settings['jo'],
    // 決定した日（その日の候補には「日だけ仮決定」を出さない）
    'fixes' => array_map(static fn($f) => ['id' => (int)$f['sched_fix_id'], 'date' => (string)$f['fix_date'], 'status' => (int)$f['status']], $fixes),
    'canFix' => $canManage, 'csrf' => csrf_token(),
    'studios' => array_map(static fn($s) => ['id' => (int)$s['studio_id'], 'name' => $s['name'], 'area' => (string)$s['area'], 'reserve' => (string)($s['reserve_url'] ?: $s['website_url']), 'tmax' => $tripMax((int)$s['studio_id'])], array_values($studios)),
    'sim' => (object)$sim,
    'names' => (object)$names, // 遅刻・欠席の人の名前（member_id => 名前）
    'windows' => array_map($winOut, $windows),
    'absent' => array_map($winOut, $absentWindows),
    'over' => $over,
];

// ---- 仮決定・決定の表示（決定1回 = カード1枚。日付順）----
$reservedByIds = array_values(array_unique(array_map('intval', array_filter(array_column($finalFixes, 'reserved_by'), static fn($v) => $v !== null))));
$reservedByName = []; // [user_id => 表示名]（アカウントが消えていれば無い）
if ($reservedByIds) {
    $st = $pdo->prepare('SELECT user_id, name FROM user_account WHERE user_id IN (' . implode(',', array_fill(0, count($reservedByIds), '?')) . ')');
    $st->execute($reservedByIds);
    $reservedByName = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$cards = [];
foreach ($fixes as $fx) {
    [$fStudio, $fRoom, $location, $details, $evTitle] = $eventInfo($fx);
    $fStatus = (int)$fx['status'];
    $card = [
        'id' => (int)$fx['sched_fix_id'], 'status' => $fStatus, 'studio' => $fStudio, 'room' => $fRoom,
        'area' => $roomArea($fx['room_id'] === null ? null : (int)$fx['room_id']),
        'reserve' => $fStudio !== null ? (string)($fStudio['reserve_url'] ?: $fStudio['website_url']) : '', // 予約ページ（無ければ公式サイト）
        // 予約済みの記録（決定のときだけ。「予約済み」のピルと、予約のうながしモーダルで使う）
        'reservedAt' => $fStatus === 3 ? $fx['reserved_at'] : null, 'reservedBy' => '',
        'absent' => $fx['absent_member_id'] === null ? '' : (string)($names[(int)$fx['absent_member_id']] ?? ''),
    ];
    if ($card['reservedAt'] !== null && $fx['reserved_by'] !== null) {
        $card['reservedBy'] = (string)($reservedByName[(int)$fx['reserved_by']] ?? '');
    }
    if ($fStatus >= 2 && $fx['fix_start'] !== null && $fx['fix_end'] !== null) {
        $s = sched_dt_to_abs($fx['fix_start']);
        $e = sched_dt_to_abs($fx['fix_end']);
        $card['when'] = sched_md((string)$fx['fix_date']) . ' ' . date('G:i', $s * 60) . '〜' . date('G:i', $e * 60);
        $card['gcal'] = sched_gcal_url($evTitle, $s, $e, $location, $details);
    } else {
        $card['when'] = sched_md((string)$fx['fix_date']);
    }
    $cards[] = $card;
}
// ---- 予約のうながし: 未予約の決定を、スタジオごとにまとめ、最も早い日を含むスタジオのグループだけ出す ----
//   $cards は日付順なので、先頭の未予約が「最も早い日」。同じスタジオの未予約は、日が離れていても1回にまとめる（予約ページを1回開けば足りる）
$reserveGroup = [];
if ($canManage) {
    $unreserved = array_values(array_filter($cards, static fn($c) => $c['status'] === 3 && $c['reservedAt'] === null && $c['studio'] !== null));
    if ($unreserved) {
        $firstSid = (int)$unreserved[0]['studio']['studio_id'];
        $reserveGroup = array_values(array_filter($unreserved, static fn($c) => (int)$c['studio']['studio_id'] === $firstSid));
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
<nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタジオ練習の日程調整</a></nav>
<div class="sch-head">
    <h1 class="display display--sm"><?= h($poll['title']) ?> <?= sched_status_pill($fixes) ?></h1>
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

<?php foreach ($cards as $c): ?>
    <section class="card sch-fixed<?= $c['status'] === 3 ? ' is-final' : '' ?>">
        <div class="sch-fixed__when"><?= icon($c['status'] === 3 ? 'event_available' : 'schedule') ?> <strong><?= h($c['when']) ?></strong>
            <?php if ($c['reservedAt'] !== null): ?>
                <span class="pill pill--ok sch-fixed__reserved" <?= tip_attr(trim($c['reservedBy'] . ' ' . date('n/j G:i', strtotime((string)$c['reservedAt'])))) ?>>予約済み</span>
            <?php endif; ?>
        </div>
        <?php if ($c['studio'] !== null): ?>
            <div class="sch-fixed__where"><?= h($c['studio']['name']) ?><?= $c['room'] !== '' ? ' ' . h($c['room']) : '' ?><?= $c['area'] !== '' ? ' ' . h($c['area']) : '' ?></div>
        <?php endif; ?>
        <?php if ($c['absent'] !== ''): ?>
            <div class="sch-fixed__absent">欠席: <?= h($c['absent']) ?></div>
        <?php endif; ?>
        <div class="sch-fixed__actions">
            <?php if (isset($c['gcal'])): ?>
                <a class="btn btn--sm" href="<?= h($c['gcal']) ?>" target="_blank" rel="noopener"><?= icon('event') ?> Googleカレンダー</a>
                <a class="btn btn--sm" href="sched_poll?id=<?= $pollId ?>&amp;ics=<?= $c['id'] ?>"><?= icon('download') ?> .ics</a>
            <?php endif; ?>
            <?php if ($c['reserve'] !== '' && $c['status'] >= 2): ?>
                <a class="btn btn--sm btn--primary" href="<?= h($c['reserve']) ?>" target="_blank" rel="noopener"><?= icon('open_in_new') ?> 予約ページ</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" data-confirm="<?= $c['status'] === 3 ? '決定' : '仮決定' ?>を取り消します。よろしいですか？">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>"><input type="hidden" name="fix" value="<?= $c['id'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit">取り消し</button>
                </form>
                <?php if ($c['reservedAt'] !== null): ?>
                    <form method="post" data-confirm="予約済みを取り消します。よろしいですか？">
                        <?= csrf_field() ?><input type="hidden" name="action" value="unreserve"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>"><input type="hidden" name="fix" value="<?= $c['id'] ?>">
                        <button class="btn btn--sm btn--ghost" type="submit">予約済みを取り消し</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($c['status'] === 2): ?><p class="muted small">仮決定はほかの調整では埋まりとして扱いません（注意だけ出ます）</p><?php endif; ?>
    </section>
<?php endforeach; ?>

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
                <span><i class="sch-sw is-free"></i>全員空き</span><span><i class="sch-sw is-free is-cheap"></i>最安</span><span><i class="sch-sw is-n1"></i>1人予定あり</span><span><i class="sch-sw is-n2"></i>2人以上予定あり</span><span><i class="sch-sw is-sp"></i>スタジオ練習</span><span><i class="sch-sw is-prov"></i>仮決定</span>
            </div>
            <div class="sch-grid-wrap" data-sched-over-grid></div>
            <?php if ($myRow !== null): ?>
                <a class="btn btn--sm btn--ghost" href="sched_me?poll=<?= $pollId ?>"><?= icon('calendar_today') ?> マイスケジュール</a>
            <?php endif; ?>
        </div>
    </details>
<?php endif; ?>
<?php if ($status === 3): // 決定が1つでもあれば、候補は折りたたむ（決定のカードを先に見せる）。開閉は assets/sched.js の setupFold が localStorage に覚える ?>
    <details class="card sch-over sch-cands-fold" data-sched-fold="sched-cands-open">
        <summary><?= icon('event') ?> 候補</summary>
        <div class="sch-over__body">
<?php endif; ?>
    <section class="sch-cands" data-sched-cands>
        <div class="sch-cands__head">
            <h2 class="section-title"><span>候補 <small class="muted" data-sched-cands-count><?= count(array_unique(array_column($windows, 'date'))) ?>日</small></span>
                <span class="info-tip" <?= tip_attr('交通費はメンバー全員の定期外運賃の合計、時間はメンバー内で最も遠い人の所要時間が表示されています。') ?>><?= icon('info', 'icon--sm') ?></span></h2>
            <div class="sch-cands__tools">
                <div class="tabs tabs--filter sch-sort" role="tablist" data-sched-sort>
                    <button type="button" class="tab is-active" data-sort="date">日付順</button>
                    <button type="button" class="tab" data-sort="best">おすすめ順</button>
                    <button type="button" class="tab" data-sort="price">安い順</button>
                </div>
                <?php /* 練習の長さを30分ずつ増減（?len=。サーバーが sched_poll_len で検証する）。上限・下限のボタンは押せない印にする */ ?>
                <div class="sch-len" data-sched-len>
                    <?php if ($len - SCHED_STEP >= SCHED_STEP): ?>
                        <a class="icon-btn" href="sched_poll?id=<?= $pollId ?>&amp;len=<?= $len - SCHED_STEP ?>" aria-label="30分短く" title="30分短く"><?= icon('remove') ?></a>
                    <?php else: ?>
                        <span class="icon-btn is-disabled" aria-disabled="true"><?= icon('remove') ?></span>
                    <?php endif; ?>
                    <strong><?= h(studio_fmt_hours($len)) ?></strong>
                    <?php if ($len + SCHED_STEP <= $lenMax): ?>
                        <a class="icon-btn" href="sched_poll?id=<?= $pollId ?>&amp;len=<?= $len + SCHED_STEP ?>" aria-label="30分長く" title="30分長く"><?= icon('add') ?></a>
                    <?php else: ?>
                        <span class="icon-btn is-disabled" aria-disabled="true"><?= icon('add') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        // 調べるボタンは、覚えていない分があるときだけ（作成画面などで調べ済みなら、覚えてある結果がそのまま候補に出ている）
        $fareMissing = false;
        if ($canManage && sched_nav_key() !== '' && $studios) {
            $fareEach(static fn(int $mid, string $origin, int $sid) => $fareOf($mid, $origin, $sid));
            $fareMissing = sched_nav_misses() > 0;
        }
        ?>
        <?php if ($fareMissing): ?>
            <form method="post" class="sch-row" data-loading data-confirm="<?= h("メンバーの最寄り駅とスタジオまでの往復交通費を検索します。\nスタジオ練習の前後" . rtrim(rtrim(number_format((int)$settings['fare_gap'] / 60, 1), '0'), '.') . "時間に授業があれば所属キャンパスへの移動も考慮します。") ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="fare_fetch"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>"><input type="hidden" name="len" value="<?= $len ?>">
                <button class="btn btn--sm" type="submit">交通費を調べる</button>
                <span class="info-tip" <?= tip_attr('マイスケジュールで最寄り駅を登録したメンバーのみ反映されます') ?>><?= icon('info', 'icon--sm') ?></span>
                <div class="loading" data-loading-box hidden role="status" aria-live="polite">
                    <span class="loading__spinner" aria-hidden="true"></span>
                    <p class="loading__title" data-loading-title>調査中…</p>
                    <p class="muted small" data-loading-sub></p>
                </div>
            </form>
        <?php endif; ?>
        <?php /* 長さの − ＋ で入れ替える部分（assets/sched.js の setupLen が、新しい長さのページから同じ部分を取ってきて差し替える） */ ?>
        <div data-sched-cands-body>
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
        </div>
        <p class="muted small">料金は目安です。スタジオの空き状況は予約ページで確認してください</p>
    </section>
<?php if ($status === 3): ?>
        </div>
    </details>
<?php endif; ?>
<script type="application/json" id="sched-data"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset_url('assets/studio_sim.js') ?>" defer></script>
<script src="<?= asset_url('assets/sched.js') ?>" defer></script>

<?php if ($reserveGroup): ?>
    <?php
    /* 予約のうながし: 決定して未予約のあいだ、開くたびに自動で出る（data-auto-open → app.js の setupAutoDialog）。予約で打ち込む日時・部屋を大きく、スタジオ名は小さく
       未予約の決定をスタジオごとにまとめ、最も早い日を含むスタジオだけ出す。1回分 = 日時＋部屋のひとかたまりを、回数ぶん並べる。「予約完了」で、このグループの全部の回をまとめて予約済みにする */
    $rsStudio = $reserveGroup[0]['studio'];
    $rsReserve = $reserveGroup[0]['reserve'];
    $rsAreas = array_values(array_unique(array_column($reserveGroup, 'area')));
    $rsCommonArea = count($rsAreas) === 1 ? $rsAreas[0] : ''; // 部屋の広さが全部同じなら、スタジオ名の横に（1回分のときは今まで通り）。違うなら、部屋ごとに付ける
    ?>
    <dialog class="modal sch-reserve" data-auto-open aria-labelledby="sch-reserve-title">
        <form method="dialog"><button class="icon-btn sch-reserve__close" aria-label="閉じる" title="閉じる"><?= icon('close') ?></button></form>
        <div class="sch-reserve__body">
            <div class="sch-reserve__studio"><?= h(trim($rsStudio['name'] . ' ' . $rsCommonArea)) ?></div>
            <?php foreach ($reserveGroup as $i => $c): ?>
                <div class="sch-reserve__item">
                    <h2 class="sch-reserve__when"<?= $i === 0 ? ' id="sch-reserve-title"' : '' ?>><?= h($c['when']) ?></h2>
                    <?php $rsRoom = trim($c['room'] . ($rsCommonArea === '' && $c['room'] !== '' ? ' ' . $c['area'] : '')); ?>
                    <?php if ($rsRoom !== ''): ?>
                        <div class="sch-reserve__room"><?= h($rsRoom) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="sch-reserve__actions">
                <?php if ($rsReserve !== ''): ?>
                    <a class="btn btn--primary" href="<?= h($rsReserve) ?>" target="_blank" rel="noopener"><?= icon('open_in_new') ?> 予約ページ</a>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reserve"><input type="hidden" name="v" value="<?= (int)$poll['version'] ?>">
                    <?php foreach ($reserveGroup as $c): ?><input type="hidden" name="fix[]" value="<?= $c['id'] ?>"><?php endforeach; ?>
                    <button class="btn<?= $rsReserve === '' ? ' btn--primary' : '' ?>" type="submit"><?= icon('check') ?> 予約完了</button>
                </form>
            </div>
        </div>
    </dialog>
<?php endif; ?>
<?php render_footer();
