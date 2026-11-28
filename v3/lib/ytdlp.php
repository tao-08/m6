<?php
/**
 * lib/ytdlp.php — 裏の処理の置き場（公開フォルダの外 .abbey-ytdlp）と ffmpeg（静的ビルド。初回だけ置く）
 *   曲の検出・自動チャプター・軽い MP3（lib/audio_stream.php）で使う。YouTube からは落とさない（音声はバンドの MP3 を使う）
 *   /tmp は実行禁止（noexec）なので、ffmpeg はホーム側に置く
 *   手元（Windows）は config.php の 'ytdlp' => ['ffmpeg' => 'ffmpeg.exe があるフォルダ'] を使う（Apache からは PATH が見えないため）
 */
declare(strict_types=1);

// サーバーに xz が無く .tar.xz を開けないので、PHP だけで開ける gzip の静的ビルドを版を決めて使う（SHA-256 は GitHub のリリースの値）
const YTDLP_FFMPEG_URL = 'https://github.com/eugeneware/ffmpeg-static/releases/download/b6.1.1/ffmpeg-linux-x64.gz';
const YTDLP_FFMPEG_SHA256 = 'bfe8a8fc511530457b528c48d77b5737527b504a3797a9bc4866aeca69c2dffa';

function ytdlp_dir(): string
{
    return dirname(__DIR__, 2) . '/.abbey-ytdlp';
}

/** ffmpeg があるフォルダ */
function ytdlp_ffmpeg_dir(): string
{
    return PHP_OS_FAMILY === 'Windows' ? (string)(config('ytdlp')['ffmpeg'] ?? '') : ytdlp_dir();
}

/** ffmpeg の静的ビルド（gzip）を取ってきて、PHP で展開して置く。SHA-256 が合わなければ置かない。失敗したら理由 */
function ytdlp_ffmpeg_install(): ?string
{
    $dir = ytdlp_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return 'mkdir failed';
    }
    $gz = $dir . '/ffmpeg.gz';
    $tmp = $dir . '/ffmpeg.tmp';
    try {
        $fp = fopen($gz, 'wb');
        $ch = curl_init(YTDLP_FFMPEG_URL);
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'AbbeyRoad.online']);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        fclose($fp);
        if (!$ok || !hash_equals(YTDLP_FFMPEG_SHA256, (string)hash_file('sha256', $gz))) {
            return 'ffmpeg download or sha256';
        }
        $in = gzopen($gz, 'rb');
        $out = fopen($tmp, 'wb');
        $copied = $in && $out ? stream_copy_to_stream($in, $out) : false;
        $in && gzclose($in);
        $out && fclose($out);
        if (!$copied || !rename($tmp, $dir . '/ffmpeg')) { // 途中まで書いたものを ffmpeg として残さない
            return 'ffmpeg gunzip';
        }
        @chmod($dir . '/ffmpeg', 0700);
        return null;
    } finally {
        @unlink($gz);
        @unlink($tmp);
    }
}

/** ffmpeg（$bin を渡せばそのコマンド）を動かす。戻り値: [終了コード, 出力の最後の部分] */
function ytdlp_run(array $args, int $timeout, ?string $bin = null): array
{
    $env = PHP_OS_FAMILY === 'Windows' ? null : ['TMPDIR' => ytdlp_dir(), 'HOME' => ytdlp_dir(), 'PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'];
    $p = @proc_open([$bin ?? ytdlp_ffmpeg_dir() . '/ffmpeg', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) {
        return [-1, 'cannot start ' . ($bin ?? 'ffmpeg')];
    }
    fclose($pipes[0]); // ffmpeg がキー入力を待たないように
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $start = microtime(true);
    $log = '';
    while (proc_get_status($p)['running']) {
        $log = substr($log . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), -4000);
        if (microtime(true) - $start > $timeout) {
            proc_terminate($p);
            $log .= ' timeout';
            break;
        }
        usleep(200000);
    }
    $log .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), trim(substr($log, -600))];
}

/**
 * 音源の $from 秒から $len 秒を MP3 に切り出す（160kbps。Gemini には audio/mpeg で渡す）。戻り値: パス（使い終わったら消す）。できなければ null
 *   MP3 は -c copy だと区切りがずれることがあるので、再エンコードする（数十秒なので速い）
 */
function audio_cut_mp3(string $path, int $from, int $len): ?string
{
    // shortcut: 前の作りで置いた yt-dlp を消す。全部のサーバーで消えたらこの行ごと消してよい
    @unlink(ytdlp_dir() . '/yt-dlp');
    if (PHP_OS_FAMILY !== 'Windows' && !is_file(ytdlp_ffmpeg_dir() . '/ffmpeg') && ($why = ytdlp_ffmpeg_install()) !== null) {
        error_log('audio cut: ' . $why);
        return null;
    }
    $out = sys_get_temp_dir() . '/abbey-cut-' . bin2hex(random_bytes(6)) . '.mp3'; // 音声は /tmp に置いてよい（実行しない）
    [$code, $log] = ytdlp_run(['-v', 'error', '-y', '-ss', (string)max(0, $from), '-t', (string)max(1, $len), '-i', $path, '-vn', '-map_metadata', '-1', '-c:a', 'libmp3lame', '-b:a', '160k', $out], 120);
    if ($code === 0 && is_file($out) && filesize($out) > 0) {
        return $out;
    }
    @unlink($out);
    error_log("audio cut: exit $code: $log");
    return null;
}
