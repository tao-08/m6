<?php
/**
 * check_ffmpeg.php — 本番で ffmpeg が使えるかの確認（管理者のみ。確認したら削除する）
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$canExec = function_exists('exec') && !in_array('exec', $disabled, true);
echo 'exec: ' . ($canExec ? "使える\n" : "無効\n");
if (!$canExec) {
    exit;
}
exec('ffmpeg -version 2>&1', $out, $code);
echo 'ffmpeg: ' . ($code === 0 ? ($out[0] ?? '') : "見つからない（終了コード {$code}）") . "\n";
if ($code === 0) { // 前処理で使うフィルターが入っているか
    exec('ffmpeg -hide_banner -filters 2>&1', $f);
    foreach (['highpass', 'equalizer', 'acompressor', 'loudnorm'] as $name) {
        echo "  {$name}: " . (preg_grep('/\s' . $name . '\s/', $f) ? "あり\n" : "なし\n");
    }
}
echo 'memory_limit: ' . ini_get('memory_limit') . ' / max_execution_time: ' . ini_get('max_execution_time') . "\n";
