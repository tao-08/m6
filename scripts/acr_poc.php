<?php
/**
 * =====================================================================
 *  scripts/acr_poc.php — ACRCloud File Scanning でライブ動画の演奏曲を検出する（検証用 PoC）
 * =====================================================================
 *  YouTube の動画 URL を ACRCloud に渡して、曲名・アーティスト・動画内の開始位置を取る。
 *  音声の取得は ACRCloud 側が行う（data_type = platforms）。yt-dlp・ffmpeg は要らない。
 *  v3/ の機能・DB には触れない。結果は画面に出すだけ。
 *
 *    php scripts/acr_poc.php create-container          コンテナを作り、container_id を表示する（config.php へは書かない）
 *    php scripts/acr_poc.php scan <YouTube URL>        登録 → 完了待ち → 結果表示
 *    php scripts/acr_poc.php list                      コンテナの中のファイルと状態を一覧する
 *    php scripts/acr_poc.php result <file_id>          登録済みのスキャンの結果をもう一度表示する
 *    php scripts/acr_poc.php rescan <file_id>          止まったスキャンをやり直させる
 *    php scripts/acr_poc.php delete <file_id>          スキャン記録を削除する
 *    php scripts/acr_poc.php selftest                  結果の統合ロジックを固定データで確かめる（通信なし・config.php 不要）
 *
 *    オプション（scan / result）:
 *      --tsv   表の代わりに TSV を出す（評価用の表に貼れる）
 *      --raw   生の JSON を一時フォルダに保存する（リポジトリの外）
 *
 *  設定は v3/config.php の 'acrcloud'（access_token / container_id / region）から読む。
 *  トークンは画面・エラー文に出さない。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../v3/lib/acrcloud.php'; // 結果の統合（acr_merge_results）・album_http（通信）

/** 完了待ちの間隔（秒）と、確認する回数の上限。無制限には待たない（5秒×12 + 10秒×78 = 約14分） */
const ACR_POLL_FAST_SEC = 5;
const ACR_POLL_FAST_COUNT = 12;
const ACR_POLL_SLOW_SEC = 10;
const ACR_POLL_MAX = 90;
/** 5xx が続けて何回出たら諦めるか */
const ACR_POLL_MAX_ERRORS = 5;
/** 通信の待ち時間の上限（秒） */
const ACR_HTTP_TIMEOUT_SEC = 30;

/** API を呼んだ回数 */
$GLOBALS['acr_calls'] = 0;

/* ---------------------------------------------------------------------
 * 結果の整理（通信なし → selftest で確かめる）
 * ------------------------------------------------------------------- */

/** 秒 → "m:ss"（1時間を超えても分で数える。取れなければ "-"） */
function acr_mmss(?float $sec): string
{
    if ($sec === null) {
        return '-';
    }
    $s = (int)round($sec);
    return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
}

/** 秒 → "h:mm:ss"（動画の長さ用。取れなければ "-"） */
function acr_hms(?float $sec): string
{
    if ($sec === null) {
        return '-';
    }
    $s = (int)round($sec);
    return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

/** 整理した行を、表示用の文字列の列にする（取れない値は "-"） */
function acr_row_cells(array $r): array
{
    return [
        acr_mmss($r['start']),
        acr_mmss($r['end']),
        $r['end'] !== null ? acr_mmss($r['end'] - $r['start']) : '-',
        $r['title'],
        $r['artist'] !== '' ? $r['artist'] : '-',
        $r['src'],
        $r['score'] !== null ? (string)$r['score'] : '-',
    ];
}

const ACR_HEADERS = ['開始', '終了', '長さ', '曲名', 'アーティスト', '由来', 'スコア'];

/** 全角を2桁として数えて右にスペースを足す */
function acr_pad(string $s, int $width): string
{
    return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
}

function acr_table(array $rows): string
{
    $cells = array_map('acr_row_cells', $rows);
    $widths = array_map('mb_strwidth', ACR_HEADERS);
    foreach ($cells as $c) {
        foreach ($c as $i => $v) {
            $widths[$i] = max($widths[$i], mb_strwidth($v));
        }
    }
    $line = fn(array $c) => rtrim(implode('  ', array_map(fn($v, $i) => acr_pad($v, $widths[$i]), $c, array_keys($c))));
    $out = [$line(ACR_HEADERS)];
    foreach ($cells as $c) {
        $out[] = $line($c);
    }
    return implode("\n", $out) . "\n";
}

function acr_tsv(array $rows): string
{
    $clean = fn(string $v) => str_replace(["\t", "\r", "\n"], ' ', $v);
    $out = [implode("\t", ACR_HEADERS + [7 => 'ISRC', 8 => '開始秒'])];
    foreach ($rows as $r) {
        $out[] = implode("\t", array_map($clean, array_merge(acr_row_cells($r), [$r['isrc'] !== '' ? $r['isrc'] : '-', (string)round($r['start'], 1)])));
    }
    return implode("\n", $out) . "\n";
}

/* ---------------------------------------------------------------------
 * 通信
 * ------------------------------------------------------------------- */

/** 画面に出す文字からトークンを消す */
function acr_redact(string $s): string
{
    $t = (string)($GLOBALS['acr_token'] ?? '');
    return $t !== '' ? str_replace($t, '***', $s) : $s;
}

function acr_fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, acr_redact($msg) . "\n");
    exit($code);
}

/** config.php から設定を読む（bootstrap は DB に接続しない。セッションは始めない） */
function acr_load_env(): array
{
    if (!is_file(__DIR__ . '/../v3/config.php')) {
        acr_fail('v3/config.php がありません');
    }
    define('APP_NO_SESSION', true);
    require_once __DIR__ . '/../v3/lib/bootstrap.php'; // config()・youtube_url_valid
    require_once __DIR__ . '/../v3/lib/youtube.php';   // youtube_video_id・youtube_watch_url

    $c = config('acrcloud');
    $token = is_array($c) ? trim((string)($c['access_token'] ?? '')) : '';
    if ($token === '') {
        acr_fail("v3/config.php の 'common' → 'acrcloud' → 'access_token' が空です");
    }
    $GLOBALS['acr_token'] = $token;
    $region = trim((string)($c['region'] ?? 'ap-southeast-1'));
    if (!in_array($region, ACR_REGIONS, true)) {
        acr_fail('region は ' . implode(' / ', ACR_REGIONS) . ' のどれかにしてください');
    }
    return ['token' => $token, 'region' => $region, 'container_id' => trim((string)($c['container_id'] ?? ''))];
}

/**
 * ACRCloud の API を呼ぶ。戻り値: ['status' => int, 'json' => array|null]
 *   GET・POST は album_http を使う。album_http は DELETE を送れないので、DELETE だけ cURL を直接使う
 */
function acr_call(array $env, string $method, string $url, ?array $body = null): array
{
    $GLOBALS['acr_calls']++;
    $headers = ['Authorization: Bearer ' . $env['token'], 'Accept: application/json'];
    $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if ($method === 'DELETE' || $method === 'PUT') {
        if (!function_exists('curl_init')) {
            acr_fail('この操作には PHP の cURL 拡張が必要です');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => ACR_HTTP_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['User-Agent: AbbeyRoad.online']),
        ]);
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($res === false) {
            acr_fail('通信できませんでした: ' . curl_error($ch));
        }
        return ['status' => $status, 'json' => json_decode((string)$res, true)];
    }

    $res = album_http($url, $headers, $method === 'POST' ? (string)$json : null, null, ACR_HTTP_TIMEOUT_SEC);
    if ($res === null) {
        acr_fail('通信できませんでした。時間をおいてもう一度試してください');
    }
    return $res;
}

/** 2xx 以外なら理由を添えて止める */
function acr_expect(array $res, array $okStatuses, string $what): void
{
    if (in_array($res['status'], $okStatuses, true)) {
        return;
    }
    if ($res['status'] === 401 || $res['status'] === 403) {
        acr_fail($what . 'に失敗しました（HTTP ' . $res['status'] . '）。トークンのスコープ（filescanning の読み書き・read-metadata）を確認してください');
    }
    $reason = is_array($res['json']) ? (string)($res['json']['message'] ?? $res['json']['error'] ?? '') : '';
    acr_fail($what . 'に失敗しました（HTTP ' . $res['status'] . ($reason !== '' ? '・' . $reason : '') . '）');
}

/** ファイル系 API のホスト（コンテナ作成だけ api-v2） */
function acr_files_base(array $env): string
{
    if ($env['container_id'] === '' || !ctype_digit($env['container_id'])) {
        acr_fail("v3/config.php の 'acrcloud' → 'container_id' が空か数字ではありません。先に create-container を実行してください");
    }
    return 'https://api-' . $env['region'] . '.acrcloud.com/api/fs-containers/' . $env['container_id'] . '/files';
}

/* ---------------------------------------------------------------------
 * コマンド
 * ------------------------------------------------------------------- */

function acr_cmd_create_container(array $env): void
{
    $body = [
        'name'       => 'm6-live-setlist',
        'region'     => $env['region'],
        'audio_type' => 'recorded',
        'buckets'    => ['ACRCloud Music'],
        'engine'     => 3, // 1 = 音源の照合 / 2 = カバー曲 / 3 = 両方
        'policy'     => ['type' => 'traverse', 'interval' => 0, 'rec_length' => 10],
    ];
    $res = acr_call($env, 'POST', 'https://api-v2.acrcloud.com/api/fs-containers', $body);
    acr_expect($res, [200, 201], 'コンテナの作成');
    $id = $res['json']['data']['id'] ?? null;
    if (!is_scalar($id) || (string)$id === '') {
        acr_fail('コンテナの ID を読めませんでした');
    }
    echo "container_id: {$id}\n";
    echo "v3/config.php の 'acrcloud' → 'container_id' に入れてください\n";
}

/** 結果を取得して表示する。戻り値: 終了コード */
function acr_show_result(array $file, bool $tsv, bool $raw, ?float $elapsed): int
{
    $meta = [];
    $uri = (string)($file['uri'] ?? '');
    if (preg_match('/^youtube:video:([A-Za-z0-9_-]{11})$/', $uri, $m)) {
        $meta[] = '動画ID: ' . $m[1];
    }
    $meta[] = '動画の長さ: ' . acr_hms(isset($file['duration']) && is_numeric($file['duration']) ? (float)$file['duration'] : null);
    $meta[] = '所要: ' . ($elapsed !== null ? round($elapsed) . '秒' : '-');
    $meta[] = 'API 呼び出し: ' . $GLOBALS['acr_calls'] . '回';

    if ($raw) {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'acr_poc';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            acr_fail('保存先を作れませんでした: ' . $dir);
        }
        $path = $dir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9-]/', '', (string)($file['id'] ?? 'file')) . '.json';
        file_put_contents($path, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $meta[] = '生の JSON: ' . $path;
    }

    // TSV のときは表だけを標準出力に出し、補足は標準エラーに出す（そのままファイルに保存できるように）
    $info = fopen($tsv ? 'php://stderr' : 'php://stdout', 'w');
    fwrite($info, implode("\n", $meta) . "\n\n");

    $state = (int)($file['state'] ?? 0);
    if ($state === 0) {
        fwrite($info, "まだ処理中です\n");
        return 0;
    }
    if ($state === -1) {
        fwrite($info, "認識結果なし\n");
        return 0;
    }
    if ($state === -2 || $state === -3) {
        fwrite($info, 'ACRCloud 側でエラーになりました（state ' . $state . "）\n");
        return 1;
    }
    $rows = acr_merge_results(is_array($file['results'] ?? null) ? $file['results'] : []);
    if (!$rows) {
        fwrite($info, "曲を検出できませんでした\n");
        return 0;
    }
    echo $tsv ? acr_tsv($rows) : acr_table($rows);
    return 0;
}

/** $retryable = true のときは、サーバー側の一時的なエラー（5xx）で止まらず null を返す（完了待ちの途中用） */
function acr_poc_fetch_file(array $env, string $fileId, bool $retryable = false): ?array
{
    $res = acr_call($env, 'GET', acr_files_base($env) . '/' . $fileId);
    if ($retryable && $res['status'] >= 500) {
        return null;
    }
    acr_expect($res, [200], '結果の取得');
    $file = $res['json']['data'][0] ?? null;
    if (!is_array($file)) {
        acr_fail('結果を読めませんでした');
    }
    return $file;
}

function acr_cmd_scan(array $env, string $url, bool $tsv, bool $raw): int
{
    $id = youtube_video_id($url);
    if ($id === null) {
        acr_fail('YouTube の動画の URL（https://www.youtube.com/watch?v=… または https://youtu.be/…）だけ使えます');
    }
    $base = acr_files_base($env);
    $res = acr_call($env, 'POST', $base, ['data_type' => 'platforms', 'url' => youtube_watch_url($id)]);
    acr_expect($res, [200, 201], '動画の登録');
    $fileId = (string)($res['json']['data']['id'] ?? '');
    if (!acr_valid_file_id($fileId)) {
        acr_fail('file_id を読めませんでした');
    }
    fwrite(STDERR, "登録しました: {$fileId}\n");

    $t0 = microtime(true);
    $file = null;
    $errors = 0;
    for ($i = 0; $i < ACR_POLL_MAX; $i++) {
        sleep($i < ACR_POLL_FAST_COUNT ? ACR_POLL_FAST_SEC : ACR_POLL_SLOW_SEC);
        $file = acr_poc_fetch_file($env, $fileId, true);
        if ($file === null) {
            // 5xx が続くときは諦める（無制限には粘らない）
            if (++$errors >= ACR_POLL_MAX_ERRORS) {
                acr_fail("ACRCloud のサーバーエラーが続きました。あとで php scripts/acr_poc.php result {$fileId} を実行してください");
            }
            continue;
        }
        $errors = 0;
        if ((int)($file['state'] ?? 0) !== 0) {
            return acr_show_result($file, $tsv, $raw, microtime(true) - $t0);
        }
    }
    acr_fail("時間内に終わりませんでした。あとで php scripts/acr_poc.php result {$fileId} を実行してください");
}

/** コンテナの中のファイルを新しい順に一覧する（結果の中身は取らない。件数が多いときは最新の50件） */
function acr_cmd_list(array $env): void
{
    $res = acr_call($env, 'GET', acr_files_base($env) . '?' . http_build_query(['page' => 1, 'per_page' => 50]));
    acr_expect($res, [200], '一覧の取得');
    $files = is_array($res['json']['data'] ?? null) ? $res['json']['data'] : [];
    if (!$files) {
        echo "ファイルはありません\n";
        return;
    }
    $states = [0 => '処理中', 1 => '完了', -1 => '結果なし', -2 => 'エラー', -3 => 'エラー'];
    $rows = [['file_id', 'URI / 名前', '長さ', '状態', '登録（UTC）']];
    foreach ($files as $f) {
        $state = (int)($f['state'] ?? 0);
        $dur = isset($f['duration']) && is_numeric($f['duration']) ? (float)$f['duration'] : null;
        $rows[] = [
            (string)($f['id'] ?? ''),
            (string)(($f['uri'] ?? '') !== '' ? $f['uri'] : ($f['name'] ?? '')),
            $dur !== null && $dur > 0 ? acr_hms($dur) : '-',
            ($states[$state] ?? (string)$state),
            str_replace(['T', '.000000Z'], [' ', ''], (string)($f['created_at'] ?? '')),
        ];
    }
    $widths = [];
    foreach ($rows as $r) {
        foreach ($r as $i => $v) {
            $widths[$i] = max($widths[$i] ?? 0, mb_strwidth($v));
        }
    }
    foreach ($rows as $r) {
        echo rtrim(implode('  ', array_map(fn($v, $i) => acr_pad($v, $widths[$i]), $r, array_keys($r)))) . "\n";
    }
    $total = $res['json']['meta']['total'] ?? null;
    if (is_numeric($total) && (int)$total > count($files)) {
        echo '（全 ' . (int)$total . " 件のうち最新の " . count($files) . " 件）\n";
    }
}

/** 処理が止まったファイルをもう一度スキャンさせる */
function acr_cmd_rescan(array $env, string $fileId): void
{
    if (!acr_valid_file_id($fileId)) {
        acr_fail('file_id の形が正しくありません');
    }
    $res = acr_call($env, 'PUT', acr_files_base($env) . '/' . $fileId . '/rescan');
    acr_expect($res, [200, 201, 204], '再スキャン');
    echo "再スキャンを依頼しました: {$fileId}\n";
}

function acr_cmd_delete(array $env, string $fileId): void
{
    if (!acr_valid_file_id($fileId)) {
        acr_fail('file_id の形が正しくありません');
    }
    $res = acr_call($env, 'DELETE', acr_files_base($env) . '/' . $fileId);
    acr_expect($res, [200, 204], '削除');
    echo "削除しました: {$fileId}\n";
}

/* ---------------------------------------------------------------------
 * selftest（固定データ・通信なし）
 * ------------------------------------------------------------------- */

function acr_selftest(): int
{
    $failed = 0;
    $check = function (string $label, mixed $actual, mixed $expected) use (&$failed): void {
        if ($actual === $expected) {
            echo "  ok   {$label}\n";
            return;
        }
        $failed++;
        echo "  FAIL {$label}\n       expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n       actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    };
    $music = fn(float $off, float $dur, string $title, string $artist, int $score, string $isrc = '') => [
        'offset' => $off, 'played_duration' => $dur, 'type' => 'traverse',
        'result' => ['title' => $title, 'artists' => [['name' => $artist]], 'score' => $score, 'external_ids' => $isrc !== '' ? ['isrc' => $isrc] : []],
    ];
    $cover = fn(float $off, float $dur, string $title, string $artist, string $isrc = '') => [
        'offset' => $off, 'played_duration' => $dur, 'type' => 'traverse', 'engine_type' => '2',
        'result' => ['title' => $title, 'artists' => [['name' => $artist, 'roles' => ['MainArtist']]], 'external_ids' => $isrc !== '' ? ['isrc' => $isrc] : []],
    ];

    echo "統合\n";
    $rows = acr_merge_results([
        'music' => [
            $music(60.0, 200.0, 'Hello, World!', 'Band A', 95, 'JPAA00000001'),
            $music(1800.0, 180.0, 'Hello World', 'Band A', 90),   // 再演（離れた場所）
        ],
        'cover_songs' => [
            $cover(62.5, 198.0, 'hello world', 'Band A', 'JPAA00000001'), // music と同じ時間帯・同じ曲
            $cover(10.0, 40.0, 'ハロー', 'Band B'),                        // 先頭の別の曲（score なし）
            $cover(900.0, 120.0, 'ハロー', 'Band B'),                       // 同じ曲だが離れている
        ],
    ]);
    $check('行数（重複は1行・再演は別行）', count($rows), 4);
    $check('開始位置の順', array_map(fn($r) => (int)$r['start'], $rows), [10, 60, 900, 1800]);
    $check('music と cover は1行に統合', $rows[1]['src'], 'music+cover');
    $check('統合後も music の score を残す', $rows[1]['score'], 95);
    $check('統合後の開始は早い方', $rows[1]['start'], 60.0);
    $check('統合後の終わりは遅い方', $rows[1]['end'], 260.5);
    $check('再演は別の行', $rows[3]['title'], 'Hello World');
    $check('cover だけの行は由来 cover', $rows[0]['src'], 'cover');
    $check('cover の score は「-」', acr_row_cells($rows[0])[6], '-');
    $check('曲名・アーティストの表示', array_slice(acr_row_cells($rows[1]), 3, 2), ['Hello, World!', 'Band A']);
    $check('開始・終了・長さの表示', array_slice(acr_row_cells($rows[1]), 0, 3), ['01:00', '04:21', '03:21']);

    echo "欠けた値\n";
    $rows = acr_merge_results(['music' => [
        ['offset' => 5, 'result' => ['title' => 'X', 'artists' => []]],   // 長さ・score・アーティストなし
        ['offset' => 9, 'result' => ['title' => '', 'artists' => []]],    // 曲名なしは捨てる
    ]]);
    $check('曲名なしの要素は除く', count($rows), 1);
    $check('長さ・アーティスト・score は「-」', array_slice(acr_row_cells($rows[0]), 1), ['-', '-', 'X', '-', 'music', '-']);
    $check('結果なしは空', acr_merge_results([]), []);

    echo "出力\n";
    $tsv = acr_tsv(acr_merge_results(['music' => [$music(0.0, 30.0, "A\tB", 'C', 80)]]));
    $check('TSV の列数（タブを含む曲名も崩れない）', count(explode("\t", explode("\n", $tsv)[1])), 9);
    $check('時間の表示', [acr_mmss(75.4), acr_mmss(null), acr_hms(3725.0)], ['01:15', '-', '1:02:05']);

    echo $failed === 0 ? "すべて通りました\n" : "{$failed} 件失敗しました\n";
    return $failed === 0 ? 0 : 1;
}

/* ---------------------------------------------------------------------
 * 入り口
 * ------------------------------------------------------------------- */

if (PHP_SAPI !== 'cli') {
    exit('コマンドラインから実行してください');
}

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$args = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$cmd = $args[0] ?? '';
$tsv = in_array('--tsv', $flags, true);
$raw = in_array('--raw', $flags, true);

switch ($cmd) {
    case 'selftest':
        exit(acr_selftest());
    case 'create-container':
        acr_cmd_create_container(acr_load_env());
        break;
    case 'scan':
        if (!isset($args[1])) {
            acr_fail('使い方: php scripts/acr_poc.php scan <YouTube URL> [--tsv] [--raw]');
        }
        $env = acr_load_env();
        exit(acr_cmd_scan($env, $args[1], $tsv, $raw));
    case 'result':
        if (!isset($args[1]) || !acr_valid_file_id($args[1])) {
            acr_fail('使い方: php scripts/acr_poc.php result <file_id> [--tsv] [--raw]');
        }
        $env = acr_load_env();
        exit(acr_show_result(acr_poc_fetch_file($env, $args[1]), $tsv, $raw, null));
    case 'list':
        acr_cmd_list(acr_load_env());
        break;
    case 'rescan':
        if (!isset($args[1])) {
            acr_fail('使い方: php scripts/acr_poc.php rescan <file_id>');
        }
        acr_cmd_rescan(acr_load_env(), $args[1]);
        break;
    case 'delete':
        if (!isset($args[1])) {
            acr_fail('使い方: php scripts/acr_poc.php delete <file_id>');
        }
        acr_cmd_delete(acr_load_env(), $args[1]);
        break;
    default:
        acr_fail("使い方: php scripts/acr_poc.php create-container | scan <URL> | list | result <file_id> | rescan <file_id> | delete <file_id> | selftest\n  オプション: --tsv --raw");
}
