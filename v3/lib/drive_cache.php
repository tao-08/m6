<?php
/**
 * =====================================================================
 *  lib/drive_cache.php — ドライブの音源を、サーバーに1回だけ落として、そこから流す
 * =====================================================================
 *  シークや自動チャプターのたびにドライブへ問い合わせると、Google に止められる（上限・IP 制限）。
 *  そこで storage/drive_cache/<ファイルID>.dat に丸ごと保存し、2回目からはディスクから流す（drive_audio.php）。
 *  ・同じファイルの同時のダウンロードは、<ID>.lock の flock で1つだけ。ほかは終わるのを待つ
 *  ・落としている途中は <ID>.part。最後まで落とせて大きさが合ったときだけ .dat に rename する（半端なファイルを流さない）
 *  ・合計が DRIVE_CACHE_MAX_BYTES を超えたら、使われていない順（更新時刻の古い順）に消す
 *  ・storage/ は外から読めない（storage/.htaccess とルートの .htaccess）
 * =====================================================================
 */
declare(strict_types=1);

/** キャッシュの合計の上限（3GB） */
const DRIVE_CACHE_MAX_BYTES = 3 * 1024 ** 3;

function drive_cache_dir(): string
{
    $dir = __DIR__ . '/../storage/drive_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** 落とし終えたファイルの場所（$fileId は drive_id_valid を通ったものだけ） */
function drive_cache_path(string $fileId): string
{
    return drive_cache_dir() . '/' . $fileId . '.dat';
}

/** 流してよいキャッシュか。ある、かつ（大きさが分かっているなら）合っている */
function drive_cache_ok(string $path, int $size): bool
{
    return is_file($path) && ($size <= 0 || filesize($path) === $size);
}

/** バンドから外したファイルのキャッシュを消す。ほかのバンドがまだ使っているなら残す */
function drive_cache_forget(PDO $pdo, array $fileIds): void
{
    $st = $pdo->prepare('SELECT 1 FROM band_drive_file WHERE file_id = ? LIMIT 1');
    foreach ($fileIds as $id) {
        if (!drive_id_valid((string)$id)) {
            continue;
        }
        $st->execute([$id]);
        if (!$st->fetchColumn()) {
            @unlink(drive_cache_path((string)$id));
        }
    }
}

/** 上限を超えていたら、古い順に消す。$keep（今落としたもの）は消さない */
function drive_cache_trim(string $keep): void
{
    $files = [];
    $total = 0;
    foreach (glob(drive_cache_dir() . '/*.dat') ?: [] as $f) {
        $files[$f] = (int)filemtime($f);
        $total += (int)filesize($f);
    }
    asort($files);
    foreach ($files as $f => $mtime) {
        if ($total <= DRIVE_CACHE_MAX_BYTES) {
            break;
        }
        if ($f !== $keep) {
            $total -= (int)filesize($f);
            @unlink($f);
        }
    }
}

/**
 * ドライブが返したエラーの理由を、ヘッダーに載せられる短い文字にする（鍵・URL は含めない）
 *   Google の JSON の error.errors[0].reason か error.status。読めなければ本文の先頭 80 文字（英数字と . _ : - と空白だけ）
 */
function drive_upstream_reason(string $body): string
{
    $j = json_decode($body, true);
    $r = $j['error']['errors'][0]['reason'] ?? $j['error']['status'] ?? null;
    return substr(preg_replace('/[^A-Za-z0-9 ._:-]/', '', is_string($r) && $r !== '' ? $r : $body), 0, 80);
}

/**
 * ドライブのファイルを丸ごと $dest に落とす。成功なら null、失敗なら [状態（HTTP の番号か curl-番号）, 理由]
 *   全体の時間制限は付けない（大きいファイル）。止まったままなら 60 秒で切る
 *   リダイレクトには付いていかない（API キーのヘッダーがリダイレクト先にも送られるため）
 */
function drive_download(string $fileId, string $dest, int $dbSize): ?array
{
    try {
        $key = drive_api_key();
    } catch (RuntimeException) {
        return ['nokey', 'no api key'];
    }
    if (!($fp = @fopen($dest, 'wb'))) {
        return ['fs', 'cannot write cache'];
    }
    $status = 0;
    $len = -1;
    $errBody = '';
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER      => ['X-Goog-Api-Key: ' . $key, 'User-Agent: AbbeyRoad.online'], // キーは URL ではなくヘッダーで渡す
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME  => 60,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_HEADERFUNCTION  => static function ($c, string $line) use (&$status, &$len): int {
            if (str_starts_with($line, 'HTTP/')) {
                $status = (int)(explode(' ', $line)[1] ?? 0);
                $len = -1;
            } elseif (stripos($line, 'content-length:') === 0) {
                $len = (int)trim(substr($line, 15));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION   => static function ($c, string $chunk) use (&$status, &$errBody, $fp): int {
            if ($status === 200) {
                return (int)fwrite($fp, $chunk); // 書けなかったら 0 を返して止める（ディスクが一杯など）
            }
            $errBody .= $chunk; // エラーの本文は 4KB だけ取っておく（理由を読むため）
            return strlen($errBody) > 4096 ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($status !== 200) {
        return $status > 0 ? [$status, drive_upstream_reason($errBody)] : ['curl-' . $errno, drive_upstream_reason($curlError)];
    }
    if ($errno) {
        return ['curl-' . $errno, drive_upstream_reason($curlError)];
    }
    clearstatcache(true, $dest);
    $got = (int)filesize($dest);
    $want = $len >= 0 ? $len : $dbSize; // 上流の長さがあればそれ、無ければ DB の大きさ
    return $want > 0 && $got !== $want ? [200, "short got $got want $want"] : null;
}
