<?php
/**
 * zz_server_probe.php — 一時: 本番サーバーで yt-dlp を動かせるかの試験（管理者だけ）。結果を見たら消す
 *   1. GitHub の yt-dlp 公式リリース（yt-dlp_linux）を一時フォルダに置く。公式の SHA-256 と合わなければ捨てる
 *   2. 版を聞く
 *   3. 限定公開の動画（BUMP OF CHICKEN、約5分）の音声を1本落とし、かかった時間と大きさを出す。落とした音声はすぐ消す
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_admin()) {
    http_response_code(403);
    exit('管理者のみ');
}
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(170);

const PROBE_VIDEO = 'https://www.youtube.com/watch?v=4ob6KBRgHvc';
$dir = sys_get_temp_dir() . '/abbey-ytdlp';
$bin = $dir . '/yt-dlp';
$say = static function (string $k, string $v): void {
    echo $k, ': ', $v, "\n";
    flush();
};
$get = static function (string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'AbbeyRoad.online']);
    $body = curl_exec($ch);
    return is_string($body) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $body : null;
};
$run = static function (array $cmd, int $timeout): array {
    $t = microtime(true);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return [-1, '(起動できない)', 0];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $o = '';
    while (proc_get_status($p)['running']) {
        $o .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (microtime(true) - $t > $timeout) {
            proc_terminate($p);
            $o .= ' (時間切れで止めた)';
            break;
        }
        usleep(200000);
    }
    $o .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);
    return [$code, mb_substr(trim($o), -600), round(microtime(true) - $t, 1)];
};

// 1. 置く
if (!is_file($bin)) {
    @mkdir($dir, 0700, true);
    $sums = $get('https://github.com/yt-dlp/yt-dlp/releases/latest/download/SHA2-256SUMS');
    $data = $get('https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux');
    $want = $sums !== null && preg_match('/^([0-9a-f]{64})\s+yt-dlp_linux$/m', $sums, $m) ? $m[1] : null;
    if ($data === null || $want === null || !hash_equals($want, hash('sha256', $data))) {
        $say('置く', '失敗（取れない、または SHA-256 が合わない）');
        exit;
    }
    file_put_contents($bin, $data);
    chmod($bin, 0700);
    $say('置く', 'OK（' . round(strlen($data) / 1e6, 1) . ' MB、SHA-256 一致）');
} else {
    $say('置く', 'もうある');
}

// 2. 版
[$code, $o] = $run([$bin, '--version'], 60);
$say('版', "exit $code: $o");
if ($code !== 0) {
    exit;
}

// 3. 1本落とす
$out = $dir . '/probe';
[$code, $o, $sec] = $run([$bin, '--no-warnings', '--no-playlist', '--no-progress', '-f', 'bestaudio[ext=m4a]/bestaudio', '-o', $out . '.%(ext)s', PROBE_VIDEO], 140);
$files = glob($out . '.*') ?: [];
$say('落とす', "exit $code / {$sec}秒 / " . ($files ? basename($files[0]) . ' ' . round(filesize($files[0]) / 1e6, 1) . ' MB' : 'ファイルなし'));
$say('ログ', $o);
foreach ($files as $f) {
    @unlink($f);
}
$say('後片付け', '落とした音声を消した');
