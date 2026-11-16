<?php
/**
 * =====================================================================
 *  sched_me.php — マイスケジュール（日程調整に使う「自分の埋まり」の入力）
 * =====================================================================
 *  GET
 *    sched_me                 … 今日から1週間の NG 枠（?from=2026-11-20 で週を移動）
 *    sched_me?poll=12         … その日程調整の期間・時間帯で NG 枠を出す（&week=1 で次の7日）
 *  POST（CSRF チェック）
 *    campus  … キャンパス（自動 / 和泉 / 駿河台）
 *    classes … 時間割（春・秋）。チェックの付いたコマを入れ直す
 *    copy    … もう片方の学期の時間割を写す
 *    ical / ical_refresh / ical_delete … カレンダー連携（iCal の URL）
 *    google_refresh / google_disconnect … カレンダー連携（Google。連携を始めるのは sched_google.php。lib/sched_google.php）
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
/** 毎週の NG の曜日（1 = 月 〜 7 = 日。date('N') と同じ）。時間割の SCHED_WEEKDAYS は日曜が無いので、日を足す */
const SCHED_ME_WEEKDAYS = SCHED_WEEKDAYS + [7 => '日'];
/** 1人が登録できる毎週の NG の数（増やしすぎて画面と計算が重くならないように） */
const SCHED_ME_WEEKLY_MAX = 20;
$user = require_admin(); // 完成するまで管理者だけ
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
        $c = (int)($_POST['campus'] ?? 0);
        $ensureProfile();
        $pdo->prepare('UPDATE sched_profile SET campus = ? WHERE member_id = ?')->execute([isset(SCHED_CAMPUSES[$c]) ? $c : null, $me]);
        flash('キャンパスを保存しました');
        redirect($back . '#campus');
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
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM sched_class WHERE member_id = ? AND term = ?')->execute([$me, $term]);
        $ins = $pdo->prepare('INSERT INTO sched_class (member_id, term, weekday, period) VALUES (?, ?, ?, ?)');
        foreach ($cells as [$w, $p]) {
            $ins->execute([$me, $term, $w, $p]);
        }
        $pdo->commit();
        if ($auto) { http_response_code(204); exit; }
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
        if ($g !== null) {
            flash('Google 連携を解除してから登録してください', 'error');
            redirect($back . '#ical');
        }
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
        if ($g !== null) {
            redirect($back . '#ical');
        }
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
                if ($b[0] === $s && $b[1] === $e && in_array($b[3], ['class', 'ical', 'photo', 'weekly'], true)) {
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
            flash('毎週の予定ありは' . SCHED_ME_WEEKLY_MAX . '件までです', 'error');
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
$st = $pdo->prepare('SELECT m.name, m.entry_year, p.campus, p.ical_url, p.ical_fetched_at, p.ical_error, COALESCE(p.feed_version, 0) AS feed_version FROM member m LEFT JOIN sched_profile p ON p.member_id = m.member_id WHERE m.member_id = ?');
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
if ($g !== null) {
    sched_google_refresh_busy($pdo, $me); // Google 連携中は Google から読む（iCal は使わない）
    $g = sched_google_row($pdo, $me); // 読み込みの結果（読んだ日時・エラー）を画面に出すため、読み直す
} else {
    sched_ical_refresh($pdo, $me); // 古ければ読み直す（30分以内に読んでいれば何もしない）
}
$data = sched_collect_busy($pdo, [$me], $days[0], $days[count($days) - 1], $poll === null ? null : $pollId, true, $me); // 最後の $me = 自分の iCal の予定のタイトルを復号して見せる
$grid = [
    'days' => array_map(static fn($d) => ['date' => $d, 'md' => sched_md($d), 'abs' => sched_day_abs($d), 'dow' => (int)date('w', strtotime($d))], $days),
    'start' => $ws, 'end' => $we, 'step' => SCHED_STEP,
    // 埋まり: [開始, 終了, 種類, ラベル, 無視中か]（場所は画面に要らないので落とす）
    'busy' => array_map(static fn($b) => [$b[0], $b[1], $b[3], $b[4], !empty($b[5])], $data['busy'][$me] ?? []),
    'warn' => array_values(array_map(static fn($p) => ['date' => $p['date'], 'title' => $p['title'], 's' => $p['s'], 'e' => $p['e']], array_filter($data['practices'], static fn($p) => in_array($me, $p['members'], true) && $p['status'] < 3))),
];
$icalOn = sched_ical_available();

render_header('マイスケジュール', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
<nav class="crumbs sch-crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタジオ練習の日程調整</a><?php if ($poll !== null): ?><span>/</span><a href="sched_poll?id=<?= $pollId ?>"><?= h($poll['title']) ?></a><?php endif; ?></nav>
<h1 class="display display--sm">マイスケジュール</h1>

<!-- ===== NG の時間 ===== -->
<section class="card sch-sec" id="ng">
    <div class="sch-sec__head">
        <h2 class="section-title section-title--card">スケジュールを編集</h2>
        <span class="info-tip" <?= tip_attr('マスをタップ・なぞって予定ありを塗る。日付をタップで1日まるごと。授業・カレンダーの予定・毎週の予定あり・決定した練習は自動で入る。自動で入った予定をタップすると、その1回分を無視できる') ?>><?= icon('info', 'icon--sm') ?></span>
    </div>
    <div class="sch-weeknav">
        <?php if ($prevUrl !== null): ?><a class="icon-btn" href="<?= h($prevUrl) ?>#ng" aria-label="前の週" title="前の週"><?= icon('chevron_left') ?></a><?php else: ?><span></span><?php endif; ?>
        <strong><?= h(sched_md($days[0]) . '〜' . sched_md($days[count($days) - 1])) ?></strong>
        <?php if ($nextUrl !== null): ?><a class="icon-btn" href="<?= h($nextUrl) ?>#ng" aria-label="次の週" title="次の週"><?= icon('chevron_right') ?></a><?php else: ?><span></span><?php endif; ?>
    </div>
    <div class="sch-legend small muted">
        <span><i class="sch-sw is-ng"></i>予定あり</span><span><i class="sch-sw is-class"></i>授業</span><span><i class="sch-sw is-ical"></i>カレンダー</span><span><i class="sch-sw is-photo"></i>画像</span><span><i class="sch-sw is-weekly"></i>毎週</span><span><i class="sch-sw is-practice"></i>練習</span>
    </div>
    <div class="sch-grid-wrap" data-sched-grid></div>
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
    <?php if ($poll !== null): ?>
        <p class="sch-answer"><a class="btn btn--ghost" href="sched_poll?id=<?= $pollId ?>"><?= icon('chevron_left') ?> 候補・回答完了へ</a></p>
    <?php endif; ?>
</section>
<script type="application/json" id="sched-grid-data"><?= json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<!-- ===== 毎週の NG ===== -->
<section class="card sch-sec" id="weekly">
    <div class="sch-sec__head">
        <h2 class="section-title section-title--card">毎週の予定あり</h2>
        <span class="info-tip" <?= tip_attr('バイトなど毎週くり返す予定。削除するまで毎週、予定ありとして扱う') ?>><?= icon('info', 'icon--sm') ?></span>
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
        <form method="post" class="sch-tt" data-term="<?= $t ?>" data-autosave<?= $t === $curTerm ? '' : ' hidden' ?>>
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
        <div class="tabs tabs--filter" role="radiogroup" aria-label="キャンパス">
            <label class="tab"><input type="radio" name="campus" value="0" data-autosubmit<?= $chosenCampus === 0 ? ' checked' : '' ?>>自動（<?= h(SCHED_CAMPUSES[$autoCampus]) ?>）</label>
            <?php foreach (SCHED_CAMPUSES as $c => $name): ?>
                <label class="tab"><input type="radio" name="campus" value="<?= $c ?>" data-autosubmit<?= $chosenCampus === $c ? ' checked' : '' ?>><?= h($name) ?></label>
            <?php endforeach; ?>
        </div>
        <noscript><button class="btn btn--sm" type="submit">保存</button></noscript>
    </form>
</section>

<!-- ===== カレンダー連携 ===== -->
<section class="card sch-sec" id="ical">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">カレンダー連携</h2>
        <button type="button" class="info-tip" data-tutorial-open="ical-help" aria-label="カレンダー連携のやり方" title="カレンダー連携のやり方"><?= icon('info', 'icon--sm') ?></button></div>
    <?php if ($googleOn && $g === null): // Google 連携の開始。押すと sched_google.php が Google の許可画面へ移動する（iPhone の人は、下の iCal の URL を使う） ?>
        <form method="post" action="sched_google" class="sch-row">
            <?= csrf_field() ?><input type="hidden" name="poll" value="<?= $poll !== null ? $pollId : 0 ?>">
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('event') ?> Google で連携</button>
        </form>
    <?php elseif ($g !== null): ?>
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
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="google_refresh"><?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
                <button class="btn btn--sm" type="submit"><?= icon('refresh') ?> 読み直す</button></form>
            <form method="post" data-confirm="Google との連携を解除します。取り込んだ予定も消えます。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="action" value="google_disconnect"><?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
                <button class="btn btn--sm btn--danger" type="submit">解除</button></form>
        </div>
    <?php endif; ?>
    <?php if ($g !== null): // Google 連携中は iCal の URL を使わない（上の連携で足りる）。 ?>
    <?php elseif (!$icalOn): ?>
        <?php if (!$googleOn): ?><p class="muted small">現在使えません（管理者の設定待ち）</p><?php endif; ?>
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
    <?php // 決定した練習を、自分のカレンダーへ自動で入れる（購読 URL。sched_feed.php）。鍵が無いサーバーでは出さない
    $feedUrl = sched_feed_url($me, (int)$prof['feed_version']);
    if ($feedUrl !== null):
        $feedWebcal = preg_replace('~^https://~', 'webcal://', $feedUrl); // webcal:// … 「購読する」の意味のアドレス。タップするとカレンダーアプリが開く ?>
        <div class="sch-feed">
            <span class="sch-label">決定した練習を追加</span>
            <div class="sch-row sch-row--wrap">
                <?php if ($g === null): // Google 連携中は、決定した練習が連携で直接入る（購読も入れると二重になる） ?>
                <a class="btn btn--sm" href="https://calendar.google.com/calendar/render?cid=<?= h(urlencode($feedWebcal)) ?>" target="_blank" rel="noopener"><?= icon('event') ?> Google</a>
                <?php endif; ?>
                <a class="btn btn--sm" href="<?= h($feedWebcal) ?>"><?= icon('smartphone') ?> iPhone</a>
                <form method="post" data-confirm="今の URL は使えなくなります。登録し直してください。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="action" value="feed_reset"><?php if ($poll !== null): ?><input type="hidden" name="poll" value="<?= $pollId ?>"><?php endif; ?>
                    <button class="btn btn--sm" type="submit" title="URL を再発行"><?= icon('refresh') ?> 再発行</button></form>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/ical_help.php'; // ⓘ から開く「カレンダー連携のやり方」（初回チュートリアルと同じ部品） ?>
<script src="<?= asset_url('assets/sched.js') ?>" defer></script>
<?php render_footer();
