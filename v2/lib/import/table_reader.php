<?php
declare(strict_types=1);

/**
 * アップロードされた CSV / PDF を「行×列の2次元配列」に変換する。
 * どちらの形式でも、後段のパーサーは同じ2次元配列だけを相手にすればよい。
 */
function read_table_file(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return match ($ext) {
        'csv', 'txt' => read_csv_table($path),
        'pdf'        => read_pdf_table($path),
        default      => throw new RuntimeException("{$originalName}: 対応していない形式です（CSV または PDF）"),
    };
}

function read_csv_table(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('ファイルを読み込めませんでした');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw; // BOM
    // Excel の「CSV」保存は Shift_JIS になることがある
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
    }

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw);
    rewind($fp);
    $rows = [];
    while (($row = fgetcsv($fp, null, ',', '"', '')) !== false) {
        $row = array_map(static fn($v) => trim(str_replace(["\r", "\n"], ' ', (string)$v)), $row);
        if (implode('', $row) !== '') {
            $rows[] = $row;
        }
    }
    fclose($fp);
    return $rows;
}

/**
 * PDF を poppler の pdftotext -bbox で単語ごとの座標付きテキストにし、
 * 「バンド名」を含む見出し行の列位置を基準にセルへ振り分けて表を復元する。
 * Excel から書き出した（文字を選択できる）PDF 用。スキャン画像の PDF は読めない。
 */
function read_pdf_table(string $path): array
{
    $bin = (string)(config('pdftotext') ?? 'pdftotext');
    $cmd = escapeshellarg($bin) . ' -bbox -enc UTF-8 ' . escapeshellarg($path) . ' - 2>&1';
    $out = [];
    $status = 0;
    exec($cmd, $out, $status);
    if ($status !== 0) {
        throw new RuntimeException('PDFの読み込みに失敗しました。サーバーに pdftotext（poppler）が入っているか、config.php の pdftotext のパスを確認してください。');
    }
    $html = implode("\n", $out);

    preg_match_all('/<page\b[^>]*>(.*?)<\/page>/s', $html, $pages);
    $rows = [];
    $columns = null;
    foreach ($pages[1] as $pageHtml) {
        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/s', $pageHtml, $m, PREG_SET_ORDER);
        $words = array_map(static fn($w) => [
            'x0' => (float)$w[1], 'y0' => (float)$w[2], 'x1' => (float)$w[3], 'y1' => (float)$w[4],
            'text' => html_entity_decode($w[5], ENT_QUOTES | ENT_XML1, 'UTF-8'),
        ], $m);
        array_push($rows, ...pdf_words_to_rows($words, $columns));
    }
    if ($rows === []) {
        throw new RuntimeException('PDFから文字を取り出せませんでした。スキャン画像のPDFは非対応です。CSVに変換してください。');
    }
    return $rows;
}

/** 同じ高さの単語を1行に、近い単語を1フレーズにまとめる */
function pdf_group_lines(array $words): array
{
    usort($words, static fn($a, $b) => ($a['y0'] + $a['y1']) <=> ($b['y0'] + $b['y1']));
    $lines = [];
    foreach ($words as $w) {
        $yc = ($w['y0'] + $w['y1']) / 2;
        $h = $w['y1'] - $w['y0'];
        $last = array_key_last($lines);
        if ($last !== null && abs($lines[$last]['yc'] - $yc) < $h * 0.5) {
            $lines[$last]['words'][] = $w;
        } else {
            $lines[] = ['yc' => $yc, 'words' => [$w]];
        }
    }
    foreach ($lines as &$line) {
        usort($line['words'], static fn($a, $b) => $a['x0'] <=> $b['x0']);
        $phrases = [];
        foreach ($line['words'] as $w) {
            $p = array_key_last($phrases);
            $gap = $p === null ? INF : $w['x0'] - $phrases[$p]['x1'];
            if ($gap < ($w['y1'] - $w['y0']) * 0.8) {
                $phrases[$p]['text'] .= ' ' . $w['text'];
                $phrases[$p]['x1'] = $w['x1'];
            } else {
                $phrases[] = ['x0' => $w['x0'], 'x1' => $w['x1'], 'yc' => $line['yc'], 'h' => $w['y1'] - $w['y0'], 'text' => $w['text']];
            }
        }
        $line['phrases'] = $phrases;
    }
    return $lines;
}

/**
 * @param array|null $columns 前ページの列定義（見出しが無いページで使い回す）
 */
function pdf_words_to_rows(array $words, ?array &$columns): array
{
    if ($words === []) {
        return [];
    }
    $lines = pdf_group_lines($words);
    $rows = [];
    $bodyLines = $lines;

    foreach ($lines as $i => $line) {
        $text = implode('', array_column($line['phrases'], 'text'));
        if (str_contains($text, 'バンド名')) {
            // 見出しセル内で折り返された文字（「持ち時/間」など）も拾うため、上下に近い行も見出しに含める
            $h = $line['words'][0]['y1'] - $line['words'][0]['y0'];
            $headerLines = array_filter($lines, static fn($l) => abs($l['yc'] - $line['yc']) <= $h * 1.2);
            $first = min(array_keys($headerLines));
            $last = max(array_keys($headerLines));
            // 見出しより上は「日付・ライブ名・会場」などの前置き行
            if ($first > 0) {
                $rows[] = array_column(pdf_merge_stacked(array_merge(...array_column(array_slice($lines, 0, $first), 'phrases'))), 'text');
            }
            $columns = array_map(static fn($p) => [
                'center' => ($p['x0'] + $p['x1']) / 2, 'x0' => $p['x0'], 'title' => $p['text'],
            ], pdf_merge_stacked(array_merge(...array_column($headerLines, 'phrases'))));
            $bodyLines = array_slice($lines, $last + 1);
            break;
        }
    }
    if ($columns === null) {
        return array_map(static fn($l) => array_column($l['phrases'], 'text'), $lines);
    }

    $phrases = array_merge(...array_column($bodyLines, 'phrases'));
    $cols = $columns;
    // 見出しの無い左端の通し番号列（1, 2, 3...）
    $numbers = array_filter($phrases, static fn($p) => $p['x1'] < $cols[0]['x0'] && preg_match('/^\d+$/', $p['text']));
    if ($numbers !== []) {
        $center = array_sum(array_map(static fn($p) => ($p['x0'] + $p['x1']) / 2, $numbers)) / count($numbers);
        array_unshift($cols, ['center' => $center, 'x0' => $center, 'title' => '']);
    }

    // 各フレーズを一番近い列へ
    foreach ($phrases as &$p) {
        $pc = ($p['x0'] + $p['x1']) / 2;
        $best = 0;
        foreach ($cols as $ci => $c) {
            if (abs($c['center'] - $pc) < abs($cols[$best]['center'] - $pc)) {
                $best = $ci;
            }
        }
        $p['col'] = $best;
    }
    unset($p);

    // 先頭列に値がある高さを「行の基準」とし、2段になったセルも最寄りの行へ寄せる
    // セル内で折り返した先頭列（「13:30〜」「14:00」の2段など）は1行にまとめる
    $firstCol = array_values(array_filter($phrases, static fn($p) => $p['col'] === 0));
    usort($firstCol, static fn($a, $b) => $a['yc'] <=> $b['yc']);
    $groups = [];
    foreach ($firstCol as $p) {
        $g = array_key_last($groups);
        if ($g !== null && $p['yc'] - end($groups[$g]) <= $p['h'] * 1.2) {
            $groups[$g][] = $p['yc'];
        } else {
            $groups[] = [$p['yc']];
        }
    }
    $anchors = array_map(static fn($g) => array_sum($g) / count($g), $groups);
    if ($anchors === []) {
        return $rows;
    }
    $pitch = count($anchors) > 1 ? ($anchors[count($anchors) - 1] - $anchors[0]) / (count($anchors) - 1) : 20.0;

    $table = array_fill(0, count($anchors), array_fill(0, count($cols), []));
    usort($phrases, static fn($a, $b) => [$a['yc'], $a['x0']] <=> [$b['yc'], $b['x0']]);
    foreach ($phrases as $p) {
        $best = 0;
        foreach ($anchors as $ai => $y) {
            if (abs($y - $p['yc']) < abs($anchors[$best] - $p['yc'])) {
                $best = $ai;
            }
        }
        if (abs($anchors[$best] - $p['yc']) <= $pitch) {
            $table[$best][$p['col']][] = $p;
        }
    }

    $rows[] = array_column($cols, 'title');
    foreach ($table as $cells) {
        $rows[] = array_map(static fn($texts) => pdf_join_cell($texts), $cells);
    }
    return $rows;
}

/** 横位置が重なるフレーズ（縦に折り返された1つのセル）を1つにまとめる */
function pdf_merge_stacked(array $phrases): array
{
    usort($phrases, static fn($a, $b) => $a['x0'] <=> $b['x0']);
    $merged = [];
    foreach ($phrases as $p) {
        $m = array_key_last($merged);
        if ($m !== null && $p['x0'] < $merged[$m]['x1'] && $p['x1'] > $merged[$m]['x0']) {
            $merged[$m]['parts'][] = $p;
            $merged[$m]['x0'] = min($merged[$m]['x0'], $p['x0']);
            $merged[$m]['x1'] = max($merged[$m]['x1'], $p['x1']);
        } else {
            $merged[] = ['x0' => $p['x0'], 'x1' => $p['x1'], 'parts' => [$p]];
        }
    }
    foreach ($merged as &$m) {
        usort($m['parts'], static fn($a, $b) => $a['yc'] <=> $b['yc']);
        $m['text'] = implode('', array_column($m['parts'], 'text'));
    }
    return $merged;
}

/**
 * 1セル内の複数フレーズを結合する。同じ行のフレーズは空白区切り。
 * 「GENERATION（谷」+「ヶ崎）」のような日本語の途中での折り返しは詰めて、それ以外は空白で区切る
 */
function pdf_join_cell(array $phrases): string
{
    $out = '';
    $prevY = null;
    foreach ($phrases as $p) {
        $t = $p['text'];
        $wrapped = $prevY !== null && abs($p['yc'] - $prevY) > 0.1;
        $prevY = $p['yc'];
        if ($out === '') {
            $out = $t;
        } elseif ($wrapped && preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}ー（(〜~・]$/u', $out) && preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー）)]/u', $t)) {
            $out .= $t;
        } else {
            $out .= ' ' . $t;
        }
    }
    return $out;
}
