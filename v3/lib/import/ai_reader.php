<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  ai_reader.php — タイムテーブル・名簿の「画像」を Claude（AI）に読ませて、CSV にする
 * =====================================================================
 *  流れ
 *    1. 画像を縮小して JPEG にする（大きいまま送るとお金がかかる。長辺 1568px で十分読める）
 *    2. 全部の画像を1回のリクエストでまとめて Claude API に送る
 *       （2ページに分かれた表を、AI が1つの表としてつなげられるように）
 *    3. 返事は JSON（形をスキーマで固定）→ 中身は「取り込み用 CSV」の文字列
 *    4. その CSV を read_csv_string() で2次元配列にする
 *       → あとは CSV をアップロードしたときと全く同じ処理（parsers.php / planner.php）に流れる
 *
 *  ※ AI の読み間違いは必ずある。だから結果を直接 DB に入れず、必ずプレビュー画面を通す。
 *  ※ API キーは config.php の anthropic.api_key（パスワードと同じ。git に入れない）
 * =====================================================================
 */

/** AI で読む画像の拡張子（iPhone の HEIC は Claude が読めないので入れていない） */
const AI_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
/** 1回のアップロードで AI に送る画像の上限（1枚あたり約10円かかるので、多すぎる送信を防ぐ） */
const AI_MAX_IMAGES = 5;
/** 縮小後の長辺。これより大きくしても読み取りの精度はほぼ上がらず、料金だけ増える */
const AI_IMAGE_MAX_EDGE = 1568;
/** AI の返事を待つ上限（秒）。画像を読んで考えるので数十秒かかることがある */
const AI_HTTP_TIMEOUT_SEC = 180;

function is_ai_image_name(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), AI_IMAGE_EXTENSIONS, true);
}

function ai_reader_enabled(): bool
{
    return (string)(config('anthropic')['api_key'] ?? '') !== '';
}

/**
 * 画像たちを AI に読ませる。
 * @param array $files [['path' => 一時ファイル, 'name' => 元のファイル名], ...]
 * @return array{sheets: array<string, array>, notes: string[]}
 *   sheets … 表示用の名前 => 2次元配列（read_table_sheets と同じ形）
 *   notes  … AI が「自信がない」と言った箇所（プレビュー画面に出す）
 */
function read_images_with_ai(array $files): array
{
    if (!ai_reader_enabled()) {
        throw new RuntimeException('画像の読み取り（AI）は設定されていません。CSV / Excel / PDF にしてアップロードしてください');
    }
    if (count($files) > AI_MAX_IMAGES) {
        throw new RuntimeException('画像は一度に ' . AI_MAX_IMAGES . ' 枚までです');
    }

    $content = [];
    foreach ($files as $i => $f) {
        $content[] = ['type' => 'text', 'text' => '画像' . ($i + 1) . '（ファイル名: ' . $f['name'] . '）'];
        $content[] = ['type' => 'image', 'source' => [
            'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode(ai_prepare_image($f['path'], $f['name'])),
        ]];
    }
    $content[] = ['type' => 'text', 'text' => 'これらの画像を、取り込み用の CSV に変換してください。'];

    $result = ai_call_claude($content);

    $sheets = [];
    $sourceNames = implode('・', array_column($files, 'name'));
    foreach ($result['tables'] as $t) {
        $csv = is_string($t['csv'] ?? null) ? $t['csv'] : '';
        $rows = read_csv_string($csv);
        if ($rows === []) {
            continue;
        }
        // AI が付けたファイル名（「12月ライブ1日目_timetable.csv」など）にはライブ名・日程が入っているので、
        // ラベルに残しておくと apply_filename_hints がヒントとして使える
        $label = $sourceNames . '［AI: ' . basename((string)($t['filename'] ?? 'table.csv')) . '］';
        while (isset($sheets[$label])) {
            $label .= '＋';
        }
        $sheets[$label] = $rows;
    }
    if ($sheets === []) {
        throw new RuntimeException('画像から表を読み取れませんでした。表全体がはっきり写った写真でもう一度試してください');
    }
    $notes = array_values(array_filter(array_map('strval', $result['notes'] ?? []), static fn($n) => trim($n) !== ''));
    return ['sheets' => $sheets, 'notes' => $notes];
}

/**
 * 画像を「長辺 AI_IMAGE_MAX_EDGE 以下の JPEG」のバイト列にする。
 * 拡張子は偽装できるので、getimagesize で中身が本当に画像か確かめる。
 */
function ai_prepare_image(string $path, string $name): string
{
    $info = @getimagesize($path);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if ($info === false || !in_array($info[2], $types, true)) {
        throw new RuntimeException("{$name}: 画像として読めません（JPEG / PNG / WebP / GIF のどれかにしてください）");
    }
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('サーバーで画像を扱う機能（GD）が使えません');
    }
    $src = @imagecreatefromstring((string)file_get_contents($path));
    if ($src === false) {
        throw new RuntimeException("{$name}: 画像を開けませんでした");
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1.0, AI_IMAGE_MAX_EDGE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // 透明な PNG は白背景にする（JPEG は透明にできない）
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start(); // imagejpeg は画面に出力する関数なので、出力を横取りして文字列として受け取る
    imagejpeg($dst, null, 85);
    return (string)ob_get_clean();
}

/** AI に渡す指示。v3 の取り込み（parsers.php）が読める CSV の形を、ここで1文字も崩さず伝える */
const AI_SYSTEM_PROMPT = <<<'PROMPT'
あなたは軽音サークルのライブのタイムテーブル・名簿（メンバー表）の画像を読み取り、取り込み用の CSV に変換する担当です。
取り込み側は見出しの文字で列を探すので、下の型を崩さないでください。

## 種類の見分け方
- 時間・バンド名・曲数… の表 → タイムテーブル（kind = "timetable"。1日分 = CSV 1本）
- バンド名・Vo・Gt・Ba・Dr… の表 → 名簿（kind = "roster"）
- 1枚に両方あれば CSV を分ける。2枚以上の画像が同じ表の続き（2ページ目）なら1本につなげ、2ページ目の見出し行は書かない

## タイムテーブルの型
```
12月6日,,,12月ライブ1日目,,会場,下高井戸G-ROKS
時間,,持ち時間,バンド名,曲数,人数
10:00,,60,集合,,
11:00,11:30,30,ELLEGARDEN（岩崎）,4,4
11:30,12:00,30,BUMP OF CHICKEN,4,5
12:00,12:10,10,転換,,
18:00,18:30,30,SEKAI NO OWARI,4,7
```
- 1行目：日付（`12月6日` か `12/6`。年・曜日は書かない）・タイトル（`〇〇ライブ1日目` の形。日目は1〜3。`教室ライブ` もあり）・`会場` のセルの右隣に会場名。画像に無いものは空にする（作らない）
  - 画像で1つのセルにまとまっていても（例 `4/16(日) 新歓ライブ2日目 スタジオ音楽館アキバ`）、日付・タイトル・会場に分けて書く → `4/16,,,新歓ライブ2日目,,会場,スタジオ音楽館アキバ`
- 見出し行は `時間,,持ち時間,バンド名,曲数,人数` 固定。画像の見出しが `開始` `終了` `出演` `バンド` などでも、この見出しに書き換える。`時間` の右の見出しが空の列が終了時刻
- 時刻は半角 HH:MM で開始・終了の2列。持ち時間・曲数・人数は数字だけ。画像に無い列は空にする
- キーボードの私物・レンタルなどのメモは読まない（列ごと書かない）
- 休憩・転換・撤収・リハ・準備・解散・打ち上げなどバンドでない枠は、曲数・人数を空にする。空行は書かない
- バンド名は画像の表記どおり。同じバンドが2回出る日程は `ELLEGARDEN（岩崎）` のように括弧で代表者を付ける

## 名簿の型
```
バンド名,Vo(Gt.),Gt.1,Gt2,Ba.,Dr.,Key./その他,曲数
ヨルシカ,安田結衣,鈴木悠太,岩崎太一,宗像雄太,垰田圭吾,村田侑斗,4
水中、それは苦しい,齋藤恭平,,,小坂千都乃,垰田圭吾,茂田井教崇(Vn),4
GOING UNDER GROUND,垰田圭吾,林佑晟,,奥山航太郎,小豆畑健吾、東哲平,三澤優,3
```
- `バンド名` から `曲数` までの間がパートの列。パートの見出しは `Vo` `Gt` `Ba` `Dr` `Key` `その他`（`Cho` `Perc` も可）で始める
- 画像の見出しが違っても、上の見出しに書き換える。例: `バンド` → `バンド名`、`バッキング` `リード` `ギター` → `Gt.1` `Gt2`、`ベース` → `Ba.`、`ドラム` → `Dr.`、`キーボード` → `Key./その他`
- 画像に曲数の列が無ければ、曲数の列は見出しだけ書いて中身は空にする
- 備考・メモの列は読まない（CSV にも notes にも書かない）
- セルの色（塗りつぶし）や、表の外にある色の凡例は読まない
- 姓と名の間に空白を入れない（空白は「別の人」の区切りとして読まれる）。1セルに2人以上なら `、` で区切る
  - ただしローマ字の名前（`Jung Yeonwoo` `LEE JUNGHOO`）は画像どおり空白を残す
- 空いているパートは空セル（`-` や `なし` も書かない）
- Vo/Gt/Ba/Dr/Key 以外の楽器（Vn・Sax・Perc…）の人は名前の後ろに半角括弧で楽器を付け（`茂田井教崇(Vn)`）、`Key./その他` 欄に書く
- ボーカルが楽器も弾くと画像に書いてあれば `山田太郎(Gt)` のように付ける。書いていなければ付けない

## CSV の書き方
- カンマ・改行・`"` を含むセルは `"..."` で囲む（`"` は `""`）
- 表の外のメモ（「要修正」など）は CSV に入れない
- 取り消し線・手書きの修正があれば修正後の方を採用する
- 読めない・自信のない文字は推測で埋めず、末尾に `?` を付けて書き（例 `山田太?`）、notes に「どの表の何行目の何が読めないか」を1件ずつ日本語で書く
- 日付・タイトル・会場が画像に無いとき、取り消し線の修正を採用したときも notes に書く
- filename は `<ライブ名><日程>_timetable.csv` / `<ライブ名>_roster.csv`（例 `12月ライブ1日目_timetable.csv`）。わからない部分は省く
- 画像にタイムテーブルも名簿も無ければ tables は空にして、notes に理由を書く
PROMPT;

/** 返事の形（JSON スキーマ）。これを渡すと、AI は必ずこの形の JSON で答える */
function ai_output_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'tables' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'kind'     => ['type' => 'string', 'enum' => ['timetable', 'roster']],
                        'filename' => ['type' => 'string'],
                        'csv'      => ['type' => 'string'],
                    ],
                    'required' => ['kind', 'filename', 'csv'],
                    'additionalProperties' => false,
                ],
            ],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['tables', 'notes'],
        'additionalProperties' => false,
    ];
}

/**
 * Claude の Messages API を呼ぶ。
 * composer（公式 SDK）が使えないレンタルサーバーでも動くように、cURL で直接 HTTP を送っている。
 * @return array{tables: array, notes: array}
 */
function ai_call_claude(array $content): array
{
    $cfg = config('anthropic') ?? [];
    $body = [
        'model'      => (string)($cfg['model'] ?? 'claude-opus-5-5'),
        'max_tokens' => 16000,
        'system'     => AI_SYSTEM_PROMPT,
        'messages'   => [['role' => 'user', 'content' => $content]],
        'output_config' => [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => ai_output_schema()],
        ],
    ];
    // AI が安全上の理由で断ったとき、自動で別のモデルに回してもらう
    // Haiku にはこの仕組みが無い（送るとエラーになる）ので、Haiku 以外のときだけ付ける
    $useFallback = !str_starts_with($body['model'], 'claude-haiku');
    if ($useFallback) {
        $body['fallbacks'] = 'default';
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('サーバーで cURL が使えないため、AI を呼び出せません');
    }
    // 返事を待つ間に PHP の実行時間の上限（レンタルサーバーだと 30 秒など）で止められないようにする
    @set_time_limit(AI_HTTP_TIMEOUT_SEC + 30);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT        => AI_HTTP_TIMEOUT_SEC,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . (string)$cfg['api_key'],
            'anthropic-version: 2023-06-01',
            ...($useFallback ? ['anthropic-beta: server-side-fallback-2026-07-01'] : []),
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        throw new RuntimeException('AI に接続できませんでした（' . curl_error($ch) . '）。時間をおいて試してください');
    }
    $res = json_decode((string)$raw, true);
    if ($status !== 200 || !is_array($res)) {
        // エラーの中身（API キーの間違いなど）は管理者にしか意味がないので、画面にはざっくり出してログに残す
        error_log('Claude API error ' . $status . ': ' . substr((string)$raw, 0, 1000));
        $msg = match (true) {
            $status === 401 => 'AI の API キーが正しくありません（管理者に連絡してください）',
            $status === 429 => 'AI が混み合っているか、利用上限に達しました。時間をおいて試してください',
            $status >= 500  => 'AI 側で一時的なエラーが起きました。時間をおいて試してください',
            default         => "AI の呼び出しに失敗しました（{$status}）",
        };
        throw new RuntimeException($msg);
    }

    $stop = $res['stop_reason'] ?? '';
    if ($stop === 'refusal') {
        throw new RuntimeException('AI がこの画像の読み取りを断りました。別の画像で試してください');
    }
    if ($stop === 'max_tokens') {
        throw new RuntimeException('表が大きすぎて読み切れませんでした。画像を分けてアップロードしてください');
    }
    // content には思考（thinking）のブロックなども入るので、最後の text ブロックを答えとして使う
    $text = null;
    foreach ($res['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text = (string)$block['text'];
        }
    }
    $data = $text === null ? null : json_decode($text, true);
    if (!is_array($data) || !is_array($data['tables'] ?? null)) {
        throw new RuntimeException('AI の返事を読み取れませんでした。もう一度試してください');
    }
    return ['tables' => $data['tables'], 'notes' => is_array($data['notes'] ?? null) ? $data['notes'] : []];
}
