<?php
/**
 * =====================================================================
 *  lib/band_survey.php — バンド希望調査（卒業ライブのバンドの優先度を集計する）
 * =====================================================================
 *  名簿のファイルを読んで調査を作り、LINE の回答を1人ずつ Claude（Haiku）で照合して、確認してから足す。回答は機微情報なので、
 *  名簿と順位の JSON を sched_ical_encrypt()（lib/sched_ical.php）で暗号化して DB に入れる。
 *  apply / check_ranks / summarize は DB に触れない純粋関数（tests/run.php でテストする）。
 * =====================================================================
 */
declare(strict_types=1);

// 名簿の「未定」のセル。人としては数えず、表に「未定」と出す（あとから人を入れる所）
const BAND_SURVEY_TBD = '未定';

/** 暗号化の鍵があるサーバーか（ないと回答を読み書きできない） */
function band_survey_ready(): bool
{
    require_once __DIR__ . '/sched_ical.php';
    return sched_ical_key() !== null;
}

/** 幹部か。is_executive() と違って管理者を含めない（管理者でも、幹部でなければ調査は見られない） */
function band_survey_is_exec(): bool
{
    return !empty(current_user()['executive']);
}

/** 新しい調査を作れる人か: 幹部 */
function band_survey_can_create(): bool
{
    return band_survey_is_exec() && band_survey_ready();
}

/**
 * 名簿のファイル（PDF / Excel / CSV。取り込みと同じ読み方）→ 名簿
 *   ['columns' => [パートの見出し, ...], 'bands' => [['band' => 名前, 'cells' => [[名前, ...], ...]（columns と同じ順）], ...]]
 * @throws RuntimeException 読めない・名簿がない
 */
function band_survey_read_roster(string $path, string $name): array
{
    require_once __DIR__ . '/import/parsers.php';
    $columns = [];
    $bands = [];
    foreach (read_table_sheets($path, $name) as $rows) {
        if (detect_table_kind($rows) !== 'roster') {
            continue;
        }
        $r = parse_roster($rows);
        $map = []; // シートの列番号 → columns の番号（シートが違っても見出しが同じなら同じ列）
        foreach ($r['columns'] as $c => $col) {
            $at = array_search($col['title'], $columns, true);
            if ($at === false) {
                $columns[] = $col['title'];
                $at = count($columns) - 1;
            }
            $map[$c] = $at;
        }
        foreach ($r['bands'] as $b) {
            $cells = [];
            foreach ($map as $c => $at) {
                $names = split_member_names((string)($b['cells'][$c] ?? ''));
                $cells[$at] = $names ?: (str_contains((string)($b['raw'][$c] ?? ''), BAND_SURVEY_TBD) ? [BAND_SURVEY_TBD] : []);
            }
            $bands[] = ['band' => $b['band_name'], 'cells' => $cells];
        }
    }
    if (!$bands) {
        throw new RuntimeException('名簿が見つかりませんでした');
    }
    foreach ($bands as &$b) { // 列が後から増えたシートの分も、すべての列をそろえる
        $b['cells'] = array_replace(array_fill(0, count($columns), []), $b['cells']);
    }
    unset($b);
    return band_survey_tidy_roster(['columns' => $columns, 'bands' => $bands]);
}

/** 名簿の名前をそろえる（同じ人は最初の書き方に）。同じ名前のバンドは「ELLEGARDEN(1人目)」に（順位はバンド名で持つため） */
function band_survey_tidy_roster(array $roster): array
{
    require_once __DIR__ . '/import/text.php'; // member_key
    $seen = [];
    foreach ($roster['bands'] as &$b) {
        foreach ($b['cells'] as &$cell) {
            $cell = array_map(static function ($m) use (&$seen) { return $m === BAND_SURVEY_TBD ? $m : ($seen[member_key($m)] ??= $m); }, $cell);
        }
        unset($cell);
    }
    unset($b);
    $count = array_count_values(array_column($roster['bands'], 'band'));
    foreach ($roster['bands'] as &$b) {
        $first = band_survey_band_members($b)[0] ?? null;
        if ($count[$b['band']] > 1 && $first !== null) {
            $b['band'] .= '(' . $first . ')';
        }
    }
    unset($b);
    return $roster;
}

/** バンドのメンバー（名簿の左の列から。同じ人は1回。「未定」は入れない） */
function band_survey_band_members(array $band): array
{
    $all = array_map('strval', array_merge(...array_values($band['cells'])));
    return array_values(array_unique(array_filter($all, static fn($m) => $m !== BAND_SURVEY_TBD)));
}

/** 人ごとのバンド: [[メンバー, [バンド, ...]], ...]（名簿に出てきた順） */
function band_survey_people(array $roster): array
{
    $of = [];
    foreach ($roster['bands'] as $b) {
        foreach (band_survey_band_members($b) as $m) {
            $of[$m][] = $b['band'];
        }
    }
    return array_map(null, array_map('strval', array_keys($of)), array_values($of)); // 「171」のような名前は配列のキーで数値になるので文字列に戻す
}

/** 名簿の人のうち、DB で入部年度が $entryYear の人（対象のチェックの初期値） */
function band_survey_default_targets(PDO $pdo, array $roster, int $entryYear): array
{
    require_once __DIR__ . '/import/text.php'; // member_key
    $st = $pdo->prepare('SELECT name FROM member WHERE entry_year = ?');
    $st->execute([$entryYear]);
    $keys = array_flip(array_map('member_key', $st->fetchAll(PDO::FETCH_COLUMN)));
    return array_values(array_filter(array_column(band_survey_people($roster), 0), static fn($m) => isset($keys[member_key($m)])));
}

/** 名前を置きかえる（$map = [元の名前 => 新しい名前]）。書き間違いを直すと同じ人にまとまる */
function band_survey_rename(array $roster, array $map): array
{
    foreach ($roster['bands'] as &$b) {
        foreach ($b['cells'] as &$cell) {
            $cell = array_values(array_unique(array_map(static fn($m) => (string)($map[$m] ?? $m), $cell)));
        }
        unset($cell);
    }
    unset($b);
    return $roster;
}

const BAND_SURVEY_PROMPT = <<<'TXT'
下の「名簿」は卒業ライブのバンドとメンバー、「回答」は LINE で集めた、1人分の「自分が組んでいるバンドの優先順位」です。名簿に照合してください。

- member: 回答した人。LINE の表示名・署名・文中の名前から、名簿のメンバー名をそのまま書く。決められなければ空
- line_name: 回答に出てくるその人の表示名（そのまま。なければ空）
- ranks: 書かれた順位ごとに written（書かれたバンド名そのまま）、band（名簿のバンド名をそのまま。略称・愛称・表記ゆれを寄せ、同じ名前のバンドが複数あれば member がいるほう。決められなければ空）、rank（順位の数字）
- 推測で埋めない。迷ったら空にする
TXT;

/**
 * Claude（Haiku）で1人分の回答を名簿に照合する。返すのは AI の答えそのまま（band_survey_apply で名簿に当てはめる）。失敗したら文字列（画面に出せる理由）
 * @return array{member: string, line_name: string, ranks: array}|string
 */
function band_survey_match(array $roster, string $answer): array|string
{
    require_once __DIR__ . '/claude.php';
    if (!claude_enabled()) {
        return 'Claude API が設定されていません';
    }
    $list = array_map(static fn($b) => ['band' => $b['band'], 'members' => band_survey_band_members($b)], $roster['bands']);
    $str = ['type' => 'string'];
    try {
        $json = claude_json(BAND_SURVEY_PROMPT, [[
            'type' => 'text',
            'text' => "# 名簿\n" . json_encode($list, JSON_UNESCAPED_UNICODE) . "\n\n# 回答\n" . $answer,
        ]], [
            'type' => 'object',
            'properties' => [
                'member' => $str,
                'line_name' => $str,
                'ranks' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['written' => $str, 'band' => $str, 'rank' => ['type' => 'integer']],
                    'required' => ['written', 'band', 'rank'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['member', 'line_name', 'ranks'],
            'additionalProperties' => false,
        ], 4000, 120, 'claude-haiku-5-5');
    } catch (RuntimeException $e) {
        return $e->getCode() === CLAUDE_ERR_MAX_TOKENS ? '回答が長すぎます' : '照合できませんでした。時間をおいて再試行してください';
    }
    return [
        'member' => trim((string)($json['member'] ?? '')),
        'line_name' => trim((string)($json['line_name'] ?? '')),
        'ranks' => is_array($json['ranks'] ?? null) ? $json['ranks'] : [],
    ];
}

/**
 * AI の答えを名簿に当てはめる（名簿にない名前・その人のバンドでないものは信じずに issues へ）
 *   $member … 確認画面で選び直した人（null なら AI の答え）/ $targets … 調査の対象の人（null ならすべて）
 * @return array{member: string, ranks: array<string, int>, written: array<string, string>, issues: string[], candidates: string[]}
 */
function band_survey_apply(array $roster, array $ai, ?string $member = null, ?array $targets = null): array
{
    $people = array_column(band_survey_people($roster), 1, 0);
    $out = ['member' => '', 'ranks' => [], 'written' => [], 'issues' => [], 'candidates' => []];
    if ($member === null) { // AI の答え。名前で決まらなければ、回答に書かれたバンドから決める
        $guess = band_survey_guess_member($people, $ai['ranks'], $targets, (string)$ai['member']);
        if (count($guess) !== 1) {
            $out['issues'][] = '回答した人を1人に決められません';
            $out['candidates'] = $guess;
            return $out;
        }
        $member = $guess[0];
    }
    if (!isset($people[$member]) || ($targets !== null && !in_array($member, $targets, true))) {
        $out['issues'][] = isset($people[$member]) ? "{$member} は調査の対象ではありません" : '回答した人が名簿に見つかりません';
        return $out;
    }
    $out['member'] = $member;
    foreach ($ai['ranks'] as $r) {
        $band = trim((string)($r['band'] ?? ''));
        $written = trim((string)($r['written'] ?? ''));
        $rank = (int)($r['rank'] ?? 0);
        $band = band_survey_find_band($people[$member], $band, $written);
        if ($band === null || $rank < 1) {
            $out['issues'][] = "「{$written}」が {$member} のバンドに見つかりません";
            continue;
        }
        $out['ranks'][$band] = $rank;
        $out['written'][$band] = $written;
    }
    return $out;
}

/**
 * AI が答えたバンドを、その人のバンド（$bands）から決める。
 *   同じ名前のバンドは名簿で「King Gnu(岩﨑太一)」と分けているため、AI が「King Gnu」と答えることがある。
 *   完全一致がなければ、大文字・小文字・空白と、名簿の側の後ろの (…) を無視して比べ、1つに決まれば使う（2つ以上なら決めない）
 */
function band_survey_find_band(array $bands, string ...$names): ?string
{
    $key = static fn(string $s) => mb_strtolower(preg_replace('/\s+/u', '', $s));
    $base = static fn(string $s) => $key(preg_replace('/[(（][^()（）]*[)）]$/u', '', $s)); // 名簿の側だけ (…) を外す（AI が別の「B(鈴木)」を答えたら取り違え）
    foreach ($names as $name) {
        if (in_array($name, $bands, true)) {
            return $name;
        }
    }
    foreach ($names as $name) {
        $hit = array_values(array_filter($bands, static fn($b) => $name !== '' && in_array($key($name), [$key((string)$b), $base((string)$b)], true)));
        if (count($hit) === 1) {
            return (string)$hit[0];
        }
    }
    return null;
}

/**
 * 回答した人を決める。回答にはその人のバンドがすべて書かれるので、書かれたバンドが最も多く当てはまる人を選ぶ。
 *   同点なら、バンドの数が書かれた数に近い人。AI が答えた人がその最上位にいれば、その人
 * @return string[] 決まれば1人、決まらなければ候補（最大3人。当てはまるバンドが0の人は入れない）
 */
function band_survey_guess_member(array $people, array $ranks, ?array $targets, string $aiMember = ''): array
{
    $n = count($ranks);
    $score = [];
    foreach ($people as $m => $bands) {
        $m = (string)$m;
        $hit = 0;
        foreach ($ranks as $r) {
            $hit += band_survey_find_band($bands, trim((string)($r['band'] ?? '')), trim((string)($r['written'] ?? ''))) !== null ? 1 : 0;
        }
        if ($hit > 0) {
            $score[$m] = [$hit, -abs(count($bands) - $n)];
        }
    }
    uasort($score, static fn($a, $b) => $b <=> $a);
    $top = reset($score);
    if (isset($score[$aiMember]) && $score[$aiMember][0] === $top[0]) {
        return [$aiMember]; // 対象でなければ、呼び出し側で「対象ではありません」と出す
    }
    if ($targets !== null) {
        $score = array_intersect_key($score, array_flip(array_map('strval', $targets)));
        $top = reset($score);
    }
    if (!$score) {
        return [];
    }
    $best = array_map('strval', array_keys(array_filter($score, static fn($s) => $s === $top))); // 「171」など数字の名前はキーで int になる
    return count($best) === 1 ? $best : array_slice(array_map('strval', array_keys($score)), 0, 3);
}

/** 名簿にもういる人なら、その書き方にそろえる（表記ゆれで別の人にならないように） */
function band_survey_canonical(array $roster, string $name): string
{
    require_once __DIR__ . '/import/text.php'; // member_key
    foreach (band_survey_people($roster) as [$m]) {
        if (member_key($m) === member_key($name)) {
            return $m;
        }
    }
    return $name;
}

/** 順位を、並びはそのままで 1, 2, 3… に詰める（バンドから外れて順位が抜けたとき） */
function band_survey_pack_ranks(array $ranks): array
{
    asort($ranks);
    return $ranks ? array_combine(array_keys($ranks), range(1, count($ranks))) : [];
}

/**
 * 名簿・順位・対象を、名簿の今の中身に合わせて片付ける。
 *   その人がもういないバンドの順位を消して詰め、どのバンドにもいない人は対象と順位から外す
 */
function band_survey_tidy_data(array $data): array
{
    $people = array_column(band_survey_people($data['roster']), 1, 0);
    $data['targets'] = array_values(array_filter($data['targets'], static fn($m) => isset($people[$m])));
    foreach ($data['ranks'] as $m => $ranks) {
        $kept = isset($people[$m]) ? array_intersect_key($ranks, array_flip($people[$m])) : [];
        if ($kept) {
            $data['ranks'][$m] = band_survey_pack_ranks($kept);
        } else {
            unset($data['ranks'][$m]);
        }
    }
    return $data;
}

/**
 * 表の1セルを変える（$bi = バンドの番号、$ci = 列の番号）。
 *   $old = '' か「未定」なら $new を足す / $new = '' なら $old を外す / 両方あれば入れ替える
 *   $target … $new を調査の対象にするか
 */
function band_survey_set_cell(array $data, int $bi, int $ci, string $old, string $new, bool $target): array
{
    if (!isset($data['roster']['bands'][$bi]['cells'][$ci])) {
        return $data;
    }
    $new = $new === '' ? '' : band_survey_canonical($data['roster'], $new);
    $cell = &$data['roster']['bands'][$bi]['cells'][$ci];
    $cell = array_values(array_filter($cell, static fn($m) => $m !== BAND_SURVEY_TBD && ($old === '' || $m !== $old)));
    if ($new !== '' && !in_array($new, $cell, true)) {
        $cell[] = $new;
    }
    unset($cell);
    if ($new !== '') {
        $data['targets'] = array_values(array_diff($data['targets'], [$new]));
        if ($target) {
            $data['targets'][] = $new;
        }
    }
    return band_survey_tidy_data($data);
}

/**
 * 名簿を読み直した調査: 新しい名簿・対象に、前の回答のうち人とバンドが同じものを残す
 */
function band_survey_merge(array $old, array $roster, array $targets): array
{
    return band_survey_tidy_data(['roster' => $roster, 'targets' => $targets, 'ranks' => $old['ranks']] + $old);
}

/** 1人の順位（[バンド => 順位]）が 1, 2, 3… の連番か。だめなら理由 */
function band_survey_check_ranks(array $ranks): ?string
{
    $list = array_values($ranks);
    sort($list);
    return $list === range(1, count($list)) || $list === [] ? null : '順位が 1〜' . count($list) . ' の連番になっていません';
}

/** 集計用の行 [[バンド, メンバー, 順位|null], ...]。対象の人だけ。$data = ['roster' => 名簿, 'targets' => [メンバー, ...], 'ranks' => [メンバー => [バンド => 順位]]] */
function band_survey_rows(array $data): array
{
    $rows = [];
    $ranks = band_survey_live_ranks($data);
    $off = array_flip($data['off'] ?? []);
    foreach ($data['roster']['bands'] as $b) {
        if (isset($off[$b['band']])) {
            continue;
        }
        foreach (array_intersect(band_survey_band_members($b), $data['targets']) as $m) {
            $rows[] = [$b['band'], $m, $ranks[$m][$b['band']] ?? null];
        }
    }
    return $rows;
}

/**
 * 一時削除したバンド（$data['off']）を抜いて、残りを 1, 2, 3… に詰めた順位。表と集計に使う（保存している順位は変えない）
 *   例: 1位のバンドを一時削除すると、2位だったバンドを1位として数える
 */
function band_survey_live_ranks(array $data): array
{
    $off = array_flip($data['off'] ?? []);
    $out = [];
    foreach ($data['ranks'] as $m => $ranks) {
        $kept = array_diff_key($ranks, $off);
        if ($kept) {
            $out[$m] = band_survey_pack_ranks($kept);
        }
    }
    return $out;
}

/** バンドの一時削除を付ける / 外す */
function band_survey_set_off(array $data, string $band, bool $on): array
{
    $off = array_values(array_diff($data['off'] ?? [], [$band]));
    if ($on && in_array($band, array_column($data['roster']['bands'], 'band'), true)) {
        $off[] = $band;
    }
    $data['off'] = $off;
    return $data;
}

/** 優先度の計算に使う母数。4バンド未満の人は 4 として数える（出演が少ない人の最下位が 0 にならないように） */
const BAND_SURVEY_MIN_BASE = 4;

/** 1人の1バンドの優先度（1位 = 1、母数の最下位 = 0）。順位がなければ null。$n = その人が順位を付けた数 */
function band_survey_score(?int $rank, int $n): ?float
{
    if ($rank === null) {
        return null;
    }
    return 1 - ($rank - 1) / (max($n, BAND_SURVEY_MIN_BASE) - 1);
}

/**
 * バンドごとの集計。優先度（heat）= 各回答者の band_survey_score の平均（母数は最低 4）。
 * 優先度の高い順（優先度なしは最後）
 */
function band_survey_summarize(array $rows): array
{
    $n = [];
    foreach ($rows as [, $member, $rank]) {
        if ($rank !== null) {
            $n[$member] = ($n[$member] ?? 0) + 1;
        }
    }
    $bands = [];
    foreach ($rows as [$band, $member, $rank]) {
        $bands[$band] ??= ['band' => $band, 'total' => 0, 'answered' => 0, 'scores' => [], 'low' => true, 'members' => []];
        $b = &$bands[$band];
        $b['total']++;
        $mn = $n[$member] ?? 0;
        if ($rank !== null) {
            $b['answered']++;
            $b['scores'][] = band_survey_score($rank, $mn);
            $b['low'] = $b['low'] && $rank > max($mn, BAND_SURVEY_MIN_BASE) / 2; // 奇数の真ん中は下位に数える
        }
        $b['members'][] = ['member' => $member, 'rank' => $rank, 'n' => $mn];
        unset($b);
    }
    $out = [];
    foreach ($bands as $b) {
        $has = $b['scores'] !== [];
        $out[] = [
            'band' => $b['band'], 'total' => $b['total'], 'answered' => $b['answered'],
            'heat' => $has ? (float)(array_sum($b['scores']) / count($b['scores'])) : null,
            'low_all' => $has && $b['low'],
            'members' => $b['members'],
        ];
    }
    usort($out, static fn($a, $b) => [$a['heat'] === null, -(float)$a['heat']] <=> [$b['heat'] === null, -(float)$b['heat']]);
    return $out;
}

/** 保存する（$data = ['roster' => 名簿, 'ranks' => 順位] の JSON を暗号化）。survey_id を返す */
function band_survey_save(PDO $pdo, string $title, array $data, int $userId): int
{
    require_once __DIR__ . '/sched_ical.php';
    $enc = sched_ical_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $pdo->prepare('INSERT INTO band_survey (title, data_enc, created_by) VALUES (?, ?, ?)')->execute([$title, $enc, $userId]);
    $id = (int)$pdo->lastInsertId();
    // 最初の閲覧可能者は作った人だけ（個別の閲覧可能者に入れるので、あとから外せる）
    $memberId = (int)(current_user()['member_id'] ?? 0);
    if ($memberId > 0) {
        $pdo->prepare('INSERT INTO band_survey_viewer (survey_id, member_id) VALUES (?, ?)')->execute([$id, $memberId]);
    }
    return $id;
}

/** 中身を書きかえる（回答を足したとき） */
function band_survey_update(PDO $pdo, int $id, array $data): void
{
    require_once __DIR__ . '/sched_ical.php';
    $enc = sched_ical_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $pdo->prepare('UPDATE band_survey SET data_enc = ? WHERE survey_id = ?')->execute([$enc, $id]);
}

/** 一覧（中身は読まない） */
function band_survey_list(PDO $pdo): array
{
    // 見られるのは: 個別の閲覧可能者（作った人も最初はここに入る。外せる）/ 入部年度が合う人（view_role = exec なら幹部だけ）/ 年度なしで exec ならすべての幹部
    $user = current_user();
    $memberId = (int)($user['member_id'] ?? 0);
    $year = 0;
    if ($memberId > 0) {
        $st = $pdo->prepare('SELECT entry_year FROM member WHERE member_id = ?');
        $st->execute([$memberId]);
        $year = (int)$st->fetchColumn();
    }
    $st = $pdo->prepare("SELECT s.survey_id, s.title, s.created_at FROM band_survey s
        WHERE EXISTS (SELECT 1 FROM band_survey_viewer v WHERE v.survey_id = s.survey_id AND v.member_id = ?)
           OR ((s.view_role = 'all' OR ?) AND (
                  EXISTS (SELECT 1 FROM band_survey_view_year y WHERE y.survey_id = s.survey_id AND y.entry_year = ?)
               OR (s.view_role = 'exec' AND NOT EXISTS (SELECT 1 FROM band_survey_view_year y WHERE y.survey_id = s.survey_id))))
        ORDER BY s.survey_id DESC");
    $st->execute([$memberId, band_survey_is_exec() ? 1 : 0, $year]);
    return $st->fetchAll();
}

/** その調査を見られる（すべての操作ができる）か */
function band_survey_can_open(PDO $pdo, int $id): bool
{
    return band_survey_ready() && in_array($id, array_map('intval', array_column(band_survey_list($pdo), 'survey_id')), true);
}

/** 閲覧可能者: ['role' => all|exec, 'years' => [年度...], 'members' => [member_id => 名前], 'creator' => 作った人の名前|null] */
function band_survey_access(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT s.view_role, m.name FROM band_survey s
        LEFT JOIN user_account u ON u.user_id = s.created_by LEFT JOIN member m ON m.member_id = u.member_id WHERE s.survey_id = ?');
    $st->execute([$id]);
    $r = $st->fetch() ?: ['view_role' => 'all', 'name' => null];
    $st = $pdo->prepare('SELECT entry_year FROM band_survey_view_year WHERE survey_id = ? ORDER BY entry_year DESC');
    $st->execute([$id]);
    $years = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare('SELECT m.member_id, m.name FROM band_survey_viewer v JOIN member m ON m.member_id = v.member_id WHERE v.survey_id = ? ORDER BY m.name');
    $st->execute([$id]);
    return ['role' => $r['view_role'], 'years' => $years, 'members' => $st->fetchAll(PDO::FETCH_KEY_PAIR), 'creator' => $r['name']];
}

/** 調査名と閲覧可能者を保存する */
function band_survey_save_access(PDO $pdo, int $id, string $title, string $role, array $years, array $memberIds): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE band_survey SET title = ?, view_role = ? WHERE survey_id = ?')->execute([$title, $role === 'exec' ? 'exec' : 'all', $id]);
        $pdo->prepare('DELETE FROM band_survey_view_year WHERE survey_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO band_survey_view_year (survey_id, entry_year) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $years)) as $y) {
            $ins->execute([$id, $y]);
        }
        $pdo->prepare('DELETE FROM band_survey_viewer WHERE survey_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO band_survey_viewer (survey_id, member_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $memberIds)) as $m) {
            $ins->execute([$id, $m]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 1件を読む。無ければ null。復号できない・前の形式なら data = null */
function band_survey_load(PDO $pdo, int $id): ?array
{
    require_once __DIR__ . '/sched_ical.php';
    $st = $pdo->prepare('SELECT survey_id, title, data_enc, created_at FROM band_survey WHERE survey_id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $plain = sched_ical_decrypt($r['data_enc']);
    $data = $plain === null ? null : json_decode($plain, true);
    $ok = is_array($data) && isset($data['roster']['bands']);
    if ($ok) {
        $data += ['ranks' => [], 'targets' => array_column(band_survey_people($data['roster']), 0)];
    }
    return ['survey_id' => (int)$r['survey_id'], 'title' => $r['title'], 'created_at' => $r['created_at'], 'data' => $ok ? $data : null];
}

function band_survey_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM band_survey WHERE survey_id = ?')->execute([$id]);
}
