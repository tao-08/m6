<?php
/**
 * lib/audio_stream.php — ディスク上の音源を、from=（バイト位置）と Range に答えて流す
 *   使うのは attach_image.php（直接置いた MP3）と drive_audio.php（ドライブから落としてキャッシュした音源）
 *   ?from=バイト位置: Range が使えない環境向け。200 で、その位置から先だけを流す（WAV は作り直したヘッダー付き。lib/wav.php）
 *   &to=バイト位置: そこまでで止める（シャッフル再生が1曲分だけ受け取るため。player.js）。from なしでも使える
 *   キャッシュ: ETag / Last-Modified を付け、private, no-cache（ブラウザは保存してよいが、使う前に毎回ここへ確認する）。
 *     確認はログインと権限のチェックのあとなので、権限を外された人には届かない。変わっていなければ 304（本体は流さない）
 */
declare(strict_types=1);

require_once __DIR__ . '/wav.php';

/**
 * @param string      $mime   Content-Type（WAV を途中から流すときは audio/wav に変わる）
 * @param string|null $dlName ダウンロードさせるときのファイル名（null ならそのまま再生）
 */
function audio_stream_file(string $path, string $mime, ?string $dlName = null): void
{
    header('X-Content-Type-Options: nosniff');
    $size = (int)filesize($path);
    $mtime = (int)filemtime($path);
    $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
    $from = 0;
    $to = $size - 1;
    $skip = ctype_digit((string)($_GET['from'] ?? '')) ? (int)$_GET['from'] : 0;
    $cap = ctype_digit((string)($_GET['to'] ?? '')) ? (int)$_GET['to'] : null;
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
