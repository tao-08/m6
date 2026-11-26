<?php
/**
 * lib/ytdlp.php — バンドの YouTube（限定公開も）から音声だけ落とす（動画から曲を検出で使う。管理者だけ）
 *   サーバーに yt-dlp は入っていないので、GitHub の公式リリース（yt-dlp_linux、Python 込みの単体版）を
 *   公開フォルダの外（ホーム側の .abbey-ytdlp）に置いて動かす。/tmp は実行禁止（noexec）なので使えない。
 *   公式の SHA-256 と合わないものは置かない。落とせなかったら、最新版に取り替えて1回だけやり直す（YouTube の変更でよく壊れるため）
 *   1曲だけ検出し直すときは、全部落としてから ffmpeg で区間を切り出す（yt-dlp に区間だけ取らせると YouTube が遅く、数分かかった）。ffmpeg はyt-dlp 公式の FFmpeg ビルドも同じ所に置く（初回だけ。約2分）
 *   手元（Windows）は config.php の 'ytdlp' => ['bin' => 'yt-dlp.exe の場所', 'ffmpeg' => 'ffmpeg.exe があるフォルダ'] を使う（winget install yt-dlp.yt-dlp。Apache からは PATH が見えないため）
 */
declare(strict_types=1);

const YTDLP_RELEASE = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/';
const YTDLP_FFMPEG_RELEASE = 'https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/';
const YTDLP_FFMPEG_TAR = 'ffmpeg-master-latest-linux64-gpl.tar.xz';
/** 落とす音声の上限（バンドの MP3 と同じ） */
const YTDLP_MAX_BYTES = 60 * 1024 * 1024;
/** 取り替えてから、次に取り替えてよいまでの秒数（壊れたまま何度も GitHub に取りに行かないため） */
const YTDLP_UPDATE_GAP = 86400;

function ytdlp_dir(): string
{
    return dirname(__DIR__, 2) . '/.abbey-ytdlp';
}

function ytdlp_bin(): string
{
    return PHP_OS_FAMILY === 'Windows' ? (string)(config('ytdlp')['bin'] ?? 'yt-dlp') : ytdlp_dir() . '/yt-dlp';
}

/** 公式リリースを取ってきて置く。SHA-256 が合わなければ置かない */
function ytdlp_install(): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        return true;
    }
    $dir = ytdlp_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $get = static function (string $name): ?string {
        $ch = curl_init(YTDLP_RELEASE . $name);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'AbbeyRoad.online']);
        $body = curl_exec($ch);
        return is_string($body) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $body : null;
    };
    $sums = $get('SHA2-256SUMS');
    $want = $sums !== null && preg_match('/^([0-9a-f]{64})\s+yt-dlp_linux$/m', $sums, $m) ? $m[1] : null;
    $data = $want === null ? null : $get('yt-dlp_linux');
    if ($data === null || !hash_equals($want, hash('sha256', $data))) {
        error_log('yt-dlp: install failed (download or sha256)');
        return false;
    }
    $tmp = $dir . '/yt-dlp.new';
    if (file_put_contents($tmp, $data) !== strlen($data) || !chmod($tmp, 0700) || !rename($tmp, ytdlp_bin())) {
        @unlink($tmp);
        return false;
    }
    // shortcut: 試験のときに /tmp に置いた分（実行できない）を消す。消えたらこの行ごと消してよい
    @unlink(sys_get_temp_dir() . '/abbey-ytdlp/yt-dlp');
    return true;
}

/** ffmpeg（と ffprobe）があるフォルダ */
function ytdlp_ffmpeg_dir(): string
{
    return PHP_OS_FAMILY === 'Windows' ? (string)(config('ytdlp')['ffmpeg'] ?? '') : ytdlp_dir();
}

/** yt-dlp 公式の FFmpeg ビルドを取ってきて、ffmpeg と ffprobe だけ取り出して置く。SHA-256 が合わなければ置かない。失敗したら理由 */
function ytdlp_ffmpeg_install(): ?string
{
    $dir = ytdlp_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return 'mkdir failed';
    }
    $tar = $dir . '/ffmpeg.tar.xz';
    $get = static function (string $name, $fp = null): ?string {
        $ch = curl_init(YTDLP_FFMPEG_RELEASE . $name);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'AbbeyRoad.online']
            + ($fp === null ? [CURLOPT_RETURNTRANSFER => true] : [CURLOPT_FILE => $fp]));
        $body = curl_exec($ch);
        return $body !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? (is_string($body) ? $body : '') : null;
    };
    $sums = $get('checksums.sha256');
    if ($sums === null || !preg_match('/^([0-9a-f]{64})\s+\*?' . preg_quote(YTDLP_FFMPEG_TAR, '/') . '$/m', $sums, $m)) {
        return 'ffmpeg checksums';
    }
    $fp = fopen($tar, 'wb');
    $ok = $get(YTDLP_FFMPEG_TAR, $fp) !== null;
    fclose($fp);
    try {
        if (!$ok || !hash_equals($m[1], (string)hash_file('sha256', $tar))) {
            return 'ffmpeg download or sha256';
        }
        [$code, $log] = ytdlp_run(['-xJf', $tar, '-C', $dir, '--strip-components=2', '--wildcards', '*/bin/ffmpeg', '*/bin/ffprobe'], 300, 'tar');
        if ($code !== 0 || !is_file($dir . '/ffmpeg')) {
            return "tar exit $code: $log";
        }
        @chmod($dir . '/ffmpeg', 0700);
        @chmod($dir . '/ffprobe', 0700);
        return null;
    } finally {
        @unlink($tar);
    }
}

/** yt-dlp（$bin を渡せばそのコマンド）を動かす。戻り値: [終了コード, 出力の最後の部分] */
function ytdlp_run(array $args, int $timeout, ?string $bin = null): array
{
    $env = PHP_OS_FAMILY === 'Windows' ? null : ['TMPDIR' => ytdlp_dir(), 'HOME' => ytdlp_dir(), 'PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'];
    $p = @proc_open([$bin ?? ytdlp_bin(), ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) {
        return [-1, 'cannot start ' . ($bin ?? 'yt-dlp')];
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
 * 動画の音声（m4a）を落とす。戻り値: ファイルのパス（使い終わったら消す）。落とせなければ null（$why に理由。ログ用）
 *   m4a は Gemini がそのまま聴ける（audio/aac として渡す）ので、ffmpeg で変換しない
 *   $section: [始まりの秒, 終わりの秒] を渡すと、その区間だけ切り出したファイルを返す（ffmpeg が要る。無ければ先に置く）
 */
function ytdlp_audio(string $videoId, ?string &$why = null, ?array $section = null): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        $why = 'bad video id';
        return null;
    }
    $bin = ytdlp_bin();
    if (PHP_OS_FAMILY !== 'Windows' && !is_file($bin) && !ytdlp_install()) {
        $why = 'install failed';
        return null;
    }
    if ($section !== null && PHP_OS_FAMILY !== 'Windows' && !is_file(ytdlp_ffmpeg_dir() . '/ffmpeg') && ($why = ytdlp_ffmpeg_install()) !== null) {
        return null;
    }
    $base = sys_get_temp_dir() . '/abbey-yt-' . bin2hex(random_bytes(6)); // 音声は /tmp に置いてよい（実行しない）
    $fetch = static function () use ($videoId, $base): array {
        foreach (glob($base . '.*') ?: [] as $f) {
            @unlink($f);
        }
        [$code, $log] = ytdlp_run(['--no-warnings', '--no-playlist', '--no-progress', '--max-filesize', (string)YTDLP_MAX_BYTES,
            '-f', 'bestaudio[ext=m4a]', '-o', $base . '.%(ext)s', 'https://www.youtube.com/watch?v=' . $videoId], 240);
        $file = $base . '.m4a';
        return [$code === 0 && is_file($file) && filesize($file) > 0 ? $file : null, "exit $code: $log"];
    };
    [$file, $why] = $fetch();
    // 落とせなければ1回だけやり直す（YouTube はたまに 403 を返す）。前に取り替えてから時間がたっていれば、先に最新版に取り替える
    if ($file === null) {
        if (PHP_OS_FAMILY !== 'Windows' && time() - (int)@filemtime($bin) > YTDLP_UPDATE_GAP) {
            ytdlp_install();
        }
        [$file, $why] = $fetch();
    }
    if ($file !== null && $section !== null) {
        // 区間を切り出す（再エンコードしない。-ss を前に置くと速い）
        [$code, $log] = ytdlp_run(['-v', 'error', '-y', '-ss', (string)(int)$section[0], '-t', (string)((int)$section[1] - (int)$section[0]), '-i', $file, '-vn', '-c', 'copy', $base . '.cut.m4a'], 120, ytdlp_ffmpeg_dir() . '/ffmpeg');
        @unlink($file);
        $file = $code === 0 && is_file($base . '.cut.m4a') && filesize($base . '.cut.m4a') > 0 ? $base . '.cut.m4a' : null;
        $why = "ffmpeg exit $code: $log";
    }
    if ($file === null) {
        foreach (glob($base . '.*') ?: [] as $f) {
            @unlink($f);
        }
        error_log('yt-dlp: download failed ' . $videoId . ' ' . $why);
    }
    return $file;
}
