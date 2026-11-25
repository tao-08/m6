<?php
/**
 * =====================================================================
 *  lib/band_survey.php — バンド希望調査（卒業ライブのバンドの熱量を集計する）
 * =====================================================================
 *  CSV（バンド,メンバー,順位）を読み、バンドごとの熱量を出す。回答は機微情報なので、
 *  行の JSON を sched_ical_encrypt()（lib/sched_ical.php）で暗号化して DB に入れる。
 *  parse / summarize は DB に触れない純粋関数（tests/run.php でテストする）。
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
 * CSV を読む。rows = [[バンド, メンバー, 順位(int|null)], ...]
 * @return array{rows: array<int, array{0: string, 1: string, 2: ?int}>, errors: string[]}
 */
function band_survey_parse_csv(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $fp = fopen('php://memory', 'r+');
    fwrite($fp, $text);
    rewind($fp);
    $rows = [];
    $errors = [];
    $line = 0;
    while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        $line++;
        if ($r === [null] || trim(implode('', $r)) === '') {
            continue; // 空行
        }
        if ($line === 1 && str_contains((string)$r[0], 'バンド')) {
            continue; // 見出し
        }
        $band = trim((string)($r[0] ?? ''));
        $member = trim((string)($r[1] ?? ''));
        $rankRaw = trim((string)($r[2] ?? ''));
        if ($band === '' || $member === '') {
            $errors[] = "{$line}行目: バンドかメンバーが空です";
            continue;
        }
        if ($rankRaw === '') {
            $rank = null;
        } elseif (ctype_digit($rankRaw) && (int)$rankRaw > 0) {
            $rank = (int)$rankRaw;
        } else {
            $errors[] = "{$line}行目: 順位が正の整数ではありません";
            continue;
        }
        $rows[] = [$band, $member, $rank];
    }
    fclose($fp);

    $ranks = [];
    foreach ($rows as [, $member, $rank]) {
        if ($rank !== null) {
            $ranks[$member][] = $rank;
        }
    }
    foreach ($ranks as $member => $list) {
        sort($list);
        if ($list !== range(1, count($list))) {
            $errors[] = $member . ': 順位が 1〜' . count($list) . ' の連番になっていません';
        }
    }
    return ['rows' => $rows, 'errors' => $errors];
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
