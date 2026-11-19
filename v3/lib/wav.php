<?php
/**
 * =====================================================================
 *  lib/wav.php — WAV を途中から流すための、ヘッダーの読み書き（DB・画面に依存しない関数だけ）
 * =====================================================================
 *  本番の前段のキャッシュが Range を落とすので、プレーヤー（assets/app.js の setupAudioPlayers）は
 *  「?from=バイト位置」で途中から流してもらう。MP3 は途中から切っても鳴るが、WAV は先頭のヘッダーが無いと鳴らない。
 *  そこで WAV は、元のヘッダーから作り直した44バイトのヘッダーを頭に付けて、データの途中から流す。
 *  使うのは attach_image.php（直接置いた音源）と drive_audio.php（ドライブの音源）
 * =====================================================================
 */
declare(strict_types=1);

/**
 * WAV の先頭（64KB ほどあればよい）から、PCM の形式とデータの位置を読む。読めない・PCM でないなら null
 * @return array{channels:int, rate:int, byteRate:int, align:int, bits:int, dataStart:int, dataSize:int}|null
 */
function wav_info(string $head): ?array
{
    $len = strlen($head);
    if ($len < 12 || !str_starts_with($head, 'RIFF') || substr($head, 8, 4) !== 'WAVE') {
        return null;
    }
    $fmt = null;
    for ($off = 12; $off + 8 <= $len;) {
        $id = substr($head, $off, 4);
        $size = (int)unpack('V', $head, $off + 4)[1];
        if ($id === 'fmt ' && $size >= 16 && $off + 8 + $size <= $len) {
            $f = unpack('vtag/vch/Vrate/VbyteRate/valign/vbits', $head, $off + 8);
            // 拡張形式（0xFFFE）は、サブ形式の先頭2バイトが 1 なら PCM
            $tag = $f['tag'] === 0xFFFE && $size >= 40 ? (int)unpack('v', $head, $off + 8 + 24)[1] : $f['tag'];
            if ($tag !== 1 || $f['ch'] < 1 || $f['align'] < 1) {
                return null;
            }
            $fmt = ['channels' => $f['ch'], 'rate' => $f['rate'], 'byteRate' => $f['byteRate'], 'align' => $f['align'], 'bits' => $f['bits']];
        } elseif ($id === 'data') {
            return $fmt === null ? null : $fmt + ['dataStart' => $off + 8, 'dataSize' => $size];
        }
        $off += 8 + $size + ($size & 1); // チャンクは偶数バイトに揃えて並ぶ
    }
    return null;
}

/** PCM の WAV ヘッダー（44バイト）。$dataBytes はこのあとに続くデータのバイト数 */
function wav_header(array $info, int $dataBytes): string
{
    return pack('a4Va4a4VvvVVvva4V', 'RIFF', min(36 + $dataBytes, 0xFFFFFFFF), 'WAVE', 'fmt ', 16, 1,
        $info['channels'], $info['rate'], $info['byteRate'], $info['align'], $info['bits'], 'data', min($dataBytes, 0xFFFFFFFF));
}

/**
 * $from（ファイルの先頭からのバイト位置）から流すときの [頭に付けるヘッダー, ファイルの読み始め, 読むバイト数]。
 * 読み始めはサンプルの境界に切り下げる。データの前なら先頭から。データが無い位置なら null
 * @return array{0:string, 1:int, 2:int}|null
 */
function wav_resume(array $info, int $size, int $from): ?array
{
    $ds = $info['dataStart'];
    $end = $info['dataSize'] > 0 && $ds + $info['dataSize'] <= $size ? $ds + $info['dataSize'] : $size; // 申告より後ろ（LIST など）は流さない
    $start = $ds + intdiv(max(0, $from - $ds), $info['align']) * $info['align'];
    $bytes = $end - $start;
    return $bytes > 0 ? [wav_header($info, $bytes), $start, $bytes] : null;
}
