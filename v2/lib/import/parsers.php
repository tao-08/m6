<?php
/**
 * =====================================================================
 *  parsers.php — 「2次元配列（表）」から意味を取り出す
 * =====================================================================
 *  table_reader.php が CSV / PDF を
 *      [ ['時間', '', '持ち時間', 'バンド名', ...],   ← 1行目
 *        ['11:30', '11:50', '20', 'King Gnu', ...], ← 2行目 ... ]
 *  という配列にしてくれるので、ここでは「どの列が何か」を見出しから探して読むだけ。
 *
 *  列の位置を決め打ち（$row[3] がバンド名、など）にしないのがポイント。
 *  タイムテーブルを作る人によって列の順番が変わっても動くようにするため。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/text.php';
require_once __DIR__ . '/table_reader.php';

/**
 * 「集合」以外で、バンドではない枠の名前。
 * プレビューでは「取り込む」のチェックが外れた状態で表示される（間違っていたら手でチェックできる）。
 */
const NON_BAND_SLOT = '/^(休憩|昼休憩|ご飯|昼食|夕食|転換|メインステージ|撤収|片付け|片づけ|リハ.*|準備|解散|打ち上げ)$/u';

/** 見出し行（「バンド名」というセルがある行）の番号を返す。無ければ null */
function find_header_index(array $rows): ?int
{
    foreach ($rows as $i => $row) {
        foreach ($row as $cell) {
            if (str_contains($cell, 'バンド名')) {
                return $i;
            }
        }
    }
    return null;
}

/**
 * 見出しの中から、正規表現 $pattern に合う列の番号を返す。
 * $from を指定すると、その列より右だけを探す（同じ名前の見出しが2つあるとき用）。
 */
function find_column(array $header, string $pattern, int $from = 0): ?int
{
    foreach ($header as $i => $title) {
        if ($i >= $from && preg_match($pattern, tt_width($title))) {
            return $i;
        }
    }
    return null;
}

/**
 * アップロードされた表が
 *   'timetable' = タイムテーブル（時間・バンド名・曲数…）
 *   'roster'    = 名簿／メンバー表（バンド名・Vo・Gt・Ba・Dr…）
 * のどちらかを、見出しの中身から判定する。
 * → ユーザーは「どっちのファイルか」を選ばずにまとめてアップロードできる。
 */
function detect_table_kind(array $rows): string
{
    $hi = find_header_index($rows);
    if ($hi === null) {
        throw new RuntimeException('「バンド名」という見出しが見つかりませんでした');
    }
    foreach ($rows[$hi] as $title) {
        if (in_array(normalize_part($title), ['Vo', 'Gt', 'Ba', 'Dr'], true)) {
            return 'roster';
        }
    }
    if (find_column($rows[$hi], '/時間/u') !== null) {
        return 'timetable';
    }
    throw new RuntimeException('タイムテーブルか名簿か判別できませんでした（「時間」列も「Vo/Gt/Ba/Dr」列も無い）');
}

/**
 * PDF では鍵盤メモが隣の「人数」列にはみ出すことがある（「私物（シンセ）, 7」のように）。
 * 数字の列のセルから「数字以外」を取り出して返す → key のメモにくっつけ直す。
 */
function overflow_text(string $cell): string
{
    $withoutNumbers = preg_replace('/\d+(\(\d+\))?/', '', tt_width($cell)) ?? '';
    return trim(preg_replace('/^[\s,、]+|[\s,、]+$/u', '', $withoutNumbers) ?? '');
}

/**
 * タイムテーブル → ライブ1日分のデータ
 *
 * 表の上の「前置き行」から読むもの:
 *   「1月5日」          → month / day
 *   「1月ライブ1日目」  → live_name = '1月ライブ', label = '1日目'
 *   「会場」の右のセル  → venue
 *
 * 戻り値の slots は「時間の枠」1つ1つ。休憩などバンドでない枠も is_band=false で入っている。
 */
function parse_timetable(array $rows): array
{
    $hi = find_header_index($rows);
    $header = $rows[$hi];
    $result = [
        'title' => '',      // 表に書いてあったタイトルそのまま（プレビューの見出し用）
        'live_name' => '',
        'label' => '',      // live_detail.label に入れる「1日目」など
        'month' => null,
        'day' => null,
        'venue' => '',
        'meeting_time' => null, // 「集合」の時刻
        'slots' => [],
    ];

    // ---- 1. 前置き行（見出しより上）からライブ名・日付・会場 ----
    // 前置きが何行あっても良いように、全セルを1列に並べてから空セルを捨てる
    $preambleRows = array_slice($rows, 0, $hi);
    $preamble = array_values(array_filter(array_map('tt_width', array_merge([], ...$preambleRows)), 'strlen'));

    $titleCandidates = [];
    for ($i = 0; $i < count($preamble); $i++) {
        $cell = $preamble[$i];
        if ($cell === '会場' || $cell === '会場:') {
            $result['venue'] = $preamble[$i + 1] ?? ''; // 「会場」の次のセルが会場名
            $i++;                                       // 会場名のセルは読んだので飛ばす
        } elseif (preg_match('/^会場[:：]?\s*(.+)$/u', $cell, $m)) {
            $result['venue'] = $m[1];                   // 「会場：〇〇」と1セルに書かれている場合
        } elseif (preg_match('/(\d{1,2})月(\d{1,2})日/u', $cell, $m)) {
            [$result['month'], $result['day']] = [(int)$m[1], (int)$m[2]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $cell, $m)) {
            [$result['month'], $result['day']] = [(int)$m[1], (int)$m[2]];
        } else {
            $titleCandidates[] = $cell;
        }
    }
    // 「ライブ」「日目」を含むセルを優先してタイトルにする
    $title = '';
    foreach ($titleCandidates as $c) {
        if (preg_match('/ライブ|日目|live/iu', $c)) {
            $title = $c;
            break;
        }
    }
    $title = $title ?: ($titleCandidates[0] ?? '');
    $result['title'] = $title;

    // 「ライブハウス2日目」→ 名前「ライブハウス」+ ラベル「2日目」
    if (preg_match('/^(.*?)\s*(\d+日目)\s*$/u', $title, $m)) {
        $result['live_name'] = trim($m[1]);
        $result['label'] = $m[2];
    } else {
        // 日目が無い（「教室ライブ」など）→ 1日だけのライブとみなす
        $result['live_name'] = $title;
        $result['label'] = '1日目';
    }

    // ---- 2. 見出しから列の位置を探す ----
    $cTime  = find_column($header, '/^時間/u');
    $cBand  = find_column($header, '/バンド名/u');
    $cSongs = find_column($header, '/曲数/u');
    $cCount = find_column($header, '/人数/u');
    $cKey   = find_column($header, '/^(key|鍵盤|キーボード)/iu');
    $timeCols = $cTime === null ? [] : [$cTime];
    // 「時間,,持ち時間」のように開始と終了の2列に分かれている（2列目の見出しが空）場合
    if ($cTime !== null && isset($header[$cTime + 1]) && trim($header[$cTime + 1]) === '' && $cTime + 1 !== $cBand) {
        $timeCols[] = $cTime + 1;
    }

    // ---- 3. 見出しより下の行を1行ずつ読む ----
    foreach (array_slice($rows, $hi + 1) as $row) {
        $name = tt_width($row[$cBand] ?? '');
        if ($name === '' || $name === 'バンド名') { // 空行・2ページ目の見出しは無視
            continue;
        }
        $times = extract_times(implode(' ', array_map(static fn($c) => $row[$c] ?? '', $timeCols)));

        if (preg_match('/^集合/u', $name)) {
            $result['meeting_time'] = $times[0] ?? null;
            continue;
        }

        $songs = $cSongs === null ? null : extract_int($row[$cSongs] ?? '');
        $count = $cCount === null ? null : extract_int($row[$cCount] ?? '');
        $keyNote = trim(($cCount === null ? '' : overflow_text($row[$cCount] ?? '') . ' ')
            . ($cKey === null ? '' : tt_width($row[$cKey] ?? '')));

        $result['slots'][] = [
            // 曲数も人数も空 or 「休憩」など → バンドではない
            'is_band'      => !preg_match(NON_BAND_SLOT, $name) && !($songs === null && $count === null),
            'band_name'    => $name,
            'start_time'   => $times[0] ?? null,
            'end_time'     => $times[1] ?? null,
            'song_count'   => $songs,
            'member_count' => $count,
            'key_note'     => $keyNote,
        ];
    }
    return $result;
}

/**
 * 名簿（メンバー表）→ バンドごとのメンバー
 *
 * 見出しが「バンド名 / Vo(Gt.) / Gt.1 / Gt.2 / Ba. / Dr. / Key. / 曲数 / ...」なら、
 * 「バンド名」と「曲数」の間にある列をパートの列とみなす。
 *
 * 戻り値:
 *   'columns' => [列番号 => ['title' => 'Vo(Gt.)', 'part' => 'Vo'], ...]
 *   'bands'   => [
 *       ['band_name' => 'King Gnu',
 *        'cells'     => [列番号 => '遠藤翔吾', ...],   ← プレビューの入力欄にそのまま出す
 *        'members'   => [['name' => '遠藤翔吾', 'part' => 'Vo'], ...], ← 照合に使う
 *        'song_count' => 3, 'member_count' => null, 'key_note' => ''],
 *       ...]
 */
function parse_roster(array $rows): array
{
    $hi = find_header_index($rows);
    $header = $rows[$hi];
    $cBand  = find_column($header, '/バンド名/u');
    $cSongs = find_column($header, '/曲数/u');
    $end    = $cSongs ?? count($header);

    $columns = [];
    for ($c = $cBand + 1; $c < $end; $c++) {
        $part = normalize_part($header[$c] ?? '');
        if ($part !== null) {
            $columns[$c] = ['title' => tt_width($header[$c]), 'part' => $part];
        }
    }
    $cCount = find_column($header, '/人数/u');
    $cKey   = find_column($header, '/^(key|鍵盤)$/iu', $end); // 曲数より右の「Key」= 鍵盤メモ

    $bands = [];
    foreach (array_slice($rows, $hi + 1) as $row) {
        $name = tt_width($row[$cBand] ?? '');
        if ($name === '' || $name === 'バンド名') {
            continue;
        }
        $cells = [];
        $members = [];
        foreach ($columns as $c => $col) {
            $names = split_member_names($row[$c] ?? '');
            $cells[$c] = implode('、', $names); // 1セルに2人いたら「、」でつないで1つの入力欄に
            foreach ($names as $person) {
                $members[] = ['name' => $person, 'part' => $col['part']];
            }
        }
        $keyNote = $cKey === null ? '' : tt_width($row[$cKey] ?? '');
        $spill = $cCount === null ? '' : overflow_text($row[$cCount] ?? '');
        $bands[] = [
            'band_name'    => $name,
            'cells'        => $cells,
            'members'      => $members,
            'song_count'   => $cSongs === null ? null : extract_int($row[$cSongs] ?? ''),
            'member_count' => $cCount === null ? null : extract_int($row[$cCount] ?? ''),
            'key_note'     => trim($spill . ' ' . $keyNote),
        ];
    }
    return ['columns' => $columns, 'bands' => $bands];
}

/**
 * タイムテーブルの1枠（$slot）に対応する名簿のバンドを探す。
 *
 * 探し方（上から順に試す）
 *   1. バンド名の完全一致（全角半角・大文字小文字・空白は無視）
 *      同名が複数あれば、曲数・人数が一致するもの
 *   2. 「ヨルシカ(安田)」のように括弧付きなら、括弧の外で候補を絞り、
 *      括弧の中の名前（安田）で始まるメンバーがいるものを選ぶ
 *
 * @param array $roster 名簿のバンドを全ファイル分つなげた配列
 * @return int|null $roster の何番目か。決められなければ null（プレビューで手で選ぶ）
 */
function match_roster_band(array $slot, array $roster): ?int
{
    $key = band_key($slot['band_name']);

    // ---- 1. 完全一致 ----
    $exact = [];
    foreach ($roster as $i => $r) {
        if (band_key($r['band_name']) === $key) {
            $exact[] = $i;
        }
    }
    if (count($exact) === 1) {
        return $exact[0];
    }
    if (count($exact) > 1) {
        $same = array_values(array_filter($exact, static fn($i) =>
            $roster[$i]['song_count'] === $slot['song_count']
            && ($roster[$i]['member_count'] === null || $roster[$i]['member_count'] === $slot['member_count'])));
        if (count($same) === 1) {
            return $same[0];
        }
    }

    // ---- 2. 括弧の中の代表者名で絞る ----
    [$base, $suffix] = band_split_suffix($slot['band_name']);
    $baseKey = band_key($base);
    $candidates = [];
    foreach ($roster as $i => $r) {
        [$rBase, $rSuffix] = band_split_suffix($r['band_name']);
        if (band_key($rBase) === $baseKey) {
            $candidates[$i] = $rSuffix;
        }
    }
    if ($candidates === []) {
        return null;
    }
    if ($suffix === '') {
        return count($candidates) === 1 ? array_key_first($candidates) : null;
    }
    $suffixKey = member_key($suffix);
    $hits = [];
    foreach ($candidates as $i => $rSuffix) {
        $hit = $rSuffix !== '' && member_key($rSuffix) === $suffixKey;
        foreach ($roster[$i]['members'] as $m) {
            $hit = $hit || str_starts_with(member_key($m['name']), $suffixKey);
        }
        if ($hit) {
            $hits[] = $i;
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}
