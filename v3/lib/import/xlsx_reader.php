<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  xlsx_reader.php — Excel（.xlsx）を「シートごとの2次元配列」にする
 * =====================================================================
 *  .xlsx の正体は「XML ファイルをいくつか ZIP で固めたもの」。
 *    xl/workbook.xml          … シートの一覧（名前と順番）
 *    xl/_rels/workbook.xml.rels … 各シートの XML ファイルがどこにあるか
 *    xl/sharedStrings.xml     … 文字列セルの中身（セルには「何番目の文字列か」だけが入る）
 *    xl/styles.xml            … 表示形式（その数値が「時刻」なのか「ただの数」なのか）
 *    xl/worksheets/sheetN.xml … セルの値
 *
 *  XAMPP の PHP は ZipArchive（zip 拡張）が無効なことが多いので、
 *  ZIP の中身は zlib（gzinflate）だけで自前で取り出す。
 *
 *  ポイント: Excel の「11:30」は内部では 0.479166…（1日を1とした割合）という数値。
 *  表示形式を見て「時刻なら 11:30 に戻す」をしないと、CSV と同じ文字列にならない。
 * =====================================================================
 */

/** 解凍後の上限（ZIP爆弾対策: 小さいファイルが展開すると巨大になる攻撃） */
const XLSX_MAX_ENTRY_BYTES = 50 * 1024 * 1024;

/**
 * @return array<string, array> シート名 => 2次元配列（非表示シートは除く）
 */
function read_xlsx_sheets(string $path): array
{
    $zip = zip_read_entries($path);
    $get = static function (string $name) use ($zip): ?string {
        return $zip[$name] ?? null;
    };

    $workbook = xlsx_load_xml($get('xl/workbook.xml') ?? throw new RuntimeException('Excel ファイルとして読めませんでした（.xlsx か確認してください）'));
    $rels = xlsx_load_xml($get('xl/_rels/workbook.xml.rels') ?? '');
    $shared = xlsx_shared_strings($get('xl/sharedStrings.xml'));
    $dateStyles = xlsx_date_styles($get('xl/styles.xml'));
    $date1904 = in_array((string)($workbook->workbookPr['date1904'] ?? ''), ['1', 'true'], true);

    // rId → ファイルパス
    $targets = [];
    foreach ($rels?->Relationship ?? [] as $r) {
        $target = (string)$r['Target'];
        $targets[(string)$r['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
    }

    $sheets = [];
    foreach ($workbook->sheets->sheet ?? [] as $sheet) {
        if (in_array((string)$sheet['state'], ['hidden', 'veryHidden'], true)) {
            continue;
        }
        // r:id は名前空間付きの属性なので attributes() で取り出す
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $xml = isset($targets[$rid]) ? $get($targets[$rid]) : null;
        if ($xml === null) {
            continue;
        }
        $sheets[(string)$sheet['name']] = xlsx_sheet_rows($xml, $shared, $dateStyles, $date1904);
    }
    if ($sheets === []) {
        throw new RuntimeException('Excel ファイルにシートが見つかりませんでした');
    }
    return $sheets;
}

/**
 * ZIP の「中央ディレクトリ」（ファイルの目次）を読んで、全エントリを [名前 => 中身] で返す。
 * 対応する圧縮方式は 0（無圧縮）と 8（Deflate）のみ。xlsx はこの2つしか使わない。
 */
function zip_read_entries(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('ファイルを読み込めませんでした');
    }
    // 末尾付近にある「End of Central Directory」(署名 PK\x05\x06) を探す
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false || strlen($data) < $eocd + 22) {
        throw new RuntimeException('Excel ファイルとして読めませんでした（.xls の古い形式なら .xlsx で保存し直してください）');
    }
    $e = unpack('vdisk/vcdDisk/vcountDisk/vcount/Vsize/Voffset', $data, $eocd + 4);

    $entries = [];
    $p = $e['offset'];
    for ($i = 0; $i < $e['count']; $i++) {
        if (substr($data, $p, 4) !== "PK\x01\x02") {
            throw new RuntimeException('Excel ファイルが壊れています');
        }
        $h = unpack('vmethod', $data, $p + 10)
           + unpack('Vcsize/Vusize/vnameLen/vextraLen/vcommentLen', $data, $p + 20)
           + unpack('Vlocal', $data, $p + 42);
        $name = substr($data, $p + 46, $h['nameLen']);
        $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];

        if ($h['usize'] > XLSX_MAX_ENTRY_BYTES) {
            throw new RuntimeException('Excel ファイルの中身が大きすぎます');
        }
        // 実データは「ローカルヘッダ」の後ろ。ローカルヘッダの名前・extra の長さは中央ディレクトリと違うことがあるので読み直す
        $lh = unpack('vnameLen/vextraLen', $data, $h['local'] + 26);
        $raw = substr($data, $h['local'] + 30 + $lh['nameLen'] + $lh['extraLen'], $h['csize']);
        $entries[$name] = match ($h['method']) {
            0 => $raw,
            8 => @gzinflate($raw, XLSX_MAX_ENTRY_BYTES) ?: '',
            default => '',
        };
    }
    return $entries;
}

/** 外部ファイルを読みに行かない設定で XML を読む（XXE 対策） */
function xlsx_load_xml(string $xml): ?SimpleXMLElement
{
    if ($xml === '') {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        throw new RuntimeException('Excel ファイルが壊れています');
    }
    return $doc;
}

/** sharedStrings.xml → [0 => '時間', 1 => 'バンド名', ...] */
function xlsx_shared_strings(?string $xml): array
{
    $doc = $xml === null ? null : xlsx_load_xml($xml);
    $out = [];
    foreach ($doc?->si ?? [] as $si) {
        $out[] = xlsx_rich_text($si);
    }
    return $out;
}

/**
 * <si> / <is> の中の文字列。
 * 書式が混ざったセルは <r><t>..</t></r> に分かれている。
 * 日本語の Excel は <rPh>（ふりがな）も持っているので、それは読まない（入れると「山田ヤマダ」になる）
 */
function xlsx_rich_text(SimpleXMLElement $node): string
{
    if (isset($node->t)) {
        return (string)$node->t;
    }
    $s = '';
    foreach ($node->r as $run) {
        $s .= (string)$run->t;
    }
    return $s;
}

/**
 * styles.xml から「日付・時刻の表示形式になっている スタイル番号」を集める。
 * @return array<int, string> スタイル番号 => 'date' | 'time' | 'datetime'
 */
function xlsx_date_styles(?string $xml): array
{
    $doc = $xml === null ? null : xlsx_load_xml($xml);
    if ($doc === null) {
        return [];
    }
    // 組み込みの表示形式番号（Excel の仕様で決まっている）
    $formats = [
        14 => 'yyyy/m/d', 15 => 'd-mmm-yy', 16 => 'd-mmm', 17 => 'mmm-yy',
        18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss', 22 => 'yyyy/m/d h:mm',
        45 => 'mm:ss', 46 => '[h]:mm:ss', 47 => 'mm:ss.0',
        // 日本語版 Excel の組み込み形式（「m月d日」「h時mm分」など）
        27 => 'yyyy年m月', 28 => 'm月d日', 29 => 'm月d日', 30 => 'm/d/yy', 31 => 'yyyy年m月d日',
        32 => 'h時mm分', 33 => 'h時mm分ss秒', 34 => 'yyyy年m月', 35 => 'm月d日', 36 => 'yyyy/m/d',
        50 => 'yyyy/m/d', 51 => 'yyyy年m月', 52 => 'yyyy年m月', 53 => 'm月d日', 54 => 'm月d日',
        55 => 'yyyy年m月', 56 => 'm月d日', 57 => 'yyyy/m/d', 58 => 'm月d日',
    ];
    foreach ($doc->numFmts->numFmt ?? [] as $f) {
        $formats[(int)$f['numFmtId']] = (string)$f['formatCode'];
    }

    $out = [];
    $i = 0;
    foreach ($doc->cellXfs->xf ?? [] as $xf) {
        $code = $formats[(int)$xf['numFmtId']] ?? '';
        // "..." の文字や [Red] などの色指定・\x のエスケープを除いてから、日付/時刻の記号を探す
        $bare = preg_replace('/"[^"]*"|\[(?!h\]|m\]|s\])[^\]]*\]|\\\\./u', '', $code) ?? '';
        $hasDate = preg_match('/[yd年月日]/iu', $bare) === 1;
        $hasTime = preg_match('/[hs時分秒]|\[[hms]\]/iu', $bare) === 1;
        if ($hasDate || $hasTime) {
            $out[$i] = $hasDate && $hasTime ? 'datetime' : ($hasDate ? 'date' : 'time');
        }
        $i++;
    }
    return $out;
}

/** "B3" → 1（A=0, B=1, ..., Z=25, AA=26） */
function xlsx_col_index(string $ref): int
{
    $letters = preg_replace('/\d+/', '', $ref) ?? '';
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** 1シート分の XML → 2次元配列（CSV を読んだときと同じ形） */
function xlsx_sheet_rows(string $xml, array $shared, array $dateStyles, bool $date1904): array
{
    $doc = xlsx_load_xml($xml);
    $rows = [];
    foreach ($doc?->sheetData->row ?? [] as $row) {
        $cells = [];
        $next = 0;
        foreach ($row->c as $c) {
            // r="B3" が省略されることもある（その場合は左から順番）
            $col = isset($c['r']) ? xlsx_col_index((string)$c['r']) : $next;
            $next = $col + 1;
            $cells[$col] = xlsx_cell_text($c, $shared, $dateStyles, $date1904);
        }
        if ($cells === []) {
            continue;
        }
        // 空いている列（B と D だけ値がある、など）を '' で埋めて 0,1,2,... の連番にする
        $dense = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) {
            $dense[$i] = trim(str_replace(["\r\n", "\r", "\n"], ' ', $v));
        }
        if (implode('', $dense) !== '') {
            $rows[] = $dense;
        }
    }
    // CSV と同じく、全行の列数をそろえる
    $width = $rows === [] ? 0 : max(array_map('count', $rows));
    return array_map(static fn($r) => array_pad($r, $width, ''), $rows);
}

function xlsx_cell_text(SimpleXMLElement $c, array $shared, array $dateStyles, bool $date1904): string
{
    $type = (string)($c['t'] ?? 'n');
    $v = (string)($c->v ?? '');
    return match ($type) {
        's'         => $shared[(int)$v] ?? '',
        'inlineStr' => isset($c->is) ? xlsx_rich_text($c->is) : '',
        'str'       => $v,                       // 数式の結果（文字列）
        'b'         => $v === '1' ? 'TRUE' : 'FALSE',
        'e'         => '',                       // #N/A などのエラーは空扱い
        default     => xlsx_number_text($v, $dateStyles[(int)($c['s'] ?? 0)] ?? null, $date1904),
    };
}

/**
 * 数値セルを文字列へ。日付・時刻の表示形式なら、CSV と同じ見た目（「11:30」「10月5日」）に戻す。
 */
function xlsx_number_text(string $v, ?string $kind, bool $date1904): string
{
    if ($v === '' || !is_numeric($v)) {
        return $v;
    }
    $num = (float)$v;
    if ($kind === null) {
        // 20.0 → "20" / 0.1+0.2 のような誤差 → 丸める
        return $num == floor($num) && abs($num) < 1e15 ? (string)(int)$num : (string)round($num, 10);
    }

    // Excel の日付シリアル値: 1900/1/0 を 0 とした日数（1904年基準のブックもある）
    $days = (int)floor($num);
    $secs = (int)round(($num - $days) * 86400);
    if ($secs >= 86400) {
        $days++;
        $secs -= 86400;
    }
    $time = sprintf('%d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60));
    if ($kind === 'time') {
        return $time;
    }
    // 1899-12-30 起点にすると、Excel の「1900年2月29日」バグを吸収できる（3月以降の日付で正しくなる）
    $base = $date1904 ? new DateTimeImmutable('1904-01-01') : new DateTimeImmutable('1899-12-30');
    $date = $base->modify("+{$days} days")->format('n月j日');
    return $kind === 'datetime' ? "{$date} {$time}" : $date;
}
