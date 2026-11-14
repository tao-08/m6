<?php
/**
 * =====================================================================
 *  lib/sched_ical.php — iCal（Google カレンダー・iCloud の「非公開 URL」）の取り込み
 * =====================================================================
 *  ■ iCal とは
 *    カレンダーの予定を書く国際規格（RFC 5545）。Google も iCloud も「この URL を開くと予定の一覧（.ics）が読める」機能を持っている。
 *    ログイン連携（OAuth）が要らない代わりに、URL を知っている人は誰でも予定を読める＝ URL そのものが合言葉。
 *
 *  ■ 守っていること
 *    1. URL は暗号化して DB に保存する（sodium = PHP 標準の暗号ライブラリ。鍵は config.php の sched.ical_key）
 *       → DB の中身が漏れても、鍵が無ければ URL はわからない
 *    2. 読みに行く先は Google と iCloud のドメインだけ（SSRF 対策）
 *       SSRF = サーバーに「内部の URL（http://localhost/… など）を読ませて」中を覗く攻撃。外部の URL を読む機能の定番の穴
 *    3. 予定のタイトル・場所・メモは捨てて、開始と終了だけ保存する
 *
 *  ■ 繰り返し予定（毎週火曜 など）
 *    .ics の中では RRULE という書き方で「ルール」だけ書かれている。展開（実際の日付の一覧にする）は
 *    sabre/vobject（Composer のライブラリ。業界で広く使われている）に任せる。例外日・タイムゾーンも正しく扱ってくれる
 * =====================================================================
 */
declare(strict_types=1);

/** 取り込む範囲（今日の前日〜この日数先まで） */
const SCHED_ICAL_DAYS = 120;
/** 読み直す間隔（秒）。これより新しければ読み直さない（Google・iCloud に何度もアクセスしないため） */
const SCHED_ICAL_TTL = 3600;
/** .ics の大きさの上限（バイト）。何年分も予定がある人でも数MB。大きすぎるものは途中で切る（サーバーのメモリを守る） */
const SCHED_ICAL_MAX_BYTES = 5 * 1024 * 1024;

/** sabre/vobject が使えるか（vendor/ を本番に上げていないと false） */
function sched_ical_available(): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    return class_exists(\Sabre\VObject\Reader::class) && sched_ical_key() !== null;
}

/** 暗号化の鍵（32バイト）。config.php の sched.ical_key（base64）。無ければ null = iCal の機能は休む */
function sched_ical_key(): ?string
{
    $raw = (string)(config('sched')['ical_key'] ?? '');
    $key = base64_decode($raw, true);
    return ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $key : null;
}

/**
 * 暗号化 → base64 の文字列
 *   secretbox = 「同じ鍵で暗号化・復号する」方式（共通鍵暗号）。改ざんされていたら復号に失敗するので気づける
 *   nonce = 毎回違う使い捨ての乱数。同じ URL でも毎回違う暗号文になる（暗号文を見比べて同じだとバレないように）
 */
function sched_ical_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, (string)sched_ical_key()));
}

/** 復号（失敗したら null） */
function sched_ical_decrypt(?string $stored): ?string
{
    $key = sched_ical_key();
    $bin = $stored === null ? false : base64_decode($stored, true);
    if ($key === null || $bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    return $plain === false ? null : $plain;
}

/**
 * 読みに行ってよい URL か（よければ https:// に直した URL、だめなら null）
 *   ・webcal:// は iCloud が出す書き方。中身は https と同じなので置き換える
 *   ・https だけ。ポート番号・ユーザー名付きの URL は断る（変な所へ向けさせない）
 *   ・ホストは calendar.google.com か、〜.icloud.com だけ
 */
function sched_ical_normalize(string $url): ?string
{
    $url = trim($url);
    if (str_starts_with(strtolower($url), 'webcal://')) {
        $url = 'https://' . substr($url, 9);
    }
    $u = parse_url($url);
    if ($u === false || strtolower($u['scheme'] ?? '') !== 'https' || isset($u['port']) || isset($u['user']) || isset($u['pass'])) {
        return null;
    }
    return sched_ical_host_ok(strtolower((string)($u['host'] ?? ''))) && strlen($url) <= 800 ? $url : null;
}

function sched_ical_host_ok(string $host): bool
{
    return $host === 'calendar.google.com' || (bool)preg_match('/^[a-z0-9-]+\.icloud\.com$/', $host);
}

/** 画面に出す URL（中身は伏せる）。例: "calendar.google.com のカレンダー" */
function sched_ical_label(?string $url): string
{
    $host = $url === null ? '' : (string)parse_url($url, PHP_URL_HOST);
    return match (true) {
        $host === 'calendar.google.com' => 'Google カレンダー',
        str_ends_with($host, 'icloud.com') => 'iCloud カレンダー',
        default => 'カレンダー',
    };
}

/**
 * .ics を取ってくる。戻り値: ['ok' => true, 'body' => 中身] / ['ok' => false, 'error' => 理由]
 *   curl … PHP から HTTP 通信をする定番の拡張機能
 *   リダイレクト（301/302）は自動で追わず、自分で行き先のホストを確かめてから追う（許可していない所へ飛ばされないため）
 */
function sched_ical_fetch(string $url): array
{
    for ($hop = 0; $hop < 3; $hop++) {
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,   // https 以外は使わない
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'AbbeyRoad.online sched',
            CURLOPT_HEADER         => false,
            // 届いた分を少しずつ受け取る関数。上限を超えたら 0 を返して打ち切る
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$tooBig): int {
                if (strlen($body) + strlen($chunk) > SCHED_ICAL_MAX_BYTES) {
                    $tooBig = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $done = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            return ['ok' => false, 'error' => 'カレンダーが大きすぎます'];
        }
        if ($done === false) {
            error_log('sched_ical_fetch: ' . $err); // 詳しい理由はサーバーのログにだけ残す（画面には出さない）
            return ['ok' => false, 'error' => '接続できません'];
        }
        if ($code >= 300 && $code < 400 && $next !== '') {
            $host = strtolower((string)parse_url($next, PHP_URL_HOST));
            if (!str_starts_with($next, 'https://') || !sched_ical_host_ok($host)) {
                return ['ok' => false, 'error' => '許可していない場所へ転送されました'];
            }
            $url = $next;
            continue;
        }
        if ($code === 404 || $code === 403 || $code === 401) {
            return ['ok' => false, 'error' => 'URL が無効です（再発行された可能性）'];
        }
        if ($code !== 200) {
            return ['ok' => false, 'error' => '読み込めません（' . $code . '）'];
        }
        return ['ok' => true, 'body' => $body];
    }
    return ['ok' => false, 'error' => '転送が多すぎます'];
}

/**
 * .ics の中身 → 埋まりの時間帯 [[開始, 終了], …]（絶対分。範囲内に切り詰め、重なりはまとめる）
 *   取り込まないもの:
 *     ・「空き時間」として登録された予定（TRANSP:TRANSPARENT）
 *     ・終日の予定（誕生日・祝日・「◯◯の日」などのメモ代わりが多いので）
 *     ・キャンセルされた予定（STATUS:CANCELLED）
 *   expand() … 繰り返し予定を、範囲内の1回1回の予定に展開する（sabre/vobject）
 */
function sched_ical_parse(string $ics, int $fromAbs, int $toAbs): array
{
    $cal = \Sabre\VObject\Reader::read($ics, \Sabre\VObject\Reader::OPTION_FORGIVING);
    $from = new DateTimeImmutable('@' . ($fromAbs * 60));
    $to = new DateTimeImmutable('@' . ($toAbs * 60));
    $cal = $cal->expand($from, $to);
    $spans = [];
    foreach ($cal->select('VEVENT') as $ev) {
        if (!isset($ev->DTSTART) || !$ev->DTSTART->hasTime()) {
            continue; // 終日
        }
        if (strtoupper((string)($ev->TRANSP ?? '')) === 'TRANSPARENT' || strtoupper((string)($ev->STATUS ?? '')) === 'CANCELLED') {
            continue;
        }
        $s = $ev->DTSTART->getDateTime();
        if (isset($ev->DTEND)) {
            $e = $ev->DTEND->getDateTime();
        } elseif (isset($ev->DURATION)) {
            $e = $s->add($ev->DURATION->getDateInterval());
        } else {
            continue; // 長さのわからない予定（規格上は「一瞬」）は埋まりにしない
        }
        $sa = max($fromAbs, intdiv($s->getTimestamp(), 60));
        $ea = min($toAbs, intdiv($e->getTimestamp(), 60));
        if ($ea > $sa) {
            $spans[] = [$sa, $ea];
        }
    }
    return sched_merge_spans($spans);
}

/** 重なる・くっつく時間帯を1つにまとめる（[[0,30],[20,60],[60,90]] → [[0,90]]） */
function sched_merge_spans(array $spans): array
{
    usort($spans, static fn($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($spans as [$s, $e]) {
        $last = array_key_last($out);
        if ($last !== null && $s <= $out[$last][1]) {
            $out[$last][1] = max($out[$last][1], $e);
        } else {
            $out[] = [$s, $e];
        }
    }
    return $out;
}

/**
 * 1人の iCal を読み直して、sched_busy（source = 1）を入れ替える
 *   $force = false なら、SCHED_ICAL_TTL 以内に読んだ人は読み直さない
 *   戻り値: null = 成功・読み直し不要 / 文字列 = 失敗の理由
 */
function sched_ical_refresh(PDO $pdo, int $memberId, bool $force = false): ?string
{
    $st = $pdo->prepare('SELECT ical_url, ical_fetched_at FROM sched_profile WHERE member_id = ?');
    $st->execute([$memberId]);
    $p = $st->fetch();
    if (!$p || $p['ical_url'] === null) {
        return null;
    }
    if (!$force && $p['ical_fetched_at'] !== null && strtotime((string)$p['ical_fetched_at']) > time() - SCHED_ICAL_TTL) {
        return null;
    }
    if (!sched_ical_available()) {
        return 'カレンダー連携は現在使えません';
    }
    $url = sched_ical_decrypt($p['ical_url']);
    $error = null;
    $spans = [];
    if ($url === null) {
        $error = '保存した URL を読めません。登録し直してください';
    } else {
        $res = sched_ical_fetch($url);
        if (!$res['ok']) {
            $error = $res['error'];
        } else {
            $fromAbs = sched_day_abs(date('Y-m-d', strtotime('-1 day')));
            try {
                $spans = sched_ical_parse($res['body'], $fromAbs, $fromAbs + (SCHED_ICAL_DAYS + 1) * 1440);
            } catch (Throwable $e) {
                $error = 'カレンダーの形式を読めません';
            }
        }
    }
    if ($error !== null) {
        // 失敗しても、前に取り込んだ予定は消さない（一時的に Google が落ちていても、埋まりが急に空かないように）
        $pdo->prepare('UPDATE sched_profile SET ical_error = ? WHERE member_id = ?')->execute([mb_substr($error, 0, 100), $memberId]);
        return $error;
    }
    // トランザクション … 「消す」と「入れる」をまとめて1つの操作にする。途中で失敗したら両方なかったことになる（ROLLBACK）
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sched_busy WHERE member_id = ? AND source = 1')->execute([$memberId]);
        $ins = $pdo->prepare('INSERT INTO sched_busy (member_id, start_at, end_at, source) VALUES (?, ?, ?, 1)');
        foreach ($spans as [$s, $e]) {
            $ins->execute([$memberId, sched_abs_to_dt($s), sched_abs_to_dt($e)]);
        }
        $pdo->prepare('UPDATE sched_profile SET ical_fetched_at = NOW(), ical_error = NULL WHERE member_id = ?')->execute([$memberId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

/**
 * 何人かの iCal を、古い人から順に読み直す（候補を出す前に呼ぶ）。全体で $budget 秒を超えたら残りは次回に回す
 *   1人 8秒かかることもあるので、ページの表示が遅くなりすぎないように時間で区切る
 */
function sched_ical_refresh_many(PDO $pdo, array $memberIds, int $budget = 6): void
{
    if (!$memberIds || !sched_ical_available()) {
        return;
    }
    $in = implode(',', array_fill(0, count($memberIds), '?'));
    $st = $pdo->prepare("SELECT member_id FROM sched_profile WHERE member_id IN ($in) AND ical_url IS NOT NULL
        AND (ical_fetched_at IS NULL OR ical_fetched_at < ?) ORDER BY ical_fetched_at");
    $st->execute([...array_map('intval', $memberIds), date('Y-m-d H:i:s', time() - SCHED_ICAL_TTL)]);
    $start = microtime(true);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        if (microtime(true) - $start > $budget) {
            break;
        }
        sched_ical_refresh($pdo, (int)$mid);
    }
}
