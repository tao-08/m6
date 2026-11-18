<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  sched_photo.php — カレンダーのスクリーンショットから予定を読み込む（マイスケジュール）
 * =====================================================================
 *  iCal で連携できないカレンダー（TimeTree・Android の標準アプリなど）の人が、画面のスクリーンショットを撮って送る。
 *  流れ
 *    1. sched_me.php の photo_read … 画像を Claude（AI）に読ませて、予定の一覧を JSON で返す（sched_photo_read）
 *    2. assets/sched.js の確認画面 … 人が一覧を見て、直したり外したりする（AI の読み間違いはあるので、必ず人が確かめる）
 *    3. sched_me.php の photo_add … 確認した予定を sched_busy に入れる（source = 2。sched_photo_normalize_add で検証）
 *
 *  ■ タイトルは iCal と同じ扱い（暗号化して保存・本人にだけ見せる。lib/sched_ical.php）
 *  ■ 画像はサーバーに保存しない（AI に送ったあと、アップロードの一時ファイルは PHP が消す）
 *  ■ AI の返事は信用しない: 日付・時刻・長さを必ずここで検証・整形してから画面に返す
 * =====================================================================
 */
require_once __DIR__ . '/import/ai_reader.php'; // ai_prepare_image（画像の検証・縮小）。claude.php も読み込まれる
require_once __DIR__ . '/sched.php';            // SCHED_STEP

/** 1回に送れる画像の枚数 */
const SCHED_PHOTO_MAX_IMAGES = 3;
/** 画像1枚の大きさの上限（バイト） */
const SCHED_PHOTO_MAX_BYTES = 10 * 1024 * 1024;
/** 1回の読み込みで AI から受け取る予定の上限（暴走した返事で画面が重くならないように） */
const SCHED_PHOTO_MAX_EVENTS = 150;
/** 1回の「追加」で入れられる予定の上限 */
const SCHED_PHOTO_MAX_ADD = 100;
/** 予定のタイトルの長さの上限（文字） */
const SCHED_PHOTO_TITLE_MAX = 100;
/** AI の返事を待つ上限（秒） */
const SCHED_PHOTO_TIMEOUT_SEC = 150;

/**
 * 初期のモデル = config の anthropic.sched_photo_model（一覧に無い・未設定なら Haiku。1回1円未満）
 *   画面で選んだモデル（claude_models() のどれか）があれば、そちらを使う
 */
function sched_photo_default_model(): string
{
    $m = (string)((config('anthropic') ?? [])['sched_photo_model'] ?? '');
    return isset(claude_models()[$m]) ? $m : 'claude-haiku-5-5';
}

/** AI に渡す指示。今日の日付（年が画面に出ていないとき、年を決める手がかり）を入れる */
function sched_photo_prompt(string $today): string
{
    $wd = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', strtotime($today))];
    return <<<PROMPT
あなたは、カレンダーアプリ（TimeTree・Google カレンダー・Android の標準カレンダーなど）のスクリーンショットから、本人の予定を読み取る担当です。
読み取った予定は「その時間は予定が入っている」という情報として、軽音サークルの練習日程の調整に使われます。

今日の日付は {$today}（{$wd}曜日）です。

## 読み取るもの
- 月表示・週表示・日表示・リスト（予定一覧）表示のどれでも、画像に写っている本人の予定を全部読む（見切れて読めないものは除く）
- 1つの予定を events の1件にする
  - date … 予定の日付（YYYY-MM-DD）
  - all_day … 終日の予定なら true。時刻が書かれていない予定（月表示でタイトルだけが並んでいるもの）も true
  - start / end … 開始・終了の時刻（HH:MM の24時間表記。例 "09:30"・"18:00"）。画像に書かれていなければ null
  - title … 予定のタイトル（画像の表記どおり。見切れていれば読めた分まで。末尾に「…」などは付けない）
- 2日以上にまたがる予定は、日ごとに1件ずつに分ける（各日の start / end は、その日の分。わからなければ all_day = true）

## 日付の決め方
- 予定を書く前に、画像ごとに screens へ「表示の種類（view）・写っている最初の日（first）・最後の日（last）」を書く。events の日付は必ずこの範囲の中にする
  - 月表示の first / last は、画面の左上のマスと右下のマスの日付（前の月・次の月の分も含む）
- 予定の日付は、その予定の「真上」にある日付の見出し（数字）で決める。左から何番目かで数えない（週・日表示は左端に時刻の列があるので、数えると1日ずれる）
- 月表示で、前の月・次の月の日付が端の行に出ているときは、その月の日付にする（例: 12月の画面の先頭行の 29・30 は 11月、最後の行の 1〜9 は翌年1月）。左上から順に数字をたどり、31（月末）のあとに 1 に戻ったら、そこからは次の月
- 何も書かれていない空のマス・空のブロックは予定ではない（title が空の予定は作らない）

## 週表示・日表示（左に時刻の目盛りがある画面）の時刻
- 予定のブロックの上端 = 開始、下端 = 終了。左の目盛り（1:00・2:00…）と横に見比べて読む。時刻の文字が書かれていなくても、ブロックの位置から必ず start / end を埋める
- 目盛りの数字は、その時刻の線の高さにある。1時間の高さの半分 = 30分。ブロックが1時間の高さより小さければ30分の予定
- タイトルだけの細いブロック（1行分の高さ）も、上端・下端から start / end を読む（null にしない）
- 時刻の列の上（日付の見出しのすぐ下）に横長に並ぶ予定は終日（all_day = true）
- 目盛りの一番下・一番上に、夜・朝の時間をたたんだ欄（∨・∧ などの印があり、中に目盛りが無い）があるときは、その中の予定の本当の時刻は画像に写っていない
  - 下のたたんだ欄の予定 … start = たたんだ欄が始まる時刻（すぐ上の目盛りの次の時刻）、end = null
  - 上のたたんだ欄の予定 … start = null、end = たたんだ欄が終わる時刻（すぐ下の目盛りの時刻）
  - どちらも notes に1件ずつ書く（例: `12/7 ドラム レッスンは18時以降の予定です。時刻をご確認ください`）

## 守ること
- リスト表示・月表示などで終了時刻が書かれていないとき、推測で埋めない（end = null）。開始時刻の1時間後などにしない
- 年が画像に出ていないときは、その日付が「今日にいちばん近い年」になる年にする（同じくらいなら未来を選ぶ）。年が出ていればそのとおりにする
- 祝日・記念日・行事の自動表示は読まない（例: 元日・成人の日・大晦日・クリスマス・クリスマスイブ・バレンタインデー・ハロウィン・七夕・節分・母の日・銀行休業日・振替休日）。これらは祝日のカレンダーから自動で出るもので、本人の予定ではない
- 六曜（大安・仏滅など）・月の満ち欠け・天気・誕生日の自動表示なども読まない
- 同じ予定を2回書かない（複数の画像で重なっているときは1件にまとめる）
- 取り消し線が引かれた予定・完了済みのタスクは、読まない
- 読めない・自信のない部分（日付が特定できない、文字がつぶれているなど）は推測で埋めず、その予定を読み飛ばして notes に書く

## notes の書き方
- 人が確認したほうがいいことだけ。1件 = 1行、40文字くらいまで、敬語で書く（例: `5/12 の予定は文字が小さく読めませんでした`）
- 問題がなければ空の配列にする
- 画像にカレンダーが写っていないときは events を空にして、その理由を notes に書く
PROMPT;
}

/** 返事の形（JSON スキーマ）。これを渡すと、AI は必ずこの形の JSON で答える */
function sched_photo_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            // 予定より先に「何表示で、何日〜何日が写っているか」を答えさせる（月をまたぐ行・列のずれで日付を間違えないように）。サーバーでは使わない
            'screens' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'view'  => ['type' => 'string', 'enum' => ['month', 'week', 'day', 'list', 'other']],
                        'first' => ['type' => 'string'],
                        'last'  => ['type' => 'string'],
                    ],
                    'required' => ['view', 'first', 'last'],
                    'additionalProperties' => false,
                ],
            ],
            'events' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date'    => ['type' => 'string'],
                        'all_day' => ['type' => 'boolean'],
                        'start'   => ['type' => ['string', 'null']],
                        'end'     => ['type' => ['string', 'null']],
                        'title'   => ['type' => 'string'],
                    ],
                    'required' => ['date', 'all_day', 'start', 'end', 'title'],
                    'additionalProperties' => false,
                ],
            ],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['screens', 'events', 'notes'],
        'additionalProperties' => false,
    ];
}

/** 'Y-m-d' が実在する日付か（2026-02-30 のような存在しない日は false） */
function sched_photo_valid_date(mixed $s): bool
{
    return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 'H:MM' / 'HH:MM'（24:00 も可）→ 0時からの分。読めなければ null */
function sched_photo_parse_time(mixed $s): ?int
{
    if (!is_string($s) || !preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $s, $m)) {
        return null;
    }
    $min = (int)$m[1] * 60 + (int)$m[2];
    return (int)$m[2] < 60 && $min <= 1440 ? $min : null;
}

/** 予定のタイトルを整える（前後の空白・改行を除き、長ければ切る） */
function sched_photo_clean_title(mixed $s): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', is_string($s) ? $s : ''));
    return mb_substr($s, 0, SCHED_PHOTO_TITLE_MAX);
}

/**
 * AI の返事を、画面に返してよい形に直す。
 *   events … [['date' => 'Y-m-d', 'all_day' => bool, 'start' => 分|null, 'end' => 分|null, 'title' => 文字], …]
 *            開始は30分に切り下げ、終了は30分に切り上げる（予定の時間を、マスからはみ出さずに覆うため）。終日は 0:00〜24:00
 *            実在しない日付・読めない時刻の予定は捨てる。同じ予定（日付・時刻・タイトルが同じ）は1件にまとめる
 *   notes  … 文字列だけ・空でないものだけ（多すぎると画面が埋まるので10件まで）
 * @return array{events: array<int, array<string, mixed>>, notes: string[]}
 */
function sched_photo_clean(array $data): array
{
    $events = [];
    $seen = [];
    foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $e) {
        if (!is_array($e) || !sched_photo_valid_date($e['date'] ?? null)) {
            continue;
        }
        $allDay = ($e['all_day'] ?? false) === true;
        $start = sched_photo_parse_time($e['start'] ?? null);
        $end = sched_photo_parse_time($e['end'] ?? null);
        if ($allDay) {
            [$start, $end] = [0, 1440];
        } else {
            $start = $start === null ? null : intdiv($start, SCHED_STEP) * SCHED_STEP;                    // 切り下げ
            $end = $end === null ? null : (int)(ceil($end / SCHED_STEP) * SCHED_STEP);                    // 切り上げ
        }
        $title = sched_photo_clean_title($e['title'] ?? '');
        if ($title === '' && $start === null && $end === null) {
            continue; // タイトルも時刻も無い = 空のマスを予定と読み違えたもの
        }
        $key = $e['date'] . '|' . var_export($start, true) . '|' . var_export($end, true) . '|' . $title;
        if (isset($seen[$key])) {
            continue; // 画像が重なっていて、同じ予定が2回読まれたとき
        }
        $seen[$key] = true;
        $events[] = ['date' => $e['date'], 'all_day' => $allDay, 'start' => $start, 'end' => $end, 'title' => $title];
        if (count($events) >= SCHED_PHOTO_MAX_EVENTS) {
            break;
        }
    }
    // 日付 → 開始の順（開始が無いものは日の先頭）
    usort($events, static fn($a, $b) => [$a['date'], $a['start'] ?? -1] <=> [$b['date'], $b['start'] ?? -1]);

    $notes = [];
    foreach (is_array($data['notes'] ?? null) ? $data['notes'] : [] as $n) {
        $n = is_string($n) ? trim($n) : '';
        if ($n !== '') {
            $notes[] = mb_substr($n, 0, 200);
        }
    }
    return ['events' => $events, 'notes' => array_slice($notes, 0, 10)];
}

/**
 * 画像たちを AI に読ませて、予定の一覧にする。
 * @param array $files [['path' => 一時ファイル, 'name' => 元のファイル名], …]（画像として読めなければ RuntimeException）
 * @return array{events: array, notes: string[]}
 * @throws RuntimeException 画面に出してよい日本語のメッセージ
 */
function sched_photo_read(array $files, string $today, ?string $model = null): array
{
    if (!claude_enabled()) {
        throw new RuntimeException('画像からの読み込みは現在使えません');
    }
    if (!$files || count($files) > SCHED_PHOTO_MAX_IMAGES) {
        throw new RuntimeException('画像は ' . SCHED_PHOTO_MAX_IMAGES . ' 枚までです');
    }
    $content = [];
    foreach ($files as $i => $f) {
        $img = ai_prepare_image($f['path'], $f['name']);
        $content[] = ['type' => 'text', 'text' => '画像' . ($i + 1)];
        $content[] = ['type' => 'image', 'source' => [
            'type' => 'base64', 'media_type' => $img['media_type'], 'data' => base64_encode($img['data']),
        ]];
    }
    $content[] = ['type' => 'text', 'text' => 'これらの画像から、予定を読み取ってください。'];
    try {
        $model = isset(claude_models()[(string)$model]) ? (string)$model : sched_photo_default_model(); // 一覧に無いモデル名は使わない
        $data = claude_json(sched_photo_prompt($today), $content, sched_photo_schema(), 16000, SCHED_PHOTO_TIMEOUT_SEC, $model);
    } catch (RuntimeException $e) {
        throw match ($e->getCode()) {
            CLAUDE_ERR_REFUSAL    => new RuntimeException('AI がこの画像の読み取りを断りました。別の画像で試してください'),
            CLAUDE_ERR_MAX_TOKENS => new RuntimeException('予定が多すぎて読み切れませんでした。画像を分けて試してください'),
            default               => $e,
        };
    }
    return sched_photo_clean($data);
}

/**
 * 「追加」で送られてきた予定を検証する（画面で直した後の値なので、AI の返事とは別に、もう一度確かめる）。
 *   フォームは書き換えられるので、日付が実在すること・開始と終了が30分刻みで 0:00〜24:00 の中にあること・開始 < 終了 を必ず確かめる
 *   1件でもおかしければ null（一部だけ入れるとどれが抜けたかわからなくなるので、全部やり直してもらう）
 * @param mixed $items JSON を decode したもの: [['date' => 'Y-m-d', 'start' => 分, 'end' => 分, 'title' => 文字], …]
 * @return array<int, array{date: string, start: int, end: int, title: string}>|null
 */
function sched_photo_normalize_add(mixed $items): ?array
{
    if (!is_array($items) || !array_is_list($items) || !$items || count($items) > SCHED_PHOTO_MAX_ADD) {
        return null;
    }
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it) || !sched_photo_valid_date($it['date'] ?? null)) {
            return null;
        }
        $s = $it['start'] ?? null;
        $e = $it['end'] ?? null;
        if (!is_int($s) || !is_int($e) || $s < 0 || $e > 1440 || $s >= $e || $s % SCHED_STEP !== 0 || $e % SCHED_STEP !== 0) {
            return null;
        }
        $out[] = ['date' => $it['date'], 'start' => $s, 'end' => $e, 'title' => sched_photo_clean_title($it['title'] ?? '')];
    }
    return $out;
}
