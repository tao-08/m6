<?php
/**
 * =====================================================================
 *  lib/tab_ai.php — 画像・テキストの TAB 譜（耳コピの記録）を Claude（AI）に読ませて、このサイトの TAB の形にする
 * =====================================================================
 *  呼び出し自体は lib/claude.php の claude_json（タイムテーブル・名簿の画像読み取り lib/import/ai_reader.php と同じ仕組み・同じモデル設定）。
 *  流れ（api_tab_ai.php）:
 *    1. TAB に添付した画像（サーバーが自分でファイルを読む。ブラウザから来た URL は取りに行かない）か、貼り付けたテキストを AI に渡す
 *    2. 返事は JSON スキーマで形を固定（bars: [{s?, n: [[step, string, fret, dur?]], r: [[step, dur]]}]）
 *    3. 返事を tab_ai_clean_bars() で甘めに整える（範囲外の音符だけ捨てる）→ tab_sanitize_data() で最終の検査
 *    4. 結果は保存せずブラウザに返す。人がプレビューで確認して、置換・追加してから保存する（AI の読み間違いは必ずある）
 *  歌詞は写し取らない（著作権）。
 *
 *  1日の回数の上限: song_tab_ai_usage（ユーザーごと・日ごとの回数）。AI は1回ごとにお金がかかるので、使いすぎを止める。
 *    ほかに回数制限の仕組み（login_attempt はログインの失敗専用）は流用できなかったので、小さな表を足した
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/tabs.php';
require_once __DIR__ . '/claude.php';

const TAB_AI_DAILY_LIMIT = 30;      // 1人が1日に使える回数
const TAB_AI_TEXT_MAX = 20000;      // 貼り付けるテキストの文字数

/**
 * 今日の回数を1つ増やす。上限に達していれば増やさずに false
 *   先に増やしてから AI を呼ぶ（失敗しても数える。連打・失敗の繰り返しで課金が膨らまないように）
 */
function tab_ai_take(PDO $pdo, int $userId): bool
{
    $pdo->prepare('DELETE FROM song_tab_ai_usage WHERE day < CURDATE() - INTERVAL 7 DAY')->execute(); // 古い行はときどき掃除
    // 上限未満のときだけ +1。行が無ければ作る（主キー (user_id, day)）
    $st = $pdo->prepare('INSERT INTO song_tab_ai_usage (user_id, day, n) VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE n = IF(n < ?, n + 1, n)');
    $st->execute([$userId, TAB_AI_DAILY_LIMIT]);
    // 影響した行数: 新しく作った = 1、+1 した = 2、上限で変わらなかった = 0
    return $st->rowCount() > 0;
}

/** AI に渡す返事の形（JSON スキーマ）。値が無いものは null を返させる */
function tab_ai_schema(): array
{
    $int = ['type' => 'integer'];
    return [
        'type' => 'object',
        'properties' => [
            'bpm'    => ['type' => ['number', 'null']],
            'tuning' => ['type' => ['string', 'null']],
            'bars'   => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        's' => ['type' => ['string', 'null']],
                        'n' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $int]],
                        'r' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $int]],
                    ],
                    'required' => ['s', 'n', 'r'],
                    'additionalProperties' => false,
                ],
            ],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['bpm', 'tuning', 'bars', 'notes'],
        'additionalProperties' => false,
    ];
}

/** 指示文。strings = 弦の数、tuning = 低い弦から、$stepsPerBar = 1小節のマス数 */
function tab_ai_system_prompt(int $strings, string $tuning, int $beats, int $steps): string
{
    $stepsPerBar = $beats * $steps;
    return <<<PROMPT
あなたは、ギター・ベースの TAB 譜（画像または文字）を、決まった形の JSON に書き起こす係です。

## いちばん大事なこと
- **書いてあるものだけ**を書き起こす。書いていない音符を作らない・補わない・「それらしく」直さない
- 読めない・自信がない音符は入れず、notes に「何小節目の何が読めなかったか」を書く
- 歌詞は写さない（著作権）。メロディの文字も書かない

## この TAB の前提
- 弦の数: {$strings}（チューニング 低い弦→高い弦: {$tuning}）
- 1小節 = {$beats}拍、1拍を {$steps} 分割 → 1小節は {$stepsPerBar} マス（step は 0〜{$stepsPerBar}-1）
- **string は 0 が一番高い弦（TAB の一番上の行）**、下の行ほど番号が大きくなる（最大 {$strings}-1）
- fret は 0（開放）〜24。ミュート（x）は -1。ハンマリング・プリング・スライド・ベンドの記号は無視して、音の数字だけ書く

## 出力
- bars: 小節の配列（左から右、上の段から下の段へ、書いてある順）
  - s: 小節の上にセクション名（Aメロ・サビ・イントロ など）が書いてあればその文字（20文字まで）。無ければ null
  - n: 音符 [step, string, fret] または長さつきの [step, string, fret, dur]。dur は音の長さ（マス数）。長さが分かるときだけ付ける
  - r: 休符 [[step, dur]]。休符の記号があるときだけ。無ければ []
  - 音符の位置（step）は、小節の中での横の位置を {$stepsPerBar} マスに丸めて決める。同じ縦位置の複数の弦の音は同じ step（和音）
- bpm: テンポが書いてあれば数字（20〜400）。無ければ null
- tuning: チューニングが書いてあれば、低い弦から高い弦の音名を空白区切り（例 `D A D G B E`）。弦の数が上と同じときだけ。無ければ null
- notes: 人が確認する必要のあること（読めなかった所など）。1件1行、40文字くらい。なければ []
- 画像にも文字にも TAB が無ければ bars は空にして、notes に理由を書く
PROMPT;
}

/**
 * AI の返事の bars を甘めに整える。範囲外の音符・休符だけ捨てて、捨てた数を返す（全体を捨てない）
 *   最終の厳密な検査は tab_sanitize_data()
 * @return array{bars: array, dropped: int}
 */
function tab_ai_clean_bars(mixed $bars, int $strings, int $stepsPerBar, string $kind = 'string'): array
{
    $out = [];
    $dropped = 0;
    if (!is_array($bars)) {
        return ['bars' => [], 'dropped' => 0];
    }
    foreach (array_slice(array_values($bars), 0, TAB_MAX_BARS) as $bar) {
        $bar = is_array($bar) ? $bar : [];
        $o = ['n' => []];
        if (isset($bar['s']) && is_string($bar['s']) && trim($bar['s']) !== '') {
            $o['s'] = $bar['s'];
        }
        foreach (is_array($bar['n'] ?? null) ? $bar['n'] : [] as $note) {
            $v = is_array($note) ? array_map('tab_int', array_values($note)) : [];
            $fretOk = count($v) >= 3 && $v[2] !== null && ($kind === 'drum' ? ($v[2] >= 1 && $v[2] <= 4) : ($v[2] >= -1 && $v[2] <= TAB_MAX_FRET));
            if (count($v) < 3 || count($v) > 4 || in_array(null, $v, true) || !$fretOk
                || $v[0] < 0 || $v[0] >= $stepsPerBar || $v[1] < 0 || $v[1] >= $strings || (isset($v[3]) && $v[3] < 1)
                || ($kind === 'drum' && $v[2] === 4 && $v[1] !== TAB_DRUM_HH)) {
                $dropped++;
                continue;
            }
            $o['n'][] = $v;
        }
        foreach (is_array($bar['r'] ?? null) ? $bar['r'] : [] as $rest) {
            $v = is_array($rest) ? array_map('tab_int', array_values($rest)) : [];
            if (count($v) !== 2 || in_array(null, $v, true) || $v[0] < 0 || $v[0] >= $stepsPerBar || $v[1] < 1) {
                $dropped++;
                continue;
            }
            $o['r'][] = $v;
        }
        $out[] = $o;
    }
    return ['bars' => $out, 'dropped' => $dropped];
}

/**
 * AI に聞く。画像（長辺 1568px 以下の JPEG にして base64）かテキストのどちらか
 * @param array|null $image ['path' => ファイルのパス, 'name' => 表示名]
 * @return array{data: array, bpm: ?float, tuning: ?string, notes: string[]} data は tab_sanitize_data を通した {"bars": [...]}
 * @throws RuntimeException 画面に出してよい日本語のメッセージ
 */
function tab_ai_read(?array $image, ?string $text, int $strings, string $tuning, int $beats, int $steps, string $kind = 'string'): array
{
    require_once __DIR__ . '/import/ai_reader.php'; // ai_prepare_image（画像の縮小）
    $content = [];
    if ($image !== null) {
        $img = ai_prepare_image($image['path'], $image['name']);
        $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['media_type'], 'data' => base64_encode($img['data'])]];
        $content[] = ['type' => 'text', 'text' => 'この画像の TAB 譜を書き起こしてください。'];
    } else {
        $content[] = ['type' => 'text', 'text' => "次の TAB 譜（文字）を書き起こしてください。\n\n" . (string)$text];
    }
    try {
        $r = claude_json(tab_ai_system_prompt($strings, $tuning, $beats, $steps), $content, tab_ai_schema(), 16000, AI_HTTP_TIMEOUT_SEC);
    } catch (RuntimeException $e) {
        throw match ($e->getCode()) {
            CLAUDE_ERR_REFUSAL    => new RuntimeException('AI がこの画像の読み取りを断りました。別の画像で試してください'),
            CLAUDE_ERR_MAX_TOKENS => new RuntimeException('長すぎて読み切れませんでした。画像を分けて試してください'),
            default               => $e,
        };
    }
    $cleaned = tab_ai_clean_bars($r['bars'] ?? null, $strings, $beats * $steps, $kind);
    $notes = array_values(array_filter(array_map('strval', is_array($r['notes'] ?? null) ? $r['notes'] : []), static fn($n) => trim($n) !== ''));
    if ($cleaned['dropped'] > 0) {
        $notes[] = '範囲外の音符を ' . $cleaned['dropped'] . ' 個捨てました';
    }
    if ($cleaned['bars'] === []) {
        throw new RuntimeException('TAB を読み取れませんでした' . ($notes ? '（' . $notes[0] . '）' : ''));
    }
    $data = tab_sanitize_data(['bars' => $cleaned['bars']], $strings, $beats * $steps, $kind);
    if ($data === null) {
        throw new RuntimeException('AI の返事を読み取れませんでした。もう一度試してください');
    }
    $tuningOut = is_string($r['tuning'] ?? null) ? trim(preg_replace('/ +/', ' ', $r['tuning']) ?? '') : null;
    if ($tuningOut !== null && ($kind === 'drum' || !tab_tuning_valid($tuningOut) || tab_string_count($tuningOut) !== $strings)) {
        $tuningOut = null;
    }
    $bpm = $r['bpm'] ?? null;
    return [
        'data'   => $data,
        'bpm'    => (is_int($bpm) || is_float($bpm)) && $bpm >= 20 && $bpm <= 400 ? round((float)$bpm, 2) : null,
        'tuning' => $tuningOut,
        'notes'  => $notes,
    ];
}
