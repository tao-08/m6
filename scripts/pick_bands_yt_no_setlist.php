<?php
/**
 * scripts/pick_bands_yt_no_setlist.php — 1回だけ動かす調査用。DB は読むだけ。
 *   YouTube の概要欄に曲名があるのに、セットリスト（song）が無いバンドを一覧する。
 *   対象は band.youtube_url のみ。
 *
 *    php scripts/pick_bands_yt_no_setlist.php [local|production]    （省略時 local）
 *
 *  production は DB が本番サーバー内(localhost)にあるので、サーバー上で動かすときだけ使える。
 *  API キー・パスワードは画面に出さない。
 */
declare(strict_types=1);

$env = $argv[1] ?? 'local';
$raw = require __DIR__ . '/../v3/config.php';
$cfg = array_replace_recursive($raw['common'] ?? [], $raw[$env] ?? $raw);

// youtube.php が呼ぶ config() の代わり（bootstrap.php はセッションを始めるので CLI では読まない）
function config(?string $key = null): mixed
{
    global $cfg;
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}
// bootstrap.php の関数。DB の URL は登録時に検証済みなので、ここでは通す
function youtube_url_valid(string $url): bool { return $url !== ''; }
require_once __DIR__ . '/../v3/lib/youtube.php';

if ((string)(config('youtube')['api_key'] ?? '') === '') {
    fwrite(STDERR, "YouTube の API キーが config.php にありません。\n");
    exit(1);
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
    "SELECT b.band_id, b.name, b.youtube_url, l.name AS live_name, l.fiscal_year, d.label, d.held_on
       FROM band b
       JOIN live_day d ON d.live_day_id = b.live_day_id
       JOIN live l ON l.live_id = d.live_id
      WHERE b.youtube_url IS NOT NULL AND b.youtube_url <> ''
        AND NOT EXISTS (SELECT 1 FROM song s WHERE s.band_id = b.band_id)
      ORDER BY b.band_id"
)->fetchAll();

$hit = 0;
$noVideo = 0;
foreach ($bands as $b) {
    $id = youtube_video_id((string)$b['youtube_url']);
    $desc = $id === null ? null : youtube_description($id);
    if ($desc === null) {
        $noVideo++;
        continue;
    }
    $titles = youtube_parse_songs($desc);
    if (!$titles) {
        continue;
    }
    $hit++;
    printf("#%d %s\n  %s %s年度 %s %s\n  %s\n  %d曲: %s\n", $b['band_id'], $b['name'], $b['live_name'], $b['fiscal_year'], $b['label'], $b['held_on'] ?? '日付不明', $b['youtube_url'], count($titles), implode(' / ', array_slice($titles, 0, 5)));
}
printf("\n対象 %d バンド / 曲名あり %d / 概要欄を取れず %d\n", count($bands), $hit, $noVideo);
