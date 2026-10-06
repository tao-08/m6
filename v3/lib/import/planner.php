<?php
/**
 * =====================================================================
 *  planner.php — 取り込みの「計画づくり」と「DBへの書き込み」
 * =====================================================================
 *  取り込みは2段階に分けている。
 *
 *   ① build_import_plan()  … ファイルを読んで「こう登録するつもり」という計画（配列）を作る。
 *                             DB にはまだ何も書かない。計画はセッションに置いてプレビュー画面で見せる。
 *   ② commit_import_plan() … プレビューで人間が直した値（フォームの送信内容）を使って DB に書く。
 *
 *  なぜ分ける？ → ファイルの読み取りは100%正しくはできない。
 *  間違ったまま DB に入ると直すのが大変なので、必ず人の目で確認してから登録する。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/parsers.php';
require_once __DIR__ . '/../repository.php';

/** 4月始まりの年度。1〜3月は前の年の年度になる（2026年1月のライブ → 2025年度） */
function academic_year(int $month, int $year): int
{
    return $month >= 4 ? $year : $year - 1;
}

/** "2026-01-12" → 2025（年度）。日付として正しくなければ null */
function fiscal_year_from_date(string $date): ?int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) || !checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
        return null;
    }
    return academic_year((int)$d[2], (int)$d[1]);
}

/**
 * タイムテーブルから読んだ会場名に一番近い、登録済みの会場を探す。
 * 「渋谷ＣＬＵＢ ＱＵＡＴＴＲＯ」→「渋谷CLUB QUATTRO」のような表記ゆれを吸収する。
 *
 * @param array<int,string> $venues [venue_id => 会場名]
 * @return int|null 似ている会場の venue_id。それっぽいものが無ければ null（= 新規作成）
 */
function guess_venue(string $parsed, array $venues): ?int
{
    $key = band_key($parsed); // 全角/半角・空白・大文字小文字をそろえた比較用キー
    if ($key === '') {
        return null;
    }
    $bestId = null;
    $bestScore = 0.0;
    foreach ($venues as $id => $name) {
        $vKey = band_key($name);
        if ($vKey === '') {
            continue;
        }
        if ($vKey === $key) {
            return (int)$id; // 完全一致なら即決
        }
        $longer = max(mb_strlen($key), mb_strlen($vKey));
        // 似ている度合い（1 = 同じ, 0 = 全然違う）
        $score = 1 - mb_levenshtein($key, $vKey) / $longer;
        // 片方がもう片方を含む（「QUATTRO」と「渋谷QUATTRO」）なら、かなり似ている扱い
        if (min(mb_strlen($key), mb_strlen($vKey)) >= 2 && (str_contains($vKey, $key) || str_contains($key, $vKey))) {
            $score = max($score, 0.8);
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = (int)$id;
        }
    }
    return $bestScore >= 0.6 ? $bestId : null;
}

/**
 * 名簿の全バンドを「検索欄に出す文字 => 'ri:bi'（何番目の名簿の何番目のバンドか）」にする。
 * 別ファイルに同じバンド名があるときだけ、区別のためにファイル名を付ける。
 * 画面の表示と登録処理の両方でこの関数を使うので、文字と中身が必ず一致する。
 */
function roster_choices(array $plan): array
{
    $count = [];
    foreach ($plan['rosters'] as $roster) {
        foreach ($roster['bands'] as $band) {
            $count[$band['band_name']] = ($count[$band['band_name']] ?? 0) + 1;
        }
    }
    $choices = [];
    foreach ($plan['rosters'] as $ri => $roster) {
        foreach ($roster['bands'] as $bi => $band) {
            $label = $count[$band['band_name']] > 1 ? "{$band['band_name']}（{$roster['file']}）" : $band['band_name'];
            if (isset($choices[$label])) {
                $label .= ' #' . ($bi + 1); // 同じファイルの中に同名バンドが2つある場合
            }
            $choices[$label] = "$ri:$bi";
        }
    }
    return $choices;
}

/**
 * パート（'Vo' 'Gt'…）→ instrument_id の初期値。
 * instrument.short_name（'Vo' 'Gt'…）と比べる。'Other'（Key./Other の列）はキーボードにする（「その他」を初期値にはしない）。
 */
function default_instrument_id(string $part): ?int
{
    return instrument_id_by_short($part === 'Other' ? 'Key' : $part);
}

/**
 * 名簿の列見出し（「Cho.」「Perc」など）→ instrument_id。楽器の略称か名前と一致しなければ null。
 * 「その他」（etc）は一致させない（見出しが「その他」の列も、初期値はキーボードにするため）。
 */
function instrument_from_header(string $title): ?int
{
    $label = mb_strtolower(preg_replace('/[\s.．\d]/u', '', tt_width($title)) ?? '');
    foreach (instruments() as $ins) {
        if ($ins['short_name'] === 'etc') {
            continue;
        }
        if ($label !== '' && ($label === mb_strtolower($ins['short_name']) || $label === mb_strtolower($ins['name']))) {
            return (int)$ins['instrument_id'];
        }
    }
    return null;
}

/**
 * 「Key/その他」列で選べる楽器 = Vo / Gt / Ba / Dr 以外の楽器（キーボード、ヴァイオリン、サックス…）。
 * 名簿の Key 列には「鍵盤以外の人」もまとめて書かれることがあるので、セルごとに選べるようにする。
 */
function extra_instruments(): array
{
    return array_values(array_filter(instruments(), static fn($i) => !in_array($i['short_name'], ['Vo', 'Gt', 'Ba', 'Dr'], true)));
}

/** 名簿の見出しに出す略称。Key とその他は同じ扱い（人ごとに楽器を選ぶ列） */
function part_label(string $part): string
{
    return match ($part) {
        'Vo' => 'Vo.', 'Gt' => 'Gt.', 'Ba' => 'Ba.', 'Dr' => 'Dr.',
        default => 'Key./Other',
    };
}

/** 人ごとに楽器を選べる列か（Key / その他） */
function is_free_part(string $part): bool
{
    return in_array($part, ['Key', 'Other'], true);
}

/**
 * 名簿の「1つのセルに2人以上」（「村田侑斗、丸野友多郎」など）を、1セル1人に分ける。
 *   1人目 → 元の列にそのまま
 *   2人目以降 → 表の右端の「追加列」へ。楽器は元の列の楽器を初期値にする（あとでプルダウンで変えられる）
 * 「丸野友多郎(Sax)」のように楽器が書いてあれば、その楽器を初期値にする。
 *
 * 結果: 各バンドに 'extras' => [['name' => ..., 'instrument_id' => ...], ...]、
 *       名簿に 'extra_cols' => 追加列の数（一番多いバンドに合わせる）
 */
function spread_roster_cells(array $roster): array
{
    $roster['extra_cols'] = 0;
    foreach ($roster['bands'] as &$band) {
        $band['extras'] = [];
        foreach ($roster['columns'] as $col => $c) {
            $names = split_member_names((string)($band['cells'][$col] ?? ''));
            $first = array_shift($names) ?? '';
            // Vo. 欄に「山田(Gt)」と書いてあれば、ギターボーカルを初期値にして名前から (Gt) を外す
            if ($c['part'] === 'Vo') {
                [$plain, $named] = parse_name_instrument($first);
                $role = vocal_role_from_instrument($named);
                if ($role !== null) {
                    $first = $plain;
                    $band['vo_roles'][$col] = $role;
                }
            }
            // Key./Other 欄に「村田(Vn)」と書いてあれば、ヴァイオリンを初期値にして名前から (Vn) を外す
            // （その欄の選択肢にある楽器だけ。Vo / Gt などは欄の選択肢に無いので、名前に残して登録時に読む）
            if (is_free_part($c['part'])) {
                [$plain, $named] = parse_name_instrument($first);
                if ($named !== null && in_array($named, array_map('intval', array_column(extra_instruments(), 'instrument_id')), true)) {
                    $first = $plain;
                    $band['cell_insts'][$col] = $named;
                }
            }
            $band['cells'][$col] = $first;
            foreach ($names as $name) {
                [$plain, $named] = parse_name_instrument($name);
                $band['extras'][] = ['name' => $plain, 'instrument_id' => $named ?? $c['instrument_id']];
            }
        }
        // 「(Gt)」などの書き方で決まらなかった Vo. 欄は、ほかの欄から推測して選んでおく
        $firstVo = true;
        foreach ($roster['columns'] as $col => $c) {
            if ($c['part'] !== 'Vo' || $band['cells'][$col] === '') {
                continue;
            }
            $band['vo_roles'][$col] ??= guess_vocal_role($roster['columns'], $band, $band['cells'][$col], $firstVo);
            $firstVo = false;
        }
        $roster['extra_cols'] = max($roster['extra_cols'], count($band['extras']));
    }
    unset($band);
    return $roster;
}

/**
 * 名簿の1バンドから「ボーカルの形」の初期値を推測する（プレビューで選んでおくだけ。あとで変えられる）
 *   1. ボーカルと同じ名前が Gt / Ba / Key / Dr 欄にいる → その楽器のボーカル
 *   2. 1人目のボーカルだけ、空欄から推測する（2人目以降のボーカルにまで当てはめると、全員ギターボーカルになってしまう）
 *      - Gt.1 が空で Gt.2 に人がいる          → ギターボーカル（空いた Gt.1 がボーカル本人の分）
 *      - Ba. が空で、メンバーが3人            → ベースボーカル（スリーピース）
 *      - Gt.1 も Gt.2 も空で、メンバーが3人   → ギターボーカル（スリーピース）
 *      「空欄」だけで決めないのは、ベースやギターがいない編成（アコースティックなど）まで巻き込むから
 * @param array  $band    spread_roster_cells で1セル1人に分けた後のバンド（cells と extras）
 * @param string $voName  ボーカルの名前
 * @return string|null VOCAL_ROLES のキー。推測できなければ null（= 単体ボーカル）
 */
function guess_vocal_role(array $columns, array $band, string $voName, bool $firstVo): ?string
{
    // ---- 1. 同じ名前を探す（右端の追加列に移した2人目以降も見る） ----
    $voKey = member_key(parse_name_instrument($voName)[0]);
    $keysOf = []; // 楽器の略称 => [名前のキー => true]
    foreach ($columns as $col => $c) {
        $name = (string)($band['cells'][$col] ?? '');
        if ($name !== '') {
            $keysOf[$c['part']][member_key(parse_name_instrument($name)[0])] = true;
        }
    }
    foreach ($band['extras'] as $x) {
        foreach (array_filter(array_column(VOCAL_ROLES, 'also')) as $short) { // Gt / Ba / Key / Dr
            if ($x['instrument_id'] === instrument_id_by_short($short)) {
                $keysOf[$short][member_key($x['name'])] = true;
            }
        }
    }
    foreach (VOCAL_ROLES as $role => $r) {
        if ($r['also'] !== null && isset($keysOf[$r['also']][$voKey])) {
            return $role;
        }
    }
    if (!$firstVo) {
        return null;
    }

    // ---- 2. 空欄から推測 ----
    // パートの列のセルを左から順に並べる（例: Gt なら [Gt.1 の名前, Gt.2 の名前]）
    $cellsOf = static function (string $part) use ($columns, $band): array {
        $cells = [];
        foreach ($columns as $col => $c) {
            if ($c['part'] === $part) {
                $cells[] = (string)($band['cells'][$col] ?? '');
            }
        }
        return $cells;
    };
    $gt = $cellsOf('Gt');
    $ba = $cellsOf('Ba');
    if (count($gt) >= 2 && $gt[0] === '' && $gt[1] !== '') {
        return 'gt';
    }
    $trio = roster_band_size($band) === 3;
    if ($trio && $ba && implode('', $ba) === '') {
        return 'ba';
    }
    if ($trio && $gt && implode('', $gt) === '') {
        return 'gt';
    }
    return null;
}

/** 名簿の1バンドの人数。「人数」列があればそれ、無ければ書かれている名前を数える（同じ人が2つの欄にいても1人） */
function roster_band_size(array $band): int
{
    if (($band['member_count'] ?? null) !== null) {
        return (int)$band['member_count'];
    }
    $keys = [];
    foreach ($band['cells'] as $name) {
        if ($name !== '') {
            $keys[member_key(parse_name_instrument($name)[0])] = true;
        }
    }
    foreach ($band['extras'] as $x) {
        $keys[member_key($x['name'])] = true;
    }
    return count($keys);
}

/**
 * 名前の後ろに楽器を書く書き方に対応する: 「丸野友多郎(Sax)」「村田侑斗（キーボード）」
 *   → ['丸野友多郎', サックスの instrument_id]
 * 括弧の中が楽器名でなければ、ただの名前として扱う。
 * 1つのセルに「村田侑斗、丸野友多郎(Sax)」と書けば、1人ずつ違う楽器にできる。
 */
function parse_name_instrument(string $name): array
{
    if (preg_match('/^(.+?)[(（]([^()（）]+)[)）]$/u', $name, $m)) {
        $label = mb_strtolower(trim(tt_width($m[2])));
        foreach (instruments() as $ins) {
            if ($label === mb_strtolower($ins['short_name']) || $label === mb_strtolower($ins['name'])) {
                return [$m[1], (int)$ins['instrument_id']];
            }
        }
    }
    return [$name, null];
}

/**
 * ① ファイル群 → 取り込み計画
 *
 * @param array $files [['path' => 一時ファイル, 'name' => 元のファイル名], ...]
 * @return array [
 *   'timetables' => [ 1日分ずつ（parse_timetable の結果 + file, year, date） ],
 *   'rosters'    => [ 名簿1ファイルずつ（parse_roster の結果 + file）],
 *   'errors'     => [ 読めなかったファイルのメッセージ ],
 * ]
 */
function build_import_plan(array $files): array
{
    $plan = ['timetables' => [], 'rosters' => [], 'errors' => []];

    // ---- 1. 1ファイルずつ読んで、タイムテーブルか名簿かで振り分け ----
    // Excel は1ファイルに複数シートがあるので、シート1枚を「1ファイル」とみなして同じ処理に流す
    foreach ($files as $f) {
        try {
            $sheets = read_table_sheets($f['path'], $f['name']);
        } catch (Throwable $e) {
            $plan['errors'][] = $f['name'] . ': ' . $e->getMessage();
            continue;
        }
        foreach ($sheets as $label => $rows) {
            try {
                if (detect_table_kind($rows) === 'timetable') {
                    $tt = parse_timetable($rows);
                    $tt['file'] = $label;
                    $plan['timetables'][] = $tt;
                } else {
                    $roster = parse_roster($rows);
                    $roster['file'] = $label;
                    foreach ($roster['columns'] as &$col) {
                        // 見出しが「Cho.」「Sax」のように楽器そのものなら、その楽器を列の初期値にする
                        $col['instrument_id'] = (is_free_part($col['part']) ? instrument_from_header($col['title']) : null)
                            ?? default_instrument_id($col['part']);
                    }
                    unset($col); // foreach の参照(&)は使い終わったら必ず unset（後で事故る）
                    $plan['rosters'][] = spread_roster_cells($roster);
                }
            } catch (Throwable $e) {
                // 1シート読めなくても他のシート・ファイルは続ける
                $plan['errors'][] = $label . ': ' . $e->getMessage();
            }
        }
    }

    // ---- 2. 日付と年度の初期値 ----
    // タイムテーブルには「年」が書かれていないので、今日に一番近い過去の年を仮で入れる
    foreach ($plan['timetables'] as &$tt) {
        $tt['date'] = '';
        $tt['year'] = academic_year((int)date('n'), (int)date('Y'));
        if ($tt['month'] && $tt['day']) {
            $y = (int)date('Y');
            // 例: 今が10月で「1月5日」と書いてあったら、たぶん今年の1月（未来の1月ではない）
            if (mktime(0, 0, 0, $tt['month'], $tt['day'], $y) > time() + 86400 * 60) {
                $y--;
            }
            if (checkdate($tt['month'], $tt['day'], $y)) {
                $tt['date'] = sprintf('%04d-%02d-%02d', $y, $tt['month'], $tt['day']);
                $tt['year'] = academic_year($tt['month'], $y);
            }
        }
        // バンドの枠は「取り込む」にチェック、休憩などは外しておく
        $order = 0;
        foreach ($tt['slots'] as &$slot) {
            $slot['include'] = $slot['is_band'];
            $slot['order'] = $slot['is_band'] ? ++$order : null;
        }
        unset($slot);
    }
    unset($tt);

    // ---- 3. 名簿のバンド ↔ タイムテーブルの枠 を自動で対応付け ----
    // 名簿が複数ファイルでも探せるように、いったん1本の配列にする（$ref で元の位置を覚えておく）
    $flat = [];
    $ref = [];
    foreach ($plan['rosters'] as $ri => $roster) {
        foreach ($roster['bands'] as $bi => $band) {
            $flat[] = $band;
            $ref[] = [$ri, $bi];
        }
    }
    foreach ($plan['rosters'] as &$roster) {
        foreach ($roster['bands'] as &$band) {
            $band['slot'] = ''; // まだどの枠にも対応していない
        }
        unset($band);
    }
    unset($roster);

    foreach ($plan['timetables'] as $ti => $tt) {
        foreach ($tt['slots'] as $si => $slot) {
            if (!$slot['is_band']) {
                continue;
            }
            $i = match_roster_band($slot, $flat);
            if ($i === null) {
                continue;
            }
            [$ri, $bi] = $ref[$i];
            // 名簿の1バンドは1枠にだけ対応させる（先に見つかった枠が優先）
            if ($plan['rosters'][$ri]['bands'][$bi]['slot'] === '') {
                $plan['rosters'][$ri]['bands'][$bi]['slot'] = "$ti:$si"; // 「何日目の何番目の枠か」
            }
        }
    }
    return $plan;
}

/**
 * 名簿のセル（「村田侑斗、丸野友多郎」など）が DB の誰と一致するかを調べる。
 * プレビューの入力欄の色分けに使う（緑=登録済み / 黄=似た人がいる / 赤=新しい人）。
 *
 * @param string[] $cells セルの文字列の配列
 * @return array 同じ順番で ['status' => 'ok'|'similar'|'new'|'', 'hint' => 説明文]
 */
function classify_cells(PDO $pdo, array $cells): array
{
    $index = load_member_index($pdo);

    // 今回の取り込みで新しく出てくる名前（DBにない名前）を集めておく
    // → 「清水啓之介」と「清水啓乃介」のように、今回の中で表記がブレているのも見つけるため
    $newKeys = [];
    foreach ($cells as $cell) {
        foreach (split_member_names((string)$cell) as $name) {
            [$name] = parse_name_instrument($name); // 「名前(Sax)」の楽器部分は外して照合
            $key = member_key($name);
            if (!isset($index[$key])) {
                $newKeys[$key] = $name;
            }
        }
    }

    $result = [];
    foreach ($cells as $cell) {
        $names = array_map(static fn($n) => parse_name_instrument($n)[0], split_member_names((string)$cell));
        if ($names === []) {
            $result[] = ['status' => '', 'hint' => '', 'suggest' => []];
            continue;
        }
        $statuses = [];
        $hints = [];
        $suggest = []; // 黄色のときの「もしかして」の候補（画面でクリックすると、その名前が入る）
        foreach ($names as $name) {
            $key = member_key($name);
            if (isset($index[$key])) {
                $statuses[] = 'ok';
                continue;
            }
            // DB の人と似ている？
            $similar = [];
            foreach ($index as $eKey => $e) {
                if (names_look_similar($key, $eKey)) {
                    $similar[] = $e['name'];
                }
            }
            if ($similar) {
                $statuses[] = 'similar';
                $hints[] = "「{$name}」は未登録。登録済みの「" . implode('」「', array_slice($similar, 0, 3)) . '」の書き間違い？';
                $suggest = [...$suggest, ...array_slice($similar, 0, 3)];
                continue;
            }
            // 今回の取り込みの中の別の新しい名前と似ている？
            $similarNew = [];
            foreach ($newKeys as $nKey => $nName) {
                if (names_look_similar($key, $nKey)) {
                    $similarNew[] = $nName;
                }
            }
            if ($similarNew) {
                $statuses[] = 'similar';
                $hints[] = "「{$name}」は未登録。今回の「" . implode('」「', $similarNew) . '」と表記ゆれ？';
                $suggest = [...$suggest, ...array_slice($similarNew, 0, 3)];
                continue;
            }
            $statuses[] = 'new';
            $hints[] = "「{$name}」は未登録 → 新しいメンバーとして登録されます";
        }
        // 1つのセルに複数人いるときは、一番注意が必要な色にする
        $status = in_array('new', $statuses, true) ? 'new' : (in_array('similar', $statuses, true) ? 'similar' : 'ok');
        // 候補で置きかえられるのは「1セルに1人」のときだけ（2人いると、どちらを置きかえるか決められない）
        $result[] = ['status' => $status, 'hint' => implode("\n", $hints), 'suggest' => count($names) === 1 ? $suggest : []];
    }
    return $result;
}

/**
 * ② プレビューで確定した内容を DB に書き込む。
 *
 * 全体を1つのトランザクションにしている。
 *   トランザクション = 「全部成功したら確定(commit)、途中で1つでも失敗したら全部取り消し(rollBack)」
 *   → 途中でエラーになって「バンドは入ったけどメンバーは入っていない」という中途半端な状態を防ぐ。
 *
 * @param array $plan  build_import_plan() の結果（セッションに入っていたもの）
 * @param array $input プレビューのフォームの送信内容
 * @return int[] 登録した live_id
 */
function commit_import_plan(PDO $pdo, array $plan, array $input): array
{
    $memberIndex = load_member_index($pdo);
    $validInstruments = array_map('intval', array_column(instruments(), 'instrument_id'));
    $rosterChoices = roster_choices($plan); // 「名簿」検索欄の文字 → 'ri:bi'
    $bandRosters = [];                      // [登録した band_id, 'ri:bi'] のリスト
    $liveIds = [];

    $pdo->beginTransaction();
    try {
        // ================= 1. ライブ・日程・バンド =================
        foreach ($plan['timetables'] as $ti => $tt) {
            $form = $input['tt'][$ti] ?? [];
            if (!empty($form['skip'])) {
                continue; // 「この日程は取り込まない」
            }
            $where = "「{$tt['file']}」";

            // ---- 入力チェック（DB の型・制約に合わせる） ----
            $liveName = trim((string)($form['live_name'] ?? ''));
            $label    = trim((string)($form['label'] ?? ''));
            $date     = (string)($form['date'] ?? '');
            $venueSel = (string)($form['venue_id'] ?? '');            // venue_id / 'new' / ''（未設定）
            $venueNew = trim((string)($form['venue_new'] ?? ''));     // 'new' のときの新しい会場名

            // 年度は入力させず、開催日から決める（年度の入れ間違いが起きない）
            $year = fiscal_year_from_date($date);
            if ($year === null) {
                throw new RuntimeException("{$where} 開催日を正しく入力してください（年度は開催日から自動で決まります）");
            }
            if ($year < 1990 || $year > 2100) { // live.fiscal_year の CHECK 制約と同じ範囲
                throw new RuntimeException("{$where} 開催日の年が範囲外です");
            }
            if ($liveName === '' || mb_strlen($liveName) > 50) {
                throw new RuntimeException("{$where} ライブ名は1〜50文字で入力してください");
            }
            if ($label === '' || mb_strlen($label) > 50) {
                throw new RuntimeException("{$where} 日程（1日目など）は1〜50文字で入力してください");
            }

            // ---- 会場: プルダウンで選んだ既存の会場 or 新規作成 ----
            if ($venueSel === 'new') {
                if ($venueNew === '' || mb_strlen($venueNew) > 50) {
                    throw new RuntimeException("{$where} 新しい会場名は1〜50文字で入力してください");
                }
                $venueId = find_or_create_venue($pdo, $venueNew);
            } elseif ($venueSel === '') {
                $venueId = null;
            } else {
                // フォームの値は書き換えられる可能性があるので、本当に存在する会場か DB で確認する
                $st = $pdo->prepare('SELECT venue_id FROM venue WHERE venue_id = ?');
                $st->execute([ctype_digit($venueSel) ? (int)$venueSel : 0]);
                $venueId = $st->fetchColumn();
                if ($venueId === false) {
                    throw new RuntimeException("{$where} 会場の選択が正しくありません");
                }
                $venueId = (int)$venueId;
            }

            // ---- 同じ日程が登録済みか ----
            $liveId = find_or_create_live($pdo, $year, $liveName);
            $st = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ? AND label = ?');
            $st->execute([$liveId, $label]);
            $existing = $st->fetchColumn();
            if ($existing !== false) {
                if (empty($form['overwrite'])) {
                    throw new RuntimeException("{$year}年度「{$liveName}」{$label} は登録済みです。置き換えるなら「上書き」にチェックしてください");
                }
                // 日程を消せば、バンド・出演記録は ON DELETE CASCADE で DB が消してくれる
                delete_live_day($pdo, (int)$existing);
                $liveId = find_or_create_live($pdo, $year, $liveName); // ライブごと消えた場合に作り直す
            }

            // ---- live_day ----
            $pdo->prepare('INSERT INTO live_day (live_id, label, held_on, venue_id) VALUES (?, ?, ?, ?)')
                ->execute([$liveId, $label, $date, $venueId]);
            $dayId = (int)$pdo->lastInsertId();

            // ---- band ----
            // 「取り込む」にチェックがある枠だけ集めて、入力された出演順で並べ替える
            $rows = [];
            foreach ($tt['slots'] as $si => $slot) {
                $s = $form['s'][$si] ?? [];
                if (empty($s['include'])) {
                    continue;
                }
                $name = trim((string)($s['name'] ?? ''));
                $songs = (string)($s['songs'] ?? '');
                if ($name === '' || mb_strlen($name) > 100) {
                    throw new RuntimeException("{$where} バンド名は1〜100文字で入力してください");
                }
                if ($songs !== '' && (!ctype_digit($songs) || (int)$songs > 255)) {
                    throw new RuntimeException("{$where}「{$name}」の曲数は0〜255の数字で入力してください");
                }
                $start = $slot['start_time'];
                $end = $slot['end_time'];
                $rows[] = [
                    'si' => $si,
                    // この枠のメンバーをどの名簿のバンドから取るか（検索欄の文字 → 'ri:bi'。一致しなければ名簿なし）
                    'roster' => $rosterChoices[trim((string)($s['roster'] ?? ''))] ?? null,
                    'order' => (int)($s['order'] ?? 999),
                    'name' => $name,
                    'songs' => (int)$songs,
                    'note' => mb_substr(trim((string)($s['note'] ?? '')), 0, 255),
                    // 終了が開始より前（日付またぎ・書き間違い）は CHECK 制約に引っかかるので終了を捨てる
                    'start' => $start,
                    'end' => ($start && $end && $end > $start) ? $end : null,
                ];
            }
            // 出演順 → 同じ番号なら元の並び順、で並べる。<=> は「宇宙船演算子」（大小比較で -1/0/1 を返す）
            usort($rows, static fn($a, $b) => [$a['order'], $a['si']] <=> [$b['order'], $b['si']]);

            $insBand = $pdo->prepare('INSERT INTO band (live_day_id, artist_id, name, play_order, start_time, end_time, song_count, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $n => $r) {
                // 出演順は 1,2,3... と振り直す（(live_day_id, play_order) が UNIQUE なので重複させない）
                $insBand->execute([$dayId, find_or_create_artist($pdo, $r['name']), $r['name'], $n + 1,
                    $r['start'], $r['end'], $r['songs'], $r['note'] !== '' ? $r['note'] : null]);
                if ($r['roster'] !== null) {
                    $bandRosters[] = [(int)$pdo->lastInsertId(), $r['roster']];
                }
            }
            $liveIds[] = $liveId;
        }

        // ================= 2. 名簿 → メンバー =================
        // タイムテーブルの「名簿」欄で選んだ名簿のバンドから、メンバーを登録する。
        // 同じ名簿のバンドを2日分の枠で選んでもOK（2日とも同じメンバーで出る場合）
        foreach ($bandRosters as [$bandId, $rosterRef]) {
            [$ri, $bi] = array_map('intval', explode(':', $rosterRef));
            $roster = $plan['rosters'][$ri];
            $rb = $input['rb'][$ri][$bi] ?? [];
            // 楽器の決め方（優先順）: ① 名前の後ろの (Sax) → ② セルのプルダウン → ③ 列のパートの楽器
            // フォームの値は書き換えられる可能性があるので、instrument テーブルにある ID だけ受け付ける
            $pick = static fn($id, ?int $default) => in_array((int)$id, $validInstruments, true) ? (int)$id : $default;
            $assignments = [];
            $add = static function (string $cell, ?int $instrument) use (&$assignments): void {
                // 1セルに「、」で2人書かれていても、1人ずつ登録する
                foreach (split_member_names($cell) as $name) {
                    [$name, $named] = parse_name_instrument($name);
                    $assignments[] = [$name, $named ?? $instrument];
                }
            };
            // 名簿の元の列（Vo. Gt. Ba. Dr. は楽器固定、Key./Other はセルごとに選ぶ）
            foreach ($roster['columns'] as $col => $c) {
                $instrument = $c['instrument_id'] ?? default_instrument_id($c['part']);
                if (is_free_part($c['part'])) {
                    $instrument = $pick($rb['ci'][$col] ?? 0, $instrument);
                }
                $cell = (string)($rb['c'][$col] ?? '');
                if ($c['part'] === 'Vo') {
                    // Vo. 欄: ボーカルは必ず登録し、ギター/ベースボーカルならその楽器の行も足す
                    $also = VOCAL_ROLES[vocal_role($rb['vr'][$col] ?? null)]['also'];
                    foreach (split_member_names($cell) as $name) {
                        [$name, $named] = parse_name_instrument($name);
                        $assignments[] = [$name, $instrument];
                        if ($also !== null) {
                            $assignments[] = [$name, default_instrument_id($also)];
                        }
                        if ($named !== null) { // 「山田(Key)」なら Vo + Key（同じ行が2回になっても INSERT IGNORE で1行）
                            $assignments[] = [$name, $named];
                        }
                    }
                    continue;
                }
                $add($cell, $instrument);
            }
            // 右端の追加列（2人目以降・自分で足した列）。楽器は全部の楽器から選べる
            foreach ((array)($rb['x'] ?? []) as $x) {
                if (is_array($x)) {
                    $add((string)($x['name'] ?? ''), $pick($x['inst'] ?? 0, default_instrument_id('Key'))); // 変な値ならキーボード
                }
            }
            attach_members($pdo, $memberIndex, $bandId, $assignments);
        }

        // ================= 3. アカウントとメンバーの自動紐付け =================
        // 先にアカウントだけ作っていた人を、今回できたメンバーと名前で紐付ける
        link_users_to_members($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack(); // 途中までの INSERT を全部なかったことにする
        throw $e;
    }
    return array_values(array_unique($liveIds));
}
