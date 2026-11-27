<?php
/**
 * lib/gemini_chapters.php — ライブ音源を Gemini に聴かせて、曲の開始・MC の開始を取り出す（自動チャプター）
 *   音源は Files API に上げて、終わったら消す。前処理はしない（共有サーバーは ffmpeg が使えないため）
 *   結果は [['kind' => 'song'|'end'|'mc'|'se', 'ms' => int], ...]（時間順）。失敗したら文字列（画面に出せる理由）
 *   se は、セトリに SE があるバンドだけ聴かせる（GEMINI_CHAPTER_SE_PROMPT を足す）
 */
declare(strict_types=1);

const GEMINI_API = 'https://generativelanguage.googleapis.com';

const GEMINI_CHAPTER_PROMPT = <<<'TXT'
添付されたライブ音源を最初から最後まで聴き取り、「曲の開始（SONG_START）」「曲の終わり（SONG_END）」「MCの開始（MC_START）」のタイムスタンプ（MM:SS形式）を時系列順に抽出してください。

【判定ルール】
SONG_START（曲の開始）:
- ドラムのカウント（声やスティック音）が明確に聞こえる場合は、カウントが始まった瞬間をタイムスタンプとする。
- カウントが聞こえない場合は、推測せず楽器や歌の演奏（イントロ）が実際に始まった瞬間をタイムスタンプとする。

SONG_END（曲の終わり）:
- 曲の最後の音（最後のジャーンの残響・シンバルの余韻）が消えた瞬間をタイムスタンプとする。拍手・歓声・チューニングは曲に含めない。
- 曲が終わってから次の曲・MC までに拍手・チューニング・無音などの間がある場合だけ出力すること。
- 曲が途切れずに次の曲へつながる場合（メドレー・続けて演奏）は、絶対に無理に SONG_END を作らず、次の SONG_START だけを出力すること。

MC_START（MCの開始）:
- 演奏が完全に終わった後（または1曲目の演奏前）に、出演者がマイクを通して話し始めた瞬間をタイムスタンプとする。
- MCが存在しない場合（最初からいきなり曲が始まる場合や、MCを挟まず連続して次の曲が始まる場合）は、絶対に無理にMC_STARTを作らず、SONG_STARTのみを連続して出力すること。
- 演奏中の掛け声や煽り（「いくぞ！」「ありがとう！」など）はMC_STARTとして扱わないこと。

根拠の記載:
- trigger_clue には、その瞬間に聞こえた具体的な音（例：「スティックのカウント音」「ギターのアルペジオ開始」「『こんばんは』という話し声」など）を簡潔に記述すること。
TXT;

/** セトリに SE があるときだけ足す。%s = SE 名（1行に1つ） */
const GEMINI_CHAPTER_SE_PROMPT = <<<'TXT'

SE_START（SEの開始）:
- このバンドは、演奏とは別に会場で既存曲の音源（SE）を流します。流れる SE は次のとおりです（セトリ順）:
%s
- 録音された音源の再生が始まった瞬間をタイムスタンプとする。バンドの生演奏は SE ではない（SONG_START）。
- SE が聞こえない場合は、絶対に無理に SE_START を作らないこと。
TXT;

/**
 * 最後に失敗した理由（ログと、管理者の画面に出す用）。$set を渡すと記録してログにも書く
 */
function gemini_why(?string $set = null): string
{
    static $why = '';
    if ($set !== null) {
        $why = $set;
        error_log('Gemini: ' . $set);
    }
    return $why;
}

/**
 * curl で1回呼ぶ。返り値は [HTTP ステータス, ヘッダー（小文字の名前 => 値）, 本文]。つながらなければ null
 * @param array<int, mixed> $opts 追加の curl オプション（CURLOPT_HTTPHEADER は追加分だけ書く）
 */
function gemini_call(string $url, string $key, array $opts, int $timeout): ?array
{
    $headers = [];
    $extra = $opts[CURLOPT_HTTPHEADER] ?? [];
    unset($opts[CURLOPT_HTTPHEADER]);
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => array_merge(['x-goog-api-key: ' . $key], $extra),
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($n))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        gemini_why('cURL errno ' . curl_errno($ch));
        return null;
    }
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $headers, (string)$body];
}

/** "MM:SS"（または "H:MM:SS"）→ ミリ秒。読めなければ null */
function gemini_chapter_ms(string $t): ?int
{
    if (!preg_match('/^(?:(\d+):)?(\d{1,3}):(\d{2})$/', trim($t), $m)) {
        return null;
    }
    return (((int)$m[1] * 60 + (int)$m[2]) * 60 + (int)$m[3]) * 1000;
}

/** Gemini の返事（events の配列）→ チャプター（時間順。同じ時刻は1つ） */
function gemini_chapters_from_events(array $events): array
{
    $out = [];
    foreach ($events as $e) {
        $kind = ['SONG_START' => 'song', 'SONG_END' => 'end', 'MC_START' => 'mc', 'SE_START' => 'se'][(string)($e['type'] ?? '')] ?? null;
        $ms = gemini_chapter_ms((string)($e['timestamp'] ?? ''));
        if ($kind !== null && $ms !== null) {
            $out[$ms] = ['kind' => $kind, 'ms' => $ms];
        }
    }
    ksort($out);
    return array_values($out);
}

/**
 * @param string[] $ses セトリの SE 名。空なら SE は探さない（プロンプトは今までのまま）
 * @return array<int, array{kind: string, ms: int}>|string
 */
function gemini_audio_chapters(string $path, string $mime, string $key, string $model, array $ses = []): array|string
{
    $types = ['SONG_START', 'SONG_END', 'MC_START'];
    $prompt = GEMINI_CHAPTER_PROMPT;
    if ($ses) {
        $types[] = 'SE_START';
        $prompt = str_replace('「MCの開始（MC_START）」', '「MCの開始（MC_START）」「SEの開始（SE_START）」', $prompt)
            . sprintf(GEMINI_CHAPTER_SE_PROMPT, implode("\n", array_map(static fn($t) => '  - ' . $t, $ses)));
    }
    $json = gemini_audio_json($path, $mime, $key, $model, $prompt, ['type' => 'OBJECT', 'properties' => ['events' => ['type' => 'ARRAY', 'items' => [
        'type' => 'OBJECT',
        'properties' => [
            'type' => ['type' => 'STRING', 'enum' => $types],
            'timestamp' => ['type' => 'STRING'],
            'trigger_clue' => ['type' => 'STRING'],
        ],
        'required' => ['type', 'timestamp', 'trigger_clue'],
    ]]], 'required' => ['events']]);
    if (is_string($json)) {
        return $json;
    }
    return is_array($json['events'] ?? null) ? gemini_chapters_from_events($json['events']) : 'Gemini API で解析できませんでした';
}

/** Google が返したエラー文（短く） */
function gemini_error_text(?array $res): string
{
    return mb_substr((string)(json_decode($res[2] ?? '', true)['error']['message'] ?? ''), 0, 200);
}

/**
 * 音源を Files API に上げて $prompt で聴かせ、$schema の形の JSON を返す（終わったら音源は消す）。失敗したら文字列（画面に出せる理由）
 *   自動チャプター（上）と、動画から曲を検出（lib/gemini_setlist.php）で使う
 *   $thinkingBudget / $flex は gemini_json と同じ
 */
function gemini_audio_json(string $path, string $mime, string $key, string $model, string $prompt, array $schema, ?int $thinkingBudget = null, bool $flex = false): array|string
{
    $file = gemini_upload($path, $mime, $key);
    if (is_string($file)) {
        return $file;
    }
    try {
        return gemini_json([['file_data' => ['mime_type' => $mime, 'file_uri' => $file['uri']]], ['text' => $prompt]], $key, $model, $schema, $thinkingBudget, $flex);
    } finally {
        gemini_delete($file, $key); // 預けた音源は消す
    }
}

/**
 * 音源を Files API に上げ、使える状態（ACTIVE）になるまで待つ。戻り値: Files API のファイル（name, uri。使い終わったら gemini_delete）。失敗したら理由
 */
function gemini_upload(string $path, string $mime, string $key): array|string
{
    $size = (int)filesize($path);
    $fail = 'Gemini API で解析できませんでした';

    // 1. 上げる場所をもらう → 2. 上げる
    $start = gemini_call(GEMINI_API . '/upload/v1beta/files', $key, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{"file":{"display_name":"live"}}',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $size,
            'X-Goog-Upload-Header-Content-Type: ' . $mime,
        ],
    ], 30);
    $uploadUrl = $start[1]['x-goog-upload-url'] ?? '';
    if (!$start || $start[0] !== 200 || !str_starts_with($uploadUrl, GEMINI_API . '/')) {
        gemini_why('upload start HTTP ' . ($start[0] ?? 0) . ' ' . gemini_error_text($start));
        return ($start[0] ?? 0) === 429 ? 'Gemini API の利用上限に達しました。時間をおいて再試行してください' : $fail;
    }
    $fp = fopen($path, 'rb');
    $up = gemini_call($uploadUrl, $key, [
        CURLOPT_UPLOAD => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_HTTPHEADER => ['X-Goog-Upload-Offset: 0', 'X-Goog-Upload-Command: upload, finalize', 'Expect:'],
    ], 280);
    fclose($fp);
    $file = json_decode($up[2] ?? '', true)['file'] ?? null;
    if (!$up || $up[0] !== 200 || !is_array($file) || !isset($file['name'], $file['uri'])) {
        gemini_why('upload HTTP ' . ($up[0] ?? 0) . ' ' . gemini_error_text($up));
        return $fail;
    }
    // 3. 使える状態（ACTIVE）になるまで待つ
    for ($i = 0; $i < 30 && ($file['state'] ?? '') !== 'ACTIVE'; $i++) {
        if (($file['state'] ?? '') === 'FAILED') {
            gemini_why('file FAILED');
            gemini_delete($file, $key);
            return $fail;
        }
        sleep(2);
        $st = gemini_call(GEMINI_API . '/v1beta/' . $file['name'], $key, [], 20);
        $file = json_decode($st[2] ?? '', true) ?: $file;
    }
    return $file;
}

/** gemini_upload で上げたファイルを消す */
function gemini_delete(array $file, string $key): void
{
    gemini_call(GEMINI_API . '/v1beta/' . $file['name'], $key, [CURLOPT_CUSTOMREQUEST => 'DELETE'], 20);
}

/**
 * $parts（文・gemini_upload した音源）を渡して、$schema の形の JSON を返させる。失敗したら文字列（画面に出せる理由）
 *   $thinkingBudget: 考える量（トークン）の上限。null なら上限なし。考えた分は出力と同じ単価で請求される
 *   $flex: Flex（半額。返事まで1〜15分。混んでいると 429 / 5xx で断られるので、待って6回までやり直す。待つのは合わせて約17分）。画面を待たせない所だけで使う
 */
function gemini_json(array $parts, string $key, string $model, array $schema, ?int $thinkingBudget = null, bool $flex = false): array|string
{
    $fail = 'Gemini API で解析できませんでした';
    $req = [
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ] + ($thinkingBudget === null ? [] : ['thinkingConfig' => ['thinkingBudget' => $thinkingBudget]]),
    ] + ($flex ? ['service_tier' => 'flex'] : []);
    foreach ($flex ? [30, 60, 120, 240, 300, 300, 0] : [0] as $wait) {
        $res = gemini_call(GEMINI_API . '/v1beta/models/' . rawurlencode($model) . ':generateContent', $key, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($req, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ], $flex ? 660 : 280); // Flex は最大 600 秒待つ
        if (!$res || !in_array($res[0], [429, 500, 502, 503, 504], true) || $wait === 0) { // 混雑は 503 以外（502 など）でも返る
            break;
        }
        sleep($wait); // Flex が混んでいる
    }
    if (!$res || $res[0] !== 200) {
        gemini_why('generate HTTP ' . ($res[0] ?? 0) . ' ' . gemini_error_text($res));
        return ($res[0] ?? 0) === 429 ? 'Gemini API の利用上限に達しました。時間をおいて再試行してください' : $fail;
    }
    $text = '';
    $body = json_decode($res[2], true);
    foreach ($body['candidates'][0]['content']['parts'] ?? [] as $p) {
        $text .= (string)($p['text'] ?? '');
    }
    $json = json_decode($text, true);
    if (!is_array($json)) {
        gemini_why('bad JSON finish=' . ($body['candidates'][0]['finishReason'] ?? $body['promptFeedback']['blockReason'] ?? '?') . ' len=' . strlen($text));
        return $fail;
    }
    return $json;
}
