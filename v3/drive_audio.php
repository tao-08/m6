<?php
/**
 * drive_audio.php?band=バンドID&f=ファイルID — バンドに紐付けたドライブの音源を、こちらのサーバー経由で流す
 *   ページ内の自前プレーヤー（lib/audio_player.php）の <audio src>。ドライブの iframe より軽く、シーク（Range）に答える。
 *   &from=バイト位置 を付けると、Range なしで（200 で）その位置から先を流す。WAV は作り直したヘッダー付き（lib/wav.php）
 *   ログインしている人だけ。band_drive_file に紐付いている音源（audio/*）だけ流す（任意のドライブの ID は中継しない）
 *   API キーは drive_api_key()。「リンクを知っている全員」に共有されたファイルだけ読める
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/wav.php';
require_login();

$bandId = (int)($_GET['band'] ?? 0);
$fileId = (string)($_GET['f'] ?? '');
$mime = '';
if ($bandId > 0 && drive_id_valid($fileId)) {
    $st = db()->prepare('SELECT mime_type FROM band_drive_file WHERE band_id = ? AND file_id = ?');
    $st->execute([$bandId, $fileId]);
    $mime = (string)$st->fetchColumn();
}
if (!str_starts_with($mime, 'audio/')) {
    http_response_code(404);
    exit;
}
// 長く流すので、先にセッションを手放す（ほかの画面が待たされない）。時間制限も外す
session_write_close();
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

try {
    $reqHeaders = ['X-Goog-Api-Key: ' . drive_api_key(), 'User-Agent: AbbeyRoad.online']; // キーは URL ではなくヘッダーで渡す
} catch (RuntimeException) { // キーが未設定
    http_response_code(502);
    exit;
}
$apiUrl = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';

// ?from=バイト位置: Range が使えない環境向け。200 で、その位置から先だけを流す（WAV は作り直したヘッダー付き）
$skip = ctype_digit((string)($_GET['from'] ?? '')) ? (int)$_GET['from'] : 0;
$prefix = '';
if ($skip > 0) {
    $range = 'bytes=' . $skip . '-';
    if (in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true)) {
        // 先頭 64KB だけ取って WAV のヘッダーを読む（全体の大きさは Content-Range の「/」の後ろ）
        $head = '';
        $headRange = '';
        $hc = curl_init($apiUrl);
        curl_setopt_array($hc, [
            CURLOPT_HTTPHEADER     => [...$reqHeaders, 'Range: bytes=0-65535'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headRange): int {
                if (stripos($line, 'content-range:') === 0) {
                    $headRange = trim(substr($line, 14));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$head): int {
                $head .= $chunk;
                return strlen($head) > 65536 ? 0 : strlen($chunk); // 上流が Range を無視しても、64KB で打ち切る
            },
        ]);
        curl_exec($hc);
        $total = preg_match('~/(\d+)$~', $headRange, $m) ? (int)$m[1] : 0;
        $wav = $total > 0 ? wav_info(substr($head, 0, 65536)) : null;
        if ($wav && !($resume = wav_resume($wav, $total, $skip))) {
            http_response_code(404); // データの終わりより後ろ
            exit;
        }
        if ($wav) {
            [$prefix, $start, $bytes] = $resume;
            $range = 'bytes=' . $start . '-' . ($start + $bytes - 1);
            $mime = 'audio/wav';
        }
    }
    $reqHeaders[] = 'Range: ' . $range;
} elseif (preg_match('/^bytes=\d*-\d*$/', $_SERVER['HTTP_RANGE'] ?? '')) {
    $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}
$okStatus = $skip > 0 ? [206] : [200, 206]; // from のときに 200 が返ったら、上流が Range を無視している（先頭から来てしまう）ので流さない

$status = 0;
$up = []; // 上流の返事のヘッダー（小文字の名前 => 値）。リダイレクトされたら最後の返事の分だけ残す
$sent = false;
// 状態とヘッダーを出す（本文の最初のバイトの前に1回だけ）。通すのは 200 / 206 / 416 だけ
$send = static function () use (&$status, &$up, &$sent, &$mime, $skip, $prefix): void {
    $sent = true;
    http_response_code($skip > 0 ? 200 : $status);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store'); // 前段のキャッシュが人をまたいで使い回さないように
    header('Accept-Ranges: ' . ($skip > 0 ? 'none' : 'bytes'));
    if ($skip > 0) { // 上流の 206 を 200 に直す: Content-Range は付けず、長さは付けたヘッダーの分も足す
        if (isset($up['content-length'])) {
            header('Content-Length: ' . ((int)$up['content-length'] + strlen($prefix)));
        }
        echo $prefix;
        return;
    }
    foreach (['content-length' => 'Content-Length', 'content-range' => 'Content-Range'] as $k => $name) {
        if (isset($up[$k]) && !($status === 416 && $k === 'content-length')) { // 416 は本文を捨てるので長さは 0
            header($name . ': ' . $up[$k]);
        }
    }
    if ($status === 416) {
        header('Content-Length: 0');
    }
};

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => $reqHeaders,
    CURLOPT_CONNECTTIMEOUT => 3, // 全体の時間制限は付けない（長い音源を最後まで流すため）
    CURLOPT_FOLLOWLOCATION => false, // 付いていくと API キーのヘッダーがリダイレクト先にも送られるため
    CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status, &$up): int {
        if (str_starts_with($line, 'HTTP/')) {
            $status = (int)(explode(' ', $line)[1] ?? 0);
            $up = [];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $up[strtolower(trim($k))] = trim($v);
        }
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$status, &$sent, $send, $okStatus): int {
        if (!in_array($status, $okStatus, true)) {
            return strlen($chunk); // エラーの本文は捨てる（あとで 502 / 416 を返す）
        }
        if (!$sent) {
            $send();
        }
        echo $chunk;
        flush();
        return connection_aborted() ? 0 : strlen($chunk); // 聞き手が離れたら上流も止める
    },
]);
curl_exec($ch);
if (!$sent) {
    if ($status === 416 && $skip > 0) {
        http_response_code(404); // from がファイルの大きさ以上
    } elseif ($status === 416) {
        $send();
    } elseif (in_array($status, $okStatus, true)) {
        $send(); // 中身が空のファイル
    } else {
        http_response_code(502);
    }
}
