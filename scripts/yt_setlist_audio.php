<?php
/**
 * scripts/yt_setlist_audio.php — 「動画から曲を検出」に渡す音声を、YouTube からまとめて落とす（手元の PC で動かす。DB は読むだけ）
 *   対象: YouTube の URL があって、セットリスト（song）が無いバンド
 *   落とした「バンドID_バンド名.mp3」を、バンド編集の「動画から曲を検出」で選ぶ（MP3 はブラウザでの取り出しを飛ばす）
 *   音声はサイトと同じ加工（モノラル・低音を削る・音量をならす）。すでにあるファイルは落とし直さない
 *   要るもの: yt-dlp と ffmpeg（winget install yt-dlp.yt-dlp / Gyan.FFmpeg）
 *
 *    php scripts/yt_setlist_audio.php <保存先フォルダ> [件数] [local|production]    （件数: 新しいバンドから。0・省略ですべて。DB は省略時 local）
 */
declare(strict_types=1);

if (($argv[1] ?? '') === '--selftest') {
    assert(audio_file_name(12, 'a/b:c?') === '12_a_b_c_.mp3');
    assert(audio_file_name(3, ' .. ') === '3_band.mp3');
    echo "ok\n";
    exit(0);
}
$out = $argv[1] ?? '';
$limit = max(0, (int)($argv[2] ?? 0));
$env = $argv[3] ?? 'local';
if ($out === '' || !is_dir($out)) {
    fwrite(STDERR, "使い方: php scripts/yt_setlist_audio.php <保存先フォルダ（作成済み）> [件数] [local|production]\n");
    exit(1);
}
$raw = require __DIR__ . '/../v3/config.php';
$cfg = array_replace_recursive($raw['common'] ?? [], $raw[$env] ?? $raw);

// youtube.php が呼ぶ config() の代わり（bootstrap.php はセッションを始めるので CLI では読まない）
function config(?string $key = null): mixed
{
    global $cfg;
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}
// bootstrap.php の関数。DB の URL は登録時に検証済みなので、ここでは通す（動画 ID は youtube_video_id が検証する）
function youtube_url_valid(string $url): bool { return $url !== ''; }
require_once __DIR__ . '/../v3/lib/youtube.php';

/** ファイル名に使えない文字を _ にする */
function audio_file_name(int $bandId, string $name): string
{
    $safe = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', $name) ?? '', ' .');
    return $bandId . '_' . mb_substr($safe !== '' ? $safe : 'band', 0, 60) . '.mp3';
}

try {
    $db = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB に接続できません（{$env}）。\n");
    exit(1);
}

$bands = $db->query(
    "SELECT b.band_id, b.name, b.youtube_url FROM band b
      WHERE b.youtube_url IS NOT NULL AND b.youtube_url <> ''
        AND NOT EXISTS (SELECT 1 FROM song s WHERE s.band_id = b.band_id)
      ORDER BY b.band_id DESC"
)->fetchAll();

$ok = $skip = $fail = 0;
foreach ($bands as $b) {
    $id = youtube_video_id((string)$b['youtube_url']);
    $file = rtrim($out, '/\\') . DIRECTORY_SEPARATOR . audio_file_name((int)$b['band_id'], (string)$b['name']);
    if ($limit > 0 && $ok + $fail >= $limit) {
        break;
    }
    if ($id === null || is_file($file)) {
        $skip++;
        continue;
    }
    echo "#{$b['band_id']} {$b['name']} … ";
    // 加工は assets/setlist_detect.js と同じ。出力名の拡張子は yt-dlp が付けるので、.mp3 を外して渡す
    //   シェルを通さず引数の配列で渡す（Windows の escapeshellarg は % を消すため）
    $p = proc_open(['yt-dlp', '-q', '--no-warnings', '--no-playlist', '-x', '-f', 'bestaudio', '--audio-format', 'mp3', '--audio-quality', '48K',
        '--postprocessor-args', 'ffmpeg:-ac 1 -ar 22050 -af highpass=f=90,loudnorm',
        '-o', substr($file, 0, -4) . '.%(ext)s', youtube_watch_url($id)], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    $log = $p ? explode("\n", trim((string)stream_get_contents($pipes[1]))) : ['yt-dlp を起動できません'];
    $code = $p ? proc_close($p) : 1;
    if ($code === 0 && is_file($file)) {
        $ok++;
        echo "ok\n";
    } else {
        $fail++;
        echo "失敗: " . mb_substr(implode(' ', array_slice($log, -2)), 0, 200) . "\n";
    }
}
printf("\n落とした %d / 飛ばした %d（既にある・URL が動画でない） / 失敗 %d\n", $ok, $skip, $fail);
