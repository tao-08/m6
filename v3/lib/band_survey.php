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

// 見られる人の入部年度。来年はここを書き換える
const BAND_SURVEY_ENTRY_YEAR = 2023;

/** 見てよい人か: 幹部 + 入部年度が合う + 暗号化の鍵があるサーバー */
function band_survey_can_view(): bool
{
    $user = current_user();
    if (!is_executive() || empty($user['member_id'])) {
        return false;
    }
    require_once __DIR__ . '/sched_ical.php';
    if (sched_ical_key() === null) {
        return false;
    }
    $st = db()->prepare('SELECT entry_year FROM member WHERE member_id = ?');
    $st->execute([(int)$user['member_id']]);
    return (int)$st->fetchColumn() === BAND_SURVEY_ENTRY_YEAR;
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
                $cells[$at] = split_member_names((string)($b['cells'][$c] ?? ''));
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
            $cell = array_map(static function ($m) use (&$seen) { return $seen[member_key($m)] ??= $m; }, $cell);
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

/** バンドのメンバー（名簿の左の列から。同じ人は1回） */
function band_survey_band_members(array $band): array
{
    return array_values(array_unique(array_map('strval', array_merge(...array_values($band['cells'])))));
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
 *   $member … 確認画面で選び直した人（null なら AI の答え）
 * @return array{member: string, ranks: array<string, int>, written: array<string, string>, issues: string[]}
 */
function band_survey_apply(array $roster, array $ai, ?string $member = null): array
{
    $people = array_column(band_survey_people($roster), 1, 0);
    $member ??= (string)$ai['member'];
    $out = ['member' => '', 'ranks' => [], 'written' => [], 'issues' => []];
    if (!isset($people[$member])) {
        $out['issues'][] = '回答した人が名簿に見つかりません';
        return $out;
    }
    $out['member'] = $member;
    foreach ($ai['ranks'] as $r) {
        $band = trim((string)($r['band'] ?? ''));
        $written = trim((string)($r['written'] ?? ''));
        $rank = (int)($r['rank'] ?? 0);
        if (!in_array($band, $people[$member], true) || $rank < 1) {
            $out['issues'][] = "「{$written}」が {$member} のバンドに見つかりません";
            continue;
        }
        $out['ranks'][$band] = $rank;
        $out['written'][$band] = $written;
    }
    return $out;
}

/** 1人の順位（[バンド => 順位]）が 1, 2, 3… の連番か。だめなら理由 */
function band_survey_check_ranks(array $ranks): ?string
{
    $list = array_values($ranks);
    sort($list);
    return $list === range(1, count($list)) || $list === [] ? null : '順位が 1〜' . count($list) . ' の連番になっていません';
}

/** 集計用の行 [[バンド, メンバー, 順位|null], ...]。$data = ['roster' => 名簿, 'ranks' => [メンバー => [バンド => 順位]]] */
function band_survey_rows(array $data): array
{
    $rows = [];
    foreach ($data['roster']['bands'] as $b) {
        foreach (band_survey_band_members($b) as $m) {
            $rows[] = [$b['band'], $m, $data['ranks'][$m][$b['band']] ?? null];
        }
    }
    return $rows;
}

/** 1人の1バンドの優先度（1位 = 1、最下位 = 0）。順位がなければ null。1バンドしか付けていない人は 1 */
function band_survey_score(?int $rank, int $n): ?float
{
    if ($rank === null) {
        return null;
    }
    return $n < 2 ? 1.0 : 1 - ($rank - 1) / ($n - 1);
}

/**
 * バンドごとの集計。優先度（heat）= 各回答者の 1-(順位-1)/(n-1)（n = その人が順位を付けた数）の平均。n が 1 の人は入れない。
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
            if ($mn >= 2) {
                $b['scores'][] = 1 - ($rank - 1) / ($mn - 1);
                $b['low'] = $b['low'] && $rank > $mn / 2; // 奇数の真ん中は下位に数える
            }
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
    $enc = sched_ical_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE));
    $pdo->prepare('INSERT INTO band_survey (title, data_enc, created_by) VALUES (?, ?, ?)')->execute([$title, $enc, $userId]);
    return (int)$pdo->lastInsertId();
}

/** 中身を書きかえる（回答を足したとき） */
function band_survey_update(PDO $pdo, int $id, array $data): void
{
    require_once __DIR__ . '/sched_ical.php';
    $enc = sched_ical_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE));
    $pdo->prepare('UPDATE band_survey SET data_enc = ? WHERE survey_id = ?')->execute([$enc, $id]);
}

/** 一覧（中身は読まない） */
function band_survey_list(PDO $pdo): array
{
    return $pdo->query('SELECT survey_id, title, created_at FROM band_survey ORDER BY survey_id DESC')->fetchAll();
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
    return ['survey_id' => (int)$r['survey_id'], 'title' => $r['title'], 'created_at' => $r['created_at'], 'data' => $ok ? $data + ['ranks' => []] : null];
}

function band_survey_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM band_survey WHERE survey_id = ?')->execute([$id]);
}
