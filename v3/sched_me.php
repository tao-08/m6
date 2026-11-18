<?php
/**
 * =====================================================================
 *  sched_me.php — マイスケジュール（日程調整に使う「自分の埋まり」の入力）
 * =====================================================================
 *  GET
 *    sched_me                 … 今日から1週間の NG 枠（?from=2026-11-20 で週を移動）
 *    sched_me?poll=12         … その日程調整の期間・時間帯で NG 枠を出す（&week=1 で次の7日）
 *  POST（CSRF チェック）
 *    campus  … キャンパス。campus = メイン（和泉 / 駿河台 / 中野 / 生田。未選択の間は学部・学年から自動）・campus2 = サブ（なし / 和泉 / 駿河台 / 中野 / 生田）
 *    classes … 時間割（春・秋）。チェックの付いたコマを入れ直す（サブのキャンパスの授業は sub[] にも入る）
 *    ical / ical_refresh / ical_delete … カレンダー連携（iCal の URL）
 *    google_refresh / google_disconnect … カレンダー連携（Google。連携を始めるのは sched_google.php。lib/sched_google.php）
 *    event_format … カレンダーに入れる練習の書式（タイトル・場所・メモ）とスタジオ名の略称（lib/sched.php の sched_event_format）。reset で初期に戻す
 *    feed_reset … 決定した練習の購読 URL を再発行（古い URL は使えなくなる。lib/sched_ical.php の sched_feed_token）
 *    busy    … NG 枠（画面に出ている範囲の手入力分を入れ直す。JS から JSON で送る）
 *    photo_read … カレンダーのスクリーンショット（最大3枚）を AI に読ませて、予定の一覧を JSON で返す（保存はしない。lib/sched_photo.php）
 *    photo_add  … 確認画面で選んだ予定を sched_busy に入れる（source = 2。JS から JSON で送る）
 *    photo_delete … 画像から読み込んだ予定を削除（グリッドで予定をタップ →「削除」）
 *    ignore  … 自動で入った予定（授業・カレンダー・画像から読込・毎週）の1回分を無視 / 元に戻す
 *    weekly_add / weekly_delete … 毎週の NG（バイト等。曜日と時間帯を1行ずつ。削除するまで毎週効く）
 *
 *  自分の予定だけを扱う（member_id はセッションのもの。フォームからは受け取らない＝他人の予定を書き換えられない）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // tip_attr（ポップアップの説明）
require_once __DIR__ . '/lib/sched.php';
require_once __DIR__ . '/lib/sched_ical.php';
require_once __DIR__ . '/lib/sched_google.php'; // Google カレンダーとの連携（OAuth）
require_once __DIR__ . '/lib/sched_photo.php'; // 画像から予定を読み込む（claude.php・ai_reader.php も読み込まれる）
require_once __DIR__ . '/lib/sched_fare.php'; // 定期の経路の候補（NAVITIME・Google。結果は DB に覚える）
/** 毎週の NG の曜日（1 = 月 〜 7 = 日。date('N') と同じ）。時間割の SCHED_WEEKDAYS は日曜が無いので、日を足す */
const SCHED_ME_WEEKDAYS = SCHED_WEEKDAYS + [7 => '日'];
/** 1人が登録できる毎週の NG の数（増やしすぎて画面と計算が重くならないように） */
const SCHED_ME_WEEKLY_MAX = 20;
$user = require_login(); // ログインしていれば誰でも
$pdo = db();
if ($user['member_id'] === null) {
    render_header('マイスケジュール', 'tools');
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

/** Google 連携中か（連携中は、iCal の URL は使わない。両方が予定を入れ替えると、お互いの取り込みを消し合うため） */
$googleOn = sched_google_available();
$g = $googleOn ? sched_google_row($pdo, $me) : null;

/* ==================== 保存 ==================== */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    // 自動保存（assets/sched.js が fetch で送る）: 画面は移動しないので、メッセージもリダイレクトも無しで 204 を返す
    $auto = ($_SERVER['HTTP_X_AUTOSAVE'] ?? '') === '1';
    $back = 'sched_me' . ($pollId > 0 ? '?poll=' . $pollId . (isset($_POST['week']) ? '&week=' . (int)$_POST['week'] : '') : (isset($_POST['from']) ? '?from=' . urlencode((string)$_POST['from']) : ''));

    if ($action === 'campus') {
        // メイン（campus）・サブ（campus2）。0 や不正な値は NULL（メイン = 自動 / サブ = なし）
        $c = (int)($_POST['campus'] ?? 0);
        $c = isset(SCHED_CAMPUSES[$c]) ? $c : null;
        $c2 = (int)($_POST['campus2'] ?? 0);
        $c2 = isset(SCHED_CAMPUSES[$c2]) ? $c2 : null;
        // サブがメイン（自動のときは、自動で決まったキャンパス）と同じなら、サブは「なし」にする
        $st = $pdo->prepare('SELECT entry_year, faculty FROM member WHERE member_id = ?');
        $st->execute([$me]);
        $mem = $st->fetch();
        $autoNow = sched_member_campus(null, $mem['entry_year'] === null ? null : (int)$mem['entry_year'], $mem['faculty'] === null ? null : (string)$mem['faculty']);
        // 自動で決まるキャンパスと同じなら NULL（= 自動）で保存する。サブだけ変えたときも、メインが固定されず学年が上がれば自動で変わる
        if ($c === $autoNow) {
            $c = null;
        }
        $mainNow = $c ?? $autoNow;
        if ($c2 === $mainNow) {
            $c2 = null;
        }
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET campus = ?, campus2 = ? WHERE member_id = ?')->execute([$c, $c2, $me]);
        flash('キャンパスを保存しました');
        redirect($back . '#campus');
    }

    if ($action === 'pass') {
        // 自宅最寄り駅（= 定期の出発駅）と、定期の到着駅（キャンパスの最寄り駅の候補から選ぶ。空 = 定期なし）
        $home = sched_station_norm(trim((string)($_POST['home_station'] ?? ''))); // 書き方をそろえる（同じ駅の人と結果を共有するため）
        $to = (string)($_POST['pass_to'] ?? '');
        $valid = array_merge(...array_values(SCHED_PASS_STATIONS));
        if (mb_strlen($home) > 30) {
            flash('駅名は30文字までです', 'error');
            redirect($back . '#pass');
        }
        // 「カスタム」を選んだときは、自由入力の駅名を使う（30文字まで）
        $custom = $to === '__custom__';
        if ($custom) {
            $to = sched_station_norm(trim((string)($_POST['pass_to_custom'] ?? '')));
            if (mb_strlen($to) > 30) {
                flash('駅名は30文字までです', 'error');
                redirect($back . '#pass');
            }
        }
        $ensureProfile();
        $to = $home !== '' && ($custom ? $to !== '' : in_array($to, $valid, true)) ? $to : '';
        $pdo->prepare('UPDATE sched_profile SET home_station = ?, pass_to = ?, pass_via = NULL WHERE member_id = ?') // 駅が変わったかもしれないので、選んだ経路は選び直し
            ->execute([$home === '' ? null : $home, $to === '' ? null : $to, $me]);
        $msg = '定期を保存しました';
        if ($home !== '' && $to !== '' && sched_nav_key() !== '') {
            // 定期の経路の候補を調べる（自宅駅・到着駅の座標と経路で API を数回使う）。1つしかなければそのまま使う
            try {
                $cands = sched_pass_candidates($pdo, $home, $to, true);
                if (count($cands) === 1) {
                    $pdo->prepare('UPDATE sched_profile SET pass_via = ? WHERE member_id = ?')->execute([json_encode($cands[0], JSON_UNESCAPED_UNICODE), $me]);
                } elseif (count($cands) > 1) {
                    $msg .= '。定期の経路を選んでください';
                }
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'error');
            }
        }
        flash($msg);
        redirect($back . '#pass');
    }

    if ($action === 'pass_on') {
        // 定期を運賃計算に考慮するか（オフ = 定期を無視して全額で計算。定期の駅・経路は消さない）
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET pass_on = ? WHERE member_id = ?')->execute([!empty($_POST['pass_on']) ? 1 : 0, $me]);
        flash('保存しました');
        redirect($back . '#pass');
    }

    if ($action === 'buf_min') {
        // 取り込んだ予定の前後の枠の分数（0〜120・30分刻み）
        $m = (int)($_POST['minutes'] ?? -1);
        if ($m < 0 || $m > 120 || $m % 30 !== 0) {
            flash('0〜120分の30分刻みで選んでください', 'error');
            redirect($back . '#ng');
        }
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET buf_min = ? WHERE member_id = ?')->execute([$m, $me]);
        flash('保存しました');
        redirect($back . '#ng');
    }

    if ($action === 'pass_via') {
        // 定期の経路を、候補から選ぶ（候補は 'pass' の保存で調べて覚えてある。番号は候補の並び）
        $st = $pdo->prepare('SELECT home_station, pass_to FROM sched_profile WHERE member_id = ?');
        $st->execute([$me]);
        $pr = $st->fetch();
        $cands = $pr && $pr['home_station'] !== null && $pr['pass_to'] !== null ? sched_pass_candidates($pdo, (string)$pr['home_station'], (string)$pr['pass_to'], false) : [];
        $i = (int)($_POST['via'] ?? -1);
        if (isset($cands[$i])) {
            $pdo->prepare('UPDATE sched_profile SET pass_via = ? WHERE member_id = ?')->execute([json_encode($cands[$i], JSON_UNESCAPED_UNICODE), $me]);
            flash('定期の経路を保存しました');
        }
        redirect($back . '#pass');
    }

    if ($action === 'classes') {
        $term = (int)($_POST['term'] ?? 0);
        if (!isset(SCHED_TERMS[$term])) {
            if ($auto) { http_response_code(400); exit; }
            redirect($back);
        }
        // "3-2"（水曜2限）の形だけ受け取る。それ以外は捨てる
        $cells = [];
        foreach ((array)($_POST['cls'] ?? []) as $v) {
            if (preg_match('/^([1-6])-([1-6])$/', (string)$v, $m)) {
                $cells[$v] = [(int)$m[1], (int)$m[2]];
            }
        }
        // サブのキャンパスの授業（sub[]）。これも "3-2" の形だけ。cls にあるコマのうち、sub にもあるものだけ sub = 1 にする
        $subCells = [];
        foreach ((array)($_POST['sub'] ?? []) as $v) {
            if (preg_match('/^[1-6]-[1-6]$/', (string)$v)) {
                $subCells[$v] = true;
            }
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM sched_class WHERE member_id = ? AND term = ?')->execute([$me, $term]);
        $ins = $pdo->prepare('INSERT INTO sched_class (member_id, term, weekday, period, sub) VALUES (?, ?, ?, ?, ?)');
        foreach ($cells as $key => [$w, $p]) {
            $ins->execute([$me, $term, $w, $p, isset($subCells[$key]) ? 1 : 0]);
        }
        $pdo->commit();
        if ($auto) { http_response_code(204); exit; }
        flash(SCHED_TERMS[$term] . 'の時間割を保存しました');
        redirect($back . '#classes');
    }

    if ($action === 'ical') {
        if ($g !== null) {
            flash('Google 連携を解除してから登録してください', 'error');
            redirect($back . '#ical');
        }
        if (!sched_ical_available()) {
            flash('カレンダー連携は現在使えません', 'error');
            redirect($back . '#ical');
        }
        $url = sched_ical_normalize((string)($_POST['url'] ?? ''));
        // Google カレンダーは「Google で連携」だけ（iCal の URL での新しい登録は受け付けない。登録済みの人は解除するまでそのまま読む）
        if ($url !== null && strtolower((string)parse_url($url, PHP_URL_HOST)) === 'calendar.google.com') {
            flash($googleOn ? 'Google カレンダーは「Google で連携」を使ってください' : 'Google カレンダーは現在使えません', 'error');
            redirect($back . '#ical');
        }
        if ($url === null) {
            flash('iCloud の URL を貼ってください', 'error');
            redirect($back . '#ical');
        }
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET ical_url = ?, ical_fetched_at = NULL, ical_error = NULL WHERE member_id = ?')->execute([sched_ical_encrypt($url), $me]);
        $err = sched_ical_refresh($pdo, $me, true);
        flash($err === null ? 'カレンダーを連携しました' : '保存しましたが、読み込めませんでした: ' . $err, $err === null ? 'success' : 'error');
        if ($err === null) {
            $_SESSION['sched_feed_auto'] = 1; // 連携できたら、次の画面で決定した練習の購読（webcal）も続けて開く（iPhone のみ。assets/sched.js）
        }
        redirect($back . '#ical');
    }

    if ($action === 'ical_refresh') {
        if ($g !== null) {
            redirect($back . '#ical');
        }
        $err = sched_ical_refresh($pdo, $me, true);
        flash($err === null ? 'カレンダーを読み直しました' : $err, $err === null ? 'success' : 'error');
        redirect($back . '#ical');
    }

    if ($action === 'event_format') {
        $ensureProfile();
        $shorts = [];
        if (!isset($_POST['reset'])) {
            $cols = ['ev_title' => sched_event_clean('title', (string)($_POST['ev_title'] ?? '')), 'ev_location' => sched_event_clean('location', (string)($_POST['ev_location'] ?? '')), 'ev_details' => sched_event_clean('details', (string)($_POST['ev_details'] ?? ''))];
            // 略称: 初期の略称（スタジオ情報の略称 → スタジオ名）と同じなら覚えない（初期の略称が変わったらそれに従う）。違うものは、空も含めて覚える（空 = 略称を使わない）
            $defaults = [];
            foreach ($pdo->query('SELECT studio_id, name, short_name FROM studio') as $r) {
                $defaults[(int)$r['studio_id']] = (string)($r['short_name'] ?? '') !== '' ? (string)$r['short_name'] : (string)$r['name'];
            }
            foreach ((array)($_POST['short'] ?? []) as $sid => $v) {
                $v = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, 30);
                if (isset($defaults[(int)$sid]) && $v !== mb_substr($defaults[(int)$sid], 0, 30)) {
                    $shorts[(int)$sid] = $v;
                }
            }
        } else {
            $cols = ['ev_title' => null, 'ev_location' => null, 'ev_details' => null];
        }
        $pdo->beginTransaction();
        // updated_at も更新する（購読のカレンダーが、書式の変更を新しい版として読み直すため）
        $pdo->prepare('UPDATE sched_profile SET ev_title = ?, ev_location = ?, ev_details = ?, updated_at = NOW() WHERE member_id = ?')->execute([...array_values($cols), $me]);
        $pdo->prepare('DELETE FROM sched_studio_short WHERE member_id = ?')->execute([$me]);
        $ins = $pdo->prepare('INSERT INTO sched_studio_short (member_id, studio_id, short_name) VALUES (?, ?, ?)');
        foreach ($shorts as $sid => $v) {
            $ins->execute([$me, $sid, $v]);
        }
        $pdo->commit();
        if ($g !== null) {
            sched_google_repatch_member($pdo, $me); // Google に入れてある、これからの練習も新しい書式に直す
        }
        flash(isset($_POST['reset']) ? '初期のフォーマットに戻しました' : 'フォーマットを保存しました');
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

    // Google 連携: 読み直す（予定を読み直し、まだ入っていない決定済みの練習をカレンダーに入れる）/ 解除（トークンを取り消し、取り込んだ予定も消す）
    //   連携を始める（Google の許可画面へ行く）のは sched_google.php
    if ($action === 'google_refresh' && $g !== null) {
        $err = sched_google_refresh_busy($pdo, $me, true);
        sched_google_sync_member($pdo, $me);
        flash($err === null ? 'カレンダーを読み直しました' : $err, $err === null ? 'success' : 'error');
        redirect($back . '#ical');
    }

    if ($action === 'google_disconnect' && $g !== null) {
        sched_google_disconnect($pdo, $me);
        flash('Google との連携を解除しました');
        redirect($back . '#ical');
    }

    // 購読 URL（決定した練習をカレンダーに自動で入れる）の再発行。版を +1 すると、古い URL は鍵が合わなくなって使えなくなる
    //   行が無い部員は INSERT で作る（版 1 から）。ある部員は ON DUPLICATE KEY UPDATE で +1（1回の SQL で「無ければ作る・有れば足す」）
    if ($action === 'feed_reset') {
        if (sched_feed_token($me, 0) === null) {
            flash('購読は現在使えません', 'error');
            redirect($back . '#ical');
        }
        $pdo->prepare('INSERT INTO sched_profile (member_id, feed_version) VALUES (?, 1) ON DUPLICATE KEY UPDATE feed_version = feed_version + 1')->execute([$me]);
        flash('URL を再発行しました。カレンダーに登録し直してください');
        redirect($back . '#ical');
    }

    // この予定を無視（授業・カレンダー・画像から読込・毎週の1回分。JS から fetch で送る。on = 1 で無視、0 で元に戻す）
    if ($action === 'ignore') {
        $s = (int)($_POST['s'] ?? 0);
        $e = (int)($_POST['e'] ?? 0);
        $on = (string)($_POST['on'] ?? '') === '1';
        $day = substr(sched_abs_to_dt($s), 0, 10);
        // 画面に出ている予定（無視中も含む）の中にある時間帯だけ受け付ける
        $found = false;
        if ($e > $s) {
            foreach (sched_collect_busy($pdo, [$me], $day, $day, null, true)['busy'][$me] ?? [] as $b) {
                if ($b[0] === $s && $b[1] === $e && in_array($b[3], ['class', 'ical', 'photo', 'weekly', 'buffer'], true)) { // buffer … 取り込んだ予定の前後の枠
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            http_response_code(400);
            exit;
        }
        if ($on) {
            $pdo->prepare('INSERT IGNORE INTO sched_ignore (member_id, start_at, end_at) VALUES (?, ?, ?)')->execute([$me, sched_abs_to_dt($s), sched_abs_to_dt($e)]);
        } else {
            $pdo->prepare('DELETE FROM sched_ignore WHERE member_id = ? AND start_at = ? AND end_at = ?')->execute([$me, sched_abs_to_dt($s), sched_abs_to_dt($e)]);
        }
        http_response_code(204);
        exit;
    }

    // 画像から読み込んだ予定を削除（グリッドで予定をタップ →「削除」。JS から fetch で送る）
    //   消せるのは自分の source = 2 だけ（member_id はセッションの $me。授業・iCal・手入力などは、ここでは消せない）
    //   同じ時間帯に「この予定を無視」が付いていて、ほかに同じ時間帯の自動の予定が無ければ、無視の印も一緒に消す（残すと、あとで同じ時間帯に入った予定まで無視されるため）
    if ($action === 'photo_delete') {
        $s = (int)($_POST['s'] ?? 0);
        $e = (int)($_POST['e'] ?? 0);
        if ($e <= $s) {
            http_response_code(400);
            exit;
        }
        [$sAt, $eAt] = [sched_abs_to_dt($s), sched_abs_to_dt($e)];
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM sched_busy WHERE member_id = ? AND source = 2 AND start_at = ? AND end_at = ?');
        $del->execute([$me, $sAt, $eAt]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            exit;
        }
        $day = substr($sAt, 0, 10);
        $still = false;
        foreach (sched_collect_busy($pdo, [$me], $day, $day, null, true)['busy'][$me] ?? [] as $b) {
            if ($b[0] === $s && $b[1] === $e && in_array($b[3], ['class', 'ical', 'photo', 'weekly'], true)) {
                $still = true;
                break;
            }
        }
        if (!$still) {
            try {
                $pdo->prepare('DELETE FROM sched_ignore WHERE member_id = ? AND start_at = ? AND end_at = ?')->execute([$me, $sAt, $eAt]);
            } catch (PDOException) {
                // sched_ignore がまだ無い（migrations/107 を流していない）DB では、消す印も無いので何もしない（lib/sched.php と同じ扱い）
            }
        }
        $pdo->commit();
        http_response_code(204);
        exit;
    }

    // 画像から予定を読む（JS から multipart で送る。保存はせず、読んだ予定の一覧を JSON で返すだけ）
    //   成功: {"events": [...], "notes": [...]} / 失敗: {"error": "..."}（HTTP 4xx・5xx）
    //   画像は AI に送るだけで、サーバーには残さない（アップロードの一時ファイルは、この処理が終わると PHP が消す）
    if ($action === 'photo_read') {
        header('Content-Type: application/json; charset=utf-8');
        $reply = static function (int $status, array $body): never {
            http_response_code($status);
            echo json_encode($body, JSON_UNESCAPED_UNICODE);
            exit;
        };
        if (!claude_enabled()) {
            $reply(503, ['error' => '画像からの読込は現在使えません']);
        }
        // $_FILES['images'] は、1枚でも複数でも「項目ごとの配列」に直してから使う
        $up = $_FILES['images'] ?? null;
        $files = [];
        if (is_array($up)) {
            foreach ((array)$up['tmp_name'] as $i => $tmp) {
                $files[] = ['path' => (string)$tmp, 'name' => basename((string)((array)$up['name'])[$i]), 'error' => (int)((array)$up['error'])[$i], 'size' => (int)((array)$up['size'])[$i]];
            }
        }
        if (!$files) {
            $reply(400, ['error' => '画像を選択してください']);
        }
        if (count($files) > SCHED_PHOTO_MAX_IMAGES) {
            $reply(400, ['error' => '画像は ' . SCHED_PHOTO_MAX_IMAGES . ' 枚までです']);
        }
        foreach ($files as $f) {
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > SCHED_PHOTO_MAX_BYTES) {
                $reply(400, ['error' => '画像は 1 枚 ' . (SCHED_PHOTO_MAX_BYTES / 1048576) . 'MB までです']);
            }
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['path'])) { // is_uploaded_file … 本当にアップロードされたファイルか（サーバー上の別のファイルを指定されていないか）
                $reply(400, ['error' => '画像を受け取れませんでした。もう一度試してください']);
            }
        }
        try {
            $reply(200, sched_photo_read($files, date('Y-m-d'), (string)($_POST['model'] ?? ''))); // model は一覧（claude_models）に無ければ初期のモデル
        } catch (RuntimeException $e) {
            $reply(502, ['error' => $e->getMessage()]); // 画面に出してよい文言（画像として読めない・AI の失敗など）
        }
    }

    // 画像から読んだ予定を追加（確認画面の「追加」。JS から JSON で送る）
    //   {"items": [{"date": "2026-11-20", "start": 540, "end": 600, "title": "バイト"}, …]}（開始・終了は 0:00 からの分）
    //   タイトルは iCal と同じく暗号化して入れる（鍵が無いサーバーでは入れない = 時間だけ）。member_id はセッションの $me
    if ($action === 'photo_add') {
        $in = json_decode((string)($_POST['payload'] ?? ''), true);
        $items = sched_photo_normalize_add(is_array($in) ? ($in['items'] ?? null) : null);
        if ($items === null) {
            if ($auto) { http_response_code(400); exit; }
            flash('追加できませんでした', 'error');
            redirect($back . '#ng');
        }
        $canEncrypt = sched_ical_available();
        // 日付・時刻 → DATETIME。同じ予定を2回入れないために、「開始|終了|タイトル」の目印を作っておく
        $rows = [];
        foreach ($items as $it) {
            $d0 = sched_day_abs($it['date']);
            $title = $canEncrypt ? $it['title'] : '';
            $rows[] = [sched_abs_to_dt($d0 + $it['start']), sched_abs_to_dt($d0 + $it['end']), $title];
        }
        $pdo->beginTransaction();
        try {
            // すでに入っている「画像から読込」の予定（この人の source = 2 だけ）。タイトルは暗号化されている（毎回違う文字列になる）ので、復号して比べる
            $st = $pdo->prepare('SELECT start_at, end_at, title FROM sched_busy WHERE member_id = ? AND source = 2 AND start_at >= ? AND start_at <= ?');
            $st->execute([$me, min(array_column($rows, 0)), max(array_column($rows, 0))]);
            $have = [];
            foreach ($st as $r) {
                $have[$r['start_at'] . '|' . $r['end_at'] . '|' . ($r['title'] === null ? '' : (sched_ical_decrypt($r['title']) ?? ''))] = true;
            }
            $ins = $pdo->prepare('INSERT INTO sched_busy (member_id, start_at, end_at, source, title) VALUES (?, ?, ?, 2, ?)');
            $added = 0;
            foreach ($rows as [$s, $e, $title]) {
                $key = $s . '|' . $e . '|' . $title;
                if (isset($have[$key])) {
                    continue; // 同じ予定がすでにある（同じ画像をもう一度読んだとき）
                }
                $have[$key] = true;
                $ins->execute([$me, $s, $e, $title !== '' ? sched_ical_encrypt($title) : null]);
                $added++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($auto) { http_response_code(204); exit; }
        flash($added . '件の予定を追加しました');
        redirect($back . '#ng');
    }

    if ($action === 'busy') {
        // JS（assets/sched.js）が送る JSON: {"days": ["2026-11-20", …], "start": 540, "end": 1440, "cells": [絶対分, …]}
        $in = json_decode((string)($_POST['payload'] ?? ''), true);
        $days = array_values(array_filter((array)($in['days'] ?? []), static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
        $ws = (int)($in['start'] ?? 0);
        $we = (int)($in['end'] ?? 0);
        if (!$days || count($days) > 7 || $ws < 0 || $we > 1440 || $we <= $ws) {
            if ($auto) { http_response_code(400); exit; }
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
        if ($auto) { http_response_code(204); exit; }
        flash('スケジュールを保存しました');
        redirect($back . '#ng');
    }

    if ($action === 'weekly_add') {
        $wd = (int)($_POST['weekday'] ?? 0);
        $ws = (int)($_POST['start'] ?? -1);
        $we = (int)($_POST['end'] ?? -1);
        // 曜日は 1〜7、時刻は 30分刻みで 0:00〜24:00、開始 < 終了（DB の CHECK と同じ条件。フォームを書き換えられても通さない）
        if ($wd < 1 || $wd > 7 || $ws < 0 || $we > 1440 || $ws >= $we || $ws % SCHED_STEP !== 0 || $we % SCHED_STEP !== 0) {
            flash('時間を正しく選んでください', 'error');
            redirect($back . '#weekly');
        }
        $st = $pdo->prepare('SELECT COUNT(*) AS n, SUM(weekday = ? AND start_min = ? AND end_min = ?) AS same FROM sched_weekly WHERE member_id = ?');
        $st->execute([$wd, $ws, $we, $me]);
        $r = $st->fetch();
        if ((int)$r['n'] >= SCHED_ME_WEEKLY_MAX) {
            flash('繰り返しの予定は' . SCHED_ME_WEEKLY_MAX . '件までです', 'error');
        } elseif ((int)$r['same'] === 0) { // まったく同じ行があれば足さない
            // member_id はフォームから受け取らず、セッションの $me を使う（他人の行は作れない）
            $pdo->prepare('INSERT INTO sched_weekly (member_id, weekday, start_min, end_min) VALUES (?, ?, ?, ?)')->execute([$me, $wd, $ws, $we]);
        }
        redirect($back . '#weekly');
    }

    if ($action === 'weekly_delete') {
        // member_id も条件に入れる = 他人の行の id を送られても消せない
        $pdo->prepare('DELETE FROM sched_weekly WHERE sched_weekly_id = ? AND member_id = ?')->execute([(int)($_POST['id'] ?? 0), $me]);
        redirect($back . '#weekly');
    }
    redirect($back);
}

/* ==================== 表示 ==================== */
$st = $pdo->prepare('SELECT m.name, m.entry_year, m.faculty, p.campus, p.campus2, p.home_station, p.pass_to, p.pass_via, COALESCE(p.pass_on, 1) AS pass_on, COALESCE(p.buf_min, ' . SCHED_DEFAULT_BUF_MIN . ') AS buf_min, p.ev_title, p.ev_location, p.ev_details, p.ical_url, p.ical_fetched_at, p.ical_error, COALESCE(p.feed_version, 0) AS feed_version FROM member m LEFT JOIN sched_profile p ON p.member_id = m.member_id WHERE m.member_id = ?');
$st->execute([$me]);
$prof = $st->fetch();
$autoCampus = sched_member_campus(null, $prof['entry_year'] === null ? null : (int)$prof['entry_year'], $prof['faculty'] === null ? null : (string)$prof['faculty']);
$chosenCampus = $prof['campus'] === null ? 0 : (int)$prof['campus'];
$mainCampus = $chosenCampus ?: $autoCampus; // 実際に使うメインのキャンパス（選んでいなければ自動の結果）
// サブのキャンパス（0 = なし）。メインと同じ値・不正な値は「なし」に数える
$subCampus = $prof['campus2'] !== null && isset(SCHED_CAMPUSES[(int)$prof['campus2']]) && (int)$prof['campus2'] !== $mainCampus ? (int)$prof['campus2'] : 0;
$evFmt = sched_event_format($pdo, $me); // カレンダーに入れる練習の書式（初期のままなら初期の文）
$evStudios = $pdo->query('SELECT studio_id, name, short_name, address FROM studio ORDER BY sort_order, studio_id')->fetchAll();
$classes = [1 => [], 2 => []];
$st = $pdo->prepare('SELECT term, weekday, period, sub FROM sched_class WHERE member_id = ?');
$st->execute([$me]);
foreach ($st as $r) {
    $classes[(int)$r['term']][$r['weekday'] . '-' . $r['period']] = (int)$r['sub'] === 1 ? 'sub' : 'main'; // コマ => メインの授業 / サブの授業
}
// 毎週の NG（曜日 → 開始の順）
$st = $pdo->prepare('SELECT sched_weekly_id, weekday, start_min, end_min FROM sched_weekly WHERE member_id = ? ORDER BY weekday, start_min, end_min');
$st->execute([$me]);
$weekly = $st->fetchAll();
// 時刻の選択肢（<option> の並び）。開始は 0:00〜23:30、終了は 0:30〜24:00
$weeklyTimes = static function (int $from, int $to, int $selected): string {
    $html = '';
    for ($m = $from; $m <= $to; $m += SCHED_STEP) {
        $html .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . h(sched_hm($m)) . '</option>';
    }
    return $html;
};
// いま（または次）の学期を先に開く
$today = date('Y-m-d');
//   授業期間の中ならその学期、夏休みなら秋、それ以外（冬〜春休み）は春（日曜・祝日でも学期は学期なので、sched_term_of は使わない）
$curTerm = ($today >= $settings['terms'][2][0] && $today <= $settings['terms'][2][1]) || ($today > $settings['terms'][1][1] && $today < $settings['terms'][2][0]) ? 2 : 1;

// ---- NG 枠の範囲（月曜〜日曜の7日ずつ。表の左端は月曜）----
$mondayOf = static fn(string $d): string => date('Y-m-d', strtotime($d . ' -' . ((int)date('N', strtotime($d)) - 1) . ' day')); // date('N') … 月曜 = 1 〜 日曜 = 7
if ($poll !== null) {
    $all = sched_dates(max((string)$poll['date_from'], $today), (string)$poll['date_to']);
    if (!$all) {
        $all = sched_dates((string)$poll['date_from'], (string)$poll['date_to']);
    }
    $all = $all ? sched_dates($mondayOf($all[0]), date('Y-m-d', strtotime($mondayOf($all[count($all) - 1]) . ' +6 day'))) : []; // 最初の週は月曜から、最後の週は日曜まで（調整の期間の外の日も、その週の中なら出る）
    $week = max(0, min((int)($_GET['week'] ?? 0), intdiv(count($all) - 1, 7)));
    $days = array_slice($all, $week * 7, 7);
    $ws = (int)$poll['day_start'];
    $we = (int)$poll['day_end'];
    $prevUrl = $week > 0 ? 'sched_me?poll=' . $pollId . '&week=' . ($week - 1) : null;
    $nextUrl = ($week + 1) * 7 < count($all) ? 'sched_me?poll=' . $pollId . '&week=' . ($week + 1) : null;
} else {
    $from = (string)($_GET['from'] ?? $today);
    $from = $mondayOf(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && strtotime($from) ? date('Y-m-d', strtotime($from)) : $today); // その日を含む週の月曜から
    $days = sched_dates($from, date('Y-m-d', strtotime($from . ' +6 day')));
    $ws = 540;
    $we = 1440;
    $prevUrl = 'sched_me?from=' . date('Y-m-d', strtotime($from . ' -7 day'));
    $nextUrl = 'sched_me?from=' . date('Y-m-d', strtotime($from . ' +7 day'));
}
if ($g !== null) {
    sched_google_refresh_busy($pdo, $me); // Google 連携中は Google から読む（iCal は使わない）
    $g = sched_google_row($pdo, $me); // 読み込みの結果（読んだ日時・エラー）を画面に出すため、読み直す
} else {
    sched_ical_refresh($pdo, $me); // 古ければ読み直す（30分以内に読んでいれば何もしない）
}
$data = sched_collect_busy($pdo, [$me], $days[0], $days[count($days) - 1], $poll === null ? null : $pollId, true, $me); // 最後の $me = 自分の iCal の予定のタイトルを復号して見せる
$grid = [
    'days' => array_map(static fn($d) => ['date' => $d, 'md' => sched_md($d), 'abs' => sched_day_abs($d), 'dow' => (int)date('w', strtotime($d)),
        'off' => $poll !== null && ($d < (string)$poll['date_from'] || $d > (string)$poll['date_to'])], $days), // off … 調整の期間の外の日（月曜始まりの週に入っただけ。グレーにして塗れない）
    'start' => $ws, 'end' => $we, 'step' => SCHED_STEP,
    // 埋まり: [開始, 終了, 種類, ラベル, 無視中か]（場所は画面に要らないので落とす）
    'busy' => array_map(static fn($b) => [$b[0], $b[1], $b[3], $b[4], !empty($b[5])], $data['busy'][$me] ?? []),
    'warn' => array_values(array_map(static fn($p) => ['date' => $p['date'], 'title' => $p['title'], 's' => $p['s'], 'e' => $p['e']], array_filter($data['practices'], static fn($p) => in_array($me, $p['members'], true) && $p['status'] < 3))),
];
// この調整（$poll）自身の仮決定も出す（練習の一覧 $data['practices'] は、この調整を除いてあるので）。1つの調整で何回も決められるので、仮決定（status 1・2）の全部の回を出す。日だけ（status 1）は s・e が null
if ($poll !== null) {
    $mine = $pdo->prepare('SELECT 1 FROM sched_poll_member WHERE sched_poll_id = ? AND member_id = ?');
    $mine->execute([$pollId, $me]);
    if ($mine->fetchColumn()) {
        foreach (sched_poll_fixes($pdo, $pollId) as $fx) {
            // 日が表の中で、仮決定で、自分がその回に欠席でないものだけ
            if (!in_array((int)$fx['status'], [1, 2], true) || !in_array((string)$fx['fix_date'], $days, true) || (int)$fx['absent_member_id'] === $me) {
                continue;
            }
            $own = (int)$fx['status'] === 2 && $fx['fix_start'] !== null;
            $grid['warn'][] = ['date' => (string)$fx['fix_date'], 'title' => (string)$poll['title'], 's' => $own ? sched_dt_to_abs($fx['fix_start']) : null, 'e' => $own && $fx['fix_end'] !== null ? sched_dt_to_abs($fx['fix_end']) : null];
        }
    }
}
$icalOn = sched_ical_available();

render_header('マイスケジュール', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
<nav class="crumbs sch-crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタジオ練習の日程調整</a><?php if ($poll !== null): ?><span>/</span><a href="sched_poll?id=<?= $pollId ?>"><?= h($poll['title']) ?></a><?php endif; ?></nav>
<div class="sch-title"><h1 class="display display--sm">マイスケジュール</h1>
    <button type="button" class="info-tip" data-tutorial-open="sched-help" aria-label="マイスケジュールの使い方" title="マイスケジュールの使い方"><?= icon('info') ?></button></div>

<!-- ===== NG の時間 ===== -->
<section class="card sch-sec" id="ng">
    <div class="sch-sec__head">
        <h2 class="section-title section-title--card">スケジュールを編集</h2>
    </div>
    <div class="sch-weeknav">
        <?php if ($prevUrl !== null): ?><a class="icon-btn" href="<?= h($prevUrl) ?>#ng" aria-label="前の週" title="前の週"><?= icon('chevron_left') ?></a><?php else: ?><span></span><?php endif; ?>
        <strong><?= h(sched_md($days[0]) . '〜' . sched_md($days[count($days) - 1])) ?></strong>
        <?php if ($nextUrl !== null): ?><a class="icon-btn" href="<?= h($nextUrl) ?>#ng" aria-label="次の週" title="次の週"><?= icon('chevron_right') ?></a><?php else: ?><span></span><?php endif; ?>
    </div>
    <div class="sch-legend small muted">
        <span><i class="sch-sw is-ng"></i>予定あり</span><span><i class="sch-sw is-class"></i>授業</span><span><i class="sch-sw is-ical"></i>カレンダー</span><span><i class="sch-sw is-weekly"></i>繰り返し</span><span><i class="sch-sw is-practice"></i>練習</span><span><i class="sch-sw is-prov"></i>仮決定</span>
    </div>
    <?php // スマホ（指）でなぞって塗るかどうか。外れていると表の上でもスクロールでき、マスはタップで1つずつ（assets/sched.js の setupGrid）。PC は常になぞれるので出さない ?>
    <label class="check sch-drag"><input type="checkbox" data-sched-drag>なぞって予定を入力</label>
    <div class="sch-grid-wrap" data-sched-grid></div>
    <!-- 塗ったマスを戻す / やり直す（assets/sched.js の setupGrid）。見た目はタイムテーブル作成・曲の編集と同じ左下の浮きボタン -->
    <div class="ttb-float">
        <button type="button" class="btn btn--sm" data-sched-undo aria-label="元に戻す" title="元に戻す" disabled><?= icon('undo') ?></button>
        <button type="button" class="btn btn--sm" data-sched-redo aria-label="やり直す" title="やり直す" disabled><?= icon('redo') ?></button>
    </div>
    <form method="post" data-sched-grid-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="busy">
        <input type="hidden" name="payload" value="">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><input type="hidden" name="week" value="<?= (int)($week ?? 0) ?>"><?php else: ?><input type="hidden" name="from" value="<?= h($days[0]) ?>"><?php endif; ?>
    </form>
    <?php if (claude_enabled()): // カレンダーのスクリーンショットから予定を読み込む（assets/sched.js の setupPhoto）。表の下。操作ボタンなので data-tip は付けない ?>
        <div class="sch-row sch-photo-bar">
            <button type="button" class="btn btn--sm sch-photo-btn" data-photo-open><?= icon('add_photo_alternate') ?> 画像から読込</button>
            <input type="file" accept="image/*" multiple hidden data-photo-input>
            <!-- 読込に使うモデル（Opus = 高精度・高め / Sonnet = 標準 / Haiku = 安い）。前回選んだものを JS が覚えておく -->
            <select class="select-sm" data-photo-model aria-label="モデル" title="モデル">
                <?php foreach (claude_models() as $mid => $mname): ?>
                    <option value="<?= h($mid) ?>"<?= $mid === sched_photo_default_model() ? ' selected' : '' ?>><?= h($mname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($g !== null): // 取り込んだ予定（iCal・Google・画像）の前後に付ける、手動と同じ「予定あり」の枠の分数（0〜120・30分刻み）。Google 連携中だけ出す（それ以外は初期の SCHED_DEFAULT_BUF_MIN） ?>
    <form method="post" class="sch-row sch-row--wrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="buf_min">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
        <span class="sch-label">連携した予定の前後にスタジオを入れない</span>
        <select name="minutes" class="select-sm" data-autosubmit aria-label="予定の前後に付ける予定ありの枠">
            <?php foreach (range(0, 120, 30) as $mm): ?><option value="<?= $mm ?>"<?= (int)$prof['buf_min'] === $mm ? ' selected' : '' ?>><?= $mm === 0 ? 'なし' : $mm . '分' ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn--sm" type="submit">保存</button></noscript>
    </form>
    <?php endif; ?>
    <?php if ($poll !== null): ?>
        <p class="sch-answer"><a class="btn btn--ghost" href="sched_poll?id=<?= $pollId ?>"><?= icon('chevron_left') ?> 候補・回答完了へ</a></p>
    <?php endif; ?>
</section>
<script type="application/json" id="sched-grid-data"><?= json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<!-- ===== カレンダー連携（折りたたみ。#ical で来たら開く: app.js の .togglecard）。中は番号付きの手順（assets/sched.js の setupCalSteps） ===== -->
<details class="card togglecard" id="ical">
<summary><?= icon('event') ?> <span class="togglecard__title">カレンダー連携<?= (($googleOn || $icalOn) && $g === null && $prof['ical_url'] === null) || ($g !== null && $g['error']) ? ' <span class="pill pill--warn">未連携</span>' : '' // Google も iCal もつないでいない（つなげる設定のサーバーだけ）か、Google の連携が切れている（予定が読めていない） ?></span>
    <button type="button" class="info-tip" data-tutorial-open="ical-help" aria-label="カレンダーの連携方法" title="カレンダーの連携方法"><?= icon('info', 'icon--sm') ?></button><?= icon('expand_more', 'togglecard__chevron') ?></summary>
<div class="togglecard__body sch-sec">
    <?php
    // 手順 ① の初期値: 連携中のものを選んでおく（どちらも無ければ空 = JS がこの端末で前に選んだものを入れる）
    $calKind = $g !== null ? 'google' : ($prof['ical_url'] !== null ? 'icloud' : '');
    // 手順 ③: 決定した練習を、自分のカレンダーへ自動で入れる（購読 URL。sched_feed.php）。鍵が無いサーバーでは出さない
    //   Google 連携中は出さない（決定した練習が連携で直接入る。購読も入れると二重になる）
    $feedUrl = $g === null ? sched_feed_url($me, (int)$prof['feed_version']) : null;
    $feedWebcal = $feedUrl !== null ? preg_replace('~^https://~', 'webcal://', $feedUrl) : ''; // webcal:// … 「購読する」の意味のアドレス。タップするとカレンダーアプリが開く
    $feedAuto = false;
    if ($feedUrl !== null) { $feedAuto = !empty($_SESSION['sched_feed_auto']); unset($_SESSION['sched_feed_auto']); } // 連携の直後だけ 1 回、購読を自動で開く
    $pollHidden = $poll !== null ? '<input type="hidden" name="poll" value="' . $pollId . '">' : '';
    ?>
    <div class="sch-steps" data-cal-steps data-kind="<?= h($calKind) ?>">
        <!-- ① 使用カレンダー -->
        <details class="sch-step" data-step="1">
            <summary><span class="sch-step__no">1</span><span class="sch-step__title">使用カレンダー</span><span class="sch-step__ok" role="img" aria-label="完了" hidden><?= icon('check', 'icon--sm') ?></span><span class="sch-step__val muted small" data-step-val></span></summary>
            <div class="sch-step__body">
                <div class="tabs">
                    <button type="button" class="tab" data-cal-kind="google" data-label="Google"><?= icon('event') ?>Google</button>
                    <button type="button" class="tab" data-cal-kind="icloud" data-label="iPhone"><?= icon('smartphone') ?>iPhone</button>
                    <button type="button" class="tab" data-cal-kind="other" data-label="その他"><?= icon('more_horiz') ?>その他</button>
                </div>
            </div>
        </details>

        <!-- ② 予定を読込（選んだカレンダーの分だけ出す） -->
        <details class="sch-step" data-step="2" hidden>
            <summary><span class="sch-step__no">2</span><span class="sch-step__title">予定を読込</span><span class="sch-step__ok" role="img" aria-label="完了" hidden><?= icon('check', 'icon--sm') ?></span><span class="sch-step__val muted small" data-step-val></span></summary>
            <div class="sch-step__body">
                <?php // ---- Google カレンダー ---- ?>
                <div class="sch-panel" data-kind-panel="google" data-done="<?= $g !== null && !$g['error'] ? '1' : '0' ?>"<?= $g !== null ? ' data-val="' . h($g['email'] ?? 'Google カレンダー') . '"' : '' ?> hidden>
                    <?php if (!$googleOn): ?>
                        <p class="muted small">現在使えません</p>
                    <?php elseif ($g === null): // Google 連携の開始。押すと sched_google.php が Google の許可画面へ移動する ?>
                        <form method="post" action="sched_google" class="sch-row sch-row--wrap">
                            <?= csrf_field() ?><input type="hidden" name="poll" value="<?= $poll !== null ? $pollId : 0 ?>">
                            <button class="btn btn--primary btn--sm" type="submit"><?= icon('event') ?> Google で連携</button>
                            <a class="muted small" href="privacy#google" target="_blank" rel="noopener">プライバシーポリシー</a>
                        </form>
                    <?php else: ?>
                        <p class="sch-ical">
                            <?= icon($g['error'] ? 'warning' : 'sync', 'icon--sm ' . ($g['error'] ? 'sch-warn' : 'sch-ok')) ?>
                            <strong><?= h($g['email'] ?? 'Google カレンダー') ?></strong>
                            <span class="muted small"><?= $g['fetched_at'] !== null ? h(date('n/j G:i', strtotime((string)$g['fetched_at']))) . ' 読込' : '' ?></span>
                        </p>
                        <?php if ($g['error']): ?><p class="flash flash--error"><?= h($g['error']) ?></p><?php endif; ?>
                        <div class="sch-row sch-row--wrap">
                            <?php if ($g['error']): // 切れたとき（または読み込めないとき）は、もう一度許可してもらう ?>
                                <form method="post" action="sched_google"><?= csrf_field() ?><input type="hidden" name="poll" value="<?= $poll !== null ? $pollId : 0 ?>">
                                    <button class="btn btn--sm btn--primary" type="submit"><?= icon('login') ?> 再連携</button></form>
                            <?php endif; ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="google_refresh"><?= $pollHidden ?>
                                <button class="btn btn--sm" type="submit"><?= icon('refresh') ?> 読み直す</button></form>
                            <form method="post" data-confirm="Google との連携を解除します。取り込んだ予定も消えます。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="action" value="google_disconnect"><?= $pollHidden ?>
                                <button class="btn btn--sm btn--danger" type="submit">解除</button></form>
                        </div>
                        <p class="muted small">決定したスタジオ練習も自動で入ります</p>
                    <?php endif; ?>
                </div>

                <?php // ---- iPhone（iCloud） ---- ?>
                <div class="sch-panel" data-kind-panel="icloud" data-done="<?= $g === null && $prof['ical_url'] !== null && !$prof['ical_error'] ? '1' : '0' ?>"<?= $g === null && $prof['ical_url'] !== null ? ' data-val="' . h(sched_ical_label(sched_ical_decrypt($prof['ical_url']))) . '"' : '' ?> hidden>
                    <?php if (!$icalOn): ?>
                        <p class="muted small">現在使えません</p>
                    <?php elseif ($g !== null): // Google 連携中は iCal の URL を登録できない（解除してから） ?>
                        <p class="muted small">Google 連携中は使えません</p>
                    <?php elseif ($prof['ical_url'] !== null): ?>
                        <p class="sch-ical">
                            <?= icon($prof['ical_error'] ? 'warning' : 'sync', 'icon--sm ' . ($prof['ical_error'] ? 'sch-warn' : 'sch-ok')) ?>
                            <strong><?= h(sched_ical_label(sched_ical_decrypt($prof['ical_url']))) ?></strong>
                            <span class="muted small"><?= $prof['ical_fetched_at'] !== null ? h(date('n/j G:i', strtotime((string)$prof['ical_fetched_at']))) . ' 読込' : '' ?></span>
                        </p>
                        <?php if ($prof['ical_error']): ?><p class="flash flash--error"><?= h($prof['ical_error']) ?></p><?php endif; ?>
                        <div class="sch-row">
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ical_refresh"><?= $pollHidden ?>
                                <button class="btn btn--sm" type="submit"><?= icon('refresh') ?> 読み直す</button></form>
                            <form method="post" data-confirm="カレンダー連携を解除します。取り込んだ予定も消えます。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="action" value="ical_delete"><?= $pollHidden ?>
                                <button class="btn btn--sm btn--danger" type="submit">解除</button></form>
                        </div>
                    <?php else: ?>
                        <ol class="sch-list-steps">
                            <li>iPhone の「カレンダー」アプリ → 下の「カレンダー」→ 取り込むカレンダーの<?= icon('info', 'icon--sm') ?> →「公開カレンダー」をオン</li>
                            <li>「リンクを共有」→「コピー」</li>
                            <li>下の欄に貼り付けて「連携」</li>
                        </ol>
                        <form method="post" class="sch-row">
                            <?= csrf_field() ?><input type="hidden" name="action" value="ical">
                            <?= $pollHidden ?>
                            <input class="search search--plain" type="url" name="url" required placeholder="webcal://…icloud.com/published/…" autocomplete="off" aria-label="iCal の URL">
                            <button class="btn btn--primary btn--sm" type="submit"><?= icon('link') ?> 連携</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php // ---- その他（TimeTree・Outlook など）: 週表示のスクリーンショットを読ませる ---- ?>
                <div class="sch-panel" data-kind-panel="other" hidden>
                    <ol class="sch-list-steps">
                        <li>カレンダーアプリを<strong>週表示</strong>にしてスクリーンショット</li>
                        <li>「画像から読込」で選択（複数枚可）</li>
                    </ol>
                    <?php if (claude_enabled()): ?>
                        <div class="sch-row"><button type="button" class="btn btn--sm" data-photo-jump><?= icon('add_photo_alternate') ?> 画像から読込</button></div>
                    <?php else: ?>
                        <p class="muted small">画像から読込は現在使えません</p>
                    <?php endif; ?>
                    <p class="muted small">Google / iCloud と同期している場合は、上でそちらを選択</p>
                </div>
            </div>
        </details>

        <!-- ③ 練習を自動で追加（Google には無い。購読 URL の鍵が無いサーバーでは出さない） -->
        <?php if ($feedUrl !== null): ?>
        <details class="sch-step" data-step="3" data-feed-ver="<?= h($me . ':' . (int)$prof['feed_version']) ?>"<?= $feedAuto ? ' data-feed-auto' : '' ?> hidden>
            <summary><span class="sch-step__no">3</span><span class="sch-step__title">練習を自動で追加</span><span class="sch-step__ok" role="img" aria-label="完了" hidden><?= icon('check', 'icon--sm') ?></span><span class="sch-step__val muted small" data-step-val></span></summary>
            <div class="sch-step__body">
                <div class="sch-panel" data-kind-panel="icloud" hidden>
                    <div class="sch-row sch-row--wrap">
                        <a class="btn btn--sm" href="<?= h($feedWebcal) ?>" data-feed-done><?= icon('smartphone') ?> iPhone</a>
                    </div>
                </div>
                <div class="sch-panel" data-kind-panel="other" hidden>
                    <div class="sch-row sch-row--wrap">
                        <button type="button" class="btn btn--sm" data-copy-url="<?= h($feedUrl) ?>" data-feed-done aria-label="購読 URL をコピー" title="購読 URL をコピー"><?= icon('content_copy') ?> URL をコピー</button>
                    </div>
                    <p class="muted small">使っているアプリの「URL でカレンダーを追加」に貼り付け</p>
                </div>
                <form method="post" class="sch-row" data-confirm="今の URL は使えなくなります。登録し直してください。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="action" value="feed_reset"><?= $pollHidden ?>
                    <button class="btn btn--sm" type="submit" title="URL を再発行"><?= icon('refresh') ?> 再発行</button></form>
            </div>
        </details>
        <?php endif; ?>
    </div>

    <?php // 決定した練習をカレンダーに入れるときの書式（Google・購読・.ics・追加リンクの全部に効く）。{…} は押すと入力欄に入る ?>
    <?php
    // 書式のプレビュー用の見本（スタジオミュージアム 新宿店の Ast。無ければ決め打ち）。入力するたびに assets/sched.js が書式へ差し込んで表示する
    $evSample = ['sid' => 0, 'vars' => ['バンド名' => 'サンプルバンド', 'スタジオ名' => 'スタジオミュージアム 新宿店', '部屋' => 'Ast', '住所' => '', 'メンバー' => (string)$prof['name'] . '・サンプル', 'URL' => APP_URL . 'sched_poll?id=1']];
    // スタジオの略称（編集欄に入れる値）: 自分で決めたもの（空も含む）→ スタジオ情報の略称 → スタジオ名
    $evShort = static function (array $s) use ($evFmt): string {
        $own = $evFmt['short'][(int)$s['studio_id']] ?? null;
        return $own !== null ? (string)$own : ((string)($s['short_name'] ?? '') !== '' ? (string)$s['short_name'] : (string)$s['name']);
    };
    foreach ($evStudios as $s) {
        if (mb_strpos((string)$s['name'], 'ミュージアム') !== false) {
            $evSample['sid'] = (int)$s['studio_id'];
            $evSample['vars']['スタジオ名'] = (string)$s['name'];
            $evSample['vars']['住所'] = (string)($s['address'] ?? '');
            $evSample['vars']['スタジオ略称'] = $evShort($s);
            break;
        }
    }
    $evSample['vars']['スタジオ略称'] ??= '';
    ?>
    <details class="sch-fold">
        <summary>予定のフォーマット</summary>
        <form method="post" class="sch-evfmt" data-ev-format data-ev-sample="<?= h(json_encode($evSample, JSON_UNESCAPED_UNICODE)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="event_format"><?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
            <label class="sch-evfield"><span class="sch-evfield__name">タイトル</span>
                <input class="search search--plain" type="text" name="ev_title" maxlength="<?= SCHED_EVENT_MAX['title'] ?>" value="<?= h($evFmt['title']) ?>" placeholder="<?= h(SCHED_EVENT_DEFAULTS['title']) ?>" autocomplete="off">
                <span class="sch-evfield__prev" data-ev-preview="ev_title"></span></label>
            <label class="sch-evfield"><span class="sch-evfield__name">場所</span>
                <input class="search search--plain" type="text" name="ev_location" maxlength="<?= SCHED_EVENT_MAX['location'] ?>" value="<?= h($evFmt['location']) ?>" placeholder="<?= h(SCHED_EVENT_DEFAULTS['location']) ?>" autocomplete="off">
                <span class="sch-evfield__prev" data-ev-preview="ev_location"></span></label>
            <label class="sch-evfield"><span class="sch-evfield__name">メモ</span>
                <textarea class="search search--plain" name="ev_details" rows="3" maxlength="<?= SCHED_EVENT_MAX['details'] ?>" placeholder="<?= h(SCHED_EVENT_DEFAULTS['details']) ?>"><?= h($evFmt['details']) ?></textarea>
                <span class="sch-evfield__prev sch-evfield__prev--multi" data-ev-preview="ev_details"></span></label>
            <div class="sch-row sch-row--wrap">
                <?php foreach (SCHED_EVENT_VARS as $v): ?><button type="button" class="btn btn--sm" data-ev-ins="{<?= h($v) ?>}">{<?= h($v) ?>}</button><?php endforeach; ?>
            </div>
            <details class="sch-evshort">
                <summary class="muted small">スタジオ略称を編集（自分のみ適用）</summary>
                <?php foreach ($evStudios as $s): $sid = (int)$s['studio_id']; ?>
                    <label class="sch-row"><span class="grow"><?= h($s['name']) ?></span>
                        <input class="search search--plain" type="text" name="short[<?= $sid ?>]" maxlength="30" value="<?= h($evShort($s)) ?>" autocomplete="off" aria-label="<?= h($s['name']) ?>の略称"></label>
                <?php endforeach; ?>
            </details>
            <div class="sch-row">
                <button class="btn btn--primary btn--sm" type="submit"><?= icon('check') ?> 保存</button>
                <?php if ($evFmt['custom'] || $evFmt['short']): ?><button class="btn btn--sm" type="submit" name="reset" value="1" formnovalidate><?= icon('restart_alt') ?> 初期に戻す</button><?php endif; ?>
            </div>
        </form>
    </details>
</div>
</details>


<!-- ===== 毎週の NG ===== -->
<section class="card sch-sec" id="weekly">
    <div class="sch-sec__head">
        <h2 class="section-title section-title--card">繰り返しの予定</h2>
        <span class="info-tip" <?= tip_attr('バイトなど毎週の予定。削除するまで毎週、予定ありとして扱います') ?>><?= icon('info', 'icon--sm') ?></span>
    </div>
    <?php foreach ($weekly as $w): ?>
        <form method="post" class="sch-row sch-weekly-row">
            <?= csrf_field() ?><input type="hidden" name="action" value="weekly_delete"><input type="hidden" name="id" value="<?= (int)$w['sched_weekly_id'] ?>">
            <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
            <span><?= h((SCHED_ME_WEEKDAYS[(int)$w['weekday']] ?? '') . ' ' . sched_hm((int)$w['start_min']) . '〜' . sched_hm((int)$w['end_min'])) ?></span>
            <button class="icon-btn icon-btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('close') ?></button>
        </form>
    <?php endforeach; ?>
    <form method="post" class="sch-row sch-row--wrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="weekly_add">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
        <select class="select-sm" name="weekday" aria-label="曜日">
            <?php foreach (SCHED_ME_WEEKDAYS as $n => $label): ?><option value="<?= $n ?>"><?= h($label) ?></option><?php endforeach; ?>
        </select>
        <select class="select-sm" name="start" aria-label="開始"><?= $weeklyTimes(0, 1410, 1080) ?></select>
        <select class="select-sm" name="end" aria-label="終了"><?= $weeklyTimes(30, 1440, 1320) ?></select>
        <button class="btn btn--primary btn--sm" type="submit">追加</button>
    </form>
</section>

<!-- ===== 時間割 ===== -->
<section class="card sch-sec" id="classes">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">時間割</h2></div>
    <div class="tabs tabs--filter" role="tablist" data-sched-terms>
        <?php foreach (SCHED_TERMS as $t => $label): ?>
            <button type="button" class="tab<?= $t === $curTerm ? ' is-active' : '' ?>" data-term-tab="<?= $t ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach (SCHED_TERMS as $t => $label): ?>
        <?php // サブのキャンパスがあるときだけ、マスをタップするたびに 空 → メイン → サブ → 空 と回す（assets/sched.js）。頭文字はキャンパス名の1文字目 ?>
        <form method="post" class="sch-tt" data-term="<?= $t ?>" data-autosave<?= $subCampus ? ' data-main-mark="' . h(mb_substr(SCHED_CAMPUSES[$mainCampus], 0, 1)) . '" data-sub-mark="' . h(mb_substr(SCHED_CAMPUSES[$subCampus], 0, 1)) . '"' : '' ?><?= $t === $curTerm ? '' : ' hidden' ?>>
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
                            <?php foreach (SCHED_WEEKDAYS as $w => $wl): $key = $w . '-' . $p; $kind = $classes[$t][$key] ?? null; ?>
                                <?php // サブがあるときだけ、サブ用の隠しチェック（sub[]）と頭文字（data-mark）を置く。sub を cls の前に置くのは、CSS で「サブの授業」を隣の指定で拾うため ?>
                                <td><label class="sch-tt__cell"><?php if ($subCampus): ?><input type="checkbox" name="sub[]" value="<?= $key ?>"<?= $kind === 'sub' ? ' checked' : '' ?> aria-label="<?= h($wl . $p . '限（' . SCHED_CAMPUSES[$subCampus] . '）') ?>"><?php endif; ?><input type="checkbox" name="cls[]" value="<?= $key ?>"<?= $kind !== null ? ' checked' : '' ?> aria-label="<?= h($wl . $p . '限') ?>"><span<?= $subCampus ? ' data-mark="' . h(mb_substr(SCHED_CAMPUSES[$kind === 'sub' ? $subCampus : $mainCampus], 0, 1)) . '"' : '' ?>></span></label></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endforeach; ?>
    <?php // キャンパス（授業の前後の移動時間の計算に使う）。時間割と同じカードに置く ?>
    <div class="sch-sec__head sch-sec__head--sub" id="campus"><h3 class="sch-sec__sub">キャンパス</h3>
        <span class="info-tip" <?= tip_attr('授業の前後の移動時間の計算に使用します') ?>><?= icon('info', 'icon--sm') ?></span></div>
    <form method="post" class="sch-campus">
        <?= csrf_field() ?><input type="hidden" name="action" value="campus">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
        <?php // 2行: メイン（4キャンパス）とサブ（なし / 4キャンパス）。同じフォームなので、片方を押すと両方の値が送られる ?>
        <div class="sch-campus__row">
            <span class="sch-campus__label">メイン</span>
            <div class="tabs tabs--filter" role="radiogroup" aria-label="メインのキャンパス">
                <?php // 「自動」のタブは無い。選んでいなければ、学部・学年から決まったキャンパス（$mainCampus）が最初から選ばれている ?>
                <?php foreach (SCHED_CAMPUSES as $c => $name): ?>
                    <label class="tab"><input type="radio" name="campus" value="<?= $c ?>" data-autosubmit<?= $mainCampus === $c ? ' checked' : '' ?>><?= h($name) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sch-campus__row">
            <span class="sch-campus__label">サブ</span>
            <div class="tabs tabs--filter" role="radiogroup" aria-label="サブのキャンパス">
                <label class="tab"><input type="radio" name="campus2" value="0" data-autosubmit<?= $subCampus === 0 ? ' checked' : '' ?>>なし</label>
                <?php foreach (SCHED_CAMPUSES as $c => $name): ?>
                    <label class="tab"><input type="radio" name="campus2" value="<?= $c ?>" data-autosubmit<?= $subCampus === $c ? ' checked' : '' ?>><?= h($name) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <noscript><button class="btn btn--sm" type="submit">保存</button></noscript>
    </form>
</section>

<!-- ===== 定期（交通費は定期の区間を除いて計算する） ===== -->
<section class="card sch-sec" id="pass">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">最寄り駅と定期券設定</h2>
        <span class="info-tip" <?= tip_attr('所要時間と運賃を計算して、スタジオの選択時に使用します') ?>><?= icon('info', 'icon--sm') ?></span></div>
    <form method="post" class="sch-pass">
        <?= csrf_field() ?><input type="hidden" name="action" value="pass">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
        <datalist id="sch-stations"><?php foreach (sched_station_suggestions($pdo) as $stn): ?><option value="<?= h($stn) ?>"><?php endforeach; ?></datalist>
        <input class="search search--plain" type="text" name="home_station" maxlength="30" value="<?= h((string)$prof['home_station']) ?>" placeholder="自宅最寄り駅" autocomplete="off" list="sch-stations" aria-label="自宅最寄り駅">
        <div class="sch-row sch-row--wrap"><?php /* 〜から先（到着駅・保存）は、自宅最寄り駅の下の行 */ ?>
        <span>〜</span>
        <?php
        // 到着駅: 先頭が大学の最寄り駅。「カスタム」を選ぶと駅名を自由入力（保存済みの駅名が一覧に無ければカスタムを選んだ状態）
        $stations = SCHED_PASS_STATIONS[$mainCampus];
        if ($subCampus) { // サブのキャンパスがあれば、その最寄り駅（候補の先頭）も足す（メインの候補に無いときだけ）
            $subStation = SCHED_PASS_STATIONS[$subCampus][0];
            if (!in_array($subStation, $stations, true)) {
                $stations[] = $subStation;
            }
        }
        $savedTo = (string)$prof['pass_to'];
        $isCustom = $savedTo !== '' && !in_array($savedTo, $stations, true);
        ?>
        <select name="pass_to" class="select-sm" aria-label="定期の到着駅" data-pass-to>
            <option value="">定期なし</option>
            <?php foreach ($stations as $i => $stn): ?><option value="<?= h($stn) ?>"<?= $savedTo === $stn ? ' selected' : '' ?>><?= h($stn) ?><?= $i === 0 ? '（最寄り）' : '' ?></option><?php endforeach; ?>
            <option value="__custom__"<?= $isCustom ? ' selected' : '' ?>>カスタム</option>
        </select>
        <input class="search search--plain" type="text" name="pass_to_custom" maxlength="30" value="<?= $isCustom ? h($savedTo) : '' ?>" placeholder="駅名" autocomplete="off" list="sch-stations" aria-label="カスタムの到着駅"<?= $isCustom ? '' : ' hidden' ?>>
        <script>
        // カスタムを選んだときだけ駅名の入力欄を出す
        document.querySelectorAll('select[data-pass-to]').forEach(function (sel) {
            var input = sel.form.querySelector('input[name="pass_to_custom"]');
            sel.addEventListener('change', function () { input.hidden = sel.value !== '__custom__'; });
        });
        </script>
        <button class="btn btn--sm" type="submit">保存</button>
        </div>
    </form>
    <form method="post" class="sch-row">
        <?= csrf_field() ?><input type="hidden" name="action" value="pass_on">
        <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
        <label class="check"><input type="checkbox" name="pass_on" value="1" data-autosubmit<?= (int)$prof['pass_on'] === 1 ? ' checked' : '' ?>>運賃に定期を反映</label>
        <noscript><button class="btn btn--sm" type="submit">保存</button></noscript>
    </form>
    <?php
    // 定期の経路の候補（複数あるとき、自分の定期に合うものを選ぶ）。候補は「保存」のときに調べて覚えてある
    $passCands = (string)$prof['home_station'] !== '' && (string)$prof['pass_to'] !== '' ? sched_pass_candidates($pdo, (string)$prof['home_station'], (string)$prof['pass_to'], false) : [];
    $passVia = json_decode((string)$prof['pass_via'], true);
    if (count($passCands) > 1): ?>
        <form method="post" class="sch-pass-via">
            <?= csrf_field() ?><input type="hidden" name="action" value="pass_via">
            <?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
            <div class="tabs tabs--filter tabs--stack" role="radiogroup" aria-label="定期の経路">
                <?php foreach ($passCands as $i => $c): ?>
                    <label class="tab"><input type="radio" name="via" value="<?= $i ?>" data-autosubmit<?= is_array($passVia) && ($passVia['ids'] ?? null) === $c['ids'] ? ' checked' : '' ?>><?= h($c['label']) ?></label>
                <?php endforeach; ?>
            </div>
            <noscript><button class="btn btn--sm" type="submit">保存</button></noscript>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/ical_help.php'; // ⓘ から開く「カレンダーの連携方法」（初回チュートリアルと同じ部品） ?>
<?php require __DIR__ . '/partials/sched_help.php'; // 見出しの ⓘ から開く「マイスケジュールの使い方」（4枚のスクリーンショット） ?>
<script src="<?= asset_url('assets/sched.js') ?>" defer></script>
<?php render_footer();
