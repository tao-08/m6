<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  text.php — 表記ゆれを吸収するための文字列の関数
 * =====================================================================
 *  タイムテーブルや名簿は人が手で作るので、同じものでも書き方がバラバラになる。
 *    「ヨルシカ（安田）」と「ヨルシカ(安田)」 … 全角/半角の括弧
 *    「KingGnu」と「King Gnu」               … 空白の有無
 *    「岩﨑 太一」と「岩崎太一」              … 異体字と姓名の間の空白
 *  そのまま === で比べると全部「別物」になってしまうので、
 *  比べる前に「比較用キー」（band_key / member_key）に変換してから比べる。
 *  ※ DB に保存するのは元の表記（キーは比べるときだけに使う）
 * =====================================================================
 */

/**
 * 康熙部首（U+2F00〜U+2FD5）→ ふつうの漢字。
 * PDF から文字を取り出すと、フォントによって「長」が見た目そっくりの部首「⾧」(U+2FA7) になることがある。
 * そのままだと「⾧谷川」と「長谷川」が別人扱いになるので直す。
 * （本当は Normalizer の NFKC で直るが、intl 拡張が無い環境でも動くように表で持つ）
 */
const KANGXI_RADICALS = '一丨丶丿乙亅二亠人儿入八冂冖冫几凵刀力勹匕匚匸十卜卩厂厶又口囗土士夂夊夕大女子宀寸小尢尸屮山巛工己巾干幺广廴廾弋弓彐彡彳心戈戶手支攴文斗斤方无日曰月木欠止歹殳毋比毛氏气水火爪父爻爿片牙牛犬玄玉瓜瓦甘生用田疋疒癶白皮皿目矛矢石示禸禾穴立竹米糸缶网羊羽老而耒耳聿肉臣自至臼舌舛舟艮色艸虍虫血行衣襾見角言谷豆豕豸貝赤走足身車辛辰辵邑酉釆里金長門阜隶隹雨靑非面革韋韭音頁風飛食首香馬骨高髟鬥鬯鬲鬼魚鳥鹵鹿麥麻黃黍黑黹黽鼎鼓鼠鼻齊齒龍龜龠';

function fix_kangxi(string $s): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (mb_str_split(KANGXI_RADICALS) as $i => $kanji) {
            $map[mb_chr(0x2F00 + $i)] = $kanji;
        }
    }
    return strtr($s, $map);
}

/** 全角英数記号→半角、半角カナ→全角、全角スペース→半角、康熙部首→漢字 */
function tt_width(string $s): string
{
    $s = fix_kangxi(mb_convert_kana($s, 'asKV', 'UTF-8'));
    $s = strtr($s, ['〜' => '~', '～' => '~', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '−' => '-', '–' => '-', '—' => '-']);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** バンド名の比較用キー */
function band_key(string $name): string
{
    return mb_strtolower(preg_replace('/\s+/u', '', tt_width($name)) ?? '', 'UTF-8');
}

/**
 * 「ヨルシカ(安田)」→ ['ヨルシカ', '安田']
 * 同じアーティストのコピーが複数あるとき、括弧で代表者名を付ける運用に対応
 */
function band_split_suffix(string $name): array
{
    $n = tt_width($name);
    if (preg_match('/^(.*?)\s*\(([^()]*)\)$/u', $n, $m) && $m[1] !== '') {
        return [trim($m[1]), trim($m[2])];
    }
    return [$n, ''];
}

/** 人名の比較用キー（空白除去＋よくある異体字を寄せる） */
function member_key(string $name): string
{
    $n = preg_replace('/\s+/u', '', tt_width($name)) ?? '';
    return strtr($n, ['﨑' => '崎', '髙' => '高', 'ケ' => 'ヶ', 'ヵ' => 'ヶ']);
}

/** 表示・保存用の人名（空白を詰める。ただし「Jung Yeonwoo」のようにローマ字どうしの間の空白は1つ残す） */
function member_display(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', tt_width($name)) ?? '');
    return preg_replace('/(?<![A-Za-z]) | (?![A-Za-z])/u', '', $name) ?? '';
}

/**
 * 1セルに複数人書かれているケースを分割する。
 * 「谷ヶ崎脩伍・藤田真央」「村田侑斗、丸野友多郎」「村田侑斗 日野佑香」に対応しつつ、
 * 「岩﨑 太一」のような姓名間スペースは1人として扱う。
 * 括弧が無く空白がちょうど3つの「林 咲太 石川 陽暉」は、2つ目の空白で分けて2人（PDF で1セルに縦に2人書いたもの）。
 */
function split_member_names(string $cell): array
{
    $cell = tt_width($cell);
    if ($cell === '') {
        return [];
    }
    $chunks = preg_split('/[・、,，\/／&＆\n]+/u', $cell) ?: [];
    $names = [];
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        // 「丸野友多郎 (Sax)」のように括弧の前に空白があっても、別人として分けないよう詰める
        $chunk = preg_replace('/\s+(?=[(（])/u', '', $chunk) ?? $chunk;
        $parts = preg_split('/\s+/u', $chunk) ?: [$chunk];
        if (count($parts) > 1 && preg_match('/^[A-Za-z][A-Za-z\s\'.-]*([(（][^)）]*[)）])?$/u', $chunk)) {
            // 「Jung Yeonwoo」「LEE JUNGHOO」のようにローマ字だけの名前は、空白で区切っても1人（空白も残す）
            $parts = [preg_replace('/\s+/u', ' ', $chunk) ?? $chunk];
        }
        $allLong = count($parts) > 1;
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3) {
                $allLong = false;
            }
        }
        if ($allLong) {
            $people = $parts;                                            // 「村田侑斗 日野佑香」→ 2人
        } elseif (count($parts) === 4 && !preg_match('/[()（）]/u', $chunk)) {
            $people = [$parts[0] . $parts[1], $parts[2] . $parts[3]];    // 「林 咲太 石川 陽暉」→ 姓 名 / 姓 名 の2人
        } else {
            $people = [implode('', $parts)];                             // 「岩﨑 太一」→ 1人
        }
        foreach ($people as $name) {
            $name = member_display($name);
            if ($name !== '' && !preg_match('/^(未定|募集中?|なし|無し|-+|\?+|？+)$/u', $name)) {
                $names[] = $name;
            }
        }
    }
    return $names;
}

/** 列見出し → パート名。パートでなければ null */
function normalize_part(string $header): ?string
{
    $h = mb_strtolower(preg_replace('/[\s.．]/u', '', tt_width($header)) ?? '');
    return match (true) {
        $h === '' => null,
        str_starts_with($h, 'vo') => 'Vo',
        str_starts_with($h, 'gt'), str_starts_with($h, 'gu'), str_starts_with($h, 'ギ') => 'Gt',
        str_starts_with($h, 'ba'), str_starts_with($h, 'ベ') => 'Ba',
        str_starts_with($h, 'dr'), str_starts_with($h, 'ドラ') => 'Dr',
        str_starts_with($h, 'key'), str_starts_with($h, 'kb'), str_starts_with($h, 'syn'), str_starts_with($h, 'キ') => 'Key',
        str_contains($h, 'その他'), str_starts_with($h, 'cho'), str_starts_with($h, 'perc') => 'Other',
        default => null,
    };
}

/** セル内の時刻を全部拾う "13:30〜14:00" → ['13:30', '14:00'] */
function extract_times(string $text): array
{
    preg_match_all('/(\d{1,2})[:：](\d{2})/u', tt_width($text), $m, PREG_SET_ORDER);
    return array_map(static fn($x) => sprintf('%02d:%02d', (int)$x[1], (int)$x[2]), $m);
}

/** "4" "4(5)" → 4 / 空なら null */
function extract_int(string $text): ?int
{
    return preg_match('/\d+/', tt_width($text), $m) ? (int)$m[0] : null;
}

/** マルチバイト対応のレーベンシュタイン距離（名前の誤字候補探し用） */
function mb_levenshtein(string $a, string $b): int
{
    $a = mb_str_split($a);
    $b = mb_str_split($b);
    $prev = range(0, count($b));
    foreach ($a as $i => $ca) {
        $cur = [$i + 1];
        foreach ($b as $j => $cb) {
            $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
        }
        $prev = $cur;
    }
    return $prev[count($b)];
}

/** 同一人物っぽいか（誤字1文字・苗字だけ表記など） */
function names_look_similar(string $keyA, string $keyB): bool
{
    if ($keyA === $keyB) {
        return false;
    }
    $la = mb_strlen($keyA);
    $lb = mb_strlen($keyB);
    if (min($la, $lb) >= 2 && (str_starts_with($keyA, $keyB) || str_starts_with($keyB, $keyA))) {
        return true;
    }
    return min($la, $lb) >= 3 && mb_levenshtein($keyA, $keyB) <= 1;
}
