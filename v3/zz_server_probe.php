<?php
/**
 * zz_server_probe.php — 一時: 本番サーバーで yt-dlp を動かせるかの調査（管理者だけ）。結果を見たら消す
 *   何も入れない・落とさない。外部プログラムは「場所と版を聞く」コマンドだけ動かす
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_admin()) {
    http_response_code(403);
    exit('管理者のみ');
}
header('Content-Type: text/plain; charset=utf-8');

$out = [];
$out['PHP'] = PHP_VERSION . ' / ' . PHP_OS_FAMILY . ' / SAPI ' . PHP_SAPI;
$out['CPU'] = function_exists('php_uname') ? php_uname('m') : '(php_uname なし)';
$out['disable_functions'] = ini_get('disable_functions') ?: '(なし)';
$out['open_basedir'] = ini_get('open_basedir') ?: '(なし)';
$out['max_execution_time'] = ini_get('max_execution_time');
$out['memory_limit'] = ini_get('memory_limit');
foreach (['proc_open', 'exec', 'shell_exec', 'popen'] as $f) {
    $out['使える: ' . $f] = function_exists($f) ? 'はい' : 'いいえ';
}
$tmp = sys_get_temp_dir();
$out['一時フォルダ'] = $tmp . (is_writable($tmp) ? '（書ける）' : '（書けない）');
$free = @disk_free_space(__DIR__);
$out['空き容量'] = $free === false ? '(不明)' : round($free / 1e9, 1) . ' GB';

// 外部プログラム: 場所と版を聞くだけ
$run = static function (array $cmd): string {
    if (!function_exists('proc_open')) {
        return '(proc_open なし)';
    }
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return '(起動できない)';
    }
    $o = trim(stream_get_contents($pipes[1]) . ' ' . stream_get_contents($pipes[2]));
    $code = proc_close($p);
    return 'exit ' . $code . ': ' . mb_substr($o, 0, 300);
};
$out['uname -srm'] = $run(['uname', '-srm']);
foreach (['python3', 'ffmpeg', 'ffprobe', 'yt-dlp'] as $bin) {
    $out['which ' . $bin] = $run(['sh', '-c', 'command -v ' . $bin]);
}
$out['python3 --version'] = $run(['python3', '--version']);
$out['ffmpeg -version'] = $run(['sh', '-c', 'ffmpeg -version 2>&1 | head -1']);

// 外への通信（中身は読まない。ステータスだけ）
foreach (['https://www.youtube.com/', 'https://github.com/'] as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $out['通信 ' . $url] = 'HTTP ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . (curl_errno($ch) ? ' / ' . curl_error($ch) : '');
}

foreach ($out as $k => $v) {
    echo $k, ': ', $v, "\n";
}
