<?php
/**
 * api_gemini_diagnostics.php — 管理者限定のGemini接続診断。
 * APIキー・APIレスポンス本文・検索フレーズは画面にもログにも出さない。
 * 実行時に短い固定プロンプトを1回だけGeminiへ送信する。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
api_user('is_admin', 'Gemini接続診断は管理者のみ利用できます');

$gemini = (array)(config('gemini') ?? []);
$apiKey = trim((string)($gemini['api_key'] ?? ''));
$model = trim((string)($gemini['lyrics_search_model'] ?? ''));
$host = 'generativelanguage.googleapis.com';
$ip = gethostbyname($host);
$dnsOk = $ip !== $host;
$result = [
    'php_version' => PHP_VERSION,
    'curl_available' => function_exists('curl_init'),
    'openssl_available' => extension_loaded('openssl'),
    'allow_url_fopen' => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
    'dns_ok' => $dnsOk,
    'dns_ip' => $dnsOk ? $ip : null,
    'api_key_configured' => $apiKey !== '',
    'model' => $model !== '' ? $model : '(未設定)',
    'http_status' => null,
    'latency_ms' => null,
    'dns_time_ms' => null,
    'tcp_connect_time_ms' => null,
    'tls_handshake_time_ms' => null,
    'first_byte_time_ms' => null,
    'primary_ip' => null,
    'primary_port' => null,
    'local_ip' => null,
    'curl_error' => null,
    'timeout_phase' => null,
    'category' => 'not_run',
    'message' => '',
];

if (!$result['curl_available']) {
    $result['category'] = 'curl_unavailable';
    $result['message'] = 'PHPのcURL拡張が無効です。サーバー側で有効化が必要です。';
    api_ok($result);
}
if (!$dnsOk) {
    $result['category'] = 'dns_failed';
    $result['message'] = 'Google APIのホスト名をDNSで解決できません。サーバーのDNSまたは外部通信設定を確認してください。';
    api_ok($result);
}
if ($apiKey === '') {
    $result['category'] = 'missing_key';
    $result['message'] = 'config.php の common.gemini.api_key が空です。';
    api_ok($result);
}
if (!preg_match('/^gemini-[a-zA-Z0-9.-]+$/', $model)) {
    $result['category'] = 'invalid_model';
    $result['message'] = 'Geminiのモデル名が未設定または形式不正です。';
    api_ok($result);
}

$url = 'https://' . $host . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
$payload = json_encode([
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => 'Reply with exactly: OK']],
    ]],
    'generationConfig' => [
        'temperature' => 0,
        'maxOutputTokens' => 8,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$started = microtime(true);
$body = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
"$elapsed = (int)round((microtime(true) - $started) * 1000);
$info = curl_getinfo($ch);
$ms = static fn(string $key): ?int => isset($info[$key]) ? (int)round((float)$info[$key] * 1000) : null;
$result['dns_time_ms'] = $ms('namelookup_time');
$result['tcp_connect_time_ms'] = $ms('connect_time');
$result['tls_handshake_time_ms'] = $ms('appconnect_time') !== null && $ms('connect_time') !== null
    ? max(0, $ms('appconnect_time') - $ms('connect_time')) : null;
$result['first_byte_time_ms'] = $ms('starttransfer_time');
$result['primary_ip'] = $info['primary_ip'] ?? null;
$result['primary_port'] = isset($info['primary_port']) ? (int)$info['primary_port'] : null;
$result['local_ip'] = $info['local_ip'] ?? null;
$curlError = curl_error($ch);
curl_close($ch);

$result['latency_ms'] = $elapsed;
if ($body === false) {
    $result['category'] = 'transport_failed';
    $result['curl_errno'] = $errno;
    $result['curl_error'] = $curlError !== '' ? substr($curlError, 0, 240) : null;
    $result['timeout_phase'] = $errno === 28
        ? (($result['tcp_connect_time_ms'] ?? 0) === 0 ? 'dns_or_tcp'
            : (($result['tls_handshake_time_ms'] ?? 0) === 0 ? 'tls_or_connect'
                : (($result['first_byte_time_ms'] ?? 0) === 0 ? 'waiting_for_response' : 'response_transfer')))
        : null;
    $result['message'] = $errno === 28
        ? '通信が制限時間内に完了しませんでした。各段階の時間から停止箇所を確認してください。'
        : 'Google APIへのHTTPS通信に失敗しました。cURLエラーと通信段階の時間を確認してください。';
    api_ok($result);
}

$result['http_status'] = $status;
$result['category'] = match (true) {
    $status >= 200 && $status < 300 => 'ok',
    $status === 400 => 'bad_request',
    $status === 401 || $status === 403 => 'auth_failed',
    $status === 404 => 'model_not_found',
    $status === 429 => 'quota_exceeded',
    $status >= 500 => 'google_server_error',
    default => 'http_error',
};
$result['message'] = match ($result['category']) {
    'ok' => 'Gemini APIへの接続と認証に成功しました。',
    'bad_request' => 'Google APIには到達しましたが、リクエストが不正です。モデル名やAPI設定を確認してください。',
    'auth_failed' => 'Google APIには到達しましたが、APIキーが拒否されました。キーとAPIの有効化状態を確認してください。',
    'model_not_found' => 'Google APIには到達しましたが、指定モデルが見つかりません。モデル名を確認してください。',
    'quota_exceeded' => 'Google APIには到達しましたが、利用上限またはレート制限に達しています。',
    'google_server_error' => 'Google API側でサーバーエラーが発生しています。',
    default => 'Google APIから想定外のHTTPステータスが返されました。',
};
// レスポンス本文にはGoogle側の詳細情報が含まれる場合があるため、保存も返却もしない。
api_ok($result);
