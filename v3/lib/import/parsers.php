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
        'label' => '',      // live_day.label に入れる「1日目」など
        'month' => null,
        'day' => null,
        'venue' => '',
        'slots' => [],
        // 年のヒント（タイトルやファイル名に「2024年度」「2024」などがあったとき）。finish_import_plan が開催日の年に使う
        'hint_year' => null,     // 見つかった年
        'hint_fiscal' => false,  // true = 「2024年度」のように年度で書いてあった
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
            $result = take_year_hint($result, find_year_hint($cell)); // 「2024年12月6日」なら年も
        } elseif (preg_match('/^(?:(20\d{2})\/)?(\d{1,2})\/(\d{1,2})$/', $cell, $m)) {
            [$result['month'], $result['day']] = [(int)$m[2], (int)$m[3]];
            if ($m[1] !== '') {
                [$result['hint_year'], $result['hint_fiscal']] = [(int)$m[1], false]; // 「2024/12/6」
            }
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

    // 日程名は「1日目」「2日目」「3日目」「教室ライブ」のどれか（DAY_LABELS）。画面ではこれが初期選択になる
    if (preg_match('/^(.*?)\s*(\d+)日目\s*$/u', $title, $m)) {
        // 「ライブハウス2日目」→ 名前「ライブハウス」+ ラベル「2日目」（4日目以降は選択肢に無いので「1日目」）
        $result['live_name'] = trim($m[1]);
        $result['label'] = in_array((int)$m[2], [1, 2, 3], true) ? (int)$m[2] . '日目' : '1日目';
    } elseif (str_contains($title, '教室ライブ')) {
        $result['live_name'] = $title;
        $result['label'] = '教室ライブ';
    } else {
        // 日目が無い → 1日だけのライブとみなして「1日目」。画面で選び直せる
        $result['live_name'] = $title;
        $result['label'] = '1日目';
    }
    // タイトルに「2024年度」などが入っていたら年のヒントにして、ライブ名からは外す（年度は別の欄で持つので）
    $hint = find_year_hint($result['live_name']);
    if ($hint['year'] !== null) {
        $result = take_year_hint($result, $hint);
        $result['live_name'] = $hint['rest'];
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

        if (preg_match('/^集合/u', $name)) { // 集合時刻は記録しないので行ごと読み飛ばす
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
 * 文字列から年（と日付）を探す。タイムテーブルのタイトル・ファイル名用
 *   「2024年度」         → 年度 2024
 *   「2024年」「2024」    → 西暦 2024
 *   「20241206」「2024-12-06」「2024.12.6」「2024年12月6日」 → 2024年12月6日
 * 2000〜2099 年だけを年とみなす（「1日目」「12月」などの数字を年と間違えないように）
 *
 * @return array{year: ?int, fiscal: bool, month: ?int, day: ?int, rest: string}  rest = 見つけた年・日付を取り除いた残り
 */
function find_year_hint(string $text): array
{
    $out = ['year' => null, 'fiscal' => false, 'month' => null, 'day' => null, 'rest' => $text];
    // 年月日がそろっているもの。(?<!\d) / (?!\d) は「前後が数字ではない」（長い数字の途中を拾わない）
    if (preg_match('/(?<!\d)(20\d{2})(?:([01]\d)([0-3]\d)|[-\/.年](\d{1,2})[-\/.月](\d{1,2})日?)(?!\d)/u', $text, $m)) {
        $month = (int)(($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? 0));
        $day = (int)(($m[3] ?? '') !== '' ? $m[3] : ($m[5] ?? 0));
        if (checkdate($month, $day, (int)$m[1])) {
            return ['year' => (int)$m[1], 'fiscal' => false, 'month' => $month, 'day' => $day,
                'rest' => tidy_hint_rest(str_replace($m[0], ' ', $text))];
        }
    }
    if (preg_match('/(?<!\d)(20\d{2})\s*(年度|年)?(?!\d)/u', $text, $m)) {
        $out = ['year' => (int)$m[1], 'fiscal' => ($m[2] ?? '') === '年度', 'month' => null, 'day' => null,
            'rest' => tidy_hint_rest(str_replace($m[0], ' ', $text))];
    }
    return $out;
}

/** 年などを取り除いた残りの、空白をまとめて端の区切り（_ - ・ 空白）を消す */
function tidy_hint_rest(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return preg_replace('/^[\s_\-・]+|[\s_\-・]+$/u', '', $s) ?? $s;
}

/** find_year_hint の結果を、まだ年（月日）が分かっていないタイムテーブルにだけ入れる */
function take_year_hint(array $tt, array $hint): array
{
    if ($hint['year'] !== null && $tt['hint_year'] === null) {
        [$tt['hint_year'], $tt['hint_fiscal']] = [$hint['year'], $hint['fiscal']];
    }
    if ($hint['month'] !== null && $tt['month'] === null) {
        [$tt['month'], $tt['day']] = [$hint['month'], $hint['day']];
    }
    return $tt;
}

/**
 * ファイルの中にライブ名・年・日付・日程が無いとき、ファイル名から補う
 *   「2024年度_文化祭ライブ_2日目_タイムテーブル.pdf」→ 年度 2024 / ライブ名「文化祭ライブ」/ 日程「2日目」
 * ファイルの中に書いてあるものが優先（ファイル名は付け方が人それぞれなので、あくまで補助）
 *
 * @param string $file 元のファイル名。Excel の複数シートは「名前.xlsx［シート名］」（table_reader.php）
 */
function apply_filename_hints(array $tt, string $file): array
{
    // 拡張子を外す。シート名もヒントになるので［］の中身は残す
    $base = preg_replace('/\.[A-Za-z0-9]{2,4}(?=［|$)/u', '', $file) ?? $file;
    $base = tt_width(str_replace(['［', '］'], ' ', $base));
    $hint = find_year_hint($base);
    $tt = take_year_hint($tt, $hint);
    $rest = $hint['rest'];

    // 月日（「12月6日」「12.6」）。年が無いので find_year_hint では拾えない分
    if (preg_match('/(?<!\d)(\d{1,2})月(\d{1,2})日/u', $rest, $m) || preg_match('/(?<![\d.])(\d{1,2})[.\/](\d{1,2})(?![\d.])/u', $rest, $m)) {
        if ($tt['month'] === null && checkdate((int)$m[1], (int)$m[2], 2000)) {
            [$tt['month'], $tt['day']] = [(int)$m[1], (int)$m[2]];
        }
        $rest = str_replace($m[0], ' ', $rest);
    }
    // 日程（中身のタイトルに日目・教室ライブが無いときだけ。parse_timetable と同じく 4日目以降は 1日目）
    if (preg_match('/(\d+)日目/u', $rest, $m)) {
        if (!preg_match('/日目|教室ライブ/u', $tt['title'])) {
            $tt['label'] = in_array((int)$m[1], [1, 2, 3], true) ? (int)$m[1] . '日目' : '1日目';
        }
        $rest = str_replace($m[0], ' ', $rest);
    }
    // ライブ名: ファイルの中に無いときだけ。「タイムテーブル」「最終版」「(1)」などの、ライブ名ではない言葉を消した残り
    if ($tt['live_name'] === '') {
        $name = preg_replace('/タイムテーブル|タイテ|タイムスケジュール|time\s*table|\bTT\b|名簿|メンバー表|最終版?|確定版?|修正版?|最新版?|完成版?|ver\.?\s*\d+|\bv\d+\b|コピー|copy|\(\d+\)/iu', ' ', $rest) ?? $rest;
        $name = preg_replace('/\(\s*\)|\[\s*\]|【\s*】/u', ' ', $name) ?? $name; // 中身を消して空になった括弧
        $tt['live_name'] = tidy_hint_rest(preg_replace('/[_\s]+/u', ' ', $name) ?? $name); // _ は区切りとみなして空白に
    }
    if ($tt['title'] === '') {
        $tt['title'] = $base; // プレビューの見出しが「（タイトルなし）」にならないように
    }
    return $tt;
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
 *        'song_count' => 3, 'member_count' => 4（同じ人は1人と数える）, 'key_note' => ''],
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
            // 人数は名簿の「人数」列を使わず、書いてある人を数える（ギタボのように同じ人が2つの欄にいても1人）
            'member_count' => count(array_unique(array_map(static fn($m) => member_key($m['name']), $members))),
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
            && ($slot['member_count'] === null || $roster[$i]['member_count'] === $slot['member_count'])));
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
    // 「ボカロバンド（瓜田・安田）」のように括弧の中に2人以上いたら、全員がメンバーにいるものを選ぶ
    $suffixKey = member_key($suffix);
    $suffixNames = array_map('member_key', preg_split('/[・、,，\/／&＆]+/u', $suffix, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $hits = [];
    foreach ($candidates as $i => $rSuffix) {
        $hit = $rSuffix !== '' && member_key($rSuffix) === $suffixKey;
        $found = 0;
        foreach ($suffixNames as $s) {
            foreach ($roster[$i]['members'] as $m) {
                if (str_starts_with(member_key($m['name']), $s)) {
                    $found++;
                    break;
                }
            }
        }
        if ($hit || ($suffixNames !== [] && $found === count($suffixNames))) {
            $hits[] = $i;
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * 名前では対応付けられなかった枠と名簿のバンドを、曲数と人数で対応付ける。
 * タイムテーブルは「ASIAN KUNG-FU GENERATION」、名簿は「アジカン」のように、略称で書かれていることがあるため。
 *
 * 残り物どうしで「曲数も人数も同じ」相手がお互いに1つだけのときに限る（2つ以上あれば決めない → プレビューで手で選ぶ）。
 * 名簿の人数は parse_roster が数えた「書いてある人の数」（タイムテーブルの人数も人の数なので、そのまま比べられる）。
 *
 * @param array $slots  まだ対応していない枠    [キー => slot]
 * @param array $roster まだ対応していない名簿のバンド [キー => band]
 * @return array 枠のキー => 名簿のキー
 */
function match_leftover_bands(array $slots, array $roster): array
{
    $same = static function (array $slot, array $band): bool {
        $members = $band['member_count'] ?? count($band['members']);
        return $slot['song_count'] !== null && $slot['song_count'] === $band['song_count']
            && ($slot['member_count'] === null || $slot['member_count'] === $members);
    };
    $pairs = [];
    foreach ($slots as $sk => $slot) {
        $hits = array_keys(array_filter($roster, static fn($band) => $same($slot, $band)));
        if (count($hits) !== 1) {
            continue;
        }
        $rk = $hits[0];
        $rivals = array_filter($slots, static fn($other) => $same($other, $roster[$rk]));
        if (count($rivals) === 1) {
            $pairs[$sk] = $rk;
        }
    }
    return $pairs;
}
