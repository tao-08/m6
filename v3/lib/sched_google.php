<?php
/**
 * =====================================================================
 *  lib/sched_google.php — Google カレンダーとの連携（OAuth）。予定の読み込みと、決定した練習の書き込み
 * =====================================================================
 *  ■ OAuth とは
 *    「パスワードを渡さずに、Google の許可を得て、その人のカレンダーを触らせてもらう」仕組み。流れは次のとおり。
 *      1. 部員が「Google で連携」を押す → Google の許可画面へ移動（sched_google.php）
 *      2. 部員が「許可」を押す → Google が「code（引換券）」を付けて、このサイトに戻してくる
 *      3. サイトが code を Google に渡して「トークン（合言葉）」に交換する（sched_google_exchange）
 *           refresh_token … 長く使える。これで新しい access_token をもらえる（なので、漏れると危ない）
 *           access_token  … 約1時間で切れる。API を呼ぶときは、この合言葉を付ける
 *    トークンは暗号化して DB に入れる（lib/sched_ical.php の sched_ical_encrypt。iCal の URL と同じ鍵。鍵が無いサーバーでは機能ごと休む）
 *
 *  ■ 守っていること
 *    1. state … 許可画面へ行く前にセッションに入れた乱数。戻ってきたときに同じか確かめる（CSRF 対策。他人が用意した連携を踏まされない）
 *    2. PKCE（ピクシー）… code_verifier（乱数）のハッシュ（code_challenge）を先に Google に渡し、交換のときに元の乱数を見せる。
 *       code を盗まれても、元の乱数を知らない人は交換できない
 *    3. 取る権限は2つだけ（予定の読み書き・カレンダーの一覧の読み取り）。メールやドライブなど、ほかは見られない
 *    4. 通信の失敗の詳しい内容は error_log にだけ出す（トークンは書かない）。画面には短い言葉だけ
 *
 *  ■ 読み込み … sched_google_refresh_busy（iCal の取り込みと同じルールで sched_busy の source = 1 に入れる）
 *  ■ 書き込み … sched_google_sync_poll / sched_google_sync_fix（決定した練習を、連携中の部員の「メインのカレンダー（primary）」に入れる・直す・消す）
 *    決定1回（sched_fix）× 部員 → Google の予定 ID は sched_google_event に覚える（あとで直す・消すのに要る）。1つの調整で何回も決められるので、予定も決定1回ごとに1つ
 *    予定には abbeySched（決定1回の id）を付けておく。読み込みのときに、このサイトが書いた予定は取り込まない
 *    （自分の練習が、「予定あり」として二重に数えられないように）
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/sched_ical.php';
require_once __DIR__ . '/sched.php';

/** 取る権限。これ以上は取らない */
const SCHED_GOOGLE_SCOPES = [
    'https://www.googleapis.com/auth/calendar.events',                // 予定の読み書き
    'https://www.googleapis.com/auth/calendar.calendarlist.readonly', // 表示中のカレンダーの一覧（読み取りだけ）
];
const SCHED_GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const SCHED_GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const SCHED_GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
const SCHED_GOOGLE_API = 'https://www.googleapis.com/calendar/v3/';
/** 取り込むカレンダーの数・1つのカレンダーを読むページ数（1ページ 250 件）の上限。読み込みが際限なく重くならないように */
const SCHED_GOOGLE_MAX_CALENDARS = 20;
const SCHED_GOOGLE_MAX_PAGES = 4;
/** 1人ぶんの読み込み全体・練習1件ぶんの書き込み全体の、待ってよい秒数（Google が遅いときに、画面が固まり続けないため） */
const SCHED_GOOGLE_READ_SECONDS = 15;
const SCHED_GOOGLE_SYNC_SECONDS = 20;
/** 連携が切れたときの error の文言（画面に出す。これが入っている間は、自動では Google に問い合わせ直さない） */
const SCHED_GOOGLE_REVOKED = '連携が切れました。再連携してください';

/* =====================================================================
 *  設定・準備
 * ===================================================================== */

/** config.php の google_oauth（無い・配列でなければ空）。テストでは config() が null を返すので、そのまま空になる */
function sched_google_config(): array
{
    $c = config('google_oauth');
    return is_array($c) ? $c : [];
}

/**
 * Google 連携が使えるか。client_id・client_secret が入っていて、トークンを暗号化する鍵があり、curl が使えるときだけ true
 *   false のときは、ボタンも出さない・同期もしない（呼び出し側は、何もせず戻る）
 */
function sched_google_available(): bool
{
    $c = sched_google_config();
    return (string)($c['client_id'] ?? '') !== '' && (string)($c['client_secret'] ?? '') !== ''
        && function_exists('curl_init') && sched_ical_key() !== null;
}

/** Google が許可のあとに戻してくる URL。Google Cloud Console の「承認済みのリダイレクト URI」と一字一句同じにする */
function sched_google_redirect_uri(): string
{
    return (defined('APP_URL') ? APP_URL : 'https://abbeyroad.online/') . 'sched_google'; // defined … テストでは bootstrap を読まないので、定数があるか先に確かめる
}

/** base64url … 普通の base64 から + / = を無くしたもの（URL にそのまま入れられる。PKCE の決まり） */
function sched_google_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** PKCE の code_verifier（乱数 64 文字。規格は 43〜128 文字）。random_bytes … 推測できない乱数（暗号用） */
function sched_google_new_verifier(): string
{
    return sched_google_b64url(random_bytes(48));
}

/** code_challenge … verifier の SHA-256 ハッシュ（S256 方式）。Google にはこちらだけを先に渡す */
function sched_google_challenge(string $verifier): string
{
    return sched_google_b64url(hash('sha256', $verifier, true));
}

/**
 * Google の許可画面の URL
 *   access_type=offline   … refresh_token ももらう（これが無いと、1時間で連携が切れる）
 *   prompt=consent        … 毎回、許可の画面を出す（出さないと、2回目以降は refresh_token が返ってこないことがある）
 *   include_granted_scopes … 前に許可した権限も足して扱う
 *   $cfg はテスト用（config.php が無くても組み立てられるように、client_id を渡せる）
 */
function sched_google_auth_url(string $state, string $verifier, ?array $cfg = null): string
{
    $cfg ??= sched_google_config();
    return SCHED_GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id'              => (string)($cfg['client_id'] ?? ''),
        'redirect_uri'           => sched_google_redirect_uri(),
        'response_type'          => 'code',
        'scope'                  => implode(' ', SCHED_GOOGLE_SCOPES),
        'state'                  => $state,
        'code_challenge'         => sched_google_challenge($verifier),
        'code_challenge_method'  => 'S256',
        'access_type'            => 'offline',
        'prompt'                 => 'consent',
        'include_granted_scopes' => 'true',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Google が返した許可済みの権限（空白区切り）に、必要な2つが両方入っているか。許可画面で片方のチェックを外されると足りなくなる */
function sched_google_scopes_ok(string $granted): bool
{
    return array_diff(SCHED_GOOGLE_SCOPES, preg_split('/\s+/', trim($granted)) ?: []) === [];
}

/* =====================================================================
 *  通信（cURL）
 * ===================================================================== */

/**
 * HTTPS で1回通信する。戻り値: ['status' => HTTP の番号（つながらなければ 0）, 'json' => 返事の JSON（無い・壊れていれば null）]
 *   既存の album_http は GET と POST 用で、PATCH / DELETE が使えないので、ここに小さく作る（書き方は sched_ical_fetch に合わせた）
 *   ・https 以外は使わない・リダイレクトは追わない（Authorization 付きのまま別の場所へ飛ばされないため）
 *   ・待ち時間は短め（つながるまで 3 秒・全体 7 秒）。詳しい失敗の理由は error_log にだけ残す（URL の ? 以降は書かない）
 */
function sched_google_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 7,
        CURLOPT_USERAGENT      => 'AbbeyRoad.online sched',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($raw === false) {
        error_log('sched_google_http: ' . $method . ' ' . (string)parse_url($url, PHP_URL_PATH) . ' ' . $err);
        return ['status' => 0, 'json' => null];
    }
    $json = json_decode((string)$raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null];
}

/** Calendar API を呼ぶ。$path は SCHED_GOOGLE_API からの続き（例: "calendars/primary/events"）。$json があれば JSON の本文にして送る */
function sched_google_api(string $method, string $path, string $token, array $query = [], ?array $json = null): array
{
    $url = SCHED_GOOGLE_API . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json']; // Bearer … 「この合言葉を持っている人」という意味の付け方
    $body = null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return sched_google_http($method, $url, $headers, $body);
}

/** token エンドポイントを呼ぶ（フォーム形式の POST）。client_id・client_secret は、ここで付ける */
function sched_google_token_request(array $params): array
{
    $c = sched_google_config();
    return sched_google_http('POST', SCHED_GOOGLE_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query($params + ['client_id' => (string)($c['client_id'] ?? ''), 'client_secret' => (string)($c['client_secret'] ?? '')], '', '&', PHP_QUERY_RFC3986));
}

/** 失敗した API の理由を error_log に出す（Google が返す error.message と reason だけ。トークンは含まれない） */
function sched_google_log(string $what, array $res): void
{
    $e = $res['json']['error'] ?? null;
    $msg = is_array($e) ? (string)($e['message'] ?? '') . ' ' . (string)($e['errors'][0]['reason'] ?? '') : (string)($res['json']['error_description'] ?? $e ?? '');
    error_log('sched_google: ' . $what . ' status=' . $res['status'] . ' ' . trim(mb_substr($msg, 0, 200)));
}

/** HTTP の番号 → 画面に出す短い言葉 */
function sched_google_status_text(int $status): string
{
    return $status === 0 ? '接続できません' : '読み込めません（' . $status . '）';
}

/* =====================================================================
 *  トークン
 * ===================================================================== */

/** 連携の行（無ければ null）。連携しているか・最後に読んだ日時・エラーを見るのに使う */
function sched_google_row(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT member_id, refresh_token, access_token, access_expires, email, fetched_at, error FROM sched_google WHERE member_id = ?');
    $st->execute([$memberId]);
    return $st->fetch() ?: null;
}

/** code（引換券）をトークンに交換する。失敗は null。戻り値は Google の返事そのまま（access_token・refresh_token・expires_in・scope） */
function sched_google_exchange(string $code, string $verifier): ?array
{
    $res = sched_google_token_request([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'code_verifier' => $verifier,
        'redirect_uri'  => sched_google_redirect_uri(),
    ]);
    if ($res['status'] !== 200 || empty($res['json']['access_token'])) {
        sched_google_log('exchange', $res);
        return null;
    }
    return $res['json'];
}

/** 新しい access_token を DB に入れる（暗号化。期限は少し短めに見て、切れる直前に使わないようにする） */
function sched_google_store_access(PDO $pdo, int $memberId, array $tok): void
{
    $life = max(60, min(3600, (int)($tok['expires_in'] ?? 3600)));
    $pdo->prepare('UPDATE sched_google SET access_token = ?, access_expires = ?, error = NULL WHERE member_id = ?')
        ->execute([sched_ical_encrypt((string)$tok['access_token']), date('Y-m-d H:i:s', time() + $life), $memberId]);
}

/**
 * 使える access_token を返す（期限の60秒前を過ぎていたら、refresh_token でもらい直す）。取れなければ null（理由は $error）
 *   invalid_grant（本人が Google 側で連携を取り消した・パスワードを変えた など）は、直しようがないので
 *   error に「連携が切れました。再連携してください」を入れる（画面に出る）
 */
function sched_google_access_token(PDO $pdo, int $memberId, ?string &$error = null): ?string
{
    $row = sched_google_row($pdo, $memberId);
    if ($row === null) {
        $error = '連携していません';
        return null;
    }
    if ($row['access_token'] !== null && $row['access_expires'] !== null && strtotime((string)$row['access_expires']) > time() + 60) {
        $token = sched_ical_decrypt($row['access_token']);
        if ($token !== null) {
            return $token;
        }
    }
    $refresh = sched_ical_decrypt($row['refresh_token']);
    if ($refresh === null) {
        $error = SCHED_GOOGLE_REVOKED; // 鍵を変えた等で読めない。やり直してもらうしかない
        $pdo->prepare('UPDATE sched_google SET access_token = NULL, access_expires = NULL, error = ? WHERE member_id = ?')->execute([$error, $memberId]);
        return null;
    }
    $res = sched_google_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    if ($res['status'] === 400 && ($res['json']['error'] ?? '') === 'invalid_grant') {
        $error = SCHED_GOOGLE_REVOKED;
        $pdo->prepare('UPDATE sched_google SET access_token = NULL, access_expires = NULL, error = ? WHERE member_id = ?')->execute([$error, $memberId]);
        return null;
    }
    if ($res['status'] !== 200 || empty($res['json']['access_token'])) {
        sched_google_log('refresh', $res);
        $error = sched_google_status_text($res['status']); // 一時的な失敗かもしれないので、DB には書かない（次に試す）
        return null;
    }
    sched_google_store_access($pdo, $memberId, $res['json']);
    return (string)$res['json']['access_token'];
}

/* =====================================================================
 *  カレンダーの一覧・予定の読み込み
 * ===================================================================== */

/**
 * 表示中のカレンダーの一覧 [['id' => …, 'primary' => bool, 'selected' => bool, 'deleted' => bool], …]。失敗は RuntimeException（画面に出す言葉つき）
 *   minAccessRole=reader … 予定の中身を読めるカレンダーだけ（「空き時間だけ見える」カレンダーは events.list が 403 になる）
 *   fields … 必要な項目だけ返してもらう（通信が軽くなる）
 */
function sched_google_calendars(string $token): array
{
    $res = sched_google_api('GET', 'users/me/calendarList', $token, [
        'minAccessRole' => 'reader', 'maxResults' => 250, 'fields' => 'items(id,summary,summaryOverride,primary,selected,deleted)',
    ]);
    if ($res['status'] !== 200 || !is_array($res['json']['items'] ?? null)) {
        sched_google_log('calendarList', $res);
        throw new RuntimeException(sched_google_status_text($res['status']));
    }
    $out = [];
    foreach ($res['json']['items'] as $it) {
        if (is_array($it) && is_string($it['id'] ?? null)) {
            $name = is_string($it['summaryOverride'] ?? null) && $it['summaryOverride'] !== '' ? $it['summaryOverride'] : (is_string($it['summary'] ?? null) ? $it['summary'] : $it['id']);
            $out[] = ['id' => $it['id'], 'name' => mb_substr($name, 0, 100), 'primary' => !empty($it['primary']), 'selected' => !empty($it['selected']), 'deleted' => !empty($it['deleted'])];
        }
    }
    return $out;
}

/**
 * 選べるカレンダー（削除済みと、祝日・誕生日・天気を除く）。選択画面に出すもの
 *   祝日・誕生日は、終日の予定がぜんぶ「1日予定あり」になってしまうため、選ばせない
 */
function sched_google_selectable(array $calendars): array
{
    return array_values(array_filter($calendars, static fn($c) => !$c['deleted'] && !preg_match('~#(holiday|weather|contacts)@group\.v\.calendar\.google\.com$~', $c['id'])));
}

/** 本人が選んだカレンダーの id。まだ選んでいない（選択画面の前に連携した人）は null = 表示中のものをすべて取り込む */
function sched_google_chosen(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT calendar_id FROM sched_google_calendar WHERE member_id = ?');
    $st->execute([$memberId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return $ids ? array_map('strval', $ids) : null;
}

/** 選んだカレンダーを入れ替える（$ids は呼び出し側で、Google の一覧にあるものだけに絞っておく） */
function sched_google_chosen_save(PDO $pdo, int $memberId, array $ids): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sched_google_calendar WHERE member_id = ?')->execute([$memberId]);
        $ins = $pdo->prepare('INSERT INTO sched_google_calendar (member_id, calendar_id) VALUES (?, ?)');
        foreach ($ids as $id) {
            $ins->execute([$memberId, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 取り込むカレンダーの id（selected = 表示中 かつ deleted でない）
 *   祝日・誕生日（連絡先）・天気など、Google が自動で入れるカレンダーは外す。
 *   終日の予定は「空き時間」でも取り込む決まりなので、入れると祝日がぜんぶ「1日予定あり」になってしまうため
 */
function sched_google_pick_calendars(array $calendars): array
{
    $ids = [];
    foreach ($calendars as $c) {
        if ($c['selected'] && !$c['deleted'] && !preg_match('~#(holiday|weather|contacts)@group\.v\.calendar\.google\.com$~', $c['id'])) {
            $ids[] = $c['id'];
        }
    }
    return $ids;
}

/**
 * Google の予定1件 → 埋まりの時間帯 [開始, 終了, タイトル]（絶対分。範囲内に切り詰める）。取り込まない予定は null
 *   ルールは lib/sched_ical.php の sched_ical_parse と同じ（ネットにつながない純粋な関数なので、テストできる）
 *   ・終日（start.date）は、「空き時間」（transparency = transparent）でも、その日の 0:00〜翌 0:00 を埋まりにする。end.date は「終わりの次の日」
 *   ・時間指定（start.dateTime）の「空き時間」は取り込まない
 *   ・キャンセルされた予定（status = cancelled）は取り込まない
 *   ・このサイトが書き込んだ練習（extendedProperties.private.abbeySched）は取り込まない（練習が二重に数えられないように）
 *   ・自分が「辞退」した予定・勤務地（workingLocation）・誕生日（birthday）も取り込まない（予定ありではないため）
 */
function sched_google_event_to_span(array $ev, int $fromAbs, int $toAbs): ?array
{
    if (($ev['status'] ?? '') === 'cancelled' || isset($ev['extendedProperties']['private']['abbeySched'])) {
        return null;
    }
    if (in_array($ev['eventType'] ?? '', ['workingLocation', 'birthday'], true)) {
        return null;
    }
    foreach ((array)($ev['attendees'] ?? []) as $a) {
        if (is_array($a) && !empty($a['self']) && ($a['responseStatus'] ?? '') === 'declined') {
            return null;
        }
    }
    $title = trim((string)($ev['summary'] ?? ''));
    $start = is_array($ev['start'] ?? null) ? $ev['start'] : [];
    $end = is_array($ev['end'] ?? null) ? $ev['end'] : [];
    $isDay = static fn($v): bool => is_string($v) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    if ($isDay($start['date'] ?? null)) {
        // 終日: 日本時間の日付として読む（sched_day_abs が「その日の 0:00」にする）
        $sDay = $start['date'];
        $eDay = $isDay($end['date'] ?? null) ? $end['date'] : date('Y-m-d', strtotime($sDay . ' +1 day'));
        $sa = max($fromAbs, sched_day_abs($sDay));
        $ea = min($toAbs, max(sched_day_abs($eDay), sched_day_abs($sDay) + 1440));
        return $ea > $sa ? [$sa, $ea, $title] : null;
    }
    if (($ev['transparency'] ?? '') === 'transparent' || !is_string($start['dateTime'] ?? null) || !is_string($end['dateTime'] ?? null)) {
        return null; // 時間指定の「空き時間」・時刻の読めない予定は埋まりにしない
    }
    $s = strtotime($start['dateTime']); // "2026-11-20T10:00:00+09:00" のような時刻（UTC との差つき）をそのまま読める
    $e = strtotime($end['dateTime']);
    if ($s === false || $e === false) {
        return null;
    }
    $sa = max($fromAbs, intdiv($s, 60));
    $ea = min($toAbs, intdiv($e, 60));
    return $ea > $sa ? [$sa, $ea, $title] : null;
}

/** Google の予定の一覧 → 埋まりの時間帯 [[開始, 終了, タイトル], …]（重なりはまとめる。sched_ical_parse の結果と同じ形） */
function sched_google_events_to_spans(array $events, int $fromAbs, int $toAbs): array
{
    $spans = [];
    foreach ($events as $ev) {
        $sp = is_array($ev) ? sched_google_event_to_span($ev, $fromAbs, $toAbs) : null;
        if ($sp !== null) {
            $spans[] = $sp;
        }
    }
    return sched_merge_spans($spans);
}

/**
 * 1つのカレンダーの予定を取ってくる（予定の生データの配列）。失敗は RuntimeException
 *   singleEvents=true … 繰り返しの予定を、1回1回の予定に展開して返してもらう（iCal のように、こちらで展開しなくてよい）
 *   pageToken … 次のページの目印。1ページ 250 件なので、続きがあれば上限のページ数まで読む
 *   読めないカレンダー（共有が外れた 404・権限のない 403）は、そのカレンダーだけ飛ばす。
 *   ただし 403 でも「回数の上限」は一時的なので、飛ばさずに失敗にする（飛ばすと、そのカレンダーの予定が埋まりから急に消えてしまう）
 */
function sched_google_fetch_events(string $token, string $calId, int $fromAbs, int $toAbs, float $deadline): array
{
    $events = [];
    $page = null;
    for ($i = 0; $i < SCHED_GOOGLE_MAX_PAGES; $i++) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('読み込みに時間がかかりすぎました');
        }
        $query = [
            'singleEvents' => 'true', 'maxResults' => 250,
            'timeMin' => date('c', $fromAbs * 60), 'timeMax' => date('c', $toAbs * 60),
            'fields' => 'nextPageToken,items(status,summary,transparency,eventType,start,end,extendedProperties/private,attendees(self,responseStatus))',
        ];
        if ($page !== null) {
            $query['pageToken'] = $page;
        }
        $res = sched_google_api('GET', 'calendars/' . rawurlencode($calId) . '/events', $token, $query);
        if ($res['status'] === 404 || ($res['status'] === 403 && !preg_match('/exceeded/i', (string)($res['json']['error']['errors'][0]['reason'] ?? '')))) {
            error_log('sched_google: 読めないカレンダーを飛ばしました status=' . $res['status']);
            return $events;
        }
        if ($res['status'] !== 200 || !is_array($res['json']['items'] ?? null)) {
            sched_google_log('events.list', $res);
            throw new RuntimeException(sched_google_status_text($res['status']));
        }
        array_push($events, ...$res['json']['items']);
        $page = $res['json']['nextPageToken'] ?? null;
        if (!is_string($page) || $page === '') {
            break;
        }
    }
    return $events;
}

/**
 * 1人の Google カレンダーを読み直して、sched_busy（source = 1）を入れ替える（sched_ical_refresh の Google 版）
 *   $force = false なら、SCHED_ICAL_TTL 以内に読んだ人は読み直さない
 *   戻り値: null = 成功・読み直し不要 / 文字列 = 失敗の理由（失敗しても、前に取り込んだ予定は消さない）
 */
function sched_google_refresh_busy(PDO $pdo, int $memberId, bool $force = false): ?string
{
    $row = sched_google_row($pdo, $memberId);
    if ($row === null) {
        return null;
    }
    if (!$force && $row['error'] === SCHED_GOOGLE_REVOKED) {
        return SCHED_GOOGLE_REVOKED; // 連携が切れている間は、ページを開くたびに Google へ聞きに行かない（「読み直す」・再連携で試す）
    }
    if (!$force && $row['fetched_at'] !== null && strtotime((string)$row['fetched_at']) > time() - SCHED_ICAL_TTL) {
        return null;
    }
    if (!sched_google_available()) {
        return 'カレンダー連携は現在使えません';
    }
    $error = null;
    $spans = [];
    $token = sched_google_access_token($pdo, $memberId, $error);
    if ($token !== null) {
        try {
            $deadline = microtime(true) + SCHED_GOOGLE_READ_SECONDS;
            $fromAbs = sched_day_abs(date('Y-m-d', strtotime('-1 day'))); // 昨日から（iCal と同じ範囲）
            $toAbs = $fromAbs + (SCHED_ICAL_DAYS + 1) * 1440;
            $raw = [];
            $cals = sched_google_calendars($token);
            $chosen = sched_google_chosen($pdo, $memberId);
            $calIds = $chosen === null ? sched_google_pick_calendars($cals)
                : array_values(array_map(static fn($c) => $c['id'], array_filter(sched_google_selectable($cals), static fn($c) => in_array($c['id'], $chosen, true)))); // 選んだもののうち、いまも一覧にあるものだけ
            foreach (array_slice($calIds, 0, SCHED_GOOGLE_MAX_CALENDARS) as $calId) {
                foreach (sched_google_fetch_events($token, $calId, $fromAbs, $toAbs, $deadline) as $ev) {
                    $sp = is_array($ev) ? sched_google_event_to_span($ev, $fromAbs, $toAbs) : null;
                    if ($sp !== null) {
                        $raw[] = $sp;
                    }
                }
            }
            $spans = sched_merge_spans($raw); // カレンダーをまたいだ重なりも、ここで1回にまとめる
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
    if ($error !== null) {
        $pdo->prepare('UPDATE sched_google SET error = ? WHERE member_id = ?')->execute([mb_substr($error, 0, 100), $memberId]);
        return $error;
    }
    // トランザクション … 「消す」と「入れる」を1つの操作にする（途中で失敗したら前の予定がそのまま残る）。sched_ical_refresh と同じ方針
    $pdo->beginTransaction();
    try {
        sched_busy_replace_calendar($pdo, $memberId, $spans);
        $pdo->prepare('UPDATE sched_google SET fetched_at = NOW(), error = NULL WHERE member_id = ?')->execute([$memberId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

/**
 * 何人かの Google カレンダーを、古い人から順に読み直す（候補を出す前に呼ぶ）。全体で $budget 秒を超えたら残りは次回に回す
 *   戻り値: かかった秒数（呼び出し側が、iCal の読み直しに残りの時間を回すのに使う）
 */
function sched_google_refresh_many(PDO $pdo, array $memberIds, int $budget = 6): float
{
    $start = microtime(true);
    if (!$memberIds || !sched_google_available()) {
        return 0.0;
    }
    $in = implode(',', array_fill(0, count($memberIds), '?'));
    $st = $pdo->prepare("SELECT member_id FROM sched_google WHERE member_id IN ($in) AND (fetched_at IS NULL OR fetched_at < ?) ORDER BY fetched_at");
    $st->execute([...array_map('intval', $memberIds), date('Y-m-d H:i:s', time() - SCHED_ICAL_TTL)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        if (microtime(true) - $start > $budget) {
            break;
        }
        try {
            sched_google_refresh_busy($pdo, (int)$mid);
        } catch (Throwable $e) {
            error_log('sched_google_refresh_many: ' . $e->getMessage()); // 1人の失敗で、ほかの人の読み込みを止めない
        }
    }
    return microtime(true) - $start;
}

/* =====================================================================
 *  決定した練習の書き込み
 * ===================================================================== */

/**
 * 決定1回（sched_fix の1行）の「Google に入れる予定の中身」と、「誰に入れるか」。
 *   1つの調整で練習を何回も決められるので、Google の予定も決定1回ごとに1つ（sched_google_event は (sched_fix_id, member_id) が主キー）
 *   戻り値: ['body' => 予定の中身（決定していなければ null）, 'members' => [部員 id => 入れるか（呼ばれていて、その回に欠席でない）]]
 *   タイトル・場所・メモは、部員ごとの書式で作る（sched_google_body_for）。ここでは、全員同じ部分（日時・印）だけを 'body' に入れ、書式の材料を 'ctx' に入れる
 */
function sched_google_fix_state(PDO $pdo, int $fixId): array
{
    $st = $pdo->prepare('SELECT f.sched_poll_id, f.status, f.fix_start, f.fix_end, f.studio_id, f.absent_member_id, p.title, s.name AS studio_name, s.short_name AS studio_short, s.address, r.name AS room_name
        FROM sched_fix f
        JOIN sched_poll p ON p.sched_poll_id = f.sched_poll_id
        LEFT JOIN studio s ON s.studio_id = f.studio_id
        LEFT JOIN studio_room r ON r.studio_room_id = f.room_id
        WHERE f.sched_fix_id = ?');
    $st->execute([$fixId]);
    $p = $st->fetch();
    if (!$p) {
        return ['body' => null, 'members' => [], 'ctx' => null]; // 決定が消えている（取り消し済み）→ 誰にも入れない
    }
    $st = $pdo->prepare('SELECT pm.member_id, m.name FROM sched_poll_member pm JOIN member m ON m.member_id = pm.member_id
        WHERE pm.sched_poll_id = ? ORDER BY m.name_kana, m.name');
    $st->execute([(int)$p['sched_poll_id']]);
    $rows = $st->fetchAll();
    $decided = (int)$p['status'] === 3 && $p['fix_start'] !== null && $p['fix_end'] !== null;
    $absent = $p['absent_member_id'] === null ? null : (int)$p['absent_member_id'];
    $members = [];
    $present = [];
    foreach ($rows as $r) {
        $in = $decided && (int)$r['member_id'] !== $absent;
        $members[(int)$r['member_id']] = $in;
        if ($in) {
            $present[] = $r['name'];
        }
    }
    if (!$decided) {
        return ['body' => null, 'members' => $members, 'ctx' => null];
    }
    return ['ctx' => [
        'poll_id' => (int)$p['sched_poll_id'], 'title' => (string)$p['title'], 'studio_id' => $p['studio_id'] === null ? null : (int)$p['studio_id'],
        'studio' => $p['studio_name'], 'studio_short' => $p['studio_short'], 'room' => (string)($p['room_name'] ?? ''), 'address' => $p['address'], 'names' => $present,
    ], 'body' => [
        'start'        => ['dateTime' => date('c', sched_dt_to_abs($p['fix_start']) * 60), 'timeZone' => 'Asia/Tokyo'],
        'end'          => ['dateTime' => date('c', sched_dt_to_abs($p['fix_end']) * 60), 'timeZone' => 'Asia/Tokyo'],
        // 読み込みのとき、このサイトが書いた予定だと見分ける印（sched_google_event_to_span）。中身は決定1回の id
        'extendedProperties' => ['private' => ['abbeySched' => (string)$fixId]],
    ], 'members' => $members];
}

/** sched_google_fix_state の結果から、その部員に入れる予定の中身（部員ごとの書式でタイトル・場所・メモを作る）。入れない（決定していない・欠席）なら null */
function sched_google_body_for(PDO $pdo, array $state, int $memberId): ?array
{
    if ($state['body'] === null || !($state['members'][$memberId] ?? false)) {
        return null;
    }
    $t = sched_event_text(sched_event_format($pdo, $memberId), $state['ctx']);
    return ['summary' => $t['title'], 'location' => $t['location'], 'description' => $t['details']] + $state['body'];
}

/**
 * 1人のカレンダーの、決定1回ぶんを「あるべき状態」に合わせる。失敗は例外（呼び出し側が、1人ずつ try/catch して次の人へ進む）
 *   $body が null … 予定は要らない → あれば Google から消す
 *   $body がある … 無ければ作る。あれば（$patch のときだけ）直す
 *   Google 側で本人が予定を消していた（404・410、または status = cancelled で返ってくる）ときは、作り直す
 */
function sched_google_sync_one(PDO $pdo, int $fixId, int $memberId, ?string $eventId, ?array $body, bool $patch = true): void
{
    if ($body === null && $eventId === null) {
        return;
    }
    $token = sched_google_access_token($pdo, $memberId, $why);
    if ($token === null) {
        throw new RuntimeException('トークンを取れません member=' . $memberId . ' ' . (string)$why);
    }
    if ($body === null) {
        $res = sched_google_api('DELETE', 'calendars/primary/events/' . rawurlencode((string)$eventId), $token);
        if (!in_array($res['status'], [200, 204, 404, 410], true)) { // 404・410 = すでに無い（本人が消した）ので、それでよい
            sched_google_log('events.delete', $res);
            throw new RuntimeException('予定を消せません fix=' . $fixId . ' member=' . $memberId);
        }
        $pdo->prepare('DELETE FROM sched_google_event WHERE sched_fix_id = ? AND member_id = ?')->execute([$fixId, $memberId]);
        return;
    }
    if ($eventId !== null) {
        if (!$patch) {
            return;
        }
        // PATCH … 送った項目だけを書き換える（予定のほかの項目＝本人が足したリマインダー等は、そのまま残る）
        $res = sched_google_api('PATCH', 'calendars/primary/events/' . rawurlencode($eventId), $token, [], $body);
        $gone = in_array($res['status'], [404, 410], true) || ($res['status'] === 200 && ($res['json']['status'] ?? '') === 'cancelled');
        if ($res['status'] === 200 && !$gone) {
            return;
        }
        if (!$gone) {
            sched_google_log('events.patch', $res);
            throw new RuntimeException('予定を直せません fix=' . $fixId . ' member=' . $memberId);
        }
        // 本人が Google 側で消していた → このまま作り直す
    }
    $res = sched_google_api('POST', 'calendars/primary/events', $token, [], $body);
    if ($res['status'] !== 200 || !is_string($res['json']['id'] ?? null)) {
        sched_google_log('events.insert', $res);
        throw new RuntimeException('予定を作れません fix=' . $fixId . ' member=' . $memberId);
    }
    // sched_poll_id は決定の行から写す（INSERT … SELECT。決定が同時に消えていれば何も入らない）
    $pdo->prepare('INSERT INTO sched_google_event (sched_poll_id, sched_fix_id, member_id, event_id) SELECT sched_poll_id, sched_fix_id, ?, ? FROM sched_fix WHERE sched_fix_id = ?
        ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)')
        ->execute([$memberId, mb_substr($res['json']['id'], 0, 255), $fixId]);
}

/**
 * 決定1回ぶんを、Google 連携中の部員のカレンダーと突き合わせる
 *   あるべき状態: status = 3（決定）で、その部員が呼ばれていて、その回に欠席でない → 予定がある。それ以外 → 予定が無い
 *   1人の失敗で全体を止めない（error_log に出して次の人へ）。$deadline = これを過ぎたら打ち切る時刻（microtime。省略すると、ここから SCHED_GOOGLE_SYNC_SECONDS 秒）
 *   機能が無効・連携している人がいなければ、何もしない（Google には何も聞かない）
 */
function sched_google_sync_fix(PDO $pdo, int $fixId, ?float $deadline = null): void
{
    if (!sched_google_available()) {
        return;
    }
    try {
        // 連携中の部員のうち、「この調整に呼ばれている」か「すでに予定を入れてある（のちに外れた人）」だけ
        $st = $pdo->prepare('SELECT g.member_id, e.event_id FROM sched_google g
            LEFT JOIN sched_google_event e ON e.member_id = g.member_id AND e.sched_fix_id = ?
            LEFT JOIN sched_fix f ON f.sched_fix_id = ?
            LEFT JOIN sched_poll_member pm ON pm.member_id = g.member_id AND pm.sched_poll_id = f.sched_poll_id
            WHERE (e.member_id IS NOT NULL OR pm.member_id IS NOT NULL) AND (g.error IS NULL OR g.error <> ?)');
        $st->execute([$fixId, $fixId, SCHED_GOOGLE_REVOKED]);
        $targets = $st->fetchAll();
        if (!$targets) {
            return; // 連携している人が関係なければ、Google には何も聞かない
        }
        $state = sched_google_fix_state($pdo, $fixId);
        $deadline ??= microtime(true) + SCHED_GOOGLE_SYNC_SECONDS;
        foreach ($targets as $t) {
            if (microtime(true) > $deadline) {
                error_log('sched_google_sync_fix: 時間切れ fix=' . $fixId);
                break;
            }
            $mid = (int)$t['member_id'];
            try {
                sched_google_sync_one($pdo, $fixId, $mid, $t['event_id'], sched_google_body_for($pdo, $state, $mid));
            } catch (Throwable $e) {
                error_log('sched_google_sync_fix: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('sched_google_sync_fix: ' . $e->getMessage());
    }
}

/**
 * 調整1件の決定すべてを、Google 連携中の部員のカレンダーと突き合わせる（決定・欠席・調整の編集のあとに呼ぶ）
 *   決定1回ごとに sched_google_sync_fix を呼ぶ。時間切れの上限（SCHED_GOOGLE_SYNC_SECONDS）は調整全体で共有する
 */
function sched_google_sync_poll(PDO $pdo, int $pollId): void
{
    if (!sched_google_available()) {
        return;
    }
    try {
        $deadline = microtime(true) + SCHED_GOOGLE_SYNC_SECONDS;
        foreach (sched_poll_fixes($pdo, $pollId) as $f) {
            sched_google_sync_fix($pdo, (int)$f['sched_fix_id'], $deadline);
        }
    } catch (Throwable $e) {
        error_log('sched_google_sync_poll: ' . $e->getMessage());
    }
}

/**
 * 決定1回を消す前に呼ぶ。全員ぶんの予定を Google から消す
 *   削除のあとだと sched_google_event の行が CASCADE で消えて、どの予定を消せばいいかわからなくなるため、先に呼ぶ
 */
function sched_google_remove_fix(PDO $pdo, int $fixId): void
{
    if (!sched_google_available()) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT member_id, event_id FROM sched_google_event WHERE sched_fix_id = ?');
        $st->execute([$fixId]);
        $deadline = microtime(true) + SCHED_GOOGLE_SYNC_SECONDS;
        foreach ($st->fetchAll() as $r) {
            if (microtime(true) > $deadline) {
                error_log('sched_google_remove_fix: 時間切れ fix=' . $fixId);
                break;
            }
            try {
                sched_google_sync_one($pdo, $fixId, (int)$r['member_id'], $r['event_id'], null);
            } catch (Throwable $e) {
                error_log('sched_google_remove_fix: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('sched_google_remove_fix: ' . $e->getMessage());
    }
}

/**
 * 調整を削除する前に呼ぶ。その調整の全部の決定・全員ぶんの予定を Google から消す
 *   削除のあとだと sched_google_event の行が CASCADE で消えて、どの予定を消せばいいかわからなくなるため、先に呼ぶ
 */
function sched_google_remove_poll(PDO $pdo, int $pollId): void
{
    if (!sched_google_available()) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT sched_fix_id, member_id, event_id FROM sched_google_event WHERE sched_poll_id = ?');
        $st->execute([$pollId]);
        $deadline = microtime(true) + SCHED_GOOGLE_SYNC_SECONDS;
        foreach ($st->fetchAll() as $r) {
            if (microtime(true) > $deadline) {
                error_log('sched_google_remove_poll: 時間切れ poll=' . $pollId);
                break;
            }
            try {
                sched_google_sync_one($pdo, (int)$r['sched_fix_id'], (int)$r['member_id'], $r['event_id'], null);
            } catch (Throwable $e) {
                error_log('sched_google_remove_poll: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('sched_google_remove_poll: ' . $e->getMessage());
    }
}

/**
 * 連携した直後（と「読み直す」のとき）に、その部員の分を合わせる
 *   ・これからの決定済みの練習（その回に欠席でない）で、まだ入っていないものを入れる（すでに入っているものは直さない。API を節約）
 *   ・欠席・取り消し・メンバーから外れた練習の予定が残っていれば、消す
 *   一時的な失敗で入らなかった練習も、「読み直す」を押せば入る
 */
function sched_google_sync_member(PDO $pdo, int $memberId): void
{
    if (!sched_google_available() || sched_google_row($pdo, $memberId) === null) {
        return;
    }
    try {
        $deadline = microtime(true) + SCHED_GOOGLE_SYNC_SECONDS;
        $st = $pdo->prepare('SELECT f.sched_fix_id, e.event_id FROM sched_poll_member pm
            JOIN sched_fix f ON f.sched_poll_id = pm.sched_poll_id
            LEFT JOIN sched_google_event e ON e.sched_fix_id = f.sched_fix_id AND e.member_id = pm.member_id
            WHERE pm.member_id = ? AND (f.absent_member_id IS NULL OR f.absent_member_id <> pm.member_id) AND f.status = 3 AND f.fix_end > NOW() AND e.event_id IS NULL');
        $st->execute([$memberId]);
        $todo = $st->fetchAll();
        $st = $pdo->prepare('SELECT e.sched_fix_id, e.event_id FROM sched_google_event e
            LEFT JOIN sched_fix f ON f.sched_fix_id = e.sched_fix_id
            LEFT JOIN sched_poll_member pm ON pm.sched_poll_id = f.sched_poll_id AND pm.member_id = e.member_id
            WHERE e.member_id = ? AND (pm.member_id IS NULL OR f.absent_member_id = e.member_id OR f.status <> 3)');
        $st->execute([$memberId]);
        $stale = $st->fetchAll();
        foreach ([...$todo, ...$stale] as $r) {
            if (microtime(true) > $deadline) {
                error_log('sched_google_sync_member: 時間切れ member=' . $memberId);
                break;
            }
            $fixId = (int)$r['sched_fix_id'];
            try {
                $state = sched_google_fix_state($pdo, $fixId);
                $want = $r['event_id'] === null; // todo は入れる、stale は消す
                sched_google_sync_one($pdo, $fixId, $memberId, $r['event_id'], $want ? sched_google_body_for($pdo, $state, $memberId) : null, false);
            } catch (Throwable $e) {
                error_log('sched_google_sync_member: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('sched_google_sync_member: ' . $e->getMessage());
    }
}

/**
 * 書式を変えたあとに呼ぶ。これからの決定済みの練習で、すでに Google に入れてある予定を、新しい書式に直す（PATCH）
 *   時間切れ・失敗は error_log に出して、次の予定へ（画面の保存は成功のまま）
 */
function sched_google_repatch_member(PDO $pdo, int $memberId): void
{
    if (!sched_google_available() || sched_google_row($pdo, $memberId) === null) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT e.sched_fix_id, e.event_id FROM sched_google_event e
            JOIN sched_fix f ON f.sched_fix_id = e.sched_fix_id
            WHERE e.member_id = ? AND f.status = 3 AND f.fix_end > NOW() ORDER BY f.fix_start LIMIT 50');
        $st->execute([$memberId]);
        $deadline = microtime(true) + SCHED_GOOGLE_SYNC_SECONDS;
        foreach ($st->fetchAll() as $r) {
            if (microtime(true) > $deadline) {
                error_log('sched_google_repatch_member: 時間切れ member=' . $memberId);
                break;
            }
            try {
                $fixId = (int)$r['sched_fix_id'];
                $body = sched_google_body_for($pdo, sched_google_fix_state($pdo, $fixId), $memberId);
                if ($body !== null) {
                    sched_google_sync_one($pdo, $fixId, $memberId, $r['event_id'], $body);
                }
            } catch (Throwable $e) {
                error_log('sched_google_repatch_member: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('sched_google_repatch_member: ' . $e->getMessage());
    }
}

/**
 * 連携を解除する。Google にトークンを取り消してもらい、取り込んだ予定（sched_busy の source = 1）と連携の行を消す
 *   Google カレンダーに入れた練習の予定は、本人のカレンダーなので消さずに残す（sched_google_event の行だけ消す）
 *   revoke（取り消し）に失敗しても、こちらの DB は必ず消す（本人が Google の設定画面からも外せる）
 */
function sched_google_disconnect(PDO $pdo, int $memberId): void
{
    $row = sched_google_row($pdo, $memberId);
    if ($row === null) {
        return;
    }
    $refresh = sched_ical_decrypt($row['refresh_token']);
    if ($refresh !== null) {
        $res = sched_google_http('POST', SCHED_GOOGLE_REVOKE_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $refresh], '', '&', PHP_QUERY_RFC3986));
        if ($res['status'] !== 200) {
            sched_google_log('revoke', $res);
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sched_google_event WHERE member_id = ?')->execute([$memberId]);
        $pdo->prepare('DELETE FROM sched_google_calendar WHERE member_id = ?')->execute([$memberId]);
        $pdo->prepare('DELETE FROM sched_busy WHERE member_id = ? AND source = 1')->execute([$memberId]);
        $pdo->prepare('DELETE FROM sched_google WHERE member_id = ?')->execute([$memberId]);
        // iCal の URL を登録していた人は、また iCal で読み直せるように「読んだ日時」を消しておく（残すと、SCHED_ICAL_TTL の間は読み直されず空のまま）
        $pdo->prepare('UPDATE sched_profile SET ical_fetched_at = NULL WHERE member_id = ?')->execute([$memberId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
