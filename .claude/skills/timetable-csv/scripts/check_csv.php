<?php
/**
 * check_csv.php — 作った CSV を「本物の取り込み処理（v3/lib/import）」に通して結果を表示する
 *
 *   php check_csv.php timetable.csv [roster.csv ...]
 *
 * import.php と同じ read_table_file → detect_table_kind → parse_* を通すので、
 * ここで読めればサイトの取り込み画面でも同じように読める。
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../v3/lib/import/parsers.php';

if ($argc < 2) {
    fwrite(STDERR, "使い方: php check_csv.php <CSVファイル> [...]\n");
    exit(2);
}

$failed = false;
foreach (array_slice($argv, 1) as $path) {
    echo "===== {$path} =====\n";
    try {
        $rows = read_table_file($path, basename($path));
        $kind = detect_table_kind($rows);
        echo "種類: {$kind}\n";

        if ($kind === 'timetable') {
            $tt = parse_timetable($rows);
            printf("タイトル: %s / ライブ名: %s / 日程: %s / 日付: %s / 会場: %s\n",
                $tt['title'], $tt['live_name'], $tt['label'],
                $tt['month'] ? "{$tt['month']}月{$tt['day']}日" : '（読めない！）',
                $tt['venue'] ?: '（なし）');
            if (!$tt['month']) {
                echo "  ⚠ 日付が読めていない。前置き行に「1月5日」の形で書くこと\n";
                $failed = true;
            }
            foreach ($tt['slots'] as $s) {
                printf("  %s %s-%s  %-24s 曲数:%s 人数:%s %s\n",
                    $s['is_band'] ? '♪' : '－',
                    $s['start_time'] ?? '??:??', $s['end_time'] ?? '??:??',
                    $s['band_name'],
                    $s['song_count'] ?? '-', $s['member_count'] ?? '-',
                    $s['key_note'] !== '' ? "key:{$s['key_note']}" : '');
                if ($s['start_time'] === null) {
                    echo "    ⚠ 開始時刻が読めていない\n";
                    $failed = true;
                }
            }
        } else {
            $roster = parse_roster($rows);
            echo "パート列: " . implode(' / ', array_map(
                static fn($c) => "{$c['title']}→{$c['part']}", $roster['columns'])) . "\n";
            foreach ($roster['bands'] as $b) {
                $members = array_map(static fn($m) => "{$m['name']}({$m['part']})", $b['members']);
                printf("  %-24s %s  曲数:%s\n", $b['band_name'], implode(' ', $members), $b['song_count'] ?? '-');
            }
        }
    } catch (Throwable $e) {
        echo "✖ 読めない: {$e->getMessage()}\n";
        $failed = true;
    }
    echo "\n";
}
exit($failed ? 1 : 0);
