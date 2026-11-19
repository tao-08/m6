<?php
/**
 * drive_audio.php?band=バンドID&f=ファイルID — バンドに紐付けたドライブの音源を、こちらのサーバー経由で流す
 *   ページ内の自前プレーヤー（lib/audio_player.php）の <audio src>。ドライブの iframe より軽く、シーク（Range）に答える。
 *   ログインしている人だけ。band_drive_file に紐付いている音源（audio/*）だけ流す（任意のドライブの ID は中継しない）
 *   API キーは drive_api_key()。「リンクを知っている全員」に共有されたファイルだけ読める
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/drive.php';
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
if (preg_match('/^bytes=\d*-\d*$/', $_SERVER['HTTP_RANGE'] ?? '')) {
    $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$status = 0;
$up = []; // 上流の返事のヘッダー（小文字の名前 => 値）。リダイレクトされたら最後の返事の分だけ残す
$sent = false;
// 状態とヘッダーを出す（本文の最初のバイトの前に1回だけ）。通すのは 200 / 206 / 416 だけ
$send = static function () use (&$status, &$up, &$sent, $mime): void {
    $sent = true;
    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Accept-Ranges: bytes');
    foreach (['content-length' => 'Content-Length', 'content-range' => 'Content-Range'] as $k => $name) {
        if (isset($up[$k]) && !($status === 416 && $k === 'content-length')) { // 416 は本文を捨てるので長さは 0
            header($name . ': ' . $up[$k]);
        }
    }
    if ($status === 416) {
        header('Content-Length: 0');
    }
};

$ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true');
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
    CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$status, &$sent, $send): int {
        if ($status !== 200 && $status !== 206) {
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
    if ($status === 416) {
        $send();
    } elseif ($status === 200 || $status === 206) {
        $send(); // 中身が空のファイル
    } else {
        http_response_code(502);
    }
}
