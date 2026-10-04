<?php
declare(strict_types=1);

require_once __DIR__ . '/text.php';
require_once __DIR__ . '/table_reader.php';

/** 「集合」以外でバンドではない枠 */
const NON_BAND_SLOT = '/^(休憩|昼休憩|ご飯|昼食|夕食|転換|メインステージ|撤収|片付け|片づけ|リハ.*|準備|解散|打ち上げ)$/u';

/** 見出し行（「バンド名」を含む行）の位置 */
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
 * PDFでは鍵盤メモが隣の「人数」列にはみ出すことがある（「私物（シンセ）, 7」）。
 * 数値列のセルから数字以外を取り出して返す
 */
function overflow_text(string $cell): string
{
    return trim(preg_replace('/^[\s,、]+|[\s,、]+$/u', '', preg_replace('/\d+(\(\d+\))?/', '', tt_width($cell)) ?? '') ?? '');
}

/** 'timetable'（タイムテーブル）か 'roster'（メンバー表）か */
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
    throw new RuntimeException('タイムテーブルかメンバー表か判別できませんでした（「時間」列も「Vo/Gt/Ba/Dr」列も無い）');
}

/**
 * タイムテーブル → ライブ1日分
 * 前置き行: 「1月5日 / 1月ライブ1日目 / 会場 / 江古田クラブドロシー」
 */
function parse_timetable(array $rows): array
{
    $hi = find_header_index($rows);
    $header = $rows[$hi];
    $result = [
        'label' => '', 'live_name' => '', 'day_no' => 1, 'month' => null, 'day' => null,
        'venue' => '', 'meeting_time' => null, 'slots' => [],
    ];

    // ---- 前置き行からライブ名・日付・会場 ----
    $preamble = array_values(array_filter(array_map('tt_width', array_merge(...array_slice($rows, 0, $hi) ?: [[]])), 'strlen'));
    // CSV の中には見出しの右側に前置きが書かれていることもある
    $labelCandidates = [];
    for ($i = 0; $i < count($preamble); $i++) {
        $cell = $preamble[$i];
        if ($cell === '会場' || $cell === '会場:') {
            $result['venue'] = $preamble[$i + 1] ?? '';
            $i++;
        } elseif (preg_match('/^会場[:：]?\s*(.+)$/u', $cell, $m)) {
            $result['venue'] = $m[1];
        } elseif (preg_match('/(\d{1,2})月(\d{1,2})日/u', $cell, $m)) {
            [$result['month'], $result['day']] = [(int)$m[1], (int)$m[2]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $cell, $m)) {
            [$result['month'], $result['day']] = [(int)$m[1], (int)$m[2]];
        } else {
            $labelCandidates[] = $cell;
        }
    }
    $label = '';
    foreach ($labelCandidates as $c) {
        if (preg_match('/ライブ|日目|live/iu', $c)) {
            $label = $c;
            break;
        }
    }
    $label = $label ?: ($labelCandidates[0] ?? '');
    $result['label'] = $label;
    if (preg_match('/^(.*?)\s*(\d+)日目\s*$/u', $label, $m)) {
        $result['live_name'] = trim($m[1]);
        $result['day_no'] = (int)$m[2];
    } else {
        $result['live_name'] = $label;
    }

    // ---- 列の位置 ----
    $cTime  = find_column($header, '/^時間/u');
    $cDur   = find_column($header, '/持ち時間/u');
    $cBand  = find_column($header, '/バンド名/u');
    $cSongs = find_column($header, '/曲数/u');
    $cCount = find_column($header, '/人数/u');
    $cKey   = find_column($header, '/^(key|鍵盤|キーボード)/iu');
    $timeCols = $cTime === null ? [] : [$cTime];
    // 「時間,,持ち時間」のように開始・終了の2列に分かれている場合
    if ($cTime !== null && isset($header[$cTime + 1]) && trim($header[$cTime + 1]) === '' && $cTime + 1 !== $cBand) {
        $timeCols[] = $cTime + 1;
    }

    $order = 0;
    foreach (array_slice($rows, $hi + 1) as $row) {
        $name = tt_width($row[$cBand] ?? '');
        if ($name === '' || $name === 'バンド名') {
            continue;
        }
        $times = extract_times(implode(' ', array_map(static fn($c) => $row[$c] ?? '', $timeCols)));
        if (preg_match('/^集合/u', $name)) {
            $result['meeting_time'] = $times[0] ?? null;
            continue;
        }
        $songs = $cSongs === null ? null : extract_int($row[$cSongs] ?? '');
        $count = $cCount === null ? null : extract_int($row[$cCount] ?? '');
        $isBand = !preg_match(NON_BAND_SLOT, $name) && !($songs === null && $count === null);
        $result['slots'][] = [
            'is_band'      => $isBand,
            'band_name'    => $name,
            'play_order'   => $isBand ? ++$order : null,
            'start_time'   => $times[0] ?? null,
            'end_time'     => $times[1] ?? null,
            'duration'     => $cDur === null ? null : extract_int($row[$cDur] ?? ''),
            'song_count'   => $songs,
            'member_count' => $count,
            'key_note'     => trim(($cCount === null ? '' : overflow_text($row[$cCount] ?? '') . ' ') . ($cKey === null ? '' : tt_width($row[$cKey] ?? ''))),
        ];
    }
    return $result;
}

/** メンバー表 → [ [band_name, members => [[name, part]], song_count, member_count, key_note], ... ] */
function parse_roster(array $rows): array
{
    $hi = find_header_index($rows);
    $header = $rows[$hi];
    $cBand  = find_column($header, '/バンド名/u');
    $cSongs = find_column($header, '/曲数/u');
    $end    = $cSongs ?? count($header);
    $partCols = [];
    for ($c = $cBand + 1; $c < $end; $c++) {
        $part = normalize_part($header[$c] ?? '');
        if ($part !== null) {
            $partCols[$c] = $part;
        }
    }
    $cCount = find_column($header, '/人数/u');
    $cKey   = find_column($header, '/^(key|鍵盤)$/iu', $end);

    $bands = [];
    foreach (array_slice($rows, $hi + 1) as $row) {
        $name = tt_width($row[$cBand] ?? '');
        if ($name === '' || $name === 'バンド名') {
            continue;
        }
        $members = [];
        foreach ($partCols as $c => $part) {
            foreach (split_member_names($row[$c] ?? '') as $person) {
                $members[$person . "\0" . $part] = ['name' => $person, 'part' => $part];
            }
        }
        $keyNote = $cKey === null ? '' : tt_width($row[$cKey] ?? '');
        $spill = $cCount === null ? '' : overflow_text($row[$cCount] ?? '');
        $bands[] = [
            'band_name'    => $name,
            'members'      => array_values($members),
            'song_count'   => $cSongs === null ? null : extract_int($row[$cSongs] ?? ''),
            'member_count' => $cCount === null ? null : extract_int($row[$cCount] ?? ''),
            'key_note'     => trim($spill . ($spill !== '' && $keyNote !== '' ? ' ' : '') . $keyNote),
        ];
    }
    return $bands;
}

/**
 * タイムテーブルのバンド名からメンバー表の該当バンドを探す。
 * 完全一致 →「ヨルシカ(安田)」のような代表者付き名称の照合、の順で試す。
 * @param array $slot parse_timetable() の slots の1要素
 * @return int|null メンバー表のインデックス
 */
function match_roster_band(array $slot, array $roster): ?int
{
    $bandName = $slot['band_name'];
    $key = band_key($bandName);
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
        // 同名が複数 → 曲数・人数が一致するものが1つだけならそれ
        $same = array_values(array_filter($exact, static fn($i) =>
            $roster[$i]['song_count'] === $slot['song_count']
            && ($roster[$i]['member_count'] === null || $roster[$i]['member_count'] === $slot['member_count'])));
        if (count($same) === 1) {
            return $same[0];
        }
    }

    [$base, $suffix] = band_split_suffix($bandName);
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
    // 括弧の中の名前を持つメンバーがいる候補に絞る
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
