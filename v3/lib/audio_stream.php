<?php
/**
 * lib/audio_stream.php — ディスク上の音源を、from=（バイト位置）と Range に答えて流す
 *   使うのは attach_image.php（直接置いた MP3）と drive_audio.php（ドライブから落としてキャッシュした音源）
 *   ?from=バイト位置: Range が使えない環境向け。200 で、その位置から先だけを流す（WAV は作り直したヘッダー付き。lib/wav.php）
 *   &to=バイト位置: そこまでで止める（シャッフル再生が1曲分だけ受け取るため。player.js）。from なしでも使える
 *   キャッシュ: ETag / Last-Modified を付け、private, no-cache（ブラウザは保存してよいが、使う前に毎回ここへ確認する）。
 *     確認はログインと権限のチェックのあとなので、権限を外された人には届かない。変わっていなければ 304（本体は流さない）
 *   音源は初めて流すときに 160kbps CBR の MP3 に置き換える（audio_shrink。作り終わるまで待つ）
 *     from= / to= は元の大きさで数えた位置で来る（player.js / app_player.js の data-size）ので、大きさの比で直す
 */
declare(strict_types=1);

require_once __DIR__ . '/wav.php';

const AUDIO_LIGHT_KBPS = 160;

/**
 * サーバーには再生用の 160kbps CBR の MP3 だけを置く（容量を減らすため）。元のファイルは初めて流すときに 160 に置き換える
 *   置き換えたら横に印 <元>.160 を置き、中に元の大きさ（バイト数）を書く。from= / to= / data-size は元の大きさで数えたままなので、比で直す
 *   1割以上小さくならなかった（元が 160k 前後以下）・作れなかったときは、置き換えずに印だけ置く（作り直すときは印を消す）
 *   ドライブの音源は、元のファイルはドライブに残る（ダウンロードはドライブへ。drive_audio.php）
 */
function audio_mark(string $path): string
{
    return $path . '.' . AUDIO_LIGHT_KBPS;
}

/** 先頭の ID3 タグ（ジャケット画像など）の長さ。音の前にあるので、from= / to= の位置からは外して数える */
function audio_id3_len(string $head): int
{
    if (strlen($head) < 10 || !str_starts_with($head, 'ID3')) {
        return 0;
    }
    $b = array_map('ord', str_split(substr($head, 6, 4)));
    return 10 + (($b[0] & 0x7F) << 21 | ($b[1] & 0x7F) << 14 | ($b[2] & 0x7F) << 7 | ($b[3] & 0x7F)) + (ord($head[5]) & 0x10 ? 10 : 0);
}

/** 元の大きさ。置き換えていなければ今の大きさ */
function audio_orig_size(string $path): int
{
    clearstatcache(true, $path);
    $n = (int)@file_get_contents(audio_mark($path));
    return $n > 0 ? $n : (is_file($path) ? (int)filesize($path) : 0);
}

/**
 * MIX（YouTube の区分でない音源）を下げる量。シャッフル再生で YouTube の音源より大きく聞こえるため。iPhone は再生側で音量を変えられないので、ファイルを作るときに下げる
 *   下げたら横に印 <元>.256q を置く（中身 ok / 作れなかったら fail）。印の無い MIX は、もう 160 にしてあっても次に流すときに作り直す
 *   印は数字で始める: 元を消す・差し替えるときに .[0-9]* でまとめて消される（drive_cache_derived・live_image.php）
 *   shortcut: 区分をあとから YouTube に変えても下げたまま。気になるなら区分の変更で印とファイルを作り直す
 */
const AUDIO_MIX_GAIN_DB = -4;
/** MIX は元が 256k。160 にすると高音が削れるので、元と同じ 256k で作る（印は <元>.256q） */
const AUDIO_MIX_KBPS = 256;

function audio_quiet_mark(string $path): string
{
    return $path . '.' . AUDIO_MIX_KBPS . 'q';
}

/** 前の作り（160k で下げた MIX）の印。ドライブの音源は落とし直して作り直す（drive_audio.php）。直接置いた MP3 は元が残っていないのでそのまま */
function audio_quiet_mark_160(string $path): string
{
    return audio_mark($path) . 'q';
}

/** まだなら 160 に置き換える（$quiet: MIX なので音量も下げる）。同時の要求は flock で1つだけが作り、ほかは待つ */
function audio_shrink(string $path, bool $quiet = false): void
{
    $mark = audio_mark($path);
    $todo = static function () use ($path, $mark, $quiet): bool {
        clearstatcache(true, $mark);
        clearstatcache(true, audio_quiet_mark($path));
        clearstatcache(true, audio_quiet_mark_160($path));
        return !is_file($mark) || ($quiet && !is_file(audio_quiet_mark($path)) && !is_file(audio_quiet_mark_160($path)));
    };
    if (!$todo() || !($lock = @fopen($mark . '.lock', 'c'))) {
        return;
    }
    if (flock($lock, LOCK_EX)) {
        if ($todo()) {
            audio_shrink_make($path, $mark, $quiet);
        }
        flock($lock, LOCK_UN);
    }
    fclose($lock);
    @unlink($mark . '.lock');
}

function audio_shrink_make(string $path, string $mark, bool $quiet = false): void
{
    require_once __DIR__ . '/ytdlp.php';
    @set_time_limit(0);
    ignore_user_abort(true); // 途中で切られても作り終える（半端なファイルは .part のまま残さない）
    $orig = audio_orig_size($path); // MIX の作り直し（もう 160 にしてある）でも、元の大きさで数えたまま（from= / to= / data-size）
    $old = $path . '.' . AUDIO_LIGHT_KBPS . '.mp3'; // 前の作り（元の横に置いていた軽い MP3）。あればそれを使う（MIX は音量を下げるので作り直す）
    $part = $old . '.part.mp3'; // ffmpeg は出力の形式を拡張子で決める
    $why = null;
    clearstatcache(true, $old);
    if (!$quiet && is_file($old) && filesize($old) > 0) {
        rename($old, $part);
    } else {
        $ffmpeg = ytdlp_ffmpeg_dir() . '/ffmpeg';
        $why = PHP_OS_FAMILY !== 'Windows' && !is_file($ffmpeg) ? ytdlp_ffmpeg_install() : null;
        if ($why === null) {
            // CBR（from= のバイト位置で切るため）。タグ・ジャケット画像は落とす
            [$code, $log] = ytdlp_run(['-v', 'error', '-y', '-i', $path, '-vn', '-map_metadata', '-1',
                ...($quiet ? ['-af', 'volume=' . AUDIO_MIX_GAIN_DB . 'dB'] : []),
                '-c:a', 'libmp3lame', '-b:a', ($quiet ? AUDIO_MIX_KBPS : AUDIO_LIGHT_KBPS) . 'k', '-ar', '44100', $part], 600, $ffmpeg);
            clearstatcache(true, $part);
            $why = $code !== 0 || !is_file($part) || filesize($part) === 0 ? "ffmpeg exit $code: $log" : null;
        }
    }
    @unlink($old);
    // 1割も減らないなら置き換えない（元が 160k 以下のとき）。MIX は音量を変えたので必ず置き換える
    if ($why === null && ($quiet || filesize($part) < $orig * 0.9) && file_put_contents($mark, (string)$orig, LOCK_EX) && rename($part, $path)) {
        if ($quiet) {
            file_put_contents(audio_quiet_mark($path), 'ok', LOCK_EX);
        }
        return;
    }
    @unlink($part);
    if ($why !== null) {
        error_log('audio_shrink: ' . basename($path) . ' ' . $why);
    }
    file_put_contents($mark, (string)$orig, LOCK_EX); // 印: 置き換えずにそのまま流す
    if ($quiet) {
        file_put_contents(audio_quiet_mark($path), 'fail', LOCK_EX); // 作れなかった: 流すたびに作り直そうとしない（作り直すときは印を消す）
    }
}

/**
 * 管理者向け: 流す前にサーバーが何をしているか（player.js の diag。&state=1）。ファイルの有無だけで見る（記録は増やさない）
 *   download … ドライブから落としている途中（<元>.part。lib/drive_cache.php）。total は DB の大きさ
 *   convert … ffmpeg が 160 / 256 に作っている途中（audio_shrink_make の .part.mp3）
 *   ready … 元はある（変換を待っている・もう終わった）/ none … まだ何も無い
 */
function audio_prep_state(string $path, int $size = 0): array
{
    clearstatcache();
    $part = $path . '.' . AUDIO_LIGHT_KBPS . '.mp3.part.mp3';
    return match (true) {
        is_file($path . '.part') => ['step' => 'download', 'done' => (int)filesize($path . '.part'), 'total' => $size],
        is_file($part) => ['step' => 'convert', 'done' => (int)filesize($part)],
        default => ['step' => is_file($path) ? 'ready' : 'none'],
    };
}

/**
 * @param string      $mime   Content-Type（WAV を途中から流すときは audio/wav に変わる）
 * @param string|null $dlName ダウンロードさせるときのファイル名（null ならそのまま再生）
 * @param bool        $quiet  MIX（YouTube の区分でない音源）: 音量を下げたものを流す
 */
function audio_stream_file(string $path, string $mime, ?string $dlName = null, bool $quiet = false): void
{
    header('X-Content-Type-Options: nosniff');
    $orig = (int)filesize($path);
    if (str_starts_with($mime, 'audio/')) {
        audio_shrink($path, $quiet);
        $orig = max(1, audio_orig_size($path));
        if (filesize($path) < $orig || @file_get_contents(audio_quiet_mark($path)) === 'ok' || @file_get_contents(audio_quiet_mark_160($path)) === 'ok') { // 下げて作り直したものは元より大きくても MP3
            $mime = 'audio/mpeg';
        }
    }
    $size = (int)filesize($path);
    $mtime = (int)filemtime($path);
    $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
    $from = 0;
    $to = $size - 1;
    // from= / to= は「時刻 ÷ 長さ × 元の大きさ」。音の部分（タグの後ろ）の割合として、今のファイルの位置に直す
    $hdr = isset($_GET['from']) || isset($_GET['to']) ? audio_id3_len((string)file_get_contents($path, false, null, 0, 10)) : 0;
    $at = static fn(string $q): ?int => ctype_digit((string)($_GET[$q] ?? '')) ? (int)round($hdr + (int)$_GET[$q] / max(1, $orig) * ($size - $hdr)) : null;
    $skip = $at('from') ?? 0;
    $cap = $at('to');
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
