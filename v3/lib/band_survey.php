<?php
/**
 * =====================================================================
 *  lib/band_survey.php — バンド希望調査（卒業ライブのバンドの熱量を集計する）
 * =====================================================================
 *  名簿のファイルと LINE の回答を Claude（Haiku）で照合し、確認画面で直してから保存する。回答は機微情報なので、
 *  行（[バンド, メンバー, 順位|null]）の JSON を sched_ical_encrypt()（lib/sched_ical.php）で暗号化して DB に入れる。
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
 * 名簿のファイル（PDF / Excel / CSV。取り込みと同じ読み方）→ [['band' => 名前, 'members' => [名前, ...]], ...]
 *   同じ人は表記ゆれ（member_key）をそろえ、最初に出てきた書き方にする
 * @throws RuntimeException 読めない・名簿がない
 */
function band_survey_read_roster(string $path, string $name): array
{
    require_once __DIR__ . '/import/parsers.php';
    $bands = [];
    foreach (read_table_sheets($path, $name) as $rows) {
        if (detect_table_kind($rows) === 'roster') {
            foreach (parse_roster($rows)['bands'] as $b) {
                $bands[] = ['band' => $b['band_name'], 'members' => array_column($b['members'], 'name')];
            }
        }
    }
    if (!$bands) {
        throw new RuntimeException('名簿が見つかりませんでした');
    }
    return band_survey_tidy_roster($bands);
}

/** 名簿の名前をそろえる（同じ人は最初の書き方に、バンド内の重複は1人に） */
function band_survey_tidy_roster(array $bands): array
{
    require_once __DIR__ . '/import/text.php'; // member_key
    $seen = [];
    foreach ($bands as &$b) {
        $names = [];
        foreach ($b['members'] as $m) {
            $names[] = $seen[member_key($m)] ??= $m;
        }
        $b['members'] = array_values(array_unique($names));
    }
    unset($b);
    // 同じ名前のバンドが2つ以上あれば「ELLEGARDEN(箕浦英翔)」のように1人目を付けて分ける（順位はバンド名で持つため）
    $count = array_count_values(array_column($bands, 'band'));
    foreach ($bands as &$b) {
        if ($count[$b['band']] > 1 && $b['members']) {
            $b['band'] .= '(' . $b['members'][0] . ')';
        }
    }
    unset($b);
    return $bands;
}

const BAND_SURVEY_PROMPT = <<<'TXT'
下の「名簿」は卒業ライブのバンドとメンバー、「回答」は LINE で集めた「自分が組んでいるバンドの優先順位」です。回答ごとに、名簿に照合してください。

- member: 回答した人。LINE の表示名・署名・文中の名前から、名簿のメンバー名をそのまま書く。決められなければ空
- line_name: 回答に出てくるその人の表示名（そのまま）
- ranks: 書かれた順位ごとに written（書かれたバンド名そのまま）、band（名簿のバンド名をそのまま。略称・愛称・表記ゆれを寄せる。決められなければ空）、rank（順位の数字）
- 推測で埋めない。迷ったら空にする
TXT;

/**
 * Claude（Haiku）で回答を名簿に照合する。失敗したら文字列（画面に出せる理由）
 * @return array{ranks: array<string, array<string, int>>, sources: array<string, string>, written: array<string, array<string, string>>, issues: string[]}|string
 */
function band_survey_match(array $roster, string $answers): array|string
{
    require_once __DIR__ . '/claude.php';
    if (!claude_enabled()) {
        return 'Claude API が設定されていません';
    }
    $str = ['type' => 'string'];
    try {
        $json = claude_json(BAND_SURVEY_PROMPT, [[
            'type' => 'text',
            'text' => "# 名簿\n" . json_encode($roster, JSON_UNESCAPED_UNICODE) . "\n\n# 回答\n" . $answers,
        ]], [
            'type' => 'object',
            'properties' => ['answers' => ['type' => 'array', 'items' => [
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
            ]]],
            'required' => ['answers'],
            'additionalProperties' => false,
        ], 16000, 180, 'claude-haiku-5-5');
    } catch (RuntimeException $e) {
        return $e->getCode() === CLAUDE_ERR_MAX_TOKENS ? '回答が長すぎます。分けて追加してください' : '照合できませんでした。時間をおいて再試行してください';
    }
    return band_survey_apply($roster, is_array($json['answers'] ?? null) ? $json['answers'] : []);
}

/**
 * 照合の結果を名簿に当てはめる（名簿にない名前・バンドは信じずに issues へ）
 *   ranks[メンバー][バンド] = 順位、sources[メンバー] = LINE の表示名、written[メンバー][バンド] = 回答の書き方
 */
function band_survey_apply(array $roster, array $answers): array
{
    $bandsOf = [];
    foreach ($roster as $b) {
        foreach ($b['members'] as $m) {
            $bandsOf[$m][] = $b['band'];
        }
    }
    $out = ['ranks' => [], 'sources' => [], 'written' => [], 'issues' => []];
    foreach ($answers as $a) {
        $member = trim((string)($a['member'] ?? ''));
        $line = trim((string)($a['line_name'] ?? '')) ?: '名前なし';
        if (!isset($bandsOf[$member]) || isset($out['sources'][$member])) {
            $out['issues'][] = isset($out['sources'][$member])
                ? "LINE の「{$line}」: {$member} の回答が2つあります"
                : "LINE の「{$line}」: 名簿のメンバーに見つかりません";
            continue;
        }
        $out['sources'][$member] = $line;
        foreach (is_array($a['ranks'] ?? null) ? $a['ranks'] : [] as $r) {
            $band = trim((string)($r['band'] ?? ''));
            $written = trim((string)($r['written'] ?? ''));
            $rank = (int)($r['rank'] ?? 0);
            if (!in_array($band, $bandsOf[$member], true) || $rank < 1) {
                $out['issues'][] = "{$member}: 「{$written}」が {$member} のバンドに見つかりません";
                continue;
            }
            $out['ranks'][$member][$band] = $rank;
            $out['written'][$member][$band] = $written;
        }
    }
    return $out;
}

/** 1人の順位が 1, 2, 3… の連番か。rows = [[バンド, メンバー, 順位|null], ...] */
function band_survey_check_ranks(array $rows): array
{
    $ranks = [];
    foreach ($rows as [, $member, $rank]) {
        if ($rank !== null) {
            $ranks[$member][] = $rank;
        }
    }
    $errors = [];
    foreach ($ranks as $member => $list) {
        sort($list);
        if ($list !== range(1, count($list))) {
            $errors[] = $member . ': 順位が 1〜' . count($list) . ' の連番になっていません';
        }
    }
    return $errors;
}

/**
 * バンドごとの集計。熱量 = 各回答者の 1-(順位-1)/(n-1)（n = その人が順位を付けた数）の平均。n が 1 の人は入れない。
 * 熱量の高い順（熱量なしは最後）
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

/** 保存する（行の JSON を暗号化）。survey_id を返す */
function band_survey_save(PDO $pdo, string $title, array $rows, int $userId): int
{
    require_once __DIR__ . '/sched_ical.php';
    $enc = sched_ical_encrypt(json_encode($rows, JSON_UNESCAPED_UNICODE));
    $pdo->prepare('INSERT INTO band_survey (title, data_enc, created_by) VALUES (?, ?, ?)')->execute([$title, $enc, $userId]);
    return (int)$pdo->lastInsertId();
}

/** 一覧（中身は読まない） */
function band_survey_list(PDO $pdo): array
{
    return $pdo->query('SELECT survey_id, title, created_at FROM band_survey ORDER BY survey_id DESC')->fetchAll();
}

/** 1件を読む。無ければ null。復号に失敗したら rows = null */
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
    $rows = $plain === null ? null : json_decode($plain, true);
    return ['survey_id' => (int)$r['survey_id'], 'title' => $r['title'], 'created_at' => $r['created_at'], 'rows' => is_array($rows) ? $rows : null];
}

function band_survey_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM band_survey WHERE survey_id = ?')->execute([$id]);
}

/** 確認画面の並び: [[メンバー, [バンド, ...]], ...]（名簿に出てきた順） */
function band_survey_draft_members(array $roster): array
{
    $of = [];
    foreach ($roster as $b) {
        foreach ($b['members'] as $m) {
            $of[$m][] = $b['band'];
        }
    }
    return array_map(null, array_map('strval', array_keys($of)), array_values($of)); // 「171」のような名前は配列のキーで数値になるので文字列に戻す
}
