<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  sched_calendar.php — 学年暦の PDF を AI（Claude）に読ませて、学期・休業の日付にする
 * =====================================================================
 *  流れ: PDF をそのまま Claude に送る（文字のPDFも画像のPDFも読める）→ 形を固定した JSON → 検証して返す
 *  ※ AI の読み間違いはある。結果は DB に直接入れず、設定画面のフォームに入れて、人が確認してから保存する
 * =====================================================================
 */
require_once __DIR__ . '/claude.php';

/** 学年暦 PDF の大きさの上限（バイト） */
const SCHED_CAL_MAX_BYTES = 5 * 1024 * 1024;

const SCHED_CAL_PROMPT = <<<'PROMPT'
あなたは大学の学年暦（年間の academic calendar）の PDF を読み取り、時間割が効く「授業期間」を取り出す担当です。

## 取り出すもの
- terms: 春学期（term = 1）と秋学期（term = 2）の授業期間。from = 授業開始日、to = 授業最終日（YYYY-MM-DD）
  - 試験期間・補講期間・入学式・オリエンテーションは含めない。秋学期は翌年にまたがる（例 2026-09-20 〜 2027-01-23）
- breaks: 授業期間の中にある、授業が無い期間。from / to（YYYY-MM-DD）。1日だけなら from = to
  - 入れる: 冬季休業、臨時休業（休講）日、大学祭週間（全学休講の期間。明大祭・生明祭など個別の日程ではなく「大学祭週間」の期間）
  - 入れない: 祝日・振替休日（祝日も授業がある。授業が無いと書かれた祝日だけ、臨時休業として入れる）、休日授業実施日・補講日（授業がある日）、学期の外の休業（夏季休業・春季休業）
  - 日が続くものは1つにまとめる（例 臨時休業 12/23〜12/24 と冬季休業 12/25〜1/7 → 12/23〜1/7）

## 決まり
- 年は PDF の年度から補って、必ず YYYY-MM-DD で書く
- 学部・キャンパスごとに日付が違う場合は、先頭（全学共通、または最初に載っているもの）を採用し、notes に書く
- 読めない・自信のない日付は推測で埋めず、近い値を書いて notes に書く
- notes は人が確認する必要があることだけ。1件 = 1行・40文字くらいまで。敬語で書く
- 学年暦に見えない PDF なら terms は空にして、notes に理由を書く
PROMPT;

function sched_cal_schema(): array
{
    $range = [
        'type' => 'object',
        'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']],
        'required' => ['from', 'to'],
        'additionalProperties' => false,
    ];
    return [
        'type' => 'object',
        'properties' => [
            'terms' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['term' => ['type' => 'integer', 'enum' => [1, 2]], 'from' => ['type' => 'string'], 'to' => ['type' => 'string']],
                'required' => ['term', 'from', 'to'],
                'additionalProperties' => false,
            ]],
            'breaks' => ['type' => 'array', 'items' => $range],
            'notes'  => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['terms', 'breaks', 'notes'],
        'additionalProperties' => false,
    ];
}

/** YYYY-MM-DD として実在する日付か */
function sched_cal_is_date(string $d): bool
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * AI の返事（JSON を配列にしたもの）を検証して、設定の形にそろえる。AI を呼ばない部分（テストできる）
 *   日付が壊れている・逆順の行は捨てる。春・秋の両方が揃わなければ例外
 * @return array{terms: array<int, array{0:string,1:string}>, breaks: array<int, array{0:string,1:string}>, notes: string[]}
 */
function sched_cal_normalize(array $data): array
{
    $terms = [];
    foreach ((array)($data['terms'] ?? []) as $t) {
        $n = (int)($t['term'] ?? 0);
        $f = (string)($t['from'] ?? '');
        $to = (string)($t['to'] ?? '');
        if (($n === 1 || $n === 2) && !isset($terms[$n]) && sched_cal_is_date($f) && sched_cal_is_date($to) && $to >= $f) {
            $terms[$n] = [$f, $to];
        }
    }
    if (count($terms) !== 2) {
        $notes = array_filter(array_map('strval', (array)($data['notes'] ?? [])));
        throw new RuntimeException('春学期・秋学期の期間を読み取れませんでした' . ($notes ? '（' . implode(' / ', $notes) . '）' : '') . '。学年暦のPDFか確認してください');
    }
    ksort($terms);
    $breaks = [];
    foreach ((array)($data['breaks'] ?? []) as $b) {
        $f = (string)($b['from'] ?? '');
        $to = (string)($b['to'] ?? '');
        if (sched_cal_is_date($f) && sched_cal_is_date($to) && $to >= $f) {
            $breaks[] = [$f, $to];
        }
    }
    $notes = array_values(array_filter(array_map(static fn($n) => trim((string)$n), (array)($data['notes'] ?? [])), static fn($n) => $n !== ''));
    $breaks = sched_cal_merge_ranges($breaks);
    if (count($breaks) > SCHED_MAX_BREAKS) {
        $notes[] = '休業が' . SCHED_MAX_BREAKS . '件を超えたため、後ろの' . (count($breaks) - SCHED_MAX_BREAKS) . '件は入れていません';
    }
    return ['terms' => $terms, 'breaks' => array_slice($breaks, 0, SCHED_MAX_BREAKS), 'notes' => $notes];
}

/**
 * 日付の範囲を日付順に並べて、重なる・翌日から続くものを1つにまとめる（休業の件数を減らす）
 *   例 [12/23〜12/24] + [12/25〜1/7] → [12/23〜1/7]
 * @param array<int, array{0:string,1:string}> $ranges
 * @return array<int, array{0:string,1:string}>
 */
function sched_cal_merge_ranges(array $ranges): array
{
    usort($ranges, static fn($a, $b) => strcmp($a[0], $b[0]));
    $out = [];
    foreach ($ranges as [$f, $to]) {
        $last = count($out) - 1;
        // 前の範囲の終わりの翌日以前に始まるなら続いている（'YYYY-MM-DD' は文字列のまま大小が比べられる）
        if ($last >= 0 && $f <= date('Y-m-d', strtotime($out[$last][1] . ' +1 day'))) {
            $out[$last][1] = max($out[$last][1], $to);
        } else {
            $out[] = [$f, $to];
        }
    }
    return $out;
}

/**
 * 学年暦の PDF を読む。
 * @throws RuntimeException 画面に出してよい日本語のメッセージ
 */
function sched_calendar_read_pdf(string $path, ?string $model = null): array
{
    $raw = (string)file_get_contents($path);
    if (!str_starts_with($raw, '%PDF')) { // 拡張子は偽装できるので中身で確かめる
        throw new RuntimeException('PDF として読めません');
    }
    if (strlen($raw) > SCHED_CAL_MAX_BYTES) {
        throw new RuntimeException('5MB を超える PDF は読み込めません');
    }
    $content = [
        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($raw)]],
        ['type' => 'text', 'text' => 'この学年暦から、学期の授業期間と休業を取り出してください。'],
    ];
    try {
        $data = claude_json(SCHED_CAL_PROMPT, $content, sched_cal_schema(), 4000, 120, $model);
    } catch (RuntimeException $e) {
        throw match ($e->getCode()) {
            CLAUDE_ERR_REFUSAL => new RuntimeException('AI がこの PDF の読み取りを断りました。別の PDF で試してください'),
            default            => $e,
        };
    }
    return sched_cal_normalize($data);
}
