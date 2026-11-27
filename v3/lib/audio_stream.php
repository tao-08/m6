<?php
/**
 * lib/audio_stream.php — ディスク上の音源を、from=（バイト位置）と Range に答えて流す
 *   使うのは attach_image.php（直接置いた MP3）と drive_audio.php（ドライブから落としてキャッシュした音源）
 *   ?from=バイト位置: Range が使えない環境向け。200 で、その位置から先だけを流す（WAV は作り直したヘッダー付き。lib/wav.php）
 *   &to=バイト位置: そこまでで止める（シャッフル再生が1曲分だけ受け取るため。player.js）。from なしでも使える
 *   キャッシュ: ETag / Last-Modified を付け、private, no-cache（ブラウザは保存してよいが、使う前に毎回ここへ確認する）。
 *     確認はログインと権限のチェックのあとなので、権限を外された人には届かない。変わっていなければ 304（本体は流さない）
 *   再生（dl なし）の音源は、通信量を減らすため 160kbps CBR の MP3（元のファイルの横の <元>.160.mp3）を流す。初回の再生で ffmpeg で作る（作り終わるまで待つ）
 *     from= / to= は元の大きさで数えた位置で来る（player.js / app_player.js の data-size）ので、大きさの比で軽いほうの位置に直す。dl=1 は元のファイル
 */
declare(strict_types=1);

require_once __DIR__ . '/wav.php';

const AUDIO_LIGHT_KBPS = 160;

/**
 * 再生用の軽い MP3 のパス。使えなければ null（元のファイルを流す）
 *   無ければここで作る。同時の要求は flock で1つだけが作り、ほかは待つ
 *   1割以上小さくならなかった（元が 160k 前後以下）・作れなかったときは空のファイルを印に置き、作り直さない（作り直すときは印を消す）
 */
function audio_light(string $path): ?string
{
    $light = $path . '.' . AUDIO_LIGHT_KBPS . '.mp3';
    clearstatcache(true, $light);
    if (!is_file($light) && ($lock = @fopen($light . '.lock', 'c'))) {
        if (flock($lock, LOCK_EX)) {
            clearstatcache(true, $light);
            if (!is_file($light)) {
                audio_light_make($path, $light);
            }
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        clearstatcache(true, $light);
    }
    $n = is_file($light) ? (int)filesize($light) : 0;
    return $n > 0 && $n < (int)filesize($path) ? $light : null;
}

function audio_light_make(string $path, string $light): void
{
    require_once __DIR__ . '/ytdlp.php';
    @set_time_limit(0);
    ignore_user_abort(true); // 途中で切られても作り終える（半端なファイルは .part のまま残さない）
    $ffmpeg = ytdlp_ffmpeg_dir() . '/ffmpeg';
    $part = $light . '.part.mp3'; // ffmpeg は出力の形式を拡張子で決める
    $why = PHP_OS_FAMILY !== 'Windows' && !is_file($ffmpeg) ? ytdlp_ffmpeg_install() : null;
    if ($why === null) {
        // CBR（from= のバイト位置で切るため）。タグ・ジャケット画像は落とす
        [$code, $log] = ytdlp_run(['-v', 'error', '-y', '-i', $path, '-vn', '-map_metadata', '-1', '-c:a', 'libmp3lame', '-b:a', AUDIO_LIGHT_KBPS . 'k', '-ar', '44100', $part], 600, $ffmpeg);
        clearstatcache(true, $part);
        $n = is_file($part) ? (int)filesize($part) : 0;
        $why = $code !== 0 || $n === 0 ? "ffmpeg exit $code: $log" : null;
        // 1割も減らないなら作らない（元が 160k のときに同じ大きさの複製が増えるため）
        if ($why === null && $n < (int)filesize($path) * 0.9 && rename($part, $light)) {
            return;
        }
    }
    @unlink($part);
    if ($why !== null) {
        error_log('audio_light: ' . basename($path) . ' ' . $why);
    }
    touch($light); // 印（空）: 元のファイルを流す
}

/**
 * @param string      $mime   Content-Type（WAV を途中から流すときは audio/wav に変わる）
 * @param string|null $dlName ダウンロードさせるときのファイル名（null ならそのまま再生）
 */
function audio_stream_file(string $path, string $mime, ?string $dlName = null): void
{
    header('X-Content-Type-Options: nosniff');
    $scale = 1.0;
    if ($dlName === null && str_starts_with($mime, 'audio/') && ($light = audio_light($path)) !== null) {
        $scale = filesize($light) / filesize($path);
        $path = $light;
        $mime = 'audio/mpeg';
    }
    $size = (int)filesize($path);
    $mtime = (int)filemtime($path);
    $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
    $from = 0;
    $to = $size - 1;
    $skip = ctype_digit((string)($_GET['from'] ?? '')) ? (int)round((int)$_GET['from'] * $scale) : 0;
    $cap = ctype_digit((string)($_GET['to'] ?? '')) ? (int)round((int)$_GET['to'] * $scale) : null;
    $part = $skip > 0 || $cap !== null; // 一部だけの 200（Range には答えない）
    $prefix = '';
    if ($part) { // 一部だけの 200 は使い回さない
        header('Cache-Control: private, no-store');
    } else {
        header('Cache-Control: private, no-cache'); // 前段のキャッシュが人をまたいで使い回さないように（private）。使う前に毎回確認させる（no-cache）
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ims = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($inm !== '' ? in_array($etag, array_map('trim', explode(',', str_replace('W/', '', $inm))), true) : ($ims !== false && $ims >= $mtime)) {
            http_response_code(304);
            return;
        }
        $ifRange = $_SERVER['HTTP_IF_RANGE'] ?? ''; // 前に保存した続きを頼まれたが、ファイルが変わっていたら全部を返す
        if ($ifRange !== '' && $ifRange !== $etag && strtotime($ifRange) !== $mtime) {
            unset($_SERVER['HTTP_RANGE']);
        }
    }
    if ($part) {
        if ($skip >= $size) {
            http_response_code(404);
            return;
        }
        $fp = fopen($path, 'rb');
        $wav = $skip > 0 ? wav_info((string)fread($fp, 65536)) : null; // 頭からならファイルのヘッダーのまま
        $resume = $wav ? wav_resume($wav, $size, $skip) : null;
        if ($resume) {
            [$prefix, $from] = $resume;
            $to = $from + $resume[2] - 1;
            $mime = 'audio/wav';
        } else {
            $from = $skip;
        }
        if ($cap !== null) {
            $to = max($from, min($to, $cap));
        }
        header('Accept-Ranges: none');
    } elseif (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { // bytes=-N: 終わりから N バイト
            $from = max(0, $size - (int)$m[2]);
        } else {
            $from = (int)$m[1];
            $to = $m[2] !== '' ? min($to, (int)$m[2]) : $to;
        }
        if ($from > $to) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            return;
        }
        http_response_code(206);
        header("Content-Range: bytes $from-$to/$size");
    }
    header('Content-Type: ' . $mime);
    if (!$part) {
        header('Accept-Ranges: bytes');
    }
    header('Content-Length: ' . (strlen($prefix) + $to - $from + 1));
    if ($dlName !== null) {
        header("Content-Disposition: attachment; filename=\"mix.mp3\"; filename*=UTF-8''" . rawurlencode($dlName));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
        return;
    }
    $fp ??= fopen($path, 'rb');
    fseek($fp, $from);
    echo $prefix;
    for ($left = $to - $from + 1; $left > 0 && !feof($fp);) {
        $chunk = (string)fread($fp, min(65536, $left));
        if ($chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fp);
}
